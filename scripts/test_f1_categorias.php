<?php
/**
 * Test funcional de la Fase 1 (categorías de plantillas editables/opcionales).
 * Opera sobre una COPIA de stats.db (no toca la BD real). Usa subprocesos.
 */
declare(strict_types=1);

$src = __DIR__ . '/../public_html/outbound/data/stats.db';
$tmp = __DIR__ . '/../tmp_f1_test.db';
if (file_exists($tmp)) { unlink($tmp); }
copy($src, $tmp);

$db = new SQLite3($tmp);
$db->enableExceptions(true);

$ok = true;
function check(string $n, bool $c, string $d = ''): void {
    global $ok;
    if ($c) { echo "  ✅ $n\n"; }
    else { $ok = false; echo "  ❌ $n" . ($d !== '' ? " — $d" : '') . "\n"; }
}

// Ejecuta plantillas.php en subproceso (su exit no mata el test).
// proc_open con array evita el escaping del shell en Windows.
function runPlantillas(string $dbPath, string $action, array $get = [], array $post = []): array {
    $get['action'] = $action;
    $cmd = [PHP_BINARY, __DIR__ . '/runner_plantillas.php', $dbPath, json_encode($get), json_encode($post)];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
    if (!is_resource($proc)) { return []; }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($err !== '' && $err !== null) { fwrite(STDERR, "[runner stderr] " . trim($err) . "\n"); }
    return json_decode((string)$out, true) ?: [];
}

// Preparar datos: 2 plantillas con categoría + 1 genérica.
$db->exec("DELETE FROM plantillas");
$db->exec("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, activo) VALUES ('Tpl A', '01 Sin Contactar', 'A', 'A', 'texto_plano', 1)");
$db->exec("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, activo) VALUES ('Tpl B', '02 Contactado', 'B', 'B', 'texto_plano', 1)");
$db->exec("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, activo) VALUES ('Tpl Gen', '', 'G', 'G', 'texto_plano', 1)");
$db->close();

// 1) get_categorias excluye la vacía.
$r = runPlantillas($tmp, 'get_categorias');
check('get_categorias devuelve 2 (sin vacía)', $r['ok'] && !in_array('', $r['categorias'] ?? [], true) && count($r['categorias'] ?? []) === 2, json_encode($r['categorias'] ?? null));

// 2) get_templates por categoría (solo esa).
$r = runPlantillas($tmp, 'get_templates', ['categoria' => '01 Sin Contactar']);
check('get_templates(categoria) solo Tpl A', $r['ok'] && count($r['templates'] ?? []) === 1 && ($r['templates'][0]['nombre'] ?? '') === 'Tpl A');

// 3) get_templates con incluir_genericas=1 → categoría + genéricas.
$r = runPlantillas($tmp, 'get_templates', ['categoria' => '01 Sin Contactar', 'incluir_genericas' => '1']);
$nombres = array_column($r['templates'] ?? [], 'nombre');
check('get_templates(+genéricas) incluye Tpl A y Tpl Gen', in_array('Tpl A', $nombres, true) && in_array('Tpl Gen', $nombres, true), json_encode($nombres));
check('get_templates(+genéricas) NO incluye Tpl B', !in_array('Tpl B', $nombres, true));

// 4) save_template con categoría vacía → sin categoría.
$db = new SQLite3($tmp);
$r = runPlantillas($tmp, 'save_template', [], ['nombre' => 'Tpl Sin Cat', 'asunto' => 'X', 'cuerpo' => 'X', 'categoria' => '', 'tipo' => 'texto_plano', 'test_ab' => '0']);
$idNew = (int)($r['id'] ?? 0);
$cat = $db->querySingle("SELECT categoria FROM plantillas WHERE id = " . $idNew);
check('save_template categoría vacía -> sin categoría', $r['ok'] && $cat === '', 'categoria=' . var_export($cat, true));

// 5) rename_categoria actualiza todas las de esa categoría.
$r = runPlantillas($tmp, 'rename_categoria', [], ['old_categoria' => '01 Sin Contactar', 'new_categoria' => '01 Renombrada']);
$catA = $db->querySingle("SELECT categoria FROM plantillas WHERE nombre = 'Tpl A'");
check('rename_categoria -> Tpl A a "01 Renombrada"', $r['ok'] && $catA === '01 Renombrada', 'cat=' . var_export($catA, true));

// 6) delete_categoria reasigna a sin categoría (no borra plantillas).
$r = runPlantillas($tmp, 'delete_categoria', [], ['categoria' => '02 Contactado']);
$catB = $db->querySingle("SELECT categoria FROM plantillas WHERE nombre = 'Tpl B'");
$nTpl = (int)$db->querySingle("SELECT COUNT(*) FROM plantillas");
check('delete_categoria -> Tpl B a sin categoría', $r['ok'] && $catB === '', 'cat=' . var_export($catB, true));
check('delete_categoria no borra plantillas (4)', $nTpl === 4, 'n=' . $nTpl);

// 7) get_templates "Todas" (sin categoría) devuelve todas.
$r = runPlantillas($tmp, 'get_templates');
check('get_templates(Todas) devuelve las 4', $r['ok'] && count($r['templates'] ?? []) === 4, 'n=' . count($r['templates'] ?? []));
$db->close();
unlink($tmp);

echo "\n" . ($ok ? 'VEREDICTO: TEST_F1_CATEGORIAS_PASS' : 'VEREDICTO: TEST_F1_CATEGORIAS_FAIL') . "\n";
exit($ok ? 0 : 1);
