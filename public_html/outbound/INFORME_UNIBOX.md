# INFORME UNIBOX — Auditoría de Respuestas y Notificaciones

**Fecha:** 20/08/2026
**Proyecto:** FutProtec Outbound CRM (`/public_html/outbound`)
**Objetivo:** Diagnóstico de la pestaña "Respuestas" (Unibox), el renderizado de correos y la campana de notificaciones, previo al rediseño UI/UX.

---

## 1. Estructura de la tabla `respuestas`

La tabla `respuestas` tiene **25 columnas**. Puntos clave:

| Columna | Tipo | Observación |
|---|---|---|
| `id` | INTEGER | PK |
| `envio_id` | INTEGER | FK a `envios` (puede ser NULL) |
| `lead_id` | INTEGER | FK a `clubes_crm` (puede ser NULL) |
| `fecha_respuesta` | DATETIME | Fecha de la respuesta |
| `remitente` | TEXT | Email del que responde (NO existe columna `email`) |
| `destinatario` | TEXT | Email al que se respondió |
| `subject` | TEXT | Asunto |
| `cuerpo` | TEXT | Cuerpo en texto plano |
| `contenido_html` | TEXT | Cuerpo HTML (para renderizado) |
| `clasificacion` | TEXT | `PENDING` por defecto; valores: `humana`, `rebote`, `baja`, `fuera_de_oficina`, `automatica`, `desconocida` |
| `estado_procesamiento` | TEXT | `nuevo` por defecto |
| `id_cuenta_smtp` | INTEGER | Cuenta SMTP que recibió la respuesta |
| `message_id` / `in_reply_to` / `references` | TEXT | Para atribución e idempotencia |
| `uid_imap` / `cuenta_uid` | TEXT | Idempotencia IMAP |
| `notificado` | INTEGER | Flag de notificación (0/1) |
| `kanban_movido` | INTEGER | Flag de movimiento Kanban (0/1) |

**IMPORTANTE:** La tabla **NO tiene columna `email`**. El JOIN con `clubes_crm` debe hacerse por `lead_id` o por `remitente` (no por `email`).

---

## 2. Registros actuales en `respuestas`

Solo existen **2 registros**:

| id | remitente | destinatario | subject | clasificacion | carpeta | lead_id | envio_id |
|---|---|---|---|---|---|---|---|
| 5 | `rodrigo@riobabel.com` | `rodrigo@getfutprotec.com` | Re: rodrigo en getfutprotec.com | `humana` | INBOX | **NULL** | **NULL** |
| 6 | `Mailer-Daemon@antispam.mailspamprotection.com` | `adrian.cano@getfutprotec.com` | Mail delivery failed | `rebote` | INBOX | **NULL** | **NULL** |

**Conclusión:** Ambos registros tienen `lead_id=NULL` y `envio_id=NULL`. Por eso el campo de club muestra `—` en la interfaz: no hay envío original atribuido ni coincidencia con `clubes_crm`.

---

## 3. LEFT JOIN `respuestas` + `clubes_crm`

El LEFT JOIN por `lead_id` o `remitente` devuelve **NULL** en `nombre_club` y `email` para ambos registros:

```
[1] r.id=5 r.remitente='rodrigo@riobabel.com' r.lead_id=NULL c.nombre_club=NULL c.email=NULL
[2] r.id=6 r.remitente='Mailer-Daemon@antispam.mailspamprotection.com' r.lead_id=NULL c.nombre_club=NULL c.email=NULL
```

**Causa raíz del `—` en el campo de club:**
1. `lead_id` es NULL (no se atribuyó a ningún club).
2. El remitente (`rodrigo@riobabel.com`, `Mailer-Daemon@...`) no coincide con ningún `email` de `clubes_crm`.

**Nota sobre columnas de `clubes_crm`:** La tabla NO tiene `contacto_nombre`, `volumen_equipos` ni `variante`. Las columnas reales relevantes son:
- `nombre_club`, `persona_contacto`, `email`, `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`, `estado_lead`, `volumen_estimado`, `num_jugadores`, `federacion`.

---

## 4. Cuentas SMTP activas (para auditoría IMAP)

La tabla `cuentas_smtp` tiene columnas: `id`, `email`, `host`, `puerto`, `usuario`, `password`, `seguridad`, `activa`, `limite_diario`, `enviados_hoy`, `ultimo_error`, `ultimo_uso`, `nombre_emisor`, `cargo_emisor`, `driver_sync`.

Hay **10 cuentas activas**, todas con `driver_sync='IMAP'`:

| id | email |
|---|---|
| 1 | rodrigo@getfutprotec.com |
| 2 | mario.ortiz@getfutprotec.com |
| 3 | alvaro.ruiz@getfutprotec.com |
| 4 | carlos.mora@getfutprotec.com |
| 5 | javier.sanz@getfutprotec.com |
| 6 | diego.navarro@getfutprotec.com |
| 7 | pablo.blanco@getfutprotec.com |
| 8 | gonzalo.vega@getfutprotec.com |
| 9 | adrian.cano@getfutprotec.com |
| 10 | sergio.gil@getfutprotec.com |

