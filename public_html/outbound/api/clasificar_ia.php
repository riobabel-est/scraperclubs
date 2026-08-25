<?php
/**
 * clasificar_ia.php — Endpoint STANDALONE de clasificación de intención con IA.
 * FutProtec Outbound CRM.
 *
 * Recibe el cuerpo de una respuesta entrante y, usando el proveedor de IA
 * configurado (DeepSeek, OpenAI, Anthropic, Google Gemini, Mistral o Groq),
 * devuelve una clasificación de intención comercial.
 *
 * PHP 8.x nativo — SiteGround compatible (usa cURL, siempre disponible).
 *
 * Uso (POST):
 *   cuerpo  : texto del mensaje a clasificar
 *   asunto  : (opcional) asunto del mensaje
 *
 * Respuesta JSON:
 *   { ok: true, intencion: "interesado|duda_precio|baja|neutral|no_interesa|otro",
 *     resumen: "..." }
 */

declare(strict_types=1);

header('Content-Type: application/json');

// Helper de cifrado AES-256-GCM (descifra la API key almacenada en config).
require_once __DIR__ . '/../inc/crypto.php';

// ─── Bootstrap BD ────────────────────────────────────────────────────────────
$DB_PATH = __DIR__ . '/../data/stats.db';
if (!file_exists($DB_PATH)) {
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}
$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);

// ─── Leer configuración de IA (multi-proveedor) ─────────────────────────────
// Mapa de proveedores: clave de API key y clave de modelo en la tabla config.
$PROVEEDORES = [
    'deepseek'  => ['api' => 'deepseek_api_key',  'modelo' => 'deepseek_model',  'nombre' => 'DeepSeek'],
    'openai'    => ['api' => 'openai_api_key',    'modelo' => 'openai_model',    'nombre' => 'OpenAI'],
    'anthropic' => ['api' => 'anthropic_api_key', 'modelo' => 'anthropic_model', 'nombre' => 'Anthropic'],
    'google'    => ['api' => 'google_api_key',    'modelo' => 'google_model',    'nombre' => 'Google Gemini'],
    'mistral'   => ['api' => 'mistral_api_key',   'modelo' => 'mistral_model',   'nombre' => 'Mistral'],
    'groq'      => ['api' => 'groq_api_key',      'modelo' => 'groq_model',      'nombre' => 'Groq'],
];

$config = [];
$res = $db->query("SELECT clave, valor FROM config");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $config[$r['clave']] = (string)$r['valor'];
}
$db->close();

$proveedor = $config['ia_proveedor'] ?? 'deepseek';
if (!isset($PROVEEDORES[$proveedor])) {
    $proveedor = 'deepseek';
}
$apiKey = futprotec_descifrarPassword($config[$PROVEEDORES[$proveedor]['api']] ?? '');
$modelo = $config[$PROVEEDORES[$proveedor]['modelo']] ?? '';

// Modelo por defecto según proveedor si no está configurado.
$MODELOS_DEFECTO = [
    'deepseek'  => 'deepseek-chat',
    'openai'    => 'gpt-4o-mini',
    'anthropic' => 'claude-3-5-haiku-latest',
    'google'    => 'gemini-1.5-flash',
    'mistral'   => 'mistral-small-latest',
    'groq'      => 'llama-3.3-70b-versatile',
];
if ($modelo === '') {
    $modelo = $MODELOS_DEFECTO[$proveedor] ?? 'deepseek-chat';
}

if ($apiKey === '') {
    echo json_encode(['ok' => false, 'error' => 'API key de ' . $PROVEEDORES[$proveedor]['nombre'] . ' no configurada']);
    exit;
}

// ─── Parámetros ─────────────────────────────────────────────────────────────
$cuerpo = trim((string)($_POST['cuerpo'] ?? ''));
$asunto = trim((string)($_POST['asunto'] ?? ''));

if ($cuerpo === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta el cuerpo del mensaje']);
    exit;
}

// Limitar longitud para no disparar el coste de tokens.
$cuerpo = mb_substr($cuerpo, 0, 3000);

// ─── Prompt de clasificación ────────────────────────────────────────────────
$system = <<<PROMPT
Eres un asistente de clasificación de respuestas de email para un CRM de ventas B2B
de software de gestión para clubes de fútbol (FutProtec). Clasifica la intención
comercial del mensaje entrante en UNA de estas categorías exactas:

