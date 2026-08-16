<?php
/**
 * FASE 6F.6 — Harness aislado de corrección TEST/REALES.
 *
 * NO toca la BD real (stats.db) y NO ejecuta ningún SMTP.
 * Construye una BD SQLite EN MEMORIA con el esquema mínimo necesario y verifica:
 *
 *   1. campaign_id=3 (TEST)  + lead TEST  → permitido por aislamiento
 *   2. campaign_id=3 (TEST)  + lead REAL  → bloqueado (lead_real_en_campana_test)
 *   3. campaign_id=2 (pilot) + lead TEST  → bloqueado (lead_test_en_campana_no_test)
 *   4. campaign_id=2 (pilot) + lead REAL  → pasa aislamiento (razon=elegible)
 *   5. get_cola campaign 3  → no contiene leads reales
 *   6. get_cola campaign 2  → no contiene leads TEST
 *   7. cron campaign TEST   → no selecciona leads reales
 *   8. enviar_lote directo  (campaign 3 + lead real) → bloqueado antes de SMTP
 *
 * Uso: php scripts/fase6f6_test_aislamiento.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

require_once __DIR__ . '/../public_html/outbound/inc/abc.php';
require_once __DIR__ . '/../public_html/outbound/inc/respuestas.php';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';

// ─── BD aislada en memoria ────────────────────────────────────────────────────
$db = new SQLite3(':memory:');
$db->enableExceptions(true);

$db->exec("CREATE TABLE pipelines (
    id INTEGER PRIMARY KEY,
    nombre TEXT,
    entorno TEXT,
    estado TEXT,
    activo INTEGER DEFAULT 1
)");
$db->exec("CREATE TABLE clubes_crm (
    id INTEGER PRIMARY KEY,
    nombre_club TEXT,
    email TEXT,
    estado_lead TEXT DEFAULT '01 Sin Contactar',
    es_duplicado INTEGER DEFAULT 0,
    federacion TEXT,
    creado_el TEXT
)");
$db->exec("CREATE TABLE envios (
    id INTEGER PRIMARY KEY,
    lead_id INTEGER,
    campaign_id INTEGER,
    email TEXT,
    estado TEXT,
    club TEXT,
    federacion TEXT,
    cuenta_emision TEXT,
    tracking_id TEXT,
    asunto TEXT,
    cuerpo_mensaje TEXT,
    message_id TEXT,
    variant TEXT,
    plantilla_id INTEGER,
    smtp_id INTEGER,
    resultado_envio TEXT,
    fecha_resultado_envio TEXT
)");

// Índice único parcial del que depende reservarEnvioLogico() para la idempotencia
// (mismo índice que producción: idx_envios_lead_campaign).
$db->exec("CREATE UNIQUE INDEX idx_envios_lead_campaign
           ON envios(lead_id, campaign_id)
           WHERE campaign_id IS NOT NULL");

// Campañas
$db->exec("INSERT INTO pipelines (id, nombre, entorno, estado, activo) VALUES
    (2, 'Piloto Comercial', 'pilot', 'PILOT', 1),
    (3, 'SMOKE TEST', 'test', 'PILOT', 1)");

// Leads
$db->exec("INSERT INTO clubes_crm (id, nombre_club, email, estado_lead, es_duplicado, creado_el) VALUES
    (1809, 'TEST_CLUB_01', 'test01@futprotec.local', '01 Sin Contactar', 0, '2026-08-01 10:00:00'),
    (1, 'A.C.R. ESCUELA DE FUTBOL', 'acrefaguilas07@gmail.com', '01 Sin Contactar', 0, '2026-08-01 09:00:00')");

$leadTest = ['nombre_club' => 'TEST_CLUB_01', 'email' => 'test01@futprotec.local'];
$leadReal = ['nombre_club' => 'A.C.R. ESCUELA DE FUTBOL', 'email' => 'acrefaguilas07@gmail.com'];

// ─── RESULTADOS ───────────────────────────────────────────────────────────────
$fail = 0;
function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $fail;
    $st = $cond ? 'PASS' : 'FAIL';
    if (!$cond) $fail++;
    echo "[{$st}] {$nombre}";
    if ($detalle !== '') echo " — {$detalle}";
    echo "\n";
}

// Precondición: helpers de clasificación
check(
    'esLeadTest() detecta lead TEST por email/criterio existente',
    esLeadTest($leadTest) === true && esLeadTest($leadReal) === false
);
check(
    'esCampanaTest() detecta campaña TEST por pipelines.entorno',
    esCampanaTest($db, 3) === true && esCampanaTest($db, 2) === false
);

// ─── 1. CAMPAÑA TEST + LEAD TEST → PERMITIDO ─────────────────────────────────
$r1 = esElegibleParaEnvio($db, 1809, 3);
check('1. campaign_id=3 (TEST) + lead TEST → permitido', $r1['ok'] === true, "razon={$r1['razon']}");

// ─── 2. CAMPAÑA TEST + LEAD REAL → BLOQUEADO ─────────────────────────────────
$r2 = esElegibleParaEnvio($db, 1, 3);
check(
    '2. campaign_id=3 (TEST) + lead REAL → bloqueado',
    $r2['ok'] === false && $r2['razon'] === 'lead_real_en_campana_test',
    "razon={$r2['razon']}"
);

// ─── 3. CAMPAÑA NO TEST + LEAD TEST → BLOQUEADO ──────────────────────────────
$r3 = esElegibleParaEnvio($db, 1809, 2);
check(
    '3. campaign_id=2 (pilot) + lead TEST → bloqueado',
    $r3['ok'] === false && $r3['razon'] === 'lead_test_en_campana_no_test',
    "razon={$r3['razon']}"
);

// ─── 4. CAMPAÑA NO TEST + LEAD REAL → PASA AISLAMIENTO ───────────────────────
$r4 = esElegibleParaEnvio($db, 1, 2);
check(
    '4. campaign_id=2 (pilot) + lead REAL → pasa aislamiento (continúa a otras validaciones)',
    $r4['ok'] === true && $r4['razon'] === 'elegible',
    "razon={$r4['razon']}"
);

// ─── 5/6. get_cola: filtrado SQL por compatibilidad campaña/lead ─────────────
$sqlBase = "SELECT c.id, c.nombre_club, c.email
            FROM clubes_crm c
            WHERE c.email IS NOT NULL AND c.email != '' AND c.es_duplicado = 0";

// Caso 5: campaña TEST → sólo leads TEST
$sql5 = $sqlBase . sqlFiltroCompatibilidadLeadCampana($db, 3) . " ORDER BY c.nombre_club ASC";
$res5 = $db->query($sql5);
$ids5 = [];
while ($x = $res5->fetchArray(SQLITE3_ASSOC)) { $ids5[] = (int)$x['id']; }
check(
    '5. get_cola campaign 3 → no contiene leads reales',
    in_array(1809, $ids5, true) && !in_array(1, $ids5, true),
    'ids=[' . implode(',', $ids5) . ']'
);

// Caso 6: campaña no TEST (2) → no contiene leads TEST
$sql6 = $sqlBase . sqlFiltroCompatibilidadLeadCampana($db, 2) . " ORDER BY c.nombre_club ASC";
$res6 = $db->query($sql6);
$ids6 = [];
while ($x = $res6->fetchArray(SQLITE3_ASSOC)) { $ids6[] = (int)$x['id']; }
check(
    '6. get_cola campaign 2 → no contiene leads TEST',
    in_array(1, $ids6, true) && !in_array(1809, $ids6, true),
    'ids=[' . implode(',', $ids6) . ']'
);

// ─── 7. cron: la selección SQL no devuelve leads incompatibles ───────────────
$filtroCron = sqlFiltroCompatibilidadLeadCampana($db, 3);
$sql7 = "SELECT c.id FROM clubes_crm c
         LEFT JOIN envios e ON LOWER(e.email) = LOWER(c.email) AND e.estado = 'enviado'
          WHERE c.estado_lead = '01 Sin Contactar'
           AND c.email IS NOT NULL AND c.email != ''
           AND e.id IS NULL
           {$filtroCron}
         ORDER BY c.creado_el ASC
         LIMIT 1";
$leadCronTest = $db->querySingle($sql7, true);
check(
    '7. cron campaign TEST → no selecciona leads reales',
    $leadCronTest !== false && (int)$leadCronTest['id'] === 1809,
    'lead_id=' . ($leadCronTest !== false ? $leadCronTest['id'] : 'NINGUNO')
);

// ─── 8. enviar_lote directo: campaign 3 + lead real → bloqueado antes de SMTP ─
// enviar_lote.php invoca esElegibleParaEnvio() (línea 105) ANTES de cualquier
// SMTP (línea 262). Aquí se reproduce esa MISMAS llamada con la misma firma.
$r8 = esElegibleParaEnvio($db, 1, 3);
check(
    '8. enviar_lote directo campaign_id=3 + id_club real → bloqueado antes de SMTP',
    $r8['ok'] === false && $r8['razon'] === 'lead_real_en_campana_test',
    "razon={$r8['razon']}"
);

// ─── REGRESIÓN (FASE 7): lógicas existentes intactas ─────────────────────────
$regresion = [];

// asignarVariante: determinística e inmutable
$v1 = asignarVariante(1, 2);
$v2 = asignarVariante(1, 2);
$regresion['asignarVariante determinística/inmutable'] = ($v1 === $v2 && in_array($v1, ['A','B','C'], true));

// esEntornoCoherente
$coh1 = esEntornoCoherente('test', 'produccion');
$coh2 = esEntornoCoherente('pilot', 'test');
$coh3 = esEntornoCoherente('pilot', 'produccion');
$regresion['esEntornoCoherente mínima'] = ($coh1['ok'] === false && $coh2['ok'] === false && $coh3['ok'] === true);

// reservarEnvioLogico: idempotencia (misma pareja → mismo id de fila, no duplicar)
$resA = reservarEnvioLogico($db, 1, 2, 'CLUB', 'club@example.com', 'FED', 'cuenta@x.com', 'tid-x', 'asunto', 'cuerpo', 'A', 1, 1);
$resB = reservarEnvioLogico($db, 1, 2, 'CLUB', 'club@example.com', 'FED', 'cuenta@x.com', 'tid-x', 'asunto', 'cuerpo', 'A', 1, 1);
$regresion['reservarEnvioLogico idempotente'] = ($resA['nuevo'] === true && $resB['nuevo'] === false && $resA['id'] === $resB['id']);

// supresión: un lead en estado de baja sigue bloqueado
$db->exec("INSERT INTO clubes_crm (id, nombre_club, email, estado_lead, es_duplicado, creado_el)
    VALUES (2000, 'Baja Club', 'baja@example.com', 'Lista Negra', 0, '2026-08-01 11:00:00')");
$rSup = esElegibleParaEnvio($db, 2000, 2);
$regresion['supresión Lista Negra intacta'] = ($rSup['ok'] === false && $rSup['razon'] === 'supresion');

// email inválido sigue bloqueado
$db->exec("INSERT INTO clubes_crm (id, nombre_club, email, estado_lead, es_duplicado, creado_el)
    VALUES (2001, 'Inválido Club', 'no-email', '01 Sin Contactar', 0, '2026-08-01 11:01:00')");
$rInv = esElegibleParaEnvio($db, 2001, 2);
$regresion['email inválido intacto'] = ($rInv['ok'] === false && $rInv['razon'] === 'email_invalido');

// duplicado sigue bloqueado
$db->exec("INSERT INTO clubes_crm (id, nombre_club, email, estado_lead, es_duplicado, creado_el)
    VALUES (2002, 'Duplicado Club', 'dup@example.com', '01 Sin Contactar', 1, '2026-08-01 11:02:00')");
$rDup = esElegibleParaEnvio($db, 2002, 2);
$regresion['duplicado intacto'] = ($rDup['ok'] === false && $rDup['razon'] === 'duplicado');

foreach ($regresion as $nombre => $ok) {
    check('REGRESIÓN: ' . $nombre, $ok);
}

// ─── RESUMEN ─────────────────────────────────────────────────────────────────
echo "\n" . ($fail === 0 ? '✅ TODAS LAS PRUEBAS PASARON' : "❌ {$fail} PRUEBA(S) FALLARON") . "\n";
echo "NO SE HA REALIZADO NINGÚN ENVÍO SMTP.\n";
exit($fail === 0 ? 0 : 1);