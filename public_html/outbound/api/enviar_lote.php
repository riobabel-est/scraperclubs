<?php
/**
 * enviar_lote.php — API endpoint para enviar un email individual desde la lanzadera.
 * Recibe id_club, id_plantilla, id_cuenta_smtp.
 * Realiza el envío SMTP autenticado nativo, registra en comunicaciones_log y envios,
 * actualiza contador de cuentas SMTP y cambia estado del club.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── Buffer + Control de errores para JSON limpio ───
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$DB_PATH  = __DIR__ . '/../data/stats.db';
$LOG_DIR  = __DIR__ . '/../logs';

if (!file_exists($DB_PATH)) {
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

require_once __DIR__ . '/../inc/eligibilidad.php';
require_once __DIR__ . '/../inc/mime.php';
require_once __DIR__ . '/../inc/pdf.php';
require_once __DIR__ . '/../inc/adjuntos.php';

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCIÓN: Escribir log de envío en archivo
// Definida ANTES de su uso (línea 410) porque al estar envuelta en un guard
// condicional PHP NO registra la función en tiempo de compilación (sin hoisting).
// ═══════════════════════════════════════════════════════════════════════════════

if (!function_exists('escribirLogEnvio')) {
/**
 * Escribe una línea de log de envío en el archivo diario.
 * Formato: [YYYY-MM-DD HH:MM:SS] RESULTADO | CLUB | EMAIL | CUENTA_SMTP | TRACKING_ID | ERROR (si aplica)
 */
function escribirLogEnvio(string $logDir, string $resultado, string $club, string $email, string $cuentaSmtp, string $trackingId, string $error): void
{
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $archivo = $logDir . '/envios_' . date('Y-m-d') . '.log';
    $icono   = $resultado === 'OK' ? '✅' : '❌';
    $linea   = sprintf(
        "[%s] %s %s | Club: %s | Email: %s | SMTP: %s | Tracking: %s%s\n",
        date('Y-m-d H:i:s'),
        $icono,
        $resultado,
        $club,
        $email,
        $cuentaSmtp,
        $trackingId,
        $error ? ' | Error: ' . $error : ''
    );
    @file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}
}

// ─── PARÁMETROS ──────────────────────────────────────────────────────────────
$idClub     = (int)($_POST['id_club'] ?? 0);
$idPlantilla = (int)($_POST['id_plantilla'] ?? 0);
$idSmtp     = (int)($_POST['id_cuenta_smtp'] ?? 0);
$idCampanaRaw = $_POST['campaign_id'] ?? $_POST['id_campana'] ?? null;
if ($idCampanaRaw === null || trim((string)$idCampanaRaw) === '') {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'campaign_id requerido', 'razon' => 'NO_CAMPAIGN']);
    exit;
}
$idCampana = (int)$idCampanaRaw;
if ($idCampana <= 0) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'campaign_id inválido', 'razon' => 'NO_CAMPAIGN']);
    exit;
}

// SAFE MODE: comprobar desde BD, no solo desde POST (anti-bypass)
$modoEntornoBD = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$modoTestBD = ($modoEntornoBD === 'test');
$modoTest   = $modoTestBD || ($_POST['modo_test'] ?? '0') === '1';
$varianteAb = strtoupper($_POST['variante_ab'] ?? 'A');
if (!in_array($varianteAb, ['A', 'B', 'C'], true)) {
    $varianteAb = 'A';
}
// Rotación ABC: el sistema calcula la variante siguiente (A→B→C→A) y la fuerza
// aquí (como en modo test); el envío de rotación respeta esa variante.
$esRotacion = (($_POST['es_rotacion'] ?? '0') === '1') ? 1 : 0;

