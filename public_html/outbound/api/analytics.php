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

/**
 * limpiarCuerpoMime — Extrae el texto plano legible de un cuerpo de correo.
 * Los runners IMAP guardan en `respuestas.cuerpo` el MIME crudo (multi-part,
 * quoted-printable, boundaries). Esta función lo reduce a texto plano limpio
 * para mostrarlo en la Unibox (snippet y visor).
 */
function limpiarCuerpoMime(string $cuerpo): string {
    $t = $cuerpo;

    // 1. Si es MIME multipart, quedarse con la parte text/plain.
    if (stripos($t, 'Content-Type: text/plain') !== false) {
        // Dividir por boundaries MIME (líneas que empiezan por --).
        $partes = preg_split('/\r?\n--[^\r\n]+/i', $t);
        foreach ($partes as $parte) {
            if (stripos($parte, 'Content-Type: text/plain') !== false) {
                // Quitar cabeceras de la parte (hasta la primera línea en blanco).
                $cuerpoParte = preg_split('/\r?\n\r?\n/', $parte, 2);
                $t = $cuerpoParte[1] ?? $parte;
                break;
            }
        }
    }

    // 2. Decodificar quoted-printable (=XX y saltos de línea suaves =).
    //    La marca inequívoca de quoted-printable es un '=' seguido de dos
    //    dígitos hexadecimales (p.ej. =C3=AD → í). Antes solo se detectaba
    //    '=3D'/'=\r\n'/'=\n', lo que dejaba sin decodificar textos como
    //    'env=C3=ADas' (envías). Ahora se detecta cualquier secuencia =XX.
    if (preg_match('/=[0-9A-Fa-f]{2}/', $t)) {
        $t = quoted_printable_decode($t);
    }


    // 3. Decodificar entidades HTML básicas (si el texto quedó escapado).
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 4. Quitar líneas de cita (>) y firmas de correo repetidas.
    $lineas = preg_split('/\r?\n/', $t);
    $limpias = [];
    foreach ($lineas as $linea) {
        $l = trim($linea);
        // Saltar líneas de cita y separadores de firma.
        if ($l === '' || str_starts_with($l, '>') || str_starts_with($l, '--')) {
            continue;
        }
        $limpias[] = $l;
    }
    $t = implode("\n", $limpias);

    // 5. Normalizar espacios y saltos.
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);

    return trim($t);
}

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


// ─── Módulo Seguimiento (P-3/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX) ────────────────
// Funciones puras de la cola de trabajo priorizada + KPIs + embudo.

/**
 * calcularPrioridadLead — Scoring de prioridad comercial de un lead.
 * Reglas (espejo del plan): apertura +30 · ≥7 días +25 · 3-6 días +10 ·
 * volumen ≥50 +15 · volumen 20-49 +10 · presupuesto creado +25 ·
 * estado '04 Propuesta' sin próxima acción +15.
 * Nivel: score ≥50 Alta · ≥25 Media · resto Baja.
 */
function calcularPrioridadLead(array $lead): array {
    $score = 0;
    $dias  = (int)($lead['dias_desde_envio'] ?? $lead['dias_desde_contacto'] ?? -1);
    $volumen = (int)($lead['volumen_estimado'] ?? 0);
    $estado  = (string)($lead['estado_lead'] ?? '');
    $sinProx = empty($lead['proxima_accion']);

    if (!empty($lead['tiene_apertura'])) $score += 30;
    if ($dias >= 7) $score += 25;
    elseif ($dias >= 3) $score += 10;
    if ($volumen >= 50) $score += 15;
    elseif ($volumen >= 20) $score += 10;
    if (!empty($lead['presupuesto_importe'])) $score += 25;
    if ($estado === '04 Propuesta' && $sinProx) $score += 15;
    if (!empty($lead['vencida'])) $score += 15;

    $nivel = $score >= 50 ? 'Alta' : ($score >= 25 ? 'Media' : 'Baja');
    return ['score' => $score, 'nivel' => $nivel];
}

/**
 * whereCampañaLead — Condición SQL de pertenencia de un lead a una campaña.
 * Fuente real de asignación: `lead_pipelines` (asignación explícita) UNION
 * `envios.campaign_id` (asignación por envío real, que es la que puebla el piloto).
 */
function whereCampañaLead(int $cid): string {
    if ($cid <= 0) return '';
    return " AND c.id IN ("
        . "SELECT lp.lead_id FROM lead_pipelines lp WHERE lp.pipeline_id = {$cid}"
        . " UNION "
        . "SELECT c2.id FROM clubes_crm c2 JOIN envios e ON LOWER(e.email) = LOWER(c2.email)"
        . " WHERE e.campaign_id = {$cid} AND COALESCE(e.es_test,0)=0"
        . ")";
}

/**
 * getSeguimientoNoRespondedores — Cola "Perseguir": leads '02 Contactado' con
 * envíos reales, sin respuesta, sin baja. Aplica filtros y scoring de prioridad.
 */
function getSeguimientoNoRespondedores($db, string $whereCommercial, array $filtros): array {
    $lista = [];
    $cid = (int)($filtros['campaign_id'] ?? 0);
    $enviosCamp = $cid > 0 ? " AND e.campaign_id = {$cid}" : '';
    $w = "c.estado_lead = '02 Contactado'"
        . " AND c.estado_lead NOT IN ('Baja / Opt-Out','Opt-Out','Unsubscribed','Lista Negra')"
        . " AND EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0{$enviosCamp})"
        . " AND NOT EXISTS (SELECT 1 FROM comunicaciones_log cl WHERE cl.lead_id = c.id AND cl.tipo_evento = 'cambio_estado' AND cl.detalles LIKE '%En Conversación%')"
        . $whereCommercial;
    if ($cid > 0) {
        $w .= whereCampañaLead($cid);
    }
    if (!empty($filtros['busqueda'])) {
        $q = $db->escapeString($filtros['busqueda']);
        $w .= " AND (c.nombre_club LIKE '%{$q}%' OR LOWER(c.email) LIKE '%" . strtolower($q) . "%')";
    }
    if (!empty($filtros['federacion'])) {
        $w .= " AND c.federacion = '" . $db->escapeString($filtros['federacion']) . "'";
    }
    if ((int)$filtros['dias_min'] > 0) {
        $w .= " AND c.ultimo_contacto IS NOT NULL AND c.ultimo_contacto <= datetime('now','-" . (int)$filtros['dias_min'] . " days')";
    }

    $sql = "SELECT c.id, c.nombre_club, c.email, c.persona_contacto, c.estado_lead, c.federacion,
        c.proxima_accion, c.ultimo_contacto, c.volumen_estimado,
        (SELECT MAX(e.fecha_envio) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0{$enviosCamp}) AS ultimo_envio,
        (SELECT e.asunto FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0{$enviosCamp} ORDER BY e.id DESC LIMIT 1) AS ultimo_asunto,
        (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0{$enviosCamp}) AS num_envios,
        (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0{$enviosCamp}) AS num_aperturas
        FROM clubes_crm c WHERE {$w} ORDER BY c.ultimo_contacto DESC";
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['dias_desde_contacto'] = $r['ultimo_contacto'] ? (int)round((time() - strtotime($r['ultimo_contacto'])) / 86400) : null;
        $r['dias_desde_envio']    = $r['ultimo_envio'] ? (int)round((time() - strtotime($r['ultimo_envio'])) / 86400) : null;
        $r['tiene_apertura']      = (bool)(int)$r['num_aperturas'];
        $p = calcularPrioridadLead($r);
        $r['score'] = $p['score'];
        $r['prioridad'] = $p['nivel'];
        if (!empty($filtros['solo_alta']) && $p['nivel'] !== 'Alta') continue;
        $lista[] = $r;
    }
    usort($lista, static function ($a, $b) {
        $ord = ['Alta' => 0, 'Media' => 1, 'Baja' => 2];
        $oa = $ord[$a['prioridad']] ?? 3;
        $ob = $ord[$b['prioridad']] ?? 3;
        if ($oa !== $ob) return $oa <=> $ob;
        return ($b['dias_desde_envio'] ?? 0) <=> ($a['dias_desde_envio'] ?? 0);
    });
    return $lista;
}

