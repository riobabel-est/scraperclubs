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

define('AUTH_KEY', 'FutProtec2026!');
$DB_PATH = __DIR__ . '/data/stats.db';
session_start();

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
if (!file_exists($DB_PATH)) {
    echo '<div class="max-w-lg mx-auto mt-20 p-8 text-center font-sans">
        <h2 class="text-xl font-bold text-red-400">stats.db no encontrada</h2>
        <p class="text-slate-400 mt-2">Ejecuta: <code class="bg-slate-800 px-2 py-1 rounded text-amber-400">php init_db.php</code></p>
        </div>';
    exit;
}

// ─── CONEXIÓN BD ─────────────────────────────────────────────────────────────
$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

require_once __DIR__ . '/inc/eligibilidad.php';
require_once __DIR__ . '/inc/metricas.php';
require_once __DIR__ . '/inc/helpers.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════ AUTENTICACIÓN PARA ENDPOINTS AJAX ════════════════════════════
// Todos los endpoints AJAX requieren autenticación previa
if (!empty($action) && empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
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

// ─── ENDPOINT: get_lead (llamado por app.js como ?action=get_lead) ───────────
if ($action === 'get_lead') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
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
    echo json_encode($row ?: null);
    exit;
}

// ─── ENDPOINT: update_lead (llamado por app.js como ?action=update_lead) ─────
if ($action === 'update_lead') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['estado_lead', 'persona_contacto', 'cargo_contacto',
                    'telefono_movil', 'telefono_fijo', 'tiene_whatsapp', 'observaciones',
                    'federacion', 'volumen_estimado', 'num_jugadores', 'categorias',
                    'fecha_decision_prevista', 'objeciones', 'proxima_accion',
                    'canal_interaccion', 'motivo_perdida'];
        if ($id <= 0 || !in_array($field, $allowed, true)) {
            echo json_encode(['ok' => false]);
            exit;
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
            $esOptOutReal = false;
            if (in_array($estadoAnterior, $estadosSupresion, true)) {
                $obsLead = (string)$db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$id}");
                if (preg_match('/\[BAJA\][^\n]*fuente\s*=\s*email/i', $obsLead)) {
                    $esOptOutReal = true;
                }
            }
            if ($esOptOutReal && !in_array($value, $estadosSupresion, true)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Este lead tiene una BAJA REAL del destinatario (opt-out). No puede reactivarse desde el Kanban. Usa la gestión de Lista Negra con confirmación explícita.',
                    'razon' => 'OPTOUT_REAL_PROTEGIDO'
                ]);
                exit;
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
            echo json_encode(['ok' => true]);
            exit;
        } else {
            $stmt = $db->prepare("UPDATE clubes_crm SET {$field} = :val WHERE id = :id");
            $stmt->bindValue(':val', $value, SQLITE3_TEXT);
        }
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true]);
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


// Estados Kanban — V4.3 (8 columnas definitivas)
$estadosKanban = [
    '01 Sin Contactar', '02 Contactado', '03 Respondió',
    '04 Interesado', '05 Cualificado', '06 Propuesta',
    '07 Negociación', '08 Ganado', '09 Perdido'
];
$colClasses = [
    '01 Sin Contactar' => 'border-slate-500',
    '02 Contactado'    => 'border-blue-500',
    '03 Respondió'     => 'border-cyan-500',
    '04 Interesado'    => 'border-amber-500',
    '05 Cualificado'   => 'border-purple-500',
    '06 Propuesta'     => 'border-indigo-500',
    '07 Negociación'   => 'border-orange-500',
    '08 Ganado'        => 'border-emerald-500',
    '09 Perdido'       => 'border-rose-500',
];

