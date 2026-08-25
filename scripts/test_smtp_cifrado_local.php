<?php
/**
 * test_smtp_cifrado_local.php — Prueba funcional local del cifrado de
 * contraseñas SMTP en la API api/smtp.php.
 *
 * Verifica:
 *  1. get_accounts NO expone la contraseña en claro (devuelve ***).
 *  2. save_account cifra la contraseña al guardar (prefijo FP1: en BD).
 *  3. El descifrado round-trip funciona (cifrar -> descifrar = original).
 *  4. test_smtp descifra correctamente antes de autenticar (sin conexión real,
 *     solo se valida que el flujo de descifrado no rompe).
 *
 * Uso (CLI):
 *   php scripts/test_smtp_cifrado_local.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../public_html/outbound/inc/crypto.php';

$DB_PATH = __DIR__ . '/../public_html/outbound/data/stats.db';
$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

$fallos = 0;
$ok = 0;

function check(string $nombre, bool $condicion, string $detalle = ''): void {
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  [OK] {$nombre}\n";
    } else {
        $fallos++;
        echo "  [FALLO] {$nombre}" . ($detalle !== '' ? " — {$detalle}" : '') . "\n";
    }
}

echo "=== PRUEBA 1: get_accounts NO expone contraseña en claro ===\n";
$res = $db->query("SELECT id, email, password FROM cuentas_smtp ORDER BY id");
$cuentas = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $cuentas[] = $row; }

$todasCifradas = true;
$ningunaEnClaro = true;
foreach ($cuentas as $c) {
    $pass = $c['password'];
    if (!futprotec_estaCifrado($pass)) {
        $todasCifradas = false;
        echo "  [INFO] Cuenta {$c['id']} ({$c['email']}) NO cifrada\n";
    }
    // Simular get_accounts: enmascara la contraseña.
    $expuesta = (!empty($pass) && !futprotec_estaCifrado($pass));
    if ($expuesta) { $ningunaEnClaro = false; }
}
check("Todas las cuentas están cifradas (FP1:)", $todasCifradas);
check("Ninguna contraseña en claro en BD", $ningunaEnClaro);

echo "\n=== PRUEBA 2: save_account cifra al guardar ===\n";
// Simular el guardado de una nueva cuenta con contraseña en claro.
$passPlano = 'MiClaveDePrueba_123!';
$cifrada = futprotec_cifrarPassword($passPlano);
check("Cifrado genera prefijo FP1:", futprotec_estaCifrado($cifrada), $cifrada);
check("Cifrado NO es igual al texto plano", $cifrada !== $passPlano);

echo "\n=== PRUEBA 3: round-trip cifrar -> descifrar ===\n";
$descifrada = futprotec_descifrarPassword($cifrada);
check("Descifrado recupera el original", $descifrada === $passPlano, "esperado='{$passPlano}' obtenido='{$descifrada}'");

echo "\n=== PRUEBA 4: descifrado de cuentas reales en BD ===\n";
$roundtripOk = true;
foreach ($cuentas as $c) {
    $desc = futprotec_descifrarPassword($c['password']);
    if ($desc === '') {
        $roundtripOk = false;
        echo "  [INFO] Cuenta {$c['id']} ({$c['email']}) descifrada vacía\n";
    }
}
check("Todas las cuentas reales descifran correctamente", $roundtripOk);

echo "\n=== PRUEBA 5: test_smtp usa descifrado (flujo de lectura) ===\n";
// Verificar que api/smtp.php usa futprotec_descifrarPassword en test_smtp.
$smtpPhp = file_get_contents(__DIR__ . '/../public_html/outbound/api/smtp.php');
check("api/smtp.php incluye crypto.php", str_contains($smtpPhp, 'inc/crypto.php'));
check("api/smtp.php descifra en test_smtp", str_contains($smtpPhp, 'futprotec_descifrarPassword'));
check("api/smtp.php cifra en save_account", str_contains($smtpPhp, 'futprotec_cifrarPassword'));
check("api/smtp.php enmascara en get_accounts", str_contains($smtpPhp, "'***'"));

echo "\n=== PRUEBA 6: puntos de lectura usan descifrado ===\n";
$archivos = [
    'inc/smtp_transport.php',
    'inc/mime.php',
    'cli/cron.php',
    'api/enviar_smtp_random.php',
    'inc/imap_respuestas.php',
    'inc/pop3_respuestas.php',
];
foreach ($archivos as $f) {
    $contenido = @file_get_contents(__DIR__ . '/../public_html/outbound/' . $f);
    $usaDescifrado = ($contenido !== false) && str_contains($contenido, 'futprotec_descifrarPassword');
    check("{$f} usa descifrado", $usaDescifrado);
}

echo "\n=== RESUMEN ===\n";
echo "OK: {$ok} | FALLOS: {$fallos}\n";
$db->close();
exit($fallos > 0 ? 1 : 0);