- interesado: muestra interés en el producto, pide información, presupuesto o demo.
- duda_precio: pregunta por precios, costes o condiciones económicas.
- baja: pide que no le contacten más, baja, unsubscribe, opt-out.
- neutral: respuesta genérica, cortesía, sin intención comercial clara.
- no_interesa: rechaza explícitamente el producto o servicio.
- otro: cualquier otra cosa (fuera de oficina, spam, etc.).

Responde SOLO con JSON válido con este formato exacto:
{"intencion":"<categoria>","resumen":"<frase breve en español de 1 línea>"}
No añadas texto fuera del JSON.
PROMPT;

$user = "ASUNTO: {$asunto}\n\nCUERPO:\n{$cuerpo}";

// ─── Llamada a la API del proveedor activo ──────────────────────────────────
// Construye la URL, cabeceras y payload según el proveedor configurado.
$url = '';
$headers = ['Content-Type: application/json'];
$payload = [];

switch ($proveedor) {
    case 'openai':
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $payload = [
            'model'    => $modelo,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 120,
        ];
        break;

    case 'anthropic':
        $url = 'https://api.anthropic.com/v1/messages';
        $headers[] = 'x-api-key: ' . $apiKey;
        $headers[] = 'anthropic-version: 2023-06-01';
        $payload = [
            'model'      => $modelo,
            'max_tokens' => 120,
            'temperature' => 0.1,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];
        break;

    case 'google':
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . $apiKey;
        $payload = [
            'contents' => [
                ['parts' => [['text' => $system . "\n\n" . $user]]],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 120,
            ],
        ];
        break;

    case 'mistral':
        $url = 'https://api.mistral.ai/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $payload = [
            'model'    => $modelo,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 120,
        ];
        break;

    case 'groq':
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $payload = [
            'model'    => $modelo,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 120,
        ];
        break;

    case 'deepseek':
    default:
        $url = 'https://api.deepseek.com/chat/completions';
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $payload = [
            'model'    => $modelo,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 120,
        ];
        break;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo json_encode(['ok' => false, 'error' => 'Error de conexión con ' . $PROVEEDORES[$proveedor]['nombre'] . ': ' . $curlErr]);
    exit;
}

$data = json_decode($resp, true);

// Extraer el contenido de la respuesta según el formato de cada proveedor.
$contenido = '';
if ($proveedor === 'anthropic') {
    // Anthropic: data.content[0].text
    if ($httpCode === 200 && isset($data['content'][0]['text'])) {
        $contenido = trim((string)$data['content'][0]['text']);
    }
} elseif ($proveedor === 'google') {
    // Gemini: data.candidates[0].content.parts[0].text
    if ($httpCode === 200 && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $contenido = trim((string)$data['candidates'][0]['content']['parts'][0]['text']);
    }
} else {
    // OpenAI / DeepSeek / Mistral / Groq: data.choices[0].message.content
    if ($httpCode === 200 && isset($data['choices'][0]['message']['content'])) {
        $contenido = trim((string)$data['choices'][0]['message']['content']);
    }
}

if ($contenido === '') {
    $errMsg = $data['error']['message'] ?? $data['error'] ?? ('HTTP ' . $httpCode);
    if (is_array($errMsg)) $errMsg = json_encode($errMsg);
    echo json_encode(['ok' => false, 'error' => $PROVEEDORES[$proveedor]['nombre'] . ': ' . $errMsg]);
    exit;
}

// ─── Parsear JSON de la respuesta ───────────────────────────────────────────
$intencion = 'otro';
$resumen   = '';
$jsonMatch = null;
if (preg_match('/\{.*\}/s', $contenido, $jsonMatch)) {
    $parsed = json_decode($jsonMatch[0], true);
    if (is_array($parsed)) {
        $intencion = (string)($parsed['intencion'] ?? 'otro');
        $resumen   = (string)($parsed['resumen'] ?? '');
    }
}

// Validar que la intención esté dentro de las categorías permitidas.
$permitidas = ['interesado', 'duda_precio', 'baja', 'neutral', 'no_interesa', 'otro'];
if (!in_array($intencion, $permitidas, true)) {
    $intencion = 'otro';
}

echo json_encode([
    'ok'       => true,
    'intencion' => $intencion,
    'resumen'  => $resumen,
]);
exit;
