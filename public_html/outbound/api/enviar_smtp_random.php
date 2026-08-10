<?php
declare(strict_types=1);

// ⚠️ BLOQUEO DE SEGURIDAD — Quitar esta línea para habilitar envíos standalone.
//    Este script envía sin pasar por la lanzadera. Usar solo bajo supervisión.
// die("SISTEMA BLOQUEADO POR EL ADMINISTRADOR: ENVIOS DETENIDOS.");

/**
 * enviar_smtp_random.php
 * Envía correos HTML con píxel de tracking usando cuentas SMTP rotativas.
 * Lee contactos desde clubes.json y registra cada envío en SQLite.
 *
 * Uso:
 *   php enviar_smtp_random.php                    # modo interactivo (lote=10, delay=3s)
 *   php enviar_smtp_random.php --lote=50 --delay=5
 *   php enviar_smtp_random.php --resume            # saltar ya enviados
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

// ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────
$DB_PATH     = __DIR__ . '/../data/stats.db';
$CLUBES_JSON = __DIR__ . '/../clubes.json';
$TRACK_URL   = 'https://getfutprotec.com/outbound/track.php';

/**
 * Obtiene una cuenta SMTP aleatoria de la BD que este activa y bajo su limite diario.
 * Retorna array asociativo o null si no hay cuentas disponibles.
 */
function obtenerCuentaSMTP(SQLite3 $db): ?array
{
    $res = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 AND enviados_hoy < limite_diario ORDER BY RANDOM() LIMIT 1");
    $cuenta = $res->fetchArray(SQLITE3_ASSOC);
    return $cuenta ?: null;
}

/**
 * Incrementa el contador de enviados_hoy para una cuenta.
 */
function incrementarEnvioSMTP(SQLite3 $db, int $cuentaId): void
{
    $db->exec("UPDATE cuentas_smtp SET enviados_hoy = enviados_hoy + 1, ultimo_uso = CURRENT_TIMESTAMP WHERE id = {$cuentaId}");
}

/**
 * Registra un error en la cuenta SMTP.
 */
function registrarErrorSMTP(SQLite3 $db, int $cuentaId, string $error): void
{
    $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString($error) . "' WHERE id = {$cuentaId}");
}

