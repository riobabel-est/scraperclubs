<?php
/**
 * analytics.php — Endpoints AJAX de analytics, follow-ups y respuestas del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * Requiere: sqlFiltroComercial(), clasificarRespuesta(), CLASIFICACIONES_VALIDAS
 *           (inc/eligibilidad.php → inc/respuestas.php) y calcularMetricas()
 *           (inc/metricas.php) cargados por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── get_last_envios ─────────────────────────────────────────────────────────
if ($action === 'get_last_envios') {
    header('Content-Type: application/json');
    // HISTÓRICO COMERCIAL: solo envíos REALES (excluye TEST).
    $res = $db->query("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado FROM envios e WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY e.id DESC LIMIT 10");
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
    // Regla central de exclusión de leads TEST (espejo de esLeadTest()).
    $whereCommercial = $excluirTest ? "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')" : '';

    // ─── F4.1: No respondedores ──────────────────────────────────────────
    // Leads en estado Contactado, con envíos, sin respuesta, no rebotados, no baja
    $noRespondedores = [];
    $sqlNR = "SELECT c.id, c.nombre_club, c.email, c.persona_contacto, c.estado_lead,
        (SELECT MAX(e.fecha_envio) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) as ultimo_envio,
        (SELECT e.asunto FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0 ORDER BY e.id DESC LIMIT 1) as ultimo_asunto,
        (SELECT MAX(a.fecha_apertura) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) as ultima_apertura,
        (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) as num_envios,
        (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) as num_aperturas,
        c.proxima_accion, c.ultimo_contacto
    FROM clubes_crm c
    WHERE c.estado_lead = '02 Contactado'
    {$whereCommercial}
    AND c.estado_lead NOT IN ('Baja / Opt-Out','Opt-Out','Unsubscribed','Lista Negra')
    AND EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0)
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
        $where = "AND r.clasificacion = '" . $db->escapeString(strtoupper($filtro)) . "'";
    }
    $sql = "
        SELECT r.id, r.envio_id, r.fecha_respuesta, r.remitente, r.subject AS subject_respuesta,
               r.clasificacion, r.estado_procesamiento,
               e.club, e.email, e.campaign_id, e.variant, e.fecha_envio, e.asunto AS asunto_envio,
               p.nombre AS campaña_nombre
        FROM respuestas r
        JOIN envios e ON e.id = r.envio_id
        LEFT JOIN pipelines p ON p.id = e.campaign_id
        WHERE 1=1" . sqlFiltroComercial('e') . "
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
        // HISTÓRICO COMERCIAL: solo envíos REALES (excluye TEST).
        $data['total'] = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado='enviado'" . sqlFiltroComercial('e'));
        $data['hoy']   = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE DATE(e.fecha_envio)=DATE('now')" . sqlFiltroComercial('e'));
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado, e.asunto, e.cuerpo_mensaje FROM envios e WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY e.id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'aperturas') {
        // Aperturas comerciales: solo de envíos REALES.
        $data['total']    = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
        $data['hoy']      = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE DATE(a.fecha_apertura)=DATE('now')" . sqlFiltroComercial('e'));
        $data['ultimos']  = [];
        $r2 = $db->query("SELECT a.*, e.club, e.email FROM aperturas a LEFT JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY a.id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'rebotes') {
        // Rebotes comerciales: solo de envíos REALES. rebotes se une por email (esquema LIVE).
        $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT r.*, e.club, e.email FROM rebotes r LEFT JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY r.id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    } elseif ($tab === 'bajas') {
        // Bajas comerciales: excluye leads TEST (regla central esLeadTest).
        $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
        $data['ultimos'] = [];
        $r2 = $db->query("SELECT id, nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%') ORDER BY id DESC LIMIT 50");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }

    } elseif ($tab === 'dashboard') {
        $pipeline = $_GET['pipeline'] ?? '';
        $variante = $_GET['variante'] ?? '';
        $excluirTest = ($_GET['excluir_test'] ?? '1') !== '0';
        // Regla central de exclusión de leads TEST (espejo de esLeadTest()).
        $whereCommercial = $excluirTest ? "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')" : '';
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
        // 2. Entregados = Contactados - Rebotes (solo envíos REALES). rebotes se une por email.
        $cntRebotesContactados = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email) = LOWER(c.email) JOIN envios e ON LOWER(e.email) = LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 AND {$stageOrder} >= 2 {$whereCommercial}");
        $cntEntregados = max($cntContactados - $cntRebotesContactados, 0);
        // 3. Abrieron (leads con al menos una apertura de envío REAL)
        $cntAbrieron = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email) = LOWER(c.email) JOIN aperturas a ON a.tracking_id = e.tracking_id WHERE COALESCE(e.es_test,0)=0 {$whereCommercial}");
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
            // Entregados (con envío REAL, sin rebote)
            $cv['entregados'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN envios e ON LOWER(e.email)=LOWER(c.email) WHERE e.estado='enviado' AND COALESCE(e.es_test,0)=0 {$whereCommercial} {$vWhere}");
            $cv['rebotes'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN rebotes r ON LOWER(r.email)=LOWER(c.email) JOIN envios e ON LOWER(e.email)=LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 {$whereCommercial} {$vWhere}");
            // Aperturas (solo de envíos REALES)
            $cv['aperturas'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN lead_pipelines lp ON lp.lead_id=c.id JOIN envios e ON LOWER(e.email)=LOWER(c.email) JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE COALESCE(e.es_test,0)=0 {$whereCommercial} {$vWhere}");
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
