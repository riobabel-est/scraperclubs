<?php
/**
 * FASE ABC-FINAL.4 — PREFLIGHT SOLO LECTURA (SIN escritura en BD).
 *
 * Verifica precondiciones y estado previo antes de crear los 3 dummies A/B/C:
 *  - campaña 3 válida (PILOT, entorno=test, activo=1)
 *  - plantilla 2 activa y test_ab=1
 *  - config.modo_entorno=test, motor_estado=pausado
 *  - no existencia previa de los dummies A/B/C (por nombre_club o email)
 *  - MAX(envios.id) actual y ausencia de envíos nuevos
 *
 * NO inserta, NO envía, NO modifica nada.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

function line(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    echo str_pad($k, 44) . " : " . $v . "\n";
}

echo "==================== PREFLIGHT ABC-FINAL.4 (SOLO LECTURA) ====================\n\n";

echo "--- A. CONFIG ---\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '?');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '?');
line('modo_entorno', $modoEntorno);
line('motor_estado', $motorEstado);

echo "\n--- B. CAMPAÑA 3 ---\n";
$camp = $db->querySingle("SELECT * FROM pipelines WHERE id = 3", true);
if ($camp) {
    foreach ($camp as $k => $v) {
        line("campana.{$k}", $v);
    }
} else {
    line('campaña 3', 'NO EXISTE');
}

echo "\n--- C. PLANTILLA 2 ---\n";
$p = $db->querySingle("SELECT * FROM plantillas WHERE id = 2", true);
if ($p) {
    foreach ($p as $k => $v) {
        if (in_array($k, ['cuerpo', 'cuerpo_b', 'cuerpo_c'], true)) {
            line("plantilla.{$k}", '(bytes=' . strlen((string)$v) . ')');
            continue;
        }
        line("plantilla.{$k}", $v);
    }
} else {
    line('plantilla 2', 'NO EXISTE');
}

echo "\n--- D. ESQUEMA clubes_crm (columnas reales) ---\n";
foreach ($db->query("PRAGMA table_info(clubes_crm)") as $c) {
    line("  {$c['name']}", $c['type'] . ' notnull=' . $c['notnull']);
}

echo "\n--- E. ESQUEMA envios (columnas reales) ---\n";
foreach ($db->query("PRAGMA table_info(envios)") as $c) {
    line("  {$c['name']}", $c['type'] . ' notnull=' . $c['notnull']);
}

echo "\n--- F. COMPROBACIÓN NO EXISTENCIA PREVIA DE DUMMIES A/B/C ---\n";
$nombres = ['TEST_ABC_FINAL4_A', 'TEST_ABC_FINAL4_B', 'TEST_ABC_FINAL4_C'];
$emails  = ['test_abc_final4_a@futprotec.local', 'test_abc_final4_b@futprotec.local', 'test_abc_final4_c@futprotec.local'];

foreach (['A', 'B', 'C'] as $i => $v) {
    $n = $nombres[$i];
    $e = $emails[$i];
    $byNombre = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE nombre_club = '" . $db->escapeString($n) . "'");
    $byEmail  = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE email = '" . $db->escapeString($e) . "'");
    line("dummy {$v} nombre '{$n}' COUNT", $byNombre);
    line("dummy {$v} email '{$e}' COUNT", $byEmail);
}

echo "\n--- G. Prefijos de leads TEST existentes con 'ABC_FINAL' ---\n";
$res = $db->query("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE LOWER(nombre_club) LIKE '%abc_final4%' OR LOWER(email) LIKE '%abc_final4%' ORDER BY id");
$found = 0;
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $found++;
    echo "  lead_id={$r['id']} | {$r['nombre_club']} | {$r['email']} | estado={$r['estado_lead']} | dup={$r['es_duplicado']}\n";
}
if ($found === 0) echo "  (ninguno)\n";

echo "\n--- H. ESTADO envios ---\n";
$maxId = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
line('MAX(envios.id)', $maxId);
$countAll = (int)$db->querySingle("SELECT COUNT(*) FROM envios");
line('COUNT(envios)', $countAll);
$countCamp3 = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE campaign_id = 3");
line("COUNT(envios WHERE campaign_id=3)", $countCamp3);

echo "\n--- I. Envíos campaign_id=3 (detalle) ---\n";
$res = $db->query("SELECT id, lead_id, campaign_id, variant, plantilla_id, smtp_id, estado, resultado_envio, email FROM envios WHERE campaign_id = 3 ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "  envio_id={$r['id']} | lead_id={$r['lead_id']} | variant={$r['variant']} | plantilla={$r['plantilla_id']} | smtp={$r['smtp_id']} | estado={$r['estado']} | res={$r['resultado_envio']} | email={$r['email']}\n";
}

$db->close();
echo "\n==================== FIN PREFLIGHT ====================\n";
exit(0);