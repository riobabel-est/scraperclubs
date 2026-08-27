<?php
/**
 * cron.php — Script autónomo para trabajos cron en producción.
 * Diseñado para ejecutarse vía CLI: php cron.php
 * 
 * Funcionalidad:
 * 1. Verifica si el motor está activado en la BD.
 * 2. Selecciona la siguiente cuenta SMTP disponible (respetando límites de 50 envíos/día).
 * 3. Toma el siguiente lead en cola y ejecuta el envío de correo.
 * 4. Registra el evento en comunicaciones_log.
 * 5. Actualiza contadores de envíos en cuentas_smtp.
 */

declare(strict_types=1);

// Transporte SMTP centralizado (unifica las implementaciones previas).
require_once __DIR__ . '/../inc/smtp_transport.php';

// ─── Solo CLI ───
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se ejecuta desde CLI.\n";
    exit(1);
}

// ─── Configuración ───
$DB_PATH = __DIR__ . '/../data/stats.db';
$LIMITE_DIARIO = 50;  // Límite por defecto, se sobrescribe con el de la cuenta

if (!file_exists($DB_PATH)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: stats.db no encontrada en {$DB_PATH}\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

require_once __DIR__ . '/../inc/eligibilidad.php';

// ═════════════════════════════════════════════════════════════════════════════
// FUNCIÓN AUXILIAR: envío SMTP con autenticación vía socket
// ═════════════════════════════════════════════════════════════════════════════

if (!function_exists('enviarSMTP')) {
/**
 * Envía un email usando SMTP con autenticación vía socket directo.
 *
 * @return bool true si el envío fue exitoso
 */
function enviarSMTP(
    string $host, int $port, string $secure,
    string $user, string $pass,
    string $from, string $to,
    string $subject, string $body, array $headers
): bool {
    // Normalizar la cuenta para el transporte centralizado.
    $cuenta = [
        'email'     => $from,
        'host'      => $host,
        'puerto'    => (int)$port,
        'usuario'   => $user,
        'password'  => $pass,
        'seguridad' => $secure,
    ];

    // Extraer Message-ID de los headers si existe.
    $messageId = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'message-id') {
            $messageId = $v;
            break;
        }
    }

    $opciones = [
        'reply_to' => $from,
    ];
    if ($messageId !== '') {
        $opciones['message_id'] = $messageId;
    }

    // Delegar en el transporte SMTP centralizado.
    $resultado = futprotec_enviarSMTP($cuenta, $to, $subject, $body, $opciones);

    if (!$resultado['ok']) {
        trigger_error($resultado['error'], E_USER_WARNING);
        return false;
    }
    return true;
}
}

/**
 * secuencia_programarYEnviar — Motor de secuencias condicionales (O-1).
 * Plan: docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md
 *
 * 1) Programa los pasos que tocan: paso 1 (descubrimiento) y pasos N>1
 *    (seguimiento por ramal; modo_auto=1 → envio pendiente, modo_auto=0 →
 *    sugerencia en propuestas_ia).
 * 2) Envía hasta $limite filas pendientes de secuencia con el transporte SMTP.
 * Regla de ramal: el paso solo se dispara si su ramal coincide con la variante
 * dominante del lead (más aperturas) o si es genérico (ramal='').
 */