// Fallback: array hardcodeado si no hay cuentas en BD (solo se usa si obtenerCuentaSMTP retorna null)
$CUENTAS_SMTP_FALLBACK = [
    [
        'email'  => 'rodrigo@getfutprotec.com',
        'user'   => 'rodrigo@getfutprotec.com',
        'pass'   => '%75Q2%#_g*12',
        'nombre' => 'Rodrigo Vázquez | FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'mario.ortiz@getfutprotec.com',
        'user'   => 'mario.ortiz@getfutprotec.com',
        'pass'   => 'ci21w_S%34#f',
        'nombre' => 'Mario Ortiz | Área de Clubes FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'alvaro.ruiz@getfutprotec.com',
        'user'   => 'alvaro.ruiz@getfutprotec.com',
        'pass'   => '~i1c%)1)i@35',
        'nombre' => 'Álvaro Ruiz | Equipamiento FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'carlos.mora@getfutprotec.com',
        'user'   => 'carlos.mora@getfutprotec.com',
        'pass'   => '_%}jP|nb~b1f',
        'nombre' => 'Carlos Mora | Proyectos Cantera FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'javier.sanz@getfutprotec.com',
        'user'   => 'javier.sanz@getfutprotec.com',
        'pass'   => '11k1%425e;%4',
        'nombre' => 'Javier Sanz | At. Clubes FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'diego.navarro@getfutprotec.com',
        'user'   => 'diego.navarro@getfutprotec.com',
        'pass'   => '1;2Aj]#1`11i',
        'nombre' => 'Diego Navarro | Equipaciones FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'pablo.blanco@getfutprotec.com',
        'user'   => 'pablo.blanco@getfutprotec.com',
        'pass'   => '(5^j@c[3k%3d',
        'nombre' => 'Pablo Blanco | FutProtec Oficial',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'gonzalo.vega@getfutprotec.com',
        'user'   => 'gonzalo.vega@getfutprotec.com',
        'pass'   => ';^361y)bO1*5',
        'nombre' => 'Gonzalo Vega | Gestión Deportivo FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'adrian.cano@getfutprotec.com',
        'user'   => 'adrian.cano@getfutprotec.com',
        'pass'   => 'k@1$%%kl2lKb',
        'nombre' => 'Adrián Cano | FutProtec Canteras',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
    [
        'email'  => 'sergio.gil@getfutprotec.com',
        'user'   => 'sergio.gil@getfutprotec.com',
        'pass'   => '(5^j@c[3k%3d',
        'nombre' => 'Sergio Gil | Relaciones Clubes FutProtec',
        'smtp'   => 'mail.getfutprotec.com',
        'puerto' => 465,
    ],
];

// Contenido del email — se carga desde la BD (plantillas) al inicializar BD
// $ASUNTO y $CUERPO_HTML_TEMPLATE se definen mas abajo, tras abrir la BD

// ─── PARSEAR ARGUMENTOS ───────────────────────────────────────────────────────
$LOTE  = 10;
$DELAY = 3; // segundos entre envíos (≥3 recomendado para evitar bloqueo)
$RESUME = false;
$TEST   = false; // Modo prueba: envía todo a contactofutprotec@gmail.com

$args = getopt('', ['lote::', 'delay::', 'resume', 'test']);
$LOTE   = isset($args['lote'])  ? max(1, (int)$args['lote'])   : $LOTE;
$DELAY  = isset($args['delay']) ? max(1, (int)$args['delay'])   : $DELAY;
$RESUME = isset($args['resume']);
$TEST   = isset($args['test']);

$modo = $TEST ? '🧪 TEST (todo a contactofutprotec@gmail.com)' : ($RESUME ? 'RESUME (saltar enviados)' : 'COMPLETO');

echo "══════════════════════════════════════════════\n";
echo "  FutProtec — Envío SMTP Campaña Outbound\n";
echo "  Modo: {$modo}\n";
echo "  Lote: {$LOTE} | Delay: {$DELAY}s\n";
echo "══════════════════════════════════════════════\n\n";

if ($TEST) {
    echo "🧪 MODO PRUEBA ACTIVADO — Todos los envíos irán a contactofutprotec@gmail.com\n\n";
}

// ─── INICIALIZAR DB ───────────────────────────────────────────────────────────
if (!file_exists($DB_PATH)) {
    die("❌ stats.db no encontrado. Ejecuta primero: php init_db.php\n");
}

$db = new SQLite3($DB_PATH);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

// ─── CARGAR PLANTILLA DESDE BD ───────────────────────────────────────────────
$tpl = $db->querySingle("SELECT asunto, cuerpo FROM plantillas WHERE activo=1 LIMIT 1", true);
if ($tpl) {
    $ASUNTO = $tpl['asunto'];
    $CUERPO_HTML_TEMPLATE = $tpl['cuerpo'];
    echo "📧 Plantilla cargada desde BD: " . substr($ASUNTO, 0, 50) . "...\n";
} else {
    // Fallback ultra-basico si no hay plantilla en BD
    $ASUNTO = 'Espinilleras personalizadas para {{CLUB}} | FutProtec';
    $CUERPO_HTML_TEMPLATE = '<p>Estimado/a {{CLUB}}, solicita info en getfutprotec.com</p>';
    echo "⚠️ Sin plantilla en BD — usando fallback.\n";
}

// ─── CARGAR CLUBES ───────────────────────────────────────────────────────────
if (!file_exists($CLUBES_JSON)) {
    $db->close();
    die("❌ clubes.json no encontrado en: {$CLUBES_JSON}\n");
}

$clubes = json_decode(file_get_contents($CLUBES_JSON), true);
if (!is_array($clubes) || empty($clubes)) {
    $db->close();
    die("❌ clubes.json vacío o inválido.\n");
}

echo "📋 Contactos cargados: " . count($clubes) . "\n";

// Filtrar ya enviados si --resume
if ($RESUME) {
    $enviados = [];
    $res = $db->query("SELECT email FROM envios");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $enviados[$row['email']] = true;
    }
    $antes = count($clubes);
    $clubes = array_filter($clubes, fn($c) => !isset($enviados[ strtolower(trim($c['email'])) ]));
    $clubes = array_values($clubes);
    echo "📋 Pendientes después de filtrar enviados: " . count($clubes) . " (saltados: " . ($antes - count($clubes)) . ")\n";
}

$total = count($clubes);
$lote = min($LOTE, $total);
echo "📤 Enviando lote de {$lote} correos...\n\n";

