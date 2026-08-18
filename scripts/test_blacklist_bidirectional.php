<?php
/**
 * test_blacklist_bidirectional.php — TEST LOCAL (BLOQUE 10)
 * =========================================================
 * Valida la gestión BIDIRECCIONAL de Lista Negra (añadir/quitar) replicando
 * EXACTAMENTE la lógica de dashboard.php (blacklist_add / blacklist_remove)
 * contra una BD SQLite EN MEMORIA (no toca la BD real).
 *
 * Tests:
 *   A  Lead normal → añadir → suprimido, inelegible, visible en Lista Negra
 *   B  Quitar (motivo obligatorio) → no suprimido, elegible, desaparece, historial permanece
 *   C  Lead con opt-out real → quitar PERMITIDO → elegible, historial [BAJA] intacto
 *   D  Añadir→Quitar→Añadir→Quitar → historial no se pierde
 *   E  Desde ficha (misma lógica blacklist_add/remove)
 *   F  Desde Lista Negra (misma lógica)
 *   G  Lead reactivado → get_cola puede devolverlo (filtro estado_lead)
 *
 * Uso: php scripts/test_blacklist_bidirectional.php
 */

declare(strict_types=1);

$PASS = 0;
$FAIL = 0;
$FAILS = [];

function check(string $nombre, bool $cond, string $detalle = ''): void {
    global $PASS, $FAIL, $FAILS;
    if ($cond) {
        $PASS++;
        echo "  ✅ $nombre\n";
    } else {
        $FAIL++;
        $FAILS[] = $nombre;
        echo "  ❌ $nombre" . ($detalle !== '' ? " — $detalle" : '') . "\n";
    }
}

// ─── Estados de supresión (espejo de dashboard.php / eligibilidad.php) ─────
$estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

// ─── Crear BD en memoria con el esquema real ───────────────────────────────
$db = new SQLite3(':memory:');
$db->exec("CREATE TABLE clubes_crm (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre_club TEXT NOT NULL,
    federacion TEXT DEFAULT '',
    persona_contacto TEXT DEFAULT '',
    cargo_contacto TEXT DEFAULT '',
    email TEXT UNIQUE NOT NULL,
    telefono_fijo TEXT DEFAULT '',
    telefono_movil TEXT DEFAULT '',
    tiene_whatsapp INTEGER DEFAULT 0,
    estado_lead TEXT DEFAULT 'Sin Contactar',
    observaciones TEXT DEFAULT '',
    ultimo_contacto DATETIME,
    creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
    es_duplicado INTEGER DEFAULT 0,
    duplicado_id INTEGER DEFAULT NULL,
    estado_lead_backup TEXT,
    volumen_estimado INTEGER DEFAULT NULL,
    num_jugadores INTEGER DEFAULT NULL,
    categorias TEXT DEFAULT '',
    fecha_decision_prevista DATE DEFAULT NULL,
    objeciones TEXT DEFAULT '',
    proxima_accion TEXT DEFAULT '',
    canal_interaccion TEXT DEFAULT '',
    motivo_perdida TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE comunicaciones_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER DEFAULT NULL,
    club_id INTEGER DEFAULT NULL,
    tipo_evento VARCHAR(50) NOT NULL,
    plantilla_id INTEGER DEFAULT NULL,
    detalles TEXT DEFAULT '',
    ip_registro VARCHAR(45) DEFAULT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_cuenta_smtp INTEGER DEFAULT NULL,
    tipo VARCHAR(20) DEFAULT 'email',
    resultado TEXT DEFAULT '',
    codigo_error TEXT DEFAULT '',
    variante_ab VARCHAR(1) DEFAULT '',
    pipeline_id INTEGER DEFAULT NULL,
    resumen TEXT DEFAULT '',
    proxima_accion TEXT DEFAULT '',
    canal VARCHAR(20) DEFAULT 'email'
)");

// ─── Helpers de inserción ──────────────────────────────────────────────────
function insertLead(SQLite3 $db, string $nombre, string $email, string $estado, string $obs = ''): int {
    $st = $db->prepare("INSERT INTO clubes_crm (nombre_club, email, estado_lead, observaciones) VALUES (:n,:e,:s,:o)");
    $st->bindValue(':n', $nombre, SQLITE3_TEXT);
    $st->bindValue(':e', $email, SQLITE3_TEXT);
    $st->bindValue(':s', $estado, SQLITE3_TEXT);
    $st->bindValue(':o', $obs, SQLITE3_TEXT);
    $st->execute();
    return (int)$db->lastInsertRowID();
}

