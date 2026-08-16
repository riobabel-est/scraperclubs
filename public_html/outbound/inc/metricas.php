<?php
/**
 * metricas.php — Fuente única de métricas del piloto A/B/C (FASE 5B).
 * Usa exclusivamente envios + respuestas + aperturas, con sus relaciones
 * respuestas.envio_id → envios.id y aperturas.tracking_id → envios.tracking_id.
 * NO usa lead_pipelines, estado_lead, email ni "último envío".
 */

declare(strict_types=1);

/**
 * Calcula las métricas del piloto para una campaña concreta.
 *
 * @return array{
 *   ok:bool, campaña:?array,
 *   aceptados:int, aperturas_totales:int, abiertos_unicos:int,
 *   respuestas:int, positive:int, negative:int, neutral:int,
 *   unsubscribe:int, ooo:int, pending:int,
 *   variantes:array{lead_id:string,...}
 * }
 */
function calcularMetricas(SQLite3 $db, int $campaignId): array
{
    if ($campaignId <= 0) {
        return ['ok' => false, 'error' => 'campaign_id requerido'];
    }

    $campaña = $db->querySingle(
        "SELECT id, nombre, identificador, estado, entorno FROM pipelines WHERE id = " . $campaignId,
        true
    );

    // Base: envíos de la campaña con variante asignada (excluye legacy sin variant).
    $base = "FROM envios e WHERE e.campaign_id = {$campaignId} AND e.variant IS NOT NULL";

    // Aceptados SMTP: fuente inmutable (resultado_envio). NO usar estado.
    $aceptados = (int)$db->querySingle("SELECT COUNT(*) {$base} AND e.resultado_envio = 'ACCEPTED'");

    // Aperturas registradas (totales) y únicas por tracking_id.
    $aperturasTotales = (int)$db->querySingle(
        "SELECT COUNT(a.id) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.campaign_id = {$campaignId} AND e.variant IS NOT NULL"
    );
    $abiertosUnicos = (int)$db->querySingle(
        "SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.campaign_id = {$campaignId} AND e.variant IS NOT NULL"
    );

    // Respuestas por envio_id.
    $respuestas = (int)$db->querySingle(
        "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant IS NOT NULL"
    );

    // Clasificaciones.
    $clasif = [];
    foreach (['POSITIVE','NEGATIVE','NEUTRAL','UNSUBSCRIBE','OOO','PENDING'] as $c) {
        $clasif[$c] = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id
             WHERE e.campaign_id = {$campaignId} AND e.variant IS NOT NULL AND r.clasificacion = '{$c}'"
        );
    }

    // Desglose por variante.
    $variantes = [];
    foreach (['A','B','C'] as $v) {
        $enviosVar = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}'");
        $aceptVar = (int)$db->querySingle("SELECT COUNT(*) FROM envios e WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND e.resultado_envio = 'ACCEPTED'");
        $apertVar = (int)$db->querySingle(
            "SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}'"
        );
        $respVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}'"
        );
        $posVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND r.clasificacion = 'POSITIVE'"
        );
        $negVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND r.clasificacion = 'NEGATIVE'"
        );
        $neuVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND r.clasificacion = 'NEUTRAL'"
        );
        $unsVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND r.clasificacion = 'UNSUBSCRIBE'"
        );
        $oooVar = (int)$db->querySingle(
            "SELECT COUNT(r.id) FROM respuestas r JOIN envios e ON e.id = r.envio_id WHERE e.campaign_id = {$campaignId} AND e.variant = '{$v}' AND r.clasificacion = 'OOO'"
        );
        $prr = $aceptVar > 0 ? round($posVar / $aceptVar * 100, 1) : 0.0;

        $variantes[$v] = [
            'variante'      => $v,
            'envios'        => $enviosVar,
            'aceptados'     => $aceptVar,
            'aperturas'     => $apertVar,
            'respuestas'    => $respVar,
            'positivas'     => $posVar,
            'negativas'     => $negVar,
            'neutrales'     => $neuVar,
            'unsubscribe'   => $unsVar,
            'ooo'           => $oooVar,
            'prr'           => $prr,
        ];
    }

    return [
        'ok'                => true,
        'campaña'           => $campaña,
        'aceptados'         => $aceptados,
        'aperturas_totales' => $aperturasTotales,
        'abiertos_unicos'   => $abiertosUnicos,
        'respuestas'        => $respuestas,
        'positive'          => $clasif['POSITIVE'],
        'negative'          => $clasif['NEGATIVE'],
        'neutral'           => $clasif['NEUTRAL'],
        'unsubscribe'       => $clasif['UNSUBSCRIBE'],
        'ooo'               => $clasif['OOO'],
        'pending'           => $clasif['PENDING'],
        'variantes'         => $variantes,
    ];
}