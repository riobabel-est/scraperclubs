<?php
/**
 * imap_cliente.php — Cliente IMAP mínimo por sockets (extraído de imap_respuestas.php, T-1).
 * Compatible SiteGround (sin extensión PHP imap).
 */

declare(strict_types=1);

/**
 * Cliente IMAP mínimo por sockets (compatible SiteGround).
 */
class ClienteIMAP
{
    private $socket = null;
    private $tag = 0;
    private $host;
    private $port;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Conecta por SSL y hace LOGIN.
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

        $timeout = $GLOBALS['IMAP_TIMEOUT'] ?? 30;
        $remote = "ssl://{$this->host}:{$this->port}";
        $this->socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            throw new \RuntimeException("No se pudo conectar a {$remote} — {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $timeout);


        // Leer saludo (tag * OK)
        $this->leerRespuesta();

        // LOGIN
        $this->comando("LOGIN " . $this->literal($user) . " " . $this->literal($pass));
        return true;
    }

    /**
     * Envía un comando y devuelve la respuesta completa.
     */
    public function comando(string $cmd): array
    {
        $this->tag++;
        $tag = "A{$this->tag}";
        fwrite($this->socket, "{$tag} {$cmd}\r\n");
        return $this->leerRespuesta($tag);
    }

    /**
     * Lee la respuesta hasta la línea de completado con el tag.
     *
     * FASE 2 — Corrección del parser de literales IMAP `{N}`:
     *  - Al detectar la sintaxis `{N}` al final de una línea de respuesta,
     *    se extrae el entero $N y se leen EXACTAMENTE $N bytes con fread()
     *    (sin esperar un salto de línea), consumiendo la totalidad del
     *    bloque literal.
     *  - Tras consumir el literal, se reanuda la lectura normal de líneas
     *    hasta recibir el tag final del comando (p. ej. "A001 OK ...").
     *  - El literal leído se guarda como un elemento del array de respuesta
     *    (el elemento inmediatamente posterior a la línea que contenía {N}),
     *    de modo que extraerLiteral() pueda recuperarlo de forma fiable.
     */
    private function leerRespuesta(?string $tag = null): array
    {
        $lines = [];

        while (($line = fgets($this->socket, 8192)) !== false) {
            $line = rtrim($line, "\r\n");

            // Detectar literal: "... {N}" al final de la línea
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $n = (int)$m[1];
                // Guardar la línea que anuncia el literal
                $lines[] = $line;
                // Leer EXACTAMENTE $n bytes (el bloque literal), sin esperar \n
                $literalData = $this->leerBytes($n);
                $lines[] = $literalData;
                // Tras el literal, el servidor envía "\r\n" y continúa con más
                // líneas de respuesta. El siguiente fgets() las leerá.
                continue;
            }

            $lines[] = $line;

            // Completado: línea que empieza con el tag (o * OK para saludo)
            if ($tag !== null && strpos($line, $tag . ' ') === 0) {
                break;
            }
            if ($tag === null && strpos($line, '* OK') === 0) {
                break;
            }
        }

