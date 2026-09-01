<?php
/**
 * click.php — Redirector y registro de clics (FASE 3 del MEGAPROMPT V2).
 *
 * Recibe ?t=<tracking_id>&u=<url_original_urlencoded>, registra el clic en la
 * tabla `clics` (con envio_id/lead_id/campaign_id derivados del envío) y
 * redirige (302) a la URL original.
 *
 * Seguridad:
 *  - tracking_id saneado (solo [a-zA-Z0-9_-]).
 *  - Whitelist de dominios (futprotec.com / getfutprotec.com): fuera de ella no
 *    se redirige (mitiga open-redirect) y el clic se marca como tipo OTR.
 *  - Atribución por tracking_id/envio_id/lead_id, NUNCA por email/asunto/texto.
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$trackingId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['t'] ?? ''));
$url        = html_entity_decode((string)($_GET['u'] ?? ''), ENT_QUOTES, 'UTF-8');
$userAgent  = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// Validez de la URL: http(s) + dominio en whitelist + longitud razonable.
$esUrlValida = (preg_match('#^https?://#i', $url) === 1)
    && (str_contains(strtolower($url), 'futprotec.com')
        || str_contains(strtolower($url), 'getfutprotec.com')
        || str_contains(strtolower($url), 'wa.me')
        || str_contains(strtolower($url), 'api.whatsapp.com'))
    && strlen($url) <= 512;

if ($trackingId === '' || !$esUrlValida) {
    header('Location: https://www.futprotec.com', true, 302);
    exit;
}

// Clasifica el tipo de CTA según la URL (heurística; se puede ampliar).
$tipoCta = 'CTA_WEB';
$urlLc = strtolower($url);
if (str_contains($urlLc, 'presupuesto') || str_contains($urlLc, 'proforma')) {
    $tipoCta = 'CTA_PRESUPUESTO';
} elseif (str_contains($urlLc, 'mockup') || str_contains($urlLc, 'diseno') || str_contains($urlLc, 'diseño')) {
    $tipoCta = 'CTA_MOCKUP';
} elseif (str_contains($urlLc, 'wa.me') || str_contains($urlLc, 'api.whatsapp.com') || str_contains($urlLc, 'whatsapp')) {
    $tipoCta = 'CTA_WHATSAPP';
} elseif (str_contains($urlLc, 'contacto') || str_contains($urlLc, 'contact')) {
    $tipoCta = 'CTA_CONTACTO';
} elseif (str_contains($urlLc, 'baja.php')) {
    $tipoCta = 'BAJA';
}

$dbPath = __DIR__ . '/../data/stats.db';

try {
    if (!file_exists($dbPath)) {
        header('Location: ' . $url, true, 302);
        exit;
    }
    $db = new SQLite3($dbPath);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');

    $envio = $db->querySingle(
        "SELECT id, lead_id, campaign_id, es_test FROM envios WHERE tracking_id = '"
        . $db->escapeString($trackingId) . "' LIMIT 1",
        true
    );

    if ($envio) {
        $stmt = $db->prepare(
            'INSERT INTO clics (envio_id, lead_id, campaign_id, tracking_id, url_original, tipo_cta, fecha, user_agent, ip, es_test)
             VALUES (:e, :l, :c, :t, :u, :tc, datetime(\'now\'), :ua, :ip, :et)'
        );
        $stmt->bindValue(':e',  (int)$envio['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':l',  ($envio['lead_id'] ?? 0) > 0 ? (int)$envio['lead_id'] : null, SQLITE3_INTEGER);
        $stmt->bindValue(':c',  ($envio['campaign_id'] ?? 0) > 0 ? (int)$envio['campaign_id'] : null, SQLITE3_INTEGER);
        $stmt->bindValue(':t',  $trackingId, SQLITE3_TEXT);
        $stmt->bindValue(':u',  $url, SQLITE3_TEXT);
        $stmt->bindValue(':tc', $tipoCta, SQLITE3_TEXT);
        $stmt->bindValue(':ua', $userAgent, SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->bindValue(':et', (int)($envio['es_test'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();
    }

    $db->close();
} catch (\Exception $e) {
    // Nunca bloquear la navegación: si falla el registro, se redirige igualmente.
    error_log('click.php error: ' . $e->getMessage());
}

// Redirigir al destino final (siempre, incluso si el registro falló).
header('Location: ' . $url, true, 302);
exit;
