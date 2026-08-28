<?php
/**
 * atencion_lead.php — Modal de Atención a Medida por Lead (Asistente IA v2).
 * Junta la charla real del lead (envios, aperturas, respuestas, mockup, presupuesto)
 * y redacta el email de seguimiento con el LLM configurado (contexto de producto).
 * El usuario edita y envía desde el modal (human-in-the-loop).
 */
declare(strict_types=1);

require_once __DIR__ . '/llm.php';

function atencion_contextoProducto(SQLite3 $db): string {
    return trim((string)$db->querySingle("SELECT valor FROM config WHERE clave = 'ia_conocimiento_producto'"));
}

/**
 * atencion_limpiarCuerpoRespuesta — Limpia el cuerpo crudo de una respuesta IMAP
 * (multipart, quoted-printable, base64, HTML) y devuelve texto plano UTF-8.
 */
function atencion_limpiarCuerpoRespuesta(string $cuerpo): string {
    $cuerpo = trim((string)$cuerpo);

    // 1) Multipart con boundary: extraer la parte text/plain (o text/html).
    if (preg_match('/boundary\s*=\s*"?([^";\r\n]+)"?/i', $cuerpo, $bm)) {
        $boundary = preg_quote(trim($bm[1]), '/');
        $partes = preg_split('/--' . $boundary . '[^\r\n]*/i', $cuerpo);
        $mejor = '';
        foreach ($partes as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (preg_match('/^([\s\S]*?\r?\n\r?\n)([\s\S]*)$/', $p, $mm)) {
                $cab = $mm[1]; $data = $mm[2];
            } else {
                $cab = ''; $data = $p;
            }
            if (stripos($cab, 'text/plain') !== false) {
                return atencion_normalizarTexto(atencion_decodificarParte($cab, $data));
            }
            if ($mejor === '' && stripos($cab, 'text/html') !== false) {
                $mejor = atencion_decodificarParte($cab, $data);
            }
        }
        if ($mejor !== '') return atencion_normalizarTexto($mejor);
    }

    // 2) Con cabeceras MIME en línea plana (no multipart): separar y decodificar.
    if (preg_match('/^([\s\S]*?\r?\n\r?\n)([\s\S]*)$/', $cuerpo, $mm)
        && stripos($mm[1], 'Content-Transfer-Encoding') !== false) {
        return atencion_normalizarTexto(atencion_decodificarParte($mm[1], $mm[2]));
    }

    // 3) Sin cabeceras: heurística QP (secuencias =XX=XX).
    if (strpos($cuerpo, '=') !== false && preg_match('/(=[0-9A-F]{2}){2,}/i', $cuerpo)) {
        $cuerpo = quoted_printable_decode($cuerpo);
    }
    return atencion_normalizarTexto($cuerpo);
}

/**
 * atencion_decodificarParte — Decodifica una parte MIME según su encoding.
 */
function atencion_decodificarParte(string $cabeceras, string $data): string {
    $enc = '8bit';
    if (preg_match('/Content-Transfer-Encoding:\s*([a-z0-9_-]+)/i', $cabeceras, $em)) {
        $enc = strtolower($em[1]);
    }
    if ($enc === 'quoted-printable') return quoted_printable_decode($data);
    if ($enc === 'base64') return (string)base64_decode($data);
    return $data;
}

/**
 * atencion_normalizarTexto — Limpia HTML residual y whitespace del texto.
 */
function atencion_normalizarTexto(string $t): string {
    $t = strip_tags($t);
    $t = preg_replace('/^--[0-9a-fA-F]{10,}\s*/', '', $t); // resto de boundary inicial
    $t = str_replace(["\r", '=3D'], '', $t);               // =3D es '=' QP residual
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return trim($t);
}

/**
 * charlaLead — Reconstruye el contexto real de un lead para el modal de atención.
 */
