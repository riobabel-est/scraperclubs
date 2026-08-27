<?php
/**
 * eligibilidad.php — Regla central de elegibilidad de envío (FASE 2B).
 * Única fuente de verdad para supresión/elegibilidad, reutilizable por los
 * motores operativos (P1 api/enviar_lote.php y P3 cli/cron.php).
 * PHP 8.x nativo — SiteGround compatible. Sin dependencias externas.
 */

declare(strict_types=1);

require_once __DIR__ . '/abc.php';
require_once __DIR__ . '/respuestas.php';

/**
 * DEFINICIÓN DE LEAD TEST (única fuente de verdad — FASE 6F.6).
 *
 * Un lead se considera TEST si cumple AL MENOS UNA de estas condiciones ya
 * existentes en el proyecto (no se inventa ningún criterio nuevo):
 *   - su `email` contiene "@futprotec.local" (comparación case-insensitive), y/o
 *   - su `nombre_club` empieza por "test" (comparación case-insensitive).
 *
 * Cualquier otro lead se considera REAL.
 *
 * @param array $lead fila de clubes_crm (debe incluir `email` y `nombre_club`)
 */
function esLeadTest(array $lead): bool
{
    $emailLower  = strtolower((string)($lead['email'] ?? ''));
    $nombreLower = mb_strtolower((string)($lead['nombre_club'] ?? ''), 'UTF-8');

    if ($emailLower !== '' && str_contains($emailLower, '@futprotec.local')) {
        return true;
    }
    if ($nombreLower !== '' && str_starts_with($nombreLower, 'test')) {
        return true;
    }
    return false;
}

/**
 * Acceso a datos: entorno de una campaña (refactor §6.3).
 * Devuelve `pipelines.entorno` o '' si no existe / id inválido.
 */
function getEntornoCampana(SQLite3 $db, int $idCampana): string
{
    if ($idCampana <= 0) {
        return '';
    }
    return (string)$db->querySingle(
        "SELECT entorno FROM pipelines WHERE id = " . $idCampana
    );
}

/**
 * DEFINICIÓN DE CAMPAÑA TEST (FASE 6F.6).
 *
 * Una campaña es TEST cuando `pipelines.entorno` = 'test' (case-insensitive).
 * Se consulta la BD (no se confía en valores pasados por el cliente).
 */
function esCampanaTest(SQLite3 $db, int $idCampana): bool
{
    return strtolower(getEntornoCampana($db, $idCampana)) === 'test';
}

/**
 * DEFINICIÓN DE ENVÍO TEST (única fuente de verdad — AISLAMIENTO TEST/REAL).
 *
 * Un envío se considera TEST si cumple AL MENOS UNA de estas condiciones:
 *   - `envios.es_test = 1` (marca inequívoca, fuente de verdad primaria), y/o
 *   - su `email` contiene "@futprotec.local" (comparación case-insensitive), y/o
 *   - su `club` empieza por "test" (comparación case-insensitive).
 *
 * La marca `es_test` es la fuente primaria. Los criterios de email/club actúan
 * como red de seguridad para filas legacy que aún no tengan la marca (p. ej.
 * antes de la migración), garantizando que NUNCA se mezcle un TEST con el
 * histórico comercial.
 *
 * @param array $envio fila de envios (debe incluir `es_test`, `email` y `club`)
 */
function esEnvioTest(array $envio): bool
{
    // Fuente primaria: marca inequívoca.
    if ((int)($envio['es_test'] ?? 0) === 1) {
        return true;
    }

    // Red de seguridad legacy (espejo de esLeadTest()).
    $emailLower = strtolower((string)($envio['email'] ?? ''));
    $clubLower  = mb_strtolower((string)($envio['club'] ?? ''), 'UTF-8');

    if ($emailLower !== '' && str_contains($emailLower, '@futprotec.local')) {
        return true;
    }
    if ($clubLower !== '' && str_starts_with($clubLower, 'test')) {
        return true;
    }
    return false;
}

