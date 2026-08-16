# Informe de Auditoría Técnica — CRM Outbound FutProtec

**Objeto:** Documentar el funcionamiento REAL del sistema actual del módulo `public_html/outbound/` para determinar si está correctamente preparado para medir una campaña comercial A/B/C con trazabilidad fiable.

**Fecha de auditoría:** 14/08/2026
**Base de datos analizada:** `public_html/outbound/data/stats.db` (SQLite)
**Método:** Lectura directa de código PHP/JS + esquema y datos reales de SQLite.

> Convención: cuando una funcionalidad no pueda verificarse con el material disponible, se indica explícitamente **"NO VERIFICABLE CON LA INFORMACIÓN DISPONIBLE"**.

---

## 1. Resumen ejecutivo

El sistema es un CRM monolítico PHP 8 + SQLite (sin frameworks externos) que **sí almacena la mayor parte de los datos necesarios** (leads, envíos, aperturas por píxel, cuentas SMTP, eventos manuales, estados comerciales, presupuestos y snapshots). Sin embargo, existe una **desconexión estructural crítica** entre los tres puntos donde se registra la variante A/B/C y el envío real:

1. La variante se asigna **en el navegador** (`js/app.js`) de forma aleatoria no balanceada.
2. La variante solo se guarda en la tabla `comunicaciones_log.variante_ab` (evento de envío) y en `lead_pipelines.variante_ab` (esta última tiene únicamente 5 registros de prueba).
3. La tabla `envios` (donde viven `tracking_id`, `asunto`, `cuerpo_mensaje`, `cuenta_emision`) **NO tiene columna de variante, ni de campaña, ni de plantilla**, y la tabla `aperturas` tampoco.

Como consecuencia, **NO es posible hoy reconstruir de forma fiable** la cadena `LEAD → CAMPAÑA → VARIANTE → SMTP → EMAIL ENVIADO → EVENTOS → RESPUESTA → INTERÉS → PROPUESTA → NEGOCIACIÓN → GANADO/PERDIDO`, principalmente porque:

- **No existe el concepto de "campaña"** como entidad persistida (la tabla `pipelines` está vacía a efectos prácticos: 1 registro de prueba).
- **La variante no viaja dentro del envío** (`envios` no la guarda), por lo que una apertura (`aperturas`) no puede asociarse directamente a la variante que la provocó salvo a través de un JOIN indirecto y con pérdidas.
- **La asignación A/B/C de la lanzadera ignora el flag `test_ab` de la plantilla**: el frontend sortea A/B/C siempre, pero el backend (`enviar_lote.php`) solo usa el contenido de variante B/C si `test_ab=1`. Si la plantilla no tiene `test_ab` activo, el lead puede quedar **etiquetado como "B" o "C" habiendo recibido en realidad el contenido "A"**.
- **El tracking de aperturas no filtra bots, precargas ni Apple Mail Privacy Protection**, y no hay deduplicación real; cada carga del píxel inserta una fila.
- **Los rebotes nunca se registran** (la tabla existe pero ningún código escribe en ella).
- **No hay click tracking ni detección automática de respuestas** (IMAP/webhook).

En resumen: el sistema dispone de la **infraestructura** (tablas y endpoints) para una trazabilidad parcial, pero **la medición A/B/C fiable no está implementada de extremo a extremo con el código actual**.

---

## 2. Arquitectura actual

### 2.1 Estructura de archivos (módulo outbound)

| Archivo | Finalidad |
|---|---|
| `dashboard.php` | Panel principal (login + endpoints AJAX + render del Kanban). |
| `api/enviar_lote.php` | Envía un email individual desde la lanzadera (SMTP nativo por socket). |
| `api/enviar_smtp_random.php` | Script CLI autónomo de envío por lotes (legacy, lectura desde `clubes.json`). |
| `api/get_cola.php` | Genera la cola de envíos de la lanzadera y asigna SMTP. |
| `api/track.php` | Píxel de seguimiento de apertura (registra en `aperturas`). |
| `api/baja.php` | Página pública de baja (pone `estado_lead='Lista Negra'`). |
| `api/leads.php` | CRUD de leads, duplicados, plantillas, línea de tiempo, validación de email. |
| `api/smtp.php` | CRUD + test de cuentas SMTP. |
| `cli/init_db.php` | Crea el esquema y migra contactos desde `clubes.json` + CSV. |
| `cli/cron.php` | Envío cron autónomo (un email por ejecución). |
| `js/app.js` | Lógica Alpine.js: lanzadera, editor, analytics, kanban. |
| `tabs/*.php` | Vistas parciales: kanban, gestor, editor, smtp, lanzadera, analytics, followups, modals. |

### 2.2 Motor y entorno

- El motor se controla por la clave `config.motor_estado` (`activo`/`pausado`). Actualmente **`pausado`**.
- El entorno real es **`modo_entorno = test`** (confirmado en `config`). Esto implica que, a menos que se cambie, los envíos en la lanzadera se redirigen a destinos de prueba.
- `delay_envio=3`, `lote_envio=10`, `lanzadera_delay=8`.
- Autenticación del panel por contraseña fija hardcodeada (`dashboard.php`, constante `AUTH_KEY='FutProtec2026!'`).

---

## 3. Base de datos

Motor: **SQLite** (`PRAGMA journal_mode=WAL`, `foreign_keys=ON` en `init_db.php`). Archivo único: `data/stats.db`.

### 3.1 Tablas existentes (verificadas en `sqlite_master`)

