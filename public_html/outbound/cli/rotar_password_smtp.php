<?php
/**
 * rotar_password_smtp.php — Rota/actualiza la contraseña de una cuenta SMTP
 * en data/stats.db, CIFRADA con FP1 (inc/crypto.php + clave maestra inc/secret.php).
 *
 * Uso LOCAL (CLI; cli/ está bloqueado por .htaccess en web):
 *   php cli/rotar_password_smtp.php --email="rodrigo@getfutprotec.com" --pass="NUEVA_PASS"
 *   php cli/rotar_password_smtp.php --email="..." --pass="..." --dry-run   # sin escribir
 *
 * NOTA E-5: primero cambia la contraseña del buzón en SiteGround (Site Tools →
 * Email → Mailboxes) y LUEGO ejecuta este script para que el CRM use la nueva.
 * Nunca pegar la password en commits ni logs.
 */
declare(strict_types=1);

$args = getopt('', ['email::', 'pass::', 'dry-run']);
$email = strtolower(trim((string)($args['email'] ?? '')));
$pass  = (string)($args['pass'] ?? '');
$dry   = isset($args['dry-run']);

if ($email === '' || $pass === '') {
    fwrite(STDERR, "Uso: php cli/rotar_password_smtp.php --email=\"cuenta@dominio\" --pass=\"NUEVA\" [--dry-run]\n");
    exit(2);
}

require_once __DIR__ . '/../inc/crypto.php';

$dbPath = __DIR__ . '/../data/stats.db';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "BD no encontrada: {$dbPath}\n");
    exit(1);
}

$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');

$row = $db->querySingle("SELECT id, email FROM cuentas_smtp WHERE LOWER(email) = '" . $db->escapeString($email) . "'", true);
if (!$row) {
    fwrite(STDERR, "No existe la cuenta SMTP: {$email}\n");
    exit(1);
}

if (strlen($pass) < 8) {
    fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

$cifrado = futprotec_cifrarPassword($pass);

if ($dry) {
    echo "[dry-run] Se actualizaría la password de {$email} (id={$row['id']}) (cifrado FP1, longitud " . strlen($pass) . ")\n";
    exit(0);
}

$stmt = $db->prepare('UPDATE cuentas_smtp SET password = :p, ultimo_error = NULL WHERE id = :id');
$stmt->bindValue(':p', $cifrado, SQLITE3_TEXT);
$stmt->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
$stmt->execute();
$db->close();

echo "OK: password rotada y cifrada para {$email} (id={$row['id']})\n";
echo "IMPORTANTE: si esta BD es la de producción, súbela a SiteGround (backup previo) o ejecuta el cambio en el servidor.\n";