/**
 * Fragmento SQL (WHERE) que restringe las consultas de ESTADÍSTICAS COMERCIALES
 * a los envíos REALES (excluye TEST). Es la regla central equivalente a
 * `es_test = 0` para todas las consultas de analytics/followups/envios/aperturas.
 *
 * Uso: añadir a la cláusula WHERE de cualquier consulta comercial.
 *   $sql = "SELECT ... FROM envios e WHERE 1=1 " . sqlFiltroComercial('e');
 *
 * @param string $alias alias de la tabla `envios` en la consulta (default 'e')
 */
function sqlFiltroComercial(string $alias = 'e'): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return " AND COALESCE({$a}es_test, 0) = 0";
}


/**
 * Fragmento SQL (WHERE) que restringe los leads de la tabla `clubes_crm`
 * (alias `c`) a los COMPATIBLES con la campaña indicada.
 *
 *  - campaña TEST    → solo devuelve leads TEST
 *  - campaña NO TEST → solo devuelve leads REALES (excluye leads TEST)
 *
 * La definición de lead TEST en SQL es el espejo exacto de esLeadTest():
 *   email LIKE '%@futprotec.local%'  OR  nombre_club LIKE 'test%'
 * (SQLite, sin collation, es case-insensitive para ASCII; los criterios usan ASCII).
 *
 * Reutilizado por get_cola.php y cron.php para que la compatibilidad se aplique
 * en servidor/SQL, nunca solo en JavaScript.
 */
function sqlFiltroCompatibilidadLeadCampana(SQLite3 $db, int $idCampana): string
{
    if (esCampanaTest($db, $idCampana)) {
        return " AND (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
    }
    return " AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
}

/**
 * Condición SQL de pertenencia de un lead a una campaña según sus SEGMENTOS
 * (campaign_segmentos) + los ya vinculados por lead_pipelines/envíos.
 *  - 'todas'      → la campaña cubre todo el universo de leads → devuelve '1=1'.
 *  - 'federacion' → cubre federaciones concretas (incluye los pendientes de contactar).
 * @param string $alias Alias de la tabla clubes_crm en la query ('' si no hay).
 * @return string Expresión SQL SIN prefijo "AND" (lista para usar en WHERE).
 */
function condicionCampanaLeads(SQLite3 $db, int $campanaId, string $alias = 'c'): string
{
    if ($campanaId <= 0) {
        return '1=1';
    }
    $pre = $alias !== '' ? $alias . '.' : '';
    $base = "({$pre}id IN (SELECT lp.lead_id FROM lead_pipelines lp WHERE lp.pipeline_id = {$campanaId}
             UNION SELECT c2.id FROM clubes_crm c2 JOIN envios e ON LOWER(e.email) = LOWER(c2.email)
             WHERE e.campaign_id = {$campanaId} AND COALESCE(e.es_test,0) = 0))";

    $feds = [];
    $todas = false;
    $res = $db->query("SELECT tipo, valor FROM campaign_segmentos WHERE campaign_id = {$campanaId}");
    if ($res) {
        while ($sg = $res->fetchArray(SQLITE3_ASSOC)) {
            $tipo = (string)($sg['tipo'] ?? '');
            if ($tipo === 'todas') {
                $todas = true;
            } elseif ($tipo === 'federacion' && trim((string)($sg['valor'] ?? '')) !== '') {
                $feds[] = trim((string)$sg['valor']);
            }
        }
    }

    if ($todas) {
        // La campaña cubre todo el universo de leads → sin restricción.
        return '1=1';
    }
    if (!empty($feds)) {
        // Leads de las federaciones configuradas (incluye pendientes de contacto)
        // + los ya vinculados por lead_pipelines/envíos.
        $inFeds = "'" . implode("','", array_map(fn($f) => $db->escapeString($f), $feds)) . "'";
        return "({$base} OR {$pre}federacion IN ({$inFeds}))";
    }
    // Sin segmentos configurados → solo los ya vinculados a la campaña.
    return $base;
}

