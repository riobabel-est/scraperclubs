<?php
/**
 * FASE LAUNCHER-FINAL — Harness aislado de idempotencia TEST vs REAL (sin SMTP).
 * NO toca stats.db. BD SQLite en memoria.
 * Verifica el cambio de enviar_lote.php:
 *   - Modo prueba reserva con campaign_id NULL → 3 variantes A/B/C no colisionan
 *     (idx_envios_lead_campaign es parcial WHERE campaign_id IS NOT NULL).
 *   - Modo real reserva con campaign_id > 0 → sigue siendo idempotente (1 fila).
 */

declare(strict_types=1);
error_reporting(E_ALL);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/respuestas.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

$db = new SQLite3(':memory:');
$db->enableExceptions(true);

$db->exec("CREATE TABLE envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    club TEXT, email TEXT, federacion TEXT DEFAULT '', cuenta_emision TEXT DEFAULT '',
    estado TEXT DEFAULT 'pendiente', tracking_id TEXT UNIQUE NOT NULL,
    asunto TEXT DEFAULT '', cuerpo_mensaje TEXT DEFAULT '',
    lead_id INTEGER, campaign_id INTEGER, variant VARCHAR(1),
    plantilla_id INTEGER, smtp_id INTEGER, message_id TEXT,
    resultado_envio TEXT, fecha_resultado_envio DATETIME)");
$db->exec("CREATE UNIQUE INDEX idx_envios_lead_campaign ON envios(lead_id, campaign_id) WHERE campaign_id IS NOT NULL");

$leadId = 1809;
$n = 0;
$total = 0;
$pass = 0;

function check(bool $cond, string $label): void {
    global $pass, $total;
    $total++;
    if ($cond) { $pass++; echo "[PASS] {$label}\n"; }
    else { echo "[FAIL] {$label}\n"; }
}

// ─── MODO PRUEBA: 3 variantes A/B/C sobre el mismo lead → 3 filas ──────────
foreach (['A', 'B', 'C'] as $v) {
    $res = reservarEnvioLogico($db, $leadId, 0, 'CLUB_TEST', 't@futprotec.local', 'FED', 'c@x.com', 't_'.$v, 's', 'c', $v, 2, 1);
}
$testRows = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$leadId} AND campaign_id IS NULL");
check($testRows === 3, "Modo prueba: 3 variantes -> 3 filas con campaign_id NULL (sin colisión)");

// Las 3 filas deben conservar variantes distintas
$variantes = [];
$r = $db->query("SELECT variant FROM envios WHERE lead_id = {$leadId} AND campaign_id IS NULL ORDER BY id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $variantes[] = $row['variant']; }
sort($variantes);
check($variantes === ['A', 'B', 'C'], "Modo prueba: variantes explícitas A/B/C preservadas");

// ─── MODO REAL: mismo lead + misma campaña → idempotente → 1 fila ──────────
reservarEnvioLogico($db, $leadId, 3, 'CLUB_REAL', 'real@gmail.com', 'FED', 'c@x.com', 't_real', 's', 'c', 'A', 2, 1);
reservarEnvioLogico($db, $leadId, 3, 'CLUB_REAL', 'real@gmail.com', 'FED', 'c@x.com', 't_real', 's', 'c', 'A', 2, 1);
$realRows = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE lead_id = {$leadId} AND campaign_id = 3");
check($realRows === 1, "Modo real: mismo lead + misma campaña -> 1 fila (idempotente)");

// La fila real conserva la variante DETERMINÍSTICA asignada (no la del llamador)
$variantReal = $db->querySingle("SELECT variant FROM envios WHERE lead_id = {$leadId} AND campaign_id = 3");
$expected = asignarVariante($leadId, 3);
check($variantReal === $expected, "Modo real: variante determinística respetada ({$variantReal})");

echo "\nResultado: {$pass}/{$total} pasaron.\n";
exit($pass === $total ? 0 : 1);