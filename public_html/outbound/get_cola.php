<?php
/**
 * get_cola.php — API endpoint para generar la cola de envíos de la lanzadera.
 * Acepta filtros (id_categoria, federacion) y asigna cuentas SMTP round-robin.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── Buffer + Control de errores para JSON limpio ───
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$DB_PATH = __DIR__ . '/stats.db';

if (!file_exists($DB_PATH)) {
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

// ─── PARÁMETROS ──────────────────────────────────────────────────────────────
$estadoLead   = trim($_GET['estado_lead'] ?? '');
$federacion   = trim($_GET['federacion'] ?? '');
$tieneWhatsapp = ($_GET['habilitar_whatsapp'] ?? '0') === '1';
$idTplEmail    = (int)($_GET['id_plantilla_email'] ?? 0);
$idTplWa       = (int)($_GET['id_plantilla_wa'] ?? 0);
$randomMode    = ($_GET['random_mode'] ?? '0') === '1';  // 🎲 anti-detección

// ─── VALIDAR DATOS DE ENTRADA ────────────────────────────────────────────────
try {
    // ─── 1. Obtener cuentas SMTP activas ──────────────────────────────────────
    $cuentas = [];
    $resCuentas = $db->query("
        SELECT id, email, usuario, password, host, puerto, seguridad, enviados_hoy, limite_diario, activa, ultimo_error
        FROM cuentas_smtp
        WHERE activa = 1
        ORDER BY email ASC
    ");
    while ($r = $resCuentas->fetchArray(SQLITE3_ASSOC)) {
        $cuentas[] = $r;
    }

    if (empty($cuentas)) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No hay cuentas SMTP activas']);
        exit;
    }

    // ─── 2. Obtener plantillas ─────────────────────────────────────────────────
    $plantillaEmail = null;
    $plantillaWa = null;

    if ($idTplEmail > 0) {
        $plantillaEmail = $db->querySingle("
            SELECT id, nombre, asunto, cuerpo, tipo
            FROM plantillas WHERE id = {$idTplEmail} AND activo = 1
        ", true);
    }
    if ($idTplWa > 0) {
        $plantillaWa = $db->querySingle("
            SELECT id, nombre, asunto, cuerpo, tipo
            FROM plantillas WHERE id = {$idTplWa} AND activo = 1 AND tipo = 'whatsapp'
        ", true);
    }

    // ─── 3. Consultar leads según el estado seleccionado ──────────────────────
    $where = "c.email IS NOT NULL AND c.email != ''
              AND c.es_duplicado = 0";

    if ($estadoLead !== '') {
        // Mapear el estado del lead codificado (01, 02...) al nombre real en BD
        $estadoReal = mapearEstadoLead($estadoLead);
        $where .= " AND c.estado_lead = '" . $db->escapeString($estadoReal) . "'";
    }

    if ($federacion !== '') {
        $where .= " AND c.federacion = '" . $db->escapeString($federacion) . "'";
    }

    $sql = "SELECT c.id, c.nombre_club, c.email, c.federacion, c.persona_contacto,
                   c.telefono_movil, c.tiene_whatsapp, c.telefono_fijo
            FROM clubes_crm c
            WHERE {$where}
            ORDER BY c.nombre_club ASC";

    $res = $db->query($sql);
    $leads = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $leads[] = $r;
    }

    // ─── 4. Asignación SMTP (Round Robin o Aleatoria según 🎲) ──────────────────
    $cuentaIndex = 0;
    $numCuentas = count($cuentas);
    $cola = [];

    // 🎲 Si modo aleatorio activo: shuffle de cuentas y leads para patrón impredecible
    if ($randomMode) {
        shuffle($cuentas);
        shuffle($leads);
    }

    // Estadísticas de uso hoy por cuenta
    $usoHoy = [];
    foreach ($cuentas as $c) {
        $enviadosHoy = (int)$db->querySingle("
            SELECT COUNT(*) FROM comunicaciones_log
            WHERE id_cuenta_smtp = {$c['id']}
              AND DATE(fecha) = DATE('now')
              AND tipo_evento = 'envio_email'
        ");
        $usoHoy[$c['id']] = $enviadosHoy;
    }

    foreach ($leads as $lead) {
        // Buscar una cuenta disponible (no saturada)
        $cuentaAsignada = null;
        $intentos = 0;

        if ($randomMode) {
            // 🎲 Modo aleatorio: barajar cuentas de nuevo para cada lead
            $cuentasShuffled = $cuentas;
            shuffle($cuentasShuffled);
            foreach ($cuentasShuffled as $candidata) {
                $enviadosHoy = $usoHoy[$candidata['id']] ?? 0;
                $limite = (int)$candidata['limite_diario'];
                if ($enviadosHoy < $limite && (int)$candidata['activa'] === 1) {
                    $cuentaAsignada = $candidata;
                    $usoHoy[$candidata['id']] = $enviadosHoy + 1;
                    break;
                }
            }
        } else {
            // Modo normal: round-robin secuencial
            while ($intentos < $numCuentas) {
                $candidata = $cuentas[$cuentaIndex % $numCuentas];
                $enviadosHoy = $usoHoy[$candidata['id']] ?? 0;
                $limite = (int)$candidata['limite_diario'];

                if ($enviadosHoy < $limite && (int)$candidata['activa'] === 1) {
                    $cuentaAsignada = $candidata;
                    $cuentaIndex = ($cuentaIndex + 1) % $numCuentas;
                    $usoHoy[$candidata['id']] = $enviadosHoy + 1;
                    break;
                }
                $cuentaIndex = ($cuentaIndex + 1) % $numCuentas;
                $intentos++;
            }
        }

        if ($cuentaAsignada === null) {
            // Todas las cuentas saturadas — no se puede seguir
            break;
        }

        // Calcular hora estimada (se hará en frontend, pero damos posición)
        $lead['smtp_asignada_email'] = $cuentaAsignada['email'];
        $lead['smtp_asignada_id']    = $cuentaAsignada['id'];
        $lead['smtp_usuario']        = $cuentaAsignada['usuario'];
        $lead['smtp_password']       = $cuentaAsignada['password'];
        $lead['smtp_host']           = $cuentaAsignada['host'];
        $lead['smtp_puerto']         = $cuentaAsignada['puerto'];
        $lead['smtp_seguridad']      = $cuentaAsignada['seguridad'];
        $lead['smtp_enviados_hoy']   = $usoHoy[$cuentaAsignada['id']] ?? 0;
        $lead['smtp_limite']         = (int)$cuentaAsignada['limite_diario'];

        $cola[] = $lead;
    }

    // ─── 5. Calcular hora estimada ────────────────────────────────────────────
    $delay = (int)($db->querySingle("SELECT valor FROM config WHERE clave = 'lanzadera_delay'") ?: 5);
    $horaBase = time();
    foreach ($cola as $i => &$item) {
        $item['posicion'] = $i + 1;
        $item['hora_estimada'] = date('H:i:s', $horaBase + ($i * $delay));
    }
    unset($item);

    // ─── 6. Resumen SMTP ──────────────────────────────────────────────────────
    $resumenSmtp = [];
    foreach ($cuentas as $c) {
        $hoy = (int)$db->querySingle("
            SELECT COUNT(*) FROM comunicaciones_log
            WHERE id_cuenta_smtp = {$c['id']}
              AND DATE(fecha) = DATE('now')
              AND tipo_evento = 'envio_email'
        ");
        $resumenSmtp[] = [
            'id'            => $c['id'],
            'email'         => $c['email'],
            'host'          => $c['host'],
            'puerto'        => $c['puerto'],
            'enviados_hoy'  => $hoy,
            'limite_diario' => (int)$c['limite_diario'],
            'activa'        => (int)$c['activa'],
            'ultimo_error'  => $c['ultimo_error'] ?? '',
        ];
    }

    // ─── 7. KPIs ──────────────────────────────────────────────────────────────
    $totalClubes = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");
    $smtpActivas  = count($cuentas);
    $enviosHoyKpi = (int)$db->querySingle("
        SELECT COUNT(*) FROM comunicaciones_log
        WHERE DATE(fecha) = DATE('now') AND tipo_evento = 'envio_email'
    ");

    ob_clean();
    echo json_encode([
        'ok'              => true,
        'cola'            => $cola,
        'total_cola'      => count($cola),
        'cuentas_smtp'    => $resumenSmtp,
        'plantilla_email' => $plantillaEmail,
        'plantilla_wa'    => $plantillaWa,
        'delay_segundos'  => $delay,
        'kpi_clubes'      => $totalClubes,
        'kpi_smtp_activas'=> $smtpActivas,
        'kpi_envios_hoy'  => $enviosHoyKpi,
        'federaciones'    => obtenerFederacionesUnicas($db),
        'categorias'      => obtenerCategoriasPlantillas($db),
    ]);

} catch (\Exception $e) {
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

$db->close();

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function obtenerFederacionesUnicas(SQLite3 $db): array
{
    $feds = [];
    $res = $db->query("SELECT DISTINCT federacion FROM clubes_crm WHERE email IS NOT NULL AND email != '' AND federacion != '' ORDER BY federacion ASC");
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        $feds[] = $r[0];
    }
    return $feds;
}

function obtenerCategoriasPlantillas(SQLite3 $db): array
{
    $cats = [];
    $res = $db->query("SELECT DISTINCT categoria FROM plantillas WHERE activo = 1 AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        $cats[] = $r[0];
    }
    return $cats;
}

/**
 * Mapea el código de estado del lead del frontend (ej: "01 Sin Contactar")
 * al valor real de estado_lead en la tabla clubes_crm (ej: "Sin Contactar").
 */
function mapearEstadoLead(string $codigo): string
{
    $mapa = [
        '01 Sin Contactar'          => 'Sin Contactar',
        '02 Email/WhatsApp Enviado'  => 'Email Enviado / En Secuencia',
        '03 Email Abierto'           => 'Impactado / Abrio Email',
        '04 En Conversacion'         => 'En Conversacion / WhatsApp',
        '05 Propuesta Enviada'       => 'Muestra / Propuesta Enviada',
        '06 Cerrado Ganado'          => 'Cerrado Ganado',
        '07 Cerrado Perdido'         => 'Cerrado Perdido',
    ];
    return $mapa[$codigo] ?? $codigo;
}
