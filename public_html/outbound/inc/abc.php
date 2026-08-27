<?php
/**
 * abc.php — Módulo central A/B/C (FASE 3).
 * Única fuente de verdad para asignación de variante (determinística e inmutable),
 * resolución de contenido por variante y coherencia entorno/campaña.
 * Reutilizado por P1 (api/enviar_lote.php) y P3 (cli/cron.php).
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

/**
 * Asigna A/B/C de forma DETERMINÍSTICA a partir de (lead_id, campaign_id).
 *
 * Método elegido: hash estable (no random por envío).
 *  - Reproducible: mismo (lead, campaña) → misma variante SIEMPRE.
 *  - Inmutable: un retry/reanudación no puede cambiar la variante.
 *  - Concurrent-safe: sin contador compartido ni escritura de asignación distinta.
 *  - Equilibrio razonable: distribución ≈33/33/33 sobre ids heterogéneas.
 *  - Auditable: se puede recalcular sin depender de estado previo.
 *
 * @return string 'A' | 'B' | 'C'
 */
function asignarVariante(int $leadId, int $campaignId): string
{
    $h = crc32((string)$campaignId . ':' . (string)$leadId);
    // Normalización explícita a entero sin signo (32-bit) antes de aplicar % 3,
    // eliminando toda dependencia del signo/representación del entero de PHP.
    if ($h < 0) {
        $h += 4294967296; // 2^32
    }
    $map = ['A', 'B', 'C'];
    return $map[$h % 3];
}

/**
 * ROTACIÓN ABC para no abridores: devuelve la SIGUIENTE variante en la rueda
 * A→B→C→A. Usada por la "secuencia de rotación" para reenviar con otro ángulo
 * a los leads que NO abrieron el email anterior (máx. según secuencia).
 *
 * @return string 'A' | 'B' | 'C'
 */
function siguienteVariante(string $actual): string
{
    $actual = strtoupper(trim($actual));
    if (!in_array($actual, ['A', 'B', 'C'], true)) {
        $actual = 'A';
    }
    $rueda = ['A' => 'B', 'B' => 'C', 'C' => 'A'];
    return $rueda[$actual];
}

/**
 * Resuelve asunto/cuerpo concretos para una variante a partir de una plantilla.
 * Centraliza la lógica que antes estaba duplicada en los motores.
 *
 * @return array{asunto:string, cuerpo:string}
 */
function resolverContenidoVariante(array $plantilla, string $variant): array
{
    $asunto = (string)($plantilla['asunto'] ?? '');
    $cuerpo = (string)($plantilla['cuerpo'] ?? '');

    if ((int)($plantilla['test_ab'] ?? 0) === 1) {
        if ($variant === 'B') {
            if (($plantilla['asunto_b'] ?? '') !== '') {
                $asunto = (string)$plantilla['asunto_b'];
            }
            if (($plantilla['cuerpo_b'] ?? '') !== '') {
                $cuerpo = (string)$plantilla['cuerpo_b'];
            }
        } elseif ($variant === 'C') {
            if (($plantilla['asunto_c'] ?? '') !== '') {
                $asunto = (string)$plantilla['asunto_c'];
            }
            if (($plantilla['cuerpo_c'] ?? '') !== '') {
                $cuerpo = (string)$plantilla['cuerpo_c'];
            }
        }
    }

    return ['asunto' => $asunto, 'cuerpo' => $cuerpo];
}

/**
 * Valida que una campaña sea operable para envío (existencia + estado + activo + entorno).
 * Política ÚNICA reutilizada por P1 (enviar_lote.php) y P3 (cron.php).
 * Estados permitidos: PILOT, ACTIVE. activo=1. Coherencia de entorno vía esEntornoCoherente().
 *
 * @return array{ok:bool, razon:string, campaña:?array}
 */
function validarCampanaActiva(SQLite3 $db, int $campaignId, string $modoEntorno): array
{
    if ($campaignId <= 0) {
        return ['ok' => false, 'razon' => 'NO_CAMPAIGN', 'campaña' => null];
    }

    $camp = $db->querySingle(
        "SELECT id, estado, activo, entorno FROM pipelines WHERE id = " . $campaignId,
        true
    );
    if (!$camp) {
        return ['ok' => false, 'razon' => 'INVALID_CAMPAIGN', 'campaña' => null];
    }

    $estadosPermitidos = ['PILOT', 'ACTIVE'];
    if (!in_array(strtoupper((string)($camp['estado'] ?? '')), $estadosPermitidos, true)
        || (int)($camp['activo'] ?? 0) !== 1) {
        return ['ok' => false, 'razon' => 'CAMPAIGN_NOT_ACTIVE', 'campaña' => $camp];
    }

    $coh = esEntornoCoherente((string)($camp['entorno'] ?? ''), $modoEntorno);
    if (!$coh['ok']) {
        return ['ok' => false, 'razon' => 'ENVIRONMENT_MISMATCH', 'campaña' => $camp];
    }

    return ['ok' => true, 'razon' => 'CAMPANIA_VALIDA', 'campaña' => $camp];
}

/**
 * Coherencia entorno/campaña (FASE 3A).
 *
 * Regla mínima usando SOLO los valores existentes:
 *  - config.modo_entorno ∈ {test, produccion}.
 *  - pipelines.entorno ∈ {test, pilot, production}.
 *
 * Impide cruces que contaminarían el experimento:
 *  - campaña test en producción → bloqueado (TEST no sale a producción).
 *  - campaña pilot/production en modo test local → bloqueado (comercial no se
 *    dispara en un entorno de pruebas con datos potencialmente contaminados).
 *
 * @return array{ok:bool, razon:string}
 */
function esEntornoCoherente(?string $campaignEntorno, ?string $modoEntorno): array
{
    $ce = strtolower(trim((string)$campaignEntorno));
    $me = strtolower(trim((string)$modoEntorno));

    if ($ce === '') $ce = 'test';
    if ($me === '') $me = 'test';

    if ($me === 'produccion' && $ce === 'test') {
        return ['ok' => false, 'razon' => 'campaign_test_en_produccion'];
    }
    if ($me === 'test' && in_array($ce, ['pilot', 'production'], true)) {
        return ['ok' => false, 'razon' => 'campaign_comercial_en_test'];
    }

    return ['ok' => true, 'razon' => 'coherente'];
}