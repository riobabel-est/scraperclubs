# FutProtec Outbound CRM — Documentación Técnica y Operativa

> **Versión:** 2.2  
> **Última actualización:** 10 Octubre 2026  
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
- 💬 **WhatsApp integrado** con selector de plantilla y envío con texto precargado
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

### Estructura de archivos (v2.2 reorganizada)

```
public_html/outbound/
├── dashboard.php          ← Panel principal (login + tabs)
├── .htaccess              ← Bloquea acceso a data/, cli/, archivos .db
├── .gitignore             ← Excluye stats.db del repo
├── README.md              ← Documentación
│
├── api/                   ← 7 endpoints backend
│   ├── enviar_lote.php       → SMTP individual (envío unitario)
│   ├── enviar_smtp_random.php→ SMTP con rotación aleatoria
│   ├── get_cola.php          → Generador de cola con filtros
│   ├── leads.php             → CRUD de leads, scanner dups, merge
│   ├── smtp.php              → CRUD cuentas SMTP + test conexión
│   ├── track.php             → Píxel tracking (1x1 PNG)
│   └── baja.php              → Página de baja/opt-out
│
├── cli/                   ← 2 scripts (no accesibles vía web)
│   ├── init_db.php           → Inicializa/migra BD
│   └── cron.php              → Tareas programadas
│
├── data/                  ← Datos protegidos
│   └── stats.db              → Base de datos SQLite3
│
└── tabs/                  ← 6 fragments UI
    ├── kanban.php            → Pipeline Kanban 7 columnas
    ├── gestor.php            → Tabla paginada con filtros
    ├── editor.php            → Editor de plantillas (pipeline + plataforma)
    ├── smtp.php              → Configuración cuentas SMTP
    ├── lanzadera.php         → Lanzadera envíos masivos
    └── modals.php            → Modales (ficha lead, add, merge, SMTP)
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
php cli/init_db.php
```

Esto crea `data/stats.db` con todas las tablas y migra automáticamente 10 cuentas SMTP + 4 plantillas preseed.

### Paso 3: Acceder al panel

```
https://getfutprotec.com/outbound/dashboard.php
```

**Contraseña por defecto:** `FutProtec2026!`

> ⚠️ Cambia la contraseña en `dashboard.php` (línea 9, constante `AUTH_KEY`).

---

## 4. Guía de Uso Paso a Paso

### 4.1 Login y Seguridad

1. Accede a `dashboard.php`
2. Introduce la contraseña
3. La sesión PHP mantiene la autenticación hasta que cierres el navegador

### 4.2 Kanban CRM — Pipeline de Leads

7 columnas que representan el pipeline de ventas. Haz clic en una tarjeta para abrir la ficha del lead.

### 4.3 Gestor de Datos

Tabla paginada con búsqueda, filtros por estado/federación, ordenación, escaneo de duplicados y merge.

### 4.4 Editor de Plantillas

**Nuevo diseño v2.2** — El listado de plantillas **es** el selector principal.

1. **Estado del Lead** (filtro opcional) — filtrar por etapa del pipeline. "Todas" = sin filtro.
2. **Lista de plantillas** — Cada fila muestra:
   - Icono de plataforma (📧 email / 💬 WhatsApp)
   - Nombre de la plantilla
   - Pipeline al que pertenece (en texto pequeño debajo)
   - Check ✓ si está seleccionada
3. **+ Nueva Plantilla** — Botón al pie del listado
4. **Eliminar Plantilla** — Visible solo cuando hay una seleccionada

**En el editor (columna derecha):**
- **Nombre** + **Pipeline** (campo deshabilitado, informativo)
- **Plataforma** — Pills toggle 📧 Email / 💬 WA (¡cada plantilla define su plataforma!)
- **Sub-formato** (solo Email) — 📄 HTML / 📝 Texto Plano
- **Test A/B** (solo Email) — Asunto A y B al 50%
- **Mensaje** — Textarea con placeholders y contador 4096 para WhatsApp
- **Previsualización** — Con datos reales de un club + remitente SMTP

