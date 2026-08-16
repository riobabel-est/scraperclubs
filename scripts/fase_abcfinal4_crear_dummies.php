<?php
/**
 * FASE ABC-FINAL.4 — CREACIÓN CONTROLADA DE 3 DUMMIES A/B/C.
 *
 * ÚNICA operación de escritura permitida en esta fase: insertar exactamente
 * 3 leads TEST en clubes_crm (los dummies A/B/C), identificables de forma
 * inequívoca por nombre/email @futprotec.local.
 *
 * NO envía. NO SMTP. NO POST. NO cron. NO Evolution API.
 * NO crea envios. NO llama a reservarEnvioLogico(). NO toca campaña/plantilla/config.
 * NO ejecuta enviar_lote.php ni enviar_smtp_random.php.
 *
 * Tras la inserción, calcula la variante con la función REAL asignarVariante($leadId, 3)
 * (cálculo puro y determinístico; NO escribe en BD).
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';

$CAMPAIGN = 3;

echo "==================== CREACIÓN DUMMIES ABC-FINAL.4 ====================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 0. GUARDA DE PRECONDICIONES (solo lectura, sin modificar nada)
// ─────────────────────────────────────────────────────────────────────────
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '');
$camp = $db->querySingle("SELECT id, estado, entorno, activo FROM pipelines WHERE id = 3", true);
$plant = $db->querySingle("SELECT id, activo, test_ab FROM plantillas WHERE id = 2", true);

$preOk = true;
$failed = [];
if ($modoEntorno !== 'test') { $preOk = false; $failed[] = "modo_entorno != test"; }
if ($motorEstado !== 'pausado') { $preOk = false; $failed[] = "motor_estado != pausado"; }
if (!$camp || strtoupper((string)$camp['estado']) !== 'PILOT' || (int)$camp['activo'] !== 1 || strtolower((string)$camp['entorno']) !== 'test') {
    $preOk = false; $failed[] = "campaña 3 no válida (PILOT/activo=1/entorno=test)";
}
if (!$plant || (int)$plant['activo'] !== 1 || (int)$plant['test_ab'] !== 1) {
    $preOk = false; $failed[] = "plantilla 2 no activa o test_ab != 1";
}

echo "modo_entorno: {$modoEntorno}\n";
echo "motor_estado: {$motorEstado}\n";
echo "campaña 3: " . json_encode($camp, JSON_UNESCAPED_UNICODE) . "\n";
echo "plantilla 2: " . json_encode($plant, JSON_UNESCAPED_UNICODE) . "\n";
echo "MAX(envios.id) ANTES: " . (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0) . "\n\n";

if (!$preOk) {
    fwrite(STDERR, "ABORTADO: precondiciones no cumplidas => " . implode('; ', $failed) . "\n");
    $db->close();
    exit(3);
}
echo "Precondiciones OK.\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. COMPROBACIÓN DE NO EXISTENCIA PREVIA
// ─────────────────────────────────────────────────────────────────────────
$dummies = [
    ['tag' => 'A', 'nombre' => 'TEST_ABC_FINAL4_A', 'email' => 'test_abc_final4_a@futprotec.local'],
    ['tag' => 'B', 'nombre' => 'TEST_ABC_FINAL4_B', 'email' => 'test_abc_final4_b@futprotec.local'],
    ['tag' => 'C', 'nombre' => 'TEST_ABC_FINAL4_C', 'email' => 'test_abc_final4_c@futprotec.local'],
];

$yaExiste = [];
foreach ($dummies as $d) {
    $n = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE nombre_club = '" . $db->escapeString($d['nombre']) . "'");
    $e = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE email = '" . $db->escapeString($d['email']) . "'");
    if ($n > 0 || $e > 0) {
        $yaExiste[] = "{$d['tag']} (nombre={$n}, email={$e})";
    }
}
if (count($yaExiste) > 0) {
    fwrite(STDERR, "ABORTADO: ya existen dummies previamente => " . implode(', ', $yaExiste) . "\n");
    $db->close();
    exit(4);
}
echo "No existe ningún dummy A/B/C previamente. Procediendo a insertar exactamente 3.\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 2. INSERCIÓN EXACTA DE 3 LEADS TEST (única escritura permitida)
//    Se establece estado_lead = 'Sin Contactar' y es_duplicado = 0 (lead limpio).
//    El resto de columnas quedan con sus defaults del esquema.
// ─────────────────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "INSERT INTO clubes_crm (nombre_club, email, estado_lead, es_duplicado)
     VALUES (:nombre, :email, 'Sin Contactar', 0)"
);

$creados = [];
$db->exec('BEGIN');
try {
    foreach ($dummies as $d) {
        $stmt->bindValue(':nombre', $d['nombre'], SQLITE3_TEXT);
        $stmt->bindValue(':email',  $d['email'],  SQLITE3_TEXT);
        $stmt->execute();
        $newId = (int)$db->lastInsertRowID();
        $creados[$d['tag']] = ['id' => $newId, 'nombre' => $d['nombre'], 'email' => $d['email']];
        echo "INSERT OK -> dummy {$d['tag']} | lead_id={$newId} | {$d['nombre']} | {$d['email']}\n";
        $stmt->reset();
    }
    $db->exec('COMMIT');
} catch (\Exception $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "ERROR INSERT: " . $e->getMessage() . "\n");
    $db->close();
    exit(5);
}

echo "\nCreados: " . count($creados) . " leads (esperado 3).\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 3. CÁLCULO DE VARIANTE CON LA FUNCIÓN REAL asignarVariante() (solo lectura)
// ─────────────────────────────────────────────────────────────────────────
echo "--- Variante calculada con asignarVariante(lead_id, 3) ---\n";
$conteo = ['A' => 0, 'B' => 0, 'C' => 0];
foreach (['A', 'B', 'C'] as $tag) {
    $id = $creados[$tag]['id'];
    $v = asignarVariante($id, $CAMPAIGN);
    $creados[$tag]['variante'] = $v;
    $conteo[$v]++;
    echo "  dummy {$tag} | lead_id={$id} | asignarVariante({$id},3) = {$v}\n";
}

echo "\nConteo de variantes: A={$conteo['A']} B={$conteo['B']} C={$conteo['C']}\n";
$abcExacto = ($conteo['A'] === 1 && $conteo['B'] === 1 && $conteo['C'] === 1);
echo "¿A/B/C exactamente una vez cada uno? " . ($abcExacto ? 'SÍ' : 'NO') . "\n";

echo "\nMAX(envios.id) DESPUÉS de crear dummies (debe seguir siendo 7): "
    . (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0) . "\n";

$db->close();

echo "\n==================== RESULTADO ====================\n";
// Correspondencia A/B/C conceptual (SOLO en memoria; no se persiste).
$destinatarios = [
    'A' => 'estudiioriobabel@gmail.com',
    'B' => 'ruyelcano@gmail.com',
    'C' => 'rodrigo@riobabel.com',
];
foreach (['A', 'B', 'C'] as $tag) {
    $d = $creados[$tag];
    echo "DUMMY {$tag} => lead_id={$d['id']} | variante={$d['variante']} | destinatario_futuro={$destinatarios[$tag]}\n";
}

if ($abcExacto) {
    echo "\nVEREDICTO: ABC_DUMMIES_READY\n";
    exit(0);
} else {
    echo "\nVEREDICTO: BLOCKED\n";
    exit(6);
}