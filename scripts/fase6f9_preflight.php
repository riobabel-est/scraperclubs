<?php
/**
 * FASE 6F.9 — PRE-FLIGHT SMOKE CONTROLADO (SOLO LECTURA).
 *
 * NO ejecuta SMTP, NO ejecuta enviar_lote.php, NO escribe en BD.
 * Valida con las FUNCIONES REALES del flujo (inc/abc.php + inc/eligibilidad.php).
 *
 * Comprobaciones FASE 1-3:
 *  - config.modo_entorno / motor_estado
 *  - campaña 3 (validarCampanaActiva real)
 *  - lead 1810 (esLeadTest, esElegibleParaEnvio, idempotencia)
 *  - plantilla 2 (activa, test_ab, variantes)
 *  - variante A mediante asignarVariante(1810, 3)
 *  - destinatario final previsto (override estático modo test)
 *  - SMTP activo con capacidad (enviados hoy reales desde comunicaciones_log)
 *
 * Salida: consola. Código de proceso 0 = TODO OK, distinto de 0 = ABORT.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ABORT: stats.db no encontrada en {$DB}\n");
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
    echo str_pad($k, 46) . " : " . $v . "\n";
}

$abort = false;
function fail(string $msg): void {
    global $abort;
    $abort = true;
    echo "!! ABORT -> " . $msg . "\n";
}

$CAMPAIGN = 3;
$LEAD     = 1810;
$PLANTILLA = 2;

// El destinatario de test se obtiene de la configuración real (config.test_emails),
// que es la misma fuente que usa el frontend (tabs/lanzadera.php + js/app.js) para
// proporcionar el valor del POST `test_email`. El primer buzón es el destinatario
// seleccionado por defecto en modo test.
$testEmailsRaw = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'") ?: '');
$testEmailsParsed = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $testEmailsRaw))));
$TEST_EMAIL = $testEmailsParsed[0] ?? '';

echo "================= 1. CONFIGURACIÓN GLOBAL =================\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: 'pausado');
show('config.modo_entorno', $modoEntorno);
show('config.motor_estado', $motorEstado);
if ($modoEntorno !== 'test') {
    fail("modo_entorno NO es 'test' (actual: {$modoEntorno}) — no se puede ejecutar smoke de campaña TEST");
}
if ($motorEstado !== 'pausado') {
    fail("motor_estado NO es 'pausado' (actual: {$motorEstado})");
}

echo "\n================= 2. CAMPAÑA (validarCampanaActiva real) =================\n";
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

echo "\n================= 3. LEAD 1810 =================\n";
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
    show('esLeadTest(1810)', $leadTest);
    if (!$leadTest) {
        fail("Lead {$LEAD} NO es TEST según esLeadTest()");
    }
    $elig = esElegibleParaEnvio($db, $LEAD, $CAMPAIGN);
    show('esElegibleParaEnvio(1810,3).ok', $elig['ok']);
    show('esElegibleParaEnvio(1810,3).razon', $elig['razon']);
    if (!$elig['ok']) {
        fail("Lead {$LEAD} NO elegible: " . $elig['razon']);
    }
}

echo "\n================= 4. IDEMPOTENCIA PREVIA (lead 1810 + campaign 3) =================\n";
$rowsPre = $db->querySingle(
    "SELECT COUNT(*) AS n FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN}",
    true
);
$nPre = (int)($rowsPre['n'] ?? 0);
show('envios(1810,3) COUNT actual', $nPre);
if ($nPre > 0) {
    $bloq = $db->querySingle("SELECT id, estado, resultado_envio FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN} ORDER BY id DESC LIMIT 1", true);
    show('envio existente id', $bloq['id'] ?? 'NULL');
    show('envio existente estado', $bloq['estado'] ?? 'NULL');
    show('envio existente resultado', $bloq['resultado_envio'] ?? 'NULL');
    // Fase 1 pide idempotencia LIMPIO (0 envios). Si hay >0, ABORT.
    fail("Idempotencia NO limpia para (1810,3): ya existe(n) {$nPre} fila(s)");
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
    show('plantilla.categoria', $p2['categoria']);
    show('plantilla.variante A (asunto/cuerpo)', ($p2['t_asunto'] && $p2['t_cuerpo']) ? 'OK' : 'FALTA');
    show('plantilla.variante B (asunto_b/cuerpo_b)', ($p2['t_asunto_b'] && $p2['t_cuerpo_b']) ? 'OK' : 'FALTA');
    show('plantilla.variante C (asunto_c/cuerpo_c)', ($p2['t_asunto_c'] && $p2['t_cuerpo_c']) ? 'OK' : 'FALTA');
    if ((int)$p2['activo'] !== 1) fail("Plantilla {$PLANTILLA} NO activa");
    if ((int)$p2['test_ab'] !== 1) fail("Plantilla {$PLANTILLA} test_ab != 1");
    if (!($p2['t_asunto'] && $p2['t_cuerpo'])) fail("Plantilla {$PLANTILLA} sin variante A completa");
}

echo "\n================= 6. VARIANTE (asignarVariante real) =================\n";
$variante = asignarVariante($LEAD, $CAMPAIGN);
show('asignarVariante(1810, 3)', $variante);
if ($variante !== 'A') {
    fail("Variante esperada A, calculada {$variante}");
}

echo "\n================= 7. DESTINATARIO FINAL PREVISTO =================\n";
show('config.test_emails (crudo)', $testEmailsRaw);
show('config.test_emails (buzones)', $testEmailsParsed);
show('destinatario test (primer buzón)', $TEST_EMAIL);
// Réplica estática de la lógica de enviar_lote.php (líneas 203-211).
$modoTestBD = ($modoEntorno === 'test');
$modoTest = $modoTestBD; // sin POST, en smoke enviaremos modo_test=1 (redundante pero coherente)
show('modo_test BD (entorno test)', $modoTestBD);
show('modo_test efectivo', $modoTest);
$leadEmail = (string)($lead['email'] ?? '');
$destino = '';
if ($modoTest && $TEST_EMAIL !== '' && filter_var($TEST_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $destino = $TEST_EMAIL;
} elseif ($modoTest) {
    $destino = 'contactofutprotec@gmail.com';
} else {
    $destino = $leadEmail;
}
show('destinatario FINAL previsto', $destino);
show('lead.email original', $leadEmail);
if ($destino !== $TEST_EMAIL) {
    fail("Destinatario final NO es {$TEST_EMAIL} (sería: {$destino})");
}

echo "\n================= 8. CUENTAS SMTP ACTIVAS CON CAPACIDAD =================\n";
$res = $db->query("SELECT id, email, host, puerto, seguridad, enviados_hoy, limite_diario, activa, nombre_emisor FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
$disponibles = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $enviadosReal = (int)$db->querySingle(
        "SELECT COUNT(*) FROM comunicaciones_log WHERE id_cuenta_smtp = {$r['id']} AND DATE(fecha) = DATE('now') AND tipo_evento = 'envio_email'"
    );
    $limite = (int)$r['limite_diario'];
    $cap = $limite - $enviadosReal;
    echo "smtp_id={$r['id']} | email={$r['email']} | host={$r['host']}:{$r['puerto']} | seg={$r['seguridad']} | contador_enviados_hoy={$r['enviados_hoy']} | enviados_hoy_real={$enviadosReal} | limite={$limite} | capacidad={$cap}\n";
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

echo "\n================= 9. ESQUEMA REFERENCIA (para auditoría) =================\n";
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
    echo "SMOKE_ABORTED_BEFORE_SMTP\n";
    $db->close();
    exit(1);
}
echo "README_FOR_SMOKE (todo pre-flight OK)\n";
$db->close();
exit(0);