function charlaLead(SQLite3 $db, int $leadId, int $campaignId = 0): array {
    $lead = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$leadId}", true);
    if (!$lead) return ['ok' => false, 'error' => 'Lead no encontrado'];

    $com = "AND COALESCE(e.es_test,0) = 0";

    // Envíos salientes (los 20 últimos, con CUERPO limpio para la charla)
    $envios = [];
    $r = $db->query("SELECT e.id, e.fecha_envio, e.variant, e.asunto, e.cuerpo_mensaje, e.cuenta_emision, e.smtp_id, e.estado, e.plantilla_id FROM envios e WHERE e.lead_id = {$leadId} {$com} ORDER BY e.id DESC LIMIT 20");
    if ($r) {
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
            $x['cuerpo_charla'] = mb_substr(atencion_limpiarCuerpoRespuesta((string)($x['cuerpo_mensaje'] ?? '')), 0, 600);
            unset($x['cuerpo_mensaje']); // no exponer el HTML crudo al frontend
            // Adjuntos salientes de este envío (presupuesto/boceto/manuales).
            $adjuntosEnv = [];
            $rAE = $db->query("SELECT id, nombre, mime, tamano FROM envios_adjuntos WHERE envio_id = " . (int)$x['id'] . " ORDER BY id");
            if ($rAE) { while ($xAE = $rAE->fetchArray(SQLITE3_ASSOC)) $adjuntosEnv[] = $xAE; }
            $x['adjuntos'] = $adjuntosEnv;
            $envios[] = $x;
        }
    }

    // Aperturas totales + primera apertura
    $aperturasTotal   = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.lead_id = {$leadId} {$com}");
    $primeraApertura  = $db->querySingle("SELECT MIN(a.fecha_apertura) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.lead_id = {$leadId} {$com}");

    // Respuestas recibidas (las 10 últimas, cuerpo limpio y truncado)
    $respuestas = [];
    $r = $db->query("SELECT r.id, r.fecha_respuesta, r.remitente, r.subject, r.cuerpo, r.clasificacion, COALESCE(r.es_rebote,0) AS es_rebote FROM respuestas r WHERE r.lead_id = {$leadId} ORDER BY r.id DESC LIMIT 10");
    if ($r) {
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
            $x['cuerpo'] = mb_substr(atencion_limpiarCuerpoRespuesta((string)$x['cuerpo']), 0, 600);
            // Adjuntos de la respuesta (metadatos para el hilo; descarga autenticada).
            $adjuntos = [];
            $rA = $db->query("SELECT id, nombre, mime, tamano FROM respuestas_adjuntos WHERE respuesta_id = " . (int)$x['id'] . " ORDER BY id");
            if ($rA) { while ($xA = $rA->fetchArray(SQLITE3_ASSOC)) $adjuntos[] = $xA; }
            $x['adjuntos'] = $adjuntos;
            $respuestas[] = $x;
        }
    }

    // Mockup y presupuesto más recientes
    $mockup      = $db->querySingle("SELECT id, estado, solicitado_en, enviado_en FROM mockups WHERE lead_id = {$leadId} ORDER BY id DESC LIMIT 1", true);
    $presupuesto = $db->querySingle("SELECT id, version, importe_total, estado, fecha FROM presupuestos WHERE lead_id = {$leadId} ORDER BY id DESC LIMIT 1", true);

    // Cuenta SMTP heredada del último envío real + cuentas activas disponibles
    $smtpHeredada = (int)$db->querySingle("SELECT e.smtp_id FROM envios e WHERE e.lead_id = {$leadId} {$com} AND e.smtp_id > 0 ORDER BY e.id DESC LIMIT 1");
    $cuentas = [];
    $r = $db->query("SELECT id, email FROM cuentas_smtp WHERE activa = 1 ORDER BY email");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $cuentas[] = $x; }

    // Plantillas para el selector del modal: TODAS las de email activas
    // (prospección, seguimiento, respuestas) para elegir cualquier formato.
    $plantillas = [];
    $r = $db->query(
        "SELECT p.id, p.nombre, p.asunto, p.cuerpo
         FROM plantillas p
         WHERE p.activo = 1 AND p.tipo != 'whatsapp'
         ORDER BY p.categoria, p.nombre"
    );
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $plantillas[] = $x; }

    return [
        'ok'              => true,
        'lead'            => $lead,
        'envios'          => $envios,
        'aperturas_total' => $aperturasTotal,
        'primera_apertura'=> $primeraApertura,
        'respuestas'      => $respuestas,
        'mockup'          => $mockup,
        'presupuesto'     => $presupuesto,
        'smtp_heredada'   => $smtpHeredada,
        'cuentas_smtp'    => $cuentas,
        'plantillas'      => $plantillas,
        'contacto_real'   => trim((string)($lead['persona_contacto'] ?? '')),
    ];
}


