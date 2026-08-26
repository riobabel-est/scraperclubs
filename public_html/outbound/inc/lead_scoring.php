<?php
/**
 * lead_scoring.php — Lead Scoring B2B (temperatura Frío/Tibio/Caliente/Muy Caliente).
 * Funciones puras (testeables). Adaptado a outbound email de clubes de fútbol.
 * Referencia: docs/PLAN_LEAD_SCORING_TEMPERATURA.md
 */
declare(strict_types=1);

/**
 * esCargoDecision — ¿El cargo de contacto es de decisión B2B?
 */
function esCargoDecision(string $cargo): bool {
    $c = mb_strtolower(trim($cargo), 'UTF-8');
    foreach (['presidente', 'director', 'gerente', 'vp', 'vicepresidente', 'secretario', 'manager', 'jefe', 'delegado', 'responsable', 'coordinador'] as $k) {
        if (str_contains($c, $k)) return true;
    }
    return false;
}

/**
 * calcularFitLead — Puntos de perfil (qué encaja con el cliente ideal).
 */
function calcularFitLead(array $lead): int {
    $score = 0;
    $n = (int)($lead['num_jugadores'] ?? $lead['volumen_estimado'] ?? 0);
    if ($n >= 50) $score += 20;
    elseif ($n >= 20) $score += 10;
    $cargo = (string)($lead['cargo_contacto'] ?? '');
    if ($cargo !== '' && esCargoDecision($cargo)) $score += 30;
    return $score;
}

/**
 * calcularBehaviorLead — Puntos de comportamiento (interés demostrado).
 * Señales reales de outbound email: aperturas recurrentes, respuestas IA,
 * estado del pipeline, presupuesto/mockup, enfriamiento por inactividad.
 */
function calcularBehaviorLead(array $lead): int {
    $score = 0;
    $clas   = strtoupper((string)($lead['clasificacion'] ?? ''));
    $estado = (string)($lead['estado_lead'] ?? '');
    $aperturas = (int)($lead['num_aperturas'] ?? 0);
    $envios    = (int)($lead['num_envios'] ?? 0);
    $diasInact = (int)($lead['dias_desde_contacto'] ?? $lead['dias_desde_envio'] ?? -1);

    // 🌋 Muy caliente
    if ($clas === 'POSITIVE' || $clas === 'HUMANA') $score += 50;
    if (!empty($lead['tiene_presupuesto'])) $score += 40;
    if (!empty($lead['tiene_mockup']))       $score += 40;
    if ($estado === '04 Propuesta')          $score += 35;
    // 🔥 Caliente
    if ($estado === '03 En Conversación')    $score += 20;
    if ($aperturas >= 4)                     $score += 30;
    // ⏳ Tibio
    if ($aperturas >= 2 && $aperturas <= 3)  $score += 20;
    if ($clas !== '' && !in_array($clas, ['NEGATIVE', 'POSITIVE', 'UNSUBSCRIBE'], true)) $score += 10;
    if ($envios >= 2)                        $score += 10;
    if ($aperturas === 1)                    $score += 5;
    // 🥶 Enfriamiento
    if ($diasInact >= 30)                    $score -= 25;
    elseif ($aperturas === 0 && $diasInact >= 15) $score -= 10;
    return $score;
}

/**
 * temperaturaDeScore — Etiqueta de temperatura según umbrales B2B (adaptados a
 * datos reales de outbound a clubes: 0-25 / 26-60 / 61-85 / >85).
 */
function temperaturaDeScore(int $score): string {
    if ($score > 85) return 'MuyCaliente';
    if ($score >= 61) return 'Caliente';
    if ($score >= 26) return 'Tibio';
    return 'Frio';
}

/**
 * estadoB2BDeLead — Mapeo a los 5 niveles del semáforo B2B estándar
 * (Prospect/Warm/MQL/SQL/Disqualified) con su color. Umbrales propios.
 */
function estadoB2BDeLead(string $temperatura, string $estadoLead): array {
    $estadosPerdida = ['06 Perdido', '07 Baja', 'Baja / Opt-Out', 'Opt-Out', 'Unsubscribed', 'Lista Negra'];
    if (in_array($estadoLead, $estadosPerdida, true)) {
        return ['estado_b2b' => 'Disqualified', 'color_b2b' => 'rojo'];
    }
    $map = [
        'MuyCaliente' => ['SQL', 'verde'],
        'Caliente'    => ['MQL', 'naranja'],
        'Tibio'       => ['Warm', 'amarillo'],
        'Frio'        => ['Prospect', 'azul'],
    ];
    $m = $map[$temperatura] ?? ['Prospect', 'azul'];
    return ['estado_b2b' => $m[0], 'color_b2b' => $m[1]];
}

/**
 * calcularTemperaturaLead — Score total + ejes + temperatura + prioridad derivada
 * + estado B2B (semáforo de 5 niveles) para la UI.
 */
function calcularTemperaturaLead(array $lead): array {
    $fit      = calcularFitLead($lead);
    $behavior = calcularBehaviorLead($lead);
    $score    = $fit + $behavior;
    $temp     = temperaturaDeScore($score);
    $prioridad = ($temp === 'MuyCaliente' || $temp === 'Caliente') ? 'Alta'
        : ($temp === 'Tibio' ? 'Media' : 'Baja');
    $b2b = estadoB2BDeLead($temp, (string)($lead['estado_lead'] ?? ''));
    return [
        'score'       => $score,
        'fit'         => $fit,
        'behavior'    => $behavior,
        'temperatura' => $temp,
        'prioridad'   => $prioridad,
        'estado_b2b'  => $b2b['estado_b2b'],
        'color_b2b'   => $b2b['color_b2b'],
    ];
}
