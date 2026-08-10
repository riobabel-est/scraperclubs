# FutProtec Outbound CRM — Documentación Técnica y Operativa

> **Versión:** 2.1  
> **Última actualización:** Octubre 2026  
> **Stack:** PHP 8.x nativo + SQLite3 + Alpine.js 3.14 + Tailwind CSS + Lucide Icons  
> **Compatibilidad:** SiteGround (StartUp / GrowBig / GoGeek / Cloud)  

---

## Índice

1. [Resumen del Sistema](#1-resumen-del-sistema)
2. [Arquitectura y Tecnología](#2-arquitectura-y-tecnología)
3. [Instalación y Configuración Inicial](#3-instalación-y-configuración-inicial)
4. [Guía de Uso Paso a Paso](#4-guía-de-uso-paso-a-paso)
   - 4.1 [Login y Seguridad](#41-login-y-seguridad)
   - 4.2 [Kanban CRM — Pipeline de Leads](#42-kanban-crm--pipeline-de-leads)
   - 4.3 [Gestor de Datos](#43-gestor-de-datos)
   - 4.4 [Editor de Plantillas](#44-editor-de-plantillas)
   - 4.5 [Configuración de Cuentas SMTP](#45-configuración-de-cuentas-smtp)
   - 4.6 [Lanzadera — Envíos Masivos](#46-lanzadera--envíos-masivos)
5. [Pipeline de Estados del Lead](#5-pipeline-de-estados-del-lead)
6. [Sistema de Tracking y Aperturas](#6-sistema-de-tracking-y-aperturas)
7. [Test A/B de Asuntos](#7-test-ab-de-asuntos)
8. [Modo Aleatorio Anti-Detección](#8-modo-aleatorio-anti-detección)
9. [Placeholders Disponibles en Plantillas](#9-placeholders-disponibles-en-plantillas)
10. [Validación de Emails y WhatsApp](#10-validación-de-emails-y-whatsapp)
11. [API Endpoints](#11-api-endpoints)
12. [Base de Datos — Esquema SQLite3](#12-base-de-datos--esquema-sqlite3)
13. [Mantenimiento y Resolución de Problemas](#13-mantenimiento-y-resolución-de-problemas)
14. [Buenas Prácticas y Anti-Bloqueo](#14-buenas-prácticas-y-anti-bloqueo)

---

## 1. Resumen del Sistema

FutProtec Outbound CRM es un panel de control completo para **gestión de leads, envío masivo de emails con tracking, y pipeline de ventas Kanban**, diseñado para el sector de clubes de fútbol federados en España.

**Capacidades principales:**

- 📋 **Pipeline Kanban** con 7 estados arrastrables para gestionar leads
- ✉️ **Envío masivo de emails** con rotación automática de cuentas SMTP
- 👁️ **Tracking de aperturas** con píxel invisible y actualización automática de estado
- 🧪 **Test A/B de asuntos** (50/50) con registro de variante enviada
- 🎲 **Modo aleatorio anti-detección** para evadir filtros anti-spam
- 📝 **Editor de plantillas** HTML / Texto Plano / WhatsApp con placeholders dinámicos
- 🔍 **Scanner y merge de duplicados** con normalización de nombres de clubes
- 📊 **Analytics en tiempo real** de tasa de éxito, rebotes y envíos por cuenta
- 🔐 **Autenticación por contraseña** y protección de credenciales SMTP

---

## 2. Arquitectura y Tecnología

### Stack tecnológico

| Capa | Tecnología | Justificación |
|---|---|---|
| **Frontend** | HTML5 + Tailwind CSS (CDN) + Alpine.js 3.14 (CDN) + Lucide Icons (CDN) | Cero dependencias de build. Carga instantánea desde CDN. |
| **Backend** | PHP 8.x nativo (sin frameworks) | Compatible con SiteGround Shared Hosting. Sin Composer, sin PECL. |
| **Base de Datos** | SQLite3 (archivo `stats.db`) | Single-file, sin servidor. WAL mode para lecturas concurrentes. SiteGround lo soporta nativamente. |
| **Email** | SMTP nativo con `stream_socket_client()` + TLS/SSL | Sin dependencias de terceros (PHPMailer, Swiftmailer). Compatible con `mail.getfutprotec.com` y cualquier servidor SMTP. |
| **Tracking** | Píxel PNG 1x1 transparente servido por PHP | Sin JavaScript. Funciona en todos los clientes de email. |

### ¿Por qué no se usa Python/Node.js/React?

SiteGround en planes compartidos (StartUp/GrowBig) **no permite procesos en segundo plano, Node.js, ni Python**. Todo debe ejecutarse dentro de Apache + PHP. Esta arquitectura es 100% compatible con SiteGround.

### Estructura de archivos

```
public_html/outbound/
├── dashboard.php          ← Panel principal (login + tabs)
├── init_db.php            ← Inicializa y migra la BD
├── stats.db               ← SQLite3 (único archivo de datos)
├── README.md              ← Esta documentación
│
├── tabs/
│   ├── kanban.php         ← Tab: Pipeline Kanban
│   ├── gestor.php         ← Tab: Gestor de datos (CRUD, filtros, paginado)
│   ├── editor.php         ← Tab: Editor de plantillas con A/B testing
│   ├── smtp.php           ← Tab: Configuración de cuentas SMTP
│   ├── lanzadera.php      ← Tab: Lanzadera de envíos masivos
│   └── modals.php         ← Modales compartidos (lead, merge, SMTP)
│
├── enviar_lote.php        ← API: Envío individual de email vía SMTP
├── enviar_smtp_random.php ← API: Envío con rotación SMTP + random
├── get_cola.php           ← API: Genera la cola de envíos con filtros
├── api_leads.php          ← API: CRUD de leads, scanner dups, merge
├── api_smtp.php           ← API: CRUD de cuentas SMTP + test conexión
├── track.php              ← Píxel de tracking (1x1 PNG)
├── baja.php               ← Página de baja/opt-out
├── cron.php               ← Tareas programadas (opcional)
└── .htaccess              ← Reglas de seguridad y reescritura
```

---

## 3. Instalación y Configuración Inicial

### Requisitos

- PHP 8.0 o superior
- Extensión SQLite3 habilitada
- Apache con `mod_rewrite` (para `.htaccess`)
- Conexión a internet (para CDNs de Tailwind, Alpine.js y Lucide)

### Paso 1: Subir archivos

Sube la carpeta `public_html/outbound/` al servidor (SiteGround, Laragon, XAMPP, etc.).

### Paso 2: Inicializar la base de datos

```bash
php init_db.php
```

Esto crea `stats.db` con todas las tablas:
- `clubes_crm` — Leads con pipeline Kanban
- `cuentas_smtp` — Cuentas de envío rotativas
- `plantillas` — Plantillas de email/WhatsApp
- `envios`, `aperturas`, `rebotes` — Tracking histórico
- `comunicaciones_log` — Timeline de comunicaciones
- `config` — Configuración global (modo test/prod, delay)

También **migra automáticamente** 10 cuentas SMTP preconfiguradas y 4 plantillas preseed.

### Paso 3: Acceder al panel

Abre en el navegador:
```
https://getfutprotec.com/outbound/dashboard.php
```

**Contraseña por defecto:** `FutProtec2026!`

> ⚠️ Cambia la contraseña en `dashboard.php` (línea 9, constante `AUTH_KEY`).

### Paso 4: Configurar cuentas SMTP

Ve a la pestaña **Config SMTP** y verifica que las 10 cuentas están activas (deberían aparecer en verde). Si alguna falla, usa el botón ⚡ para testear la conexión.

### Paso 5 (opcional): Migrar datos de scraping

```bash
php init_db.php --migrate-contacts
```

Esto importa leads desde `../../output/clean/contactos_sintaxis_ok.csv` y `../../clubes.json`.

---

## 4. Guía de Uso Paso a Paso

### 4.1 Login y Seguridad

1. Accede a `dashboard.php`
2. Introduce la contraseña `FutProtec2026!`
3. La sesión PHP mantiene la autenticación hasta que cierres el navegador o hagas clic en **Logout**

**El archivo `.htaccess`** bloquea acceso directo a `stats.db` y archivos sensibles.

### 4.2 Kanban CRM — Pipeline de Leads

El Kanban muestra **7 columnas** que representan el pipeline de ventas:

| Columna | Significado |
|---|---|
| Sin Contactar | Lead nuevo, sin acción |
| Email Enviado / En Secuencia | Se envió email, esperando apertura/respuesta |
| Impactado / Abrio Email | Abrió el email (detectado por píxel) |
| En Conversacion / WhatsApp | Respondió o hay diálogo abierto |
| Muestra / Propuesta Enviada | Se envió propuesta comercial |
| Cerrado Ganado | Cliente convertido |
| Cerrado Perdido | Oportunidad perdida |

**Operaciones en cada tarjeta:**
- Haz clic en una tarjeta para abrir la **ficha del lead**
- Cambia el estado con el dropdown en la ficha
- Añade notas con timestamp automático
- Mueve el lead entre columnas arrastrando (cambio de estado automático)
- Enlace directo a WhatsApp (si tiene móvil válido)

### 4.3 Gestor de Datos

Tabla paginada con todos los leads. Permite:

- **Buscar** por nombre de club
- **Filtrar** por estado del lead y federación
- **Ordenar** por cualquier columna (clic en cabecera)
- **Escanear duplicados** — botón naranja que detecta emails repetidos
- **Merge de duplicados** — unifica dos registros conservando datos del mejor

### 4.4 Editor de Plantillas

Permite crear, editar y eliminar plantillas categorizadas **por estado del lead**.

**Flujo de uso:**

1. Selecciona un **Estado del Lead** (01-07) en el primer dropdown
2. El segundo dropdown muestra solo plantillas asociadas a ese estado
3. Selecciona una existente o pulsa **Nueva**
4. Configura:
   - **Nombre** descriptivo
   - **Formato:** HTML / Texto Plano / WhatsApp
   - **Asunto** (con placeholders como `{{CLUB}}`)
   - **Cuerpo** del mensaje
   - **Test A/B** (opcional) — dos variantes de asunto
5. Pulsa **Guardar**
6. Usa el selector de **Previsualización** para ver cómo queda con datos reales de un club

**Los placeholders se reemplazan automáticamente al enviar:**
- `{{CLUB}}` → Nombre del club
- `{{CONTACTO}}` → Persona de contacto
- `{{FEDERACION}}` → Nombre de la federación
- `{{ANIO}}` → Año actual
- `{{EMAIL}}` → Email del destinatario
- `{{SENDER_NAME}}` → Nombre del remitente
- `{{SENDER_TITLE}}` → Cargo del remitente
- `{{SENDER_EMAIL}}` → Email del remitente

### 4.5 Configuración de Cuentas SMTP

Gestiona las cuentas de envío. Cada cuenta tiene:

| Campo | Descripción |
|---|---|
| Email | Dirección del remitente |
| Host | Servidor SMTP (ej: `mail.getfutprotec.com`) |
| Puerto | 465 (SSL) o 587 (TLS) |
| Usuario | Normalmente = email |
| Password | Contraseña de la cuenta |
| Límite diario | Máx. envíos/día (default: 50) |
| Nombre emisor | Nombre visible en el "From" |
| Cargo emisor | Cargo visible con placeholder `{{SENDER_TITLE}}` |

**Operaciones:**
- ⚡ **Test** — Verifica conexión y autenticación SMTP
- ⏻ **Toggle ON/OFF** — Activa/desactiva la cuenta sin borrarla
- ✏️ **Editar** — Modifica cualquier campo
- 🗑️ **Eliminar** — Solo si hay más de 1 cuenta

> 🔒 Las contraseñas se muestran parcialmente ocultas (`rod***`) en la UI.

### 4.6 Lanzadera — Envíos Masivos

Es el módulo principal para campañas de email. Funciona así:

#### Configuración del lote

1. **Seleccionar Federación** — Filtra leads por federación (opcional, "Todas" = sin filtro)
2. **Seleccionar Estado del Lead** — Elige a qué leads enviar (ej: `01 Sin Contactar` para primer contacto, `02 Email/WhatsApp Enviado` para seguimiento)
3. **Seleccionar Plantilla** — Solo muestra plantillas asociadas al estado elegido

#### Cargar cola

Pulsa **🔵 Cargar Cola**. El sistema:
- Consulta los leads que coinciden con los filtros
- Asigna cuentas SMTP en **round-robin** (distribución equitativa)
- Si el modo aleatorio 🎲 está activo, baraja leads y cuentas
- Calcula la hora estimada de cada envío según el delay configurado

#### Iniciar envíos

1. Ajusta el **Retardo entre envíos** (slider: 1-60 segundos). Recomendado ≥5s para no saturar.
2. Pulsa **🟢 INICIAR LANZADERA**
3. El motor envía secuencialmente, mostrando:
   - Fila actual en **ámbar** (procesando)
   - Filas completadas en **gris atenuado**
   - Log en tiempo real con ✅/🔴 por cada envío
   - Analytics de sesión (% éxito)

#### Controles

- **🟡 PAUSAR** — Detiene temporalmente (reanudable)
- **🔴 DETENER** — Cancela todo y limpia la cola

#### Modo producción vs pruebas

El switch **MODO PRUEBAS / MODO PRODUCCION** en la barra superior controla:

| | Modo Pruebas | Modo Producción |
|---|---|---|
| Destinatario | `contactofutprotec@gmail.com` (o emails de prueba) | Email real del lead |
| Cambio de estado | ❌ No cambia | ✅ `Sin Contactar` → `Email Enviado / En Secuencia` |
| Nota en lead | `[TEST] Email de prueba...` | `[LANZADERA] Email enviado...` |

> 🧪 Usa el campo **Destinos de Prueba** para testear con emails específicos antes de lanzar en producción.

---

## 5. Pipeline de Estados del Lead

### Mapeo UI ↔ Base de Datos

| Código UI | Nombre en BD | Automático / Manual | Trigger |
|---|---|---|---|
| `01 Sin Contactar` | `Sin Contactar` | — | Estado inicial |
| `02 Email/WhatsApp Enviado` | `Email Enviado / En Secuencia` | ✅ Automático | `enviar_lote.php` al enviar con éxito en producción |
| `03 Email Abierto` | `Impactado / Abrio Email` | ✅ Automático | `track.php` al cargar el píxel de tracking |
| `04 En Conversacion` | `En Conversacion / WhatsApp` | ✋ Manual | Desde el Kanban |
| `05 Propuesta Enviada` | `Muestra / Propuesta Enviada` | ✋ Manual | Desde el Kanban |
| `06 Cerrado Ganado` | `Cerrado Ganado` | ✋ Manual | Desde el Kanban |
| `07 Cerrado Perdido` | `Cerrado Perdido` | ✋ Manual | Desde el Kanban |

### Flujo típico

```
01 → [envías email] → 02 → [abre email] → 03 → [responde] → 04 → [envías propuesta] → 05 → 06 ✅
                                                                                           ↘ 07 ❌
```

---

## 6. Sistema de Tracking y Aperturas

Cada email enviado incluye un **píxel de tracking invisible**:

```html
<img src="https://getfutprotec.com/outbound/track.php?id=fut_ABCD1234" 
     width="1" height="1" style="display:none" alt="">
```

**Cómo funciona:**

1. Al enviar, se genera un `tracking_id` único (`fut_` + timestamp hex + random 6 bytes)
2. El píxel y un fingerprint anti-detección se inyectan antes de `</body>`
3. Cuando el destinatario abre el email, su cliente carga el píxel → `track.php`
4. `track.php` registra: IP, User-Agent, fecha/hora en la tabla `aperturas`
5. **Actualiza automáticamente** el estado del lead a `Impactado / Abrio Email` (solo la primera vez)
6. También registra una nota `[TRACKING] Email abierto` en observaciones

**Limitaciones:**
- Algunos clientes de email (Outlook, Gmail con imágenes desactivadas) no cargan imágenes por defecto
- No detecta si el email fue realmente leído, solo si se cargó la imagen
- La tasa de apertura real suele ser mayor que la detectada

---

## 7. Test A/B de Asuntos

Permite probar dos variantes de asunto y ver cuál funciona mejor.

**Activación en el editor de plantillas:**
1. Activa el switch 🧪 **Test A/B de Asunto**
2. Escribe el **Asunto A** (50% de los envíos)
3. Escribe el **Asunto B** (50% de los envíos)

**En cada envío:**
- Se elige aleatoriamente variante A o B (50/50)
- Se registra en `comunicaciones_log.variante_ab` (`'A'` o `'B'`)
- Se registra en `envios.asunto` el asunto real usado

**Para analizar resultados:**
```sql
-- Tasa de apertura por variante
SELECT e.variante_ab, 
       COUNT(*) as envios,
       COUNT(a.tracking_id) as aperturas
FROM envios e
LEFT JOIN aperturas a ON a.tracking_id = e.tracking_id
WHERE e.fecha_envio > DATE('now', '-30 days')
GROUP BY e.variante_ab;
```

> 📊 Nota: El análisis A/B se hace actualmente por query SQL directa. Próximamente se añadirá un dashboard visual.

---

## 8. Modo Aleatorio Anti-Detección

El botón **🎲 ALEATORIO ON/OFF** en la barra superior activa el modo aleatorio, diseñado para:

- **Evitar patrones detectables** por filtros anti-spam (misma cuenta, misma hora, mismo orden)
- **Distribuir impredeciblemente** los envíos entre cuentas SMTP
- **Añadir jitter aleatorio** al delay entre envíos

**Efectos del modo aleatorio:**
- Los leads se barajan aleatoriamente (no orden alfabético)
- Las cuentas SMTP se barajan para cada lead individualmente
- El delay tiene ±50% de variación aleatoria

> ⚠️ Actívalo para campañas grandes (> 100 envíos) donde quieras minimizar la huella digital del envío.

---

## 9. Placeholders Disponibles en Plantillas

| Placeholder | Se reemplaza por | Origen |
|---|---|---|
| `{{CLUB}}` | Nombre del club | `clubes_crm.nombre_club` |
| `{{CONTACTO}}` | Persona de contacto (o "responsable") | `clubes_crm.persona_contacto` |
| `{{FEDERACION}}` | Federación del club | `clubes_crm.federacion` |
| `{{ANIO}}` | Año actual (ej: 2026) | `date('Y')` |
| `{{EMAIL}}` | Email del destinatario | `clubes_crm.email` |
| `{{SENDER_NAME}}` | Nombre del remitente | `cuentas_smtp.nombre_emisor` |
| `{{SENDER_TITLE}}` | Cargo del remitente | `cuentas_smtp.cargo_emisor` |
| `{{SENDER_EMAIL}}` | Email del remitente | `cuentas_smtp.email` |

---

## 10. Validación de Emails y WhatsApp

### Validación de email al añadir leads

Al añadir un nuevo lead manualmente:
1. `filter_var($email, FILTER_VALIDATE_EMAIL)` — formato correcto
2. `checkdnsrr($domain, 'MX')` — el dominio tiene servidor de correo

Si el dominio no tiene registros MX, el lead **se rechaza** con un mensaje explicativo.

### Detección automática de WhatsApp

Si el número de móvil:
- Tiene **9 dígitos** (tras limpiar espacios, guiones, +34)
- Empieza por **6 o 7**

→ Se marca automáticamente `tiene_whatsapp = 1` y aparece el botón de WhatsApp en la ficha del lead.

---

## 11. API Endpoints

Todos los endpoints aceptan POST con `application/x-www-form-urlencoded` o GET con query string. Respuestas en JSON.

### `dashboard.php` (endpoints vía `?action=`)

| Action | Método | Parámetros | Descripción |
|---|---|---|---|
| `update_lead` | POST | `id`, `field`, `value` | Actualiza un campo del lead |
| `add_lead` | POST | `nombre`, `email`, `federacion`, `telefono_movil`, `telefono_fijo`, `persona_contacto`, `cargo_contacto` | Añade nuevo lead con validación MX |
| `get_lead` | GET | `id` | Obtiene datos completos de un lead |
| `save_template` | POST | `id?`, `nombre`, `asunto`, `asunto_b?`, `cuerpo`, `tipo`, `categoria`, `test_ab` | Crea/edita plantilla |
| `delete_template` | POST | `id` | Elimina plantilla |
| `get_templates` | GET | `categoria?` | Lista plantillas, opcionalmente filtradas |
| `get_categorias` | GET | — | Lista categorías (estados) con plantillas |
| `preview_template` | GET | `template_id`, `club_id` | Previsualiza plantilla con datos reales |
| `update_config` | POST | `key`, `value` | Actualiza clave de configuración |

### `api_leads.php`

| Action | Método | Descripción |
|---|---|---|
| `get_leads_table` | GET | Tabla paginada con filtros (`page`, `per_page`, `search`, `estado`, `federacion`, `sort`, `order`) |
| `scan_duplicates` | GET | Escanea emails duplicados y marca `es_duplicado=1` |
| `merge_leads` | POST | Fusiona dos leads (`keep_id`, `dup_id`, `merge_notes`) |
| `get_config` | GET | Obtiene valor de configuración (`key`) |

### `api_smtp.php`

| Action | Método | Descripción |
|---|---|---|
| `get_accounts` | GET | Lista todas las cuentas SMTP (passwords ocultas) |
| `save_account` | POST | Crea o edita cuenta SMTP |
| `toggle_account` | POST | Activa/desactiva cuenta (`id`) |
| `delete_account` | POST | Elimina cuenta (`id`) |
| `test_smtp` | POST | Prueba conexión y autenticación SMTP |

### `get_cola.php`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `estado_lead` | string | Código UI del estado (ej: `01 Sin Contactar`) |
| `federacion` | string | Filtrar por federación |
| `id_plantilla_email` | int | ID de plantilla a usar |
| `id_plantilla_wa` | int | ID plantilla WhatsApp (opcional) |
| `habilitar_whatsapp` | 0/1 | Activar envío WhatsApp |
| `random_mode` | 0/1 | Activar asignación aleatoria |

**Respuesta:** JSON con `cola` (array de leads con SMTP asignada), `cuentas_smtp`, `kpi_*`, `federaciones`, `categorias`.

### `enviar_lote.php`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `id_club` | int | ID del lead |
| `id_plantilla` | int | ID de la plantilla |
| `id_cuenta_smtp` | int | ID de la cuenta SMTP |
| `modo_test` | 0/1 | Si es 1, envía a email de prueba |
| `variante_ab` | A/B | Variante del test A/B |
| `test_email` | string | Email de override en modo test |

### `track.php`

| Parámetro | Tipo | Descripción |
|---|---|---|
| `id` | string | Tracking ID (`fut_XXXX_XXXX`) |

Responde con un PNG 1x1 transparente. No devuelve errores visibles (anti-scanner).

---

## 12. Base de Datos — Esquema SQLite3

### Tabla `clubes_crm`

```sql
CREATE TABLE clubes_crm (
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
);
```

### Tabla `cuentas_smtp`

```sql
CREATE TABLE cuentas_smtp (
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
    ultimo_uso DATETIME DEFAULT NULL,
    nombre_emisor VARCHAR(100) DEFAULT '',
    cargo_emisor VARCHAR(100) DEFAULT ''
);
```

### Tabla `plantillas`

```sql
CREATE TABLE plantillas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre VARCHAR(100) NOT NULL,
    asunto VARCHAR(255) DEFAULT '',
    asunto_b VARCHAR(255) DEFAULT '',   -- Test A/B variante B
    test_ab INTEGER DEFAULT 0,          -- 1 = activo, 0 = inactivo
    cuerpo TEXT NOT NULL,
    tipo VARCHAR(20) DEFAULT 'html',    -- 'html' | 'texto_plano' | 'whatsapp'
    categoria VARCHAR(50) DEFAULT 'prospeccion',
    activo INTEGER DEFAULT 1,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Tabla `envios`

```sql
CREATE TABLE envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    club TEXT NOT NULL,
    email TEXT NOT NULL,
    federacion TEXT DEFAULT '',
    cuenta_emision TEXT DEFAULT '',
    fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado TEXT DEFAULT 'pendiente',    -- 'pendiente' | 'enviado' | 'error' | 'abierto'
    tracking_id TEXT UNIQUE NOT NULL,
    asunto TEXT DEFAULT '',
    cuerpo_mensaje TEXT DEFAULT ''
);
```

### Tabla `aperturas`

```sql
CREATE TABLE aperturas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tracking_id TEXT NOT NULL,
    fecha_apertura DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    FOREIGN KEY (tracking_id) REFERENCES envios(tracking_id)
);
```

### Tabla `comunicaciones_log`

```sql
CREATE TABLE comunicaciones_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER DEFAULT NULL,
    club_id INTEGER DEFAULT NULL,
    tipo_evento VARCHAR(50) NOT NULL,
    plantilla_id INTEGER DEFAULT NULL,
    id_cuenta_smtp INTEGER DEFAULT NULL,
    tipo VARCHAR(20) DEFAULT 'email',
    resultado TEXT DEFAULT '',
    codigo_error TEXT DEFAULT '',
    variante_ab VARCHAR(1) DEFAULT '',  -- 'A' o 'B'
    detalles TEXT DEFAULT '',
    ip_registro VARCHAR(45) DEFAULT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Tabla `config`

```sql
CREATE TABLE config (
    clave TEXT PRIMARY KEY,
    valor TEXT
);
```

Claves utilizadas:
- `motor_estado` — `'activo'` / `'pausado'`
- `modo_entorno` — `'test'` / `'produccion'`
- `lanzadera_delay` — segundos entre envíos (default: 5)
- `email_test` — email para pruebas

---

## 13. Mantenimiento y Resolución de Problemas

### Error: "stats.db no encontrada"

```bash
php init_db.php
```

### Error: "No hay cuentas SMTP activas"

Ve a **Config SMTP** y verifica que al menos una cuenta tenga el toggle en ON (verde).

### Error: "Cuenta SMTP saturada"

La cuenta alcanzó su límite diario. Espera al día siguiente o aumenta `limite_diario` en la configuración de la cuenta.

### La tasa de apertura es 0%

Posibles causas:
- El píxel de tracking está siendo bloqueado por el cliente de email
- Los emails están cayendo en spam (verifica registros MX, SPF, DKIM del dominio `getfutprotec.com`)
- Verifica que `track.php` es accesible públicamente: `curl -I https://getfutprotec.com/outbound/track.php`

### SiteGround no refleja los cambios

Si `curl https://getfutprotec.com/outbound/tabs/editor.php` muestra la versión antigua:
1. Ve a SiteGround → **Site Tools** → **Dev** → **Git**
2. Haz clic en **Pull** para forzar la sincronización
3. Limpia la caché de SiteGround si está activado el **SuperCacher**

### Backup de la base de datos

```bash
cp public_html/outbound/stats.db public_html/outbound/stats_backup_$(date +%Y%m%d).db
```

### Reiniciar contadores diarios

Los contadores `enviados_hoy` se reinician consultando `comunicaciones_log WHERE DATE(fecha) = DATE('now')`. No es necesario reinicio manual.

---

## 14. Buenas Prácticas y Anti-Bloqueo

### Recomendaciones para campañas

| Práctica | Valor recomendado |
|---|---|
| Delay entre envíos | 5-10 segundos |
| Envíos por cuenta/día | Máx. 50 (límite típico de hosting compartido) |
| Modo aleatorio | Activar para campañas > 100 envíos |
| Cuentas SMTP activas | Mínimo 3 para rotación efectiva |
| Modo pruebas primero | Siempre testear con 2-3 emails antes de producción |

### Anti-Detección

El sistema incluye varias técnicas anti-spam:
- **Fingerprint único** por email (`fpid:XXXX` en comentario HTML)
- **Rotación de cuentas SMTP** (diferentes remitentes)
- **Jitter aleatorio** en delays (modo 🎲)
- **Píxel de tracking invisible** (1x1 PNG, no JavaScript)
- **Cabeceras profesionales**: `X-Mailer`, `MIME-Version`, `Content-Type` correcto
- **Asunto codificado en Base64 UTF-8** para caracteres especiales

### Límites legales (RGPD/LOPDGDD)

- Todos los emails incluyen enlace de **baja** (`baja.php?email=`)
- Los leads que hacen opt-out se marcan como `estado_lead = 'Opt-Out'`
- No se envían emails a leads con estado `Opt-Out`, `Unsubscribed` o `Lista Negra`
- Los datos de tracking (IP, User-Agent) se almacenan solo con fines estadísticos

---

## 📞 Soporte

Para incidencias técnicas, contacta al equipo de desarrollo.

---

*FutProtec Outbound CRM v2.1 — Construido con PHP nativo, SQLite3 y Alpine.js para máxima compatibilidad con SiteGround.*