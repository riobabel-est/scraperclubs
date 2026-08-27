<?php
/**
 * comparar_bd.php — Compara la BD local con la remota descargada (stats.db.remoto_*).
 * Solo lectura. Muestra diferencias por tabla y datos clave.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$local  = 'public_html/outbound/data/stats.db';
$remoto = glob('public_html/outbound/data/stats.db.remoto_*');
if (!$remoto) { echo "No hay BD remota descargada.\n"; exit(1); }
sort($remoto);
$remoto = end($remoto);
echo "Local : $local\nRemoto: $remoto\n\n";

function abrir(string $p): SQLite3 { $d = new SQLite3($p); $d->enableExceptions(true); return $d; }
function tablas(SQLite3 $d): array { $r = $d->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"); $t=[]; while($f=$r->fetchArray(SQLITE3_NUM)) $t[]=$f[0]; return $t; }
function conteo(SQLite3 $d, string $tabla): int { return (int)$d->querySingle("SELECT COUNT(*) FROM \"$tabla\""); }

$L = abrir($local); $R = abrir($remoto);

echo "=== TABLAS ===";
$tl = tablas($L); $tr = tablas($R);
$todas = array_values(array_unique(array_merge($tl, $tr)));
foreach ($todas as $t) {
    $cl = in_array($t, $tl, true) ? conteo($L, $t) : '—';
    $cr = in_array($t, $tr, true) ? conteo($R, $t) : '—';
    $marca = ($cl !== $cr) ? '  <-- DIFERENTE' : '';
    echo "\n  $t | local=$cl | remoto=$cr$marca";
}

echo "\n\n=== clubes_crm: estados ===";
foreach (['L' => $L, 'R' => $R] as $k => $db) {
    echo "\n  [$k] ";
    $r = $db->query("SELECT estado_lead, COUNT(*) n FROM clubes_crm GROUP BY estado_lead ORDER BY n DESC");
    $partes = [];
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) $partes[] = "{$f['estado_lead']}:{$f['n']}";
    echo implode(' | ', $partes);
}

echo "\n\n=== envios: por estado ===";
foreach (['L' => $L, 'R' => $R] as $k => $db) {
    echo "\n  [$k] ";
    $r = $db->query("SELECT estado, COUNT(*) n FROM envios GROUP BY estado ORDER BY n DESC");
    $partes = [];
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) $partes[] = "{$f['estado']}:{$f['n']}";
    echo implode(' | ', $partes);
}

echo "\n\n=== config: claves con valor distinto ===";
$cL = []; $r = $L->query("SELECT clave, valor FROM config"); while ($f = $r->fetchArray(SQLITE3_ASSOC)) $cL[$f['clave']] = $f['valor'];
$cR = []; $r = $R->query("SELECT clave, valor FROM config"); while ($f = $r->fetchArray(SQLITE3_ASSOC)) $cR[$f['clave']] = $f['valor'];
$claves = array_values(array_unique(array_merge(array_keys($cL), array_keys($cR))));
foreach ($claves as $k) {
    $vL = $cL[$k] ?? '(no existe)';
    $vR = $cR[$k] ?? '(no existe)';
    if ($vL !== $vR) {
        $mostrar = fn($v) => (strlen($v) > 60) ? substr($v, 0, 60) . '…' : $v;
        echo "\n  $k:\n    local = " . $mostrar($vL) . "\n    remoto= " . $mostrar($vR);
    }
}

echo "\n\n=== plantillas ===";
foreach (['L' => $L, 'R' => $R] as $k => $db) {
    echo "\n  [$k]\n";
    $r = $db->query("SELECT id, nombre, categoria, activo FROM plantillas ORDER BY id");
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) echo "    {$f['id']} | {$f['nombre']} | [{$f['categoria']}] | act={$f['activo']}\n";
}

echo "\n=== pipelines (campañas) ===";
foreach (['L' => $L, 'R' => $R] as $k => $db) {
    echo "\n  [$k]\n";
    $r = $db->query("SELECT id, nombre, identificador, estado, entorno, activo FROM pipelines ORDER BY id");
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) echo "    {$f['id']} | {$f['nombre']} | {$f['identificador']} | {$f['estado']} | {$f['entorno']} | act={$f['activo']}\n";
}

echo "\n=== tablas nuevas (secuencias / secuencia_pasos / propuestas_ia / respuestas / mockups / presupuestos / contactos_club / telefonos_club) ===";
$check = ['secuencias','secuencia_pasos','propuestas_ia','respuestas','mockups','presupuestos','lead_pipelines','contactos_club','telefonos_club','campaign_segmentos','campaign_plantillas'];
foreach ($check as $t) {
    $cl = in_array($t, $tl, true) ? conteo($L, $t) : '—';
    $cr = in_array($t, $tr, true) ? conteo($R, $t) : '—';
    echo "  $t: local=$cl remoto=$cr\n";
}

echo "\nFIN\n";
