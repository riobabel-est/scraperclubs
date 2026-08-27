<?php
/**
 * smtp_transport.php — Transporte SMTP centralizado para FutProtec Outbound.
 *
 * Unifica las 3 implementaciones previas de envío SMTP:
 *   - inc/mime.php            (enviarSMTPAutenticado)
 *   - cli/cron.php            (enviarSMTP)
 *   - api/enviar_smtp_random.php (enviarSMTP / enviarSMTPAutenticado)
 *
 * Características:
 *   - Verificación estricta de códigos SMTP (220/250/334/235/354).
 *   - Soporte SSL (465), STARTTLS (587) y TCP plano.
 *   - Soporte text/html y multipart/alternative (texto_plano + html).
 *   - Soporte Message-ID y headers extra.
 *   - Timeout de lectura explícito para evitar bloqueos.
 *   - Devuelve ['ok' => bool, 'error' => string].
 *
 * Compatible con SiteGround (PHP 8.x nativo, sin extensiones PECL).
 */

// Helper de cifrado reversible (descifra la contraseña SMTP almacenada).
require_once __DIR__ . '/crypto.php';

if (!function_exists('futprotec_leerRespuestaSMTP')) {

    /**
     * Lee una respuesta multilínea del servidor SMTP (RFC 5321).
     */
    function futprotec_leerRespuestaSMTP($socket): string
    {
        $resp = '';
        while (($line = fgets($socket, 512)) !== false) {
            $resp .= $line;
            // Fin de respuesta: 3 dígitos + espacio.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            // Si no es multilínea SMTP real (código-guión o código-espacio), salir.
            if (!preg_match('/^\d{3}[- ]/', $line)) {
                break;
            }
            // Es /^\d{3}-/ → multilínea real, continuar leyendo.
        }
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new \RuntimeException('Timeout de lectura SMTP');
        }
        return trim($resp);
    }
}

