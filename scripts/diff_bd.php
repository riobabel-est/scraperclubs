<?php
/**
 * diff_bd.php — Identifica filas concretas que difieren entre local y remota.
 * Solo lectura.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$local  = 'public_html/outbound/data/stats.db';
$list = glob('public_html/outbound/data/stats.db.remoto_*');
$remoto = $list ? end($list) : '';

function abrir(string $p): SQLite3 { $d = new SQLite3($p); $d->enableExceptions(true); return $d; }
function cols(SQLite3 $d, string $t): array { $r = $d->query("PRAGMA table_info($t)"); $c=[]; while($f=$r->fetchArray(SQLITE3_ASSOC)) $c[]=$f['name']; return $c; }

$L = abrir($local); $R = abrir($remoto);

echo "=== APERTURAS: en remoto y no en local ===\n";
$cols = cols($L, 'aperturas');
$rR = $R->query("SELECT * FROM aperturas ORDER BY id");
$idL = [];
$r = $L->query("SELECT id, tracking_id, fecha_apertura FROM aperturas"); while ($f = $r->fetchArray(SQLITE3_ASSOC)) $idL[$f['id']] = $f;
while ($f = $rR->fetchArray(SQLITE3_ASSOC)) {
    if (!isset($idL[$f['id']])) {
        echo "  APERTURA nueva en remoto id={$f['id']}: tracking={$f['tracking_id']} fecha={$f['fecha_apertura']}\n";
    }
}

echo "\n=== ENVIOS: diferencias ===\n";
$colsEnv = array_values(array_intersect(cols($L, 'envios'), cols($R, 'envios')));
$campos = implode(', ', array_filter(['id','tracking_id','email','estado','variant','fecha_envio'], fn($c) => in_array($c, $colsEnv, true)));
$rL = $L->query("SELECT $campos FROM envios ORDER BY id");
$rR = $R->query("SELECT $campos FROM envios ORDER BY id");
$mapL = []; while ($f = $rL->fetchArray(SQLITE3_ASSOC)) $mapL[$f['id']] = $f;
$mapR = []; while ($f = $rR->fetchArray(SQLITE3_ASSOC)) $mapR[$f['id']] = $f;
foreach ($mapR as $id => $f) {
    if (!isset($mapL[$id])) echo "  ENVIO en remoto y no local id=$id: {$f['email']} {$f['estado']} {$f['variant']}\n";
}
foreach ($mapL as $id => $f) {
    if (!isset($mapR[$id])) echo "  ENVIO en local y no remoto id=$id: {$f['email']} {$f['estado']} {$f['variant']}\n";
}

echo "\n=== CLUBES: estado distinto ===\n";
$rL = $L->query("SELECT id, nombre_club, email, estado_lead FROM clubes_crm ORDER BY id");
$rR = $R->query("SELECT id, nombre_club, email, estado_lead FROM clubes_crm ORDER BY id");
$eL = []; while ($f = $rL->fetchArray(SQLITE3_ASSOC)) $eL[$f['id']] = $f;
$eR = []; while ($f = $rR->fetchArray(SQLITE3_ASSOC)) $eR[$f['id']] = $f;
foreach ($eR as $id => $f) {
    if (isset($eL[$id]) && $eL[$id]['estado_lead'] !== $f['estado_lead']) {
        echo "  lead $id {$f['nombre_club']}: local={$eL[$id]['estado_lead']} remoto={$f['estado_lead']}\n";
    }
}
// emails presentes en uno y no en otro
foreach ($eR as $id => $f) { if (!isset($eL[$id])) echo "  lead en remoto y no local id=$id: {$f['nombre_club']} {$f['email']}\n"; }
foreach ($eL as $id => $f) { if (!isset($eR[$id])) echo "  lead en local y no remoto id=$id: {$f['nombre_club']} {$f['email']}\n"; }

echo "\n=== COMUNICACIONES_LOG: diferencias por id ===\n";
$rL = $L->query("SELECT * FROM comunicaciones_log ORDER BY id");
$rR = $R->query("SELECT * FROM comunicaciones_log ORDER BY id");
$cL = []; while ($f = $rL->fetchArray(SQLITE3_ASSOC)) $cL[$f['id']] = $f;
$cR = []; while ($f = $rR->fetchArray(SQLITE3_ASSOC)) $cR[$f['id']] = $f;
foreach ($cR as $id => $f) { if (!isset($cL[$id])) echo "  log en remoto y no local id=$id: {$f['tipo_evento']} lead={$f['lead_id']}\n"; }
foreach ($cL as $id => $f) { if (!isset($cR[$id])) echo "  log en local y no remoto id=$id: {$f['tipo_evento']} lead={$f['lead_id']}\n"; }

echo "\n=== PROPUESTAS_IA (local) ===\n";
$r = $L->query("SELECT id, lead_id, tipo, estado, prioridad, titulo FROM propuestas_ia ORDER BY id");
while ($f = $r->fetchArray(SQLITE3_ASSOC)) echo "  {$f['id']} | lead={$f['lead_id']} | {$f['tipo']} | {$f['estado']}\n";

echo "\nFIN\n";
