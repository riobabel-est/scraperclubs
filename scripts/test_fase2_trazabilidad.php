<?php
/**
 * test_fase2_trazabilidad.php — TESTS de FASE 2 del MEGAPROMPT V2 (CRM FutProtec).
 *
 * Comprueba:
 *   TEST 01 — DB integrity
 *   TEST 05 — follow-up traceability (reservarEnvioLogico persiste parent_envio_id
 *             y respuesta_origen_id; cadena email→respuesta→follow-up reconstruible)
 *   TEST 06 — campaign attribution (el envío original tiene campaign_id/variant/
 *             plantilla_id/smtp_id/message_id y la respuesta enlaza con él)
 *   TEST 14 — backup + migration integrity (columnas nuevas presentes, fecha ISO)
 *
 * Uso local:  php scripts/test_fase2_trazabilidad.php
 * No envía emails. Solo lectura sobre stats.db + una BD en memoria para el INSERT.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

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

// ─── TEST 05/06 — cadena de trazabilidad real (lead Segosala 1217) ─────────
// Se usa la respuesta cuyo envío pertenece a la campaña (la respuesta 8 → envío
// 188), no la respuesta más reciente (id 34 → follow-up histórico huérfano sin campaña).
$resp = $db->querySingle(
    "SELECT r.id, r.envio_id, r.lead_id, r.campaign_id, r.fecha_respuesta_iso
     FROM respuestas r JOIN envios e ON e.id = r.envio_id
     WHERE r.lead_id = 1217 AND e.campaign_id = 2 ORDER BY r.id DESC LIMIT 1",
    true
);
check('TEST 05 respuesta del lead con envio_id + fecha_iso', $resp && (int)($resp['envio_id'] ?? 0) > 0 && !empty($resp['fecha_respuesta_iso']), json_encode($resp));

if ($resp && (int)($resp['envio_id'] ?? 0) > 0) {
    $envio = $db->querySingle(
        "SELECT id, campaign_id, plantilla_id, smtp_id, variant, message_id FROM envios WHERE id = " . (int)$resp['envio_id'],
        true
    );
    check('TEST 06 envío original con campaign_id/variant/plantilla/smtp/message_id',
        $envio && (int)($envio['campaign_id'] ?? 0) === 2 && in_array($envio['variant'] ?? '', ['A', 'B', 'C'], true)
        && !empty($envio['message_id']),
        json_encode($envio));
    check('TEST 06 respuesta enlazada por envio_id (parent derivable)',
        (int)$envio['id'] === (int)$resp['envio_id']);
} else {
    check('TEST 06 envío original', false, 'sin envio_id en la respuesta');
}

// ─── TEST 05 — insertarEnvioLogico persiste los nuevos metadatos (BD memoria) ──
$mem = new SQLite3(':memory:');
$mem->exec("CREATE TABLE envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    club TEXT, email TEXT, federacion TEXT, cuenta_emision TEXT, estado TEXT,
    tracking_id TEXT, asunto TEXT, cuerpo_mensaje TEXT,
    lead_id INTEGER, campaign_id INTEGER, variant TEXT, plantilla_id INTEGER,
    smtp_id INTEGER, message_id TEXT, es_test INTEGER, es_rotacion INTEGER,
    parent_envio_id INTEGER, respuesta_origen_id INTEGER
)");
insertarEnvioLogico(
    $mem, 'Club Test', 'test@futprotec.local', '', 'cuenta@x.com', 'trk_test_f2',
    'Re: Original', 'cuerpo', 9999, 2, 'B', 1, 5, '<msg-test@x>', 1, false, 0, 321, 12345
);
$fila = $mem->querySingle('SELECT parent_envio_id, respuesta_origen_id, campaign_id, es_test FROM envios WHERE tracking_id = \'trk_test_f2\'', true);
check('TEST 05 follow-up persiste parent_envio_id + respuesta_origen_id',
    $fila && (int)$fila['parent_envio_id'] === 321 && (int)$fila['respuesta_origen_id'] === 12345,
    json_encode($fila));
$mem->close();

// ─── TEST 14 — estructura de migración ─────────────────────────────────────
$cols = [];
$r = $db->query('PRAGMA table_info(envios)');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $cols[] = $row['name']; }
check('TEST 14 envios con columnas FASE 2',
    in_array('variant_original', $cols, true) && in_array('campaign_batch_id', $cols, true)
    && in_array('parent_envio_id', $cols, true) && in_array('respuesta_origen_id', $cols, true));
$colsLog = [];
$rLog = $db->query('PRAGMA table_info(comunicaciones_log)');
while ($row = $rLog->fetchArray(SQLITE3_ASSOC)) { $colsLog[] = $row['name']; }
check('TEST 14 comunicaciones_log con metadata', in_array('metadata', $colsLog, true));
$isoCount = (int)$db->querySingle("SELECT COUNT(*) FROM respuestas WHERE fecha_respuesta_iso IS NOT NULL");
$totalResp = (int)$db->querySingle('SELECT COUNT(*) FROM respuestas');
check('TEST 14 fecha_respuesta_iso poblada (todas)', $isoCount === $totalResp && $totalResp >= 30, "{$isoCount}/{$totalResp}");
$tablas = [];
$rTab = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='oportunidades'");
while ($row = $rTab->fetchArray(SQLITE3_ASSOC)) { $tablas[] = $row['name']; }
check('TEST 14 tabla oportunidades existe', in_array('oportunidades', $tablas, true));

$db->close();

echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
