<?php
/**
 * api_leads.php — API de gestión de leads: scanner de duplicados, merge y consultas.
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

// ─── Buffer + Control de errores para JSON limpio ───
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$DB_PATH = __DIR__ . '/../data/stats.db';

// ─── AUTENTICACIÓN ───────────────────────────────────────────────────────────
session_start();
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && empty($_SESSION['auth_outbound'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

if (!file_exists($DB_PATH)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'stats.db no encontrada']);
    exit;
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ═════════════════════════════════════════════════════════════════════════════
// FUNCIONES DE NORMALIZACION
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Normaliza el nombre de un club eliminando prefijos comunes
 * para mejorar la comparacion de similitud.
 */
function normalizar_nombre_club(string $nombre): string
{
    $prefijos = [
        'Club Deportivo', 'Club Deportiva',
        'C.D.', 'C. D.', 'CD ', 'CD. ',
        'A.D.', 'A. D.', 'AD ', 'AD. ',
        'C.F.', 'C. F.', 'CF ', 'CF. ',
        'S.D.', 'S. D.', 'SD ', 'SD. ',
        'U.D.', 'U. D.', 'UD ', 'UD. ',
        'Asociacion Deportiva', 'Asociacion',
        'Agrupacion Deportiva', 'Agrupacion',
        'Escuela de Futbol', 'Escuela Futbol',
        'Union Deportiva',
    ];

    $n = mb_strtoupper(trim($nombre), 'UTF-8');
    foreach ($prefijos as $pref) {
        $prefUpper = mb_strtoupper($pref, 'UTF-8');
        $len = mb_strlen($prefUpper, 'UTF-8');
        if (mb_substr($n, 0, $len, 'UTF-8') === $prefUpper) {
            $n = trim(mb_substr($n, $len, null, 'UTF-8'));
        }
    }

    // Quitar prefijos de 2-3 letras como palabras completas (solo al inicio)
    $n = preg_replace('/^(CD|AD|CF|SD|UD|AC|EF)\s+/i', '', $n);

    // Quitar S.A.D., SAD
    $n = preg_replace('/\bS\.?A\.?D\.?\b/i', '', $n);
    // Quitar puntos sueltos, comas, guiones
    $n = str_replace(['.', ',', '-', '  '], [' ', ' ', ' ', ' '], $n);
    $n = preg_replace('/\s+/', ' ', $n);

    return trim($n);
}

// ═════════════════════════════════════════════════════════════════════════════
// FUNCIONES PURAS DE DETECCIÓN DE DUPLICADOS
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Resetea los flags de duplicado de toda la tabla clubes_crm.
 */
function resetFlagsDuplicados(SQLite3 $db): void
{
    $db->exec("UPDATE clubes_crm SET es_duplicado = 0, duplicado_id = NULL");
}

/**
 * Detecta pares de duplicados por email idéntico (ignorando vacíos).
 * Devuelve un array de pares ['keep_id', 'dup_id', 'tipo' => 'email_exacto'].
 *
 * @param array<int,array<string,mixed>> $clubes
 * @return array<int,array<string,mixed>>
 */
function detectarDuplicadosEmail(array $clubes): array
{
    $pares = [];
    $emailMap = [];
    foreach ($clubes as $c) {
        $email = strtolower(trim($c['email']));
        if ($email === '') continue;
        $emailMap[$email][] = $c;
    }

    foreach ($emailMap as $grupo) {
        $n = count($grupo);
        if ($n > 1) {
            for ($i = 1; $i < $n; $i++) {
                $pares[] = [
                    'keep_id' => $grupo[0]['id'],
                    'dup_id'  => $grupo[$i]['id'],
                    'tipo'    => 'email_exacto',
                ];
            }
        }
    }

    return $pares;
}

/**
 * Palabras genéricas que no sirven para confirmar que dos clubes son el mismo.
 * Compartir solo estas palabras (p.ej. "ATLETICO", "CLUB", "FUTBOL") no basta
 * para considerar dos registros como duplicados.
 */
function palabrasGenericasClub(): array
{
    return [
        'ATLETICO', 'ATLETIC', 'CLUB', 'DEPORTIVO', 'DEPORTIVA', 'FUTBOL',
        'FUTBOL', 'UNION', 'UNION', 'AGRUPACION', 'ASOCIACION', 'ESCUELA',
        'SOCIEDAD', 'SPORTING', 'SPORT', 'REAL', 'CD', 'AD', 'CF', 'SD', 'UD',
        'FUTBOL', 'FUTBOL', 'FUTBOL', 'FUTBOL', 'FUTBOL', 'FUTBOL',
    ];
}

/**
 * Elimina los acentos/tildes de una cadena en mayúsculas para comparar de forma
 * insensible a acentos (p.ej. "ATLÉTICO" -> "ATLETICO").
 */
function quitarAcentos(string $s): string
{
    $con = ['Á','É','Í','Ó','Ú','Ü','À','È','Ì','Ò','Ù','Ñ'];
    $sin = ['A','E','I','O','U','U','A','E','I','O','U','N'];
    return str_replace($con, $sin, $s);
}

