<?php
/**
 * test_adjuntos.php — Verificación del almacén de adjuntos en disco (FASE ADJUNTOS).
 *
 * Comprueba:
 *   1. Cada fila con `ruta` tiene su archivo en disco y el tamaño coincide.
 *   2. futprotec_ruta_adjunto() resuelve rutas válidas y rechaza path traversal.
 *   3. futprotec_guardar_adjunto() escribe en la estructura <club>/enviados|recibidos.
 *
 * Uso: php scripts/test_adjuntos.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../public_html/outbound/inc/adjuntos.php';

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
$db = new SQLite3($DB);
$db->enableExceptions(true);

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS | {$nombre}\n"; }
    else { $fail++; echo "FAIL | {$nombre} | {$detalle}\n"; }
}

// ─── 1. Rutas en disco existen y tamaño coincide con la BD ─────────────────
$totalRutas = 0;
foreach (['respuestas_adjuntos' => 'recibidos', 'envios_adjuntos' => 'enviados'] as $tabla => $tipo) {
    $res = $db->query("SELECT id, nombre, tamano, ruta, datos, length(datos) AS tam_blob FROM {$tabla}");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($row['ruta'])) {
            $totalRutas++;
            $abs = futprotec_ruta_adjunto((string)$row['ruta']);
            check("{$tabla}#{$row['id']} archivo en disco", $abs !== null && is_file($abs), $row['ruta'] ?? '');
            if ($abs && is_file($abs)) {
                $tamDisk = filesize($abs);
                check("{$tabla}#{$row['id']} tamaño disco == BD", $tamDisk === (int)$row['tamano'], "disco={$tamDisk} bd={$row['tamano']}");
                // Integridad: contenido disco == BLOB BD.
                check("{$tabla}#{$row['id']} contenido == BLOB", file_get_contents($abs) === (string)$row['datos'], 'diferente');
            }
        }
    }
}
check('todas las filas con ruta verificadas (>0)', $totalRutas > 0, "rutas={$totalRutas}");

// ─── 2. Anti path traversal ────────────────────────────────────────────────
check('ruta inválida (fuera del almacén) → null', futprotec_ruta_adjunto('../stats.db') === null);
check('ruta no-adjuntos → null', futprotec_ruta_adjunto('otra/../stats.db') === null);

// ─── 3. Escritura nueva (estructura <club>/<tipo>) ─────────────────────────
$tmpDatos = 'contenido de prueba ADJUNTOS-' . time();
$rutaNueva = futprotec_guardar_adjunto(9999, 'recibidos', 'test_adjuntos.txt', $tmpDatos);
check('guardar_adjunto devuelve ruta', $rutaNueva !== null, (string)$rutaNueva);
if ($rutaNueva !== null) {
    $abs = futprotec_ruta_adjunto($rutaNueva);
    check('archivo de prueba escrito en disco', $abs !== null && is_file($abs) && file_get_contents($abs) === $tmpDatos, (string)$abs);
    // Limpiar el archivo de prueba (no dejar basura en el almacén).
    if ($abs && is_file($abs)) { @unlink($abs); }
}
check('guardar_adjunto en enviados (otro tipo)', futprotec_guardar_adjunto(9999, 'enviados', 'x.png', 'data') !== null);

$db->close();
echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
