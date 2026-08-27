<?php
/**
 * campanas.php — Endpoints del Configurador de Campañas (P-1 Fases 2-3).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 *
 * Añade dos tablas auxiliares (idempotentes) SIN tocar el esquema de `pipelines`:
 *   - campaign_segmentos (campaign_id, tipo 'federacion'|'estado'|'todas', valor)
 *   - campaign_plantillas (campaign_id, plantilla_id)
 */

declare(strict_types=1);

// ─── Esquema idempotente (no altera pipelines existentes) ─────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS campaign_segmentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'federacion',
    valor TEXT NOT NULL DEFAULT '',
    UNIQUE(campaign_id, tipo, valor)
)");
$db->exec("CREATE TABLE IF NOT EXISTS campaign_plantillas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    plantilla_id INTEGER NOT NULL,
    UNIQUE(campaign_id, plantilla_id)
)");

// ─── get_federaciones ─────────────────────────────────────────────────────────
// Lista de federaciones reales de clubes_crm (para el checklist del configurador).
if ($action === 'get_federaciones') {
    header('Content-Type: application/json; charset=utf-8');
    $feds = [];
    $res = $db->query("SELECT DISTINCT federacion FROM clubes_crm WHERE email IS NOT NULL AND email != '' AND federacion != '' ORDER BY federacion ASC");
    while ($r = $res->fetchArray(SQLITE3_NUM)) { $feds[] = $r[0]; }
    echo json_encode(['ok' => true, 'federaciones' => $feds]);
    exit;
}

// ─── get_campanas ─────────────────────────────────────────────────────────────
// Lista de campañas con su segmento (todas/federaciones/estado) y plantillas.
if ($action === 'get_campanas') {
    header('Content-Type: application/json; charset=utf-8');
    $res = $db->query("SELECT id, nombre, identificador, estado, entorno, activo FROM pipelines ORDER BY id ASC");
    $campanas = [];
    while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
        $cid = (int)$c['id'];
        $c['segmento'] = ['todas' => false, 'federaciones' => [], 'estado' => ''];
        $r2 = $db->query("SELECT tipo, valor FROM campaign_segmentos WHERE campaign_id = {$cid}");
        while ($s = $r2->fetchArray(SQLITE3_ASSOC)) {
            if ($s['tipo'] === 'todas') { $c['segmento']['todas'] = true; }
            elseif ($s['tipo'] === 'federacion') { $c['segmento']['federaciones'][] = $s['valor']; }
            elseif ($s['tipo'] === 'estado') { $c['segmento']['estado'] = $s['valor']; }
        }
        $c['plantillas_id'] = [];
        $r3 = $db->query("SELECT plantilla_id FROM campaign_plantillas WHERE campaign_id = {$cid} ORDER BY plantilla_id");
        while ($p = $r3->fetchArray(SQLITE3_ASSOC)) { $c['plantillas_id'][] = (int)$p['plantilla_id']; }
        $campanas[] = $c;
    }
    echo json_encode(['ok' => true, 'campanas' => $campanas]);
    exit;
}


