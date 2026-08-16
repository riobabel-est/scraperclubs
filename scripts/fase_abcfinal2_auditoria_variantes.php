<?php
/**
 * FASE ABC-FINAL.2 — AUDITORÍA FINAL DE VARIANTES A/B/C (SOLO LECTURA).
 *
 * NO envía, NO POST, NO SMTP, NO cron, NO escribe en BD.
 * Audita envios 3..7 (campaign_id=3) contra asignarVariante() real y
 * la plantilla 2, comparando el contenido realmente almacenado.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

function line(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    echo str_pad($k, 30) . " : " . $v . "\n";
}

$CAMPAIGN = 3;

echo "==================== A1. CONFIG ====================\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '?');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '?');
line('config.modo_entorno', $modoEntorno);
line('config.motor_estado', $motorEstado);

$camp = $db->querySingle("SELECT id, nombre, estado, entorno, activo FROM pipelines WHERE id = {$CAMPAIGN}", true);
line('campaign_id=3', json_encode($camp, JSON_UNESCAPED_UNICODE));

echo "\n==================== C. PLANTILLA 2 ====================\n";
$p = $db->querySingle(
    "SELECT * FROM plantillas WHERE id = 2",
    true
);
line('plantilla.id', $p['id']);
line('plantilla.nombre', $p['nombre']);
line('plantilla.activo', $p['activo']);
line('plantilla.test_ab', $p['test_ab']);
line('plantilla.tipo', $p['tipo'] ?? null);
line('plantilla.categoria', $p['categoria'] ?? null);
echo "--- Columnas de plantillas ---\n";
foreach ($db->query("PRAGMA table_info(plantillas)") as $c) {
    echo "  {$c['name']} ({$c['type']})\n";
}

echo "\n--- Contenido variantes plantilla 2 ---\n";
foreach (['A' => ['asunto', 'cuerpo'], 'B' => ['asunto_b', 'cuerpo_b'], 'C' => ['asunto_c', 'cuerpo_c']] as $v => $cols) {
    $asunto = (string)($p[$cols[0]] ?? '');
    $cuerpo = (string)($p[$cols[1]] ?? '');
    line("asunto_{$v}", $asunto);
    echo "  cuerpo_{$v} (".strlen($cuerpo)." bytes):\n";
    echo "  " . str_replace("\n", "\n  ", mb_substr($cuerpo, 0, 400)) . (strlen($cuerpo) > 400 ? "..." : "") . "\n";
    line("  variante {$v} completa", ($asunto !== '' && $cuerpo !== ''));
}

echo "\n==================== E. DIFERENCIACIÓN DIRECTA A/B/C ====================\n";
$cA = (string)($p['cuerpo'] ?? '');
$cB = (string)($p['cuerpo_b'] ?? '');
$cC = (string)($p['cuerpo_c'] ?? '');
$sA = (string)($p['asunto'] ?? '');
$sB = (string)($p['asunto_b'] ?? '');
$sC = (string)($p['asunto_c'] ?? '');
line('cuerpo A == cuerpo B', $cA === $cB ? 'IDENTICOS' : 'DIFERENTES');
line('cuerpo A == cuerpo C', $cA === $cC ? 'IDENTICOS' : 'DIFERENTES');
line('cuerpo B == cuerpo C', $cB === $cC ? 'IDENTICOS' : 'DIFERENTES');
line('asunto A == asunto B', $sA === $sB ? 'IDENTICOS' : 'DIFERENTES');
line('asunto A == asunto C', $sA === $sC ? 'IDENTICOS' : 'DIFERENTES');
line('asunto B == asunto C', $sB === $sC ? 'IDENTICOS' : 'DIFERENTES');

echo "\n==================== A/B. AUDITORÍA ENVÍOS 3..7 ====================\n";
$res = $db->query("SELECT * FROM envios WHERE campaign_id = {$CAMPAIGN} AND id IN (3,4,5,6,7) ORDER BY id");
$rows = [];
while ($e = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $e;
}

$coverage = ['A' => false, 'B' => false, 'C' => false];
$allMatch = true;

function normalizarCuerpoAlmacenado(string $body): string {
    // Quitar píxel de tracking y fingerprint anti-detección (variables por envío).
    $b = preg_replace('/<img src="[^"]*track\.php\?id=[^"]*"[^>]*>/i', '', $body);
    $b = preg_replace('/<!--\s*fpid:[^>]*-->/i', '', $b);
    return $b;
}

foreach ($rows as $e) {
    $eid = (int)$e['id'];
    $leadId = (int)$e['lead_id'];
    $variantReg = (string)$e['variant'];
    $variantCalc = asignarVariante($leadId, $CAMPAIGN);
    $coverage[$variantReg] = true;

    echo "\n--- envio_id={$eid} | lead_id={$leadId} ---\n";
    line('variant registrada', $variantReg);
    line('variant calculada', $variantCalc);
    line('ASIGNACION COINCIDE', $variantReg === $variantCalc ? 'true' : 'false');
    if ($variantReg !== $variantCalc) { $allMatch = false; }

    line('estado', $e['estado']);
    line('resultado_envio', $e['resultado_envio']);
    line('plantilla_id', $e['plantilla_id']);
    line('smtp_id', $e['smtp_id']);
    line('email (lead original)', $e['email']);
    line('message_id', $e['message_id']);
    line('tracking_id', $e['tracking_id']);
    line('asunto almacenado', $e['asunto']);
    line('cuerpo_mensaje bytes', strlen((string)$e['cuerpo_mensaje']));

    // Reconstruir el contenido esperado con las sustituciones reales del flujo.
    $club = $db->querySingle("SELECT nombre_club, email, federacion, persona_contacto FROM clubes_crm WHERE id = {$leadId}", true);
    $cuenta = $db->querySingle("SELECT email, nombre_emisor, cargo_emisor FROM cuentas_smtp WHERE id = " . (int)$e['smtp_id'], true);

    $nombreClub   = $club['nombre_club'] ?? (string)$e['club'];
    $emailClub    = $club['email'] ?? (string)$e['email'];
    $federacion   = (string)($club['federacion'] ?? '');
    $contacto     = ($club['persona_contacto'] ?? '') ?: 'responsable';
    $senderName   = (string)($cuenta['nombre_emisor'] ?? '');
    if ($senderName === '') { $senderName = ucfirst(explode('@', (string)$cuenta['email'])[0]); }
    $senderTitle  = (string)($cuenta['cargo_emisor'] ?? '');
    $senderEmail  = (string)$cuenta['email'];

    $plantillaVariante = resolverContenidoVariante([
        'asunto' => $p['asunto'], 'cuerpo' => $p['cuerpo'],
        'asunto_b' => $p['asunto_b'], 'cuerpo_b' => $p['cuerpo_b'],
        'asunto_c' => $p['asunto_c'], 'cuerpo_c' => $p['cuerpo_c'],
        'test_ab' => $p['test_ab'],
    ], $variantReg);

    $replacements = [
        '{{CLUB}}'        => $nombreClub,
        '{{CONTACTO}}'     => $contacto,
        '{{FEDERACION}}'   => $federacion,
        '{{ANIO}}'         => (string)(int)date('Y', strtotime((string)$e['fecha_resultado_envio'])),
        '{{EMAIL}}'        => $emailClub,
        '{{SENDER_NAME}}'  => $senderName,
        '{{SENDER_TITLE}}' => $senderTitle,
        '{{SENDER_EMAIL}}' => $senderEmail,
    ];

    $asuntoEsperado = str_replace(array_keys($replacements), array_values($replacements), (string)$plantillaVariante['asunto']);
    $cuerpoEsperado = str_replace(array_keys($replacements), array_values($replacements), (string)$plantillaVariante['cuerpo']);

    $cuerpoNormalizado = normalizarCuerpoAlmacenado((string)$e['cuerpo_mensaje']);

    $asuntoCoincide = trim((string)$e['asunto']) === trim($asuntoEsperado);
    $cuerpoCoincide = trim($cuerpoNormalizado) === trim($cuerpoEsperado);

    line('asunto coherencia', $asuntoCoincide ? 'OK' : 'DISCREPANCIA');
    line('cuerpo coherencia', $cuerpoCoincide ? 'OK' : 'DISCREPANCIA');

    if (!$asuntoCoincide) {
        echo "  [asunto esperado] {$asuntoEsperado}\n";
    }
    if (!$cuerpoCoincide) {
        echo "  [cuerpo normalizado len=" . strlen($cuerpoNormalizado) . " vs esperado len=" . strlen($cuerpoEsperado) . "]\n";
        echo "  --- cuerpo normalizado (inicio) ---\n  " . str_replace("\n", "\n  ", mb_substr($cuerpoNormalizado, 0, 300)) . "\n";
        echo "  --- cuerpo esperado (inicio) ---\n  " . str_replace("\n", "\n  ", mb_substr($cuerpoEsperado, 0, 300)) . "\n";
    }
}

echo "\n==================== D. COBERTURA A/B/C ====================\n";
line('cobertura A', $coverage['A'] ? 'presente' : 'AUSENTE');
line('cobertura B', $coverage['B'] ? 'presente' : 'AUSENTE');
line('cobertura C', $coverage['C'] ? 'presente' : 'AUSENTE');

echo "\n==================== F. SMOKE RECIBIDO (envio_id=7) ====================\n";
$e7 = $db->querySingle("SELECT * FROM envios WHERE id = 7", true);
line('envio_id=7.variant', $e7['variant']);
line('envio_id=7.estado', $e7['estado']);
line('envio_id=7.resultado_envio', $e7['resultado_envio']);
line('envio_id=7.message_id', $e7['message_id']);
line('envio_id=7.smtp_id', $e7['smtp_id']);

echo "\n==================== G. INTEGRIDAD ====================\n";
$maxId = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
line('MAX(envios.id)', $maxId);
$gt7 = (int)($db->querySingle("SELECT COUNT(*) FROM envios WHERE id > 7") ?: 0);
line('envios.id > 7 COUNT', $gt7);
$e6 = $db->querySingle("SELECT id, estado, resultado_envio, lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id FROM envios WHERE id = 6", true);
line('envio_id=6', json_encode($e6, JSON_UNESCAPED_UNICODE));
line('envio_id=7 (id,estado,res,lead,var)', json_encode([
    'id' => $e7['id'], 'estado' => $e7['estado'], 'resultado_envio' => $e7['resultado_envio'],
    'lead_id' => $e7['lead_id'], 'variant' => $e7['variant'], 'plantilla_id' => $e7['plantilla_id'], 'smtp_id' => $e7['smtp_id'],
], JSON_UNESCAPED_UNICODE));
line('modo_entorno', $modoEntorno);
line('motor_estado', $motorEstado);

$db->close();

echo "\n==================== VEREDICTO ====================\n";
$abcDiferentes = !($cA === $cB || $cA === $cC || $cB === $cC)
    && !($sA === $sB || $sA === $sC || $sB === $sC);
$coberturaOk = $coverage['A'] && $coverage['B'] && $coverage['C'];

echo "A -> " . ($coverage['A'] ? 'PASS' : 'BLOCKED') . "\n";
echo "B -> " . ($coverage['B'] ? 'PASS' : 'BLOCKED') . "\n";
echo "C -> " . ($coverage['C'] ? 'PASS' : 'BLOCKED') . "\n";
echo "asignación determinística coincide -> " . ($allMatch ? 'PASS' : 'BLOCKED') . "\n";
echo "variantes diferenciadas -> " . ($abcDiferentes ? 'PASS' : 'BLOCKED') . "\n";

if ($allMatch && $coberturaOk && $abcDiferentes) {
    echo "ABC_TEST_PASS\n";
} else {
    echo "BLOCKED\n";
}
exit(0);