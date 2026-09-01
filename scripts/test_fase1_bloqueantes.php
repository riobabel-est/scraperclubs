<?php
/**
 * test_fase1_bloqueantes.php — TESTS de FASE 1 del MEGAPROMPT V2 (CRM FutProtec).
 *
 * Comprueba:
 *   TEST 01 — DB integrity
 *   TEST 10 — hard bounce suppression (lead con rebote no elegible)
 *   TEST 11 — TEST/REAL isolation (lead TEST en campaña REAL bloqueado)
 *   TEST 12 — deterministic A/B/C (misma combinación → misma variante)
 *   TEST 08 — RFC 2047 (encoded-word para nombres no ASCII)
 *
 * Uso local:  php scripts/test_fase1_bloqueantes.php
 * No envía emails. Solo lectura sobre stats.db.
 */

declare(strict_types=1);

$DB = __DIR__ . '/../public_html/outbound/data/stats.db';
require_once __DIR__ . '/../public_html/outbound/inc/eligibilidad.php';  // incluye abc.php y respuestas.php
require_once __DIR__ . '/../public_html/outbound/inc/smtp_transport.php'; // incluye crypto.php

$pass = 0;
$fail = 0;

function check(string $nombre, bool $cond, string $detalle = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS | {$nombre}\n";
    } else {
        $fail++;
        echo "FAIL | {$nombre} | {$detalle}\n";
    }
}

// ─── TEST 01 — DB integrity ────────────────────────────────────────────────
$db = new SQLite3($DB);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$integrity = (string)($db->querySingle('PRAGMA integrity_check') ?? '');
check('TEST 01 DB integrity', $integrity === 'ok', $integrity);

// ─── TEST 10 — hard bounce suppression ─────────────────────────────────────
// lead 881 = pdrociera@yahoo.es → hard bounce real (FASE 1.1, fila 1 de rebotes).
$elig881 = esElegibleParaEnvio($db, 881, 2);
check(
    'TEST 10 lead con hard bounce NO elegible',
    !$elig881['ok'] && ($elig881['razon'] ?? '') === 'hard_bounce',
    json_encode($elig881, JSON_UNESCAPED_UNICODE)
);
// lead 1217 (C.D. Segosala, real, sin rebote) → no debe estar bloqueado por hard_bounce.
$elig1217 = esElegibleParaEnvio($db, 1217, 2);
check(
    'TEST 10 lead sin hard bounce no bloqueado por hard_bounce',
    ($elig1217['razon'] ?? '') !== 'hard_bounce',
    json_encode($elig1217, JSON_UNESCAPED_UNICODE)
);

// ─── TEST 11 — TEST/REAL isolation ─────────────────────────────────────────
// lead 1811 = TEST_CLUB_03_Valencia (test) en campaña 2 (no test) → bloqueado.
$eligTest = esElegibleParaEnvio($db, 1811, 2);
check(
    'TEST 11 lead TEST en campaña REAL bloqueado',
    !$eligTest['ok'] && ($eligTest['razon'] ?? '') === 'lead_test_en_campana_no_test',
    json_encode($eligTest, JSON_UNESCAPED_UNICODE)
);

// ─── TEST 12 — deterministic A/B/C ─────────────────────────────────────────
$r1 = asignarVariante(881, 2);
$r2 = asignarVariante(881, 2);
$r3 = asignarVariante(881, 2);
check(
    'TEST 12 misma combinación → misma variante',
    $r1 === $r2 && $r2 === $r3 && in_array($r1, ['A', 'B', 'C'], true),
    "{$r1}/{$r2}/{$r3}"
);

// ─── TEST 08 — RFC 2047 ────────────────────────────────────────────────────
check('TEST 08 RFC2047 nombre ASCII literal', futprotec_encodeHeaderName('FutProtec') === 'FutProtec');
$enc = futprotec_encodeHeaderName('Adrián Cano');
check(
    'TEST 08 RFC2047 acento → encoded-word',
    str_starts_with($enc, '=?UTF-8?B?') && str_ends_with($enc, '?='),
    $enc
);
$b64 = str_replace(['=?UTF-8?B?', '?='], '', $enc);
check('TEST 08 RFC2047 decode correcto', base64_decode($b64) === 'Adrián Cano', $b64);
$enc2 = futprotec_encodeHeaderName('FutProtec España');
check('TEST 08 RFC2047 ñ → encoded-word', str_starts_with($enc2, '=?UTF-8?B?'), $enc2);
$enc3 = futprotec_encodeHeaderName('José María García');
check('TEST 08 RFC2047 á/é → encoded-word', str_starts_with($enc3, '=?UTF-8?B?'), $enc3);

// ─── TEST 07 — raw MIME (cabeceras construidas como el transporte, sin enviar) ──
// Replica la construcción de inc/smtp_transport.php (línea From + Subject + To + Reply-To).
$fromName      = 'Adrián Cano';
$fromEmail     = 'adrian.cano@getfutprotec.com';
$destinatario  = 'club@ejemplo.es';
$asunto        = 'Espinilleras personalizadas para tu club';
$replyTo       = $fromEmail;
$rawMime =
    "From: " . futprotec_encodeHeaderName($fromName) . " <{$fromEmail}>\r\n" .
    "To: <{$destinatario}>\r\n" .
    "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Reply-To: {$replyTo}\r\n" .
    "Content-Type: text/html; charset=UTF-8\r\n";
check('TEST 07 raw MIME From encoded-word', str_starts_with($rawMime, 'From: =?UTF-8?B?'), $rawMime);
check('TEST 07 raw MIME Subject UTF-8', str_contains($rawMime, 'Subject: =?UTF-8?B?'));
check('TEST 07 raw MIME Reply-To presente', str_contains($rawMime, 'Reply-To: '));
check('TEST 07 raw MIME cabeceras 100% ASCII', !preg_match('/[^\x20-\x7E\r\n]/', $rawMime), $rawMime);
echo "---- RAW MIME inspeccionado ----\n" . $rawMime . "--------------------------------\n";

$db->close();

echo "----\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
