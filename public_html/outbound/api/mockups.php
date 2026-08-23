<?php
/**
 * mockups.php — Endpoints AJAX de mockups del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

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
        $db->exec("UPDATE clubes_crm SET estado_lead = '04 Propuesta', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");
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
