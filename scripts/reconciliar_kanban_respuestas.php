<?php
/**
 * reconciliar_kanban_respuestas.php — Reconciliación Kanban por respuestas humanas
 * ==============================================================================
 * Diagnóstico y corrección del flujo automático de movimiento de tarjetas Kanban.
 *
 * PROBLEMA:
 *  - El Kanban agrupa los leads por `clubes_crm.estado_lead`.
 *  - Cuando un lead responde, el runner IMAP registra la respuesta en `respuestas`
 *    y llama a `imap_mover_kanban()` que actualiza `estado_lead = '03 En Conversación'`.
 *  - PERO las respuestas registradas ANTES de implementar `imap_mover_kanban()`
 *    quedaron sin mover la tarjeta. Al ser ahora "duplicados", el runner no las
 *    reprocesa y la tarjeta nunca se mueve.
 *
 * SOLUCIÓN:
 *  - Modo auditoría (sin --apply): lista los leads con respuesta humana cuyo
 *    estado_lead NO es '03 En Conversación'.
 *  - Modo aplicar (--apply): actualiza estado_lead a '03 En Conversación' para
 *    esos leads (respeta protección de opt-out real).
 *
 * Uso:
 *   php reconciliar_kanban_respuestas.php            # auditoría (solo lectura)
 *   php reconciliar_kanban_respuestas.php --apply    # aplica cambios
 *
 * Compatibilidad SiteGround: PHP 8.x nativo + SQLite3.
 */

declare(strict_types=1);

$DB_PATH = __DIR__ . '/../public_html/outbound/data/stats.db';
$APPLY = in_array('--apply', $argv, true);

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "ERROR: No existe la BD en {$DB_PATH}\n");
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->exec('PRAGMA busy_timeout = 5000');

// Clasificaciones que se consideran respuesta humana (mueven el Kanban).
// Mismo criterio que imap_es_respuesta_humana() en inc/imap_respuestas.php.
$humanas = ['humana', 'interesado', 'duda_precio', 'neutral', 'no_interesa'];

// Estados de supresión que NO deben reactivarse (protección opt-out real).
$supresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];

echo "=== Reconciliación Kanban por respuestas humanas ===\n";
echo "Modo: " . ($APPLY ? "APLICAR (escribe en BD)" : "AUDITORÍA (solo lectura)") . "\n";
echo "BD: {$DB_PATH}\n\n";

// Verificar que las tablas existen
$tablaResp = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='respuestas'");
$tablaClub = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='clubes_crm'");
if (!$tablaResp || !$tablaClub) {
    fwrite(STDERR, "ERROR: Faltan tablas (respuestas=" . var_export($tablaResp, true) . ", clubes_crm=" . var_export($tablaClub, true) . ")\n");
    exit(1);
}

// Columnas de respuestas
$colsResp = [];
$res = $db->query('PRAGMA table_info(respuestas)');
while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
    $colsResp[$c['name']] = true;
}
echo "Columnas en respuestas: " . implode(', ', array_keys($colsResp)) . "\n\n";

// Determinar columna de clasificación y lead_id
$colClas = isset($colsResp['clasificacion_ia']) ? 'clasificacion_ia' : (isset($colsResp['clasificacion']) ? 'clasificacion' : null);
$colLead = isset($colsResp['lead_id']) ? 'lead_id' : null;
if (!$colClas || !$colLead) {
    fwrite(STDERR, "ERROR: No se encontraron columnas de clasificación/lead_id en respuestas\n");
    exit(1);
}

// Buscar leads con respuesta humana cuyo estado_lead NO es '03 En Conversación'
$inList = implode(',', array_map(fn($v) => "'" . SQLite3::escapeString($v) . "'", $humanas));
$sql = "
    SELECT DISTINCT r.{$colLead} AS lead_id,
           c.nombre_club,
           c.estado_lead,
           c.email,
           (SELECT COUNT(*) FROM respuestas r2 WHERE r2.{$colLead} = r.{$colLead} AND r2.{$colClas} IN ({$inList})) AS num_respuestas_humanas
    FROM respuestas r
    JOIN clubes_crm c ON c.id = r.{$colLead}
    WHERE r.{$colClas} IN ({$inList})
      AND c.estado_lead IS NOT NULL
      AND c.estado_lead != '03 En Conversación'
      AND c.estado_lead NOT IN ('" . implode("','", array_map(fn($v) => SQLite3::escapeString($v), $supresion)) . "')
    ORDER BY c.nombre_club ASC
";

$res = $db->query($sql);
$pendientes = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $pendientes[] = $row;
}

echo "Leads con respuesta humana pendientes de mover a '03 En Conversación': " . count($pendientes) . "\n\n";

if (count($pendientes) === 0) {
    echo "✓ No hay leads pendientes de reconciliar.\n";
    exit(0);
}

foreach ($pendientes as $i => $p) {
    $estado = $p['estado_lead'] ?? '(NULL)';
    echo sprintf(
        "  %d. [id=%d] %s | estado_actual='%s' | respuestas_humanas=%d | email=%s\n",
        $i + 1,
        $p['lead_id'],
        $p['nombre_club'],
        $estado,
        $p['num_respuestas_humanas'],
        $p['email'] ?? '-'
    );
}

if (!$APPLY) {
    echo "\nModo auditoría: no se realizaron cambios.\n";
    echo "Para aplicar, ejecutar con --apply\n";
    exit(0);
}

// Aplicar cambios
echo "\n=== Aplicando cambios ===\n";
$stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = '03 En Conversación', ultimo_contacto = CURRENT_TIMESTAMP WHERE id = :id");
$actualizados = 0;
foreach ($pendientes as $p) {
    $stmt->bindValue(':id', (int)$p['lead_id'], SQLITE3_INTEGER);
    $stmt->execute();
    if ($db->changes() > 0) {
        $actualizados++;
        echo "  ✓ [id={$p['lead_id']}] {$p['nombre_club']} → '03 En Conversación'\n";
    }
}

echo "\nTotal actualizados: {$actualizados}\n";
echo "=== Reconciliación completada ===\n";
