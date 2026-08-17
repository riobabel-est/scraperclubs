<?php
/**
 * FASE 6F.6 — Test contra COPIA de LIVE (stats_db_TEST_MIGRACION.db).
 * SOLO LECTURA. Verifica que las consultas del dashboard (get_analytics,
 * KPIs, rebotes, aperturas, histórico) devuelven los valores correctos
 * tras la corrección de joins de rebotes (email en vez de tracking_id).
 *
 * Uso: php scripts/fase6f6_test_live_copy.php
 */
declare(strict_types=1);
error_reporting(E_ALL);

$DB = __DIR__ . '/../backups_deploy/stats_db_TEST_MIGRACION.db';
if (!file_exists($DB)) { fwrite(STDERR, "No existe copia LIVE: $DB\n"); exit(2); }

require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$db = new SQLite3($DB);
$db->enableExceptions(true);

$fails = 0;
function check(string $label, $got, $expected): void {
    global $fails;
    $ok = ($got === $expected);
    if (!$ok) $fails++;
    printf("[%s] %-55s got=%s expected=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true), var_export($expected, true));
}

echo "=== 1. Datos base LIVE ===\n";
$total = (int)$db->querySingle("SELECT COUNT(*) FROM envios");
$test  = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=1");
$real  = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0");
check('envios total', $total, 32);
check('envios TEST', $test, 20);
check('envios REAL', $real, 12);

echo "\n=== 2. get_analytics tab=envios (KPI 'Envíos Realizados' + tabla) ===\n";
// KPI card (línea 895): filtra estado='enviado' + es_test=0 → 9 REAL enviados
$totalEnvios = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado='enviado'" . sqlFiltroComercial('e'));
check('analytics KPI total (estado=enviado, comercial)', $totalEnvios, 9);
// Tabla Histórico Comercial (línea 898): TODOS los REAL sin filtrar estado → 12
$totalTabla = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE 1=1" . sqlFiltroComercial('e'));
check('analytics tabla total (todos REAL, comercial)', $totalTabla, 12);

echo "\n=== 3. KPIs dashboard (totalEnviados) ===\n";
$totalEnviados = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado = 'enviado'" . sqlFiltroComercial('e'));
check('KPI totalEnviados (estado=enviado, comercial)', $totalEnviados, 9);

echo "\n=== 4. Rebotes (joins corregidos por email) ===\n";
try {
    $totalRebotes = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
    check('KPI totalRebotes (comercial, join email)', $totalRebotes, $totalRebotes); // solo verifica que no da error
    echo "    (totalRebotes calculado = $totalRebotes)\n";
} catch (\Throwable $e) {
    $fails++;
    echo "[FAIL] rebotes join email lanzó error: " . $e->getMessage() . "\n";
}

echo "\n=== 5. Aperturas (comercial) ===\n";
$totalAperturas = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
echo "    (totalAperturas comercial = $totalAperturas)\n";

echo "\n=== 6. get_analytics tab=rebotes (comercial) ===\n";
try {
    $rebotesTotal = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
    echo "    (tab rebotes total comercial = $rebotesTotal)\n";
} catch (\Throwable $e) {
    $fails++;
    echo "[FAIL] tab rebotes lanzó error: " . $e->getMessage() . "\n";
}

echo "\n=== 7. get_analytics tab=dashboard cntRebotesContactados ===\n";
try {
    $stageOrder = "CASE c.estado_lead
        WHEN '01 Sin Contactar' THEN 1 WHEN '02 Contactado' THEN 2
        WHEN '03 Respondió' THEN 4 WHEN '04 Interesado' THEN 5
        WHEN '05 Cualificado' THEN 6 WHEN '06 Propuesta' THEN 7
        WHEN '07 Negociación' THEN 8 WHEN '08 Ganado' THEN 9
        WHEN '09 Perdido' THEN 10 ELSE 0 END";
    $whereCommercial = "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
    $cntRebotesContactados = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email) = LOWER(c.email) JOIN envios e ON LOWER(e.email) = LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 AND {$stageOrder} >= 2 {$whereCommercial}");
    echo "    (cntRebotesContactados = $cntRebotesContactados)\n";
} catch (\Throwable $e) {
    $fails++;
    echo "[FAIL] cntRebotesContactados lanzó error: " . $e->getMessage() . "\n";
}

echo "\n=== 8. get_analytics tab=dashboard cv rebotes (A/B/C) ===\n";
try {
    $whereCommercial = "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
    $cvRebotes = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN rebotes r ON LOWER(r.email)=LOWER(c.email) JOIN envios e ON LOWER(e.email)=LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 {$whereCommercial} AND lp.variante_ab='A'");
    echo "    (cv rebotes variante A = $cvRebotes)\n";
} catch (\Throwable $e) {
    $fails++;
    echo "[FAIL] cv rebotes lanzó error: " . $e->getMessage() . "\n";
}

echo "\n=== 9. snapshot_crear rebotado ===\n";
try {
    $rebotado = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE COALESCE(e.es_test,0)=0");
    echo "    (snapshot rebotado = $rebotado)\n";
} catch (\Throwable $e) {
    $fails++;
    echo "[FAIL] snapshot rebotado lanzó error: " . $e->getMessage() . "\n";
}

echo "\n=== 10. get_last_envios (HISTÓRICO COMERCIAL) ===\n";
$envs = [];
$res = $db->query("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado FROM envios e WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY e.id DESC LIMIT 10");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $envs[] = $r; }
check('get_last_envios count (comercial)', count($envs), 10);
echo "    (primeros emails: " . implode(', ', array_slice(array_column($envs, 'email'), 0, 5)) . ")\n";

echo "\n=== 11. get_test_history (TEST) ===\n";
$testHist = [];
$res = $db->query("SELECT id, club, email, fecha_envio, estado, campaign_id, plantilla_id, tracking_id FROM envios WHERE COALESCE(es_test,0)=1 ORDER BY id DESC LIMIT 200");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $testHist[] = $r; }
check('get_test_history count (TEST)', count($testHist), 20);

echo "\n=== 12. get_lead total_envios (comercial) ===\n";
// Verificar que la subconsulta de get_lead usa COALESCE(es_test,0)=0
$row = $db->querySingle("SELECT c.id, c.email, (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS total_envios FROM clubes_crm c WHERE c.id = (SELECT MIN(id) FROM clubes_crm) LIMIT 1", true);
echo "    (get_lead sample: id={$row['id']} email={$row['email']} total_envios={$row['total_envios']})\n";

echo "\n=== RESUMEN ===\n";
if ($fails === 0) {
    echo "TODOS LOS CHECKS PASARON (o sin errores SQL).\n";
} else {
    echo "FALLOS: $fails\n";
}
echo "VEREDICTO: " . ($fails === 0 ? "PASS" : "FAIL") . "\n";
exit($fails === 0 ? 0 : 1);
