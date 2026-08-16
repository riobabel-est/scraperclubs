<?php
/**
 * FASE 6F.1 — Prueba local de selección de contenido A/B/C.
 * SOLO LECTURA: NO realiza POST, NO abre conexión SMTP, NO escribe en BD.
 *
 * Demuestra que el código REAL de api/enviar_lote.php (SELECT ya corregido)
 * carga cuerpo + cuerpo_b + cuerpo_c desde la plantilla real (id=2) y que
 * inc/abc.php::resolverContenidoVariante() selecciona la variante correcta.
 *
 * Uso: php scripts/fase6f1_test_contenido_abc.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

$ENVIO_LOTE = $ROOT . '/public_html/outbound/api/enviar_lote.php';
$ABC_INC    = $ROOT . '/public_html/outbound/inc/abc.php';
$DB_PATH    = $ROOT . '/public_html/outbound/data/stats.db';

echo "═══════════════════════════════════════════════════════════════\n";
echo " FASE 6F.1 — Prueba local de contenido A/B/C (SIN SMTP / SIN POST)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ── 1. Extraer el SELECT REAL de la sección de plantilla de enviar_lote.php ──
$src = file_get_contents($ENVIO_LOTE);
$srcNoComments = preg_replace('#//.*$#m', '', $src);
$srcNoComments = preg_replace('#/\*.*?\*/#s', '', $srcNoComments);

if (!preg_match('/\$plantilla\s*=\s*\$db->querySingle\(\s*"\s*(SELECT\s+.*?)\s+FROM\s+plantillas/si', $srcNoComments, $m)) {
    fwrite(STDERR, "FALLO: no se pudo extraer el SELECT de plantillas de enviar_lote.php\n");
    exit(1);
}
$selectColumnas = $m[1];
$selectColumnas = trim(preg_replace('/\s+/', ' ', $selectColumnas));
$selectColumnas = preg_replace('/^SELECT\s+/i', '', $selectColumnas);

echo "1) SELECT real extraído de api/enviar_lote.php:\n";
echo "   SELECT {$selectColumnas} FROM plantillas ...\n\n";

$tieneCB = preg_match('/\bcuerpo_b\b/i', $selectColumnas) === 1;
$tieneCC = preg_match('/\bcuerpo_c\b/i', $selectColumnas) === 1;
echo "   ─ cuerpo_b presente en el SELECT: " . ($tieneCB ? 'SÍ' : 'NO') . "\n";
echo "   ─ cuerpo_c presente en el SELECT: " . ($tieneCC ? 'SÍ' : 'NO') . "\n\n";

if (!$tieneCB || !$tieneCC) {
    fwrite(STDERR, "FALLO: el SELECT no contiene cuerpo_b/cuerpo_c\n");
    exit(1);
}

// ── 2. Abrir BD en SOLO LECTURA ──
$db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);
$db->exec('PRAGMA query_only = ON');

require_once $ABC_INC;

echo "2) BD abierta en SOLO LECTURA (SQLITE3_OPEN_READONLY + PRAGMA query_only=ON)\n\n";

// ── 3. Ejecutar el MISMO SELECT que usa enviar_lote.php (plantilla real id=2) ──
$sql = "SELECT {$selectColumnas} FROM plantillas WHERE id = 2 AND activo = 1";
$plantilla = $db->querySingle($sql, true);

if (!$plantilla) {
    fwrite(STDERR, "FALLO: plantilla id=2 no encontrada\n");
    exit(1);
}

echo "3) Plantilla real cargada: id={$plantilla['id']} '{$plantilla['nombre']}' (tipo={$plantilla['tipo']}, test_ab={$plantilla['test_ab']})\n";
echo "   Claves del array \$plantilla recibido:\n";
foreach (array_keys($plantilla) as $k) {
    $len = is_string($plantilla[$k]) ? strlen($plantilla[$k]) : '-';
    echo "      - {$k}" . (in_array($k, ['cuerpo','cuerpo_b','cuerpo_c','asunto','asunto_b','asunto_c'], true) ? " (len={$len})" : '') . "\n";
}
echo "\n";

// ── 4. Resolver variantes con resolverContenidoVariante() REAL ──
$casos = [
    'A' => ['lead_id' => 1809, 'campaign_id' => 3, 'club_esperado' => 'TEST_CLUB_01_RealMadrid'],
    'B' => ['lead_id' => 1813, 'campaign_id' => 3, 'club_esperado' => 'TEST_CLUB_05_Bilbao'],
    'C' => ['lead_id' => 1811, 'campaign_id' => 3, 'club_esperado' => 'TEST_CLUB_03_Valencia'],
];

// Datos de remitente igual que enviar_lote.php (cuenta SMTP id=1, nombre/cargo vacíos)
$senderEmail = 'rodrigo@getfutprotec.com';
$senderName  = '';   // cuentas_smtp.nombre_emisor está vacío
$senderTitle = '';   // cuentas_smtp.cargo_emisor está vacío
if ($senderName === '') {
    $senderName = ucfirst(explode('@', $senderEmail)[0]); // fallback = "Rodrigo"
}

$resultados = [];

