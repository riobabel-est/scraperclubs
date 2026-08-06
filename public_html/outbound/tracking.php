<?php
/**
 * tracking.php — Pixel de seguimiento de aperturas (1x1 GIF transparente).
 * 
 * URL: /outbound/tracking.php?id=LOG_ID
 * 
 * Cuando se carga, registra un evento 'email_abierto' en comunicaciones_log
 * y en la tabla aperturas.
 * 
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$DB_PATH = __DIR__ . '/stats.db';

// ─── Obtener ID del log de comunicación ───
$logId = (int)($_GET['id'] ?? 0);

if ($logId <= 0) {
    // Sin ID válido, devolver el pixel igualmente (no romper el correo)
    sendPixel();
    exit;
}

if (!file_exists($DB_PATH)) {
    sendPixel();
    exit;
}

try {
    $db = new SQLite3($DB_PATH);
    $db->enableExceptions(true);
    $db->exec('PRAGMA busy_timeout=3000');

    // Buscar el registro en comunicaciones_log
    $log = $db->querySingle("SELECT * FROM comunicaciones_log WHERE id = {$logId}", true);
    
    if ($log) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Registrar apertura en comunicaciones_log (nuevo evento)
        $stmt = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, ip_registro, fecha)
             VALUES (:lid, :cid, 'email_abierto', :det, :ip, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':lid', $log['lead_id'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $log['club_id'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':det', 'Apertura desde ' . ($ip ?: 'desconocida'), SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->execute();

        // También registrar en tabla aperturas (legacy) si existe tracking_id en detalles
        $detalles = $log['detalles'] ?? '';
        if (preg_match('/tracking_id[:\s]+([a-f0-9\-]+)/i', $detalles, $m)) {
            $trackingId = $m[1];
            $stmt2 = $db->prepare(
                "INSERT INTO aperturas (tracking_id, fecha_apertura, ip, user_agent)
                 VALUES (:tid, CURRENT_TIMESTAMP, :ip, :ua)"
            );
            $stmt2->bindValue(':tid', $trackingId, SQLITE3_TEXT);
            $stmt2->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt2->bindValue(':ua', $ua, SQLITE3_TEXT);
            $stmt2->execute();
        }
    }

    $db->close();
} catch (\Exception $e) {
    // Silencioso: no romper el correo
}

// ─── Enviar pixel GIF 1x1 transparente ───
sendPixel();

/**
 * Envía un GIF transparente de 1x1 pixel.
 */
function sendPixel(): void
{
    // GIF 1x1 transparente (43 bytes)
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($gif));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $gif;
    exit;
}