<?php
/**
 * test_fase3_tracking.php — TESTS de FASE 3 del MEGAPROMPT V2 (CRM FutProtec).
 *
 * Comprueba:
 *   TEST 01 — DB integrity
 *   TEST 02 — apertura dedup (vista vw_aperturas_analiticas correcta, bruto intacto)
 *   TEST 03 — tracking (aperturas brutas sin cambios; vista por envío/lead)
 *   TEST 04 — click attribution (registro en `clics` con trazabilidad completa)
 *   TEST 07 — MIME: reescritura de CTA a api/click.php (clics medibles)
 *
 * Uso local:  php scripts/test_fase3_tracking.php
 * No envía emails. El clic de prueba se registra con es_test=1 y se elimina al
 * final (no deja datos en la tabla nueva).
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
require_once __DIR__ . '/../public_html/outbound/inc/mime.php';

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS | {$nombre}\n";
    } else {
        $fail++;
        echo "FAIL | {$nombre} | {$detalle}\n";
    }
}

// ─── TEST 01 — DB integrity ────────────────────────────────────────────────
$db = new SQLite3($DB);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
check('TEST 01 DB integrity', ($db->querySingle('PRAGMA integrity_check') ?? '') === 'ok');

// ─── TEST 02/03 — apertura dedup (vista analítica) ─────────────────────────
$bruto = (int)$db->querySingle('SELECT COUNT(*) FROM aperturas');
$brutoVinculadas = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas a WHERE EXISTS (SELECT 1 FROM envios e WHERE e.tracking_id = a.tracking_id)");
$sumaVista = (int)$db->querySingle('SELECT COALESCE(SUM(num_aperturas),0) FROM vw_aperturas_analiticas');
check('TEST 02 dedup: suma vista == aperturas vinculadas', $sumaVista === $brutoVinculadas, "vista={$sumaVista} vinculadas={$brutoVinculadas}");
$camp2Opened = (int)$db->querySingle("SELECT COUNT(*) FROM vw_aperturas_analiticas WHERE campaign_id=2 AND es_test=0 AND opened=1");
check('TEST 02 camp2 leads con apertura (dedup, >0)', $camp2Opened >= 100, "opened={$camp2Opened}");
$sego = $db->querySingle(
    "SELECT num_aperturas, primera_apertura, ultima_apertura, opened FROM vw_aperturas_analiticas WHERE lead_id=1217 AND campaign_id=2 LIMIT 1",
    true
);
check('TEST 02 Segosala: opened=1 (dedup)', $sego && (int)$sego['opened'] === 1 && (int)$sego['num_aperturas'] > 0, json_encode($sego));
$vistaFilas = (int)$db->querySingle('SELECT COUNT(*) FROM vw_aperturas_analiticas');
$enviosTotal = (int)$db->querySingle('SELECT COUNT(*) FROM envios');
check('TEST 02 vista: 1 fila por envío', $vistaFilas === $enviosTotal, "vista={$vistaFilas} envios={$enviosTotal}");

// ─── TEST 04 — click attribution ───────────────────────────────────────────
// Usar un envío TEST (lead test, es_test=1) para el clic de prueba.
$envioTest = $db->querySingle(
    "SELECT id, lead_id, campaign_id, tracking_id, es_test FROM envios WHERE es_test=1 AND tracking_id IS NOT NULL ORDER BY id LIMIT 1",
    true
);
if ($envioTest) {
    $sqlIns = 'INSERT INTO clics (envio_id, lead_id, campaign_id, tracking_id, url_original, tipo_cta, fecha, user_agent, ip, es_test)
               VALUES (:e, :l, :c, :t, :u, :tc, datetime(\'now\'), :ua, :ip, :et)';
    $st = $db->prepare($sqlIns);
    $st->bindValue(':e', (int)$envioTest['id'], SQLITE3_INTEGER);
    $st->bindValue(':l', $envioTest['lead_id'], SQLITE3_INTEGER);
    $st->bindValue(':c', $envioTest['campaign_id'] ?? null, SQLITE3_INTEGER);
    $st->bindValue(':t', $envioTest['tracking_id'], SQLITE3_TEXT);
    $st->bindValue(':u', 'https://www.futprotec.com', SQLITE3_TEXT);
    $st->bindValue(':tc', 'CTA_WEB', SQLITE3_TEXT);
    $st->bindValue(':ua', 'Mozilla/5.0 (test)', SQLITE3_TEXT);
    $st->bindValue(':ip', '127.0.0.1', SQLITE3_TEXT);
    $st->bindValue(':et', 1, SQLITE3_INTEGER);
    $st->execute();
    $clicId = (int)$db->lastInsertRowID();

    $clic = $db->querySingle("SELECT * FROM clics WHERE id = {$clicId}", true);
    check('TEST 04 clic registrado con trazabilidad completa',
        $clic && (int)$clic['envio_id'] === (int)$envioTest['id']
        && (int)$clic['lead_id'] === (int)$envioTest['lead_id']
        && $clic['tipo_cta'] === 'CTA_WEB'
        && $clic['url_original'] === 'https://www.futprotec.com',
        json_encode($clic));
    check('TEST 04 clic atribuido por tracking_id (no email/asunto)',
        $clic && $clic['tracking_id'] === $envioTest['tracking_id']);

    // Limpiar el clic de prueba (tabla nueva, no dejar ruido).
    $db->exec("DELETE FROM clics WHERE id = {$clicId}");
} else {
    check('TEST 04 click attribution', false, 'sin envío TEST para probar');
}

// ─── TEST 07 — MIME: CTA reescrito a click.php ─────────────────────────────
$html = convertirContenidoAHtml(
    "Visita nuestra web: https://www.futprotec.com",
    'https://getfutprotec.com/outbound/api/track.php',
    'fut_test_clic_abc123'
);
check('TEST 07 CTA reescrito a api/click.php', str_contains($html, 'api/click.php?t=fut_test_clic_abc123'), substr($html, 0, 200));
check('TEST 07 CTA conserva URL visible', str_contains($html, 'https://www.futprotec.com'), substr($html, 0, 200));
$htmlBaja = convertirContenidoAHtml(
    "Baja: https://getfutprotec.com/outbound/api/baja.php?t=abc",
    'https://getfutprotec.com/outbound/api/track.php',
    'fut_test_baja_1'
);
check('TEST 07 URL de baja NO se reescribe a click.php', !str_contains($htmlBaja, 'click.php?t=fut_test_baja_1'), substr($htmlBaja, 0, 200));

$db->close();

echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