| Tabla | Finalidad |
|---|---|
| `clubes_crm` | Leads/clubes (1813 registros). |
| `envios` | Histórico de envíos de email (2 registros). |
| `aperturas` | Registros de apertura por píxel (0 registros). |
| `rebotes` | Rebotes (0 registros; **nunca se escribe**). |
| `cuentas_smtp` | Cuentas SMTP para envío (10 registros). |
| `plantillas` | Plantillas de email/WhatsApp (7 registros). |
| `plantillas_new` | Tabla residual de migración (no usada). |
| `comunicaciones_log` | Línea de tiempo de eventos por lead (25 registros). |
| `config` | Configuración clave/valor. |
| `pipelines` | "Campañas"/pipelines (1 registro de prueba). |
| `lead_pipelines` | Relación lead↔pipeline↔variante (5 registros de prueba). |
| `mockups` | Solicitudes de mockups (0 registros). |
| `presupuestos` | Presupuestos por lead (0 registros). |
| `snapshots` | Fotografías históricas del funnel (2 registros). |
| `_migraciones` | Registro de ejecución de migraciones DDL. |

### 3.2 Detalle por tabla (campos, tipos, claves)

#### `clubes_crm` (leads)
- **PK:** `id`
- **Campos:** `nombre_club`, `federacion`, `persona_contacto`, `cargo_contacto`, `email` (UNIQUE), `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`, `estado_lead`, `observaciones`, `ultimo_contacto`, `creado_el`, `es_duplicado`, `duplicado_id`, `estado_lead_backup`, `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida`.
- **Relaciones:** 1:N con `envios` (por `email`, no por FK), `comunicaciones_log`, `mockups`, `presupuestos`, `lead_pipelines`.
- **Índices:** `idx_crm_email`, `idx_crm_estado`, `idx_crm_federacion` + autoindex sobre `email` UNIQUE.
- **Nota:** no tiene `variante` ni `campaign_id` propios; la relación con campaña/variante vive en `lead_pipelines` (N:M con `pipelines`).

#### `envios` (envíos)
- **PK:** `id`
- **Campos:** `club`, `email`, `federacion`, `cuenta_emision`, `fecha_envio`, `estado` (`pendiente`/`enviado`/`error`/`abierto`), `tracking_id` (UNIQUE), `asunto`, `cuerpo_mensaje`.
- **Relaciones:** 1:N con `aperturas` (FK sobre `tracking_id`).
- **Índices:** `idx_envios_estado`, `idx_envios_cuenta`, `idx_envios_tracking` + autoindex sobre `tracking_id`.
- **FALTAN (crítico para A/B/C):** `variante_ab`, `campaña/pipeline_id`, `plantilla_id`, `lead_id`.

#### `aperturas`
- **PK:** `id`
- **Campos:** `tracking_id` (FK → `envios.tracking_id`), `fecha_apertura`, `ip`, `user_agent`.
- **Índice:** `idx_aperturas_tracking`.
- **Nota:** no guarda variante, campaña, ni email directamente (solo vía `tracking_id` → `envios.email`).

#### `comunicaciones_log` (eventos/timeline)
- **PK:** `id`
- **Campos:** `lead_id`, `club_id`, `tipo_evento`, `plantilla_id`, `detalles`, `ip_registro`, `fecha`, `id_cuenta_smtp`, `tipo`, `resultado`, `codigo_error`, `variante_ab`, `pipeline_id`, `resumen`, `proxima_accion`, `canal`.
- **Índices:** `idx_comlog_lead`, `idx_comlog_club`, `idx_comlog_cuenta`, `idx_comlog_fecha`.
- **Aquí es donde se registra `variante_ab`** para el evento `envio_email` (verificado: 2 registros con variante `'A'`).

#### `cuentas_smtp`
- **PK:** `id`
- **Campos:** `email` (UNIQUE), `host`, `puerto`, `usuario`, `password`, `seguridad` (`ssl`), `activa`, `limite_diario` (50), `enviados_hoy`, `ultimo_error`, `ultimo_uso`, `nombre_emisor`, `cargo_emisor`.
- **Relaciones:** 1:N con `comunicaciones_log` (por `id_cuenta_smtp`).

#### `plantillas`
- **PK:** `id`
- **Campos:** `nombre`, `asunto`, `cuerpo`, `tipo`, `categoria`, `activo`, `fecha_creacion`, **`asunto_b`, `test_ab`, `cuerpo_b`, `cuerpo_c`, `asunto_c`**.
- **Campos A/B/C reales:** `asunto_b`, `asunto_c`, `cuerpo_b`, `cuerpo_c`, `test_ab`.
- **Nota:** la lanzadera guarda el contenido de variante en el backend solo si `test_ab=1`.

#### `pipelines` (campañas)
- **PK:** `id`
- **Campos:** `nombre`, `descripcion`, `fecha_inicio`, `fecha_fin`, `variante_ganadora`, `activo`, `created_at`.
- **Estado real:** 1 registro — `"Experimento Fase 1 TEST"` (descripción "NO REAL"). No se usa como entidad de campaña en los envíos.

#### `lead_pipelines`
- **PK:** `id`
- **Campos:** `lead_id` (FK), `pipeline_id` (FK), `variante_ab`, `fecha_asignacion`, `UNIQUE(lead_id, pipeline_id)`.
- **Estado real:** 5 registros de prueba (variantes A×2, B×2, C×1), asignados al pipeline "TEST".
- **Nota:** la lanzadera actual **NO escribe** en esta tabla.

#### `rebotes`
- **PK:** `id`
- **Campos:** `email`, `motivo`, `fecha_rebote`.
- **Estado real:** 0 registros. **Ningún código PHP hace `INSERT INTO rebotes`** (verificado por búsqueda global).

