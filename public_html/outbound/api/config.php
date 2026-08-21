<?php
/**
 * config.php — Endpoints AJAX de configuración del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── get_config ──────────────────────────────────────────────────────────────
// Devuelve las claves de configuración solicitadas (o todas si no se filtra).
// Se usa para cargar en la UI de Configuración los valores guardados (p.ej.
// la API key y el modelo de DeepSeek). NUNCA expone credenciales SMTP.
if ($action === 'get_config') {
    header('Content-Type: application/json');
    try {
        $claves = [];
        if (isset($_GET['keys']) && $_GET['keys'] !== '') {
            $claves = array_map('trim', explode(',', (string)$_GET['keys']));
        }
        $res = $db->query("SELECT clave, valor FROM config");
        $todo = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $todo[$r['clave']] = $r['valor'];
        }
        if (count($claves) > 0) {
            $filtrado = [];
            foreach ($claves as $k) {
                if (isset($todo[$k])) $filtrado[$k] = $todo[$k];
            }
            $todo = $filtrado;
        }
        echo json_encode(['ok' => true, 'config' => $todo]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── update_config ───────────────────────────────────────────────────────────
if ($action === 'update_config') {
    header('Content-Type: application/json');
    try {
        $k = $_POST['key'] ?? '';
        $v = $_POST['value'] ?? '';
        $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES (:k, :v) ON CONFLICT(clave) DO UPDATE SET valor = :v2");
        $stmt->bindValue(':k', $k, SQLITE3_TEXT);
        $stmt->bindValue(':v', $v, SQLITE3_TEXT);
        $stmt->bindValue(':v2', $v, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
