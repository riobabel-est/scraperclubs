<?php
/**
 * motor_propuestas.php — Asistente de Campaña IA (human-in-the-loop).
 * Reglas B2B (temperatura/scoring) generan propuestas; el LLM redacta razón y
 * mensaje comercial. El usuario aprueba o rechaza (nada se ejecuta sin su OK).
 */
declare(strict_types=1);

require_once __DIR__ . '/llm.php';

function contextoProducto(SQLite3 $db): string {
    return trim((string)$db->querySingle("SELECT valor FROM config WHERE clave = 'ia_conocimiento_producto'"));
}

/**
 * motor_reglas_candidatos — Aplica las reglas y devuelve propuestas deterministas.
 */
function motor_reglas_candidatos(SQLite3 $db, int $campaignId): array {
    $propuestas = [];
    $whereComm = "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
    $filtros = ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false, 'campaign_id' => $campaignId, 'excluir_test' => true];

    // 1) CALENTAR — leads nuevos sin envío (1er toque)
    if (function_exists('getSeguimientoNuevosSinActividad')) {
        foreach (array_slice(getSeguimientoNuevosSinActividad($db, $whereComm, $filtros), 0, 5) as $l) {
            $propuestas[] = [
                'tipo' => 'calentar', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                'titulo' => 'Enviar 1er correo (lead nuevo sin contacto)',
                'razon' => "Lead creado hace {$l['dias_desde_creado']} días sin ningún envío. Fit: " . ($l['fit'] ?? 0) . " pts.",
                'prioridad' => $l['prioridad'] ?? 'Media', 'datos' => $l,
            ];
        }
    }

    // 2) PERSIGUIR / PAUSAR / DESCARTAR — no respondedores (top 30 por prioridad)
    if (function_exists('getSeguimientoNoRespondedores')) {
        foreach (array_slice(getSeguimientoNoRespondedores($db, $whereComm, $filtros), 0, 30) as $l) {
            $apert = (int)($l['num_aperturas'] ?? 0);
            $dias  = (int)($l['dias_desde_envio'] ?? 0);
            $temp  = $l['temperatura'] ?? 'Frio';
            if ($apert >= 2) {
                $propuestas[] = [
                    'tipo' => 'perseguir', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                    'titulo' => '2º toque personalizado (abrió y no respondió)',
                    'razon' => "{$apert} aperturas, {$dias} días sin respuesta. Temperatura: {$temp}.",
                    'prioridad' => $l['prioridad'] ?? 'Media', 'datos' => $l,
                ];
            } elseif ($apert === 0 && $dias >= 30) {
                $propuestas[] = [
                    'tipo' => 'descartar', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                    'titulo' => 'Mover a Perdido (sin interés: 0 aperturas en 30+ días)',
                    'razon' => "{$dias} días sin apertura. Temperatura: {$temp}.",
                    'prioridad' => 'Baja', 'datos' => $l,
                ];
            } elseif ($apert === 0 && $dias >= 15) {
                $propuestas[] = [
                    'tipo' => 'pausar', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                    'titulo' => 'Pausar seguimiento (sin señales de interés)',
                    'razon' => "{$dias} días sin apertura. Recomendado nutrir o archivar.",
                    'prioridad' => 'Baja', 'datos' => $l,
                ];
            } elseif ($apert === 1) {
                $propuestas[] = [
                    'tipo' => 'perseguir', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                    'titulo' => '2º toque (1 apertura)',
                    'razon' => "Solo {$apert} apertura en {$dias} días. Mensaje de refuerzo.",
                    'prioridad' => $l['prioridad'] ?? 'Media', 'datos' => $l,
                ];
            }
        }
    }

    // 3) CERRAR — en conversación/propuesta sin próxima acción
    if (function_exists('getSeguimientoSinProximaAccion')) {
        foreach (array_slice(getSeguimientoSinProximaAccion($db, $whereComm, $filtros), 0, 5) as $l) {
            $propuestas[] = [
                'tipo' => 'cerrar', 'lead_id' => (int)$l['id'], 'campaign_id' => $campaignId,
                'titulo' => 'Avanzar/cerrar: en ' . ($l['estado_lead'] ?? 'conversación') . ' sin siguiente paso',
                'razon' => ($l['dias_desde_contacto'] ?? 0) . " días sin contacto. Temperatura: " . ($l['temperatura'] ?? '?') . ".",
                'prioridad' => $l['prioridad'] ?? 'Media', 'datos' => $l,
            ];
        }
    }

    // 4) MOCKUP — presentar mockup cuando está solicitado/en producción
    $rM = $db->query("SELECT m.lead_id, m.estado, c.nombre_club FROM mockups m JOIN clubes_crm c ON c.id = m.lead_id WHERE m.estado IN ('solicitado','en_produccion') {$whereComm} ORDER BY m.id DESC LIMIT 3");
    if ($rM) {
        while ($m = $rM->fetchArray(SQLITE3_ASSOC)) {
            $propuestas[] = [
                'tipo' => 'mockup', 'lead_id' => (int)$m['lead_id'], 'campaign_id' => $campaignId,
                'titulo' => 'Presentar mockup (diseño) a ' . $m['nombre_club'],
                'razon' => "Mockup en estado '{$m['estado']}'. Momento de enviarlo con texto comercial.",
                'prioridad' => 'Alta', 'datos' => $m,
            ];
        }
    }

    // 5) PROFORMA — presentar presupuesto cuando está creado
    $rP = $db->query("SELECT p.lead_id, p.importe_total, c.nombre_club FROM presupuestos p JOIN clubes_crm c ON c.id = p.lead_id WHERE p.estado = 'creado' {$whereComm} ORDER BY p.id DESC LIMIT 3");
    if ($rP) {
        while ($p = $rP->fetchArray(SQLITE3_ASSOC)) {
            $propuestas[] = [
                'tipo' => 'proforma', 'lead_id' => (int)$p['lead_id'], 'campaign_id' => $campaignId,
                'titulo' => 'Presentar proforma/presupuesto a ' . $p['nombre_club'],
                'razon' => "Presupuesto de " . number_format((float)$p['importe_total'], 0, ',', '.') . "€ creado. Adjuntar y pedir confirmación.",
                'prioridad' => 'Alta', 'datos' => $p,
            ];
        }
    }

    return $propuestas;
}

