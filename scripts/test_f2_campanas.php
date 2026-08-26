<?php
/**
 * Test funcional del Configurador de Campañas (P-1 Fases 2-3, backend).
 * Opera sobre una COPIA de stats.db (no toca la BD real). Usa subprocesos.
 */
declare(strict_types=1);

$src = __DIR__ . '/../public_html/outbound/data/stats.db';
$tmp = __DIR__ . '/../tmp_f2_test.db';
if (file_exists($tmp)) { unlink($tmp); }
copy($src, $tmp);

$ok = true;
function check(string $n, bool $c, string $d = ''): void {
    global $ok;
    if ($c) { echo "  ✅ $n\n"; }
    else { $ok = false; echo "  ❌ $n" . ($d !== '' ? " — $d" : '') . "\n"; }
}

function runCampanas(string $dbPath, string $action, array $get = [], array $post = []): array {
    $get['action'] = $action;
    $cmd = [PHP_BINARY, __DIR__ . '/runner_campanas.php', $dbPath, json_encode($get), json_encode($post)];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
    if (!is_resource($proc)) { return []; }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($err !== '' && $err !== null) { fwrite(STDERR, "[stderr] " . trim($err) . "\n"); }
    return json_decode((string)$out, true) ?: [];
}

// 1) get_federaciones devuelve lista (puede estar vacía en local; solo valida estructura).
$r = runCampanas($tmp, 'get_federaciones');
check('get_federaciones devuelve array', $r['ok'] && isset($r['federaciones']) && is_array($r['federaciones']));

// 2) save_campaign nueva (segmento Madrid + 1 plantilla).
$db = new SQLite3($tmp);
$pid1 = (int)$db->querySingle("SELECT id FROM plantillas ORDER BY id LIMIT 1");
$db->close();
$r = runCampanas($tmp, 'save_campaign', [], [
    'id' => 0, 'nombre' => 'Campaña Test F2', 'identificador' => 'TEST_F2',
    'entorno' => 'test', 'estado' => 'PILOT', 'activo' => 1,
    'todas_federaciones' => '0', 'federaciones' => json_encode(['Madrid']),
    'estado_lead' => '01 Sin Contactar', 'plantillas' => json_encode([$pid1]),
]);
$newId = (int)($r['id'] ?? 0);
check('save_campaign crea campaña', $r['ok'] && $newId > 0, json_encode($r));

// 3) get_campanas devuelve la campaña con su segmento y plantillas.
$r = runCampanas($tmp, 'get_campanas');
$c = null;
foreach ($r['campanas'] ?? [] as $cc) { if ((int)$cc['id'] === $newId) { $c = $cc; break; } }
check('get_campanas incluye la nueva', is_array($c));
check('segmento federaciones=["Madrid"]', is_array($c['segmento']['federaciones'] ?? null) && in_array('Madrid', $c['segmento']['federaciones'], true), json_encode($c['segmento'] ?? null));
check('segmento todas=false', ($c['segmento']['todas'] ?? true) === false);
check('segmento estado="01 Sin Contactar"', ($c['segmento']['estado'] ?? '') === '01 Sin Contactar');
check('plantillas_id=[$pid1]', in_array($pid1, $c['plantillas_id'] ?? [], true), json_encode($c['plantillas_id'] ?? null));

// 4) Editar la misma campaña → todas_federaciones=1 (segmento todas).
$r = runCampanas($tmp, 'save_campaign', [], [
    'id' => $newId, 'nombre' => 'Campaña Test F2', 'identificador' => 'TEST_F2',
    'entorno' => 'test', 'estado' => 'PILOT', 'activo' => 1,
    'todas_federaciones' => '1', 'federaciones' => json_encode([]),
    'estado_lead' => '', 'plantillas' => json_encode([]),
]);
check('save_campaign edita (ok)', $r['ok'] && (int)$r['id'] === $newId);
$r = runCampanas($tmp, 'get_campanas');
$c = null;
foreach ($r['campanas'] ?? [] as $cc) { if ((int)$cc['id'] === $newId) { $c = $cc; break; } }
check('edición: segmento todas=true', ($c['segmento']['todas'] ?? false) === true, json_encode($c['segmento'] ?? null));
check('edición: plantillas vacías', count($c['plantillas_id'] ?? []) === 0);

// 5) delete_campaign.
$r = runCampanas($tmp, 'delete_campaign', [], ['id' => $newId]);
check('delete_campaign ok', $r['ok']);
$r = runCampanas($tmp, 'get_campanas');
$found = false;
foreach ($r['campanas'] ?? [] as $cc) { if ((int)$cc['id'] === $newId) { $found = true; break; } }
check('delete_campaign elimina de get_campanas', !$found);

unlink($tmp);
echo "\n" . ($ok ? 'VEREDICTO: TEST_F2_CAMPANAS_PASS' : 'VEREDICTO: TEST_F2_CAMPANAS_FAIL') . "\n";
exit($ok ? 0 : 1);
