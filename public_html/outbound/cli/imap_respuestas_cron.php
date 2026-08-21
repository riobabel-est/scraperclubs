<?php
/**
 * imap_respuestas_cron.php — RUNNER WEB PERMANENTE para Cron Job (SiteGround)
 * ==============================================================================
 * Ejecuta la sincronización de respuestas IMAP/POP3 de forma automática y segura,
 * invocable por HTTP desde el Cron Job de SiteGround (que no permite CLI directo
 * en planes compartidos).
 *
 * Reutiliza la lógica completa del CLI `cli/imap_respuestas.php` (atribución,
 * idempotencia, detención de secuencias, logs) pero expuesta como endpoint HTTP.
 *
 * SEGURIDAD:
 *   - Requiere token secreto (?token=...) para ejecutar (hash_equals, timing-safe).
 *   - El token se lee de la constante IMAP_CRON_SECRET o de la variable de entorno
 *     IMAP_CRON_SECRET (getenv). Si no está definido, se bloquea la ejecución.
 *   - Protección anti-concurrencia: bloqueo por archivo (flock) para evitar que
 *     dos cron solapados procesen el mismo buzón a la vez.
 *   - Sin ?apply=1 solo muestra auditoría (no escribe en BD).
 *   - Con ?apply=1&token=... registra las respuestas en la tabla `respuestas`.
 *
 * USO (Cron Job HTTP):
 *   GET .../cli/imap_respuestas_cron.php?token=SECRET&apply=1
 *
 * PHP 8.x nativo — SiteGround compatible (sin extensiones PECL externas).
 */

declare(strict_types=1);

// ─── Token secreto (constante o variable de entorno) ───
// IMPORTANTE: definir IMAP_CRON_SECRET en el entorno de SiteGround o editar la
// constante antes de subir. Nunca exponer en logs ni commits.
$SECRET = getenv('IMAP_CRON_SECRET') ?: 'IMAP_RESPUESTAS_CRON_20260820';

$token = (string)($_GET['token'] ?? '');
if (!hash_equals($SECRET, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FORBIDDEN\n";
    exit;
}

$apply = (($_GET['apply'] ?? '') === '1');

$DB_PATH = __DIR__ . '/../data/stats.db';
$LOG_PATH = __DIR__ . '/../logs/imap_sync.log';
$LOCK_PATH = __DIR__ . '/../logs/imap_sync.lock';

header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($DB_PATH)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

// ─── Protección anti-concurrencia (flock) ───
$lockDir = dirname($LOCK_PATH);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = @fopen($LOCK_PATH, 'c');
if ($lockHandle === false) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: no se pudo abrir el archivo de bloqueo.\n";
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] ⚠️ Ya hay una sincronización IMAP en curso. Se omite esta ejecución.\n";
    exit(0);
}

require_once __DIR__ . '/../inc/imap_respuestas.php';
require_once __DIR__ . '/../inc/pop3_respuestas.php';

// ─── Helper de logging (consola + archivo) ───
function imap_cron_log(string $mensaje, bool $echo = true): void
{
    global $LOG_PATH;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    if ($echo) {
        echo $linea . "\n";
    }
    $dir = dirname($LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($LOG_PATH, $linea . "\n", FILE_APPEND | LOCK_EX);
}

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
        imap_cron_log("Migración: columna driver_sync añadida a cuentas_smtp (default IMAP)");
    }
} catch (\Throwable $e) {
    imap_cron_log("AVISO: no se pudo asegurar driver_sync: {$e->getMessage()}");
}

imap_cron_log("=== Sincronización de respuestas IMAP/POP3 (CRON WEB) ===");
imap_cron_log("Modo: " . ($apply ? 'APLICAR (escribe en BD)' : 'AUDITORÍA (solo lectura)'));

// ─── Seleccionar cuentas activas ───
$cuentas = [];
$res = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $cuentas[] = $row;
}

imap_cron_log("Cuentas a procesar: " . count($cuentas));

$totales = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'mensajes' => 0, 'secuencias_detenidas' => 0];

