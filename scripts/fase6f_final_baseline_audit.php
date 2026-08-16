<?php
/**
 * FASE 6F.FINAL — Baseline definitivo de plataforma (SOLO LECTURA).
 *
 * Abre stats.db en modo READONLY. No ejecuta SMTP, no hace POST, no toca
 * cron, no escribe en la BD. Toda la salida es consulta.
 */

declare(strict_types=1);

$dbPath = __DIR__ . '/../public_html/outbound/data/stats.db';

try {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
} catch (\Exception $e) {
    echo "ERROR abriendo BD: " . $e->getMessage() . "\n";
    exit(1);
}

function q(SQLite3 $db, string $sql): array {
    $rows = [];
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
    }
    return $rows;
}

function q1(SQLite3 $db, string $sql) {
    return $db->querySingle($sql);
}

echo "================ 1. CONFIG GLOBAL ================\n";
echo "  config.modo_entorno    = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='modo_entorno'"), true) . "\n";
echo "  config.motor_estado    = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='motor_estado'"), true) . "\n";
echo "  config.email_test      = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='email_test'"), true) . "\n";
echo "  config.test_emails     = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='test_emails'"), true) . "\n";
echo "  config.delay_envio     = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='delay_envio'"), true) . "\n";
echo "  config.lanzadera_delay = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='lanzadera_delay'"), true) . "\n";
echo "  config.lote_envio      = " . var_export(q1($db, "SELECT valor FROM config WHERE clave='lote_envio'"), true) . "\n";

echo "\n================ 2. CAMPAÑAS (pipelines) ================\n";
$colsPipe = q($db, "PRAGMA table_info(pipelines)");
echo "  columnas pipelines: " . implode(', ', array_column($colsPipe, 'name')) . "\n";
$pipes = q($db, "SELECT * FROM pipelines ORDER BY id ASC");
foreach ($pipes as $p) {
    echo "  ---\n";
    foreach (['id','nombre','identificador','estado','entorno','activo','plantilla_id'] as $k) {
        if (array_key_exists($k, $p)) {
            echo "  $k = " . var_export($p[$k], true) . "\n";
        }
    }
}

echo "\n================ 3. ENVIOS ================\n";
echo "  SELECT MAX(id), COUNT(*) FROM envios => ";
echo var_export(q1($db, "SELECT MAX(id) FROM envios"), true) . " / " . var_export(q1($db, "SELECT COUNT(*) FROM envios"), true) . "\n";

$colsEnv = q($db, "PRAGMA table_info(envios)");
echo "  columnas envios: " . implode(', ', array_column($colsEnv, 'name')) . "\n";

$extra = [];
if (in_array('resultado_envio', array_column($colsEnv, 'name'), true)) {
    $extra[] = 'resultado_envio';
}
foreach ([6,7,8] as $eid) {
    $row = q($db, "SELECT * FROM envios WHERE id={$eid}");
    if (!$row) { echo "  envio {$eid}: NO EXISTE\n"; continue; }
    $row = $row[0];
    echo "  --- envio_id={$eid} ---\n";
    foreach ([
        'lead_id','campaign_id','variant','plantilla_id','smtp_id',
        'estado','resultado_envio','club','email','cuenta_emision',
        'asunto','tracking_id','message_id'
    ] as $k) {
        if (array_key_exists($k, $row)) {
            $v = $row[$k];
            if (in_array($k, ['cuerpo_mensaje'], true)) { $v = (strlen((string)$v)) . " bytes"; }
            echo "  $k = " . var_export($v, true) . "\n";
        }
    }
}

// Integridad: no hay envíos > 8
$maxId = (int)q1($db, "SELECT MAX(id) FROM envios");
echo "\n================ 11. INTEGRIDAD ================\n";
echo "  MAX(envios.id) = {$maxId}\n";
echo "  envios con id > 8 = " . var_export(q1($db, "SELECT COUNT(*) FROM envios WHERE id > 8"), true) . "\n";
echo "  MAX(clubes_crm.id) = " . var_export(q1($db, "SELECT MAX(id) FROM clubes_crm"), true) . "\n";
echo "  leads con id > 1817 = " . var_export(q1($db, "SELECT COUNT(*) FROM clubes_crm WHERE id > 1817"), true) . "\n";

echo "\n================ 6. DUMMIES TEST (1814-1817) ================\n";
$dummies = q($db, "SELECT id, nombre_club, email, estado_lead, federacion FROM clubes_crm WHERE id IN (1814,1815,1816,1817) ORDER BY id");
foreach ($dummies as $d) {
    echo "  id={$d['id']} | nombre={$d['nombre_club']} | email={$d['email']} | estado={$d['estado_lead']} | fed={$d['federacion']}\n";
}

echo "\n================ 8. PLANTILLA 2 ================\n";
$t2 = q($db, "SELECT id, nombre, activo, test_ab, asunto, asunto_b, asunto_c, LENGTH(cuerpo) AS len_cuerpo, LENGTH(cuerpo_b) AS len_cuerpo_b, LENGTH(cuerpo_c) AS len_cuerpo_c FROM plantillas WHERE id=2");
foreach ($t2 as $t) {
    echo "  id={$t['id']} | activo={$t['activo']} | test_ab={$t['test_ab']} | cuerpo_len={$t['len_cuerpo']} | cuerpo_b_len={$t['len_cuerpo_b']} | cuerpo_c_len={$t['len_cuerpo_c']}\n";
    echo "  nombre = {$t['nombre']}\n";
}

echo "\n================ 9. SMTP (cuentas activas) ================\n";
$active = q($db, "SELECT id, email, activa, limite_diario, enviados_hoy, ultimo_uso FROM cuentas_smtp WHERE activa=1 ORDER BY id");
echo "  cuentas activas = " . count($active) . "\n";
foreach ($active as $a) {
    echo "  id={$a['id']} | email={$a['email']} | limite_diario={$a['limite_diario']} | enviados_hoy={$a['enviados_hoy']} | ultimo_uso={$a['ultimo_uso']}\n";
}
echo "\n  Cuenta usada en últimos envíos (por smtp_id):\n";
$lastSmtp = q($db, "SELECT smtp_id, COUNT(*) AS n, MAX(id) AS max_id FROM envios WHERE smtp_id IS NOT NULL GROUP BY smtp_id ORDER BY max_id DESC");
foreach ($lastSmtp as $ls) {
    echo "  smtp_id={$ls['smtp_id']} | envios={$ls['n']} | ultimo_envio_id={$ls['max_id']}\n";
}

echo "\n================ 7. AISLAMIENTO TEST/REAL ================\n";
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$leadsDummy = q($db, "SELECT id, email, estado_lead, es_duplicado, nombre_club FROM clubes_crm WHERE id IN (1814,1815,1816,1817) ORDER BY id");
foreach ($leadsDummy as $lead) {
    $isTest = esLeadTest($lead);
    // elegibilidad contra campaña no test (campaign_id=2)
    $elig = esElegibleParaEnvio($db, (int)$lead['id'], 2);
    echo "  esLeadTest({$lead['id']}) = " . var_export($isTest, true) . " | elegibilidad camp2 = " . var_export($elig, true) . "\n";
}

echo "\n================ 10. EVOLUTION API ================\n";
echo "  Integración CRM <-> Evolution API = NO EXISTE (no hay código ni config)\n";

echo "\n================ FIN AUDITORÍA ================\n";
$db->close();