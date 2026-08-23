<?php
/**
 * imap_respuestas.php — Módulo IMAP de registro de respuestas (FASE F)
 * =====================================================================
 * Lee los buzones IMAP de las cuentas SMTP y registra las respuestas
 * recibidas en la tabla `respuestas`, con atribución a lead/envío/campaña
 * e idempotencia.
 *
 * Compatibilidad SiteGround:
 *  - NO depende de la extensión PHP `imap` (puede no estar habilitada).
 *  - Usa sockets directos (mismo patrón que enviarSMTP() en cron.php).
 *  - PHP 8.x nativo, SQLite3.
 *
 * MODO ESTRICTAMENTE READ-ONLY sobre el buzón:
 *  - SELECT en modo readonly (no marca mensajes como leídos).
 *  - FETCH con BODY.PEEK (no altera el flag \Seen).
 *  - NO ejecuta STORE / COPY / MOVE / DELETE / EXPUNGE / APPEND.
 *
 * La única escritura es en la BD local (tabla respuestas + comunicaciones_log),
 * que es el objetivo de la FASE F.
 */

declare(strict_types=1);

// ─── Configuración ───
$DB_PATH = __DIR__ . '/../data/stats.db';
$IMAP_HOST = 'mail.getfutprotec.com';
$IMAP_PORT = 993; // IMAP SSL
// Timeout estricto de 5 segundos por operación de socket (FASE 2):
// evita que un comando BODY.PEEK que SiteGround no responde deje el
// proceso colgado durante 120s. Si se agota, se degrada a ENVELOPE.
$IMAP_TIMEOUT = 5;


