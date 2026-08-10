<?php
/**
 * dashboard.php — Panel CRM Kanban v2.0 FutProtec.
 * Tailwind CSS + Alpine.js + Lucide Icons. Modo Oscuro.
 * PHP 8.x nativo — SiteGround compatible.
 */
declare(strict_types=1);

define('AUTH_KEY', 'FutProtec2026!');
$DB_PATH = __DIR__ . '/stats.db';
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════ ENDPOINTS AJAX ═══════════════════════════════════════════════

// ─── update_lead ─────────────────────────────────────────────────────────────
if ($action === 'update_lead') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['estado_lead', 'persona_contacto', 'cargo_contacto',
                    'telefono_movil', 'telefono_fijo', 'tiene_whatsapp', 'observaciones',
                    'federacion'];
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
            $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->bindValue(':val', $value, SQLITE3_TEXT);
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

// ─── add_lead (con validación MX y WhatsApp auto-detect) ────────────────────
if ($action === 'add_lead') {
    header('Content-Type: application/json');
    try {
        $n  = $_POST['nombre'] ?? '';
        $e  = $_POST['email'] ?? '';
        $f  = $_POST['federacion'] ?? '';
        $m  = $_POST['telefono_movil'] ?? '';
        $fi = $_POST['telefono_fijo'] ?? '';
        $p  = $_POST['persona_contacto'] ?? '';
        $c  = $_POST['cargo_contacto'] ?? '';

        if ($n === '' || $e === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre y Email obligatorios']);
            exit;
        }
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Email con formato invalido']);
            exit;
        }
        $domain = substr(strrchr($e, '@'), 1);
        if (!checkdnsrr($domain, 'MX')) {
            echo json_encode(['ok' => false, 'error' => "El dominio {$domain} no tiene servidor de correo (MX)"]);
            exit;
        }
        // WhatsApp: si móvil empieza por 6 o 7 (9 dígitos)
        $wa = 0;
        if ($m !== '') {
            $limpio = preg_replace('/[^0-9]/', '', $m);
            if (strlen($limpio) === 9 && in_array($limpio[0], ['6', '7'], true)) {
                $wa = 1;
            }
        }
        $stmt = $db->prepare("INSERT INTO clubes_crm
            (nombre_club, email, federacion, telefono_movil, telefono_fijo, persona_contacto, cargo_contacto, tiene_whatsapp, estado_lead)
            VALUES (:n, :e, :f, :m, :fi, :p, :c, :wa, 'Sin Contactar')");
        $stmt->bindValue(':n',  $n,  SQLITE3_TEXT);
        $stmt->bindValue(':e',  strtolower($e), SQLITE3_TEXT);
        $stmt->bindValue(':f',  $f,  SQLITE3_TEXT);
        $stmt->bindValue(':m',  $m,  SQLITE3_TEXT);
        $stmt->bindValue(':fi', $fi, SQLITE3_TEXT);
        $stmt->bindValue(':p',  $p,  SQLITE3_TEXT);
        $stmt->bindValue(':c',  $c,  SQLITE3_TEXT);
        $stmt->bindValue(':wa', $wa, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID(), 'mx_ok' => true, 'whatsapp' => $wa]);
    } catch (\Exception $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
    }
    exit;
}

// ─── update_config ───────────────────────────────────────────────────────────
if ($action === 'update_config') {
    header('Content-Type: application/json');
    try {
        $k = $_POST['key'] ?? '';
        $v = $_POST['value'] ?? '';
        $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES (:k, :v) ON CONFLICT(clave) DO UPDATE SET valor = :v2");
        $stmt->bindValue(':k', $k, SQLITE3_TEXT);
        $stmt->bindValue(':v', $v, SQLITE3_TEXT);
        $stmt->bindValue(':v2', $v, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── get_lead ────────────────────────────────────────────────────────────────
if ($action === 'get_lead') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $row = $db->querySingle("
        SELECT c.*,
               (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email)) AS total_envios,
               (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email)) AS total_aperturas
        FROM clubes_crm c WHERE c.id = {$id}", true);
    echo json_encode($row ?: null);
    exit;
}

// ─── save_template ───────────────────────────────────────────────────────────
if ($action === 'save_template') {
    header('Content-Type: application/json');
    try {
        $id  = (int)($_POST['id'] ?? 0);
        $n   = $_POST['nombre'] ?? '';
        $a   = $_POST['asunto'] ?? '';
        $ab  = $_POST['asunto_b'] ?? '';
        $c   = $_POST['cuerpo'] ?? '';
        $t   = $_POST['tipo'] ?? 'html';
        $cat = $_POST['categoria'] ?? 'prospeccion';
        $act = $_POST['activo'] ?? 1;
        $tab = (int)($_POST['test_ab'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE plantillas SET nombre = :n, asunto = :a, asunto_b = :ab, cuerpo = :c, tipo = :t, categoria = :cat, activo = :act, test_ab = :tab WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':ab', $ab, SQLITE3_TEXT);
            $stmt->bindValue(':tab', $tab, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare("INSERT INTO plantillas (nombre, asunto, asunto_b, cuerpo, tipo, categoria, activo, test_ab, fecha_creacion) VALUES (:n, :a, :ab, :c, :t, :cat, :act, :tab, DATETIME('now'))");
            $stmt->bindValue(':ab', $ab, SQLITE3_TEXT);
            $stmt->bindValue(':tab', $tab, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':n',   $n,   SQLITE3_TEXT);
        $stmt->bindValue(':a',   $a,   SQLITE3_TEXT);
        $stmt->bindValue(':c',   $c,   SQLITE3_TEXT);
        $stmt->bindValue(':t',   $t,   SQLITE3_TEXT);
        $stmt->bindValue(':cat', $cat, SQLITE3_TEXT);
        $stmt->bindValue(':act', (int)$act, SQLITE3_INTEGER);
        $stmt->execute();
        $newId = $id > 0 ? $id : $db->lastInsertRowID();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── delete_template ─────────────────────────────────────────────────────────
if ($action === 'delete_template') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID invalido']);
            exit;
        }
        $db->exec("DELETE FROM plantillas WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── get_templates ───────────────────────────────────────────────────────────
if ($action === 'get_templates') {
    header('Content-Type: application/json');
    $cat = $_GET['categoria'] ?? '';
    $sql = "SELECT id, nombre, asunto, asunto_b, test_ab, cuerpo, tipo, categoria, activo FROM plantillas";
    if ($cat !== '') {
        $sql .= " WHERE categoria = '" . $db->escapeString($cat) . "'";
    }
    $sql .= " ORDER BY id ASC";
    $res = $db->query($sql);
    $tpls = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $tpls[] = $r;
    }
    echo json_encode(['ok' => true, 'templates' => $tpls]);
    exit;
}

// ─── get_categorias ──────────────────────────────────────────────────────────
if ($action === 'get_categorias') {
    header('Content-Type: application/json');
    $cats = [];
    $res = $db->query("SELECT DISTINCT categoria FROM plantillas WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        $cats[] = $r[0];
    }
    echo json_encode(['ok' => true, 'categorias' => $cats]);
    exit;
}

// ─── preview_template ────────────────────────────────────────────────────────
if ($action === 'preview_template') {
    header('Content-Type: application/json');
    $tid = (int)($_GET['template_id'] ?? 0);
    $cid = (int)($_GET['club_id'] ?? 0);
    $tpl  = $db->querySingle("SELECT * FROM plantillas WHERE id = {$tid}", true);
    $club = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$cid}", true);
    if ($tpl && $club) {
        $asunto = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['asunto']
        );
        $cuerpo = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['cuerpo']
        );
        echo json_encode(['ok' => true, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'tipo' => $tpl['tipo']]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'No encontrado']);
    }
    exit;
}

