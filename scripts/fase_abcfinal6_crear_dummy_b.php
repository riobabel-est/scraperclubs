<?php
declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "BLOCKED: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$CAMPAIGN = 3;
$LEAD = 1817;

echo "==================== FASE 2 — CREAR DUMMY B (1817) ====================\n\n";

$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '');

if ($modoEntorno !== 'test' || $motorEstado !== 'pausado') {
    fwrite(STDERR, "BLOCKED: precondiciones de entorno no cumplidas ({$modoEntorno}/{$motorEstado})\n");
    $db->close();
    exit(3);
}

$exists = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE id = {$LEAD}");
if ($exists > 0) {
    fwrite(STDERR, "BLOCKED: lead_id={$LEAD} ya existe\n");
    $db->close();
    exit(4);
}

$stmt = $db->prepare(
    "INSERT INTO clubes_crm (nombre_club, email, estado_lead, es_duplicado)
     VALUES (:nombre, :email, '01 Sin Contactar', 0)"
);
$stmt->bindValue(':nombre', 'TEST_ABC_FINAL6_B', SQLITE3_TEXT);
$stmt->bindValue(':email',  'test_abc_final6_b@futprotec.local', SQLITE3_TEXT);
$stmt->execute();
$realId = (int)$db->lastInsertRowID();

echo "INSERT OK -> lead_id real = {$realId} (esperado 1817)\n";

if ($realId !== $LEAD) {
    fwrite(STDERR, "BLOCKED: ID real {$realId} != 1817\n");
    $db->close();
    exit(5);
}

$lead = $db->querySingle("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE id = {$realId}", true);
$variante = asignarVariante($realId, $CAMPAIGN);
$elig = esElegibleParaEnvio($db, $realId, $CAMPAIGN);
$enviosCount = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$realId} AND campaign_id = {$CAMPAIGN}");

echo "lead.id          = {$lead['id']}\n";
echo "lead.nombre_club = {$lead['nombre_club']}\n";
echo "lead.email       = {$lead['email']}\n";
echo "lead.estado_lead = {$lead['estado_lead']}\n";
echo "lead.es_duplicado = {$lead['es_duplicado']}\n";
echo "asignarVariante({$realId},3) = {$variante}\n";
echo "esElegibleParaEnvio = " . ($elig['ok'] ? 'elegible' : $elig['razon']) . "\n";
echo "COUNT(envios lead 1817 + campaign 3) = {$enviosCount}\n";

$maxEnvios = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
echo "MAX(envios.id) = {$maxEnvios} (debe seguir siendo 7)\n";

$db->close();

$ok = ($realId === $LEAD) && ($variante === 'B') && ($elig['ok'] === true) && ($enviosCount === 0) && ($maxEnvios === 7);

echo "\n==================== RESULTADO FASE 2 ====================\n";
if ($ok) {
    echo "DUMMY_B_CREATED (lead_id=1817, variante B, elegible, sin envíos)\n";
    exit(0);
}
echo "BLOCKED\n";
exit(6);
