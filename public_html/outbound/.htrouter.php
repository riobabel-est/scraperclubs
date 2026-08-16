<?php
/**
 * Router mínimo para PHP built-in server.
 * Devuelve false para archivos existentes (el servidor los maneja).
 * Solo enruta a dashboard.php cuando la ruta no corresponde a un archivo real.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si es un archivo real, dejar que el servidor lo sirva directamente
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// Si es una API sin extensión, intentar con .php
if (preg_match('#^/api/#', $path) && is_file($file . '.php')) {
    return false;
}

// Todo lo demás: servir dashboard.php
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/dashboard.php';
$_SERVER['SCRIPT_NAME'] = '/dashboard.php';
require __DIR__ . '/dashboard.php';