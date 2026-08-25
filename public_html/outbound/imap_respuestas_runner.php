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
 *   GET .../outbound/imap_respuestas_runner.php?token=SECRET            (auditoría)
 *   GET .../outbound/imap_respuestas_runner.php?token=SECRET&apply=1    (aplicar)
 *
 * NOTA (2026-08-19): usa imap_procesar_buzon() del inc actualizado, que emplea
 * ENVELOPE como fuente primaria (comando ligero que el servidor IMAP de
 * SiteGround SÍ responde) y el cuerpo como opcional. Así el email del usuario
 * se registra aunque el cuerpo no pueda leerse.
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
$soloCuenta = (string)($_GET['cuenta'] ?? '');

$DB_PATH = __DIR__ . '/data/stats.db';


header('Content-Type: text/plain; charset=utf-8');

if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

require_once __DIR__ . '/inc/imap_respuestas.php';

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

echo "[" . date('Y-m-d H:i:s') . "] === RUNNER WEB: Registro de respuestas IMAP ===\n";
echo "[" . date('Y-m-d H:i:s') . "] Modo: " . ($apply ? 'APLICAR (escribe en BD)' : 'AUDITORÍA (solo lectura)') . "\n";

// ─── Seleccionar cuentas activas ───
$cuentas = [];
if ($soloCuenta !== '') {
    $stmt = $db->prepare("SELECT * FROM cuentas_smtp WHERE activa = 1 AND email = :email ORDER BY id");
    $stmt->bindValue(':email', $soloCuenta, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cuentas[] = $row;
    }
    if (empty($cuentas)) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: cuenta '{$soloCuenta}' no encontrada o inactiva.\n";
        $db->close();
        exit(1);
    }
} else {
    $res = $db->query("SELECT * FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cuentas[] = $row;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Cuentas a procesar: " . count($cuentas) . ($soloCuenta !== '' ? " (filtrada: {$soloCuenta})" : '') . "\n";


$totales = ['insertados' => 0, 'duplicados' => 0, 'errores' => 0, 'mensajes' => 0, 'sin_atribucion' => 0];

foreach ($cuentas as $cuenta) {
    echo "[" . date('Y-m-d H:i:s') . "] ── Procesando cuenta: {$cuenta['email']} ──\n";

    $imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
    try {
        $imap->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''));
        echo "[" . date('Y-m-d H:i:s') . "]   [OK] Login IMAP correcto\n";

        // ─── Usa imap_procesar_buzon() del inc, que emplea BODY.PEEK[HEADER.FIELDS]
        //     como fuente PRIMARIA (comando ligero que SiteGround responde) y
        //     ENVELOPE como fallback. Así se obtienen from/to/subject/message_id
        //     completos para atribución e idempotencia. ───
        $stats = imap_procesar_buzon($db, $cuenta, $imap);

        $totales['mensajes'] += $stats['mensajes'];
        $totales['insertados'] += $stats['insertados'];
        $totales['duplicados'] += $stats['duplicados'];
        $totales['errores'] += $stats['errores'];
        $totales['sin_atribucion'] += $stats['sin_atribucion'];

        echo "[" . date('Y-m-d H:i:s') . "]   Carpeta(s) procesadas: {$stats['carpetas']} | Msgs: {$stats['mensajes']} | Insertados: {$stats['insertados']} | Duplicados: {$stats['duplicados']} | Errores: {$stats['errores']} | Sin atribución: {$stats['sin_atribucion']}\n";
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
echo "[" . date('Y-m-d H:i:s') . "] Sin atribución: {$totales['sin_atribucion']}\n";
echo "[" . date('Y-m-d H:i:s') . "] Errores: {$totales['errores']}\n";

$db->close();
echo "[" . date('Y-m-d H:i:s') . "] Ciclo completado.\n";
exit(0);
