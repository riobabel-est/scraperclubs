<?php
/**
 * blacklist.php — Endpoints AJAX de Lista Negra del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── blacklist_add (AÑADIR A LISTA NEGRA) ──────────────────────────────────
// Añade CUALQUIER lead a la Lista Negra por motivos operativos (lead de prueba,
// importación incorrecta, cliente no objetivo, error humano, bloqueo preventivo,
// opt-out real, etc.). Reutiliza el mecanismo de supresión existente
// (estado_lead='Lista Negra') y registra el origen como [LISTA NEGRA] en
// observaciones. NUNCA borra historial.
// BLOQUE 3: guarda el estado anterior en estado_lead_backup (si no existe) para
// poder restaurarlo al quitar de Lista Negra.
if ($action === 'blacklist_add') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID requerido']);
            exit;
        }
        $lead = $db->querySingle("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = {$id}", true);
        if (!$lead) {
            echo json_encode(['ok' => false, 'error' => 'Lead no encontrado']);
            exit;
        }
        $estadoActual = (string)$lead['estado_lead'];
        $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

        // Si ya está en Lista Negra, no duplicar (idempotente).
        if (in_array($estadoActual, $estadosSupresion, true)) {
            echo json_encode(['ok' => true, 'tipo' => 'ya_suprimido', 'ya_suprimido' => true]);
            exit;
        }

        $fecha = date('Y-m-d H:i:s');
        $motivoTxt = $motivo !== '' ? ' | motivo=' . $motivo : '';
        $obs = (string)$lead['observaciones'];
        $nuevaObs = $obs
            . "\n[LISTA NEGRA] " . $fecha . " | fuente=manual" . $motivoTxt;

        // Guardar estado anterior en estado_lead_backup (solo si no hay uno previo).
        $backupActual = (string)($lead['estado_lead_backup'] ?? '');
        $nuevoBackup = $backupActual !== '' ? $backupActual : $estadoActual;

        $stmt = $db->prepare(
            "UPDATE clubes_crm
             SET estado_lead = 'Lista Negra',
                 estado_lead_backup = :backup,
                 observaciones = :o,
                 ultimo_contacto = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->bindValue(':backup', $nuevoBackup, SQLITE3_TEXT);
        $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        // Trazabilidad en comunicaciones_log
        $stmtLog = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
             VALUES (:lid, :cid, 'blacklist_add', :det, CURRENT_TIMESTAMP)"
        );
        $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
        $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
        $stmtLog->bindValue(':det', 'Añadido a Lista Negra' . ($motivo !== '' ? ' | motivo=' . $motivo : ''), SQLITE3_TEXT);
        $stmtLog->execute();
        echo json_encode(['ok' => true, 'tipo' => 'bloqueo_manual']);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── blacklist_remove (QUITAR DE LISTA NEGRA) ───────────────────────────────
// Quita CUALQUIER lead actualmente suprimido de la Lista Negra (bloqueo manual,
// opt-out real, o cualquier otro contacto marcado en Lista Negra). NUNCA borra
// historial: registra la reactivación (quién/cuándo/motivo) en observaciones y
// comunicaciones_log. El motivo de reactivación es OBLIGATORIO.
// BLOQUE 3: restaura el estado anterior guardado en estado_lead_backup si existe
// y es un estado operativo válido; si no, usa la regla explícita '01 Sin Contactar'.
if ($action === 'blacklist_remove') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID requerido']);
            exit;
        }
        // Motivo de reactivación OBLIGATORIO (BLOQUE 6).
        if ($motivo === '') {
            echo json_encode(['ok' => false, 'error' => 'El motivo de reactivación es obligatorio.', 'razon' => 'MOTIVO_REQUERIDO']);
            exit;
        }
        $lead = $db->querySingle("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = {$id}", true);
        if (!$lead) {
            echo json_encode(['ok' => false, 'error' => 'Lead no encontrado']);
            exit;
        }
        $obs = (string)$lead['observaciones'];
        $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

        // Determinar el estado a restaurar (BLOQUE 3).
        // Preferencia: estado_lead_backup si existe y es un estado operativo válido.
        $backup = trim((string)($lead['estado_lead_backup'] ?? ''));
        $estadoRestaurado = '01 Sin Contactar'; // regla explícita por defecto
        if ($backup !== '' && !in_array($backup, $estadosSupresion, true)) {
            $estadoRestaurado = $backup;
        }

        $fecha = date('Y-m-d H:i:s');
        $motivoTxt = ' | motivo=' . $motivo;
        $nuevaObs = $obs
            . "\n[REACTIVACIÓN] " . $fecha . " | fuente=manual | quitar_lista_negra" . $motivoTxt;

        $stmt = $db->prepare(
            "UPDATE clubes_crm
             SET estado_lead = :estado,
                 estado_lead_backup = '',
                 observaciones = :o,
                 ultimo_contacto = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->bindValue(':estado', $estadoRestaurado, SQLITE3_TEXT);
        $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        // Trazabilidad en comunicaciones_log
        $stmtLog = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
             VALUES (:lid, :cid, 'blacklist_remove', :det, CURRENT_TIMESTAMP)"
        );
        $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
        $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
        $stmtLog->bindValue(':det', 'Quitado de Lista Negra | estado_restaurado=' . $estadoRestaurado . $motivoTxt, SQLITE3_TEXT);
        $stmtLog->execute();
        echo json_encode(['ok' => true, 'tipo' => 'lista_negra_quitado', 'estado_restaurado' => $estadoRestaurado]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── get_blacklist (LISTA NEGRA — listado con tipo de supresión) ───────────
// Devuelve los leads en estado de supresión con su tipo:
//   - optout_real    → historial [BAJA] ... fuente=email (baja del destinatario)
//   - bloqueo_manual → sin historial de baja real (bloqueo operativo manual)
// NUNCA borra historial. Solo lectura.
if ($action === 'get_blacklist') {
    header('Content-Type: application/json');
    try {
        $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
        $inList = "'" . implode("','", array_map(function ($e) use ($db) { return $db->escapeString($e); }, $estadosSupresion)) . "'";
        $res = $db->query("SELECT id, nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE estado_lead IN ({$inList}) ORDER BY id DESC LIMIT 500");
        $items = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $obs = (string)$r['observaciones'];
            $esOptOutReal = (bool)preg_match('/\[BAJA\][^\n]*fuente\s*=\s*email/i', $obs);
            // Extraer motivo/fecha de la última marca de supresión
            $motivo = '';
            $fecha = '';
            if (preg_match('/\[(?:BAJA|BLOQUEO MANUAL|LISTA NEGRA)\][^\n]*?(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[^\n]*?(?:motivo=([^\n|]*))?/i', $obs, $m)) {
                $fecha = $m[1] ?? '';
                $motivo = trim($m[2] ?? '');
            }
            $items[] = [
                'id'                  => (int)$r['id'],
                'nombre_club'         => $r['nombre_club'],
                'email'               => $r['email'],
                'estado_lead'         => $r['estado_lead'],
                'estado_lead_backup'  => (string)($r['estado_lead_backup'] ?? ''),
                'tipo'                => $esOptOutReal ? 'optout_real' : 'bloqueo_manual',
                'motivo'              => $motivo,
                'fecha'               => $fecha,
            ];
        }

        echo json_encode(['ok' => true, 'items' => $items, 'total' => count($items)]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