/**
 * atencion_extraerTextoAdjunto — Extrae texto legible de un adjunto enviado
 * (para que la IA sepa qué se entregó: presupuesto, boceto, tarifas...).
 * Soporta PDFs generados por construirPdfSimple (texto en streams "(...) Tj")
 * y archivos de texto plano. Otros tipos devuelven '' (solo se indica el nombre).
 */
function atencion_extraerTextoAdjunto(string $nombre, string $mime, string $datos): string
{
    $mimeL = strtolower(trim((string)$mime));
    $nombreL = strtolower(trim((string)$nombre));
    if ($mimeL === 'text/plain' || str_ends_with($nombreL, '.txt')) {
        return trim($datos);
    }
    if ($mimeL === 'application/pdf' || str_ends_with($nombreL, '.pdf')) {
        $texto = '';
        if (preg_match_all('/\(([^)]*)\)\s*Tj/i', $datos, $m)) {
            foreach ($m[1] as $t) {
                $t = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], (string)$t);
                // El PDF se genera en Windows-1252 → convertir a UTF-8 para tildes/ñ.
                $t = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $t);
                $texto .= ($t === false ? $m[1] : $t) . "\n";
            }
        }
        return trim($texto);
    }
    // Imágenes, ZIP y otros: sin texto extraíble (se indica solo el nombre).
    return '';
}


/**
 * contextoDialogoCompleto — Construye el DIÁLOGO COMPLETO y estructurado de un
 * lead (envíos de FutProtec + respuestas del club + aperturas) en orden
 * cronológico, con el CUERPO REAL de cada mensaje (no solo asunto). Es lo que la
 * IA necesita para responder con coherencia a la última pregunta del club.
 */
function contextoDialogoCompleto(SQLite3 $db, int $leadId): string
{
    $lineas = [];

    // Envíos salientes (con cuerpo completo, no solo asunto)
    $r = $db->query(
        "SELECT e.id, e.fecha_envio, e.asunto, e.cuerpo_mensaje, e.cuenta_emision, e.variant
         FROM envios e WHERE e.lead_id = {$leadId} AND COALESCE(e.es_test,0) = 0
         ORDER BY e.id ASC"
    );
    if ($r) {
        while ($e = $r->fetchArray(SQLITE3_ASSOC)) {
            $cuerpo = atencion_limpiarCuerpoRespuesta((string)($e['cuerpo_mensaje'] ?? ''));
            $cuerpo = mb_substr($cuerpo, 0, 800);
            $bloque = "[envío {$e['fecha_envio']}] de {$e['cuenta_emision']} · variante " . ($e['variant'] ?? '')
                . "\nASUNTO: " . trim((string)$e['asunto'])
                . "\nCUERPO: " . $cuerpo;

            // Adjuntos enviados (presupuesto, boceto, tarifas...) con su contenido,
            // para que la IA reconozca QUÉ se ha entregado y qué no.
            $rAdj = $db->query("SELECT nombre, mime, datos FROM envios_adjuntos WHERE envio_id = " . (int)$e['id'] . " ORDER BY id");
            if ($rAdj) {
                $adjBloque = [];
                while ($adj = $rAdj->fetchArray(SQLITE3_ASSOC)) {
                    $txt = mb_substr(atencion_extraerTextoAdjunto((string)$adj['nombre'], (string)$adj['mime'], (string)$adj['datos']), 0, 400);
                    $adjBloque[] = trim((string)$adj['nombre'])
                        . ($txt !== '' ? " → \"{$txt}\"" : ' (sin texto extraíble)');
                }
                if (!empty($adjBloque)) {
                    $bloque .= "\nADJUNTOS ENVIADOS: " . implode('; ', $adjBloque);
                }
            }
            $lineas[] = $bloque;
        }
    }

    // Aperturas (señal de interés)
    $nApert = (int)$db->querySingle(
        "SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
         WHERE e.lead_id = {$leadId} AND COALESCE(e.es_test,0) = 0"
    );
    if ($nApert > 0) {
        $lineas[] = "[aperturas] {$nApert}";
    }

    // Respuestas entrantes (con cuerpo completo + clasificación)
    $r = $db->query(
        "SELECT r.fecha_respuesta, r.remitente, r.subject, r.cuerpo, r.clasificacion
         FROM respuestas r WHERE r.lead_id = {$leadId} ORDER BY r.id ASC"
    );
    if ($r) {
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
            $cuerpo = atencion_limpiarCuerpoRespuesta((string)($x['cuerpo'] ?? ''));
            $cuerpo = mb_substr($cuerpo, 0, 800);
            $lineas[] = "[respuesta {$x['fecha_respuesta']}] de {$x['remitente']} · clasificación: " . ($x['clasificacion'] ?? '')
                . "\nASUNTO: " . trim((string)$x['subject'])
                . "\nCUERPO: " . $cuerpo;
        }
    }

    if (empty($lineas)) {
        return 'Sin historial previo (primer contacto).';
    }
    return implode("\n\n", $lineas);
}