/**
 * Filtro SQL del KANBAN por campaña (wrapper de condicionCampanaLeads con alias 'c').
 * Devuelve un fragmento SQL (posiblemente vacío) para insertar en un WHERE.
 */
function sqlFiltroKanbanPorCampana(SQLite3 $db, int $campanaId): string
{
    $c = condicionCampanaLeads($db, $campanaId, 'c');
    return ($c === '1=1') ? '' : ' AND ' . $c;
}

/**
 * Acceso a datos: lead con los campos mínimos para evaluar elegibilidad
 * (refactor §6.3).
 */
function getLeadParaElegibilidad(SQLite3 $db, int $leadId): ?array
{
    if ($leadId <= 0) {
        return null;
    }
    $lead = $db->querySingle(
        "SELECT id, email, estado_lead, es_duplicado, nombre_club
         FROM clubes_crm WHERE id = " . $leadId,
        true
    );
    return $lead ?: null;
}

/**
 * Lógica pura: ¿el estado del lead es de supresión/baja? (refactor §6.3).
 * Modelo de 7 columnas (V5.1):
 *   '06 Perdido' → mala venta manual (No interesa / Rechazó propuesta / Otro)
 *   '07 Baja'    → baja automática de campaña (opt-out/unsubscribe automático)
 * Se mantienen además los estados legacy de supresión para no romper datos históricos.
 */
function esEstadoSupresion(string $estadoLead): bool
{
    return in_array($estadoLead, [
        'Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido',
        '06 Perdido', '06 Baja/Archivado', 'Baja/Archivado', '07 Baja', 'Baja'
    ], true);
}

/**
 * ¿Puede este lead recibir este envío?
 *
 * Bloques (servidor, no confiar en JS):
 *  - lead inexistente o id inválido
 *  - supresión (Lista Negra y equivalentes de baja)
 *  - lead marcado como duplicado
 *  - email inválido
 *  - AISLAMIENTO TEST/REAL (FASE 6F.6, regla SIMÉTRICA):
 *      CAMPAÑA TEST + LEAD REAL     → bloqueado (lead_real_en_campana_test)
 *      CAMPAÑA NO TEST + LEAD TEST  → bloqueado (lead_test_en_campana_no_test)
 *
 * @param SQLite3 $db          conexión abierta (con enableExceptions)
 * @param int     $leadId      clubes_crm.id
 * @param int     $campaignId  pipelines.id (0 = sin campaña)
 * @return array{ok:bool, razon:string}
 */
function esElegibleParaEnvio(SQLite3 $db, int $leadId, int $campaignId = 0): array
{
    if ($leadId <= 0) {
        return ['ok' => false, 'razon' => 'lead_no_valido'];
    }

    $lead = getLeadParaElegibilidad($db, $leadId);
    if (!$lead) {
        return ['ok' => false, 'razon' => 'lead_no_encontrado'];
    }

    if (esEstadoSupresion((string)($lead['estado_lead'] ?? ''))) {
        return ['ok' => false, 'razon' => 'supresion'];
    }

    if ((int)($lead['es_duplicado'] ?? 0) === 1) {
        return ['ok' => false, 'razon' => 'duplicado'];
    }

    if (empty($lead['email']) || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'razon' => 'email_invalido'];
    }

    // AISLAMIENTO TEST/REAL (FASE 6F.6) — regla SIMÉTRICA.
    // Se comprueba ANTES del envío y usa las definiciones centralizadas
    // esCampanaTest() / esLeadTest(). No sustituye las validaciones previas.
    if ($campaignId > 0) {
        $campanaTest = esCampanaTest($db, $campaignId);
        $leadTest    = esLeadTest($lead);

        if ($campanaTest && !$leadTest) {
            return ['ok' => false, 'razon' => 'lead_real_en_campana_test'];
        }
        if (!$campanaTest && $leadTest) {
            return ['ok' => false, 'razon' => 'lead_test_en_campana_no_test'];
        }
    }

    return ['ok' => true, 'razon' => 'elegible'];
}

