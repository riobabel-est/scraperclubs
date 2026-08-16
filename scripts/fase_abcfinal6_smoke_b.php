<?php
declare(strict_types=1);

/**
 * FASE ABC-FINAL.6 — FASE 3: SMOKE ÚNICO REAL de variante B.
 *
 * Ejecuta el flujo ACTUAL de envío individual (public_html/outbound/api/enviar_lote.php)
 * para el lead 1817 (variante B determinística), con destinatario resuelto desde
 * config.test_emails (SIN literales de correo) y modo_test=1.
 *
 * ÚNICO POST/ejecución de envío de esta fase. NO cron, NO enviar_smtp_random.php,
 * NO Evolution API, NO segundo envío.
 */

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
if (!file_exists($DB)) {
    fwrite(STDERR, "BLOCKED: stats.db no encontrada\n");
    exit(2);
}

// Leer destinatario desde config.test_emails (primer buzón), sin literales.
$dbRead = new SQLite3($DB, SQLITE3_OPEN_READONLY);
$dbRead->enableExceptions(true);
$raw = (string)($dbRead->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'") ?: '');
$emails = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $raw))));
$testEmail = $emails[0] ?? '';
$dbRead->close();

if ($testEmail === '') {
    fwrite(STDERR, "BLOCKED: config.test_emails sin buzones\n");
    exit(3);
}

// SMTP seleccionado en precheck (id=1, con capacidad). id_cuenta_smtp es un id de BD, no secreto.
$smtpId = 1;

echo "Smoke B — destinatario resuelto desde config.test_emails: {$testEmail}\n";
echo "Smoke B — ejecutando flujo real enviar_lote.php (lead 1817, campaign 3, plantilla 2, smtp {$smtpId}, modo_test=1)\n\n";

// Poblar $_POST y ejecutar el flujo REAL del endpoint (mismo código de producción).
$_POST = [
    'campaign_id'    => '3',
    'id_club'        => '1817',
    'id_plantilla'   => '2',
    'id_cuenta_smtp' => (string)$smtpId,
    'modo_test'      => '1',
    'test_email'     => $testEmail,
    'variante_ab'    => 'B',
];

require __DIR__ . '/../public_html/outbound/api/enviar_lote.php';
