<?php
/**
 * optimizar_esquema.php — Saneamiento de esquema (deuda técnica T-4).
 * Idempotente: crea los índices recomendados que falten, ejecuta ANALYZE y
 * comprueba integridad (integrity_check + foreign_key_check).
 *
 * Uso: php cli/optimizar_esquema.php
 * Seguro en BD existentes y en producción (solo crea índices, no modifica datos).
 */
declare(strict_types=1);

$dbPath = __DIR__ . '/../data/stats.db';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "BD no encontrada: {$dbPath}\n");
    exit(1);
}

$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

// Índices recomendados por tabla (CREATE IF NOT EXISTS → seguro repetirlos).
$indices = [
    'respuestas' => [
        'idx_respuestas_lead'        => 'CREATE INDEX IF NOT EXISTS idx_respuestas_lead        ON respuestas(lead_id)',
        'idx_respuestas_cuenta_uid'  => 'CREATE INDEX IF NOT EXISTS idx_respuestas_cuenta_uid  ON respuestas(cuenta_uid)',
        'idx_respuestas_hash'        => 'CREATE INDEX IF NOT EXISTS idx_respuestas_hash        ON respuestas(hash_auxiliar)',
        'idx_respuestas_estado_conv' => 'CREATE INDEX IF NOT EXISTS idx_respuestas_estado_conv ON respuestas(estado_conversacion)',
        'idx_respuestas_fecha'       => 'CREATE INDEX IF NOT EXISTS idx_respuestas_fecha       ON respuestas(fecha_respuesta)',
        'idx_respuestas_carpeta'     => 'CREATE INDEX IF NOT EXISTS idx_respuestas_carpeta     ON respuestas(carpeta)',
    ],
    'rebotes' => [
        'idx_rebotes_envio'    => 'CREATE INDEX IF NOT EXISTS idx_rebotes_envio    ON rebotes(envio_id)',
        'idx_rebotes_lead'     => 'CREATE INDEX IF NOT EXISTS idx_rebotes_lead     ON rebotes(lead_id)',
        'idx_rebotes_campaign' => 'CREATE INDEX IF NOT EXISTS idx_rebotes_campaign ON rebotes(campaign_id)',
    ],
];

$creados = 0;
$yaExistentes = 0;
foreach ($indices as $tabla => $lista) {
    $existentes = [];
    $res = $db->query("PRAGMA index_list({$tabla})");
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $existentes[$r['name']] = true; }
    }
    foreach ($lista as $nombre => $sql) {
        if (isset($existentes[$nombre])) { $yaExistentes++; continue; }
        try {
            $db->exec($sql);
            echo "  + índice creado: {$nombre}\n";
            $creados++;
        } catch (\Throwable $e) {
            echo "  ⚠️ no se pudo crear {$nombre}: {$e->getMessage()}\n";
        }
    }
}

$db->exec('ANALYZE');
$integ = (string)$db->querySingle('PRAGMA integrity_check');
$fkRows = [];
$resFk = $db->query('PRAGMA foreign_key_check');
if ($resFk) { while ($f = $resFk->fetchArray(SQLITE3_ASSOC)) { $fkRows[] = $f; } }

echo "\n=== RESUMEN ===\n";
echo "Índices creados: {$creados} | ya existentes: {$yaExistentes}\n";
echo "integrity_check: {$integ}\n";
echo "foreign_key_check: " . count($fkRows) . " violaciones\n";

$db->close();
echo "OK: saneamiento de esquema completado.\n";
