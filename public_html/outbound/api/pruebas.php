<?php
/**
 * pruebas.php — Endpoints AJAX de gestión de pruebas (aislamiento TEST/REAL).
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string), $DB_PATH (string) definidos
 *           por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// get_test_recipients — lista destinatarios de prueba
if ($action === 'get_test_recipients') {
    header('Content-Type: application/json');
    $items = [];
    $res = $db->query("SELECT id, email, nombre, activo FROM destinatarios_test ORDER BY id ASC");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $items[] = $r; }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// add_test_recipient — añade un destinatario de prueba
if ($action === 'add_test_recipient') {
    header('Content-Type: application/json');
    try {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Email inválido']);
            exit;
        }
        $stmt = $db->prepare("INSERT INTO destinatarios_test (email, nombre, activo) VALUES (:e, :n, 1)");
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// delete_test_recipient — elimina un destinatario de prueba
if ($action === 'delete_test_recipient') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        $db->exec("DELETE FROM destinatarios_test WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// get_test_leads — lista leads TEST (regla central esLeadTest)
if ($action === 'get_test_leads') {
    header('Content-Type: application/json');
    $items = [];
    $res = $db->query("SELECT id, nombre_club, email, estado_lead FROM clubes_crm WHERE LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%' ORDER BY id DESC LIMIT 200");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $items[] = $r; }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// get_test_history — histórico de envíos TEST (es_test = 1)
if ($action === 'get_test_history') {
    header('Content-Type: application/json');
    $items = [];
    $res = $db->query("SELECT id, club, email, fecha_envio, estado, campaign_id, plantilla_id, tracking_id FROM envios WHERE COALESCE(es_test,0)=1 ORDER BY id DESC LIMIT 200");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $items[] = $r; }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// clear_test_history — limpia el histórico de pruebas (solo es_test=1)
if ($action === 'clear_test_history') {
    header('Content-Type: application/json');
    try {
        $confirm = ($_POST['confirm'] ?? '') === 'CONFIRMAR';
        if (!$confirm) {
            echo json_encode(['ok' => false, 'error' => 'Se requiere confirmación explícita (confirm=CONFIRMAR)']);
            exit;
        }
        // Backup previo del histórico TEST antes de limpiar.
        $backupDir = __DIR__ . '/../data/backups';
        if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
        $backupFile = $backupDir . '/test_history_' . date('Ymd_His') . '.db';
        @copy($DB_PATH, $backupFile);

        // Eliminar de forma transaccional: aperturas y comunicaciones asociadas a envíos TEST.
        $db->exec("BEGIN");
        $db->exec("DELETE FROM aperturas WHERE tracking_id IN (SELECT tracking_id FROM envios WHERE COALESCE(es_test,0)=1)");
        $db->exec("DELETE FROM comunicaciones_log WHERE lead_id IN (SELECT DISTINCT lead_id FROM envios WHERE COALESCE(es_test,0)=1)");
        $db->exec("DELETE FROM envios WHERE COALESCE(es_test,0)=1");
        $db->exec("COMMIT");
        echo json_encode(['ok' => true, 'backup' => basename($backupFile)]);
    } catch (\Exception $e) {
        @$db->exec("ROLLBACK");
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
