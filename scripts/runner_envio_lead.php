<?php
/**
 * runner_envio_lead.php — Wrapper CLI para enviar UN lead vía api/enviar_lote.php.
 *
 * Permite disparar el lote real de FASE 6 desde CLI reutilizando EXACTAMENTE el
 * flujo probado del endpoint (reserva + SMTP + registro + tracking/clics/baja).
 *
 * Uso:
 *   php scripts/runner_envio_lead.php --lead=11 --tpl=1 --smtp=9 --campaign=2
 *
 * En producción enviar_lote.php recalcula la variante determinista (asignarVariante)
 * y aplica supresión de bounces/elegibilidad. Modo REAL por defecto.
 */

declare(strict_types=1);

$opts = getopt('', ['lead:', 'tpl:', 'smtp:', 'campaign:']);

$_POST['id_club']         = (int)($opts['lead'] ?? 0);
$_POST['id_plantilla']    = (int)($opts['tpl'] ?? 0);
$_POST['id_cuenta_smtp']  = (int)($opts['smtp'] ?? 0);
$_POST['campaign_id']     = (int)($opts['campaign'] ?? 0);
$_POST['modo_test']       = '0';  // REAL: el destino es el email del club
$_POST['variante_ab']     = 'A';  // el backend recalcula en producción

// Localizar api/enviar_lote.php en local (repo) o producción (public_html/scripts -> outbound).
$candidatas = [
    __DIR__ . '/../public_html/outbound/api/enviar_lote.php', // entorno local
    __DIR__ . '/../outbound/api/enviar_lote.php',             // producción (public_html/scripts)
];
$pathEnviarLote = null;
foreach ($candidatas as $p) {
    if (is_file($p)) { $pathEnviarLote = $p; break; }
}
if (!$pathEnviarLote) {
    fwrite(STDERR, "ERROR: no se encuentra api/enviar_lote.php\n");
    exit(2);
}
require $pathEnviarLote;