// ─── save_campaign ────────────────────────────────────────────────────────────
// Crea/actualiza la campaña y reemplaza su segmento + plantillas asignadas.
if ($action === 'save_campaign') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $identificador = trim($_POST['identificador'] ?? '');
        $entorno = trim($_POST['entorno'] ?? 'test');
        $estado = trim($_POST['estado'] ?? 'PILOT');
        $activo = (int)($_POST['activo'] ?? 1);
        if ($nombre === '' || $identificador === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre e identificador son obligatorios']);
            exit;
        }

        // Upsert pipeline.
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE pipelines SET nombre=:n, identificador=:i, entorno=:e, estado=:es, activo=:a WHERE id=:id");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':i', $identificador, SQLITE3_TEXT);
            $stmt->bindValue(':e', $entorno, SQLITE3_TEXT);
            $stmt->bindValue(':es', $estado, SQLITE3_TEXT);
            $stmt->bindValue(':a', $activo, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO pipelines (nombre, identificador, estado, entorno, activo) VALUES (:n, :i, :es, :e, :a)");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':i', $identificador, SQLITE3_TEXT);
            $stmt->bindValue(':es', $estado, SQLITE3_TEXT);
            $stmt->bindValue(':e', $entorno, SQLITE3_TEXT);
            $stmt->bindValue(':a', $activo, SQLITE3_INTEGER);
            $stmt->execute();
            $id = (int)$db->lastInsertRowID();
        }

        // Segmento: 'todas' | lista de federaciones | estado opcional.
        $db->exec("DELETE FROM campaign_segmentos WHERE campaign_id = {$id}");
        $todas = (($_POST['todas_federaciones'] ?? '0') === '1');
        $federaciones = $_POST['federaciones'] ?? [];
        if (is_string($federaciones)) { $federaciones = json_decode($federaciones, true) ?: []; }
        if ($todas) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_segmentos (campaign_id, tipo, valor) VALUES (:cid, 'todas', '1')");
            $stmt->bindValue(':cid', $id, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            foreach ($federaciones as $fed) {
                $fed = trim((string)$fed);
                if ($fed === '') { continue; }
                $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_segmentos (campaign_id, tipo, valor) VALUES (:cid, 'federacion', :v)");
                $stmt->bindValue(':cid', $id, SQLITE3_INTEGER);
                $stmt->bindValue(':v', $fed, SQLITE3_TEXT);
                $stmt->execute();
            }
        }
        $estadoLead = trim($_POST['estado_lead'] ?? '');
        if ($estadoLead !== '') {
            $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_segmentos (campaign_id, tipo, valor) VALUES (:cid, 'estado', :v)");
            $stmt->bindValue(':cid', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':v', $estadoLead, SQLITE3_TEXT);
            $stmt->execute();
        }

        // Plantillas asignadas (banco central; sin duplicar).
        $db->exec("DELETE FROM campaign_plantillas WHERE campaign_id = {$id}");
        $plantillas = $_POST['plantillas'] ?? [];
        if (is_string($plantillas)) { $plantillas = json_decode($plantillas, true) ?: []; }
        foreach ($plantillas as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) { continue; }
            $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_plantillas (campaign_id, plantilla_id) VALUES (:cid, :pid)");
            $stmt->bindValue(':cid', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
            $stmt->execute();
        }

        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── delete_campaign ──────────────────────────────────────────────────────────
// Elimina la campaña y sus segmentos/plantillas (no toca plantillas del banco).
if ($action === 'delete_campaign') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        $db->exec("DELETE FROM campaign_segmentos WHERE campaign_id = {$id}");
        $db->exec("DELETE FROM campaign_plantillas WHERE campaign_id = {$id}");
        $db->exec("DELETE FROM pipelines WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// SECUENCIAS CONDICIONALES (O-1 — ramificación por ramal ABC)
// Plan: docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md
// Tablas: secuencias (campaña, modo_auto) + secuencia_pasos (plantilla, espera, ramal)
// ═════════════════════════════════════════════════════════════════════════════

// ─── get_secuencias ───────────────────────────────────────────────────────────
// Lista las secuencias de una campaña con sus pasos (para el configurador).
if ($action === 'get_secuencias') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_GET['campaign_id'] ?? 0);
    $lista = [];
    $res = $db->query("SELECT id, campaign_id, nombre, modo_auto, activo, rotar_no_abridores, rotar_espera_dias, rotar_max_envios, rotar_plantilla_id FROM secuencias" . ($cid > 0 ? " WHERE campaign_id = {$cid}" : '') . " ORDER BY campaign_id, id");
    while ($s = $res->fetchArray(SQLITE3_ASSOC)) {
        $s['pasos'] = [];
        $r2 = $db->query("SELECT id, paso, plantilla_id, espera_dias, ramal, activo FROM secuencia_pasos WHERE secuencia_id = " . (int)$s['id'] . " ORDER BY paso ASC");
        while ($p = $r2->fetchArray(SQLITE3_ASSOC)) $s['pasos'][] = $p;
        $lista[] = $s;
    }
    echo json_encode(['ok' => true, 'secuencias' => $lista]);
    exit;
}