$CARPETAS_AUDITAR = ['INBOX', 'INBOX.Junk', 'INBOX.spam'];

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
     * Obtiene el cuerpo de un mensaje (BODY.PEEK[TEXT] — no marca leído).
     */
    public function fetchCuerpo(string $seq): string
    {
        $resp = $this->comando("FETCH {$seq} (BODY.PEEK[TEXT])");
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

/**
 * Decodifica cabeceras MIME (RFC 2047).
 */
function imap_decodificar(string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') {
        return '';
    }
    // Decodificar =?charset?B?...?= y =?charset?Q?...?=
    if (preg_match('/=\?[^?]+\?[BQ]\?[^?]*\?=/i', $valor)) {
        $partes = preg_split('/(=\?[^?]+\?[BQ]\?[^?]*\?=)/i', $valor, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $decodificado = '';
        foreach ($partes as $p) {
            if (preg_match('/^=\?([^?]+)\?([BQ])\?([^?]*)\?=$/i', $p, $m)) {
                $charset = $m[1];
                $enc = strtoupper($m[2]);
                $data = $m[3];
                if ($enc === 'B') {
                    $decodificado .= base64_decode($data);
                } else {
                    $decodificado .= quoted_printable_decode(str_replace('_', ' ', $data));
                }
            } else {
                $decodificado .= $p;
            }
        }
        return $decodificado;
    }
    return $valor;
}

/**
 * Extrae el email de una cabecera From/To (ej: "Nombre <email>").
 */
function imap_extraer_email(string $cabecera): string
{
    if (preg_match('/<([^>]+)>/', $cabecera, $m)) {
        return trim($m[1]);
    }
    return trim($cabecera);
}

/**
 * Parsea un mensaje crudo (cabeceras + cuerpo) en un array estructurado.
 */
function imap_parsear_mensaje(string $raw): array
{
    // Separar cabeceras y cuerpo
    $partes = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
    $cabecerasRaw = $partes[0] ?? '';
    $cuerpo = $partes[1] ?? '';

    // Parsear cabeceras
    $cabeceras = [];
    $lines = preg_split("/\r\n|\n/", $cabecerasRaw);
    $current = '';
    foreach ($lines as $line) {
        if (preg_match('/^(\S+):\s*(.*)$/', $line, $m)) {
            $current = strtolower($m[1]);
            $cabeceras[$current] = ($cabeceras[$current] ?? '') . $m[2];
        } elseif (preg_match('/^\s+(.+)$/', $line, $m) && $current !== '') {
            // Línea continuada
            $cabeceras[$current] .= ' ' . trim($m[1]);
        }
    }

    $get = function (string $k) use ($cabeceras): string {
        return $cabeceras[$k] ?? '';
    };

    return [
        'message_id'   => imap_decodificar($get('message-id')),
        'in_reply_to'  => imap_decodificar($get('in-reply-to')),
        'references'   => imap_decodificar($get('references')),
        'from'         => imap_decodificar($get('from')),
        'from_email'   => imap_extraer_email(imap_decodificar($get('from'))),
        'to'           => imap_decodificar($get('to')),
        'to_email'     => imap_extraer_email(imap_decodificar($get('to'))),
        'subject'      => imap_decodificar($get('subject')),
        'date'         => imap_decodificar($get('date')),
        'cuerpo'       => trim($cuerpo),
    ];
}

/**
 * Parsea la respuesta ENVELOPE de IMAP en un array estructurado.
 * El ENVELOPE es un comando ligero que el servidor IMAP de SiteGround
 * SÍ responde (a diferencia de BODY.PEEK[HEADER]/BODY.PEEK[TEXT] que se
 * cuelgan en mensajes problemáticos).
 *
 * Formato ENVELOPE:
 *   ENVELOPE (date subject (from) (sender) (reply-to) (to) (cc) (bcc) in-reply-to message-id)
 *   Cada address: (name adl mailbox host)
 */
function imap_parsear_envelope(array $resp): array
{
    // Buscar la línea FETCH que contiene ENVELOPE (la respuesta incluye además
    // la línea de completado "A1 OK FETCH completed", por lo que NO se puede
    // usar un regex que exija que la cadena termine en ')').
    $raw = implode(' ', $resp);
    if (!preg_match('/ENVELOPE\s*\((.*)\)/', $raw, $m)) {
        return [];
    }
    $env = $m[1];


    // Tokenizar respetando paréntesis y comillas
    $tokens = [];
    $len = strlen($env);
    $i = 0;
    $depth = 0;
    $current = '';
    $inQuote = false;
    while ($i < $len) {
        $ch = $env[$i];
        if ($ch === '"') {
            $inQuote = !$inQuote;
            $current .= $ch;
        } elseif ($ch === '(' && !$inQuote) {
            $depth++;
            $current .= $ch;
        } elseif ($ch === ')' && !$inQuote) {
            $depth--;
            $current .= $ch;
        } elseif ($ch === ' ' && !$inQuote && $depth === 0) {
            if (trim($current) !== '') {
                $tokens[] = trim($current);
            }
            $current = '';
        } else {
            $current .= $ch;
        }
        $i++;
    }
    if (trim($current) !== '') {
        $tokens[] = trim($current);
    }

    // Los tokens del ENVELOPE (sin el paréntesis exterior):
    // [0]=date [1]=subject [2]=from [3]=sender [4]=reply-to [5]=to [6]=cc [7]=bcc [8]=in-reply-to [9]=message-id
    $date = $tokens[0] ?? '';
    $subject = $tokens[1] ?? '';
    $from = $tokens[2] ?? '';
    $to = $tokens[5] ?? '';
    $inReplyTo = $tokens[8] ?? '';
    $messageId = $tokens[9] ?? '';

    // ─── LIMPIEZA del message-id ───
    // El regex greedy '/ENVELOPE\s*\((.*)\)/' captura hasta el último ')', que
    // es el cierre del FETCH (no del ENVELOPE). Por eso el token message-id
    // puede incluir basura del protocolo (p. ej. ')) A4 OK Fetch completed').
    // El message-id siempre termina con '>', así que recortamos ahí.
    // Además, el token puede venir entre comillas ("<...>"), así que también
    // se eliminan las comillas dobles.
    if (strpos($messageId, '>') !== false) {
        $messageId = substr($messageId, 0, strpos($messageId, '>') + 1);
    }
    // Igual para in-reply-to (también termina con '>')
    if (strpos($inReplyTo, '>') !== false) {
        $inReplyTo = substr($inReplyTo, 0, strpos($inReplyTo, '>') + 1);
    }


    // Extraer email de un bloque address: (name adl mailbox host)
    // Cada campo puede ser NIL o una cadena entre comillas. El mailbox y host
    // son los campos 3 y 4. Regex robusto que tolera NIL.
    // El token del bloque address viene envuelto en paréntesis (p. ej.
    // (("Nombre" NIL "mailbox" "host")) o ((NIL NIL "mailbox" "host"))), por lo
    // que primero se extrae el contenido entre el primer '(' y el último ')'.
    $extraerEmail = function (string $addr): string {
        // Quitar los paréntesis exteriores del bloque address de forma iterativa.
        // El bloque address puede venir con varios niveles de paréntesis, p. ej.
        //   (("Nombre" NIL "mailbox" "host"))   ← lista de 1 address
        //   ((NIL NIL "mailbox" "host"))        ← address sin nombre
        // Tras quitar todos los paréntesis exteriores queda:
        //   "Nombre" NIL "mailbox" "host"   (o NIL si no hay address)
        $addr = trim($addr);
        while (strpos($addr, '(') === 0 && strrpos($addr, ')') === strlen($addr) - 1) {
            $addr = trim(substr($addr, 1, -1));
        }
        // Buscar los 4 campos (cada uno NIL o "cadena")
        if (preg_match('/^(?:NIL|"[^"]*")\s+(?:NIL|"[^"]*")\s+(?:NIL|"([^"]*)")\s+(?:NIL|"([^"]*)")/', $addr, $m)) {
            $mailbox = $m[1] ?? '';
            $host = $m[2] ?? '';
            if ($mailbox !== '' && $host !== '') {
                return $mailbox . '@' . $host;
            }
        }
        return '';
    };



    return [
        'message_id'   => trim($messageId, '<>"'),
        'in_reply_to'  => trim($inReplyTo, '<>"'),

        'references'   => '', // ENVELOPE no incluye References; se rellena si se lee el header
        'from'         => $from,
        'from_email'   => $extraerEmail($from),
        'to'           => $to,
        'to_email'     => $extraerEmail($to),
        'subject'      => imap_decodificar(trim($subject, '"')),
        'date'         => trim($date, '"'),
        'cuerpo'       => '',
    ];
}

/**
 * Parsea el texto crudo de BODY.PEEK[HEADER.FIELDS ...] en un array estructurado.
 * Reutiliza la lógica de imap_parsear_mensaje (que ya separa cabeceras y cuerpo).
 * Devuelve un array con los campos del header (subject, from, to, message_id,
 * in_reply_to, references, date).
 */
function imap_parsear_header_fields(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }

    // ─── LIMPIEZA del literal de HEADER.FIELDS ───
    // El literal devuelto por BODY.PEEK[HEADER.FIELDS ...] puede incluir, al
    // final, el cierre del FETCH y la línea de completado del protocolo IMAP
    // (p. ej. ")) A4 OK Fetch completed ..."). Para evitar que esa basura se
    // pegue a la última cabecera (p. ej. Message-ID), recortamos el raw en el
    // último salto de línea que no forme parte de una cabecera válida.
    $raw = imap_limpiar_literal_header($raw);

    $msg = imap_parsear_mensaje($raw);
    // imap_parsear_mensaje ya devuelve message_id, in_reply_to, references,
    // from, from_email, to, to_email, subject, date, cuerpo.
    return $msg;
}

/**
 * Limpia el literal de BODY.PEEK[HEADER.FIELDS ...] eliminando la basura del
 * protocolo IMAP que el servidor puede pegar al final (cierre del FETCH y
 * línea de completado). Conserva solo las líneas de cabecera válidas.
 */
