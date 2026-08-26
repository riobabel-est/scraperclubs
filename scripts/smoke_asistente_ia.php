<?php
// Smoke del Asistente IA (motor de propuestas) sobre una COPIA TEMPORAL de la BD
// SIN API keys — valida reglas, persistencia, dup-check y aprobar/rechazar sin
// gastar tokens de producción.
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/stats_asistente_' . getmypid() . '.db';
copy(__DIR__ . '/../public_html/outbound/data/stats.db', $tmp);
$db = new SQLite3($tmp);
$action = '';
$_GET = [];
$db->exec("DELETE FROM config WHERE clave LIKE '%_api_key' OR clave = 'ia_proveedor'");
$db->exec("DELETE FROM propuestas_ia");
require __DIR__ . '/../public_html/outbound/api/analytics.php';
require __DIR__ . '/../public_html/outbound/inc/motor_propuestas.php';

$ok = true;
function check(string $nombre, bool $cond, string $det = ''): void {
    global $ok;
    echo ($cond ? 'PASS' : 'FAIL') . " $nombre" . ($det !== '' ? " ($det)" : '') . "\n";
    if (!$cond) $ok = false;
}

$candidatas = motor_reglas_candidatos($db, 2);
check('reglas genera candidatas', is_array($candidatas) && count($candidatas) > 0, 'count=' . count($candidatas));
$tipos = [];
foreach ($candidatas as $c) $tipos[] = $c['tipo'];
check('tipos esperados', in_array('perseguir', $tipos, true) || in_array('calentar', $tipos, true), implode(',', array_unique($tipos)));

$nuevas = motor_generar_propuestas($db, 2);
check('persistencia inserciones', count($nuevas) > 0, 'generadas=' . count($nuevas));
$n2 = motor_generar_propuestas($db, 2);
check('no duplica pendientes', count($n2) === 0, 'segunda pasada=' . count($n2));
$total = (int)$db->querySingle('SELECT COUNT(*) FROM propuestas_ia WHERE estado = "pendiente"');
check('pendientes en BD', $total === count($nuevas), "total=$total esperado=" . count($nuevas));

$id = (int)$nuevas[0]['id'];
$db->exec("UPDATE propuestas_ia SET estado = 'aprobada', aprobado_el = CURRENT_TIMESTAMP WHERE id = $id");
$e = (string)$db->querySingle("SELECT estado FROM propuestas_ia WHERE id = $id");
check('aprobar cambia estado', $e === 'aprobada', "estado=$e");
$id2 = (int)$nuevas[1]['id'];
$db->exec("UPDATE propuestas_ia SET estado = 'rechazada', voto = 'test', aprobado_el = CURRENT_TIMESTAMP WHERE id = $id2");
$e2 = (string)$db->querySingle("SELECT estado FROM propuestas_ia WHERE id = $id2");
check('rechazar cambia estado', $e2 === 'rechazada', "estado=$e2");

$db->exec("INSERT OR REPLACE INTO config (clave, valor) VALUES ('ia_conocimiento_producto', 'Test producto')");
check('contextoProducto lee config', contextoProducto($db) === 'Test producto');

$db->close();
unlink($tmp);
echo ($ok ? "\nSMOKE ASISTENTE IA: OK\n" : "\nSMOKE ASISTENTE IA: FALLOS\n");
exit($ok ? 0 : 1);
