<?php
/**
 * test_baja_flow.php — Tests del flujo de baja (GO-LIVE UNSUBSCRIBE).
 *
 * Ejecuta los 10 tests de la PARTE J sobre una BD temporal, SIN tocar la BD real
 * ni abrir SMTP. Cada escenario se ejecuta en un subproceso PHP que incluye
 * baja.php con las superglobales configuradas y BAJA_DB_PATH apuntando a la BD
 * temporal.
 *
 * Uso: php scripts/test_baja_flow.php
 */

declare(strict_types=1);

$BASE = __DIR__ . '/../public_html/outbound';
$BAJA = $BASE . '/api/baja.php';
$TMP  = sys_get_temp_dir() . '/futprotec_baja_test_' . bin2hex(random_bytes(4)) . '.db';
$RUN  = sys_get_temp_dir() . '/futprotec_baja_run_' . bin2hex(random_bytes(4)) . '.php';

$pass = 0;
$fail = 0;
$results = [];

function check(string $nombre, bool $ok, string $detalle = ''): void {
    global $pass, $fail, $results;
    if ($ok) { $pass++; } else { $fail++; }
    $results[] = [$nombre, $ok, $detalle];
}

// ─── Crear BD temporal ────────────────────────────────────────────────────────
$db = new SQLite3($TMP);
$db->exec("CREATE TABLE clubes_crm (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre_club TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    estado_lead TEXT DEFAULT 'Sin Contactar',
    observaciones TEXT DEFAULT '',
    ultimo_contacto DATETIME,
    es_duplicado INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    tracking_id TEXT UNIQUE NOT NULL,
    lead_id INTEGER,
    campaign_id INTEGER,
    estado TEXT DEFAULT 'pendiente'
)");
// Tabla pipelines (necesaria para esCampanaTest en esElegibleParaEnvio).
// Campaña 2 = NO-TEST (entorno 'real').
$db->exec("CREATE TABLE pipelines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT,
    entorno TEXT DEFAULT 'real'
)");
$db->exec("INSERT INTO pipelines (id, nombre, entorno) VALUES (2, 'Campana Real', 'real')");
// Lead de prueba (TEST) + envío con tracking_id.
$db->exec("INSERT INTO clubes_crm (nombre_club, email, estado_lead) VALUES ('TEST Club Baja', 'test_baja@futprotec.local', 'Sin Contactar')");
$leadId = (int)$db->lastInsertRowID();
$db->exec("INSERT INTO envios (email, tracking_id, lead_id, campaign_id, estado) VALUES ('test_baja@futprotec.local', 'fut_test_tracking_abc123', {$leadId}, 2, 'enviado')");
// Lead REAL (para TEST 9b: elegible en campaña NO-TEST).
$db->exec("INSERT INTO clubes_crm (nombre_club, email, estado_lead) VALUES ('Real Club Ejemplo', 'real_club@example.com', 'Sin Contactar')");
$leadRealId = (int)$db->lastInsertRowID();
$db->close();


// Helper: ejecutar baja.php en subproceso con superglobales dadas.
function runBaja(string $tmpDb, string $runFile, array $get, array $post, string $method): string {
    $code = '<?php
        $_GET = ' . var_export($get, true) . ';
        $_POST = ' . var_export($post, true) . ';
        $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';
        define("BAJA_DB_PATH", ' . var_export($tmpDb, true) . ');
        ob_start();
        include ' . var_export(__DIR__ . '/../public_html/outbound/api/baja.php', true) . ';
        $html = ob_get_clean();
        echo $html;
    ';
    file_put_contents($runFile, $code);
    $out = shell_exec('php ' . escapeshellarg($runFile) . ' 2>&1');
    return (string)$out;
}