**Flujo típico para crear una plantilla de WhatsApp:**
1. Selecciona "01 Sin Contactar" en el filtro (o cualquier estado)
2. Pulsa **+ Nueva Plantilla**
3. En el editor, cambia Plataforma a **💬 WA**
4. Escribe el mensaje usando placeholders ({{CLUB}}, {{CONTACTO}}, etc.)
5. **Guardar**

Ahora esa plantilla aparecerá en el selector de WhatsApp de la ficha del lead y en la lanzadera.

### 4.5 Configuración de Cuentas SMTP

Gestiona las cuentas de envío. Cada cuenta tiene email, host, puerto, seguridad, límite diario, nombre emisor y cargo emisor. Botones: test, toggle ON/OFF, editar, eliminar.

### 4.6 Lanzadera — Envíos Masivos

**Configuración del lote:**
1. **Seleccionar Federación** — Filtra por federación (opcional)
2. **Seleccionar Estado del Lead** — Elige etapa del pipeline
3. **Seleccionar Plantilla** — Solo muestra las del estado elegido

**Cargar cola** → **INICIAR LANZADERA** → Motor envía secuencialmente con delay configurable.

---

## 5. Pipeline de Estados del Lead

| Código UI | Nombre en BD | Trigger |
|---|---|---|
| `01 Sin Contactar` | `Sin Contactar` | Estado inicial |
| `02 Email/WhatsApp Enviado` | `Email Enviado / En Secuencia` | ✅ Auto: `enviar_lote.php` |
| `03 Email Abierto` | `Impactado / Abrio Email` | ✅ Auto: `track.php` |
| `04 En Conversacion` | `En Conversacion / WhatsApp` | ✋ Manual |
| `05 Propuesta Enviada` | `Muestra / Propuesta Enviada` | ✋ Manual |
| `06 Cerrado Ganado` | `Cerrado Ganado` | ✋ Manual |
| `07 Cerrado Perdido` | `Cerrado Perdido` | ✋ Manual |

---

## 6. Sistema de Tracking y Aperturas

Cada email incluye un píxel 1x1 PNG. Al cargarse, `track.php` registra IP, User-Agent y actualiza el estado del lead a `Impactado / Abrio Email`.

---

## 7. Test A/B de Asuntos

Disponible solo para plantillas Email. Dos variantes 50/50. Se registra en `comunicaciones_log.variante_ab`.

---

## 8. Modo Aleatorio Anti-Detección

Botón 🎲 en la barra superior. Baraja leads y cuentas SMTP, añade jitter al delay. Recomendado para campañas >100 envíos.

---

## 9. Placeholders Disponibles en Plantillas

| Placeholder | Reemplazo |
|---|---|
| `{{CLUB}}` | Nombre del club |
| `{{CONTACTO}}` | Persona de contacto |
| `{{FEDERACION}}` | Federación |
| `{{ANIO}}` | Año actual |
| `{{EMAIL}}` | Email del destinatario |
| `{{SENDER_NAME}}` | Nombre del remitente (de cuenta SMTP) |
| `{{SENDER_TITLE}}` | Cargo del remitente (de cuenta SMTP) |
| `{{SENDER_EMAIL}}` | Email del remitente (de cuenta SMTP) |

---

## 10. Validación de Emails y WhatsApp

### Email
- `filter_var()` + `checkdnsrr(MX)` al añadir leads manualmente
- Dominios sin MX se rechazan con mensaje

### WhatsApp
- Detección automática: si el móvil tiene 9 dígitos y empieza por 6 o 7 → `tiene_whatsapp = 1`
- **Ficha del lead**: selector de plantilla WhatsApp con botón "Enviar WA" que abre `wa.me/34XXXX?text=...` con placeholders reemplazados
- **Editor**: las plantillas WhatsApp no tienen asunto ni A/B testing, contador de 4096 caracteres

