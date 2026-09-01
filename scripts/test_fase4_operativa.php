<?php
/**
 * test_fase4_operativa.php — TESTS de FASE 4 del MEGAPROMPT V2 (CRM FutProtec).
 *
 * Comprueba:
 *   TEST 01 — DB integrity
 *   TEST 04 — clasificación rápida (set de 9 estados válidos + mapeo de estados)
 *   TEST 06 — oportunidad: una respuesta POSITIVE genera oportunidad (idempotente)
 *   TEST 07 — presupuesto vinculable a lead+campaña+oportunidad (estructura)
 *   TEST 08 — mockup trazable (estructura con campaign_id/opportunity_id/version)
 *
 * Uso local:  php scripts/test_fase4_operativa.php
 * No envía emails. Los tests 06/07/08 usan una BD en memoria (no toca datos reales).
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php'; // incluye abc.php y respuestas.php

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS | {$nombre}\n";
    } else {
        $fail++;
        echo "FAIL | {$nombre} | {$detalle}\n";
    }
}

// ─── TEST 01 — DB integrity + estructura real ──────────────────────────────
$db = new SQLite3($DB);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
check('TEST 01 DB integrity', ($db->querySingle('PRAGMA integrity_check') ?? '') === 'ok');

$colsPres = [];
$r = $db->query('PRAGMA table_info(presupuestos)');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $colsPres[] = $row['name']; }
check('TEST 07 presupuestos con campaign_id/opportunity_id',
    in_array('campaign_id', $colsPres, true) && in_array('opportunity_id', $colsPres, true)
    && in_array('respuesta_origen_id', $colsPres, true) && in_array('envio_origen_id', $colsPres, true));

$colsMock = [];
$r = $db->query('PRAGMA table_info(mockups)');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $colsMock[] = $row['name']; }
check('TEST 08 mockups con campaign_id/opportunity_id/version',
    in_array('campaign_id', $colsMock, true) && in_array('opportunity_id', $colsMock, true)
    && in_array('presupuesto_id', $colsMock, true) && in_array('version', $colsMock, true));

// No tocar el histórico: la tabla oportunidades debe seguir vacía (0 filas).
$opReal = (int)$db->querySingle('SELECT COUNT(*) FROM oportunidades');
check('TEST 06 no se crean oportunidades retroactivas (histórico intacto)', $opReal === 0, "oportunidades={$opReal}");

// ─── TEST 04 — clasificación rápida (puro, sin DB) ─────────────────────────
$setRapido = ['POSITIVE', 'INTERESADO', 'SOLICITA_INFO', 'SOLICITA_PRECIO', 'SOLICITA_MOCKUP', 'NO_INTERESADO', 'FUERA_DE_OFICINA', 'HARD_BOUNCE', 'OTRO'];
$faltan = array_diff($setRapido, CLASIFICACIONES_VALIDAS);
check('TEST 04 set rápido (9 estados) válido', count($faltan) === 0, implode(',', $faltan));
check('TEST 04 SOLICITA_PRECIO → 03 En Conversación', estadoDestinoPorClasificacion('SOLICITA_PRECIO') === '03 En Conversación');
check('TEST 04 NO_INTERESADO → 06 Perdido', estadoDestinoPorClasificacion('NO_INTERESADO') === '06 Perdido');
check('TEST 04 HARD_BOUNCE no mueve estado', estadoDestinoPorClasificacion('HARD_BOUNCE') === null);
// ─── TEST 06 — oportunidad desde respuesta (BD en memoria) ─────────────────
$m = new SQLite3(':memory:');
$m->exec("CREATE TABLE clubes_crm (id INTEGER PRIMARY KEY, email TEXT, nombre_club TEXT, estado_lead TEXT)");
$m->exec("CREATE TABLE envios (id INTEGER PRIMARY KEY, lead_id INTEGER, campaign_id INTEGER, message_id TEXT)");
$m->exec("CREATE TABLE respuestas (id INTEGER PRIMARY KEY, envio_id INTEGER, lead_id INTEGER, campaign_id INTEGER, clasificacion TEXT, intencion TEXT, proxima_accion TEXT)");
$m->exec("CREATE TABLE oportunidades (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, campaign_id INTEGER, estado TEXT, origen TEXT, fecha_creacion TEXT, fecha_actualizacion TEXT, es_test INTEGER, notas TEXT)");
$m->exec("CREATE TABLE comunicaciones_log (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, club_id INTEGER, tipo_evento TEXT, detalles TEXT, fecha TEXT)");
$m->exec("INSERT INTO clubes_crm (id, email, nombre_club, estado_lead) VALUES (7777, 'club-real@ejemplo.es', 'Club Real Test', '03 En Conversación')");
$m->exec("INSERT INTO envios (id, lead_id, campaign_id, message_id) VALUES (8888, 7777, 2, '<m1@x>')");
$m->exec("INSERT INTO respuestas (id, envio_id, lead_id, campaign_id, clasificacion) VALUES (9999, 8888, 7777, 2, 'POSITIVE')");

$op1 = crearOportunidadDesdeRespuesta($m, 9999);
check('TEST 06 respuesta POSITIVE genera oportunidad', $op1['ok'] && !($op1['existente'] ?? true), json_encode($op1));
$opId1 = $op1['id'] ?? 0;
$opRow = $m->querySingle("SELECT lead_id, campaign_id, es_test FROM oportunidades WHERE id = {$opId1}", true);
check('TEST 06 oportunidad vinculada a lead+campaña', $opRow && (int)$opRow['lead_id'] === 7777 && (int)$opRow['campaign_id'] === 2, json_encode($opRow));
check('TEST 06 oportunidad es REAL (es_test=0)', $opRow && (int)$opRow['es_test'] === 0);
$ev = (int)$m->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'oportunidad_creada' AND lead_id = 7777");
check('TEST 06 evento oportunidad_creada registrado', $ev === 1, "eventos={$ev}");
$op2 = crearOportunidadDesdeRespuesta($m, 9999);
check('TEST 06 idempotente (no duplica)', $op2['ok'] && ($op2['existente'] ?? false) === true && $op2['id'] === $opId1, json_encode($op2));

// ─── TEST 07 — INSERT de presupuesto con opportunity_id (en memoria) ───────
$m->exec("CREATE TABLE presupuestos (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, pipeline_id INTEGER, campaign_id INTEGER, opportunity_id INTEGER, version INTEGER, unidades INTEGER, precio_unitario REAL, subtotal REAL, descuento_aplicado REAL, condiciones_pago TEXT, transporte TEXT, importe_total REAL, margen_potencial_club REAL, estado TEXT, fecha TEXT)");
$st = $m->prepare("INSERT INTO presupuestos (lead_id, pipeline_id, campaign_id, opportunity_id, version, unidades, precio_unitario, subtotal, descuento_aplicado, condiciones_pago, transporte, importe_total, margen_potencial_club, estado, fecha) VALUES (:lid, NULL, :cid, :oid, 1, 50, 9.0, 450.0, 0, '50%+50%', 'Incluido', 450.0, 200.0, 'creado', CURRENT_TIMESTAMP)");
$st->bindValue(':lid', 7777, SQLITE3_INTEGER);
$st->bindValue(':cid', 2, SQLITE3_INTEGER);
$st->bindValue(':oid', $opId1, SQLITE3_INTEGER);
$st->execute();
$pRow = $m->querySingle("SELECT lead_id, campaign_id, opportunity_id FROM presupuestos WHERE id = " . $m->lastInsertRowID(), true);
check('TEST 07 presupuesto vinculado a lead+campaña+oportunidad',
    $pRow && (int)$pRow['lead_id'] === 7777 && (int)$pRow['campaign_id'] === 2 && (int)$pRow['opportunity_id'] === $opId1, json_encode($pRow));

$m->close();

echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