function imap_limpiar_literal_header(string $raw): string
{
    $lines = preg_split("/\r\n|\n/", $raw);
    $limpias = [];
    $cabecerasValidas = ['subject', 'from', 'to', 'date', 'message-id', 'in-reply-to', 'references'];
    $enCabecera = false;

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        // ¿Es una cabecera válida?
        if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $trim, $m)) {
            $nombre = strtolower($m[1]);
            if (in_array($nombre, $cabecerasValidas, true)) {
                $limpias[] = $line;
                $enCabecera = true;
                continue;
            }
            // Cabecera no solicitada: detener (es basura del protocolo)
            $enCabecera = false;
            break;
        }
        // Línea continuada de una cabecera válida
        if ($enCabecera && preg_match('/^\s+\S/', $line)) {
            $limpias[] = $line;
            continue;
        }
        // Cualquier otra línea (cierre FETCH, completado, etc.) → detener
        break;
    }

    return implode("\r\n", $limpias);
}

/**
 * Clasificación inicial de la respuesta (sin IA).
 */
function imap_clasificar(array $msg, ?SQLite3 $db = null): string
{
    $subject = strtolower($msg['subject'] ?? '');
    $from = strtolower($msg['from_email'] ?? '');
    $cuerpo = strtolower($msg['cuerpo'] ?? '');

    // Rebote (mailer-daemon)
    if (strpos($from, 'mailer-daemon') !== false || strpos($from, 'postmaster') !== false) {
        return 'rebote';
    }
    // Baja (unsubscribe)
    if (strpos($subject, 'unsubscribe') !== false || strpos($subject, 'baja') !== false) {
        return 'baja';
    }
    // Fuera de oficina
    if (strpos($subject, 'out of office') !== false || strpos($subject, 'fuera de oficina') !== false
        || strpos($subject, 'vacaciones') !== false || strpos($subject, 'ausencia') !== false) {
        return 'fuera_de_oficina';
    }
    // Automática (respuestas automáticas típicas)
    if (strpos($subject, 'automatic reply') !== false || strpos($subject, 'respuesta autom') !== false
        || strpos($subject, 'auto-reply') !== false) {
        return 'automatica';
    }
    // Sin In-Reply-To ni References → probablemente no es respuesta a un envío
    if (empty($msg['in_reply_to']) && empty($msg['references'])) {
        return 'desconocida';
    }

    // ─── Refinamiento con IA (DeepSeek) ─────────────────────────────────────
    // Si hay BD y una API key configurada, se intenta clasificar la intención
    // comercial de la respuesta humana. Si no hay key, falla o devuelve algo
    // no válido, se mantiene 'humana' (comportamiento original).
    if ($db !== null) {
        $intencion = imap_clasificar_con_ia($db, $msg);
        if ($intencion !== null) {
            return $intencion;
        }
    }

    // Por defecto: humana
    return 'humana';
}

/**
 * Clasifica la intención comercial de una respuesta humana usando DeepSeek.
 * Devuelve la intención (interesado|duda_precio|baja|neutral|no_interesa|otro)
 * o null si no hay API key configurada o la llamada falla (fallback a 'humana').
 */
function imap_clasificar_con_ia(SQLite3 $db, array $msg): ?string
{
    // ─── Leer configuración de IA (multi-proveedor) ─────────────────────────
    // Mapa de proveedores: clave de API key y clave de modelo en la tabla config.
    $PROVEEDORES = [
        'deepseek'  => ['api' => 'deepseek_api_key',  'modelo' => 'deepseek_model',  'nombre' => 'DeepSeek'],
        'openai'    => ['api' => 'openai_api_key',    'modelo' => 'openai_model',    'nombre' => 'OpenAI'],
        'anthropic' => ['api' => 'anthropic_api_key', 'modelo' => 'anthropic_model', 'nombre' => 'Anthropic'],
        'google'    => ['api' => 'google_api_key',    'modelo' => 'google_model',    'nombre' => 'Google Gemini'],
        'mistral'   => ['api' => 'mistral_api_key',   'modelo' => 'mistral_model',   'nombre' => 'Mistral'],
        'groq'      => ['api' => 'groq_api_key',      'modelo' => 'groq_model',      'nombre' => 'Groq'],
    ];
    $MODELOS_DEFECTO = [
        'deepseek'  => 'deepseek-chat',
        'openai'    => 'gpt-4o-mini',
        'anthropic' => 'claude-3-5-haiku-latest',
        'google'    => 'gemini-1.5-flash',
        'mistral'   => 'mistral-small-latest',
        'groq'      => 'llama-3.3-70b-versatile',
    ];

    $config = [];
    $res = $db->query("SELECT clave, valor FROM config");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $config[$r['clave']] = (string)$r['valor'];
    }

    $proveedor = $config['ia_proveedor'] ?? 'deepseek';
    if (!isset($PROVEEDORES[$proveedor])) {
        $proveedor = 'deepseek';
    }
    $apiKey = $config[$PROVEEDORES[$proveedor]['api']] ?? '';
    $modelo = $config[$PROVEEDORES[$proveedor]['modelo']] ?? ($MODELOS_DEFECTO[$proveedor] ?? 'deepseek-chat');

    if ($apiKey === '') {
        return null; // IA no configurada → fallback a 'humana'
    }

    $cuerpo = trim((string)($msg['cuerpo'] ?? ''));
    $asunto = trim((string)($msg['subject'] ?? ''));
    if ($cuerpo === '') {
        return null;
    }
    $cuerpo = mb_substr($cuerpo, 0, 3000);

    $system = <<<PROMPT
Eres un asistente de clasificación de respuestas de email para un CRM de ventas B2B
de software de gestión para clubes de fútbol (FutProtec). Clasifica la intención
comercial del mensaje entrante en UNA de estas categorías exactas:

- interesado: muestra interés en el producto, pide información, presupuesto o demo.
- duda_precio: pregunta por precios, costes o condiciones económicas.
- baja: pide que no le contacten más, baja, unsubscribe, opt-out.
- neutral: respuesta genérica, cortesía, sin intención comercial clara.
- no_interesa: rechaza explícitamente el producto o servicio.
- otro: cualquier otra cosa (fuera de oficina, spam, etc.).