#### `presupuestos`, `mockups`, `snapshots`
- Existen con esquema completo (ver `presupuestos`: versiones, unidades, importe, margen, condiciones; `mockups`: lead, estado, solicitado/enviado; `snapshots`: counters del funnel).
- **Estado real:** `presupuestos` = 0, `mockups` = 0, `snapshots` = 2 (ambas con el estado histórico "1812 Sin Contactar / 1 Respondió").

### 3.3 Relaciones
- `clubes_crm` —< `envios` (N:1 por email, sin FK explícita sobre club).
- `envios` —< `aperturas` (1:N por `tracking_id`, FK).
- `clubes_crm` —< `comunicaciones_log` (1:N por `lead_id`/`club_id`).
- `clubes_crm` >—< `pipelines` (N:M vía `lead_pipelines`).
- `cuentas_smtp` —< `comunicaciones_log` (1:N).
- No existen FKs entre `envios` y `lead_pipelines`, lo cual rompe la trazabilidad campaña→envío.

---

## 4. Gestión de leads

### 4.1 Volumen real
- **Total en `clubes_crm`:** 1813.
- **Distribución por estado:** `01 Sin Contactar` = 1812, `03 Respondió` = 1.
- **Duplicados marcados:** `es_duplicado=1` → 66; `es_duplicado=0` → 1747.

### 4.2 Campos identificadores de un club
- `id` (PK), `nombre_club`, `email` (único, normalizado a minúsculas), `federacion`, `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`.

### 4.3 Datos comerciales presentes
- `estado_lead`, `observaciones`, `ultimo_contacto`, `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida`.
- **Ausentes en el lead:** `variante`, `campaign_id`, `fecha_respuesta`, `motivo_rebote` (los rebotes están en otra tabla).

### 4.4 Identidad del contacto
- Se dispone de `persona_contacto` y `cargo_contacto`, pero **la mayor parte proviene de una migración desde `clubes.json` sin esos datos** (el campo se usa poco; en los 2 envíos existentes el nombre de contacto no está correlacionado).

### 4.5 ¿Puede un mismo lead participar en varias campañas sin perder histórico?
- La estructura `lead_pipelines` (N:M) está pensada para ello, pero **está vacía a efectos reales** (5 filas de prueba). La relación con "campaña" no está ligada a los envíos (`envios` no tiene `pipeline_id`). Por tanto: **NO VERIFICABLE / no implementado de forma operativa.** Un lead solo tiene un `estado_lead` global, que se machaca al cambiar de estado; no hay histórico de estados por campaña (solo los `cambio_estado` en `comunicaciones_log`).

---

## 5. Sistema de envío

### 5.1 Selección de leads
- **Lanzadera (`get_cola.php`):** filtra `clubes_crm` por `email NOT NULL`, `es_duplicado=0`, y opcionalmente por `estado_lead` y `federacion`. **NO filtra por "Lista Negra"/baja** salvo que el operador elija ese estado.
- **Cron (`cron.php`):** selecciona `estado_lead='01 Sin Contactar'` sin envío previo.
- **CLI legacy (`enviar_smtp_random.php`):** lee `clubes.json`, no `clubes_crm`.

### 5.2 Selección de variante
- **Lanzadera → `js/app.js` (`iniciarMotor`):** `Math.random()` → A/B/C (33,3% cada uno), **siempre**, sin comprobar el `test_ab` de la plantilla ni balancear.
- **Backend `enviar_lote.php`:** acepta `variante_ab` por POST (default `'A'`), y solo aplica contenido de variante B/C si `test_ab=1`. **Registra la letra en `comunicaciones_log.variante_ab` siempre** (aunque no cambie el contenido).
- **CLI legacy:** reparte 33/33/33 o 50/50 según `test_ab`/`asunto_c`.

### 5.3 Asignación SMTP
- **Lanzadera (`get_cola.php`):** round-robin secuencial (modo normal) o aleatoria (modo 🎲), respetando límite diario. Asigna `smtp_asignada_id`.
- **`enviar_lote.php`:** usa el `id_cuenta_smtp` que le llega por POST (validado contra BD y límite).
- **Legacy/cron:** selección aleatoria (legacy) o "menor `enviados_hoy`" (cron).

### 5.4 Evitación de duplicados
- `tracking_id` UNIQUE en `envios`. Pero **no hay** deduplicación de reenvío al mismo lead: se puede enviar el mismo club múltiples veces (el `get_cola` no excluye leads ya enviados).

### 5.5 Registro del envío
- `enviar_lote.php` inserta en `envios` (con `asunto` y `cuerpo_mensaje` completos) y en `comunicaciones_log` (`tipo_evento='envio_email'`, `variante_ab`, `id_cuenta_smtp`, `resultado`).
- Escribe además un archivo de log diario en `logs/envios_YYYY-MM-DD.log`.

### 5.6 Qué ocurre si falla / si un SMTP falla / retry
- Si el SMTP devuelve error, se guarda `estado='error'` en `envios`, `resultado='error'` + `codigo_error` en `comunicaciones_log`, y `ultimo_error` en `cuentas_smtp`.
- **No existe reintento (retry) ni backoff** en la lanzadera (`enviar_lote.php` no reintenta; solo reporta error). Tampoco hay encolamiento de reintentos.

### 5.7 Velocidad y limitación por SMTP
- Delay entre envíos: `lanzadera_delay` (=8s) en frontend; en CLI se usa `--delay`.
- Límite por cuenta: `limite_diario` (50). En `enviar_lote.php` se valida recalculando `COUNT(comunicaciones_log WHERE tipo_evento='envio_email' AND DATE(fecha)=DATE('now'))`; en `get_cola.php` usa el mismo conteo. **En legacy/cron se usa el campo `enviados_hoy`, que NO se resetea a cero al cambiar de día** (no hay ningún `UPDATE ... SET enviados_hoy=0` ligado a fecha).

