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

// ─── REPOSITORIO DE ADJUNTOS (Ajustes → Adjuntos) ────────────────────────────
// Biblioteca de archivos reutilizables (catálogo, tarifas, logos...) que se
// pueden adjuntar a los emails desde la Bandeja o el modal Atender.

// get_adjuntos_repo — lista los archivos del repositorio (sin el BLOB)
if ($action === 'get_adjuntos_repo') {
    header('Content-Type: application/json');
    $items = [];
    $res = $db->query("SELECT id, nombre, mime, tamano, creado_el FROM adjuntos_repo ORDER BY id DESC");
    if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) $items[] = $r; }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// add_adjunto_repo — sube uno o varios archivos al repositorio (input 'adjunto[]')
if ($action === 'add_adjunto_repo') {
    header('Content-Type: application/json');
    try {
        if (empty($_FILES['adjunto'])) {
            echo json_encode(['ok' => false, 'error' => 'No se recibió ningún archivo.']);
            exit;
        }
        $f = $_FILES['adjunto'];
        $names = is_array($f['name'] ?? null) ? $f['name'] : (isset($f['name']) ? [$f['name']] : []);
        $tmps  = is_array($f['tmp_name'] ?? null) ? $f['tmp_name'] : (isset($f['tmp_name']) ? [$f['tmp_name']] : []);
        $mimes = is_array($f['type'] ?? null) ? $f['type'] : (isset($f['type']) ? [$f['type']] : []);
        $errs  = is_array($f['error'] ?? null) ? $f['error'] : (isset($f['error']) ? [$f['error']] : []);
        $subidos = 0;
        $total = 0;
        foreach ($names as $i => $nombre) {
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmpP = (string)($tmps[$i] ?? '');
            if ($tmpP === '' || !is_uploaded_file($tmpP)) continue;
            $bin = (string)file_get_contents($tmpP);
            $total += strlen($bin);
            if ($total > 20 * 1024 * 1024) { // tope 20MB acumulado
                echo json_encode(['ok' => false, 'error' => 'El total de archivos no puede superar 20 MB.']);
                exit;
            }
            $stmt = $db->prepare(
                'INSERT INTO adjuntos_repo (nombre, mime, tamano, datos) VALUES (:n, :m, :t, :d)'
            );
            $stmt->bindValue(':n', basename((string)$nombre), SQLITE3_TEXT);
            $stmt->bindValue(':m', (string)($mimes[$i] ?? 'application/octet-stream'), SQLITE3_TEXT);
            $stmt->bindValue(':t', strlen($bin), SQLITE3_INTEGER);
            $stmt->bindValue(':d', $bin, SQLITE3_BLOB);
            $stmt->execute();
            $subidos++;
        }
        echo json_encode(['ok' => true, 'subidos' => $subidos]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// delete_adjunto_repo — elimina un archivo del repositorio
if ($action === 'delete_adjunto_repo') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        $db->exec("DELETE FROM adjuntos_repo WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}
