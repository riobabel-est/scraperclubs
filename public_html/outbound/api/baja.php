<?php
/**
 * baja.php — Gestión de bajas (opt-out) con confirmación explícita.
 *
 * REDISEÑO GO-LIVE (FASE UNSUBSCRIBE):
 *  - El primer GET del enlace NO ejecuta la baja: solo muestra una página de
 *    confirmación clara y sin ambigüedad.
 *  - La baja efectiva SOLO se ejecuta mediante un POST explícito (CONFIRMAR BAJA).
 *  - El motivo de baja es OPCIONAL y nunca una condición para completar la baja.
 *  - Idempotente: confirmar dos veces no duplica ni reactiva.
 *  - Identificación segura:
 *      * Nuevo enlace:  baja.php?t=TOKEN   (TOKEN = tracking_id del envío, no expone email)
 *      * Compatibilidad: baja.php?email=EMAIL (enlaces antiguos siguen funcionando)
 *  - Registro CRM: marca estado_lead = 'Lista Negra' (mecanismo de supresión
 *    existente, ya bloqueado por esElegibleParaEnvio) y registra el historial
 *    (fecha, fuente, motivo, campaign_id, envio_id) en observaciones.
 *
 * PHP 8.x nativo — SiteGround compatible. Sin dependencias externas.
 */

declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', 0);

// Ruta de BD configurable para tests locales (si BAJA_DB_PATH está definida).
// En producción se usa la ruta real. No afecta al comportamiento en servidor.
$dbPath = defined('BAJA_DB_PATH') ? BAJA_DB_PATH : __DIR__ . '/../data/stats.db';


// Secreto de firma CSRF (estable por servidor, NO es una credencial SMTP).
// Derivado de la ruta de la BD para no hardcodear un valor arbitrario.
$CSRF_SECRET = hash('sha256', $dbPath . '::futprotec_baja_csrf_v1');

// Estados de supresión que indican que el lead ya está dado de baja.
$estadosBaja = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out'];

/**
 * Resuelve el email y el lead_id a partir del token de baja (tracking_id del envío)
 * o del email directo (compatibilidad hacia atrás).
 *
 * @return array{email:string, lead_id:int, campaign_id:int, envio_id:int}|null
 */
function resolverDestinatario(SQLite3 $db, string $token, string $email): ?array
{
    // 1) Token (nuevo enlace): buscar en envios por tracking_id.
    if ($token !== '') {
        $stmt = $db->prepare(
            "SELECT e.email, e.lead_id, e.campaign_id, e.id AS envio_id
             FROM envios e
             WHERE e.tracking_id = :t
             LIMIT 1"
        );
        $stmt->bindValue(':t', $token, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if ($row && !empty($row['email'])) {
            return [
                'email'       => (string)$row['email'],
                'lead_id'     => (int)($row['lead_id'] ?? 0),
                'campaign_id' => (int)($row['campaign_id'] ?? 0),
                'envio_id'    => (int)($row['envio_id'] ?? 0),
            ];
        }
        return null;
    }

    // 2) Email directo (compatibilidad): validar formato.
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $db->prepare("SELECT id, email FROM clubes_crm WHERE email = :e LIMIT 1");
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $res = $stmt->execute();
        $lead = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if ($lead) {
            return [
                'email'       => (string)$lead['email'],
                'lead_id'     => (int)$lead['id'],
                'campaign_id' => 0,
                'envio_id'    => 0,
            ];
        }
        // Email válido pero no en CRM: permitir registrar baja igualmente (opt-out global).
        return [
            'email'       => $email,
            'lead_id'     => 0,
            'campaign_id' => 0,
            'envio_id'    => 0,
        ];
    }

    return null;

}

/**
 * Ejecuta la baja (opt-out) de forma idempotente.
 * Marca estado_lead = 'Lista Negra' y registra el historial en observaciones.
 * Si el lead ya está dado de baja, no duplica ni reactiva.
 *
 * @return array{ya_baja:bool, registrado:bool}
 */
