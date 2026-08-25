<?php
/**
 * pop3_respuestas.php — Fallback POP3 (puerto 995) para registro de respuestas
 * =============================================================================
 * FASE 3 — Si la conexión por IMAP sigue fallando o sufriendo cierres por parte
 * de SiteGround, este módulo actúa como respaldo usando el protocolo POP3 sobre
 * SSL (ssl://mail.getfutprotec.com:995).
 *
 * Compatibilidad SiteGround:
 *  - NO depende de la extensión PHP `imap` ni de extensiones PECL.
 *  - Usa sockets directos (mismo patrón que enviarSMTP() en cron.php).
 *  - PHP 8.x nativo, SQLite3.
 *
 * Secuencia estándar POP3 implementada:
 *  - USER <email>
 *  - PASS <password>
 *  - STAT / LIST
 *  - TOP <msg_num> 10   → lee solo las primeras 10 líneas de cabecera para
 *                         extraer Message-ID, In-Reply-To y From.
 *  - RETR <msg_num>     → extrae el cuerpo completo finalizado en la línea ".\r\n".
 *
 * MODO ESTRICTAMENTE READ-ONLY sobre el buzón:
 *  - NO ejecuta DELE (no borra mensajes).
 *  - NO ejecuta RSET / NOOP destructivos.
 *  - La única escritura es en la BD local (tabla respuestas + comunicaciones_log).
 */

declare(strict_types=1);

// Helper de cifrado reversible (descifra la contraseña POP3 almacenada).
require_once __DIR__ . '/crypto.php';

// ─── Configuración ───
$POP3_HOST = 'mail.getfutprotec.com';

$POP3_PORT = 995; // POP3 SSL
$POP3_TIMEOUT = 5; // Timeout estricto de 5s por operación (mismo criterio que IMAP)

/**
 * Cliente POP3 mínimo por sockets (compatible SiteGround).
 */
class ClientePOP3
{
    private $socket = null;
    private $host;
    private $port;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Conecta por SSL y hace USER/PASS.
     */
    public function conectar(string $user, string $pass): bool
    {
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $timeout = $GLOBALS['POP3_TIMEOUT'] ?? 30;
        $remote = "ssl://{$this->host}:{$this->port}";
        $this->socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            throw new \RuntimeException("No se pudo conectar a {$remote} — {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $timeout);

        // Saludo: "+OK ..."
        $this->leerLinea();

        // USER
        $this->comando("USER {$user}");
        // PASS
        $this->comando("PASS {$pass}");

        return true;
    }

    /**
     * Envía un comando y devuelve la primera línea de respuesta.
     * Lanza excepción si la respuesta no empieza por "+OK".
     */
    public function comando(string $cmd): string
    {
        fwrite($this->socket, $cmd . "\r\n");
        $linea = $this->leerLinea();
        if (strpos($linea, '+OK') !== 0) {
            throw new \RuntimeException("POP3 error en '{$cmd}': {$linea}");
        }
        return $linea;
    }

    /**
     * Lee una línea del socket (hasta \r\n). Respeta el timeout estricto.
     */
    private function leerLinea(): string
    {
        $linea = fgets($this->socket, 8192);
        if ($linea === false) {
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Timeout de lectura POP3');
            }
            throw new \RuntimeException('Conexión POP3 cerrada por el servidor');
        }
        return rtrim($linea, "\r\n");
    }