// ─── blacklist_add (espejo EXACTO de dashboard.php) ────────────────────────
function blacklist_add(SQLite3 $db, int $id, string $motivo): array {
    global $estadosSupresion;
    $lead = $db->querySingle("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = {$id}", true);
    if (!$lead) return ['ok' => false, 'error' => 'Lead no encontrado'];
    $estadoActual = (string)$lead['estado_lead'];
    if (in_array($estadoActual, $estadosSupresion, true)) {
        return ['ok' => true, 'tipo' => 'ya_suprimido', 'ya_suprimido' => true];
    }
    $fecha = date('Y-m-d H:i:s');
    $motivoTxt = $motivo !== '' ? ' | motivo=' . $motivo : '';
    $obs = (string)$lead['observaciones'];
    $nuevaObs = $obs . "\n[LISTA NEGRA] " . $fecha . " | fuente=manual" . $motivoTxt;
    $backupActual = (string)($lead['estado_lead_backup'] ?? '');
    $nuevoBackup = $backupActual !== '' ? $backupActual : $estadoActual;
    $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead='Lista Negra', estado_lead_backup=:backup, observaciones=:o, ultimo_contacto=CURRENT_TIMESTAMP WHERE id=:id");
    $stmt->bindValue(':backup', $nuevoBackup, SQLITE3_TEXT);
    $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $stmtLog = $db->prepare("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (:lid,:cid,'blacklist_add',:det,CURRENT_TIMESTAMP)");
    $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', 'Añadido a Lista Negra' . $motivoTxt, SQLITE3_TEXT);
    $stmtLog->execute();
    return ['ok' => true, 'tipo' => 'bloqueo_manual'];
}

