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

// ─── ANTI-CACHÉ (crítico) ────────────────────────────────────────────────────
// La cola es 100% dinámica. El caché dinámico de SiteGround cacheó URLs con
// query string y devolvía colas obsoletas (leads ya enviados volvían a salir).
// Se fuerza revalidación en cada petición (headers estándar de no-cache).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$DB_PATH = __DIR__ . '/../data/stats.db';

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

require_once __DIR__ . '/../inc/eligibilidad.php';
require_once __DIR__ . '/../inc/abc.php';


// ─── PARÁMETROS ──────────────────────────────────────────────────────────────
$estadoLead   = trim($_GET['estado_lead'] ?? '');
$federacion   = trim($_GET['federacion'] ?? '');
$tieneWhatsapp = ($_GET['habilitar_whatsapp'] ?? '0') === '1';
$idTplEmail    = (int)($_GET['id_plantilla_email'] ?? 0);
$idTplWa       = (int)($_GET['id_plantilla_wa'] ?? 0);
$randomMode    = ($_GET['random_mode'] ?? '0') === '1';  // 🎲 anti-detección
$esRotacion    = ($_GET['rotacion'] ?? '0') === '1';     // 🔄 rotación ABC para no abridores
$idCampana     = (int)($_GET['campaign_id'] ?? $_GET['id_campana'] ?? 0);