// ─── FUNCIÓN DE ENVÍO SMTP (nativa, sin dependencias) ────────────────────────
function enviarSMTP(array $cuenta, string $destinatario, string $asunto, string $cuerpoHTML): array
{
    $fromEmail = $cuenta['email'];
    $fromName  = $cuenta['nombre'];
    $smtpHost  = $cuenta['smtp'];
    $smtpPort  = $cuenta['puerto'];
    $smtpUser  = $cuenta['user'];
    $smtpPass  = $cuenta['pass'];

    // Construir cabeceras
    $boundary = md5(uniqid((string)random_int(0, 99999), true));
    $headers = [
        'From'         => "{$fromName} <{$fromEmail}>",
        'Reply-To'     => $fromEmail,
        'X-Mailer'     => 'FutProtec-Outbound/1.0',
        'MIME-Version' => '1.0',
        'Content-Type' => "text/html; charset=UTF-8",
    ];

    $headerString = '';
    foreach ($headers as $k => $v) {
        $headerString .= "{$k}: {$v}\r\n";
    }

    try {
        $success = mail(
            $destinatario,
            "=?UTF-8?B?" . base64_encode($asunto) . "?=",
            $cuerpoHTML,
            $headerString,
            "-f {$fromEmail}"
        );

        return ['ok' => $success, 'error' => $success ? '' : 'mail() returned false'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Envía correo vía SMTP autenticado usando sockets nativos PHP.
 * Fallback si mail() no está disponible o no funciona.
 */
function enviarSMTPAutenticado(array $cuenta, string $destinatario, string $asunto, string $cuerpoHTML): array
{
    $fromEmail = $cuenta['email'];
    $fromName  = $cuenta['nombre'];
    $smtpHost  = $cuenta['smtp'];
    $smtpPort  = (int)$cuenta['puerto'];
    $smtpUser  = $cuenta['user'];
    $smtpPass  = $cuenta['pass'];

    $timeout = 30;

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

        // Helper RFC 5321 estricto: 3 dígitos + espacio = fin, 3 dígitos + guion = multilínea
        $read = function() use ($fp): string {
            $resp = '';
            while ($line = fgets($fp, 512)) {
                if ($line === false || $line === '') break;
                $resp .= $line;
                // /^\d{3}\s/ → fin de respuesta SMTP
                if (preg_match('/^\d{3}\s/', $line)) break;
                // Si no es multilínea SMTP real (código-guión o código-espacio), salir
                if (!preg_match('/^\d{3}[- ]/', $line)) break;
                // Es /^\d{3}-/ → multilínea real, continuar leyendo
            }
            return $resp;
        };

        // Helper para enviar comando
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

        // Construir mensaje
        $boundary = md5(uniqid((string)random_int(0, 99999), true));
        $mensaje = "From: {$fromName} <{$fromEmail}>\r\n";
        $mensaje .= "To: <{$destinatario}>\r\n";
        $mensaje .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";
        $mensaje .= "MIME-Version: 1.0\r\n";
        $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mensaje .= "X-Mailer: FutProtec-Outbound/1.0\r\n";
        $mensaje .= "\r\n";
        $mensaje .= $cuerpoHTML;
        $mensaje .= "\r\n.\r\n";

        fwrite($fp, $mensaje);
        $read();

        // QUIT
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

// ─── BUCLE DE ENVÍO ──────────────────────────────────────────────────────────
$enviadasOk    = 0;
$enviadasError = 0;
$startTime     = microtime(true);

for ($i = 0; $i < $lote; $i++) {
    $club = $clubes[$i];
    $nombreClub = $club['club'];
    $emailClub  = trim($club['email']);
    $federacion = $club['federacion'] ?? '';

    // Validar email
    if (!filter_var($emailClub, FILTER_VALIDATE_EMAIL)) {
        echo "[SKIP] Email inválido: {$emailClub} ({$nombreClub})\n";
        $enviadasError++;
        continue;
    }

    // Seleccionar cuenta SMTP desde BD (rotacion dinamica con limite diario)
    $cuenta = obtenerCuentaSMTP($db);
    if (!$cuenta) {
        echo "[SKIP] No hay cuentas SMTP activas disponibles (todas alcanzaron su limite diario o estan desactivadas).\n";
        $enviadasError++;
        continue;
    }

    // Generar tracking_id único: fut_<timestamp>_<random>
    $trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));

    // Preparar contenido del email usando placeholders del editor ({{CLUB}}, etc.)
    $asunto = str_replace(
        ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
        [$nombreClub, 'responsable', $federacion, date('Y')],
        $ASUNTO
    );
    $cuerpo = str_replace(
        ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
        [$nombreClub, 'responsable', $federacion, date('Y')],
        $CUERPO_HTML_TEMPLATE
    );
    // Anadir pixel de tracking al final del cuerpo
    $pixel = '<img src="' . $TRACK_URL . '?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';
    $cuerpo = str_replace('</body>', $pixel . '</body>', $cuerpo);

    // Modo test: sobreescribir destinatario
    $emailDestino = $TEST ? 'contactofutprotec@gmail.com' : $emailClub;

    // Construir array para SMTP (compatible con funciones de envio existentes)
    $cuentaSMTP = [
        'email'  => $cuenta['email'],
        'user'   => $cuenta['usuario'],
        'pass'   => $cuenta['password'],
        'nombre' => $cuenta['email'],
        'smtp'   => $cuenta['host'],
        'puerto' => (int)$cuenta['puerto'],
    ];

    // Enviar
    $resultado = enviarSMTPAutenticado($cuentaSMTP, $emailDestino, $asunto, $cuerpo);

    // Determinar estado
    $estado = $resultado['ok'] ? 'enviado' : 'error';

    // Tracking: incrementar enviados_hoy o registrar error
    if ($resultado['ok']) {
        incrementarEnvioSMTP($db, (int)$cuenta['id']);
    } else {
        registrarErrorSMTP($db, (int)$cuenta['id'], $resultado['error'] ?? 'Error desconocido');
    }

    // Insertar registro en BD (guardar asunto y cuerpo_mensaje completos)
    $stmt = $db->prepare(
        'INSERT INTO envios (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje)
         VALUES (:club, :email, :fed, :cuenta, :estado, :tid, :asunto, :cuerpo)'
    );
    $stmt->bindValue(':club',   $nombreClub,  SQLITE3_TEXT);
    $stmt->bindValue(':email',  $emailClub,   SQLITE3_TEXT);
    $stmt->bindValue(':fed',    $federacion,  SQLITE3_TEXT);
    $stmt->bindValue(':cuenta', $cuenta['email'], SQLITE3_TEXT);
    $stmt->bindValue(':estado', $estado,      SQLITE3_TEXT);
    $stmt->bindValue(':tid',    $trackingId,  SQLITE3_TEXT);
    $stmt->bindValue(':asunto', $asunto,      SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo', $cuerpo,      SQLITE3_TEXT);

    try {
        $stmt->execute();
    } catch (\Exception $e) {
        // Si tracking_id duplicado, regenerar e intentar de nuevo
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            $trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(8));
            $stmt->reset();
            $stmt->bindValue(':tid', $trackingId, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    // Mostrar progreso
    $icono = $resultado['ok'] ? '✅' : '❌';
    $progreso = "[" . ($i + 1) . "/{$lote}]";
    $destMostrar = $TEST ? "{$emailClub} → {$emailDestino}" : $emailClub;
    echo "{$icono} {$progreso} {$nombreClub} → {$destMostrar} | cuenta: {$cuenta['email']}";

    if (!$resultado['ok']) {
        echo " | ERROR: {$resultado['error']}";
        $enviadasError++;
    } else {
        $enviadasOk++;
    }
    echo "\n";

    // Delay entre envíos (anti-bloqueo)
    if ($i < $lote - 1) {
        sleep($DELAY);
    }
}

// ─── RESUMEN ─────────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 1);
echo "\n══════════════════════════════════════════════\n";
echo "  RESUMEN — Lote completado\n";
echo "  ✅ Enviados:  {$enviadasOk}\n";
echo "  ❌ Errores:   {$enviadasError}\n";
echo "  ⏱  Tiempo:    {$elapsed}s\n";
echo "══════════════════════════════════════════════\n";

// Mostrar totales acumulados
$totalEnviados = $db->querySingle("SELECT COUNT(*) FROM envios WHERE estado = 'enviado'");
$totalErrores  = $db->querySingle("SELECT COUNT(*) FROM envios WHERE estado = 'error'");
$totalPendientes = $db->querySingle("SELECT COUNT(*) FROM envios WHERE estado = 'pendiente'");
echo "  📊 Acumulado BD → Enviados: {$totalEnviados} | Errores: {$totalErrores} | Pendientes: {$totalPendientes}\n";
echo "══════════════════════════════════════════════\n";

$db->close();