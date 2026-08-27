<?php
/**
 * adjunto.php — Sirve un archivo adjunto de una respuesta (tabla respuestas_adjuntos).
 * Requiere sesión autenticada (auth_outbound, igual que el resto del panel).
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

session_start();
if (empty($_SESSION['auth_outbound'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$DB_PATH = __DIR__ . '/../data/stats.db';
if (!file_exists($DB_PATH)) {
    http_response_code(500);
    exit;
}
$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'id inválido';
    exit;
}

$row = $db->querySingle(
    "SELECT nombre, mime, tamano, datos FROM respuestas_adjuntos WHERE id = {$id}",
    true
);
if (!$row) {
    http_response_code(404);
    echo 'Adjunto no encontrado';
    exit;
}

$nombre = (string)($row['nombre'] ?? 'adjunto');
$mime   = (string)($row['mime'] ?? 'application/octet-stream');
$datos  = (string)($row['datos'] ?? '');

// Anti-caché (los adjuntos pueden cambiar si se re-importan).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($datos));

// inline (ver en navegador) para tipos visualizables; attachment para el resto.
$visualizables = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/html', 'text/csv'];
$disposicion = in_array(strtolower($mime), $visualizables, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');

echo $datos;
$db->close();
