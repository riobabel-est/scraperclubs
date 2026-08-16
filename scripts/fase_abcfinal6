<?php
/**
 * FASE ABC-FINAL.6 — PRECHECK SOLO LECTURA (Fase 1).
 *
 * NO crea leads, NO envía, NO SMTP, NO escribe en BD.
 * Verifica las precondiciones exactas antes de crear el dummy B (1817) y
 * ejecutar el smoke único.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "BLOCKED: stats.db no encontrada en {$DB}\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

function show(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
    echo str_pad($k, 52) . " : " . $v . "\n";
}

$abort = false;
function fail(string $msg): void {
    global $abort;
    $abort = true;
    echo "!! BLOCKED -> " . $msg . "\n";
}

$CAMPAIGN  = 3;
$LEAD      = 1817;
$PLANTILLA = 2;

echo "================= 1. CONFIGURACIÓN GLOBAL =================\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: 'pausado');
show('config.modo_entorno', $modoEntorno);
show('config.motor_estado', $motorEstado);
if ($modoEntorno !== 'test') fail("modo_entorno NO es 'test'");
if ($motorEstado !== 'pausado') fail("motor_estado NO es 'pausado'");

echo "\n================= 2. MAX(clubes_crm.id) e inexistencia de 1817 =================\n";
$maxLeadId = (int)($db->querySingle("SELECT MAX(id) FROM clubes_crm") ?: 0);
show('MAX(clubes_crm.id)', $maxLeadId);
if ($maxLeadId !== 1816) fail("MAX(clubes_crm.id) NO es 1816 (actual {$maxLeadId})");
$exists1817 = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE id = 1817");
show('lead_id=1817 COUNT', $exists1817);
if ($exists1817 !== 0) fail("lead_id=1817 YA EXISTE");

echo "\n================= 3. CAMPAÑA 3 =================\n";
$validacion = validarCampanaActiva($db, $CAMPAIGN, $modoEntorno);
show('validarCampanaActiva(3, test).ok', $validacion['ok']);
show('validarCampanaActiva(3, test).razon', $validacion['razon']);
if ($validacion['ok'] !== true) fail('campaña 3 NO válida (PILOT/test/activo=1)');
else {
    show('campana.estado', $validacion['campaña']['estado']);
    show('campana.entorno', $validacion['campaña']['entorno']);
    show('campana.activo', $validacion['campaña']['activo']);
}

echo "\n================= 4. PLANTILLA 2 =================\n";
$p2 = $db->querySingle(
    "SELECT id, activo, test_ab,
            (asunto IS NOT NULL AND asunto != '') AS t_asunto,
            (cuerpo IS NOT NULL AND cuerpo != '') AS t_cuerpo,
            (asunto_b IS NOT NULL AND asunto_b != '') AS t_asunto_b,
            (cuerpo_b IS NOT NULL AND cuerpo_b != '') AS t_cuerpo_b,
            (asunto_c IS NOT NULL AND asunto_c != '') AS t_asunto_c,
            (cuerpo_c IS NOT NULL AND cuerpo_c != '') AS t_cuerpo_c
     FROM plantillas WHERE id = {$PLANTILLA}",
    true
);
show('plantilla.id', $p2['id'] ?? null);
show('plantilla.activo', $p2['activo'] ?? null);
show('plantilla.test_ab', $p2['test_ab'] ?? null);
if (!$p2 || (int)$p2['activo'] !== 1) fail('plantilla 2 NO activa');
if ((int)$p2['test_ab'] !== 1) fail('plantilla 2 test_ab != 1');
if (!($p2['t_cuerpo_b'] && $p2['t_asunto_b'])) fail('plantilla 2 sin variante B completa (asunto_b/cuerpo_b)');

echo "\n================= 5. VARIANTE determinística de 1817 =================\n";
$variante = asignarVariante($LEAD, $CAMPAIGN);
show('asignarVariante(1817, 3)', $variante);
if ($variante !== 'B') fail("variante esperada B, calculada {$variante}");

echo "\n================= 6. DESTINATARIO config.test_emails =================\n";
$testEmailsRaw = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'") ?: '');
$testEmailsParsed = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $testEmailsRaw))));
$TEST_EMAIL = $testEmailsParsed[0] ?? '';
show('config.test_emails (crudo)', $testEmailsRaw);
show('config.test_emails (buzones)', $testEmailsParsed);
show('destinatario (primer buzón)', $TEST_EMAIL);
if ($TEST_EMAIL !== 'estudioriobabel@gmail.com') fail("primer buzón NO es estudioriobabel@gmail.com (sería {$TEST_EMAIL})");
if (!filter_var($TEST_EMAIL, FILTER_VALIDATE_EMAIL)) fail("destinatario no es email válido");

echo "\n================= 7. CUENTA SMTP ACTIVA CON CAPACIDAD =================\n";
$res = $db->query("SELECT id, email, host, puerto, seguridad, activa, limite_diario FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
$disponibles = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $enviadosReal = (int)$db->querySingle(
        "SELECT COUNT(*) FROM comunicaciones_log WHERE id_cuenta_smtp = {$r['id']} AND DATE(fecha) = DATE('now') AND tipo_evento = 'envio_email'"
    );
    $cap = (int)$r['limite_diario'] - $enviadosReal;
    echo "smtp_id={$r['id']} | {$r['email']} | enviados_hoy_real={$enviadosReal} | capacidad={$cap}\n";
    if ($cap > 0) $disponibles[] = ['id' => (int)$r['id'], 'email' => $r['email'], 'cap' => $cap];
}
if (count($disponibles) === 0) fail('no hay cuentas SMTP disponibles');
else {
    show('SMTP seleccionado id', $disponibles[0]['id']);
    show('SMTP seleccionado email', $disponibles[0]['email']);
    show('SMTP capacidad', $disponibles[0]['cap']);
}

echo "\n================= 8. INTEGRIDAD envios =================\n";
$maxEnvios = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
show('MAX(envios.id)', $maxEnvios);
if ($maxEnvios !== 7) fail("MAX(envios.id) NO es 7 (actual {$maxEnvios})");
$e6 = $db->querySingle("SELECT id, lead_id, variant, estado, resultado_envio FROM envios WHERE id = 6", true);
$e7 = $db->querySingle("SELECT id, lead_id, variant, estado, resultado_envio FROM envios WHERE id = 7", true);
show('envio_id=6', $e6 ? json_encode($e6, JSON_UNESCAPED_UNICODE) : 'NO EXISTE');
show('envio_id=7', $e7 ? json_encode($e7, JSON_UNESCAPED_UNICODE) : 'NO EXISTE');
if (!$e6 || $e6['lead_id'] != 1810 || $e6['variant'] !== 'A') fail('envio_id=6 no íntegro (esperado lead 1810 variante A)');
if (!$e7 || $e7['lead_id'] != 1812 || $e7['variant'] !== 'C') fail('envio_id=7 no íntegro (esperado lead 1812 variante C)');

$db->close();

echo "\n================= VEREDICTO PRECHECK =================\n";
if ($abort) {
    echo "BLOCKED\n";
    exit(1);
}
echo "READY_FOR_ABC_FINAL6_SMOKE_B (precheck OK)\n";
echo "PARAMS: campaign_id=3 id_club=1817 id_plantilla=2 id_cuenta_smtp={$disponibles[0]['id']} modo_test=1 test_email={$TEST_EMAIL}\n";
exit(0);