function secuencia_programarYEnviar(SQLite3 $db, int $campaignId, int $limite = 10): array
{
    $stats = ['paso1' => 0, 'pasoN' => 0, 'sugerencias' => 0, 'enviados' => 0, 'errores' => 0, 'excluidos' => 0];
    $filtroComp = sqlFiltroCompatibilidadLeadCampana($db, $campaignId);
    $placeholders = ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'];

    $resSec = $db->query("SELECT id, campaign_id, nombre, modo_auto, activo FROM secuencias WHERE campaign_id = {$campaignId} AND activo = 1 ORDER BY id");
    if (!$resSec) return $stats;
    while ($sec = $resSec->fetchArray(SQLITE3_ASSOC)) {
        $secId = (int)$sec['id'];
        $modoAuto = (int)$sec['modo_auto'];

        $pasos = [];
        $resPasos = $db->query("SELECT * FROM secuencia_pasos WHERE secuencia_id = {$secId} AND activo = 1 ORDER BY paso ASC");
        if (!$resPasos) continue;
        while ($p = $resPasos->fetchArray(SQLITE3_ASSOC)) $pasos[] = $p;
        if (empty($pasos)) continue;

        foreach ($pasos as $paso) {
            $numPaso = (int)$paso['paso'];
            $espera = max(0, (int)$paso['espera_dias']);
            $plantillaId = (int)$paso['plantilla_id'];
            $ramal = strtoupper((string)$paso['ramal']);

            if ($numPaso === 1) {
                // ── PASO 1 (descubrimiento): leads sin envío en la campaña ──
                $resLeads = $db->query("SELECT c.* FROM clubes_crm c
                    LEFT JOIN envios e ON LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.estado='enviado'
                    WHERE c.estado_lead='01 Sin Contactar' AND c.email IS NOT NULL AND c.email != '' AND e.id IS NULL {$filtroComp}
                    ORDER BY c.creado_el ASC LIMIT 25");
                if (!$resLeads) continue;
                while ($lead = $resLeads->fetchArray(SQLITE3_ASSOC)) {
                    $elig = esElegibleParaEnvio($db, (int)$lead['id'], $campaignId);
                    if (!$elig['ok']) { $stats['excluidos']++; continue; }
                    $tpl = $db->querySingle("SELECT * FROM plantillas WHERE id={$plantillaId} AND activo=1", true);
                    if (!$tpl) continue;
                    $variant = asignarVariante((int)$lead['id'], $campaignId);
                    $contenido = resolverContenidoVariante($tpl, $variant);
                    $vals = [$lead['nombre_club'], $lead['persona_contacto'] ?: 'responsable', $lead['federacion'] ?? '', date('Y')];
                    $asunto = str_replace($placeholders, $vals, $contenido['asunto']);
                    $cuerpo = str_replace($placeholders, $vals, $contenido['cuerpo']);
                    if ($modoAuto === 1) {
                        // Automático: el cron programa el primer contacto.
                        $tracking = 'trk_' . bin2hex(random_bytes(8));
                        $esTest = esLeadTest($lead) ? 1 : 0;
                        // ANTI-DOBLE (F4): INSERT OR IGNORE respeta el índice UNIQUE
                        // (lead_id, campaign_id, paso_secuencia) ante concurrencia.
                        $db->exec("INSERT OR IGNORE INTO envios (club,email,federacion,cuenta_emision,estado,tracking_id,asunto,cuerpo_mensaje,lead_id,campaign_id,variant,plantilla_id,es_test,secuencia_id,paso_secuencia,message_id)
                            VALUES ('" . $db->escapeString($lead['nombre_club']) . "','" . $db->escapeString($lead['email']) . "','" . $db->escapeString($lead['federacion'] ?? '') . "','','pendiente','{$tracking}','" . $db->escapeString($asunto) . "','" . $db->escapeString($cuerpo) . "'," . (int)$lead['id'] . ",{$campaignId},'{$variant}',{$plantillaId},{$esTest},{$secId},1,'')");
                        if ($db->changes() > 0) { $stats['paso1']++; }
                    }
                    // Modo asistido: el primer contacto lo hace el usuario desde la
                    // Lanzadera (los pasos 2/3 y la rotación ABC se anclan a ese envío).
                }
            } else {
                // ── PASOS N>1: seguimiento por ramal ──
                $prevPaso = $numPaso - 1;
                // Paso previo completado:
                //  - Paso 2: cuenta un envío de secuencia previo (paso_secuencia=1) O un
                //    envío MANUAL de la campaña (primer contacto desde la Lanzadera).
                //  - Pasos >2: solo el paso previo de la secuencia.
                // Se excluyen los leads ya rotados (es_rotacion=1): su reenvío con otra
                // variante ya fue su segundo intento (evita doble envío/sugerencia).
                if ($numPaso === 2) {
                    $condPrev = "AND (
                        EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.paso_secuencia=1 AND e.estado='enviado' AND e.fecha_envio <= datetime('now','-" . $espera . " days'))
                        OR EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.secuencia_id IS NULL AND COALESCE(e.es_rotacion,0)=0 AND e.estado='enviado' AND e.fecha_envio <= datetime('now','-" . $espera . " days'))
                    )";
                    $condManualPost = '';
                } else {
                    $condPrev = "AND EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.paso_secuencia={$prevPaso} AND e.estado='enviado' AND e.fecha_envio <= datetime('now','-" . $espera . " days'))";
                    $condManualPost = "AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.secuencia_id IS NULL AND e.campaign_id={$campaignId} AND e.estado='enviado')";
                }
                $resLeads = $db->query("SELECT c.* FROM clubes_crm c
                    WHERE c.email IS NOT NULL AND c.email != ''
                      {$condPrev}
                      AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.paso_secuencia={$numPaso})
                      AND NOT EXISTS (SELECT 1 FROM respuestas r WHERE r.lead_id=c.id)
                      AND NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email) AND e.campaign_id={$campaignId} AND e.es_rotacion=1 AND e.estado='enviado')
                      {$condManualPost}
                    {$filtroComp}
                    ORDER BY c.id ASC LIMIT 25");
                if (!$resLeads) continue;
                while ($lead = $resLeads->fetchArray(SQLITE3_ASSOC)) {
                    $elig = esElegibleParaEnvio($db, (int)$lead['id'], $campaignId);
                    if (!$elig['ok']) { $stats['excluidos']++; continue; }
                    // Variante dominante (la que más abrió) → ramal.
                    $varianteDom = '';
                    $rV = $db->query("SELECT e.variant, COUNT(a.id) n FROM envios e JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE LOWER(e.email)=LOWER('" . $db->escapeString($lead['email']) . "') AND COALESCE(e.es_test,0)=0 AND e.variant IS NOT NULL AND e.variant!='' GROUP BY e.variant ORDER BY n DESC LIMIT 1");
                    if ($rV && ($fV = $rV->fetchArray(SQLITE3_ASSOC))) $varianteDom = strtoupper((string)$fV['variant']);
                    if ($ramal !== '' && $varianteDom !== '' && $ramal !== $varianteDom) continue; // ramal no coincide
                    $variantPaso = $varianteDom !== '' ? $varianteDom : 'A';
                    $tpl = $db->querySingle("SELECT * FROM plantillas WHERE id={$plantillaId} AND activo=1", true);
                    if (!$tpl) continue;
                    $contenido = resolverContenidoVariante($tpl, $variantPaso);
                    $vals = [$lead['nombre_club'], $lead['persona_contacto'] ?: 'responsable', $lead['federacion'] ?? '', date('Y')];
                    $asunto = str_replace($placeholders, $vals, $contenido['asunto']);
                    $cuerpo = str_replace($placeholders, $vals, $contenido['cuerpo']);

                    if ($modoAuto === 1) {
                        $tracking = 'trk_' . bin2hex(random_bytes(8));
                        $esTest = esLeadTest($lead) ? 1 : 0;
                        // ANTI-DOBLE (F4): INSERT OR IGNORE respeta el índice UNIQUE
                        // (lead_id, campaign_id, paso_secuencia) ante concurrencia.
                        $db->exec("INSERT OR IGNORE INTO envios (club,email,federacion,cuenta_emision,estado,tracking_id,asunto,cuerpo_mensaje,lead_id,campaign_id,variant,plantilla_id,es_test,secuencia_id,paso_secuencia,message_id)
                            VALUES ('" . $db->escapeString($lead['nombre_club']) . "','" . $db->escapeString($lead['email']) . "','" . $db->escapeString($lead['federacion'] ?? '') . "','','pendiente','{$tracking}','" . $db->escapeString($asunto) . "','" . $db->escapeString($cuerpo) . "'," . (int)$lead['id'] . ",{$campaignId},'{$variantPaso}',{$plantillaId},{$esTest},{$secId},{$numPaso},'')");
                        if ($db->changes() > 0) { $stats['pasoN']++; }
                    } else {
                        // Modo asistido → sugerencia pendiente en propuestas_ia.
                        $existe = (int)$db->querySingle("SELECT COUNT(*) FROM propuestas_ia WHERE lead_id=" . (int)$lead['id'] . " AND tipo='secuencia_paso{$numPaso}' AND estado='pendiente'");
                        if ($existe === 0) {
                            $stmtP = $db->prepare("INSERT INTO propuestas_ia (lead_id, campaign_id, tipo, titulo, razon, mensaje_sugerido, prioridad, estado, creado_el)
                                VALUES (:lid,:cid,:tipo,:tit,:raz,:msg,'Media','pendiente',CURRENT_TIMESTAMP)");
                            $stmtP->bindValue(':lid', (int)$lead['id'], SQLITE3_INTEGER);
                            $stmtP->bindValue(':cid', $campaignId, SQLITE3_INTEGER);
                            $stmtP->bindValue(':tipo', 'secuencia_paso' . $numPaso, SQLITE3_TEXT);
                            $stmtP->bindValue(':tit', 'Secuencia Paso ' . $numPaso . ' — ' . $lead['nombre_club'], SQLITE3_TEXT);
                            $stmtP->bindValue(':raz', 'Espera cumplida tras el paso ' . $prevPaso . ' (ramal ' . ($varianteDom ?: 'genérico') . ')', SQLITE3_TEXT);
                            $stmtP->bindValue(':msg', $asunto . "\n\n" . $cuerpo, SQLITE3_TEXT);
                            $stmtP->execute();
                            $stats['sugerencias']++;
                        }
                    }
                }
            }
        }
    }
    // ── Envío de las filas pendientes de secuencia (hasta el límite) ──
    $resPend = $db->query("SELECT * FROM envios WHERE estado='pendiente' AND secuencia_id IS NOT NULL ORDER BY id ASC LIMIT {$limite}");
    if ($resPend) {
        while ($envio = $resPend->fetchArray(SQLITE3_ASSOC)) {
            // ANTI-DOBLE / ELEGIBILIDAD (F4): re-validar antes de enviar. Entre la
            // programación del paso y el envío el lead pudo darse de baja, responder,
            // marcarse duplicado o superar la espera → se excluye sin enviar.
            $eligPend = esElegibleParaEnvio($db, (int)$envio['lead_id'], $campaignId);
            $hayRespuesta = (int)$db->querySingle("SELECT COUNT(*) FROM respuestas WHERE lead_id=" . (int)$envio['lead_id']);
            if (!$eligPend['ok'] || $hayRespuesta > 0) {
                $db->exec("UPDATE envios SET estado='excluido', resultado_envio='SKIPPED', fecha_resultado_envio=CURRENT_TIMESTAMP WHERE id=" . (int)$envio['id']);
                $stats['excluidos']++;
                continue;
            }
            $cuenta = elegirCuentaSmtpDisponible($db);
            if (!$cuenta) break;
            $ok = enviarSMTP(
                $cuenta['host'], (int)$cuenta['puerto'], $cuenta['seguridad'],
                $cuenta['usuario'],
                // F4: las credenciales SMTP están cifradas FP1: en BD desde 2026-08-25.
                futprotec_descifrarPassword($cuenta['password'] ?? ''),
                $cuenta['email'],
                $envio['email'], $envio['asunto'], $envio['cuerpo_mensaje'],
                ['Message-ID' => '', 'X-Tracking-ID' => $envio['tracking_id']]
            );
            if ($ok) {
                $db->exec("UPDATE envios SET estado='enviado', cuenta_emision='" . $db->escapeString($cuenta['email']) . "', fecha_envio=CURRENT_TIMESTAMP, smtp_id=" . (int)$cuenta['id'] . " WHERE id=" . (int)$envio['id']);
                $db->exec("UPDATE cuentas_smtp SET ultimo_uso=CURRENT_TIMESTAMP WHERE id=" . (int)$cuenta['id']);
                $db->exec("UPDATE clubes_crm SET estado_lead='02 Contactado', ultimo_contacto=CURRENT_TIMESTAMP WHERE id=" . (int)$envio['lead_id']);
                $db->exec("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, plantilla_id, id_cuenta_smtp, tipo, resultado, detalles, fecha)
                    VALUES (" . (int)$envio['lead_id'] . "," . (int)$envio['lead_id'] . ",'envio_email'," . (int)$envio['plantilla_id'] . "," . (int)$cuenta['id'] . ",'email','exito','Envío automático de secuencia (paso " . (int)$envio['paso_secuencia'] . ") via " . $cuenta['email'] . "',CURRENT_TIMESTAMP)");
                // E-2/FI-005: sincronizar DESPUÉS del INSERT en comunicaciones_log.
                sincronizarEnviadosHoyCuenta($db, (int)$cuenta['id']);
                $stats['enviados']++;
            } else {
                $db->exec("UPDATE envios SET estado='error', resultado_envio='FAILED', fecha_resultado_envio=CURRENT_TIMESTAMP WHERE id=" . (int)$envio['id']);
                // E-2/FI-005: corrige desfases de la columna aunque falle el envío.
                sincronizarEnviadosHoyCuenta($db, (int)$cuenta['id']);
                $stats['errores']++;
            }
        }
    }
    return $stats;
}

