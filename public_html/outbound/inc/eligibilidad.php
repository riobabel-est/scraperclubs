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
 * DEFINICIÓN DE CAMPAÑA TEST (FASE 6F.6).
 *
 * Una campaña es TEST cuando `pipelines.entorno` = 'test' (case-insensitive).
 * Se consulta la BD (no se confía en valores pasados por el cliente).
 */
function esCampanaTest(SQLite3 $db, int $idCampana): bool
{
    if ($idCampana <= 0) {
        return false;
    }
    $entorno = strtolower((string)$db->querySingle(
        "SELECT entorno FROM pipelines WHERE id = " . $idCampana
    ));
    return $entorno === 'test';
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

    // Estados de supresión / baja que bloquean el envío comercial.
    $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

    $lead = $db->querySingle(
        "SELECT id, email, estado_lead, es_duplicado, nombre_club
         FROM clubes_crm WHERE id = " . $leadId,
        true
    );

    if (!$lead) {
        return ['ok' => false, 'razon' => 'lead_no_encontrado'];
    }

    if (in_array($lead['estado_lead'], $estadosSupresion, true)) {
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
    if ($plantillaId <= 0) {
        return false;
    }
    $n = (int)$db->querySingle(
        "SELECT COUNT(*)
         FROM envios e
         JOIN pipelines p ON p.id = e.campaign_id
         WHERE e.plantilla_id = {$plantillaId}
           AND UPPER(p.estado) IN ('PILOT','ACTIVE')"
    );
    return $n > 0;
}

/**
 * Reserva el "envío lógico" para (lead, campaña) ANTES de intentar SMTP.
 *
 * Garantiza por índice único parcial idx_envios_lead_campaign que un lead
 * tenga como máximo UN envío lógico por campaña, sin bloquear el intento SMTP.
 *
 * Estrategia (distingue envío lógico / intento SMTP / aceptación / error):
 *  - campaignId <= 0: sin restricción de idempotencia (nuevo envío directo).
 *  - campaignId > 0:  INSERT OR IGNORE en estado provisional 'pendiente'.
 *      * nuevo = true  → el llamador hace SMTP y actualiza estado (enviado/error).
 *      * nuevo = false → ya existe; el llamador NO debe crear otro envío lógico.
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
    ?int $smtpId = null
): array {
    // INMUTABILIDAD: para campaña real la variante es determinística e
    // independiente del valor que pase el llamador (impide random por envío).
    if ($campaignId > 0) {
        $variant = asignarVariante($leadId, $campaignId);
    }

    // Message-ID estable derivado del tracking_id (un retry produce el mismo).
    $messageId = generarMessageIdEnvio($trackingId, $cuentaEmision);

    if ($campaignId > 0) {
        // Reserva idempotente: solo una fila (lead_id, campaign_id) con campaign no nulo.
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO envios
                (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje,
                 lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id)
             VALUES
                (:club, :email, :fed, :cuenta, 'pendiente', :tid, :asunto, :cuerpo,
                 :lid, :cid, :variant, :pid, :sid, :mid)"
        );
        $stmt->bindValue(':club',  $club,  SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':fed',   $federacion, SQLITE3_TEXT);
        $stmt->bindValue(':cuenta',$cuentaEmision, SQLITE3_TEXT);
        $stmt->bindValue(':tid',   $trackingId, SQLITE3_TEXT);
        $stmt->bindValue(':asunto',$asunto, SQLITE3_TEXT);
        $stmt->bindValue(':cuerpo',$cuerpo, SQLITE3_TEXT);
        $stmt->bindValue(':lid',   $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':cid',   $campaignId, SQLITE3_INTEGER);
        $stmt->bindValue(':variant', $variant, SQLITE3_TEXT);
        $stmt->bindValue(':pid',   $plantillaId, SQLITE3_INTEGER);
        $stmt->bindValue(':sid',   $smtpId, SQLITE3_INTEGER);
        $stmt->bindValue(':mid',   $messageId, SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() > 0) {
            return ['id' => (int)$db->lastInsertRowID(), 'nuevo' => true, 'estado' => 'pendiente'];
        }

        // Ya existe: devolver la fila existente (no crear segundo envío lógico).
        $row = $db->querySingle(
            "SELECT id, estado FROM envios WHERE lead_id = {$leadId} AND campaign_id = {$campaignId} ORDER BY id DESC LIMIT 1",
            true
        );
        if ($row) {
            return ['id' => (int)$row['id'], 'nuevo' => false, 'estado' => (string)$row['estado']];
        }
        // Fallback teórico (no debería pasar con el índice): insert directo.
    }

    // Sin campaña (legacy/test): insert directo.
    $stmt = $db->prepare(
        "INSERT INTO envios
            (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje,
             lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id)
         VALUES
            (:club, :email, :fed, :cuenta, 'pendiente', :tid, :asunto, :cuerpo,
             :lid, :cid, :variant, :pid, :sid, :mid)"
    );
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
    $stmt->execute();

    return ['id' => (int)$db->lastInsertRowID(), 'nuevo' => true, 'estado' => 'pendiente'];
}