if (!function_exists('futprotec_enviarSMTP')) {
    /**
     * Envía un email vía SMTP autenticado usando sockets nativos PHP.
     *
     * @param array  $cuenta  Claves normalizadas:
     *                        email, host, puerto, usuario, password,
     *                        seguridad ('ssl'|'tls'|''), nombre_emisor (opcional).
     * @param string $destinatario Email destino.
     * @param string $asunto   Asunto (se codifica UTF-8 base64).
     * @param string $cuerpoHTML Cuerpo HTML.
     * @param array  $opciones Opcional:
     *                        'texto_plano' => string (si se quiere multipart/alternative),
     *                        'message_id'  => string (Message-ID a usar),
     *                        'headers'     => array (headers extra, clave => valor),
     *                        'reply_to'    => string (Reply-To).
     *
     * @return array ['ok' => bool, 'error' => string]
     */
    function futprotec_enviarSMTP(array $cuenta, string $destinatario, string $asunto, string $cuerpoHTML, array $opciones = []): array
    {
        $fromEmail = $cuenta['email'] ?? '';
        $fromName  = $cuenta['nombre_emisor'] ?? $cuenta['nombre'] ?? $fromEmail;
        $smtpHost  = $cuenta['host'] ?? $cuenta['smtp'] ?? '';
        $smtpPort  = (int)($cuenta['puerto'] ?? 587);
        $smtpUser  = $cuenta['usuario'] ?? $cuenta['user'] ?? '';
        $smtpPass  = $cuenta['password'] ?? $cuenta['pass'] ?? '';
        // La contraseña puede estar cifrada en BD (FP1:...). Descifrar antes de usar.
        $smtpPass  = futprotec_descifrarPassword($smtpPass);
        $seguridad = strtolower($cuenta['seguridad'] ?? '');


        // Inferir seguridad si no viene explícita.
        if ($seguridad === '') {
            $seguridad = ($smtpPort === 465) ? 'ssl' : (($smtpPort === 587) ? 'tls' : '');
        }

        $textoPlano = $opciones['texto_plano'] ?? '';
        $messageId  = $opciones['message_id'] ?? '';
        $headersExtra = $opciones['headers'] ?? [];
        $replyTo    = $opciones['reply_to'] ?? '';

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

            $remote = ($seguridad === 'ssl')
                ? "ssl://{$smtpHost}:{$smtpPort}"
                : "tcp://{$smtpHost}:{$smtpPort}";

            $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) {
                return ['ok' => false, 'error' => "Conexión fallida: {$errstr} ({$errno})"];
            }

            stream_set_timeout($fp, $readTimeout);

            // Helper para enviar comando y leer respuesta.
            $cmd = function (string $c) use ($fp): string {
                fwrite($fp, $c . "\r\n");
                return futprotec_leerRespuestaSMTP($fp);
            };

            // Leer banner (220).
            $resp = futprotec_leerRespuestaSMTP($fp);
            if (substr($resp, 0, 3) !== '220') {
                throw new \RuntimeException("Saludo SMTP inesperado: {$resp}");
            }

            // EHLO.
            $resp = $cmd("EHLO getfutprotec.com");
            if (substr($resp, 0, 3) !== '250') {
                throw new \RuntimeException("EHLO fallido: {$resp}");
            }

            // STARTTLS si es TLS.
            if ($seguridad === 'tls') {
                $resp = $cmd("STARTTLS");
                if (substr($resp, 0, 3) !== '220') {
                    throw new \RuntimeException("STARTTLS fallido: {$resp}");
                }
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                stream_set_timeout($fp, $readTimeout);
                $resp = $cmd("EHLO getfutprotec.com");
                if (substr($resp, 0, 3) !== '250') {
                    throw new \RuntimeException("EHLO tras STARTTLS fallido: {$resp}");
                }
            }

            // AUTH LOGIN.
            $resp = $cmd("AUTH LOGIN");
            if (substr($resp, 0, 3) !== '334') {
                throw new \RuntimeException("AUTH LOGIN no soportado: {$resp}");
            }
            $resp = $cmd(base64_encode($smtpUser));
            if (substr($resp, 0, 3) !== '334') {
                throw new \RuntimeException("Usuario rechazado: {$resp}");
            }
            $resp = $cmd(base64_encode($smtpPass));
            if (substr($resp, 0, 3) !== '235') {
                throw new \RuntimeException("Contraseña rechazada: {$resp}");
            }

            // MAIL FROM.
            $resp = $cmd("MAIL FROM:<{$fromEmail}>");
            if (substr($resp, 0, 3) !== '250') {
                throw new \RuntimeException("MAIL FROM rechazado: {$resp}");
            }

            // RCPT TO.
            $resp = $cmd("RCPT TO:<{$destinatario}>");
            if (substr($resp, 0, 3) !== '250') {
                throw new \RuntimeException("RCPT TO rechazado: {$resp}");
            }

            // DATA.
            $resp = $cmd("DATA");
            if (substr($resp, 0, 3) !== '354') {
                throw new \RuntimeException("DATA rechazado: {$resp}");
            }

            // Construir mensaje.
            $adjuntos = $opciones['adjuntos'] ?? []; // [{nombre, mime, contenido(bytes)}]
            $boundaryAlt = '--=_FutProtec_' . md5(uniqid((string)time(), true) . 'alt');
            $mensaje = "From: {$fromName} <{$fromEmail}>\r\n";
            $mensaje .= "To: <{$destinatario}>\r\n";
            $mensaje .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
            $mensaje .= "MIME-Version: 1.0\r\n";
            $mensaje .= "X-Mailer: FutProtec-Outbound/1.0\r\n";

            if ($replyTo !== '') {
                $mensaje .= "Reply-To: {$replyTo}\r\n";
            }
            if ($messageId !== '') {
                $mensaje .= "Message-ID: {$messageId}\r\n";
            }

            // Headers extra (evitando duplicar los ya establecidos).
            $reservados = ['from', 'to', 'subject', 'mime-version', 'content-type', 'message-id', 'reply-to'];
            foreach ($headersExtra as $k => $v) {
                if (!in_array(strtolower($k), $reservados, true)) {
                    $mensaje .= "{$k}: {$v}\r\n";
                }
            }

            // Parte del cuerpo (multipart/alternative con texto+html, o solo html).
            $cuerpoParte = '';
            if ($textoPlano !== '') {
                $cuerpoParte .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n";
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= "--{$boundaryAlt}\r\n";
                $cuerpoParte .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $cuerpoParte .= "Content-Transfer-Encoding: 8bit\r\n";
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= $textoPlano;
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= "--{$boundaryAlt}\r\n";
                $cuerpoParte .= "Content-Type: text/html; charset=UTF-8\r\n";
                $cuerpoParte .= "Content-Transfer-Encoding: 8bit\r\n";
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= $cuerpoHTML;
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= "--{$boundaryAlt}--\r\n";
            } else {
                $cuerpoParte .= "Content-Type: text/html; charset=UTF-8\r\n";
                $cuerpoParte .= "\r\n";
                $cuerpoParte .= $cuerpoHTML;
            }

            if (!empty($adjuntos)) {
                // multipart/mixed: cuerpo + adjuntos.
                $boundaryMix = '--=_FutProtec_' . md5(uniqid((string)time(), true) . 'mix');
                $mensaje .= "Content-Type: multipart/mixed; boundary=\"{$boundaryMix}\"\r\n";
                $mensaje .= "\r\n";
                $mensaje .= "--{$boundaryMix}\r\n";
                $mensaje .= $cuerpoParte;
                $mensaje .= "\r\n";
                foreach ($adjuntos as $adj) {
                    $fname = basename((string)($adj['nombre'] ?? 'adjunto'));
                    $mime = (string)($adj['mime'] ?? 'application/octet-stream');
                    $bin = (string)($adj['contenido'] ?? '');
                    $mensaje .= "--{$boundaryMix}\r\n";
                    $mensaje .= "Content-Type: {$mime}; name=\"{$fname}\"\r\n";
                    $mensaje .= "Content-Transfer-Encoding: base64\r\n";
                    $mensaje .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n";
                    $mensaje .= "\r\n";
                    $mensaje .= chunk_split(base64_encode($bin)) . "\r\n";
                }
                $mensaje .= "--{$boundaryMix}--\r\n";
            } else {
                // Sin adjuntos: el cuerpo va directo.
                $mensaje .= $cuerpoParte;
            }

            $mensaje .= "\r\n.\r\n";

            fwrite($fp, $mensaje);
            $resp = futprotec_leerRespuestaSMTP($fp);
            if (substr($resp, 0, 3) !== '250') {
                throw new \RuntimeException("Envío de datos fallido: {$resp}");
            }

            // QUIT.
            $cmd("QUIT");
            fclose($fp);

            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            if (isset($fp) && is_resource($fp)) {
                @fclose($fp);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
