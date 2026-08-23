<?php
/**
 * Runner web temporal: Atribución retroactiva de respuestas IMAP sin lead_id/envio_id.
 *
 * PROBLEMA: En producción, las respuestas se registraron en la tabla `respuestas`
 * sin atribución (lead_id=null, envio_id=null). Esto hace que la subconsulta del
 * Kanban no las encuentre y muestre "Pendiente" en lugar de "mail".
 *
 * SOLUCIÓN: Para cada respuesta con lead_id=null y clasificación humana:
 *   1. Buscar el envío por message_id_original (quitando corchetes) → envios.message_id
 *   2. Si no, buscar por email remitente (último envío a ese email, estado no fallido)
 *   3. Si encuentra el envío, actualizar envio_id, lead_id, campaign_id, id_cuenta_smtp
 *   4. Mover el lead a '03 En Conversación' (misma lógica que imap_mover_kanban)
 *
 * USO (HTTP):
 *   https://getfutprotec.com/outbound/atribuir_respuestas_runner.php?token=ATRIBUIR_RESPUESTAS_20260823
 *   https://getfutprotec.com/outbound/atribuir_respuestas_runner.php?token=ATRIBUIR_RESPUESTAS_20260823&apply=1
 *
 * SEGURIDAD: No borra nada. Solo actualiza columnas de atribución en respuestas
 * y el estado del lead. Respeta la protección de opt-out real.
 */

define('AUTH_KEY', 'ATRIBUIR_RESPUESTAS_20260823');

// Autenticación por token
if (!isset($_GET['token']) || $_GET['token'] !== AUTH_KEY) {
    http_response_code(403);
    echo "Acceso denegado";
    exit;
}

$APPLY = isset($_GET['apply']) && $_GET['apply'] === '1';

$DB_PATH = __DIR__ . '/data/stats.db';
if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

echo "=== ATRIBUCIÓN RETROACTIVA DE RESPUESTAS ===\n";
echo "Modo: " . ($APPLY ? "APLICAR" : "DRY-RUN (no modifica)") . "\n";
echo "BD: {$DB_PATH}\n\n";

// Clasificaciones que se consideran respuesta humana (misma lista que imap_es_respuesta_humana)
$humanas = ['humana', 'interesado', 'duda_precio', 'neutral', 'no_interesa'];

// Pipeline canónico unificado
$ordenPipeline = [
    '01 Sin Contactar'    => 1,
    '02 Contactado'       => 2,
    '03 En Conversación'  => 3,
    '04 Propuesta'        => 4,
    '05 Ganado'           => 5,
    '06 Perdido'          => 6,
    '07 Baja'             => 7,
];
$estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

// 1. Obtener respuestas sin atribuir con clasificación humana
$placeholders = implode(',', array_fill(0, count($humanas), '?'));
$stmt = $db->prepare(
    "SELECT id, remitente, subject, clasificacion, lead_id, envio_id, campaign_id, id_cuenta_smtp, message_id_original
     FROM respuestas
     WHERE (lead_id IS NULL OR lead_id = '')
       AND clasificacion IN ({$placeholders})"
);
foreach ($humanas as $i => $h) {
    $stmt->bindValue($i + 1, $h, SQLITE3_TEXT);
}
$res = $stmt->execute();

$respuestas = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $respuestas[] = $row;
}

echo "Respuestas humanas sin atribuir: " . count($respuestas) . "\n\n";

$atribuidas = 0;
$noEncontradas = 0;
$movidasKanban = 0;