/**
 * Devuelve el conjunto de palabras significativas (no genéricas) de un nombre
 * normalizado. Se usan para confirmar que dos clubes comparten identidad.
 * Las palabras se comparan sin acentos para que "ATLÉTICO" se filtre como
 * genérica igual que "ATLETICO".
 *
 * @return array<string,bool>
 */
function palabrasSignificativasClub(string $nombreNormalizado): array
{
    $genericas = array_flip(palabrasGenericasClub());
    $palabras = preg_split('/\s+/', trim($nombreNormalizado));
    $sig = [];
    foreach ($palabras as $p) {
        $p = quitarAcentos(mb_strtoupper(trim($p), 'UTF-8'));
        if ($p === '') continue;
        if (mb_strlen($p, 'UTF-8') < 4) continue;      // palabras muy cortas no cuentan
        if (isset($genericas[$p])) continue;            // genéricas no cuentan
        $sig[$p] = true;
    }
    return $sig;
}


/**
 * Devuelve la última palabra significativa del nombre normalizado (el
 * discriminador real del club, p.ej. "CARDEÑA", "PINILLA"). Recorre las palabras
 * de atrás hacia adelante e ignora acrónimos residuales de 1-2 letras que quedan
 * tras normalizar sufijos como "C.F." -> "C F" o "S.A.D." -> "S A D".
 */
function ultimaPalabraNombre(string $nombreNormalizado): string
{
    $palabras = preg_split('/\s+/', trim($nombreNormalizado));
    for ($i = count($palabras) - 1; $i >= 0; $i--) {
        $p = quitarAcentos(mb_strtoupper(trim($palabras[$i]), 'UTF-8'));
        if ($p === '') continue;
        if (mb_strlen($p, 'UTF-8') < 3) continue; // ignora acrónimos residuales (C, F, S, A, D)
        return $p;
    }
    return '';
}


/**
 * Detecta pares de duplicados por nombre similar (>80%) dentro de la misma
 * federación. Usa un set de claves "keep|dup" para evitar pares repetidos en O(1).
 *
 * VALIDACIÓN ESTRICTA: para evitar falsos positivos (p.ej. "ATLETICO CARDEÑA"
 * vs "ATLETICO CORDOBES", "ATLETICO PINCIA" vs "ATLETICO PINILLA", o
 * "ALGECIRAS C.F." vs "C.D. ALGECIRAS F.S."), además del umbral de similitud se
 * exige que la ÚLTIMA palabra del nombre normalizado coincida exactamente.
 * La última palabra es el discriminador real del club (nombre propio o acrónimo
 * de modalidad), no la ciudad ni el prefijo genérico.
 *
 * @param array<int,array<string,mixed>> $clubes
 * @return array<int,array<string,mixed>>
 */
function detectarDuplicadosNombre(array $clubes): array
{
    $pares = [];
    $vistos = []; // set de claves "keep|dup" para deduplicación O(1)

    // Agrupar por federación
    $federacionMap = [];
    foreach ($clubes as $c) {
        $fed = trim($c['federacion']);
        if ($fed === '') $fed = '_sin_federacion_';
        $federacionMap[$fed][] = $c;
    }

    foreach ($federacionMap as $grupo) {
        $n = count($grupo);

        // Precalcular normalización, longitud, última palabra y email una sola vez por club
        $norm = [];
        $len = [];
        $ultima = [];
        $email = [];
        for ($i = 0; $i < $n; $i++) {
            $norm[$i] = normalizar_nombre_club($grupo[$i]['nombre_club']);
            $len[$i] = mb_strlen($norm[$i], 'UTF-8');
            $ultima[$i] = ultimaPalabraNombre($norm[$i]);
            $email[$i] = strtolower(trim($grupo[$i]['email'] ?? ''));
        }

        for ($i = 0; $i < $n; $i++) {
            if ($len[$i] < 5) continue;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($len[$j] < 5) continue;

                // Solo comparar si longitudes similares (ratio < 2x)
                $lenRatio = max($len[$i], $len[$j]) / max(1, min($len[$i], $len[$j]));
                if ($lenRatio > 2.0) continue;

                // REGLA EMAIL DISCRIMINANTE: si ambos tienen email y son DISTINTOS,
                // son clubes distintos (cada club usa su propio email de contacto).
                // Solo se descarta la detección si ambos emails existen y difieren.
                if ($email[$i] !== '' && $email[$j] !== '' && $email[$i] !== $email[$j]) continue;

                // VALIDACIÓN ESTRICTA: la última palabra (discriminador) debe coincidir
                if ($ultima[$i] === '' || $ultima[$j] === '') continue;
                if ($ultima[$i] !== $ultima[$j]) continue;


                similar_text($norm[$i], $norm[$j], $pct);
                if ($pct > 90) {
                    $clave = $grupo[$i]['id'] . '|' . $grupo[$j]['id'];
                    if (isset($vistos[$clave])) continue;
                    $vistos[$clave] = true;
                    $pares[] = [
                        'keep_id' => $grupo[$i]['id'],
                        'dup_id'  => $grupo[$j]['id'],
                        'tipo'    => 'nombre_similar',
                        'pct'     => round($pct, 1),
                    ];
                }

            }
        }
    }

    return $pares;
}



