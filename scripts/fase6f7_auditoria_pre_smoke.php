<?php
/**
 * FASE 6F.7 — Auditoría PRE-SMOKE (SOLO LECTURA).
 * No escribe nada, no abre SMTP, no ejecuta envíos.
 */
declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
$db = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);

function show(string $k, $v): void {
    if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
    if ($v === null) { $v = 'NULL'; }
    echo str_pad($k, 44) . " : " . $v . "\n";
}

echo "================= CONFIGURACIÓN GLOBAL =================\n";
foreach (['modo_entorno','motor_estado','lanzadera_delay','test_emails'] as $clave) {
    $v = $db->querySingle("SELECT valor FROM config WHERE clave = '" . $db->escapeString($clave) . "'");
    show('config[' . $clave . ']', $v);
}

echo "\n================= CAMPAÑAS (pipelines) =================\n";
$res = $db->query("SELECT id, nombre, identificador, estado, entorno, activo FROM pipelines WHERE id IN (1,2,3) ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "--- pipeline id={$r['id']} ---\n";
    show('nombre', $r['nombre']);
    show('identificador', $r['identificador']);
    show('estado', $r['estado']);
    show('entorno', $r['entorno']);
    show('activo', $r['activo']);
}

echo "\n================= LEADS TEST 1809-1813 =================\n";
$res = $db->query("SELECT id, nombre_club, email, estado_lead, es_duplicado FROM clubes_crm WHERE id BETWEEN 1809 AND 1813 ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "--- lead id={$r['id']} ---\n";
    show('nombre_club', $r['nombre_club']);
    show('email', $r['email']);
    show('estado_lead', $r['estado_lead']);
    show('es_duplicado', $r['es_duplicado']);
}

echo "\n================= ENVÍOS (campaign_id=3) =================\n";
$res = $db->query("SELECT id, lead_id, club, email, estado, resultado_envio, variant, plantilla_id, smtp_id FROM envios WHERE campaign_id = 3 ORDER BY id");
$n = 0;
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $n++;
    echo "envio_id={$r['id']} | lead_id={$r['lead_id']} | email={$r['email']} | estado={$r['estado']} | resultado={$r['resultado_envio']} | variant={$r['variant']} | plantilla_id={$r['plantilla_id']} | smtp_id={$r['smtp_id']}\n";
}
if ($n === 0) echo "(ningún envío para campaign_id=3)\n";

echo "\n================= IDEMPOTENCIA LEADS 1809-1813 vs CAMPAÑA 3 =================\n";
foreach (range(1809, 1813) as $lid) {
    $row = $db->querySingle("SELECT id, estado, resultado_envio FROM envios WHERE lead_id = {$lid} AND campaign_id = 3 ORDER BY id DESC LIMIT 1", true);
    if ($row) {
        show("lead {$lid}", "envío lógico EXISTE (envio_id={$row['id']}, estado={$row['estado']}, resultado={$row['resultado_envio']})");
    } else {
        show("lead {$lid}", "LIMPIO (sin envío lógico en campaign 3)");
    }
}

echo "\n================= PLANTILLAS ACTIVAS =================\n";
$res = $db->query("SELECT id, nombre, tipo, categoria, test_ab, activo FROM plantillas WHERE activo = 1 ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "plantilla id={$r['id']} | nombre={$r['nombre']} | tipo={$r['tipo']} | categoria={$r['categoria']} | test_ab={$r['test_ab']}\n";
}

echo "\n================= PLANTILLA ID=2 (detalle) =================\n";
$p2 = $db->querySingle("SELECT id, nombre, tipo, categoria, test_ab, activo, (asunto IS NOT NULL AND asunto != '') AS tiene_asunto, (cuerpo IS NOT NULL AND cuerpo != '') AS tiene_cuerpo, (asunto_b IS NOT NULL AND asunto_b != '') AS tiene_asunto_b, (cuerpo_b IS NOT NULL AND cuerpo_b != '') AS tiene_cuerpo_b, (asunto_c IS NOT NULL AND asunto_c != '') AS tiene_asunto_c, (cuerpo_c IS NOT NULL AND cuerpo_c != '') AS tiene_cuerpo_c FROM plantillas WHERE id = 2", true);
if ($p2) {
    foreach ($p2 as $k => $v) { show('plantilla2.' . $k, $v); }
} else {
    show('plantilla id=2', 'NO EXISTE');
}

echo "\n================= CUENTAS SMTP ACTIVAS (sin credenciales) =================\n";
$res = $db->query("SELECT id, email, host, puerto, seguridad, enviados_hoy, limite_diario, activa, nombre_emisor FROM cuentas_smtp WHERE activa = 1 ORDER BY id");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "smtp_id={$r['id']} | email={$r['email']} | host={$r['host']} | puerto={$r['puerto']} | seg={$r['seguridad']} | enviados_hoy={$r['enviados_hoy']} | limite={$r['limite_diario']} | emisor={$r['nombre_emisor']}\n";
}

echo "\n================= MODO TEST EFECTIVO =================\n";
$modo = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'modo_entorno'") ?: 'test');
show('modo_entorno', $modo);
show('modo_test_efectivo', ($modo === 'test'));

echo "\n================= TEST EMAIL CONFIGURABLE =================\n";
$te = $db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'");
show('test_emails', $te !== null ? $te : '(vacío / no configurado)');

// Para el smoke se usa el parámetro POST test_email, no la config.
echo "\n[OK] Auditoría completada en SOLO LECTURA (SQLITE3_OPEN_READONLY). Ningún POST, ningún SMTP, ninguna escritura.\n";
$db->close();