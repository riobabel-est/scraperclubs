<?php
/**
 * migrar_plantillas_objetivo.php — Aplica SOLO la reorganización de plantillas
 * por objetivo (sin ejecutar el resto de init_db para no duplicar telefonos_club).
 * Idempotente. Se ejecuta una vez localmente y puede quedarse como herramienta CLI.
 *
 * Estructura final (2026-08-26):
 *   - 01 Prospección  (1er contacto)
 *   - 02 Seguimiento  (2º/3er toque: no respondió, alta apertura)
 *   - 03 Respuestas   (objeciones, síes)
 *   - WhatsApp se deja SIN categoría (genérica) hasta integrar su flujo.
 */
declare(strict_types=1);
$DB = __DIR__ . '/../data/stats.db';
$db = new SQLite3($DB);
$db->enableExceptions(true);

echo "Reorganización de plantillas (categorías numeradas por objetivo)...\n";

// 1) Renombrado de categorías legacy (BD fresca) → categorías finales.
$renomCategorias = [
    '01 Sin Contactar'   => '01 Prospección',
    '02 Contactado'      => '02 Seguimiento',
    '03 Respondió'       => '03 Respuestas',
    '03 En Conversación' => '03 Respuestas',
    'respuesta_modelo'   => '03 Respuestas',
    'prospeccion'        => '01 Prospección',
    'seguimiento'        => '02 Seguimiento',
    'whatsapp'           => '',
];
// 2) Renombrado de los nombres intermedios (BD ya migrada a la convención anterior).
$renomIntermedios = [
    '[Prospección] Clubes Fútbol Base' => '01 Prospección',
    '[Seguimiento] Leads Interesados / Alta Apertura' => '02 Seguimiento',
    '[Respuestas] Manejo de Objeciones' => '03 Respuestas',
    '[WhatsApp] Primer Contacto' => '',
];
$mapaCategorias = array_merge($renomCategorias, $renomIntermedios);
foreach ($mapaCategorias as $vieja => $nueva) {
    $stmt = $db->prepare("UPDATE plantillas SET categoria = :nueva WHERE categoria = :vieja");
    $stmt->bindValue(':nueva', $nueva, SQLITE3_TEXT);
    $stmt->bindValue(':vieja', $vieja, SQLITE3_TEXT);
    $stmt->execute();
    if ($db->changes() > 0) {
        echo "   Categoría '{$vieja}' → " . ($nueva === '' ? "(sin categoría)" : "'{$nueva}'") . " (" . $db->changes() . " plantilla(s))\n";
    }
}

// 3) Renombrar nombres legacy → convención "Paso N / objetivo" (no pisa ediciones del usuario).
$renomPlantillas = [
    '01 Prospeccion (abc - texto plano)'     => 'Prospección - Paso 1 - Test ABC (Dolor/Beneficio)',
    'Si la respuesta es un "Sí" simple'      => 'Respuesta - Sí simple - Siguiente paso',
    'Objecion - Precio/Pedido Minimo V4.3'   => 'Respuesta - Objeción Precio/Pedido Mínimo',
    'WhatsApp - Saludo V4.3'                 => 'WhatsApp - Saludo Primer Contacto',
];
foreach ($renomPlantillas as $antiguo => $nuevo) {
    $stmt = $db->prepare("UPDATE plantillas SET nombre = :nuevo WHERE nombre = :antiguo");
    $stmt->bindValue(':nuevo', $nuevo, SQLITE3_TEXT);
    $stmt->bindValue(':antiguo', $antiguo, SQLITE3_TEXT);
    $stmt->execute();
    if ($db->changes() > 0) {
        echo "   Plantilla '{$antiguo}' → '{$nuevo}'\n";
    }
}

