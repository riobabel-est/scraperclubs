<?php
declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "BLOCKED: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';

function show(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
    echo str_pad($k, 52) . " : " . $v . "\n";
}

function normalizarCuerpo(string $body): string {
    $b = preg_replace('/<img src="[^"]*track\.php\?id=[^"]*"[^>]*>/i', '', $body);
    $b = preg_replace('/<!--\s*fpid:[^>]*-->/i', '', $b);
    return trim($b);
}

$CAMPAIGN = 3;
$LEAD = 1817;
$PLANTILLA = 2;
$SMTP = 1;

$abort = false;
function fail(string $msg): void {
    global $abort;
    $abort = true;
    echo "!! BLOCKED -> " . $msg . "\n";
}

echo "================= POSTCHECK ABC-FINAL.6 (SOLO LECTURA) =================\n\n";

// 1. Nuevo envío
echo "--- 1. Envío lead 1817 + campaign 3 ---\n";
$e = $db->querySingle("SELECT * FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN} ORDER BY id DESC LIMIT 1", true);
if (!$e) { fail("no existe envío para lead 1817 campaign 3"); }
else {
    foreach (['id','lead_id','campaign_id','variant','plantilla_id','smtp_id','estado','resultado_envio','message_id','tracking_id','asunto','club','email','cuenta_emision'] as $col) {
        $v = $e[$col] ?? null;
        if ($col === 'asunto') { show('envio.asunto', $v); }
        elseif ($col === 'message_id' || $col === 'tracking_id') { show('envio.'.$col, ($v && $v !== '') ? $v : '(VACIO)'); }
        else { show('envio.'.$col, $v); }
    }
    show('envio.cuerpo_mensaje bytes', strlen((string)($e['cuerpo_mensaje'] ?? '')));

    if ((int)$e['id'] !== 8) fail("envio.id NO es 8 (actual {$e['id']})");
    if ((int)$e['lead_id'] !== 1817) fail("envio.lead_id != 1817");
    if ((int)$e['campaign_id'] !== 3) fail("envio.campaign_id != 3");
    if ($e['variant'] !== 'B') fail("envio.variant != B (actual {$e['variant']})");
    if ((int)$e['plantilla_id'] !== 2) fail("envio.plantilla_id != 2");
    if ((int)$e['smtp_id'] !== 1) fail("envio.smtp_id inválido");
    if ($e['estado'] !== 'enviado') fail("envio.estado != enviado (actual {$e['estado']})");
    if ($e['resultado_envio'] !== 'ACCEPTED') fail("envio.resultado_envio != ACCEPTED (actual {$e['resultado_envio']})");
    if (empty($e['message_id'])) fail("envio.message_id vacío");
    if (empty($e['tracking_id'])) fail("envio.tracking_id vacío");
}

// 2. Contenido: comparar con variante B resuelta
echo "\n--- 2. Contenido: verificar cuerpo B (no A ni C) ---\n";
$p = $db->querySingle("SELECT asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c FROM plantillas WHERE id = {$PLANTILLA}", true);
$club = $db->querySingle("SELECT nombre_club, email, federacion, persona_contacto FROM clubes_crm WHERE id = {$LEAD}", true);
$cuenta = $db->querySingle("SELECT email, nombre_emisor, cargo_emisor FROM cuentas_smtp WHERE id = {$SMTP}", true);

$nombreClub = $club['nombre_club'];
$emailClub = $club['email'];
$federacion = (string)($club['federacion'] ?? '');
$contacto = ($club['persona_contacto'] ?? '') ?: 'responsable';
$senderName = (string)($cuenta['nombre_emisor'] ?? '');
if ($senderName === '') $senderName = ucfirst(explode('@', (string)$cuenta['email'])[0]);
$senderTitle = (string)($cuenta['cargo_emisor'] ?? '');
$senderEmail = (string)$cuenta['email'];

$replacements = [
    '{{CLUB}}' => $nombreClub,
    '{{CONTACTO}}' => $contacto,
    '{{FEDERACION}}' => $federacion,
    '{{ANIO}}' => date('Y'),
    '{{EMAIL}}' => $emailClub,
    '{{SENDER_NAME}}' => $senderName,
    '{{SENDER_TITLE}}' => $senderTitle,
    '{{SENDER_EMAIL}}' => $senderEmail,
];

$resolver = function(string $variant) use ($p, $replacements): array {
    $c = resolverContenidoVariante([
        'asunto' => $p['asunto'], 'cuerpo' => $p['cuerpo'],
        'asunto_b' => $p['asunto_b'], 'cuerpo_b' => $p['cuerpo_b'],
        'asunto_c' => $p['asunto_c'], 'cuerpo_c' => $p['cuerpo_c'],
        'test_ab' => $p['test_ab'],
    ], $variant);
    $asunto = str_replace(array_keys($replacements), array_values($replacements), (string)$c['asunto']);
    $cuerpo = str_replace(array_keys($replacements), array_values($replacements), (string)$c['cuerpo']);
    return ['asunto' => trim($asunto), 'cuerpo' => trim($cuerpo)];
};

