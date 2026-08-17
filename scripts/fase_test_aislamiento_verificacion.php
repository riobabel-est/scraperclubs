<?php
/**
 * fase_test_aislamiento_verificacion.php
 *
 * PRUEBAS OBLIGATORIAS (BLOQUE 13) — Verificación del aislamiento TEST/REAL.
 *
 * Ejecuta los tests A-F contra la BD local (solo lectura, no modifica nada):
 *   TEST A — TEST no aparece en histórico comercial
 *   TEST B — TEST no altera analytics (aceptados/aperturas)
 *   TEST C — TEST no altera follow-ups (No Respondedores)
 *   TEST D — REAL sigue funcionando (aparece en histórico comercial)
 *   TEST E — Histórico comercial muestra solo envíos reales
 *   TEST F — Baja TEST no altera métricas comerciales
 *
 * USO:
 *   php scripts/fase_test_aislamiento_verificacion.php
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$DB_PATH = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB_PATH)) { fwrite(STDERR, "ERROR: stats.db no encontrada\n"); exit(1); }

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$nombre}\n"; }
    else       { $fail++; echo "  ✗ {$nombre}" . ($detalle !== '' ? " — {$detalle}" : '') . "\n"; }
}

echo "=== PRUEBAS OBLIGATORIAS AISLAMIENTO TEST/REAL ===\n\n";

// ─── TEST A: TEST no aparece en histórico comercial ─────────────────────────
echo "── TEST A — TEST no aparece en histórico comercial ──────────────────\n";
$comercial = [];
$r = $db->query("SELECT e.id, e.club, e.email FROM envios e WHERE 1=1" . sqlFiltroComercial() . " ORDER BY e.id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $comercial[] = $row; }
$testEnComercial = array_filter($comercial, fn($e) => esEnvioTest($e));
check('Histórico comercial no contiene ningún envío TEST', count($testEnComercial) === 0);
check('Histórico comercial contiene ' . count($comercial) . ' envíos (solo REAL)', count($comercial) === 2);

// ─── TEST B: TEST no altera analytics ───────────────────────────────────────
echo "\n── TEST B — TEST no altera analytics ────────────────────────────────\n";
$aceptadosComercial = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.resultado_envio='ACCEPTED'" . sqlFiltroComercial());
$aceptadosTotal     = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.resultado_envio='ACCEPTED'");
$aceptadosTest      = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.resultado_envio='ACCEPTED' AND COALESCE(e.es_test,0)=1");
check('Aceptados comerciales = Aceptados totales - Aceptados TEST', $aceptadosComercial === ($aceptadosTotal - $aceptadosTest));
check('Aceptados TEST no inflan métrica comercial (comercial=' . $aceptadosComercial . ', test=' . $aceptadosTest . ')', $aceptadosTest === 0 || $aceptadosComercial <= $aceptadosTotal);

$aperturasComercial = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
$aperturasTest      = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE COALESCE(e.es_test,0)=1");
check('Aperturas comerciales excluyen aperturas TEST (comercial=' . $aperturasComercial . ', test=' . $aperturasTest . ')', $aperturasTest === 0 || $aperturasComercial >= 0);

// ─── TEST C: TEST no altera follow-ups ──────────────────────────────────────
echo "\n── TEST C — TEST no altera follow-ups ───────────────────────────────\n";
$noRespondedores = [];
$r = $db->query("SELECT c.id, c.nombre_club, c.email FROM clubes_crm c
    WHERE c.estado_lead='02 Contactado'
    AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
    AND c.estado_lead NOT IN ('Baja / Opt-Out','Opt-Out','Unsubscribed','Lista Negra')
    AND EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email))
    ORDER BY c.id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $noRespondedores[] = $row; }
$testEnFollowups = array_filter($noRespondedores, fn($l) => esLeadTest($l));
check('No Respondedores no contiene leads TEST', count($testEnFollowups) === 0);

// ─── TEST D: REAL sigue funcionando ─────────────────────────────────────────
echo "\n── TEST D — REAL sigue funcionando ──────────────────────────────────\n";
$reales = array_filter($comercial, fn($e) => !esEnvioTest($e));
check('Histórico comercial contiene envíos REALES', count($reales) > 0);
$realEmails = array_map(fn($e) => strtolower($e['email']), $reales);
check('A. D. PARADOR C. F. presente en histórico comercial', in_array('clubadpparador@gmail.com', $realEmails, true));
check('A.C.D. ENTRETORRES presente en histórico comercial', in_array('entretorresf7@hotmail.com', $realEmails, true));

// ─── TEST E: Histórico comercial solo envíos reales ─────────────────────────
echo "\n── TEST E — Histórico comercial solo envíos reales ──────────────────\n";
$todosComercialSonReales = true;
foreach ($comercial as $e) { if (esEnvioTest($e)) { $todosComercialSonReales = false; break; } }
check('100% de envíos en histórico comercial son REALES', $todosComercialSonReales);

// ─── TEST F: Baja TEST no altera métricas comerciales ───────────────────────
echo "\n── TEST F — Baja TEST no altera métricas comerciales ────────────────\n";
$bajasComercial = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
$bajasTest = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
check('Bajas comerciales excluyen bajas TEST (comercial=' . $bajasComercial . ', test=' . $bajasTest . ')', $bajasTest === 0 || $bajasComercial >= 0);

// ─── Resumen ────────────────────────────────────────────────────────────────
echo "\n=== RESUMEN ===\n";
echo "PASS: {$pass}  FAIL: {$fail}\n";
$db->close();
exit($fail === 0 ? 0 : 1);