// Datos Kanban
$kanbanData = [];
foreach ($estadosKanban as $est) {
    $stmt = $db->prepare("
        SELECT c.*, (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS num_opens
        FROM clubes_crm c WHERE c.estado_lead = :estado ORDER BY c.nombre_club ASC
    ");
    $stmt->bindValue(':estado', $est, SQLITE3_TEXT);
    $res = $stmt->execute();
    $cards = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
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
    <script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        [x-cloak] { display: none !important; }
        .kanban-scroll { scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
        .kanban-scroll > * { scroll-snap-align: start; }
        @media (min-width: 768px) { .kanban-scroll { scroll-snap-type: none; } }
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
            <a href="?logout=1" class="text-slate-500 hover:text-slate-300 text-xs transition ml-2">
                <i data-lucide="log-out" class="w-4 h-4 inline"></i>
            </a>
        </div>
    </div>
</header>

<!-- ═══════════ SCORECARDS CLICKEABLES ═══════════ -->
<div class="max-w-full mx-auto px-4 py-4 grid grid-cols-2 md:grid-cols-5 gap-3">
    <div @click="tab='gestor'" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-amber-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Total Leads</span>
            <i data-lucide="users" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-slate-200 mt-1"><?= number_format($totalLeads) ?></div>
        <div class="text-xs text-slate-500 mt-1">Histórico global</div>
    </div>
    <div @click="abrirAnalytics('envios')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-blue-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Envíos Totales</span>
            <i data-lucide="send" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-blue-400 mt-1"><?= number_format($totalEnviados) ?></div>
        <div class="text-xs text-slate-500 mt-1">emails enviados</div>
    </div>
    <div @click="abrirAnalytics('aperturas')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-cyan-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Tasa Apertura</span>
            <i data-lucide="eye" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-cyan-400 mt-1"><?= $tasaApertura ?>%</div>
        <div class="text-xs text-slate-500 mt-1"><?= $totalAperturas ?> aperturas</div>
    </div>
    <div @click="abrirAnalytics('rebotes')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-rose-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Tasa Rebote</span>
            <i data-lucide="alert-triangle" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold mt-1 <?= $tasaRebote > 5 ? 'text-rose-400' : ($tasaRebote > 2 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= $tasaRebote ?>%</div>
        <div class="text-xs text-slate-500 mt-1"><?= $totalRebotes ?> rebotes</div>
    </div>
    <div @click="abrirAnalytics('bajas')" class="bg-slate-900 border border-slate-800 rounded-xl p-4 cursor-pointer hover:border-amber-500/30 hover:bg-slate-800/50 transition">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Leads de Baja</span>
            <i data-lucide="user-minus" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold mt-1 <?= $totalBajas > 0 ? 'text-amber-400' : 'text-slate-500' ?>"><?= $totalBajas ?></div>
        <div class="text-xs text-slate-500 mt-1">Opt-Out / Unsubscribed / Lista Negra</div>
    </div>
</div>

<!-- ═══════════ TABS ═══════════ -->
<div class="max-w-full mx-auto px-4">
    <nav class="flex gap-1 border-b border-slate-800 overflow-x-auto">
        <button @click="tab='kanban'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'kanban' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Kanban CRM</button>
        <button @click="tab='gestor'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'gestor' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Gestor de Datos</button>
        <button @click="tab='editor'; loadCategorias()"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'editor' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Editor Plantilla</button>
        <button @click="tab='smtp'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'smtp' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Config SMTP</button>
        <button @click="tab='lanza'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'lanza' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Lanzadera</button>
        <button @click="tab='analytics'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'analytics' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Analytics</button>
        <button @click="tab='followups'"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'followups' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Follow-ups</button>
        <button @click="tab='respuestas'; loadRespuestas()"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'respuestas' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Respuestas</button>
        <button @click="tab='lista_negra'; blCargar()"
            class="px-4 py-2.5 text-xs font-semibold rounded-t-lg transition border-b-2 whitespace-nowrap"
            :class="tab === 'lista_negra' ? 'border-amber-400 text-amber-400 bg-slate-900' : 'border-transparent text-slate-500 hover:text-slate-300'">Lista Negra</button>
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
<div x-show="tab === 'followups'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/followups.php'; ?>
</div>
<div x-show="tab === 'respuestas'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/respuestas.php'; ?>
</div>
<div x-show="tab === 'lista_negra'" x-cloak class="max-w-full mx-auto px-4 py-4">
    <?php include __DIR__ . '/tabs/lista_negra.php'; ?>
</div>

<!-- ═══════════ MODALS ═══════════ -->

<?php
include __DIR__ . '/tabs/modals.php';
?>

<!-- ALPINE.JS APP -->
<script>
window._cfg = {motorActivo:<?= $motorActivo?'true':'false' ?>,modeTest:<?= $modoPruebas?'true':'false' ?>};
</script>
<script src="js/app.js?v=10"></script>
</body>
</html>
<?php
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
                <p class="text-slate-500 text-xs mt-1">Panel CRM Kanban v2.0</p>
            </div>
            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2 text-rose-400 text-xs text-center mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-4">
                    <label class="text-[10px] text-slate-500 uppercase tracking-wider">Contrasena</label>
                    <div class="mt-1" style="position:relative;">
                        <input type="password" name="password" data-login-password-input
                            class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-3 pr-12 py-2 text-sm text-slate-200 text-center focus:outline-none focus:border-amber-500/50"
                            placeholder="........" required autofocus>
                        <button type="button" data-login-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
                            style="position:absolute; right:0.5rem; top:50%; transform:translateY(-50%); width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; border-radius:0.375rem; color:#94a3b8; background:transparent; border:none; cursor:pointer; transition:color .15s, background-color .15s;"
                            class="hover:text-amber-400 hover:bg-slate-700/60">
                            <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                            <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">
                    Acceder al Panel
                </button>
            </form>
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
        lucide.createIcons();
        </script>
    </body>
    </html>
    <?php
}
