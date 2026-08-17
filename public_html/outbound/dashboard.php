<?php
/**
 * dashboard.php — Panel CRM Kanban v2.0 FutProtec.
 * Tailwind CSS + Alpine.js + Lucide Icons. Modo Oscuro.
 * PHP 8.x nativo — SiteGround compatible.
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ═══════════════ AUTENTICACIÓN PARA ENDPOINTS AJAX ════════════════════════════
// Todos los endpoints AJAX requieren autenticación previa
if (!empty($action) && empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

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
            // Obtener estado anterior antes de actualizar
            $estadoAnterior = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$id}");
            $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->bindValue(':val', $value, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            // Registrar cambio de estado en comunicaciones_log (trazabilidad)
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
            VALUES (:n, :e, :f, :m, :fi, :p, :c, :wa, '01 Sin Contactar')");
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
    if ($row) {
        $mockup = $db->querySingle("SELECT * FROM mockups WHERE lead_id = {$id} ORDER BY id DESC LIMIT 1", true);
        $row['mockup'] = $mockup ?: null;
        $presupuesto = $db->querySingle("SELECT * FROM presupuestos WHERE lead_id = {$id} ORDER BY version DESC LIMIT 1", true);
        $row['presupuesto'] = $presupuesto ?: null;
    }
    echo json_encode($row ?: null);
    exit;
}

// ─── mockup_capacity ─────────────────────────────────────────────────────────
if ($action === 'mockup_capacity') {
    header('Content-Type: application/json');
    $semanaInicio = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $solicitadosSemana = (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE solicitado_en >= '{$semanaInicio}'");
    $enProduccion = (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE estado='solicitado'");
    $enviados = (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE estado='enviado'");
    $capacidad = 100;
    $restante = max(0, $capacidad - $solicitadosSemana);
    $pctUtilizado = $capacidad > 0 ? round($solicitadosSemana / $capacidad * 100, 1) : 0;
    echo json_encode([
        'ok' => true,
        'solicitados_semana' => $solicitadosSemana,
        'en_produccion' => $enProduccion,
        'enviados' => $enviados,
        'capacidad_semanal' => $capacidad,
        'restante' => $restante,
        'pct_utilizado' => $pctUtilizado,
        'alerta_80' => $pctUtilizado >= 80,
        'alerta_95' => $pctUtilizado >= 95,
    ]);
    exit;
}

// ─── mockup_solicitar ────────────────────────────────────────────────────────
if ($action === 'mockup_solicitar') {
    header('Content-Type: application/json');
    try {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId <= 0) { echo json_encode(['ok'=>false,'error'=>'lead_id requerido']); exit; }
        $db->exec("INSERT INTO mockups (lead_id, pipeline_id, estado, solicitado_en) VALUES ({$leadId}, NULL, 'solicitado', CURRENT_TIMESTAMP)");
        $db->exec("UPDATE clubes_crm SET estado_lead = '06 Propuesta', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");
        $db->exec("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES ({$leadId}, {$leadId}, 'mockup_solicitado', 'Mockup solicitado', CURRENT_TIMESTAMP)");
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ─── mockup_enviado ──────────────────────────────────────────────────────────
if ($action === 'mockup_enviado') {
    header('Content-Type: application/json');
    try {
        $mockupId = (int)($_POST['mockup_id'] ?? 0);
        if ($mockupId <= 0) { echo json_encode(['ok'=>false,'error'=>'mockup_id requerido']); exit; }
        $db->exec("UPDATE mockups SET estado = 'enviado', enviado_en = CURRENT_TIMESTAMP WHERE id = {$mockupId}");
        $row = $db->querySingle("SELECT lead_id FROM mockups WHERE id = {$mockupId}", true);
        if ($row) {
            $db->exec("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES ({$row['lead_id']}, {$row['lead_id']}, 'mockup_enviado', 'Mockup enviado al club', CURRENT_TIMESTAMP)");
        }
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ─── get_interacciones ───────────────────────────────────────────────────────
if ($action === 'get_interacciones') {
    header('Content-Type: application/json');
    $leadId = (int)($_GET['lead_id'] ?? 0);
    if ($leadId <= 0) { echo json_encode(['ok'=>false,'error'=>'lead_id requerido']); exit; }
    $interacciones = [];
    $res = $db->query("SELECT * FROM comunicaciones_log WHERE lead_id = {$leadId} ORDER BY fecha DESC LIMIT 30");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $interacciones[] = $r; }
    echo json_encode(['ok' => true, 'interacciones' => $interacciones]);
    exit;
}

// ─── registrar_interaccion ───────────────────────────────────────────────────
if ($action === 'registrar_interaccion') {
    header('Content-Type: application/json');
    try {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $canal  = $_POST['canal'] ?? 'email';
        $tipoEvento = $_POST['tipo_evento'] ?? 'nota_manual';
        $resumen = $_POST['resumen'] ?? '';
        $resultado = $_POST['resultado'] ?? '';
        $proximaAccion = $_POST['proxima_accion'] ?? '';
        if ($leadId <= 0 || $resumen === '') {
            echo json_encode(['ok'=>false,'error'=>'lead_id y resumen obligatorios']);
            exit;
        }
        $stmt = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, canal, resumen, resultado, proxima_accion, fecha)
             VALUES (:lid, :cid, :tipo, :det, :canal, :res, :resul, :prox, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':lid', $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':tipo', $tipoEvento, SQLITE3_TEXT);
        $stmt->bindValue(':det', $resumen, SQLITE3_TEXT);
        $stmt->bindValue(':canal', $canal, SQLITE3_TEXT);
        $stmt->bindValue(':res', $resumen, SQLITE3_TEXT);
        $stmt->bindValue(':resul', $resultado, SQLITE3_TEXT);
        $stmt->bindValue(':prox', $proximaAccion, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ─── snapshot_crear ──────────────────────────────────────────────────────────
if ($action === 'snapshot_crear') {
    header('Content-Type: application/json');
    try {
        $stageOrder = "CASE estado_lead
            WHEN '01 Sin Contactar' THEN 1 WHEN '02 Contactado' THEN 2
            WHEN '03 Respondió' THEN 4 WHEN '04 Interesado' THEN 5
            WHEN '05 Cualificado' THEN 6 WHEN '06 Propuesta' THEN 7
            WHEN '07 Negociación' THEN 8 WHEN '08 Ganado' THEN 9
            WHEN '09 Perdido' THEN 10 ELSE 0 END";
        $total = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");
        $sinContactar = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 1");
        $contactado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 2");
        $respondio = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 4");
        $interesado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 5");
        $cualificado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE volumen_estimado >= 50 AND {$stageOrder} >= 6");
        $propuesta = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 7");
        $negociacion = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 8");
        $ganado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 9");
        $perdido = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE {$stageOrder} = 10");
        $rebotado = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes");
        $baja = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra','Baja / Opt-Out')");

        $stmt = $db->prepare(
            "INSERT INTO snapshots (fecha, total_leads, sin_contactar, contactado, respondio, interesado, cualificado, propuesta, negociacion, ganado, perdido, rebotado, baja_optout, metadata)
             VALUES (CURRENT_TIMESTAMP, :tot, :sc, :co, :re, :in, :cu, :pr, :ne, :ga, :pe, :rb, :ba, :meta)"
        );
        $stmt->bindValue(':tot', $total, SQLITE3_INTEGER);
        $stmt->bindValue(':sc', $sinContactar, SQLITE3_INTEGER);
        $stmt->bindValue(':co', $contactado, SQLITE3_INTEGER);
        $stmt->bindValue(':re', $respondio, SQLITE3_INTEGER);
        $stmt->bindValue(':in', $interesado, SQLITE3_INTEGER);
        $stmt->bindValue(':cu', $cualificado, SQLITE3_INTEGER);
        $stmt->bindValue(':pr', $propuesta, SQLITE3_INTEGER);
        $stmt->bindValue(':ne', $negociacion, SQLITE3_INTEGER);
        $stmt->bindValue(':ga', $ganado, SQLITE3_INTEGER);
        $stmt->bindValue(':pe', $perdido, SQLITE3_INTEGER);
        $stmt->bindValue(':rb', $rebotado, SQLITE3_INTEGER);
        $stmt->bindValue(':ba', $baja, SQLITE3_INTEGER);
        $stmt->bindValue(':meta', json_encode(['timestamp' => date('c')]), SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID(), 'total' => $total]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ─── presupuesto_crear ──────────────────────────────────────────────────────
if ($action === 'presupuesto_crear') {
    header('Content-Type: application/json');
    try {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $condiciones = $_POST['condiciones_pago'] ?? '50%+50%';
        if ($leadId <= 0) { echo json_encode(['ok'=>false,'error'=>'lead_id requerido']); exit; }
        $club = $db->querySingle("SELECT volumen_estimado FROM clubes_crm WHERE id = {$leadId}", true);
        $volumen = (int)($club['volumen_estimado'] ?? 0);
        if ($volumen < 50) { echo json_encode(['ok'=>false,'error'=>'Volumen minimo 50 pares']); exit; }
        $calc = calcularPrecioYMargenLocal($volumen, 15);
        $precioUnit = $calc['precio_b2b'];
        $subtotal = $volumen * $precioUnit;
        $descuento = ($condiciones === '100% adelantado') ? round($subtotal * 0.05, 2) : 0;
        $total = $subtotal - $descuento;
        $lastVer = (int)$db->querySingle("SELECT COALESCE(MAX(version),0) FROM presupuestos WHERE lead_id = {$leadId}");
        $version = $lastVer + 1;
        $stmt = $db->prepare("INSERT INTO presupuestos (lead_id, pipeline_id, version, unidades, precio_unitario, subtotal, descuento_aplicado, condiciones_pago, transporte, importe_total, margen_potencial_club, estado, fecha) VALUES (:lid, NULL, :ver, :uni, :pu, :sub, :desc, :cp, 'Incluido Peninsula', :tot, :mar, 'creado', CURRENT_TIMESTAMP)");
        $stmt->bindValue(':lid', $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':ver', $version, SQLITE3_INTEGER);
        $stmt->bindValue(':uni', $volumen, SQLITE3_INTEGER);
        $stmt->bindValue(':pu', $precioUnit, SQLITE3_FLOAT);
        $stmt->bindValue(':sub', $subtotal, SQLITE3_FLOAT);
        $stmt->bindValue(':desc', $descuento, SQLITE3_FLOAT);
        $stmt->bindValue(':cp', $condiciones, SQLITE3_TEXT);
        $stmt->bindValue(':tot', $total, SQLITE3_FLOAT);
        $stmt->bindValue(':mar', $calc['margen_total'], SQLITE3_FLOAT);
        $stmt->execute();
        $newId = $db->lastInsertRowID();
        $db->exec("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES ({$leadId}, {$leadId}, 'presupuesto_creado', 'Presupuesto v{$version} creado: {$volumen} pares x {$precioUnit}€ = {$total}€', CURRENT_TIMESTAMP)");
        echo json_encode(['ok'=>true,'id'=>$newId,'version'=>$version,'total'=>$total,'unidades'=>$volumen,'precio_unitario'=>$precioUnit]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ─── save_template ───────────────────────────────────────────────────────────
if ($action === 'save_template') {
    header('Content-Type: application/json');
    try {
        $id  = (int)($_POST['id'] ?? 0);

        // Congelación de plantillas (FASE 3A AJUSTE 2): no sobrescribir una
        // plantilla usada por una campaña PILOT/ACTIVE. El snapshot histórico
        // sigue siendo la fuente del mensaje; esta regla evita mezclar dos
        // contenidos bajo el mismo plantilla_id.
        if ($id > 0 && plantillaEstaCongelada($db, $id)) {
            echo json_encode(['ok' => false, 'error' => 'Plantilla congelada (usada por campaña PILOT/ACTIVE). Crea una nueva plantilla.']);
            exit;
        }

        $n   = $_POST['nombre'] ?? '';
        $a   = $_POST['asunto'] ?? '';
        $ab  = $_POST['asunto_b'] ?? '';
        $ac  = $_POST['asunto_c'] ?? '';
        $c   = $_POST['cuerpo'] ?? '';
        $cb  = $_POST['cuerpo_b'] ?? '';
        $cc  = $_POST['cuerpo_c'] ?? '';
        $t   = $_POST['tipo'] ?? 'html';
        $cat = $_POST['categoria'] ?? 'prospeccion';
        $act = $_POST['activo'] ?? 1;
        $tab = (int)($_POST['test_ab'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE plantillas SET nombre = :n, asunto = :a, asunto_b = :ab, asunto_c = :ac, cuerpo = :c, cuerpo_b = :cb, cuerpo_c = :cc, tipo = :t, categoria = :cat, activo = :act, test_ab = :tab WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':ab', $ab, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $ac, SQLITE3_TEXT);
            $stmt->bindValue(':tab', $tab, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare("INSERT INTO plantillas (nombre, asunto, asunto_b, asunto_c, cuerpo, tipo, categoria, activo, test_ab, fecha_creacion) VALUES (:n, :a, :ab, :ac, :c, :t, :cat, :act, :tab, DATETIME('now'))");
            $stmt->bindValue(':ab', $ab, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $ac, SQLITE3_TEXT);
            $stmt->bindValue(':tab', $tab, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':n',   $n,   SQLITE3_TEXT);
        $stmt->bindValue(':a',   $a,   SQLITE3_TEXT);
        $stmt->bindValue(':c',   $c,   SQLITE3_TEXT);
        $stmt->bindValue(':cb',  $cb,  SQLITE3_TEXT);
        $stmt->bindValue(':cc',  $cc,  SQLITE3_TEXT);
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
    $sql = "SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria, activo FROM plantillas";
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

// ─── get_followups (F4.1 + F4.2 + F4.3) ───────────────────────────────────
if ($action === 'get_followups') {
    header('Content-Type: application/json');
    $excluirTest = ($_GET['excluir_test'] ?? '1') !== '0';
    $whereCommercial = $excluirTest ? "AND c.nombre_club NOT LIKE '%TEST%'" : '';

    // ─── F4.1: No respondedores ──────────────────────────────────────────
    // Leads en estado Contactado, con envíos, sin respuesta, no rebotados, no baja
    $noRespondedores = [];
    $sqlNR = "SELECT c.id, c.nombre_club, c.email, c.persona_contacto, c.estado_lead,
        (SELECT MAX(e.fecha_envio) FROM envios e WHERE LOWER(e.email) = LOWER(c.email)) as ultimo_envio,
        (SELECT e.asunto FROM envios e WHERE LOWER(e.email) = LOWER(c.email) ORDER BY e.id DESC LIMIT 1) as ultimo_asunto,
        (SELECT MAX(a.fecha_apertura) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email)) as ultima_apertura,
        (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email)) as num_envios,
        (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email)) as num_aperturas,
        c.proxima_accion, c.ultimo_contacto
    FROM clubes_crm c
    WHERE c.estado_lead = '02 Contactado'
    {$whereCommercial}
    AND c.estado_lead NOT IN ('Baja / Opt-Out','Opt-Out','Unsubscribed','Lista Negra')
    AND EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email))
    AND NOT EXISTS (SELECT 1 FROM comunicaciones_log cl WHERE cl.lead_id = c.id AND cl.tipo_evento = 'cambio_estado' AND cl.detalles LIKE '%Respondió%')
    ORDER BY c.ultimo_contacto DESC";
    $resNR = $db->query($sqlNR);
    while ($r = $resNR->fetchArray(SQLITE3_ASSOC)) {
        // Calcular dias desde ultimo contacto
        $r['dias_desde_contacto'] = $r['ultimo_contacto'] ? (int)round((time() - strtotime($r['ultimo_contacto'])) / 86400) : null;
        $r['dias_desde_envio'] = $r['ultimo_envio'] ? (int)round((time() - strtotime($r['ultimo_envio'])) / 86400) : null;
        $r['tiene_apertura'] = $r['ultima_apertura'] ? true : false;
        $noRespondedores[] = $r;
    }

    // ─── F4.2: Leads sin proxima accion ──────────────────────────────────
    $sinProximaAccion = [];
    $sqlSPA = "SELECT c.id, c.nombre_club, c.email, c.estado_lead, c.volumen_estimado,
        c.proxima_accion, c.ultimo_contacto
    FROM clubes_crm c
    WHERE (c.proxima_accion IS NULL OR c.proxima_accion = '')
    {$whereCommercial}
    AND c.estado_lead IN ('03 Respondió','04 Interesado','05 Cualificado','06 Propuesta','07 Negociación')
    ORDER BY c.ultimo_contacto DESC";
    $resSPA = $db->query($sqlSPA);
    while ($r = $resSPA->fetchArray(SQLITE3_ASSOC)) {
        $r['dias_desde_contacto'] = $r['ultimo_contacto'] ? (int)round((time() - strtotime($r['ultimo_contacto'])) / 86400) : null;
        $pres = $db->querySingle("SELECT importe_total FROM presupuestos WHERE lead_id = {$r['id']} ORDER BY version DESC LIMIT 1", true);
        $r['presupuesto_importe'] = $pres ? $pres['importe_total'] : null;
        $sinProximaAccion[] = $r;
    }

    // ─── F4.3: KPIs Operativos ───────────────────────────────────────────
    // Mockups pendientes
    $mockupsPendientes = (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE estado IN ('solicitado','en_produccion')");

    // Presupuestos pendientes (asumiendo estado = 'creado')
    $presupuestosPendientes = (int)$db->querySingle("SELECT COUNT(*) FROM presupuestos WHERE estado = 'creado'");

    $kpisOperativos = [
        'mockups_pendientes' => $mockupsPendientes,
        'presupuestos_pendientes' => $presupuestosPendientes,
        'no_respondedores' => count($noRespondedores),
        'sin_proxima_accion' => count($sinProximaAccion),
    ];

    echo json_encode([
        'ok' => true,
        'no_respondedores' => $noRespondedores,
        'sin_proxima_accion' => $sinProximaAccion,
        'kpis' => $kpisOperativos
    ]);
    exit;
}

// ─── get_respuestas ──────────────────────────────────────────────────────────
if ($action === 'get_respuestas') {
    header('Content-Type: application/json');
    $filtro = trim($_GET['clasificacion'] ?? '');
    $where = '';
    if ($filtro !== '' && in_array(strtoupper($filtro), CLASIFICACIONES_VALIDAS, true)) {
        $where = "WHERE r.clasificacion = '" . $db->escapeString(strtoupper($filtro)) . "'";
    }
    $sql = "
        SELECT r.id, r.envio_id, r.fecha_respuesta, r.remitente, r.subject AS subject_respuesta,
               r.clasificacion, r.estado_procesamiento,
               e.club, e.email, e.campaign_id, e.variant, e.fecha_envio, e.asunto AS asunto_envio,
               p.nombre AS campaña_nombre
        FROM respuestas r
        JOIN envios e ON e.id = r.envio_id
        LEFT JOIN pipelines p ON p.id = e.campaign_id
        {$where}
        ORDER BY r.fecha_respuesta DESC
        LIMIT 200";
    $res = $db->query($sql);
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    echo json_encode(['ok' => true, 'respuestas' => $items]);
    exit;
}

// ─── get_respuesta ───────────────────────────────────────────────────────────
if ($action === 'get_respuesta') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'id requerido']); exit; }
    $resp = $db->querySingle("SELECT * FROM respuestas WHERE id = {$id}", true);
    if (!$resp) { echo json_encode(['ok' => false, 'error' => 'no encontrada']); exit; }
    $envio = $db->querySingle(
        "SELECT e.*, p.nombre AS campaña_nombre FROM envios e LEFT JOIN pipelines p ON p.id = e.campaign_id WHERE e.id = " . (int)$resp['envio_id'],
        true
    );
    echo json_encode(['ok' => true, 'respuesta' => $resp, 'envio' => $envio]);
    exit;
}

// ─── clasificar_respuesta ────────────────────────────────────────────────────
if ($action === 'clasificar_respuesta') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $clasif = strtoupper(trim($_POST['clasificacion'] ?? ''));
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'id requerido']); exit; }
    $res = clasificarRespuesta($db, $id, $clasif);
    echo json_encode($res);
    exit;
}

// ─── get_piloto_metricas (FASE 5B) ──────────────────────────────────────────
if ($action === 'get_piloto_metricas') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['campaign_id'] ?? $_GET['id_campana'] ?? 0);
    echo json_encode(calcularMetricas($db, $cid));
    exit;
}

// ─── get_piloto_campanas (FASE 5D) ──────────────────────────────────────────
if ($action === 'get_piloto_campanas') {
    header('Content-Type: application/json');
    $campos = [];
    $res = $db->query("SELECT id, nombre, identificador, estado, entorno, activo FROM pipelines ORDER BY id ASC");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $campos[] = $r;
    }
    echo json_encode(['ok' => true, 'campanas' => $campos]);
    exit;
}

// ─── get_analytics ───────────────────────────────────────────────────────────
if ($action === 'get_analytics') {
    header('Content-Type: application/json');
    $tab = $_GET['tab'] ?? 'envios';
    $data = ['ok' => true, 'tab' => $tab];
    if ($tab === 'envios') {
        $data['total'] = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE estado='enviado'");
        $data['hoy']   = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE DATE(fecha_envio)=DATE('now')");
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT id, club, email, cuenta_emision, fecha_envio, estado, asunto, cuerpo_mensaje FROM envios ORDER BY id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'aperturas') {
        $data['total']    = (int)$db->querySingle("SELECT COUNT(DISTINCT tracking_id) FROM aperturas");
        $data['hoy']      = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas WHERE DATE(fecha_apertura)=DATE('now')");
        $data['ultimos']  = [];
        $r2 = $db->query("SELECT a.*, e.club, e.email FROM aperturas a LEFT JOIN envios e ON a.tracking_id=e.tracking_id ORDER BY a.id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'rebotes') {
        $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes");
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT * FROM rebotes ORDER BY id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'bajas') {
        $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra')");
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT id, nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') ORDER BY id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'dashboard') {
        $pipeline = $_GET['pipeline'] ?? '';
        $variante = $_GET['variante'] ?? '';
        $excluirTest = ($_GET['excluir_test'] ?? '1') !== '0';
        $whereCommercial = $excluirTest ? "AND c.nombre_club NOT LIKE '%TEST%'" : '';
        $wherePipeline = $pipeline ? "AND lp.pipeline_id = " . (int)$pipeline : '';
        $whereVariante = $variante ? "AND lp.variante_ab = '" . $db->escapeString($variante) . "'" : '';

        // Helper: stage_order
        $stageOrder = "CASE c.estado_lead
            WHEN '01 Sin Contactar' THEN 1 WHEN '02 Contactado' THEN 2
            WHEN '03 Respondió' THEN 4 WHEN '04 Interesado' THEN 5
            WHEN '05 Cualificado' THEN 6 WHEN '06 Propuesta' THEN 7
            WHEN '07 Negociación' THEN 8 WHEN '08 Ganado' THEN 9
            WHEN '09 Perdido' THEN 10 ELSE 0 END";

        // F3.1 — Funnel 12 niveles (spec V4.3)
        // 1. Contactados
        $cntTotal = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE 1=1 {$whereCommercial}");
        $cntContactados = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 2 {$whereCommercial}");
        // 2. Entregados = Contactados - Rebotes
        $cntRebotesContactados = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email) = LOWER(c.email) WHERE {$stageOrder} >= 2 {$whereCommercial}");
        $cntEntregados = max($cntContactados - $cntRebotesContactados, 0);
        // 3. Abrieron (leads con al menos una apertura)
        $cntAbrieron = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email) = LOWER(c.email) JOIN aperturas a ON a.tracking_id = e.tracking_id WHERE 1=1 {$whereCommercial}");
        // 4. Respondieron
        $cntRespondio = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 4 {$whereCommercial}");
        // 5. Respuestas positivas
        $cntInteresado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 5 {$whereCommercial}");
        // 6. Cualificados
        $cntCualificado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE volumen_estimado >= 50 AND {$stageOrder} >= 6 {$whereCommercial}");
        // 7. Oportunidades
        $cntPropuesta = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 7 {$whereCommercial}");
        // 8. Mockups enviados (DISTINCT lead_id)
        $cntMockups = (int)$db->querySingle("SELECT COUNT(DISTINCT m.lead_id) FROM mockups m JOIN clubes_crm c ON m.lead_id=c.id WHERE m.estado='enviado' {$whereCommercial}");
        // 9. Presupuestos (DISTINCT lead_id)
        $cntPresupuestos = (int)$db->querySingle("SELECT COUNT(DISTINCT p.lead_id) FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id WHERE 1=1 {$whereCommercial}");
        // 10. Negociaciones
        $cntNegociacion = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 8 {$whereCommercial}");
        // 11. Ganados
        $cntGanado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} = 9 {$whereCommercial}");
        // 12. Perdidos
        $cntPerdido = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} = 10 {$whereCommercial}");

        $data['funnel'] = [
            ['nivel'=>'1. Contactados','cnt'=>$cntContactados,'pct'=>100],
            ['nivel'=>'2. Entregados','cnt'=>$cntEntregados,'pct'=>$cntContactados>0?round($cntEntregados/$cntContactados*100,1):0],
            ['nivel'=>'3. Abrieron','cnt'=>$cntAbrieron,'pct'=>$cntEntregados>0?round($cntAbrieron/$cntEntregados*100,1):0],
            ['nivel'=>'4. Respondieron','cnt'=>$cntRespondio,'pct'=>$cntAbrieron>0?round($cntRespondio/$cntAbrieron*100,1):0],
            ['nivel'=>'5. Resp. Positivas','cnt'=>$cntInteresado,'pct'=>$cntRespondio>0?round($cntInteresado/$cntRespondio*100,1):0],
            ['nivel'=>'6. Cualificados','cnt'=>$cntCualificado,'pct'=>$cntInteresado>0?round($cntCualificado/$cntInteresado*100,1):0],
            ['nivel'=>'7. Oportunidades','cnt'=>$cntPropuesta,'pct'=>$cntCualificado>0?round($cntPropuesta/$cntCualificado*100,1):0],
            ['nivel'=>'8. Mockups','cnt'=>$cntMockups,'pct'=>$cntPropuesta>0?round($cntMockups/$cntPropuesta*100,1):0],
            ['nivel'=>'9. Presupuestos','cnt'=>$cntPresupuestos,'pct'=>$cntMockups>0?round($cntPresupuestos/$cntMockups*100,1):0],
            ['nivel'=>'10. Negociaciones','cnt'=>$cntNegociacion,'pct'=>$cntPresupuestos>0?round($cntNegociacion/$cntPresupuestos*100,1):0],
            ['nivel'=>'11. Ganados','cnt'=>$cntGanado,'pct'=>$cntNegociacion>0?round($cntGanado/$cntNegociacion*100,1):0],
            ['nivel'=>'12. Perdidos','cnt'=>$cntPerdido,'pct'=>$cntGanado+$cntPerdido>0?round($cntPerdido/($cntGanado+$cntPerdido)*100,1):0],
        ];

        // KPIs económicos (F3.3) — Solo versión más reciente de presupuesto por lead
        $data['kpi'] = [];
        $ganadosEco = $db->query("SELECT COALESCE(SUM(p.unidades),0) as pares, COALESCE(SUM(p.importe_total),0) as fact, COALESCE(SUM(p.margen_potencial_club),0) as margen FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id JOIN (SELECT lead_id, MAX(version) as max_ver FROM presupuestos GROUP BY lead_id) pmax ON p.lead_id = pmax.lead_id AND p.version = pmax.max_ver WHERE c.estado_lead='08 Ganado' {$whereCommercial}");
        $eco = $ganadosEco->fetchArray(SQLITE3_ASSOC);
        $paresGanados = (int)$eco['pares'];
        $factGanada = (float)$eco['fact'];
        $margenGanado = (float)$eco['margen'];
        $nGanados = max((int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder}=9 {$whereCommercial}"),1);

        $data['kpi'] = [
            'ganados_100' => $cntTotal>0 ? round($cntGanado/$cntTotal*100,2) : 0,
            'fact_100' => $cntTotal>0 ? round($factGanada/$cntTotal*100,0) : 0,
            'pares_100' => $cntTotal>0 ? round($paresGanados/$cntTotal*100,1) : 0,
            'margen_100' => $cntTotal>0 ? round($margenGanado/$cntTotal*100,0) : 0,
            'ticket_medio' => $nGanados>0 ? round($factGanada/$nGanados,0) : 0,
            'pares_medio' => $nGanados>0 ? round($paresGanados/$nGanados,0) : 0,
            'fact_media' => $nGanados>0 ? round($factGanada/$nGanados,0) : 0,
        ];

        // F3.2 / F3.5 — A/B/C comparativa ampliada (spec V4.3)
        $data['abc'] = [];
        $variantes = ['A','B','C'];
        foreach ($variantes as $v) {
            $vWhere = "AND lp.variante_ab='{$v}'";
            $cv = [];
            $cv['variante'] = $v;
            // Leads asignados
            $cv['leads'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE 1=1 {$whereCommercial} {$vWhere}");
            // Entregados (con envío, sin rebote)
            $cv['entregados'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN envios e ON LOWER(e.email)=LOWER(c.email) WHERE e.estado='enviado' {$whereCommercial} {$vWhere}");
            $cv['rebotes'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN rebotes r ON LOWER(r.email)=LOWER(c.email) WHERE 1=1 {$whereCommercial} {$vWhere}");
            // Aperturas
            $cv['aperturas'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN envios e ON LOWER(e.email)=LOWER(c.email) JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE 1=1 {$whereCommercial} {$vWhere}");
            $cv['tasa_apertura'] = $cv['entregados']>0 ? round($cv['aperturas']/$cv['entregados']*100,1) : 0;
            // Respuestas
            $cv['respondio'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}>=4 {$whereCommercial} {$vWhere}");
            $cv['tasa_resp'] = $cv['aperturas']>0 ? round($cv['respondio']/$cv['aperturas']*100,1) : 0;
            // Resp. Positivas
            $cv['interesado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}>=5 {$whereCommercial} {$vWhere}");
            // Cualificados
            $cv['cualificado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE volumen_estimado>=50 AND {$stageOrder}>=6 {$whereCommercial} {$vWhere}");
            // Propuestas
            $cv['propuesta'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}>=7 {$whereCommercial} {$vWhere}");
            // Mockups enviados (DISTINCT)
            $cv['mockups'] = (int)$db->querySingle("SELECT COUNT(DISTINCT m.lead_id) FROM mockups m JOIN clubes_crm c ON m.lead_id=c.id JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE m.estado='enviado' {$whereCommercial} {$vWhere}");
            // Presupuestos (DISTINCT)
            $cv['presupuestos'] = (int)$db->querySingle("SELECT COUNT(DISTINCT p.lead_id) FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE 1=1 {$whereCommercial} {$vWhere}");
            // Negociaciones
            $cv['negociacion'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}>=8 {$whereCommercial} {$vWhere}");
            // Ganados / Perdidos
            $cv['ganado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}=9 {$whereCommercial} {$vWhere}");
            $cv['perdido'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id WHERE {$stageOrder}=10 {$whereCommercial} {$vWhere}");
            $cv['conversion'] = $cv['leads']>0 ? round($cv['ganado']/$cv['leads']*100,1) : 0;
            // Económicos por variante — Solo versión más reciente de presupuesto por lead
            $ecoV = $db->querySingle("SELECT COALESCE(SUM(p.importe_total),0) as fact, COALESCE(SUM(p.unidades),0) as pares FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN (SELECT lead_id, MAX(version) as max_ver FROM presupuestos GROUP BY lead_id) pmax ON p.lead_id = pmax.lead_id AND p.version = pmax.max_ver WHERE c.estado_lead='08 Ganado' {$whereCommercial} {$vWhere}", true);
            $cv['facturacion'] = (int)$ecoV['fact'];
            $cv['pares'] = (int)$ecoV['pares'];
            $cv['ticket_medio'] = $cv['ganado']>0 ? round($cv['facturacion']/$cv['ganado'],0) : 0;
            $cv['fact_100'] = $cv['leads']>0 ? round($cv['facturacion']/$cv['leads']*100,0) : 0;
            $cv['pares_100'] = $cv['leads']>0 ? round($cv['pares']/$cv['leads']*100,1) : 0;
            $data['abc'][] = $cv;
        }
        // Determinar variante ganadora (si hay evidencia suficiente: al menos 5 leads por variante)
        $data['abc_ganadora'] = null;
        $maxConversion = 0;
        foreach ($data['abc'] as $cv) {
            if ($cv['leads'] >= 5 && $cv['conversion'] > $maxConversion) {
                $maxConversion = $cv['conversion'];
                $data['abc_ganadora'] = $cv['variante'];
            }
        }

        // Objetivo 20 clubes
        $data['objetivo'] = [
            'objetivo' => 20,
            'ganados' => $cntGanado,
            'pct' => $cntGanado>0 ? round($cntGanado/20*100,1) : 0,
            'restantes' => max(20-$cntGanado,0),
            'tasa_cierre' => $cntContactados>0 ? round($cntGanado/$cntContactados*100,2) : 0,
            'contactos_necesarios' => $cntGanado>0 ? (int)ceil(20/($cntGanado/$cntContactados))-($cntContactados) : 'Sin datos suficientes',
            'facturacion' => $factGanada,
            'pares' => $paresGanados,
            'margen' => $margenGanado,
        ];

        // Pipeline names para filtros
        $data['pipelines'] = [];
        $rp = $db->query("SELECT id, nombre FROM pipelines WHERE activo=1");
        while ($r = $rp->fetchArray(SQLITE3_ASSOC)) { $data['pipelines'][] = $r; }
    }
    echo json_encode($data);
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

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function calcularPrecioYMargenLocal(int $volumen, int $pvp = 15): array {
    if ($volumen <= 0) return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'Desconocido'];
    if ($volumen >= 200)            [$precio, $tramo] = [7, '200+ pares'];
    elseif ($volumen >= 100)        [$precio, $tramo] = [8, '100-199 pares'];
    elseif ($volumen >= 50)         [$precio, $tramo] = [9, '50-99 pares'];
    else return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'<50 pares'];
    return ['precio_b2b'=>$precio,'facturacion'=>$volumen*$precio,'margen_par'=>$pvp-$precio,'margen_total'=>$volumen*($pvp-$precio),'tramo'=>$tramo];
}

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
        <div class="text-xs text-slate-500 mt-1"><?= $smtpActivas ?> cuentas activas</div>
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

<!-- ═══════════ MODALS ═══════════ -->
<?php
$federacionesSelect = [
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