function ejecutarBaja(SQLite3 $db, array $dest, string $motivo): array
{
    $email = $dest['email'];
    $leadId = $dest['lead_id'];

    if ($leadId > 0) {
        // Comprobar estado actual para idempotencia.
        $estadoActual = (string)$db->querySingle(
            "SELECT estado_lead FROM clubes_crm WHERE id = " . $leadId
        );
        $yaBaja = in_array($estadoActual, ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out'], true);

        if ($yaBaja) {
            // Ya estaba dado de baja: no duplicar. Solo añadir motivo si se informa y no existe.
            if ($motivo !== '') {
                $obs = (string)$db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = " . $leadId);
                if (stripos($obs, 'Motivo baja:') === false) {
                    $nuevaObs = $obs . "\n[BAJA] Motivo baja: " . $motivo;
                    $stmt = $db->prepare("UPDATE clubes_crm SET observaciones = :o WHERE id = :id");
                    $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $leadId, SQLITE3_INTEGER);
                    $stmt->execute();
                }
            }
            return ['ya_baja' => true, 'registrado' => false];
        }

        // Baja efectiva: marcar estado_lead = 'Lista Negra' (mecanismo de supresión existente).
        $fecha = date('Y-m-d H:i:s');
        $fuente = 'email';
        $campaign = $dest['campaign_id'] > 0 ? ' | campaign_id=' . $dest['campaign_id'] : '';
        $envio = $dest['envio_id'] > 0 ? ' | envio_id=' . $dest['envio_id'] : '';
        $motivoTxt = $motivo !== '' ? ' | Motivo baja: ' . $motivo : '';

        $obs = (string)$db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = " . $leadId);
        $nuevaObs = $obs
            . "\n[BAJA] " . $fecha . " | fuente=" . $fuente . $campaign . $envio . $motivoTxt;

        $stmt = $db->prepare(
            "UPDATE clubes_crm
             SET estado_lead = 'Lista Negra',
                 observaciones = :o,
                 ultimo_contacto = :f
             WHERE id = :id"
        );
        $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
        $stmt->bindValue(':f', $fecha, SQLITE3_TEXT);
        $stmt->bindValue(':id', $leadId, SQLITE3_INTEGER);
        $stmt->execute();

        return ['ya_baja' => false, 'registrado' => true];
    }

    // Email no presente en CRM: registrar baja global en observaciones de un lead
    // si existe por email; si no existe, no hay nada que marcar (opt-out implícito).
    $stmtLead = $db->prepare("SELECT id, observaciones FROM clubes_crm WHERE email = :e LIMIT 1");
    $stmtLead->bindValue(':e', $email, SQLITE3_TEXT);
    $resLead = $stmtLead->execute();
    $lead = $resLead ? $resLead->fetchArray(SQLITE3_ASSOC) : false;
    if ($lead) {

        $fecha = date('Y-m-d H:i:s');
        $motivoTxt = $motivo !== '' ? ' | Motivo baja: ' . $motivo : '';
        $nuevaObs = (string)$lead['observaciones']
            . "\n[BAJA] " . $fecha . " | fuente=email" . $motivoTxt;
        $stmt = $db->prepare(
            "UPDATE clubes_crm
             SET estado_lead = 'Lista Negra', observaciones = :o
             WHERE id = :id"
        );
        $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int)$lead['id'], SQLITE3_INTEGER);
        $stmt->execute();
        return ['ya_baja' => false, 'registrado' => true];
    }

    return ['ya_baja' => false, 'registrado' => false];
}

// ─── Identificar destinatario ────────────────────────────────────────────────
$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$email = trim((string)($_GET['email'] ?? $_POST['email'] ?? ''));
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$db = null;
$dest = null;
if (file_exists($dbPath)) {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec('PRAGMA busy_timeout=5000');
    $dest = resolverDestinatario($db, $token, $email);
}