### 5.8 Identificación del mensaje enviado
- Se usa `tracking_id` = `fut_<hex(timestamp)>_<random>`. Lo guarda `envios` y lo expone `track.php`.

### 5.9 Conclusión del punto clave
**¿Podemos saber qué email recibió cada club, con qué variante, desde qué SMTP y cuándo?**
- **SMTP y fecha:** SÍ, en `envios.cuenta_emision` + `envios.fecha_envio`.
- **Qué email/contenido:** SÍ, en `envios.asunto` + `envios.cuerpo_mensaje` (se guarda el contenido completo).
- **Variante:** PARCIALMENTE. La variante **no está en `envios`**; está en `comunicaciones_log.variante_ab` (por evento) y hay que unir por `lead_id`/`email`. Esta unión es frágil y no garantiza correspondencia unívoca envío↔variante si hay reenvíos.

---

## 6. SMTP

### 6.1 Dónde están configuradas
- Tabla `cuentas_smtp` (10 cuentas, dominio `@getfutprotec.com`, host `mail.getfutprotec.com`, puerto 465, SSL).
- También existe **array hardcodeado** en `api/enviar_smtp_random.php` (`$CUENTAS_SMTP_FALLBACK`) con contraseñas, usado solo como fallback del script legacy. `init_db.php` también tiene un array de preseed con contraseñas.

### 6.2 Selección
- `get_cola.php`: **round-robin** por defecto o **aleatoria** (modo 🎲).
- `enviar_smtp_random.php`: **aleatoria** (`ORDER BY RANDOM()`).
- `cron.php`: **la de menor `enviados_hoy`**.

### 6.3 ¿Se registra la cuenta utilizada?
- SÍ. En `envios.cuenta_emision` (alias email) y en `comunicaciones_log.id_cuenta_smtp`.

### 6.4 Límite diario y contador
- `limite_diario` (50) y `enviados_hoy`. 
- **Problema:** hay **dos mecanismos de conteo divergentes** (campo `enviados_hoy` incrementado pero nunca reiniciado vs. `COUNT(comunicaciones_log)` por día). `enviar_lote.php` y `get_cola.php` usan el conteo real; `enviar_smtp_random.php` y `cron.php` usan el campo desactualizado.

### 6.5 Control de errores y reputación
- Hay `ultimo_error` y `ultimo_uso` por cuenta, y `test_smtp` valida autenticación. **No hay** control de reputación ni de dominio/remitente asociado a nivel de métricas (solo `nombre_emisor`/`cargo_emisor` para el From).

### 6.6 ¿Podemos analizar resultados por SMTP y detectar anomalías?
- PARCIALMENTE. Podemos agrupar envíos por `cuenta_emision` y por `id_cuenta_smtp` en `comunicaciones_log`, pero **no podemos ligar una apertura a una cuenta SMTP directamente** (las aperturas cuelgan de `envios.tracking_id`, y `envios` sí tiene `cuenta_emision`, así que por ahí sí es recuperable la SMTP de una apertura). La anomalía de "envíos aceptados pero no entregados" **no es detectable** porque no hay rebotes reales.

---

## 7. Tracking de aperturas

Archivo: `api/track.php`.

### 7.1 Identificador
- `tracking_id` se genera en el envío (`envios`) y se incrusta como `<img src="https://getfutprotec.com/outbound/api/track.php?id=<tracking_id>">`.

### 7.2 Asociaciones
- **Lead:** vía `envios.tracking_id` → `envios.email` → `clubes_crm.email` (JOIN textual por email).
- **Campaña:** **NO** se asocia (no hay campaign/pipeline en envios ni en aperturas).
- **Variante:** **NO** se asocia directamente. `aperturas` no guarda variante; `envios` no guarda variante. Solo se podría inferir cruzando con `comunicaciones_log` por `lead_id/email`, con pérdidas.

### 7.3 Información guardada
- `tracking_id`, `fecha_apertura`, `ip`, `user_agent`.
- Se actualiza `envios.estado='abierto'` (solo si estaba `='enviado'`).
- Se añade una observación al lead: `[TRACKING ...] Email abierto (tracking: ...)`, sin cambiar estado Kanban (correcto, apertura = evento).

### 7.4 Número de aperturas / primera / última
- Se inserta **una fila por cada carga del píxel**. No hay lógica de "solo primera apertura" ni deduplicación. "Número de aperturas" = `COUNT(*)` por tracking (contando recargas), no aperturas únicas reales.
- `dashboard.php` calcula "aperturas" como `COUNT(DISTINCT tracking_id)`, es decir, "leads que abrieron al menos una vez" (más ajustado que `COUNT(*)`).

### 7.5 Riesgos / falsos positivos
- **Bots, escáneres de antivirus, proxies y precarga de imágenes** generan aperturas falsas (el píxel no distingue el origen).
- **Apple Mail Privacy Protection (MPP) y similares** precargan píxeles desde sus propios proxies, lo que genera **aperturas automáticas no humanas**. El sistema no detecta ni filtra este caso.
- **No hay** cabeceras `X-` de verificación ni lógica anti-bot.

### 7.6 Qué significa una "apertura" registrada
- Una apertura en este sistema significa **"el píxel fue solicitado por un cliente HTTP"** — no necesariamente "una persona abrió y leyó el correo". **Grado de confianza: bajo-medio** para inferir interés real. No debe tomarse como indicador fiable sin limpieza de falsos positivos.

---

## 8. Click tracking