/**
 * generarEmailIA — Redacta asunto + cuerpo de seguimiento con el LLM.
 * Reglas: sin inventar nombre de contacto, datos reales del historial,
 * conocimiento de producto, plantilla base opcional. Máx 120 palabras.
 */
function generarEmailIA(SQLite3 $db, int $leadId, ?int $plantillaId = null): ?array {
    $charla = charlaLead($db, $leadId);
    if (empty($charla['ok'])) return null;
    $lead = $charla['lead'];
    $ctx = atencion_contextoProducto($db);

    // Ramal de interés: variante del test ABC con más aperturas del lead.
    // Guía al LLM para CONTINUAR el mismo ángulo que el club ya validó.
    $varianteDom = '';
    $rV = $db->query(
        "SELECT e.variant, COUNT(a.id) AS n FROM envios e
         JOIN aperturas a ON a.tracking_id = e.tracking_id
         WHERE LOWER(e.email) = LOWER('" . $db->escapeString($lead['email']) . "') AND COALESCE(e.es_test,0)=0
           AND e.variant IS NOT NULL AND e.variant != ''
         GROUP BY e.variant ORDER BY n DESC LIMIT 1"
    );
    if ($rV && ($fV = $rV->fetchArray(SQLITE3_ASSOC))) {
        $varianteDom = (string)$fV['variant'];
    }

    // Interés actual del lead: clasificación de su última respuesta humana.
    // Guía al LLM para adaptar la estrategia (avanzar, resolver duda o no insistir).
    $interesLead = (string)$db->querySingle(
        "SELECT clasificacion FROM respuestas WHERE lead_id = " . (int)$lead['id'] . " AND COALESCE(es_rebote,0)=0 ORDER BY id DESC LIMIT 1"
    );

    $contacto = $charla['contacto_real'];
    if ($contacto !== '') {
        $reglaSaludo = "Hay persona de contacto: {$contacto}. Usa su nombre en el saludo.";
    } else {
        $reglaSaludo = 'NO hay persona de contacto (email genérico). NO inventes nombres: usa "Hola, responsables del club:" o un saludo sin nombre.';
    }

    // Diálogo COMPLETO (envíos con cuerpo + respuestas completas + aperturas).
    $historial = contextoDialogoCompleto($db, (int)$lead['id']);

    // Datos comerciales reales (mockup/presupuesto) como contexto adicional.
    $contextoExtra = '';
    if ($charla['mockup']) {
        $contextoExtra .= "\n[mockup] estado: {$charla['mockup']['estado']} (solicitado: {$charla['mockup']['solicitado_en']})";
    }
    if ($charla['presupuesto']) {
        $contextoExtra .= "\n[presupuesto] v{$charla['presupuesto']['version']} · " . number_format((float)$charla['presupuesto']['importe_total'], 0, ',', '.') . " € · estado: {$charla['presupuesto']['estado']}";
    }
    if ($contextoExtra !== '') {
        $historial .= $contextoExtra;
    }

    // ── FORMATO DE REFERENCIA: la IA lee las plantillas del sistema ──
    // Si hay una plantilla concreta seleccionada, es la BASE. Además se listan
    // las plantillas de la campaña (incluidas variantes A/B/C y de seguimiento)
    // para que la IA imite el tono/estructura/cercanía del negocio al redactar
    // la respuesta al club.
    $formatoReferencia = '';
    $refs = [];
    $vistas = [];

    // 1) Plantilla BASE concreta (si se seleccionó en el modal): estructura principal.
    if ($plantillaId !== null && $plantillaId > 0) {
        $p = $db->querySingle("SELECT id, nombre, asunto, cuerpo FROM plantillas WHERE id = {$plantillaId} AND activo = 1", true);
        if ($p) {
            $vistas[(int)$p['id']] = true;
            $refs[] = "PLANTILLA BASE (usa esta como estructura principal, adaptada al contexto):\nASUNTO: {$p['asunto']}\nCUERPO: " . mb_substr($p['cuerpo'], 0, 250);
        }
    }

    // 2) Plantillas de la CAMPAÑA (incluye variantes A/B/C del test de prospección).
    if (!empty($charla['plantillas'])) {
        foreach ($charla['plantillas'] as $tp) {
            $tid = (int)($tp['id'] ?? 0);
            if (isset($vistas[$tid])) continue;
            $vistas[$tid] = true;
            $refs[] = "Plantilla '{$tp['nombre']}':\nASUNTO: {$tp['asunto']}\nCUERPO: " . mb_substr((string)$tp['cuerpo'], 0, 200);
        }
    }

    // 3) TODAS las plantillas de email activas (prospección, seguimiento,
    //    respuestas) como FORMATO de referencia para la respuesta al club.
    $resTpl = $db->query(
        "SELECT id, nombre, asunto, cuerpo FROM plantillas
         WHERE activo = 1 AND tipo != 'whatsapp'
         ORDER BY categoria, id LIMIT 10"
    );
    if ($resTpl) {
        while ($tpAll = $resTpl->fetchArray(SQLITE3_ASSOC)) {
            $tidAll = (int)$tpAll['id'];
            if (isset($vistas[$tidAll])) continue;
            $vistas[$tidAll] = true;
            $refs[] = "Plantilla '{$tpAll['nombre']}':\nASUNTO: {$tpAll['asunto']}\nCUERPO: " . mb_substr((string)$tpAll['cuerpo'], 0, 200);
            if (count($refs) >= 8) break; // tope razonable para no saturar el prompt
        }
    }

    if (!empty($refs)) {
        $formatoReferencia = "FORMATO DE REFERENCIA — imita el tono, la estructura y la cercanía de estas plantillas del negocio (no las copies textualmente; adáptalas al diálogo del club):\n" . implode("\n\n", $refs);
    }

    $system = "Eres un asistente comercial B2B de FutProtec (software de gestión para clubes de fútbol) que RESPONDE dentro de una conversación por email ya iniciada."
        . ($ctx !== '' ? "\n\nCONOCIMIENTO DE PRODUCTO (úsalo como base):\n" . mb_substr($ctx, 0, 4000) : '')
        . "\n\nREGLA DE SALUDO: {$reglaSaludo}"
        . ($varianteDom !== '' ? "\nRAMAL DE INTERÉS: el lead validó con sus aperturas el enfoque de la variante {$varianteDom} del test de prospección (A=General/Producto, B=Identidad/Cantera, C=Financiero/Rentabilidad). CONTINÚA exactamente esa misma línea argumental: no cambies de tema ni mezcles ángulos." : '')
        . ($interesLead !== '' ? "\nINTERÉS ACTUAL DEL LEAD: su última respuesta se clasificó como '{$interesLead}'. Adáptate a ese interés: si es positivo (solicita muestra/presupuesto) AVANZA hacia la propuesta concreta sin rodeos; si expresa dudas o es neutral, resuelve la objeción y refuerza la MISMA variante; si no interesa, no insistas y deja la puerta abierta." : '')
        . ($formatoReferencia !== '' ? "\nIMITA el tono y la estructura del FORMATO DE REFERENCIA del negocio que se te da (sin copiar textualmente)." : '')
        . "\nTAREA: lee el HISTORIAL CRONOLÓGICO del club y responde DIRECTAMENTE a la última pregunta, objeción o solicitud que haya hecho (presupuesto, boceto de espinilleras, dudas, plazos, etc.)."
        . "\nADJUNTOS ENVIADOS: el HISTORIAL incluye en cada envío la línea ADJUNTOS ENVIADOS con el nombre y el contenido extraído de los archivos que se entregaron (presupuesto, boceto, tarifas). Usa esa información para saber qué YA se envió: no ofrezcas ni repitas algo ya entregado, y si falta algo pendiente, indícalo con naturalidad."
        . "\nReglas: no repitas mensajes anteriores ni uses frases de prospección inicial; usa SOLO datos reales del historial (no inventes hechos, precios ni plazos); sé concreto y cercano; máximo 140 palabras."
        . "\nResponde EXACTAMENTE con este formato de dos líneas:\nASUNTO: <texto>\nCUERPO: <texto>";

    $user = "CLUB: {$lead['nombre_club']} (" . trim((string)$lead['federacion']) . ")\n"
        . "EMAIL: {$lead['email']}\n"
        . ($contacto !== '' ? "CONTACTO: {$contacto}\n" : '')
        . "HISTORIAL REAL:\n{$historial}\n"
        . ($formatoReferencia !== '' ? "\n{$formatoReferencia}\n" : '')
        . "\nEscribe el email de seguimiento.";

    $texto = llm_chat($db, $system, $user, 600, 0.6);
    if ($texto === null) return null;

    $asunto = ''; $cuerpo = '';
    if (preg_match('/ASUNTO:\s*(.*)/i', $texto, $m)) $asunto = trim($m[1]);
    if (preg_match('/CUERPO:\s*([\s\S]*)$/i', $texto, $m)) $cuerpo = trim($m[1]);
    if ($asunto === '' && $cuerpo === '') $cuerpo = $texto;

    return ['asunto' => $asunto, 'cuerpo' => $cuerpo];
}

