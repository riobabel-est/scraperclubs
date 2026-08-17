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
    $fromEmail = $cuenta['email'];
    $smtpHost  = $cuenta['host'];
    $smtpPort  = (int)$cuenta['puerto'];
    $smtpUser  = $cuenta['usuario'];
    $smtpPass  = $cuenta['password'];
    $seguridad = $cuenta['seguridad'] ?? 'ssl';

    $tipoPlantilla = strtolower(trim($tipoPlantilla));
    $esHtml = ($tipoPlantilla === 'html');

    // Content-Type principal según el tipo de plantilla:
    // - texto_plano → multipart/alternative (text/plain + text/html)
    // - html        → text/html; charset=UTF-8 (comportamiento histórico intacto)
    $contentType = $esHtml
        ? 'text/html; charset=UTF-8'
        : 'multipart/alternative; boundary="futprotec_alt_' . bin2hex(random_bytes(8)) . '"';

    $timeout = 30;
    $readTimeout = 15;

    try {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $remote = ($smtpPort === 465)
            ? "ssl://{$smtpHost}:{$smtpPort}"
            : "tcp://{$smtpHost}:{$smtpPort}";

        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);

        if (!$fp) {
            return ['ok' => false, 'error' => "Conexión fallida: {$errstr} ({$errno})"];
        }

        // Timeout de LECTURA explícito: evita que fgets() quede bloqueado
        // indefinidamente si el servidor acepta la conexión pero no responde.
        stream_set_timeout($fp, $readTimeout);

        $read = function() use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 512)) !== false) {
                $resp .= $line;
                if (preg_match('/^\d{3}\s/', $line)) break;
                if (!preg_match('/^\d{3}[- ]/', $line)) break;
            }
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Timeout de lectura SMTP');
            }
            return $resp;
        };

        $cmd = function(string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        // Leer banner
        $read();

        // EHLO
        $cmd("EHLO getfutprotec.com");

        // STARTTLS si puerto 587
        if ($smtpPort === 587) {
            $cmd("STARTTLS");
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            stream_set_timeout($fp, $readTimeout);
            $cmd("EHLO getfutprotec.com");
        }

        // AUTH LOGIN
        $cmd("AUTH LOGIN");
        $cmd(base64_encode($smtpUser));
        $cmd(base64_encode($smtpPass));

        // MAIL FROM
        $cmd("MAIL FROM:<{$fromEmail}>");

        // RCPT TO
        $cmd("RCPT TO:<{$destinatario}>");

        // DATA
        $cmd("DATA");

        // Construir mensaje con nombre del remitente dinámico
        $senderName  = $cuenta['nombre_emisor'] ?? '';
        $fromName = !empty($senderName) ? $senderName : ucfirst(explode('@', $fromEmail)[0]);
        $mensaje = "From: {$fromName} <{$fromEmail}>\r\n";
        $mensaje .= "Reply-To: {$fromEmail}\r\n";
        $mensaje .= "To: <{$destinatario}>\r\n";
        $mensaje .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
        if ($messageId !== null && $messageId !== '') {
            $mensaje .= "Message-ID: {$messageId}\r\n";
        }
        $mensaje .= "MIME-Version: 1.0\r\n";
        $mensaje .= "Content-Type: {$contentType}\r\n";
        $mensaje .= "X-Mailer: FutProtec-Lanzadera/2.0\r\n";
        $mensaje .= "\r\n";

        if ($esHtml) {
            // Plantilla HTML: comportamiento histórico intacto.
            $mensaje .= $cuerpo;
        } else {
            // Plantilla texto_plano: multipart/alternative.
            // Extraer el boundary del Content-Type ya construido.
            if (preg_match('/boundary="([^"]+)"/', $contentType, $m)) {
                $boundary = $m[1];
            } else {
                $boundary = 'futprotec_alt_' . bin2hex(random_bytes(8));
            }

            // Parte text/plain: contenido original con saltos de línea, SIN HTML,
            // SIN píxel de tracking, SIN comentarios.
            $mensaje .= "--{$boundary}\r\n";
            $mensaje .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
            $mensaje .= "\r\n";
            $mensaje .= $plainPart;
            $mensaje .= "\r\n";

            // Parte text/html: mismo contenido convertido a HTML mínimo + píxel.
            $mensaje .= "--{$boundary}\r\n";
            $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
            $mensaje .= "\r\n";
            $mensaje .= $htmlPart;
            $mensaje .= "\r\n";

            // Cierre del multipart/alternative.
            $mensaje .= "--{$boundary}--\r\n";
        }

        $mensaje .= "\r\n.\r\n";

        fwrite($fp, $mensaje);
        $dataResp = $read();

        // Verificar respuesta (250 OK esperado)
        $sendOk = str_contains($dataResp, '250');

        // QUIT
        $cmd("QUIT");
        fclose($fp);

        if ($sendOk) {
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => 'Respuesta SMTP inesperada: ' . trim($dataResp)];

    } catch (\Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            @fclose($fp);
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
