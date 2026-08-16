<?php
/**
 * FASE ABC-FINAL.5 — CANDIDATO ÚNICO PARA VARIANTE B (SOLO LECTURA).
 *
 * NO crea leads. NO modifica BD. NO envía. NO SMTP. NO POST. NO cron.
 * NO Evolution API. NO modifica plantilla. NO modifica configuración.
 *
 * Calcula en memoria asignarVariante(1817,3), asignarVariante(1818,3), ...
 * hasta encontrar el PRIMER ID futuro cuya variante determinística sea 'B'.
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

$CAMPAIGN = 3;

echo "==================== FASE ABC-FINAL.5 — CANDIDATO VARIANTE B (SOLO LECTURA) ====================\n\n";

// 1. MAX(clubes_crm.id)
$maxLeadId = (int)($db->querySingle("SELECT MAX(id) FROM clubes_crm") ?: 0);
echo "MAX(clubes_crm.id) actual : {$maxLeadId}\n";
echo "Confirmar máximo = 1816    : " . ($maxLeadId === 1816 ? 'SÍ' : 'NO') . "\n\n";

echo "--- Búsqueda del PRIMER ID futuro con variante B ---\n";
echo "| ID candidato | variante |\n";
echo "| -----------: | :------: |\n";

$candidato = null;
$id = $maxLeadId + 1; // empezar en 1817
$limite = $maxLeadId + 2000; // safety net amplia

for (; $id <= $limite; $id++) {
    $v = asignarVariante($id, $CAMPAIGN);
    echo "| " . str_pad((string)$id, 12, ' ', STR_PAD_LEFT) . " |    {$v}     |\n";
    if ($v === 'B') {
        $candidato = $id;
        break;
    }
}

echo "\n==================== RESULTADO ====================\n";
if ($candidato === null) {
    echo "NO ENCONTRADO (revisar rango)\n";
    $db->close();
    exit(3);
}

$varianteCandidato = asignarVariante($candidato, $CAMPAIGN);

echo "CANDIDATO_B_ID = {$candidato}\n";
echo "CANDIDATO_B_VARIANTE = {$varianteCandidato}\n";
echo "MAX_ID_ACTUAL = {$maxLeadId}\n\n";

echo "--- VALIDACIÓN ---\n";
$existe = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE id = {$candidato}");
echo "ID candidato {$candidato} no existe actualmente : " . ($existe === 0 ? 'SÍ (no existe)' : 'NO (YA EXISTE)') . "\n";
echo "asignarVariante({$candidato},3) = {$varianteCandidato} : " . ($varianteCandidato === 'B' ? 'OK' : 'FALLO') . "\n";

$camp = $db->querySingle("SELECT id, estado, entorno, activo FROM pipelines WHERE id = 3", true);
$campOk = $camp && strtoupper((string)$camp['estado']) === 'PILOT' && strtolower((string)$camp['entorno']) === 'test' && (int)$camp['activo'] === 1;
echo "campaña 3 PILOT/test/activo=1 : " . ($campOk ? 'OK' : 'FALLO') . " " . json_encode($camp, JSON_UNESCAPED_UNICODE) . "\n";

$plant = $db->querySingle("SELECT id, activo, test_ab FROM plantillas WHERE id = 2", true);
$plantOk = $plant && (int)$plant['activo'] === 1 && (int)$plant['test_ab'] === 1;
echo "plantilla 2 activa y test_ab=1 : " . ($plantOk ? 'OK' : 'FALLO') . " " . json_encode($plant, JSON_UNESCAPED_UNICODE) . "\n";

$modoEntorno = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'");
echo "modo_entorno=test : " . ($modoEntorno === 'test' ? 'OK' : 'FALLO') . " (valor={$modoEntorno})\n";

$motorEstado = (string)$db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'");
echo "motor_estado=pausado : " . ($motorEstado === 'pausado' ? 'OK' : 'FALLO') . " (valor={$motorEstado})\n";

$maxEnvios = (int)($db->querySingle("SELECT MAX(id) FROM envios") ?: 0);
echo "MAX(envios.id)=7 : " . ($maxEnvios === 7 ? 'OK' : 'FALLO') . " (valor={$maxEnvios})\n";

$db->close();

echo "\n==================== PARADA OBLIGATORIA ====================\n";
echo "FASE ABC-FINAL.5\n";
echo "SOLO LECTURA\n";
echo "CANDIDATO B ENCONTRADO: {$candidato} (variante {$varianteCandidato})\n";
echo "NO SE CREÓ NINGÚN LEAD\n";
echo "NO SE REALIZÓ NINGÚN ENVÍO\n";
exit(0);