<?php
/**
 * Runner para testear api/plantillas.php en subproceso (evita que su exit() mate el test).
 * Uso: php scripts/runner_plantillas.php <db_path> <json_get> <json_post>
 */
declare(strict_types=1);

$dbPath = $argv[1] ?? '';
$_GET  = json_decode($argv[2] ?? '{}', true) ?: [];
$_POST = json_decode($argv[3] ?? '{}', true) ?: [];

if ($dbPath === '' || !file_exists($dbPath)) {
    echo json_encode(['ok' => false, 'error' => 'BD no encontrada']);
    exit(1);
}
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
// En producción $action lo define el orquestador (dashboard.php).
$action = $_GET['action'] ?? $_POST['action'] ?? '';
require __DIR__ . '/../public_html/outbound/api/plantillas.php';