// Selección manual desde Seguimiento (acciones en lote): lista de IDs exactos.
// Cuando vienen IDs, se ignoran los filtros de estado/federación (el comercial
// seleccionó la lista concreta de leads).
$idsSeleccion = [];
$idsRaw = trim($_GET['ids'] ?? '');
if ($idsRaw !== '') {
    foreach (explode(',', $idsRaw) as $v) {
        $v = (int)$v;
        if ($v > 0) { $idsSeleccion[] = $v; }
    }
}

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
    // EXCLUSIÓN DE SUPRESIÓN (LISTA NEGRA): los leads con estado de supresión
    // (opt-out real o bloqueo manual) NUNCA entran en la cola. Espejo SQL de
    // esElegibleParaEnvio() (inc/eligibilidad.php) para que "pendientes" =
    // candidatos realmente elegibles, no solo los que pasan el filtro de estado.
    $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido', '06 Perdido', '06 Baja/Archivado', '07 Baja', 'Baja'];

    $where = "c.email IS NOT NULL AND c.email != ''
              AND c.es_duplicado = 0
              AND c.estado_lead NOT IN ('" . implode("','", array_map(function ($e) use ($db) {
                  return $db->escapeString($e);
              }, $estadosSupresion)) . "')";


    // ─── 3b. MODO ROTACIÓN ABC (rotacion=1) ───────────────────────────────────
    // Cola preparada para reenviar con la SIGUIENTE variante (A→B→C→A) a los
    // leads que NO abrieron su último email y aún tienen intentos disponibles.
    // Ignora los filtros de estado/federación: la lógica de rotación es la fuente.
    $rotacionInfo = null;
    if ($esRotacion) {
        if ($idCampana <= 0) {
            ob_clean();
            echo json_encode(['ok' => false, 'error' => 'Selecciona una campaña para cargar la rotación ABC.']);
            exit;
        }
        $sec = $db->querySingle("SELECT * FROM secuencias WHERE campaign_id = {$idCampana} AND activo = 1 AND rotar_no_abridores = 1 ORDER BY id DESC LIMIT 1", true);
        if (!$sec) {
            ob_clean();
            echo json_encode(['ok' => false, 'error' => 'No hay secuencia con rotación ABC activa para esta campaña. Actívala en Plantillas y Campañas → Secuencia.']);
            exit;
        }
        $esperaRot = max(1, (int)$sec['rotar_espera_dias']);
        $maxEnviosRot = max(2, (int)$sec['rotar_max_envios']);
        $tplRot = (int)$sec['rotar_plantilla_id'];
        if ($tplRot <= 0) {
            $tplRot = (int)$db->querySingle("SELECT plantilla_id FROM secuencia_pasos WHERE secuencia_id = " . (int)$sec['id'] . " AND paso = 1 AND activo = 1 ORDER BY id LIMIT 1");
        }
        $inSup = "'" . implode("','", array_map(function ($e) use ($db) { return $db->escapeString($e); }, $estadosSupresion)) . "'";

        $sqlRot = "SELECT c.id, c.nombre_club, c.email, c.federacion, c.persona_contacto, c.telefono_movil, c.tiene_whatsapp, c.telefono_fijo,
                          (SELECT COUNT(*) FROM envios e WHERE e.lead_id = c.id AND e.campaign_id = {$idCampana} AND COALESCE(e.es_test,0) = 0 AND e.estado IN ('enviado','abierto')) AS n_envios
                   FROM clubes_crm c
                   WHERE c.id IN (
                           SELECT e.lead_id FROM envios e
                           WHERE e.campaign_id = {$idCampana} AND COALESCE(e.es_test,0) = 0 AND e.estado IN ('enviado','abierto')
                             AND e.lead_id IS NOT NULL
                       )
                     AND c.email IS NOT NULL AND c.email != ''
                     AND c.es_duplicado = 0
                     AND c.estado_lead NOT IN ({$inSup})
                     AND NOT EXISTS (SELECT 1 FROM respuestas r WHERE r.lead_id = c.id)
                     AND NOT EXISTS (SELECT 1 FROM aperturas a
                                     WHERE a.tracking_id IN (SELECT e2.tracking_id FROM envios e2 WHERE e2.lead_id = c.id AND e2.campaign_id = {$idCampana}))
                     AND (SELECT COUNT(*) FROM envios e3 WHERE e3.lead_id = c.id AND e3.campaign_id = {$idCampana} AND COALESCE(e3.es_test,0) = 0 AND e3.estado IN ('enviado','abierto')) < {$maxEnviosRot}
                     AND (SELECT MAX(e4.fecha_envio) FROM envios e4 WHERE e4.lead_id = c.id AND e4.campaign_id = {$idCampana} AND COALESCE(e4.es_test,0) = 0 AND e4.estado IN ('enviado','abierto')) <= datetime('now', '-{$esperaRot} days')
                   ORDER BY c.nombre_club ASC";

        $leads = [];
        $resRot = $db->query($sqlRot);
        while ($l = $resRot->fetchArray(SQLITE3_ASSOC)) {
            $ultVar = $db->querySingle("SELECT variant FROM envios WHERE lead_id = " . (int)$l['id'] . " AND campaign_id = {$idCampana} AND es_rotacion = 1 ORDER BY id DESC LIMIT 1");
            if (!$ultVar) $ultVar = asignarVariante((int)$l['id'], $idCampana);
            $l['variante_anterior'] = strtoupper((string)$ultVar);
            $l['variante_siguiente'] = siguienteVariante($l['variante_anterior']);
            $l['intento'] = (int)$l['n_envios'] + 1;
            $l['rotacion_plantilla_id'] = $tplRot;
            $leads[] = $l;
        }
        $rotacionInfo = [
            'secuencia_id'   => (int)$sec['id'],
            'secuencia_nombre' => $sec['nombre'],
            'espera_dias'    => $esperaRot,
            'max_envios'     => $maxEnviosRot,
            'plantilla_id'   => $tplRot,
            'plantilla_nombre' => $tplRot > 0 ? ($db->querySingle("SELECT nombre FROM plantillas WHERE id = {$tplRot}") ?: '') : '',
        ];
        $estadoLead = '';
        $federacion = '';
    } else {


    // AISLAMIENTO TEST/REAL (FASE 6F.6): si se filtra por campaña, la cola solo
    // devuelve leads compatibles (campaña TEST → sólo leads TEST; campaña no TEST
    // → nunca leads TEST). Filtrado en servidor/SQL, no confiar en JS.
    if ($idCampana > 0 && empty($idsSeleccion) && $db->querySingle("SELECT COUNT(*) FROM pipelines WHERE id = " . $idCampana) > 0) {
        $where .= sqlFiltroCompatibilidadLeadCampana($db, $idCampana);
    }

    // Selección manual (Seguimiento → acciones en lote): si vienen IDs exactos,
    // se fuerzan y se ignoran los filtros de estado/federación/campaña
    // (la lista manual es la autoridad; la campaña se elige en el envío).
    if (!empty($idsSeleccion)) {
        $where .= " AND c.id IN (" . implode(',', $idsSeleccion) . ")";
        $estadoLead = '';
        $federacion = '';
        $idCampana = 0;
    }

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
    } // fin else — modo cola normal (no rotación)

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
        // SEGURIDAD: NO se exponen smtp_usuario ni smtp_password al frontend.
        // El envío se realiza server-side en enviar_lote.php, que resuelve la
        // cuenta SMTP por id_cuenta_smtp desde la BD. El navegador solo necesita
        // el id (smtp_asignada_id) para el envío; las credenciales son sensibles
        // y nunca deben salir del servidor.
        $lead['smtp_asignada_email'] = $cuentaAsignada['email'];
        $lead['smtp_asignada_id']    = $cuentaAsignada['id'];
        $lead['smtp_host']           = $cuentaAsignada['host'];
        $lead['smtp_puerto']         = $cuentaAsignada['puerto'];
        $lead['smtp_seguridad']      = $cuentaAsignada['seguridad'];
        $lead['smtp_enviados_hoy']   = $usoHoy[$cuentaAsignada['id']] ?? 0;
        $lead['smtp_limite']         = (int)$cuentaAsignada['limite_diario'];

        // Variante A/B/C determinística (FASE 3) calculada server-side con la
        // función real asignarVariante(). Solo cuando hay campaña; si no, null.
        // Permite a la lanzadera seleccionar leads que cubran A/B/C sin duplicar
        // la lógica de asignación en JavaScript.
        $lead['variante_ab'] = $esRotacion
            ? ($lead['variante_siguiente'] ?? 'A')
            : ($idCampana > 0 ? asignarVariante((int)$lead['id'], $idCampana) : null);
        $lead['es_rotacion'] = $esRotacion ? 1 : 0;

        $cola[] = $lead;

    }

    // ─── 5. Calcular hora estimada ────────────────────────────────────────────
    // Rango de retardo aleatorio (lanzadera_delay_min/max) con fallback al valor
    // histórico lanzadera_delay (retardo fijo). La hora estimada usa la MEDIA.
    $delayMin = (int)($db->querySingle("SELECT valor FROM config WHERE clave = 'lanzadera_delay_min'") ?: 0);
    $delayMax = (int)($db->querySingle("SELECT valor FROM config WHERE clave = 'lanzadera_delay_max'") ?: 0);
    $delayLegacy = (int)($db->querySingle("SELECT valor FROM config WHERE clave = 'lanzadera_delay'") ?: 5);
    if ($delayMin <= 0 || $delayMax <= 0 || $delayMin > $delayMax) {
        $delayMin = max(1, $delayLegacy);
        $delayMax = max($delayMin, $delayLegacy);
    }
    $delay = (int)round(($delayMin + $delayMax) / 2);
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
        'delay_min_segundos' => $delayMin,
        'delay_max_segundos' => $delayMax,
        'kpi_clubes'      => $totalClubes,
        'kpi_smtp_activas'=> $smtpActivas,
        'kpi_envios_hoy'  => $enviosHoyKpi,
        'federaciones'    => obtenerFederacionesUnicas($db),
        'categorias'      => obtenerCategoriasPlantillas($db),
        'rotacion'        => $rotacionInfo,
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
        '01 Sin Contactar'   => '01 Sin Contactar',
        '02 Contactado'      => '02 Contactado',
        '03 En Conversación' => '03 En Conversación',
        '04 Propuesta'       => '04 Propuesta',
        '05 Ganado'          => '05 Ganado',
        '06 Perdido'         => '06 Perdido',
        '07 Baja'            => '07 Baja',
    ];
    return $mapa[$codigo] ?? $codigo;
}