/**
 * Acceso a datos: nº de envíos de una plantilla en campañas PILOT/ACTIVE
 * (refactor §6.3).
 */
function getEnviosPlantillaEnCampanasActivas(SQLite3 $db, int $plantillaId): int
{
    if ($plantillaId <= 0) {
        return 0;
    }
    return (int)$db->querySingle(
        "SELECT COUNT(*)
         FROM envios e
         JOIN pipelines p ON p.id = e.campaign_id
         WHERE e.plantilla_id = {$plantillaId}
           AND UPPER(p.estado) IN ('PILOT','ACTIVE')"
    );
}

/**
 * Congelación mínima de plantillas (FASE 3A, AJUSTE 2).
 *
 * Una plantilla usada por una campaña en PILOT/ACTIVE no debe sobrescribirse:
 * si existe algún envío cuyo `plantilla_id` apunta a esta plantilla y su campaña
 * está en PILOT o ACTIVE, la plantilla se considera CONGELADA.
 *
 * Esto garantiza consistencia de la campaña sin construir un sistema de versionado.
 * El snapshot de `envios` sigue siendo la fuente histórica del mensaje; esta regla
 * es una capa independiente que evita mezclar dos contenidos bajo el mismo plantilla_id.
 */
function plantillaEstaCongelada(SQLite3 $db, int $plantillaId): bool
{
    return getEnviosPlantillaEnCampanasActivas($db, $plantillaId) > 0;
}

/**
 * Reserva el "envío lógico" para (lead, campaña) ANTES de intentar SMTP.
 *
 * Idempotencia por índice único parcial idx_envios_lead_campaign: un lead tiene
 * como máximo UN envío BASE (es_rotacion=0) por campaña, sin bloquear el intento
 * SMTP. La ROTACIÓN ABC (es_rotacion=1) NO está sujeta a esa unicidad: permite
 * múltiples filas (una por intento tras cada ventana de espera de la secuencia).
 *
 * Estrategia (distingue envío lógico / intento SMTP / aceptación / error):
 *  - campaignId <= 0: sin restricción de idempotencia (nuevo envío directo).
 *  - campaignId > 0:  INSERT OR IGNORE en estado provisional 'pendiente'.
 *      * nuevo = true  → el llamador hace SMTP y actualiza estado (enviado/error).
 *      * nuevo = false → ya existe (solo aplica al envío base); el llamador NO
 *                        debe crear otro envío lógico.
 *
 * Estados finales (no reenviar): 'enviado', 'abierto'.
 * Estados retryables (permiten reintento sobre la MISMA fila): 'pendiente', 'error'.
 *
 * @return array{id:int, nuevo:bool, estado:string}
 */
function reservarEnvioLogico(
    SQLite3 $db,
    int $leadId,
    int $campaignId,
    string $club,
    string $email,
    string $federacion,
    string $cuentaEmision,
    string $trackingId,
    string $asunto,
    string $cuerpo,
    ?string $variant = null,
    ?int $plantillaId = null,
    ?int $smtpId = null,
    int $esTest = 0,
    int $esRotacion = 0
): array {
    // INMUTABILIDAD: para campaña real la variante es determinística e
    // independiente del valor que pase el llamador (impide random por envío).
    // EXCEPCIÓN: envíos de ROTACIÓN ABC (es_rotacion=1) — la variante rotada la
    // calcula el sistema (A→B→C→A) y debe respetarse tal cual.
    if ($campaignId > 0 && $esRotacion === 0) {
        $variant = asignarVariante($leadId, $campaignId);
    }

    // Message-ID estable derivado del tracking_id (un retry produce el mismo).
    $messageId = generarMessageIdEnvio($trackingId, $cuentaEmision);

    if ($campaignId > 0) {
        // Reserva idempotente: solo una fila (lead_id, campaign_id, es_rotacion)
        // con campaign no nulo (el índice único incluye es_rotacion, por lo que
        // el envío base y el de rotación conviven sin colisionar).
        insertarEnvioLogico($db, $club, $email, $federacion, $cuentaEmision, $trackingId,
            $asunto, $cuerpo, $leadId, $campaignId, $variant, $plantillaId, $smtpId, $messageId, $esTest, true, $esRotacion);
        if ($db->changes() > 0) {
            return ['id' => (int)$db->lastInsertRowID(), 'nuevo' => true, 'estado' => 'pendiente'];
        }
        // Ya existe: devolver la fila existente (no crear segundo envío lógico).
        $row = getEnvioLogicoExistente($db, $leadId, $campaignId, $esRotacion);
        if ($row) {
            return ['id' => (int)$row['id'], 'nuevo' => false, 'estado' => (string)$row['estado']];
        }
        // Fallback teórico (no debería pasar con el índice): cae al insert directo.
    }

    // Sin campaña (legacy/test): insert directo.
    insertarEnvioLogico($db, $club, $email, $federacion, $cuentaEmision, $trackingId,
        $asunto, $cuerpo, $leadId, $campaignId, $variant, $plantillaId, $smtpId, $messageId, $esTest, false, $esRotacion);

    return ['id' => (int)$db->lastInsertRowID(), 'nuevo' => true, 'estado' => 'pendiente'];
}

