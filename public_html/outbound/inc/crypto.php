<?php
/**
 * crypto.php — Cifrado reversible centralizado para FutProtec Outbound.
 *
 * Protege las contraseñas SMTP/IMAP/POP3 almacenadas en la BD (cuentas_smtp).
 * Usa AES-256-GCM (autenticado) con IV aleatorio por cifrado y tag de
 * integridad. La clave maestra vive en inc/secret.php (fuera de la BD),
 * protegida por .htaccess.
 *
 * Compatible con SiteGround (PHP 8.x nativo, openssl siempre disponible).
 *
 * Formato del valor cifrado en BD:
 *   FP1:<base64(iv)>:<base64(tag)>:<base64(ciphertext)>
 *
 * El prefijo "FP1:" permite detectar si un valor ya está cifrado. Si un valor
 * NO tiene el prefijo, se asume que es texto plano (compatibilidad con datos
 * existentes durante la migración) y se devuelve tal cual.
 */

if (!function_exists('futprotec_cifrarPassword')) {
    /**
     * Cifra una contraseña en claro con AES-256-GCM.
     *
     * @param string $texto Contraseña en claro.
     * @return string Valor cifrado con prefijo FP1: (o '' si entrada vacía).
     */
    function futprotec_cifrarPassword(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        $clave = futprotec_claveMaestra();
        $iv = random_bytes(12); // 96 bits, recomendado para GCM.
        $ciphertext = openssl_encrypt($texto, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            return $texto; // Fallback seguro: no romper el flujo.
        }
        return 'FP1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }
}

if (!function_exists('futprotec_descifrarPassword')) {
    /**
     * Descifra una contraseña almacenada. Si el valor no tiene el prefijo FP1:
     * (texto plano legacy), se devuelve tal cual.
     *
     * @param string $cifrado Valor almacenado en BD.
     * @return string Contraseña en claro.
     */
    function futprotec_descifrarPassword(string $cifrado): string
    {
        if ($cifrado === '') {
            return '';
        }
        if (strpos($cifrado, 'FP1:') !== 0) {
            // Texto plano legacy (aún no migrado).
            return $cifrado;
        }
        $partes = explode(':', $cifrado, 4);
        if (count($partes) !== 4) {
            return $cifrado;
        }
        $clave = futprotec_claveMaestra();
        $iv = base64_decode($partes[1], true);
        $tag = base64_decode($partes[2], true);
        $ciphertext = base64_decode($partes[3], true);
        if ($iv === false || $tag === false || $ciphertext === false) {
            return '';
        }
        $texto = openssl_decrypt($ciphertext, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
        return ($texto === false) ? '' : $texto;
    }
}

if (!function_exists('futprotec_estaCifrado')) {
    /**
     * Indica si un valor almacenado ya está cifrado (tiene prefijo FP1:).
     */
    function futprotec_estaCifrado(string $valor): bool
    {
        return strpos($valor, 'FP1:') === 0;
    }
}

if (!function_exists('futprotec_claveMaestra')) {
    /**
     * Devuelve la clave maestra de cifrado desde inc/secret.php.
     * Si no existe el archivo, genera una clave persistente en data/ (fallback
     * para entornos locales sin secret.php desplegado).
     */
    function futprotec_claveMaestra(): string
    {
        static $clave = null;
        if ($clave !== null) {
            return $clave;
        }

        $secretFile = __DIR__ . '/secret.php';
        if (file_exists($secretFile)) {
            $secret = require $secretFile;
            if (is_array($secret) && !empty($secret['clave'])) {
                $clave = $secret['clave'];
                return $clave;
            }
        }

        // Fallback: clave persistente en data/ (fuera del webroot no es posible
        // en hosting compartido; data/ está bloqueada por .htaccess).
        $fallbackFile = __DIR__ . '/../data/.futprotec_key';
        if (file_exists($fallbackFile)) {
            $clave = trim(file_get_contents($fallbackFile));
            return $clave;
        }
        $clave = bin2hex(random_bytes(32));
        @file_put_contents($fallbackFile, $clave, LOCK_EX);
        @chmod($fallbackFile, 0600);
        return $clave;
    }
}