// ═════════════════════════════════════════════════════════════════════════════
// 0. CAMPAÑA OBLIGATORIA (FASE 2C) — cron no envía sin campaña válida y trazable
// ═════════════════════════════════════════════════════════════════════════════
$opts = getopt('', ['campaign-id:', 'campaign:']);
$campaignRaw = $opts['campaign-id'] ?? $opts['campaign'] ?? null;

if ($campaignRaw === null || trim((string)$campaignRaw) === '') {
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / NO CAMPAIGN — usa --campaign-id=N\n";
    $db->close();
    exit(1);
}

$campaignId = (int)$campaignRaw;
if ($campaignId <= 0) {
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / NO CAMPAIGN — campaign-id debe ser entero positivo\n";
    $db->close();
    exit(1);
}

$modoEntornoGlobal = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
$validacion = validarCampanaActiva($db, $campaignId, $modoEntornoGlobal);
$reasonLabels = [
    'NO_CAMPAIGN'         => 'NO CAMPAIGN',
    'INVALID_CAMPAIGN'    => 'INVALID CAMPAIGN',
    'CAMPAIGN_NOT_ACTIVE' => 'CAMPAIGN NOT ACTIVE',
    'ENVIRONMENT_MISMATCH'=> 'ENVIRONMENT MISMATCH',
];
if (!$validacion['ok']) {
    $label = $reasonLabels[$validacion['razon']] ?? 'CAMPAIGN INVALID';
    echo "[" . date('Y-m-d H:i:s') . "] BLOCKED / {$label}\n";
    $db->close();
    exit(1);
}

