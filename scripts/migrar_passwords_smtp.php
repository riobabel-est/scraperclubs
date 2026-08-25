<?php
/**
 * migrar_passwords_smtp.php — Migra las contraseñas SMTP/IMAP/POP3 de texto
 * plano a cifrado AES-256-GCM (prefijo FP1:) en la BD stats.db.
 *
 * Uso (CLI):
 *   php scripts/migrar_passwords_smtp.php
 *
 * Idempotente: solo cifra las cuentas cuya contraseña NO tenga el prefijo FP1:.
 * Las que ya estén cifradas se dejan intactas.
 *
 * Compatible con SiteGround (PHP 8.x nativo, openssl siempre disponible).
 */

declare(strict_types=1);

$DB_PATH = __DIR__ . '/../public_html/outbound/data/stats.db';
require_once __DIR__ . '/../public_html/outbound/inc/crypto.php';

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada en {$DB_PATH}\n");
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

// Verificar que la tabla existe.
$tabla = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='cuentas_smtp'");
if (!$tabla) {
    fwrite(STDERR, "ERROR: tabla cuentas_smtp no existe en la BD.\n");
    exit(1);
}

$res = $db->query("SELECT id, email, password FROM cuentas_smtp ORDER BY id");
$migradas = 0;
$yaCifradas = 0;
$vacias = 0;
$errores = 0;

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $id = (int)$row['id'];
    $email = $row['email'];
    $pass = $row['password'] ?? '';

    if ($pass === '') {
        $vacias++;
        echo "[{$id}] {$email}: sin contraseña (omitida)\n";
        continue;
    }

    if (futprotec_estaCifrado($pass)) {
        $yaCifradas++;
        echo "[{$id}] {$email}: ya cifrada (omitida)\n";
        continue;
    }

    // Texto plano legacy -> cifrar.
    $cifrada = futprotec_cifrarPassword($pass);
    if ($cifrada === '' || $cifrada === $pass) {
        // Fallback de cifrado fallido: no tocar para no corromper.
        $errores++;
        echo "[{$id}] {$email}: ERROR al cifrar (se deja intacta)\n";
        continue;
    }

    $stmt = $db->prepare("UPDATE cuentas_smtp SET password = :pw WHERE id = :id");
    $stmt->bindValue(':pw', $cifrada, SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $migradas++;
    echo "[{$id}] {$email}: migrada a cifrado\n";
}

echo "\n=== RESUMEN ===\n";
echo "Migradas: {$migradas}\n";
echo "Ya cifradas: {$yaCifradas}\n";
echo "Sin contraseña: {$vacias}\n";
echo "Errores: {$errores}\n";

$db->close();
exit($errores > 0 ? 2 : 0);