/**
 * getSeguimientoSinProximaAccion — Cola "Avanzar": leads en conversación o
 * propuesta sin próxima acción definida. Aplica filtros y scoring de prioridad.
 */
function getSeguimientoSinProximaAccion($db, string $whereCommercial, array $filtros): array {
    $lista = [];
    // Incluye leads sin próxima acción O con próxima acción vencida (agenda).
    $w = "c.estado_lead IN ('03 En Conversación','04 Propuesta')"
        . " AND (c.proxima_accion IS NULL OR c.proxima_accion = ''"
        . "      OR c.fecha_proxima_accion IS NULL OR c.fecha_proxima_accion <= datetime('now'))"
        . $whereCommercial;
    $cid = (int)($filtros['campaign_id'] ?? 0);
    if ($cid > 0) {
        $w .= whereCampañaLead($cid);
    }
    if (!empty($filtros['busqueda'])) {
        $q = $db->escapeString($filtros['busqueda']);
        $w .= " AND (c.nombre_club LIKE '%{$q}%' OR LOWER(c.email) LIKE '%" . strtolower($q) . "%')";
    }
    if (!empty($filtros['federacion'])) {
        $w .= " AND c.federacion = '" . $db->escapeString($filtros['federacion']) . "'";
    }
    if ((int)$filtros['dias_min'] > 0) {
        $w .= " AND c.ultimo_contacto IS NOT NULL AND c.ultimo_contacto <= datetime('now','-" . (int)$filtros['dias_min'] . " days')";
    }

    $sql = "SELECT c.id, c.nombre_club, c.email, c.estado_lead, c.federacion, c.volumen_estimado,
        c.proxima_accion, c.fecha_proxima_accion, c.ultimo_contacto
        FROM clubes_crm c WHERE {$w} ORDER BY c.ultimo_contacto DESC";
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['dias_desde_contacto'] = $r['ultimo_contacto'] ? (int)round((time() - strtotime($r['ultimo_contacto'])) / 86400) : null;
        if (!empty($r['fecha_proxima_accion'])) {
            $ts = strtotime($r['fecha_proxima_accion']);
            $r['dias_vencida'] = (int)round((time() - $ts) / 86400);
            $r['vencida'] = $ts <= time();
        } else {
            $r['dias_vencida'] = null;
            $r['vencida'] = false;
        }
        $pres = $db->querySingle("SELECT importe_total FROM presupuestos WHERE lead_id = " . (int)$r['id'] . " ORDER BY version DESC LIMIT 1", true);
        $r['presupuesto_importe'] = $pres ? (float)$pres['importe_total'] : null;
        $p = calcularPrioridadLead($r);
        $r['score'] = $p['score'];
        $r['prioridad'] = $p['nivel'];
        if (!empty($filtros['solo_alta']) && $p['nivel'] !== 'Alta') continue;
        $lista[] = $r;
    }
    // Orden: prioridad → vencidos primero → días desc.
    usort($lista, static function ($a, $b) {
        $ord = ['Alta' => 0, 'Media' => 1, 'Baja' => 2];
        $oa = $ord[$a['prioridad']] ?? 3;
        $ob = $ord[$b['prioridad']] ?? 3;
        if ($oa !== $ob) return $oa <=> $ob;
        $va = !empty($a['vencida']) ? 0 : 1;
        $vb = !empty($b['vencida']) ? 0 : 1;
        if ($va !== $vb) return $va <=> $vb;
        return ($b['dias_desde_contacto'] ?? 0) <=> ($a['dias_desde_contacto'] ?? 0);
    });
    return $lista;
}

/**
 * getSeguimientoNuevosSinActividad — Smart View "Calentar": leads nuevos
 * (creados en los últimos 7 días) sin ningún envío todavía. Patrón Close:
 * "build a list of new leads created in the past week without logged activity".
 */
function getSeguimientoNuevosSinActividad($db, string $whereCommercial, array $filtros): array {
    $lista = [];
    $w = "c.estado_lead IN ('01 Sin Contactar','02 Contactado')"
        . " AND c.creado_el >= datetime('now','-7 days')"
        . " AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0)"
        . $whereCommercial;
    $cid = (int)($filtros['campaign_id'] ?? 0);
    if ($cid > 0) {
        $w .= whereCampañaLead($cid);
    }
    if (!empty($filtros['busqueda'])) {
        $q = $db->escapeString($filtros['busqueda']);
        $w .= " AND (c.nombre_club LIKE '%{$q}%' OR LOWER(c.email) LIKE '%" . strtolower($q) . "%')";
    }
    if (!empty($filtros['federacion'])) {
        $w .= " AND c.federacion = '" . $db->escapeString($filtros['federacion']) . "'";
    }

    $sql = "SELECT c.id, c.nombre_club, c.email, c.estado_lead, c.federacion, c.volumen_estimado,
        c.creado_el, c.ultimo_contacto
        FROM clubes_crm c WHERE {$w} ORDER BY c.creado_el DESC";
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['dias_desde_creado'] = $r['creado_el'] ? (int)round((time() - strtotime($r['creado_el'])) / 86400) : null;
        $r['dias_desde_contacto'] = $r['ultimo_contacto'] ? (int)round((time() - strtotime($r['ultimo_contacto'])) / 86400) : null;
        $p = calcularPrioridadLead($r);
        $r['score'] = $p['score'];
        $r['prioridad'] = $p['nivel'];
        if (!empty($filtros['solo_alta']) && $p['nivel'] !== 'Alta') continue;
        $lista[] = $r;
    }
    usort($lista, static function ($a, $b) {
        $ord = ['Alta' => 0, 'Media' => 1, 'Baja' => 2];
        $oa = $ord[$a['prioridad']] ?? 3;
        $ob = $ord[$b['prioridad']] ?? 3;
        if ($oa !== $ob) return $oa <=> $ob;
        return ($b['dias_desde_creado'] ?? 0) <=> ($a['dias_desde_creado'] ?? 0);
    });
    return $lista;
}

/**
 * getSeguimientoKpis — KPIs inteligibles del módulo: colas + tasas reales de
 * apertura/respuesta + trabajo operativo + pipeline en juego (€).
 */
