<?php
/**
 * config.php — Endpoints AJAX de configuración del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// Helper de cifrado AES-256-GCM (clave maestra en inc/secret.php).
require_once __DIR__ . '/../inc/crypto.php';

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
            $valor = $r['valor'];
            // Descifrar claves de API de IA (*_api_key) para que el editor las
            // muestre (están cifradas AES-256-GCM en BD desde 2026-08-25).
            if (str_ends_with((string)$r['clave'], '_api_key')) {
                $valor = futprotec_descifrarPassword((string)$valor);
            }
            $todo[$r['clave']] = $valor;
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
        // Seguridad (2026-08-25): las claves de API de IA (*_api_key) se guardan
        // CIFRADAS AES-256-GCM (prefijo FP1:) en BD, igual que las SMTP. El resto
        // de claves de config (modelos, proveedor, delays) permanecen en claro.
        if (str_ends_with($k, '_api_key')) {
            $v = futprotec_cifrarPassword($v);
        }
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
