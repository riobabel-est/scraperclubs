<?php
/**
 * track.php — Píxel de seguimiento de aperturas de email.
 * Recibe ?id=TRACKING_ID y registra la apertura en SQLite.
 * Retorna un PNG transparente 1x1 silencioso.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$trackingId = $_GET['id'] ?? '';

if ($trackingId === '') {
    // Sin ID: devolver pixel igual (no revelar error a scanners)
    sendPixel();
    exit;
}

// Sanitizar tracking_id: solo permitir caracteres seguros
$trackingId = preg_replace('/[^a-zA-Z0-9_-]/', '', $trackingId);

if ($trackingId === '') {
    sendPixel();
    exit;
}

$dbPath = __DIR__ . '/stats.db';

try {
    if (!file_exists($dbPath)) {
        sendPixel();
        exit;
    }

    $db = new SQLite3($dbPath);
    $db->exec('PRAGMA journal_mode=WAL');

    // Verificar que el tracking_id existe en envios
    $stmt = $db->prepare('SELECT id FROM envios WHERE tracking_id = :tid LIMIT 1');
    $stmt->bindValue(':tid', $trackingId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        // Tracking ID no válido
        $db->close();
        sendPixel();
        exit;
    }

    // Obtener el email del envío
    $envio = $db->querySingle("SELECT email, id FROM envios WHERE tracking_id = '" . $db->escapeString($trackingId) . "' LIMIT 1", true);

    // Registrar apertura
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Truncar user_agent por seguridad
    $userAgent = mb_substr($userAgent, 0, 500);

    $stmtInsert = $db->prepare(
        'INSERT INTO aperturas (tracking_id, ip, user_agent) VALUES (:tid, :ip, :ua)'
    );
    $stmtInsert->bindValue(':tid', $trackingId, SQLITE3_TEXT);
    $stmtInsert->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmtInsert->bindValue(':ua', $userAgent, SQLITE3_TEXT);
    $stmtInsert->execute();

    // Actualizar estado del envío a 'abierto' si no está ya
    $db->exec("UPDATE envios SET estado = 'abierto' WHERE tracking_id = '{$db->escapeString($trackingId)}' AND estado = 'enviado'");

    // Actualizar estado del lead en clubes_crm (solo la primera apertura)
    if ($envio && !empty($envio['email'])) {
        $email = $envio['email'];
        // Solo actualizar si el lead está en "Email Enviado / En Secuencia" (evitar downgrade)
        $club = $db->querySingle("SELECT id, estado_lead FROM clubes_crm WHERE LOWER(email) = LOWER('" . $db->escapeString($email) . "') LIMIT 1", true);
        if ($club && $club['estado_lead'] === 'Email Enviado / En Secuencia') {
            $ts = date('d/m H:i');
            $nuevaObs = "[TRACKING {$ts}] Email abierto (tracking: {$trackingId})";
            $obsExistente = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$club['id']}");
            $obsMerge = $obsExistente ? $obsExistente . "\n" . $nuevaObs : $nuevaObs;
            $stmtUpd = $db->prepare("UPDATE clubes_crm SET estado_lead = 'Impactado / Abrio Email', observaciones = :obs, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
            $stmtUpd->bindValue(':obs', $obsMerge, SQLITE3_TEXT);
            $stmtUpd->bindValue(':id', $club['id'], SQLITE3_INTEGER);
            $stmtUpd->execute();
        }
    }

    $db->close();
} catch (\Exception $e) {
    // Silenciar errores — solo registrar el pixel
    error_log("track.php error: " . $e->getMessage());
}

// Siempre devolver pixel transparente
sendPixel();

/**
 * Envía una imagen PNG 1x1 transparente con headers anti-caché.
 */
function sendPixel(): void
{
    // Headers anti-caché
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    // PNG 1x1 transparente (95 bytes)
    $pixel = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk' .
        '+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );

    echo $pixel;
}