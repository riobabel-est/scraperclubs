<?php
/**
 * presupuestos.php — Endpoints AJAX de snapshots y presupuestos del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * Requiere: calcularPrecioYMargenLocal() (inc/helpers.php).
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

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
        // Rebotes y bajas comerciales: excluyen TEST (envios.es_test=0 y leads TEST).
        // rebotes se une por email (esquema LIVE: id, email, motivo, fecha_rebote).
        $rebotado = (int)$db->querySingle("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE COALESCE(e.es_test,0)=0");
        $baja = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra','Baja / Opt-Out') AND NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')");

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