    /**
     * STAT — Devuelve [numMensajes, tamañoTotal].
     */
    public function stat(): array
    {
        $resp = $this->comando('STAT');
        // "+OK 25 123456"
        if (preg_match('/\+OK\s+(\d+)\s+(\d+)/', $resp, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        return [0, 0];
    }

    /**
     * LIST — Devuelve array de números de mensaje.
     */
    public function list(): array
    {
        fwrite($this->socket, "LIST\r\n");
        $primera = $this->leerLinea();
        if (strpos($primera, '+OK') !== 0) {
            throw new \RuntimeException("POP3 error en LIST: {$primera}");
        }
        $nums = [];
        while (($linea = $this->leerLinea()) !== '.') {
            if (preg_match('/^(\d+)\s+\d+$/', $linea, $m)) {
                $nums[] = (int)$m[1];
            }
        }
        return $nums;
    }

    /**
     * TOP <msg_num> 10 — Lee solo las primeras 10 líneas de cabecera.
     * Devuelve el texto crudo de las cabeceras (finalizado en ".").
     */
    public function top(int $msgNum, int $lineas = 10): string
    {
        fwrite($this->socket, "TOP {$msgNum} {$lineas}\r\n");
        $primera = $this->leerLinea();
        if (strpos($primera, '+OK') !== 0) {
            throw new \RuntimeException("POP3 error en TOP {$msgNum}: {$primera}");
        }
        $data = '';
        while (($linea = $this->leerLinea()) !== '.') {
            $data .= $linea . "\r\n";
        }
        return $data;
    }

    /**
     * RETR <msg_num> — Descarga el mensaje completo (cabeceras + cuerpo),
     * finalizado en la línea ".\r\n". Devuelve el texto crudo.
     */
    public function retr(int $msgNum): string
    {
        fwrite($this->socket, "RETR {$msgNum}\r\n");
        $primera = $this->leerLinea();
        if (strpos($primera, '+OK') !== 0) {
            throw new \RuntimeException("POP3 error en RETR {$msgNum}: {$primera}");
        }
        $data = '';
        while (($linea = $this->leerLinea()) !== '.') {
            $data .= $linea . "\r\n";
        }
        return $data;
    }

    /**
     * QUIT — Cierra la sesión de forma limpia.
     */
    public function cerrar(): void
    {
        if ($this->socket) {
            try {
                fwrite($this->socket, "QUIT\r\n");
            } catch (\Throwable $e) {
                // ignorar
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }
}

/**
 * Parsea el texto crudo de TOP/RETR (cabeceras + cuerpo) en un array estructurado.
 * Reutiliza la lógica de imap_parsear_mensaje (que ya separa cabeceras y cuerpo).
 */
function pop3_parsear_mensaje(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    // imap_parsear_mensaje está definido en inc/imap_respuestas.php (se incluye
    // antes que este archivo en el orquestador). Devuelve message_id, in_reply_to,
    // references, from, from_email, to, to_email, subject, date, cuerpo.
    return imap_parsear_mensaje($raw);
}

/**
 * Procesa un buzón POP3 completo de una cuenta.
 * Devuelve estadísticas (misma estructura que imap_procesar_buzon).
 */
function pop3_procesar_buzon(SQLite3 $db, array $cuenta, ClientePOP3 $pop3): array
{
    $stats = ['carpetas' => 1, 'mensajes' => 0, 'insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'sin_atribucion' => 0];

    // STAT para saber cuántos mensajes hay
    [$numMensajes, ] = $pop3->stat();
    if ($numMensajes === 0) {
        return $stats;
    }

    // LIST para obtener los números de mensaje
    $nums = $pop3->list();
    $stats['mensajes'] = count($nums);

    foreach ($nums as $msgNum) {
        try {
            // ─── Fuente PRIMARIA: TOP <msg_num> 10 ───
            // Lee solo las primeras 10 líneas de cabecera para extraer
            // Message-ID, In-Reply-To y From de forma ligera.
            $msg = [];
            try {
                $rawTop = $pop3->top($msgNum, 10);
                if (trim($rawTop) !== '') {
                    $msg = pop3_parsear_mensaje($rawTop);
                }
            } catch (\Throwable $e) {
                // TOP falló (timeout): el socket puede quedar corrupto.
                // Reconectar y continuar con el siguiente mensaje.
                try { $pop3->cerrar(); } catch (\Throwable $ign) {}
                $pop3 = new ClientePOP3($GLOBALS['POP3_HOST'], $GLOBALS['POP3_PORT']);
                $pop3->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''));
            }

            // ─── Cuerpo: RETR <msg_num> con degradado elegante ───

            // Si TOP no aportó cuerpo (solo cabeceras), se intenta RETR para
            // obtener el cuerpo completo. Si falla, se mantienen los datos de TOP.
            if (empty($msg['cuerpo'])) {
                try {
                    $rawRetr = $pop3->retr($msgNum);
                    if (trim($rawRetr) !== '') {
                        $msg = pop3_parsear_mensaje($rawRetr);
                    }
                } catch (\Throwable $e) {
                    // Timeout/error en RETR: degradar elegantemente.
                    try { $pop3->cerrar(); } catch (\Throwable $ign) {}
                    $pop3 = new ClientePOP3($GLOBALS['POP3_HOST'], $GLOBALS['POP3_PORT']);
                    $pop3->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''));
                }
            }


            $clasificacion = imap_clasificar($msg);
            $envio = imap_atribuir($db, $msg);

            if ($envio === null) {
                $stats['sin_atribucion']++;
            }

            // POP3 no tiene UID estable; usamos message_id para idempotencia.
            $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, 'POP3', null, $cuenta['email']);

            if ($resultado === 'insertado') {
                $stats['insertados']++;
            } elseif ($resultado === 'duplicado') {
                $stats['duplicados']++;
            } else {
                $stats['errores']++;
            }
        } catch (\Throwable $e) {
            $stats['errores']++;
        }
    }

    return $stats;
}

/**
 * Orquestador POP3: recorre todas las cuentas SMTP activas con driver_sync='POP3'
 * (o como fallback si IMAP falla) y procesa sus buzones.
 */
function pop3_procesar_todas_cuentas(SQLite3 $db): array
{
    $resultado = ['cuentas' => 0, 'total_insertados' => 0, 'total_duplicados' => 0, 'total_errores' => 0, 'detalle' => []];

    $cuentas = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
    while ($cuenta = $cuentas->fetchArray(SQLITE3_ASSOC)) {
        $resultado['cuentas']++;
        $pop3 = new ClientePOP3($GLOBALS['POP3_HOST'], $GLOBALS['POP3_PORT']);
        try {
            $pop3->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''));
            $stats = pop3_procesar_buzon($db, $cuenta, $pop3);

            $resultado['total_insertados'] += $stats['insertados'];
            $resultado['total_duplicados'] += $stats['duplicados'];
            $resultado['total_errores'] += $stats['errores'];
            $resultado['detalle'][$cuenta['email']] = $stats;
        } catch (\Throwable $e) {
            $resultado['total_errores']++;
            $resultado['detalle'][$cuenta['email']] = ['error' => $e->getMessage()];
        } finally {
            $pop3->cerrar();
        }
    }

    return $resultado;
}