// ─── get_rotacion ───────────────────────────────────────────────────────────
// ROTACIÓN ABC PARA NO ABRIDORES: calcula los leads cuyo último envío de la
// campaña supera la espera configurada, NO abrieron, NO respondieron y aún les
// quedan intentos (rotar_max_envios). Devuelve la variante SIGUIENTE (A→B→C→A)
// y la plantilla a usar, para que la lanzadera la cargue preparada.
if ($action === 'get_rotacion') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../inc/abc.php';
        $cid = (int)($_GET['campaign_id'] ?? 0);
        $secId = (int)($_GET['secuencia_id'] ?? 0);
        if ($cid <= 0) { echo json_encode(['ok' => false, 'error' => 'campaign_id requerido']); exit; }

        $sec = null;
        if ($secId > 0) {
            $sec = $db->querySingle("SELECT * FROM secuencias WHERE id = {$secId} AND campaign_id = {$cid} AND activo = 1", true);
        } else {
            $sec = $db->querySingle("SELECT * FROM secuencias WHERE campaign_id = {$cid} AND activo = 1 AND rotar_no_abridores = 1 ORDER BY id DESC LIMIT 1", true);
        }
        if (!$sec || (int)$sec['rotar_no_abridores'] !== 1) {
            echo json_encode(['ok' => false, 'error' => 'No hay secuencia con rotación ABC activa para esta campaña. Actívala en Plantillas y Campañas → Secuencia.']);
            exit;
        }

        $espera = max(1, (int)$sec['rotar_espera_dias']);
        $maxEnvios = max(2, (int)$sec['rotar_max_envios']);
        $tplId = (int)$sec['rotar_plantilla_id'];
        if ($tplId <= 0) {
            $tplId = (int)$db->querySingle("SELECT plantilla_id FROM secuencia_pasos WHERE secuencia_id = " . (int)$sec['id'] . " AND paso = 1 AND activo = 1 ORDER BY id LIMIT 1");
        }
        $tplNombre = $tplId > 0 ? $db->querySingle("SELECT nombre FROM plantillas WHERE id = {$tplId}") : '';

        $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido', '06 Perdido', '06 Baja/Archivado', '07 Baja', 'Baja'];
        $inSup = "'" . implode("','", array_map(fn($e) => $db->escapeString($e), $estadosSupresion)) . "'";

        // Leads con ≥1 envío enviado/abierto en la campaña, sin aperturas ni
        // respuestas, con espera cumplida y con intentos restantes.
        $sql = "SELECT c.id, c.nombre_club, c.email, c.federacion, c.persona_contacto, c.telefono_movil,
                       (SELECT COUNT(*) FROM envios e WHERE e.lead_id = c.id AND e.campaign_id = {$cid} AND COALESCE(e.es_test,0) = 0 AND e.estado IN ('enviado','abierto')) AS n_envios
                FROM clubes_crm c
                WHERE c.id IN (
                        SELECT e.lead_id FROM envios e
                        WHERE e.campaign_id = {$cid} AND COALESCE(e.es_test,0) = 0 AND e.estado IN ('enviado','abierto')
                          AND e.lead_id IS NOT NULL
                    )
                  AND c.email IS NOT NULL AND c.email != ''
                  AND c.es_duplicado = 0
                  AND c.estado_lead NOT IN ({$inSup})
                  AND NOT EXISTS (SELECT 1 FROM respuestas r WHERE r.lead_id = c.id)
                  AND NOT EXISTS (SELECT 1 FROM aperturas a
                                  WHERE a.tracking_id IN (SELECT e2.tracking_id FROM envios e2 WHERE e2.lead_id = c.id AND e2.campaign_id = {$cid}))
                  AND (SELECT COUNT(*) FROM envios e3 WHERE e3.lead_id = c.id AND e3.campaign_id = {$cid} AND COALESCE(e3.es_test,0) = 0 AND e3.estado IN ('enviado','abierto')) < {$maxEnvios}
                  AND (SELECT MAX(e4.fecha_envio) FROM envios e4 WHERE e4.lead_id = c.id AND e4.campaign_id = {$cid} AND COALESCE(e4.es_test,0) = 0 AND e4.estado IN ('enviado','abierto')) <= datetime('now', '-{$espera} days')
                ORDER BY c.nombre_club ASC";

        $leads = [];
        $res = $db->query($sql);
        while ($l = $res->fetchArray(SQLITE3_ASSOC)) {
            // Última variante: si ya hubo rotación, la de la última rotación; si no, la determinística base.
            $ultVar = $db->querySingle("SELECT variant FROM envios WHERE lead_id = " . (int)$l['id'] . " AND campaign_id = {$cid} AND es_rotacion = 1 ORDER BY id DESC LIMIT 1");
            if (!$ultVar) $ultVar = asignarVariante((int)$l['id'], $cid);
            $l['variante_anterior'] = strtoupper((string)$ultVar);
            $l['variante_siguiente'] = siguienteVariante($l['variante_anterior']);
            $l['intento'] = (int)$l['n_envios'] + 1;
            $leads[] = $l;
        }

        echo json_encode([
            'ok' => true,
            'secuencia' => [
                'id' => (int)$sec['id'],
                'nombre' => $sec['nombre'],
                'espera_dias' => $espera,
                'max_envios' => $maxEnvios,
                'plantilla_id' => $tplId,
                'plantilla_nombre' => $tplNombre,
            ],
            'leads' => $leads,
            'total' => count($leads),
        ]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── save_secuencia ───────────────────────────────────────────────────────────
// Crea/actualiza una secuencia y reemplaza sus pasos.
if ($action === 'save_secuencia') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $modoAuto = (($_POST['modo_auto'] ?? '0') === '1') ? 1 : 0;
        $activo = (($_POST['activo'] ?? '1') === '1') ? 1 : 0;
        $rotarNoAbridores = (($_POST['rotar_no_abridores'] ?? '0') === '1') ? 1 : 0;
        $rotarEspera = max(1, (int)($_POST['rotar_espera_dias'] ?? 3));
        $rotarMaxEnvios = max(2, (int)($_POST['rotar_max_envios'] ?? 2));
        $rotarPlantilla = (int)($_POST['rotar_plantilla_id'] ?? 0);
        if ($campaignId <= 0 || $nombre === '') {
            echo json_encode(['ok' => false, 'error' => 'campaign_id y nombre son obligatorios']);
            exit;
        }
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE secuencias SET campaign_id=:c, nombre=:n, modo_auto=:ma, activo=:a,
                                  rotar_no_abridores=:rna, rotar_espera_dias=:red, rotar_max_envios=:rme, rotar_plantilla_id=:rpid
                                  WHERE id=:id");
            $stmt->bindValue(':c', $campaignId, SQLITE3_INTEGER);
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':ma', $modoAuto, SQLITE3_INTEGER);
            $stmt->bindValue(':a', $activo, SQLITE3_INTEGER);
            $stmt->bindValue(':rna', $rotarNoAbridores, SQLITE3_INTEGER);
            $stmt->bindValue(':red', $rotarEspera, SQLITE3_INTEGER);
            $stmt->bindValue(':rme', $rotarMaxEnvios, SQLITE3_INTEGER);
            $stmt->bindValue(':rpid', $rotarPlantilla, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO secuencias (campaign_id, nombre, modo_auto, activo, rotar_no_abridores, rotar_espera_dias, rotar_max_envios, rotar_plantilla_id)
                                  VALUES (:c, :n, :ma, :a, :rna, :red, :rme, :rpid)");
            $stmt->bindValue(':c', $campaignId, SQLITE3_INTEGER);
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':ma', $modoAuto, SQLITE3_INTEGER);
            $stmt->bindValue(':a', $activo, SQLITE3_INTEGER);
            $stmt->bindValue(':rna', $rotarNoAbridores, SQLITE3_INTEGER);
            $stmt->bindValue(':red', $rotarEspera, SQLITE3_INTEGER);
            $stmt->bindValue(':rme', $rotarMaxEnvios, SQLITE3_INTEGER);
            $stmt->bindValue(':rpid', $rotarPlantilla, SQLITE3_INTEGER);
            $stmt->execute();
            $id = (int)$db->lastInsertRowID();
        }

        // Reemplazar pasos.
        $db->exec("DELETE FROM secuencia_pasos WHERE secuencia_id = {$id}");
        $pasos = $_POST['pasos'] ?? [];
        if (is_string($pasos)) $pasos = json_decode($pasos, true) ?: [];
        foreach ($pasos as $p) {
            $paso = (int)($p['paso'] ?? 0);
            $plantillaId = (int)($p['plantilla_id'] ?? 0);
            $espera = max(0, (int)($p['espera_dias'] ?? 2));
            $ramal = strtoupper(substr(trim((string)($p['ramal'] ?? '')), 0, 1));
            if (!in_array($ramal, ['A', 'B', 'C'], true)) $ramal = '';
            $pActivo = (($p['activo'] ?? '1') === '1' || (int)($p['activo'] ?? 1) === 1) ? 1 : 0;
            if ($paso <= 0 || $plantillaId <= 0) continue;
            $stmt = $db->prepare("INSERT INTO secuencia_pasos (secuencia_id, paso, plantilla_id, espera_dias, ramal, activo) VALUES (:sid, :paso, :pid, :esp, :ram, :act)");
            $stmt->bindValue(':sid', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':paso', $paso, SQLITE3_INTEGER);
            $stmt->bindValue(':pid', $plantillaId, SQLITE3_INTEGER);
            $stmt->bindValue(':esp', $espera, SQLITE3_INTEGER);
            $stmt->bindValue(':ram', $ramal, SQLITE3_TEXT);
            $stmt->bindValue(':act', $pActivo, SQLITE3_INTEGER);
            $stmt->execute();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── delete_secuencia ─────────────────────────────────────────────────────────
// Elimina una secuencia y sus pasos (no toca envíos ya registrados).
if ($action === 'delete_secuencia') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        $db->exec("DELETE FROM secuencia_pasos WHERE secuencia_id = {$id}");
        $db->exec("DELETE FROM secuencias WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}
