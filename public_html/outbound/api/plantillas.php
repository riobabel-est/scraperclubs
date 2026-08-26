<?php
/**
 * plantillas.php — Endpoints AJAX de plantillas del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── save_template ───────────────────────────────────────────────────────────
if ($action === 'save_template') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        $cuerpo = $_POST['cuerpo'] ?? '';
        $tipo = $_POST['tipo'] ?? 'comercial';
        // Campos ABC (variantes A/B/C) que envía el editor (app.js).
        $asunto_b = $_POST['asunto_b'] ?? '';
        $asunto_c = $_POST['asunto_c'] ?? '';
        $cuerpo_b = $_POST['cuerpo_b'] ?? '';
        $cuerpo_c = $_POST['cuerpo_c'] ?? '';
        $test_ab = (int)($_POST['test_ab'] ?? 0);
        if ($nombre === '' || $asunto === '' || $cuerpo === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre, asunto y cuerpo son obligatorios']);
            exit;
        }
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE plantillas SET nombre=:n, categoria=:c, asunto=:a, cuerpo=:b, tipo=:t, asunto_b=:ab, asunto_c=:ac, cuerpo_b=:cb, cuerpo_c=:cc, test_ab=:ta WHERE id=:id");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':c', $categoria, SQLITE3_TEXT);
            $stmt->bindValue(':a', $asunto, SQLITE3_TEXT);
            $stmt->bindValue(':b', $cuerpo, SQLITE3_TEXT);
            $stmt->bindValue(':t', $tipo, SQLITE3_TEXT);
            $stmt->bindValue(':ab', $asunto_b, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $asunto_c, SQLITE3_TEXT);
            $stmt->bindValue(':cb', $cuerpo_b, SQLITE3_TEXT);
            $stmt->bindValue(':cc', $cuerpo_c, SQLITE3_TEXT);
            $stmt->bindValue(':ta', $test_ab, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, fecha_creacion) VALUES (:n, :c, :a, :b, :t, :ab, :ac, :cb, :cc, :ta, CURRENT_TIMESTAMP)");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':c', $categoria, SQLITE3_TEXT);
            $stmt->bindValue(':a', $asunto, SQLITE3_TEXT);
            $stmt->bindValue(':b', $cuerpo, SQLITE3_TEXT);
            $stmt->bindValue(':t', $tipo, SQLITE3_TEXT);
            $stmt->bindValue(':ab', $asunto_b, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $asunto_c, SQLITE3_TEXT);
            $stmt->bindValue(':cb', $cuerpo_b, SQLITE3_TEXT);
            $stmt->bindValue(':cc', $cuerpo_c, SQLITE3_TEXT);
            $stmt->bindValue(':ta', $test_ab, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
        }
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── delete_template ─────────────────────────────────────────────────────────
if ($action === 'delete_template') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        $db->exec("DELETE FROM plantillas WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── get_templates ───────────────────────────────────────────────────────────
if ($action === 'get_templates') {
    header('Content-Type: application/json');
    $cat = trim($_GET['categoria'] ?? '');
    $incluirGenericas = (($_GET['incluir_genericas'] ?? '') === '1');
    $sql = "SELECT id, nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, fecha_creacion AS updated_at FROM plantillas";
    $params = [];
    if ($cat !== '') {
        if ($incluirGenericas) {
            // Lanzadera: plantillas de la categoría (estado) + genéricas (sin categoría).
            $sql .= " WHERE categoria = :cat OR categoria = ''";
        } else {
            // Editor: solo la categoría seleccionada.
            $sql .= " WHERE categoria = :cat";
        }
        $params[':cat'] = $cat;
    }
    $sql .= " ORDER BY fecha_creacion DESC";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v, SQLITE3_TEXT); }
    $res = $stmt->execute();
    $items = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $items[] = $r; }
    // El frontend (app.js) consume la clave 'templates' en la Lanzadera y el Editor.
    // Se mantiene 'items' por compatibilidad con cualquier otro consumidor.
    echo json_encode(['ok' => true, 'templates' => $items, 'items' => $items]);
    exit;
}

// ─── get_categorias ──────────────────────────────────────────────────────────
if ($action === 'get_categorias') {
    header('Content-Type: application/json');
    $res = $db->query("SELECT DISTINCT categoria FROM plantillas WHERE categoria != '' ORDER BY categoria ASC");
    $cats = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $cats[] = $r['categoria']; }
    echo json_encode(['ok' => true, 'categorias' => $cats]);
    exit;
}

// ─── rename_categoria ─────────────────────────────────────────────────────────
// Renombra una categoría en todas las plantillas que la usan ('' = quitar categoría).
if ($action === 'rename_categoria') {
    header('Content-Type: application/json');
    try {
        $old = trim($_POST['old_categoria'] ?? '');
        $new = trim($_POST['new_categoria'] ?? '');
        if ($old === '') { echo json_encode(['ok' => false, 'error' => 'Categoría origen requerida']); exit; }
        $stmt = $db->prepare("UPDATE plantillas SET categoria = :new WHERE categoria = :old");
        $stmt->bindValue(':new', $new, SQLITE3_TEXT);
        $stmt->bindValue(':old', $old, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'afectadas' => $db->changes()]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── delete_categoria ─────────────────────────────────────────────────────────
// Elimina una categoría: sus plantillas pasan a "Sin pipeline" (no se borran).
if ($action === 'delete_categoria') {
    header('Content-Type: application/json');
    try {
        $cat = trim($_POST['categoria'] ?? '');
        if ($cat === '') { echo json_encode(['ok' => false, 'error' => 'Categoría requerida']); exit; }
        $stmt = $db->prepare("UPDATE plantillas SET categoria = '' WHERE categoria = :cat");
        $stmt->bindValue(':cat', $cat, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'afectadas' => $db->changes()]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── preview_template ────────────────────────────────────────────────────────
if ($action === 'preview_template') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $tpl = $db->querySingle("SELECT * FROM plantillas WHERE id = {$id}", true);
    if ($tpl) {
        $club = $db->querySingle("SELECT nombre_club, persona_contacto, federacion FROM clubes_crm ORDER BY id DESC LIMIT 1", true);
        $asunto = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['asunto']
        );
        $cuerpo = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['cuerpo']
        );
        echo json_encode(['ok' => true, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'tipo' => $tpl['tipo']]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'No encontrado']);
    }
    exit;
}
