<?php
/**
 * test_seguimiento.php — Test de las funciones puras del módulo Seguimiento
 * (api/analytics.php): calcularPrioridadLead + integridad del endpoint.
 * Uso: php scripts/test_seguimiento.php
 */
declare(strict_types=1);

$db = new SQLite3(':memory:');
$db->exec("CREATE TABLE clubes_crm (
    id INTEGER PRIMARY KEY AUTOINCREMENT, nombre_club TEXT, email TEXT,
    persona_contacto TEXT, estado_lead TEXT, federacion TEXT, proxima_accion TEXT,
    ultimo_contacto DATETIME, volumen_estimado INTEGER, creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_proxima_accion DATETIME)");
$db->exec("CREATE TABLE envios (id INTEGER PRIMARY KEY, email TEXT, asunto TEXT, fecha_envio DATETIME, es_test INTEGER DEFAULT 0, tracking_id TEXT, variant TEXT, campaign_id INTEGER)");
$db->exec("CREATE TABLE aperturas (id INTEGER PRIMARY KEY, tracking_id TEXT, fecha_apertura DATETIME)");
$db->exec("CREATE TABLE rebotes (id INTEGER PRIMARY KEY, email TEXT)");
$db->exec("CREATE TABLE comunicaciones_log (id INTEGER PRIMARY KEY, lead_id INTEGER, tipo_evento TEXT, detalles TEXT)");
$db->exec("CREATE TABLE presupuestos (id INTEGER PRIMARY KEY, lead_id INTEGER, importe_total REAL, estado TEXT, version INTEGER)");
$db->exec("CREATE TABLE mockups (id INTEGER PRIMARY KEY, lead_id INTEGER, estado TEXT)");
$db->exec("CREATE TABLE respuestas (id INTEGER PRIMARY KEY, lead_id INTEGER, clasificacion TEXT, fecha_respuesta DATETIME)");

// Seed: 2 no respondedores (uno con apertura+7d, otro fresco) + 1 nuevo sin actividad + 1 antiguo sin actividad
$db->exec("INSERT INTO clubes_crm (id,nombre_club,email,estado_lead,federacion,ultimo_contacto,volumen_estimado,creado_el) VALUES
    (1,'Club Alpha','a@club.es','02 Contactado','Andalucía',datetime('now','-10 days'),60,datetime('now','-20 days')),
    (2,'Club Beta','b@club.es','02 Contactado','Madrid',datetime('now','-2 days'),15,datetime('now','-15 days')),
    (3,'Club Gamma','g@club.es','01 Sin Contactar','Valencia',NULL,NULL,datetime('now','-3 days')),
    (4,'Club Delta','d@club.es','01 Sin Contactar','Madrid',NULL,NULL,datetime('now','-30 days'))");
$db->exec("INSERT INTO envios (id,email,asunto,fecha_envio,es_test,tracking_id) VALUES
    (1,'a@club.es','Oferta',datetime('now','-10 days'),0,'t1'),
    (2,'b@club.es','Oferta',datetime('now','-2 days'),0,'t2')");
$db->exec("INSERT INTO aperturas (id,tracking_id,fecha_apertura) VALUES (1,'t1',datetime('now','-9 days'))");

$action = ''; // Evita ejecutar endpoints al incluir el módulo.
require __DIR__ . '/../public_html/outbound/api/analytics.php';

$tests = 0; $ok = 0;
function check(string $nombre, bool $cond): void {
    global $tests, $ok;
    $tests++;
    if ($cond) { $ok++; echo "  PASS  {$nombre}\n"; }
    else { echo "  FAIL  {$nombre}\n"; }
}

echo "── calcularPrioridadLead ──\n";
$p = calcularPrioridadLead(['tiene_apertura' => true, 'dias_desde_envio' => 8, 'volumen_estimado' => 60, 'proxima_accion' => '']);
check('Alta: abrió + ≥7d + volumen 60', $p['nivel'] === 'Alta' && $p['score'] === 70);
$p = calcularPrioridadLead(['tiene_apertura' => false, 'dias_desde_envio' => 8, 'volumen_estimado' => 15, 'proxima_accion' => '']);
check('Media: ≥7d sin apertura, volumen bajo', $p['nivel'] === 'Media' && $p['score'] === 25);
$p = calcularPrioridadLead(['tiene_apertura' => false, 'dias_desde_envio' => 2, 'volumen_estimado' => 15, 'proxima_accion' => '']);
check('Baja: recién enviado, volumen bajo', $p['nivel'] === 'Baja');
$p = calcularPrioridadLead(['tiene_apertura' => true, 'num_aperturas' => 3, 'dias_desde_envio' => 8, 'volumen_estimado' => 15, 'proxima_accion' => '']);
check('Interés repetido: 3 aperturas + 8d = Alta (score 65)', $p['nivel'] === 'Alta' && $p['score'] === 65);
$p = calcularPrioridadLead(['tiene_apertura' => true, 'num_aperturas' => 5, 'dias_desde_envio' => 8, 'volumen_estimado' => 15, 'proxima_accion' => '']);
check('Relectura reiterada: 5 aperturas + 8d = Alta (score 75)', $p['nivel'] === 'Alta' && $p['score'] === 75);
$p = calcularPrioridadLead(['tiene_apertura' => false, 'dias_desde_contacto' => 5, 'estado_lead' => '04 Propuesta', 'proxima_accion' => '', 'presupuesto_importe' => 1000.0]);
check('Alta: propuesta sin próxima acción + presupuesto', $p['nivel'] === 'Alta');

echo "\n── getSeguimientoNoRespondedores ──\n";
$where = "AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')";
$nr = getSeguimientoNoRespondedores($db, $where, ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false]);
check('Devuelve 2 no respondedores', count($nr) === 2);
check('Orden: Alta primero', ($nr[0]['prioridad'] ?? '') === 'Alta');
check('Tiene apertura marcada en Club Alpha', $nr[0]['tiene_apertura'] === true);
$nrSoloAlta = getSeguimientoNoRespondedores($db, $where, ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => true]);
check('Filtro solo_alta reduce a 1', count($nrSoloAlta) === 1);
$nrBusq = getSeguimientoNoRespondedores($db, $where, ['busqueda' => 'beta', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false]);
check('Filtro búsqueda (beta) → 1', count($nrBusq) === 1);
$nrFed = getSeguimientoNoRespondedores($db, $where, ['busqueda' => '', 'federacion' => 'Madrid', 'dias_min' => 0, 'solo_alta' => false]);
check('Filtro federación (Madrid) → 1', count($nrFed) === 1);