// 4) Mover "Respuesta - Sí simple" a la categoría de Respuestas (estaba en Seguimiento).
$stmt = $db->prepare("UPDATE plantillas SET categoria = '03 Respuestas' WHERE nombre = :n AND categoria = '02 Seguimiento'");
$stmt->bindValue(':n', 'Respuesta - Sí simple - Siguiente paso', SQLITE3_TEXT);
$stmt->execute();
if ($db->changes() > 0) {
    echo "   Movida 'Respuesta - Sí simple' → '03 Respuestas'\n";
}

// 4) Crear "Seguimiento - Paso 2 - Recordatorio corto (48h)" si no existe.
$nombrePaso2 = 'Seguimiento - Paso 2 - Recordatorio corto (48h)';
$existePaso2 = (int)$db->querySingle("SELECT COUNT(*) FROM plantillas WHERE nombre = '" . $db->escapeString($nombrePaso2) . "'");
if ($existePaso2 === 0) {
    $cuerpoPaso2 = '<p>Hola, equipo de {{CLUB}}:</p>'
        . '<p>Te escribía por si querías echarle un vistazo a nuestras <strong>espinilleras personalizadas</strong> para {{CLUB}}. ¿Te parece bien que te pase el catálogo con los precios para vuestro volumen?</p>'
        . '<p>Solo te llevará un minuto y sin compromiso.</p>'
        . '<p>Un saludo,<br>{{CONTACTO}}<br>Equipo FutProtec<br><a href="https://getfutprotec.com">https://getfutprotec.com</a></p>';
    $stmt = $db->prepare(
        "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo, test_ab, fecha_creacion)
         VALUES (:n, :a, :c, 'html', '02 Seguimiento', 1, 0, CURRENT_TIMESTAMP)"
    );
    $stmt->bindValue(':n', $nombrePaso2, SQLITE3_TEXT);
    $stmt->bindValue(':a', '¿Te llegó bien la información de {{CLUB}}?', SQLITE3_TEXT);
    $stmt->bindValue(':c', $cuerpoPaso2, SQLITE3_TEXT);
    $stmt->execute();
    echo "   Plantilla creada: {$nombrePaso2}\n";
} else {
    echo "   Plantilla '{$nombrePaso2}' ya existe (sin cambios)\n";
}

// 5) Crear "Seguimiento Caliente - Paso 3 (Alta Apertura)" si no existe.
$nombreCaliente = 'Seguimiento Caliente - Paso 3 (Alta Apertura)';
$existeCaliente = (int)$db->querySingle("SELECT COUNT(*) FROM plantillas WHERE nombre = '" . $db->escapeString($nombreCaliente) . "'");
if ($existeCaliente === 0) {
    $cuerpoCaliente = '<p>Hola, equipo de {{CLUB}}:</p>'
        . '<p>Vi que le echaron un ojo a las espinilleras personalizadas que les envié hace unos días. Para no hacerles perder tiempo, ¿les gustaría que les prepare un <strong>boceto digital rápido y gratuito</strong> con el escudo de su club para ver cómo quedarían?</p>'
        . '<p>Solo necesito que me confirmen si el logo de su web es el correcto. ¿Les cuadra?</p>'
        . '<p>Un saludo,<br>{{CONTACTO}}<br>Equipo FutProtec<br><a href="https://getfutprotec.com">https://getfutprotec.com</a></p>';
    $stmt = $db->prepare(
        "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo, test_ab, fecha_creacion)
         VALUES (:n, :a, :c, 'html', '02 Seguimiento', 1, 0, CURRENT_TIMESTAMP)"
    );
    $stmt->bindValue(':n', $nombreCaliente, SQLITE3_TEXT);
    $stmt->bindValue(':a', 'Un detalle rápido para {{CLUB}}', SQLITE3_TEXT);
    $stmt->bindValue(':c', $cuerpoCaliente, SQLITE3_TEXT);
    $stmt->execute();
    echo "   Plantilla creada: {$nombreCaliente}\n";
} else {
    echo "   Plantilla '{$nombreCaliente}' ya existe (sin cambios)\n";
}

echo "OK\n";

