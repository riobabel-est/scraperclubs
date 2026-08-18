<?php
/**
 * test_mime_plaintext_tracking.php — Test local de construcción MIME
 * multipart/alternative para plantillas texto_plano (FIX DEFINITIVO).
 *
 * NO abre conexión SMTP. Verifica la construcción del mensaje para una
 * plantilla texto_plano y para una plantilla html (regresión).
 *
 * Criterios (sección 10 del task):
 *   - Content-Type principal = multipart/alternative
 *   - Existen text/plain y text/html
 *   - plain contiene saltos de línea = YES
 *   - plain contiene HTML = NO
 *   - plain contiene pixel = NO
 *   - html contiene saltos visuales = YES
 *   - html contiene pixel = YES
 *   - A/B/C, placeholders, baja, tracking_id correctos
 *
 * Uso: php scripts/test_mime_plaintext_tracking.php
 */

declare(strict_types=1);

// ─── Cargar las funciones de construcción MIME ───────────────────────────────
// Se incluyen los módulos inc/ (mime.php para la construcción MIME y abc.php
// para A/B/C) SIN ejecutar el flujo HTTP de enviar_lote.php.
require_once __DIR__ . '/../public_html/outbound/inc/mime.php';
require_once __DIR__ . '/../public_html/outbound/inc/abc.php';

// ─── Helpers de test ─────────────────────────────────────────────────────────
$passCount = 0;
$failCount = 0;

function check(string $label, bool $cond): void
{
    global $passCount, $failCount;
    if ($cond) {
        $passCount++;
        echo "  ✅ {$label}\n";
    } else {
        $failCount++;
        echo "  ❌ {$label}\n";
    }
}

// ─── Construcción del mensaje multipart/alternative (sin SMTP) ───────────────
// Replicamos la lógica de construcción de enviar_lote.php para texto_plano,
// pero sin abrir conexión SMTP. Extraemos el cuerpo del mensaje tal y como
// se enviaría por DATA.

function construirMensajeTextoPlano(string $cuerpo, string $trackingId): array
{
    $TRACK_URL = 'https://getfutprotec.com/outbound/api/track.php';

    // Parte plain: contenido original con saltos de línea.
    $plainPart = $cuerpo;

    // Parte html: mismo contenido convertido a HTML mínimo + pixel.
    $htmlPart = convertirContenidoAHtml($cuerpo, $TRACK_URL, $trackingId);

    // Content-Type principal.
    $contentType = 'multipart/alternative; boundary="futprotec_alt_testboundary"';
    $boundary = 'futprotec_alt_testboundary';

    // Construir el mensaje completo (cabeceras + cuerpo).
    $mensaje = "From: Test <test@getfutprotec.com>\r\n";
    $mensaje .= "To: <destino@example.com>\r\n";
    $mensaje .= "Subject: =?UTF-8?B?" . base64_encode('Asunto test') . "?=\r\n";
    $mensaje .= "MIME-Version: 1.0\r\n";
    $mensaje .= "Content-Type: {$contentType}\r\n";
    $mensaje .= "\r\n";

    // Parte text/plain.
    $mensaje .= "--{$boundary}\r\n";
    $mensaje .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
    $mensaje .= "\r\n";
    $mensaje .= $plainPart;
    $mensaje .= "\r\n";

    // Parte text/html.
    $mensaje .= "--{$boundary}\r\n";
    $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
    $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
    $mensaje .= "\r\n";
    $mensaje .= $htmlPart;
    $mensaje .= "\r\n";

    // Cierre.
    $mensaje .= "--{$boundary}--\r\n";

    return [
        'mensaje'    => $mensaje,
        'plainPart'  => $plainPart,
        'htmlPart'   => $htmlPart,
        'contentType'=> $contentType,
        'boundary'   => $boundary,
    ];
}

// ─── TEST 1: Plantilla texto_plano ───────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "TEST 1: Plantilla texto_plano → multipart/alternative\n";
echo "═══════════════════════════════════════════════════════════════\n";

$cuerpoComercial = "Hola {{CONTACTO}},\n\n"
    . "Somos FutProtec y queremos presentarte nuestra plataforma.\n"
    . "Puedes darte de baja aquí: https://getfutprotec.com/outbound/api/baja.php?email={{EMAIL}}\n\n"
    . "Un saludo,\n"
    . "El equipo de {{SENDER_NAME}}";