---

## 11. API Endpoints

### `dashboard.php` (vía `?action=`)

| Action | Método | Descripción |
|---|---|---|
| `update_lead` | POST | Actualiza un campo del lead |
| `add_lead` | POST | Añade nuevo lead (valida MX) |
| `get_lead` | GET | Datos completos de un lead |
| `save_template` | POST | Crea/edita plantilla |
| `delete_template` | POST | Elimina plantilla |
| `get_templates` | GET | Lista plantillas (filtro: `categoria`) |
| `get_categorias` | GET | Lista categorías con plantillas |
| `preview_template` | GET | Previsualiza con datos reales |
| `update_config` | POST | Actualiza clave de configuración |

### `api/leads.php`, `api/smtp.php`, `api/get_cola.php`, `api/enviar_lote.php`, `api/track.php`

Documentados en detalle en sus respectivos archivos.

---

## 12. Base de Datos — Esquema SQLite3

Tablas principales: `clubes_crm`, `cuentas_smtp`, `plantillas`, `envios`, `aperturas`, `comunicaciones_log`, `config`, `rebotes`.

> Ver `cli/init_db.php` para el esquema SQL completo.

---

## 13. Mantenimiento y Resolución de Problemas

### stats.db no encontrada
```bash
php cli/init_db.php
```

### SiteGround no refleja cambios
1. Site Tools → Dev → Git → Pull
2. Limpiar SuperCacher

### Backup
```bash
cp public_html/outbound/data/stats.db public_html/outbound/data/stats_backup_$(date +%Y%m%d).db
```

---

## 14. Buenas Prácticas y Anti-Bloqueo

| Práctica | Recomendación |
|---|---|
| Delay entre envíos | 5-10 segundos |
| Envíos por cuenta/día | Máx. 50 |
| Modo aleatorio | Activar >100 envíos |
| Cuentas SMTP activas | Mínimo 3 |
| Modo pruebas primero | Testear con 2-3 emails |

---

## 📝 Changelog

### v2.2 — 10 Oct 2026
- 🔄 **Reorganización completa de carpetas**: `api/` (7), `cli/` (2), `data/` (1), `tabs/` (6)
- 🗑️ **Limpieza**: 13 archivos basura/debug eliminados
- 📋 **Ficha lead rediseñada**: layout por filas, botón GUARDAR condicional con detección de cambios
- 💬 **WhatsApp integrado**: selector de plantilla en ficha lead, envío con texto precargado vía `wa.me?text=`
- ✏️ **Editor de plantillas rediseñado**: listado scrollable como selector principal, pipeline filtrable, pills toggle Email/WhatsApp por plantilla, sub-selector HTML/Texto Plano
- 🏷️ **Placeholders SENDER**: ahora se reemplazan en previsualización con datos reales de cuenta SMTP
- 📊 **Badges visuales**: emojis 📧/💬 en listado, contador de caracteres WhatsApp con código de colores
- 🔄 **Plantillas categorizadas por estado**: `01 Sin Contactar` a `07 Cerrado Perdido`

### v2.1 — Oct 2026
- 🚀 **Lanzadera**: selects Federación → Estado → Plantilla
- 🔍 **Editor**: A/B testing, previsualización con datos reales
- 👁️ **Tracking**: track.php actualiza estado del lead al abrir email (`Impactado / Abrio Email`)

### v2.0
- 🎯 **Kanban CRM** con pipeline de 7 estados
- ✉️ **SMTP rotativo** con 10 cuentas
- 📊 **Dashboard** con KPIs en tiempo real
- 📝 Editor de plantillas, gestor de datos, scanner de duplicados

---

*FutProtec Outbound CRM v2.2 — Construido con PHP nativo, SQLite3 y Alpine.js para máxima compatibilidad con SiteGround.*