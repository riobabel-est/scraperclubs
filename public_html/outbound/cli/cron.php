<?php
/**
 * cron.php — Script autónomo para trabajos cron en producción.
 * Diseñado para ejecutarse vía CLI: php cron.php
 * 
 * Funcionalidad:
 * 1. Verifica si el motor está activado en la BD.
 * 2. Selecciona la siguiente cuenta SMTP disponible (respetando límites de 50 envíos/día).
 * 3. Toma el siguiente lead en cola y ejecuta el envío de correo.
 * 4. Registra el evento en comunicaciones_log.
 * 5. Actualiza contadores de envíos en cuentas_smtp.
 */

declare(strict_types=1);

// Transporte SMTP centralizado (unifica las implementaciones previas).
require_once __DIR__ . '/../inc/smtp_transport.php';

// ─── Solo CLI ───
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se ejecuta desde CLI.\n";
    exit(1);
}

// ─── Configuración ───
$DB_PATH = __DIR__ . '/../data/stats.db';
$LIMITE_DIARIO = 50;  // Límite por defecto, se sobrescribe con el de la cuenta

if (!file_exists($DB_PATH)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

require_once __DIR__ . '/../inc/eligibilidad.php';

// ═════════════════════════════════════════════════════════════════════════════
// 0. CAMPAÑA OBLIGATORIA (FASE 2C) — cron no envía sin campaña válida y trazable
// ═════════════════════════════════════════════════════════════════════════════
$opts = getopt('', ['campaign-id:', 'campaign:']);
$campaignRaw = $opts['campaign-id'] ?? $opts['campaign'] ?? null;

if ($campaignRaw === null || trim((string)$campaignRaw) === '') {
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / NO CAMPAIGN — usa --campaign-id=N\n";
    $db->close();
    exit(1);
}

$campaignId = (int)$campaignRaw;
if ($campaignId <= 0) {
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / NO CAMPAIGN — campaign-id debe ser entero positivo\n";
    $db->close();
    exit(1);
}

$modoEntornoGlobal = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$validacion = validarCampanaActiva($db, $campaignId, $modoEntornoGlobal);
$reasonLabels = [
    'NO_CAMPAIGN'         => 'NO CAMPAIGN',
    'INVALID_CAMPAIGN'    => 'INVALID CAMPAIGN',
    'CAMPAIGN_NOT_ACTIVE' => 'CAMPAIGN NOT ACTIVE',
    'ENVIRONMENT_MISMATCH'=> 'ENVIRONMENT MISMATCH',
];
if (!$validacion['ok']) {
    $label = $reasonLabels[$validacion['razon']] ?? 'CAMPAIGN INVALID';
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / {$label}\n";
    $db->close();
    exit(1);
}

$campaign = $validacion['campaña'];
echo "[" . date('Y-m-d H:i:s') . "] Campaña válida: #{$campaignId} (estado={$campaign['estado']}, entorno={$campaign['entorno']})\n";

// ═════════════════════════════════════════════════════════════════════════════
// 1. Verificar si el motor está activado
// ═════════════════════════════════════════════════════════════════════════════
$motorEstado = $db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'");
if ($motorEstado !== 'activo') {
    echo "[" . date('Y-m-d H:i:s') . "] Motor PAUSADO. No se realiza ningún envío.\n";
    $db->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Motor ACTIVO. Iniciando ciclo de envío...\n";

// ═════════════════════════════════════════════════════════════════════════════
// 2. Seleccionar siguiente cuenta SMTP disponible
// ═════════════════════════════════════════════════════════════════════════════
$cuentaRow = $db->querySingle(
    "SELECT * FROM cuentas_smtp 
     WHERE activa = 1 AND enviados_hoy < limite_diario 
     ORDER BY enviados_hoy ASC, id ASC 
     LIMIT 1",
    true
);

if (!$cuentaRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ⚠️ No hay cuentas SMTP disponibles (todas han alcanzado su límite diario o están inactivas).\n";
    $db->close();
    exit(0);
}

$limiteCuenta = (int)$cuentaRow['limite_diario'];
$enviadosHoy  = (int)$cuentaRow['enviados_hoy'];

echo "[" . date('Y-m-d H:i:s') . "] Cuenta SMTP seleccionada: {$cuentaRow['email']} ({$enviadosHoy}/{$limiteCuenta} envíos hoy)\n";

// ═════════════════════════════════════════════════════════════════════════════
// 3. Seleccionar siguiente lead en cola (estado = "Sin Contactar")
// ═════════════════════════════════════════════════════════════════════════════
// AISLAMIENTO TEST/REAL (FASE 6F.6): la selección SQL NO puede devolver un
// lead incompatible con la campaña (campaña TEST → sólo leads TEST; campaña no
// TEST → nunca leads TEST). Mismo fragmento SQL que get_cola.php.
$filtroCompatibilidad = sqlFiltroCompatibilidadLeadCampana($db, $campaignId);

$leadRow = $db->querySingle(
    "SELECT c.* FROM clubes_crm c
     LEFT JOIN envios e ON LOWER(e.email) = LOWER(c.email) AND e.estado = 'enviado'
      WHERE c.estado_lead = '01 Sin Contactar'
       AND c.email IS NOT NULL AND c.email != ''
       AND e.id IS NULL
       {$filtroCompatibilidad}
     ORDER BY c.creado_el ASC
     LIMIT 1",
    true
);

if (!$leadRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ✅ No hay leads pendientes de primer contacto. Todos los clubes están en secuencia.\n";
    $db->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Lead seleccionado: #{$leadRow['id']} — {$leadRow['nombre_club']} ({$leadRow['email']})\n";

// ═════════════════════════════════════════════════════════════════════════════
// 3.5 Elegibilidad central (supresión) — defensa en profundidad
// ═════════════════════════════════════════════════════════════════════════════
$elig = esElegibleParaEnvio($db, (int)$leadRow['id'], $campaignId);
if (!$elig['ok']) {
    echo "[" . date('Y-m-d H:i:s') . "] 🚫 Lead #{$leadRow['id']} NO elegible ({$elig['razon']}). Se salta.\n";
    $db->close();
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. Verificar modo de entorno (test / producción)
// ═════════════════════════════════════════════════════════════════════════════
$modoEntorno = $db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test';

// ═════════════════════════════════════════════════════════════════════════════
// 5. Obtener plantilla activa
// ═════════════════════════════════════════════════════════════════════════════
$plantilla = $db->querySingle(
    "SELECT * FROM plantillas WHERE activo = 1 AND tipo = 'html' ORDER BY id ASC LIMIT 1",
    true
);

if (!$plantilla) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No hay plantilla HTML activa.\n";
    $db->close();
    exit(1);
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. Variante determinística + contenido por variante (FASE 3)
// ═════════════════════════════════════════════════════════════════════════════
$variantUsada = asignarVariante((int)$leadRow['id'], $campaignId);
$contenido = resolverContenidoVariante($plantilla, $variantUsada);

// ═════════════════════════════════════════════════════════════════════════════
// 6. Construir email
// ═════════════════════════════════════════════════════════════════════════════
$asunto = str_replace(
    ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
    [
        $leadRow['nombre_club'],
        $leadRow['persona_contacto'] ?: 'responsable',
        $leadRow['federacion'] ?? '',
        date('Y'),
    ],
    $contenido['asunto']
);

$cuerpo = str_replace(
    ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
    [
        $leadRow['nombre_club'],
        $leadRow['persona_contacto'] ?: 'responsable',
        $leadRow['federacion'] ?? '',
        date('Y'),
    ],
    $contenido['cuerpo']
);

// Generar tracking ID único
$trackingId = bin2hex(random_bytes(16));

// Incluir pixel de tracking en el cuerpo HTML
$pixelUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'getfutprotec.com') . "/outbound/api/track.php?id={$trackingId}";
$cuerpo .= "\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\">";

// ═════════════════════════════════════════════════════════════════════════════
// 6.5 Reservar envío lógico ANTES de SMTP (idempotencia por lead_id+campaign_id)
// ═════════════════════════════════════════════════════════════════════════════
$reserva = reservarEnvioLogico(
    $db,
    (int)$leadRow['id'],
    $campaignId,
    $leadRow['nombre_club'],
    $leadRow['email'],
    $leadRow['federacion'] ?? '',
    $cuentaRow['email'],
    $trackingId,
    $asunto,
    $cuerpo,
    $variantUsada,
    (int)$plantilla['id'],
    (int)$cuentaRow['id']
);

$envioRow = $db->querySingle(
    "SELECT id, estado, tracking_id, asunto, cuerpo_mensaje, message_id FROM envios WHERE id = " . $reserva['id'],
    true
);
if (!$envioRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: no se pudo reservar el envío lógico.\n";
    $db->close();
    exit(1);
}

if (in_array($envioRow['estado'], ['enviado', 'abierto'], true)) {
    echo "[" . date('Y-m-d H:i:s') . "] ⏭ Lead ya enviado en esta campaña (envio_id=" . $envioRow['id'] . "). No se reenvía.\n";
    $db->close();
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// 7. Enviar email
// ═════════════════════════════════════════════════════════════════════════════
$headers = [
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/html; charset=UTF-8',
    'From'         => $cuentaRow['email'],
    'Reply-To'     => $cuentaRow['email'],
    'X-Mailer'     => 'FutProtec Cron Engine',
    'X-Tracking-ID' => $trackingId,
    'X-Campaign'   => 'outbound_v1',
    'Message-ID'   => $envioRow['message_id'] ?? '',
];

$headerString = '';
foreach ($headers as $k => $v) {
    $headerString .= "{$k}: {$v}\r\n";
}

$enviado = false;
$errorMsg = '';

if ($modoEntorno === 'produccion') {
    try {
        // Intentar envío SMTP con autenticación
        $smtpHost = $cuentaRow['host'];
        $smtpPort = (int)$cuentaRow['puerto'];
        $smtpUser = $cuentaRow['usuario'];
        $smtpPass = futprotec_descifrarPassword($cuentaRow['password'] ?? '');
        $smtpSecure = $cuentaRow['seguridad']; // ssl o tls

        // Usar mail() como fallback si no hay configuración completa
        if (empty($smtpHost) || empty($smtpUser)) {
            $enviado = mail(
                $leadRow['email'],
                '=?UTF-8?B?' . base64_encode($asunto) . '?=',
                $cuerpo,
                $headerString
            );
        } else {
            // Envío con autenticación SMTP vía socket
            $enviado = enviarSMTP(
                $smtpHost, $smtpPort, $smtpSecure,
                $smtpUser, $smtpPass,
                $cuentaRow['email'],
                $leadRow['email'],
                $asunto, $cuerpo, $headers
            );
        }

        if (!$enviado) {
            $errorMsg = error_get_last()['message'] ?? 'Error desconocido en mail()';
        }
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
    }
} else {
    // Modo test: simular envío exitoso
    echo "[" . date('Y-m-d H:i:s') . "] 🧪 MODO PRUEBAS: Simulando envío a {$leadRow['email']}...\n";
    $enviado = true;
}

// ═════════════════════════════════════════════════════════════════════════════
// 8. Actualizar el envío lógico reservado con el resultado SMTP
// ═════════════════════════════════════════════════════════════════════════════
if ($enviado) {
    // Marcar la MISMA fila reservada como enviada, con resultado SMTP inmutable.
    $stmt = $db->prepare("UPDATE envios SET estado = 'enviado', resultado_envio = 'ACCEPTED', fecha_resultado_envio = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', (int)$envioRow['id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Registrar en comunicaciones_log
    $stmtLog = $db->prepare(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, plantilla_id, id_cuenta_smtp, tipo, resultado, detalles, fecha)
         VALUES (:lid, :cid, 'envio_email', :pid, :sid, 'email', 'exito', :det, CURRENT_TIMESTAMP)"
    );
    $stmtLog->bindValue(':lid', $leadRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $leadRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':pid', $plantilla['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':sid', $cuentaRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', "Email enviado vía {$cuentaRow['email']} (tracking: {$trackingId})", SQLITE3_TEXT);
    $stmtLog->execute();

    // Actualizar estado del lead
    $db->exec("UPDATE clubes_crm SET estado_lead = '02 Contactado', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadRow['id']}");

    // Incrementar contador de envíos de la cuenta SMTP
    $db->exec("UPDATE cuentas_smtp SET enviados_hoy = enviados_hoy + 1 WHERE id = {$cuentaRow['id']}");

    echo "[" . date('Y-m-d H:i:s') . "] ✅ Email enviado correctamente a {$leadRow['email']} (tracking: {$trackingId})\n";
    echo "[" . date('Y-m-d H:i:s') . "] Lead #{$leadRow['id']} actualizado a '02 Contactado'\n";
    echo "[" . date('Y-m-d H:i:s') . "] Cuenta SMTP {$cuentaRow['email']}: " . ($enviadosHoy + 1) . "/{$limiteCuenta} envíos hoy\n";
} else {
    // Marcar la fila reservada como error (retryable, sin duplicar), resultado inmutable FAILED.
    $stmt = $db->prepare("UPDATE envios SET estado = 'error', resultado_envio = 'FAILED', fecha_resultado_envio = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', (int)$envioRow['id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Registrar error en la cuenta SMTP
    $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString($errorMsg) . "' WHERE id = {$cuentaRow['id']}");

    echo "[" . date('Y-m-d H:i:s') . "] ❌ ERROR al enviar a {$leadRow['email']}: {$errorMsg}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Error registrado en cuenta SMTP #{$cuentaRow['id']} (envio_id={$envioRow['id']} queda 'error')\n";
}

$db->close();
echo "[" . date('Y-m-d H:i:s') . "] Ciclo completado.\n";
exit(0);

// ═════════════════════════════════════════════════════════════════════════════
// FUNCIÓN AUXILIAR: envío SMTP con autenticación vía socket
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Envía un email usando SMTP con autenticación vía socket directo.
 *
 * @return bool true si el envío fue exitoso
 */
function enviarSMTP(
    string $host, int $port, string $secure,
    string $user, string $pass,
    string $from, string $to,
    string $subject, string $body, array $headers
): bool {
    // Normalizar la cuenta para el transporte centralizado.
    $cuenta = [
        'email'     => $from,
        'host'      => $host,
        'puerto'    => (int)$port,
        'usuario'   => $user,
        'password'  => $pass,
        'seguridad' => $secure,
    ];

    // Extraer Message-ID de los headers si existe.
    $messageId = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'message-id') {
            $messageId = $v;
            break;
        }
    }

    $opciones = [
        'reply_to' => $from,
    ];
    if ($messageId !== '') {
        $opciones['message_id'] = $messageId;
    }

    // Delegar en el transporte SMTP centralizado.
    $resultado = futprotec_enviarSMTP($cuenta, $to, $subject, $body, $opciones);

    if (!$resultado['ok']) {
        trigger_error($resultado['error'], E_USER_WARNING);
        return false;
    }
    return true;
}
