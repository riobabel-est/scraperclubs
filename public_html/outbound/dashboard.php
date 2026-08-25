<?php
/**
 * dashboard.php — Panel CRM Kanban v2.0 FutProtec.
 * Tailwind CSS + Alpine.js + Lucide Icons. Modo Oscuro.
 * PHP 8.x nativo — SiteGround compatible.
 *
 * Orquestador: los endpoints AJAX se delegan a api/*.php y el render
 * HTML se mantiene aquí. Los helpers de presentación viven en inc/helpers.php.
 */
declare(strict_types=1);

// ─── RUTA BD + SESIÓN ────────────────────────────────────────────────────────
$DB_PATH = __DIR__ . '/data/stats.db';
session_start();

// ─── SECRETOS (centro único: inc/secret.php — gitignored + .htaccess) ───────
$__secretos = [];
if (file_exists(__DIR__ . '/inc/secret.php')) {
    $__secretos = require __DIR__ . '/inc/secret.php';
}

// ─── CONEXIÓN BD (temprana: la pass del panel puede vivir cifrada en config) ─
$db = null;
if (file_exists($DB_PATH)) {
    try {
        $db = new SQLite3($DB_PATH);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');
    } catch (\Exception $e) {
        $db = null;
    }
}

// ─── PASS DEL PANEL (editable desde la UI) ───────────────────────────────────
// 1) BD config['auth_dashboard'] cifrada FP1: (fuente principal desde 2026-08-25).
// 2) Fallback: inc/secret.php['auth_dashboard'] (transición / si aún no hay BD).
require_once __DIR__ . '/inc/crypto.php';
$__authFromDb = '';
if ($db) {
    try {
        $__authFromDb = (string)$db->querySingle("SELECT valor FROM config WHERE clave='auth_dashboard'");
        if ($__authFromDb !== '') {
            $__authFromDb = futprotec_descifrarPassword($__authFromDb);
        }
    } catch (\Exception $e) {
        $__authFromDb = '';
    }
}
define('AUTH_KEY', $__authFromDb !== '' ? $__authFromDb : (string)($__secretos['auth_dashboard'] ?? ''));

// Email de recuperación configurado (editable desde la UI, tabla config).
$RESET_EMAIL = '';
if ($db) {
    try {
        $RESET_EMAIL = (string)$db->querySingle("SELECT valor FROM config WHERE clave='reset_email'");
    } catch (\Exception $e) {
        $RESET_EMAIL = '';
    }
}

// ─── ANTI-CACHÉ (SOLO ENTORNO LOCAL/DEV) ────────────────────────────────────
// Fuerza que el navegador SIEMPRE re-solicite el HTML y los assets (app.js,
// css, etc.) en cada carga SOLO en desarrollo. Sin esto, una versión cacheada
// antigua de app.js (sin rsSyncing) provoca "Alpine Expression Error:
// rsSyncing is not defined" en el tab Respuestas.
// En producción (SiteGround) NO se aplican cabeceras no-store para no penalizar
// la caché condicional (ETag/Last-Modified); la frescura de app.js se garantiza
// con cache-busting por filemtime() en la carga del script.
$__esLocal = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
if ($__esLocal) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}



// ─── LOGIN / LOGOUT ──────────────────────────────────────────────────────────
if (isset($_POST['password'])) {
    if ($_POST['password'] === AUTH_KEY) {
        $_SESSION['auth_outbound'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $loginError = 'Contrasena incorrecta';
}
if (isset($_GET['logout'])) {
    unset($_SESSION['auth_outbound']);
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
if (!$db) {
    echo '<div class="max-w-lg mx-auto mt-20 p-8 text-center font-sans">
        <h2 class="text-xl font-bold text-red-400">stats.db no encontrada</h2>
        <p class="text-slate-400 mt-2">Ejecuta: <code class="bg-slate-800 px-2 py-1 rounded text-amber-400">php init_db.php</code></p>
        </div>';
    exit;
}

require_once __DIR__ . '/inc/eligibilidad.php';
require_once __DIR__ . '/inc/metricas.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/imap_respuestas.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════ RECUPERACIÓN DE CONTRASEÑA (público, sin sesión) ════════════
// 1) POST action=request_reset → genera token (exp 30 min) y envía email.
// 2) GET ?reset=TOKEN → página HTML para fijar nueva contraseña.
// 3) POST action=reset_password → valida token y actualiza la pass cifrada.
if ($action === 'request_reset') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$db) { echo json_encode(['ok' => false, 'error' => 'BD no disponible']); exit; }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // Respuesta genérica para no revelar si el email existe en el sistema.
    $respuestaGenerica = ['ok' => true, 'message' => 'Si el email es correcto, recibirás un enlace de recuperación.'];
    if ($email === '' || strtolower($RESET_EMAIL) !== $email) {
        echo json_encode($respuestaGenerica);
        exit;
    }
    // Token de un solo uso + expiración 30 min.
    $token = bin2hex(random_bytes(32));
    $exp   = (string)(time() + 1800);
    $stmtT = $db->prepare("INSERT INTO config (clave, valor) VALUES ('reset_token', :t) ON CONFLICT(clave) DO UPDATE SET valor = :t");
    $stmtT->bindValue(':t', $token, SQLITE3_TEXT); $stmtT->execute();
    $stmtE = $db->prepare("INSERT INTO config (clave, valor) VALUES ('reset_token_exp', :e) ON CONFLICT(clave) DO UPDATE SET valor = :e");
    $stmtE->bindValue(':e', $exp, SQLITE3_TEXT); $stmtE->execute();
    // Enviar email con el enlace (usa la cuenta SMTP activa de la BD).
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
        . '/dashboard.php?reset=' . rawurlencode($token);
    @enviarEmailRecuperacion($db, $RESET_EMAIL, $baseUrl); // fallo de SMTP no se revela
    echo json_encode($respuestaGenerica);
    exit;
}

// Página de reset (GET ?reset=TOKEN): muestra el formulario si el token es válido.
if (isset($_GET['reset'])) {
    $tokenReset = trim((string)$_GET['reset']);
    $tokenValido = false;
    if ($db && $tokenReset !== '') {
        $savedTok = (string)$db->querySingle("SELECT valor FROM config WHERE clave='reset_token'");
        $savedExp = (int)$db->querySingle("SELECT valor FROM config WHERE clave='reset_token_exp'");
        $tokenValido = ($savedTok !== '' && hash_equals($savedTok, $tokenReset) && time() <= $savedExp);
    }
    if ($db) { $db->close(); }
    mostrarPaginaReset($tokenValido, $tokenReset);
    exit;
}

if ($action === 'reset_password') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$db) { echo json_encode(['ok' => false, 'error' => 'BD no disponible']); exit; }
    $token   = trim((string)($_POST['reset_token'] ?? ''));
    $nueva   = (string)($_POST['new_password'] ?? '');
    $confirma = (string)($_POST['confirm_password'] ?? '');
    $savedTok = (string)$db->querySingle("SELECT valor FROM config WHERE clave='reset_token'");
    $savedExp = (int)$db->querySingle("SELECT valor FROM config WHERE clave='reset_token_exp'");
    if ($savedTok === '' || !hash_equals($savedTok, $token) || time() > $savedExp) {
        echo json_encode(['ok' => false, 'error' => 'Enlace inválido o expirado. Solicita uno nuevo.']);
        exit;
    }
    if (strlen($nueva) < 8) {
        echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres.']);
        exit;
    }
    if ($nueva !== $confirma) {
        echo json_encode(['ok' => false, 'error' => 'Las contraseñas no coinciden.']);
        exit;
    }
    $cifrada = futprotec_cifrarPassword($nueva);
    $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES ('auth_dashboard', :v) ON CONFLICT(clave) DO UPDATE SET valor = :v");
    $stmt->bindValue(':v', $cifrada, SQLITE3_TEXT); $stmt->execute();
    // Token de un solo uso: eliminar tras usarlo.
    $db->exec("DELETE FROM config WHERE clave IN ('reset_token','reset_token_exp')");
    echo json_encode(['ok' => true, 'message' => 'Contraseña actualizada. Ya puedes acceder al panel.']);
    exit;
}

