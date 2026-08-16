<?php
/**
 * FASE ABC-FINAL.1 — SELECCIÓN DE 3 DUMMIES A/B/C (SOLO LECTURA).
 *
 * NO ejecuta envíos, NO POST, NO SMTP, NO cron, NO escribe en BD.
 * Usa las funciones REALES asignarVariante() y esElegibleParaEnvio().
 *
 * Busca 3 leads TEST limpios (uno por variante A/B/C) para campaign_id=3,
 * excluyendo leads 1809..1813 y cualquier lead con envío lógico en campaña 3.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "ERROR: stats.db no encontrada\n");
    exit(2);
}

$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$CAMPAIGN = 3;
$EXCLUIR  = [1809, 1810, 1811, 1812, 1813];

echo "========== 1. CONFIG / CAMPAÑA / PLANTILLA ==========\n";
$modoEntorno = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: '?');
$motorEstado = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") ?: '?');
echo "modo_entorno: {$modoEntorno}\n";
echo "motor_estado: {$motorEstado}\n";

$camp = $db->querySingle("SELECT id, nombre, estado, entorno, activo FROM pipelines WHERE id = {$CAMPAIGN}", true);
echo "campaña 3: " . json_encode($camp, JSON_UNESCAPED_UNICODE) . "\n";

$plantilla = $db->querySingle("SELECT id, nombre, activo, test_ab FROM plantillas WHERE id = 2", true);
echo "plantilla 2: " . json_encode($plantilla, JSON_UNESCAPED_UNICODE) . "\n";

echo "\nMax(envios.id) ANTES de la consulta: " . (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0) . "\n";

echo "\n========== 2. FUNCIÓN DE VARIANTE (verificación real) ==========\n";
// Demostración de que es determinística usando la propia función real.
echo "asignarVariante(1810,3) = " . asignarVariante(1810, 3) . " (esperado A, primer smoke)\n";
echo "asignarVariante(1812,3) = " . asignarVariante(1812, 3) . " (esperado C, segundo smoke)\n";

echo "\n========== 3. TODOS LOS LEADS TEST EXISTENTES ==========\n";
$res = $db->query("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm ORDER BY id");
$candidatos = [];
$descartados = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $leadTest = esLeadTest($r);
    if (!$leadTest) {
        continue; // no son de interés para esta fase
    }

    $id = (int)$r['id'];
    $motivo = null;

    // Excluir leads 1809..1813
    if (in_array($id, $EXCLUIR, true)) {
        $motivo = "excluido por rango 1809-1813";
    }
    // Excluir duplicados
    elseif ((int)($r['es_duplicado'] ?? 0) === 1) {
        $motivo = "duplicado";
    }
    // Elegibilidad real (supresión, email inválido, aislamiento TEST/REAL)
    else {
        $elig = esElegibleParaEnvio($db, $id, $CAMPAIGN);
        if (!$elig['ok']) {
            $motivo = "no elegible: {$elig['razon']}";
        } else {
            // Excluir leads con envío lógico en campaña 3
            $yaEnviado = (int)($db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$id} AND campaign_id = {$CAMPAIGN}") ?: 0);
            if ($yaEnviado > 0) {
                $motivo = "ya tiene envío lógico en campaign_id=3";
            }
        }
    }

    if ($motivo !== null) {
        $descartados[] = [
            'lead_id' => $id,
            'club' => $r['nombre_club'],
            'email' => $r['email'],
            'estado_lead' => $r['estado_lead'],
            'motivo' => $motivo,
        ];
        continue;
    }

    // Candidato limpio → calcular variante con la función REAL.
    $variante = asignarVariante($id, $CAMPAIGN);
    $candidatos[] = [
        'lead_id' => $id,
        'club' => $r['nombre_club'],
        'email' => $r['email'],
        'estado_lead' => $r['estado_lead'],
        'variante' => $variante,
    ];
    echo "CANDIDATO lead_id={$id} | {$r['nombre_club']} | {$r['email']} | variante={$variante}\n";
}

echo "\n========== 4. CANDIDATOS DESCARTADOS Y MOTIVO ==========\n";
if (count($descartados) === 0) {
    echo "(ninguno)\n";
} else {
    foreach ($descartados as $d) {
        echo "lead_id={$d['lead_id']} | {$d['club']} | {$d['email']} | estado={$d['estado_lead']} | motivo={$d['motivo']}\n";
    }
}

echo "\n========== 5. SELECCIÓN UNO POR VARIANTE ==========\n";
function pick(array $cands, string $var): ?array {
    foreach ($cands as $c) {
        if ($c['variante'] === $var) {
            return $c;
        }
    }
    return null;
}

$dummyA = pick($candidatos, 'A');
$dummyB = pick($candidatos, 'B');
$dummyC = pick($candidatos, 'C');

echo "Dummy A: " . ($dummyA ? "lead_id={$dummyA['lead_id']}" : "NO ENCONTRADO") . "\n";
echo "Dummy B: " . ($dummyB ? "lead_id={$dummyB['lead_id']}" : "NO ENCONTRADO") . "\n";
echo "Dummy C: " . ($dummyC ? "lead_id={$dummyC['lead_id']}" : "NO ENCONTRADO") . "\n";

echo "\n========== 6. ELEGIBILIDAD REAL DE LOS 3 SELECCIONADOS ==========\n";
foreach (['A' => $dummyA, 'B' => $dummyB, 'C' => $dummyC] as $var => $d) {
    if (!$d) continue;
    $e = esElegibleParaEnvio($db, $d['lead_id'], $CAMPAIGN);
    echo "Dummy {$var} lead_id={$d['lead_id']}: eligibilidad=" . ($e['ok'] ? 'elegible' : $e['razon']) . "\n";
}

echo "\nMax(envios.id) DESPUÉS de la consulta: " . (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0) . "\n";

echo "\n========== 7. INTEGRIDAD envio_id=6 y envio_id=7 ==========\n";
foreach ([6, 7] as $eid) {
    $e = $db->querySingle("SELECT id, estado, resultado_envio, lead_id, campaign_id, variant, plantilla_id, smtp_id FROM envios WHERE id = {$eid}", true);
    echo "envio_id={$eid}: " . ($e ? json_encode($e, JSON_UNESCAPED_UNICODE) : "NO EXISTE") . "\n";
}

$db->close();

echo "\n========== VEREDICTO ==========\n";
if ($dummyA && $dummyB && $dummyC) {
    echo "READY_FOR_ABC_DUMMY_SEND\n";
    echo "\n--- TABLA RESULTADO ---\n";
    echo "| Rol dummy | lead_id | club | email | variante | elegible | motivo |\n";
    echo "|---|---|---|---|---|---|---|\n";
    foreach (['A' => $dummyA, 'B' => $dummyB, 'C' => $dummyC] as $var => $d) {
        echo "| DUMMY {$var} | {$d['lead_id']} | {$d['club']} | {$d['email']} | {$var} | si | limpio |\n";
    }
} else {
    echo "BLOCKED\n";
}
exit(0);