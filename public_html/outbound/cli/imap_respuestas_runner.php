<?php
/**
 * imap_respuestas_runner.php — RUNNER WEB TEMPORAL (se borra tras verificar).
 * ============================================================================
 * Ejecuta el procesamiento de respuestas IMAP contra data/stats.db de producción.
 * Replica la lógica del CLI imap_respuestas.php pero accesible por HTTP
 * (SiteGround no permite CLI directo).
 *
 * SEGURIDAD:
 *   - Requiere token secreto (?token=...) para ejecutar.
 *   - Sin ?apply=1 solo muestra auditoría (no escribe en BD).
 *   - Con ?apply=1&token=... registra las respuestas en la tabla `respuestas`.
 *   - Este archivo se ELIMINA tras la verificación (no queda en producción).
 *
 * USO:
 *   GET .../cli/imap_respuestas_runner.php?token=SECRET            (auditoría)
 *   GET .../cli/imap_respuestas_runner.php?token=SECRET&apply=1    (aplicar)
 */

declare(strict_types=1);

// Token secreto (se pasa por query). Cambiar antes de subir.
$SECRET = 'IMAP_RESPUESTAS_20260819';

$token = (string)($_GET['token'] ?? '');
if (!hash_equals($SECRET, $token)) {
    http_response_code(403);
    echo "FORBIDDEN\n";
    exit;
}

$apply = (($_GET['apply'] ?? '') === '1');

$DB_PATH = __DIR__ . '/../data/stats.db';

header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

require_once __DIR__ . '/../inc/imap_respuestas.php';

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

echo "[" . date('Y-m-d H:i:s') . "] === RUNNER WEB: Registro de respuestas IMAP ===\n";
echo "[" . date('Y-m-d H:i:s') . "] Modo: " . ($apply ? 'APLICAR (escribe en BD)' : 'AUDITORÍA (solo lectura)') . "\n";

// ─── Seleccionar cuentas activas ───
$cuentas = [];
$res = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $cuentas[] = $row;
}

echo "[" . date('Y-m-d H:i:s') . "] Cuentas a procesar: " . count($cuentas) . "\n";

$totales = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'mensajes' => 0];

foreach ($cuentas as $cuenta) {
    echo "[" . date('Y-m-d H:i:s') . "] ── Procesando cuenta: {$cuenta['email']} ──\n";

    $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
    try {
        $imap->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''));
        echo "[" . date('Y-m-d H:i:s') . "]   [OK] Login IMAP correcto\n";

        foreach ($CARPETAS_AUDITAR as $carpeta) {
            try {
                $total = $imap->seleccionar($carpeta);
                echo "[" . date('Y-m-d H:i:s') . "]   Carpeta '{$carpeta}': {$total} mensajes\n";
                if ($total === 0) {
                    continue;
                }

                $seqs = $imap->buscarTodos();
                foreach ($seqs as $seq) {
                    $totales['mensajes']++;
                    try {
                        $uid = $imap->fetchUID($seq);
                        $rawHeader = $imap->fetchCabeceras($seq);
                        $rawBody = $imap->fetchCuerpo($seq);
                        $raw = $rawHeader . "\r\n\r\n" . $rawBody;

                        $msg = imap_parsear_mensaje($raw);
                        $clasificacion = imap_clasificar($msg);
                        $envio = imap_atribuir($db, $msg);

                        $atribucion = $envio ? "envio_id={$envio['id']} lead_id={$envio['lead_id']} camp={$envio['campaign_id']}" : 'SIN ATRIBUCIÓN';

                        if (!$apply) {
                            echo "[" . date('Y-m-d H:i:s') . "]     [AUDITORÍA] Se registraría: {$msg['from_email']} | {$msg['subject']} | {$clasificacion} | {$atribucion}\n";
                            continue;
                        }

                        $resultado = imap_registrar_respuesta($db, $msg, $envio, $clasificacion, $carpeta, $uid, $cuenta['email']);
                        if ($resultado === 'insertado') {
                            $totales['insertados']++;
                            echo "[" . date('Y-m-d H:i:s') . "]     ✅ Respuesta registrada: {$msg['from_email']} | {$msg['subject']} | {$clasificacion}\n";
                        } elseif ($resultado === 'duplicado') {
                            $totales['duplicados']++;
                            echo "[" . date('Y-m-d H:i:s') . "]     ⏭ Duplicado (ya registrado): {$msg['message_id']}\n";
                        } else {
                            $totales['errores']++;
                            echo "[" . date('Y-m-d H:i:s') . "]     ❌ Error al registrar respuesta\n";
                        }
                    } catch (\Throwable $e) {
                        $totales['errores']++;
                        echo "[" . date('Y-m-d H:i:s') . "]     ❌ Error procesando msg {$seq}: {$e->getMessage()}\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "[" . date('Y-m-d H:i:s') . "]   ⚠️ Carpeta '{$carpeta}' no accesible: {$e->getMessage()}\n";
            }
        }
    } catch (\Throwable $e) {
        $totales['errores']++;
        echo "[" . date('Y-m-d H:i:s') . "]   ❌ Error de conexión IMAP: {$e->getMessage()}\n";
    } finally {
        $imap->cerrar();
    }
}

echo "[" . date('Y-m-d H:i:s') . "] === RESUMEN ===\n";
echo "[" . date('Y-m-d H:i:s') . "] Mensajes procesados: {$totales['mensajes']}\n";
echo "[" . date('Y-m-d H:i:s') . "] Insertados: {$totales['insertados']}\n";
echo "[" . date('Y-m-d H:i:s') . "] Duplicados: {$totales['duplicados']}\n";
echo "[" . date('Y-m-d H:i:s') . "] Errores: {$totales['errores']}\n";

$db->close();
echo "[" . date('Y-m-d H:i:s') . "] Ciclo completado.\n";
exit(0);
