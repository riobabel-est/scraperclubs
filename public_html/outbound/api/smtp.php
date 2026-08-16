<?php
/**
 * api_smtp.php — API de gestion de cuentas SMTP.
 * Endpoints: get_accounts, save_account, toggle_account, delete_account, test_smtp.
 * PHP 8.x nativo — SiteGround compatible.
 */
declare(strict_types=1);

$DB_PATH = __DIR__ . '/../data/stats.db';

// ─── AUTENTICACIÓN ───────────────────────────────────────────────────────────
session_start();
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

if (!file_exists($DB_PATH)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ═══════════════════════════════════════ get_accounts ═══════════════════════
if ($action === 'get_accounts') {
    header('Content-Type: application/json');
    $accounts = [];
    $res = $db->query("SELECT * FROM cuentas_smtp ORDER BY email ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        // Se devuelve la contraseña completa para permitir su previsualización en el editor.
        // El modal la mantiene oculta por defecto (type=password) y solo se muestra al pulsar el toggle.
        $accounts[] = $row;
    }
    echo json_encode(['ok' => true, 'accounts' => $accounts]);
    exit;
}

// ═══════════════════════════════════════ save_account ═══════════════════════
if ($action === 'save_account') {
    header('Content-Type: application/json');
    try {
        $id        = (int)($_POST['id'] ?? 0);
        $email     = trim($_POST['email'] ?? '');
        $host      = trim($_POST['host'] ?? 'mail.getfutprotec.com');
        $puerto    = (int)($_POST['puerto'] ?? 465);
        $usuario   = trim($_POST['usuario'] ?? $email);
        $password  = $_POST['password'] ?? '';
        $seguridad = trim($_POST['seguridad'] ?? 'ssl');
        $limite    = (int)($_POST['limite_diario'] ?? 50);
        $nEmisor   = trim($_POST['nombre_emisor'] ?? '');
        $cEmisor   = trim($_POST['cargo_emisor'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Email invalido']);
            exit;
        }

        if ($id > 0) {
            if ($password !== '' && !str_contains($password, '***')) {
                $stmt = $db->prepare("UPDATE cuentas_smtp SET email=:email, host=:host, puerto=:p, usuario=:u, password=:pw, seguridad=:s, limite_diario=:l, nombre_emisor=:nemi, cargo_emisor=:cemi WHERE id=:id");
                $stmt->bindValue(':pw', $password, SQLITE3_TEXT);
            } else {
                $stmt = $db->prepare("UPDATE cuentas_smtp SET email=:email, host=:host, puerto=:p, usuario=:u, seguridad=:s, limite_diario=:l, nombre_emisor=:nemi, cargo_emisor=:cemi WHERE id=:id");
            }
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            if ($password === '' || str_contains($password, '***')) {
                echo json_encode(['ok' => false, 'error' => 'Password requerido para nueva cuenta']);
                exit;
            }
            $stmt = $db->prepare("INSERT INTO cuentas_smtp (email, host, puerto, usuario, password, seguridad, limite_diario, nombre_emisor, cargo_emisor) VALUES (:email, :host, :p, :u, :pw, :s, :l, :nemi, :cemi)");
            $stmt->bindValue(':pw', $password, SQLITE3_TEXT);
        }
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':host', $host, SQLITE3_TEXT);
        $stmt->bindValue(':p', $puerto, SQLITE3_INTEGER);
        $stmt->bindValue(':u', $usuario, SQLITE3_TEXT);
        $stmt->bindValue(':s', $seguridad, SQLITE3_TEXT);
        $stmt->bindValue(':l', $limite, SQLITE3_INTEGER);
        $stmt->bindValue(':nemi', $nEmisor, SQLITE3_TEXT);
        $stmt->bindValue(':cemi', $cEmisor, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $id > 0 ? $id : $db->lastInsertRowID()]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════ toggle_account ════════════════════
if ($action === 'toggle_account') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID invalido']);
            exit;
        }
        $current = (int)$db->querySingle("SELECT activa FROM cuentas_smtp WHERE id = {$id}");
        $newVal  = $current ? 0 : 1;
        $db->exec("UPDATE cuentas_smtp SET activa = {$newVal} WHERE id = {$id}");
        echo json_encode(['ok' => true, 'activa' => $newVal]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════ delete_account ═════════════════════
if ($action === 'delete_account') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID invalido']);
            exit;
        }
        $count = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp");
        if ($count <= 1) {
            echo json_encode(['ok' => false, 'error' => 'No puedes eliminar la ultima cuenta']);
            exit;
        }
        $db->exec("DELETE FROM cuentas_smtp WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════ test_smtp ══════════════════════════
if ($action === 'test_smtp') {
    header('Content-Type: application/json');
    set_time_limit(20);
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID invalido']);
            exit;
        }

        $cuenta = $db->querySingle("SELECT * FROM cuentas_smtp WHERE id = {$id}", true);
        if (!$cuenta) {
            echo json_encode(['ok' => false, 'error' => 'Cuenta no encontrada']);
            exit;
        }

        $host    = $cuenta['host'];
        $puerto  = (int)$cuenta['puerto'];
        $user    = $cuenta['usuario'];
        $pass    = $cuenta['password'];
        $timeout = 15;
        $readTimeout = 15;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $remote = ($puerto === 465) ? "ssl://{$host}:{$puerto}" : "tcp://{$host}:{$puerto}";
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);

        if (!$fp) {
            $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString("Conexion fallida: {$errstr} ({$errno})") . "' WHERE id = {$id}");
            echo json_encode(['ok' => false, 'status' => 'error', 'message' => "Conexion fallida: {$errstr}"]);
            exit;
        }

        // Timeout de LECTURA explícito: evita que fgets() quede bloqueado
        // indefinidamente si el servidor acepta la conexión pero no responde.
        stream_set_timeout($fp, $readTimeout);

        $read = function () use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 512)) !== false) {
                $resp .= $line;
                if (preg_match('/^\d{3}\s/', $line)) break;
                if (!preg_match('/^\d{3}[- ]/', $line)) break;
            }
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('Timeout de lectura SMTP');
            }
            return $resp;
        };

        $cmd = function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        // Banner + EHLO
        $read();
        $cmd("EHLO getfutprotec.com");

        // STARTTLS si puerto 587
        if ($puerto === 587) {
            $cmd("STARTTLS");
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            stream_set_timeout($fp, $readTimeout);
            $cmd("EHLO getfutprotec.com");
        }

        // AUTH LOGIN
        $authResp = $cmd("AUTH LOGIN");
        if (!str_contains($authResp, '334')) {
            fclose($fp);
            $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString("AUTH LOGIN no soportado: {$authResp}") . "' WHERE id = {$id}");
            echo json_encode(['ok' => false, 'status' => 'error', 'message' => "AUTH LOGIN no soportado"]);
            exit;
        }

        $cmd(base64_encode($user));
        $passResp = $cmd(base64_encode($pass));
        fclose($fp);

        if (str_contains($passResp, '235')) {
            $db->exec("UPDATE cuentas_smtp SET ultimo_error = NULL WHERE id = {$id}");
            echo json_encode(['ok' => true, 'status' => 'success', 'message' => 'Conexion y autenticacion exitosa']);
        } else {
            $errorMsg = trim($passResp);
            $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString("Auth fallida: {$errorMsg}") . "' WHERE id = {$id}");
            echo json_encode(['ok' => false, 'status' => 'error', 'message' => "Autenticacion fallida: {$errorMsg}"]);
        }
    } catch (\Exception $e) {
        if (isset($fp) && is_resource($fp)) @fclose($fp);
        echo json_encode(['ok' => false, 'status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Default
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Accion no reconocida']);