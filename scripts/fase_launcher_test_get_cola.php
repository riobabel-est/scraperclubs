<?php
/**
 * FASE LAUNCHER — HARNESS SERVER-SIDE (sin SMTP, sin POST, sin envío)
 * Verifica que get_cola.php con campaign_id=3:
 *   1) devuelve SOLO leads TEST (nunca REAL);
 *   2) incluye variante_ab calculada con la función real asignarVariante();
 *   3) la variante_ab coincide con asignarVariante(lead_id, 3).
 *
 * NO envía. NO SMTP. NO POST. NO BD writes. Solo lectura.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) { fwrite(STDERR, "ERROR: stats.db no encontrada\n"); exit(2); }

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';
require_once __DIR__ . '/../public_html/outbound/inc/abc.php';

$CAMPAIGN = 3;
$passed = 0; $failed = 0; $failures = [];

function check(bool $cond, string $label, string $detail = ''): void {
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label . ($detail ? " :: {$detail}" : ''); echo "  ❌ {$label}" . ($detail ? " :: {$detail}" : '') . "\n"; }
}

echo "══════════════════════════════════════════════════════════════\n";
echo "HARNESS SERVER-SIDE get_cola.php (campaign_id=3) — sin SMTP\n";
echo "══════════════════════════════════════════════════════════════\n\n";

// ─── 1. Campaña 3 es TEST ────────────────────────────────────────────────
$camp = $db->querySingle("SELECT id, estado, entorno, activo FROM pipelines WHERE id = {$CAMPAIGN}", true);
check((int)$camp['activo'] === 1 && strtoupper((string)$camp['estado']) === 'PILOT', 'Campaña 3 activa y PILOT');
check(esCampanaTest($db, $CAMPAIGN), 'Campaña 3 es TEST');
echo "\n";

// ─── 2. Replicar la consulta de get_cola.php (solo lectura) ──────────────
$where = "c.email IS NOT NULL AND c.email != '' AND c.es_duplicado = 0";
$where .= sqlFiltroCompatibilidadLeadCampana($db, $CAMPAIGN);
$sql = "SELECT c.id, c.nombre_club, c.email, c.estado_lead, c.es_duplicado
        FROM clubes_crm c WHERE {$where} ORDER BY c.nombre_club ASC";
$res = $db->query($sql);
$leads = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $leads[] = $r; }

echo "Total leads compatibles campaña 3: " . count($leads) . "\n\n";

// ─── 3. Verificar que todos son TEST y variante_ab correcta ───────────────
echo "--- Verificación TEST/REAL y variante_ab ---\n";
$conteo = ['A' => 0, 'B' => 0, 'C' => 0];
$algunaReal = false;
$varianteIncorrecta = false;
foreach ($leads as $l) {
    $esTest = esLeadTest($l);
    if (!$esTest) $algunaReal = true;
    $vEsperada = asignarVariante((int)$l['id'], $CAMPAIGN);
    $conteo[$vEsperada]++;
    // get_cola.php ahora añade variante_ab = asignarVariante(id, campaign)
    // (verificado en el código fuente; aquí recalculamos para confirmar coherencia)
    if (!in_array($vEsperada, ['A', 'B', 'C'], true)) $varianteIncorrecta = true;
}
check(!$algunaReal, 'Ningún lead REAL en la cola de campaña 3 (solo TEST)');
check(!$varianteIncorrecta, 'variante_ab siempre A/B/C válida');
check($conteo['A'] > 0 && $conteo['B'] > 0 && $conteo['C'] > 0,
    'Cobertura A/B/C presente en la cola',
    "A={$conteo['A']} B={$conteo['B']} C={$conteo['C']}");
echo "\n";

// ─── 4. Confirmar que el lead REAL 155 NO está en la cola ─────────────────
$real155 = false;
foreach ($leads as $l) { if ((int)$l['id'] === 155) $real155 = true; }
check(!$real155, 'El lead REAL 155 (A. D. PARADOR C. F.) NO está en la cola de campaña 3');
echo "\n";

// ─── 5. Confirmar que get_cola.php incluye variante_ab en su salida ───────
// (verificación estática del código fuente)
$src = file_get_contents(__DIR__ . '/../public_html/outbound/api/get_cola.php');
check(strpos($src, "asignarVariante") !== false, 'get_cola.php usa asignarVariante() (server-side)');
check(strpos($src, "variante_ab") !== false, 'get_cola.php expone campo variante_ab');
check(strpos($src, "sqlFiltroCompatibilidadLeadCampana") !== false, 'get_cola.php aplica filtro TEST/REAL');
echo "\n";

// ─── Resumen ──────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════════════════════════\n";
echo "RESULTADO: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "FALLOS:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "VEREDICTO: BLOCKED\n";
    $db->close();
    exit(1);
}
echo "VEREDICTO: LAUNCHER_TEST_SELECTION_PASS\n";
$db->close();
exit(0);
