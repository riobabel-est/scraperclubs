<?php
/**
 * respuestas.php — Módulo FASE 4B: captura/clasificación de respuestas inbound.
 * Registro manual/asistido, idempotente, con Message-ID y clasificación humana.
 * No implementa IMAP/POP/webhook. Sin envíos.
 */

declare(strict_types=1);

// FASE 4: set rápido de clasificación comercial (9 estados del megaprompt) +
// vocabularios legacy (IMAP/heurística/IA y Unibox) para no romper lectura ni
// reclasificación histórica.
const CLASIFICACIONES_VALIDAS = [
    'PENDING', 'POSITIVE', 'NEGATIVE', 'NEUTRAL', 'UNSUBSCRIBE', 'OOO',
    'INTERESADO', 'SOLICITA_INFO', 'SOLICITA_PRECIO', 'SOLICITA_MOCKUP', 'SOLICITA_MUESTRA',
    'NO_INTERESADO', 'FUERA_DE_OFICINA', 'HARD_BOUNCE', 'OTRO',
    'HUMANA', 'REBOTE', 'DUDA_PRECIO', 'NO_INTERESA', 'AUTOMATICA', 'DESCONOCIDA', 'BAJA', 'OPT-OUT',
];

/**
 * estadoDestinoPorClasificacion — Mapea la clasificación de una respuesta al
 * estado de destino en el pipeline (trigger por sentimiento, 2026-08-26).
 *
 * Vocabulario A (IMAP/heurística/IA): humana, interesado, duda_precio, neutral,
 *   no_interesa, baja, fuera_de_oficina, automatica, desconocida, otro.
 * Vocabulario B (Unibox): PENDING, POSITIVE, NEGATIVE, NEUTRAL, UNSUBSCRIBE, OOO.
 *
 * - Positivo (interesado/humana/POSITIVE) y dudoso (duda_precio/neutral/NEUTRAL)
 *   → '03 En Conversación'
 * - Negativo (no_interesa/NEGATIVE) → '06 Perdido'
 * - Baja (baja/UNSUBSCRIBE/opt-out) → '07 Baja'
 * - Ruido (OOO, fuera_de_oficina, automatica, desconocida, otro, PENDING, '')
 *   → null (no mover el lead)
 *
 * @return string|null Estado del pipeline o null si no debe moverse.
 */
function estadoDestinoPorClasificacion(string $clasificacion): ?string
{
    $c = strtoupper(trim($clasificacion));
    if (in_array($c, ['INTERESADO', 'HUMANA', 'POSITIVE', 'DUDA_PRECIO', 'NEUTRAL',
        'SOLICITA_INFO', 'SOLICITA_PRECIO', 'SOLICITA_MOCKUP', 'SOLICITA_MUESTRA'], true)) {
        return '03 En Conversación';
    }
    if (in_array($c, ['NO_INTERESA', 'NO_INTERESADO', 'NEGATIVE'], true)) {
        return '06 Perdido';
    }
    if (in_array($c, ['BAJA', 'UNSUBSCRIBE', 'OPT-OUT'], true)) {
        return '07 Baja';
    }
    return null;
}

/**
 * Genera un Message-ID válido y estable para un envío lógico.
 * Se deriva del tracking_id del envío, por lo que un retry produce el MISMO valor
 * (inmutabilidad del identidad del mensaje). No sustituye a envio_id.
 */
function generarMessageIdEnvio(string $trackingId, string $smtpEmail = ''): string
{
    $dominio = '';
    if ($smtpEmail !== '' && str_contains($smtpEmail, '@')) {
        $dominio = substr($smtpEmail, strrpos($smtpEmail, '@') + 1);
    }
    if ($dominio === '') {
        $dominio = 'getfutprotec.com';
    }
    return '<' . $trackingId . '@' . $dominio . '>';
}

