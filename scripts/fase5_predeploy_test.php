<?php
/**
 * FASE 5 — Test local contra COPIA de LIVE (NO toca la BD LIVE).
 * Verifica el aislamiento TEST/REAL tras la corrección del alias en
 * get_analytics → tab=envios (FROM envios e + sqlFiltroComercial('e')).
 *
 * Usa: backups_deploy/stats_db_TEST_MIGRACION.db (copia de LIVE POST-migración,
 * con columna es_test y 32 envíos). SOLO LECTURA. No modifica nada.
 *
 * NOTA sobre esquema de la copia:
 *  - rebotes NO tiene tracking_id (usa email). Se une por email.
 *  - respuestas usa envio_id.
 *  - aperturas usa tracking_id.
 */
declare(strict_types=1);

$DB = __DIR__ . '/../backups_deploy/stats_db_TEST_MIGRACION.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: no existe copia LIVE: {$DB}\n");
    exit(1);
}

$db = new SQLite3($DB);
$db->enableExceptions(true);

// ─── Helper: sqlFiltroComercial (espejo de inc/eligibilidad.php) ────────────
function sqlFiltroComercial(string $alias = 'e'): string {
    $a = $alias !== '' ? $alias . '.' : '';
    return " AND COALESCE({$a}es_test, 0) = 0";
}

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  [PASS] {$label}\n"; }
    else     { $FAIL++; echo "  [FAIL] {$label} {$detail}\n"; }
}

echo "=== BLOQUE 4: get_analytics → tab=envios (corregido) ===\n";

// Listado de últimos envíos comerciales (get_analytics tab=envios ultimos)
// Esta es la tabla "Histórico Comercial". Debe devolver los 12 REAL y NINGÚN TEST.
$r2 = $db->query("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado, e.asunto, e.cuerpo_mensaje FROM envios e WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY e.id DESC LIMIT 50");
$ultimos = [];
while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $ultimos[] = $row; }
echo "  Nº registros en 'ultimos' (Histórico Comercial) = " . count($ultimos) . "\n";
check('Histórico Comercial = 12', count($ultimos) === 12, "(got " . count($ultimos) . ")");

// Emails que NO deben aparecer
$prohibidos = [
    'test01@futprotec.local',
    'test03@futprotec.local',
    'test04@futprotec.local',
    'test05@futprotec.local',
    'test_abc_final4_a@futprotec.local',
    'test_abc_final4_b@futprotec.local',
    'test_abc_final4_c@futprotec.local',
    'test_abc_final6_b@futprotec.local',
    'hola@riobabel.com',
    'info@fsnazareno.es',
];
$emailsUltimos = array_map(fn($r) => strtolower((string)$r['email']), $ultimos);
$encontrados = [];
foreach ($prohibidos as $p) {
    if (in_array(strtolower($p), $emailsUltimos, true)) { $encontrados[] = $p; }
}
check('No aparecen TEST en Histórico Comercial', count($encontrados) === 0, "(encontrados: " . implode(',', $encontrados) . ")");

// Contador total (get_analytics tab=envios total): solo estado='enviado' + es_test=0
$total = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado='enviado'" . sqlFiltroComercial('e'));
echo "  Contador total (estado=enviado, es_test=0) = {$total} (9 enviados + 3 abiertos = 12 REAL; el contador solo cuenta 'enviado')\n";

echo "\n=== BLOQUE 5: Histórico de pruebas (TEST) ===\n";
$testTotal = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=1");
echo "  TEST (es_test=1) = {$testTotal}\n";
check('TEST = 20', $testTotal === 20, "(got {$testTotal})");

$realTotal = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0");
echo "  REAL (es_test=0) = {$realTotal}\n";
check('REAL = 12', $realTotal === 12, "(got {$realTotal})");

$totalAll = (int)$db->querySingle("SELECT COUNT(*) FROM envios");
echo "  TOTAL envios = {$totalAll}\n";
check('TOTAL = 32', $totalAll === 32, "(got {$totalAll})");

echo "\n=== BLOQUE 6: Otras métricas (excluyen TEST) ===\n";

// Aperturas comerciales (aperturas.tracking_id = envios.tracking_id)
$ap = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
echo "  Aperturas comerciales = {$ap}\n";

// Rebotes comerciales (rebotes se une por email en esta copia)
$rb = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
echo "  Rebotes comerciales (join por email) = {$rb}\n";

// Respuestas comerciales (respuestas.envio_id = envios.id)
$resp = (int)$db->querySingle("SELECT COUNT(*) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE 1=1" . sqlFiltroComercial('e'));
echo "  Respuestas comerciales = {$resp}\n";

// get_lead: total_envios por lead (usa COALESCE(e.es_test,0)=0)
$leadTest = $db->querySingle("SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) LIKE '%@futprotec.local%' AND COALESCE(e.es_test,0)=0");
echo "  Envíos REALES a emails @futprotec.local = {$leadTest}\n";
check('get_lead no mezcla TEST (0 reales a futprotec.local)', $leadTest === 0, "(got {$leadTest})");

// snapshot_crear: rebotado comercial
$snapReb = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE COALESCE(e.es_test,0)=0");
echo "  Snapshot rebotado comercial = {$snapReb}\n";

// Bajas comerciales (excluye leads TEST por regla esLeadTest)
$bajas = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra','Baja / Opt-Out') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
echo "  Bajas comerciales = {$bajas}\n";

// A/B/C comercial: envíos por variante (solo REALES)
$abc = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.variant IS NOT NULL" . sqlFiltroComercial('e'));
echo "  Envíos A/B/C comerciales = {$abc}\n";

echo "\n=== RESUMEN ===\n";
echo "PASS: {$PASS}  FAIL: {$FAIL}\n";
echo ($FAIL === 0 ? "PRE_DEPLOY_TEST_ISOLATION_PASS\n" : "PRE_DEPLOY_TEST_ISOLATION_BLOCKED\n");
exit($FAIL === 0 ? 0 : 1);