// ─── Validación de campaña (existencia + estado + activo + entorno) ──────
// Política ÚNICA compartida con P3 (cron.php). SIN debilitamientos: se aplica
// igual en MODO PRUEBAS y en PRODUCCIÓN. Una campaña DRAFT NO es operable para
// pruebas de envío; debe estar PILOT/ACTIVE (y coherente con el entorno) para
// poder llegar a SMTP. La UI realiza la misma prevalidación para no prometer
// un envío que el backend va a rechazar.
try {
    $validacion = validarCampanaActiva($db, $idCampana, $modoEntornoBD);
    if (!$validacion['ok']) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Campaña no válida', 'razon' => $validacion['razon']]);
        exit;
    }
} catch (\Exception $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'Error validando campaña', 'razon' => 'CAMPAIGN_VALIDATION_ERROR']);
    exit;
}

// ─── Variante (FASE 3) ──────────────────────────────────────────────────
// - PRODUCCIÓN/real: determinística e inmutable (asignarVariante), para que
//   un retry/reanudación nunca cambie la variante.
// - PRUEBA (modo_test): respeta la variante explícita A/B/C elegida en la UI,
//   de modo que "Enviar correos de prueba" entregue las 3 variantes distintas.
$varianteUsada = ($modoTest || $esRotacion === 1) ? $varianteAb : asignarVariante($idClub, $idCampana);

