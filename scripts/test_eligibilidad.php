<?php
/**
 * Test funcional local del refactor §6.3 de inc/eligibilidad.php.
 * Opera sobre una COPIA de stats.db (no toca la BD real).
 */
declare(strict_types=1);

require __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$dbPath = __DIR__ . '/../tmp_test_eligibilidad.db';
if (file_exists($dbPath)) { unlink($dbPath); }
copy(__DIR__ . '/../public_html/outbound/data/stats.db', $dbPath);
$db = new SQLite3($dbPath);
$db->enableExceptions(true);

$ok = true;
function check(string $n, bool $c, string $d = ''): void {
    global $ok;
    if ($c) { echo "  ✅ $n\n"; }
    else { $ok = false; echo "  ❌ $n" . ($d !== '' ? " — $d" : '') . "\n"; }
}

// ─── Funciones puras ───
check('esEstadoSupresion("Lista Negra") = true', esEstadoSupresion('Lista Negra') === true);
check('esEstadoSupresion("07 Baja") = true', esEstadoSupresion('07 Baja') === true);
check('esEstadoSupresion("01 Sin Contactar") = false', esEstadoSupresion('01 Sin Contactar') === false);
check('esLeadTest(test@futprotec.local) = true', esLeadTest(['email' => 'x@futprotec.local', 'nombre_club' => 'Club']) === true);

// ─── Acceso a datos / campañas ───
check('esCampanaTest(id=0) = false', esCampanaTest($db, 0) === false);
$campTest = (int)$db->querySingle("SELECT id FROM pipelines WHERE LOWER(entorno)='test' ORDER BY id LIMIT 1");
if ($campTest > 0) {
    check("esCampanaTest(campaña test id=$campTest) = true", esCampanaTest($db, $campTest) === true);
    check('getEntornoCampana devuelve valor', getEntornoCampana($db, $campTest) !== '');
} else {
    echo "  ℹ sin campaña TEST en BD local (se omite ese check)\n";
}
$campNoTest = (int)$db->querySingle("SELECT id FROM pipelines WHERE LOWER(entorno)!='test' ORDER BY id LIMIT 1");
if ($campNoTest > 0) {
    check("esCampanaTest(campaña no-test id=$campNoTest) = false", esCampanaTest($db, $campNoTest) === false);
}

// ─── esElegibleParaEnvio ───
check('esElegibleParaEnvio(id=0) -> lead_no_valido', (esElegibleParaEnvio($db, 0)['razon'] ?? '') === 'lead_no_valido');
$leadId = (int)$db->querySingle("SELECT id FROM clubes_crm ORDER BY id DESC LIMIT 1");
$res = esElegibleParaEnvio($db, $leadId, 0);
check('esElegibleParaEnvio(lead real) devuelve ok/razon', isset($res['ok']) && isset($res['razon']));
$leadSup = (int)$db->querySingle("SELECT id FROM clubes_crm WHERE estado_lead='Lista Negra' ORDER BY id LIMIT 1");
if ($leadSup > 0) {
    check('esElegibleParaEnvio(lead Lista Negra) -> supresion', (esElegibleParaEnvio($db, $leadSup, 0)['razon'] ?? '') === 'supresion');
}

// ─── getLeadParaElegibilidad ───
$leadArr = getLeadParaElegibilidad($db, $leadId);
check('getLeadParaElegibilidad devuelve array', is_array($leadArr) && isset($leadArr['email']));
check('getLeadParaElegibilidad(id=0) = null', getLeadParaElegibilidad($db, 0) === null);

// ─── plantillaEstaCongelada ───
check('plantillaEstaCongelada(id=0) = false', plantillaEstaCongelada($db, 0) === false);
$pid = (int)$db->querySingle("SELECT id FROM plantillas ORDER BY id DESC LIMIT 1");
if ($pid > 0) {
    check('plantillaEstaCongelada devuelve bool', is_bool(plantillaEstaCongelada($db, $pid)));
}

// ─── reservarEnvioLogico (sin campaña, sobre copia) ───
$r1 = reservarEnvioLogico($db, $leadId, 0, 'Club Test', 'test@futprotec.local', 'TEST', 'test@x.com', 'tid_' . time(), 'Asunto', 'Cuerpo', null, null, null, 1);
check('reservarEnvioLogico(sin campaña) -> id/nuevo/estado', isset($r1['id']) && isset($r1['nuevo']) && $r1['estado'] === 'pendiente');
check('reservarEnvioLogico(sin campaña) nuevo=true', $r1['nuevo'] === true);

// ─── reservarEnvioLogico con campaña: idempotencia (INSERT OR IGNORE) ───
$campReal = (int)$db->querySingle("SELECT id FROM pipelines ORDER BY id LIMIT 1");
if ($campReal > 0 && $leadId > 0) {
    $ts = 'tid_c' . time();
    $a = reservarEnvioLogico($db, $leadId, $campReal, 'Club Test2', 'test2@futprotec.local', 'TEST', 'test@x.com', $ts, 'Asunto2', 'Cuerpo2', null, null, null, 1);
    $b = reservarEnvioLogico($db, $leadId, $campReal, 'Club Test2', 'test2@futprotec.local', 'TEST', 'test@x.com', $ts, 'Asunto2', 'Cuerpo2', null, null, null, 1);
    check('reservarEnvioLogico(campaña) 1ª = nuevo=true', ($a['nuevo'] ?? false) === true);
    check('reservarEnvioLogico(campaña) 2ª = nuevo=false (idempotente)', ($b['nuevo'] ?? true) === false);
    check('reservarEnvioLogico(campaña) mismo id', (int)($a['id'] ?? 0) === (int)($b['id'] ?? -1));
    check('getEnvioLogicoExistente devuelve fila', is_array(getEnvioLogicoExistente($db, $leadId, $campReal)));
} else {
    echo "  ℹ sin pipelines/leads válidos para idempotencia (se omite)\n";
}

$db->close();
unlink($dbPath);

echo "\n" . ($ok ? 'VEREDICTO: TEST_ELIGIBILIDAD_PASS' : 'VEREDICTO: TEST_ELIGIBILIDAD_FAIL') . "\n";
exit($ok ? 0 : 1);
