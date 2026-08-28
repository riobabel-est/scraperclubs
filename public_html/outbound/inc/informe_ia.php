<?php
/**
 * inc/informe_ia.php — Recopila el contexto de datos del sistema para el
 * Asistente de Informes IA (endpoint informe_ia). El contexto se pasa al LLM
 * como JSON real para que responda con datos verificables (leads, envíos,
 * plantillas, respuestas, campañas). PHP 8.x — SiteGround compatible.
 */

declare(strict_types=1);

/**
 * Limpieza ligera de cuerpos MIME crudos (para que la IA lea texto legible).
 * No sustituye a limpiarCuerpoMime() de analytics.php (que no se puede incluir
 * desde un inc); extrae la parte de texto plano y decodifica quoted-printable.
 */
function limpiarCuerpoBasico(string $raw): string
{
    $t = $raw;
    if (preg_match('/Content-Type:\s*text\/plain(?:[^\r\n]*)\r?\n(?:[A-Za-z-]+:\s*[^\r\n]*\r?\n)*\r?\n(.*?)(?=\r?\n--|Content-Type:|$)/is', $t, $m)) {
        $t = $m[1];
    }
    $t = (string)preg_replace_callback('/=([0-9A-F]{2})/', fn($x) => chr(hexdec($x[1])), $t);
    $t = (string)preg_replace('/=\r?\n/', '', $t);
    $t = trim((string)strip_tags($t));
    return mb_substr($t, 0, 220);
}

/**
 * Recopila las métricas y datos que el asistente necesita para describir
 * "todo lo que sucede" en la operación de outbound.
 *
 * @param SQLite3 $db  Conexión a la BD (stats.db)
 * @param int     $cid Campaña seleccionada (0 = global)
 */