function getSeguimientoKpis($db, string $whereCommercial, array $noRespondedores, array $sinProximaAccion, array $nuevos, int $cid = 0): array {
    $stageOrder = "CASE c.estado_lead WHEN '01 Sin Contactar' THEN 1 WHEN '02 Contactado' THEN 2"
        . " WHEN '03 En Conversación' THEN 3 WHEN '04 Propuesta' THEN 4 WHEN '05 Ganado' THEN 5"
        . " WHEN '06 Perdido' THEN 6 WHEN '07 Baja' THEN 7 ELSE 0 END";
    $whereCamp  = whereCampañaLead($cid);
    $enviosCamp = $cid > 0 ? " AND e.campaign_id = {$cid}" : '';
    $cntContactados = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 2 {$whereCommercial}{$whereCamp}");
    $cntRespondio   = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 3 {$whereCommercial}{$whereCamp}");
    $cntAbrieron    = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email)=LOWER(c.email) JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE COALESCE(e.es_test,0)=0 {$enviosCamp}{$whereCamp}");
    $cntRebotes     = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email)=LOWER(c.email) JOIN envios e ON LOWER(e.email)=LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 AND {$stageOrder} >= 2 {$enviosCamp}{$whereCamp}");
    $entregados     = max($cntContactados - $cntRebotes, 0);

    $mockupsPend  = (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE estado IN ('solicitado','en_produccion')");
    $presPend     = (int)$db->querySingle("SELECT COUNT(*) FROM presupuestos WHERE estado = 'creado'");
    $pipelineVal  = (float)$db->querySingle("SELECT COALESCE(SUM(p.importe_total),0) FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id WHERE p.estado NOT IN ('perdido','rechazado') {$whereCommercial}{$whereCamp}");

    return [
        'no_respondedores'       => count($noRespondedores),
        'sin_proxima_accion'     => count($sinProximaAccion),
        'nuevos_sin_actividad'   => count($nuevos),
        'tasa_apertura'          => $entregados > 0 ? round($cntAbrieron / $entregados * 100, 1) : 0.0,
        'tasa_respuesta'         => $cntContactados > 0 ? round($cntRespondio / $cntContactados * 100, 1) : 0.0,
        'mockups_pendientes'     => $mockupsPend,
        'presupuestos_pendientes'=> $presPend,
        'pipeline_value'         => round($pipelineVal, 2),
    ];
}

/**
 * getSeguimientoFunnel — Embudo por las 5 etapas principales del pipeline con %
 * de conversión de etapa → siguiente (mismo patrón que getAnalyticsDashboard).
 */
function getSeguimientoFunnel($db, string $whereCommercial, int $cid = 0): array {
    $nombres = ['01 Sin Contactar', '02 Contactado', '03 En Conversación', '04 Propuesta', '05 Ganado'];
    $whereCamp = whereCampañaLead($cid);
    $counts = [];
    foreach ($nombres as $nombre) {
        $counts[] = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE c.estado_lead = '" . $db->escapeString($nombre) . "' {$whereCommercial}{$whereCamp}");
    }
    $funnel = [];
    for ($i = 0; $i < count($nombres); $i++) {
        $siguiente = $i + 1 < count($nombres) ? $counts[$i + 1] : null;
        $pct = ($siguiente !== null && $counts[$i] > 0) ? round($siguiente / $counts[$i] * 100, 1) : null;
        $funnel[] = ['etapa' => $nombres[$i], 'cnt' => $counts[$i], 'pct' => $pct];
    }
    return $funnel;
}




// ─── get_seguimiento (módulo Seguimiento — cola de trabajo + KPIs + embudo) ──
if ($action === 'get_seguimiento') {
    header('Content-Type: application/json');
    $excluirTest = ($_GET['excluir_test'] ?? '1') !== '0';
    $whereCommercial = $excluirTest ? "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')" : '';
    $filtros = [
        'busqueda'    => trim((string)($_GET['busqueda'] ?? '')),
        'federacion'  => trim((string)($_GET['federacion'] ?? '')),
        'dias_min'    => max(0, (int)($_GET['dias_min'] ?? 0)),
        'solo_alta'   => (($_GET['solo_alta'] ?? '0') === '1'),
        'campaign_id' => max(0, (int)($_GET['campaign_id'] ?? 0)),
        'excluir_test'=> $excluirTest,
    ];

    $noRespondedores    = getSeguimientoNoRespondedores($db, $whereCommercial, $filtros);
    $sinProximaAccion   = getSeguimientoSinProximaAccion($db, $whereCommercial, $filtros);
    $nuevosSinActividad = getSeguimientoNuevosSinActividad($db, $whereCommercial, $filtros);
    $kpis               = getSeguimientoKpis($db, $whereCommercial, $noRespondedores, $sinProximaAccion, $nuevosSinActividad, (int)$filtros['campaign_id']);
    $funnel             = getSeguimientoFunnel($db, $whereCommercial, (int)$filtros['campaign_id']);

    echo json_encode([
        'ok' => true,
        'no_respondedores' => $noRespondedores,
        'sin_proxima_accion' => $sinProximaAccion,
        'nuevos_sin_actividad' => $nuevosSinActividad,
        'kpis' => $kpis,
        'funnel' => $funnel,
    ]);
    exit;
}



// ─── sync_respuestas ─────────────────────────────────────────────────────────
// Dispara la sincronización IMAP/POP3 de respuestas de forma segura desde el
// dashboard (usuario ya autenticado por sesión). Invoca internamente el runner
// web api/imap_sync.php con el token del servidor, SIN exponerlo al frontend.
// El runner hace exit(0) al terminar, por lo que se invoca vía HTTP interno
// (file_get_contents) en lugar de require directo.
if ($action === 'sync_respuestas') {
    header('Content-Type: application/json');
    try {
        // Mismo origen del token que api/imap_sync.php (getenv con fallback).
        $secret = getenv('IMAP_CRON_SECRET') ?: 'IMAP_RESPUESTAS_CRON_20260820';

        // Construir URL absoluta del runner (misma raíz que este dashboard).
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/api/imap_sync.php';

        $url = $base . '?token=' . rawurlencode($secret) . '&apply=1';

        $ctx = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true]]);
        $salida = @file_get_contents($url, false, $ctx);

        // Extraer resumen del runner (líneas clave) para feedback al usuario.
        $resumen = [];
        if (is_string($salida)) {
            foreach (preg_split('/\r?\n/', $salida) as $linea) {
                if (preg_match('/Insertados:|Duplicados:|Errores:|Mensajes procesados:|Secuencias detenidas:/', $linea)) {
                    $resumen[] = trim($linea);
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'sync' => true,
            'resumen' => $resumen,
            'raw' => is_string($salida) ? substr($salida, 0, 2000) : 'sin respuesta',
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'sync_respuestas: ' . $e->getMessage()]);
    }
    exit;
}

// ─── Funciones puras de respuestas ───────────────────────────────────────────
// Refactor: se extrae la lógica de score/prioridad y ordenación de get_respuestas
// a funciones puras para que sean testables de forma aislada.