/**
 * Marca en BD los pares de duplicados detectados.
 * Devuelve el array de pares marcados.
 *
 * @param array<int,array<string,mixed>> $pares
 * @return array<int,array<string,mixed>>
 */
function marcarDuplicados(SQLite3 $db, array $pares): array
{
    $marcados = [];
    $stmtMark = $db->prepare("UPDATE clubes_crm SET es_duplicado = 1, duplicado_id = :dup WHERE id = :id");
    foreach ($pares as $p) {
        $stmtMark->bindValue(':dup', $p['keep_id'], SQLITE3_INTEGER);
        $stmtMark->bindValue(':id', $p['dup_id'], SQLITE3_INTEGER);
        try { $stmtMark->execute(); } catch (\Exception) {}
        $stmtMark->reset();
        $marcados[] = $p;
    }
    return $marcados;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: scan_duplicates — Escanea TODA la BD y guarda resultados
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'scan_duplicates') {
    header('Content-Type: application/json');
    set_time_limit(120);

    try {
        // Resetear flags de duplicado
        resetFlagsDuplicados($db);

        $clubes = [];
        $res = $db->query("SELECT id, nombre_club, email, federacion FROM clubes_crm ORDER BY id ASC");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $clubes[] = $row;
        }

        $total = count($clubes);

        // Detección por email idéntico + por nombre similar
        $paresEncontrados = array_merge(
            detectarDuplicadosEmail($clubes),
            detectarDuplicadosNombre($clubes)
        );

        // Persistir en BD
        $duplicadosMarcados = marcarDuplicados($db, $paresEncontrados);

        echo json_encode([
            'ok'       => true,
            'total'    => $total,
            'dups'     => count($duplicadosMarcados),
            'pares'    => $duplicadosMarcados,
        ]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_duplicates — Devuelve lista de duplicados detectados
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_duplicates') {
    header('Content-Type: application/json');

    $dups = [];
    $res = $db->query("
        SELECT dup.id as dup_id, dup.nombre_club as dup_nombre, dup.email as dup_email,
               dup.federacion as dup_fed, dup.es_duplicado, dup.duplicado_id,
               keep.id as keep_id, keep.nombre_club as keep_nombre, keep.email as keep_email,
               keep.federacion as keep_fed
        FROM clubes_crm dup
        LEFT JOIN clubes_crm keep ON keep.id = dup.duplicado_id
        WHERE dup.es_duplicado = 1
        ORDER BY dup.nombre_club ASC
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $dups[] = $row;
    }

    echo json_encode(['ok' => true, 'duplicados' => $dups, 'total' => count($dups)]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: merge_leads — Fusiona dos leads y elimina el duplicado
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'merge_leads') {
    header('Content-Type: application/json');

    try {
        $keepId  = (int)($_POST['keep_id'] ?? 0);
        $dupId   = (int)($_POST['dup_id'] ?? 0);
        $mergeNotes = ($_POST['merge_notes'] ?? '1') === '1';

        if ($keepId <= 0 || $dupId <= 0 || $keepId === $dupId) {
            echo json_encode(['ok' => false, 'error' => 'IDs inválidos']);
            exit;
        }

        $keep = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$keepId}", true);
        $dup  = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$dupId}", true);

        if (!$keep || !$dup) {
            echo json_encode(['ok' => false, 'error' => 'Registros no encontrados']);
            exit;
        }

        // Fusionar observaciones si se solicita
        if ($mergeNotes) {
            $ts = date('d/m/Y H:i');
            $mergedObs = ($keep['observaciones'] ?? '');
            $dupObs = ($dup['observaciones'] ?? '');
            if ($dupObs) {
                $mergedObs .= ($mergedObs ? "\n" : '') . "[MERGE {$ts}] Notas de #{$dupId} ({$dup['nombre_club']}):\n{$dupObs}";
            }
            $mergedObs .= ($mergedObs ? "\n" : '') . "[MERGE {$ts}] Fusionado con #{$dupId} ({$dup['nombre_club']})";
            $db->exec("UPDATE clubes_crm SET observaciones = '" . $db->escapeString(trim($mergedObs)) . "' WHERE id = {$keepId}");
        }

        // Eliminar duplicado
        $db->exec("DELETE FROM clubes_crm WHERE id = {$dupId}");

        // Actualizar referencias: si otro club tenia duplicado_id apuntando al eliminado
        $db->exec("UPDATE clubes_crm SET duplicado_id = {$keepId} WHERE duplicado_id = {$dupId}");
        // Limpiar flag en el conservado
        $db->exec("UPDATE clubes_crm SET es_duplicado = 0, duplicado_id = NULL WHERE id = {$keepId}");

        echo json_encode(['ok' => true, 'merged' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ENDPOINT: delete_lead — Elimina un lead de la BD (con limpieza de referencias)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'delete_lead') {
    header('Content-Type: application/json');

    try {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
            exit;
        }

        $lead = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$id}", true);
        if (!$lead) {
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado']);
            exit;
        }

        // Limpiar referencias: si otro club apuntaba a este como duplicado
        $db->exec("UPDATE clubes_crm SET duplicado_id = NULL, es_duplicado = 0 WHERE duplicado_id = {$id}");

        // Eliminar el lead
        $db->exec("DELETE FROM clubes_crm WHERE id = {$id}");

        echo json_encode(['ok' => true, 'deleted' => true, 'id' => $id]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: omitir_duplicado — Desmarca un lead como duplicado para que
// vuelva a ser elegible en la lanzadera (se envía igualmente aunque comparta
// nombre con otro registro pero tenga email distinto).
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'omitir_duplicado') {
    header('Content-Type: application/json');

    try {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
            exit;
        }

        $lead = $db->querySingle("SELECT * FROM clubes_crm WHERE id = {$id}", true);
        if (!$lead) {
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado']);
            exit;
        }

        // Desmarcar como duplicado: vuelve a ser elegible en la lanzadera
        $db->exec("UPDATE clubes_crm SET es_duplicado = 0, duplicado_id = NULL WHERE id = {$id}");

        echo json_encode(['ok' => true, 'omitido' => true, 'id' => $id]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_leads_table — Datos paginados para la tabla gestor
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_leads_table') {

    header('Content-Type: application/json');

    $page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(250, max(10, (int)($_GET['per_page'] ?? 50)));
    $sort    = $_GET['sort'] ?? 'nombre_club';
    $order   = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $search  = trim($_GET['search'] ?? '');
    $filterEstado = trim($_GET['estado'] ?? '');
    $filterFed    = trim($_GET['federacion'] ?? '');
    $filterDup    = trim($_GET['duplicado'] ?? '');


    $allowedSorts = ['nombre_club', 'email', 'estado_lead', 'federacion', 'creado_el', 'telefono_movil'];
    if (!in_array($sort, $allowedSorts, true)) $sort = 'nombre_club';

    $where = [];
    $params = [];

    // Exclusión estricta de Lista Negra y estados de baja
    $where[] = "estado_lead NOT IN ('Lista Negra', 'Opt-Out', 'Unsubscribed', 'Email Inválido', 'Perdido', 'Baja / Opt-Out')";

    if ($search !== '') {
        $where[] = "(nombre_club LIKE :search OR email LIKE :search2)";
        $params[':search'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
    }
    if ($filterEstado !== '') {
        $where[] = "estado_lead = :estado";
        $params[':estado'] = $filterEstado;
    }
    if ($filterFed !== '') {
        $where[] = "federacion = :fed";
        $params[':fed'] = $filterFed;
    }
    if ($filterDup === '1') {
        $where[] = "es_duplicado = 1";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';


    // Count
    $sqlCount = "SELECT COUNT(*) as cnt FROM clubes_crm {$whereSQL}";
    $stmtCount = $db->prepare($sqlCount);
    foreach ($params as $k => $v) {
        $stmtCount->bindValue($k, $v, SQLITE3_TEXT);
    }
    $total = (int)$stmtCount->execute()->fetchArray(SQLITE3_NUM)[0];
    $totalPages = max(1, (int)ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    // Data
    $sqlData = "SELECT * FROM clubes_crm {$whereSQL} ORDER BY {$sort} {$order} LIMIT :limit OFFSET :offset";
    $stmtData = $db->prepare($sqlData);
    foreach ($params as $k => $v) {
        $stmtData->bindValue($k, $v, SQLITE3_TEXT);
    }
    $stmtData->bindValue(':limit', $perPage, SQLITE3_INTEGER);
    $stmtData->bindValue(':offset', $offset, SQLITE3_INTEGER);

    $res = $stmtData->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
    }

    // Lista unica de federaciones para filtro
    $federaciones = [];
    $resFed = $db->query("SELECT DISTINCT federacion FROM clubes_crm ORDER BY federacion ASC");
    while ($r = $resFed->fetchArray(SQLITE3_ASSOC)) {
        if (trim($r['federacion']) !== '') {
            $federaciones[] = $r['federacion'];
        }
    }

    echo json_encode([
        'ok'          => true,
        'data'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'federaciones' => $federaciones,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_estadisticas_estado — Desglose de clubes por estado
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_estadisticas_estado') {
    header('Content-Type: application/json');

    $estadisticas = [];
    $res = $db->query("SELECT estado_lead, COUNT(*) as cnt FROM clubes_crm GROUP BY estado_lead ORDER BY cnt DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $estadisticas[] = $row;
    }

    $total = 0;
    foreach ($estadisticas as $e) {
        $total += (int)$e['cnt'];
    }

    // Duplicados
    $dups = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm WHERE es_duplicado = 1");

    echo json_encode([
        'ok'           => true,
        'total'        => $total,
        'duplicados'   => $dups,
        'estadisticas' => $estadisticas,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_plantillas — Devuelve todas las plantillas activas
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_plantillas') {
    header('Content-Type: application/json');

    $plantillas = [];
    $res = $db->query("SELECT * FROM plantillas WHERE activo = 1 ORDER BY categoria, nombre ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $plantillas[] = $row;
    }

    echo json_encode(['ok' => true, 'plantillas' => $plantillas]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: save_plantilla — Crea o actualiza una plantilla
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'save_plantilla') {
    header('Content-Type: application/json');

    try {
        $id        = (int)($_POST['id'] ?? 0);
        $nombre    = trim($_POST['nombre'] ?? '');
        $asunto    = trim($_POST['asunto'] ?? '');
        $cuerpo    = $_POST['cuerpo'] ?? '';
        $tipo      = $_POST['tipo'] ?? 'html';
        $categoria = $_POST['categoria'] ?? 'prospeccion';

        if ($nombre === '') {
            echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio']);
            exit;
        }

        if ($id > 0) {
            $stmt = $db->prepare(
                "UPDATE plantillas SET nombre=:n, asunto=:a, cuerpo=:c, tipo=:t, categoria=:cat WHERE id=:id"
            );
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo)
                 VALUES (:n, :a, :c, :t, :cat, 1)"
            );
        }
        $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
        $stmt->bindValue(':a', $asunto, SQLITE3_TEXT);
        $stmt->bindValue(':c', $cuerpo, SQLITE3_TEXT);
        $stmt->bindValue(':t', $tipo, SQLITE3_TEXT);
        $stmt->bindValue(':cat', $categoria, SQLITE3_TEXT);
        $stmt->execute();

        $newId = $id > 0 ? $id : $db->lastInsertRowID();

        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: delete_plantilla — Desactiva una plantilla
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'delete_plantilla') {
    header('Content-Type: application/json');

    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
            exit;
        }

        $db->exec("UPDATE plantillas SET activo = 0 WHERE id = {$id}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_timeline — Devuelve el historial de comunicaciones de un lead
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_timeline') {
    header('Content-Type: application/json');

    $leadId = (int)($_GET['lead_id'] ?? 0);
    $clubId = (int)($_GET['club_id'] ?? 0);

    if ($leadId <= 0 && $clubId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Se requiere lead_id o club_id']);
        exit;
    }

    $where = $leadId > 0 ? "lead_id = {$leadId}" : "club_id = {$clubId}";
    $eventos = [];
    $res = $db->query(
        "SELECT * FROM comunicaciones_log WHERE {$where} ORDER BY fecha DESC LIMIT 50"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $eventos[] = $row;
    }

    echo json_encode(['ok' => true, 'eventos' => $eventos]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: add_nota_timeline — Añade nota manual al timeline
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'add_nota_timeline') {
    header('Content-Type: application/json');

    try {
        $leadId  = (int)($_POST['lead_id'] ?? 0);
        $clubId  = (int)($_POST['club_id'] ?? 0);
        $detalle = trim($_POST['detalles'] ?? '');

        if (($leadId <= 0 && $clubId <= 0) || $detalle === '') {
            echo json_encode(['ok' => false, 'error' => 'Datos insuficientes']);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
             VALUES (:lid, :cid, 'nota_manual', :det, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':lid', $leadId > 0 ? $leadId : null, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $clubId > 0 ? $clubId : null, SQLITE3_INTEGER);
        $stmt->bindValue(':det', $detalle, SQLITE3_TEXT);
        $stmt->execute();

        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_plantilla_wa — Devuelve plantillas tipo whatsapp
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_plantillas_wa') {
    header('Content-Type: application/json');

    $plantillas = [];
    $res = $db->query("SELECT * FROM plantillas WHERE activo = 1 AND tipo = 'whatsapp' ORDER BY nombre ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $plantillas[] = $row;
    }

    echo json_encode(['ok' => true, 'plantillas' => $plantillas]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: save_nuevo_lead — Alta manual de un nuevo club/lead
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'save_nuevo_lead') {
    header('Content-Type: application/json');
    try {
        $nombre     = trim($_POST['nombre_club'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $telefono   = trim($_POST['telefono_movil'] ?? '');
        $telefono_fijo = trim($_POST['telefono_fijo'] ?? '');
        $tiene_whatsapp = (int)($_POST['tiene_whatsapp'] ?? 0);
        $observaciones = trim($_POST['observaciones'] ?? '');
        $contacto   = trim($_POST['persona_contacto'] ?? '');
        $cargo      = trim($_POST['cargo_contacto'] ?? '');
        $federacion = trim($_POST['federacion'] ?? '');
        $estado     = trim($_POST['estado_lead'] ?? '01 Sin Contactar');

        if ($nombre === '' || $email === '') {
            ob_clean();
            echo json_encode(['ok' => false, 'error' => 'Nombre y Email son obligatorios']);
            exit;
        }

        // Verificar si ya existe por email
        $stmtCheck = $db->prepare("SELECT id FROM clubes_crm WHERE LOWER(email) = LOWER(:email)");
        $stmtCheck->bindValue(':email', $email, SQLITE3_TEXT);
        $existente = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);
        if ($existente) {
            ob_clean();
            echo json_encode(['ok' => false, 'error' => 'Ya existe un club con ese email (ID #' . $existente['id'] . ')']);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT INTO clubes_crm (nombre_club, email, telefono_movil, telefono_fijo, persona_contacto, cargo_contacto, federacion, estado_lead, tiene_whatsapp, observaciones, creado_el)
             VALUES (:n, :e, :tm, :tf, :p, :c, :f, :est, :tw, :obs, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':n', $nombre, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->bindValue(':tm', $telefono, SQLITE3_TEXT);
        $stmt->bindValue(':tf', $telefono_fijo, SQLITE3_TEXT);
        $stmt->bindValue(':p', $contacto, SQLITE3_TEXT);
        $stmt->bindValue(':c', $cargo, SQLITE3_TEXT);
        $stmt->bindValue(':f', $federacion, SQLITE3_TEXT);
        $stmt->bindValue(':est', $estado, SQLITE3_TEXT);
        $stmt->bindValue(':tw', $tiene_whatsapp, SQLITE3_INTEGER);
        $stmt->bindValue(':obs', $observaciones, SQLITE3_TEXT);
        $stmt->execute();

        $newId = $db->lastInsertRowID();

        ob_clean();
        echo json_encode(['ok' => true, 'id' => $newId, 'mensaje' => 'Club añadido correctamente']);
    } catch (\Exception $e) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: validate_email — Valida sintaxis + registro MX de un email
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'validate_email') {
    header('Content-Type: application/json');
    $email = trim($_GET['email'] ?? $_POST['email'] ?? '');

    if ($email === '') {
        ob_clean();
        echo json_encode(['ok' => false, 'valid' => false, 'reason' => 'empty']);
        exit;
    }

    // Validación sintáctica
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_clean();
        echo json_encode(['ok' => true, 'valid' => false, 'reason' => 'syntax_invalid']);
        exit;
    }

    // Validación de registro MX
    $domain = substr($email, strrpos($email, '@') + 1);
    $hasMx = checkdnsrr($domain, 'MX');

    ob_clean();
    echo json_encode([
        'ok'     => true,
        'valid'  => $hasMx,
        'reason' => $hasMx ? 'ok' : 'no_mx_record',
        'domain' => $domain,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_config — Devuelve un valor de config por clave
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_config') {
    header('Content-Type: application/json');
    $key = trim($_GET['key'] ?? '');
    if ($key === '') {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Falta key']);
        exit;
    }
    $valor = $db->querySingle("SELECT valor FROM config WHERE clave = '" . $db->escapeString($key) . "'");
    ob_clean();
    echo json_encode(['ok' => true, 'key' => $key, 'valor' => $valor]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_estado_lanzadera — Estado completo del motor de envíos
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_estado_lanzadera') {
    header('Content-Type: application/json');

    $motorActivo = ($db->querySingle("SELECT valor FROM config WHERE clave = 'motor_estado'") === 'activo');
    $delay = (int)($db->querySingle("SELECT valor FROM config WHERE clave = 'lanzadera_delay'") ?: 30);

    $cola = [];
    $resCola = $db->query("
        SELECT c.id, c.nombre_club, c.email,
               (SELECT e.cuenta_emision FROM envios e WHERE LOWER(e.email) = LOWER(c.email) ORDER BY e.id DESC LIMIT 1) AS ultimo_smtp
        FROM clubes_crm c
        WHERE c.estado_lead = '01 Sin Contactar'
          AND c.email IS NOT NULL AND c.email != ''
          AND c.es_duplicado = 0
        ORDER BY c.id ASC
        LIMIT 10
    ");
    while ($r = $resCola->fetchArray(SQLITE3_ASSOC)) {
        $cola[] = [
            'id'    => $r['id'],
            'club'  => $r['nombre_club'],
            'email' => $r['email'],
            'smtp'  => $r['ultimo_smtp'] ?: '—',
        ];
    }

    $smtpCuentas = [];
    $resSmtp = $db->query("SELECT id, email, enviados_hoy, limite_diario, activa, ultimo_error FROM cuentas_smtp ORDER BY email ASC");
    while ($r = $resSmtp->fetchArray(SQLITE3_ASSOC)) {
        $smtpCuentas[] = $r;
    }

    $logs = [];
    $resLogs = $db->query("SELECT fecha_envio as fecha, club, email, cuenta_emision, estado FROM envios ORDER BY id DESC LIMIT 50");
    while ($r = $resLogs->fetchArray(SQLITE3_ASSOC)) {
        $estadoEmoji = $r['estado'] === 'enviado' ? '✅' : ($r['estado'] === 'error' ? '❌' : '⏳');
        $logs[] = [
            'fecha'   => $r['fecha'],
            'mensaje' => $estadoEmoji . ' ' . $r['club'] . ' → ' . $r['email'] . ' [' . ($r['cuenta_emision'] ?: '?') . '] ' . $r['estado'],
        ];
    }

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'motor_activo' => $motorActivo,
        'delay'        => $delay,
        'cola'         => $cola,
        'smtp_cuentas' => $smtpCuentas,
        'logs'         => $logs,
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: toggle_lanzadera — Activa/desactiva el motor de envíos
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'toggle_lanzadera') {
    header('Content-Type: application/json');
    $activo = (int)($_POST['activo'] ?? 0);
    $nuevoEstado = $activo ? 'activo' : 'pausado';
    try {
        $db->exec("INSERT INTO config (clave, valor) VALUES ('motor_estado', '" . $db->escapeString($nuevoEstado) . "') ON CONFLICT(clave) DO UPDATE SET valor = '" . $db->escapeString($nuevoEstado) . "'");
        ob_clean();
        echo json_encode(['ok' => true, 'motor_activo' => $activo === 1]);
    } catch (\Exception $e) {
        ob_clean();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: calcular_precio
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'calcular_precio') {
    header('Content-Type: application/json');
    $volumen = max(0, (int)($_GET['volumen'] ?? 0));
    $pvp = max(1, (int)($_GET['pvp'] ?? 15));
    ob_clean();
    echo json_encode(calcularPrecioYMargen($volumen, $pvp));
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: update_lead — Actualiza un campo de un lead (con protección opt-out)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'update_lead') {
    header('Content-Type: application/json');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['estado_lead', 'persona_contacto', 'cargo_contacto',
                    'telefono_movil', 'telefono_fijo', 'tiene_whatsapp', 'observaciones',
                    'federacion', 'volumen_estimado', 'num_jugadores', 'categorias',
                    'fecha_decision_prevista', 'objeciones', 'proxima_accion',
                    'canal_interaccion', 'motivo_perdida'];
        if ($id <= 0 || !in_array($field, $allowed, true)) {
            echo json_encode(['ok' => false]);
            exit;
        }
        if ($field === 'tiene_whatsapp') {
            $value = $value ? '1' : '0';
        }
        if ($field === 'observaciones') {
            $existing = $db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$id}");
            $ts = date('d/m H:i');
            $merged = $existing ? $existing . "\n[{$ts}] {$value}" : "[{$ts}] {$value}";
            $stmt = $db->prepare("UPDATE clubes_crm SET observaciones = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->bindValue(':val', $merged, SQLITE3_TEXT);
        } elseif ($field === 'estado_lead') {
            // Obtener estado anterior antes de actualizar
            $estadoAnterior = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = {$id}");

            // ─── PROTECCIÓN OPT-OUT REAL (GO-LIVE) ─────────────────────────────
            // Un opt-out real (baja solicitada por el destinatario vía email) NO
            // puede deshacerse accidentalmente cambiando estado_lead desde el Kanban.
            // Se detecta por el historial [BAJA] ... fuente=email en observaciones.
            $estadosSupresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
            $esOptOutReal = false;
            if (in_array($estadoAnterior, $estadosSupresion, true)) {
                $obsLead = (string)$db->querySingle("SELECT observaciones FROM clubes_crm WHERE id = {$id}");
                if (preg_match('/\[BAJA\][^\n]*fuente\s*=\s*email/i', $obsLead)) {
                    $esOptOutReal = true;
                }
            }
            if ($esOptOutReal && !in_array($value, $estadosSupresion, true)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Este lead tiene una BAJA REAL del destinatario (opt-out). No puede reactivarse desde el Kanban. Usa la gestión de Lista Negra con confirmación explícita.',
                    'razon' => 'OPTOUT_REAL_PROTEGIDO'
                ]);
                exit;
            }

            $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = :val, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->bindValue(':val', $value, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            // Registrar cambio de estado en comunicaciones_log (trazabilidad)
            $stmtLog = $db->prepare(
                "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha)
                 VALUES (:lid, :cid, 'cambio_estado', :det, CURRENT_TIMESTAMP)"
            );
            $stmtLog->bindValue(':lid', $id, SQLITE3_INTEGER);
            $stmtLog->bindValue(':cid', $id, SQLITE3_INTEGER);
            $detalle = "Estado cambiado de '{$estadoAnterior}' a '{$value}'";
            $stmtLog->bindValue(':det', $detalle, SQLITE3_TEXT);
            $stmtLog->execute();
            echo json_encode(['ok' => true]);
            exit;
        } else {
            $stmt = $db->prepare("UPDATE clubes_crm SET {$field} = :val WHERE id = :id");
            $stmt->bindValue(':val', $value, SQLITE3_TEXT);
        }

        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: add_lead — Alta manual de lead (con validación MX y WhatsApp auto)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'add_lead') {
    header('Content-Type: application/json');
    try {
        $n  = $_POST['nombre'] ?? '';
        $e  = $_POST['email'] ?? '';
        $f  = $_POST['federacion'] ?? '';
        $m  = $_POST['telefono_movil'] ?? '';
        $fi = $_POST['telefono_fijo'] ?? '';
        $p  = $_POST['persona_contacto'] ?? '';
        $c  = $_POST['cargo_contacto'] ?? '';

        if ($n === '' || $e === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre y Email obligatorios']);
            exit;
        }
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Email con formato invalido']);
            exit;
        }
        $domain = substr(strrchr($e, '@'), 1);
        if (!checkdnsrr($domain, 'MX')) {
            echo json_encode(['ok' => false, 'error' => "El dominio {$domain} no tiene servidor de correo (MX)"]);
            exit;
        }
        // WhatsApp: si móvil empieza por 6 o 7 (9 dígitos)
        $wa = 0;
        if ($m !== '') {
            $limpio = preg_replace('/[^0-9]/', '', $m);
            if (strlen($limpio) === 9 && in_array($limpio[0], ['6', '7'], true)) {
                $wa = 1;
            }
        }
        $stmt = $db->prepare("INSERT INTO clubes_crm
            (nombre_club, email, federacion, telefono_movil, telefono_fijo, persona_contacto, cargo_contacto, tiene_whatsapp, estado_lead)
            VALUES (:n, :e, :f, :m, :fi, :p, :c, :wa, '01 Sin Contactar')");
        $stmt->bindValue(':n',  $n,  SQLITE3_TEXT);
        $stmt->bindValue(':e',  strtolower($e), SQLITE3_TEXT);
        $stmt->bindValue(':f',  $f,  SQLITE3_TEXT);
        $stmt->bindValue(':m',  $m,  SQLITE3_TEXT);
        $stmt->bindValue(':fi', $fi, SQLITE3_TEXT);
        $stmt->bindValue(':p',  $p,  SQLITE3_TEXT);
        $stmt->bindValue(':c',  $c,  SQLITE3_TEXT);
        $stmt->bindValue(':wa', $wa, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID(), 'mx_ok' => true, 'whatsapp' => $wa]);
    } catch (\Exception $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_lead — Devuelve un lead con sus métricas de envío/apertura
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_lead') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $row = $db->querySingle("
        SELECT c.*,
               (SELECT COUNT(*) FROM envios e WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS total_envios,
               (SELECT COUNT(*) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE LOWER(e.email) = LOWER(c.email) AND COALESCE(e.es_test,0)=0) AS total_aperturas
        FROM clubes_crm c WHERE c.id = {$id}", true);
    if ($row) {
        $mockup = $db->querySingle("SELECT * FROM mockups WHERE lead_id = {$id} ORDER BY id DESC LIMIT 1", true);
        $row['mockup'] = $mockup ?: null;
        $presupuesto = $db->querySingle("SELECT * FROM presupuestos WHERE lead_id = {$id} ORDER BY version DESC LIMIT 1", true);
        $row['presupuesto'] = $presupuesto ?: null;
    }
    echo json_encode($row ?: null);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: get_interacciones — Historial de interacciones de un lead
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_interacciones') {
    header('Content-Type: application/json');
    $leadId = (int)($_GET['lead_id'] ?? 0);
    if ($leadId <= 0) { echo json_encode(['ok'=>false,'error'=>'lead_id requerido']); exit; }
    $interacciones = [];
    $res = $db->query("SELECT * FROM comunicaciones_log WHERE lead_id = {$leadId} ORDER BY fecha DESC LIMIT 30");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $interacciones[] = $r; }
    echo json_encode(['ok' => true, 'interacciones' => $interacciones]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ENDPOINT: registrar_interaccion — Registra una interacción manual
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'registrar_interaccion') {
    header('Content-Type: application/json');
    try {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $canal  = $_POST['canal'] ?? 'email';
        $tipoEvento = $_POST['tipo_evento'] ?? 'nota_manual';
        $resumen = $_POST['resumen'] ?? '';
        $resultado = $_POST['resultado'] ?? '';
        $proximaAccion = $_POST['proxima_accion'] ?? '';
        if ($leadId <= 0 || $resumen === '') {
            echo json_encode(['ok'=>false,'error'=>'lead_id y resumen obligatorios']);
            exit;
        }
        $stmt = $db->prepare(
            "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, canal, resumen, resultado, proxima_accion, fecha)
             VALUES (:lid, :cid, :tipo, :det, :canal, :res, :resul, :prox, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(':lid', $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $leadId, SQLITE3_INTEGER);
        $stmt->bindValue(':tipo', $tipoEvento, SQLITE3_TEXT);
        $stmt->bindValue(':det', $resumen, SQLITE3_TEXT);
        $stmt->bindValue(':canal', $canal, SQLITE3_TEXT);
        $stmt->bindValue(':res', $resumen, SQLITE3_TEXT);
        $stmt->bindValue(':resul', $resultado, SQLITE3_TEXT);
        $stmt->bindValue(':prox', $proximaAccion, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $db->lastInsertRowID()]);
    } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// Default
header('Content-Type: application/json');
ob_clean();
echo json_encode(['ok' => false, 'error' => 'Accion no reconocida']);

// ═════════════════════════════════════════════════════════════════════════════
// FUNCION: calcularPrecioYMargen
// ═════════════════════════════════════════════════════════════════════════════
function calcularPrecioYMargen(int $volumen, int $pvp = 15): array
{
    if ($volumen <= 0) {
        return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'Desconocido'];
    }
    if ($volumen >= 200)            [$precio, $tramo] = [7, '200+ pares'];
    elseif ($volumen >= 100)        [$precio, $tramo] = [8, '100-199 pares'];
    elseif ($volumen >= 50)         [$precio, $tramo] = [9, '50-99 pares'];
    else return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'<50 pares'];
    return [
        'precio_b2b'   => $precio,
        'facturacion'  => $volumen * $precio,
        'margen_par'   => $pvp - $precio,
        'margen_total' => $volumen * ($pvp - $precio),
        'tramo'        => $tramo
    ];
}