$resA = $resolver('A');
$resB = $resolver('B');
$resC = $resolver('C');

$storedAsunto = trim((string)$e['asunto']);
$storedCuerpo = normalizarCuerpo((string)$e['cuerpo_mensaje']);

$asuntoOkB = ($storedAsunto === $resB['asunto']);
$cuerpoOkB = ($storedCuerpo === $resB['cuerpo']);
$cuerpoNoA = ($storedCuerpo !== $resA['cuerpo']);
$cuerpoNoC = ($storedCuerpo !== $resC['cuerpo']);

show('asunto almacenado == asunto_b resuelto', $asuntoOkB ? 'SÍ' : 'NO');
show('cuerpo_mensaje == cuerpo_b resuelto', $cuerpoOkB ? 'SÍ' : 'NO');
show('cuerpo_mensaje != cuerpo A', $cuerpoNoA ? 'SÍ' : 'NO');
show('cuerpo_mensaje != cuerpo C', $cuerpoNoC ? 'SÍ' : 'NO');

if (!$asuntoOkB) { show('asunto esperado B', $resB['asunto']); }
if (!$cuerpoOkB) {
    show('cuerpo B len', strlen($resB['cuerpo']));
    show('cuerpo almacenado normalizado len', strlen($storedCuerpo));
    echo "--- cuerpo almacenado (inicio) ---\n" . mb_substr($storedCuerpo, 0, 300) . "\n";
    echo "--- cuerpo B esperado (inicio) ---\n" . mb_substr($resB['cuerpo'], 0, 300) . "\n";
}
if (!$cuerpoNoA) fail("cuerpo_mensaje coincide con cuerpo A");
if (!$cuerpoNoC) fail("cuerpo_mensaje coincide con cuerpo C");

// 3. Integridad
echo "\n--- 3. Integridad ---\n";
$maxEnvios = (int)$db->querySingle("SELECT MAX(id) FROM envios");
show('MAX(envios.id)', $maxEnvios);
if ($maxEnvios !== 8) fail("MAX(envios.id) != 8 (actual {$maxEnvios})");

$newCount = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE campaign_id = {$CAMPAIGN} AND id > 7");
show('COUNT(envios campaign_id=3 AND id>7)', $newCount);
if ($newCount !== 1) fail("COUNT(id>7) != 1 (actual {$newCount})");

$e6 = $db->querySingle("SELECT id, lead_id, variant, estado, resultado_envio, message_id FROM envios WHERE id = 6", true);
$e7 = $db->querySingle("SELECT id, lead_id, variant, estado, resultado_envio, message_id FROM envios WHERE id = 7", true);
show('envio_id=6', $e6 ? json_encode($e6, JSON_UNESCAPED_UNICODE) : 'NO EXISTE');
show('envio_id=7', $e7 ? json_encode($e7, JSON_UNESCAPED_UNICODE) : 'NO EXISTE');
if (!$e6 || $e6['lead_id'] != 1810 || $e6['variant'] !== 'A' || $e6['estado'] !== 'enviado' || $e6['resultado_envio'] !== 'ACCEPTED') fail('envio_id=6 no íntegro');
if (!$e7 || $e7['lead_id'] != 1812 || $e7['variant'] !== 'C' || $e7['estado'] !== 'enviado' || $e7['resultado_envio'] !== 'ACCEPTED') fail('envio_id=7 no íntegro');

// 4. Estado operativo
echo "\n--- 4. Estado operativo intacto ---\n";
$modoEntorno = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'");
$motorEstado = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'");
show('modo_entorno', $modoEntorno);
show('motor_estado', $motorEstado);
$camp = $db->querySingle("SELECT estado, entorno, activo FROM pipelines WHERE id = 3", true);
show('campaña 3', json_encode($camp, JSON_UNESCAPED_UNICODE));
$plant = $db->querySingle("SELECT activo, test_ab FROM plantillas WHERE id = 2", true);
show('plantilla 2', json_encode($plant, JSON_UNESCAPED_UNICODE));
if ($modoEntorno !== 'test') fail("modo_entorno cambió a {$modoEntorno}");
if ($motorEstado !== 'pausado') fail("motor_estado cambió a {$motorEstado}");

$db->close();

echo "\n================= VEREDICTO POSTCHECK =================\n";
if ($abort) { echo "BLOCKED\n"; exit(1); }
if ($asuntoOkB && $cuerpoOkB && $cuerpoNoA && $cuerpoNoC && $maxEnvios === 8 && $newCount === 1) {
    echo "ABC_OPERATIONAL_PASS\n";
    exit(0);
}
echo "BLOCKED\n";
exit(2);
