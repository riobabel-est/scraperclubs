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
