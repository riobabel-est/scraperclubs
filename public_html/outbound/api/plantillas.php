<?php
/**
 * plantillas.php — Endpoints AJAX de plantillas del CRM Outbound.
 * Extraído de dashboard.php (modularización del monolito).
 * Requiere: $db (SQLite3 abierto), $action (string) definidos por el orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// Cliente LLM multi-proveedor para el Asistente IA de plantillas.
require_once __DIR__ . '/../inc/llm.php';

// ─── save_template ───────────────────────────────────────────────────────────
if ($action === 'save_template') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        $cuerpo = $_POST['cuerpo'] ?? '';
        $tipo = $_POST['tipo'] ?? 'comercial';
        // Campos ABC (variantes A/B/C) que envía el editor (app.js).
        $asunto_b = $_POST['asunto_b'] ?? '';
        $asunto_c = $_POST['asunto_c'] ?? '';
        $cuerpo_b = $_POST['cuerpo_b'] ?? '';
        $cuerpo_c = $_POST['cuerpo_c'] ?? '';
        $test_ab = (int)($_POST['test_ab'] ?? 0);
        if ($nombre === '' || $asunto === '' || $cuerpo === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre, asunto y cuerpo son obligatorios']);
            exit;
        }
        if ($id > 0) {
            // T-3 (2026-09-02) plantillas VERSIONADAS: si la plantilla ya se usó en
            // envíos (histórico), NO se sobrescribe: se crea una copia con el nuevo
            // contenido y se devuelve su id (la original queda inmutable para los
            // envíos ya registrados; se desactiva para no ofrecerse en nuevos envíos).
            $usos = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE plantilla_id = {$id}")
                  + (int)$db->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE plantilla_id = {$id}");
            if ($usos > 0) {
                $idOriginal = $id;
                $db->exec("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, activo, fecha_creacion)
                           SELECT nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, 0, CURRENT_TIMESTAMP
                           FROM plantillas WHERE id = {$idOriginal}");
                $id = (int)$db->lastInsertRowID();
                // La ORIGINAL se desactiva (queda inmutable y fuera de nuevos envíos).
                $db->exec("UPDATE plantillas SET activo = 0 WHERE id = {$idOriginal}");
            }
            $stmt = $db->prepare("UPDATE plantillas SET nombre=:n, categoria=:c, asunto=:a, cuerpo=:b, tipo=:t, asunto_b=:ab, asunto_c=:ac, cuerpo_b=:cb, cuerpo_c=:cc, test_ab=:ta, activo=1 WHERE id=:id");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':c', $categoria, SQLITE3_TEXT);
            $stmt->bindValue(':a', $asunto, SQLITE3_TEXT);
            $stmt->bindValue(':b', $cuerpo, SQLITE3_TEXT);
            $stmt->bindValue(':t', $tipo, SQLITE3_TEXT);
            $stmt->bindValue(':ab', $asunto_b, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $asunto_c, SQLITE3_TEXT);
            $stmt->bindValue(':cb', $cuerpo_b, SQLITE3_TEXT);
            $stmt->bindValue(':cc', $cuerpo_c, SQLITE3_TEXT);
            $stmt->bindValue(':ta', $test_ab, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['ok' => true, 'id' => $id, 'versionada' => ($usos > 0)]);
        } else {
            $stmt = $db->prepare("INSERT INTO plantillas (nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, fecha_creacion) VALUES (:n, :c, :a, :b, :t, :ab, :ac, :cb, :cc, :ta, CURRENT_TIMESTAMP)");
            $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
            $stmt->bindValue(':c', $categoria, SQLITE3_TEXT);
            $stmt->bindValue(':a', $asunto, SQLITE3_TEXT);
            $stmt->bindValue(':b', $cuerpo, SQLITE3_TEXT);
            $stmt->bindValue(':t', $tipo, SQLITE3_TEXT);
            $stmt->bindValue(':ab', $asunto_b, SQLITE3_TEXT);
            $stmt->bindValue(':ac', $asunto_c, SQLITE3_TEXT);
            $stmt->bindValue(':cb', $cuerpo_b, SQLITE3_TEXT);
            $stmt->bindValue(':cc', $cuerpo_c, SQLITE3_TEXT);
            $stmt->bindValue(':ta', $test_ab, SQLITE3_INTEGER);
            $stmt->execute();
            echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
        }
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── delete_template ─────────────────────────────────────────────────────────
if ($action === 'delete_template') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID requerido']); exit; }
        // T-3: no permitir borrar plantillas ya utilizadas en envíos (integridad histórica).
        $usos = (int)$db->querySingle("SELECT COUNT(*) FROM envios WHERE plantilla_id = {$id}")
              + (int)$db->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE plantilla_id = {$id}");
        if ($usos > 0) {
            echo json_encode(['ok' => false, 'error' => 'Esta plantilla ya se usó en envíos: no se puede borrar (se puede desactivar o crear una copia).', 'razon' => 'PLANTILLA_USADA']);
            exit;
        }
        $db->exec("DELETE FROM plantillas WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── get_templates ───────────────────────────────────────────────────────────
if (!function_exists('sanearUtf8Recursivo')) {
    /** Sanea strings UTF-8 inválidos de un array (evita json_encode=false). */
    function sanearUtf8Recursivo(array &$arr): void
    {
        foreach ($arr as &$v) {
            if (is_string($v)) {
                if ($v !== '' && preg_match('//u', $v) !== 1) {
                    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
                }
            } elseif (is_array($v)) {
                sanearUtf8Recursivo($v);
            }
        }
        unset($v);
    }
}
if ($action === 'get_templates') {
    header('Content-Type: application/json');
    $cat = trim($_GET['categoria'] ?? '');
    $incluirGenericas = (($_GET['incluir_genericas'] ?? '') === '1');
    $sql = "SELECT id, nombre, categoria, asunto, cuerpo, tipo, asunto_b, asunto_c, cuerpo_b, cuerpo_c, test_ab, fecha_creacion AS updated_at FROM plantillas";
    $params = [];
    if ($cat !== '') {
        if ($incluirGenericas) {
            // Lanzadera: plantillas de la categoría (estado) + genéricas (sin categoría).
            $sql .= " WHERE categoria = :cat OR categoria = ''";
        } else {
            // Editor: solo la categoría seleccionada.
            $sql .= " WHERE categoria = :cat";
        }
        $params[':cat'] = $cat;
    }
    // Orden de gestión (2026-08-26): primero las categorías numeradas
    // (01 Prospección → 02 Seguimiento → 03 Respuestas), después las genéricas;
    // dentro de cada grupo, por nombre alfabético.
    $sql .= " ORDER BY (categoria = '') ASC, categoria COLLATE NOCASE ASC, nombre COLLATE NOCASE ASC";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v, SQLITE3_TEXT); }
    $res = $stmt->execute();
    $items = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $items[] = $r; }
    // El frontend (app.js) consume la clave 'templates' en la Lanzadera y el Editor.
    // Se mantiene 'items' por compatibilidad con cualquier otro consumidor.
    // Saneo UTF-8: un campo malformado no debe vaciar el selector (bug Bandeja).
    sanearUtf8Recursivo($items);
    echo json_encode(['ok' => true, 'templates' => $items, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

// ─── get_categorias ──────────────────────────────────────────────────────────
if ($action === 'get_categorias') {
    header('Content-Type: application/json');
    $res = $db->query("SELECT DISTINCT categoria FROM plantillas WHERE categoria != '' ORDER BY categoria ASC");
    $cats = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $cats[] = $r['categoria']; }
    echo json_encode(['ok' => true, 'categorias' => $cats]);
    exit;
}

// ─── rename_categoria ─────────────────────────────────────────────────────────
// Renombra una categoría en todas las plantillas que la usan ('' = quitar categoría).
if ($action === 'rename_categoria') {
    header('Content-Type: application/json');
    try {
        $old = trim($_POST['old_categoria'] ?? '');
        $new = trim($_POST['new_categoria'] ?? '');
        if ($old === '') { echo json_encode(['ok' => false, 'error' => 'Categoría origen requerida']); exit; }
        $stmt = $db->prepare("UPDATE plantillas SET categoria = :new WHERE categoria = :old");
        $stmt->bindValue(':new', $new, SQLITE3_TEXT);
        $stmt->bindValue(':old', $old, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'afectadas' => $db->changes()]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── delete_categoria ─────────────────────────────────────────────────────────
// Elimina una categoría: sus plantillas pasan a "Sin pipeline" (no se borran).
if ($action === 'delete_categoria') {
    header('Content-Type: application/json');
    try {
        $cat = trim($_POST['categoria'] ?? '');
        if ($cat === '') { echo json_encode(['ok' => false, 'error' => 'Categoría requerida']); exit; }
        $stmt = $db->prepare("UPDATE plantillas SET categoria = '' WHERE categoria = :cat");
        $stmt->bindValue(':cat', $cat, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'afectadas' => $db->changes()]);
    } catch (\Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ─── preview_template ────────────────────────────────────────────────────────
if ($action === 'preview_template') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $tpl = $db->querySingle("SELECT * FROM plantillas WHERE id = {$id}", true);
    if ($tpl) {
        $club = $db->querySingle("SELECT nombre_club, persona_contacto, federacion FROM clubes_crm ORDER BY id DESC LIMIT 1", true);
        $asunto = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['asunto']
        );
        $cuerpo = str_replace(
            ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}'],
            [$club['nombre_club'], $club['persona_contacto'] ?: 'responsable', $club['federacion'], date('Y')],
            $tpl['cuerpo']
        );
        echo json_encode(['ok' => true, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'tipo' => $tpl['tipo']]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'No encontrado']);
    }
    exit;
}