**NO implementado.**
- No existe `click.php` ni ningún endpoint que envuelva/redirija enlaces.
- Los enlaces en las plantillas son `href` directos: `https://getfutprotec.com/contacto`, `https://getfutprotec.com/outbound/api/baja.php?email=...`.
- **No hay** tabla `clicks`, ni registro de URL original/URL de tracking, ni contador de clicks.
- **No es posible** distinguir click en web / WhatsApp / baja / otros, porque no se rastrea ningún enlace.

---

## 9. Respuestas

### 9.1 ¿Cómo se detectan actualmente?
- **Manual.** No hay IMAP, webhook, ni importación automática de respuestas.
- Un operador registra la respuesta de dos formas:
  1. Cambia `estado_lead` (ej. a `03 Respondió`), lo que genera `comunicaciones_log` con `tipo_evento='cambio_estado'`.
  2. Usa `registrar_interaccion` (nota manual con `canal`, `resumen`, `resultado`, `proxima_accion`).

### 9.2 Qué ocurre cuando un club responde
- Nada automático. El sistema no recibe el reply; el operador debe leer el buzón por su cuenta y actualizar el lead manualmente.

### 9.3 Clasificación de respuestas
- **No existe** clasificación automática en: positiva / negativa / neutra / fuera de oficina / rebote / petición de baja / interesado / no interesado.
- La única "clasificación" es la columna `estado_lead` (Kanban comercial) por acción manual del operador. No hay un campo de sentimiento de respuesta distinto del estado.

---

## 10. Bajas

Archivo: `api/baja.php`.

### 10.1 Cómo se genera el enlace
- En las plantillas HTML se incluye: `https://getfutprotec.com/outbound/api/baja.php?email={{EMAIL}}`.
- El enlace va **sin token ni hash**: identifica al lead solo por su email en texto plano (URL).

### 10.2 Qué campo modifica
- `UPDATE clubes_crm SET estado_lead='Lista Negra', observaciones=[...baja...] WHERE email=:email`.
- **No** escribe en `rebotes` ni en una tabla global de supresión.

### 10.3 ¿La baja es inmediata?
- Sí, en cuanto se procesa la petición GET (se ejecuta el UPDATE).

### 10.4 ¿Bloquea futuros envíos?
- **Parcialmente / NO de forma global.**
  - `get_cola.php` **no excluye** `Lista Negra` (solo filtra duplicados y el estado que el operador elija).
  - `cron.php` sí lo excluye implícitamente (solo elige `01 Sin Contactar`).
  - `enviar_lote.php` **no comprueba la baja antes de enviar**.
  - `leads.php` (`save_nuevo_lead` y `add_lead` en `dashboard.php`) **no comprueba** si el email ya está en lista de supresión; solo evita duplicados por email.
- **No existe lista global de supresión** como tabla dedicada. Un email dado de baja puede reingresar si se reimporta o se añade manualmente, y podría incluirse en una cola si el filtro de estado no lo impide.

---

## 11. Rebotes y entregabilidad

### 11.1 ¿El sistema registra rebotes?
- **NO.** La tabla `rebotes` existe pero **ningún código escribe en ella** (búsqueda global de `INSERT INTO rebotes` = 0 resultados). `rebotes` = 0 registros.

### 11.2 Clasificación de rebotes
- No hay hard bounce / soft bounce / mailbox full / invalid recipient / SMTP rejection / delivery failure. Solo `motivo` (texto libre) que nadie rellena.

### 11.3 Aceptación SMTP vs. entrega real
- El sistema solo puede saber que **el servidor SMTP devolvió `250` tras `DATA`** (en `enviar_lote.php` y `cron.php` `enviarSMTPAutenticado`/`enviarSMTP`).
- Eso **NO garantiza entrega al buzón**. Un mensaje puede ser aceptado por el relay y luego rebotado o entregado a spam.
- **Conclusión:** **NO es posible conocer la entrega real (delivered) ni clasificar rebotes con la información actual.** La métrica "Entregados" del dashboard es una estimación (`Contactados - Rebotes` con `rebotes=0`), por lo que en la práctica iguala "enviados" a "entregados".

---

## 12. Sistema A/B/C

### 12.1 Cómo se asignan las variantes
- **Lanzadera (vía UI):** `js/app.js` sortea `Math.random()` → A (33%), B (33%), C (33%) **independientemente del flag `test_ab`** de la plantilla. Pasado al backend por POST `variante_ab`.
- **CLI legacy:** sortea 33/33/33 (si hay `asunto_c` y `test_ab`) o 50/50 (A/B), o solo A.
- **Cron:** **no implementa variantes** (usa siempre el asunto base `plantillas.asunto`); no hay lógica A/B/C.

### 12.2 ¿Distribución equilibrada?
- En lanzadera es aleatoria no forzada; **no hay bloqueo para garantizar equilibrio** (puede quedar 40/30/30%). 
- Además, si la plantilla no tiene `test_ab=1`, la letra sorteada no cambia el contenido, rompiendo cualquier lectura.

### 12.3 ¿Asignación antes del envío / puede cambiar después?
- La variante se decide en el instante del envío (no queda asignada previamente en `lead_pipelines`). 
- El registro persistido (`comunicaciones_log.variante_ab`) no se modifica después, pero **`lead_pipelines.variante_ab` está desconectado del envío** y puede variar sin relación con lo enviado.

### 12.4 ¿Queda registrada la variante por lead?
- En `comunicaciones_log.variante_ab` (evento de envío) para la lanzadera. 
- **NO en `envios`** (sin columna). 
- **NO en `aperturas`**.