Responde SOLO con el nombre de la categoría exacta, sin texto adicional.
PROMPT;

    $user = "ASUNTO: {$asunto}\n\nCUERPO:\n{$cuerpo}";

    // ─── Construir la llamada según el proveedor activo ─────────────────────
    $url = '';
    $headers = ['Content-Type: application/json'];
    $payload = [];

    switch ($proveedor) {
        case 'openai':
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $payload = [
                'model'    => $modelo,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 20,
            ];
            break;

        case 'anthropic':
            $url = 'https://api.anthropic.com/v1/messages';
            $headers[] = 'x-api-key: ' . $apiKey;
            $headers[] = 'anthropic-version: 2023-06-01';
            $payload = [
                'model'      => $modelo,
                'max_tokens' => 20,
                'temperature' => 0.1,
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user],
                ],
            ];
            break;

        case 'google':
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . $apiKey;
            $payload = [
                'contents' => [
                    ['parts' => [['text' => $system . "\n\n" . $user]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 20,
                ],
            ];
            break;

        case 'mistral':
            $url = 'https://api.mistral.ai/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $payload = [
                'model'    => $modelo,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 20,
            ];
            break;

        case 'groq':
            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $payload = [
                'model'    => $modelo,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 20,
            ];
            break;

        case 'deepseek':
        default:
            $url = 'https://api.deepseek.com/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $payload = [
                'model'    => $modelo,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 20,
            ];
            break;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return null;
    }
    $data = json_decode($resp, true);

    // Extraer el contenido según el formato de cada proveedor.
    $contenido = '';
    if ($proveedor === 'anthropic') {
        $contenido = trim((string)($data['content'][0]['text'] ?? ''));
    } elseif ($proveedor === 'google') {
        $contenido = trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    } else {
        $contenido = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    }
    $contenido = strtolower($contenido);

    $permitidas = ['interesado', 'duda_precio', 'baja', 'neutral', 'no_interesa', 'otro'];
    foreach ($permitidas as $p) {
        if (strpos($contenido, $p) !== false) {
            return $p;
        }
    }
    return null;
}

/**
 * Atribuye una respuesta a un envío/lead/campaña.
 * Prioridad: In-Reply-To → References → email remitente.
 */