echo "\n── getSeguimientoFunnel ──\n";
$funnel = getSeguimientoFunnel($db, $where);
check('5 etapas', count($funnel) === 5);
check('Etapa 01 Sin Contactar = 2', $funnel[0]['cnt'] === 2);
check('Etapa 02 Contactado = 2', $funnel[1]['cnt'] === 2);

echo "\n── getSeguimientoNuevosSinActividad (Smart View Calentar) ──\n";
$nuevos = getSeguimientoNuevosSinActividad($db, $where, ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false]);
check('Solo Club Gamma (7d, sin envíos)', count($nuevos) === 1 && ($nuevos[0]['nombre_club'] ?? '') === 'Club Gamma');
$nuevosMadrid = getSeguimientoNuevosSinActividad($db, $where, ['busqueda' => '', 'federacion' => 'Madrid', 'dias_min' => 0, 'solo_alta' => false]);
check('Filtro federación Madrid → 0 (Delta es antiguo)', count($nuevosMadrid) === 0);

echo "\n── getSeguimientoSinProximaAccion (agenda) ──\n";
$db->exec("UPDATE clubes_crm SET estado_lead='03 En Conversación', proxima_accion='Llamar', fecha_proxima_accion=datetime('now','-2 days') WHERE id=3");
$spa = getSeguimientoSinProximaAccion($db, $where, ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false]);
check('Incluye vencida (Club Gamma)', count($spa) === 1 && !empty($spa[0]['vencida']));

echo "\n── getSeguimientoKpis ──\n";
$nuevosPost = getSeguimientoNuevosSinActividad($db, $where, ['busqueda' => '', 'federacion' => '', 'dias_min' => 0, 'solo_alta' => false]);
$kpis = getSeguimientoKpis($db, $where, $nr, $spa, $nuevosPost);
check('no_respondedores = 2', $kpis['no_respondedores'] === 2);
check('nuevos_sin_actividad = 0 (Gamma pasó a Conversación)', $kpis['nuevos_sin_actividad'] === 0);
check('sin_proxima_accion = 1', $kpis['sin_proxima_accion'] === 1);
check('tasa_respuesta = 33.3 (1/3 contactados)', $kpis['tasa_respuesta'] === 33.3);
check('tasa_apertura = 33.3 (1/3 entregados)', $kpis['tasa_apertura'] === 33.3);

echo "\nResultado: {$ok}/{$tests} OK\n";
exit($ok === $tests ? 0 : 1);