// ─── get_last_envios ─────────────────────────────────────────────────────────
if ($action === 'get_last_envios') {
    header('Content-Type: application/json');
    $res = $db->query("SELECT id, club, email, cuenta_emision, fecha_envio, estado FROM envios ORDER BY id DESC LIMIT 10");
    $envs = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $envs[] = $r;
    }
    echo json_encode(['ok' => true, 'envios' => $envs]);
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

// KPIs — Históricos y Globales
$totalLeads      = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");
$totalEnviados   = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE estado = 'enviado'");
$totalAperturas  = (int)$db->querySingle("SELECT COUNT(DISTINCT tracking_id) FROM aperturas");
$tasaApertura    = $totalEnviados > 0 ? round(($totalAperturas / $totalEnviados) * 100, 1) : 0;
$totalRebotes    = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes");
$tasaRebote      = $totalEnviados > 0 ? round(($totalRebotes / $totalEnviados) * 100, 1) : 0;
$totalBajas      = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out', 'Unsubscribed', 'Lista Negra')");
$smtpActivas     = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp WHERE activa = 1");
$smtpEnviadosHoy = (int)$db->querySingle("SELECT COALESCE(SUM(enviados_hoy), 0) FROM cuentas_smtp");

// Estados Kanban
$estadosKanban = [
    'Sin Contactar', 'Email Enviado / En Secuencia', 'Impactado / Abrio Email',
    'En Conversacion / WhatsApp', 'Muestra / Propuesta Enviada',
    'Cerrado Ganado', 'Cerrado Perdido'
];
$colClasses = [
    'Sin Contactar'               => 'border-slate-500',
    'Email Enviado / En Secuencia' => 'border-blue-500',
    'Impactado / Abrio Email'      => 'border-cyan-500',
    'En Conversacion / WhatsApp'   => 'border-amber-500',
    'Muestra / Propuesta Enviada'  => 'border-purple-500',
    'Cerrado Ganado'               => 'border-emerald-500',
    'Cerrado Perdido'              => 'border-rose-500',
];