// ─── Estado de la operación ───────────────────────────────────────────────────
$accion = 'confirmacion';   // confirmacion | realizada | motivo_guardado | error
$mensaje = '';
$yaBaja = false;
$motivoGuardado = false;

// POST: confirmar baja (acción explícita).
if ($metodo === 'POST' && ($_POST['accion'] ?? '') === 'confirmar') {
    // Validación CSRF: el token de baja (o email) debe coincidir con el HMAC.
    $csrf = trim((string)($_POST['csrf'] ?? ''));
    $identificador = $token !== '' ? $token : $email;
    $csrfEsperado = hash_hmac('sha256', $identificador, $CSRF_SECRET);

    if ($dest === null) {
        $accion = 'error';
        $mensaje = 'No se pudo identificar la dirección de correo.';
    } elseif (!hash_equals($csrfEsperado, $csrf)) {
        $accion = 'error';
        $mensaje = 'Solicitud no válida. Por favor, vuelve a abrir el enlace de baja.';
    } else {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        $res = ejecutarBaja($db, $dest, $motivo);
        $yaBaja = $res['ya_baja'];
        $motivoGuardado = ($motivo !== '');
        $accion = 'realizada';
        if ($yaBaja) {
            $mensaje = 'Esta dirección ya estaba dada de baja.';
        }
    }
}

// POST: guardar motivo (opcional, tras la baja).
if ($metodo === 'POST' && ($_POST['accion'] ?? '') === 'motivo') {
    $csrf = trim((string)($_POST['csrf'] ?? ''));
    $identificador = $token !== '' ? $token : $email;
    $csrfEsperado = hash_hmac('sha256', $identificador, $CSRF_SECRET);

    if ($dest === null) {
        $accion = 'error';
        $mensaje = 'No se pudo identificar la dirección de correo.';
    } elseif (!hash_equals($csrfEsperado, $csrf)) {
        $accion = 'error';
        $mensaje = 'Solicitud no válida.';
    } else {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if ($motivo !== '') {
            ejecutarBaja($db, $dest, $motivo); // idempotente: solo añade motivo si no existe
            $motivoGuardado = true;
        }
        $accion = 'motivo_guardado';
    }
}

// ─── CSRF para los formularios ────────────────────────────────────────────────
$identificador = $token !== '' ? $token : $email;
$csrfToken = hash_hmac('sha256', $identificador, $CSRF_SECRET);

// ─── HTML ─────────────────────────────────────────────────────────────────────
$emailMostrar = $dest ? htmlspecialchars($dest['email'], ENT_QUOTES, 'UTF-8') : '';
$tokenHidden = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$emailHidden = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$csrfHidden = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

