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

// ─── Solo CLI ───
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se ejecuta desde CLI.\n";
    exit(1);
}

// ─── Configuración ───
$DB_PATH = __DIR__ . '/stats.db';
$LIMITE_DIARIO = 50;  // Límite por defecto, se sobrescribe con el de la cuenta

if (!file_exists($DB_PATH)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

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
$leadRow = $db->querySingle(
    "SELECT c.* FROM clubes_crm c
     LEFT JOIN envios e ON LOWER(e.email) = LOWER(c.email) AND e.estado = 'enviado'
     WHERE c.estado_lead = 'Sin Contactar'
       AND c.email IS NOT NULL AND c.email != ''
       AND e.id IS NULL
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
    $plantilla['asunto']
);

$cuerpo = str_replace(
    ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
    [
        $leadRow['nombre_club'],
        $leadRow['persona_contacto'] ?: 'responsable',
        $leadRow['federacion'] ?? '',
        date('Y'),
    ],
    $plantilla['cuerpo']
);

// Generar tracking ID único
$trackingId = bin2hex(random_bytes(16));

// Incluir pixel de tracking en el cuerpo HTML
$pixelUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'getfutprotec.com') . "/outbound/track.php?id={$trackingId}";
$cuerpo .= "\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\">";

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
        $smtpPass = $cuentaRow['password'];
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
// 8. Registrar envío en BD
// ═════════════════════════════════════════════════════════════════════════════
if ($enviado) {
    // Registrar en tabla envios
    $stmt = $db->prepare(
        "INSERT INTO envios (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje)
         VALUES (:club, :email, :fed, :cuenta, 'enviado', :tid, :asunto, :cuerpo)"
    );
    $stmt->bindValue(':club',   $leadRow['nombre_club'], SQLITE3_TEXT);
    $stmt->bindValue(':email',  $leadRow['email'],       SQLITE3_TEXT);
    $stmt->bindValue(':fed',    $leadRow['federacion'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':cuenta', $cuentaRow['email'],     SQLITE3_TEXT);
    $stmt->bindValue(':tid',    $trackingId,             SQLITE3_TEXT);
    $stmt->bindValue(':asunto', $asunto,                 SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo', $cuerpo,                 SQLITE3_TEXT);
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
    $db->exec("UPDATE clubes_crm SET estado_lead = 'Email Enviado / En Secuencia', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadRow['id']}");

    // Incrementar contador de envíos de la cuenta SMTP
    $db->exec("UPDATE cuentas_smtp SET enviados_hoy = enviados_hoy + 1 WHERE id = {$cuentaRow['id']}");

    echo "[" . date('Y-m-d H:i:s') . "] ✅ Email enviado correctamente a {$leadRow['email']} (tracking: {$trackingId})\n";
    echo "[" . date('Y-m-d H:i:s') . "] Lead #{$leadRow['id']} actualizado a 'Email Enviado / En Secuencia'\n";
    echo "[" . date('Y-m-d H:i:s') . "] Cuenta SMTP {$cuentaRow['email']}: " . ($enviadosHoy + 1) . "/{$limiteCuenta} envíos hoy\n";
} else {
    // Registrar error en la cuenta SMTP
    $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString($errorMsg) . "' WHERE id = {$cuentaRow['id']}");

    echo "[" . date('Y-m-d H:i:s') . "] ❌ ERROR al enviar a {$leadRow['email']}: {$errorMsg}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Error registrado en cuenta SMTP #{$cuentaRow['id']}\n";
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
    try {
        $errno = 0;
        $errstr = '';

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $remote = ($secure === 'ssl')
            ? "ssl://{$host}:{$port}"
            : "tcp://{$host}:{$port}";

        $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new \RuntimeException("No se pudo conectar a {$host}:{$port} — {$errstr} ({$errno})");
        }

        // Leer saludo del servidor
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '220') {
            throw new \RuntimeException("Saludo SMTP inesperado: {$resp}");
        }

        // EHLO
        fwrite($socket, "EHLO getfutprotec.com\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("EHLO fallido: {$resp}");
        }

        // STARTTLS si es TLS
        if ($secure === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $resp = leerRespuestaSMTP($socket);
            if (substr($resp, 0, 3) !== '220') {
                throw new \RuntimeException("STARTTLS fallido: {$resp}");
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO getfutprotec.com\r\n");
            leerRespuestaSMTP($socket);
        }

        // Autenticación
        fwrite($socket, "AUTH LOGIN\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '334') {
            throw new \RuntimeException("AUTH LOGIN no soportado: {$resp}");
        }

        fwrite($socket, base64_encode($user) . "\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '334') {
            throw new \RuntimeException("Usuario rechazado: {$resp}");
        }

        fwrite($socket, base64_encode($pass) . "\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '235') {
            throw new \RuntimeException("Contraseña rechazada: {$resp}");
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$from}>\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("MAIL FROM rechazado: {$resp}");
        }

        // RCPT TO
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("RCPT TO rechazado: {$resp}");
        }

        // DATA
        fwrite($socket, "DATA\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '354') {
            throw new \RuntimeException("DATA rechazado: {$resp}");
        }

        // Construir mensaje
        $boundary = '--=_FutProtec_' . md5(uniqid((string)time(), true));
        $message = "From: {$from}\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        foreach ($headers as $k => $v) {
            if (!in_array(strtolower($k), ['mime-version', 'content-type', 'from'])) {
                $message .= "{$k}: {$v}\r\n";
            }
        }
        $message .= "\r\n";
        $message .= $body;
        $message .= "\r\n.";

        fwrite($socket, $message . "\r\n");
        $resp = leerRespuestaSMTP($socket);
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("Envío de datos fallido: {$resp}");
        }

        // QUIT
        fwrite($socket, "QUIT\r\n");
        leerRespuestaSMTP($socket);

        fclose($socket);
        return true;
    } catch (\Throwable $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        trigger_error($e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Lee la respuesta multilínea del servidor SMTP.
 */
function leerRespuestaSMTP($socket): string
{
    $resp = '';
    while ($line = fgets($socket, 512)) {
        $resp .= $line;
        // Las respuestas SMTP multilínea tienen '-' después del código en líneas intermedias
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return trim($resp);
}