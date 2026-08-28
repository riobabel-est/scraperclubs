<?php
/**
 * inicio.php — Tab "Inicio": qué enviar hoy + qué conseguir por cliente.
 * Cruza datos que ya existen (cola de seguimiento, bandeja IMAP, mockups,
 * presupuestos, próximas acciones) y genera el resumen operativo del día con IA.
 * PHP 8.x core — SiteGround compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/llm.php';

if (!function_exists('atencion_contextoProducto')) {
    function atencion_contextoProducto(SQLite3 $db): string {
        return trim((string)$db->querySingle("SELECT valor FROM config WHERE clave = 'ia_conocimiento_producto'"));
    }
}

/**
 * datosInicio — Consolidado de pendientes del día para el tab Inicio.
 */
function datosInicio(SQLite3 $db, int $campaignId): array {
    $whereComm = "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
    $filtros = ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false, 'campaign_id' => $campaignId, 'excluir_test' => true];

    $acciones = [];

    // 2º toque: abrió y no respondió (cola Perseguir)
    if (function_exists('getSeguimientoNoRespondedores')) {
        foreach (getSeguimientoNoRespondedores($db, $whereComm, $filtros) as $l) {
            $apert = (int)($l['num_aperturas'] ?? 0);
            if ($apert >= 1) {
                $acciones[] = [
                    'tipo' => 'responder_2toque', 'lead_id' => (int)$l['id'],
                    'nombre_club' => $l['nombre_club'], 'email' => $l['email'],
                    'razon' => "{$apert} apertura(s), " . ($l['dias_desde_envio'] ?? '?') . " días sin respuesta",
                    'prioridad' => $l['prioridad'] ?? 'Media',
                ];
            }
        }
    }
    // 1er toque: nuevo sin actividad (cola Calentar)
    if (function_exists('getSeguimientoNuevosSinActividad')) {
        foreach (array_slice(getSeguimientoNuevosSinActividad($db, $whereComm, $filtros), 0, 10) as $l) {
            $acciones[] = [
                'tipo' => 'enviar_1toque', 'lead_id' => (int)$l['id'],
                'nombre_club' => $l['nombre_club'], 'email' => $l['email'],
                'razon' => "Sin contacto, " . ($l['dias_desde_creado'] ?? '?') . " días desde alta",
                'prioridad' => $l['prioridad'] ?? 'Media',
            ];
        }
    }
    // Presentar mockup (solicitado / en producción)
    $r = $db->query("SELECT m.lead_id, m.estado, c.nombre_club, c.email FROM mockups m JOIN clubes_crm c ON c.id = m.lead_id WHERE m.estado IN ('solicitado','en_produccion') {$whereComm} ORDER BY m.solicitado_en ASC");
    if ($r) { while ($m = $r->fetchArray(SQLITE3_ASSOC)) { $acciones[] = ['tipo' => 'enviar_mockup', 'lead_id' => (int)$m['lead_id'], 'nombre_club' => $m['nombre_club'], 'email' => $m['email'], 'razon' => "Mockup '{$m['estado']}' listo para presentar", 'prioridad' => 'Alta']; } }
    // Presentar proforma (presupuesto creado)
    $r = $db->query("SELECT p.lead_id, p.version, p.importe_total, c.nombre_club, c.email FROM presupuestos p JOIN clubes_crm c ON c.id = p.lead_id WHERE p.estado = 'creado' {$whereComm} ORDER BY p.fecha ASC");
    if ($r) { while ($p = $r->fetchArray(SQLITE3_ASSOC)) { $acciones[] = ['tipo' => 'presentar_proforma', 'lead_id' => (int)$p['lead_id'], 'nombre_club' => $p['nombre_club'], 'email' => $p['email'], 'razon' => "Proforma v{$p['version']} " . number_format((float)$p['importe_total'], 0, ',', '.') . " € por confirmar", 'prioridad' => 'Alta']; } }

    return datosInicio_resto($db, $campaignId, $acciones, $whereComm);
}