$motivos = [
    'No me interesa el producto',
    'Ya tengo proveedor',
    'No soy la persona adecuada',
    'No trabajamos este tipo de producto',
    'Recibo demasiadas comunicaciones',
    'Otro',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>FutProtec — Gestión de Bajas</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .icono {
            width: 48px; height: 48px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
        }
        .icono-info { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .icono-ok   { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
        .icono-err  { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        h1 { font-size: 20px; font-weight: 600; color: #f8fafc; text-align: center; margin-bottom: 12px; }
        p  { font-size: 14px; line-height: 1.6; color: #cbd5e1; text-align: center; margin-bottom: 16px; }
        .email { color: #93c5fd; font-weight: 600; word-break: break-all; }
        .btn {
            display: block; width: 100%;
            padding: 14px 16px;
            border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; text-align: center;
            text-decoration: none;
            margin-top: 10px;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #334155; color: #e2e8f0; }
        .btn-secondary:hover { background: #475569; }
        .motivo { margin-top: 16px; text-align: left; }
        .motivo label {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            border: 1px solid #334155; border-radius: 8px;
            margin-bottom: 8px; cursor: pointer;
            font-size: 14px; color: #cbd5e1;
        }
        .motivo label:hover { background: #334155; }
        .motivo input[type="radio"] { accent-color: #2563eb; }
        .footer { margin-top: 20px; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #334155; padding-top: 14px; }
        .oculto { display: none; }
    </style>
</head>
<body>
    <div class="card">

    <?php if ($accion === 'confirmacion' && $dest !== null): ?>
        <!-- PASO 1: Confirmación explícita -->
        <div class="icono icono-info">✉️</div>
        <h1>Dejar de recibir comunicaciones</h1>
        <p>
            Has solicitado dejar de recibir comunicaciones comerciales de FutProtec
            para <span class="email"><?php echo $emailMostrar; ?></span>.
        </p>
        <p>¿Quieres confirmar la baja?</p>
        <form method="POST" action="baja.php">
            <input type="hidden" name="t" value="<?php echo $tokenHidden; ?>">
            <input type="hidden" name="email" value="<?php echo $emailHidden; ?>">
            <input type="hidden" name="csrf" value="<?php echo $csrfHidden; ?>">
            <input type="hidden" name="accion" value="confirmar">
            <button type="submit" class="btn btn-primary">CONFIRMAR BAJA</button>
        </form>
        <a href="https://getfutprotec.com" class="btn btn-secondary">Seguir recibiendo</a>

    <?php elseif ($accion === 'realizada'): ?>
        <!-- PASO 2: Baja realizada + motivo opcional -->
        <div class="icono icono-ok">✓</div>
        <h1><?php echo $yaBaja ? 'Ya estabas dado de baja' : 'Baja realizada correctamente'; ?></h1>
        <p>
            <?php if ($yaBaja): ?>
                La dirección <span class="email"><?php echo $emailMostrar; ?></span>
                ya estaba dada de baja de nuestras comunicaciones comerciales.
            <?php else: ?>
                La dirección <span class="email"><?php echo $emailMostrar; ?></span>
                ha sido dada de baja de nuestras comunicaciones comerciales.
            <?php endif; ?>
        </p>
        <?php if (!$motivoGuardado): ?>
            <p>Si quieres, dinos por qué ya no te interesa:</p>
            <form method="POST" action="baja.php">
                <input type="hidden" name="t" value="<?php echo $tokenHidden; ?>">
                <input type="hidden" name="email" value="<?php echo $emailHidden; ?>">
                <input type="hidden" name="csrf" value="<?php echo $csrfHidden; ?>">
                <input type="hidden" name="accion" value="motivo">
                <div class="motivo">
                    <?php foreach ($motivos as $m): ?>
                        <label>
                            <input type="radio" name="motivo" value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary">ENVIAR</button>
                <button type="submit" class="btn btn-secondary" name="motivo" value="">OMITIR</button>
            </form>
        <?php else: ?>
            <p>Gracias por tu respuesta.</p>
        <?php endif; ?>

    <?php elseif ($accion === 'motivo_guardado'): ?>
        <!-- PASO 3: Motivo guardado -->
        <div class="icono icono-ok">✓</div>
        <h1>Baja completada</h1>
        <p>Tu baja ha quedado registrada. No volverás a recibir comunicaciones comerciales de FutProtec.</p>

    <?php else: ?>
        <!-- Error / destinatario no identificado -->
        <div class="icono icono-err">⚠️</div>
        <h1>No se pudo procesar la solicitud</h1>
        <p><?php echo htmlspecialchars($mensaje !== '' ? $mensaje : 'El enlace no es válido o ha caducado.', ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="https://getfutprotec.com" class="btn btn-secondary">Volver a FutProtec</a>
    <?php endif; ?>

        <div class="footer">
            FutProtec — Equipación y Protección Técnica para Fútbol Base
        </div>
    </div>
</body>
</html>
<?php
if ($db !== null) {
    $db->close();
}