### 12.5 ¿Queda registrado el asunto completo y el cuerpo? ¿o solo A/B/C?
- **Envíos (`envios`):** guarda `asunto` y `cuerpo_mensaje` **completos y ya renderizados** (con placeholders sustituidos), pero **sin etiqueta de variante**.
- **Eventos (`comunicaciones_log`):** guarda solo la letra `variante_ab`, y `detalles` con nombre de plantilla. No guarda asunto/cuerpo.
- **Plantillas:** guardan `asunto_b/c` y `cuerpo_b/c` (solo para plantillas id 1 y 2 con `test_ab=1`).

### 12.6 ¿Podemos reconstruir A→enviados→aperturas→...→ventas por variante?
- **NO de forma fiable.**
  - El dashboard A/B/C (`dashboard.php`, `get_analytics` tab `dashboard`) **calcula por `lead_pipelines.lp.variante_ab`**, que solo tiene 5 registros de prueba. No refleja las variantes reales de los envíos.
  - Los envíos reales registran variante en `comunicaciones_log`, pero no hay JOIN entre `aperturas` y `comunicaciones_log` por variante.
  - Las respuestas/ventas (estado_lead `03...09`) no guardan la variante que las originó; solo se pueden cruzar por `lead_id` contra `comunicaciones_log` o `lead_pipelines`, y con reenvíos el vínculo es ambiguo.

---

## 13. Kanban

### 13.1 Columnas actuales
Definidas en `dashboard.php` (`$estadosKanban`):
1. `01 Sin Contactar`
2. `02 Contactado`
3. `03 Respondió`
4. `04 Interesado`
5. `05 Cualificado`
6. `06 Propuesta`
7. `07 Negociación`
8. `08 Ganado`
9. `09 Perdido`

### 13.2 Reglas que hacen avanzar un lead
- Envío OK (no test) → `02 Contactado` (en `enviar_lote.php`).
- Envío en test → solo nota, no cambia estado.
- `mockup_solicitar` → `06 Propuesta`.
- Cambios manuales por operador → `update_lead` (cualquier estado), registrando `cambio_estado`.
- **No hay automatización de avance por apertura/click/respuesta** (la apertura NO cambia estado; la respuesta tampoco, salvo acción manual).

### 13.3 Eventos vs. estados
- **NO están separados de forma limpia.** 
  - `estado_lead` (Kanban) es un **estado comercial**, no un evento.
  - Los **eventos** (envío, apertura, cambio_estado, mockup, presupuesto) viven en `comunicaciones_log` / `aperturas` / `envios` / `mockups` / `presupuestos`.
  - El valor `03 Respondió` es un estado comercial, pero "Respondió" también se infiere en `get_followups` consultando `cambio_estado` con `LIKE '%Respondió%'`. Hay mezcla de semántica entre estado y evento.
- El problema práctico: los "eventos" solo se reconstruyen parcialmente porque las aperturas no escriben en `comunicaciones_log` (solo en `aperturas` + observación).

---

## 14. Eventos

### 14.1 Registro de eventos disponibles
| Evento | Tabla / mecanismo |
|---|---|
| Email enviado | `envios` + `comunicaciones_log` (`tipo_evento='envio_email'`). |
| Email abierto | `aperturas` (píxel) + observación del lead. **No** en `comunicaciones_log`. |
| Click | **No existe**. |
| Cambio de estado | `comunicaciones_log` (`tipo_evento='cambio_estado'`). |
| Mockup solicitado/enviado | `mockups` + `comunicaciones_log` (`mockup_solicitado`/`mockup_enviado`). |
| Presupuesto creado | `presupuestos` + `comunicaciones_log` (`presupuesto_creado`). |
| Nota / interacción manual | `comunicaciones_log` (`registrar_interaccion`, `nota_manual`). |
| Baja | `clubes_crm.estado_lead='Lista Negra'` (observación). **No** en `comunicaciones_log`. |
| Rebote | **No existe** (tabla vacía). |

### 14.2 Observaciones sobre completitud
- Los `tipo_evento` presentes en la BD hoy: `cambio_estado` (23) y `envio_email` (2).
- No hay un `tipo_evento` para apertura, click, baja, ni rebote, pese a que algunos tienen tabla propia.

---

## 15. Métricas actuales

### 15.1 Métricas globales (scorecards, `dashboard.php`)
| Métrica | Fórmula (código) | Fuente | Fiabilidad |
|---|---|---|---|
| Total leads | `COUNT(clubes_crm)` | `clubes_crm` | OK (1813). Incluye 66 duplicados marcados. |
| Envíos totales | `COUNT(envios WHERE estado='enviado')` | `envios` | OK con el dato guardado (2). |
| Tasa de apertura | `COUNT(DISTINCT tracking_id aperturas) / envíos_enviados * 100` | `aperturas`/`envios` | Poco fiable (aperturas=0 hoy; píxel con falsos positivos). |
| Rebotes totales | `COUNT(rebotes)` | `rebotes` | Siempre 0 (no se escribe). |
| Tasa de rebote | `rebotes / envíos_enviados * 100` | `rebotes`/`envios` | No fiable. |
| Leads de baja | `COUNT(clubes_crm WHERE estado IN ('Opt-Out','Unsubscribed','Lista Negra'))` | `clubes_crm` | Aproximada (no es lista global). |
| SMTP activas | `COUNT(cuentas_smtp WHERE activa=1)` | `cuentas_smtp` | OK (10). |
| SMTP enviados hoy | `SUM(enviados_hoy)` | `cuentas_smtp` | No fiable (contador no reiniciado). |

