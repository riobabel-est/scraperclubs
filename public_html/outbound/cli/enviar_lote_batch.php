<?php
/**
 * enviar_lote_batch.php — DISPARO DEL LOTE REAL (FASE 6 del MEGAPROMPT V2).
 *
 * Envía el primer lote controlado de campaña 2 reutilizando api/enviar_lote.php
 * (vía scripts/runner_envio_lead.php). Salvaguardas:
 *   - Batch debe existir y estar AUTORIZADO (creado por auditoria_pre_lote.php).
 *   - Solo leads elegibles (sin envío previo, sin bounce, sin supresión, REAL).
 *   - Variante determinista (la recalcula el backend).
 *   - SMTP rotativa respetando limite_diario (15/día).
 *   - Delay >= 3 s entre envíos.
 *   - --dry-run muestra el plan sin enviar.
 *
 * Uso: php cli/enviar_lote_batch.php --campaign=2 --batch=2026-08-30-A \
 *          [--limite=150] [--delay=3] [--dry-run]
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/eligibilidad.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "Solo CLI\n"; exit(1); }

$DB_PATH = __DIR__ . '/../data/stats.db';
$RUNNER  = dirname(__DIR__, 2) . '/scripts/runner_envio_lead.php';

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

$opts = getopt('', ['campaign:', 'batch:', 'limite:', 'delay:', 'dry-run', 'bd:']);
if (!empty($opts['bd'])) {
    $DB_PATH = (string)$opts['bd'];
    $db->close();
    $db = new SQLite3($DB_PATH);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
}
$campaignId = (int)($opts['campaign'] ?? 0);
$batch      = trim((string)($opts['batch'] ?? ''));
$limite     = max(1, (int)($opts['limite'] ?? 150));
$delay      = max(3, (int)($opts['delay'] ?? 3));
$dryRun     = isset($opts['dry-run']);

if ($campaignId <= 0 || $batch === '') { echo "Uso: php cli/enviar_lote_batch.php --campaign=N --batch=X [--limite=N] [--delay=N] [--dry-run]\n"; exit(2); }

// ─── Verificación del batch (debe existir y estar AUTORIZADO) ──────────────
$batchRow = $db->querySingle("SELECT id, estado, tamano FROM batches WHERE campaign_id = {$campaignId} AND batch = '" . $db->escapeString($batch) . "'", true);
if (!$batchRow) { echo "ERROR: batch '{$batch}' no registrado. Ejecuta primero la auditoría con --crear-batch.\n"; exit(2); }
if (strtoupper((string)$batchRow['estado']) !== 'AUTORIZADO') { echo "ERROR: batch '{$batch}' no está AUTORIZADO (estado={$batchRow['estado']}).\n"; exit(2); }

// ─── Selección del lote (primeros N leads reales sin envío, elegibles) ─────
$sql = "SELECT c.id, c.email, c.nombre_club, c.federacion, c.estado_lead
        FROM clubes_crm c
        WHERE 1=1
          AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email)
                          AND e.campaign_id = {$campaignId} AND COALESCE(e.es_rotacion,0) = 0)
          AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
        ORDER BY c.id LIMIT {$limite}";
$candidatos = [];
$r = $db->query($sql);
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $elig = esElegibleParaEnvio($db, (int)$row['id'], $campaignId);
    if ($elig['ok']) { $candidatos[] = $row; }
}
$candidatos = array_slice($candidatos, 0, $limite);

if (empty($candidatos)) { echo "Sin leads elegibles para el lote.\n"; exit(0); }

// ─── Asignación SMTP rotativa (respeta limite_diario) ──────────────────────
$cuentas = [];
$r = $db->query("SELECT id, email, limite_diario, enviados_hoy FROM cuentas_smtp WHERE activa = 1 ORDER BY id ASC");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $cuentas[] = $row; }
if (empty($cuentas)) { echo "ERROR: sin cuentas SMTP activas.\n"; exit(2); }

$usoLocal = array_fill_keys(array_column($cuentas, 'id'), 0);
$plan = [];
foreach ($candidatos as $c) {
    $smtp = null;
    $mejorUso = PHP_INT_MAX;
    foreach ($cuentas as $cu) {
        $usados = (int)$cu['enviados_hoy'] + $usoLocal[(int)$cu['id']];
        // Balanceo por carga mínima: la cuenta con menor uso diario disponible.
        if ($usados < (int)$cu['limite_diario'] && $usados < $mejorUso) {
            $mejorUso = $usados;
            $smtp = $cu;
        }
    }
    if (!$smtp) { break; }
    $usoLocal[(int)$smtp['id']]++;
    $plan[] = ['lead' => (int)$c['id'], 'email' => $c['email'], 'club' => $c['nombre_club'],
        'variante' => asignarVariante((int)$c['id'], $campaignId), 'smtp' => (int)$smtp['id'], 'smtp_email' => $smtp['email']];
}

if ($dryRun) {
    echo "━━ DRY-RUN — LOTE {$batch} (campaña {$campaignId}) · " . count($plan) . " envíos planificados ━━\n";
    foreach ($plan as $p) {
        printf("  lead=%-5d var=%s smtp=%-28s %s (%s)\n", $p['lead'], $p['variante'], $p['smtp_email'], $p['email'], mb_substr($p['club'], 0, 32));
    }
    echo "━━ NINGÚN EMAIL ENVIADO (dry-run) ━━\n";
    $db->close();
    exit(0);
}

// ─── Disparo real ───────────────────────────────────────────────────────────
echo "━━ ENVIANDO LOTE {$batch} (campaña {$campaignId}) · " . count($plan) . " envíos · delay={$delay}s ━━\n";
$ok = 0; $err = 0; $detalleErrores = [];
$inicio = microtime(true);

foreach ($plan as $i => $p) {
    $phpBin = (string)(PHP_BINARY ?: 'php');
    $cmd = '"' . $phpBin . '" "' . $RUNNER . '" --lead=' . $p['lead'] . ' --tpl=1 --smtp=' . $p['smtp'] . ' --campaign=' . $campaignId;
    $out = shell_exec($cmd . ' 2>&1');
    $j = json_decode((string)$out, true);
    if (is_array($j) && !empty($j['envio_exitoso'])) {
        $ok++;
        echo "  [" . ($i + 1) . "/" . count($plan) . "] ✅ lead {$p['lead']} ({$p['email']}) var={$p['variante']} smtp={$p['smtp']}\n";
    } else {
        $err++;
        $msg = is_array($j) ? ($j['error_smtp'] ?? $j['error'] ?? 'desconocido') : trim((string)$out);
        $detalleErrores[] = "lead {$p['lead']}: {$msg}";
        echo "  [" . ($i + 1) . "/" . count($plan) . "] ❌ lead {$p['lead']} ({$p['email']}) error: {$msg}\n";
    }
    if ($i < count($plan) - 1) { sleep($delay); }
}
$seg = round(microtime(true) - $inicio, 1);

// ─── Actualizar estado del batch (solo completo cuando no quedan pendientes) ──
// El batch NO se marca ENVIADO en un disparo parcial: continúa AUTORIZADO para
// las siguientes tandas (máx 150/día según el plan). tamano se mantiene (200).
$pendientes = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE 1=1
    AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email)
                    AND e.campaign_id = {$campaignId} AND COALESCE(e.es_rotacion,0) = 0)
    AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')");
$nuevoEstado = ($pendientes === 0) ? 'ENVIADO' : (($err > 0) ? 'ENVIADO_PARCIAL' : 'AUTORIZADO');
$db->exec("UPDATE batches SET estado = '{$nuevoEstado}' WHERE id = " . (int)$batchRow['id']);

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo " LOTE {$batch} · OK={$ok} · ERROR={$err} · duración={$seg}s · batch={$nuevoEstado} · pendientes={$pendientes}\n";
if (!empty($detalleErrores)) {
    echo " Errores:\n";
    foreach ($detalleErrores as $e) { echo "  - {$e}\n"; }
}
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$db->close();
exit($err === 0 ? 0 : 3);
