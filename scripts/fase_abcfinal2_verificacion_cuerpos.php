<?php
/**
 * SOLO LECTURA — Verificación exacta de cuerpos de envios 3..7 vs plantilla 2.
 * Compara los cuerpos normalizados entre sí y contra las variantes crudas.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';

function norm(string $b): string {
    $b = preg_replace('/<img src="[^"]*track\.php\?id=[^"]*"[^>]*>/i', '', $b);
    $b = preg_replace('/<!--\s*fpid:[^>]*-->/i', '', $b);
    return trim($b);
}

$p = $db->querySingle("SELECT asunto, cuerpo, asunto_b, cuerpo_b, asunto_c, cuerpo_c, test_ab FROM plantillas WHERE id = 2", true);

// Cuerpos crudos de plantilla (sin sustituciones)
$cuerpoA = (string)$p['cuerpo'];
$cuerpoB = (string)$p['cuerpo_b'];
$cuerpoC = (string)$p['cuerpo_c'];

echo "=== Plantilla 2 cuerpos crudos ===\n";
echo "cuerpoA len = " . strlen($cuerpoA) . " | md5 = " . md5($cuerpoA) . "\n";
echo "cuerpoB len = " . strlen($cuerpoB) . " | md5 = " . md5($cuerpoB) . "\n";
echo "cuerpoC len = " . strlen($cuerpoC) . " | md5 = " . md5($cuerpoC) . "\n";

echo "\n=== Envios 3..7: cuerpo_mensaje normalizado ===\n";
$g = $db->query("SELECT id, lead_id, variant, asunto, cuerpo_mensaje FROM envios WHERE campaign_id = 3 AND id IN (3,4,5,6,7) ORDER BY id");

$envios = [];
while ($e = $g->fetchArray(SQLITE3_ASSOC)) {
    $cuerpoNorm = norm((string)$e['cuerpo_mensaje']);
    $envios[] = [
        'id' => (int)$e['id'],
        'lead_id' => (int)$e['lead_id'],
        'variant' => $e['variant'],
        'asunto' => $e['asunto'],
        'cuerpo' => $cuerpoNorm,
    ];
    echo "envio_id={$e['id']} | lead={$e['lead_id']} | variant={$e['variant']} | cuerpo_norm_len=" . strlen($cuerpoNorm) . " | md5=" . md5($cuerpoNorm) . "\n";
}

echo "\n=== Asunto almacenado por envío ===\n";
foreach ($envios as $e) {
    echo "envio_id={$e['id']} | variant={$e['variant']} | asunto=\"{$e['asunto']}\"\n";
}

echo "\n=== Comparación de cuerpos entre envíos ===\n";
for ($i = 0; $i < count($envios); $i++) {
    for ($j = $i + 1; $j < count($envios); $j++) {
        $a = $envios[$i];
        $b = $envios[$j];
        $igual = md5($a['cuerpo']) === md5($b['cuerpo']);
        echo "envio {$a['id']} (var {$a['variant']}) vs envio {$b['id']} (var {$b['variant']}): " . ($igual ? 'IDENTICOS' : 'DIFERENTES') . "\n";
    }
}

echo "\n=== Veredicto de coherencia de contenido por envío ===\n";
foreach ($envios as $e) {
    $var = $e['variant'];
    // El cuerpo crudo esperado de la variante, sin sustituciones.
    $esperado = $var === 'A' ? $cuerpoA : ($var === 'B' ? $cuerpoB : $cuerpoC);
    // Reemplazar placeholders básicos para comparar de forma aproximada.
    // Comparación más fiable: ver si el cuerpo almacenado es PREFIJO/coincide con la variante A vs B vs C.
    $dist_A = levenshtein(mb_substr($e['cuerpo'], 0, 60), mb_substr($cuerpoA, 0, 60));
    $dist_B = levenshtein(mb_substr($e['cuerpo'], 0, 60), mb_substr($cuerpoB, 0, 60));
    $dist_C = levenshtein(mb_substr($e['cuerpo'], 0, 60), mb_substr($cuerpoC, 0, 60));
    $mejor = ($dist_A <= $dist_B && $dist_A <= $dist_C) ? 'A' : (($dist_B <= $dist_C) ? 'B' : 'C');
    echo "envio_id={$e['id']} | variant registrada={$var} | variante inferida por contenido={$mejor} | dist(A,B,C)={$dist_A},{$dist_B},{$dist_C}\n";
}

$db->close();