/**
 * Registra una respuesta inbound de forma idempotente.
 *
 * Identificador único de idempotencia, por prioridad:
 *   1. message_id de la respuesta (UNIQUE).
 *   2. hash estable (message_id + remitente + envio_id) si el proveedor no da message_id.
 *
 * Envía `$datos` con: envio_id, remitente, destinatario, subject, cuerpo,
 * message_id, in_reply_to, references, clasificacion, fecha_respuesta.
 *
 * @return array{ok:bool, id:int|null, duplicado:bool, error:string}
 */
function registrarRespuesta(SQLite3 $db, array $datos): array
{
    $envioId = (int)($datos['envio_id'] ?? 0);
    if ($envioId <= 0) {
        return ['ok' => false, 'id' => null, 'duplicado' => false, 'error' => 'envio_id requerido'];
    }

    $envio = $db->querySingle("SELECT id, lead_id, campaign_id, variant, message_id FROM envios WHERE id = {$envioId}", true);
    if (!$envio) {
        return ['ok' => false, 'id' => null, 'duplicado' => false, 'error' => 'envio_id no existe'];
    }

    $remitente  = trim((string)($datos['remitente'] ?? ''));
    $messageId  = trim((string)($datos['message_id'] ?? ''));
    $subject    = trim((string)($datos['subject'] ?? ''));
    $cuerpo     = (string)($datos['cuerpo'] ?? '');
    $inReplyTo  = trim((string)($datos['in_reply_to'] ?? ''));
    $references = trim((string)($datos['references'] ?? ''));
    $clasif     = strtoupper(trim((string)($datos['clasificacion'] ?? 'PENDING')));
    if (!in_array($clasif, CLASIFICACIONES_VALIDAS, true)) {
        $clasif = 'PENDING';
    }
    $fechaResp = !empty($datos['fecha_respuesta']) ? (string)$datos['fecha_respuesta'] : date('Y-m-d H:i:s');

    // Idempotencia: si viene message_id, prioridad 1. Sino, hash estable (prioridad 3).
    $uniqueKey = null;
    if ($messageId !== '') {
        $uniqueKey = $messageId;
    } else {
        // Fallback: hash estable (no usar solo email+fecha).
        $uniqueKey = 'h:' . sha1($envioId . '|' . strtolower($remitente) . '|' . $cuerpo);
    }

    // Si ya existe message_id (o hash), no duplicar.
    $existente = $db->querySingle(
        "SELECT id FROM respuestas WHERE message_id = '" . $db->escapeString($uniqueKey) . "' LIMIT 1",
        true
    );
    if ($existente) {
        return ['ok' => true, 'id' => (int)$existente['id'], 'duplicado' => true, 'error' => ''];
    }

    // UNSUBSCRIBE → supresión inmediata del lead (integración con la única fuente).
    if ($clasif === 'UNSUBSCRIBE' && $envio['lead_id']) {
        $db->exec("UPDATE clubes_crm SET estado_lead = 'Lista Negra', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = " . (int)$envio['lead_id']);
    }

    $stmt = $db->prepare(
        "INSERT INTO respuestas
            (envio_id, fecha_respuesta, remitente, destinatario, subject, cuerpo,
             message_id, in_reply_to, \"references\", clasificacion,
             fecha_clasificacion, estado_procesamiento)
         VALUES
            (:envio, :fecha, :rem, :dest, :subj, :cuerpo,
             :mid, :irt, :ref, :clas,
             CASE WHEN :clas != 'PENDING' THEN CURRENT_TIMESTAMP ELSE NULL END, :estado)"
    );
    $stmt->bindValue(':envio',  $envioId, SQLITE3_INTEGER);
    $stmt->bindValue(':fecha',  $fechaResp, SQLITE3_TEXT);
    $stmt->bindValue(':rem',    $remitente, SQLITE3_TEXT);
    $stmt->bindValue(':dest',   trim((string)($datos['destinatario'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':subj',   $subject, SQLITE3_TEXT);
    $stmt->bindValue(':cuerpo', $cuerpo, SQLITE3_TEXT);
    $stmt->bindValue(':mid',    $uniqueKey, SQLITE3_TEXT);
    $stmt->bindValue(':irt',    $inReplyTo, SQLITE3_TEXT);
    $stmt->bindValue(':ref',    $references, SQLITE3_TEXT);
    $stmt->bindValue(':clas',   $clasif, SQLITE3_TEXT);
    $stmt->bindValue(':estado', $clasif === 'PENDING' ? 'nuevo' : 'clasificado', SQLITE3_TEXT);

    try {
        $stmt->execute();
        return ['ok' => true, 'id' => (int)$db->lastInsertRowID(), 'duplicado' => false, 'error' => ''];
    } catch (\Exception $e) {
        // Race: si dos procesos insertaron el mismo key, devolver el existente.
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            $existente = $db->querySingle(
                "SELECT id FROM respuestas WHERE message_id = '" . $db->escapeString($uniqueKey) . "' LIMIT 1",
                true
            );
            if ($existente) {
                return ['ok' => true, 'id' => (int)$existente['id'], 'duplicado' => true, 'error' => ''];
            }
        }
        return ['ok' => false, 'id' => null, 'duplicado' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Clasifica/rec lasifica una respuesta de forma idempotente.
 * Solo actualiza `clasificacion`/`fecha_clasificacion`/`estado_procesamiento`.
 * No modifica envio_id, lead/campaign/variant/plantilla/smtp ni message_id.
 * UNSUBSCRIBE activa la MISMA supresión (Lista Negra), idempotente.
 */
function clasificarRespuesta(SQLite3 $db, int $respuestaId, string $clasificacion): array
{
    $clasif = strtoupper(trim($clasificacion));
    if (!in_array($clasif, CLASIFICACIONES_VALIDAS, true)) {
        return ['ok' => false, 'error' => 'clasificacion invalida'];
    }

    $resp = $db->querySingle("SELECT envio_id FROM respuestas WHERE id = {$respuestaId}", true);
    if (!$resp) {
        return ['ok' => false, 'error' => 'respuesta no encontrada'];
    }

    // Resolver el lead asociado al envío.
    $envio = $db->querySingle("SELECT lead_id FROM envios WHERE id = " . (int)$resp['envio_id'], true);
    $leadId = $envio && !empty($envio['lead_id']) ? (int)$envio['lead_id'] : null;

    // UNSUBSCRIBE → supresión del lead del envío (misma fuente de baja).
    if ($clasif === 'UNSUBSCRIBE' && $leadId) {
        $db->exec("UPDATE clubes_crm SET estado_lead = 'Lista Negra', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");
    }

    // Trigger por sentimiento (reclasificación manual desde la Unibox):
    // POSITIVE/NEUTRAL → '03 En Conversación', NEGATIVE → '06 Perdido',
    // UNSUBSCRIBE → '07 Baja' (salvo que ya esté suprimido).
    if ($leadId) {
        $destino = estadoDestinoPorClasificacion($clasif);
        if ($destino !== null) {
            $estadoActual = (string)$db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$leadId}");
            $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
            if (!in_array($estadoActual, $estadosSupresion, true)) {
                $db->exec("UPDATE clubes_crm SET estado_lead = '{$destino}', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadId}");
                $stmtLog = $db->prepare(
                    "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
                     VALUES (:lid, :cid, 'cambio_estado', :det, CURRENT_TIMESTAMP)"
                );
                $stmtLog->bindValue(':lid', $leadId, SQLITE3_INTEGER);
                $stmtLog->bindValue(':cid', $leadId, SQLITE3_INTEGER);
                $stmtLog->bindValue(':det', "Estado cambiado a '{$destino}' por reclasificación {$clasif} (Unibox)", SQLITE3_TEXT);
                $stmtLog->execute();
            }
        }
    }

    $stmt = $db->prepare("UPDATE respuestas SET clasificacion = :c, fecha_clasificacion = CURRENT_TIMESTAMP, estado_procesamiento = :e WHERE id = :id");
    $stmt->bindValue(':c', $clasif, SQLITE3_TEXT);
    $stmt->bindValue(':e', $clasif === 'PENDING' ? 'nuevo' : 'clasificado', SQLITE3_TEXT);
    $stmt->bindValue(':id', $respuestaId, SQLITE3_INTEGER);
    $stmt->execute();

    return ['ok' => true, 'id' => $respuestaId, 'clasificacion' => $clasif];
}

/**
 * Resuelve la correlación estándar (In-Reply-To / References) → envio_id.
 * NO usa email ni "último envío" como atribución automática.
 * @return int|null  envio_id o null si no hay coincidencia inequívoca.
 */
function resolverEnvioPorCorrelacion(SQLite3 $db, string $inReplyTo = '', string $references = ''): ?int
{
    $candidatos = [];
    if ($inReplyTo !== '') {
        $candidatos[] = trim($inReplyTo);
    }
    if ($references !== '') {
        foreach (preg_split('/\s+/', trim($references)) ?: [] as $ref) {
            if ($ref !== '') $candidatos[] = trim($ref);
        }
    }

    foreach ($candidatos as $c) {
        $id = $db->querySingle("SELECT id FROM envios WHERE message_id = '" . $db->escapeString($c) . "' LIMIT 1");
        if ($id) {
            return (int)$id;
        }
    }
    return null;
}

/**
 * Recupera el contexto completo (lead, campaña, variante, plantilla, smtp) de un
 * envio, sin joins ambiguos por email.
 */
function contextoEnvio(SQLite3 $db, int $envioId): ?array
{
    return $db->querySingle(
        "SELECT id, lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id, asunto
         FROM envios WHERE id = {$envioId}",
        true
    ) ?: null;
}

/**
 * FASE 4.1 — Clasificación rápida COMPLETA (set de 9 estados comerciales).
 * Extiende clasificarRespuesta() añadiendo `intencion` y `proxima_accion`, y
 * crea automáticamente la oportunidad comercial cuando la clasificación es
 * positiva (POSITIVE / INTERESADO / SOLICITA_*).
 *
 * @return array{ok:bool, id:int|null, clasificacion:string, oportunidad_id:?int, error?:string}
 */
function clasificarRespuestaCompleta(
    SQLite3 $db,
    int $respuestaId,
    string $clasificacion,
    ?string $intencion = null,
    ?string $proximaAccion = null
): array {
    $clasif = strtoupper(trim($clasificacion));
    $res = clasificarRespuesta($db, $respuestaId, $clasif);
    if (!$res['ok']) {
        return $res;
    }

    // Persistir intención y próxima acción (FASE 4.1).
    if ($intencion !== null || $proximaAccion !== null) {
        $stmt = $db->prepare(
            'UPDATE respuestas SET intencion = COALESCE(:i, intencion),
                    proxima_accion = COALESCE(:pa, proxima_accion) WHERE id = :id'
        );
        $stmt->bindValue(':i', $intencion !== '' ? $intencion : null, SQLITE3_TEXT);
        $stmt->bindValue(':pa', $proximaAccion !== '' ? $proximaAccion : null, SQLITE3_TEXT);
        $stmt->bindValue(':id', $respuestaId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    // FASE 4.2 — Auto-oportunidad: una respuesta comercial positiva crea la
    // oportunidad (si no existe una activa). El estado del Kanban lo sigue
    // gobernando clubes_crm.estado_lead; oportunidades es la capa comercial.
    $opId = null;
    if (in_array($clasif, ['POSITIVE', 'INTERESADO', 'SOLICITA_INFO', 'SOLICITA_PRECIO', 'SOLICITA_MOCKUP', 'SOLICITA_MUESTRA'], true)) {
        $op = crearOportunidadDesdeRespuesta($db, $respuestaId);
        if ($op['ok']) {
            $opId = $op['id'] ?? null;
        }
    }

    return ['ok' => true, 'id' => $res['id'] ?? $respuestaId, 'clasificacion' => $clasif, 'oportunidad_id' => $opId];
}

/**
 * FASE 4.2 — Crea (o reutiliza) la oportunidad comercial de un lead a partir de
 * una respuesta. Idempotente: si ya existe una oportunidad activa para el lead,
 * devuelve la existente. Registra el evento `oportunidad_creada` en
 * comunicaciones_log. `oportunidades.estado` será la fuente comercial cuando
 * exista; `clubes_crm.estado_lead` se conserva como histórico.
 *
 * @return array{ok:bool, id:?int, existente:bool, error?:string}
 */
function crearOportunidadDesdeRespuesta(SQLite3 $db, int $respuestaId, array $datos = []): array
{
    $resp = $db->querySingle(
        "SELECT envio_id, lead_id, campaign_id FROM respuestas WHERE id = {$respuestaId}",
        true
    );
    if (!$resp) {
        return ['ok' => false, 'id' => null, 'existente' => false, 'error' => 'respuesta no encontrada'];
    }

    $leadId     = (int)($resp['lead_id'] ?? 0);
    $campaignId = (int)($resp['campaign_id'] ?? 0);
    if ($leadId <= 0) {
        $envio = $db->querySingle("SELECT lead_id, campaign_id FROM envios WHERE id = " . (int)($resp['envio_id'] ?? 0), true);
        if ($envio) {
            $leadId     = (int)$envio['lead_id'];
            $campaignId = (int)($envio['campaign_id'] ?? 0);
        }
    }
    if ($leadId <= 0) {
        return ['ok' => false, 'id' => null, 'existente' => false, 'error' => 'lead no resuelto'];
    }

    // Idempotencia: no crear duplicados (oportunidad activa existente).
    $exist = $db->querySingle(
        "SELECT id FROM oportunidades WHERE lead_id = {$leadId} AND estado NOT IN ('GANADA','PERDIDA','CANCELADA') ORDER BY id DESC LIMIT 1"
    );
    if ($exist) {
        return ['ok' => true, 'id' => (int)$exist, 'existente' => true];
    }

    $estado = (string)($datos['estado'] ?? 'NUEVA');
    $origen = (string)($datos['origen'] ?? 'RESPUESTA');
    $leadRow = $db->querySingle("SELECT email, nombre_club FROM clubes_crm WHERE id = {$leadId}", true);
    $esTest  = ($leadRow && esLeadTest($leadRow)) ? 1 : 0;
    $notas   = (string)($datos['notas'] ?? 'Creada desde respuesta #' . $respuestaId);

    $stmt = $db->prepare(
        'INSERT INTO oportunidades (lead_id, campaign_id, estado, origen, fecha_creacion, fecha_actualizacion, es_test, notas)
         VALUES (:l, :c, :e, :o, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :et, :n)'
    );
    $stmt->bindValue(':l', $leadId, SQLITE3_INTEGER);
    $stmt->bindValue(':c', $campaignId > 0 ? $campaignId : null, SQLITE3_INTEGER);
    $stmt->bindValue(':e', $estado, SQLITE3_TEXT);
    $stmt->bindValue(':o', $origen, SQLITE3_TEXT);
    $stmt->bindValue(':et', $esTest, SQLITE3_INTEGER);
    $stmt->bindValue(':n', $notas, SQLITE3_TEXT);
    $stmt->execute();
    $newId = (int)$db->lastInsertRowID();

    // Evento en comunicaciones_log (trazabilidad del embudo).
    $db->exec(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
         VALUES ({$leadId}, {$leadId}, 'oportunidad_creada', 'Oportunidad creada desde respuesta #{$respuestaId} (origen: {$origen})', CURRENT_TIMESTAMP)"
    );

    return ['ok' => true, 'id' => $newId, 'existente' => false];
}