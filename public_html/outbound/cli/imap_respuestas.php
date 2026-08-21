<?php
/**
 * imap_respuestas.php — CLI de registro de respuestas IMAP/POP3 (FASE F + FASE 4)
 * ==============================================================================
 * Recorre todas las cuentas SMTP activas, lee sus buzones (IMAP o POP3 según el
 * driver_sync configurado por cuenta) y registra las respuestas recibidas en la
 * tabla `respuestas` con atribución e idempotencia. Además, detiene los
 * follow-ups de los leads que hayan respondido (secuencia_lead → DETENIDO).
 *
 * Uso:
 *   php cli/imap_respuestas.php
 *   php cli/imap_respuestas.php --cuenta=email@getfutprotec.com
 *   php cli/imap_respuestas.php --dry-run
 *
 * Opciones:
 *   --cuenta=EMAIL   Procesar solo una cuenta específica.
 *   --dry-run        No escribir en BD, solo mostrar lo que se detectaría.
 *   --verbose        Mostrar detalle por mensaje.
 *
 * MODO READ-ONLY sobre el buzón (SELECT readonly, sin STORE/COPY/DELETE/DELE).
 * La única escritura es en la BD local (tabla respuestas + comunicaciones_log).
 *
 * FASE 1: usa FETCH <seq> (UID ENVELOPE FLAGS) como fuente primaria (comando
 * ligero que el servidor IMAP de SiteGround SÍ responde sin colgarse).
 * FASE 2: parser de literales {N} corregido + timeout estricto de 5s + degradado.
 * FASE 3: fallback POP3 (puerto 995) si driver_sync='POP3' o IMAP falla.
 * FASE 4: logs con marcas de tiempo en logs/imap_sync.log, invocable por Cron.
 */

declare(strict_types=1);

// ─── Solo CLI ───
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se ejecuta desde CLI.\n";
    exit(1);
}

$DB_PATH = __DIR__ . '/../data/stats.db';
$LOG_PATH = __DIR__ . '/../logs/imap_sync.log';

if (!file_exists($DB_PATH)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

require_once __DIR__ . '/../inc/imap_respuestas.php';
require_once __DIR__ . '/../inc/pop3_respuestas.php';

// ─── Helper de logging (consola + archivo) ───
function imap_log(string $mensaje, bool $echo = true): void
{
    global $LOG_PATH;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    if ($echo) {
        echo $linea . "\n";
    }
    // Asegurar directorio de logs
    $dir = dirname($LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($LOG_PATH, $linea . "\n", FILE_APPEND | LOCK_EX);
}

// ─── Parsear opciones ───
$opts = getopt('', ['cuenta:', 'dry-run', 'verbose']);
$soloCuenta = $opts['cuenta'] ?? null;
$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

// ─── Asegurar columna driver_sync en cuentas_smtp (idempotente) ───
try {
    $cols = [];
    $res = $db->query('PRAGMA table_info(cuentas_smtp)');
    while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
        $cols[$c['name']] = true;
    }
    if (!isset($cols['driver_sync'])) {
        $db->exec("ALTER TABLE cuentas_smtp ADD COLUMN driver_sync TEXT DEFAULT 'IMAP'");
        imap_log("Migración: columna driver_sync añadida a cuentas_smtp (default IMAP)");
    }
} catch (\Throwable $e) {
    imap_log("AVISO: no se pudo asegurar driver_sync: {$e->getMessage()}");
}

imap_log("=== Sincronización de respuestas IMAP/POP3 ===");
imap_log("Modo: " . ($dryRun ? 'DRY-RUN (sin escritura)' : 'PRODUCCIÓN (escribe en BD)'));

// ─── Seleccionar cuentas ───
if ($soloCuenta !== null) {
    $stmt = $db->prepare("SELECT * FROM cuentas_smtp WHERE email = :email AND activa = 1");
    $stmt->bindValue(':email', $soloCuenta, SQLITE3_TEXT);
    $cuentas = [];
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cuentas[] = $row;
    }
    if (empty($cuentas)) {
        imap_log("ERROR: cuenta '{$soloCuenta}' no encontrada o inactiva.");
        $db->close();
        exit(1);
    }
} else {
    $cuentas = [];
    $res = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cuentas[] = $row;
    }
}

imap_log("Cuentas a procesar: " . count($cuentas));

$totales = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'mensajes' => 0, 'secuencias_detenidas' => 0];

