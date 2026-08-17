<?php
$db = new SQLite3('public_html/outbound/data/stats.db');
$smtpId = $db->querySingle("SELECT id FROM cuentas_smtp WHERE activa=1 LIMIT 1");

$var = $argv[1] ?? 'A';
$email = $argv[2] ?? 'estudioriobabel@gmail.com';
$id_club = $argv[3] ?? 1814;

echo "Enviando variante {$var} a {$email} (club {$id_club})...\n";
$_POST = [
    'id_club' => $id_club,
    'id_plantilla' => 1,
    'id_cuenta_smtp' => $smtpId,
    'modo_test' => 1,
    'variante_ab' => $var,
    'campaign_id' => 3,
    'test_email' => $email
];
require 'public_html/outbound/api/enviar_lote.php';