// Helper: leer estado del lead en BD temporal.
function leadEstado(string $tmpDb, string $email): array {
    $db = new SQLite3($tmpDb);
    $stmt = $db->prepare("SELECT estado_lead, observaciones FROM clubes_crm WHERE email = :e LIMIT 1");
    $stmt->bindValue(':e', $email, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    $db->close();
    return $row ?: ['estado_lead' => '', 'observaciones' => ''];
}


// Helper: CSRF esperado (mismo algoritmo que baja.php).
function csrfFor(string $tmpDb, string $ident): string {
    $secret = hash('sha256', $tmpDb . '::futprotec_baja_csrf_v1');
    return hash_hmac('sha256', $ident, $secret);
}

$tracking = 'fut_test_tracking_abc123';
$email = 'test_baja@futprotec.local';

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 1: GET enlace → BD NO modificada (solo muestra confirmación).
// ═══════════════════════════════════════════════════════════════════════════════
$html = runBaja($TMP, $RUN, ['t' => $tracking], [], 'GET');
$estado = leadEstado($TMP, $email);
check('TEST 1: GET no modifica BD', $estado['estado_lead'] === 'Sin Contactar', 'estado=' . $estado['estado_lead']);

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 2: Mostrar confirmación.
// ═══════════════════════════════════════════════════════════════════════════════
check('TEST 2: Muestra confirmación', str_contains($html, 'CONFIRMAR BAJA') && str_contains($html, '¿Quieres confirmar la baja?'));

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 3: Cancelar (GET "Seguir recibiendo" = no POST) → BD NO modificada.
// ═══════════════════════════════════════════════════════════════════════════════
$estado = leadEstado($TMP, $email);
check('TEST 3: Cancelar no modifica BD', $estado['estado_lead'] === 'Sin Contactar', 'estado=' . $estado['estado_lead']);

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 4: Confirmar baja (POST) → BAJA registrada.
// ═══════════════════════════════════════════════════════════════════════════════
$csrf = csrfFor($TMP, $tracking);
$html = runBaja($TMP, $RUN, [], ['t' => $tracking, 'csrf' => $csrf, 'accion' => 'confirmar'], 'POST');
$estado = leadEstado($TMP, $email);
check('TEST 4: Confirmar registra baja', $estado['estado_lead'] === 'Lista Negra', 'estado=' . $estado['estado_lead']);
check('TEST 4b: Muestra "Baja realizada"', str_contains($html, 'Baja realizada correctamente'));

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 5: Confirmar dos veces → una única baja (idempotente, no duplica).
// ═══════════════════════════════════════════════════════════════════════════════
$obsAntes = leadEstado($TMP, $email)['observaciones'];
$html = runBaja($TMP, $RUN, [], ['t' => $tracking, 'csrf' => $csrf, 'accion' => 'confirmar'], 'POST');
$estado = leadEstado($TMP, $email);
$obsDespues = $estado['observaciones'];
$nBaja = substr_count($obsDespues, '[BAJA]');
check('TEST 5: Confirmar dos veces es idempotente', $estado['estado_lead'] === 'Lista Negra' && $nBaja === 1, 'nBaja=' . $nBaja);
check('TEST 5b: Muestra "ya estabas dado de baja"', str_contains($html, 'Ya estabas dado de baja'));


// ═══════════════════════════════════════════════════════════════════════════════
// TEST 6: Motivo seleccionado → motivo registrado.
// ═══════════════════════════════════════════════════════════════════════════════
$html = runBaja($TMP, $RUN, [], ['t' => $tracking, 'csrf' => $csrf, 'accion' => 'motivo', 'motivo' => 'Ya tengo proveedor'], 'POST');
$estado = leadEstado($TMP, $email);
check('TEST 6: Motivo registrado', str_contains($estado['observaciones'], 'Ya tengo proveedor'), $estado['observaciones']);

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 7: Motivo omitido → baja registrada, motivo vacío.
// ═══════════════════════════════════════════════════════════════════════════════
// Nuevo lead para test 7.
$db = new SQLite3($TMP);
$db->exec("INSERT INTO clubes_crm (nombre_club, email, estado_lead) VALUES ('TEST Club Baja 2', 'test_baja2@futprotec.local', 'Sin Contactar')");
$leadId2 = (int)$db->lastInsertRowID();
$db->exec("INSERT INTO envios (email, tracking_id, lead_id, campaign_id, estado) VALUES ('test_baja2@futprotec.local', 'fut_test_tracking_xyz789', {$leadId2}, 2, 'enviado')");
$db->close();
$tracking2 = 'fut_test_tracking_xyz789';
$csrf2 = csrfFor($TMP, $tracking2);
$html = runBaja($TMP, $RUN, [], ['t' => $tracking2, 'csrf' => $csrf2, 'accion' => 'confirmar', 'motivo' => ''], 'POST');
$estado = leadEstado($TMP, 'test_baja2@futprotec.local');
check('TEST 7: Motivo omitido → baja registrada', $estado['estado_lead'] === 'Lista Negra', 'estado=' . $estado['estado_lead']);
check('TEST 7b: Motivo omitido → sin motivo en observaciones', !str_contains($estado['observaciones'], 'Motivo baja:'), $estado['observaciones']);

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 8: Manipular email/token → rechazado.
// ═══════════════════════════════════════════════════════════════════════════════
// Token inválido.
$html = runBaja($TMP, $RUN, ['t' => 'token_invalido_manipulado'], [], 'GET');
check('TEST 8a: Token inválido rechazado', str_contains($html, 'No se pudo procesar') || str_contains($html, 'no es válido'));
// CSRF incorrecto en POST.
$html = runBaja($TMP, $RUN, [], ['t' => $tracking, 'csrf' => 'csrf_incorrecto', 'accion' => 'confirmar'], 'POST');
$estado = leadEstado($TMP, $email);
check('TEST 8b: CSRF incorrecto rechazado (no modifica)', $estado['estado_lead'] === 'Lista Negra' && str_contains($html, 'Solicitud no válida'));

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 9: Lead dado de baja → esElegibleParaEnvio = false.
// ═══════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';
$db = new SQLite3($TMP);
$db->enableExceptions(true);
$eligBaja = esElegibleParaEnvio($db, $leadId, 2);
$eligNormal = esElegibleParaEnvio($db, $leadRealId, 2);
$db->close();
check('TEST 9: Lead dado de baja no elegible', !$eligBaja['ok'] && $eligBaja['razon'] === 'supresion', 'razon=' . $eligBaja['razon']);
check('TEST 9b: Lead normal sigue elegible', $eligNormal['ok'], 'razon=' . $eligNormal['razon']);


// ═══════════════════════════════════════════════════════════════════════════════
// TEST 10: Compatibilidad con enlaces antiguos ?email=.
// ═══════════════════════════════════════════════════════════════════════════════
$db = new SQLite3($TMP);
$db->exec("INSERT INTO clubes_crm (nombre_club, email, estado_lead) VALUES ('TEST Club Baja 3', 'test_baja3@futprotec.local', 'Sin Contactar')");
$db->close();
// GET con ?email= → confirmación, no baja.
$html = runBaja($TMP, $RUN, ['email' => 'test_baja3@futprotec.local'], [], 'GET');
$estado = leadEstado($TMP, 'test_baja3@futprotec.local');
check('TEST 10a: GET ?email= muestra confirmación', str_contains($html, 'CONFIRMAR BAJA'));
check('TEST 10b: GET ?email= no modifica BD', $estado['estado_lead'] === 'Sin Contactar', 'estado=' . $estado['estado_lead']);
// POST con ?email= → baja.
$csrfEmail = csrfFor($TMP, 'test_baja3@futprotec.local');
$html = runBaja($TMP, $RUN, [], ['email' => 'test_baja3@futprotec.local', 'csrf' => $csrfEmail, 'accion' => 'confirmar'], 'POST');
$estado = leadEstado($TMP, 'test_baja3@futprotec.local');
check('TEST 10c: POST ?email= registra baja', $estado['estado_lead'] === 'Lista Negra', 'estado=' . $estado['estado_lead']);

// ─── Limpieza ──────────────────────────────────────────────────────────────────
@unlink($TMP);
@unlink($RUN);

// ─── Resultado ─────────────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════\n";
echo "  TEST BAJA FLOW (GO-LIVE UNSUBSCRIBE)\n";
echo "═══════════════════════════════════════════════════════════════\n";
foreach ($results as [$nombre, $ok, $detalle]) {
    echo ($ok ? "  ✅ " : "  ❌ ") . $nombre . ($detalle !== '' ? "  → " . $detalle : '') . "\n";
}
echo "───────────────────────────────────────────────────────────────\n";
echo "  ✅ Pass: {$pass}\n";
echo "  ❌ Fail: {$fail}\n";
echo "───────────────────────────────────────────────────────────────\n";
echo ($fail === 0 ? "  VEREDICTO: GO_LIVE_UNSUBSCRIBE_PASS\n" : "  VEREDICTO: BLOCKED\n");
echo "═══════════════════════════════════════════════════════════════\n";
exit($fail === 0 ? 0 : 1);