foreach ($cuentas as $cuenta) {
    $driver = strtoupper($cuenta['driver_sync'] ?? 'IMAP');
    imap_log("── Procesando cuenta: {$cuenta['email']} (driver: {$driver}) ──");

    // ─── FASE 3: selección de driver (IMAP por defecto, POP3 si configurado) ───
    if ($driver === 'POP3') {
        $pop3 = new ClientePOP3($POP3_HOST, $POP3_PORT);
        try {
            $pop3->conectar($cuenta['usuario'], $cuenta['password']);
            imap_log("  [OK] Login POP3 correcto");
            $stats = pop3_procesar_buzon($db, $cuenta, $pop3);
            $totales['mensajes'] += $stats['mensajes'];
            $totales['insertados'] += $stats['insertados'];
            $totales['duplicados'] += $stats['duplicados'];
            $totales['errores'] += $stats['errores'];
            imap_log("  [OK] POP3: {$stats['mensajes']} mensajes, {$stats['insertados']} insertados, {$stats['duplicados']} duplicados, {$stats['errores']} errores");
        } catch (\Throwable $e) {
            $totales['errores']++;
            imap_log("  ❌ Error de conexión POP3: {$e->getMessage()}");
        } finally {
            $pop3->cerrar();
        }
        continue;
    }

    // ─── Driver IMAP (por defecto) ───
    $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
    try {
        $imap->conectar($cuenta['usuario'], $cuenta['password']);
        imap_log("  [OK] Login IMAP correcto");

        foreach ($CARPETAS_AUDITAR as $carpeta) {
            try {
                $total = $imap->seleccionar($carpeta);
                imap_log("  Carpeta '{$carpeta}': {$total} mensajes");
                if ($total === 0) {
                    continue;
                }

                $seqs = $imap->buscarTodos();
                foreach ($seqs as $seq) {
                    $totales['mensajes']++;
                    try {
                        // ─── FASE 1: Fuente PRIMARIA = FETCH <seq> (UID ENVELOPE FLAGS) ───
                        $respEnvelope = $imap->fetchEnvelopeCompleto($seq);
                        $uid = $imap->extraerUID($respEnvelope);
                        $msg = imap_parsear_envelope($respEnvelope);

                        // ─── FASE 2: Intento de cuerpo con degradado elegante ───
                        try {
                            $cuerpoRaw = $imap->fetchCuerpo($seq);
                            if (trim($cuerpoRaw) !== '') {
                                $msg['cuerpo'] = trim($cuerpoRaw);
                            }
                        } catch (\Throwable $e) {
                            // Timeout/error en BODY.PEEK[TEXT]: reconectar.
                            try { $imap->cerrar(); } catch (\Throwable $ign) {}
                            $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
                            $imap->conectar($cuenta['usuario'], $cuenta['password']);
                            $imap->seleccionar($carpeta);
                        }

                        // ─── Fallback de metadatos si ENVELOPE no aportó nada ───
                        if (empty($msg['message_id']) && empty($msg['from_email'])) {
                            try {
                                $rawHeader = $imap->fetchHeaderFields($seq);
                                if (trim($rawHeader) !== '') {
                                    $msg = array_merge($msg, imap_parsear_header_fields($rawHeader));
                                }
                            } catch (\Throwable $e) {
                                // ignorar
                            }
                        }

                        $clasificacion = imap_clasificar($msg);
                        $envio = imap_atribuir($db, $msg);

                        $atribucion = $envio ? "envio_id={$envio['id']} lead_id={$envio['lead_id']} camp={$envio['campaign_id']}" : 'SIN ATRIBUCIÓN';

                        if ($verbose) {
                            imap_log("    Msg {$seq}: From={$msg['from_email']} | Subj={$msg['subject']} | Clasif={$clasificacion} | {$atribucion}");
                        }

                        if ($dryRun) {
                            imap_log("    [DRY-RUN] Se registraría: {$msg['from_email']} | {$msg['subject']} | {$clasificacion} | {$atribucion}");
                            continue;
                        }

                        $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uid, $cuenta['email']);
                        if ($resultado === 'insertado') {
                            $totales['insertados']++;
                            imap_log("    ✅ Respuesta registrada: {$msg['from_email']} | {$msg['subject']} | {$clasificacion}");

                            // ─── Detener secuencia del lead que respondió ───
                            if ($envio !== null && !empty($envio['lead_id'])) {
                                $detenida = imap_detener_secuencia($db, (int)$envio['lead_id']);
                                if ($detenida) {
                                    $totales['secuencias_detenidas']++;
                                    imap_log("    ⏹ Secuencia detenida para lead_id={$envio['lead_id']} (motivo: RESPUESTA_IMAP)");
                                }
                            }
                        } elseif ($resultado === 'duplicado') {
                            $totales['duplicados']++;
                            imap_log("    ⏭ Duplicado (ya registrado): {$msg['message_id']}");
                        } else {
                            $totales['errores']++;
                            imap_log("    ❌ Error al registrar respuesta");
                        }
                    } catch (\Throwable $e) {
                        $totales['errores']++;
                        imap_log("    ❌ Error procesando msg {$seq}: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                imap_log("  ⚠️ Carpeta '{$carpeta}' no accesible: {$e->getMessage()}");
            }
        }
    } catch (\Throwable $e) {
        $totales['errores']++;
        imap_log("  ❌ Error de conexión IMAP: {$e->getMessage()}");
    } finally {
        $imap->cerrar();
    }
}

imap_log("=== RESUMEN ===");
imap_log("Mensajes procesados: {$totales['mensajes']}");
imap_log("Insertados: {$totales['insertados']}");
imap_log("Duplicados: {$totales['duplicados']}");
imap_log("Errores: {$totales['errores']}");
imap_log("Secuencias detenidas: {$totales['secuencias_detenidas']}");

// ─── Log resumido por cuenta (formato FASE 4) ───
foreach ($cuentas as $cuenta) {
    imap_log("[ACCOUNT: {$cuenta['email']}] OK: {$totales['mensajes']} mensajes analizados, {$totales['insertados']} respuestas detectadas, {$totales['secuencias_detenidas']} secuencias detenidas.");
}

$db->close();
imap_log("Ciclo completado.");
exit(0);
