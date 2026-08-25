<?php
/**
 * migrar_api_keys.php — Migra las API keys de IA de la tabla `config`
 * de texto plano a cifrado AES-256-GCM (prefijo FP1:) en stats.db.
 *
 * Uso (CLI local):
 *   php scripts/migrar_api_keys.php
 *
 * Idempotente: solo cifra las claves *_api_key que NO tengan prefijo FP1:.
 * Las ya cifradas se dejan intactas. Retrocompatible: si no se ejecuta, el
 * código nuevo sigue funcionando porque futprotec_descifrarPassword() devuelve
 * el valor en claro cuando no tiene prefijo FP1:.
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
$tabla = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
if (!$tabla) {
    fwrite(STDERR, "ERROR: tabla config no existe en la BD.\n");
    exit(1);
}

$res = $db->query("SELECT clave, valor FROM config ORDER BY clave");
$migradas = 0;
$yaCifradas = 0;
$vacias = 0;
$errores = 0;

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $clave = (string)$row['clave'];
    $valor = (string)($row['valor'] ?? '');

    // Solo interesan las claves de API (*_api_key).
    if (!str_ends_with($clave, '_api_key')) {
        continue;
    }
    if ($valor === '') {
        $vacias++;
        echo "[{$clave}]: vacía (omitida)\n";
        continue;
    }
    if (futprotec_estaCifrado($valor)) {
        $yaCifradas++;
        echo "[{$clave}]: ya cifrada (omitida)\n";
        continue;
    }

    $cifrada = futprotec_cifrarPassword($valor);
    if ($cifrada === '' || $cifrada === $valor) {
        $errores++;
        echo "[{$clave}]: ERROR al cifrar (se deja intacta)\n";
        continue;
    }

    $stmt = $db->prepare("UPDATE config SET valor = :v WHERE clave = :k");
    $stmt->bindValue(':v', $cifrada, SQLITE3_TEXT);
    $stmt->bindValue(':k', $clave, SQLITE3_TEXT);
    $stmt->execute();
    $migradas++;
    echo "[{$clave}]: migrada a cifrado\n";
}

echo "\n=== RESUMEN ===\n";
echo "Migradas: {$migradas}\n";
echo "Ya cifradas: {$yaCifradas}\n";
echo "Vacías: {$vacias}\n";
echo "Errores: {$errores}\n";

$db->close();
exit($errores > 0 ? 2 : 0);
