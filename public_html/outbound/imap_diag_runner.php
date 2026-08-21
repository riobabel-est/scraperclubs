<?php
/**
 * imap_diag_runner.php — RUNNER WEB TEMPORAL DE DIAGNÓSTICO (se borra tras verificar).
 * ============================================================================
 * Diagnostica por qué el mensaje 1 de rodrigo@getfutprotec.com da timeout.
 * Lee SOLO comandos ligeros (UID, RFC822.SIZE, ENVELOPE) para aislar el problema.
 *
 * SEGURIDAD:
 *   - Requiere token secreto (?token=...) para ejecutar.
 *   - SOLO LECTURA. No escribe en BD.
 *   - Este archivo se ELIMINA tras la verificación.
 */

declare(strict_types=1);

$SECRET = 'IMAP_DIAG_20260819';
$token = (string)($_GET['token'] ?? '');
if (!hash_equals($SECRET, $token)) {
    http_response_code(403);
    echo "FORBIDDEN\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/inc/imap_respuestas.php';

$db = new SQLite3(__DIR__ . '/data/stats.db');
$db->enableExceptions(true);

// Buscar la cuenta rodrigo
$res = $db->query("SELECT * FROM cuentas_smtp WHERE email = 'rodrigo@getfutprotec.com' AND activa = 1");
$cuenta = $res->fetchArray(SQLITE3_ASSOC);
if (!$cuenta) {
    echo "Cuenta rodrigo no encontrada\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] === DIAGNÓSTICO IMAP: rodrigo ===\n";
echo "[" . date('Y-m-d H:i:s') . "] Host: {$IMAP_HOST}:{$IMAP_PORT}\n";

$imap = new ClienteIMAP($IMAP_HOST, $IMAP_PORT);
try {
    $imap->conectar($cuenta['usuario'], $cuenta['password']);
    echo "[" . date('Y-m-d H:i:s') . "] [OK] Login IMAP correcto\n";

    $total = $imap->seleccionar('INBOX');
    echo "[" . date('Y-m-d H:i:s') . "] INBOX: {$total} mensajes\n";

    // 1. Leer SOLO el UID (comando ligero)
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 1: fetchUID('1') ---\n";
    try {
        $uid = $imap->fetchUID('1');
        echo "[" . date('Y-m-d H:i:s') . "] [OK] UID = {$uid}\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ fetchUID falló: {$e->getMessage()}\n";
    }

    // 2. Leer SOLO el tamaño (RFC822.SIZE) — comando ligero
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 2: RFC822.SIZE ---\n";
    try {
        $size = $imap->comando("FETCH 1 (RFC822.SIZE)");
        echo "[" . date('Y-m-d H:i:s') . "] [OK] RFC822.SIZE = " . json_encode($size) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ RFC822.SIZE falló: {$e->getMessage()}\n";
    }

    // 3. Leer SOLO ENVELOPE (cabeceras esenciales, ligero)
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 3: ENVELOPE ---\n";
    try {
        $env = $imap->comando("FETCH 1 (ENVELOPE)");
        echo "[" . date('Y-m-d H:i:s') . "] [OK] ENVELOPE = " . json_encode($env) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ ENVELOPE falló: {$e->getMessage()}\n";
    }


    // 4. Leer cabeceras completas (BODY.PEEK[HEADER])
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 4: BODY.PEEK[HEADER] ---\n";
    try {
        $hdr = $imap->fetchCabeceras('1');
        echo "[" . date('Y-m-d H:i:s') . "] [OK] HEADER leído, " . strlen($hdr) . " bytes\n";
        echo "[" . date('Y-m-d H:i:s') . "] Primeras líneas:\n";
        echo substr($hdr, 0, 1000) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ HEADER falló: {$e->getMessage()}\n";
    }

    // 5. Leer cuerpo (BODY.PEEK[TEXT])
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 5: BODY.PEEK[TEXT] ---\n";
    try {
        $body = $imap->fetchCuerpo('1');
        echo "[" . date('Y-m-d H:i:s') . "] [OK] BODY leído, " . strlen($body) . " bytes\n";
        echo "[" . date('Y-m-d H:i:s') . "] Primeras líneas:\n";
        echo substr($body, 0, 1000) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ BODY falló: {$e->getMessage()}\n";
    }

    // 6. Probar BODYSTRUCTURE (estructura MIME, ligero)
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 6: BODYSTRUCTURE ---\n";
    try {
        $bs = $imap->comando("FETCH 1 (BODYSTRUCTURE)");
        echo "[" . date('Y-m-d H:i:s') . "] [OK] BODYSTRUCTURE = " . json_encode($bs) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ BODYSTRUCTURE falló: {$e->getMessage()}\n";
    }

    // 7. Probar BODY.PEEK[] (mensaje completo)
    echo "[" . date('Y-m-d H:i:s') . "] --- Paso 7: BODY.PEEK[] ---\n";
    try {
        $full = $imap->comando("FETCH 1 (BODY.PEEK[])");
        echo "[" . date('Y-m-d H:i:s') . "] [OK] BODY.PEEK[] leído, " . count($full) . " líneas\n";
        echo "[" . date('Y-m-d H:i:s') . "] Primeras líneas:\n";
        echo implode("\n", array_slice($full, 0, 20)) . "\n";
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ BODY.PEEK[] falló: {$e->getMessage()}\n";
    }



} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ❌ Error general: {$e->getMessage()}\n";
} finally {
    $imap->cerrar();
}

echo "[" . date('Y-m-d H:i:s') . "] === FIN DIAGNÓSTICO ===\n";
exit(0);
