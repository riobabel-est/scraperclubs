<?php
/**
 * FASE ABC-FINAL.4 — VALIDACIÓN POST-CREACIÓN (SOLO LECTURA).
 *
 * NO inserta, NO envía, NO modifica nada.
 * Verifica:
 *   - los 3 leads dummies existen, son TEST, no duplicados, limpios
 *   - elegibilidad real (esElegibleParaEnvio) para campaign_id=3
 *   - variante real asignarVariante(lead_id, 3)
 *   - ausencia de envíos (COUNT=0 para los 3 IDs; MAX(envios.id)=7)
 *   - seguridad: campaña/plantilla/config sin cambios
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

function line(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    echo str_pad($k, 48) . " : " . $v . "\n";
}

$CAMPAIGN = 3;

echo "==================== VALIDACIÓN POST-CREACIÓN ABC-FINAL.4 (SOLO LECTURA) ====================\n\n";

$dummies = [
    'A' => 1814,
    'B' => 1815,
    'C' => 1816,
];

echo "--- 1. Existencia, TEST, duplicado, estado ---\n";
$okExisten = true;
foreach ($dummies as $tag => $id) {
    $r = $db->querySingle("SELECT id, nombre_club, email, estado_lead, es_duplicado, creado_el FROM clubes_crm WHERE id = {$id}", true);
    if (!$r) { echo "  dummy {$tag} lead_id={$id}: NO EXISTE\n"; $okExisten = false; continue; }
    $esTest = esLeadTest($r) ? 'SÍ' : 'NO';
    $esDup = (int)$r['es_duplicado'] === 1 ? 'SÍ' : 'NO';
    $estadoOk = ((string)$r['estado_lead'] === 'Sin Contactar') ? 'OK' : 'NO-OK';
    echo "  dummy {$tag} | lead_id={$r['id']} | {$r['nombre_club']} | {$r['email']}\n";
    line("     TEST (esLeadTest)", $esTest);
    line("     es_duplicado", $esDup);
    line("     estado_lead", $r['estado_lead'] . " ({$estadoOk})");
}

echo "\n--- 2. Variante real asignarVariante(lead_id, 3) ---\n";
$conteo = ['A' => 0, 'B' => 0, 'C' => 0];
foreach ($dummies as $tag => $id) {
    $v = asignarVariante($id, $CAMPAIGN);
    $conteo[$v]++;
    line("dummy {$tag} lead_id={$id}", $v);
}
line("conteo A", $conteo['A']);
line("conteo B", $conteo['B']);
line("conteo C", $conteo['C']);
$abcExacto = ($conteo['A'] === 1 && $conteo['B'] === 1 && $conteo['C'] === 1);
line("A/B/C exacto (1-1-1)", $abcExacto ? 'SÍ' : 'NO');

echo "\n--- 3. Elegibilidad real para campaign_id=3 ---\n";
foreach ($dummies as $tag => $id) {
    $e = esElegibleParaEnvio($db, $id, $CAMPAIGN);
    line("dummy {$tag} lead_id={$id}", $e['ok'] ? 'elegible' : 'NO: ' . $e['razon']);
}

echo "\n--- 4. Ausencia de envío lógico en campaign_id=3 ---\n";
$idsStr = implode(',', array_values($dummies));
$cnt = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE campaign_id = {$CAMPAIGN} AND lead_id IN ({$idsStr})");
line("COUNT(envios campaign_id=3 AND lead IN ids)", $cnt);
line("esperado 0", $cnt === 0 ? 'OK' : 'FALLO');

echo "\n--- 5. Integridad envios ---\n";
$maxId = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
line("MAX(envios.id)", $maxId);
line("esperado 7", $maxId === 7 ? 'OK' : 'FALLO');
$countAll = (int)$db->querySingle("SELECT COUNT(*) FROM envios");
line("COUNT(envios)", $countAll);
$gt7 = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE id > 7");
line("envios.id > 7 COUNT", $gt7);

echo "\n--- 6. Seguridad: sin cambios en campaña/plantilla/config ---\n";
$camp = $db->querySingle("SELECT id, estado, entorno, activo FROM pipelines WHERE id = 3", true);
line("campaña 3", json_encode($camp, JSON_UNESCAPED_UNICODE));
$plant = $db->querySingle("SELECT id, activo, test_ab FROM plantillas WHERE id = 2", true);
line("plantilla 2", json_encode($plant, JSON_UNESCAPED_UNICODE));
$modoEntorno = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'");
$motorEstado = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'");
line("modo_entorno", $modoEntorno);
line("motor_estado", $motorEstado);

$db->close();

echo "\n==================== VEREDICTO ====================\n";
if ($okExisten && $abcExacto && $cnt === 0 && $maxId === 7) {
    echo "ABC_DUMMIES_READY\n";
} else {
    echo "BLOCKED\n";
}
exit(0);