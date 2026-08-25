<?php
/**
 * mime.php — Construcción MIME para envíos (FIX DEFINITIVO multipart/alternative).
 *
 * Centraliza la construcción de las partes text/plain y text/html para plantillas
 * `texto_plano` y el envío SMTP autenticado nativo. Reutilizado por
 * api/enviar_lote.php y por los tests locales (scripts/test_mime_plaintext_tracking.php)
 * SIN abrir conexión SMTP.
 *
 * PHP 8.x nativo — SiteGround compatible. Sin dependencias externas.
 */

declare(strict_types=1);

// Transporte SMTP centralizado (unifica las implementaciones previas).
require_once __DIR__ . '/smtp_transport.php';

if (!function_exists('convertirContenidoAHtml')) {
/**
 * Convierte un contenido comercial de texto plano en un HTML deliberadamente
 * sencillo (sin tablas, sin imágenes decorativas, sin CSS complejo, sin JS,
 * sin fuentes externas). Conserva los saltos de línea como <br> y añade el
 * píxel de tracking SOLO en esta parte HTML.
 *
 * @param string $texto      contenido comercial original (con \n y \n\n)
 * @param string $trackUrl   base del píxel de tracking
 * @param string $trackingId id único del envío
 * @return string HTML mínimo con el píxel de tracking
 */
function convertirContenidoAHtml(string $texto, string $trackUrl, string $trackingId): string
{
    // Escapar HTML para que el texto se muestre literalmente (sin interpretar
    // posibles < > & que pudiera contener el contenido comercial).
    $escaped = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

    // Convertir saltos de línea a <br> (nl2br sobre el texto ya escapado).
    $conSaltos = nl2br($escaped);

    // Enlace de baja: sustituir la URL visible por un enlace simple <a>.
    // El placeholder {{EMAIL}} ya fue sustituido antes de llegar aquí.
    // Patrón: https://getfutprotec.com/outbound/api/baja.php?email=...  (compatibilidad)
    //         https://getfutprotec.com/outbound/api/baja.php?t=...       (token, nuevos envíos)
    $conSaltos = preg_replace(
        '#(https://getfutprotec\.com/outbound/api/baja\.php\?(?:email|t)=[^\s<]+)#',
        '<a href="$1" style="color:#1a73e8;">$1</a>',
        $conSaltos
    );


    // Píxel de tracking (solo en la parte HTML).
    $pixel = '<img src="' . $trackUrl . '?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';

    return '<div style="white-space:normal; font-family:Arial,sans-serif; font-size:14px; line-height:1.5;">'
        . $conSaltos
        . '</div>'
        . $pixel;
}
}

if (!function_exists('enviarSMTPAutenticado')) {
/**
 * Envía un email vía SMTP autenticado nativo.
 *
 * Para plantillas `texto_plano` construye un mensaje `multipart/alternative` con:
 *   - text/plain  → contenido original con saltos de línea (sin HTML, sin píxel)
 *   - text/html   → mismo contenido convertido a HTML mínimo + píxel de tracking
 *
 * Para plantillas `html` mantiene el comportamiento histórico: text/html con el
 * píxel ya inyectado en el cuerpo.
 *
 * @param array  $cuenta        datos de la cuenta SMTP
 * @param string $destinatario  email destino
 * @param string $asunto        asunto (ya con placeholders sustituidos)
 * @param string $cuerpo        cuerpo base (fuente de verdad para BD / reserva)
 * @param string|null $messageId Message-ID estable
 * @param string $tipoPlantilla 'texto_plano' | 'html'
 * @param string $plainPart     parte text/plain (solo texto_plano)
 * @param string $htmlPart      parte text/html (solo texto_plano)
 * @return array{ok:bool, error:string}
 */
function enviarSMTPAutenticado(
    array $cuenta,
    string $destinatario,
    string $asunto,
    string $cuerpo,
    ?string $messageId = null,
    string $tipoPlantilla = 'texto_plano',
    string $plainPart = '',
    string $htmlPart = ''
): array {
    $tipoPlantilla = strtolower(trim($tipoPlantilla));
    $esHtml = ($tipoPlantilla === 'html');

    // Normalizar la cuenta para el transporte centralizado.
    // La contraseña puede venir cifrada (FP1:...) desde la BD; se descifra
    // aquí para que el transporte reciba siempre el valor en claro.
    $cuentaNormalizada = [
        'email'          => $cuenta['email'],
        'host'           => $cuenta['host'],
        'puerto'         => (int)$cuenta['puerto'],
        'usuario'        => $cuenta['usuario'],
        'password'       => futprotec_descifrarPassword($cuenta['password'] ?? ''),
        'seguridad'      => $cuenta['seguridad'] ?? 'ssl',
        'nombre_emisor'  => $cuenta['nombre_emisor'] ?? '',
    ];


    // Construir opciones para el transporte centralizado.
    $opciones = [];
    if ($messageId !== null && $messageId !== '') {
        $opciones['message_id'] = $messageId;
    }
    $opciones['reply_to'] = $cuenta['email'];

    if ($esHtml) {
        // Plantilla HTML: comportamiento histórico intacto (text/html).
        $opciones['texto_plano'] = '';
        $cuerpoHTML = $cuerpo;
    } else {
        // Plantilla texto_plano: multipart/alternative (text/plain + text/html).
        $opciones['texto_plano'] = $plainPart;
        $cuerpoHTML = $htmlPart;
    }

    // Delegar en el transporte SMTP centralizado.
    return futprotec_enviarSMTP($cuentaNormalizada, $destinatario, $asunto, $cuerpoHTML, $opciones);
}
}
