<?php
/**
 * diag_smtp.php — Diagnóstico directo de conexión SMTP + envío.
 * BORRAR después de la prueba.
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(30);

echo "=== PHP: " . PHP_VERSION . " | openssl: " . (extension_loaded('openssl') ? 'YES' : 'NO') . " ===\n\n";

// Obtener credenciales desde la BD (sin hardcodear)
$dbPath = __DIR__ . '/stats.db';
if (!file_exists($dbPath)) {
    die("❌ stats.db no encontrada. Ejecuta primero: php init_db.php\n");
}
$db = new SQLite3($dbPath);
$db->enableExceptions(true);

$cuenta = $db->querySingle("SELECT * FROM cuentas_smtp WHERE activa = 1 LIMIT 1", true);
if (!$cuenta) {
    die("❌ No hay cuentas SMTP activas en la BD.\n");
}

// Test 1: Conexión SSL directa
$host = $cuenta['host'];
$port = (int)$cuenta['puerto'];
$user = $cuenta['usuario'];
$pass = $cuenta['password'];

echo "[1] Conectando ssl://{$host}:{$port}...\n";
$errno = 0; $errstr = '';
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);

if (!$fp) { echo "❌ FAIL: {$errstr} ({$errno})\n"; exit(1); }
echo "✅ Conectado\n";

// Leer banner multilínea (220-... → 220 ...)
$banner = '';
while ($line = fgets($fp, 512)) {
    $banner .= $line;
    if (isset($line[3]) && $line[3] === ' ') break;
}
echo "Banner: " . str_replace(["\r","\n"], ' | ', trim($banner)) . "\n";

// EHLO
fwrite($fp, "EHLO getfutprotec.com\r\n");
while ($line = fgets($fp, 512)) {
    if (isset($line[3]) && $line[3] === ' ') break;
}

// AUTH
fwrite($fp, "AUTH LOGIN\r\n"); fgets($fp, 512);
fwrite($fp, base64_encode($user) . "\r\n"); fgets($fp, 512);
fwrite($fp, base64_encode($pass) . "\r\n");
$authResp = fgets($fp, 512);
echo "AUTH: " . trim($authResp) . "\n";

if (!str_starts_with($authResp, '235')) {
    echo "❌ AUTH falló\n"; fclose($fp); exit(2);
}
echo "✅ Autenticado\n\n";

// Test 2: Enviar correo real
echo "[2] Enviando correo de prueba...\n";
fwrite($fp, "MAIL FROM:<{$user}>\r\n"); echo "< " . trim(fgets($fp, 512)) . "\n";
fwrite($fp, "RCPT TO:<contactofutprotec@gmail.com>\r\n"); echo "< " . trim(fgets($fp, 512)) . "\n";
fwrite($fp, "DATA\r\n"); echo "< " . trim(fgets($fp, 512)) . "\n";

$body = "From: Rodrigo Vazquez | FutProtec <{$user}>\r\n";
$body .= "To: <contactofutprotec@gmail.com>\r\n";
$body .= "Subject: =?UTF-8?B?" . base64_encode("Test SMTP FutProtec") . "?=\r\n";
$body .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
$body .= "<h1>✅ Test SMTP exitoso</h1><p>Enviado desde getfutprotec.com</p>\r\n.\r\n";

fwrite($fp, $body);
$dataResp = fgets($fp, 512);
echo "< " . trim($dataResp) . "\n";

if (str_starts_with($dataResp, '250')) {
    echo "\n✅✅ ENVÍO EXITOSO a contactofutprotec@gmail.com ✅✅\n";
} else {
    echo "\n❌ Envío falló\n";
}

fwrite($fp, "QUIT\r\n");
fclose($fp);