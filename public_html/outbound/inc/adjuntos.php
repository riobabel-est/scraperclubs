<?php
/**
 * adjuntos.php — Utilidades de almacenamiento de adjuntos en disco.
 *
 * Estructura (por club):  data/adjuntos/<club_id>/enviados|recibidos/<archivo>
 *   - "enviados"  → adjuntos que enviamos (envios_adjuntos)
 *   - "recibidos" → adjuntos que nos envían (respuestas_adjuntos)
 *
 * La columna `ruta` de las tablas guarda la ruta relativa ('adjuntos/<club>/...').
 * Si una fila no tiene ruta (registro legacy), se sirve el BLOB `datos`.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

if (!function_exists('futprotec_adjuntos_dir')) {
    /**
     * Ruta base absoluta del almacén de adjuntos (relativa a public_html/outbound).
     */
    function futprotec_adjuntos_dir(): string
    {
        return __DIR__ . '/../data/adjuntos';
    }
}

if (!function_exists('futprotec_guardar_adjunto')) {
    /**
     * Escribe un adjunto en disco y devuelve la ruta relativa para guardar en BD.
     *
     * @param int    $clubId  clubes_crm.id del club (0 si no resuelto)
     * @param string $tipo    'enviados' | 'recibidos'
     * @param string $nombre  nombre original del archivo
     * @param string $datos   contenido binario
     * @return string|null    ruta relativa ('adjuntos/<club>/<tipo>/<archivo>') o null si falla
     */
    function futprotec_guardar_adjunto(int $clubId, string $tipo, string $nombre, string $datos): ?string
    {
        $tipo = in_array($tipo, ['enviados', 'recibidos'], true) ? $tipo : 'recibidos';
        $club = max(0, $clubId);
        $dir  = rtrim(futprotec_adjuntos_dir(), '/\\') . '/' . $club . '/' . $tipo;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }

        // Sanear nombre (evitar separadores y caracteres peligrosos).
        $nombreLimpio = (string)preg_replace('/[\\\\\/:*?"<>|\r\n]+/', '_', (string)$nombre);
        $nombreLimpio = trim($nombreLimpio);
        if ($nombreLimpio === '') {
            $nombreLimpio = 'adjunto';
        }

        $ruta = $dir . '/' . $nombreLimpio;
        // Evitar colisión: si ya existe, prefijar con timestamp.
        if (file_exists($ruta)) {
            $nombreLimpio = time() . '_' . $nombreLimpio;
            $ruta = $dir . '/' . $nombreLimpio;
        }

        if (@file_put_contents($ruta, $datos) === false) {
            return null;
        }

        return 'adjuntos/' . $club . '/' . $tipo . '/' . $nombreLimpio;
    }
}

if (!function_exists('futprotec_ruta_adjunto')) {
    /**
     * Resuelve la ruta absoluta de un adjunto desde la ruta relativa guardada.
     * Devuelve null si la ruta es inválida o escapa del almacén (anti path traversal).
     */
    function futprotec_ruta_adjunto(string $rutaRelativa): ?string
    {
        $rutaRelativa = ltrim(trim($rutaRelativa), '/\\');
        // FIX (2026-09-02): tolerar rutas legacy guardadas con separador Windows (\).
        // Algunas migraciones locales escribieron 'adjuntos\\<club>\\<tipo>\\<archivo>'.
        $rutaRelativa = str_replace('\\', '/', $rutaRelativa);
        if ($rutaRelativa === '' || !str_starts_with($rutaRelativa, 'adjuntos/')) {
            return null;
        }
        $baseReal = realpath(futprotec_adjuntos_dir());
        $abs = rtrim(futprotec_adjuntos_dir(), '/\\') . '/' . substr($rutaRelativa, strlen('adjuntos/'));
        $real = realpath($abs);
        if ($baseReal === false || $real === false || !str_starts_with($real, $baseReal)) {
            return null;
        }
        return $real;
    }
}