// Datos Kanban
$kanbanData = [];
foreach ($estadosKanban as $est) {
    $stmt = $db->prepare("
        SELECT c.*, (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email)) AS num_opens
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

$federaciones = [];
$resFed = $db->query("SELECT DISTINCT federacion FROM clubes_crm WHERE federacion != '' ORDER BY federacion ASC");
while ($r = $resFed->fetchArray(SQLITE3_ASSOC)) {
    $federaciones[] = $r['federacion'];
}

$db->close();

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function getWaLink(string $m): string {
    $n = explode(',', $m);
    $f = trim($n[0] ?? '');
    return ($f !== '' && preg_match('/^[67]\d{8}$/', $f)) ? 'https://wa.me/34' . $f : '';
}

function escHtml(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ═══════════════ RENDER HTML ════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FutProtec — Outbound CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            safelist: ['bg-emerald-400', 'bg-amber-400', 'bg-rose-500', 'text-emerald-400', 'text-amber-400', 'text-rose-400', 'animate-pulse', 'border-emerald-500/30', 'border-amber-500/30', 'border-rose-500/30', 'border-emerald-400', 'border-rose-400', 'bg-emerald-500/20', 'bg-amber-500/20', 'bg-rose-500/20', 'bg-blue-500/20', 'bg-blue-500/30', 'text-blue-400', 'border-blue-500/30', 'border-l-2', 'border-l-amber-400', 'bg-amber-500/10'],
            theme: { extend: { colors: { slate: { 950: '#0a0f1a' } } } }
        };
    </script>
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
            <button @click="toggleRandom()"
                class="px-3 py-1 rounded-full text-xs font-semibold transition border"
                :class="randomMode ? 'bg-purple-500/20 text-purple-400 border-purple-500/30' : 'bg-slate-700 text-slate-400 border-slate-600'"
                x-text="randomMode ? '🎲 ALEATORIO ON' : '🎲 ALEATORIO OFF'"></button>
            <button @click="toggleModo()"
                class="px-3 py-1 rounded-full text-xs font-semibold transition"
                :class="modeTest ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'"
                x-text="modeTest ? 'MODO PRUEBAS' : 'MODO PRODUCCION'"></button>
            <a href="?logout=1" class="text-slate-500 hover:text-slate-300 text-xs transition ml-2">
                <i data-lucide="log-out" class="w-4 h-4 inline"></i>
            </a>
        </div>
    </div>
</header>

<!-- ═══════════ SCORECARDS ═══════════ -->
<div class="max-w-full mx-auto px-4 py-4 grid grid-cols-2 md:grid-cols-5 gap-3">
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Total Leads</span>
            <i data-lucide="users" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-slate-200 mt-1"><?= number_format($totalLeads) ?></div>
        <div class="text-xs text-slate-500 mt-1">Histórico global</div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Envíos Totales</span>
            <i data-lucide="send" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-blue-400 mt-1"><?= number_format($totalEnviados) ?></div>
        <div class="text-xs text-slate-500 mt-1"><?= $smtpActivas ?> cuentas activas</div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Tasa Apertura</span>
            <i data-lucide="eye" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold text-cyan-400 mt-1"><?= $tasaApertura ?>%</div>
        <div class="text-xs text-slate-500 mt-1"><?= $totalAperturas ?> aperturas</div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Tasa Rebote</span>
            <i data-lucide="alert-triangle" class="w-4 h-4 text-slate-500"></i>
        </div>
        <div class="text-2xl font-semibold mt-1 <?= $tasaRebote > 5 ? 'text-rose-400' : ($tasaRebote > 2 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= $tasaRebote ?>%</div>
        <div class="text-xs text-slate-500 mt-1"><?= $totalRebotes ?> rebotes</div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
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

<!-- ═══════════ MODALS ═══════════ -->
<?php
$federacionesSelect = [
    'Real Federacion Andaluza de Futbol',
    'Real Federacion Aragonesa de Futbol',
    'Real Federacion de Futbol del Principado de Asturias',
    'Federacio Balear de Futbol (Islas Baleares)',
    'Federacion Canaria de Futbol',
    'Real Federacion Cantabra de Futbol',
    'Federacion de Futbol de Castilla-La Mancha',
    'Real Federacion de Castilla y Leon de Futbol',
    'Federacio Catalana de Futbol',
    'Real Federacion de Futbol de Ceuta',
    'Federacion Extremena de Futbol',
    'Real Federacion Gallega de Futbol',
    'Real Federacion de Futbol de Madrid',
    'Federacion Melillense de Futbol',
    'Federacion de Futbol de la Region de Murcia',
    'Federacion Navarra de Futbol',
    'Federazioa / Federacion Vasca de Futbol',
    'Federacio de Futbol de la Comunitat Valenciana',
    'Federacion Riojana de Futbol',
];
include __DIR__ . '/tabs/modals.php';
?>

<!-- ═══════════ ALPINE.JS APP ═══════════ -->
<script>
var app = function() {
    var i = {
        tab: 'kanban',
        killSwitch: <?= $motorActivo ? 'true' : 'false' ?>,
        modeTest: <?= $modoPruebas ? 'true' : 'false' ?>,

        // Modales
        lm: false, mm: false, sm: false, al: false,
        ln: '', mn: true, mk: 0, md: 0,
        ld: {}, mf: [], mha: '', mhb: '',

        // SMTP
        se: 0,
        randomMode: false,             // 🎲 modo aleatorio (anti-blacklist)
        sf: { email: '', host: 'mail.getfutprotec.com', puerto: 465, usuario: '', password: '', seguridad: 'ssl', limite_diario: 50, nombre_emisor: '', cargo_emisor: '' },

        // Add Lead
        af: { nombre: '', email: '', federacion: '', movil: '', fijo: '', persona: '', cargo: '' },

        // Gestor
        gs: '', ge: '', gf: '', gt: '', gp: 1, gpp: 50, gsc: 'nombre_club', gso: 'ASC',

        // Editor
         ec: '', et: '', en: false,
         estadosLead: [
             '01 Sin Contactar',
             '02 Email/WhatsApp Enviado',
             '03 Email Abierto',
             '04 En Conversacion',
             '05 Propuesta Enviada',
             '06 Cerrado Ganado',
             '07 Cerrado Perdido'
         ],
         categorias: [], templates: [],
        edNombre: '', edAsunto: '', edAsuntoB: '', edTestAb: 0,
        edCuerpo: '', edTipo: 'html',
        previewClubId: '', debounceTimer: null,

        // Lanzadera v2
        lzMotorEstado: 'PAUSADO',      // PAUSADO | ACTIVO | DETENIDO
        lzDelay: 5,                     // segundos (default 5s)
        lzInterval: null,               // timer del motor
        lzAbortController: null,        // para cancelar envíos
        testEmails: '',                // 🧪 campo de texto con emails de prueba
        lzCola: [],                     // array completo de leads con SMTP asignada
        lzColaIndex: 0,                // índice del lead actual en proceso
        lzColaPaginada: [],            // subconjunto visible (infinite scroll)
        lzColaPageSize: 50,           // cuántos items cargar por scroll
        lzColaPageCurrent: 0,         // página actual de scroll
        lzColaCompletados: {},         // objeto: { [clubId]: true } para leads ya enviados
        lzLogEnviados: [],             // log completo de envíos realizados en esta sesión
        lzLogEnviadosPaginados: [],    // subconjunto visible del log
        lzLogPageSize: 30,            // cuántos items del log cargar por scroll
        lzLogPageCurrent: 0,          // página actual de scroll del log
        lzCuentasSmtp: [],             // estado de cuentas SMTP
        lzFederacion: '',              // 1.1 Seleccionar Federacion
        lzEstadoLead: '',              // 1.2 Seleccionar Estado del Lead
        lzIdPlantillaEmail: '',        // 1.3 Seleccionar Plantilla
        lzIdPlantillaWa: '',           // 1.4 Plantilla WA
        lzWhatsappOn: false,           // 1.4 Toggle WA
        lzFederaciones: [],            // opciones del dropdown 1.1
        lzEstadosLead: [               // opciones del dropdown 1.2 (estados fijos)
            '01 Sin Contactar',
            '02 Email/WhatsApp Enviado',
            '03 Email Abierto',
            '04 En Conversacion',
            '05 Propuesta Enviada',
            '06 Cerrado Ganado',
            '07 Cerrado Perdido'
        ],
        lzTemplatesEmail: [],          // plantillas email filtradas
        lzTemplatesWa: [],             // plantillas whatsapp filtradas
        lzTabMonitor: 'cola',          // cola | log
        lzKpiClubes: 0,                // KPI: total clubes
        lzKpiSmtpActivas: 0,          // KPI: SMTP activas
        lzKpiEnviosHoy: 0,            // KPI: envíos hoy

        // ─── Computed ─────────────────────────────────────────────────────────
        get waLink() {
            const m = this.ld.telefono_movil || '';
            const n = m.split(',').map(s => s.trim()).filter(s => /^[67]\d{8}$/.test(s));
            return n.length > 0 ? 'https://wa.me/34' + n[0] : '';
        },
        get templatesFiltradas() {
            return this.templates.filter(t => t.categoria === this.ec);
        },

        // ─── Analytics de Sesión (Lanzadera) ────────────────────────────────
        get lzEnvioOkCount() {
            return this.lzLogEnviados.filter(l => l.envio_exitoso).length;
        },
        get lzEnvioErrorCount() {
            return this.lzLogEnviados.filter(l => !l.envio_exitoso).length;
        },
        get lzTotalProcesados() {
            return this.lzLogEnviados.length;
        },
        get lzTasaExito() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100) : 0;
        },
        get lzEnvioOkPct() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100) : 0;
        },
        get lzEnvioErrorPct() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioErrorCount / this.lzTotalProcesados) * 100) : 0;
        },

        // ─── 🧪 Parser de emails de prueba ────────────────────────────────
        get testEmailsList() {
            if (!this.testEmails || !this.testEmails.trim()) return [];
            return this.testEmails
                .split(/[\n,]+/)
                .map(e => e.trim())
                .filter(e => e.length > 0 && e.includes('@'));
        },

        // ─── Boot ─────────────────────────────────────────────────────────────
        async boot() {
            window.app = this;
            lucide.createIcons();
            // Cada inicialización es independiente para que un fallo no
            // bloquee el resto del panel (ej: SMTP no depende de Gestor)
            try { await this.loadGestor(); } catch (e) { console.error('boot: loadGestor falló', e); }
            try { await this.loadSmtp(); } catch (e) { console.error('boot: loadSmtp falló', e); }
            try { await this.bootLanzadera(); } catch (e) { console.error('boot: bootLanzadera falló', e); }
        },

        // ─── Config ───────────────────────────────────────────────────────────
        async toggleKS() {
            this.killSwitch = !this.killSwitch;
            const f = new FormData();
            f.append('action', 'update_config');
            f.append('key', 'motor_estado');
            f.append('value', this.killSwitch ? 'activo' : 'pausado');
            await fetch('', { method: 'POST', body: f });
        },
        async toggleModo() {
            this.modeTest = !this.modeTest;
            const f = new FormData();
            f.append('action', 'update_config');
            f.append('key', 'modo_entorno');
            f.append('value', this.modeTest ? 'test' : 'produccion');
            await fetch('', { method: 'POST', body: f });
        },
        toggleRandom() {
            this.randomMode = !this.randomMode;
        },

        // ─── Lead Modal ───────────────────────────────────────────────────────
        async openLead(id) {
            const r = await fetch('?action=get_lead&id=' + id);
            this.ld = await r.json();
            this.ln = '';
            this.lm = true;
            setTimeout(() => lucide.createIcons(), 100);
        },
        async saveF(field, value) {
            if (!this.ld.id) return;
            const f = new FormData();
            f.append('action', 'update_lead');
            f.append('id', this.ld.id);
            f.append('field', field);
            f.append('value', value);
            await fetch('', { method: 'POST', body: f });
        },
        async addNota() {
            if (!this.ln.trim()) return;
            await this.saveF('observaciones', this.ln);
            const r = await fetch('?action=get_lead&id=' + this.ld.id);
            this.ld = await r.json();
            this.ln = '';
        },

        // ─── Add Lead (con validación MX y WhatsApp) ─────────────────────────
        openAddLead() {
            this.af = { nombre: '', email: '', federacion: '', movil: '', fijo: '', persona: '', cargo: '' };
            this.al = true;
            setTimeout(() => lucide.createIcons(), 100);
        },
        get afWaDetected() {
            if (!this.af.movil) return false;
            const limpio = this.af.movil.replace(/[^0-9]/g, '');
            return limpio.length === 9 && ['6', '7'].includes(limpio[0]);
        },
        async saveAddLead() {
            const f = new FormData();
            f.append('action', 'add_lead');
            f.append('nombre', this.af.nombre);
            f.append('email', this.af.email);
            f.append('federacion', this.af.federacion);
            f.append('telefono_movil', this.af.movil);
            f.append('telefono_fijo', this.af.fijo);
            f.append('persona_contacto', this.af.persona);
            f.append('cargo_contacto', this.af.cargo);
            const r = await fetch('', { method: 'POST', body: f });
            const j = await r.json();
            if (j.ok) {
                this.al = false;
                this.loadGestor();
                alert('Lead anadido');
            } else {
                alert(j.error || 'Desconocido');
            }
        },

        // ─── Merge ────────────────────────────────────────────────────────────
        async openMerge(k, d) {
            this.mk = k;
            this.md = d;
            const [r1, r2] = await Promise.all([
                fetch('?action=get_lead&id=' + k).then(r => r.json()),
                fetch('?action=get_lead&id=' + d).then(r => r.json())
            ]);
            const a = r1, b = r2;
            if (!a || !b) return;
            this.mha = this.fr('Club', a.nombre_club) + this.fr('Email', a.email) + this.fr('Fed', a.federacion || '')
                     + this.fr('Contacto', a.persona_contacto) + this.fr('Movil', a.telefono_movil) + this.fr('Fijo', a.telefono_fijo)
                     + this.fr('Estado', a.estado_lead) + '<div class="mt-1"><strong class="text-slate-500">Notas:</strong><br>' + this.esc(a.observaciones || '(sin notas)') + '</div>';
            this.mhb = this.fr('Club', b.nombre_club) + this.fr('Email', b.email) + this.fr('Fed', b.federacion || '')
                     + this.fr('Contacto', b.persona_contacto) + this.fr('Movil', b.telefono_movil) + this.fr('Fijo', b.telefono_fijo)
                     + this.fr('Estado', b.estado_lead) + '<div class="mt-1"><strong class="text-slate-500">Notas:</strong><br>' + this.esc(b.observaciones || '(sin notas)') + '</div>';
            this.mf = [
                { name: 'nombre', label: 'Nombre', vA: a.nombre_club, vB: b.nombre_club, cA: true },
                { name: 'contacto', label: 'Contacto', vA: a.persona_contacto, vB: b.persona_contacto, cA: !!a.persona_contacto },
                { name: 'movil', label: 'Movil', vA: a.telefono_movil, vB: b.telefono_movil, cA: !!a.telefono_movil },
                { name: 'fijo', label: 'Fijo', vA: a.telefono_fijo, vB: b.telefono_fijo, cA: !!a.telefono_fijo },
                { name: 'estado', label: 'Estado', vA: a.estado_lead, vB: b.estado_lead, cA: true }
            ];
            this.mm = true;
            this.mn = true;
            setTimeout(() => lucide.createIcons(), 100);
        },
        fr(label, val) { return '<div><strong class="text-slate-500 text-[9px]">' + label + ':</strong> ' + this.esc(val || '-') + '</div>'; },
        async doMerge() {
            const fm = { nombre: 'nombre_club', contacto: 'persona_contacto', movil: 'telefono_movil', fijo: 'telefono_fijo', estado: 'estado_lead' };
            for (const f of this.mf) {
                const s = document.querySelector('input[name="mg_' + f.name + '"]:checked');
                if (s && s.value === 'B') {
                    const fd = new FormData();
                    fd.append('action', 'update_lead');
                    fd.append('id', this.mk);
                    fd.append('field', fm[f.name]);
                    const bL = await fetch('?action=get_lead&id=' + this.md).then(r => r.json());
                    fd.append('value', bL[fm[f.name]] || '');
                    await fetch('', { method: 'POST', body: fd });
                }
            }
            const fd = new FormData();
            fd.append('action', 'merge_leads');
            fd.append('keep_id', this.mk);
            fd.append('dup_id', this.md);
            fd.append('merge_notes', this.mn ? '1' : '0');
            const r = await fetch('api_leads.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) { this.mm = false; location.reload(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Scan Dups ────────────────────────────────────────────────────────
        async scanDups() {
            const r = await fetch('api_leads.php?action=scan_duplicates');
            const j = await r.json();
            if (j.ok) { alert('Escaneo: ' + j.dups + ' duplicados en ' + j.total + ' clubes.'); location.reload(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Gestor ───────────────────────────────────────────────────────────
        async loadGestor() {
            const p = new URLSearchParams({
                action: 'get_leads_table', page: this.gp, per_page: this.gpp,
                sort: this.gsc, order: this.gso,
                search: this.gs, estado: this.ge, federacion: this.gf
            });
            const r = await fetch('api_leads.php?' + p.toString());
            const j = await r.json();
            if (!j.ok) return;
            this.gt = j.total + ' resultados';
            let h = '';
            j.data.forEach(l => {
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><span class="font-medium text-slate-300">' + this.esc(l.nombre_club) + '</span>'
                   + (l.es_duplicado == 1 ? ' <span class="bg-amber-500/15 text-amber-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-pointer" onclick="window.app.openMerge(' + l.duplicado_id + ',' + l.id + ')">DUPLICADO</span>' : '')
                   + '</td>'
                   + '<td class="px-3 py-2 hidden md:table-cell"><code class="text-[10px] text-slate-500">' + this.esc(l.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden md:table-cell text-[10px] text-slate-400 font-mono">' + this.esc(l.telefono_movil || '-') + '</td>'
                   + '<td class="px-3 py-2 text-[10px] text-slate-400">' + this.esc(l.estado_lead) + '</td>'
                   + '<td class="px-3 py-2 hidden lg:table-cell text-[10px] text-slate-600">' + this.esc(l.federacion || '') + '</td>'
                   + '<td class="px-3 py-2 text-right"><button class="px-2 py-1 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-400 hover:text-slate-200 hover:border-slate-600 transition" onclick="window.app.openLead(' + l.id + ')">Ficha</button></td>'
                   + '</tr>';
            });
            document.getElementById('gestorBody').innerHTML = h || '<tr><td colspan="6" class="px-3 py-8 text-center text-slate-600">Sin resultados</td></tr>';
            let pg = '';
            const tp = j.total_pages;
            const cp = this.gp;
            let s = Math.max(1, cp - 2);
            let e = Math.min(tp, cp + 2);
            const bpg = (n) => '<button class="px-2 py-0.5 text-[10px] rounded border ' + (n === cp ? 'bg-slate-700 border-slate-600 text-slate-200' : 'border-slate-800 text-slate-500 hover:text-slate-300') + '" onclick="window.app.gp=' + n + ';window.app.loadGestor()" title="Ir a pagina ' + n + '">' + n + '</button>';
            if (s > 1) {
                pg += bpg(1);
                if (s > 2) pg += '<span class="px-1 text-slate-600">…</span>';
            }
            for (let i = s; i <= e; i++) pg += bpg(i);
            if (e < tp) {
                if (e < tp - 1) pg += '<span class="px-1 text-slate-600">…</span>';
                pg += bpg(tp);
            }
            document.getElementById('gestorP').innerHTML = pg;
        },
        gSort(col) {
            if (this.gsc === col) this.gso = this.gso === 'ASC' ? 'DESC' : 'ASC';
            else { this.gsc = col; this.gso = 'ASC'; }
            this.gp = 1; this.loadGestor();
        },

        // ─── Editor ────────────────────────────────────────────────────────────
        async loadCategorias() {
            const r = await fetch('?action=get_categorias');
            const j = await r.json();
            if (j.ok) this.categorias = j.categorias;
        },
        async onCategoriaChange() {
            this.et = ''; this.en = false;
            if (!this.ec) return;
            const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.ec));
            const j = await r.json();
            if (j.ok) this.templates = j.templates;
            setTimeout(() => lucide.createIcons(), 50);
        },
        async onTemplateChange() {
            if (!this.et) { this.en = false; return; }
            const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.ec));
            const j = await r.json();
            const t = j.templates.find(x => x.id == this.et);
            if (t) {
                this.edNombre = t.nombre;
                this.edAsunto = t.asunto || '';
                this.edAsuntoB = t.asunto_b || '';
                this.edTestAb = parseInt(t.test_ab) || 0;
                this.edCuerpo = t.cuerpo || '';
                this.edTipo = t.tipo || 'html';
                this.en = false;
                this.autoPreview();
            }
            setTimeout(() => lucide.createIcons(), 50);
        },
        nuevaPlantilla() {
            this.et = ''; this.en = true;
            this.edNombre = 'Nueva plantilla'; this.edAsunto = ''; this.edAsuntoB = ''; this.edTestAb = 0;
            this.edCuerpo = ''; this.edTipo = 'html';
            setTimeout(() => lucide.createIcons(), 50);
        },
        async eliminarPlantilla() {
            if (!this.et) return;
            if (!confirm('Eliminar esta plantilla?')) return;
            const f = new FormData();
            f.append('action', 'delete_template'); f.append('id', this.et);
            const r = await fetch('', { method: 'POST', body: f });
            const j = await r.json();
            if (j.ok) { this.et = ''; this.en = false; this.onCategoriaChange(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        async guardarPlantilla() {
            const f = new FormData();
            f.append('action', 'save_template');
            if (this.et && !this.en) f.append('id', this.et);
            f.append('nombre', this.edNombre);
            f.append('asunto', this.edAsunto);
            f.append('asunto_b', this.edAsuntoB);
            f.append('test_ab', this.edTestAb);
            f.append('cuerpo', this.edCuerpo);
            f.append('tipo', this.edTipo);
            f.append('categoria', this.ec);
            f.append('activo', '1');
            const r = await fetch('', { method: 'POST', body: f });
            const j = await r.json();
            if (j.ok) { this.en = false; this.et = j.id; this.onCategoriaChange(); alert('Plantilla guardada'); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        insertTag(tag) { this.edCuerpo += tag; this.autoPreview(); },
        onCuerpoInput() { clearTimeout(this.debounceTimer); this.debounceTimer = setTimeout(() => this.autoPreview(), 500); },
        onTipoChange() { this.autoPreview(); },
        async autoPreview() { if (!this.previewClubId || (!this.et && !this.en)) return; this.previewTpl(); },
        async previewTpl() {
            const ci = this.previewClubId; if (!ci) return;
            const body = this.edCuerpo || (this.et ? await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.ec)).then(r => r.json()).then(j => {
                const t = j.templates.find(x => x.id == this.et); return t ? t.cuerpo : '';
            }) : '');
            if (!body) { document.getElementById('previewContainer').innerHTML = '<p class="text-slate-400 text-center py-8">Sin contenido para previsualizar</p>'; return; }
            const r = await fetch('?action=get_lead&id=' + ci);
            const club = await r.json(); if (!club) return;
            const html = body.replace(/{{CLUB}}/g, club.nombre_club || '')
                             .replace(/{{CONTACTO}}/g, club.persona_contacto || 'responsable')
                             .replace(/{{FEDERACION}}/g, club.federacion || '')
                             .replace(/{{ANIO}}/g, new Date().getFullYear());
            const container = document.getElementById('previewContainer');
            if (this.edTipo === 'whatsapp') {
                container.innerHTML = '<div style="background:#e5ddd5;padding:16px;border-radius:8px;max-width:400px;font-family:sans-serif;font-size:14px;white-space:pre-wrap">' + this.esc(html) + '</div>';
            } else {
                container.style.whiteSpace = 'pre-wrap'; container.style.wordBreak = 'break-word'; container.innerHTML = html;
            }
        },

        // ═══════════════════════════════════════════════════════════════════════
        // LANZADERA OUTBOUND v2 — Motor de Envíos Secuencial
        // ═══════════════════════════════════════════════════════════════════════

        async bootLanzadera() {
            // Cargar delay guardado
            try {
                const r = await fetch('api_leads.php?action=get_config&key=lanzadera_delay');
                const j = await r.json();
                if (j.ok && j.valor) this.lzDelay = parseInt(j.valor) || 5;
            } catch (e) { this.lzDelay = 5; }

            // Cargar datos iniciales (federaciones)
            try {
                const r = await fetch('get_cola.php');
                const j = await r.json();
                if (j.ok) {
                    this.lzFederaciones = j.federaciones || [];
                    this.lzCuentasSmtp = j.cuentas_smtp || [];
                    this.lzKpiClubes = j.kpi_clubes || 0;
                    this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0;
                    this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0;
                }
            } catch (e) {}
        },

        // ─── 1.2 Cambio de Estado del Lead → cargar plantillas ─────────
        async lzOnEstadoChange() {
            this.lzIdPlantillaEmail = '';
            this.lzTemplatesEmail = [];
            if (!this.lzEstadoLead) return;
            try {
                const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.lzEstadoLead));
                const j = await r.json();
                if (j.ok && j.templates) {
                    this.lzTemplatesEmail = j.templates.filter(t => t.tipo !== 'whatsapp');
                    this.lzTemplatesWa = j.templates.filter(t => t.tipo === 'whatsapp');
                }
            } catch (e) {}
        },

        // ─── Validar si puede cargar cola ──────────────────────────────────────
        puedeCargarCola() {
            return this.lzEstadoLead !== '' && this.lzIdPlantillaEmail !== '';
        },

        // ─── 5.1 Cargar Cola de Envíos ────────────────────────────────────────
        async cargarCola() {
            if (!this.puedeCargarCola()) {
                alert('Selecciona al menos Estado del Lead y Plantilla de Email');
                return;
            }
            this.lzCola = [];
            this.lzColaPaginada = [];
            this.lzColaPageCurrent = 0;
            this.lzColaIndex = 0;
            this.lzColaCompletados = {};
            this.lzLogEnviados = [];
            this.lzLogEnviadosPaginados = [];
            this.lzLogPageCurrent = 0;
            this.lzMotorEstado = 'PAUSADO';

            const params = new URLSearchParams({
                estado_lead: this.lzEstadoLead,
                federacion: this.lzFederacion,
                id_plantilla_email: this.lzIdPlantillaEmail,
                id_plantilla_wa: this.lzIdPlantillaWa,
                habilitar_whatsapp: this.lzWhatsappOn ? '1' : '0',
                random_mode: this.randomMode ? '1' : '0',
            });

            try {
                const r = await fetch('get_cola.php?' + params.toString());
                const j = await r.json();
                if (!j.ok) { alert('Error: ' + (j.error || 'Desconocido')); return; }
                this.lzCola = j.cola || [];
                // Cargar primeros 50 items para infinite scroll
                if (this.lzCola.length > 0) {
                    this.lzColaPaginada = this.lzCola.slice(0, Math.min(this.lzColaPageSize, this.lzCola.length));
                    this.lzColaPageCurrent = 1;
                }
                this.lzCuentasSmtp = j.cuentas_smtp || [];
                this.lzKpiClubes = j.kpi_clubes || 0;
                this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0;
                this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0;
                this.lzDelay = j.delay_segundos || 5;
                if (this.lzCola.length === 0) {
                    alert('No hay leads pendientes con los filtros seleccionados.');
                }
            } catch (e) {
                alert('Error de conexión al cargar la cola.');
            }
            setTimeout(() => lucide.createIcons(), 100);
        },

        // ─── 5.2 INICIAR LANZADERA — Motor secuencial async/await ─────────────
        async iniciarMotor() {
            if (this.lzCola.length === 0) return;
            this.lzMotorEstado = 'ACTIVO';
            this.lzAbortController = new AbortController();
            const signal = this.lzAbortController.signal;

            for (let i = this.lzColaIndex; i < this.lzCola.length; i++) {
                // Verificar si se pausó o detuvo
                if (signal.aborted) break;
                if (this.lzMotorEstado === 'PAUSADO') break;
                if (this.lzMotorEstado === 'DETENIDO') break;

                this.lzColaIndex = i;
                const lead = this.lzCola[i];
                if (!lead) continue;

                // Enviar email (con variante A/B aleatoria 50%)
                const vAb = Math.random() < 0.5 ? 'A' : 'B';
                const fd = new FormData();
                fd.append('id_club', lead.id);
                fd.append('id_plantilla', this.lzIdPlantillaEmail);
                fd.append('id_cuenta_smtp', lead.smtp_asignada_id);
                fd.append('modo_test', this.modeTest ? '1' : '0');
                fd.append('variante_ab', vAb);
                // 🧪 Si modo test y hay emails de prueba, rotar entre ellos
                if (this.modeTest && this.testEmailsList.length > 0) {
                    fd.append('test_email', this.testEmailsList[i % this.testEmailsList.length]);
                }

                try {
                    const r = await fetch('enviar_lote.php', {
                        method: 'POST',
                        body: fd,
                        signal: signal
                    });
                    const j = await r.json();
                    // Marcar como completado (grisado en la cola)
                    this.lzColaCompletados[lead.id] = true;

                    // Registrar en log
                    this.lzLogEnviados.unshift({
                        timestamp: j.timestamp || new Date().toISOString(),
                        club: j.club || lead.nombre_club,
                        email: j.email || lead.email,
                        cuenta_smtp: j.cuenta_smtp || lead.smtp_asignada_email,
                        envio_exitoso: j.envio_exitoso || false,
                        error_smtp: j.error_smtp || '',
                    });
                    // Mostrar primeros logs automáticamente
                    if (this.lzLogEnviadosPaginados.length === 0) {
                        this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length));
                        this.lzLogPageCurrent = 1;
                    }

                    // Actualizar barra SMTP
                    const smtpIdx = this.lzCuentasSmtp.findIndex(c => c.id == lead.smtp_asignada_id);
                    if (smtpIdx >= 0 && j.envio_exitoso) {
                        this.lzCuentasSmtp[smtpIdx].enviados_hoy = (this.lzCuentasSmtp[smtpIdx].enviados_hoy || 0) + 1;
                    } else if (smtpIdx >= 0 && j.error_smtp) {
                        this.lzCuentasSmtp[smtpIdx].ultimo_error = j.error_smtp;
                    }

                    // Actualizar KPIs
                    if (j.envio_exitoso) {
                        this.lzKpiEnviosHoy = (this.lzKpiEnviosHoy || 0) + 1;
                    }
                } catch (e) {
                    if (e.name === 'AbortError') break;
                    this.lzColaCompletados[lead.id] = true;
                    this.lzLogEnviados.unshift({
                        timestamp: new Date().toISOString(),
                        club: lead.nombre_club,
                        email: lead.email,
                        cuenta_smtp: lead.smtp_asignada_email || '—',
                        envio_exitoso: false,
                        error_smtp: e.message || 'Error de red',
                    });
                    if (this.lzLogEnviadosPaginados.length === 0) {
                        this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length));
                        this.lzLogPageCurrent = 1;
                    }
                }

                // Delay entre envíos (con jitter aleatorio si 🎲 activo)
                if (i < this.lzCola.length - 1 && this.lzMotorEstado === 'ACTIVO') {
                    const baseMs = this.lzDelay * 1000;
                    const ms = this.randomMode
                        ? baseMs + Math.floor(Math.random() * this.lzDelay * 1000) - (this.lzDelay * 500)
                        : baseMs;
                    await this.delay(Math.max(500, ms));
                }
            }

            // Si terminó la cola normalmente
            if (this.lzColaIndex >= this.lzCola.length - 1 && this.lzMotorEstado === 'ACTIVO') {
                this.lzMotorEstado = 'PAUSADO';
            }
            this.lzAbortController = null;
            setTimeout(() => lucide.createIcons(), 100);
        },

        // ─── 5.3 PAUSAR MOTOR ─────────────────────────────────────────────────
        pausarMotor() {
            this.lzMotorEstado = 'PAUSADO';
            if (this.lzAbortController) {
                this.lzAbortController.abort();
                this.lzAbortController = null;
            }
        },

        // ─── 5.4 DETENER MOTOR ────────────────────────────────────────────────
        detenerMotor() {
            this.lzMotorEstado = 'DETENIDO';
            if (this.lzAbortController) {
                this.lzAbortController.abort();
                this.lzAbortController = null;
            }
            this.lzCola = [];
            this.lzColaIndex = 0;
            this.lzLogEnviados = [];
        },

        // ─── Infinite Scroll: Cola ──────────────────────────────────────────
        lzOnColaScroll() {
            const el = document.getElementById('lzColaScroll');
            if (!el) return;
            const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 100;
            if (nearBottom && this.lzColaPaginada.length < this.lzCola.length) {
                this.lzLoadMoreCola();
            }
        },
        lzLoadMoreCola() {
            const next = (this.lzColaPageCurrent + 1) * this.lzColaPageSize;
            if (next >= this.lzColaPaginada.length) {
                const end = Math.min(this.lzCola.length, (this.lzColaPageCurrent + 1) * this.lzColaPageSize + this.lzColaPageSize);
                const start = this.lzColaPageCurrent * this.lzColaPageSize;
                if (start < this.lzCola.length) {
                    const slice = this.lzCola.slice(start, end);
                    this.lzColaPaginada.push(...slice);
                    this.lzColaPageCurrent++;
                }
            }
        },

        // ─── Infinite Scroll: Log ────────────────────────────────────────────
        lzOnLogScroll() {
            const el = document.getElementById('lzLogScroll');
            if (!el) return;
            const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 100;
            if (nearBottom && this.lzLogEnviadosPaginados.length < this.lzLogEnviados.length) {
                this.lzLoadMoreLog();
            }
        },
        lzLoadMoreLog() {
            const start = this.lzLogPageCurrent * this.lzLogPageSize;
            if (start < this.lzLogEnviados.length) {
                const end = Math.min(this.lzLogEnviados.length, start + this.lzLogPageSize);
                const slice = this.lzLogEnviados.slice(start, end);
                this.lzLogEnviadosPaginados.push(...slice);
                this.lzLogPageCurrent++;
            }
        },

        // ─── Guardar delay en BD ───────────────────────────────────────────────
        async lzSaveDelay() {
            const f = new FormData();
            f.append('action', 'update_config');
            f.append('key', 'lanzadera_delay');
            f.append('value', this.lzDelay);
            await fetch('', { method: 'POST', body: f });
        },

        // ─── Helper: delay asíncrono ──────────────────────────────────────────
        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        },

        // ─── SMTP ─────────────────────────────────────────────────────────────
        async loadSmtp() {
            const r = await fetch('api_smtp.php?action=get_accounts');
            const j = await r.json(); if (!j.ok) return;
            let h = '';
            j.accounts.forEach(a => {
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><code class="text-[10px] text-slate-300">' + this.esc(a.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden sm:table-cell text-[10px] text-slate-500">' + this.esc(a.host) + ':' + a.puerto + '</td>'
                   + '<td class="px-3 py-2 text-center text-[10px]"><span class="text-slate-300 font-semibold">' + a.enviados_hoy + '</span><span class="text-slate-600"> / ' + a.limite_diario + '</span></td>'
                   + '<td class="px-3 py-2 text-center">'
                   + (a.activa == 1 ? '<span class="bg-emerald-500/15 text-emerald-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">ON</span>' : '<span class="bg-slate-700 text-slate-500 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">OFF</span>')
                   + ' ' + (a.ultimo_error ? '<span class="bg-rose-500/15 text-rose-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-help" title="' + this.esc(a.ultimo_error) + '">!</span>' : '<span class="bg-emerald-500/15 text-emerald-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">OK</span>')
                   + '</td>'
                   + '<td class="px-3 py-2 text-right"><div class="flex gap-1 justify-end">'
                   + '<button class="px-2 py-1 bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 rounded text-[10px] hover:bg-cyan-500/20 transition" onclick="window.app.testSmtp(' + a.id + ',this)"><i data-lucide="zap" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded text-[10px] hover:bg-amber-500/20 transition" onclick="window.app.toggleSmtp(' + a.id + ')"><i data-lucide="power" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded text-[10px] hover:bg-blue-500/20 transition" onclick="window.app.openSmtp(' + a.id + ')"><i data-lucide="pencil" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded text-[10px] hover:bg-rose-500/20 transition" onclick="window.app.deleteSmtp(' + a.id + ')"><i data-lucide="trash-2" class="w-3 h-3"></i></button>'
                   + '</div></td></tr>';
            });
            document.getElementById('smtpBody').innerHTML = h || '<tr><td colspan="5" class="px-3 py-8 text-center text-slate-600">Sin cuentas</td></tr>';
            setTimeout(() => lucide.createIcons(), 50);
        },
        async openSmtp(id) {
            this.se = id;
            if (id > 0) {
                const r = await fetch('api_smtp.php?action=get_accounts');
                const j = await r.json();
                const a = j.accounts.find(x => x.id == id);
                if (a) {
                    this.sf = { email: a.email, host: a.host, puerto: a.puerto, usuario: a.usuario, password: a.password, seguridad: a.seguridad, limite_diario: a.limite_diario, nombre_emisor: a.nombre_emisor || '', cargo_emisor: a.cargo_emisor || '' };
                }
            } else {
                this.sf = { email: '', host: 'mail.getfutprotec.com', puerto: 465, usuario: '', password: '', seguridad: 'ssl', limite_diario: 50 };
            }
            this.sm = true; setTimeout(() => lucide.createIcons(), 100);
        },
        async saveSmtp() {
            const f = new FormData(); f.append('action', 'save_account');
            if (this.se > 0) f.append('id', this.se);
            f.append('email', this.sf.email); f.append('host', this.sf.host); f.append('puerto', this.sf.puerto);
            f.append('usuario', this.sf.usuario); f.append('password', this.sf.password);
            f.append('seguridad', this.sf.seguridad); f.append('limite_diario', this.sf.limite_diario);
            f.append('nombre_emisor', this.sf.nombre_emisor || ''); f.append('cargo_emisor', this.sf.cargo_emisor || '');
            const r = await fetch('api_smtp.php', { method: 'POST', body: f });
            const j = await r.json();
            if (j.ok) { this.sm = false; this.loadSmtp(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        async toggleSmtp(id) { const f = new FormData(); f.append('action', 'toggle_account'); f.append('id', id); await fetch('api_smtp.php', { method: 'POST', body: f }); this.loadSmtp(); },
        async deleteSmtp(id) { if (!confirm('Eliminar esta cuenta SMTP?')) return; const f = new FormData(); f.append('action', 'delete_account'); f.append('id', id); const r = await fetch('api_smtp.php', { method: 'POST', body: f }); const j = await r.json(); if (j.ok) this.loadSmtp(); else alert('Error: ' + (j.error || 'Desconocido')); },
        async testSmtp(id, btn) {
            if (btn) { btn.disabled = true; const orig = btn.innerHTML; btn.innerHTML = '<span class="w-3 h-3 border-2 border-cyan-400 border-t-transparent rounded-full animate-spin inline-block"></span>'; }
            const f = new FormData(); f.append('action', 'test_smtp'); f.append('id', id);
            const r = await fetch('api_smtp.php', { method: 'POST', body: f });
            const j = await r.json();
            const ok = j.status === 'success';
            alert((ok ? 'CONEXION EXITOSA: ' : 'ERROR: ') + j.message);
            if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            this.loadSmtp();
        },

        // ─── Util ─────────────────────────────────────────────────────────────
        esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    };
    window.app = i;
    return i;
};
</script>
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
        <script src="https://cdn.tailwindcss.com"></script>
        <script>tailwind.config = { darkMode: 'class' };</script>
        <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
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
                    <input type="password" name="password"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 text-center mt-1 focus:outline-none focus:border-amber-500/50"
                        placeholder="........" required autofocus>
                </div>
                <button type="submit" class="w-full py-2.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">
                    Acceder al Panel
                </button>
            </form>
        </div>
    </body>
    </html>
    <?php
}