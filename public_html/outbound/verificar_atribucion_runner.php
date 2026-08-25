<?php
/**
 * Runner web temporal: Verifica el estado de atribución de las respuestas IMAP.
 * Solo lectura. No modifica nada.
 */
// Secreto del runner (centro único: inc/secret.php — gitignored + .htaccess)
$__secretos = [];
if (file_exists(__DIR__ . '/inc/secret.php')) {
    $__secretos = require __DIR__ . '/inc/secret.php';
}
define('AUTH_KEY', (string)($__secretos['auth_runners'] ?? ''));
if (!isset($_GET['token']) || $_GET['token'] !== AUTH_KEY) {
    http_response_code(403);
    echo "Acceso denegado";
    exit;
}

$DB_PATH = __DIR__ . '/data/stats.db';
if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

echo "=== VERIFICACIÓN DE ATRIBUCIÓN DE RESPUESTAS ===\n";
echo "BD: {$DB_PATH}\n\n";

// Estado de las respuestas 8 y 11
$stmt = $db->prepare("SELECT id, remitente, clasificacion, lead_id, envio_id, campaign_id, id_cuenta_smtp FROM respuestas WHERE id IN (8, 11)");
$res = $stmt->execute();
echo "--- Respuestas 8 y 11 ---\n";
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "id={$row['id']} | remitente='{$row['remitente']}' | clasificacion='{$row['clasificacion']}' | lead_id=" . var_export($row['lead_id'], true) . " | envio_id=" . var_export($row['envio_id'], true) . " | campaign_id=" . var_export($row['campaign_id'], true) . " | id_cuenta_smtp=" . var_export($row['id_cuenta_smtp'], true) . "\n";
}

// Estado de los leads 1217 y 407
echo "\n--- Leads 1217 y 407 ---\n";
$stmt = $db->prepare("SELECT id, nombre, email, estado_lead, ultimo_contacto FROM clubes_crm WHERE id IN (1217, 407)");
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "id={$row['id']} | nombre='{$row['nombre']}' | email='{$row['email']}' | estado_lead='{$row['estado_lead']}' | ultimo_contacto='{$row['ultimo_contacto']}'\n";
}

// Conteo de respuestas sin atribuir
echo "\n--- Conteo respuestas sin atribuir (lead_id null/vacio) ---\n";
$total = $db->querySingle("SELECT COUNT(*) FROM respuestas WHERE lead_id IS NULL OR lead_id = ''");
echo "Total respuestas sin lead_id: {$total}\n";

$db->close();
echo "\n=== FIN VERIFICACIÓN ===\n";