foreach ($respuestas as $resp) {
    $rid = $resp['id'];
    $remitente = trim($resp['remitente'] ?? '');
    $midOriginal = trim($resp['message_id_original'] ?? '');
    $clasificacion = $resp['clasificacion'];

    echo "--- Respuesta #{$rid} | remitente='{$remitente}' | clasificacion='{$clasificacion}' ---\n";

    // Buscar envío
    $envio = null;

    // 1. Por message_id_original (quitando corchetes)
    if ($midOriginal !== '' && $midOriginal !== 'NIL' && $midOriginal !== '/') {
        $midLimpio = trim($midOriginal, '<> ');
        $stmtE = $db->prepare(
            "SELECT * FROM envios WHERE REPLACE(message_id, '<', '') = REPLACE(REPLACE(:mid, '<', ''), '>', '') LIMIT 1"
        );
        $stmtE->bindValue(':mid', $midLimpio, SQLITE3_TEXT);
        $envio = $stmtE->execute()->fetchArray(SQLITE3_ASSOC);
        if ($envio) {
            echo "  [message_id_original] → envío #{$envio['id']} (lead {$envio['lead_id']})\n";
        }
    }

    // 2. Por email remitente (último envío a ese email, estado no fallido)
    if (!$envio && $remitente !== '') {
        $stmtE = $db->prepare(
            "SELECT * FROM envios WHERE LOWER(email) = LOWER(:email) AND estado NOT IN ('fallido', 'error', 'rechazado', 'rebote', 'bounce') ORDER BY fecha_envio DESC LIMIT 1"
        );
        $stmtE->bindValue(':email', $remitente, SQLITE3_TEXT);
        $envio = $stmtE->execute()->fetchArray(SQLITE3_ASSOC);
        if ($envio) {
            echo "  [email remitente] → envío #{$envio['id']} (lead {$envio['lead_id']})\n";
        }
    }

    if (!$envio) {
        echo "  ✗ No se encontró envío para atribuir\n";
        $noEncontradas++;
        continue;
    }

    $leadId = $envio['lead_id'] ?? null;
    if ($leadId === null) {
        echo "  ✗ El envío #{$envio['id']} no tiene lead_id\n";
        $noEncontradas++;
        continue;
    }

    // Actualizar atribución en la respuesta
    $campaignId = $envio['campaign_id'] ?? null;
    $smtpId = $envio['smtp_id'] ?? null;

    if ($APPLY) {
        $stmtU = $db->prepare(
            "UPDATE respuestas SET envio_id = :eid, lead_id = :lid, campaign_id = :cid, id_cuenta_smtp = :sid WHERE id = :rid"
        );
        $stmtU->bindValue(':eid', $envio['id'], SQLITE3_INTEGER);
        $stmtU->bindValue(':lid', $leadId, SQLITE3_INTEGER);
        $stmtU->bindValue(':cid', $campaignId !== null ? $campaignId : null, $campaignId !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmtU->bindValue(':sid', $smtpId !== null ? $smtpId : null, $smtpId !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmtU->bindValue(':rid', $rid, SQLITE3_INTEGER);
        $stmtU->execute();
        echo "  ✓ Respuesta #{$rid} atribuida → envío #{$envio['id']}, lead #{$leadId}\n";
    } else {
        echo "  [DRY-RUN] Atribuiría respuesta #{$rid} → envío #{$envio['id']}, lead #{$leadId}\n";
    }
    $atribuidas++;

    // Mover Kanban (misma lógica que imap_mover_kanban)
    $estadoActual = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$leadId}");
    if ($estadoActual === false || $estadoActual === null) {
        echo "  ✗ Lead #{$leadId} no existe en clubes_crm\n";
        continue;
    }

    if (in_array($estadoActual, $estadosSupresion, true)) {
        echo "  ⚠ Lead #{$leadId} está en estado de supresión ('{$estadoActual}'), no se mueve\n";
        continue;
    }

    $ordenActual = $ordenPipeline[$estadoActual] ?? 0;
    if ($ordenActual >= 3) {
        echo "  ℹ Lead #{$leadId} ya está en '{$estadoActual}' (orden {$ordenActual}), no se mueve\n";
        continue;
    }

    if ($APPLY) {
        $db->exec("UPDATE clubes_crm SET estado_lead = '03 En Conversación', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");
        // Registrar en comunicaciones_log
        $stmtLog = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
             VALUES (:lid, :cid, 'cambio_estado', :det, CURRENT_TIMESTAMP)"
        );
        $stmtLog->bindValue(':lid', $leadId, SQLITE3_INTEGER);
        $stmtLog->bindValue(':cid', $leadId, SQLITE3_INTEGER);
        $stmtLog->bindValue(':det', "Estado cambiado de '{$estadoActual}' a '03 En Conversación' (atribución retroactiva respuesta IMAP)", SQLITE3_TEXT);
        $stmtLog->execute();
        echo "  ✓ Lead #{$leadId} movido a '03 En Conversación'\n";
    } else {
        echo "  [DRY-RUN] Movería lead #{$leadId} de '{$estadoActual}' a '03 En Conversación'\n";
    }
    $movidasKanban++;
}

echo "\n=== RESUMEN ===\n";
echo "Respuestas humanas sin atribuir: " . count($respuestas) . "\n";
echo "Atribuidas: {$atribuidas}\n";
echo "No encontradas: {$noEncontradas}\n";
echo "Leads a mover a '03 En Conversación': {$movidasKanban}\n";

$db->close();
echo "\n" . ($APPLY ? "Cambios APLICADOS." : "DRY-RUN completado. Usa apply=1 para aplicar.") . "\n";
