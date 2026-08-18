<?php
/**
 * fase_test_aislamiento_migracion.php
 *
 * MIGRACIÓN CONTROLADA — Aislamiento TEST/REAL (BLOQUE 3 + BLOQUE 10 + BLOQUE 11).
 *
 * Añade la columna `envios.es_test` (fuente de verdad inequívoca para distinguir
 * envíos TEST de envíos REALES) y la rellena de forma NO destructiva.
 *
 * ESTRATEGIA (recomendación BLOQUE 11):
 *   - NO se borra físicamente ningún registro.
 *   - Los envíos TEST se marcan `es_test = 1` y quedan EXCLUIDOS de todas las
 *     estadísticas comerciales (Histórico Comercial, Analytics, Follow-ups).
 *   - Los envíos REALES quedan `es_test = 0` intactos.
 *
 * REGLA CENTRAL de clasificación (espejo exacto de esLeadTest() en eligibilidad.php):
 *   es_test = 1  ⇔  email LIKE '%@futprotec.local%'  OR  club LIKE 'test%'
 *
 * SEGURIDAD:
 *   - Idempotente: si la columna ya existe, no la recrea.
 *   - No destructivo: solo INSERT/UPDATE de marcado, nunca DELETE.
 *   - Requiere confirmación explícita (--apply) para escribir en la BD real.
 *     Sin --apply solo muestra la clasificación (modo auditoría).
 *
 * USO:
 *   php scripts/fase_test_aislamiento_migracion.php            # auditoría (no escribe)
 *   php scripts/fase_test_aislamiento_migracion.php --apply    # aplica migración + backfill
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$DB_PATH = __DIR__ . '/../public_html/outbound/data/stats.db';
$apply   = in_array('--apply', $argv, true);

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada en {$DB_PATH}\n");
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

echo "=== AUDITORÍA AISLAMIENTO TEST/REAL ===\n";
echo "BD: {$DB_PATH}\n";
echo "Modo: " . ($apply ? "APLICAR (escribe en BD)" : "AUDITORÍA (solo lectura)") . "\n\n";

// ─── 1. Clasificación exhaustiva (BLOQUE 10) ────────────────────────────────
$rows = [];
$res = $db->query(
    "SELECT id, lead_id, club, email, campaign_id, plantilla_id, fecha_envio, estado, tracking_id
     FROM envios ORDER BY id"
);
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $r;
}

$test = [];
$real = [];
$ambiguos = [];
foreach ($rows as $r) {
    $emailLower  = strtolower((string)$r['email']);
    $clubLower   = strtolower((string)$r['club']);
    $esTest = (str_contains($emailLower, '@futprotec.local') || str_starts_with($clubLower, 'test'));

    // Lista de emails REALES protegidos (BLOQUE 10): nunca se marcan TEST.
    $emailsRealesProtegidos = [
        'clubatleticobahia@gmail.com', 'clubsanbernabe@gmail.com', 'cdcondeorgaz@hotmail.es',
        'cdfabero1953@gmail.com', 'atleticoserrada@hotmail.com', 'acrefaguilas07@gmail.com',
        'isanchez10790@gmail.com', 'vnavamari@hotmail.com', 'entretorresf7@hotmail.com',
        'clubadpparador@gmail.com', 'info@fsnazareno.es', 'hola@riobabel.com',
    ];
    $esRealProtegido = in_array(strtolower((string)$r['email']), $emailsRealesProtegidos, true);

    if ($esRealProtegido) {
        $real[] = $r;
    } elseif ($esTest) {
        $test[] = $r;
    } else {
        // Sin marca TEST y sin email protegido: ambiguo (requiere revisión manual).
        $ambiguos[] = $r;
    }
}

echo "── CLASIFICACIÓN ──────────────────────────────────────────────\n";
echo "TEST inequívocos = " . count($test) . "\n";
echo "REAL inequívocos = " . count($real) . "\n";
echo "AMBIGUOS         = " . count($ambiguos) . "\n\n";

echo "── DETALLE TEST ───────────────────────────────────────────────\n";
foreach ($test as $r) {
    printf(
        "  id=%d lead=%s club=%s email=%s camp=%s tpl=%s fecha=%s estado=%s\n",
        $r['id'], $r['lead_id'], $r['club'], $r['email'],
        var_export($r['campaign_id'], true), var_export($r['plantilla_id'], true),
        $r['fecha_envio'], $r['estado']
    );
}

echo "\n── DETALLE REAL ───────────────────────────────────────────────\n";
foreach ($real as $r) {
    printf(
        "  id=%d lead=%s club=%s email=%s camp=%s tpl=%s fecha=%s estado=%s\n",
        $r['id'], $r['lead_id'], $r['club'], $r['email'],
        var_export($r['campaign_id'], true), var_export($r['plantilla_id'], true),
        $r['fecha_envio'], $r['estado']
    );
}

if (!empty($ambiguos)) {
    echo "\n── DETALLE AMBIGUOS ───────────────────────────────────────────\n";
    foreach ($ambiguos as $r) {
        printf(
            "  id=%d lead=%s club=%s email=%s camp=%s tpl=%s fecha=%s estado=%s\n",
            $r['id'], $r['lead_id'], $r['club'], $r['email'],
            var_export($r['campaign_id'], true), var_export($r['plantilla_id'], true),
            $r['fecha_envio'], $r['estado']
        );
    }
    echo "\n⚠️  HAY REGISTROS AMBIGUOS. NO se aplicará ninguna migración.\n";
    echo "   Revisar manualmente antes de continuar (BLOQUE 10: no eliminar con AMBIGUOS > 0).\n";
    $db->close();
    exit(2);
}

// ─── 2. Migración de esquema (BLOQUE 3) ─────────────────────────────────────
$colExiste = (int)$db->querySingle(
    "SELECT COUNT(*) FROM pragma_table_info('envios') WHERE name = 'es_test'"
);

if (!$apply) {
    echo "\n── MIGRACIÓN (simulada) ───────────────────────────────────────\n";
    echo "Columna envios.es_test: " . ($colExiste ? "YA EXISTE" : "NO EXISTE (se creará)") . "\n";
    echo "Backfill: " . count($test) . " envíos se marcarán es_test=1\n";
    echo "Backfill: " . count($real) . " envíos quedarán es_test=0\n";
    echo "\nModo auditoría: no se ha escrito nada. Ejecuta con --apply para aplicar.\n";
    $db->close();
    exit(0);
}

// ─── 3. Aplicar migración ───────────────────────────────────────────────────
$db->exec('BEGIN TRANSACTION');
try {
    if (!$colExiste) {
        $db->exec("ALTER TABLE envios ADD COLUMN es_test INTEGER NOT NULL DEFAULT 0");
        echo "\n✓ Columna envios.es_test creada (default 0).\n";
    } else {
        echo "\n✓ Columna envios.es_test ya existía.\n";
    }

    // Backfill: marcar TEST
    $stmt = $db->prepare("UPDATE envios SET es_test = 1 WHERE id = :id");
    foreach ($test as $r) {
        $stmt->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
        $stmt->execute();
    }
    echo "✓ " . count($test) . " envíos TEST marcados es_test=1.\n";

    // Backfill: garantizar REAL = 0 (idempotente)
    $stmt2 = $db->prepare("UPDATE envios SET es_test = 0 WHERE id = :id");
    foreach ($real as $r) {
        $stmt2->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
        $stmt2->execute();
    }
    echo "✓ " . count($real) . " envíos REALES confirmados es_test=0.\n";

    // Tabla destinatarios_test (BLOQUE 8): destinatarios de prueba aislados.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS destinatarios_test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            nombre TEXT DEFAULT '',
            activo INTEGER NOT NULL DEFAULT 1,
            creado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )"
    );
    echo "✓ Tabla destinatarios_test asegurada.\n";

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
echo "\n── VERIFICACIÓN ────────────────────────────────────────────────\n";
echo "envios es_test=1 (TEST): {$nTest}\n";
echo "envios es_test=0 (REAL): {$nReal}\n";
echo "Total: " . ($nTest + $nReal) . "\n";
echo "integrity_check: " . $db->querySingle('PRAGMA integrity_check') . "\n";

$db->close();
echo "\nMigración completada. Los envíos TEST quedan excluidos de las estadísticas comerciales.\n";