function datosInicio_resto(SQLite3 $db, int $campaignId, array $acciones, string $whereComm): array {
    $activos = "AND c.estado_lead IN ('03 En Conversación','04 Propuesta')";

    // Bandeja: últimas respuestas IMAP con su clasificación IA
    $bandeja = [];
    $r = $db->query("SELECT r.id, r.remitente, r.subject, r.clasificacion, r.fecha_respuesta, r.notificado, r.lead_id, c.nombre_club FROM respuestas r LEFT JOIN clubes_crm c ON c.id = r.lead_id ORDER BY r.id DESC LIMIT 6");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $bandeja[] = $x; }

    // "Qué conseguir por cliente": lo que falta para cerrar (pipeline activo)
    $conseguir = [];
    $r = $db->query("SELECT m.lead_id, c.nombre_club, c.estado_lead FROM mockups m JOIN clubes_crm c ON c.id = m.lead_id WHERE m.estado NOT IN ('enviado') {$activos} {$whereComm} ORDER BY m.id DESC LIMIT 10");
    if ($r) { while ($m = $r->fetchArray(SQLITE3_ASSOC)) $conseguir[] = ['lead_id' => (int)$m['lead_id'], 'nombre_club' => $m['nombre_club'], 'pendiente' => 'Enviar mockup', 'estado_lead' => $m['estado_lead']]; }
    $r = $db->query("SELECT p.lead_id, p.version, p.importe_total, c.nombre_club, c.estado_lead FROM presupuestos p JOIN clubes_crm c ON c.id = p.lead_id WHERE p.estado = 'creado' {$activos} {$whereComm} ORDER BY p.fecha ASC LIMIT 10");
    if ($r) { while ($p = $r->fetchArray(SQLITE3_ASSOC)) $conseguir[] = ['lead_id' => (int)$p['lead_id'], 'nombre_club' => $p['nombre_club'], 'pendiente' => "Confirmar proforma v{$p['version']} (" . number_format((float)$p['importe_total'], 0, ',', '.') . " €)", 'estado_lead' => $p['estado_lead']]; }
    $r = $db->query("SELECT r.lead_id, c.nombre_club, c.estado_lead, COUNT(*) n FROM respuestas r JOIN clubes_crm c ON c.id = r.lead_id WHERE r.notificado = 0 {$activos} GROUP BY r.lead_id ORDER BY n DESC LIMIT 10");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $conseguir[] = ['lead_id' => (int)$x['lead_id'], 'nombre_club' => $x['nombre_club'], 'pendiente' => $x['n'] . " respuesta(s) sin atender", 'estado_lead' => $x['estado_lead']]; }

    // Próximas acciones vencidas
    $vencidasLista = [];
    $r = $db->query("SELECT c.id, c.nombre_club, c.email, c.fecha_proxima_accion FROM clubes_crm c WHERE c.fecha_proxima_accion IS NOT NULL AND c.fecha_proxima_accion < CURRENT_TIMESTAMP AND c.estado_lead NOT IN ('05 Ganado','06 Perdido','07 Baja') {$whereComm} ORDER BY c.fecha_proxima_accion ASC LIMIT 8");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $vencidasLista[] = $x; }

    // Distribución de aperturas por hora (para la franja recomendada por IA)
    $horas = [];
    $r = $db->query("SELECT CAST(strftime('%H', a.fecha_apertura) AS INTEGER) h, COUNT(*) n FROM aperturas a GROUP BY h ORDER BY n DESC");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $horas[] = ['hora' => (int)$x['h'], 'n' => (int)$x['n']]; }

    $kpis = [
        'pendientes_hoy'       => (int)$db->querySingle("SELECT COUNT(*) FROM propuestas_ia WHERE estado='pendiente' AND (fecha_prevista IS NULL OR fecha_prevista <= CURRENT_TIMESTAMP)"),
        'respuestas_sin_atender' => (int)$db->querySingle("SELECT COUNT(*) FROM respuestas WHERE notificado = 0"),
        'mockups_pendientes'   => (int)$db->querySingle("SELECT COUNT(*) FROM mockups WHERE estado IN ('solicitado','en_produccion')"),
        'proformas_por_presentar' => (int)$db->querySingle("SELECT COUNT(*) FROM presupuestos WHERE estado='creado'"),
        'acciones_vencidas'    => count($vencidasLista),
    ];

    $rank = ['Alta' => 0, 'Media' => 1, 'Baja' => 2];
    usort($acciones, function ($a, $b) use ($rank) { return ($rank[$a['prioridad']] ?? 1) <=> ($rank[$b['prioridad']] ?? 1); });

    return [
        'ok' => true,
        'kpis' => $kpis,
        'acciones' => array_slice($acciones, 0, 25),
        'conseguir' => array_slice($conseguir, 0, 20),
        'bandeja' => $bandeja,
        'vencidas' => $vencidasLista,
        'horas' => array_slice($horas, 0, 5),
    ];
}

/**
 * generarResumenDiaIA — El LLM redacta el resumen operativo del día (4-6 bullets)
 * con los datos REALES de datosInicio: prioridad 1, retrasos, franja horaria.
 */
function generarResumenDiaIA(SQLite3 $db, int $campaignId): ?string {
    $d = datosInicio($db, $campaignId);
    $ctx = atencion_contextoProducto($db);

    $txt = "KPIs de HOY:\n";
    foreach ($d['kpis'] as $k => $v) $txt .= "- {$k}: {$v}\n";
    $txt .= "\nAcciones prioritarias de hoy:\n";
    foreach (array_slice($d['acciones'], 0, 8) as $a) $txt .= "- [{$a['prioridad']}] {$a['razon']} -> {$a['nombre_club']}\n";
    if (count($d['conseguir']) > 0) {
        $txt .= "\nPendientes por cliente (lo que falta para cerrar):\n";
        foreach (array_slice($d['conseguir'], 0, 6) as $c) $txt .= "- {$c['nombre_club']}: {$c['pendiente']}\n";
    }
    if (count($d['vencidas']) > 0) $txt .= "\nAcciones VENCIDAS: " . count($d['vencidas']) . "\n";
    if (count($d['horas']) > 0) {
        $txt .= "\nActividad real de aperturas por hora (para elegir franja):\n";
        foreach ($d['horas'] as $h) $txt .= "- {$h['hora']}:00h -> {$h['n']} aperturas\n";
    }

    $system = "Eres un asistente de ventas B2B senior. Con los datos REALES del día de hoy, redacta un resumen operativo breve (4-6 bullets, español, sin inventar datos) que incluya:\n"
        . "1) La PRIORIDAD 1 de hoy (qué lead/acción atender primero y por qué).\n"
        . "2) Alertas de retraso (acciones vencidas, leads sin respuesta).\n"
        . "3) Franja horaria recomendada para los envíos según la actividad real de aperturas.\n"
        . "4) Recordatorio de pendientes por cliente (mockups/proformas).\n"
        . "Sé directo y accionable. No inventes números, clubes ni datos que no estén en la lista."
        . ($ctx !== '' ? "\n\nCONTEXTO DE PRODUCTO:\n" . mb_substr($ctx, 0, 4000) : '');

    return llm_chat($db, $system, $txt, 400, 0.4);
}