/**
 * calcularScorePrioridad — Calcula score y semáforo de prioridad por conversación.
 * Combina: clasificación de mensajes + aperturas del lead + tiempo sin responder.
 * Modifica cada conversación in-place añadiendo 'score', 'prioridad' y
 * 'horas_sin_respuesta'.
 */
function calcularScorePrioridad($db, array &$conversaciones): void {
    $ahora = time();
    foreach ($conversaciones as &$conv) {
        $score = 0;
        $tieneRespuestaHumana = false;
        $ultimaRespuestaTs = 0;
        $clasificaciones = [];

        foreach ($conv['mensajes'] as $m) {
            $clas = strtoupper((string)($m['clasificacion'] ?? ''));
            $clasificaciones[$clas] = true;
            if ($clas === 'POSITIVE') { $score += 15; $tieneRespuestaHumana = true; }
            elseif ($clas === 'NEUTRAL') { $score += 2; }
            elseif ($clas === 'OOO') { $score += 1; }
            elseif ($clas === 'NEGATIVE') { $score -= 5; }

            $ts = strtotime((string)($m['fecha_respuesta'] ?? ''));
            if ($ts > $ultimaRespuestaTs) $ultimaRespuestaTs = $ts;
        }

        // Aperturas del lead (via tracking_id de sus envíos)
        if ($conv['lead_id'] > 0) {
            $nAperturas = (int)$db->querySingle(
                "SELECT COUNT(DISTINCT a.id) FROM aperturas a
                 JOIN envios e ON a.tracking_id = e.tracking_id
                 WHERE e.lead_id = " . $conv['lead_id'] . " AND COALESCE(e.es_test,0)=0"
            );
            $score += min($nAperturas, 5) * 2; // +2 por apertura (máx 5)
        }

        $conv['score'] = max(0, $score);

        // ─── Semáforo de prioridad ────────────────────────────────────────
        // Combina: tiempo sin responder + clasificación + score.
        $prioridad = 'media';
        $horasSinRespuesta = $ultimaRespuestaTs > 0 ? ($ahora - $ultimaRespuestaTs) / 3600 : 0;

        // Clasificación dominante
        if (isset($clasificaciones['POSITIVE'])) {
            $prioridad = 'alta';
        } elseif (isset($clasificaciones['UNSUBSCRIBE']) || isset($clasificaciones['NEGATIVE'])) {
            $prioridad = 'baja';
        }

        // Tiempo sin responder: si hay respuesta humana pendiente de gestionar
        if ($tieneRespuestaHumana && $horasSinRespuesta > 48) {
            $prioridad = 'alta';
        } elseif ($tieneRespuestaHumana && $horasSinRespuesta > 24) {
            $prioridad = ($prioridad === 'baja') ? 'media' : 'alta';
        }

        // Score alto refuerza prioridad
        if ($conv['score'] >= 20 && $prioridad !== 'baja') {
            $prioridad = 'alta';
        } elseif ($conv['score'] >= 10 && $prioridad === 'media') {
            $prioridad = 'media';
        }

        $conv['prioridad'] = $prioridad;
        $conv['horas_sin_respuesta'] = round($horasSinRespuesta, 1);
    }
    unset($conv);
}

/**
 * ordenarConversaciones — Ordena por prioridad (alta>media>baja) y luego por
 * la última respuesta más reciente. Devuelve el array ordenado.
 */
function ordenarConversaciones(array $conversaciones): array {
    $ordenPrio = ['alta' => 0, 'media' => 1, 'baja' => 2];
    usort($conversaciones, function ($a, $b) use ($ordenPrio) {
        $pa = $ordenPrio[$a['prioridad']] ?? 1;
        $pb = $ordenPrio[$b['prioridad']] ?? 1;
        if ($pa !== $pb) return $pa <=> $pb;
        // Dentro de la misma prioridad, la más reciente primero
        $ta = 0; $tb = 0;
        foreach ($a['mensajes'] as $m) { $ts = strtotime((string)($m['fecha_respuesta'] ?? '')); if ($ts > $ta) $ta = $ts; }
        foreach ($b['mensajes'] as $m) { $ts = strtotime((string)($m['fecha_respuesta'] ?? '')); if ($ts > $tb) $tb = $ts; }
        return $tb <=> $ta;
    });
    return $conversaciones;
}

// ─── get_respuestas ──────────────────────────────────────────────────────────
// Bandeja de conversaciones comerciales agrupadas por lead.
// Cada conversación incluye: datos del lead, score, semáforo de prioridad,
// y el hilo de mensajes (envío original + respuestas) en orden cronológico.