function imap_atribuir(SQLite3 $db, array $msg): ?array
{
    // 1. Buscar por In-Reply-To (message_id del envío original)
    //    Normalizamos quitando los corchetes < > porque envios.message_id
    //    se guarda con corchetes y el in_reply_to del mensaje llega sin ellos.
    if (!empty($msg['in_reply_to'])) {
        $mid = trim($msg['in_reply_to'], '<> ');
        $stmt = $db->prepare("SELECT * FROM envios WHERE REPLACE(message_id, '<', '') = REPLACE(REPLACE(:mid, '<', ''), '>', '') LIMIT 1");
        $stmt->bindValue(':mid', $mid, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            return $row;
        }
    }

    // 2. Buscar por References (puede contener varios message_id)
    if (!empty($msg['references'])) {
        $refs = preg_split('/\s+/', trim($msg['references']));
        foreach ($refs as $ref) {
            $ref = trim($ref, '<> ');
            if ($ref === '') {
                continue;
            }
            $stmt = $db->prepare("SELECT * FROM envios WHERE REPLACE(message_id, '<', '') = REPLACE(REPLACE(:mid, '<', ''), '>', '') LIMIT 1");
            $stmt->bindValue(':mid', $ref, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($row) {
                return $row;
            }
        }
    }

    // 3. Buscar por email remitente (último envío a ese email).
    //    Aceptamos cualquier estado de envío entregado (enviado, abierto,
    //    entregado, etc.) porque un envío que ya fue abierto deja de estar
    //    en estado 'enviado' y la respuesta debe poder atribuirse igualmente.
    if (!empty($msg['from_email'])) {
        $stmt = $db->prepare(
            "SELECT * FROM envios WHERE LOWER(email) = LOWER(:email) AND estado NOT IN ('fallido', 'error', 'rechazado', 'rebote', 'bounce') ORDER BY fecha_envio DESC LIMIT 1"
        );
        $stmt->bindValue(':email', $msg['from_email'], SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

/**
 * Asegura que la tabla `respuestas` tenga las columnas necesarias.
 * Migración idempotente: añade columnas si no existen (no borra nada).
 */
function imap_asegurar_esquema(SQLite3 $db): void
{
    $columnas = [
        'lead_id'            => 'INTEGER',
        'campaign_id'        => 'INTEGER',
        'id_cuenta_smtp'     => 'INTEGER',
        'message_id_original'=> 'TEXT',
        'contenido_html'     => 'TEXT',
        'uid_imap'           => 'TEXT',
        'cuenta_uid'         => 'TEXT',
        'hash_auxiliar'      => 'TEXT',
        'carpeta'            => 'TEXT',
        'notificado'         => 'INTEGER DEFAULT 0',
        'kanban_movido'      => 'INTEGER DEFAULT 0',
    ];
    $existentes = [];
    $res = $db->query('PRAGMA table_info(respuestas)');
    while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
        $existentes[$c['name']] = true;
    }
    foreach ($columnas as $col => $tipo) {
        if (!isset($existentes[$col])) {
            $db->exec("ALTER TABLE respuestas ADD COLUMN {$col} {$tipo}");
        }
    }
}

/**
 * Mueve un lead a la etapa '03 Respondió' si es una respuesta humana.
 * Respeta la protección de opt-out real (no reactiva bajas).
 * Devuelve true si movió el Kanban, false en caso contrario.
 */
/**
 * Determina si una clasificación corresponde a una respuesta humana que debe
 * mover el Kanban a "03 En Conversación" y generar notificación.
 *
 * Se considera respuesta humana tanto la heurística 'humana' como las
 * intenciones comerciales devueltas por la IA (interesado, duda_precio,
 * neutral, no_interesa). Las clasificaciones no humanas (rebote, baja,
 * fuera_de_oficina, automatica, desconocida, otro) NO mueven el Kanban.
 *
 * @param string $clasificacion Clasificación guardada en respuestas.clasificacion
 */
function imap_es_respuesta_humana(string $clasificacion): bool
{
    return in_array($clasificacion, [
        'humana',
        'interesado',
        'duda_precio',
        'neutral',
        'no_interesa',
    ], true);
}

function imap_mover_kanban(SQLite3 $db, ?array $envio, string $clasificacion): bool
{
    if (!imap_es_respuesta_humana($clasificacion)) {
        return false;
    }

    $leadId = $envio['lead_id'] ?? null;
    if ($leadId === null) {
        return false;
    }

    // Obtener estado actual del lead
    $estadoActual = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$leadId}");
    if ($estadoActual === false || $estadoActual === null) {
        return false;
    }

    // Protección opt-out real: no reactivar bajas
    $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
    if (in_array($estadoActual, $estadosSupresion, true)) {
        return false;
    }

    // Solo mover si aún no está en una etapa posterior a '03 En Conversación'
    // (pipeline canónico unificado: 01 Sin Contactar → 02 Contactado → 03 En Conversación → 04 Propuesta → 05 Ganado → 06 Perdido → 07 Baja)
    $orden = [
        '01 Sin Contactar'    => 1,
        '02 Contactado'       => 2,
        '03 En Conversación'  => 3,
        '04 Propuesta'        => 4,
        '05 Ganado'           => 5,
        '06 Perdido'          => 6,
        '07 Baja'             => 7,
    ];
    $ordenActual = $orden[$estadoActual] ?? 0;
    if ($ordenActual >= 3) {
        return false; // Ya está en 03 o posterior
    }

    // Mover a '03 En Conversación' (respuesta humana = lead en conversación)
    $db->exec("UPDATE clubes_crm SET estado_lead = '03 En Conversación', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");

    // Registrar cambio de estado en comunicaciones_log (trazabilidad)
    $stmtLog = $db->prepare(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
         VALUES (:lid, :cid, 'cambio_estado', :det, CURRENT_TIMESTAMP)"
    );
    $stmtLog->bindValue(':lid', $leadId, SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $leadId, SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', "Estado cambiado de '{$estadoActual}' a '03 En Conversación' (respuesta humana IMAP)", SQLITE3_TEXT);
    $stmtLog->execute();

    return true;
}

/**
 * Comprueba si ya existe una respuesta en la tabla `respuestas` que coincida
 * con el valor de una columna concreta (idempotencia). Devuelve true si existe.
 *
 * @param SQLite3 $db
 * @param string  $columna Columna a comprobar (message_id, cuenta_uid, hash_auxiliar)
 * @param string  $valor   Valor a buscar
 */
function imap_existe_respuesta(SQLite3 $db, string $columna, string $valor): bool
{
    if ($valor === '') {
        return false;
    }
    $stmt = $db->prepare("SELECT id FROM respuestas WHERE {$columna} = :v LIMIT 1");
    $stmt->bindValue(':v', $valor, SQLITE3_TEXT);
    return (bool)$stmt->execute()->fetchArray();
}

/**
 * Inserta una respuesta en la tabla `respuestas`.
 * Devuelve el id de la fila insertada, o null si falló el INSERT.
 *
 * @return int|null
 */
function imap_insertar_respuesta(SQLite3 $db, array $msg, ?array $envio, string $clasificacion, string $carpeta, ?string $uidImap = null, ?string $cuentaEmail = null, string $hashAux = ''): ?int
{
    $envioId = $envio['id'] ?? null;
    $leadId = $envio['lead_id'] ?? null;
    $campaignId = $envio['campaign_id'] ?? null;
    $smtpId = $envio['smtp_id'] ?? null;
    $messageIdOriginal = $msg['in_reply_to'] ?? ($msg['references'] ?? '');

    $stmt = $db->prepare(
        "INSERT INTO respuestas
         (envio_id, lead_id, campaign_id, id_cuenta_smtp, fecha_respuesta, remitente, destinatario,
          subject, cuerpo, contenido_html, message_id, message_id_original, in_reply_to, \"references\",
          uid_imap, cuenta_uid, hash_auxiliar, carpeta, clasificacion, fecha_clasificacion,
          estado_procesamiento, creado_el)
         VALUES
         (:envio_id, :lead_id, :campaign_id, :smtp_id, :fecha, :remitente, :destinatario,
          :subject, :cuerpo, :html, :message_id, :mid_orig, :in_reply_to, :references,
          :uid, :cuenta_uid, :hash, :carpeta, :clasificacion, CURRENT_TIMESTAMP,
          :estado, CURRENT_TIMESTAMP)"
    );

    $fecha = $msg['date'] ?? date('Y-m-d H:i:s');
    $stmt->bindValue(':envio_id', $envioId, $envioId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':lead_id', $leadId, $leadId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':campaign_id', $campaignId, $campaignId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':smtp_id', $smtpId, $smtpId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':fecha', $fecha, SQLITE3_TEXT);
    $stmt->bindValue(':remitente', $msg['from_email'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':destinatario', $msg['to_email'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':subject', $msg['subject'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo', $msg['cuerpo'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':html', $msg['cuerpo_html'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':message_id', $msg['message_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':mid_orig', $messageIdOriginal, SQLITE3_TEXT);
    $stmt->bindValue(':in_reply_to', $msg['in_reply_to'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':references', $msg['references'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':uid', $uidImap ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':cuenta_uid', ($cuentaEmail && $uidImap) ? $cuentaEmail . ':' . $uidImap : '', SQLITE3_TEXT);
    $stmt->bindValue(':hash', $hashAux, SQLITE3_TEXT);
    $stmt->bindValue(':carpeta', $carpeta, SQLITE3_TEXT);
    $stmt->bindValue(':clasificacion', $clasificacion, SQLITE3_TEXT);
    $stmt->bindValue(':estado', 'pendiente', SQLITE3_TEXT);

    try {
        $stmt->execute();
    } catch (\Throwable $e) {
        return null;
    }

    return (int)$db->lastInsertRowID();
}

/**
 * Registra en `comunicaciones_log` el evento de respuesta recibida.
 */
function imap_registrar_log_respuesta(SQLite3 $db, ?int $leadId, ?int $smtpId, int $respuestaId, string $clasificacion, string $carpeta): void
{
    $stmtLog = $db->prepare(
        "INSERT INTO comunicaciones_log
         (lead_id, club_id, tipo_evento, id_cuenta_smtp, tipo, resultado, detalles, fecha)
         VALUES (:lid, :cid, 'respuesta_recibida', :sid, 'email', 'exito', :det, CURRENT_TIMESTAMP)"
    );
    $stmtLog->bindValue(':lid', $leadId, $leadId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $leadId, $leadId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmtLog->bindValue(':sid', $smtpId, $smtpId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', "Respuesta recibida (respuesta_id={$respuestaId}, clasificacion={$clasificacion}, carpeta={$carpeta})", SQLITE3_TEXT);
    $stmtLog->execute();
}

/**
 * FASE G — Registra la notificación de nueva respuesta humana (🔔 NUEVA RESPUESTA)
 * y marca la respuesta como notificada. Aplica a cualquier respuesta humana,
 * incluida la intención comercial devuelta por la IA (interesado, duda_precio,
 * neutral, no_interesa) además de la heurística 'humana'.
 */
function imap_notificar_respuesta(SQLite3 $db, ?int $leadId, int $respuestaId, string $clasificacion): void
{
    if (!imap_es_respuesta_humana($clasificacion)) {
        return;
    }

    $stmtNotif = $db->prepare(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
         VALUES (:lid, :cid, 'notificacion_respuesta', :det, CURRENT_TIMESTAMP)"
    );
    $stmtNotif->bindValue(':lid', $leadId, $leadId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmtNotif->bindValue(':cid', $leadId, $leadId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmtNotif->bindValue(':det', "🔔 NUEVA RESPUESTA (respuesta_id={$respuestaId}, clasificacion={$clasificacion})", SQLITE3_TEXT);
    $stmtNotif->execute();
    $db->exec("UPDATE respuestas SET notificado = 1 WHERE id = {$respuestaId}");
}

/**
 * Registra una respuesta en la tabla `respuestas` con idempotencia.
 * Devuelve 'insertado', 'duplicado' o 'error'.
 */
function imap_registrar_respuesta(SQLite3 $db, array $msg, ?array $envio, string $clasificacion, string $carpeta, ?string $uidImap = null, ?string $cuentaEmail = null): string
{
    // Asegurar esquema (idempotente)
    imap_asegurar_esquema($db);

    // ─── Idempotencia completa ───
    // 1. Message-ID
    if (!empty($msg['message_id']) && imap_existe_respuesta($db, 'message_id', $msg['message_id'])) {
        return 'duplicado';
    }
    // 2. cuenta + UID (idempotencia correcta por cuenta y UID IMAP)
    // NOTA: El UID IMAP solo es único dentro de cada buzón/cuenta. Consultarlo
    // sin filtrar por cuenta (como se hacía antes) provocaba FALSOS duplicados:
    // mensajes de cuentas distintas con el mismo UID (1, 2, 3...) colisionaban
    // con filas de otras cuentas y se descartaban sin registrarse. Por eso se
    // combina SIEMPRE con la cuenta (cuenta_uid = cuentaEmail:uidImap).
    if (!empty($uidImap) && !empty($cuentaEmail) && imap_existe_respuesta($db, 'cuenta_uid', $cuentaEmail . ':' . $uidImap)) {
        return 'duplicado';
    }

    // 4. hash auxiliar (message_id + from + subject)
    $hashAux = '';
    if (!empty($msg['message_id'])) {
        $hashAux = hash('sha256', $msg['message_id'] . '|' . ($msg['from_email'] ?? '') . '|' . ($msg['subject'] ?? ''));
        if (imap_existe_respuesta($db, 'hash_auxiliar', $hashAux)) {
            return 'duplicado';
        }
    }

    $leadId = $envio['lead_id'] ?? null;
    $smtpId = $envio['smtp_id'] ?? null;

    // INSERT en respuestas
    $respuestaId = imap_insertar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uidImap, $cuentaEmail, $hashAux);
    if ($respuestaId === null) {
        return 'error';
    }

    // Registrar en comunicaciones_log
    imap_registrar_log_respuesta($db, $leadId, $smtpId, $respuestaId, $clasificacion, $carpeta);

    // ─── FASE G: Notificación ───
    imap_notificar_respuesta($db, $leadId, $respuestaId, $clasificacion);

    // ─── Kanban: respuesta humana → 03 Respondió ───
    $movido = imap_mover_kanban($db, $envio, $clasificacion);
    if ($movido) {
        $db->exec("UPDATE respuestas SET kanban_movido = 1 WHERE id = {$respuestaId}");
    }

    return 'insertado';
}

/**
 * Procesa un único mensaje de un buzón (extraído de imap_procesar_buzon).
 *
 * Encapsula el flujo completo de un mensaje: FETCH ENVELOPE, intento de cuerpo
 * con degradado elegante, fallback de metadatos, clasificación, atribución y
 * registro. Devuelve los contadores incrementados para que el orquestador los
 * acumule en las estadísticas globales.
 *
 * NOTA: $imap se pasa por referencia porque, ante un timeout en BODY.PEEK[TEXT],
 * el socket puede quedar corrupto y se reconecta (se asigna un nuevo ClienteIMAP).
 *
 * @return array{insertados:int,duplicados:int,errores:int,sin_atribucion:int}
 */
function imap_procesar_mensaje(SQLite3 $db, array $cuenta, string $carpeta, string $seq, ClienteIMAP &$imap): array
{
    $inc = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'sin_atribucion' => 0];

    try {
        // ─── FASE 1: Fuente PRIMARIA = FETCH <seq> (UID ENVELOPE FLAGS) ───
        // Un único comando que devuelve UID + ENVELOPE + FLAGS. Es ligero
        // y el servidor IMAP de SiteGround lo responde sin colgarse (a
        // diferencia de BODY.PEEK[HEADER]/BODY.PEEK[TEXT] en mensajes
        // problemáticos). Aporta Message-ID, In-Reply-To, From, Subject,
        // Date y UID para atribución e idempotencia.
        $respEnvelope = $imap->fetchEnvelopeCompleto($seq);
        $uid = $imap->extraerUID($respEnvelope);
        $msg = imap_parsear_envelope($respEnvelope);

        // ─── FASE 2: Intento de cuerpo con degradado elegante ───
        // Se intenta leer BODY.PEEK[TEXT] para guardar el cuerpo en
        // respuestas.cuerpo. Si SiteGround no responde dentro del timeout
        // estricto (5s) o lanza error, se captura la excepción y se
        // mantienen los datos de ENVELOPE (no se pierde la respuesta).
        try {
            $cuerpoRaw = $imap->fetchCuerpo($seq);
            if (trim($cuerpoRaw) !== '') {
                $msg['cuerpo'] = trim($cuerpoRaw);
            }
        } catch (\Throwable $e) {
            // Timeout/error en BODY.PEEK[TEXT]: el socket puede quedar
            // corrupto. Reconectar y reseleccionar la carpeta para
            // continuar con el siguiente mensaje de forma limpia.
            try { $imap->cerrar(); } catch (\Throwable $ign) {}
            $imap = new ClienteIMAP($GLOBALS['IMAP_HOST'], $GLOBALS['IMAP_PORT']);
            $imap->conectar($cuenta['usuario'], $cuenta['password']);
            $imap->seleccionar($carpeta);
        }

        // ─── Fallback de metadatos: si ENVELOPE no aportó nada ───
        // (p. ej. mensaje sin ENVELOPE parseable), se intenta
        // BODY.PEEK[HEADER.FIELDS ...] como refuerzo.
        if (empty($msg['message_id']) && empty($msg['from_email'])) {
            try {
                $rawHeader = $imap->fetchHeaderFields($seq);
                if (trim($rawHeader) !== '') {
                    $msg = array_merge($msg, imap_parsear_header_fields($rawHeader));
                }
            } catch (\Throwable $e) {
                // ignorar: seguimos con lo que haya en ENVELOPE
            }
        }

        $clasificacion = imap_clasificar($msg, $db);
        $envio = imap_atribuir($db, $msg);

        if ($envio === null) {
            $inc['sin_atribucion']++;
        }

        $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uid, $cuenta['email']);

        if ($resultado === 'insertado') {
            $inc['insertados']++;
        } elseif ($resultado === 'duplicado') {
            $inc['duplicados']++;
        } else {
            $inc['errores']++;
        }
    } catch (\Throwable $e) {
        $inc['errores']++;
    }

    return $inc;
}

/**
 * Procesa un buzón completo de una cuenta.
 * Devuelve estadísticas.
 */
function imap_procesar_buzon(SQLite3 $db, array $cuenta, ClienteIMAP $imap): array
{
    $stats = ['carpetas' => 0, 'mensajes' => 0, 'insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'sin_atribucion' => 0];

    foreach ($GLOBALS['CARPETAS_AUDITAR'] as $carpeta) {
        try {
            $total = $imap->seleccionar($carpeta);
            $stats['carpetas']++;
            if ($total === 0) {
                continue;
            }

            $seqs = $imap->buscarTodos();
            foreach ($seqs as $seq) {
                $stats['mensajes']++;
                $inc = imap_procesar_mensaje($db, $cuenta, $carpeta, $seq, $imap);
                $stats['insertados'] += $inc['insertados'];
                $stats['duplicados'] += $inc['duplicados'];
                $stats['errores'] += $inc['errores'];
                $stats['sin_atribucion'] += $inc['sin_atribucion'];
            }



        } catch (\Throwable $e) {
            // Carpeta no accesible, continuar
        }
    }

    return $stats;
}

/**
 * FASE 1 — Detiene la secuencia de follow-ups de un lead que ha respondido.
 *
 * Actualiza la tabla `secuencia_lead` estableciendo estado='DETENIDO' y
 * motivo='RESPUESTA_IMAP' para todos los registros activos del lead.
 * Es idempotente: si ya está DETENIDO, no hace nada y devuelve false.
 *
 * Devuelve true si detuvo al menos una secuencia, false en caso contrario.
 */
function imap_detener_secuencia(SQLite3 $db, int $leadId): bool
{
    // Comprobar si la tabla existe
    $tabla = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='secuencia_lead'");
    if (!$tabla) {
        return false;
    }

    // Comprobar columnas disponibles
    $cols = [];
    $res = $db->query('PRAGMA table_info(secuencia_lead)');
    while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
        $cols[$c['name']] = true;
    }
    if (!isset($cols['estado']) || !isset($cols['lead_id'])) {
        return false;
    }

    // Detener secuencias activas del lead (idempotente: solo las que no estén DETENIDO)
    $stmt = $db->prepare(
        "UPDATE secuencia_lead
         SET estado = 'DETENIDO', motivo = 'RESPUESTA_IMAP'
         WHERE lead_id = :lid AND (estado IS NULL OR estado NOT IN ('DETENIDO', 'COMPLETADA', 'CANCELADA'))"
    );
    $stmt->bindValue(':lid', $leadId, SQLITE3_INTEGER);
    $stmt->execute();

    $cambios = $db->changes();
    return $cambios > 0;
}

/**
 * Orquestador: recorre todas las cuentas SMTP activas y procesa sus buzones.
 */
function imap_procesar_todas_cuentas(SQLite3 $db): array

{
    $resultado = ['cuentas' => 0, 'total_insertados' => 0, 'total_duplicados' => 0, 'total_errores' => 0, 'detalle' => []];

    $cuentas = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
    while ($cuenta = $cuentas->fetchArray(SQLITE3_ASSOC)) {
        $resultado['cuentas']++;
        $imap = new ClienteIMAP($GLOBALS['IMAP_HOST'], $GLOBALS['IMAP_PORT']);
        try {
            $imap->conectar($cuenta['usuario'], $cuenta['password']);
            $stats = imap_procesar_buzon($db, $cuenta, $imap);
            $resultado['total_insertados'] += $stats['insertados'];
            $resultado['total_duplicados'] += $stats['duplicados'];
            $resultado['total_errores'] += $stats['errores'];
            $resultado['detalle'][$cuenta['email']] = $stats;
        } catch (\Throwable $e) {
            $resultado['total_errores']++;
            $resultado['detalle'][$cuenta['email']] = ['error' => $e->getMessage()];
        } finally {
            $imap->cerrar();
        }
    }

    return $resultado;
}

/**
 * Procesa un único mensaje de un buzón en MODO LIGERO (solo remitentes).
 *
 * A diferencia de imap_procesar_mensaje(), NO descarga el cuerpo del email
 * (BODY.PEEK[TEXT]). Solo lee el ENVELOPE (UID, From, Subject, Message-ID,
 * Date) que es suficiente para:
 *   - Atribuir la respuesta a un lead/envío/campaña.
 *   - Clasificar la respuesta (humana vs automática).
 *   - Registrar la respuesta en la tabla `respuestas` (sin cuerpo).
 *   - Mover el lead al Kanban "03 En Conversación" si es una respuesta humana.
 *
 * Es la versión usada por el dashboard al cargar, para que el Kanban muestre
 * de forma limpia qué remitentes han respondido sin necesidad de descargar
 * el contenido de los emails (más rápido y ligero).
 *
 * @return array{insertados:int,duplicados:int,errores:int,sin_atribucion:int}
 */
function imap_procesar_mensaje_ligero(SQLite3 $db, array $cuenta, string $carpeta, string $seq, ClienteIMAP &$imap): array
{
    $inc = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'sin_atribucion' => 0];

    try {
        // ─── Fuente PRIMARIA = FETCH <seq> (UID ENVELOPE FLAGS) ───
        // Un único comando ligero que devuelve UID + ENVELOPE + FLAGS.
        // NO se descarga el cuerpo (BODY.PEEK[TEXT]) para no penalizar la
        // carga del dashboard. Aporta Message-ID, In-Reply-To, From, Subject,
        // Date y UID para atribución e idempotencia.
        $respEnvelope = $imap->fetchEnvelopeCompleto($seq);
        $uid = $imap->extraerUID($respEnvelope);
        $msg = imap_parsear_envelope($respEnvelope);

        // Fallback de metadatos si ENVELOPE no aportó nada
        if (empty($msg['message_id']) && empty($msg['from_email'])) {
            try {
                $rawHeader = $imap->fetchHeaderFields($seq);
                if (trim($rawHeader) !== '') {
                    $msg = array_merge($msg, imap_parsear_header_fields($rawHeader));
                }
            } catch (\Throwable $e) {
                // ignorar: seguimos con lo que haya en ENVELOPE
            }
        }

        $clasificacion = imap_clasificar($msg, $db);
        $envio = imap_atribuir($db, $msg);

        if ($envio === null) {
            $inc['sin_atribucion']++;
        }

        $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uid, $cuenta['email']);

        if ($resultado === 'insertado') {
            $inc['insertados']++;
        } elseif ($resultado === 'duplicado') {
            $inc['duplicados']++;
        } else {
            $inc['errores']++;
        }
    } catch (\Throwable $e) {
        $inc['errores']++;
    }

    return $inc;
}

/**
 * Procesa un buzón completo de una cuenta en MODO LIGERO (solo remitentes).
 * Devuelve estadísticas.
 */
function imap_procesar_buzon_ligero(SQLite3 $db, array $cuenta, ClienteIMAP $imap): array
{
    $stats = ['carpetas' => 0, 'mensajes' => 0, 'insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'sin_atribucion' => 0];

    // Límite de mensajes por buzón en modo ligero. Como SEARCH ALL devuelve
    // los números de secuencia en orden ascendente (los más recientes al
    // final), tomamos los últimos N para no penalizar la carga del dashboard
    // en producción cuando hay muchos mensajes en los buzones. Los antiguos
    // ya están registrados (idempotencia) y las respuestas nuevas son las
    // que importan para el Kanban.
    $LIMITE_LIGERO = 100;

    foreach ($GLOBALS['CARPETAS_AUDITAR'] as $carpeta) {
        try {
            $total = $imap->seleccionar($carpeta);
            $stats['carpetas']++;
            if ($total === 0) {
                continue;
            }

            $seqs = $imap->buscarTodos();
            // Solo los últimos $LIMITE_LIGERO mensajes (los más recientes)
            if (count($seqs) > $LIMITE_LIGERO) {
                $seqs = array_slice($seqs, -$LIMITE_LIGERO);
            }
            foreach ($seqs as $seq) {
                $stats['mensajes']++;
                $inc = imap_procesar_mensaje_ligero($db, $cuenta, $carpeta, $seq, $imap);
                $stats['insertados'] += $inc['insertados'];
                $stats['duplicados'] += $inc['duplicados'];
                $stats['errores'] += $inc['errores'];
                $stats['sin_atribucion'] += $inc['sin_atribucion'];
            }
        } catch (\Throwable $e) {
            // Carpeta no accesible, continuar
        }
    }

    return $stats;
}

/**
 * Orquestador LIGERO: recorre todas las cuentas SMTP activas y procesa sus
 * buzones SOLO leyendo remitentes (sin descargar cuerpos). Usado por el
 * dashboard al cargar para que el Kanban muestre de forma limpia qué
 * remitentes han respondido.
 */
function imap_procesar_todas_cuentas_ligero(SQLite3 $db): array
{
    $resultado = ['cuentas' => 0, 'total_insertados' => 0, 'total_duplicados' => 0, 'total_errores' => 0, 'detalle' => []];

    $cuentas = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
    while ($cuenta = $cuentas->fetchArray(SQLITE3_ASSOC)) {
        $resultado['cuentas']++;
        $imap = new ClienteIMAP($GLOBALS['IMAP_HOST'], $GLOBALS['IMAP_PORT']);
        try {
            $imap->conectar($cuenta['usuario'], $cuenta['password']);
            $stats = imap_procesar_buzon_ligero($db, $cuenta, $imap);
            $resultado['total_insertados'] += $stats['insertados'];
            $resultado['total_duplicados'] += $stats['duplicados'];
            $resultado['total_errores'] += $stats['errores'];
            $resultado['detalle'][$cuenta['email']] = $stats;
        } catch (\Throwable $e) {
            $resultado['total_errores']++;
            $resultado['detalle'][$cuenta['email']] = ['error' => $e->getMessage()];
        } finally {
            $imap->cerrar();
        }
    }

    return $resultado;
}


