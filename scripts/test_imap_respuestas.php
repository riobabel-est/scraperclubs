<?php
/**
 * test_imap_respuestas.php — Test unitario del módulo IMAP de respuestas (FASE F)
 * ==============================================================================
 * Valida las funciones de parsing, clasificación y atribución con datos simulados,
 * SIN tocar producción ni la BD real.
 *
 * Uso: php scripts/test_imap_respuestas.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../public_html/outbound/inc/imap_respuestas.php';

$pasos = 0;
$fallos = 0;

function check(string $nombre, bool $condicion): void
{
    global $pasos, $fallos;
    $pasos++;
    if ($condicion) {
        echo "  ✅ {$nombre}\n";
    } else {
        $fallos++;
        echo "  ❌ {$nombre}\n";
    }
}

echo "=== TEST 1: Parsing de mensaje ===\n";
$raw = "Message-ID: <resp123@club.com>\r\n"
    . "In-Reply-To: <futprotec-abc123@getfutprotec.com>\r\n"
    . "References: <futprotec-abc123@getfutprotec.com>\r\n"
    . "From: \"Club A.D. XYZ\" <info@adxyz.com>\r\n"
    . "To: rodrigo@getfutprotec.com\r\n"
    . "Subject: Re: Piloto Comercial FutProtec\r\n"
    . "Date: Mon, 18 Aug 2026 10:14:00 +0200\r\n"
    . "\r\n"
    . "Hola, nos interesa el presupuesto. Saludos.";

$msg = imap_parsear_mensaje($raw);
check('message_id extraído', $msg['message_id'] === '<resp123@club.com>');
check('in_reply_to extraído', $msg['in_reply_to'] === '<futprotec-abc123@getfutprotec.com>');
check('from_email extraído', $msg['from_email'] === 'info@adxyz.com');
check('to_email extraído', $msg['to_email'] === 'rodrigo@getfutprotec.com');
check('subject extraído', $msg['subject'] === 'Re: Piloto Comercial FutProtec');
check('cuerpo extraído', strpos($msg['cuerpo'], 'presupuesto') !== false);

echo "\n=== TEST 2: Clasificación ===\n";
check('humana (con In-Reply-To)', imap_clasificar($msg) === 'humana');

$rebote = imap_parsear_mensaje("From: Mail Delivery System <MAILER-DAEMON@server.com>\r\nSubject: Delivery Status Notification\r\n\r\nbounce");
check('rebote', imap_clasificar($rebote) === 'rebote');

$baja = imap_parsear_mensaje("From: info@club.com\r\nSubject: Unsubscribe\r\nIn-Reply-To: <x>\r\n\r\nbaja");
check('baja', imap_clasificar($baja) === 'baja');

$ooo = imap_parsear_mensaje("From: info@club.com\r\nSubject: Out of Office\r\nIn-Reply-To: <x>\r\n\r\nvacaciones");
check('fuera de oficina', imap_clasificar($ooo) === 'fuera_de_oficina');

$auto = imap_parsear_mensaje("From: info@club.com\r\nSubject: Automatic Reply\r\nIn-Reply-To: <x>\r\n\r\nauto");
check('automática', imap_clasificar($auto) === 'automatica');

$sinRef = imap_parsear_mensaje("From: info@club.com\r\nSubject: Hola\r\n\r\nmensaje sin referencia");
check('desconocida (sin In-Reply-To)', imap_clasificar($sinRef) === 'desconocida');

echo "\n=== TEST 3: Atribución (BD temporal) ===\n";
// Crear BD temporal en memoria
$db = new SQLite3(':memory:');
$db->exec("CREATE TABLE envios (
    id INTEGER PRIMARY KEY,
    club TEXT, email TEXT, federacion TEXT, cuenta_emision TEXT,
    fecha_envio DATETIME, estado TEXT, tracking_id TEXT, asunto TEXT, cuerpo_mensaje TEXT,
    lead_id INTEGER, campaign_id INTEGER, variant VARCHAR(1), plantilla_id INTEGER,
    smtp_id INTEGER, message_id TEXT, resultado_envio TEXT, fecha_resultado_envio DATETIME, es_test INTEGER
)");
$db->exec("INSERT INTO envios (id, club, email, estado, lead_id, campaign_id, variant, smtp_id, message_id, es_test)
           VALUES (1, 'A.D. XYZ', 'info@adxyz.com', 'enviado', 42, 2, 'B', 1, '<futprotec-abc123@getfutprotec.com>', 0)");

// Atribución por In-Reply-To
$envio = imap_atribuir($db, $msg);
check('atribución por In-Reply-To', $envio !== null && $envio['id'] === 1 && $envio['lead_id'] === 42 && $envio['campaign_id'] === 2);

// Atribución por email remitente (sin In-Reply-To)
$msg2 = imap_parsear_mensaje("From: info@adxyz.com\r\nSubject: Hola\r\n\r\nmensaje");
$envio2 = imap_atribuir($db, $msg2);
check('atribución por email remitente', $envio2 !== null && $envio2['id'] === 1);

// Sin atribución
$msg3 = imap_parsear_mensaje("From: otra@empresa.com\r\nSubject: Hola\r\n\r\nmensaje");
$envio3 = imap_atribuir($db, $msg3);
check('sin atribución', $envio3 === null);

echo "\n=== TEST 4: Idempotencia y campos nuevos ===\n";
$db->exec("CREATE TABLE respuestas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    envio_id INTEGER, fecha_respuesta DATETIME, remitente TEXT, destinatario TEXT,
    subject TEXT, cuerpo TEXT, message_id TEXT, in_reply_to TEXT, \"references\" TEXT,
    clasificacion TEXT, fecha_clasificacion DATETIME, estado_procesamiento TEXT, creado_el DATETIME
)");
$db->exec("CREATE TABLE comunicaciones_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, club_id INTEGER,
    tipo_evento VARCHAR(50), plantilla_id INTEGER, detalles TEXT, ip_registro VARCHAR(45),
    fecha DATETIME, id_cuenta_smtp INTEGER, tipo VARCHAR(20), resultado TEXT,
    codigo_error TEXT, variante_ab VARCHAR(1), pipeline_id INTEGER, resumen TEXT,
    proxima_accion TEXT, canal VARCHAR(20)
)");
$db->exec("CREATE TABLE clubes_crm (
    id INTEGER PRIMARY KEY, nombre_club TEXT, email TEXT, estado_lead TEXT,
    ultimo_contacto DATETIME, observaciones TEXT
)");
$db->exec("INSERT INTO clubes_crm (id, nombre_club, email, estado_lead) VALUES (42, 'A.D. XYZ', 'info@adxyz.com', '02 Contactado')");

// Primera inserción con UID y cuenta (respuesta humana → debe mover Kanban)
$r1 = imap_registrar_respuesta($db, $msg, $envio, 'humana', 'INBOX', '12345', 'rodrigo@getfutprotec.com');
check('primera inserción', $r1 === 'insertado');

// Verificar campos nuevos guardados
$row = $db->querySingle("SELECT * FROM respuestas WHERE id = 1", true);
check('lead_id guardado', (int)$row['lead_id'] === 42);
check('campaign_id guardado', (int)$row['campaign_id'] === 2);
check('id_cuenta_smtp guardado', (int)$row['id_cuenta_smtp'] === 1);
check('message_id_original guardado', $row['message_id_original'] === '<futprotec-abc123@getfutprotec.com>');
check('uid_imap guardado', $row['uid_imap'] === '12345');
check('cuenta_uid guardado', $row['cuenta_uid'] === 'rodrigo@getfutprotec.com:12345');
check('hash_auxiliar guardado', $row['hash_auxiliar'] !== '');
check('carpeta guardada', $row['carpeta'] === 'INBOX');
check('notificado = 1 (respuesta humana)', (int)$row['notificado'] === 1);
check('kanban_movido = 1 (respuesta humana)', (int)$row['kanban_movido'] === 1);

// Kanban: respuesta humana movió el lead a '03 Respondió'
$estadoLead = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = 42");
check('Kanban movido a 03 Respondió', $estadoLead === '03 Respondió');

// Notificación FASE G: evento notificacion_respuesta registrado
$notifCount = $db->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'notificacion_respuesta'");
check('1 evento de notificación', $notifCount === 1);

// Idempotencia por Message-ID
$r2 = imap_registrar_respuesta($db, $msg, $envio, 'humana', 'INBOX', '12345', 'rodrigo@getfutprotec.com');
check('segunda inserción es duplicado (Message-ID)', $r2 === 'duplicado');

// Idempotencia por UID IMAP (mismo UID, distinto message_id)
$msgUid = imap_parsear_mensaje("Message-ID: <otro@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Re: Piloto\r\n\r\notro");
$r3 = imap_registrar_respuesta($db, $msgUid, $envio, 'humana', 'INBOX', '12345', 'rodrigo@getfutprotec.com');
check('duplicado por UID IMAP', $r3 === 'duplicado');

// Idempotencia por cuenta+UID (misma cuenta+UID, distinto message_id)
$msgCu = imap_parsear_mensaje("Message-ID: <otro2@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Re: Piloto\r\n\r\notro2");
$r4 = imap_registrar_respuesta($db, $msgCu, $envio, 'humana', 'INBOX', '99999', 'rodrigo@getfutprotec.com');
check('insertado con UID distinto', $r4 === 'insertado');
$r5 = imap_registrar_respuesta($db, $msgCu, $envio, 'humana', 'INBOX', '99999', 'rodrigo@getfutprotec.com');
check('duplicado por cuenta+UID', $r5 === 'duplicado');

// Idempotencia por hash auxiliar (mismo message_id+from+subject, sin UID)
$msgHash = imap_parsear_mensaje("Message-ID: <otro3@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Re: Piloto\r\n\r\nhash");
$r6 = imap_registrar_respuesta($db, $msgHash, $envio, 'humana', 'INBOX');
check('insertado sin UID', $r6 === 'insertado');
$r7 = imap_registrar_respuesta($db, $msgHash, $envio, 'humana', 'INBOX');
check('duplicado por hash auxiliar', $r7 === 'duplicado');

$count = $db->querySingle("SELECT COUNT(*) FROM respuestas");
check('total filas en respuestas', $count === 3);

$logCount = $db->querySingle("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'respuesta_recibida'");
check('3 eventos en comunicaciones_log', $logCount === 3);

echo "\n=== TEST 5: Kanban — respuesta automática NO mueve ===\n";
// Resetear lead a '02 Contactado'
$db->exec("UPDATE clubes_crm SET estado_lead = '02 Contactado' WHERE id = 42");
// Respuesta automática
$msgAuto = imap_parsear_mensaje("Message-ID: <auto@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Automatic Reply\r\n\r\nauto");
$rAuto = imap_registrar_respuesta($db, $msgAuto, $envio, 'automatica', 'INBOX', '77777', 'rodrigo@getfutprotec.com');
check('respuesta automática insertada', $rAuto === 'insertado');
$estadoAuto = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = 42");
check('Kanban NO movido (automática)', $estadoAuto === '02 Contactado');
$kanbanAuto = $db->querySingle("SELECT kanban_movido FROM respuestas WHERE id = 5");
check('kanban_movido = 0 (automática)', (int)$kanbanAuto === 0);

echo "\n=== TEST 6: Kanban — protección opt-out real ===\n";
// Lead en estado de baja real
$db->exec("UPDATE clubes_crm SET estado_lead = 'Opt-Out', observaciones = '[BAJA] fuente=email' WHERE id = 42");
$msgHumana = imap_parsear_mensaje("Message-ID: <humana2@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Re: Piloto\r\n\r\ninteresado");
$rHumana = imap_registrar_respuesta($db, $msgHumana, $envio, 'humana', 'INBOX', '88888', 'rodrigo@getfutprotec.com');
check('respuesta humana insertada', $rHumana === 'insertado');
$estadoOptOut = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = 42");
check('Kanban NO reactiva opt-out real', $estadoOptOut === 'Opt-Out');

echo "\n=== TEST 7: Kanban — lead ya en etapa posterior ===\n";
$db->exec("UPDATE clubes_crm SET estado_lead = '04 Interesado', observaciones = '' WHERE id = 42");
$msgPosterior = imap_parsear_mensaje("Message-ID: <posterior@club.com>\r\nIn-Reply-To: <futprotec-abc123@getfutprotec.com>\r\nFrom: info@adxyz.com\r\nSubject: Re: Piloto\r\n\r\nposterior");
$rPosterior = imap_registrar_respuesta($db, $msgPosterior, $envio, 'humana', 'INBOX', '88889', 'rodrigo@getfutprotec.com');
check('respuesta insertada', $rPosterior === 'insertado');
$estadoPosterior = $db->querySingle("SELECT estado_lead FROM clubes_crm WHERE id = 42");
check('Kanban NO retrocede (ya en 04)', $estadoPosterior === '04 Interesado');

$db->close();

echo "\n=== RESUMEN ===\n";
echo "Pasos: {$pasos}, Fallos: {$fallos}\n";
exit($fallos === 0 ? 0 : 1);