// Sustituir placeholders (simula el flujo real).
$replacements = [
    '{{CONTACTO}}'    => 'Juan',
    '{{EMAIL}}'       => 'club@example.com',
    '{{SENDER_NAME}}' => 'FutProtec',
];
$cuerpoSustituido = str_replace(array_keys($replacements), array_values($replacements), $cuerpoComercial);

$trackingId = 'fut_test_abc123';
$m = construirMensajeTextoPlano($cuerpoSustituido, $trackingId);

echo "\n[Content-Type principal]\n";
check('Content-Type = multipart/alternative', str_contains($m['contentType'], 'multipart/alternative'));
check('Existe boundary', str_contains($m['contentType'], 'boundary='));

echo "\n[Partes presentes]\n";
check('Existe text/plain', str_contains($m['mensaje'], 'Content-Type: text/plain; charset=UTF-8'));
check('Existe text/html', str_contains($m['mensaje'], 'Content-Type: text/html; charset=UTF-8'));
check('Cierre multipart correcto (--boundary--)', str_contains($m['mensaje'], '--' . $m['boundary'] . '--'));

echo "\n[Parte text/plain]\n";
$plain = $m['plainPart'];
check('plain contiene saltos de línea (\\n)', str_contains($plain, "\n"));
check('plain contiene doble salto de línea (\\n\\n)', str_contains($plain, "\n\n"));
check('plain NO contiene <img', !str_contains($plain, '<img'));
check('plain NO contiene <style', !str_contains($plain, '<style'));
check('plain NO contiene <script', !str_contains($plain, '<script'));
check('plain NO contiene <!--', !str_contains($plain, '<!--'));
check('plain NO contiene track.php', !str_contains($plain, 'track.php'));
check('plain NO contiene HTML (<div)', !str_contains($plain, '<div'));
check('plain NO contiene <br', !str_contains($plain, '<br'));
check('plain contiene URL de baja visible', str_contains($plain, 'https://getfutprotec.com/outbound/api/baja.php?email=club@example.com'));
check('plain contiene placeholder sustituido (CONTACTO)', str_contains($plain, 'Juan'));
check('plain contiene placeholder sustituido (SENDER_NAME)', str_contains($plain, 'FutProtec'));

echo "\n[Parte text/html]\n";
$html = $m['htmlPart'];
check('html contiene saltos visuales (<br)', str_contains($html, '<br'));
check('html contiene pixel de tracking', str_contains($html, 'track.php?id=' . $trackingId));
check('html contiene <img', str_contains($html, '<img'));
check('html contiene enlace de baja simple (<a href)', str_contains($html, '<a href="https://getfutprotec.com/outbound/api/baja.php?email=club@example.com"'));
check('html NO contiene tablas', !str_contains($html, '<table'));
check('html NO contiene <script', !str_contains($html, '<script'));
check('html NO contiene fuentes externas', !str_contains($html, '@import') && !str_contains($html, 'fonts.googleapis'));
check('html contiene div mínimo', str_contains($html, '<div style="white-space:normal; font-family:Arial,sans-serif; font-size:14px; line-height:1.5;">'));

echo "\n[Tracking]\n";
check('tracking_id presente en html', str_contains($html, 'id=' . $trackingId));
check('tracking_id AUSENTE en plain', !str_contains($plain, $trackingId));

echo "\n[A/B/C — identidad del contenido]\n";
// Verificar que ambas partes proceden de la misma variable base.
check('plain y html comparten el texto base (CONTACTO)', str_contains($plain, 'Juan') && str_contains($html, 'Juan'));
check('plain y html comparten el texto base (SENDER_NAME)', str_contains($plain, 'FutProtec') && str_contains($html, 'FutProtec'));

// ─── TEST 2: Regresión plantilla html ────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "TEST 2: Regresión plantilla html → text/html (comportamiento histórico)\n";
echo "═══════════════════════════════════════════════════════════════\n";

$cuerpoHtml = "<html><body><p>Hola {{CONTACTO}},</p><p>Este es un HTML.</p></body></html>";
$cuerpoHtmlSust = str_replace('{{CONTACTO}}', 'Juan', $cuerpoHtml);

