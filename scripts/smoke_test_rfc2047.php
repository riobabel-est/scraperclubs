<?php
/**
 * smoke_test_rfc2047.php — SMOKE TEST (FASE 6).
 *
 * Valida el `From` RFC 2047 contra SMTP real: envía 1 email de campaña 2 en
 * MODO TEST (es_test=1) con la cuenta 'Adrián Cano' a un buzón del equipo.
 * No es un envío comercial; queda aislado por el aislamiento TEST/REAL.
 *
 * Uso: php scripts/smoke_test_rfc2047.php
 */

declare(strict_types=1);

$_POST['id_club']         = 11;            // C. F. SAN JOSE-LOS ANGELES (real, sin bounce)
$_POST['id_plantilla']    = 1;             // Prospección - Paso 1 - Test ABC
$_POST['id_cuenta_smtp']  = 9;             // adrian.cano@getfutprotec.com (nombre 'Adrián Cano')
$_POST['campaign_id']     = 2;             // Campaña 2 (la comercial)
$_POST['modo_test']       = '1';           // MODO TEST → destino = test_email, es_test=1
$_POST['test_email']      = 'estudioriobabel@gmail.com';
$_POST['variante_ab']     = 'A';

require __DIR__ . '/../public_html/outbound/api/enviar_lote.php';
