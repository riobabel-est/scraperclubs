<?php
/**
 * init_db.php — Configura la base de datos SQLite para el CRM Kanban FutProtec v2.0.
 * Crea tablas: envios, aperturas, rebotes, clubes_crm, config, plantillas.
 * Migra contactos desde clubes.json + CSV con clasificación telefónica.
 *
 * Uso: php init_db.php                    # Inicializa/Migra
 *      php init_db.php --migrate-contacts  # Fuerza migración desde CSV
 *
 * PHP 8.x nativo — SiteGround compatible.
 */

declare(strict_types=1);

$dbPath = __DIR__ . '/../data/stats.db';
$clubesJson = __DIR__ . '/../../clubes.json';
$csvContactos = __DIR__ . '/../../output/clean/contactos_sintaxis_ok.csv';

$forceMigrate = in_array('--migrate-contacts', $argv ?? [], true);

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA foreign_keys=ON');

    echo "══════════════════════════════════════════════\n";
    echo "  FutProtec — Init DB CRM Kanban v2.0\n";
    echo "══════════════════════════════════════════════\n\n";

    // ─────────────────────────────────────────────────────────────────────
    // 1. TABLAS EXISTENTES (envios, aperturas, rebotes)
    // ─────────────────────────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS envios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            club TEXT NOT NULL,
            email TEXT NOT NULL,
            federacion TEXT DEFAULT '',
            cuenta_emision TEXT DEFAULT '',
            fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
            estado TEXT DEFAULT 'pendiente',
            tracking_id TEXT UNIQUE NOT NULL,
            asunto TEXT DEFAULT '',
            cuerpo_mensaje TEXT DEFAULT ''
        )
    ");

    // Migracion: anadir columnas si no existen
    $cols = [];
    $res = $db->query("PRAGMA table_info(envios)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    if (!in_array('asunto', $cols, true)) {
        $db->exec("ALTER TABLE envios ADD COLUMN asunto TEXT DEFAULT ''");
        echo "   Migracion: columna 'asunto' anadida a envios\n";
    }
    if (!in_array('cuerpo_mensaje', $cols, true)) {
        $db->exec("ALTER TABLE envios ADD COLUMN cuerpo_mensaje TEXT DEFAULT ''");
        echo "   Migracion: columna 'cuerpo_mensaje' anadida a envios\n";
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS aperturas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tracking_id TEXT NOT NULL,
            fecha_apertura DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            FOREIGN KEY (tracking_id) REFERENCES envios(tracking_id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS rebotes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            motivo TEXT DEFAULT '',
            fecha_rebote DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // ─────────────────────────────────────────────────────────────────────
    // 2. NUEVAS TABLAS CRM KANBAN v2.0
    // ─────────────────────────────────────────────────────────────────────

    // Tabla ampliada de leads / clubes para CRM Kanban
    $db->exec("
        CREATE TABLE IF NOT EXISTS clubes_crm (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre_club TEXT NOT NULL,
            federacion TEXT DEFAULT '',
            persona_contacto TEXT DEFAULT '',
            cargo_contacto TEXT DEFAULT '',
            email TEXT UNIQUE NOT NULL,
            telefono_fijo TEXT DEFAULT '',
            telefono_movil TEXT DEFAULT '',
            tiene_whatsapp INTEGER DEFAULT 0,
            estado_lead TEXT DEFAULT 'Sin Contactar',
            observaciones TEXT DEFAULT '',
            ultimo_contacto DATETIME,
            creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
            es_duplicado INTEGER DEFAULT 0,
            duplicado_id INTEGER DEFAULT NULL
        )
    ");

    // Migracion: anadir columnas de duplicados si no existen
    $colsCrm = [];
    $resCrm = $db->query("PRAGMA table_info(clubes_crm)");
    while ($r = $resCrm->fetchArray(SQLITE3_ASSOC)) {
        $colsCrm[] = $r['name'];
    }
    if (!in_array('es_duplicado', $colsCrm, true)) {
        $db->exec("ALTER TABLE clubes_crm ADD COLUMN es_duplicado INTEGER DEFAULT 0");
        echo "   Migracion CRM: columna 'es_duplicado' anadida\n";
    }
    if (!in_array('duplicado_id', $colsCrm, true)) {
        $db->exec("ALTER TABLE clubes_crm ADD COLUMN duplicado_id INTEGER DEFAULT NULL");
        echo "   Migracion CRM: columna 'duplicado_id' anadida\n";
    }

    // Migracion: anadir campos de datos fisicos (solo se rellenan al comprar)
    $camposFisicos = [
        'direccion'          => "ALTER TABLE clubes_crm ADD COLUMN direccion TEXT DEFAULT ''",
        'cp'                 => "ALTER TABLE clubes_crm ADD COLUMN cp TEXT DEFAULT ''",
        'ciudad'             => "ALTER TABLE clubes_crm ADD COLUMN ciudad TEXT DEFAULT ''",
        'provincia'          => "ALTER TABLE clubes_crm ADD COLUMN provincia TEXT DEFAULT ''",
        'cif'                => "ALTER TABLE clubes_crm ADD COLUMN cif TEXT DEFAULT ''",
        'contacto_facturacion' => "ALTER TABLE clubes_crm ADD COLUMN contacto_facturacion TEXT DEFAULT ''",
    ];
    foreach ($camposFisicos as $campo => $sql) {
        if (!in_array($campo, $colsCrm, true)) {
            $db->exec($sql);
            echo "   Migracion CRM: columna '{$campo}' anadida\n";
        }
    }

    // Migracion: agenda de proxima accion (P1 del estudio CRM moderno).
    // Fecha limite para la proxima accion del lead; alimenta la cola "Avanzar".
    if (!in_array('fecha_proxima_accion', $colsCrm, true)) {
        $db->exec("ALTER TABLE clubes_crm ADD COLUMN fecha_proxima_accion DATETIME DEFAULT NULL");
        echo "   Migracion CRM: columna 'fecha_proxima_accion' anadida\n";
    }

    // Tabla: propuestas del Asistente IA (human-in-the-loop).
    $db->exec("CREATE TABLE IF NOT EXISTS propuestas_ia (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_id INTEGER NOT NULL,
        campaign_id INTEGER,
        tipo TEXT NOT NULL,
        titulo TEXT DEFAULT '',
        razon TEXT DEFAULT '',
        mensaje_sugerido TEXT DEFAULT '',
        prioridad TEXT DEFAULT 'Media',
        estado TEXT DEFAULT 'pendiente',
        creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
        aprobado_el DATETIME,
        voto TEXT DEFAULT ''
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_propuestas_estado ON propuestas_ia(estado)");
    // Ciclo de vida: fecha prevista de cada recomendación (posponer/vencer).
    $colsProp = [];
    $r = $db->query("PRAGMA table_info(propuestas_ia)");
    if ($r) { while ($x = $r->fetchArray(SQLITE3_ASSOC)) $colsProp[] = $x['name']; }
    if (!in_array('fecha_prevista', $colsProp, true)) {
        // SQLite solo admite DEFAULT constante en ALTER ADD COLUMN.
        $db->exec("ALTER TABLE propuestas_ia ADD COLUMN fecha_prevista DATETIME DEFAULT '2000-01-01 00:00:00'");
        echo "   Migracion propuestas_ia: columna 'fecha_prevista' anadida\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2b. TABLAS ESCALABLES: CONTACTOS Y TELEFONOS DEL CLUB
    // Modelo empresa-contacto: un club (clubes_crm) puede tener N contactos
    // (contactos_club) y N telefonos (telefonos_club). Cada telefono guarda su
    // propio estado WhatsApp y el nombre de la persona a la que pertenece.
    // ─────────────────────────────────────────────────────────────────────

    // Contactos del club (personas: presidente, delegado, encargado material...)
    $db->exec("
        CREATE TABLE IF NOT EXISTS contactos_club (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            club_id INTEGER NOT NULL,
            nombre TEXT DEFAULT '',
            cargo TEXT DEFAULT '',
            email_contacto TEXT DEFAULT '',
            telefono TEXT DEFAULT '',
            es_principal INTEGER DEFAULT 0,
            activo INTEGER DEFAULT 1,
            creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (club_id) REFERENCES clubes_crm(id)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contactos_club ON contactos_club(club_id)");

    // Telefonos del club (una fila por numero, escalable)
    $db->exec("
        CREATE TABLE IF NOT EXISTS telefonos_club (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            club_id INTEGER NOT NULL,
            numero TEXT NOT NULL,
            tipo VARCHAR(10) DEFAULT 'movil',
            tiene_whatsapp INTEGER DEFAULT 0,
            whatsapp_verificado INTEGER DEFAULT 0,
            es_principal INTEGER DEFAULT 0,
            nombre_contacto TEXT DEFAULT '',
            notas TEXT DEFAULT '',
            creado_el DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (club_id) REFERENCES clubes_crm(id)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_telefonos_club ON telefonos_club(club_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_telefonos_numero ON telefonos_club(numero)");

    // Tabla de cuentas SMTP para rotacion dinamica

    $db->exec("
        CREATE TABLE IF NOT EXISTS cuentas_smtp (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            host TEXT NOT NULL DEFAULT 'mail.getfutprotec.com',
            puerto INTEGER NOT NULL DEFAULT 465,
            usuario TEXT NOT NULL,
            password TEXT NOT NULL,
            seguridad TEXT DEFAULT 'ssl',
            activa INTEGER DEFAULT 1,
            limite_diario INTEGER DEFAULT 50,
            enviados_hoy INTEGER DEFAULT 0,
            ultimo_error TEXT DEFAULT NULL,
            ultimo_uso DATETIME DEFAULT NULL
        )
    ");

    // Migrar cuentas hardcodeadas a la tabla si esta vacia
    // E-1 (2026-08-27): SE ELIMINARON las credenciales SMTP en claro que había aquí.
    // Las cuentas se dan de alta y se les configuran usuario/password desde la UI
    // (tab "Ajustes → SMTP"). En producción la tabla ya está poblada con las
    // credenciales cifradas FP1: (ver inc/crypto.php), por lo que este bootstrap
    // SOLO aplica a entornos nuevos con la tabla vacía.
    $countSMTP = (int)$db->querySingle("SELECT COUNT(*) FROM cuentas_smtp");
    if ($countSMTP === 0) {
        $cuentasDefault = [
            ['rodrigo@getfutprotec.com', 'Rodrigo Vazquez | FutProtec'],
            ['mario.ortiz@getfutprotec.com', 'Mario Ortiz | Area de Clubes FutProtec'],
            ['alvaro.ruiz@getfutprotec.com', 'Alvaro Ruiz | Equipamiento FutProtec'],
            ['carlos.mora@getfutprotec.com', 'Carlos Mora | Proyectos Cantera FutProtec'],
            ['javier.sanz@getfutprotec.com', 'Javier Sanz | At. Clubes FutProtec'],
            ['diego.navarro@getfutprotec.com', 'Diego Navarro | Equipaciones FutProtec'],
            ['pablo.blanco@getfutprotec.com', 'Pablo Blanco | FutProtec Oficial'],
            ['gonzalo.vega@getfutprotec.com', 'Gonzalo Vega | Gestion Deportivo FutProtec'],
            ['adrian.cano@getfutprotec.com', 'Adrian Cano | FutProtec Canteras'],
            ['sergio.gil@getfutprotec.com', 'Sergio Gil | Relaciones Clubes FutProtec'],
        ];
        $stmtSMTP = $db->prepare(
            'INSERT INTO cuentas_smtp (email, usuario, password, host, puerto, seguridad, activa, limite_diario)
             VALUES (:email, :user, :pass, :host, :port, :sec, 1, 50)'
        );
        foreach ($cuentasDefault as $c) {
            $stmtSMTP->bindValue(':email', $c[0], SQLITE3_TEXT);
            $stmtSMTP->bindValue(':user', $c[0], SQLITE3_TEXT);
            // E-1: sin credenciales en el repo; se configuran por la UI (tab SMTP).
            $stmtSMTP->bindValue(':pass', '', SQLITE3_TEXT);
            $stmtSMTP->bindValue(':host', 'mail.getfutprotec.com', SQLITE3_TEXT);
            $stmtSMTP->bindValue(':port', 465, SQLITE3_INTEGER);
            $stmtSMTP->bindValue(':sec', 'ssl', SQLITE3_TEXT);
            $stmtSMTP->execute();
            $stmtSMTP->reset();
        }
        echo "   Cuentas SMTP creadas (sin credenciales, configúralas por la UI): " . count($cuentasDefault) . "\n";
    }

    // Tabla de configuracion global (motor y entornos)
    $db->exec("
        CREATE TABLE IF NOT EXISTS config (
            clave TEXT PRIMARY KEY,
            valor TEXT
        )
    ");

    // Insertar defaults de configuracion si no existen
    $stmt = $db->prepare('INSERT OR IGNORE INTO config (clave, valor) VALUES (:k, :v)');
    $defaults = [
        'motor_estado'     => 'pausado',
        'modo_entorno'     => 'test',
        'email_test'       => 'contactofutprotec@gmail.com',
        'delay_envio'      => '3',
        'lote_envio'       => '10',
    ];
    foreach ($defaults as $k => $v) {
        $stmt->bindValue(':k', $k, SQLITE3_TEXT);
        $stmt->bindValue(':v', $v, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }

    // Tabla de plantillas de email y WhatsApp editables (ampliada)
    $db->exec("
        CREATE TABLE IF NOT EXISTS plantillas_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre VARCHAR(100) NOT NULL,
            asunto VARCHAR(255) DEFAULT '',
            cuerpo TEXT NOT NULL,
            tipo VARCHAR(20) DEFAULT 'html',
            categoria VARCHAR(50) DEFAULT 'prospeccion',
            activo INTEGER DEFAULT 1,
            fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Migrar plantillas viejas a la nueva tabla si es necesario
    $colTipo = [];
    $resCheck = $db->query("PRAGMA table_info(plantillas)");
    while ($r = $resCheck->fetchArray(SQLITE3_ASSOC)) {
        $colTipo[] = $r['name'];
    }
    $needsMigration = !in_array('tipo', $colTipo, true);

    if ($needsMigration) {
        // Crear tabla nueva y migrar datos
        $db->exec("
            CREATE TABLE IF NOT EXISTS plantillas_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre VARCHAR(100) NOT NULL,
                asunto VARCHAR(255) DEFAULT '',
                cuerpo TEXT NOT NULL,
                tipo VARCHAR(20) DEFAULT 'html',
                categoria VARCHAR(50) DEFAULT 'prospeccion',
                activo INTEGER DEFAULT 1,
                fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Migrar datos existentes
        $resOld = $db->query("SELECT id, nombre, asunto, cuerpo_html FROM plantillas WHERE activo=1");
        $migrados = 0;
        while ($row = $resOld->fetchArray(SQLITE3_ASSOC)) {
            $stmtMig = $db->prepare(
                "INSERT OR IGNORE INTO plantillas_new (id, nombre, asunto, cuerpo, tipo, categoria)
                 VALUES (:id, :n, :a, :c, 'html', 'prospeccion')"
            );
            $stmtMig->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $stmtMig->bindValue(':n', $row['nombre'], SQLITE3_TEXT);
            $stmtMig->bindValue(':a', $row['asunto'], SQLITE3_TEXT);
            $stmtMig->bindValue(':c', $row['cuerpo_html'], SQLITE3_TEXT);
            $stmtMig->execute();
            $migrados++;
        }
        // Reemplazar tabla vieja
        $db->exec("DROP TABLE IF EXISTS plantillas");
        $db->exec("ALTER TABLE plantillas_new RENAME TO plantillas");
        echo "   Plantillas migradas a nuevo esquema: {$migrados} registros\n";
    }

    // Tabla de registro de comunicaciones (tracking timeline)
    $db->exec("
        CREATE TABLE IF NOT EXISTS comunicaciones_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id INTEGER DEFAULT NULL,
            club_id INTEGER DEFAULT NULL,
            tipo_evento VARCHAR(50) NOT NULL,
            plantilla_id INTEGER DEFAULT NULL,
            detalles TEXT DEFAULT '',
            ip_registro VARCHAR(45) DEFAULT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comlog_lead ON comunicaciones_log(lead_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comlog_club ON comunicaciones_log(club_id)");

    // Migracion: anadir columnas para lanzadera outbound
    $colsComLog = [];
    $resComLog = $db->query("PRAGMA table_info(comunicaciones_log)");
    while ($r = $resComLog->fetchArray(SQLITE3_ASSOC)) {
        $colsComLog[] = $r['name'];
    }
    if (!in_array('id_cuenta_smtp', $colsComLog, true)) {
        $db->exec("ALTER TABLE comunicaciones_log ADD COLUMN id_cuenta_smtp INTEGER DEFAULT NULL");
        echo "   Migracion: columna 'id_cuenta_smtp' anadida a comunicaciones_log\n";
    }
    if (!in_array('tipo', $colsComLog, true)) {
        $db->exec("ALTER TABLE comunicaciones_log ADD COLUMN tipo VARCHAR(20) DEFAULT 'email'");
        echo "   Migracion: columna 'tipo' anadida a comunicaciones_log\n";
    }
    if (!in_array('resultado', $colsComLog, true)) {
        $db->exec("ALTER TABLE comunicaciones_log ADD COLUMN resultado TEXT DEFAULT ''");
        echo "   Migracion: columna 'resultado' anadida a comunicaciones_log\n";
    }
    if (!in_array('codigo_error', $colsComLog, true)) {
        $db->exec("ALTER TABLE comunicaciones_log ADD COLUMN codigo_error TEXT DEFAULT ''");
        echo "   Migracion: columna 'codigo_error' anadida a comunicaciones_log\n";
    }
    if (!in_array('variante_ab', $colsComLog, true)) {
        $db->exec("ALTER TABLE comunicaciones_log ADD COLUMN variante_ab VARCHAR(1) DEFAULT ''");
        echo "   Migracion: columna 'variante_ab' anadida a comunicaciones_log\n";
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comlog_cuenta ON comunicaciones_log(id_cuenta_smtp)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_comlog_fecha ON comunicaciones_log(fecha)");

    // Migracion: anadir columnas A/B Testing a plantillas
    $colsPlantillas = [];
    $resPlantillas = $db->query("PRAGMA table_info(plantillas)");
    while ($r = $resPlantillas->fetchArray(SQLITE3_ASSOC)) {
        $colsPlantillas[] = $r['name'];
    }
    if (!in_array('asunto_b', $colsPlantillas, true)) {
        $db->exec("ALTER TABLE plantillas ADD COLUMN asunto_b VARCHAR(255) DEFAULT ''");
        echo "   Migracion: columna 'asunto_b' anadida a plantillas\n";
    }
    if (!in_array('test_ab', $colsPlantillas, true)) {
        $db->exec("ALTER TABLE plantillas ADD COLUMN test_ab INTEGER DEFAULT 0");
        echo "   Migracion: columna 'test_ab' anadida a plantillas\n";
    }
    if (!in_array('asunto_c', $colsPlantillas, true)) {
        $db->exec("ALTER TABLE plantillas ADD COLUMN asunto_c VARCHAR(255) DEFAULT ''");
        echo "   Migracion: columna 'asunto_c' anadida a plantillas (A/B/C testing)\n";
    }

    // Migracion: anadir columnas de remitente dinamico a cuentas_smtp
    $colsSmtp = [];
    $resSmtp = $db->query("PRAGMA table_info(cuentas_smtp)");
    while ($r = $resSmtp->fetchArray(SQLITE3_ASSOC)) {
        $colsSmtp[] = $r['name'];
    }
    if (!in_array('nombre_emisor', $colsSmtp, true)) {
        $db->exec("ALTER TABLE cuentas_smtp ADD COLUMN nombre_emisor VARCHAR(100) DEFAULT ''");
        echo "   Migracion: columna 'nombre_emisor' anadida a cuentas_smtp\n";
    }
    if (!in_array('cargo_emisor', $colsSmtp, true)) {
        $db->exec("ALTER TABLE cuentas_smtp ADD COLUMN cargo_emisor VARCHAR(100) DEFAULT ''");
        echo "   Migracion: columna 'cargo_emisor' anadida a cuentas_smtp\n";
    }

    // Insertar plantillas preseed (4 presets) si no hay ninguna
    $countPlantillas = (int)$db->querySingle("SELECT COUNT(*) FROM plantillas");
    if ($countPlantillas === 0) {
        // ─────────────────────────────────────────────────────────────────
        // PRESET 1: Email 1 - Primer Contacto (Texto Plano) — prospeccion
        // ─────────────────────────────────────────────────────────────────
        $t1asunto = 'Espinilleras personalizadas para {{CLUB}} | FutProtec';
        $t1cuerpo = "Estimado/a responsable de {{CLUB}},\n\n".
            "Me presento: soy {{CONTACTO}} del equipo de FutProtec, especialistas en espinilleras y material de protección para fútbol base.\n\n".
            "Trabajamos con clubes de toda España ofreciendo espinilleras personalizadas con los colores y escudo de cada club, adaptadas a todas las categorías desde prebenjamín hasta juvenil.\n\n".
            "¿Te interesaría recibir nuestro catálogo sin compromiso para {{CLUB}}?\n\n".
            "Quedo a tu disposición para cualquier consulta. También podemos hablar por WhatsApp o teléfono si lo prefieres.\n\n".
            "Un cordial saludo,\n".
            "{{CONTACTO}}\n".
            "Equipo FutProtec\n".
            "https://getfutprotec.com";

        $stmt = $db->prepare(
            "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo)
             VALUES (:n, :a, :c, :t, :cat, 1)"
        );
        $stmt->bindValue(':n', 'Email 1 - Primer Contacto (Texto Plano)', SQLITE3_TEXT);
        $stmt->bindValue(':a', $t1asunto, SQLITE3_TEXT);
        $stmt->bindValue(':c', $t1cuerpo, SQLITE3_TEXT);
        $stmt->bindValue(':t', 'texto_plano', SQLITE3_TEXT);
        $stmt->bindValue(':cat', 'prospeccion', SQLITE3_TEXT);
        $stmt->execute();
        echo "   Plantilla preseed 1/4: Email 1 - Primer Contacto (Texto Plano)\n";

        // ─────────────────────────────────────────────────────────────────
        // PRESET 2: Email 2 - Presentación y Catálogo (HTML) — seguimiento
        // ─────────────────────────────────────────────────────────────────
        $t2asunto = 'Catálogo de espinilleras para {{CLUB}} | FutProtec';
        $t2cuerpo = <<<'EOT2'
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1a1a2e; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 30px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #e2b04a; margin: 0; font-size: 24px;">FutProtec</h1>
        <p style="color: #ffffff; margin: 10px 0 0 0; font-size: 16px;">Catálogo de Espinilleras Personalizadas</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px;">
        <p style="font-size: 15px; line-height: 1.6;">Hola {{CONTACTO}},</p>
        <p style="font-size: 15px; line-height: 1.6;">Tal como hablamos, aquí tienes nuestro <strong>catálogo completo</strong> de espinilleras personalizadas para el <strong>{{CLUB}}</strong>.</p>
        <div style="background: #f8f8f8; border-radius: 8px; padding: 20px; margin: 20px 0;">
            <h3 style="color: #1a1a2e; margin-top: 0;">Lo que incluye nuestro servicio:</h3>
            <ul style="font-size: 14px; line-height: 1.8; color: #333;">
                <li>✅ Diseño 100% personalizado con escudo y colores del club</li>
                <li>✅ Protección de alto impacto con certificación CE</li>
                <li>✅ Tallas desde prebenjamín hasta juvenil</li>
                <li>✅ Materiales ligeros, transpirables y lavables</li>
                <li>✅ Precios especiales por volumen para clubes federados</li>
                <li>✅ Envío incluido a península en pedidos superiores a 20 unidades</li>
            </ul>
        </div>
        <p style="font-size: 15px; line-height: 1.6;">Adjunto te envío el catálogo en PDF con todos los modelos, precios y tiempos de entrega.</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="https://getfutprotec.com/contacto" style="background: #e2b04a; color: #1a1a2e; padding: 14px 35px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">Solicitar Presupuesto Personalizado</a>
        </p>
        <p style="font-size: 13px; color: #777; margin-top: 30px;">Si tienes cualquier duda, estoy a tu disposición por email, teléfono o WhatsApp.</p>
        <p style="font-size: 13px; color: #999;">Un cordial saludo,<br><strong>{{CONTACTO}}</strong><br>Equipo FutProtec</p>
    </div>
    <div style="text-align: center; padding: 15px; font-size: 11px; color: #aaa;">
        <p>Este mensaje se envía de acuerdo con la legislación sobre protección de datos (RGPD y LOPDGDD).<br>
        Si no deseas recibir más comunicaciones, <a href="https://getfutprotec.com/outbound/api/baja.php?email={{EMAIL}}" style="color: #aaa;">solicita tu baja aquí</a>.</p>
        <p>{{ANIO}} FutProtec — Espinilleras Personalizadas</p>
    </div>
</body>
</html>
EOT2;

        $stmt2 = $db->prepare(
            "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo)
             VALUES (:n, :a, :c, :t, :cat, 1)"
        );
        $stmt2->bindValue(':n', 'Email 2 - Presentación y Catálogo (HTML)', SQLITE3_TEXT);
        $stmt2->bindValue(':a', $t2asunto, SQLITE3_TEXT);
        $stmt2->bindValue(':c', $t2cuerpo, SQLITE3_TEXT);
        $stmt2->bindValue(':t', 'html', SQLITE3_TEXT);
        $stmt2->bindValue(':cat', 'seguimiento', SQLITE3_TEXT);
        $stmt2->execute();
        echo "   Plantilla preseed 2/4: Email 2 - Presentación y Catálogo (HTML)\n";

        // ─────────────────────────────────────────────────────────────────
        // PRESET 3: Objeción - Sin Presupuesto Adelantado — respuesta_modelo
        // ─────────────────────────────────────────────────────────────────
        $t3asunto = 'Re: Presupuesto {{CLUB}} — Sin compromiso previo | FutProtec';
        $t3cuerpo = "Hola {{CONTACTO}},\n\n".
            "Gracias por tu respuesta. Entiendo completamente la preocupación sobre el presupuesto adelantado.\n\n".
            "Quería aclararte que en FutProtec trabajamos con varias opciones flexibles para clubes como {{CLUB}}:\n\n".
            "- No pedimos pago por adelantado: se factura contra entrega del material.\n".
            "- Ofrecemos descuentos por volumen a partir de 15 unidades.\n".
            "- Podemos enviar una muestra física sin coste para que valoréis la calidad.\n".
            "- Plazos de entrega de 10-15 días hábiles desde confirmación.\n\n".
            "Si te parece, puedo preparar un presupuesto orientativo sin compromiso para que lo reviséis con calma. ¿Cuántas espinilleras estimáis necesitar por temporada aproximadamente?\n\n".
            "Quedo a tu disposición. Un cordial saludo,\n".
            "{{CONTACTO}}\n".
            "Equipo FutProtec\n".
            "https://getfutprotec.com";

        $stmt3 = $db->prepare(
            "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo)
             VALUES (:n, :a, :c, :t, :cat, 1)"
        );
        $stmt3->bindValue(':n', 'Objeción - Sin Presupuesto Adelantado', SQLITE3_TEXT);
        $stmt3->bindValue(':a', $t3asunto, SQLITE3_TEXT);
        $stmt3->bindValue(':c', $t3cuerpo, SQLITE3_TEXT);
        $stmt3->bindValue(':t', 'texto_plano', SQLITE3_TEXT);
        $stmt3->bindValue(':cat', 'respuesta_modelo', SQLITE3_TEXT);
        $stmt3->execute();
        echo "   Plantilla preseed 3/4: Objeción - Sin Presupuesto Adelantado\n";

        // ─────────────────────────────────────────────────────────────────
        // PRESET 4: WA - Saludo Primer Contacto — whatsapp
        // ─────────────────────────────────────────────────────────────────
        $t4cuerpo = "👋 Hola {{CONTACTO}}, soy del equipo de FutProtec.\n\n".
            "Te escribo porque trabajamos con clubes de {{FEDERACION}} ofreciendo espinilleras personalizadas con los colores y escudo de cada equipo.\n\n".
            "¿Te interesaría que te enviara nuestro catálogo sin compromiso para {{CLUB}}?\n\n".
            "¡Gracias y buen día! ⚽";

        $stmt4 = $db->prepare(
            "INSERT INTO plantillas (nombre, asunto, cuerpo, tipo, categoria, activo)
             VALUES (:n, :a, :c, :t, :cat, 1)"
        );
        $stmt4->bindValue(':n', 'WA - Saludo Primer Contacto', SQLITE3_TEXT);
        $stmt4->bindValue(':a', '', SQLITE3_TEXT);
        $stmt4->bindValue(':c', $t4cuerpo, SQLITE3_TEXT);
        $stmt4->bindValue(':t', 'whatsapp', SQLITE3_TEXT);
        $stmt4->bindValue(':cat', 'whatsapp', SQLITE3_TEXT);
        $stmt4->execute();
        echo "   Plantilla preseed 4/4: WA - Saludo Primer Contacto\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. INDICES
    // ─────────────────────────────────────────────────────────────────────
    $db->exec("CREATE INDEX IF NOT EXISTS idx_envios_estado ON envios(estado)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_envios_cuenta ON envios(cuenta_emision)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_envios_tracking ON envios(tracking_id)");
    // Índice compuesto para acelerar el JOIN aperturas↔envios y el filtro de aislamiento
    // TEST/REAL (es_test=0) usado en la agregación de aperturas del Kanban.
    $db->exec("CREATE INDEX IF NOT EXISTS idx_envios_tracking_test ON envios(tracking_id, es_test)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_aperturas_tracking ON aperturas(tracking_id)");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_rebotes_email ON rebotes(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_crm_estado ON clubes_crm(estado_lead)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_crm_federacion ON clubes_crm(federacion)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_crm_email ON clubes_crm(email)");

    // ─────────────────────────────────────────────────────────────────────
    // 4. MIGRACION DE CONTACTOS CON CLASIFICACION TELEFONICA
    // ─────────────────────────────────────────────────────────────────────
    $countCRM = (int)$db->querySingle("SELECT COUNT(*) FROM clubes_crm");

    if ($countCRM === 0 || $forceMigrate) {
        echo "\n   Migrando contactos a clubes_crm...\n";

        // 4a. Cargar CSV con telefonos (indice por email)
        $csvPhones = [];
        if (file_exists($csvContactos)) {
            $fh = fopen($csvContactos, 'r');
            if ($fh) {
                fgetcsv($fh); // saltar cabecera
                while (($row = fgetcsv($fh)) !== false) {
                    if (count($row) < 4) continue;
                    $telefonoRaw   = trim($row[2] ?? '');
                    $emailCsv      = strtolower(trim($row[3] ?? ''));

                    if ($emailCsv === '' || !filter_var($emailCsv, FILTER_VALIDATE_EMAIL)) continue;

                    $parsed = parseAndClassifyPhones($telefonoRaw);
                    $csvPhones[$emailCsv] = $parsed;
                }
                fclose($fh);
                echo "   CSV procesado: " . count($csvPhones) . " contactos con telefonos\n";
            }
        } else {
            echo "   CSV de contactos no encontrado en: {$csvContactos}\n";
            echo "   Se migrara sin datos de telefono\n";
        }

        // 4b. Cargar clubes.json
        $clubesMigrados = 0;
        $clubesConTelefono = 0;

        if (file_exists($clubesJson)) {
            $clubes = json_decode(file_get_contents($clubesJson), true);
            if (is_array($clubes) && !empty($clubes)) {
                $stmtInsert = $db->prepare(
                    'INSERT INTO clubes_crm (nombre_club, federacion, email, telefono_movil, telefono_fijo, tiene_whatsapp, estado_lead)
                     VALUES (:nombre, :fed, :email, :movil, :fijo, :wa, :estado)'
                );

                foreach ($clubes as $club) {
                    $nombreClub = trim($club['club'] ?? '');
                    $federacion = trim($club['federacion'] ?? '');
                    $email      = strtolower(trim($club['email'] ?? ''));
                    $estado     = trim($club['estado'] ?? 'Sin Contactar');

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                    $movil = '';
                    $fijo  = '';
                    $wa    = 0;
                    if (isset($csvPhones[$email])) {
                        $phoneData = $csvPhones[$email];
                        $movil = !empty($phoneData['moviles']) ? implode(', ', $phoneData['moviles']) : '';
                        $fijo  = !empty($phoneData['fijos']) ? implode(', ', $phoneData['fijos']) : '';
                        // Asignar tiene_whatsapp = 1 automáticamente si hay móvil
                        $wa = ($movil !== '') ? 1 : 0;
                    }

                    $estadoLead = mapEstadoLegacy($estado, $email, $db);

                    try {
                        $stmtInsert->bindValue(':nombre', $nombreClub, SQLITE3_TEXT);
                        $stmtInsert->bindValue(':fed',    $federacion,  SQLITE3_TEXT);
                        $stmtInsert->bindValue(':email',  $email,       SQLITE3_TEXT);
                        $stmtInsert->bindValue(':movil',  $movil,       SQLITE3_TEXT);
                        $stmtInsert->bindValue(':fijo',   $fijo,        SQLITE3_TEXT);
                        $stmtInsert->bindValue(':wa',     $wa,          SQLITE3_INTEGER);
                        $stmtInsert->bindValue(':estado', $estadoLead,  SQLITE3_TEXT);
                        $stmtInsert->execute();
                        $clubesMigrados++;
                        if ($movil !== '' || $fijo !== '') {
                            $clubesConTelefono++;
                        }
                        $stmtInsert->reset();
                    } catch (\Exception $e) {
                        // Email duplicado: saltar (reset no es posible en estado invalido)
                        if (!str_contains($e->getMessage(), 'UNIQUE')) {
                            echo "   Error migrando '{$nombreClub}': " . $e->getMessage() . "\n";
                        }
                    }
                }
            }
        }

        echo "   Migrados: {$clubesMigrados} clubes a clubes_crm\n";
        echo "   Con telefonos: {$clubesConTelefono}\n";
    } else {
        echo "   clubes_crm ya tiene {$countCRM} registros. Usa --migrate-contacts para forzar re-migracion.\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4c. MIGRACION DE TELEFONOS EXISTENTES A telefonos_club
    // Puebla la tabla escalable telefonos_club a partir de los campos
    // legacy telefono_movil / telefono_fijo de clubes_crm (una fila por
    // numero). Es idempotente: solo inserta si el numero no existe ya para
    // ese club. Se ejecuta SIEMPRE (no solo con --migrate-contacts).
    // ─────────────────────────────────────────────────────────────────────
    $telefonosMigrados = 0;
    $resClubesTel = $db->query("SELECT id, telefono_movil, telefono_fijo, tiene_whatsapp FROM clubes_crm");
    $stmtTel = $db->prepare(
        "INSERT OR IGNORE INTO telefonos_club (club_id, numero, tipo, tiene_whatsapp, whatsapp_verificado, es_principal)
         VALUES (:club_id, :numero, :tipo, :wa, 0, :principal)"
    );
    while ($clubTel = $resClubesTel->fetchArray(SQLITE3_ASSOC)) {
        $clubId = (int)$clubTel['id'];
        $waClub = (int)$clubTel['tiene_whatsapp'];

        // Móviles: cada numero en su propia fila
        $moviles = array_filter(array_map('trim', explode(',', (string)$clubTel['telefono_movil'])));
        foreach ($moviles as $i => $num) {
            if ($num === '') continue;
            $stmtTel->bindValue(':club_id', $clubId, SQLITE3_INTEGER);
            $stmtTel->bindValue(':numero', $num, SQLITE3_TEXT);
            $stmtTel->bindValue(':tipo', 'movil', SQLITE3_TEXT);
            $stmtTel->bindValue(':wa', $waClub, SQLITE3_INTEGER);
            $stmtTel->bindValue(':principal', $i === 0 ? 1 : 0, SQLITE3_INTEGER);
            $stmtTel->execute();
            $telefonosMigrados++;
            $stmtTel->reset();
        }

        // Fijos: cada numero en su propia fila
        $fijos = array_filter(array_map('trim', explode(',', (string)$clubTel['telefono_fijo'])));
        foreach ($fijos as $i => $num) {
            if ($num === '') continue;
            $stmtTel->bindValue(':club_id', $clubId, SQLITE3_INTEGER);
            $stmtTel->bindValue(':numero', $num, SQLITE3_TEXT);
            $stmtTel->bindValue(':tipo', 'fijo', SQLITE3_TEXT);
            $stmtTel->bindValue(':wa', 0, SQLITE3_INTEGER);
            $stmtTel->bindValue(':principal', 0, SQLITE3_INTEGER);
            $stmtTel->execute();
            $telefonosMigrados++;
            $stmtTel->reset();
        }
    }
    if ($telefonosMigrados > 0) {
        echo "   Telefonos migrados a telefonos_club: {$telefonosMigrados}\n";
    }

    echo "\n══════════════════════════════════════════════\n";
    echo "  VERIFICACION DE TABLAS\n";
    echo "══════════════════════════════════════════════\n";

    $tables = ['envios', 'aperturas', 'rebotes', 'clubes_crm', 'config', 'plantillas', 'contactos_club', 'telefonos_club'];

    foreach ($tables as $table) {
        $cnt = (int)$db->querySingle("SELECT COUNT(*) FROM {$table}");
        echo "   {$table}: {$cnt} registros\n";
    }

    $resEstados = $db->query("SELECT estado_lead, COUNT(*) as cnt FROM clubes_crm GROUP BY estado_lead ORDER BY cnt DESC");
    echo "\n   Distribucion Kanban:\n";
    while ($row = $resEstados->fetchArray(SQLITE3_ASSOC)) {
        echo "      {$row['estado_lead']}: {$row['cnt']}\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. REORGANIZACIÓN DE PLANTILLAS POR FUNCIÓN/OBJETIVO (asesor 2026-08-26)
    //    Categorías finales numeradas:
    //      01 Prospección · 02 Seguimiento · 03 Respuestas
    //    WhatsApp se deja SIN categoría (genérica) hasta integrar su flujo.
    //    Idempotente: los UPDATE solo actúan sobre categorías legacy o nombres
    //    intermedios; los INSERT son condicionales por nombre.
    // ─────────────────────────────────────────────────────────────────────
    echo "\n  Reorganización de plantillas (categorías numeradas)...\n";

    // 3.1 Renombrado de categorías legacy (BD fresca) + nombres intermedios → finales.
    $mapaCategorias = [
        // Legacy (BD fresca / preseed)
        '01 Sin Contactar'   => '01 Prospección',
        '02 Contactado'      => '02 Seguimiento',
        '03 Respondió'       => '03 Respuestas',
        '03 En Conversación' => '03 Respuestas',
        'respuesta_modelo'   => '03 Respuestas',
        'prospeccion'        => '01 Prospección',
        'seguimiento'        => '02 Seguimiento',
        'whatsapp'           => '',
        // Nombres intermedios (BD ya migrada a la convención anterior)
        '[Prospección] Clubes Fútbol Base' => '01 Prospección',
        '[Seguimiento] Leads Interesados / Alta Apertura' => '02 Seguimiento',
        '[Respuestas] Manejo de Objeciones' => '03 Respuestas',
        '[WhatsApp] Primer Contacto' => '',
    ];
    foreach ($mapaCategorias as $vieja => $nueva) {
        $stmt = $db->prepare("UPDATE plantillas SET categoria = :nueva WHERE categoria = :vieja");
        $stmt->bindValue(':nueva', $nueva, SQLITE3_TEXT);
        $stmt->bindValue(':vieja', $vieja, SQLITE3_TEXT);
        $stmt->execute();
        if ($db->changes() > 0) {
            echo "   Categoría '{$vieja}' → " . ($nueva === '' ? '(sin categoría)' : "'{$nueva}'") . ' (' . $db->changes() . " plantilla(s))\n";
        }
    }

    // 3.2 Mover "Respuesta - Sí simple" a Respuestas (estaba en Seguimiento).
    $stmt = $db->prepare("UPDATE plantillas SET categoria = '03 Respuestas' WHERE nombre = :n AND categoria = '02 Seguimiento'");
    $stmt->bindValue(':n', 'Respuesta - Sí simple - Siguiente paso', SQLITE3_TEXT);
    $stmt->execute();
    if ($db->changes() > 0) {
        echo "   Movida 'Respuesta - Sí simple' → '03 Respuestas'\n";
    }

    // 3.3 Plantilla "Seguimiento - Paso 2 - Recordatorio corto (48h)" si no existe.
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

    // 3.4 Plantilla "Seguimiento Caliente" (alta apertura sin respuesta) si no existe.
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

    // ─────────────────────────────────────────────────────────────────────
    // 4. SECUENCIAS CONDICIONALES (O-1 — ramificación por ramal ABC)
    //    Plan: docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md
    //    Idempotente: CREATE IF NOT EXISTS + ALTER con chequeo + índice único.
    // ─────────────────────────────────────────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS secuencias (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        nombre      TEXT NOT NULL,
        modo_auto   INTEGER NOT NULL DEFAULT 0,
        activo      INTEGER NOT NULL DEFAULT 1,
        creado_el   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(campaign_id, nombre)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS secuencia_pasos (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        secuencia_id INTEGER NOT NULL,
        paso        INTEGER NOT NULL,
        plantilla_id INTEGER NOT NULL,
        espera_dias INTEGER NOT NULL DEFAULT 2,
        ramal       VARCHAR(1) NOT NULL DEFAULT '',
        activo      INTEGER NOT NULL DEFAULT 1,
        UNIQUE(secuencia_id, paso)
    )");
    $colsEnvios = [];
    $resEnv = $db->query("PRAGMA table_info(envios)");
    if ($resEnv) { while ($rowE = $resEnv->fetchArray(SQLITE3_ASSOC)) $colsEnvios[] = $rowE['name']; }
    if (!in_array('secuencia_id', $colsEnvios, true)) {
        $db->exec("ALTER TABLE envios ADD COLUMN secuencia_id INTEGER DEFAULT NULL");
        echo "   Migración: columna 'secuencia_id' añadida a envios\n";
    }
    if (!in_array('paso_secuencia', $colsEnvios, true)) {
        $db->exec("ALTER TABLE envios ADD COLUMN paso_secuencia INTEGER DEFAULT NULL");
        echo "   Migración: columna 'paso_secuencia' añadida a envios\n";
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envios_sec_paso ON envios(lead_id, campaign_id, paso_secuencia) WHERE paso_secuencia IS NOT NULL");

    // ─────────────────────────────────────────────────────────────────────
    // 4c. ADJUNTOS PREDETERMINADOS POR PLANTILLA (editor → repositorio)
    //     Vincula una plantilla con adjuntos del repositorio (adjuntos_repo)
    //     que se adjuntan automáticamente al enviar esa plantilla.
    // ─────────────────────────────────────────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS plantillas_adjuntos (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        plantilla_id     INTEGER NOT NULL,
        adjunto_repo_id  INTEGER NOT NULL,
        orden            INTEGER NOT NULL DEFAULT 0,
        activo           INTEGER NOT NULL DEFAULT 1,
        UNIQUE(plantilla_id, adjunto_repo_id)
    )");


    // ─────────────────────────────────────────────────────────────────────
    // 4b. ROTACIÓN ABC PARA NO ABRIDORES (O-1b)
    //     El índice único (lead_id, campaign_id) pasa a incluir es_rotacion para
    //     permitir un envío de rotación (es_rotacion=1) junto al envío base
    //     (es_rotacion=0) sin colisionar. Idempotente (DROP+CREATE seguros).
    // ─────────────────────────────────────────────────────────────────────
    $colsEnvs = [];
    $resEnvs = $db->query("PRAGMA table_info(envios)");
    if ($resEnvs) { while ($rowE = $resEnvs->fetchArray(SQLITE3_ASSOC)) $colsEnvs[] = $rowE['name']; }
    if (!in_array('es_rotacion', $colsEnvs, true)) {
        $db->exec("ALTER TABLE envios ADD COLUMN es_rotacion INTEGER NOT NULL DEFAULT 0");
        echo "   Migración: columna 'es_rotacion' añadida a envios\n";
    }
    // UNICIDAD IDEMPOTENTE: SOLO el envío BASE (es_rotacion=0) es único por
    // (lead_id, campaign_id). La rotación ABC permite múltiples filas (hasta
    // rotar_max_envios) — una por intento tras cada ventana de espera.
    $db->exec("DROP INDEX IF EXISTS idx_envios_lead_campaign");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envios_lead_campaign ON envios(lead_id, campaign_id) WHERE campaign_id IS NOT NULL AND es_rotacion = 0");
    echo "   Índice de envíos: unicidad solo en envío base (rotación multi-intento)\n";

    // Configuración de la rotación por secuencia (panel del configurador).
    $colsSec = [];
    $resSec = $db->query("PRAGMA table_info(secuencias)");
    if ($resSec) { while ($rowS = $resSec->fetchArray(SQLITE3_ASSOC)) $colsSec[] = $rowS['name']; }
    $colsRot = [
        'rotar_no_abridores' => "ALTER TABLE secuencias ADD COLUMN rotar_no_abridores INTEGER NOT NULL DEFAULT 0",
        'rotar_espera_dias'  => "ALTER TABLE secuencias ADD COLUMN rotar_espera_dias INTEGER NOT NULL DEFAULT 3",
        'rotar_max_envios'   => "ALTER TABLE secuencias ADD COLUMN rotar_max_envios INTEGER NOT NULL DEFAULT 2",
        'rotar_plantilla_id' => "ALTER TABLE secuencias ADD COLUMN rotar_plantilla_id INTEGER NOT NULL DEFAULT 0",
    ];
    foreach ($colsRot as $colRot => $sqlRot) {
        if (!in_array($colRot, $colsSec, true)) {
            $db->exec($sqlRot);
            echo "   Migración: columna 'secuencias.{$colRot}' añadida\n";
        }
    }

    echo "   Tablas de secuencias verificadas\n";

    $db->close();
    echo "\nBase de datos inicializada correctamente: {$dbPath}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNCIONES DE CLASIFICACION TELEFONICA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parsea un string de telefono con multiples numeros y los clasifica en
 * moviles (prefijo 6 o 7) y fijos (prefijo 8 o 9).
 *
 * Reglas segun especificacion espanola:
 * - Empieza por 6 o 7 (9 digitos tras limpieza) -> telefono_movil
 * - Empieza por 8 o 9 (9 digitos tras limpieza) -> telefono_fijo
 *
 * @return array{ moviles: string[], fijos: string[] }
 */
function parseAndClassifyPhones(string $telefonoRaw): array
{
    $moviles = [];
    $fijos   = [];

    // Normalizar: reemplazar separadores comunes por pipe
    $telefonoRaw = str_replace([' - ', '-', '/', '  ', "\t"], '|', $telefonoRaw);
    $telefonoRaw = preg_replace('/\s+/', ' ', $telefonoRaw);

    // Separar por pipe
    $parts = preg_split('/\s*\|\s*/', $telefonoRaw);

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;

        // Limpiar numero: quitar espacios, guiones, parentesis, puntos, +
        $limpio = preg_replace('/[\s\-\(\)\.\+]/', '', $part);

        // Si empieza con +34, quitar prefijo
        if (str_starts_with($limpio, '+34')) {
            $limpio = substr($limpio, 3);
        }
        // Si empieza con 0034, quitar prefijo
        if (str_starts_with($limpio, '0034')) {
            $limpio = substr($limpio, 4);
        }

        // Validar que sean 9 digitos
        if (!preg_match('/^\d{9}$/', $limpio)) {
            // Intentar extraer un numero de 9 digitos
            if (preg_match('/\d{9}/', $limpio, $m)) {
                $limpio = $m[0];
            } else {
                continue;
            }
        }

        $primerDigito = $limpio[0];

        if ($primerDigito === '6' || $primerDigito === '7') {
            $moviles[] = $limpio;
        } elseif ($primerDigito === '8' || $primerDigito === '9') {
            $fijos[] = $limpio;
        }
    }

    $moviles = array_unique($moviles);
    $fijos   = array_unique($fijos);

    return [
        'moviles' => array_values($moviles),
        'fijos'   => array_values($fijos),
    ];
}

/**
 * Mapea el estado legacy al nuevo pipeline Kanban.
 * Consulta si el email ya ha sido enviado o abierto.
 */
function mapEstadoLegacy(string $estadoLegacy, string $email, SQLite3 $db): string
{
    $stmt = $db->prepare(
        "SELECT e.estado, e.tracking_id,
                (SELECT COUNT(*) FROM aperturas a WHERE a.tracking_id = e.tracking_id) as num_aperturas
         FROM envios e
         WHERE LOWER(e.email) = LOWER(:email)
         ORDER BY e.id DESC
         LIMIT 1"
    );
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $res = $stmt->execute();
    $envio = $res->fetchArray(SQLITE3_ASSOC);

    if ($envio) {
        return '02 Contactado';
    }

    return '01 Sin Contactar';
}