function recopilarContextoInforme(SQLite3 $db, int $cid = 0): array
{
    $ex = " AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";

    // ─── KPIs globales (coherentes con la cabecera del panel) ───
    $ctx = [];
    $ctx['total_leads']      = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");
    $ctx['leads_tocados']    = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email)=LOWER(c.email) WHERE COALESCE(e.es_test,0)=0 AND e.estado IN ('enviado','abierto'){$ex}");
    $ctx['emails_enviados']  = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.estado IN ('enviado','abierto')" . sqlFiltroComercial('e'));
    $tocados                 = max(1, $ctx['leads_tocados']);
    $ctx['leads_abrieron']   = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN envios e ON LOWER(e.email)=LOWER(c.email) JOIN aperturas a ON a.tracking_id=e.tracking_id WHERE COALESCE(e.es_test,0)=0{$ex}");
    $ctx['tasa_apertura_pct']  = round($ctx['leads_abrieron'] / $tocados * 100, 1);
    $ctx['leads_respondieron'] = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN respuestas r ON r.lead_id=c.id WHERE COALESCE(r.es_rebote,0)=0{$ex}");
    $ctx['tasa_respuesta_pct'] = round($ctx['leads_respondieron'] / $tocados * 100, 1);
    $ctx['leads_positivas']  = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM clubes_crm c JOIN respuestas r ON r.lead_id=c.id WHERE COALESCE(r.es_rebote,0)=0 AND upper(r.clasificacion)='POSITIVE'{$ex}");
    $ctx['tasa_positivas_pct'] = round($ctx['leads_positivas'] / $tocados * 100, 1);
    $ctx['en_conversacion']  = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('03 En Conversación','04 Propuesta')");
    $ctx['esperando_respuesta'] = (int)$db->querySingle("SELECT COUNT(DISTINCT r.lead_id) FROM respuestas r WHERE COALESCE(r.es_rebote,0)=0 AND r.lead_id>0 AND NOT EXISTS (SELECT 1 FROM respuestas r2 WHERE r2.lead_id=r.lead_id AND COALESCE(r2.es_rebote,0)=0 AND r2.estado_conversacion='requiere_respuesta')");
    $ctx['bajas']            = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead IN ('Opt-Out','Unsubscribed','Lista Negra')");
    $ctx['rebotes']          = (int)$db->querySingle("SELECT COUNT(*) FROM respuestas WHERE COALESCE(es_rebote,0)=1");

    // ─── Embudo de leads (para el cuello de botella) ───
    $embudo = [
        ['etapa' => 'Entregados',   'n' => $ctx['leads_tocados']],
        ['etapa' => 'Abrieron',     'n' => $ctx['leads_abrieron']],
        ['etapa' => 'Respondieron', 'n' => $ctx['leads_respondieron']],
        ['etapa' => 'Positivas',    'n' => $ctx['leads_positivas']],
    ];
    $ctx['embudo_leads'] = [];
    $prev = null;
    foreach ($embudo as $e) {
        $ctx['embudo_leads'][] = ['etapa' => $e['etapa'], 'n' => $e['n'], 'pct_desde_anterior' => $prev !== null && $prev > 0 ? round($e['n'] / $prev * 100, 1) : null];
        $prev = $e['n'];
    }
    $ctx['cuello_botella'] = null;
    for ($i = 0; $i < count($embudo) - 1; $i++) {
        $a = $embudo[$i]['n']; $b = $embudo[$i + 1]['n'];
        if ($a > 0 && $b < $a) {
            $pct = (int)round($b / $a * 100);
            if ($ctx['cuello_botella'] === null || $pct < $ctx['cuello_botella']['pct']) {
                $ctx['cuello_botella'] = ['origen' => $embudo[$i]['etapa'], 'destino' => $embudo[$i + 1]['etapa'], 'pct' => $pct];
            }
        }
    }

    // ─── Campañas ───
    $ctx['campanas'] = [];
    $res = $db->query("SELECT id, nombre, identificador, estado, entorno FROM pipelines ORDER BY id");
    while ($p = $res->fetchArray(SQLITE3_ASSOC)) {
        $ctx['campanas'][] = [
            'id' => (int)$p['id'],
            'nombre' => $p['nombre'],
            'identificador' => $p['identificador'],
            'estado' => $p['estado'],
            'entorno' => $p['entorno'],
            'envios_reales' => (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE campaign_id = " . (int)$p['id'] . " AND COALESCE(es_test,0) = 0"),
        ];
    }

    // ─── Detalle de la campaña seleccionada ───
    if ($cid > 0) {
        require_once __DIR__ . '/metricas.php';
        $m = calcularMetricas($db, $cid);
        if ($m['ok']) {
            $ctx['campaña_seleccionada'] = [
                'id' => $cid,
                'nombre' => $m['campaña']['nombre'] ?? '',
                'leads_tocados'      => $m['leads_tocados'],
                'leads_entregados'   => $m['leads_entregados'],
                'leads_abrieron'     => $m['leads_abrieron'],
                'leads_apertura_rate'=> $m['leads_apertura_rate'],
                'leads_respondieron' => $m['leads_respondieron'],
                'leads_respuesta_rate' => $m['leads_respuesta_rate'],
                'leads_positivas'    => $m['leads_positivas'],
                'leads_prr'          => $m['leads_prr'],
                'aceptados'          => $m['aceptados'],
                'aperturas_totales'  => $m['aperturas_totales'],
                'variantes'          => $m['variantes'],
            ];
        }
    }

    // ─── Plantillas más usadas (usos + aperturas) ───
    $ctx['plantillas'] = [];
    $res = $db->query(
        "SELECT COALESCE(p.id,0) AS pid, COALESCE(p.nombre,'(sin plantilla)') AS nombre, COALESCE(p.categoria,'') AS categoria,
                COUNT(e.id) AS usos, COUNT(DISTINCT a.tracking_id) AS aperturas
         FROM envios e
         LEFT JOIN plantillas p ON p.id = e.plantilla_id
         LEFT JOIN aperturas a ON a.tracking_id = e.tracking_id
         WHERE COALESCE(e.es_test,0) = 0
         GROUP BY pid, nombre, categoria
         ORDER BY usos DESC LIMIT 10"
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $ctx['plantillas'][] = [
            'id' => (int)$r['pid'], 'nombre' => $r['nombre'], 'categoria' => $r['categoria'],
            'usos' => (int)$r['usos'], 'aperturas' => (int)$r['aperturas'],
        ];
    }

    // ─── Respuestas por clasificación ───
    $ctx['clasificaciones'] = [];
    $res = $db->query("SELECT clasificacion, COUNT(*) n FROM respuestas WHERE COALESCE(es_rebote,0)=0 GROUP BY clasificacion ORDER BY n DESC");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $ctx['clasificaciones'][$r['clasificacion']] = (int)$r['n'];

    // ─── Envíos de los últimos 7 días ───
    $ctx['envios_ultimos_7dias'] = [];
    $res = $db->query("SELECT date(fecha_envio) AS dia, COUNT(*) n FROM envios WHERE COALESCE(es_test,0)=0 AND date(fecha_envio) >= date('now','-6 days') GROUP BY date(fecha_envio) ORDER BY dia");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $ctx['envios_ultimos_7dias'][$r['dia']] = (int)$r['n'];

    // ─── Leads calientes sin respuesta (más aperturas sin haber respondido) ───
    $ctx['leads_calientes_sin_respuesta'] = [];
    $res = $db->query(
        "SELECT c.id, c.nombre_club, c.federacion, COUNT(DISTINCT a.tracking_id) AS aperturas, COUNT(DISTINCT e.id) AS envios
         FROM clubes_crm c
         JOIN envios e ON LOWER(e.email) = LOWER(c.email)
         JOIN aperturas a ON a.tracking_id = e.tracking_id
         WHERE COALESCE(e.es_test,0)=0{$ex}
           AND NOT EXISTS (SELECT 1 FROM respuestas r WHERE r.lead_id = c.id AND COALESCE(r.es_rebote,0)=0)
         GROUP BY c.id ORDER BY aperturas DESC, envios DESC LIMIT 8"
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $ctx['leads_calientes_sin_respuesta'][] = [
            'id' => (int)$r['id'], 'nombre' => $r['nombre_club'], 'aperturas' => (int)$r['aperturas'], 'envios' => (int)$r['envios'],
        ];
    }

    // ─── Detalle de aperturas (únicas vs totales + distribución por lead) ───
    $ctx['aperturas_detalle'] = [];
    $ctx['aperturas_detalle']['leads_unicos'] = $ctx['leads_abrieron'];
    $ctx['aperturas_detalle']['aperturas_totales'] = (int)$db->querySingle("SELECT COUNT(a.id) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE COALESCE(e.es_test,0) = 0");
    $ctx['aperturas_detalle']['ratio_por_lead'] = $ctx['leads_abrieron'] > 0 ? round($ctx['aperturas_detalle']['aperturas_totales'] / $ctx['leads_abrieron'], 2) : 0;
    $distA = ['1' => 0, '2' => 0, '3' => 0, '4_o_mas' => 0];
    $res = $db->query(
        "SELECT n, COUNT(*) AS cnt FROM (
             SELECT c.id, COUNT(a.id) AS n
             FROM clubes_crm c
             JOIN envios e ON LOWER(e.email) = LOWER(c.email)
             JOIN aperturas a ON a.tracking_id = e.tracking_id
             WHERE COALESCE(e.es_test,0) = 0{$ex}
             GROUP BY c.id
         ) GROUP BY n"
    );
    if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $n = (int)$r['n']; if ($n <= 3) $distA[(string)$n] = (int)$r['cnt']; else $distA['4_o_mas'] += (int)$r['cnt']; } }
    $ctx['aperturas_detalle']['distribucion_por_lead'] = $distA;

    // ─── Top leads con más aperturas (calientes reales, con variante y fechas) ───
    $ctx['top_aperturas'] = [];
    $res = $db->query(
        "SELECT c.id, c.nombre_club, e.variant, e.asunto AS asunto_envio,
                COUNT(a.id) AS n_aperturas,
                MIN(a.fecha_apertura) AS primera_apertura,
                MAX(a.fecha_apertura) AS ultima_apertura,
                (SELECT COUNT(*) FROM respuestas r WHERE r.lead_id = c.id AND COALESCE(r.es_rebote,0) = 0) AS respondio
         FROM clubes_crm c
         JOIN envios e ON LOWER(e.email) = LOWER(c.email)
         JOIN aperturas a ON a.tracking_id = e.tracking_id
         WHERE COALESCE(e.es_test,0) = 0{$ex}
         GROUP BY c.id
         ORDER BY n_aperturas DESC, ultima_apertura DESC LIMIT 12"
    );
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $ctx['top_aperturas'][] = [
                'lead_id' => (int)$r['id'], 'club' => $r['nombre_club'], 'variante' => $r['variant'] ?? '(sin)',
                'asunto_envio' => mb_substr((string)$r['asunto_envio'], 0, 70),
                'aperturas' => (int)$r['n_aperturas'], 'primera_apertura' => (string)$r['primera_apertura'],
                'ultima_apertura' => (string)$r['ultima_apertura'], 'respondio' => (int)$r['respondio'],
            ];
        }
    }


    // ─── Respuestas detalladas (literal + variante + asunto + clasificación) ───
    $ctx['respuestas_detalle'] = [];
    $res = $db->query(
        "SELECT r.id, r.lead_id, c.nombre_club, r.remitente, r.subject AS asunto_respuesta, r.clasificacion,
                r.fecha_respuesta, e.variant, e.asunto AS asunto_envio, COALESCE(r.cuerpo,'') AS cuerpo
         FROM respuestas r
         JOIN envios e ON e.id = r.envio_id
         LEFT JOIN clubes_crm c ON c.id = r.lead_id
         WHERE COALESCE(r.es_rebote,0) = 0
         ORDER BY r.id DESC LIMIT 15"
    );
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $ctx['respuestas_detalle'][] = [
                'lead_id' => (int)$r['lead_id'], 'club' => $r['nombre_club'] ?: $r['remitente'],
                'clasificacion' => $r['clasificacion'], 'variante' => $r['variant'] ?? '(sin)',
                'asunto_respuesta' => mb_substr((string)$r['asunto_respuesta'], 0, 80),
                'asunto_envio' => mb_substr((string)$r['asunto_envio'], 0, 80),
                'cuerpo' => limpiarCuerpoBasico((string)$r['cuerpo']), 'fecha' => (string)$r['fecha_respuesta'],
            ];
        }
    }

    // ─── Seguimiento de las respuestas positivas (¿se respondió? ¿qué se envió?) ───
    $ctx['positivas_seguimiento'] = [];
    $res = $db->query(
        "SELECT c.id AS lead_id, c.nombre_club, r.fecha_respuesta, r.clasificacion
         FROM respuestas r JOIN clubes_crm c ON c.id = r.lead_id
         WHERE COALESCE(r.es_rebote,0) = 0 AND upper(r.clasificacion) IN ('POSITIVE','INTERESADO')
         ORDER BY r.id DESC LIMIT 10"
    );
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $lid = (int)$r['lead_id'];
            $enviosPost = [];
            $resE = $db->query("SELECT asunto, fecha_envio, estado FROM envios WHERE lead_id = {$lid} AND COALESCE(es_test,0) = 0 ORDER BY id DESC LIMIT 5");
            if ($resE) { while ($e = $resE->fetchArray(SQLITE3_ASSOC)) { $enviosPost[] = ['asunto' => mb_substr((string)$e['asunto'], 0, 70), 'fecha' => $e['fecha_envio'], 'estado' => $e['estado']]; } }
            $ultResp = (string)$db->querySingle("SELECT subject FROM respuestas WHERE lead_id = {$lid} AND COALESCE(es_rebote,0) = 0 ORDER BY id DESC LIMIT 1");
            $ctx['positivas_seguimiento'][] = [
                'lead_id' => $lid, 'club' => $r['nombre_club'], 'fecha_respuesta' => $r['fecha_respuesta'],
                'clasificacion' => $r['clasificacion'], 'envios_posteriores' => $enviosPost, 'ultima_respuesta' => mb_substr($ultResp, 0, 80),
            ];
        }
    }

    // ─── Segmentación de la base de leads ───
    $exC = " NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')";
    $ctx['segmentacion'] = [];
    $ctx['segmentacion']['total'] = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC}");
    $ctx['segmentacion']['por_federacion'] = [];
    $res = $db->query("SELECT COALESCE(NULLIF(federacion,''),'(sin federación)') AS fed, COUNT(*) n FROM clubes_crm WHERE{$exC} GROUP BY fed ORDER BY n DESC LIMIT 8");
    if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) $ctx['segmentacion']['por_federacion'][$r['fed']] = (int)$r['n']; }
    $emailGenerico = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND (LOWER(email) LIKE 'info@%' OR LOWER(email) LIKE 'contacto@%' OR LOWER(email) LIKE 'contact@%' OR LOWER(email) LIKE 'administracion@%' OR LOWER(email) LIKE 'gerencia@%' OR LOWER(email) LIKE 'direccion@%' OR LOWER(email) LIKE 'secretaria@%' OR LOWER(email) LIKE 'club@%' OR LOWER(email) LIKE 'presidencia@%')");
    $ctx['segmentacion']['email_generico_info_contacto'] = $emailGenerico;
    $ctx['segmentacion']['email_generico_resto'] = max(0, $ctx['segmentacion']['total'] - $emailGenerico);
    $ctx['segmentacion']['con_whatsapp'] = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND tiene_whatsapp = 1");
    $ctx['segmentacion']['volumen'] = [
        '<50' => (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND (volumen_estimado IS NULL OR volumen_estimado < 50)"),
        '50_99' => (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND volumen_estimado >= 50 AND volumen_estimado < 100"),
        '100_199' => (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND volumen_estimado >= 100 AND volumen_estimado < 200"),
        '200_mas' => (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE{$exC} AND volumen_estimado >= 200"),
    ];


    // ─── Entregabilidad (rebotes por dominio, cuentas SMTP, distribución) ───
    $ctx['entregabilidad'] = [];
    $ctx['entregabilidad']['rebotes'] = $ctx['rebotes'];
    $ctx['entregabilidad']['tasa_rebote_pct'] = $ctx['emails_enviados'] > 0 ? round($ctx['rebotes'] / $ctx['emails_enviados'] * 100, 2) : 0;
    // Dominio del email que rebotó: se extrae del cuerpo del NDR (no hay columna
    // email en respuestas; los rebotes tienen remitente Mailer-Daemon).
    $ctx['entregabilidad']['rebotes_por_dominio'] = [];
    $res = $db->query("SELECT cuerpo FROM respuestas WHERE COALESCE(es_rebote,0) = 1");
    $dominios = [];
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $body = (string)$r['cuerpo'];
            if (preg_match('/Recipient Address:\s*[^@\s]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/i', $body, $m)) {
                $d = strtolower($m[1]);
            } elseif (preg_match('/(?:to:|original recipient:|recipient:)\s*[^@\s]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/i', $body, $m)) {
                $d = strtolower($m[1]);
            } else {
                $d = '(sin dominio identificable)';
            }
            $dominios[$d] = ($dominios[$d] ?? 0) + 1;
        }
    }
    arsort($dominios);
    $ctx['entregabilidad']['rebotes_por_dominio'] = array_slice($dominios, 0, 10, true);
    $ctx['entregabilidad']['envios_por_cuenta'] = [];
    $res = $db->query("SELECT COALESCE(cuenta_emision,'(sin cuenta)') AS cuenta, COUNT(*) n FROM envios WHERE COALESCE(es_test,0) = 0 GROUP BY cuenta ORDER BY n DESC LIMIT 12");
    if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) $ctx['entregabilidad']['envios_por_cuenta'][$r['cuenta']] = (int)$r['n']; }
    $ctx['entregabilidad']['rebotes_por_cuenta'] = [];
    $res = $db->query("SELECT COALESCE(destinatario,'(sin cuenta)') AS cuenta, COUNT(*) n FROM respuestas WHERE COALESCE(es_rebote,0) = 1 GROUP BY cuenta ORDER BY n DESC LIMIT 12");
    if ($res) { while ($r = $res->fetchArray(SQLITE3_ASSOC)) $ctx['entregabilidad']['rebotes_por_cuenta'][$r['cuenta']] = (int)$r['n']; }

    // ─── Tabla de leads con actividad (para análisis de campaña) ───
    // Campos: lead_id, variante, fecha_envio, entregado, n_aperturas, primera/ultima
    // apertura, respondio, clasificacion, rebote. Limitada a los más recientes.
    $ctx['tabla_leads'] = [];
    $res = $db->query(
        "SELECT c.id AS lead_id,
                (SELECT variant FROM envios e3 WHERE e3.lead_id = c.id AND COALESCE(e3.es_test,0) = 0 ORDER BY e3.id DESC LIMIT 1) AS variant,
                (SELECT MAX(fecha_envio) FROM envios e4 WHERE e4.lead_id = c.id AND COALESCE(e4.es_test,0) = 0) AS fecha_envio,
                (SELECT MAX(CASE WHEN resultado_envio = 'ACCEPTED' THEN 1 ELSE 0 END) FROM envios e5 WHERE e5.lead_id = c.id AND COALESCE(e5.es_test,0) = 0) AS entregado,
                (SELECT COUNT(*) FROM aperturas a WHERE a.tracking_id IN (SELECT tracking_id FROM envios e6 WHERE e6.lead_id = c.id AND COALESCE(e6.es_test,0) = 0)) AS n_aperturas,
                (SELECT MIN(fecha_apertura) FROM aperturas a2 WHERE a2.tracking_id IN (SELECT tracking_id FROM envios e7 WHERE e7.lead_id = c.id AND COALESCE(e7.es_test,0) = 0)) AS primera_apertura,
                (SELECT MAX(fecha_apertura) FROM aperturas a3 WHERE a3.tracking_id IN (SELECT tracking_id FROM envios e8 WHERE e8.lead_id = c.id AND COALESCE(e8.es_test,0) = 0)) AS ultima_apertura,
                (SELECT COUNT(*) FROM respuestas r WHERE r.lead_id = c.id AND COALESCE(r.es_rebote,0) = 0) AS respondio,
                (SELECT clasificacion FROM respuestas r2 WHERE r2.lead_id = c.id AND COALESCE(r2.es_rebote,0) = 0 ORDER BY r2.id DESC LIMIT 1) AS clasificacion,
                (SELECT COUNT(*) FROM respuestas r3 WHERE r3.lead_id = c.id AND COALESCE(r3.es_rebote,0) = 1) AS rebote
         FROM envios e
         JOIN clubes_crm c ON LOWER(e.email) = LOWER(c.email)
         WHERE COALESCE(e.es_test,0) = 0{$ex}
         GROUP BY c.id
         ORDER BY fecha_envio DESC
         LIMIT 150"
    );
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $ctx['tabla_leads'][] = [
                'lead_id' => (int)$r['lead_id'], 'variante' => $r['variant'] ?? '(sin)',
                'fecha_envio' => (string)$r['fecha_envio'], 'entregado' => (int)$r['entregado'],
                'n_aperturas' => (int)$r['n_aperturas'], 'primera_apertura' => (string)$r['primera_apertura'],
                'ultima_apertura' => (string)$r['ultima_apertura'], 'respondio' => (int)$r['respondio'],
                'clasificacion' => (string)$r['clasificacion'], 'rebote' => (int)$r['rebote'],
            ];
        }
    }


    return $ctx;
}