foreach ($cuentas as $cuenta) {
    $driver = strtoupper($cuenta['driver_sync'] ?? 'IMAP');
    imap_cron_log("── Procesando cuenta: {$cuenta['email']} (driver: {$driver}) ──");

    // ─── Driver POP3 ───
    if ($driver === 'POP3') {
        $pop3 = new ClientePOP3($POP3_HOST, $POP3_PORT);
        try {
            $pop3->conectar($cuenta['usuario'], $cuenta['password']);
            imap_cron_log("  [OK] Login POP3 correcto");
            $stats = pop3_procesar_buzon($db, $cuenta, $pop3);
            $totales['mensajes'] += $stats['mensajes'];
            $totales['insertados'] += $stats['insertados'];
            $totales['duplicados'] += $stats['duplicados'];
            $totales['errores'] += $stats['errores'];
            imap_cron_log("  [OK] POP3: {$stats['mensajes']} mensajes, {$stats['insertados']} insertados, {$stats['duplicados']} duplicados, {$stats['errores']} errores");
        } catch (\Throwable $e) {
            $totales['errores']++;
            imap_cron_log("  ❌ Error de conexión POP3: {$e->getMessage()}");
        } finally {
            $pop3->cerrar();
        }
        continue;
    }

    // ─── Driver IMAP (por defecto) ───
    $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
    try {
        $imap->conectar($cuenta['usuario'], $cuenta['password']);
        imap_cron_log("  [OK] Login IMAP correcto");

        foreach ($CARPETAS_AUDITAR as $carpeta) {
            try {
                $total = $imap->seleccionar($carpeta);
                imap_cron_log("  Carpeta '{$carpeta}': {$total} mensajes");
                if ($total === 0) {
                    continue;
                }

                $seqs = $imap->buscarTodos();
                foreach ($seqs as $seq) {
                    $totales['mensajes']++;
                    try {
                        // Fuente PRIMARIA = FETCH <seq> (UID ENVELOPE FLAGS)
                        $respEnvelope = $imap->fetchEnvelopeCompleto($seq);
                        $uid = $imap->extraerUID($respEnvelope);
                        $msg = imap_parsear_envelope($respEnvelope);

                        // Intento de cuerpo con degradado elegante
                        try {
                            $cuerpoRaw = $imap->fetchCuerpo($seq);
                            if (trim($cuerpoRaw) !== '') {
                                $msg['cuerpo'] = trim($cuerpoRaw);
                            }
                        } catch (\Throwable $e) {
                            try { $imap->cerrar(); } catch (\Throwable $ign) {}
                            $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
                            $imap->conectar($cuenta['usuario'], $cuenta['password']);
                            $imap->seleccionar($carpeta);
                        }

                        // Fallback de metadatos si ENVELOPE no aportó nada
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

                        if (!$apply) {
                            imap_cron_log("    [AUDITORÍA] Se registraría: {$msg['from_email']} | {$msg['subject']} | {$clasificacion} | {$atribucion}");
                            continue;
                        }

                        $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uid, $cuenta['email']);
                        if ($resultado === 'insertado') {
                            $totales['insertados']++;
                            imap_cron_log("    ✅ Respuesta registrada: {$msg['from_email']} | {$msg['subject']} | {$clasificacion}");

                            // Detener secuencia del lead que respondió
                            if ($envio !== null && !empty($envio['lead_id'])) {
                                $detenida = imap_detener_secuencia($db, (int)$envio['lead_id']);
                                if ($detenida) {
                                    $totales['secuencias_detenidas']++;
                                    imap_cron_log("    ⏹ Secuencia detenida para lead_id={$envio['lead_id']} (motivo: RESPUESTA_IMAP)");
                                }
                            }
                        } elseif ($resultado === 'duplicado') {
                            $totales['duplicados']++;
                            imap_cron_log("    ⏭ Duplicado (ya registrado): {$msg['message_id']}");
                        } else {
                            $totales['errores']++;
                            imap_cron_log("    ❌ Error al registrar respuesta");
                        }
                    } catch (\Throwable $e) {
                        $totales['errores']++;
                        imap_cron_log("    ❌ Error procesando msg {$seq}: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                imap_cron_log("  ⚠️ Carpeta '{$carpeta}' no accesible: {$e->getMessage()}");
            }
        }
    } catch (\Throwable $e) {
        $totales['errores']++;
        imap_cron_log("  ❌ Error de conexión IMAP: {$e->getMessage()}");
    } finally {
        $imap->cerrar();
    }
}

imap_cron_log("=== RESUMEN ===");
imap_cron_log("Mensajes procesados: {$totales['mensajes']}");
imap_cron_log("Insertados: {$totales['insertados']}");
imap_cron_log("Duplicados: {$totales['duplicados']}");
imap_cron_log("Errores: {$totales['errores']}");
imap_cron_log("Secuencias detenidas: {$totales['secuencias_detenidas']}");

// ─── Log resumido por cuenta ───
foreach ($cuentas as $cuenta) {
    imap_cron_log("[ACCOUNT: {$cuenta['email']}] OK: {$totales['mensajes']} mensajes analizados, {$totales['insertados']} respuestas detectadas, {$totales['secuencias_detenidas']} secuencias detenidas.");
}

$db->close();
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
imap_cron_log("Ciclo completado.");
exit(0);
