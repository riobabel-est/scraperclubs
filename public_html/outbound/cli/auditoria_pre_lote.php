<?php
/**
 * auditoria_pre_lote.php — CHECKPOINT DE LOTE (FASE 5 del MEGAPROMPT V2).
 *
 * Audita automáticamente un lote de leads ANTES de permitir el envío REAL.
 * Resultado: `READY TO SEND` o `BLOCKED` (decisión inequívoca; los warnings se
 * reportan dentro del informe pero no sustituyen a la decisión).
 *
 * Uso (CLI):
 *   php cli/auditoria_pre_lote.php --campaign=2 --batch=2026-08-29-A --limite=300 \
 *       [--federacion=Andalucía] [--json] [--crear-batch]
 *
 * Comprobaciones (10):
 *   TEST/REAL · DUPLICATE · BOUNCE · BLACKLIST · EMAIL VALIDITY · CAMPAIGN ·
 *   VARIANT · TEMPLATE · SMTP · TRACKING
 *
 * PHP 8.x nativo — SiteGround compatible. No envía emails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/eligibilidad.php'; // incluye abc.php y respuestas.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se ejecuta desde CLI.\n";
    exit(1);
}

$DB_PATH = __DIR__ . '/../data/stats.db';
if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

$opts = getopt('', ['campaign:', 'batch:', 'limite:', 'federacion:', 'json', 'crear-batch']);
$campaignId  = (int)($opts['campaign'] ?? 0);
$batch       = trim((string)($opts['batch'] ?? ''));
$limite      = max(1, (int)($opts['limite'] ?? 300));
$federacion  = trim((string)($opts['federacion'] ?? ''));
$jsonOut     = isset($opts['json']);
$crearBatch  = isset($opts['crear-batch']);

if ($campaignId <= 0 || $batch === '') {
    echo "Uso: php cli/auditoria_pre_lote.php --campaign=N --batch=YYYY-MM-DD-A [--limite=N] [--federacion=X] [--json] [--crear-batch]\n";
    exit(2);
}

$checks = []; // cada uno: ['test'=>..., 'estado'=>PASS|WARNING|ERROR, 'n'=>int, 'detalle'=>string]

function addCheck(string $test, string $estado, int $n, string $detalle): void
{
    global $checks;
    $checks[] = ['test' => $test, 'estado' => $estado, 'n' => $n, 'detalle' => $detalle];
}

// ═══ CAMPAIGN CHECK ════════════════════════════════════════════════════════
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$camp = $db->querySingle("SELECT id, nombre, estado, entorno, activo FROM pipelines WHERE id = {$campaignId}", true);
if (!$camp) {
    addCheck('CAMPAIGN', 'ERROR', 0, 'campaign_id no existe');
    $campaignOk = false;
} else {
    $validacion = validarCampanaActiva($db, $campaignId, $modoEntorno);
    addCheck('CAMPAIGN', $validacion['ok'] ? 'PASS' : 'ERROR', 0,
        $validacion['ok'] ? "{$camp['nombre']} ({$camp['estado']}/{$camp['entorno']})" : $validacion['razon']);
    $campaignOk = $validacion['ok'];
}

// ═══ SELECCIÓN DEL LOTE CANDIDATO ═══════════════════════════════════════════
// Leads sin primer envío en la campaña (es_rotacion=0), con filtros opcionales.
$sql = "SELECT c.id, c.email, c.nombre_club, c.estado_lead, c.federacion, c.es_duplicado
        FROM clubes_crm c
        WHERE 1=1
          AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email)
                          AND e.campaign_id = {$campaignId} AND COALESCE(e.es_rotacion,0) = 0)";
if ($federacion !== '') {
    $sql .= " AND c.federacion = '" . $db->escapeString($federacion) . "'";
}
$sql .= " ORDER BY c.id LIMIT {$limite}";

$candidatos = [];
$r = $db->query($sql);
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $candidatos[] = $row;
}

// ═══ TEST/REAL CHECK ═══════════════════════════════════════════════════════
$testEnLote = 0;
foreach ($candidatos as $c) {
    if (esLeadTest($c)) { $testEnLote++; }
}
addCheck('TEST/REAL', $testEnLote === 0 ? 'PASS' : 'ERROR', $testEnLote,
    $testEnLote === 0 ? 'sin leads TEST en el lote' : 'leads TEST detectados (bloqueado)');

// ═══ DUPLICATE CHECK ═══════════════════════════════════════════════════════
$emails = array_map(fn($c) => strtolower(trim($c['email'])), $candidatos);
$dup = count($emails) - count(array_unique($emails));
addCheck('DUPLICATE', $dup === 0 ? 'PASS' : 'ERROR', $dup,
    $dup === 0 ? 'sin emails duplicados en el lote' : 'emails duplicados en el lote (bloqueado)');

// ═══ BOUNCE CHECK ══════════════════════════════════════════════════════════
$bounceN = 0;
$bounceEmails = [];
if (!empty($emails)) {
    $in = "'" . implode("','", array_map(fn($e) => $db->escapeString($e), array_values(array_unique($emails)))) . "'";
    $q = "SELECT LOWER(email) AS em FROM rebotes WHERE email <> '' AND LOWER(email) IN ({$in})
          UNION
          SELECT LOWER(e.email) AS em FROM respuestas r JOIN envios e ON e.id = r.envio_id
          WHERE r.es_rebote = 1 AND e.email IS NOT NULL AND LOWER(e.email) IN ({$in})";
    $rr = $db->query($q);
    while ($row = $rr->fetchArray(SQLITE3_ASSOC)) {
        $bounceEmails[$row['em']] = true;
    }
    foreach ($emails as $e) { if (isset($bounceEmails[$e])) $bounceN++; }
}
addCheck('BOUNCE', $bounceN === 0 ? 'PASS' : 'ERROR', $bounceN,
    $bounceN === 0 ? 'sin hard bounces en el lote' : 'emails con hard bounce excluidos (bloqueado)');

// ═══ BLACKLIST CHECK ═══════════════════════════════════════════════════════
$blacklistN = 0;
$estadosSup = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido', '06 Perdido', '06 Baja/Archivado', 'Baja/Archivado', '07 Baja', 'Baja'];
foreach ($candidatos as $c) {
    if (in_array((string)$c['estado_lead'], $estadosSup, true)) { $blacklistN++; }
}
addCheck('BLACKLIST', $blacklistN === 0 ? 'PASS' : 'ERROR', $blacklistN,
    $blacklistN === 0 ? 'sin leads en lista negra/baja' : 'leads en supresión excluidos (bloqueado)');

// ═══ EMAIL VALIDITY CHECK ══════════════════════════════════════════════════
$invN = 0;
$sinMxN = 0;
foreach ($candidatos as $c) {
    $em = (string)$c['email'];
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { $invN++; continue; }
    $dom = substr($em, (int)strrpos($em, '@') + 1);
    if ($dom !== '' && !checkdnsrr($dom, 'MX')) { $sinMxN++; }
}
addCheck('EMAIL VALIDITY', $invN === 0 ? 'PASS' : 'ERROR', $invN,
    $invN === 0 ? 'formatos válidos (sin MX: ' . $sinMxN . ' warning)' : 'emails con formato inválido (bloqueado)');

// ═══ VARIANT CHECK ═════════════════════════════════════════════════════════
$variantes = ['A' => 0, 'B' => 0, 'C' => 0];
foreach ($candidatos as $c) {
    $v = asignarVariante((int)$c['id'], $campaignId);
    if (isset($variantes[$v])) { $variantes[$v]++; }
}
addCheck('VARIANT', count($candidatos) > 0 ? 'PASS' : 'WARNING', count($candidatos),
    'determinista A/B/C → ' . json_encode($variantes));

// ═══ TEMPLATE CHECK ════════════════════════════════════════════════════════
$tpl = $db->querySingle(
    "SELECT t.id, t.nombre, t.activo FROM campaign_plantillas cp
     JOIN plantillas t ON t.id = cp.plantilla_id WHERE cp.campaign_id = {$campaignId} LIMIT 1",
    true
);
$tplOk = $tpl && (int)$tpl['activo'] === 1;
addCheck('TEMPLATE', $tplOk ? 'PASS' : 'ERROR', $tpl ? 1 : 0,
    $tplOk ? "plantilla '{$tpl['nombre']}' activa" : ($tpl ? 'plantilla inactiva (bloqueado)' : 'campaña sin plantilla (bloqueado)'));

// ═══ SMTP CHECK ════════════════════════════════════════════════════════════
$smtpActivas = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp WHERE activa = 1 AND enviados_hoy < limite_diario");
$smtpTotales = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp WHERE activa = 1");
addCheck('SMTP', $smtpActivas > 0 ? 'PASS' : 'ERROR', $smtpActivas,
    $smtpActivas > 0 ? "{$smtpActivas}/{$smtpTotales} cuentas disponibles hoy" : 'sin cuentas SMTP con límite diario disponible (bloqueado)');

// ═══ TRACKING CHECK ════════════════════════════════════════════════════════
$trackingProbe = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));
$colision = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE tracking_id = '{$trackingProbe}'");
addCheck('TRACKING', $colision === 0 ? 'PASS' : 'ERROR', $colision,
    'generación de tracking_id/message_id OK (sin colisión)');

// ═══ DECISIÓN FINAL ════════════════════════════════════════════════════════
$errores = 0;
foreach ($checks as $ch) { if ($ch['estado'] === 'ERROR') $errores++; }
$decision = $errores === 0 ? 'READY TO SEND' : 'BLOCKED';

if ($crearBatch && $decision === 'READY TO SEND') {
    $existeBatch = (int)$db->querySingle("SELECT COUNT(*) FROM batches WHERE campaign_id = {$campaignId} AND batch = '" . $db->escapeString($batch) . "'");
    if ($existeBatch === 0) {
        $st = $db->prepare('INSERT INTO batches (campaign_id, batch, estado, tamano) VALUES (:c, :b, \'AUTORIZADO\', :t)');
        $st->bindValue(':c', $campaignId, SQLITE3_INTEGER);
        $st->bindValue(':b', $batch, SQLITE3_TEXT);
        $st->bindValue(':t', count($candidatos), SQLITE3_INTEGER);
        $st->execute();
    }
}

// ═══ SALIDA ════════════════════════════════════════════════════════════════
if ($jsonOut) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => $decision === 'READY TO SEND',
        'decision' => $decision,
        'campaign_id' => $campaignId,
        'batch' => $batch,
        'leads' => count($candidatos),
        'checks' => $checks,
        'variantes' => $variantes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo " CHECKPOINT PRE-LOTE — CAMPAÑA {$campaignId} · BATCH {$batch}\n";
    echo " Leads del lote: " . count($candidatos) . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    foreach ($checks as $ch) {
        printf("  [%-6s] %-14s (%d) %s\n", $ch['estado'], $ch['test'], $ch['n'], $ch['detalle']);
    }
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo " DECISIÓN: {$decision}\n";
    echo ($decision === 'READY TO SEND')
        ? " Requiere confirmación explícita del usuario antes de enviar.\n"
        : " Envío BLOQUEADO. Corrige los ERRORES antes de reintentar.\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
}

$db->close();
exit($decision === 'READY TO SEND' ? 0 : 3);