---

## 5. Script de lectura IMAP (flujo de respuestas)

### Módulo principal
- **Archivo:** `inc/imap_respuestas.php` (1097 líneas).
- **Método:** Sockets directos (NO depende de la extensión PHP `imap`). Compatible SiteGround.
- **Host/Puerto:** `mail.getfutprotec.com:993` (IMAP SSL). Timeout estricto de **5s** por operación.
- **Carpetas auditadas:** `INBOX`, `INBOX.Junk`, `INBOX.spam`.
- **Modo READ-ONLY:** usa `SELECT` readonly y `BODY.PEEK` (no marca mensajes como leídos). No ejecuta STORE/COPY/MOVE/DELETE/EXPUNGE/APPEND.
- **Credenciales:** se obtienen de `cuentas_smtp` (`usuario` y `password`).

### CLI
- **Archivo:** `cli/imap_respuestas.php`.
- **Uso:** `php cli/imap_respuestas.php [--cuenta=EMAIL] [--dry-run] [--verbose]`.
- **Driver:** usa `driver_sync` de cada cuenta (IMAP por defecto, POP3 puerto 995 si configurado).
- **Log:** `logs/imap_sync.log`.
- **Acciones:** registra respuestas en `respuestas`, detiene secuencias de leads que respondieron (`secuencia_lead → DETENIDO`).

### Runner web (temporal)
- **Archivo:** `cli/imap_respuestas_runner.php`.
- **Seguridad:** requiere token `IMAP_RESPUESTAS_20260819`. Sin `apply=1` solo audita (no escribe en BD).
- **Nota:** archivo marcado para eliminación tras verificación.

### Atribución de respuestas
`imap_atribuir()` busca el envío original en `envios` por prioridad:
1. **In-Reply-To** (message_id del envío original).
2. **References** (puede contener varios message_id).
3. **Email remitente** (último envío a ese email con estado `enviado`).

**Conclusión:** Los 2 registros actuales tienen `lead_id=NULL` porque `imap_atribuir()` no encontró ningún envío en `envios` que coincida (los emails de prueba no corresponden a envíos reales).

### Cron
- `cli/cron.php` es **solo para envíos salientes** (no lee IMAP). La lectura de respuestas se invoca por CLI o runner web.

---

## 6. Diagnóstico del problema del Unibox

### 6.1 Campo de club muestra `—`
**Causa:** Los registros de `respuestas` tienen `lead_id=NULL` y el remitente no coincide con ningún `email` de `clubes_crm`. El JOIN no encuentra coincidencia.

**Recomendación para el rediseño:**
- El JOIN debe usar `respuestas.lead_id = clubes_crm.id` **O** `LOWER(respuestas.remitente) = LOWER(clubes_crm.email)`.
- Para respuestas sin atribución, mostrar un fallback con el email del remitente en lugar de `—`.

### 6.2 Renderizado de correos
- La tabla tiene `contenido_html` y `cuerpo`. El visor debe:
  - Si existe `contenido_html` → renderizar en contenedor sanitizado.
  - Si existe `cuerpo` → aplicar `white-space: pre-wrap; font-family: sans-serif; font-size: 14px; line-height: 1.6;`.

### 6.3 Campana de notificaciones
- La tabla tiene columna `notificado` (0/1) y se registran eventos `notificacion_respuesta` en `comunicaciones_log` para respuestas `humana`.
- El binding Alpine.js debe usar `x-show="rsNuevas > 0"` y `x-text="rsNuevas"` (sin llaves `{{ }}` huérfanas).

---

## 7. Recomendaciones para el rediseño Unibox Split-View

1. **Panel izquierdo (35%):** lista de triaje con buscador por `nombre_club` y filtro por clasificación (`Todas`, `Interesado`, `Duda Precio`, `Baja`). Tarjeta con `nombre_club`, fecha, `persona_contacto`, badge de volumen (`volumen_estimado`), snippet de `cuerpo` (110 chars) y badge de intención según `clasificacion`.
2. **Panel derecho (65%):** visor de conversación con header de ficha rápida (`nombre_club`, `persona_contacto`, `telefono`, `estado_lead`), hilo de mensajes (enviado derecha / recibido izquierda) y footer con caja de respuesta + plantillas rápidas + botones SMTP y WhatsApp.
3. **API:** refactorizar la consulta SQL con LEFT JOIN estricto y extraer `SUBSTR(cuerpo,1,150) AS snippet`, `cuerpo`, `contenido_html`, `fecha`, y datos del club.
4. **Endpoint de estado:** `POST /api/actualizar_estado_lead` para cambiar `clubes_crm.estado_lead` en tiempo real.

---

*Fin del informe.*
