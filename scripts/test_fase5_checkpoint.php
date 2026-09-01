<?php
/**
 * test_fase5_checkpoint.php — TESTS de FASE 5 del MEGAPROMPT V2 (CRM FutProtec).
 *
 * Comprueba:
 *   TEST 01 — DB integrity
 *   TEST 13 — batch checkpoint: el script ejecuta las 10 comprobaciones, devuelve
 *             una decisión inequívoca (READY TO SEND / BLOCKED) y la tabla batches
 *             existe. Un error crítico (campaña inválida) → BLOCKED.
 *
 * Uso local:  php scripts/test_fase5_checkpoint.php
 * No envía emails. Solo ejecuta auditorías de lectura (sin --crear-batch).
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
$SCRIPT = __DIR__ . '/../public_html/outbound/cli/auditoria_pre_lote.php';

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS | {$nombre}\n";
    } else {
        $fail++;
        echo "FAIL | {$nombre} | {$detalle}\n";
    }
}

// ─── TEST 01 — DB integrity + tabla batches ────────────────────────────────
$db = new SQLite3($DB);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
check('TEST 01 DB integrity', ($db->querySingle('PRAGMA integrity_check') ?? '') === 'ok');
check('TEST 13 tabla batches existe',
    (int)$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='batches'") === 1);
$batchesAntes = (int)$db->querySingle('SELECT COUNT(*) FROM batches');
$db->close();

// ─── TEST 13 — auditoría real de campaña 2 (lote de 50, solo lectura) ──────
$cmd = 'php "' . $SCRIPT . '" --campaign=2 --batch=2026-08-29-TEST --limite=50 --json';
$salida = shell_exec($cmd . ' 2>&1');
$j = json_decode((string)$salida, true);
check('TEST 13 script responde JSON', is_array($j), substr((string)$salida, 0, 200));
check('TEST 13 decisión inequívoca', is_array($j) && in_array($j['decision'] ?? '', ['READY TO SEND', 'BLOCKED'], true), $j['decision'] ?? '?');
check('TEST 13 ejecuta 10 comprobaciones', is_array($j) && count($j['checks'] ?? []) === 10, 'checks=' . count($j['checks'] ?? []));
$tests = array_map(fn($c) => $c['test'], $j['checks'] ?? []);
$esperados = ['TEST/REAL', 'DUPLICATE', 'BOUNCE', 'BLACKLIST', 'EMAIL VALIDITY', 'CAMPAIGN', 'VARIANT', 'TEMPLATE', 'SMTP', 'TRACKING'];
sort($tests);
sort($esperados);
check('TEST 13 contiene las 10 comprobaciones esperadas', $tests === $esperados, implode(',', $tests));
check('TEST 13 sin ERROR en lote sano',
    is_array($j) && count(array_filter($j['checks'] ?? [], fn($c) => $c['estado'] === 'ERROR')) === 0,
    json_encode($j['checks'] ?? []));

// ─── TEST 13 — campaña inválida → BLOCKED ──────────────────────────────────
$cmdBad = 'php "' . $SCRIPT . '" --campaign=999 --batch=2026-08-29-TEST --limite=10 --json';
$salidaBad = shell_exec($cmdBad . ' 2>&1');
$jb = json_decode((string)$salidaBad, true);
check('TEST 13 campaña inválida → BLOCKED', is_array($jb) && ($jb['decision'] ?? '') === 'BLOCKED', $jb['decision'] ?? '?');

// La auditoría (sin --crear-batch) no debe crear lotes nuevos.
$db2 = new SQLite3($DB);
$batchesDespues = (int)$db2->querySingle('SELECT COUNT(*) FROM batches');
$db2->close();
check('TEST 13 auditoría sin --crear-batch no crea lotes', $batchesDespues === $batchesAntes, "antes={$batchesAntes} despues={$batchesDespues}");

echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