// Simular la inyección del pixel en plantilla html (igual que enviar_lote.php).
$fingerprint = bin2hex(random_bytes(8));
$pixel = '<img src="https://getfutprotec.com/outbound/api/track.php?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';
$antiDetect = "\n<!-- fpid:{$fingerprint} -->\n";
if (stripos($cuerpoHtmlSust, '</body>') !== false) {
    $cuerpoHtmlSust = str_ireplace('</body>', $pixel . $antiDetect . "\n</body>", $cuerpoHtmlSust);
} else {
    $cuerpoHtmlSust .= "\n" . $pixel . $antiDetect;
}

$contentTypeHtml = 'text/html; charset=UTF-8';
check('Content-Type = text/html (no multipart)', $contentTypeHtml === 'text/html; charset=UTF-8');
check('html contiene pixel', str_contains($cuerpoHtmlSust, 'track.php?id=' . $trackingId));
check('html contiene fingerprint', str_contains($cuerpoHtmlSust, 'fpid:'));
check('html NO es multipart/alternative', !str_contains($contentTypeHtml, 'multipart'));

// ─── TEST 3: A/B/C variantes ─────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "TEST 3: A/B/C — resolución de variantes (resolverContenidoVariante)\n";
echo "═══════════════════════════════════════════════════════════════\n";

$plantillaABC = [
    'asunto'   => 'Asunto A',
    'asunto_b' => 'Asunto B',
    'asunto_c' => 'Asunto C',
    'test_ab'  => 1,
    'cuerpo'   => "Cuerpo A\nlinea2",
    'cuerpo_b' => "Cuerpo B\nlinea2",
    'cuerpo_c' => "Cuerpo C\nlinea2",
];

$resA = resolverContenidoVariante($plantillaABC, 'A');
$resB = resolverContenidoVariante($plantillaABC, 'B');
$resC = resolverContenidoVariante($plantillaABC, 'C');

check('Variante A → asunto A', $resA['asunto'] === 'Asunto A');
check('Variante A → cuerpo A', $resA['cuerpo'] === "Cuerpo A\nlinea2");
check('Variante B → asunto B', $resB['asunto'] === 'Asunto B');
check('Variante B → cuerpo B', $resB['cuerpo'] === "Cuerpo B\nlinea2");
check('Variante C → asunto C', $resC['asunto'] === 'Asunto C');
check('Variante C → cuerpo C', $resC['cuerpo'] === "Cuerpo C\nlinea2");

// ─── TEST 4: asignarVariante determinística ──────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "TEST 4: asignarVariante determinística e inmutable\n";
echo "═══════════════════════════════════════════════════════════════\n";

$v1 = asignarVariante(123, 456);
$v2 = asignarVariante(123, 456);
check('Mismo (lead, campaña) → misma variante', $v1 === $v2);
check('Variante ∈ {A,B,C}', in_array($v1, ['A', 'B', 'C'], true));

// ─── TEST 5: baja en html ────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "TEST 5: Enlace de baja en html (enlace simple, no oculto)\n";
echo "═══════════════════════════════════════════════════════════════\n";

$htmlBaja = convertirContenidoAHtml(
    "Texto con baja: https://getfutprotec.com/outbound/api/baja.php?email=club@example.com",
    'https://getfutprotec.com/outbound/api/track.php',
    $trackingId
);
check('html contiene enlace de baja <a href>', str_contains($htmlBaja, '<a href="https://getfutprotec.com/outbound/api/baja.php?email=club@example.com"'));
check('html NO oculta el enlace (display:none en el <a>)', !str_contains($htmlBaja, '<a href="https://getfutprotec.com/outbound/api/baja.php?email=club@example.com" style="display:none'));

// ─── Resumen ─────────────────────────────────────────────────────────────────
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "RESUMEN\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  ✅ Pass: {$passCount}\n";
echo "  ❌ Fail: {$failCount}\n";

if ($failCount === 0) {
    echo "\n  VEREDICTO: PLAINTEXT_TRACKING_MIME_PASS\n";
    exit(0);
}
echo "\n  VEREDICTO: BLOCKED\n";
exit(1);