$campaign = $validacion['campaña'];
echo "[" . date('Y-m-d H:i:s') . "] Campaña válida: #{$campaignId} (estado={$campaign['estado']}, entorno={$campaign['entorno']})\n";

// ═════════════════════════════════════════════════════════════════════════════
// 1. Verificar si el motor está activado
// ═════════════════════════════════════════════════════════════════════════════
$motorEstado = $db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'");
if ($motorEstado !== 'activo') {
    echo "[" . date('Y-m-d H:i:s') . "] Motor PAUSADO. No se realiza ningún envío.\n";
    $db->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Motor ACTIVO. Iniciando ciclo de envío...\n";

// ─── MODO SECUENCIA (O-1 — ramificación por ramal ABC) ─────────────────────
// Si la campaña tiene secuencias activas, este ciclo programa y envía sus pasos
// (paso 1 + pasos N por ramal, asistido o automático) y deja el 1er contacto
// genérico para campañas sin secuencia.
$tieneSecuencia = (int)$db->querySingle("SELECT COUNT(*) FROM secuencias WHERE campaign_id = {$campaignId} AND activo = 1");
if ($tieneSecuencia > 0) {
    $statsSec = secuencia_programarYEnviar($db, $campaignId, 10);
    echo "[" . date('Y-m-d H:i:s') . "] SECUENCIA: paso1=" . $statsSec['paso1']
        . " · pasosN=" . $statsSec['pasoN'] . " · sugerencias=" . $statsSec['sugerencias']
        . " · enviados=" . $statsSec['enviados'] . " · errores=" . $statsSec['errores']
        . " · excluidos=" . $statsSec['excluidos'] . "\n";
    $db->close();
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. Seleccionar siguiente cuenta SMTP disponible
// ═════════════════════════════════════════════════════════════════════════════
$cuentaRow = elegirCuentaSmtpDisponible($db);

if (!$cuentaRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ⚠️ No hay cuentas SMTP disponibles (todas han alcanzado su límite diario o están inactivas).\n";
    $db->close();
    exit(0);
}

$limiteCuenta = (int)$cuentaRow['limite_diario'];
$enviadosHoy  = (int)$cuentaRow['enviados_hoy'];

echo "[" . date('Y-m-d H:i:s') . "] Cuenta SMTP seleccionada: {$cuentaRow['email']} ({$enviadosHoy}/{$limiteCuenta} envíos hoy)\n";

// ═════════════════════════════════════════════════════════════════════════════
// 3. Seleccionar siguiente lead en cola (estado = "Sin Contactar")
// ═════════════════════════════════════════════════════════════════════════════
// AISLAMIENTO TEST/REAL (FASE 6F.6): la selección SQL NO puede devolver un
// lead incompatible con la campaña (campaña TEST → sólo leads TEST; campaña no
// TEST → nunca leads TEST). Mismo fragmento SQL que get_cola.php.
$filtroCompatibilidad = sqlFiltroCompatibilidadLeadCampana($db, $campaignId);

$leadRow = $db->querySingle(
    "SELECT c.* FROM clubes_crm c
     LEFT JOIN envios e ON LOWER(e.email) = LOWER(c.email) AND e.estado = 'enviado'
      WHERE c.estado_lead = '01 Sin Contactar'
       AND c.email IS NOT NULL AND c.email != ''
       AND e.id IS NULL
       {$filtroCompatibilidad}
     ORDER BY c.creado_el ASC
     LIMIT 1",
    true
);

if (!$leadRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ✅ No hay leads pendientes de primer contacto. Todos los clubes están en secuencia.\n";
    $db->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Lead seleccionado: #{$leadRow['id']} — {$leadRow['nombre_club']} ({$leadRow['email']})\n";

// ═════════════════════════════════════════════════════════════════════════════
// 3.5 Elegibilidad central (supresión) — defensa en profundidad
// ═════════════════════════════════════════════════════════════════════════════
$elig = esElegibleParaEnvio($db, (int)$leadRow['id'], $campaignId);
if (!$elig['ok']) {
    echo "[" . date('Y-m-d H:i:s') . "] 🚫 Lead #{$leadRow['id']} NO elegible ({$elig['razon']}). Se salta.\n";
    $db->close();
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. Verificar modo de entorno (test / producción)
// ═════════════════════════════════════════════════════════════════════════════
$modoEntorno = $db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test';

// ═════════════════════════════════════════════════════════════════════════════
// 5. Obtener plantilla activa
// ═════════════════════════════════════════════════════════════════════════════
$plantilla = $db->querySingle(
    "SELECT * FROM plantillas WHERE activo = 1 AND tipo = 'html' ORDER BY id ASC LIMIT 1",
    true
);

if (!$plantilla) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No hay plantilla HTML activa.\n";
    $db->close();
    exit(1);
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. Variante determinística + contenido por variante (FASE 3)
// ═════════════════════════════════════════════════════════════════════════════
$variantUsada = asignarVariante((int)$leadRow['id'], $campaignId);
$contenido = resolverContenidoVariante($plantilla, $variantUsada);

// ═════════════════════════════════════════════════════════════════════════════
// 6. Construir email
// ═════════════════════════════════════════════════════════════════════════════
$asunto = str_replace(
    ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
    [
        $leadRow['nombre_club'],
        $leadRow['persona_contacto'] ?: 'responsable',
        $leadRow['federacion'] ?? '',
        date('Y'),
    ],
    $contenido['asunto']
);

$cuerpo = str_replace(
    ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
    [
        $leadRow['nombre_club'],
        $leadRow['persona_contacto'] ?: 'responsable',
        $leadRow['federacion'] ?? '',
        date('Y'),
    ],
    $contenido['cuerpo']
);

// Generar tracking ID único
$trackingId = bin2hex(random_bytes(16));

// Incluir pixel de tracking en el cuerpo HTML
$pixelUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'getfutprotec.com') . "/outbound/api/track.php?id={$trackingId}";
$cuerpo .= "\n<img src=\"{$pixelUrl}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\">";

// ═════════════════════════════════════════════════════════════════════════════
// 6.5 Reservar envío lógico ANTES de SMTP (idempotencia por lead_id+campaign_id)
// ═════════════════════════════════════════════════════════════════════════════
$reserva = reservarEnvioLogico(
    $db,
    (int)$leadRow['id'],
    $campaignId,
    $leadRow['nombre_club'],
    $leadRow['email'],
    $leadRow['federacion'] ?? '',
    $cuentaRow['email'],
    $trackingId,
    $asunto,
    $cuerpo,
    $variantUsada,
    (int)$plantilla['id'],
    (int)$cuentaRow['id']
);

$envioRow = $db->querySingle(
    "SELECT id, estado, tracking_id, asunto, cuerpo_mensaje, message_id FROM envios WHERE id = " . $reserva['id'],
    true
);
if (!$envioRow) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: no se pudo reservar el envío lógico.\n";
    $db->close();
    exit(1);
}

if (in_array($envioRow['estado'], ['enviado', 'abierto'], true)) {
    echo "[" . date('Y-m-d H:i:s') . "] ⏭ Lead ya enviado en esta campaña (envio_id=" . $envioRow['id'] . "). No se reenvía.\n";
    $db->close();
    exit(0);
}

// ═════════════════════════════════════════════════════════════════════════════
// 7. Enviar email
// ═════════════════════════════════════════════════════════════════════════════
$headers = [
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/html; charset=UTF-8',
    'From'         => $cuentaRow['email'],
    'Reply-To'     => $cuentaRow['email'],
    'X-Mailer'     => 'FutProtec Cron Engine',
    'X-Tracking-ID' => $trackingId,
    'X-Campaign'   => 'outbound_v1',
    'Message-ID'   => $envioRow['message_id'] ?? '',
];

$headerString = '';
foreach ($headers as $k => $v) {
    $headerString .= "{$k}: {$v}\r\n";
}

$enviado = false;
$errorMsg = '';

if ($modoEntorno === 'produccion') {
    try {
        // Intentar envío SMTP con autenticación
        $smtpHost = $cuentaRow['host'];
        $smtpPort = (int)$cuentaRow['puerto'];
        $smtpUser = $cuentaRow['usuario'];
        $smtpPass = futprotec_descifrarPassword($cuentaRow['password'] ?? '');
        $smtpSecure = $cuentaRow['seguridad']; // ssl o tls

        // Usar mail() como fallback si no hay configuración completa
        if (empty($smtpHost) || empty($smtpUser)) {
            $enviado = mail(
                $leadRow['email'],
                '=?UTF-8?B?' . base64_encode($asunto) . '?=',
                $cuerpo,
                $headerString
            );
        } else {
            // Envío con autenticación SMTP vía socket
            $enviado = enviarSMTP(
                $smtpHost, $smtpPort, $smtpSecure,
                $smtpUser, $smtpPass,
                $cuentaRow['email'],
                $leadRow['email'],
                $asunto, $cuerpo, $headers
            );
        }

        if (!$enviado) {
            $errorMsg = error_get_last()['message'] ?? 'Error desconocido en mail()';
        }
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
    }
} else {
    // Modo test: simular envío exitoso
    echo "[" . date('Y-m-d H:i:s') . "] 🧪 MODO PRUEBAS: Simulando envío a {$leadRow['email']}...\n";
    $enviado = true;
}

// ═════════════════════════════════════════════════════════════════════════════
// 8. Actualizar el envío lógico reservado con el resultado SMTP
// ═════════════════════════════════════════════════════════════════════════════
if ($enviado) {
    // Marcar la MISMA fila reservada como enviada, con resultado SMTP inmutable.
    $stmt = $db->prepare("UPDATE envios SET estado = 'enviado', resultado_envio = 'ACCEPTED', fecha_resultado_envio = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', (int)$envioRow['id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Registrar en comunicaciones_log
    $stmtLog = $db->prepare(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, plantilla_id, id_cuenta_smtp, tipo, resultado, detalles, fecha)
         VALUES (:lid, :cid, 'envio_email', :pid, :sid, 'email', 'exito', :det, CURRENT_TIMESTAMP)"
    );
    $stmtLog->bindValue(':lid', $leadRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $leadRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':pid', $plantilla['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':sid', $cuentaRow['id'], SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', "Email enviado vía {$cuentaRow['email']} (tracking: {$trackingId})", SQLITE3_TEXT);
    $stmtLog->execute();

    // Actualizar estado del lead
    $db->exec("UPDATE clubes_crm SET estado_lead = '02 Contactado', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = {$leadRow['id']}");

    // Incrementar contador de envíos de la cuenta SMTP (recuento real, E-2/FI-005)
    $db->exec("UPDATE cuentas_smtp SET ultimo_uso = CURRENT_TIMESTAMP WHERE id = {$cuentaRow['id']}");
    sincronizarEnviadosHoyCuenta($db, (int)$cuentaRow['id']);

    echo "[" . date('Y-m-d H:i:s') . "] ✅ Email enviado correctamente a {$leadRow['email']} (tracking: {$trackingId})\n";
    echo "[" . date('Y-m-d H:i:s') . "] Lead #{$leadRow['id']} actualizado a '02 Contactado'\n";
    echo "[" . date('Y-m-d H:i:s') . "] Cuenta SMTP {$cuentaRow['email']}: " . ($enviadosHoy + 1) . "/{$limiteCuenta} envíos hoy\n";
} else {
    // Marcar la fila reservada como error (retryable, sin duplicar), resultado inmutable FAILED.
    $stmt = $db->prepare("UPDATE envios SET estado = 'error', resultado_envio = 'FAILED', fecha_resultado_envio = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', (int)$envioRow['id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Registrar error en la cuenta SMTP
    $db->exec("UPDATE cuentas_smtp SET ultimo_error = '" . $db->escapeString($errorMsg) . "' WHERE id = {$cuentaRow['id']}");
    // E-2/FI-005: corrige desfases de la columna aunque falle el envío.
    sincronizarEnviadosHoyCuenta($db, (int)$cuentaRow['id']);

    echo "[" . date('Y-m-d H:i:s') . "] ❌ ERROR al enviar a {$leadRow['email']}: {$errorMsg}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Error registrado en cuenta SMTP #{$cuentaRow['id']} (envio_id={$envioRow['id']} queda 'error')\n";
}

$db->close();
echo "[" . date('Y-m-d H:i:s') . "] Ciclo completado.\n";
exit(0);

