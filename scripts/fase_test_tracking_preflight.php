<?php
$db = new SQLite3('public_html/outbound/data/stats.db');
$db->enableExceptions(true);

echo "1. Comprobando campaña...\n";
$c = $db->querySingle("SELECT id, identificador, estado, entorno, activo FROM pipelines WHERE id=3", true);
if (!$c || $c['estado'] !== 'PILOT' || $c['entorno'] !== 'test' || $c['activo'] != 1) die("Campaña 3 inválida\n");
echo "OK\n";

echo "2. Comprobando plantilla...\n";
$t = $db->querySingle("SELECT id, nombre, test_ab, activo, asunto, asunto_b, asunto_c FROM plantillas WHERE id=1", true);
if (!$t || $t['activo'] != 1 || $t['test_ab'] != 1 || !$t['asunto'] || !$t['asunto_b'] || !$t['asunto_c']) die("Plantilla 1 inválida o sin ABC completo\n");
echo "OK\n";

echo "3. Comprobando modo test...\n";
$m = $db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'");
if ($m !== 'test') die("modo_entorno no es test\n");
echo "OK\n";

echo "4. Comprobando test_emails...\n";
$e = $db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'");
if (strpos($e, 'estudioriobabel@gmail.com') === false) die("Faltan test_emails\n");
echo "OK\n";

echo "5. Comprobando SMTP disponible...\n";
$s = $db->querySingle("SELECT id FROM cuentas_smtp WHERE activa=1 LIMIT 1");
if (!$s) die("No hay SMTP activo\n");
echo "OK\n";

echo "PRE-FLIGHT SUPERADO. LISTO PARA ENVIAR.\n";