### 15.2 Métricas del funnel (tab Analytics)
- Se construye en `dashboard.php` (`get_analytics`, tab `dashboard`) con `stageOrder` sobre `estado_lead`:
  1. Contactados = `stageOrder>=2`
  2. Entregados = Contactados − Rebotes (con `rebotes=0`)
  3. Abrieron = leads con ≥1 apertura (JOIN envios/aperturas)
  4. Respondieron = `stageOrder>=4`
  5. Resp. positivas = `stageOrder>=5`
  6. Cualificados = `volumen_estimado>=50 AND stageOrder>=6`
  7. Oportunidades = `stageOrder>=7`
  8. Mockups enviados = `DISTINCT mockups.lead_id estado='enviado'`
  9. Presupuestos = `DISTINCT presupuestos.lead_id`
  10. Negociaciones = `stageOrder>=8`
  11. Ganados = `stageOrder=9`
  12. Perdidos = `stageOrder=10`
- **Fiabilidad:** las métricas 2 (Entregados) asumen que no hay rebotes; las 4-12 dependen de la correcta actualización manual de `estado_lead`; **no hay histórico automático** salvo `snapshots` (2 filas).

### 15.3 Métricas A/B/C (comparativa)
- En `get_analytics` se calculan por `variante_ab` desde **`lead_pipelines`** (5 filas de prueba), con métricas: leads, entregados, rebotes, aperturas, tasa apertura, respondió, tasa respuesta, interesado, cualificado, propuesta, mockups, presupuestos, negociación, ganado, perdido, conversión, facturación, pares, ticket medio, fact/100, pares/100.
- **Fiabilidad: MUY BAJA** porque `lead_pipelines` no se alimenta con los envíos reales. Toda esta comparativa queda desconectada de la operación real.

### 15.4 Métricas solicitadas y su estado
| Métrica | ¿Calculada? | Observación |
|---|---|---|
| Enviados | Sí | OK en `envios`. |
| Entregados | Sí (estimado) | = enviados − rebotes; sin rebotes reales. |
| Aperturas | Sí (píxel) | Con falsos positivos; no único fiable. |
| Open rate | Sí | Aperturas/enviados; no sobre entregados. |
| Clicks | **No** | No hay tracking. |
| CTR | **No** | — |
| Respuestas | Parcial | Solo manual vía estado/interacción. |
| Reply rate | Parcial | Solo si estado `03`+; no automatizado. |
| Respuestas positivas | Parcial | `estado>=05` manual. |
| Positive reply rate | Parcial | Derivada del estado. |
| Bajas | Parcial | `Lista Negra`/`Opt-Out`/`Unsubscribed`. |
| Rebotes | **No** | Tabla vacía. |
| Propuestas | Parcial | `estado>=07` / `presupuestos`. |
| Oportunidades | Parcial | `estado>=07`. |
| Ventas | Parcial | `estado='08 Ganado'`. |
| Conversiones | Parcial | Ganados/leads, sobre `lead_pipelines` (vacía). |

---

## 16. Trazabilidad Lead → Campaña → Variante → SMTP → Resultado

### 16.1 Flujo actual real
```
clubes_crm (lead)
   └─ lanzadera (js) decide variante A/B/C (client-side, no balanceada)
        └─ enviar_lote.php envía vía SMTP y escribe:
             • envios (tracking_id, asunto, cuerpo, cuenta_emision, fecha)   ← NO variante
             • comunicaciones_log (variante_ab, id_cuenta_smtp, plantilla_id) ← sí variante
   └─ track.php (píxel) escribe:
             • aperturas (tracking_id, ip, ua)                                ← NO variante, NO campaña
   └─ operador cambia estado_lead (respuesta/interés/venta)                   ← NO variante, NO campaña
   └─ lead_pipelines (variante_ab)                                            ← solo 5 filas test, NO alimentada
```

### 16.2 Verificación por eslabón
| Eslabón | ¿Trazable? | Dónde |
|---|---|---|
| LEAD | Sí | `clubes_crm.id`/`email`. |
| CAMPAÑA | **No operativo** | `pipelines` sin uso real; `envios` sin `pipeline_id`. |
| VARIANTE | Parcial | `comunicaciones_log.variante_ab` (envío); `lead_pipelines.variante_ab` (test). **Rota con reenvíos; no en envios/aperturas**. |
| SMTP | Sí | `envios.cuenta_emision`, `comunicaciones_log.id_cuenta_smtp`. |
| EMAIL ENVIADO | Sí | `envios` (contenido completo guardado). |
| EVENTOS (apertura) | Parcial | `aperturas` → `envios` por `tracking_id`; sin variante/campaña. |
| RESPUESTA | Manual | `estado_lead` / `comunicaciones_log`; sin vínculo automático a envío/variante. |
| INTERÉS/PROPUESTA/NEGOCIACIÓN | Manual | `estado_lead` 04→07; sin variante/campaña asociada. |
| GANADO/PERDIDO | Manual | `estado_lead` 08/09; sin variante/campaña asociada. |

### 16.3 Conclusión de trazabilidad
La cadena **Lead → Variante → SMTP → Email** es parcialmente recuperable (con una unión frágil entre `envios` y `comunicaciones_log`). La cadena **→ Resultado (respuesta/venta)** NO está conectada a la variante ni a la campaña. El eslabón **Campaña** no existe como tal. Por tanto **la trazabilidad completa que se quiere medir NO es fiable hoy.**

---

## 17. Problemas y riesgos

### 🔴 CRÍTICO
1. **La variante no se guarda en `envios` ni en `aperturas`** → imposible asociar una apertura/resultado a la variante de forma directa y robusta.
2. **El dashboard A/B/C lee de `lead_pipelines`**, que no se alimenta con los envíos reales (5 filas de prueba), mientras la variante real está en `comunicaciones_log`. **Las conclusiones A/B/C serían falsas.**
3. **La lanzadera sortea A/B/C siempre (`Math.random()`), pero `enviar_lote.php` solo aplica contenido B/C si `test_ab=1`.** Si la plantilla no está marcada como test A/B, un lead etiquetado "B" o "C" habría recibido el contenido "A". → Variantes mal asignadas y conclusiones incorrectas.
4. **Los rebotes nunca se registran** (`rebotes` vacía). La métrica "Entregados" del dashboard es engañosa (asume entregados sin rebotes). No hay conocimiento de entrega real.
5. **No existe concepto de Campaña operativa** (`pipelines` vacío de uso real; `envios` sin `pipeline_id`). No se puede segmentar la campaña A/B/C.