// ─── SINCRONIZACIÓN IMAP LIGERA AL CARGAR ────────────────────────────────────
// Al acceder al dashboard se sincronizan las respuestas IMAP en MODO LIGERO
// (solo remitentes, sin descargar el contenido de los emails). Esto actualiza
// `estado_lead` de los leads que han respondido y los mueve a la columna
// "03 En Conversación" del Kanban, de modo que el panel muestra de forma
// limpia qué remitentes han respondido sin necesidad de recargar manualmente.
// Se ejecuta SOLO en render HTML (no en endpoints AJAX) y SOLO si hay sesión
// autenticada, para no penalizar las llamadas AJAX ni las peticiones no
// autenticadas. Se envuelve en try/catch para que un fallo de IMAP nunca
// rompa la carga del dashboard.
if (empty($action) && !empty($_SESSION['auth_outbound'])) {
    try {
        imap_procesar_todas_cuentas_ligero($db);
    } catch (\Throwable $e) {
        // Silencioso: la sincronización IMAP no debe romper la carga del panel.
    }
}



// ═══════════════ AUTENTICACIÓN PARA ENDPOINTS AJAX ════════════════════════════
// Todos los endpoints AJAX requieren autenticación previa
if (!empty($action) && empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// ═══════════════ GESTIÓN DE CREDENCIALES DEL PANEL (autenticado) ══════════════
// change_password: cambia la contraseña del panel (cifrada FP1: en config).
if ($action === 'change_password') {
    header('Content-Type: application/json; charset=utf-8');
    $actual   = (string)($_POST['password_actual'] ?? '');
    $nueva    = (string)($_POST['password_nueva'] ?? '');
    $confirma = (string)($_POST['password_confirmar'] ?? '');
    if (!hash_equals((string)AUTH_KEY, $actual)) {
        echo json_encode(['ok' => false, 'error' => 'La contraseña actual no es correcta.']);
        exit;
    }
    if (strlen($nueva) < 8) {
        echo json_encode(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres.']);
        exit;
    }
    if ($nueva !== $confirma) {
        echo json_encode(['ok' => false, 'error' => 'Las contraseñas no coinciden.']);
        exit;
    }
    $cifrada = futprotec_cifrarPassword($nueva);
    $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES ('auth_dashboard', :v) ON CONFLICT(clave) DO UPDATE SET valor = :v");
    $stmt->bindValue(':v', $cifrada, SQLITE3_TEXT); $stmt->execute();
    echo json_encode(['ok' => true, 'message' => 'Contraseña actualizada correctamente.']);
    exit;
}

// update_reset_email: cambia el email de recuperación (contacto de reseteo).
if ($action === 'update_reset_email') {
    header('Content-Type: application/json; charset=utf-8');
    $email = strtolower(trim((string)($_POST['reset_email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Email de recuperación inválido.']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES ('reset_email', :v) ON CONFLICT(clave) DO UPDATE SET valor = :v");
    $stmt->bindValue(':v', $email, SQLITE3_TEXT); $stmt->execute();
    echo json_encode(['ok' => true, 'message' => 'Email de recuperación actualizado.']);
    exit;
}

// ═══════════════ ENDPOINTS AJAX (delegados a api/*.php) ═══════════════════════
// Cada archivo api/*.php define los handlers de su dominio. Si $action coincide,
// el handler responde JSON y hace exit; si no, el flujo continúa al render HTML.
// NOTA: api/leads.php es un endpoint STANDALONE (con su propio bootstrap y exit)
// y se invoca directamente desde app.js como 'api/leads.php?action=...'. Por eso
// NO se incluye aquí. Los handlers get_lead/update_lead que app.js llama a
// '?action=...' se definen internamente más abajo.
require __DIR__ . '/api/blacklist.php';
require __DIR__ . '/api/mockups.php';
require __DIR__ . '/api/presupuestos.php';
require __DIR__ . '/api/plantillas.php';
require __DIR__ . '/api/analytics.php';
require __DIR__ . '/api/config.php';
require __DIR__ . '/api/pruebas.php';

// ─── Funciones puras de dashboard ────────────────────────────────────────────
// Refactor: se extrae la lógica de negocio de los handlers AJAX a funciones
// puras para que sean testables de forma aislada y los handlers queden como
// orquestadores delgados.

/**
 * getLeadDetalle — Obtiene un lead con sus contadores de envíos/aperturas y
 * su último mockup y presupuesto. Devuelve null si no existe.
 */
function getLeadDetalle($db, int $id): ?array {
    $row = $db->querySingle("
        SELECT c.*,
               (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS total_envios,
               (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS total_aperturas
        FROM clubes_crm c WHERE c.id = {$id}", true);
    if ($row) {
        $mockup = $db->querySingle("SELECT * FROM mockups WHERE lead_id = {$id} ORDER BY id DESC LIMIT 1", true);
        $row['mockup'] = $mockup ?: null;
        $presupuesto = $db->querySingle("SELECT * FROM presupuestos WHERE lead_id = {$id} ORDER BY version DESC LIMIT 1", true);
        $row['presupuesto'] = $presupuesto ?: null;
    }
    return $row ?: null;
}

/**
 * CAMPOS_EDITABLES_LEAD — Lista blanca de campos editables desde el Kanban.
 */
const CAMPOS_EDITABLES_LEAD = [
    'estado_lead', 'persona_contacto', 'cargo_contacto',
    'telefono_movil', 'telefono_fijo', 'tiene_whatsapp', 'observaciones',
    'federacion', 'volumen_estimado', 'num_jugadores', 'categorias',
    'fecha_decision_prevista', 'objeciones', 'proxima_accion',
    'canal_interaccion', 'motivo_perdida',
];

/**
 * esOptOutReal — Determina si un lead tiene una BAJA REAL del destinatario
 * (opt-out por email) que impide su reactivación desde el Kanban.
 */
function esOptOutReal($db, int $id): bool {
    $estadoAnterior = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$id}");
    $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
    if (!in_array($estadoAnterior, $estadosSupresion, true)) {
        return false;
    }
    $obsLead = (string)$db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$id}");
    return (bool)preg_match('/\[BAJA\][^\n]*fuente\s*=\s*email/i', $obsLead);
}

/**
 * updateLeadCampo — Actualiza un campo editable de un lead. Devuelve un array
 * con 'ok' y, en caso de error, 'error'/'razon'. Maneja la lógica especial de
 * observaciones (merge con timestamp), estado_lead (protección opt-out real +
 * log) y tiene_whatsapp (normalización a '1'/'0').
 */
function updateLeadCampo($db, int $id, string $field, string $value): array {
    if ($id <= 0 || !in_array($field, CAMPOS_EDITABLES_LEAD, true)) {
        return ['ok' => false];
    }
    if ($field === 'tiene_whatsapp') {
        $value = $value ? '1' : '0';
    }
    if ($field === 'observaciones') {
        $existing = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$id}");
        $ts = date('d/m H:i');
        $merged = $existing ? $existing . "\n[{$ts}] {$value}" : "[{$ts}] {$value}";
        $stmt = $db->prepare("UPDATE clubes_crm SET observaciones = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':val', $merged, SQLITE3_TEXT);
    } elseif ($field === 'estado_lead') {
        $estadoAnterior = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$id}");
        $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
        if (esOptOutReal($db, $id) && !in_array($value, $estadosSupresion, true)) {
            return [
                'ok'    => false,
                'error' => 'Este lead tiene una BAJA REAL del destinatario (opt-out). No puede reactivarse desde el Kanban. Usa la gestión de Lista Negra con confirmación explícita.',
                'razon' => 'OPTOUT_REAL_PROTEGIDO'
            ];
        }
        $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':val', $value, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $stmtLog = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
             VALUES (:lid, :cid, 'cambio_estado', :det, CURRENT_TIMESTAMP)"
        );
        $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
        $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
        $detalle = "Estado cambiado de '{$estadoAnterior}' a '{$value}'";
        $stmtLog->bindValue(':det', $detalle, SQLITE3_TEXT);
        $stmtLog->execute();
        return ['ok' => true];
    } else {
        $stmt = $db->prepare("UPDATE clubes_crm SET {$field} = :val WHERE id = :id");
        $stmt->bindValue(':val', $value, SQLITE3_TEXT);
    }
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    return ['ok' => true];
}

/**
 * ESTADOS_UNIBOX_PERMITIDOS — Vocabulario de intención del visor Unibox.
 * Se traduce al estado canónico del Kanban (7 columnas) mediante
 * mapearEstadoUnibox(). Mantener sincronizado con el desplegable del tab
 * Respuestas (app.js rsEstadosLead / respuestas.php).
 */
const ESTADOS_UNIBOX_PERMITIDOS = ['Interesado', 'Duda Precio', 'Baja', 'Neutral', 'No Interesa', 'Pendiente'];

/**
 * mapearEstadoUnibox — Traduce el vocabulario de intención del Unibox al
 * estado canónico del Kanban (7 columnas).
 *   Interesado / Duda Precio / Neutral → 03 En Conversación
 *   Pendiente                          → 02 Contactado
 *   No Interesa                        → 06 Perdido (mala venta manual)
 *   Baja                               → 07 Baja (baja automática de campaña)
 */
function mapearEstadoUnibox(string $estado): string {
    $mapa = [
        'Interesado'  => '03 En Conversación',
        'Duda Precio' => '03 En Conversación',
        'Neutral'     => '03 En Conversación',
        'Pendiente'   => '02 Contactado',
        'No Interesa' => '06 Perdido',
        'Baja'        => '07 Baja',
    ];
    return $mapa[$estado] ?? $estado;
}

/**
 * actualizarEstadoLeadUnibox — Actualiza el estado de un lead desde el visor
 * Unibox. Acepta el vocabulario de intención (Interesado, Duda Precio, Baja,
 * Neutral, No Interesa, Pendiente) y lo traduce al estado canónico del Kanban.
 */
function actualizarEstadoLeadUnibox($db, int $id, string $estado): array {
    if ($id <= 0 || $estado === '') {
        return ['ok' => false, 'error' => 'Parámetros inválidos'];
    }
    if (!in_array($estado, ESTADOS_UNIBOX_PERMITIDOS, true)) {
        return ['ok' => false, 'error' => 'Estado no permitido'];
    }
    $estadoCanonico = mapearEstadoUnibox($estado);
    $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = :estado WHERE id = :id");
    $stmt->bindValue(':estado', $estadoCanonico, SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    return ['ok' => true];
}


/**
 * enviarRespuestaSmtpLead — Envía una respuesta SMTP al lead usando una cuenta
 * activa con rotación y límite diario, y registra el envío en `envios`.
 * Devuelve ['ok'=>true,'tracking_id'=>...] o ['ok'=>false,'error'=>...].
 */
function enviarRespuestaSmtpLead($db, int $leadId, string $email, string $cuerpo, string $asunto): array {
    if ($email === '' || $cuerpo === '') {
        return ['ok' => false, 'error' => 'Faltan destinatario o cuerpo del mensaje'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Email de destino inválido'];
    }

    require_once __DIR__ . '/inc/smtp_transport.php';

    // Seleccionar cuenta SMTP activa con rotación y límite diario.
    $cuenta = $db->querySingle(
        "SELECT * FROM cuentas_smtp WHERE activa = 1 AND enviados_hoy < limite_diario ORDER BY RANDOM() LIMIT 1",
        true
    );
    if (!$cuenta) {
        return ['ok' => false, 'error' => 'No hay cuentas SMTP activas disponibles'];
    }

    $cuentaNormalizada = [
        'email'         => $cuenta['email'],
        'host'          => $cuenta['smtp'],
        'puerto'        => (int)$cuenta['puerto'],
        'usuario'       => $cuenta['user'],
        'password'      => $cuenta['pass'],
        'seguridad'     => ((int)$cuenta['puerto'] === 465) ? 'ssl' : 'tls',
        'nombre_emisor' => $cuenta['nombre'] ?? $cuenta['email'],
    ];

    // Cuerpo en HTML simple (párrafos) para el envío.
    $cuerpoHtml = '<div style="font-family:sans-serif;font-size:14px;line-height:1.6;color:#1e293b;">'
        . nl2br(htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8'))
        . '</div>';

    $resultado = futprotec_enviarSMTP(
        $cuentaNormalizada,
        $email,
        $asunto,
        $cuerpoHtml,
        ['reply_to' => $cuenta['email']]
    );

    if (!$resultado['ok']) {
        return ['ok' => false, 'error' => $resultado['error'] ?? 'Error al enviar'];
    }

    // Incrementar contador de la cuenta.
    $db->exec("UPDATE cuentas_smtp SET enviados_hoy = enviados_hoy + 1, ultimo_uso = CURRENT_TIMESTAMP WHERE id = " . (int)$cuenta['id']);

    // Registrar en envios para trazabilidad (usa lead_id para vincular la respuesta).
    $trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));
    $stmt = $db->prepare(
        'INSERT INTO envios (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje, lead_id)
         VALUES (:club, :email, :fed, :cuenta, :estado, :tid, :asunto, :cuerpo, :lead_id)'
    );
    $stmt->bindValue(':club',   $leadId > 0 ? ('Lead #' . $leadId) : $email, SQLITE3_TEXT);
    $stmt->bindValue(':email',  $email, SQLITE3_TEXT);
    $stmt->bindValue(':fed',    '', SQLITE3_TEXT);
    $stmt->bindValue(':cuenta', $cuenta['email'], SQLITE3_TEXT);
    $stmt->bindValue(':estado', 'enviado', SQLITE3_TEXT);
    $stmt->bindValue(':tid',    $trackingId, SQLITE3_TEXT);
    $stmt->bindValue(':asunto', $asunto, SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo', $cuerpoHtml, SQLITE3_TEXT);
    $stmt->bindValue(':lead_id', $leadId > 0 ? $leadId : null, SQLITE3_INTEGER);
    $stmt->execute();

    return ['ok' => true, 'tracking_id' => $trackingId];
}

// ─── ENDPOINT: get_lead (llamado por app.js como ?action=get_lead) ───────────
if ($action === 'get_lead') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    echo json_encode(getLeadDetalle($db, $id));
    exit;
}

// ─── ENDPOINT: update_lead (llamado por app.js como ?action=update_lead) ─────
if ($action === 'update_lead') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        echo json_encode(updateLeadCampo($db, $id, $field, $value));
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── ENDPOINT: actualizar_estado_lead (Unibox — panel derecho) ───────────────
// Actualiza clubes_crm.estado_lead en tiempo real desde el desplegable del visor.
if ($action === 'actualizar_estado_lead') {
    header('Content-Type: application/json');
    $id     = (int)($_POST['lead_id'] ?? 0);
    $estado = trim((string)($_POST['estado'] ?? ''));
    try {
        echo json_encode(actualizarEstadoLeadUnibox($db, $id, $estado));
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── ENDPOINT: enviar_respuesta_smtp (Unibox — respuesta inmediata) ──────────
// Envía una respuesta SMTP al lead usando una cuenta activa de cuentas_smtp.
if ($action === 'enviar_respuesta_smtp') {
    header('Content-Type: application/json');
    $leadId  = (int)($_POST['lead_id'] ?? 0);
    $email   = trim((string)($_POST['email'] ?? ''));
    $cuerpo  = trim((string)($_POST['cuerpo'] ?? ''));
    $asunto  = trim((string)($_POST['asunto'] ?? 'Re: FutProtec'));
    try {
        echo json_encode(enviarRespuestaSmtpLead($db, $leadId, $email, $cuerpo, $asunto));
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// ─── AUTENTICACIÓN ───────────────────────────────────────────────────────────
if (empty($_SESSION['auth_outbound'])) {

    $db->close();
    showLoginForm($loginError ?? '');
    exit;
}

// ─── ENDPOINT: sync_respuestas (botón "Actualizar" del tab Respuestas) ───────
// Dispara la sincronización IMAP/POP3 de forma segura. El frontend llama a
// ?action=sync_respuestas (autenticado por sesión) y aquí se invoca el runner
// api/imap_sync.php por HTTP interno con el token compartido, sin exponerlo
// al navegador. Devuelve un resumen JSON para que app.js lo muestre.
if ($action === 'sync_respuestas') {
    header('Content-Type: application/json; charset=utf-8');

    // Mismo token que api/imap_sync.php (getenv con fallback idéntico).
    $secret = getenv('IMAP_CRON_SECRET') ?: 'IMAP_RESPUESTAS_CRON_20260820';

    $runnerUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
        . '/api/imap_sync.php?token=' . rawurlencode($secret) . '&apply=1';

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $salida = @file_get_contents($runnerUrl, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $httpCode = (int)$m[1];
    }

    if ($salida === false || $httpCode >= 400) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo ejecutar la sincronización (HTTP ' . $httpCode . ').']);
        exit;
    }

    // Extraer resumen de las líneas de log del runner para mostrarlo en la UI.
    $resumen = [];
    foreach (explode("\n", $salida) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || strpos($linea, '[') !== 0) {
            continue;
        }
        if (preg_match('/Insertados:\s*(\d+)/', $linea, $m)) {
            $resumen[] = 'Insertados: ' . $m[1];
        }
        if (preg_match('/Duplicados:\s*(\d+)/', $linea, $m)) {
            $resumen[] = 'Duplicados: ' . $m[1];
        }
        if (preg_match('/Errores:\s*(\d+)/', $linea, $m)) {
            $resumen[] = 'Errores: ' . $m[1];
        }
        if (preg_match('/Mensajes procesados:\s*(\d+)/', $linea, $m)) {
            $resumen[] = 'Mensajes: ' . $m[1];
        }
    }

    echo json_encode(['ok' => true, 'resumen' => $resumen]);
    exit;
}


// ═══════════════ CARGAR DATOS PARA EL DASHBOARD ══════════════════════════════
$config = [];
$resCfg = $db->query("SELECT clave, valor FROM config");
while ($r = $resCfg->fetchArray(SQLITE3_ASSOC)) {
    $config[$r['clave']] = $r['valor'];
}
$motorActivo  = ($config['motor_estado'] ?? 'pausado') === 'activo';
$modoPruebas  = ($config['modo_entorno'] ?? 'test') === 'test';

// KPIs — Históricos y Globales (solo envíos REALES; los TEST no alteran métricas comerciales)
$totalLeads      = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");
$totalEnviados   = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado IN ('enviado', 'abierto')" . sqlFiltroComercial('e'));
$totalAperturas  = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
$tasaApertura    = $totalEnviados > 0 ? round(($totalAperturas / $totalEnviados) * 100, 1) : 0;
$totalRebotes    = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
$tasaRebote      = $totalEnviados > 0 ? round(($totalRebotes / $totalEnviados) * 100, 1) : 0;
$totalBajas      = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out', 'Unsubscribed', 'Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
$smtpActivas     = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp WHERE activa = 1");
$smtpEnviadosHoy = (int)$db->querySingle("SELECT COALESCE(SUM(enviados_hoy), 0) FROM cuentas_smtp");


// Estados Kanban — V5.1 (7 columnas unificadas al flujo de espinilleras)
// 01 Sin Contactar → 02 Contactado → 03 En Conversación → 04 Propuesta
// → 05 Ganado | 06 Perdido (malas ventas manuales) | 07 Baja (bajas automáticas de campaña)
$estadosKanban = [
    '01 Sin Contactar', '02 Contactado', '03 En Conversación',
    '04 Propuesta', '05 Ganado', '06 Perdido', '07 Baja'
];
$colClasses = [
    '01 Sin Contactar'   => 'border-slate-500',
    '02 Contactado'      => 'border-blue-500',
    '03 En Conversación' => 'border-cyan-500',
    '04 Propuesta'       => 'border-indigo-500',
    '05 Ganado'          => 'border-emerald-500',
    '06 Perdido'         => 'border-rose-500',
    '07 Baja'            => 'border-amber-500',
];


// Datos Kanban — V4.4: agregación de aperturas única (LEFT JOIN) en lugar de
// subconsulta correlacionada por fila (N+1). Se calcula UNA sola vez y se
// reutiliza para los 9 estados + contadores de chips (sin consultas extra).
$kanbanData = [];
$chipCounters = [
    'calientes' => 0,          // leads con >= 2 aperturas (re-impacto prioritario)
    'pendiente_wa' => 0,       // leads con WhatsApp y sin contactar/contactado
    'leidos' => 0,             // leads con >= 1 apertura (num_opens >= 1)
    'federaciones' => [],      // contador por federación
];
$kanbanLeads = [];             // array plano para filtros en cliente (Alpine)

// Agregación única de aperturas por email (solo envíos REALES, es_test=0).
$stmtAgg = $db->query("
    SELECT LOWER(e.email) AS email, COUNT(*) AS num_opens
    FROM aperturas a
    JOIN envios e ON a.tracking_id = e.tracking_id
    WHERE COALESCE(e.es_test,0) = 0
    GROUP BY LOWER(e.email)
");
$aperturasPorEmail = [];
while ($rowAgg = $stmtAgg->fetchArray(SQLITE3_ASSOC)) {
    $aperturasPorEmail[$rowAgg['email']] = (int)$rowAgg['num_opens'];
}

foreach ($estadosKanban as $est) {
    $stmt = $db->prepare("
        SELECT c.*,
               (SELECT r.clasificacion
                FROM respuestas r
                WHERE r.lead_id = c.id
                  AND r.clasificacion IS NOT NULL
                  AND r.clasificacion != ''
                ORDER BY r.fecha_respuesta DESC, r.id DESC
                LIMIT 1) AS clasificacion_ia
        FROM clubes_crm c
        WHERE c.estado_lead = :estado
        ORDER BY c.nombre_club ASC
    ");
    $stmt->bindValue(':estado', $est, SQLITE3_TEXT);
    $res = $stmt->execute();
    $cards = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        // Asignar num_opens desde la agregación única (0 si no hay aperturas).
        $row['num_opens'] = $aperturasPorEmail[strtolower($row['email'])] ?? 0;
        // Clasificación IA de la última respuesta ('' si no hay).
        $row['clasificacion_ia'] = (string)($row['clasificacion_ia'] ?? '');


        // ── Contadores de chips (sin consultas SQL extra) ──
        if ($row['num_opens'] >= 1) {
            $chipCounters['leidos']++;
        }
        if ($row['num_opens'] >= 2) {
            $chipCounters['calientes']++;
        }
        $estadoLead = (string)($row['estado_lead'] ?? '');
        if ((int)($row['tiene_whatsapp'] ?? 0) === 1
            && in_array($estadoLead, ['01 Sin Contactar', '02 Contactado'], true)) {
            $chipCounters['pendiente_wa']++;
        }
        $fed = (string)($row['federacion'] ?? '');
        if ($fed !== '') {
            $chipCounters['federaciones'][$fed] = ($chipCounters['federaciones'][$fed] ?? 0) + 1;
        }

        // ── Datos planos para filtros en cliente (Alpine) ──
        $kanbanLeads[] = [
            'id'             => (int)$row['id'],
            'nombre_club'    => (string)$row['nombre_club'],
            'federacion'     => $fed,
            'estado_lead'    => $estadoLead,
            'tiene_whatsapp' => (int)($row['tiene_whatsapp'] ?? 0),
            'num_opens'      => $row['num_opens'],
            'telefono_movil' => (string)($row['telefono_movil'] ?? ''),
            'es_duplicado'   => (int)($row['es_duplicado'] ?? 0),
        ];

        $cards[] = $row;
    }
    $kanbanData[$est] = $cards;
}


// Datos para dropdowns
$clubesList = [];
$resClubes = $db->query("SELECT id, nombre_club, persona_contacto FROM clubes_crm ORDER BY nombre_club ASC LIMIT 1000");
while ($r = $resClubes->fetchArray(SQLITE3_ASSOC)) {
    $clubesList[] = $r;
}

$federaciones = [
    'Real Federación Andaluza de Fútbol',
    'Federación Aragonesa de Fútbol',
    'Real Federación de Fútbol del Principado de Asturias',
    'Federació de Futbol de les Illes Balears',
    'Federación Canaria de Fútbol',
    'Federación Cántabra de Fútbol',
    'Federación de Fútbol de Castilla-La Mancha',
    'Real Federación de Castilla y León de Fútbol',
    'Federació Catalana de Futbol',
    'Federación de Fútbol de Ceuta',
    'Federación Extremeña de Fútbol',
    'Real Federación Galega de Fútbol',
    'Real Federación de Fútbol de Madrid',
    'Federación Melillense de Fútbol',
    'Federación de Fútbol de la Región de Murcia',
    'Federación Navarra de Fútbol',
    'Federación Vasca de Fútbol',
    'Federació de Futbol de la Comunitat Valenciana',
    'Federación Riojana de Fútbol',
];

$federacionesSelect = $federaciones;

$db->close();

// ═══════════════ RENDER HTML ════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="es" class="dark">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FutProtec — Outbound CRM</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23f59e0b'/><text x='16' y='23' font-size='22' text-anchor='middle' fill='%230a0f1a' font-family='sans-serif' font-weight='bold'>FP</text></svg>">
    <link rel="stylesheet" href="css/tailwind.min.css">
    <!-- FIX SCOPE rsSyncing: app.js se carga con defer ANTES de Alpine.js.
         Así app.js se ejecuta primero (tras el parseo), registra el listener
         'alpine:init', y cuando Alpine.js se ejecuta registra 'app' como
         componente ANTES de procesar el DOM. Esto elimina la condición de
         carrera que provocaba "Alpine Expression Error: rsSyncing is not
         defined" en el tab Respuestas. -->
    <script defer src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
    <script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        [x-cloak] { display: none !important; }
        .kanban-scroll { scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
        .kanban-scroll > * { scroll-snap-align: start; }
        @media (min-width: 768px) { .kanban-scroll { scroll-snap-type: none; } }
        /* Cuerpo de mensaje en texto plano (respuestas entrantes) */
        .mensaje-cuerpo-texto {
            white-space: pre-wrap;
            font-family: sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #e2e8f0;
            background: #1e293b;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            word-break: break-word;
        }
        /* Cuerpo de mensaje en HTML (respuestas entrantes) */
        .mensaje-cuerpo-html {
            margin-top: 10px;
            padding: 15px;
            border-radius: 8px;
            background: #1e293b;
            color: #e2e8f0;
            font-size: 14px;
            line-height: 1.5;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        .mensaje-cuerpo-html img { max-width: 100%; height: auto; }
        .mensaje-cuerpo-html a { color: #38bdf8; }
        .mensaje-cuerpo-html table { max-width: 100%; }

        /* ═══════════════════════════════════════════════════════════════════
           SISTEMA DE DISEÑO GLOBAL (UI TOKENS)
           Fuente única de verdad de componentes. CUALQUIER vista, tabla,
           formulario o modal DEBE usar estas clases. No usar estilos inline
           ni clases ad-hoc que rompan la jerarquía.
           ═══════════════════════════════════════════════════════════════════ */

        /* ── Tarjetas / Contenedores ─────────────────────────────────────── */
        .ui-card { background: #0f172a; border: 1px solid #1e293b; border-radius: 0.75rem; padding: 1.25rem; }
        .ui-card-sub { background: rgba(30,41,59,0.3); border: 1px solid rgba(51,65,85,0.5); border-radius: 0.75rem; padding: 1rem; }
        .ui-card-inner { background: rgba(30,41,59,0.5); border: 1px solid #334155; border-radius: 0.5rem; padding: 0.75rem; }

        /* ── Cabeceras de sección ────────────────────────────────────────── */
        .ui-section-title { font-size: 1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #e2e8f0; }
        .ui-section-sub { font-size: 0.75rem; color: #94a3b8; }
        .ui-title-icon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }

        /* ── Labels ──────────────────────────────────────────────────────── */
        .ui-label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }

        /* ── Inputs / Selects / Textareas ────────────────────────────────── */
        .ui-input { width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.875rem; color: #e2e8f0; outline: none; }
        .ui-input:focus { border-color: rgba(245,158,11,0.5); }
        .ui-input-sm { width: 100%; background: #1e293b; border: 1px solid #334155; border-radius: 0.5rem; padding: 0.375rem 0.75rem; font-size: 0.75rem; color: #e2e8f0; outline: none; }
        .ui-input-sm:focus { border-color: rgba(245,158,11,0.5); }
        .ui-input-readonly { background: rgba(30,41,59,0.5); color: #94a3b8; cursor: not-allowed; }

        /* ── Botones ─────────────────────────────────────────────────────── */
        .ui-btn { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; transition: all 0.15s ease; border: 1px solid transparent; }
        .ui-btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .ui-btn-primary { background: rgba(59,130,246,0.2); color: #60a5fa; border-color: rgba(59,130,246,0.3); }
        .ui-btn-primary:hover { background: rgba(59,130,246,0.3); }
        .ui-btn-danger { background: rgba(244,63,94,0.2); color: #fb7185; border-color: rgba(244,63,94,0.3); }
        .ui-btn-danger:hover { background: rgba(244,63,94,0.3); }
        .ui-btn-success { background: rgba(16,185,129,0.2); color: #34d399; border-color: rgba(16,185,129,0.3); }
        .ui-btn-success:hover { background: rgba(16,185,129,0.3); }
        .ui-btn-warning { background: rgba(245,158,11,0.2); color: #fbbf24; border-color: rgba(245,158,11,0.3); }
        .ui-btn-warning:hover { background: rgba(245,158,11,0.3); }
        .ui-btn-ghost { background: #1e293b; border-color: #334155; color: #94a3b8; }
        .ui-btn-ghost:hover { background: #334155; color: #e2e8f0; }

        /* ── Tablas ──────────────────────────────────────────────────────── */
        .ui-table { width: 100%; font-size: 0.875rem; }
        .ui-thead { background: rgba(30,41,59,0.5); color: #cbd5e1; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .ui-th { padding: 0.5rem 0.75rem; text-align: left; font-weight: 600; }
        .ui-td { padding: 0.5rem 0.75rem; }
        .ui-tr { border-bottom: 1px solid rgba(30,41,59,0.5); transition: background 0.15s ease; }
        .ui-tr:hover { background: rgba(30,41,59,0.3); }

        /* ── Modales / Diálogos ──────────────────────────────────────────── */
        .ui-modal-overlay { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6); }
        .ui-modal { background: #0f172a; border: 1px solid #334155; border-radius: 1rem; width: 100%; margin: 1rem; }
        .ui-modal-header { padding: 0.75rem 1.25rem; border-bottom: 1px solid #1e293b; display: flex; align-items: center; justify-content: space-between; }
        .ui-modal-title { font-size: 0.875rem; font-weight: 700; color: #e2e8f0; }
        .ui-modal-body { padding: 1.25rem; }
        .ui-modal-close { color: #94a3b8; transition: color 0.15s ease; }
        .ui-modal-close:hover { color: #e2e8f0; }

        /* ── Badges / Chips / Dots de estado ─────────────────────────────── */
        .ui-badge { padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .ui-dot { width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block; }
        .ui-dot-sm { width: 0.375rem; height: 0.375rem; border-radius: 9999px; display: inline-block; }

    </style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen" x-data="app()" x-init="boot()">


<!-- ═══════════ TOPBAR ═══════════ -->
<header class="bg-slate-900 border-b border-slate-800 sticky top-0 z-50">
    <div class="max-w-full mx-auto px-4 py-2 flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-3">
            <i data-lucide="shield" class="w-5 h-5 text-amber-400"></i>
            <span class="font-bold text-slate-100 text-sm tracking-tight">FutProtec Outbound CRM</span>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <button @click="irARespuestas()" class="relative text-slate-300 hover:text-amber-400 transition p-1.5 rounded-lg hover:bg-slate-800/60" title="Respuestas nuevas">
                <i data-lucide="bell" class="w-4 h-4"></i>
                <span x-show="rsNuevas > 0" x-cloak x-text="rsNuevas" class="absolute -top-1 -right-1 bg-orange-500 text-white text-xs font-bold rounded-full min-w-[20px] h-[20px] flex items-center justify-center px-1.5 border-2 border-slate-900 shadow-[0_0_10px_rgba(249,115,22,0.5)]"></span>

            </button>
            <a href="?logout=1" class="text-slate-300 hover:text-slate-100 text-sm transition ml-2">
                <i data-lucide="log-out" class="w-4 h-4 inline"></i>
            </a>
        </div>

    </div>
</header>

<!-- ═══════════ SCORECARDS CLICKEABLES ═══════════ -->
<div class="max-w-full mx-auto px-4 py-4 grid grid-cols-2 md:grid-cols-5 gap-3">
    <div @click="tab='gestor'" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-amber-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-300 text-sm uppercase tracking-wider">Total Leads</span>
            <i data-lucide="users" class="w-4 h-4 text-slate-400"></i>
        </div>
        <div class="text-2xl font-semibold text-slate-100 mt-1"><?= number_format($totalLeads) ?></div>
        <div class="text-sm text-slate-400 mt-1">Histórico global</div>
    </div>
    <div @click="abrirAnalytics('envios')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-blue-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-300 text-sm uppercase tracking-wider">Envíos Totales</span>
            <i data-lucide="send" class="w-4 h-4 text-slate-400"></i>
        </div>
        <div class="text-2xl font-semibold text-blue-400 mt-1"><?= number_format($totalEnviados) ?></div>
        <div class="text-sm text-slate-400 mt-1">emails enviados</div>
    </div>
    <div @click="abrirAnalytics('aperturas')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-cyan-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-300 text-sm uppercase tracking-wider">Tasa Apertura</span>
            <i data-lucide="eye" class="w-4 h-4 text-slate-400"></i>
        </div>
        <div class="text-2xl font-semibold text-cyan-400 mt-1"><?= $tasaApertura ?>%</div>
        <div class="text-sm text-slate-400 mt-1"><?= $totalAperturas ?> aperturas</div>
    </div>
    <div @click="abrirAnalytics('rebotes')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-rose-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-300 text-sm uppercase tracking-wider">Tasa Rebote</span>
            <i data-lucide="alert-triangle" class="w-4 h-4 text-slate-400"></i>
        </div>
        <div class="text-2xl font-semibold mt-1 <?= $tasaRebote > 5 ? 'text-rose-400' : ($tasaRebote > 2 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= $tasaRebote ?>%</div>
        <div class="text-sm text-slate-400 mt-1"><?= $totalRebotes ?> rebotes</div>
    </div>
    <div @click="abrirAnalytics('bajas')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-amber-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-300 text-sm uppercase tracking-wider">Leads de Baja</span>
            <i data-lucide="user-minus" class="w-4 h-4 text-slate-400"></i>
        </div>
        <div class="text-2xl font-semibold mt-1 <?= $totalBajas > 0 ? 'text-amber-400' : 'text-slate-400' ?>"><?= $totalBajas ?></div>
        <div class="text-sm text-slate-400 mt-1">Opt-Out / Unsubscribed / Lista Negra</div>
    </div>
</div>

<!-- ═══════════ TABS ═══════════ -->
<div class="max-w-full mx-auto px-4">
    <nav class="flex gap-1 border-b border-slate-800 overflow-x-auto">
        <button @click="tab='kanban'"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'kanban' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Kanban CRM</button>
        <button @click="tab='gestor'"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'gestor' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Gestor de Datos</button>
        <button @click="tab='editor'; loadCategorias()"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'editor' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Editor Plantilla</button>
        <button @click="tab='smtp'"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'smtp' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Configuración</button>
        <button @click="tab='lanza'"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'lanza' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Lanzadera</button>
        <button @click="tab='analytics'"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'analytics' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Analytics</button>
        <button @click="tab='respuestas'; loadRespuestas()"

            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'respuestas' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Respuestas</button>
        <button @click="tab='lista_negra'; blCargar()"
            class="px-4 py-2.5 text-sm font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'lista_negra' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-300 hover:text-slate-100'">Lista Negra</button>
    </nav>
</div>

<!-- ═══════════ TAB CONTENTS (includes) ═══════════ -->
<div x-show="tab === 'kanban'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/kanban.php'; ?>
</div>
<div x-show="tab === 'gestor'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/gestor.php'; ?>
</div>
<div x-show="tab === 'editor'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/editor.php'; ?>
</div>
<div x-show="tab === 'smtp'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/smtp.php'; ?>
</div>
<div x-show="tab === 'lanza'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/lanzadera.php'; ?>
</div>
<div x-show="tab === 'analytics'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/analytics.php'; ?>
</div>
<div x-show="tab === 'respuestas'" x-cloak class="max-w-full mx-auto px-4 pt-4 pb-8">
    <?php include __DIR__ . '/tabs/respuestas.php'; ?>
</div>
<div x-show="tab === 'lista_negra'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/lista_negra.php'; ?>
</div>

<!-- ═══════════ MODALS ═══════════ -->

<?php
include __DIR__ . '/tabs/modals.php';
?>

<!-- ═══════════ TOAST DE NOTIFICACIÓN (FASE G) ═══════════ -->
<div x-show="rsToastVisible" x-cloak x-transition.opacity.duration.300ms
     class="fixed bottom-5 right-5 z-[100] max-w-sm w-full">
    <div class="bg-slate-900 border border-amber-500/40 rounded-xl shadow-2xl p-4 flex items-start gap-3">
        <div class="flex-1">
            <div class="text-sm font-semibold text-amber-400">Nuevas respuestas</div>
            <div class="text-sm text-slate-300 mt-1" x-text="rsToast"></div>

        </div>
        <button @click="irARespuestas()" class="shrink-0 px-3 py-1.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">Ver</button>
        <button @click="rsToastVisible=false; rsToast=''" class="shrink-0 text-slate-400 hover:text-slate-300 transition p-1" title="Cerrar">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
</div>

<!-- ALPINE.JS APP -->
<script>
window._cfg = {motorActivo:<?= $motorActivo?'true':'false' ?>,modeTest:<?= $modoPruebas?'true':'false' ?>};
// Datos para Filtros Rápidos (Chips) del Kanban — expuestos al cliente (Alpine).
// _kanbanLeads: array plano de leads para filtrar en memoria (sin AJAX).
// _chipCounters: contadores dinámicos calculados en el servidor (sin consultas extra).
window._kanbanLeads = <?= json_encode($kanbanLeads, JSON_UNESCAPED_UNICODE) ?>;
window._chipCounters = <?= json_encode($chipCounters, JSON_UNESCAPED_UNICODE) ?>;
</script>
<!-- app.js se carga con defer en el <head> (ANTES de Alpine.js) para garantizar
     que 'app' se registre como componente antes de que Alpine procese el DOM.
     No se duplica aquí para evitar doble ejecución. -->


</body>
</html>

<?php
// ═══════════════ HELPERS DE RECUPERACIÓN DE CONTRASEÑA ═══════════════════════
/**
 * Envía el email de recuperación usando la cuenta SMTP activa de la BD.
 * Devuelve true/false; el fallo NO se revela al visitante (evita enumeración).
 */
function enviarEmailRecuperacion(SQLite3 $db, string $destino, string $enlace): bool
{
    require_once __DIR__ . '/inc/smtp_transport.php';

    $cuenta = $db->querySingle("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id LIMIT 1", true);
    if (!$cuenta) {
        $cuenta = $db->querySingle("SELECT * FROM cuentas_smtp ORDER BY id LIMIT 1", true);
    }
    if (!$cuenta || empty($cuenta['email'])) {
        return false;
    }

    $pass = futprotec_descifrarPassword((string)($cuenta['password'] ?? ''));
    $c = [
        'email'          => (string)($cuenta['email'] ?? ''),
        'usuario'        => (string)($cuenta['usuario'] ?? $cuenta['email'] ?? ''),
        'password'       => $pass,
        'host'           => (string)($cuenta['host'] ?? ''),
        'puerto'         => (int)($cuenta['puerto'] ?? 465),
        'seguridad'      => ((int)($cuenta['puerto'] ?? 465) === 465) ? 'ssl' : 'tls',
        'nombre'         => 'FutProtec — Recuperación',
    ];

    $asunto = 'Recuperación de contraseña — Panel FutProtec';
    $texto  = "Hola,\n\n"
        . "Recibimos una solicitud para recuperar la contraseña del panel FutProtec.\n\n"
        . "Para establecer una nueva contraseña, abre este enlace (válido 30 minutos):\n\n"
        . $enlace . "\n\n"
        . "Si no has solicitado este cambio, ignora este mensaje.\n\n"
        . "— FutProtec";
    $html = "<p>Hola,</p><p>Recibimos una solicitud para recuperar la contraseña del panel FutProtec.</p>"
        . "<p>Para establecer una nueva contraseña, abre este enlace (válido 30 minutos):</p>"
        . "<p><a href=\"" . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . "\">Restablecer contraseña</a></p>"
        . "<p>Si no has solicitado este cambio, ignora este mensaje.</p><p>— FutProtec</p>";

    $r = futprotec_enviarSMTP($c, $destino, $asunto, $html, ['texto_plano' => $texto]);
    return (bool)($r['ok'] ?? false);
}

/**
 * Página HTML de restablecimiento de contraseña (GET ?reset=TOKEN).
 */
function mostrarPaginaReset(bool $tokenValido, string $token): void
{
    ?><!DOCTYPE html>
    <html lang="es" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FutProtec — Restablecer contraseña</title>
        <link rel="stylesheet" href="css/tailwind.min.css">
        <script src="https://unpkg.com/lucide@latest"></script>
        <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
    </head>
    <body class="bg-slate-950 min-h-screen flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 w-full max-w-sm shadow-2xl">
            <div class="text-center mb-6">
                <div class="text-2xl font-bold text-amber-400">FutProtec</div>
                <p class="text-slate-300 text-sm mt-1">Restablecer contraseña</p>
            </div>
            <?php if (!$tokenValido): ?>
                <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2 text-rose-400 text-sm text-center mb-4">
                    Enlace inválido o expirado. Solicita uno nuevo desde el panel.
                </div>
                <div class="text-center">
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'dashboard.php') ?>"
                       class="text-amber-400 hover:text-amber-300 text-sm">← Volver al acceso</a>
                </div>
            <?php else: ?>
                <form id="resetForm" onsubmit="return false;">
                    <input type="hidden" name="reset_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-4">
                        <label class="text-sm text-slate-300 uppercase tracking-wider">Nueva contraseña</label>
                        <div class="flex gap-2 mt-1">
                            <input type="password" id="new_password" name="new_password" required minlength="8" data-reset-password-input
                                class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"
                                placeholder="Mínimo 8 caracteres">
                            <button type="button" data-reset-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
                                class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-amber-400 hover:border-amber-500/40 transition">
                                <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                                <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="text-sm text-slate-300 uppercase tracking-wider">Confirmar contraseña</label>
                        <div class="flex gap-2 mt-1">
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" data-reset-password-input
                                class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                            <button type="button" data-reset-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
                                class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-amber-400 hover:border-amber-500/40 transition">
                                <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                                <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                            </button>
                        </div>
                    </div>
                    <p id="resetMsg" class="text-sm mb-4 hidden"></p>
                    <button type="submit" id="resetBtn"
                        class="w-full py-2.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">
                        Guardar nueva contraseña
                    </button>
                </form>
                <div class="text-center mt-4">
                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'dashboard.php') ?>"
                       class="text-slate-400 hover:text-amber-400 text-xs">← Volver al acceso</a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($tokenValido): ?>
        <script>
        document.getElementById('resetForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            var btn = document.getElementById('resetBtn');
            var msg = document.getElementById('resetMsg');
            btn.disabled = true;
            btn.textContent = 'Guardando...';
            var fd = new FormData();
            fd.append('action', 'reset_password');
            fd.append('reset_token', document.querySelector('[name=reset_token]').value);
            fd.append('new_password', document.getElementById('new_password').value);
            fd.append('confirm_password', document.getElementById('confirm_password').value);
            try {
                var r = await fetch('?action=reset_password', { method: 'POST', body: fd });
                var j = await r.json();
                msg.classList.remove('hidden');
                if (j.ok) {
                    msg.className = 'text-sm mb-4 text-emerald-400';
                    msg.textContent = j.message;
                    setTimeout(function () { window.location.href = 'dashboard.php'; }, 1800);
                } else {
                    msg.className = 'text-sm mb-4 text-rose-400';
                    msg.textContent = j.error || 'Error al restablecer.';
                    btn.disabled = false;
                    btn.textContent = 'Guardar nueva contraseña';
                }
            } catch (err) {
                msg.className = 'text-sm mb-4 text-rose-400';
                msg.textContent = 'Error de conexión. Inténtalo de nuevo.';
                btn.disabled = false;
                btn.textContent = 'Guardar nueva contraseña';
            }
        });
        // Toggle mostrar/ocultar de los campos de contraseña (botón ojo).
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-reset-toggle]');
            if (!btn) return;
            var input = btn.parentElement ? btn.parentElement.querySelector('input[data-reset-password-input]') : null;
            if (!input) return;
            var eye = btn.querySelector('[data-eye]');
            var eyeOff = btn.querySelector('[data-eye-off]');
            var show = (input.type === 'password');
            input.type = show ? 'text' : 'password';
            if (eye) eye.classList.toggle('hidden', show);
            if (eyeOff) eyeOff.classList.toggle('hidden', !show);
            btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
            btn.setAttribute('aria-label', btn.title);
        });
        lucide.createIcons();
        </script>
        <?php endif; ?>
    </body>
    </html><?php
}

// ═══════════════ LOGIN FORM ═══════════════════════════════════════════════
function showLoginForm(string $error = ''): void {
    ?>
    <!DOCTYPE html>
    <html lang="es" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FutProtec — Acceso Panel</title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23f59e0b'/><text x='16' y='23' font-size='22' text-anchor='middle' fill='%230a0f1a' font-family='sans-serif' font-weight='bold'>FP</text></svg>">
        <link rel="stylesheet" href="css/tailwind.min.css">
        <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
        <script src="https://unpkg.com/lucide@latest"></script>
    </head>
    <body class="bg-slate-950 min-h-screen flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 w-full max-w-sm shadow-2xl">
            <div class="text-center mb-6">
                <div class="text-2xl font-bold text-amber-400">FutProtec</div>
                <p class="text-slate-300 text-sm mt-1">Panel CRM Kanban v2.0</p>
            </div>
            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2 text-rose-400 text-sm text-center mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-4">
                    <label class="text-sm text-slate-300 uppercase tracking-wider">Contrasena</label>
                    <div class="flex gap-2 mt-1">
                        <input type="password" name="password" data-login-password-input
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 text-center focus:outline-none focus:border-amber-500/50"
                            placeholder="........" required autofocus>
                        <button type="button" data-login-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
                            class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-amber-400 hover:border-amber-500/40 transition">
                            <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                            <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">
                    Acceder al Panel
                </button>
            </form>
            <div class="text-center mt-4">
                <button type="button" data-forgot-toggle class="text-sm text-slate-400 hover:text-amber-400 transition">¿Olvidaste la contraseña?</button>
            </div>
            <div id="forgotBox" class="hidden mt-3">
                <form id="forgotForm" onsubmit="return false;">
                    <input type="email" id="forgotEmail" required placeholder="Email de recuperación"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:outline-none focus:border-amber-500/50">
                    <button type="submit" id="forgotBtn"
                        class="mt-2 w-full py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-300 hover:bg-slate-700 transition">
                        Enviar enlace de recuperación
                    </button>
                </form>
                <p id="forgotMsg" class="text-sm mt-2 hidden"></p>
            </div>
        </div>
        <script>
        // Toggle de contraseña del login con JavaScript nativo (sin Alpine)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-login-toggle]');
            if (!btn) return;
            var input = btn.parentElement ? btn.parentElement.querySelector('input[data-login-password-input]') : null;
            if (!input) return;
            var eye = btn.querySelector('[data-eye]');
            var eyeOff = btn.querySelector('[data-eye-off]');
            var show = (input.type === 'password');
            input.type = show ? 'text' : 'password';
            if (eye) eye.classList.toggle('hidden', show);
            if (eyeOff) eyeOff.classList.toggle('hidden', !show);
            btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
        // Recuperación de contraseña: mostrar/ocultar el form de email + envío.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-forgot-toggle]');
            if (!btn) return;
            var box = document.getElementById('forgotBox');
            if (box) box.classList.toggle('hidden');
        });
        document.getElementById('forgotForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            var email = document.getElementById('forgotEmail').value.trim();
            var btn = document.getElementById('forgotBtn');
            var msg = document.getElementById('forgotMsg');
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            msg.classList.add('hidden');
            var fd = new FormData();
            fd.append('action', 'request_reset');
            fd.append('email', email);
            try {
                var r = await fetch('?action=request_reset', { method: 'POST', body: fd });
                var j = await r.json();
                msg.className = 'text-sm mt-2 text-slate-300';
                msg.textContent = j.message || 'Si el email es correcto, recibirás un enlace.';
                msg.classList.remove('hidden');
            } catch (err) {
                msg.className = 'text-sm mt-2 text-rose-400';
                msg.textContent = 'Error de conexión. Inténtalo de nuevo.';
                msg.classList.remove('hidden');
            }
            btn.disabled = false;
            btn.textContent = 'Enviar enlace de recuperación';
        });
        lucide.createIcons();
        </script>
    </body>
    </html>
    <?php
}
