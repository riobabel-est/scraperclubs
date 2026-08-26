<?php
/**
 * llm.php — Cliente LLM multi-proveedor (DeepSeek/OpenAI/Mistral/Groq/Anthropic/Google).
 * Reutiliza la configuración de IA del panel (tabla `config`: ia_proveedor, *_api_key, *_model).
 * Requiere: futprotec_descifrarPassword() (inc/secretos) si las claves están cifradas.
 */
declare(strict_types=1);

/**
 * llm_config — Lee la configuración activa del proveedor IA.
 * Devuelve ['proveedor','nombre','api_key','modelo'] o null si no hay clave.
 */
function llm_config(SQLite3 $db): ?array {
    $PROVEEDORES = [
        'deepseek'  => ['api' => 'deepseek_api_key',  'modelo' => 'deepseek_model',  'nombre' => 'DeepSeek'],
        'openai'    => ['api' => 'openai_api_key',    'modelo' => 'openai_model',    'nombre' => 'OpenAI'],
        'anthropic' => ['api' => 'anthropic_api_key', 'modelo' => 'anthropic_model', 'nombre' => 'Anthropic'],
        'google'    => ['api' => 'google_api_key',    'modelo' => 'google_model',    'nombre' => 'Google Gemini'],
        'mistral'   => ['api' => 'mistral_api_key',   'modelo' => 'mistral_model',   'nombre' => 'Mistral'],
        'groq'      => ['api' => 'groq_api_key',      'modelo' => 'groq_model',      'nombre' => 'Groq'],
    ];
    $MODELOS_DEFECTO = [
        'deepseek'  => 'deepseek-chat',
        'openai'    => 'gpt-4o-mini',
        'anthropic' => 'claude-3-5-haiku-latest',
        'google'    => 'gemini-1.5-flash',
        'mistral'   => 'mistral-small-latest',
        'groq'      => 'llama-3.3-70b-versatile',
    ];
    $config = [];
    $res = $db->query("SELECT clave, valor FROM config");
    if ($res) {
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $config[$r['clave']] = (string)$r['valor'];
        }
    }
    $proveedor = $config['ia_proveedor'] ?? 'deepseek';
    if (!isset($PROVEEDORES[$proveedor])) $proveedor = 'deepseek';

    $cifrada = $config[$PROVEEDORES[$proveedor]['api']] ?? '';
    $apiKey = $cifrada;
    if (function_exists('futprotec_descifrarPassword')) {
        $apiKey = futprotec_descifrarPassword($cifrada);
    }
    if ($apiKey === '') return null;

    return [
        'proveedor' => $proveedor,
        'nombre'    => $PROVEEDORES[$proveedor]['nombre'],
        'api_key'   => $apiKey,
        'modelo'    => $config[$PROVEEDORES[$proveedor]['modelo']] ?? ($MODELOS_DEFECTO[$proveedor] ?? 'deepseek-chat'),
    ];
}

/**
 * llm_chat — Llamada genérica al LLM activo. Devuelve el texto o null (error/sin clave).
 */
function llm_chat(SQLite3 $db, string $system, string $user, int $maxTokens = 600, float $temperature = 0.4): ?string {
    $c = llm_config($db);
    if (!$c) return null;
    $proveedor = $c['proveedor'];
    $url = '';
    $headers = ['Content-Type: application/json'];
    $payload = [];

    switch ($proveedor) {
        case 'openai':
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $c['api_key'];
            $payload = ['model' => $c['modelo'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => $temperature, 'max_tokens' => $maxTokens];
            break;
        case 'anthropic':
            $url = 'https://api.anthropic.com/v1/messages';
            $headers[] = 'x-api-key: ' . $c['api_key'];
            $headers[] = 'anthropic-version: 2023-06-01';
            $payload = ['model' => $c['modelo'], 'max_tokens' => $maxTokens, 'temperature' => $temperature, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]];
            break;
        case 'google':
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $c['modelo'] . ':generateContent?key=' . $c['api_key'];
            $payload = ['contents' => [['parts' => [['text' => $system . "\n\n" . $user]]]], 'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens]];
            break;
        case 'mistral':
            $url = 'https://api.mistral.ai/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $c['api_key'];
            $payload = ['model' => $c['modelo'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => $temperature, 'max_tokens' => $maxTokens];
            break;
        case 'groq':
            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $c['api_key'];
            $payload = ['model' => $c['modelo'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => $temperature, 'max_tokens' => $maxTokens];
            break;
        case 'deepseek':
        default:
            $url = 'https://api.deepseek.com/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $c['api_key'];
            $payload = ['model' => $c['modelo'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => $temperature, 'max_tokens' => $maxTokens];
            break;
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", $headers),
        'content' => json_encode($payload),
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    if ($proveedor === 'anthropic') {
        return isset($data['content'][0]['text']) ? trim((string)$data['content'][0]['text']) : null;
    }
    if ($proveedor === 'google') {
        return isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim((string)$data['candidates'][0]['content']['parts'][0]['text']) : null;
    }
    return isset($data['choices'][0]['message']['content']) ? trim((string)$data['choices'][0]['message']['content']) : null;
}