/**
 * IA ANALIZA EL LEAD (AI Command Center): lee TODA la conversación (envíos con
 * cuerpo, respuestas completas, aperturas, mockup, presupuesto) y devuelve un
 * análisis ejecutivo: resumen, intención comercial con % de confianza, motivo y
 * la próxima acción sugerida. Persiste en ia_lead_analisis.
 *
 * @return array|null {resumen, intencion, confianza, proxima_accion, motivo}
 */
function ia_analizar_lead(SQLite3 $db, int $leadId, int $campaignId = 0): ?array
{
    // Asegurar esquema (idempotente): la tabla puede no existir si el sync
    // IMAP aún no ha corrido en este entorno.
    $db->exec("
        CREATE TABLE IF NOT EXISTS ia_lead_analisis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id INTEGER NOT NULL,
            resumen TEXT DEFAULT '',
            intencion TEXT DEFAULT '',
            confianza REAL DEFAULT 0,
            proxima_accion TEXT DEFAULT '',
            motivo TEXT DEFAULT '',
            creado_el DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ila_lead ON ia_lead_analisis(lead_id)");

    $charla = charlaLead($db, $leadId, $campaignId);
    if (empty($charla['ok']) || empty($charla['lead'])) return null;
    $lead = $charla['lead'];

    $historial = contextoDialogoCompleto($db, $leadId);
    $ctx = atencion_contextoProducto($db);

    $extra = '';
    if ($charla['mockup']) {
        $extra .= "\n[mockup] estado: {$charla['mockup']['estado']} (solicitado: {$charla['mockup']['solicitado_en']})";
    }
    if ($charla['presupuesto']) {
        $extra .= "\n[presupuesto] v{$charla['presupuesto']['version']} · " . number_format((float)$charla['presupuesto']['importe_total'], 0, ',', '.') . " € · estado: {$charla['presupuesto']['estado']}";
    }
    if ($charla['aperturas_total'] > 0) {
        $extra .= "\n[aperturas] {$charla['aperturas_total']}";
    }

    $system = "Eres el ANALISTA SENIOR DE VENTAS B2B de FutProtec (software de gestión para clubes de fútbol)."
        . " Lee el HISTORIAL COMPLETO de la conversación de un club y produce un análisis ejecutivo accionable."
        . "\nEl HISTORIAL incluye los ADJUNTOS ENVIADOS de cada mensaje (con su contenido extraído): úsalos para reconocer qué se ha entregado (presupuesto, boceto, tarifas...) y qué queda pendiente en tu análisis y próxima acción."
        . ($ctx !== '' ? "\n\nCONOCIMIENTO DE PRODUCTO:\n" . mb_substr($ctx, 0, 4000) : '')
        . "\n\nResponde SOLO con un JSON válido (sin texto fuera), con estas claves exactas:"
        . "\n- resumen: 2-3 frases ejecutivas de qué está pasando en la conversación."
        . "\n- intencion: una de [interesado, duda_precio, baja, neutral, no_interesa, otro, pendiente]. Usa 'pendiente' si NO hay información suficiente para decidir."
        . "\n- confianza: número 0.0 a 1.0 con tu seguridad en la intención (si dudas, <= 0.5)."
        . "\n- proxima_accion: UNA acción concreta y accionable (p.ej. 'Enviar presupuesto con plazos', 'Llamar para resolver la duda de precio', 'Agradecer y esperar', 'Confirmar baja')."
        . "\n- motivo: UNA frase que explique la intención basándote en hechos del historial (nunca inventes)."
        . "\nEjemplo: {\"resumen\":\"...\",\"intencion\":\"duda_precio\",\"confianza\":0.85,\"proxima_accion\":\"...\",\"motivo\":\"...\"}";

    $user = "CLUB: {$lead['nombre_club']} (" . trim((string)$lead['federacion']) . ")\n"
        . "CONTACTO: {$charla['contacto_real']}\n"
        . "EMAIL: {$lead['email']}\n"
        . ($campaignId > 0 ? "CAMPAÑA: {$campaignId}\n" : '')
        . "\nHISTORIAL COMPLETO:\n{$historial}{$extra}";

    $texto = llm_chat($db, $system, $user, 700, 0.2);
    if ($texto === null) return null;

    // Extraer el JSON de la respuesta (robusto ante texto extra).
    $json = '';
    if (preg_match('/\{[^{}]*\}/s', $texto, $m)) {
        $json = $m[0];
    } elseif (preg_match('/\{.*\}/s', $texto, $m)) {
        $json = $m[0];
    } else {
        $json = $texto;
    }
    $datos = json_decode($json, true);
    if (!is_array($datos)) return null;

    $resultado = [
        'resumen'       => (string)($datos['resumen'] ?? ''),
        'intencion'     => (string)($datos['intencion'] ?? 'pendiente'),
        'confianza'     => max(0, min(1, (float)($datos['confianza'] ?? 0))),
        'proxima_accion'=> (string)($datos['proxima_accion'] ?? ''),
        'motivo'        => (string)($datos['motivo'] ?? ''),
    ];
    $validas = ['interesado', 'duda_precio', 'baja', 'neutral', 'no_interesa', 'otro', 'pendiente'];
    if (!in_array($resultado['intencion'], $validas, true)) {
        $resultado['intencion'] = 'pendiente';
    }

    // Persistir (se lee siempre el análisis más reciente por lead).
    $stmt = $db->prepare(
        "INSERT INTO ia_lead_analisis (lead_id, resumen, intencion, confianza, proxima_accion, motivo)
         VALUES (:l, :r, :i, :c, :p, :m)"
    );
    $stmt->bindValue(':l', $leadId, SQLITE3_INTEGER);
    $stmt->bindValue(':r', $resultado['resumen'], SQLITE3_TEXT);
    $stmt->bindValue(':i', $resultado['intencion'], SQLITE3_TEXT);
    $stmt->bindValue(':c', $resultado['confianza'], SQLITE3_FLOAT);
    $stmt->bindValue(':p', $resultado['proxima_accion'], SQLITE3_TEXT);
    $stmt->bindValue(':m', $resultado['motivo'], SQLITE3_TEXT);
    $stmt->execute();

    return $resultado;
}

/**
 * Devuelve el análisis IA más reciente de un lead (si existe).
 */
function ia_analisis_reciente(SQLite3 $db, int $leadId): ?array
{
    if ($leadId <= 0) return null;
    $row = $db->querySingle(
        "SELECT resumen, intencion, confianza, proxima_accion, motivo, creado_el
         FROM ia_lead_analisis WHERE lead_id = {$leadId} ORDER BY id DESC LIMIT 1",
        true
    );
    return $row ?: null;
}