foreach ($casos as $variant => $c) {
    // — Determinismo (igual que asignarVariante) —
    $varianteCalculada = asignarVariante((int)$c['lead_id'], (int)$c['campaign_id']);

    // — Resolución de contenido REAL —
    $contenido = resolverContenidoVariante($plantilla, $variant);

    // — Sustitución de placeholders CON EL MISMO MAPA que usa enviar_lote.php —
    $club = $db->querySingle(
        "SELECT id, nombre_club, email, federacion, persona_contacto FROM clubes_crm WHERE id = {$c['lead_id']}",
        true
    );
    $nombreClub = $club['nombre_club'];
    $emailClub  = $club['email'];
    $federacion = $club['federacion'] ?? '';
    $contacto   = $club['persona_contacto'] ?: 'responsable';

    $replacements = [
        '{{CLUB}}'         => $nombreClub,
        '{{CONTACTO}}'      => $contacto,
        '{{FEDERACION}}'    => $federacion,
        '{{ANIO}}'          => date('Y'),
        '{{EMAIL}}'         => $emailClub,
        '{{SENDER_NAME}}'   => $senderName,
        '{{SENDER_TITLE}}'  => $senderTitle,
        '{{SENDER_EMAIL}}'  => $senderEmail,
    ];

    $asunto = str_replace(array_keys($replacements), array_values($replacements), $contenido['asunto']);
    $cuerpo = str_replace(array_keys($replacements), array_values($replacements), $contenido['cuerpo']);

    $resultados[$variant] = [
        'asunto'            => $asunto,
        'cuerpo'            => $cuerpo,
        'varianteCalculada' => $varianteCalculada,
        'club'              => $nombreClub,
    ];
}

echo "4) Resultados por variante (contenido YA resuelto con plantilla real):\n\n";

foreach (['A', 'B', 'C'] as $variant) {
    $r = $resultados[$variant];
    $c = $casos[$variant];
    $hash = hash('sha256', $r['cuerpo']);
    $len  = strlen($r['cuerpo']);
    $delta = "lead {$c['lead_id']} -> variante calculada {$r['varianteCalculada']} " . ($r['varianteCalculada'] === $variant ? '(OK)' : '(FALLO)');

    echo "   ── Variante {$variant} ({$delta}) ──\n";
    echo "      club real : {$r['club']}\n";
    echo "      asunto    : {$r['asunto']}\n";
    echo "      cuerpo    : len = {$len} bytes | SHA-256 = {$hash}\n";

    // Placeholders residuales
    preg_match_all('/\{\{[^}]+\}\}/', $r['cuerpo'], $dbl);
    preg_match_all('/\{\[[^\]]+\]\}/', $r['cuerpo'], $sq);
    $residuales = array_merge($dbl[0], $sq[0]);
    echo "      placeholders residuales: " . (empty($residuales) ? 'ninguno' : implode(', ', array_unique($residuales))) . "\n";

    // URLs
    preg_match_all('#https?://[^\s"\'<>]+#i', $r['cuerpo'], $urls);
    $urls = array_values(array_unique($urls[0]));
    if (empty($urls)) {
        echo "      URLs detectadas: ninguna\n";
    } else {
        echo "      URLs detectadas:\n";
        foreach ($urls as $u) {
            echo "         - {$u}\n";
        }
    }
    echo "\n";
}

// ── 5. Verificación de no identidad ──
$identicos = ($resultados['A']['cuerpo'] === $resultados['B']['cuerpo'])
          || ($resultados['A']['cuerpo'] === $resultados['C']['cuerpo'])
          || ($resultados['B']['cuerpo'] === $resultados['C']['cuerpo']);

echo "5) Verificación:\n";
echo "   ─ A == B ? " . ($resultados['A']['cuerpo'] === $resultados['B']['cuerpo'] ? 'SÍ (idénticos)' : 'NO (distintos)') . "\n";
echo "   ─ A == C ? " . ($resultados['A']['cuerpo'] === $resultados['C']['cuerpo'] ? 'SÍ (idénticos)' : 'NO (distintos)') . "\n";
echo "   ─ B == C ? " . ($resultados['B']['cuerpo'] === $resultados['C']['cuerpo'] ? 'SÍ (idénticos)' : 'NO (distintos)') . "\n";
echo "   ─ ¿A/B/C idénticos? " . ($identicos ? 'SÍ (PROBLEMA)' : 'NO (correcto)') . "\n";

// ── 6. Clasificación de placeholders por variante ──
echo "\n6) Clasificación de placeholders por variante:\n";
$tokensInteres = ['{{CLUB}}','{{CONTACTO}}','{{EMAIL}}','{{FEDERACION}}','{{ANIO}}','{{SENDER_NAME}}','{{SENDER_TITLE}}','{{SENDER_EMAIL}}','{[CLUB]}','{[SENDER_NAME]}','{[SENDER_TITLE]}','{[SENDER_EMAIL]}'];
foreach (['A', 'B', 'C'] as $variant) {
    $cuerpoRes = $resultados[$variant]['cuerpo'];
    echo "\n   Variante {$variant}:\n";
    foreach ($tokensInteres as $tok) {
        $presente = str_contains($cuerpoRes, $tok);
        if ($presente) {
            echo "      - {$tok}: NO RESUELTO\n";
        } else {
            // determinar si ese token estaba en la plantilla original de esa variante
            echo "      - {$tok}: no presente en el cuerpo final\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " CONFIRMACIÓN DE NO-EFECTOS\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "   POST realizados:              0\n";
echo "   SMTP connections:             0\n";
echo "   INSERT / UPDATE / DELETE:     0\n";
echo "   BD abierta en modo:           READONLY\n";
echo "   Registros envios campaign=3:  no tocados\n";
echo "═══════════════════════════════════════════════════════════════\n";

$db->close();