<?php
/**
 * lead_search.php — API endpoint para el "Envío dirigido" de la Lanzadera.
 * Busca leads por nombre de club, email o lead_id.
 *
 * Seguridad:
 *  - Autenticación de sesión existente (auth_outbound).
 *  - Aislamiento TEST/REAL según campaña (sqlFiltroCompatibilidadLeadCampana).
 *  - Devuelve SOLO campos necesarios para búsqueda (nunca credenciales SMTP).
 *  - Límite razonable de resultados (máx. 20).
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

// ─── PARÁMETROS ──────────────────────────────────────────────────────────────
$q          = trim($_GET['q'] ?? '');
$leadId     = (int)($_GET['lead_id'] ?? 0);
$idCampana  = (int)($_GET['campaign_id'] ?? 0);
$maxResults = 20;

try {
    // ─── Construcción de la consulta ─────────────────────────────────────────
    $where = "c.email IS NOT NULL AND c.email != '' AND c.es_duplicado = 0";

    // Aislamiento TEST/REAL (FASE 6F.6): si hay campaña, solo leads compatibles.
    if ($idCampana > 0 && $db->querySingle("SELECT COUNT(*) FROM pipelines WHERE id = " . $idCampana) > 0) {
        $where .= sqlFiltroCompatibilidadLeadCampana($db, $idCampana);
    }

    // Búsqueda por lead_id (prioridad absoluta si se indica)
    if ($leadId > 0) {
        $where .= " AND c.id = " . $leadId;
    } elseif ($q !== '') {
        // Búsqueda por nombre de club o email (LIKE, case-insensitive en SQLite ASCII)
        $like = '%' . $db->escapeString($q) . '%';
        $where .= " AND (c.nombre_club LIKE '" . $like . "' OR c.email LIKE '" . $like . "')";
    } else {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Parámetro q o lead_id requerido']);
        exit;
    }

    $sql = "SELECT c.id, c.nombre_club, c.email, c.federacion, c.estado_lead
            FROM clubes_crm c
            WHERE {$where}
            ORDER BY c.nombre_club ASC
            LIMIT " . $maxResults;

    $res = $db->query($sql);
    $leads = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        // es_test: derivado de la definición centralizada (esLeadTest)
        $leads[] = [
            'id'           => (int)$r['id'],
            'nombre_club'  => $r['nombre_club'],
            'email'        => $r['email'],
            'federacion'   => $r['federacion'] ?? '',
            'estado_lead'  => $r['estado_lead'] ?? '',
            'es_test'      => esLeadTest($r),
        ];
    }

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'results' => $leads,
        'total'   => count($leads),
        'limit'   => $maxResults,
    ]);

} catch (\Exception $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

$db->close();
