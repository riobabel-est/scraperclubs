<?php
/**
 * FASE 6F.9D — PRE-FLIGHT SEGUNDO SMOKE (SOLO LECTURA).
 *
 * NO ejecuta SMTP, NO ejecuta enviar_lote.php, NO escribe en BD.
 * Verifica las condiciones exactas exigidas antes del envío único:
 *   - config.modo_entorno = test
 *   - config.motor_estado = pausado
 *   - campaña 3 válida (validarCampanaActiva real)
 *   - lead 1812 limpio (esLeadTest + esElegibleParaEnvio + idempotencia)
 *   - (1812,3) sin envío
 *   - destinatario config.test_emails = estudioriobabel@gmail.com
 *   - variante determinística asignarVariante(1812,3) = C
 *   - SMTP activo con capacidad (selección por id, misma del primer smoke)
 *
 * Salida: consola. Código 0 = READY, distinto de 0 = BLOCKED.
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
    echo str_pad($k, 48) . " : " . $v . "\n";
}

$abort = false;
function fail(string $msg): void {
    global $abort;
    $abort = true;
    echo "!! BLOCKED -> " . $msg . "\n";
}

$CAMPAIGN  = 3;
$LEAD      = 1812;
$PLANTILLA = 2;

// Destinatario tomado de configuración (misma fuente del frontend/endpoint).
$testEmailsRaw = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'") ?: '');
$testEmailsParsed = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $testEmailsRaw))));
$TEST_EMAIL = $testEmailsParsed[0] ?? '';

echo "================= 1. CONFIGURACIÓN GLOBAL =================\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: 'pausado');
show('config.modo_entorno', $modoEntorno);
show('config.motor_estado', $motorEstado);
if ($modoEntorno !== 'test') {
    fail("modo_entorno NO es 'test' (actual: {$modoEntorno})");
}
if ($motorEstado !== 'pausado') {
    fail("motor_estado NO es 'pausado' (actual: {$motorEstado})");
}

echo "\n================= 2. CAMPAÑA 3 =================\n";
$validacion = validarCampanaActiva($db, $CAMPAIGN, $modoEntorno);
show('validarCampanaActiva(3, test).ok', $validacion['ok']);
show('validarCampanaActiva(3, test).razon', $validacion['razon']);
if ($validacion['ok'] !== true || $validacion['razon'] !== 'CAMPANIA_VALIDA') {
    fail('La campaña 3 NO valida como CAMPANIA_VALIDA');
} else {
    $camp = $validacion['campaña'];
    show('campana.id', $camp['id']);
    show('campana.estado', $camp['estado']);
    show('campana.entorno', $camp['entorno']);
    show('campana.activo', $camp['activo']);
}

echo "\n================= 3. LEAD 1812 =================\n";
$lead = $db->querySingle("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE id = {$LEAD}", true);
if (!$lead) {
    fail("Lead {$LEAD} NO existe");
} else {
    show('lead.id', $lead['id']);
    show('lead.nombre_club', $lead['nombre_club']);
    show('lead.email', $lead['email']);
    show('lead.estado_lead', $lead['estado_lead']);
    show('lead.es_duplicado', $lead['es_duplicado']);
    $leadTest = esLeadTest($lead);
    show('esLeadTest(1812)', $leadTest);
    if (!$leadTest) {
        fail("Lead {$LEAD} NO es TEST según esLeadTest()");
    }
    $elig = esElegibleParaEnvio($db, $LEAD, $CAMPAIGN);
    show('esElegibleParaEnvio(1812,3).ok', $elig['ok']);
    show('esElegibleParaEnvio(1812,3).razon', $elig['razon']);
    if (!$elig['ok']) {
        fail("Lead {$LEAD} NO elegible: " . $elig['razon']);
    }
}

echo "\n================= 4. IDEMPOTENCIA PREVIA (1812,3) =================\n";
$nPre = (int)($db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN}") ?: 0);
show('envios(1812,3) COUNT actual', $nPre);
if ($nPre > 0) {
    fail("Idempotencia NO limpia para (1812,3): ya existe(n) {$nPre} fila(s)");
} else {
    show('idempotencia', 'LIMPIO');
}

echo "\n================= 5. PLANTILLA 2 =================\n";
$p2 = $db->querySingle(
    "SELECT id, nombre, activo, test_ab, categoria,
            (asunto IS NOT NULL AND asunto != '') AS t_asunto,
            (cuerpo IS NOT NULL AND cuerpo != '') AS t_cuerpo,
            (asunto_b IS NOT NULL AND asunto_b != '') AS t_asunto_b,
            (cuerpo_b IS NOT NULL AND cuerpo_b != '') AS t_cuerpo_b,
            (asunto_c IS NOT NULL AND asunto_c != '') AS t_asunto_c,
            (cuerpo_c IS NOT NULL AND cuerpo_c != '') AS t_cuerpo_c
     FROM plantillas WHERE id = {$PLANTILLA}",
    true
);
if (!$p2) {
    fail("Plantilla {$PLANTILLA} NO existe");
} else {
    show('plantilla.id', $p2['id']);
    show('plantilla.nombre', $p2['nombre']);
    show('plantilla.activo', $p2['activo']);
    show('plantilla.test_ab', $p2['test_ab']);
    show('plantilla.variante A (asunto/cuerpo)', ($p2['t_asunto'] && $p2['t_cuerpo']) ? 'OK' : 'FALTA');
    show('plantilla.variante B (asunto_b/cuerpo_b)', ($p2['t_asunto_b'] && $p2['t_cuerpo_b']) ? 'OK' : 'FALTA');
    show('plantilla.variante C (asunto_c/cuerpo_c)', ($p2['t_asunto_c'] && $p2['t_cuerpo_c']) ? 'OK' : 'FALTA');
    if ((int)$p2['activo'] !== 1) fail("Plantilla {$PLANTILLA} NO activa");
    if ((int)$p2['test_ab'] !== 1) fail("Plantilla {$PLANTILLA} test_ab != 1");
    if (!($p2['t_asunto_c'] && $p2['t_cuerpo_c'])) fail("Plantilla {$PLANTILLA} sin variante C completa");
}

echo "\n================= 6. VARIANTE (asignarVariante real) =================\n";
$variante = asignarVariante($LEAD, $CAMPAIGN);
show('asignarVariante(1812, 3)', $variante);
if ($variante !== 'C') {
    fail("Variante esperada C, calculada {$variante}");
}

echo "\n================= 7. DESTINATARIO =================\n";
show('config.test_emails (crudo)', $testEmailsRaw);
show('config.test_emails (buzones)', $testEmailsParsed);
show('destinatario test (primer buzón)', $TEST_EMAIL);
if ($TEST_EMAIL !== 'estudioriobabel@gmail.com') {
    fail("Destinatario configurado NO es estudioriobabel@gmail.com (sería: {$TEST_EMAIL})");
}
if (!filter_var($TEST_EMAIL, FILTER_VALIDATE_EMAIL)) {
    fail("Destinatario estudioriobabel@gmail.com NO es un email válido");
}

echo "\n================= 8. CUENTA SMTP ACTIVA CON CAPACIDAD =================\n";
$res = $db->query("SELECT id, email, host, puerto, seguridad, enviados_hoy, limite_diario, activa FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
$disponibles = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $enviadosReal = (int)$db->querySingle(
        "SELECT COUNT(*) FROM comunicaciones_log WHERE id_cuenta_smtp = {$r['id']} AND DATE(fecha) = DATE('now') AND tipo_evento = 'envio_email'"
    );
    $limite = (int)$r['limite_diario'];
    $cap = $limite - $enviadosReal;
    echo "smtp_id={$r['id']} | email={$r['email']} | host={$r['host']}:{$r['puerto']} | seg={$r['seguridad']} | enviados_hoy_real={$enviadosReal} | limite={$limite} | capacidad={$cap}\n";
    if ($cap > 0) {
        $disponibles[] = ['id' => (int)$r['id'], 'email' => $r['email'], 'cap' => $cap];
    }
}
if (count($disponibles) === 0) {
    fail('No hay cuentas SMTP disponibles con capacidad');
} else {
    $elegida = $disponibles[0];
    show('SMTP seleccionado id', $elegida['id']);
    show('SMTP seleccionado email', $elegida['email']);
    show('SMTP capacidad disponible', $elegida['cap']);
}

echo "\n================= 9. INTEGRIDAD envio_id=6 + MAX ID =================\n";
$e6 = $db->querySingle(
    "SELECT id, estado, resultado_envio, campaign_id, lead_id, variant, plantilla_id, smtp_id, message_id, tracking_id
     FROM envios WHERE id = 6",
    true
);
if (!$e6) {
    fail('envio_id=6 NO existe');
} else {
    show('envio_id=6.estado', $e6['estado']);
    show('envio_id=6.resultado_envio', $e6['resultado_envio']);
    show('envio_id=6.campaign_id', $e6['campaign_id']);
    show('envio_id=6.lead_id', $e6['lead_id']);
    show('envio_id=6.variant', $e6['variant']);
    show('envio_id=6.plantilla_id', $e6['plantilla_id']);
    show('envio_id=6.smtp_id', $e6['smtp_id']);
    show('envio_id=6.message_id', $e6['message_id']);
    show('envio_id=6.tracking_id', $e6['tracking_id']);
    if ($e6['lead_id'] != 1810) fail("envio_id=6.lead_id NO es 1810 (actual {$e6['lead_id']})");
    if ($e6['estado'] !== 'enviado') fail("envio_id=6.estado NO es 'enviado'");
    if ($e6['resultado_envio'] !== 'ACCEPTED') fail("envio_id=6.resultado_envio NO es ACCEPTED");
}

$maxId = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
show('MAX(envios.id)', $maxId);
if ($maxId !== 6) {
    fail("MAX(envios.id) NO es 6 (actual {$maxId}) — hay envíos posteriores no documentados");
}

echo "\n================= 10. ESQUEMA REFERENCIA =================\n";
echo "-- envios --\n";
foreach ($db->query("PRAGMA table_info(envios)") as $c) {
    echo "  {$c['name']} ({$c['type']})\n";
}
echo "-- comunicaciones_log --\n";
foreach ($db->query("PRAGMA table_info(comunicaciones_log)") as $c) {
    echo "  {$c['name']} ({$c['type']})\n";
}

echo "\n================= VEREDICTO PRE-FLIGHT =================\n";
if ($abort) {
    echo "BLOCKED\n";
    $db->close();
    exit(1);
}
echo "READY_FOR_SECOND_SMOKE (pre-flight OK)\n";
echo "PARAMS: campaign_id=3 id_club=1812 id_plantilla=2 id_cuenta_smtp={$elegida['id']} modo_test=1 test_email={$TEST_EMAIL}\n";
$db->close();
exit(0);