/**
 * Persistencia: INSERT del envío lógico (refactor §6.3).
 * @param bool $ignore true → INSERT OR IGNORE (reserva idempotente con campaña)
 */
function insertarEnvioLogico(
    SQLite3 $db,
    string $club,
    string $email,
    string $federacion,
    string $cuentaEmision,
    string $trackingId,
    string $asunto,
    string $cuerpo,
    int $leadId,
    int $campaignId,
    ?string $variant,
    ?int $plantillaId,
    ?int $smtpId,
    string $messageId,
    int $esTest,
    bool $ignore = false,
    int $esRotacion = 0
): void {
    $sql = ($ignore ? 'INSERT OR IGNORE INTO envios' : 'INSERT INTO envios')
        . " (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje,
            lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id, es_test, es_rotacion)
         VALUES
            (:club, :email, :fed, :cuenta, 'pendiente', :tid, :asunto, :cuerpo,
             :lid, :cid, :variant, :pid, :sid, :mid, :estest, :esrot)";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':club',  $club,  SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':fed',   $federacion, SQLITE3_TEXT);
    $stmt->bindValue(':cuenta',$cuentaEmision, SQLITE3_TEXT);
    $stmt->bindValue(':tid',   $trackingId, SQLITE3_TEXT);
    $stmt->bindValue(':asunto',$asunto, SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo',$cuerpo, SQLITE3_TEXT);
    $stmt->bindValue(':lid',   $leadId > 0 ? $leadId : null, SQLITE3_INTEGER);
    $stmt->bindValue(':cid',   $campaignId > 0 ? $campaignId : null, SQLITE3_INTEGER);
    $stmt->bindValue(':variant', $variant, SQLITE3_TEXT);
    $stmt->bindValue(':pid',   $plantillaId, SQLITE3_INTEGER);
    $stmt->bindValue(':sid',   $smtpId, SQLITE3_INTEGER);
    $stmt->bindValue(':mid',   $messageId, SQLITE3_TEXT);
    $stmt->bindValue(':estest', $esTest, SQLITE3_INTEGER);
    $stmt->bindValue(':esrot', $esRotacion, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Acceso a datos: fila del envío lógico existente para (lead, campaña)
 * (refactor §6.3).
 */
function getEnvioLogicoExistente(SQLite3 $db, int $leadId, int $campaignId, int $esRotacion = 0): ?array
{
    if ($leadId <= 0 || $campaignId <= 0) {
        return null;
    }
    $row = $db->querySingle(
        "SELECT id, estado FROM envios WHERE lead_id = {$leadId} AND campaign_id = {$campaignId} AND es_rotacion = {$esRotacion} ORDER BY id DESC LIMIT 1",
        true
    );
    return $row ?: null;
}