if ($action === 'get_respuestas') {

    header('Content-Type: application/json; charset=utf-8');
    try {
        // Asegurar que exista conexión SQLite activa ($db)
        if (!isset($db) || !$db) {
            throw new \Exception("Error de conexión a la base de datos.");
        }

        $filtro = trim($_GET['clasificacion'] ?? '');
        $filtroPrioridad = trim($_GET['prioridad'] ?? '');
        $where = '';
        if ($filtro !== '' && in_array(strtoupper($filtro), CLASIFICACIONES_VALIDAS, true)) {
            // Mapear clasificación de la UI a las clasificaciones del módulo IMAP
            // (que se guardan en minúscula) para que el filtro también las encuentre.
            $mapaFiltro = [
                'POSITIVE'    => ['POSITIVE', 'humana'],
                'NEGATIVE'    => ['NEGATIVE', 'rebote'],
                'UNSUBSCRIBE' => ['UNSUBSCRIBE', 'baja'],
                'OOO'         => ['OOO', 'fuera_de_oficina'],
                'NEUTRAL'     => ['NEUTRAL', 'automatica'],
                'PENDING'     => ['PENDING', 'desconocida'],
            ];
            $clasFiltro = strtoupper($filtro);
            $valores = $mapaFiltro[$clasFiltro] ?? [$clasFiltro];
            $esc = array_map(fn($v) => "'" . $db->escapeString($v) . "'", $valores);
            $where = "AND r.clasificacion IN (" . implode(',', $esc) . ")";
        }

        // Filtro por campaña (contexto global del panel — P0 navegación).
        $cidResp = max(0, (int)($_GET['campaign_id'] ?? 0));
        if ($cidResp > 0) {
            $where .= " AND (e.campaign_id = {$cidResp} OR r.campaign_id = {$cidResp})";
        }

        // LEFT JOIN: muestra TODAS las respuestas, incluidas las sin envío asociado.
        // Consulta validada contra el esquema real de la BD (INFORME_UNIBOX):
        //   - `respuestas` NO tiene columna `email` → se usa `remitente`/`destinatario`.
        //   - `clubes_crm` NO tiene `contacto_nombre`/`volumen_equipos`/`variante` →
        //     se usan `persona_contacto`, `volumen_estimado`/`num_jugadores`.
        //   - El JOIN con clubes_crm se hace por `lead_id` O por `remitente` (email).
        //   - COALESCE blinda los campos clave del club y el snippet contra nulos,
        //     evitando el caracter '—' en la interfaz (fallback dinámico).
        $sql = "
            SELECT
                r.id AS respuesta_id,
                r.id,
                r.envio_id,
                r.lead_id,
                r.fecha_respuesta,
                r.remitente AS remitente_email,
                r.destinatario AS buzon_destino,
                r.subject AS subject_respuesta,
                COALESCE(r.cuerpo, 'Sin contenido de texto') AS cuerpo,
                COALESCE(r.contenido_html, r.cuerpo, 'Sin contenido HTML') AS contenido_html,
                SUBSTR(COALESCE(r.cuerpo, r.subject, 'Sin vista previa'), 1, 110) AS snippet,
                COALESCE(r.fecha_respuesta, CURRENT_TIMESTAMP) AS fecha,
                COALESCE(r.clasificacion, 'PENDING') AS clasificacion,
                r.estado_procesamiento,
                r.campaign_id,
                r.message_id,
                r.in_reply_to,
                r.notificado,
                e.club, e.email, e.campaign_id AS envio_campaign_id, e.variant,
                e.fecha_envio, e.asunto AS asunto_envio, e.tracking_id, e.lead_id AS envio_lead_id,
                e.cuenta_emision AS cuenta_destino,
                p.nombre AS campaña_nombre,
                c.id AS club_id,
                CASE
                    WHEN c.nombre_club IS NOT NULL AND c.nombre_club != '' THEN c.nombre_club
                    ELSE COALESCE(r.remitente, 'Club Desconocido')
                END AS nombre_club,
                COALESCE(c.persona_contacto, 'Sin Contacto') AS persona_contacto,
                COALESCE(c.telefono_movil, c.telefono_fijo, 'Sin teléfono') AS telefono,
                COALESCE(c.volumen_estimado, c.num_jugadores) AS volumen_equipos,
                COALESCE(c.estado_lead, '03 En Conversación') AS estado_lead,
                COALESCE(c.telefono_movil, '') AS lead_telefono_movil,
                COALESCE(c.tiene_whatsapp, 0) AS lead_tiene_whatsapp,
                COALESCE(c.volumen_estimado, 0) AS lead_volumen_estimado,
                c.proxima_accion AS lead_proxima_accion,
                c.ultimo_contacto AS lead_ultimo_contacto,
                COALESCE(c.persona_contacto, 'Director Deportivo') AS lead_contacto_nombre,
                COALESCE(c.telefono_fijo, '') AS lead_telefono,
                COALESCE(c.volumen_estimado, 0) AS lead_volumen_equipos,
                COALESCE(e.variant, 'A') AS lead_variante
            FROM respuestas r
            LEFT JOIN envios e ON e.id = r.envio_id
            LEFT JOIN pipelines p ON p.id = e.campaign_id
            LEFT JOIN clubes_crm c ON (r.lead_id = c.id OR LOWER(r.remitente) = LOWER(c.email))

            WHERE 1=1
              AND (r.envio_id IS NULL OR COALESCE(e.es_test, 0) = 0)
            {$where}
            ORDER BY r.id DESC
            LIMIT 500";


        $res = $db->query($sql);
        if (!$res) {
            throw new \Exception("Error al ejecutar la consulta de respuestas: " . $db->lastErrorMsg());
        }
        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            // Mapear clasificaciones del módulo IMAP a las de la UI
            $mapa = [
                'humana'          => 'POSITIVE',
                'rebote'          => 'NEGATIVE',
                'baja'            => 'UNSUBSCRIBE',
                'fuera_de_oficina'=> 'OOO',
                'automatica'      => 'NEUTRAL',
                'desconocida'     => 'PENDING',
            ];
            $clas = strtoupper((string)($row['clasificacion'] ?? ''));
            if (isset($mapa[strtolower($clas)])) {
                $row['clasificacion'] = $mapa[strtolower($clas)];
            }
            $items[] = $row;
        }

        // ─── Agrupar por lead (lead_id si existe, si no cada respuesta es individual) ──
        // IMPORTANTE: NO se agrupa por email. Cuando una respuesta no tiene lead_id
        // asociado (correos entrantes sin envío original), cada fila de `respuestas`
        // se entrega como una conversación individual para no colapsar correos
        // distintos (p.ej. de la misma cuenta de pruebas) en un único hilo artificial.
        $conversaciones = [];
        $indice = []; // clave de agrupación -> índice en $conversaciones

        foreach ($items as $r) {
            // Determinar clave de agrupación: solo por lead_id real.
            $leadId = (int)($r['lead_id'] ?? 0);
            if ($leadId <= 0) $leadId = (int)($r['envio_lead_id'] ?? 0);
            // Sin lead_id → cada respuesta es su propia conversación (clave única por id).
            $clave = $leadId > 0 ? 'lead:' . $leadId : 'resp:' . (int)($r['id'] ?? 0);

            if (!isset($indice[$clave])) {
                // Datos del lead (clubes_crm)
                $leadInfo = null;
                if ($leadId > 0) {
                    $leadInfo = $db->querySingle(
                        "SELECT id, nombre_club, email, estado_lead, ultimo_contacto, proxima_accion,
                                volumen_estimado, num_jugadores, telefono_movil, tiene_whatsapp
                         FROM clubes_crm WHERE id = " . $leadId, true
                    );
                }
                $indice[$clave] = count($conversaciones);
                // Los metadatos del lead se toman preferentemente del LEFT JOIN a
                // clubes_crm (lead_*), con fallback al query individual ($leadInfo).
                $conversaciones[] = [
                    'clave' => $clave,
                    'id' => (int)($r['id'] ?? 0),
                    'lead_id' => $leadId,
                    // Fallback dinámico: si no hay club atribuido, se muestra el
                    // email del remitente en lugar del caracter '—'.
                    'club' => $r['club'] ?? ($r['nombre_club'] ?? $leadInfo['nombre_club'] ?? $r['remitente_email'] ?? 'Club Desconocido'),
                    'nombre_club' => $r['nombre_club'] ?? $r['lead_nombre_club'] ?? $leadInfo['nombre_club'] ?? $r['club'] ?? $r['remitente_email'] ?? 'Club Desconocido',
                    'email' => $r['email'] ?? ($leadInfo['email'] ?? $r['remitente_email'] ?? ''),
                    'remitente_email' => $r['remitente_email'] ?? $r['remitente'] ?? '',
                    // Clave SIN tilde (buzon_destino) para que el frontend la lea
                    // correctamente. Se mantiene 'buzón_cuenta' por compatibilidad.
                    'buzon_destino' => $r['buzon_destino'] ?? $r['destinatario'] ?? $r['buzón_cuenta'] ?? '',
                    'buzón_cuenta' => $r['buzón_cuenta'] ?? $r['buzon_destino'] ?? $r['destinatario'] ?? '',
                    'cuerpo_texto' => limpiarCuerpoMime((string)($r['cuerpo_texto'] ?? $r['cuerpo'] ?? '')),
                    'snippet' => mb_substr(limpiarCuerpoMime((string)($r['cuerpo_texto'] ?? $r['cuerpo'] ?? '')), 0, 110),

                    'fecha' => $r['fecha'] ?? $r['fecha_respuesta'] ?? null,

                    'estado_lead' => $r['estado_lead'] ?? $r['lead_estado_lead'] ?? $leadInfo['estado_lead'] ?? null,
                    'ultimo_contacto' => $r['lead_ultimo_contacto'] ?? $leadInfo['ultimo_contacto'] ?? null,
                    'proxima_accion' => $r['lead_proxima_accion'] ?? $leadInfo['proxima_accion'] ?? null,
                    'volumen_estimado' => $r['lead_volumen_estimado'] ?? $leadInfo['volumen_estimado'] ?? null,
                    'num_jugadores' => $leadInfo['num_jugadores'] ?? null,
                    'telefono_movil' => $r['lead_telefono_movil'] ?? $leadInfo['telefono_movil'] ?? null,
                    'telefono' => $r['telefono'] ?? $r['lead_telefono'] ?? $leadInfo['telefono_movil'] ?? $r['lead_telefono_movil'] ?? null,
                    'contacto_nombre' => $r['persona_contacto'] ?? $r['lead_contacto_nombre'] ?? $leadInfo['persona_contacto'] ?? null,
                    'volumen_equipos' => $r['volumen_equipos'] ?? $r['lead_volumen_equipos'] ?? $leadInfo['volumen_estimado'] ?? null,
                    'variante' => $r['lead_variante'] ?? $r['variant'] ?? null,
                    'tiene_whatsapp' => $r['lead_tiene_whatsapp'] ?? $leadInfo['tiene_whatsapp'] ?? null,
                    'campaña_nombre' => $r['campaña_nombre'] ?? null,
                    'variant' => $r['variant'] ?? null,
                    'mensajes' => [],
                    'score' => 0,
                    'prioridad' => 'media',
                    'nuevas' => 0,
                ];


            }
            $idx = $indice[$clave];
            // Limpiar el cuerpo MIME de cada mensaje para que el snippet y el
            // visor muestren el texto legible (no los headers/boundaries crudos).
            $r['cuerpo_texto'] = limpiarCuerpoMime((string)($r['cuerpo'] ?? ''));
            // cuerpo_limpio: SIEMPRE el texto legible completo del mensaje.
            // Se prefiere el cuerpo limpio (que pasa por limpiarCuerpoMime) y, si
            // no hay texto, se intenta extraer texto del contenido_html. Esto
            // garantiza que el visor muestre TODO el contenido del email aunque
            // `contenido_html` contenga MIME crudo o un HTML con estilos que
            // rompan el layout.
            $cuerpoLimpio = $r['cuerpo_texto'];
            if (trim($cuerpoLimpio) === '' || $cuerpoLimpio === 'Sin contenido de texto') {
                $cuerpoLimpio = limpiarCuerpoMime((string)($r['contenido_html'] ?? ''));
            }
            $r['cuerpo_limpio'] = $cuerpoLimpio;
            $conversaciones[$idx]['mensajes'][] = $r;

            if ((int)($r['notificado'] ?? 0) === 0) {
                $conversaciones[$idx]['nuevas']++;
            }

        }

        // ─── Calcular score y prioridad por conversación (función pura) ────────
        calcularScorePrioridad($db, $conversaciones);

        // ─── Ordenar: prioridad (alta>media>baja) y luego por última respuesta ──
        $conversaciones = ordenarConversaciones($conversaciones);


        // Aplicar filtro de prioridad si viene
        if ($filtroPrioridad !== '' && in_array($filtroPrioridad, ['alta', 'media', 'baja'], true)) {
            $conversaciones = array_values(array_filter($conversaciones, fn($c) => $c['prioridad'] === $filtroPrioridad));
        }

        // Payload normalizado: expone el array de conversaciones bajo múltiples
        // claves (data, conversaciones) para compatibilidad cruzada con cualquier
        // contrato que el frontend (app.js / Alpine.js) intente leer.
        echo json_encode([
            'ok' => true,
            'success' => true,
            'data' => $conversaciones,
            'conversaciones' => $conversaciones,
            'count' => count($conversaciones)
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (\Throwable $e) {
        http_response_code(200); // Evitar romper el json parse del frontend
        echo json_encode([
            'ok' => false,
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'data' => [],
            'conversaciones' => [],
            'count' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

// ─── actualizar_estado_lead (UNIBOX UI) ──────────────────────────────────────
// Actualiza clubes_crm.estado_lead en tiempo real desde el header del visor.
if ($action === 'actualizar_estado_lead') {
    header('Content-Type: application/json');
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $estado = trim($_POST['estado_lead'] ?? '');
    if ($leadId <= 0) { echo json_encode(['ok' => false, 'error' => 'lead_id requerido']); exit; }
    if ($estado === '') { echo json_encode(['ok' => false, 'error' => 'estado_lead requerido']); exit; }
    $estadoEsc = $db->escapeString($estado);
    $existe = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE id = {$leadId}");
    if ($existe === 0) { echo json_encode(['ok' => false, 'error' => 'lead no encontrado']); exit; }
    $db->exec("UPDATE clubes_crm SET estado_lead = '{$estadoEsc}', ultimo_contacto = datetime('now') WHERE id = {$leadId}");
    echo json_encode(['ok' => true, 'lead_id' => $leadId, 'estado_lead' => $estado]);
    exit;
}

// ─── enviar_respuesta_smtp (UNIBOX UI) ───────────────────────────────────────
// Envía una respuesta SMTP al lead usando la cuenta correspondiente al envío
// original (o la primera cuenta SMTP activa como fallback).
if ($action === 'enviar_respuesta_smtp') {
    header('Content-Type: application/json');
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $emailDestino = trim($_POST['email'] ?? '');
    $asunto = trim($_POST['asunto'] ?? 'Re: ' . ($_POST['asunto_original'] ?? ''));
    $cuerpo = trim($_POST['cuerpo'] ?? '');
    $respuestaId = (int)($_POST['respuesta_id'] ?? 0);

    if ($emailDestino === '' || $cuerpo === '') {
        echo json_encode(['ok' => false, 'error' => 'email y cuerpo son requeridos']);
        exit;
    }
    if (!filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'email destino no válido']);
        exit;
    }

    // Determinar la cuenta SMTP de emisión: preferentemente la del envío original.
    $cuenta = null;
    if ($respuestaId > 0) {
        $cuenta = $db->querySingle(
            "SELECT e.cuenta_emision FROM respuestas r LEFT JOIN envios e ON e.id = r.envio_id WHERE r.id = {$respuestaId}",
            true
        );
    }
    $cuentaEmision = $cuenta['cuenta_emision'] ?? '';

    // Fallback: primera cuenta SMTP activa.
    if ($cuentaEmision === '') {
        $cuentaEmision = (string)$db->querySingle("SELECT email FROM cuentas_smtp WHERE activa = 1 ORDER BY id ASC LIMIT 1");
    }

    // Registrar el envío en la tabla envios (mismo esquema que el launcher).
    $asuntoEsc = $db->escapeString($asunto);
    $cuerpoEsc = $db->escapeString($cuerpo);
    $emailEsc = $db->escapeString($emailDestino);
    $cuentaEsc = $db->escapeString($cuentaEmision);
    $tracking = 'trk_' . bin2hex(random_bytes(8));
    $db->exec("INSERT INTO envios (club, email, cuenta_emision, asunto, cuerpo_mensaje, estado, fecha_envio, tracking_id, lead_id, es_test)
               VALUES ('', '{$emailEsc}', '{$cuentaEsc}', '{$asuntoEsc}', '{$cuerpoEsc}', 'enviado', datetime('now'), '{$tracking}', {$leadId}, 0)");

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Respuesta registrada y encolada para envío SMTP',
        'tracking_id' => $tracking,
        'cuenta_emision' => $cuentaEmision,
    ]);
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

// ─── get_unread_count ────────────────────────────────────────────────────────
// Endpoint LIGERO para el notificador global de la campana (polling cada 30s).
// Solo cuenta respuestas sin notificar; no carga conversaciones ni hace JOINs
// pesados. Compatible con el filtro comercial (excluye TEST).
if ($action === 'get_unread_count') {
    header('Content-Type: application/json; charset=utf-8');
    // IMPORTANTE: la tabla `respuestas` NO tiene columna `es_test` (esa columna
    // vive en `envios`). Por eso se hace LEFT JOIN con `envios` y se excluyen las
    // respuestas de envíos TEST usando e.es_test, exactamente igual que hace
    // get_respuestas. Antes se usaba sqlFiltroComercial('r') que generaba
    // `COALESCE(r.es_test,0)=0` → error SQL → el polling devolvía 0 y la campana
    // no se actualizaba hasta abrir la tab Respuestas.
    $sqlNotif = "SELECT COUNT(*) as total
                 FROM respuestas r
                 LEFT JOIN envios e ON e.id = r.envio_id
                 WHERE r.notificado = 0
                   AND (r.envio_id IS NULL OR COALESCE(e.es_test, 0) = 0)";
    $stmtNotif = $db->prepare($sqlNotif);
    $resNotif = $stmtNotif->execute();
    $rowNotif = $resNotif->fetchArray(SQLITE3_ASSOC);
    echo json_encode(['success' => true, 'unread' => intval($rowNotif['total'] ?? 0)]);
    exit;
}

// ─── Funciones puras de analytics ────────────────────────────────────────────
// Refactor: se extrae la lógica de cada tab de get_analytics a funciones puras
// para que sean testables de forma aislada y el handler quede como orquestador.

/**
 * getAnalyticsEnvios — Histórico de envíos REALES (excluye TEST).
 */
function getAnalyticsEnvios($db): array {
    $data = [];
    $data['total'] = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado='enviado'" . sqlFiltroComercial('e'));
    $data['hoy']   = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE DATE(e.fecha_envio)=DATE('now')" . sqlFiltroComercial('e'));
    $data['ultimos'] = [];
    $r2 = $db->query("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado, e.asunto, e.cuerpo_mensaje FROM envios e WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY e.id DESC LIMIT 50");
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    return $data;
}

/**
 * getAnalyticsAperturas — Aperturas comerciales (solo envíos REALES).
 */
function getAnalyticsAperturas($db): array {
    $data = [];
    $data['total']    = (int)$db->querySingle("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e'));
    $data['hoy']      = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE DATE(a.fecha_apertura)=DATE('now')" . sqlFiltroComercial('e'));
    $data['ultimos']  = [];
    $r2 = $db->query("SELECT a.*, e.club, e.email FROM aperturas a LEFT JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY a.id DESC LIMIT 50");
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    return $data;
}

/**
 * getAnalyticsRebotes — Rebotes comerciales (solo envíos REALES).
 */
function getAnalyticsRebotes($db): array {
    $data = [];
    $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e'));
    $data['ultimos'] = [];
    $r2 = $db->query("SELECT r.*, e.club, e.email FROM rebotes r LEFT JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1" . sqlFiltroComercial('e') . " ORDER BY r.id DESC LIMIT 50");
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    return $data;
}

/**
 * getAnalyticsBajas — Bajas comerciales (excluye leads TEST).
 */
function getAnalyticsBajas($db): array {
    $data = [];
    $data['total']   = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");
    $data['ultimos'] = [];
    $r2 = $db->query("SELECT id, nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%') ORDER BY id DESC LIMIT 50");
    while ($row = $r2->fetchArray(SQLITE3_ASSOC)) { $data['ultimos'][] = $row; }
    return $data;
}

/**
 * getAnalyticsDashboard — Funnel 12 niveles, KPIs económicos, comparativa A/B/C
 * y objetivo 20 clubes. Acepta filtros de pipeline, variante y exclusión de TEST.
 */
function getAnalyticsDashboard($db, string $pipeline = '', string $variante = '', bool $excluirTest = true): array {
    $data = [];
    // Regla central de exclusión de leads TEST (espejo de esLeadTest()).
    $whereCommercial = $excluirTest ? "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')" : '';
    $wherePipeline = $pipeline ? "AND lp.pipeline_id = " . (int)$pipeline : '';
    $whereVariante = $variante ? "AND lp.variante_ab = '" . $db->escapeString($variante) . "'" : '';
    // Pertenencia real a la campaña: lead_pipelines UNION envios.campaign_id.
    $whereCampDash = $pipeline ? whereCampañaLead((int)$pipeline) : '';

    // Helper: stage_order (pipeline canónico unificado de 7 columnas)
    $stageOrder = "CASE c.estado_lead
        WHEN '01 Sin Contactar' THEN 1 WHEN '02 Contactado' THEN 2
        WHEN '03 En Conversación' THEN 3 WHEN '04 Propuesta' THEN 4
        WHEN '05 Ganado' THEN 5 WHEN '06 Perdido' THEN 6
        WHEN '07 Baja' THEN 7 ELSE 0 END";

    // F3.1 — Funnel 12 niveles (spec V4.3)
    // 1. Contactados
    $cntTotal = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE 1=1 {$whereCommercial}{$whereCampDash}");
    $cntContactados = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 2 {$whereCommercial}{$whereCampDash}");
    // 2. Entregados = Contactados - Rebotes (solo envíos REALES). rebotes se une por email.
    $cntRebotesContactados = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email) = LOWER(c.email) JOIN envios e ON LOWER(e.email) = LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 AND {$stageOrder} >= 2 {$whereCommercial}{$whereCampDash}");
    $cntEntregados = max($cntContactados - $cntRebotesContactados, 0);
    // 3. Abrieron (leads con al menos una apertura de envío REAL)
    $cntAbrieron = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email) = LOWER(c.email) JOIN aperturas a ON a.tracking_id = e.tracking_id WHERE COALESCE(e.es_test,0)=0 {$whereCommercial}{$whereCampDash}");
    // 4. En Conversación (respondieron)
    $cntRespondio = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 3 {$whereCommercial}{$whereCampDash}");
    // 5. Propuestas (respuestas positivas / cualificados / oportunidades / negociaciones)
    $cntInteresado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 4 {$whereCommercial}{$whereCampDash}");
    // 6. Cualificados (con volumen estimado)
    $cntCualificado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE volumen_estimado >= 50 AND {$stageOrder} >= 4 {$whereCommercial}{$whereCampDash}");
    // 7. Oportunidades (en Propuesta)
    $cntPropuesta = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 4 {$whereCommercial}{$whereCampDash}");
    // 8. Mockups enviados (DISTINCT lead_id)
    $cntMockups = (int)$db->querySingle("SELECT COUNT(DISTINCT m.lead_id) FROM mockups m JOIN clubes_crm c ON m.lead_id=c.id WHERE m.estado='enviado' {$whereCommercial}{$whereCampDash}");
    // 9. Presupuestos (DISTINCT lead_id)
    $cntPresupuestos = (int)$db->querySingle("SELECT COUNT(DISTINCT p.lead_id) FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id WHERE 1=1 {$whereCommercial}{$whereCampDash}");
    // 10. Negociaciones (en Propuesta)
    $cntNegociacion = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} >= 4 {$whereCommercial}{$whereCampDash}");
    // 11. Ganados
    $cntGanado = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} = 5 {$whereCommercial}{$whereCampDash}");
    // 12. Perdidos
    $cntPerdido = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder} = 6 {$whereCommercial}{$whereCampDash}");

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
    $ganadosEco = $db->query("SELECT COALESCE(SUM(p.unidades),0) as pares, COALESCE(SUM(p.importe_total),0) as fact, COALESCE(SUM(p.margen_potencial_club),0) as margen FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id JOIN (SELECT lead_id, MAX(version) as max_ver FROM presupuestos GROUP BY lead_id) pmax ON p.lead_id = pmax.lead_id AND p.version = pmax.max_ver WHERE c.estado_lead='05 Ganado' {$whereCommercial}{$whereCampDash}");
    $eco = $ganadosEco->fetchArray(SQLITE3_ASSOC);
    $paresGanados = (int)$eco['pares'];
    $factGanada = (float)$eco['fact'];
    $margenGanado = (float)$eco['margen'];
    $nGanados = max((int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm c WHERE {$stageOrder}=5 {$whereCommercial}{$whereCampDash}"),1);

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
        $vCamp = $pipeline ? " AND e.campaign_id = " . (int)$pipeline : '';
        $vLpCamp = $pipeline ? " AND lp.pipeline_id = " . (int)$pipeline : '';
        // Pertenencia real a la variante: lead_pipelines.variante_ab UNION envios.variant.
        // (La subquery del UNION usa alias e2, por lo que su condición de campaña es e2.campaign_id.)
        $vWhere = " AND c.id IN ("
            . "SELECT lp.lead_id FROM lead_pipelines lp WHERE lp.variante_ab='{$v}'{$vLpCamp}"
            . " UNION "
            . "SELECT c2.id FROM clubes_crm c2 JOIN envios e2 ON LOWER(e2.email)=LOWER(c2.email)"
            . " WHERE e2.variant='{$v}' AND COALESCE(e2.es_test,0)=0" . ($pipeline ? " AND e2.campaign_id = " . (int)$pipeline : "")
            . ")";
        $cv = [];
        $cv['variante'] = $v;
        // Leads asignados (pertenencia real a la variante)
        $cv['leads'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE 1=1 {$whereCommercial} {$vWhere}");
        // Entregados (con envío REAL de la variante, sin rebote)
        $cv['entregados'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email)=LOWER(c.email) WHERE e.estado='enviado' AND COALESCE(e.es_test,0)=0 AND e.variant='{$v}'{$vCamp} {$whereCommercial}");
        $cv['rebotes'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN rebotes r ON LOWER(r.email)=LOWER(c.email) JOIN envios e ON LOWER(e.email)=LOWER(r.email) WHERE COALESCE(e.es_test,0)=0 AND e.variant='{$v}'{$vCamp} {$whereCommercial}");
        // Aperturas (solo de envíos REALES de la variante)
        $cv['aperturas'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email)=LOWER(c.email) JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE COALESCE(e.es_test,0)=0 AND e.variant='{$v}'{$vCamp} {$whereCommercial}");
        $cv['tasa_apertura'] = $cv['entregados']>0 ? round($cv['aperturas']/$cv['entregados']*100,1) : 0;
        // Respuestas
        $cv['respondio'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}>=3 {$whereCommercial} {$vWhere}");
        $cv['tasa_resp'] = $cv['aperturas']>0 ? round($cv['respondio']/$cv['aperturas']*100,1) : 0;
        // Resp. Positivas
        $cv['interesado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}>=4 {$whereCommercial} {$vWhere}");
        // Cualificados
        $cv['cualificado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE volumen_estimado>=50 AND {$stageOrder}>=4 {$whereCommercial} {$vWhere}");
        // Propuestas
        $cv['propuesta'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}>=4 {$whereCommercial} {$vWhere}");
        // Mockups enviados (DISTINCT)
        $cv['mockups'] = (int)$db->querySingle("SELECT COUNT(DISTINCT m.lead_id) FROM mockups m JOIN clubes_crm c ON m.lead_id=c.id WHERE m.estado='enviado' {$whereCommercial} {$vWhere}");
        // Presupuestos (DISTINCT)
        $cv['presupuestos'] = (int)$db->querySingle("SELECT COUNT(DISTINCT p.lead_id) FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id WHERE 1=1 {$whereCommercial} {$vWhere}");
        // Negociaciones
        $cv['negociacion'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}>=4 {$whereCommercial} {$vWhere}");
        // Ganados / Perdidos
        $cv['ganado'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}=5 {$whereCommercial} {$vWhere}");
        $cv['perdido'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c WHERE {$stageOrder}=6 {$whereCommercial} {$vWhere}");
        $cv['conversion'] = $cv['leads']>0 ? round($cv['ganado']/$cv['leads']*100,1) : 0;
        // Económicos por variante — Solo versión más reciente de presupuesto por lead
        $ecoV = $db->querySingle("SELECT COALESCE(SUM(p.importe_total),0) as fact, COALESCE(SUM(p.unidades),0) as pares FROM presupuestos p JOIN clubes_crm c ON p.lead_id=c.id JOIN (SELECT lead_id, MAX(version) as max_ver FROM presupuestos GROUP BY lead_id) pmax ON p.lead_id = pmax.lead_id AND p.version = pmax.max_ver WHERE c.estado_lead='05 Ganado' {$whereCommercial} {$vWhere}", true);
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

    return $data;
}

// ─── get_analytics (orquestador) ─────────────────────────────────────────────
if ($action === 'get_analytics') {
    header('Content-Type: application/json');
    $tab = $_GET['tab'] ?? 'envios';
    $data = ['ok' => true, 'tab' => $tab];
    if ($tab === 'envios') {
        $data = array_merge($data, getAnalyticsEnvios($db));
    } elseif ($tab === 'aperturas') {
        $data = array_merge($data, getAnalyticsAperturas($db));
    } elseif ($tab === 'rebotes') {
        $data = array_merge($data, getAnalyticsRebotes($db));
    } elseif ($tab === 'bajas') {
        $data = array_merge($data, getAnalyticsBajas($db));
    } elseif ($tab === 'dashboard') {
        $pipeline = $_GET['pipeline'] ?? '';
        $variante = $_GET['variante'] ?? '';
        $excluirTest = ($_GET['excluir_test'] ?? '1') !== '0';
        $data = array_merge($data, getAnalyticsDashboard($db, $pipeline, $variante, $excluirTest));
    }
    echo json_encode($data);
    exit;
}


