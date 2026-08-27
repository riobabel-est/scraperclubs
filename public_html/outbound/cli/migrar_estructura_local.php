<?php
/**
 * migrar_estructura_local.php — Aplica a la BD (con datos de producción) la
 * ESTRUCTURA local avanzada: tablas propuestas_ia, secuencias, secuencia_pasos,
 * campaign_segmentos, campaign_plantillas + columnas de secuencia en envios.
 * Idempotente. NO toca los datos de las tablas existentes.
 */
declare(strict_types=1);
$DB = __DIR__ . '/../data/stats.db';
$db = new SQLite3($DB);
$db->enableExceptions(true);

$db->exec("CREATE TABLE IF NOT EXISTS propuestas_ia (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    campaign_id INTEGER,
    tipo TEXT NOT NULL,
    titulo TEXT DEFAULT '',
    razon TEXT DEFAULT '',
    mensaje_sugerido TEXT DEFAULT '',
    prioridad TEXT DEFAULT 'Media',
    estado TEXT DEFAULT 'pendiente',
    creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
    aprobado_el DATETIME,
    voto TEXT DEFAULT ''
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_propuestas_estado ON propuestas_ia(estado)");
$cols = []; $r = $db->query("PRAGMA table_info(propuestas_ia)"); while ($f = $r->fetchArray(SQLITE3_ASSOC)) $cols[] = $f['name'];
if (!in_array('fecha_prevista', $cols, true)) {
    $db->exec("ALTER TABLE propuestas_ia ADD COLUMN fecha_prevista DATETIME DEFAULT '2000-01-01 00:00:00'");
    echo "propuestas_ia.fecha_prevista añadida\n";
}

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
$db->exec("CREATE TABLE IF NOT EXISTS campaign_segmentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'federacion',
    valor TEXT NOT NULL DEFAULT '',
    UNIQUE(campaign_id, tipo, valor)
)");
$db->exec("CREATE TABLE IF NOT EXISTS campaign_plantillas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    plantilla_id INTEGER NOT NULL,
    UNIQUE(campaign_id, plantilla_id)
)");

$cols = []; $r = $db->query("PRAGMA table_info(envios)"); while ($f = $r->fetchArray(SQLITE3_ASSOC)) $cols[] = $f['name'];
if (!in_array('secuencia_id', $cols, true)) { $db->exec("ALTER TABLE envios ADD COLUMN secuencia_id INTEGER DEFAULT NULL"); echo "envios.secuencia_id añadida\n"; }
if (!in_array('paso_secuencia', $cols, true)) { $db->exec("ALTER TABLE envios ADD COLUMN paso_secuencia INTEGER DEFAULT NULL"); echo "envios.paso_secuencia añadida\n"; }
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envios_sec_paso ON envios(lead_id, campaign_id, paso_secuencia) WHERE paso_secuencia IS NOT NULL");

// ── Completar columnas que la estructura local espera (idempotente) ──
function asegurarColumnas(SQLite3 $db, string $tabla, array $defs): void
{
    $existentes = [];
    $r = @$db->query("PRAGMA table_info($tabla)");
    if (!$r) { echo "  (tabla $tabla no existe; se omite)\n"; return; }
    while ($f = $r->fetchArray(SQLITE3_ASSOC)) $existentes[] = $f['name'];
    foreach ($defs as $col => $sql) {
        if (!in_array($col, $existentes, true)) {
            $db->exec("ALTER TABLE $tabla ADD COLUMN $sql");
            echo "  $tabla.$col añadida\n";
        }
    }
}

asegurarColumnas($db, 'clubes_crm', [
    'direccion'             => "direccion TEXT DEFAULT ''",
    'cp'                    => "cp TEXT DEFAULT ''",
    'ciudad'                => "ciudad TEXT DEFAULT ''",
    'provincia'             => "provincia TEXT DEFAULT ''",
    'cif'                   => "cif TEXT DEFAULT ''",
    'contacto_facturacion'  => "contacto_facturacion TEXT DEFAULT ''",
    'fecha_proxima_accion'  => "fecha_proxima_accion DATETIME DEFAULT NULL",
]);
asegurarColumnas($db, 'comunicaciones_log', [
    'id_cuenta_smtp' => "id_cuenta_smtp INTEGER DEFAULT NULL",
    'tipo'           => "tipo VARCHAR(20) DEFAULT 'email'",
    'resultado'      => "resultado VARCHAR(20) DEFAULT ''",
    'variante_ab'    => "variante_ab VARCHAR(1) DEFAULT ''",
]);
asegurarColumnas($db, 'respuestas', [
    'notificado'            => "notificado INTEGER DEFAULT 0",
    'kanban_movido'         => "kanban_movido INTEGER DEFAULT 0",
    'cuenta_uid'            => "cuenta_uid TEXT DEFAULT ''",
    'hash_auxiliar'         => "hash_auxiliar TEXT DEFAULT ''",
    'estado_procesamiento'  => "estado_procesamiento TEXT DEFAULT 'nuevo'",
]);
asegurarColumnas($db, 'envios', [
    'message_id'             => "message_id TEXT DEFAULT ''",
    'resultado_envio'        => "resultado_envio TEXT DEFAULT ''",
    'fecha_resultado_envio'  => "fecha_resultado_envio DATETIME",
]);
asegurarColumnas($db, 'plantillas', [
    'asunto_b'       => "asunto_b TEXT DEFAULT ''",
    'asunto_c'       => "asunto_c TEXT DEFAULT ''",
    'cuerpo_b'       => "cuerpo_b TEXT DEFAULT ''",
    'cuerpo_c'       => "cuerpo_c TEXT DEFAULT ''",
    'test_ab'        => "test_ab INTEGER DEFAULT 0",
    'fecha_creacion' => "fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP",
]);

echo "Estructura local aplicada OK\n";
