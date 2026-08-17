<?php
/**
 * lead_validate.php — API endpoint para validar (SIN ENVIAR) que un lead es
 * elegible para envío en la campaña seleccionada desde la Lanzadera.
 *
 * Replica EXACTAMENTE la cadena de validación de enviar_lote.php (misma política
 * única: validarCampanaActiva + esElegibleParaEnvio + email válido), pero NO
 * realiza ningún envío SMTP, NO reserva envío lógico y NO toca la BD de envíos.
 *
 * Uso: "Envío dirigido" → el usuario busca un lead, lo selecciona y pulsa
 * "Validar". Este endpoint confirma que el lead pasará la validación del motor
 * antes de pulsar INICIAR LANZADERA.
 *
 * Seguridad:
 *  - Autenticación de sesión existente (auth_outbound).
 *  - Aislamiento TEST/REAL (esElegibleParaEnvio).
 *  - NO envía ningún email.
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── Buffer + Control de errores para JSON limpio ───
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$DB_PATH = __DIR__ . '/../data/stats.db';

if (!file_exists($DB_PATH)) {
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}

// ─── Autenticación (misma sesión que el dashboard) ─────────────────────────
session_start();
if (empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

require_once __DIR__ . '/../inc/eligibilidad.php';
require_once __DIR__ . '/../inc/abc.php';

// ─── PARÁMETROS ──────────────────────────────────────────────────────────────
$idClub     = (int)($_GET['lead_id'] ?? $_GET['id_club'] ?? 0);
$idCampana  = (int)($_GET['campaign_id'] ?? $_GET['id_campana'] ?? 0);

try {
    // ─── Validación de campaña (misma política que enviar_lote.php) ──────────
    $modoEntornoBD = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
    $validacion = validarCampanaActiva($db, $idCampana, $modoEntornoBD);
    if (!$validacion['ok']) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Campaña no válida', 'razon' => $validacion['razon']]);
        exit;
    }

    // ─── Validación del lead (misma política que enviar_lote.php) ────────────
    if ($idClub <= 0) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'lead_id requerido', 'razon' => 'lead_no_valido']);
        exit;
    }

    $club = $db->querySingle(
        "SELECT id, nombre_club, email, federacion, persona_contacto, estado_lead
         FROM clubes_crm WHERE id = " . $idClub,
        true
    );

    if (!$club) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Lead no encontrado (id=' . $idClub . ')', 'razon' => 'lead_no_encontrado']);
        exit;
    }

    if (empty($club['email']) || !filter_var($club['email'], FILTER_VALIDATE_EMAIL)) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Email inválido: ' . ($club['email'] ?? 'vacío'), 'razon' => 'email_invalido']);
        exit;
    }

    // Elegibilidad (supresión + TEST/PILOT + aislamiento TEST/REAL)
    $elig = esElegibleParaEnvio($db, (int)$club['id'], $idCampana);
    if (!$elig['ok']) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Lead no elegible para envío', 'razon' => $elig['razon']]);
        exit;
    }

    // ─── Variante A/B/C determinística (misma que el motor) ──────────────────
    $variante = asignarVariante((int)$club['id'], $idCampana);

    ob_clean();
    echo json_encode([
        'ok'          => true,
        'lead_id'     => (int)$club['id'],
        'nombre_club' => $club['nombre_club'],
        'email'       => $club['email'],
        'federacion'  => $club['federacion'] ?? '',
        'estado_lead' => $club['estado_lead'] ?? '',
        'variante_ab' => $variante,
        'campaign_id' => $idCampana,
        'mensaje'     => 'Lead elegible para envío en la campaña seleccionada',
    ]);

} catch (\Exception $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

$db->close();
