<?php
/**
 * FASE 6F.9D — VALIDACIÓN POST-ENVÍO (SOLO LECTURA).
 *
 * NO ejecuta ningún envío, NO escribe en BD, NO ejecuta cron ni SMTP.
 * Comprueba la trazabilidad completa del único envío (lead 1812, campaign 3).
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

function show(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
    echo str_pad($k, 52) . " : " . $v . "\n";
}

$LEAD = 1812;
$CAMPAIGN = 3;

echo "================= A. ENVIOS (lead 1812 + campaign 3) =================\n";
$rows = [];
$res = $db->query("SELECT * FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN} ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $r;
}
show('envios(1812,3) COUNT', count($rows));
foreach ($rows as $e) {
    echo "--- envio id={$e['id']} ---\n";
    foreach (['id','club','email','federacion','cuenta_emision','estado','resultado_envio','lead_id','campaign_id','variant','plantilla_id','smtp_id','message_id','tracking_id','fecha_resultado_envio','fecha_creacion'] as $col) {
        show("  {$col}", $e[$col] ?? null);
    }
}

echo "\n================= B. IDEMPOTENCIA =================\n";
$n = (int)($db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$LEAD} AND campaign_id = {$CAMPAIGN}") ?: 0);
show('COUNT(envios WHERE lead_id=1812 AND campaign_id=3)', $n);
show('idempotencia OK (==1)', $n === 1 ? 'true' : 'false');

echo "\n================= C. INTEGRIDAD envio_id=6 =================\n";
$e6 = $db->querySingle(
    "SELECT id, estado, resultado_envio, campaign_id, lead_id, variant, plantilla_id, smtp_id, message_id, tracking_id
     FROM envios WHERE id = 6",
    true
);
if (!$e6) {
    show('envio_id=6', 'NO EXISTE');
} else {
    foreach ($e6 as $k => $v) {
        show("envio_id=6.{$k}", $v);
    }
}

echo "\n================= D. MAX ID =================\n";
$maxId = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
show('MAX(envios.id)', $maxId);

echo "\n================= E. COMUNICACIONES LOG (lead 1812) =================\n";
$logs = [];
$r2 = $db->query("SELECT * FROM comunicaciones_log WHERE lead_id = {$LEAD} ORDER BY id DESC LIMIT 10");
while ($l = $r2->fetchArray(SQLITE3_ASSOC)) {
    $logs[] = $l;
}
show('comunicaciones_log(lead 1812) COUNT (últimas 10)', count($logs));
foreach ($logs as $l) {
    echo "--- log id={$l['id']} ---\n";
    foreach (['id','lead_id','club_id','tipo_evento','plantilla_id','id_cuenta_smtp','tipo','resultado','codigo_error','variante_ab','detalles','fecha'] as $col) {
        show("  {$col}", $l[$col] ?? null);
    }
}

echo "\n================= F. LEAD 1812 =================\n";
$lead = $db->querySingle(
    "SELECT id, nombre_club, email, estado_lead, ultimo_contacto, observaciones, es_duplicado
     FROM clubes_crm WHERE id = {$LEAD}",
    true
);
if (!$lead) {
    show('lead 1812', 'NO EXISTE');
} else {
    foreach ($lead as $k => $v) {
        show("lead.{$k}", $v);
    }
}

echo "\n================= G. CONTEO GLOBAL DE SEGURIDAD =================\n";
$totales = (int)($db->querySingle("SELECT COUNT(*) FROM envios") ?: 0);
show('envios totales', $totales);
$enviosCamp3 = (int)($db->querySingle("SELECT COUNT(*) FROM envios WHERE campaign_id = 3") ?: 0);
show('envios campaign_id=3 total', $enviosCamp3);
$logsHoyEnvio = (int)($db->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE DATE(fecha) = DATE('now') AND tipo_evento = 'envio_email'") ?: 0);
show('comunicaciones_log envio_email hoy', $logsHoyEnvio);

$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '?');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '?');
show('config.modo_entorno (post)', $modoEntorno);
show('config.motor_estado (post)', $motorEstado);

$db->close();
echo "\nFIN VALIDACIÓN POST-ENVÍO (solo lectura)\n";
exit(0);