// ─── VALIDAR ─────────────────────────────────────────────────────────────────
try {
    if ($idClub <= 0 || $idPlantilla <= 0 || $idSmtp <= 0) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Faltan parámetros: id_club, id_plantilla, id_cuenta_smtp']);
        exit;
    }

    // ─── 1. Obtener datos del club ────────────────────────────────────────────
    $club = $db->querySingle("
        SELECT id, nombre_club, email, federacion, persona_contacto, telefono_movil, tiene_whatsapp
        FROM clubes_crm WHERE id = {$idClub}
    ", true);

    if (!$club) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Club no encontrado (id=' . $idClub . ')']);
        exit;
    }

    if (empty($club['email']) || !filter_var($club['email'], FILTER_VALIDATE_EMAIL)) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Email inválido: ' . ($club['email'] ?? 'vacío')]);
        exit;
    }

    // ─── 1.5 Elegibilidad (supresión + TEST/PILOT) ────────────────────────────
    $elig = esElegibleParaEnvio($db, (int)$club['id'], $idCampana);
    if (!$elig['ok']) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Lead no elegible para envío', 'razon' => $elig['razon']]);
        exit;
    }

    // ─── 2. Obtener plantilla ─────────────────────────────────────────────────
    $plantilla = $db->querySingle("
        SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria
        FROM plantillas WHERE id = {$idPlantilla} AND activo = 1
    ", true);

    if (!$plantilla) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Plantilla no encontrada (id=' . $idPlantilla . ')']);
        exit;
    }

    // A/B/C: resolver contenido exacto por variante (centralizado, FASE 3)
    $contenido = resolverContenidoVariante($plantilla, $varianteUsada);
    $asuntoTpl = $contenido['asunto'];
    $cuerpoTpl = $contenido['cuerpo'];

    // OVERRIDE A MEDIDA (modal de atención): si llega asunto/cuerpo personalizados
    // se usan en lugar del contenido de la plantilla. Los placeholders ({{CLUB}}…)
    // se resuelven igual en el paso 4, así que el texto a medida puede usarlos.
    $asuntoOverride = trim((string)($_POST['asunto'] ?? ''));
    $cuerpoOverride = trim((string)($_POST['cuerpo'] ?? ''));
    // Sanear UTF-8 (bytes malformados del editor rompían json_encode de la Bandeja).
    if ($cuerpoOverride !== '' && preg_match('//u', $cuerpoOverride) !== 1) {
        $cuerpoOverride = mb_convert_encoding($cuerpoOverride, 'UTF-8', 'UTF-8');
    }
    if ($asuntoOverride !== '') $asuntoTpl = $asuntoOverride;
    if ($cuerpoOverride !== '') $cuerpoTpl = $cuerpoOverride;
    // Envío A MEDIDA / respuesta (modal "Atender"): asunto o cuerpo personalizados
    // indican una comunicación extra del comercial. NO debe chocar con la
    // idempotencia del envío base (1 fila por (lead,campaña)) — se registra como
    // fila NUEVA en envios (campaign_id NULL) para que quede constancia real.
    $esMedida = ($asuntoOverride !== '' || $cuerpoOverride !== '');

    // ─── 3. Obtener cuenta SMTP ──────────────────────────────────────────────
    $cuenta = $db->querySingle("
        SELECT id, email, usuario, password, host, puerto, seguridad, enviados_hoy, limite_diario, activa, nombre_emisor, cargo_emisor
        FROM cuentas_smtp WHERE id = {$idSmtp}
    ", true);

    if (!$cuenta) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Cuenta SMTP no encontrada (id=' . $idSmtp . ')']);
        exit;
    }

    if ((int)$cuenta['activa'] !== 1) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Cuenta SMTP inactiva: ' . $cuenta['email']]);
        exit;
    }

    // Verificar límite diario
    $enviadosHoyReal = (int)$db->querySingle("
        SELECT COUNT(*) FROM comunicaciones_log
        WHERE id_cuenta_smtp = {$idSmtp}
          AND DATE(fecha) = DATE('now')
          AND tipo_evento = 'envio_email'
    ");
    if ($enviadosHoyReal >= (int)$cuenta['limite_diario']) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Cuenta SMTP saturada: ' . $cuenta['email'] . ' (' . $enviadosHoyReal . '/' . $cuenta['limite_diario'] . ')']);
        exit;
    }

    // ─── 4. Preparar contenido ─────────────────────────────────────────────────
    $nombreClub   = $club['nombre_club'];
    $emailClub    = $club['email'];
    $federacion   = $club['federacion'] ?? '';
    $contacto     = $club['persona_contacto'] ?: 'responsable';
    $TRACK_URL    = 'https://getfutprotec.com/outbound/api/track.php';

    // Datos del remitente dinámico
    $senderName  = $cuenta['nombre_emisor'] ?? '';
    $senderTitle = $cuenta['cargo_emisor'] ?? '';
    $senderEmail = $cuenta['email'];
    // Fallback si no hay nombre: extraer del email
    if (empty($senderName)) {
        $senderName = ucfirst(explode('@', $senderEmail)[0]);
    }

    $replacements = [
        '{{CLUB}}'         => $nombreClub,
        '{[CLUB]}'         => $nombreClub,
        '[[CLUB]]'         => $nombreClub,
        '{{CONTACTO}}'     => $contacto,
        '{[CONTACTO]}'     => $contacto,
        '[[CONTACTO]]'     => $contacto,
        '{{FEDERACION}}'   => $federacion,
        '{[FEDERACION]}'   => $federacion,
        '[[FEDERACION]]'   => $federacion,
        '{{ANIO}}'         => date('Y'),
        '{[ANIO]}'         => date('Y'),
        '[[ANIO]]'         => date('Y'),
        '{{EMAIL}}'        => $emailClub,
        '{[EMAIL]}'        => $emailClub,
        '[[EMAIL]]'        => $emailClub,
        '{{SENDER_NAME}}'  => $senderName,
        '{[SENDER_NAME]}'  => $senderName,
        '[[SENDER_NAME]]'  => $senderName,
        '{{SENDER_TITLE}}' => $senderTitle,
        '{[SENDER_TITLE]}' => $senderTitle,
        '[[SENDER_TITLE]]' => $senderTitle,
        '{{SENDER_EMAIL}}' => $senderEmail,
        '{[SENDER_EMAIL]}' => $senderEmail,
        '[[SENDER_EMAIL]]' => $senderEmail,
    ];

    $asunto = str_replace(array_keys($replacements), array_values($replacements), $asuntoTpl);
    $cuerpo = str_replace(array_keys($replacements), array_values($replacements), $cuerpoTpl);

    // RED DE SEGURIDAD anti-spam: NUNCA dejar un placeholder sin resolver en
    // asunto/cuerpo ({{…}}, {[…]} o [[…]]) — son señal de plantilla sin procesar
    // y los filtros (p.ej. SpamExperts de riobabel) descartan el correo.
    $patronPlaceholders = '/\{\{[^}]*\}\}|\{\[[^\]]*\]\}|\[\[[^\]]*\]\]/';
    $asunto = trim((string)preg_replace($patronPlaceholders, '', $asunto));
    $asunto = trim((string)preg_replace('/\s+/', ' ', $asunto));          // espacios múltiples
    $asunto = trim((string)preg_replace('/^\s*[-,;:.]+\s*/', '', $asunto)); // separadores residuales al inicio
    $cuerpo = (string)preg_replace($patronPlaceholders, '', $cuerpo);
    // Evitar asuntos vacíos tras la limpieza (fallback comercial seguro).
    if ($asunto === '') {
        $asunto = 'Espinilleras personalizadas - ' . $nombreClub;
    }

    // Generar tracking_id único para el píxel de seguimiento
    $trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));

    // ─── 4.1b Enlace de baja seguro (GO-LIVE UNSUBSCRIBE) ─────────────────────
    // Para nuevos envíos se sustituye el enlace de baja `?email={email}` por la
    // versión con token `?t={tracking_id}`. El tracking_id es un token aleatorio
    // criptográficamente seguro que NO expone el email en la URL y permite a
    // baja.php resolver el destinatario desde la tabla `envios`.
    // Compatibilidad: los enlaces antiguos `?email=` siguen funcionando en baja.php.
    $bajaUrlEmail = 'https://getfutprotec.com/outbound/api/baja.php?email=' . $emailClub;
    $bajaUrlToken = 'https://getfutprotec.com/outbound/api/baja.php?t=' . $trackingId;
    if (strpos($cuerpo, $bajaUrlEmail) !== false) {
        $cuerpo = str_replace($bajaUrlEmail, $bajaUrlToken, $cuerpo);
    }

    // Tipo de plantilla: decide el Content-Type MIME y la construcción de partes.

    // - texto_plano → multipart/alternative con text/plain (contenido original con
    //   saltos de línea) + text/html (mismo contenido convertido a HTML mínimo con
    //   el píxel de tracking). El píxel SOLO vive en la parte HTML.
    // - html        → text/html; charset=UTF-8, se inyecta el píxel de tracking.
    $tipoPlantilla = strtolower(trim((string)($plantilla['tipo'] ?? 'texto_plano')));
    $esHtml = ($tipoPlantilla === 'html');

    // ─── 4.1 Construcción de partes MIME ──────────────────────────────────────
    // Ambas partes proceden de la MISMA variable base $cuerpo (contenido comercial
    // tras sustituir placeholders). Esto evita divergencias A/B/C.
    $plainPart = '';
    $htmlPart  = '';

    if ($esHtml) {
        // Plantilla HTML: comportamiento histórico intacto. El cuerpo ya es HTML
        // y se inyecta el píxel de tracking + fingerprint anti-detección.
        $fingerprint = bin2hex(random_bytes(8));  // 🎲 hash único por email (anti-spam)
        $pixel = '<img src="' . $TRACK_URL . '?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';
        $antiDetect = "\n<!-- fpid:{$fingerprint} -->\n";  // invisible para humanos, único para filtros
        if (stripos($cuerpo, '</body>') !== false) {
            $cuerpo = str_ireplace('</body>', $pixel . $antiDetect . "\n</body>", $cuerpo);
        } else {
            $cuerpo .= "\n" . $pixel . $antiDetect;
        }
        $htmlPart = $cuerpo;
    } else {
        // Plantilla texto_plano: construir las dos representaciones del MISMO
        // contenido comercial.
        //  - plainPart: contenido original con saltos de línea, SIN HTML, SIN píxel.
        //  - htmlPart : mismo contenido convertido a HTML mínimo + píxel de tracking.
        $plainPart = $cuerpo;
        $htmlPart  = convertirContenidoAHtml($cuerpo, $TRACK_URL, $trackingId);
    }


    // ─── 5. Determinar destinatario ────────────────────────────────────────────
    $testEmailOverride = trim($_POST['test_email'] ?? '');
    if ($modoTest && $testEmailOverride !== '' && filter_var($testEmailOverride, FILTER_VALIDATE_EMAIL)) {
        $emailDestino = $testEmailOverride;
    } elseif ($modoTest) {
        $emailDestino = 'contactofutprotec@gmail.com';
    } else {
        $emailDestino = $emailClub;
    }

    // ─── 6. Reservar el envío lógico ANTES de SMTP (idempotencia + concurrencia) ──
    // PRUEBA (modo_test): reserva con campaign_id NULL para que las 3 variantes
    // A/B/C sobre el MISMO lead no colisionen con idx_envios_lead_campaign ni
    // bloqueen el envío comercial posterior. PRODUCCIÓN: idempotente contra
    // campaign_id (un lead → una fila por campaña), comportamiento intacto.
    $campaignIdParaReserva = $modoTest ? 0 : ($esMedida ? 0 : $idCampana);
    $reserva = reservarEnvioLogico(
        $db,
        (int)$club['id'],
        $campaignIdParaReserva,
        $nombreClub,
        $emailClub,
        $federacion,
        $cuenta['email'],
        $trackingId,
        $asunto,
        $cuerpo,
        ($campaignIdParaReserva > 0) ? $varianteUsada : $varianteAb,
        $idPlantilla,
        $idSmtp,
        $modoTest ? 1 : 0,
        $esRotacion,
        // FASE 2: encadenar el follow-up al envío/respuesta original (si procede).
        (int)($_POST['parent_envio_id'] ?? 0) > 0 ? (int)$_POST['parent_envio_id'] : null,
        (int)($_POST['respuesta_origen_id'] ?? 0) > 0 ? (int)$_POST['respuesta_origen_id'] : null
    );


    $envioRow = $db->querySingle(
        "SELECT id, estado, tracking_id, asunto, cuerpo_mensaje, message_id FROM envios WHERE id = {$reserva['id']}",
        true
    );
    if (!$envioRow) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No se pudo reservar el envío lógico']);
        exit;
    }

    // Si ya está en estado final, no reenviar: devolver el envío existente.
    if (in_array($envioRow['estado'], ['enviado', 'abierto'], true)) {
        ob_clean();
        echo json_encode([
            'ok'            => true,
            'dup'           => true,
            'envio_exitoso' => true,
            'estado'        => $envioRow['estado'],
            'error_smtp'    => '',
            'club'          => $nombreClub,
            'email'         => $emailClub,
            'cuenta_smtp'   => $cuenta['email'],
            'cuenta_id'     => $idSmtp,
            'tracking_id'   => $envioRow['tracking_id'],
            'timestamp'     => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    // ─── 7. Enviar SMTP usando el contenido ya reservado ────────────────────────
    $asuntoEnvio = $envioRow['asunto'] !== '' ? $envioRow['asunto'] : $asunto;
    $cuerpoEnvio = $envioRow['cuerpo_mensaje'] !== '' ? $envioRow['cuerpo_mensaje'] : $cuerpo;

    // ─── 7.1 Adjuntos PDF (presupuesto + boceto de espinilleras) ────────────────
    $adjuntos = [];
    $adjuntarPresupuesto = (($_POST['adjuntar_presupuesto'] ?? '0') === '1') || (($_POST['incluir_proforma'] ?? '0') === '1');
    $adjuntarBoceto      = (($_POST['adjuntar_boceto'] ?? '0') === '1') || (($_POST['incluir_mockup'] ?? '0') === '1');
    $slugClub = preg_replace('/[^A-Za-z0-9]+/', '_', $nombreClub);
    if ($adjuntarPresupuesto) {
        $presu = $db->querySingle("SELECT importe_total, estado, version FROM presupuestos WHERE lead_id = {$idClub} ORDER BY version DESC LIMIT 1", true);
        $importe = ($presu && $presu['importe_total']) ? number_format((float)$presu['importe_total'], 0, ',', '.') . ' €' : 'A convenir';
        $adjuntos[] = [
            'nombre'    => 'presupuesto_' . $slugClub . '.pdf',
            'mime'      => 'application/pdf',
            'contenido' => generarPdfPresupuesto([
                'club'     => $nombreClub,
                'importe'  => $importe,
                'contacto' => $senderName,
            ]),
        ];
    }
    if ($adjuntarBoceto) {
        $adjuntos[] = [
            'nombre'    => 'boceto_espinilleras_' . $slugClub . '.pdf',
            'mime'      => 'application/pdf',
            'contenido' => generarPdfBoceto(['club' => $nombreClub]),
        ];
    }

    // Adjuntos MANUALES subidos en el modal de atención (input file 'adjunto[]').
    if (!empty($_FILES['adjunto'])) {
        $fA = $_FILES['adjunto'];
        $names = is_array($fA['name'] ?? null) ? $fA['name'] : (isset($fA['name']) ? [$fA['name']] : []);
        $tmps  = is_array($fA['tmp_name'] ?? null) ? $fA['tmp_name'] : (isset($fA['tmp_name']) ? [$fA['tmp_name']] : []);
        $mimes = is_array($fA['type'] ?? null) ? $fA['type'] : (isset($fA['type']) ? [$fA['type']] : []);
        $errs  = is_array($fA['error'] ?? null) ? $fA['error'] : (isset($fA['error']) ? [$fA['error']] : []);
        $totalA = 0;
        foreach ($names as $i => $nombre) {
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmpP = (string)($tmps[$i] ?? '');
            if ($tmpP === '' || !is_uploaded_file($tmpP)) continue;
            $bin = (string)file_get_contents($tmpP);
            $totalA += strlen($bin);
            if ($totalA > 8 * 1024 * 1024) {
                ob_clean();
                echo json_encode(['ok' => false, 'error' => 'El total de adjuntos no puede superar 8 MB.']);
                exit;
            }
            $adjuntos[] = [
                'nombre'    => basename((string)$nombre),
                'mime'      => (string)($mimes[$i] ?? 'application/octet-stream'),
                'contenido' => $bin,
            ];
        }
    }

    // ─── 7.2 Adjuntos predeterminados de la plantilla (editor → repositorio) ──
    $stmtPA = $db->prepare(
        "SELECT ar.id, ar.nombre, ar.mime, ar.tamano, ar.datos
         FROM plantillas_adjuntos pa JOIN adjuntos_repo ar ON ar.id = pa.adjunto_repo_id
         WHERE pa.plantilla_id = :pid AND pa.activo = 1 ORDER BY pa.orden ASC"
    );
    $stmtPA->bindValue(':pid', $idPlantilla, SQLITE3_INTEGER);
    $resPA = $stmtPA->execute();
    while ($pa = $resPA->fetchArray(SQLITE3_ASSOC)) {
        $contenidoAdj = (string)($pa['datos'] ?? '');
        if ($contenidoAdj === '') continue;
        $adjuntos[] = [
            'nombre'    => (string)$pa['nombre'],
            'mime'      => (string)($pa['mime'] ?: 'application/octet-stream'),
            'contenido' => $contenidoAdj,
        ];
    }

    $resultado = enviarSMTPAutenticado(
        $cuenta,
        $emailDestino,
        $asuntoEnvio,
        $cuerpoEnvio,
        $envioRow['message_id'] ?? null,
        $tipoPlantilla,
        $plainPart,
        $htmlPart,
        $adjuntos
    );


    $estadoEnvio = $resultado['ok'] ? 'enviado' : 'error';
    $errorMsg    = $resultado['error'] ?? '';
    $trackingIdFinal = $envioRow['tracking_id'] !== '' ? $envioRow['tracking_id'] : $trackingId;

    // Actualizar la MISMA fila lógica con el resultado SMTP.
    // resultado_envio es la fuente inmutable de aceptación (ACCEPTED/FAILED),
    // separada del estado de ciclo de vida (pendiente/enviado/abierto/error).
    $resultadoEnvio = $resultado['ok'] ? 'ACCEPTED' : 'FAILED';
    $stmtUpd = $db->prepare("UPDATE envios SET estado = :est, resultado_envio = :res, fecha_resultado_envio = CURRENT_TIMESTAMP WHERE id = :id");
    $stmtUpd->bindValue(':est', $estadoEnvio, SQLITE3_TEXT);
    $stmtUpd->bindValue(':res', $resultadoEnvio, SQLITE3_TEXT);
    $stmtUpd->bindValue(':id', (int)$envioRow['id'], SQLITE3_INTEGER);
    $stmtUpd->execute();

    // Guardar los ADJUNTOS salientes (presupuesto, boceto y manuales) para que
    // aparezcan como chips 📎 descargables en el hilo de la Bandeja y en la
    // charla del modal (tabla envios_adjuntos, consultada por get_respuestas).
    if (!empty($adjuntos) && (int)$envioRow['id'] > 0) {
        $clubIdAdj = (int)($club['id'] ?? 0);
        foreach ($adjuntos as $adj) {
            $bin = (string)($adj['contenido'] ?? '');
            $rutaA = futprotec_guardar_adjunto($clubIdAdj, 'enviados', (string)($adj['nombre'] ?? 'adjunto'), $bin);
            $stmtAdj = $db->prepare('INSERT INTO envios_adjuntos (envio_id, nombre, mime, tamano, datos, ruta) VALUES (:e, :n, :m, :t, :d, :r)');
            $stmtAdj->bindValue(':e', (int)$envioRow['id'], SQLITE3_INTEGER);
            $stmtAdj->bindValue(':n', (string)($adj['nombre'] ?? 'adjunto'), SQLITE3_TEXT);
            $stmtAdj->bindValue(':m', (string)($adj['mime'] ?? 'application/octet-stream'), SQLITE3_TEXT);
            $stmtAdj->bindValue(':t', strlen($bin), SQLITE3_INTEGER);
            $stmtAdj->bindValue(':d', $bin, SQLITE3_BLOB);
            $stmtAdj->bindValue(':r', $rutaA, SQLITE3_TEXT);
            $stmtAdj->execute();
        }
    }

    // Envío a medida: si el modal marcó "Incluir mockup", pasa a enviado al éxito.
    if ($estadoEnvio === 'enviado' && ($_POST['marcar_mockup_enviado'] ?? '0') === '1') {
        $db->exec("UPDATE mockups SET estado = 'enviado', enviado_en = CURRENT_TIMESTAMP WHERE lead_id = {$idClub} AND estado IN ('solicitado','en_produccion')");
    }

    // Insertar en comunicaciones_log
    $stmtLog = $db->prepare(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, plantilla_id, id_cuenta_smtp, tipo, resultado, codigo_error, variante_ab, detalles, fecha)
         VALUES (:lid, :cid, 'envio_email', :pid, :sid, 'email', :res, :err, :vab, :det, CURRENT_TIMESTAMP)"
    );
    $stmtLog->bindValue(':lid', $club['id'],                    SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $club['id'],                    SQLITE3_INTEGER);
    $stmtLog->bindValue(':pid', $idPlantilla,                   SQLITE3_INTEGER);
    $stmtLog->bindValue(':sid', $idSmtp,                        SQLITE3_INTEGER);
    $stmtLog->bindValue(':res', $resultado['ok'] ? 'exito' : 'error', SQLITE3_TEXT);
    $stmtLog->bindValue(':err', mb_substr($errorMsg, 0, 255),  SQLITE3_TEXT);
    $stmtLog->bindValue(':vab', $varianteUsada,                 SQLITE3_TEXT);
    $detalleLog = $modoTest
        ? '[TEST campaña ' . $idCampana . '] Envío a ' . $emailClub . ' con plantilla ' . $plantilla['nombre']
        : 'Envío a ' . $emailClub . ' con plantilla ' . $plantilla['nombre'];
    $stmtLog->bindValue(':det', $detalleLog, SQLITE3_TEXT);
    $stmtLog->execute();

    // Actualizar contador de cuenta SMTP
    if ($resultado['ok']) {
        $db->exec("UPDATE cuentas_smtp SET ultimo_uso = CURRENT_TIMESTAMP, ultimo_error = NULL WHERE id = {$idSmtp}");
        // E-2/FI-005: la columna enviados_hoy se sincroniza con el recuento REAL
        // de comunicaciones_log (fuente de verdad), no con un contador acumulado.
        sincronizarEnviadosHoyCuenta($db, $idSmtp);
    } else {
        $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString($errorMsg) . "', ultimo_uso = CURRENT_TIMESTAMP WHERE id = {$idSmtp}");
    }

    // Cambiar estado del club SOLO si NO es modo pruebas
    if ($resultado['ok'] && !$modoTest) {
        $ts = date('d/m H:i');
        $nuevaObs = "[LANZADERA {$ts}] Email enviado con plantilla '{$plantilla['nombre']}' via {$cuenta['email']}";
        $obsExistente = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$idClub}");
        $obsMerge = $obsExistente ? $obsExistente . "\n" . $nuevaObs : $nuevaObs;

        $stmtUpd = $db->prepare("UPDATE clubes_crm SET estado_lead = '02 Contactado', observaciones = :obs, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
        $stmtUpd->bindValue(':obs', $obsMerge, SQLITE3_TEXT);
        $stmtUpd->bindValue(':id', $idClub, SQLITE3_INTEGER);
        $stmtUpd->execute();
    }
    // En modo pruebas: registrar nota sin cambiar estado
    if ($resultado['ok'] && $modoTest) {
        $ts = date('d/m H:i');
        $nuevaObs = "[TEST {$ts}] Email de prueba enviado a {$emailDestino} con plantilla '{$plantilla['nombre']}' (lead original: {$emailClub})";
        $obsExistente = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$idClub}");
        $obsMerge = $obsExistente ? $obsExistente . "\n" . $nuevaObs : $nuevaObs;
        $stmtUpd = $db->prepare("UPDATE clubes_crm SET observaciones = :obs, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
        $stmtUpd->bindValue(':obs', $obsMerge, SQLITE3_TEXT);
        $stmtUpd->bindValue(':id', $idClub, SQLITE3_INTEGER);
        $stmtUpd->execute();
    }

    // ─── 6.5 Escribir log en archivo ──────────────────────────────────────────
    escribirLogEnvio(
        $LOG_DIR,
        $resultado['ok'] ? 'OK' : 'ERROR',
        $nombreClub,
        $emailClub,
        $cuenta['email'],
        $trackingId,
        $errorMsg
    );

    // ─── 7. Respuesta ─────────────────────────────────────────────────────────
    ob_clean();
    echo json_encode([
        'ok'            => true,
        'envio_exitoso' => $resultado['ok'],
        'estado'        => $estadoEnvio,
        'error_smtp'    => $errorMsg,
        'club'          => $nombreClub,
        'email'         => $emailClub,
        'cuenta_smtp'   => $cuenta['email'],
        'cuenta_id'     => $idSmtp,
        'timestamp'     => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

$db->close();

// Las funciones convertirContenidoAHtml() y enviarSMTPAutenticado() se definen
// en inc/mime.php (incluido arriba). La función escribirLogEnvio() se define al
// inicio del archivo para que esté disponible antes de su primer uso.