// ─── generar_plantilla_ia ─────────────────────────────────────────────────────
// Asistente IA para crear emails en el editor de plantillas.
// Reutiliza llm_chat (multi-proveedor de Ajustes → IA) + conocimiento de producto.
// Devuelve {asunto, cuerpo} o {variantes: [A,B,C]} según el parámetro variantes.
if ($action === 'generar_plantilla_ia') {
    header('Content-Type: application/json; charset=utf-8');
    $categoria   = trim($_POST['categoria'] ?? '');
    $ramal       = trim($_POST['ramal'] ?? '');
    $tono        = trim($_POST['tono'] ?? 'profesional');
    $longitud    = trim($_POST['longitud'] ?? 'media');
    $instruccion = trim($_POST['instruccion'] ?? '');
    $variantes   = (($_POST['variantes'] ?? '0') === '1');

    $ctx = trim((string)$db->querySingle("SELECT valor FROM config WHERE clave = 'ia_conocimiento_producto'") ?? '');

    $mapaRama = [
        'general'    => 'General / Producto (presentación del producto y sus ventajas)',
        'identidad'  => 'Identidad / Cantera (escudo, colores del club, orgullo de los jugadores, categorías base)',
        'financiero' => 'Financiero / Rentabilidad (precio por unidad, diferencia, sin pedido mínimo)',
    ];
    $enfoque = $mapaRama[$ramal] ?? 'Equilibrado (combina beneficios del producto y cercanía con el club)';

    $maxPalabras = ['corta' => 60, 'media' => 110, 'larga' => 180][$longitud] ?? 110;
    $tonoDesc = ['profesional' => 'profesional y cercano', 'cercano' => 'cercano y natural', 'directo' => 'directo y conciso', 'formal' => 'formal e institucional'][$tono] ?? 'profesional y cercano';

    $system = "Eres un redactor de ventas B2B de un software de gestión de clubes de fútbol (FutProtec)."
        . ($ctx !== '' ? "\n\nCONOCIMIENTO DE PRODUCTO (úsalo como base, no inventes datos):\n" . mb_substr($ctx, 0, 4000) : '')
        . "\n\nREGLA: Escribe en español, tono {$tonoDesc}, máximo {$maxPalabras} palabras. Usa los placeholders {{CLUB}}, {{CONTACTO}}, {{FEDERACION}} y {{ANIO}} donde corresponda. No inventes precios, cifras ni hechos. El email debe ser accionable (una sola llamada a la acción).";

    $user = "Crea un email de la categoría '{$categoria}' con el enfoque: {$enfoque}."
        . ($instruccion !== '' ? " Requisitos adicionales: {$instruccion}." : '')
        . ($variantes
            ? "\n\nGenera 3 variantes (A, B, C) con enfoques ligeramente distintos. Formato EXACTO:\nVARIANTE A\nASUNTO: <texto>\nCUERPO: <texto>\n\nVARIANTE B\nASUNTO: <texto>\nCUERPO: <texto>\n\nVARIANTE C\nASUNTO: <texto>\nCUERPO: <texto>"
            : "\n\nFormato EXACTO de respuesta (dos líneas):\nASUNTO: <texto>\nCUERPO: <texto>");

    $texto = llm_chat($db, $system, $user, 1400, 0.6);
    if ($texto === null) {
        echo json_encode(['ok' => false, 'error' => 'No hay API key de IA configurada. Ve a Ajustes → Inteligencia Artificial.']);
        exit;
    }

    if ($variantes) {
        $resultado = [];
        foreach (['A', 'B', 'C'] as $letra) {
            if (preg_match('/VARIANTE\s+' . $letra . '\s*\n(ASUNTO:\s*[^\n]*\nCUERPO:\s*[\s\S]*?)(?=\nVARIANTE|\z)/i', $texto, $m)) {
                $as = ''; $cu = '';
                if (preg_match('/ASUNTO:\s*(.*)/i', $m[1], $a)) $as = trim($a[1]);
                if (preg_match('/CUERPO:\s*([\s\S]*)$/i', $m[1], $c)) $cu = trim($c[1]);
                $resultado[] = ['asunto' => $as, 'cuerpo' => $cu];
            }
        }
        if (count($resultado) === 3) {
            echo json_encode(['ok' => true, 'variantes' => $resultado]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se pudo estructurar 3 variantes. Inténtalo de nuevo.']);
        }
    } else {
        $asunto = ''; $cuerpo = '';
        if (preg_match('/ASUNTO:\s*(.*)/i', $texto, $m)) $asunto = trim($m[1]);
        if (preg_match('/CUERPO:\s*([\s\S]*)$/i', $texto, $m)) $cuerpo = trim($m[1]);
        if ($asunto === '' && $cuerpo === '') $cuerpo = $texto;
        echo json_encode(['ok' => true, 'asunto' => $asunto, 'cuerpo' => $cuerpo]);
    }
    exit;
}

// ─── get_plantilla_adjuntos — adjuntos predeterminados de una plantilla ──────
if ($action === 'get_plantilla_adjuntos') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['plantilla_id'] ?? 0);
    if ($pid <= 0) { echo json_encode(['ok' => false, 'error' => 'plantilla_id requerido']); exit; }
    $items = [];
    $stmt = $db->prepare(
        "SELECT pa.id AS pa_id, pa.orden, ar.id, ar.nombre, ar.mime, ar.tamano
         FROM plantillas_adjuntos pa JOIN adjuntos_repo ar ON ar.id = pa.adjunto_repo_id
         WHERE pa.plantilla_id = :pid AND pa.activo = 1 ORDER BY pa.orden ASC"
    );
    $stmt->bindValue(':pid', $pid, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $items[] = $r;
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// ─── plantilla_adjunto_add — vincula un adjunto del repo a una plantilla ─────
if ($action === 'plantilla_adjunto_add') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_POST['plantilla_id'] ?? 0);
    $rid = (int)($_POST['adjunto_repo_id'] ?? 0);
    if ($pid <= 0 || $rid <= 0) { echo json_encode(['ok' => false, 'error' => 'plantilla_id y adjunto_repo_id requeridos']); exit; }
    $existe = (int)$db->querySingle("SELECT COUNT(*) FROM adjuntos_repo WHERE id = {$rid}");
    if ($existe === 0) { echo json_encode(['ok' => false, 'error' => 'Adjunto del repositorio no existe']); exit; }
    $orden = (int)$db->querySingle("SELECT COALESCE(MAX(orden),0)+1 FROM plantillas_adjuntos WHERE plantilla_id = {$pid}");
    $db->exec("INSERT OR IGNORE INTO plantillas_adjuntos (plantilla_id, adjunto_repo_id, orden, activo) VALUES ({$pid}, {$rid}, {$orden}, 1)");
    echo json_encode(['ok' => true]);
    exit;
}

// ─── plantilla_adjunto_remove — desvincula un adjunto de una plantilla ───────
if ($action === 'plantilla_adjunto_remove') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_POST['plantilla_id'] ?? 0);
    $rid = (int)($_POST['adjunto_repo_id'] ?? 0);
    if ($pid <= 0 || $rid <= 0) { echo json_encode(['ok' => false, 'error' => 'plantilla_id y adjunto_repo_id requeridos']); exit; }
    $db->exec("DELETE FROM plantillas_adjuntos WHERE plantilla_id = {$pid} AND adjunto_repo_id = {$rid}");
    echo json_encode(['ok' => true]);
    exit;
}

