<?php
declare(strict_types=1);

// Transporte SMTP centralizado (unifica las implementaciones previas).
require_once __DIR__ . '/../inc/smtp_transport.php';

// ⚠️ BLOQUEO DE SEGURIDAD — FASE 2B (aprobado): P2 queda DESACTIVADO.
//    Este script lee clubes.json (fuente desincronizada) y bypassea supresión,
//    campaña, trazabilidad e idempotencia. Desactivado de forma reversible.
die("SISTEMA BLOQUEADO POR EL ADMINISTRADOR: ENVIOS DETENIDOS.");

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
$LOG_DIR     = __DIR__ . '/../logs';
$TRACK_URL   = 'https://getfutprotec.com/outbound/api/track.php';

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

// ─── SAFE MODE: leer modo_entorno desde BD (fuente de verdad server-side) ──
$DB_SAFE = new SQLite3(__DIR__ . '/../data/stats.db');
$DB_SAFE->enableExceptions(true);
$modoEntornoBD = ($DB_SAFE->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$DB_SAFE->close();

// ─── PARSEAR ARGUMENTOS ───────────────────────────────────────────────────────
$LOTE   = 10;
$DELAY  = 3; // segundos entre envíos (≥3 recomendado para evitar bloqueo)
$RESUME = false;

$args = getopt('', ['lote::', 'delay::', 'resume', 'test']);
$LOTE   = isset($args['lote'])  ? max(1, (int)$args['lote'])   : $LOTE;
$DELAY  = isset($args['delay']) ? max(1, (int)$args['delay'])   : $DELAY;
$RESUME = isset($args['resume']);
$TEST   = ($modoEntornoBD === 'test') || isset($args['test']);

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
$tpl = $db->querySingle("SELECT asunto, asunto_b, asunto_c, test_ab, cuerpo FROM plantillas WHERE activo=1 LIMIT 1", true);
if ($tpl) {
    $ASUNTO              = $tpl['asunto'];
    $ASUNTO_B            = $tpl['asunto_b'] ?? '';
    $ASUNTO_C            = $tpl['asunto_c'] ?? '';
    $TEST_AB             = (int)($tpl['test_ab'] ?? 0);
    $CUERPO_HTML_TEMPLATE = $tpl['cuerpo'];
    $tieneC              = !empty($ASUNTO_C);
    echo "📧 Plantilla cargada desde BD: " . substr($ASUNTO, 0, 50) . "...\n";
    if ($TEST_AB && $tieneC) {
        echo "🧪 Test A/B/C activo en esta plantilla\n";
    } elseif ($TEST_AB && !empty($ASUNTO_B)) {
        echo "🧪 Test A/B activo en esta plantilla\n";
    }
} else {
    // Fallback ultra-basico si no hay plantilla en BD
    $ASUNTO = 'Espinilleras personalizadas para {{CLUB}} | FutProtec';
    $ASUNTO_B = '';
    $ASUNTO_C = '';
    $TEST_AB  = 0;
    $tieneC   = false;
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
    // Normalizar la cuenta para el transporte centralizado.
    $cuentaNormalizada = [
        'email'         => $cuenta['email'],
        'host'          => $cuenta['smtp'],
        'puerto'        => (int)$cuenta['puerto'],
        'usuario'       => $cuenta['user'],
        'password'      => $cuenta['pass'],
        'seguridad'     => ((int)$cuenta['puerto'] === 465) ? 'ssl' : 'tls',
        'nombre_emisor' => $cuenta['nombre'] ?? '',
    ];

    $opciones = [
        'reply_to' => $cuenta['email'],
    ];

    // Delegar en el transporte SMTP centralizado.
    return futprotec_enviarSMTP($cuentaNormalizada, $destinatario, $asunto, $cuerpoHTML, $opciones);
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

    // Seleccionar variante A/B/C del asunto
    $asuntoBase = $ASUNTO;
    $varianteAb = 'A';
    if ($TEST_AB === 1) {
        if ($tieneC) {
            // Modo A/B/C: 33% cada variante
            $r = mt_rand(1, 100);
            if ($r <= 33) {
                $varianteAb = 'A';
                $asuntoBase = $ASUNTO;
            } elseif ($r <= 66 && !empty($ASUNTO_B)) {
                $varianteAb = 'B';
                $asuntoBase = $ASUNTO_B;
            } else {
                $varianteAb = 'C';
                $asuntoBase = $ASUNTO_C;
            }
        } elseif (!empty($ASUNTO_B)) {
            // Modo A/B clásico: 50% cada variante
            $varianteAb = (mt_rand(1, 100) <= 50) ? 'A' : 'B';
            $asuntoBase = ($varianteAb === 'B') ? $ASUNTO_B : $ASUNTO;
        }
    }

    // Preparar contenido del email usando placeholders del editor ({{CLUB}}, etc.)
    $asunto = str_replace(
        ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
        [$nombreClub, 'responsable', $federacion, date('Y')],
        $asuntoBase
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

    // Escribir log en archivo
    escribirLogEnvio(
        $LOG_DIR,
        $resultado['ok'] ? 'OK' : 'ERROR',
        $nombreClub,
        $emailClub,
        $cuenta['email'],
        $trackingId,
        $resultado['ok'] ? '' : ($resultado['error'] ?? 'Error desconocido')
    );

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

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCIÓN: Escribir log de envío en archivo
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Escribe una línea de log de envío en el archivo diario.
 * Formato: [YYYY-MM-DD HH:MM:SS] RESULTADO | CLUB | EMAIL | CUENTA_SMTP | TRACKING_ID | ERROR (si aplica)
 */
function escribirLogEnvio(string $logDir, string $resultado, string $club, string $email, string $cuentaSmtp, string $trackingId, string $error): void
{
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $archivo = $logDir . '/envios_' . date('Y-m-d') . '.log';
    $icono   = $resultado === 'OK' ? '✅' : '❌';
    $linea   = sprintf(
        "[%s] %s %s | Club: %s | Email: %s | SMTP: %s | Tracking: %s%s\n",
        date('Y-m-d H:i:s'),
        $icono,
        $resultado,
        $club,
        $email,
        $cuentaSmtp,
        $trackingId,
        $error ? ' | Error: ' . $error : ''
    );
    @file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
}