        $meta = stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) {
            throw new \RuntimeException('Timeout de lectura IMAP');
        }

        return $lines;
    }

    /**
     * Lee exactamente N bytes del socket.
     * FASE 2: usa fread() para consumir el bloque literal completo sin
     * esperar un carácter de salto de línea (que puede no existir dentro
     * del literal). Respeta el timeout de socket estricto (5s).
     */
    private function leerBytes(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->socket, $n - strlen($data));
            if ($chunk === false || $chunk === '') {
                // Comprobar timeout para no quedarnos colgados
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('Timeout de lectura de literal IMAP');
                }
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }


    /**
     * Escapa un literal IMAP: {len}\r\n<data>
     */
    private function literal(string $s): string
    {
        return '{' . strlen($s) . '}' . "\r\n" . $s;
    }

    /**
     * Lista las carpetas.
     */
    public function listarCarpetas(): array
    {
        $resp = $this->comando('LIST "" "*"');
        $carpetas = [];
        foreach ($resp as $line) {
            if (preg_match('/\* LIST \(([^)]*)\) "([^"]*)" (.+)$/', $line, $m)) {
                $carpetas[] = trim($m[3], '"');
            }
        }
        return $carpetas;
    }

    /**
     * Selecciona una carpeta en modo READ-ONLY. Devuelve el total de mensajes.
     */
    public function seleccionar(string $carpeta): int
    {
        $resp = $this->comando('SELECT ' . $this->literal($carpeta));
        $total = 0;
        foreach ($resp as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                $total = (int)$m[1];
            }
        }
        return $total;
    }

    /**
     * Busca todos los mensajes. Devuelve array de números de secuencia.
     */
    public function buscarTodos(): array
    {
        $resp = $this->comando('SEARCH ALL');
        $seqs = [];
        foreach ($resp as $line) {
            if (preg_match('/^\* SEARCH (.+)$/', $line, $m)) {
                $seqs = preg_split('/\s+/', trim($m[1]));
            }
        }
        return array_filter($seqs);
    }

    /**
     * Obtiene el UID de un mensaje (para idempotencia cuenta+UID).
     */
    public function fetchUID(string $seq): string
    {
        $resp = $this->comando("FETCH {$seq} (UID)");
        foreach ($resp as $line) {
            if (preg_match('/UID\s+(\d+)/i', $line, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * Obtiene el ENVELOPE de un mensaje (comando ligero que NO se cuelga
     * en mensajes problemáticos del servidor IMAP de SiteGround).
     * Devuelve el array de líneas de respuesta.
     */
    public function fetchEnvelope(string $seq): array
    {
        return $this->comando("FETCH {$seq} (ENVELOPE)");
    }

    /**
     * FASE 1 — Obtiene UID + ENVELOPE + FLAGS en UN SOLO comando.
     *
     * `FETCH <seq> (UID ENVELOPE FLAGS)` devuelve en una única respuesta los
     * metadatos necesarios para la atribución e idempotencia:
     *  - UID: para idempotencia cuenta+UID (evita un FETCH UID separado que
     *    añade una ronda extra y puede colgarse en SiteGround).
     *  - ENVELOPE: Message-ID, In-Reply-To, From, Subject, Date.
     *  - FLAGS: para detectar mensajes marcados (p. ej. \Seen) si se desea.
     *
     * Devuelve el array de líneas de respuesta (el parser de ENVELOPE y el
     * extractor de UID operan sobre él).
     */
    public function fetchEnvelopeCompleto(string $seq): array
    {
        return $this->comando("FETCH {$seq} (UID ENVELOPE FLAGS)");
    }

    /**
     * FASE 1 — Extrae el UID de una respuesta FETCH (UID ENVELOPE FLAGS).
     * Devuelve '' si no se encuentra.
     */
    public function extraerUID(array $resp): string
    {
        foreach ($resp as $line) {
            if (preg_match('/\bUID\s+(\d+)/i', $line, $m)) {
                return $m[1];
            }
        }
        return '';
    }


    /**
     * Obtiene SOLO campos concretos del header (BODY.PEEK[HEADER.FIELDS ...]).
     * Es mucho más ligero que BODY.PEEK[HEADER] o BODY.PEEK[TEXT], por lo que
     * el servidor IMAP de SiteGround suele responderlo sin colgarse.
     * Devuelve el texto crudo del header (o '' si falla).
     */
    public function fetchHeaderFields(string $seq): string
    {
        $campos = 'SUBJECT FROM TO DATE MESSAGE-ID IN-REPLY-TO REFERENCES';
        $resp = $this->comando("FETCH {$seq} (BODY.PEEK[HEADER.FIELDS ({$campos})])");
        return $this->extraerLiteral($resp);
    }


    /**
     * Obtiene cabeceras de un mensaje (BODY.PEEK[HEADER] — no marca leído).
     */
    public function fetchCabeceras(string $seq): string
    {
        $resp = $this->comando("FETCH {$seq} (BODY.PEEK[HEADER])");
        return $this->extraerLiteral($resp);
    }


    /**
     * Obtiene el mensaje COMPLETO por UID (backfill de cuerpos de respuestas
     * ya registradas sin contenido).
     */
    public function fetchCuerpoPorUID(string $uid): string
    {
        $resp = $this->comando("UID FETCH {$uid} (BODY.PEEK[])");
        return $this->extraerLiteral($resp);
    }

    /**
     * Obtiene el cuerpo de un mensaje (BODY.PEEK[TEXT] — no marca leído).
     */
    public function fetchCuerpo(string $seq): string
    {
        $resp = $this->comando("FETCH {$seq} (BODY.PEEK[TEXT])");
        return $this->extraerLiteral($resp);
    }

    /**
     * Obtiene el mensaje COMPLETO (cabeceras + cuerpo) con BODY.PEEK[].
     * Fallback de fetchCuerpo(): algunos mensajes de SiteGround no responden
     * a BODY.PEEK[TEXT] pero sí al mensaje completo.
     */
    public function fetchCuerpoCompleto(string $seq): string
    {
        $resp = $this->comando("FETCH {$seq} (BODY.PEEK[])");
        return $this->extraerLiteral($resp);
    }

    /**
     * Extrae el contenido del literal de una respuesta FETCH.
     */
    private function extraerLiteral(array $resp): string
    {
        // Buscar la línea que contiene {N} y tomar el siguiente elemento como literal
        for ($i = 0; $i < count($resp); $i++) {
            if (preg_match('/\{(\d+)\}$/', $resp[$i], $m)) {
                $n = (int)$m[1];
                // El literal es el siguiente elemento (ya leído por leerRespuesta)
                if (isset($resp[$i + 1])) {
                    return $resp[$i + 1];
                }
            }
        }
        return '';
    }

    /**
     * Cierra la conexión.
     */
    public function cerrar(): void
    {
        if ($this->socket) {
            try {
                $this->comando('LOGOUT');
            } catch (\Throwable $e) {
                // ignorar
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }
}
