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

    // Envíos salientes (los 8 últimos)
    $envios = [];
    $r = $db->query("SELECT e.id, e.fecha_envio, e.variant, e.asunto, e.cuenta_emision, e.smtp_id, e.estado, e.plantilla_id FROM envios e WHERE e.lead_id = {$leadId} {$com} ORDER BY e.id DESC LIMIT 8");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $envios[] = $x; }

    // Aperturas totales + primera apertura
    $aperturasTotal   = (int)$db->querySingle("SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.lead_id = {$leadId} {$com}");
    $primeraApertura  = $db->querySingle("SELECT MIN(a.fecha_apertura) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.lead_id = {$leadId} {$com}");

    // Respuestas recibidas (las 5 últimas, cuerpo limpio y truncado)
    $respuestas = [];
    $r = $db->query("SELECT r.id, r.fecha_respuesta, r.remitente, r.subject, r.cuerpo, r.clasificacion FROM respuestas r WHERE r.lead_id = {$leadId} ORDER BY r.id DESC LIMIT 5");
    if ($r) {
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
            $x['cuerpo'] = mb_substr(atencion_limpiarCuerpoRespuesta((string)$x['cuerpo']), 0, 300);
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

    // Plantillas de la campaña activa (para el selector del modal + prefill A)
    $plantillas = [];
    if ($campaignId > 0) {
        $r = $db->query("SELECT p.id, p.nombre, p.asunto, p.cuerpo FROM campaign_plantillas cp JOIN plantillas p ON p.id = cp.plantilla_id WHERE cp.campaign_id = {$campaignId} AND p.activo = 1 ORDER BY p.nombre");
        if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $plantillas[] = $x; }
    }
    // Fallback: si la campaña no tiene plantillas asociadas, listar las activas.
    if (count($plantillas) === 0) {
        $r = $db->query("SELECT id, nombre, asunto, cuerpo FROM plantillas WHERE activo = 1 ORDER BY nombre LIMIT 20");
        if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $plantillas[] = $x; }
    }

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
 * generarEmailIA — Redacta asunto + cuerpo de seguimiento con el LLM.
 * Reglas: sin inventar nombre de contacto, datos reales del historial,
 * conocimiento de producto, plantilla base opcional. Máx 120 palabras.
 */
function generarEmailIA(SQLite3 $db, int $leadId, ?int $plantillaId = null): ?array {
    $charla = charlaLead($db, $leadId);
    if (empty($charla['ok'])) return null;
    $lead = $charla['lead'];
    $ctx = atencion_contextoProducto($db);

    $contacto = $charla['contacto_real'];
    if ($contacto !== '') {
        $reglaSaludo = "Hay persona de contacto: {$contacto}. Usa su nombre en el saludo.";
    } else {
        $reglaSaludo = 'NO hay persona de contacto (email genérico). NO inventes nombres: usa "Hola, responsables del club:" o un saludo sin nombre.';
    }

    // Historial resumido (solo datos reales)
    $lineas = [];
    foreach ($charla['envios'] as $e) {
        $lineas[] = "[enviado {$e['fecha_envio']}] variante {$e['variant']} · asunto: " . trim((string)$e['asunto']) . " · remitente: {$e['cuenta_emision']}";
    }
    if ($charla['aperturas_total'] > 0) {
        $lineas[] = "[aperturas] {$charla['aperturas_total']} (primera: {$charla['primera_apertura']})";
    }
    foreach ($charla['respuestas'] as $x) {
        $lineas[] = "[respuesta {$x['fecha_respuesta']}] de {$x['remitente']}: " . trim((string)$x['cuerpo']);
    }
    if ($charla['mockup']) {
        $lineas[] = "[mockup] estado: {$charla['mockup']['estado']} (solicitado: {$charla['mockup']['solicitado_en']})";
    }
    if ($charla['presupuesto']) {
        $lineas[] = "[presupuesto] v{$charla['presupuesto']['version']} · " . number_format((float)$charla['presupuesto']['importe_total'], 0, ',', '.') . " € · estado: {$charla['presupuesto']['estado']}";
    }
    $historial = implode("\n", $lineas);
    if ($historial === '') $historial = 'Sin historial previo (primer contacto).';

    $plantillaBase = '';
    if ($plantillaId !== null && $plantillaId > 0) {
        $p = $db->querySingle("SELECT asunto, cuerpo FROM plantillas WHERE id = {$plantillaId} AND activo = 1", true);
        if ($p) {
            $plantillaBase = "PLANTILLA BASE (re-escríbela adaptándola al contexto y a los datos reales del historial):\nASUNTO BASE: {$p['asunto']}\nCUERPO BASE:\n{$p['cuerpo']}";
        }
    }

    $system = "Eres un redactor de ventas B2B de un software de gestión de clubes de fútbol (FutProtec)."
        . ($ctx !== '' ? "\n\nCONOCIMIENTO DE PRODUCTO (úsalo como base):\n" . mb_substr($ctx, 0, 2000) : '')
        . "\n\nREGLA DE SALUDO: {$reglaSaludo}"
        . "\nRedacta un email de seguimiento comercial en español, profesional y cercano, máximo 120 palabras, que avance la conversación usando SOLO datos reales del historial (no inventes hechos)."
        . "\nResponde EXACTAMENTE con este formato de dos líneas:\nASUNTO: <texto>\nCUERPO: <texto>";

    $user = "CLUB: {$lead['nombre_club']} (" . trim((string)$lead['federacion']) . ")\n"
        . "EMAIL: {$lead['email']}\n"
        . ($contacto !== '' ? "CONTACTO: {$contacto}\n" : '')
        . "HISTORIAL REAL:\n{$historial}\n"
        . ($plantillaBase !== '' ? "\n{$plantillaBase}\n" : '')
        . "\nEscribe el email de seguimiento.";

    $texto = llm_chat($db, $system, $user, 600, 0.6);
    if ($texto === null) return null;

    $asunto = ''; $cuerpo = '';
    if (preg_match('/ASUNTO:\s*(.*)/i', $texto, $m)) $asunto = trim($m[1]);
    if (preg_match('/CUERPO:\s*([\s\S]*)$/i', $texto, $m)) $cuerpo = trim($m[1]);
    if ($asunto === '' && $cuerpo === '') $cuerpo = $texto;

    return ['asunto' => $asunto, 'cuerpo' => $cuerpo];
}