### 🟠 IMPORTANTE
6. **Asignación de variante no balanceada ni persistida previamente**: aleatoria cliente-side sin bloqueo de equilibrio ni asignación previa en `lead_pipelines`. Riesgo de muestras desiguales.
7. **Aperturas con falsos positivos** (bots, antivirus, Apple MPP) sin filtrado ni deduplicación. Una "apertura" no es fiable como señal de interés.
8. **Dos mecanismos de límite diario SMTP divergentes** (`enviados_hoy` incrementado y nunca reiniciado vs. `COUNT(comunicaciones_log)` por día). Posible saturación de cuentas o bloqueos.
9. **Bajas no bloquean envíos de forma global**: `get_cola.php`/`enviar_lote.php` no comprueban `Lista Negra`; no existe lista global de supresión. Emails dados de baja pueden reingresar.
10. **Sin click tracking**: no se puede medir interés por enlace (CTR), clave para el experimento.
11. **Respuestas sin detección automática ni clasificación** (positiva/negativa/out-of-office/rebote). Todo es manual → sesgo y pérdida de datos.
12. **`estado_lead` es un estado global único** por lead; un lead no puede estar en varios estados/campañas simultáneamente ni conservar histórico por campaña. La relación N:M (`lead_pipelines`) no se usa.
13. **`estado_lead` con prefijos numéricos y variantes con/sin tilde** (`03 Respondió` en datos vs. `03 Respondio` en `mapearEstadoLead`) → riesgo de que filtros no coincidan y leads invisibles.

### 🟡 MEJORA
14. Los 66 duplicados marcados no están excluidos de los envíos en todos los flujos (solo `get_cola.php` filtra `es_duplicado=0`).
15. `snapshots` solo manual y con 2 filas; no hay serie temporal automática del funnel.
16. `envios.estado='abierto'` se persiste, pero las métricas de entregados usan `'enviado'`; apertura no alimenta `comunicaciones_log`.
17. Contraseñas SMTP hardcodeadas en código PHP y expuestas vía `get_accounts` (se devuelve `password` al frontend) → riesgo de seguridad.
18. Credenciales de `$CUENTAS_SMTP_FALLBACK` en `enviar_smtp_random.php` están en el repo (contradice la regla de no hardcodear secretos).

### 🟢 CORRECTO
19. Se guarda `asunto` y `cuerpo_mensaje` completos por envío (`envios`) → el contenido exacto es auditable.
20. Se registra SMTP utilizada (`cuenta_emision` + `id_cuenta_smtp`).
21. `tracking_id` es único y enlaza apertura→envío.
22. La apertura no cambia el estado Kanban (evento vs estado bien separado en ese punto).
23. El envío en modo test no altera el estado comercial del lead.

---

## 18. Elementos no verificables

- **Número exacto de leads "contactables"** (se tiene 1812 `01 Sin Contactar`, 1 `03 Respondió`; no hay dato de cuántos tienen email MX válido más allá de la validación puntual del alta manual).
- **Entregabilidad real / hard bounce / soft bounce**: no existe telemetría de retorno; **NO VERIFICABLE** con el sistema actual.
- **Recepción real por parte del destinatario**: solo se registra el `250` SMTP; **NO VERIFICABLE**.
- **Interés real por apertura**: la apertura no es fiable; **NO VERIFICABLE** sin limpieza de falsos positivos.
- **Atribución de respuesta a un envío/variante concreto**: el vínculo no existe; **NO VERIFICABLE**.
- **Efectividad de la rotación SMTP por reputación**: no hay métricas de reputación; **NO VERIFICABLE**.
- **Distribución equilibrada de variantes en envíos pasados**: solo hay 2 envíos y no llevan variante en `envios`; **NO VERIFICABLE**.

---

## 19. Conclusión técnica

El CRM Outbound de FutProtec **tiene la base de datos y los endpoints para una trazabilidad parcial** de envíos (lead, SMTP, contenido, fecha, tracking de píxel), pero **no está preparado para medir de forma fiable una campaña A/B/C de extremo a extremo**.

Los motivos determinantes son:

1. **La variante no viaja dentro del envío ni de la apertura**, quedando aislada en `comunicaciones_log` (evento de envío) y en una tabla `lead_pipelines` sin alimentar.
2. **La analítica A/B/C del panel no lee la variante real** de los envíos, sino una tabla de prueba (`lead_pipelines`).
3. **La asignación de variante en la lanzadera ignora el flag `test_ab`**, lo que puede etiquetar como B/C envíos que en realidad llevaban el contenido A.
4. **No hay campaña como entidad operativa** (tabla `pipelines` sin uso en los envíos).
5. **No hay rebotes ni click tracking ni detección automática de respuestas**, de modo que entregabilidad, CTR y respuesta/out-of-office no pueden medirse.
6. **Las aperturas no son una señal fiable** (píxel sin protección frente a bots, precargas y Apple MPP).

Por tanto, **el experimento A/B/C con el objetivo de medir "qué argumento genera más interés y más ventas" produciría conclusiones no fiables con la implementación actual.** Este informe debe servir únicamente como fotografía técnica de partida para que un auditor posterior decida qué cambios de trazabilidad son necesarios antes de lanzar la campaña.