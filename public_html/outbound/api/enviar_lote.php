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

// ─── Validación de campaña (existencia + estado + activo + entorno) ──────
// Política ÚNICA compartida con P3 (cron.php).
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

// ─── Variante determinística (FASE 3) — inmutable en retry ──────────────
$varianteUsada = asignarVariante($idClub, $idCampana);

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
        '{{CONTACTO}}'      => $contacto,
        '{{FEDERACION}}'    => $federacion,
        '{{ANIO}}'          => date('Y'),
        '{{EMAIL}}'         => $emailClub,
        '{{SENDER_NAME}}'   => $senderName,
        '{{SENDER_TITLE}}'  => $senderTitle,
        '{{SENDER_EMAIL}}'  => $senderEmail,
    ];

    $asunto = str_replace(array_keys($replacements), array_values($replacements), $asuntoTpl);
    $cuerpo = str_replace(array_keys($replacements), array_values($replacements), $cuerpoTpl);

    // Generar tracking_id único para el píxel de seguimiento
    $trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));

    // Inyectar píxel de tracking y fingerprint anti-detección al final del cuerpo
    $fingerprint = bin2hex(random_bytes(8));  // 🎲 hash único por email (anti-spam)
    $pixel = '<img src="' . $TRACK_URL . '?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';
    $antiDetect = "\n<!-- fpid:{$fingerprint} -->\n";  // invisible para humanos, único para filtros
    if (stripos($cuerpo, '</body>') !== false) {
        $cuerpo = str_ireplace('</body>', $pixel . $antiDetect . "\n</body>", $cuerpo);
    } else {
        $cuerpo .= "\n" . $pixel . $antiDetect;
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
    $reserva = reservarEnvioLogico(
        $db,
        (int)$club['id'],
        $idCampana,
        $nombreClub,
        $emailClub,
        $federacion,
        $cuenta['email'],
        $trackingId,
        $asunto,
        $cuerpo,
        ($idCampana > 0) ? $varianteUsada : null,
        $idPlantilla,
        $idSmtp
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
    $resultado = enviarSMTPAutenticado($cuenta, $emailDestino, $asuntoEnvio, $cuerpoEnvio, $envioRow['message_id'] ?? null);

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
    $stmtLog->bindValue(':det', 'Envío a ' . $emailClub . ' con plantilla ' . $plantilla['nombre'], SQLITE3_TEXT);
    $stmtLog->execute();

    // Actualizar contador de cuenta SMTP
    if ($resultado['ok']) {
        $db->exec("UPDATE cuentas_smtp SET enviados_hoy = enviados_hoy + 1, ultimo_uso = CURRENT_TIMESTAMP, ultimo_error = NULL WHERE id = {$idSmtp}");
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

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCIÓN: Escribir log de envío en archivo
// ═══════════════════════════════════════════════════════════════════════════════

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

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCIÓN SMTP AUTENTICADO NATIVO
// ═══════════════════════════════════════════════════════════════════════════════

function enviarSMTPAutenticado(array $cuenta, string $destinatario, string $asunto, string $cuerpoHTML, ?string $messageId = null): array
{
    $fromEmail = $cuenta['email'];
    $smtpHost  = $cuenta['host'];
    $smtpPort  = (int)$cuenta['puerto'];
    $smtpUser  = $cuenta['usuario'];
    $smtpPass  = $cuenta['password'];
    $seguridad = $cuenta['seguridad'] ?? 'ssl';

    $timeout = 30;
    $readTimeout = 15;

    try {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $remote = ($smtpPort === 465)
            ? "ssl://{$smtpHost}:{$smtpPort}"
            : "tcp://{$smtpHost}:{$smtpPort}";

        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);

        if (!$fp) {
            return ['ok' => false, 'error' => "Conexión fallida: {$errstr} ({$errno})"];
        }

        // Timeout de LECTURA explícito: evita que fgets() quede bloqueado
        // indefinidamente si el servidor acepta la conexión pero no responde.
        stream_set_timeout($fp, $readTimeout);

        $read = function() use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 512)) !== false) {
                $resp .= $line;
                if (preg_match('/^\d{3}\s/', $line)) break;
                if (!preg_match('/^\d{3}[- ]/', $line)) break;
            }
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Timeout de lectura SMTP');
            }
            return $resp;
        };

        $cmd = function(string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        // Leer banner
        $read();

        // EHLO
        $cmd("EHLO getfutprotec.com");

        // STARTTLS si puerto 587
        if ($smtpPort === 587) {
            $cmd("STARTTLS");
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            stream_set_timeout($fp, $readTimeout);
            $cmd("EHLO getfutprotec.com");
        }

        // AUTH LOGIN
        $cmd("AUTH LOGIN");
        $cmd(base64_encode($smtpUser));
        $cmd(base64_encode($smtpPass));

        // MAIL FROM
        $cmd("MAIL FROM:<{$fromEmail}>");

        // RCPT TO
        $cmd("RCPT TO:<{$destinatario}>");

        // DATA
        $cmd("DATA");

        // Construir mensaje con nombre del remitente dinámico
        $senderName  = $cuenta['nombre_emisor'] ?? '';
        $fromName = !empty($senderName) ? $senderName : ucfirst(explode('@', $fromEmail)[0]);
        $mensaje = "From: {$fromName} <{$fromEmail}>\r\n";
        $mensaje .= "Reply-To: {$fromEmail}\r\n";
        $mensaje .= "To: <{$destinatario}>\r\n";
        $mensaje .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
        if ($messageId !== null && $messageId !== '') {
            $mensaje .= "Message-ID: {$messageId}\r\n";
        }
        $mensaje .= "MIME-Version: 1.0\r\n";
        $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mensaje .= "X-Mailer: FutProtec-Lanzadera/2.0\r\n";
        $mensaje .= "\r\n";
        $mensaje .= $cuerpoHTML;
        $mensaje .= "\r\n.\r\n";

        fwrite($fp, $mensaje);
        $dataResp = $read();

        // Verificar respuesta (250 OK esperado)
        $sendOk = str_contains($dataResp, '250');

        // QUIT
        $cmd("QUIT");
        fclose($fp);

        if ($sendOk) {
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => 'Respuesta SMTP inesperada: ' . trim($dataResp)];

    } catch (\Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            @fclose($fp);
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}