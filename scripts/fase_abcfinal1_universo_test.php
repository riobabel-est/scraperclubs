<?php
/**
 * SOLO LECTURA — Inventario completo de leads TEST y envíos campaña 3.
 * No escribe en BD.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

echo "Total leads en clubes_crm: " . $db->querySingle("SELECT COUNT(*) FROM clubes_crm") . "\n";

echo "\n--- Leads con email LIKE %@futprotec.local% ---\n";
$r = $db->query("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE LOWER(email) LIKE '%@futprotec.local%' ORDER BY id");
while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
    echo "  id={$x['id']} | {$x['nombre_club']} | {$x['email']} | estado={$x['estado_lead']} | dup={$x['es_duplicado']}\n";
}

echo "\n--- Leads con nombre LIKE test% ---\n";
$r = $db->query("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE LOWER(nombre_club) LIKE 'test%' ORDER BY id");
while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
    echo "  id={$x['id']} | {$x['nombre_club']} | {$x['email']} | estado={$x['estado_lead']} | dup={$x['es_duplicado']}\n";
}

echo "\n--- Envios campaign_id=3 ---\n";
$r = $db->query("SELECT id, lead_id, campaign_id, variant, estado FROM envios WHERE campaign_id = 3 ORDER BY id");
while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
    echo "  envio_id={$x['id']} | lead_id={$x['lead_id']} | variant={$x['variant']} | estado={$x['estado']}\n";
}

$db->close();