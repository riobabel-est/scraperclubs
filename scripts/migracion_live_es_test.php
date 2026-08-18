<?php
/**
 * migracion_live_es_test.php
 *
 * MIGRACIÓN CONTROLADA LIVE — Aislamiento TEST/REAL (autorizada por usuario).
 *
 * Añade `envios.es_test` (fuente de verdad única) y la tabla `destinatarios_test`.
 * Backfill EXACTO por ID (NO heurísticas):
 *   TEST = 3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22  → es_test = 1
 *   REAL = 1,2,23,24,25,26,27,29,30,31,32,33                      → es_test = 0
 *
 * NO añade `clubes_crm.es_test` (fuente de verdad = envios.es_test).
 * NO borra registros. NO modifica otros campos. Idempotente.
 *
 * SEGURIDAD:
 *   - Sin --apply: solo auditoría (no escribe).
 *   - Con --apply: aplica migración + backfill en la BD indicada.
 *
 * USO:
 *   php scripts/migracion_live_es_test.php [--apply] [--db=/ruta/stats.db]
 */

declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dbArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--db=')) {
        $dbArg = substr($a, 5);
    }
}

$DB_PATH = $dbArg ?? (__DIR__ . '/../public_html/outbound/data/stats.db');

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada en {$DB_PATH}\n");
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA busy_timeout=5000');

echo "=== MIGRACIÓN LIVE AISLAMIENTO TEST/REAL ===\n";
echo "BD: {$DB_PATH}\n";
echo "Modo: " . ($apply ? "APLICAR" : "AUDITORÍA (solo lectura)") . "\n\n";

// ─── 1. Estado actual ────────────────────────────────────────────────────────
$total = (int)$db->querySingle('SELECT COUNT(*) FROM envios');
$colExiste = (int)$db->querySingle(
    "SELECT COUNT(*) FROM pragma_table_info('envios') WHERE name = 'es_test'"
);
$tablaDest = (int)$db->querySingle(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='destinatarios_test'"
);

echo "envios totales: {$total}\n";
echo "envios.es_test existe: " . ($colExiste ? 'SI' : 'NO') . "\n";
echo "destinatarios_test existe: " . ($tablaDest ? 'SI' : 'NO') . "\n\n";

// ─── 2. Backfill exacto por ID ───────────────────────────────────────────────
$idsTest = [3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22];
$idsReal = [1,2,23,24,25,26,27,29,30,31,32,33];

// Validar que todos los IDs existen y no hay solapamiento
$idsTestSet = array_flip($idsTest);
$idsRealSet = array_flip($idsReal);
$overlap = array_intersect($idsTest, $idsReal);
if (!empty($overlap)) {
    fwrite(STDERR, "ERROR: solapamiento de IDs TEST/REAL: " . implode(',', $overlap) . "\n");
    exit(2);
}

$existentes = [];
$res = $db->query('SELECT id FROM envios');
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $existentes[(int)$r['id']] = true;
}
$faltantes = [];
foreach (array_merge($idsTest, $idsReal) as $id) {
    if (!isset($existentes[$id])) {
        $faltantes[] = $id;
    }
}
if (!empty($faltantes)) {
    fwrite(STDERR, "ERROR: IDs no existen en envios: " . implode(',', $faltantes) . "\n");
    exit(2);
}

echo "── BACKFILL EXACTO ──────────────────────────────────────────────\n";
echo "TEST (es_test=1): " . count($idsTest) . " envíos\n";
echo "REAL (es_test=0): " . count($idsReal) . " envíos\n";
echo "Total backfill: " . (count($idsTest) + count($idsReal)) . "\n\n";

if (!$apply) {
    echo "Modo auditoría: no se ha escrito nada.\n";
    echo "Ejecuta con --apply para aplicar la migración.\n";
    $db->close();
    exit(0);
}

// ─── 3. Aplicar migración ────────────────────────────────────────────────────
$db->exec('BEGIN TRANSACTION');
try {
    if (!$colExiste) {
        $db->exec("ALTER TABLE envios ADD COLUMN es_test INTEGER NOT NULL DEFAULT 0");
        echo "✓ Columna envios.es_test creada (default 0).\n";
    } else {
        echo "✓ Columna envios.es_test ya existía.\n";
    }

    // Backfill TEST
    $stmtT = $db->prepare("UPDATE envios SET es_test = 1 WHERE id = :id");
    foreach ($idsTest as $id) {
        $stmtT->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmtT->execute();
    }
    echo "✓ " . count($idsTest) . " envíos TEST marcados es_test=1.\n";

    // Backfill REAL
    $stmtR = $db->prepare("UPDATE envios SET es_test = 0 WHERE id = :id");
    foreach ($idsReal as $id) {
        $stmtR->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmtR->execute();
    }
    echo "✓ " . count($idsReal) . " envíos REALES confirmados es_test=0.\n";

    // Tabla destinatarios_test (BLOQUE 8) — solo si el código la requiere y no existe
    if (!$tablaDest) {
        $db->exec(
            "CREATE TABLE destinatarios_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                nombre TEXT DEFAULT '',
                activo INTEGER NOT NULL DEFAULT 1,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );
        echo "✓ Tabla destinatarios_test creada.\n";
    } else {
        echo "✓ Tabla destinatarios_test ya existía.\n";
    }

    $db->exec('COMMIT');
} catch (\Exception $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    $db->close();
    exit(1);
}

// ─── 4. Verificación ────────────────────────────────────────────────────────
$nTest = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE es_test = 1");
$nReal = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE es_test = 0");
$nNull = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE es_test IS NULL");
echo "\n── VERIFICACIÓN ────────────────────────────────────────────────\n";
echo "TOTAL = " . ($nTest + $nReal + $nNull) . "\n";
echo "TEST  = {$nTest}\n";
echo "REAL  = {$nReal}\n";
echo "NULL  = {$nNull}\n";
echo "integrity_check: " . $db->querySingle('PRAGMA integrity_check') . "\n";

// Verificación explícita de los 12 REAL
$badReal = [];
foreach ($idsReal as $id) {
    $v = (int)$db->querySingle("SELECT es_test FROM envios WHERE id = {$id}");
    if ($v !== 0) { $badReal[] = $id; }
}
// Verificación explícita de los 20 TEST
$badTest = [];
foreach ($idsTest as $id) {
    $v = (int)$db->querySingle("SELECT es_test FROM envios WHERE id = {$id}");
    if ($v !== 1) { $badTest[] = $id; }
}
echo "REAL con es_test!=0: " . (empty($badReal) ? 'NINGUNO ✓' : implode(',', $badReal)) . "\n";
echo "TEST con es_test!=1: " . (empty($badTest) ? 'NINGUNO ✓' : implode(',', $badTest)) . "\n";

$db->close();
echo "\nMigración completada.\n";
