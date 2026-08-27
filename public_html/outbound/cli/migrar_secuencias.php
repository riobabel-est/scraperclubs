<?php
/**
 * migrar_secuencias.php — Aplica SOLO el DDL de secuencias (O-1) a una BD existente,
 * sin ejecutar el resto de init_db. Idempotente.
 */
declare(strict_types=1);
$DB = __DIR__ . '/../data/stats.db';
$db = new SQLite3($DB);
$db->enableExceptions(true);

$db->exec("CREATE TABLE IF NOT EXISTS secuencias (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    nombre      TEXT NOT NULL,
    modo_auto   INTEGER NOT NULL DEFAULT 0,
    activo      INTEGER NOT NULL DEFAULT 1,
    creado_el   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(campaign_id, nombre)
)");
$db->exec("CREATE TABLE IF NOT EXISTS secuencia_pasos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    secuencia_id INTEGER NOT NULL,
    paso        INTEGER NOT NULL,
    plantilla_id INTEGER NOT NULL,
    espera_dias INTEGER NOT NULL DEFAULT 2,
    ramal       VARCHAR(1) NOT NULL DEFAULT '',
    activo      INTEGER NOT NULL DEFAULT 1,
    UNIQUE(secuencia_id, paso)
)");

$cols = [];
$res = $db->query("PRAGMA table_info(envios)");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];
if (!in_array('secuencia_id', $cols, true)) {
    $db->exec("ALTER TABLE envios ADD COLUMN secuencia_id INTEGER DEFAULT NULL");
    echo "Columna secuencia_id añadida\n";
}
if (!in_array('paso_secuencia', $cols, true)) {
    $db->exec("ALTER TABLE envios ADD COLUMN paso_secuencia INTEGER DEFAULT NULL");
    echo "Columna paso_secuencia añadida\n";
}
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envios_sec_paso ON envios(lead_id, campaign_id, paso_secuencia) WHERE paso_secuencia IS NOT NULL");

echo "Tablas secuencias/secuencia_pasos + índice OK\n";
