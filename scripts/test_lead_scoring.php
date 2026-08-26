<?php
/**
 * test_lead_scoring.php — Test de las funciones puras de Lead Scoring B2B
 * (inc/lead_scoring.php): fit, behavior, umbrales de temperatura e integración.
 * Uso: php scripts/test_lead_scoring.php
 */
declare(strict_types=1);

require __DIR__ . '/../public_html/outbound/inc/lead_scoring.php';

$tests = 0; $ok = 0;
function check(string $n, bool $c): void { global $tests, $ok; $tests++; if ($c) { $ok++; echo "  PASS  {$n}\n"; } else { echo "  FAIL  {$n}\n"; } }

echo "── calcularFitLead ──\n";
check('Fit: num_jugadores 60 → +20', calcularFitLead(['num_jugadores' => 60]) === 20);
check('Fit: num_jugadores 30 → +10', calcularFitLead(['num_jugadores' => 30]) === 10);
check('Fit: cargo presidente → +30', calcularFitLead(['cargo_contacto' => 'Presidente']) === 30);
check('Fit: sin datos → 0', calcularFitLead([]) === 0);

echo "\n── calcularBehaviorLead ──\n";
check('Behavior: 1 apertura → +5', calcularBehaviorLead(['num_aperturas' => 1]) === 5);
check('Behavior: 3 aperturas → +20', calcularBehaviorLead(['num_aperturas' => 3]) === 20);
check('Behavior: 5 aperturas → +30', calcularBehaviorLead(['num_aperturas' => 5]) === 30);
check('Behavior: POSITIVE → +50', calcularBehaviorLead(['clasificacion' => 'POSITIVE']) === 50);
check('Behavior: presupuesto → +40', calcularBehaviorLead(['tiene_presupuesto' => 1]) === 40);
check('Behavior: estado 04 Propuesta → +35', calcularBehaviorLead(['estado_lead' => '04 Propuesta']) === 35);
check('Behavior: 35d sin aperturas → −25', calcularBehaviorLead(['dias_desde_contacto' => 35, 'num_aperturas' => 0]) === -25);
check('Behavior: 20d sin aperturas → −10', calcularBehaviorLead(['dias_desde_contacto' => 20, 'num_aperturas' => 0]) === -10);

echo "\n── temperaturaDeScore ──\n";
check('Temp: 0 → Frio', temperaturaDeScore(0) === 'Frio');
check('Temp: 50 → Tibio', temperaturaDeScore(50) === 'Tibio');
check('Temp: 70 → Caliente', temperaturaDeScore(70) === 'Caliente');
check('Temp: 90 → MuyCaliente', temperaturaDeScore(90) === 'MuyCaliente');

echo "\n── calcularTemperaturaLead (integración) ──\n";
$t1 = calcularTemperaturaLead(['num_aperturas' => 5, 'clasificacion' => 'POSITIVE', 'num_jugadores' => 60]);
check('5 apert + POSITIVE + fit 60 → MuyCaliente (score≥85)', $t1['temperatura'] === 'MuyCaliente' && $t1['score'] >= 85);
$t2 = calcularTemperaturaLead(['num_aperturas' => 0, 'dias_desde_contacto' => 40]);
check('Inactivo 40d sin aperturas → Frio', $t2['temperatura'] === 'Frio');
$t3 = calcularTemperaturaLead(['num_aperturas' => 3, 'num_envios' => 2, 'dias_desde_contacto' => 2]);
check('3 aperturas + 2 envíos recientes → Tibio', $t3['temperatura'] === 'Tibio');
check('Prioridad derivada: MuyCaliente → Alta', $t1['prioridad'] === 'Alta');
check('Ejes separados: fit=20 behavior=80', $t1['fit'] === 20 && $t1['behavior'] === 80);

echo "\n── estadoB2BDeLead (semáforo 5 niveles) ──\n";
check('MuyCaliente → SQL/verde', estadoB2BDeLead('MuyCaliente', '03 En Conversación') === ['estado_b2b' => 'SQL', 'color_b2b' => 'verde']);
check('Caliente → MQL/naranja', estadoB2BDeLead('Caliente', '02 Contactado') === ['estado_b2b' => 'MQL', 'color_b2b' => 'naranja']);
check('Tibio → Warm/amarillo', estadoB2BDeLead('Tibio', '02 Contactado') === ['estado_b2b' => 'Warm', 'color_b2b' => 'amarillo']);
check('Frio → Prospect/azul', estadoB2BDeLead('Frio', '01 Sin Contactar') === ['estado_b2b' => 'Prospect', 'color_b2b' => 'azul']);
check('06 Perdido → Disqualified/rojo (aunque MuyCaliente)', estadoB2BDeLead('MuyCaliente', '06 Perdido') === ['estado_b2b' => 'Disqualified', 'color_b2b' => 'rojo']);
$t4 = calcularTemperaturaLead(['num_aperturas' => 5, 'clasificacion' => 'POSITIVE', 'num_jugadores' => 60, 'estado_lead' => '07 Baja']);
check('Integración: Baja → Disqualified', $t4['estado_b2b'] === 'Disqualified' && $t4['color_b2b'] === 'rojo');

echo "\nResultado: {$ok}/{$tests} OK\n";
exit($ok === $tests ? 0 : 1);
