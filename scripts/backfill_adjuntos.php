<?php
/**
 * backfill_adjuntos.php — Recupera los adjuntos de las respuestas entrantes
 * que no tienen ninguno registrado (herramienta de desarrollo local).
 *
 * Re-descarga el mensaje RAW por UID_IMAP desde los buzones de getfutprotec.com,
 * re-parsea las partes MIME y guarda los adjuntos en `respuestas_adjuntos`.
 * Es la misma función que dispara el botón "Actualizar" de la Bandeja.
 *
 * Uso: php scripts/backfill_adjuntos.php [limite]
 */

declare(strict_types=1);

require __DIR__ . '/../public_html/outbound/inc/imap_respuestas.php';

$limite = max(1, (int)($argv[1] ?? 100));

$db = new SQLite3(__DIR__ . '/../public_html/outbound/data/stats.db');
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

echo "Backfill de adjuntos (limite={$limite})...\n";
$stats = imap_backfill_adjuntos($db, $limite);
print_r($stats);
echo "Adjuntos en BD: " . $db->querySingle('SELECT COUNT(*) FROM respuestas_adjuntos') . "\n";

$db->close();