/**
 * motor_redactar_con_ia — Redacta el mensaje comercial de una propuesta con el LLM.
 */
function motor_redactar_con_ia(SQLite3 $db, array $prop): array {
    $ctx = contextoProducto($db);
    $system = "Eres un copywriter de ventas B2B para un software de gestión de clubes de fútbol."
        . ($ctx !== '' ? "\n\nCONTEXTO DE PRODUCTO (úsalo como base):\n" . mb_substr($ctx, 0, 4000) : '')
        . "\n\nRedacta un email comercial breve, en español, profesional y cercano (máximo 120 palabras), con asunto y cuerpo. Devuelve SOLO el texto del email.";

    $lead = $prop['datos'] ?? [];
    $user = "Tipo de acción: {$prop['titulo']}\n"
        . "Club: " . ($lead['nombre_club'] ?? '?') . " (" . ($lead['federacion'] ?? '') . ")\n"
        . "Señales: " . ($prop['razon'] ?? '') . "\n"
        . ($prop['tipo'] === 'proforma' && !empty($lead['importe_total']) ? "Importe presupuesto: {$lead['importe_total']}€\n" : '')
        . "Escribe el email.";

    $texto = llm_chat($db, $system, $user, 400, 0.5);
    if ($texto !== null) {
        $prop['mensaje_sugerido'] = $texto;
    }
    return $prop;
}

/**
 * motor_generar_propuestas — Genera, persiste y devuelve propuestas pendientes.
 * "Lista de hoy": SOLO reglas deterministas (sin LLM en lote). El mensaje
 * comercial se redacta bajo demanda en el modal de atención (generar_email_ia).
 * Ciclo de vida: re-evalúa las pendientes — si la condición ya no aplica se
 * marcan 'obsoleta' (nunca se borran); las nuevas se insertan con fecha_prevista.
 */
function motor_generar_propuestas(SQLite3 $db, int $campaignId): array {
    require_once __DIR__ . '/../api/analytics.php';

    $candidatas = motor_reglas_candidatos($db, $campaignId);
    $lista = [];

    // 1) Re-evaluar pendientes existentes → obsoletas si ya no aplican.
    $mapaActual = [];
    foreach ($candidatas as $c) {
        $mapaActual[(int)$c['lead_id'] . '|' . $c['tipo']] = true;
    }
    $pend = $db->query("SELECT id, lead_id, tipo FROM propuestas_ia WHERE estado = 'pendiente'");
    if ($pend) {
        $updObs = $db->prepare("UPDATE propuestas_ia SET estado = 'obsoleta', aprobado_el = CURRENT_TIMESTAMP WHERE id = :id");
        while ($p = $pend->fetchArray(SQLITE3_ASSOC)) {
            $clave = (int)$p['lead_id'] . '|' . $p['tipo'];
            if (!isset($mapaActual[$clave])) {
                $updObs->bindValue(':id', (int)$p['id'], SQLITE3_INTEGER);
                $updObs->execute();
            }
        }
    }

    // 2) Insertar las nuevas (evita duplicados pendientes del mismo lead+tipo).
    foreach ($candidatas as $prop) {
        $existe = (int)$db->querySingle(
            "SELECT COUNT(*) FROM propuestas_ia WHERE lead_id = " . (int)$prop['lead_id']
            . " AND tipo = '" . $db->escapeString($prop['tipo']) . "' AND estado = 'pendiente'"
        );
        if ($existe > 0) continue;
        $stmt = $db->prepare("INSERT INTO propuestas_ia (lead_id, campaign_id, tipo, titulo, razon, mensaje_sugerido, prioridad, estado, fecha_prevista) VALUES (:lid, :cid, :tipo, :titulo, :razon, '', :prio, 'pendiente', CURRENT_TIMESTAMP)");
        $stmt->bindValue(':lid', (int)$prop['lead_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':cid', (int)$campaignId, SQLITE3_INTEGER);
        $stmt->bindValue(':tipo', $prop['tipo'], SQLITE3_TEXT);
        $stmt->bindValue(':titulo', $prop['titulo'], SQLITE3_TEXT);
        $stmt->bindValue(':razon', $prop['razon'], SQLITE3_TEXT);
        $stmt->bindValue(':prio', $prop['prioridad'] ?? 'Media', SQLITE3_TEXT);
        $stmt->execute();
        $prop['id'] = (int)$db->lastInsertRowID();
        $lista[] = $prop;
    }
    return $lista;
}