// ─── blacklist_remove (espejo EXACTO de dashboard.php) ─────────────────────
function blacklist_remove(SQLite3 $db, int $id, string $motivo): array {
    global $estadosSupresion;
    if ($motivo === '') return ['ok' => false, 'error' => 'El motivo de reactivación es obligatorio.', 'razon' => 'MOTIVO_REQUERIDO'];
    $lead = $db->querySingle("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = {$id}", true);
    if (!$lead) return ['ok' => false, 'error' => 'Lead no encontrado'];
    $obs = (string)$lead['observaciones'];
    $backup = trim((string)($lead['estado_lead_backup'] ?? ''));
    $estadoRestaurado = '01 Sin Contactar';
    if ($backup !== '' && !in_array($backup, $estadosSupresion, true)) {
        $estadoRestaurado = $backup;
    }
    $fecha = date('Y-m-d H:i:s');
    $motivoTxt = ' | motivo=' . $motivo;
    $nuevaObs = $obs . "\n[REACTIVACIÓN] " . $fecha . " | fuente=manual | quitar_lista_negra" . $motivoTxt;
    $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead=:estado, estado_lead_backup='', observaciones=:o, ultimo_contacto=CURRENT_TIMESTAMP WHERE id=:id");
    $stmt->bindValue(':estado', $estadoRestaurado, SQLITE3_TEXT);
    $stmt->bindValue(':o', $nuevaObs, SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $stmtLog = $db->prepare("INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (:lid,:cid,'blacklist_remove',:det,CURRENT_TIMESTAMP)");
    $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
    $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
    $stmtLog->bindValue(':det', 'Quitado de Lista Negra | estado_restaurado=' . $estadoRestaurado . $motivoTxt, SQLITE3_TEXT);
    $stmtLog->execute();
    return ['ok' => true, 'tipo' => 'lista_negra_quitado', 'estado_restaurado' => $estadoRestaurado];
}

// ─── esElegibleParaEnvio (espejo de eligibilidad.php, solo bloque supresión) ─
function esElegibleParaEnvio(SQLite3 $db, int $leadId): array {
    global $estadosSupresion;
    $lead = $db->querySingle("SELECT id, email, estado_lead, es_duplicado, nombre_club FROM clubes_crm WHERE id = {$leadId}", true);
    if (!$lead) return ['ok' => false, 'razon' => 'lead_no_encontrado'];
    if (in_array($lead['estado_lead'], $estadosSupresion, true)) return ['ok' => false, 'razon' => 'supresion'];
    if ((int)($lead['es_duplicado'] ?? 0) === 1) return ['ok' => false, 'razon' => 'duplicado'];
    if (empty($lead['email']) || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'razon' => 'email_invalido'];
    return ['ok' => true, 'razon' => 'elegible'];
}

// ─── get_cola (espejo del filtro de supresión de get_cola.php) ─────────────
function getColaIncluye(SQLite3 $db, int $leadId): bool {
    global $estadosSupresion;
    $inList = "'" . implode("','", array_map(function ($e) use ($db) { return $db->escapeString($e); }, $estadosSupresion)) . "'";
    $row = $db->querySingle("SELECT id FROM clubes_crm WHERE id = {$leadId} AND estado_lead NOT IN ({$inList})");
    return (bool)$row;
}

echo "═══════════════════════════════════════════════════════════════\n";
echo " TEST BLACKLIST BIDIRECCIONAL (LOCAL, BD en memoria)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST A — Lead normal: añadir → suprimido, inelegible, visible en Lista Negra
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST A — Añadir lead normal a Lista Negra\n";
$idA = insertLead($db, 'TEST_CLUB_A', 'test_a@futprotec.local', '01 Sin Contactar');
$r = blacklist_add($db, $idA, 'Cliente pidió no recibir comunicaciones');
check('A1 blacklist_add ok', $r['ok'] === true);
$leadA = $db->querySingle("SELECT estado_lead, estado_lead_backup, observaciones FROM clubes_crm WHERE id = {$idA}", true);
check('A2 estado = Lista Negra', $leadA['estado_lead'] === 'Lista Negra');
check('A3 estado_lead_backup guardado', $leadA['estado_lead_backup'] === '01 Sin Contactar');
check('A4 marca [LISTA NEGRA] en observaciones', str_contains($leadA['observaciones'], '[LISTA NEGRA]'));
$eligA = esElegibleParaEnvio($db, $idA);
check('A5 inelegible (supresion)', $eligA['ok'] === false && $eligA['razon'] === 'supresion');
check('A6 visible en Lista Negra (get_blacklist)', getColaIncluye($db, $idA) === false);
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST B — Quitar (motivo obligatorio) → no suprimido, elegible, historial permanece
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST B — Quitar de Lista Negra con motivo\n";
$rB = blacklist_remove($db, $idA, '');
check('B1 motivo obligatorio (vacío rechazado)', $rB['ok'] === false && $rB['razon'] === 'MOTIVO_REQUERIDO');
$rB2 = blacklist_remove($db, $idA, 'Cliente volvió a solicitar contacto');
check('B2 blacklist_remove ok', $rB2['ok'] === true);
check('B3 estado restaurado', $rB2['estado_restaurado'] === '01 Sin Contactar');
$leadB = $db->querySingle("SELECT estado_lead, estado_lead_backup, observaciones FROM clubes_crm WHERE id = {$idA}", true);
check('B4 no suprimido', $leadB['estado_lead'] === '01 Sin Contactar');
check('B5 backup limpiado', $leadB['estado_lead_backup'] === '');
check('B6 historial [LISTA NEGRA] permanece', str_contains($leadB['observaciones'], '[LISTA NEGRA]'));
check('B7 marca [REACTIVACIÓN] añadida', str_contains($leadB['observaciones'], '[REACTIVACIÓN]'));
$eligB = esElegibleParaEnvio($db, $idA);
check('B8 elegible', $eligB['ok'] === true && $eligB['razon'] === 'elegible');
check('B9 desaparece de Lista Negra (get_cola lo incluye)', getColaIncluye($db, $idA) === true);
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST C — Lead con opt-out real: quitar PERMITIDO, historial [BAJA] intacto
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST C — Opt-out real: quitar permitido, historial intacto\n";
$obsOptOut = "\n[BAJA] 2026-08-01 10:00:00 | fuente=email | motivo=destinatario";
$idC = insertLead($db, 'TEST_CLUB_C', 'test_c@futprotec.local', 'Opt-Out', $obsOptOut);
$eligC0 = esElegibleParaEnvio($db, $idC);
check('C1 inicialmente inelegible (opt-out)', $eligC0['ok'] === false && $eligC0['razon'] === 'supresion');
$rC = blacklist_remove($db, $idC, 'Cliente activo / relación comercial');
check('C2 quitar opt-out PERMITIDO', $rC['ok'] === true);
$leadC = $db->querySingle("SELECT estado_lead, observaciones FROM clubes_crm WHERE id = {$idC}", true);
check('C3 historial [BAJA] fuente=email intacto', str_contains($leadC['observaciones'], '[BAJA]') && str_contains($leadC['observaciones'], 'fuente=email'));
check('C4 marca [REACTIVACIÓN] añadida', str_contains($leadC['observaciones'], '[REACTIVACIÓN]'));
$eligC = esElegibleParaEnvio($db, $idC);
check('C5 elegible tras quitar (sin otra causa)', $eligC['ok'] === true && $eligC['razon'] === 'elegible');
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST D — Añadir→Quitar→Añadir→Quitar: historial no se pierde
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST D — Ciclo repetido añadir/quitar\n";
$idD = insertLead($db, 'TEST_CLUB_D', 'test_d@futprotec.local', '02 Contactado');
blacklist_add($db, $idD, 'Bloqueo 1');
blacklist_remove($db, $idD, 'Reactivación 1');
blacklist_add($db, $idD, 'Bloqueo 2');
blacklist_remove($db, $idD, 'Reactivación 2');
$leadD = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$idD}", true);
$obsD = $leadD['observaciones'];
check('D1 2 marcas [LISTA NEGRA]', substr_count($obsD, '[LISTA NEGRA]') === 2);
check('D2 2 marcas [REACTIVACIÓN]', substr_count($obsD, '[REACTIVACIÓN]') === 2);
check('D3 estado final operativo', $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$idD}") === '02 Contactado');
check('D4 elegible al final', esElegibleParaEnvio($db, $idD)['ok'] === true);
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST E — Desde ficha (misma lógica blacklist_add/remove)
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST E — Desde ficha del lead\n";
$idE = insertLead($db, 'TEST_CLUB_E', 'test_e@futprotec.local', '03 Respondió');
$rE1 = blacklist_add($db, $idE, 'Lead de prueba');
check('E1 añadir desde ficha', $rE1['ok'] === true && $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$idE}") === 'Lista Negra');
$rE2 = blacklist_remove($db, $idE, 'Prueba / QA');
check('E2 quitar desde ficha', $rE2['ok'] === true && $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$idE}") === '03 Respondió');
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST F — Desde Lista Negra (misma lógica)
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST F — Desde Lista Negra\n";
$idF = insertLead($db, 'TEST_CLUB_F', 'test_f@futprotec.local', '04 Interesado');
blacklist_add($db, $idF, 'Bloqueo preventivo');
check('F1 visible en Lista Negra', getColaIncluye($db, $idF) === false);
blacklist_remove($db, $idF, 'Bloqueo introducido por error');
check('F2 quitar desde Lista Negra', $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$idF}") === '04 Interesado');
check('F3 vuelve a cola', getColaIncluye($db, $idF) === true);
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// TEST G — Lead reactivado → get_cola puede devolverlo
// ═══════════════════════════════════════════════════════════════════════════
echo "TEST G — Lead reactivado devuelto por get_cola\n";
$idG = insertLead($db, 'TEST_CLUB_G', 'test_g@futprotec.local', '01 Sin Contactar');
blacklist_add($db, $idG, 'Bloqueo temporal');
check('G1 en Lista Negra → NO en cola', getColaIncluye($db, $idG) === false);
blacklist_remove($db, $idG, 'Cliente volvió a solicitar contacto');
check('G2 reactivado → SÍ en cola', getColaIncluye($db, $idG) === true);
check('G3 elegible', esElegibleParaEnvio($db, $idG)['ok'] === true);
echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// RESUMEN
// ═══════════════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════\n";
echo " RESULTADO: $PASS pasados, $FAIL fallidos\n";
if ($FAIL > 0) {
    echo " FALLIDOS:\n";
    foreach ($FAILS as $f) echo "   - $f\n";
    echo " VEREDICTO: BLOCKED\n";
    exit(1);
}
echo " VEREDICTO: BLACKLIST_BIDIRECTIONAL_MANAGEMENT_PASS\n";
echo "═══════════════════════════════════════════════════════════════\n";
exit(0);
