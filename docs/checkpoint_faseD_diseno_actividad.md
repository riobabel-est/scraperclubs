# CHECKPOINT — FASE D: Auditoría Arquitectónica READ-ONLY de Actividad Comercial

**Proyecto:** FutProtec CRM Outbound
**Fase:** D — Diseño arquitectónico READ-ONLY (Plan Maestro de Evolución Post-Core)
**Fecha:** 2026-08-19
**Modo:** READ-ONLY (sin UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX, sin envíos, sin campañas)
**Estado:** Auditoría completada. Sin modificación de producción.

---

## 1. INFORME EJECUTIVO

El CRM FutProtec ya dispone de un núcleo sólido y reconciliado. La auditoría confirma que **la base para la evolución por capas ya existe en gran parte**:

- **Message-ID estable** por envío (derivado del tracking_id) → base para IMAP/respuestas.
- **Tabla `respuestas` ya creada** con `message_id`, `in_reply_to`, `references`, `clasificacion`, `estado_procesamiento` e idempotencia.
- **Función `resolverEnvioPorCorrelacion()` ya implementada** (In-Reply-To/References → envio_id).
- **`comunicaciones_log` funciona como event store parcial** → reutilizable para la timeline.
- **Apertura = evento, no estado** (V4.3 correcto, alineado con el principio EVENTO != ESTADO).

**Huecos funcionales confirmados (a implementar):**
1. **IMAP** no implementado (respuestas.php lo declara explícitamente).
2. **Click tracking** no existe.
3. **Tracking web identificado** no existe (solo píxel de apertura).
4. **Timeline unificada** no existe (los eventos están dispersos en varias tablas).
5. **Scoring** no existe.
6. **Notificaciones** no existen.

**Conclusión:** El plan es viable. La prioridad 1 (IMAP/respuestas) tiene la mayor parte de la infraestructura ya construida. La FASE E (IMAP READ-ONLY) es el siguiente paso natural.

---

## 2. INVENTARIO TÉCNICO — BASE DE DATOS

**BD principal:** `public_html/outbound/data/stats.db` (SQLite, WAL).

### Tablas existentes (inventario completo)

| Tabla | Propósito | Reutilizable para |
|---|---|---|
| `clubes_crm` | Leads/clubes (Kanban) | lead_events, scoring |
| `envios` | Envíos lógicos + Message-ID + variante | timeline, IMAP, click |
| `aperturas` | Eventos de apertura (píxel) | timeline, scoring |
| `respuestas` | Respuestas inbound + clasificación | timeline, IMAP, scoring |
| `rebotes` | Rebotes | timeline |
| `pipelines` | Campañas | timeline, creador campañas |
| `lead_pipelines` | Asignación lead→campaña + variante | timeline |
| `plantillas` | Plantillas A/B/C | creador campañas |
| `cuentas_smtp` | Cuentas SMTP + límites | gestión SMTP |
| `comunicaciones_log` | Event store parcial | timeline, auditoría |
| `config` | Config global (motor, entorno) | — |
| `mockups` | Solicitudes de mockup | timeline, scoring |
| `presupuestos` | Presupuestos | timeline, scoring |
| `snapshots` | Snapshots de estado | — |
| `_migraciones` | Control de migraciones | — |

### Esquema clave (columnas relevantes)

**`envios`** (v4.3): `id`, `club`, `email`, `federacion`, `cuenta_emision`, `fecha_envio`, `estado`, `tracking_id` (UNIQUE), `asunto`, `cuerpo_mensaje`, `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`, `message_id`, `es_test`, `resultado_envio`, `fecha_resultado_envio`.

**`respuestas`**: `id`, `envio_id`, `fecha_respuesta`, `remitente`, `destinatario`, `subject`, `cuerpo`, `message_id` (UNIQUE), `in_reply_to`, `references`, `clasificacion`, `fecha_clasificacion`, `estado_procesamiento`.

**`aperturas`**: `id`, `tracking_id`, `fecha_apertura`, `ip`, `user_agent`.

**`pipelines`** (campañas): `id`, `nombre`, `descripcion`, `fecha_inicio`, `fecha_fin`, `variante_ganadora`, `activo`, `created_at` + (migraciones posteriores) `estado`, `entorno`.

**`lead_pipelines`**: `id`, `lead_id`, `pipeline_id`, `variante_ab`, `fecha_asignacion`, UNIQUE(lead_id, pipeline_id).

**`comunicaciones_log`**: `id`, `lead_id`, `club_id`, `tipo_evento`, `plantilla_id`, `id_cuenta_smtp`, `tipo`, `resultado`, `codigo_error`, `variante_ab`, `pipeline_id`, `resumen`, `proxima_accion`, `canal`, `detalles`, `ip_registro`, `fecha`.

**`clubes_crm`**: `id`, `nombre_club`, `federacion`, `persona_contacto`, `cargo_contacto`, `email` (UNIQUE), `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`, `estado_lead`, `observaciones`, `ultimo_contacto`, `creado_el`, `es_duplicado`, `duplicado_id`, `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida`.

**`cuentas_smtp`**: `id`, `email`, `host`, `puerto`, `usuario`, `password`, `seguridad`, `activa`, `limite_diario`, `enviados_hoy`, `ultimo_error`, `ultimo_uso`, `nombre_emisor`, `cargo_emisor`.

**`plantillas`**: `id`, `nombre`, `asunto`, `cuerpo`, `tipo`, `categoria`, `activo`, `fecha_creacion`, `asunto_b`, `asunto_c`, `cuerpo_b`, `cuerpo_c`, `test_ab`.

---

## 3. INVENTARIO TÉCNICO — ENDPOINTS / API

| Endpoint | Función |
|---|---|
| `api/track.php` | Píxel de apertura (registra en `aperturas`, actualiza `envios.estado='abierto'`) |
| `api/baja.php` | Opt-out con confirmación explícita (token = tracking_id, marca `Lista Negra`) |
| `api/enviar_lote.php` | Motor P1 de envío por lote |
| `api/get_cola.php` | Cola de leads elegibles |
| `api/smtp.php` | Gestión cuentas SMTP |
| `api/leads.php`, `api/lead_search.php`, `api/lead_validate.php` | Gestión de leads |
| `api/plantillas.php` | Gestión de plantillas |
| `api/config.php` | Config global |
| `api/analytics.php` | Métricas |
| `api/blacklist.php` | Lista negra |
| `api/mockups.php`, `api/presupuestos.php` | Mockups y presupuestos |
| `api/pruebas.php` | Pruebas |
| `cli/cron.php` | Motor P3 de envío por cron (CLI) |
| `cli/init_db.php` | Inicialización/migración BD |
| `cli/migracion_live_runner.php` | Migración es_test |

---

## 4. INVENTARIO TÉCNICO — EVENTOS EXISTENTES

| Evento | Dónde se registra | ¿Trazable a lead/campaña/envío? |
|---|---|---|
| Envío | `envios` + `comunicaciones_log` | Sí (lead_id, campaign_id, variant, message_id) |
| Apertura | `aperturas` + `envios.estado` | Sí (vía tracking_id → envio) |
| Respuesta | `respuestas` | Sí (envio_id → lead/campaign/variant) |
| Baja | `clubes_crm.estado_lead` + observaciones | Sí (vía tracking_id → envio) |
| Rebote | `rebotes` | Parcial (solo email) |
| Mockup | `mockups` | Sí (lead_id, pipeline_id) |
| Presupuesto | `presupuestos` | Sí (lead_id, pipeline_id) |

**Observación:** Los eventos están dispersos. No hay una vista unificada por lead. `comunicaciones_log` es el candidato más cercano a un event store, pero no captura aperturas ni respuestas de forma sistemática.

---

## 5. INVENTARIO TÉCNICO — SMTP / IMAP

**SMTP:** Implementado y operativo.
- Tabla `cuentas_smtp` con rotación, límites diarios (`limite_diario`, `enviados_hoy`), `nombre_emisor`, `cargo_emisor`.
- Envío vía socket SMTP con autenticación (`enviarSMTP()` en cron.php) o `mail()` fallback.
- Message-ID estable por envío (`generarMessageIdEnvio()`).

**IMAP:** **NO implementado.**
- `respuestas.php` declara explícitamente: "No implementa IMAP/POP/webhook".
- No hay configuración IMAP en BD ni en código.
- **Riesgo a validar en FASE E:** que SiteGround permita conexiones IMAP salientes desde el servidor y que las cuentas SMTP tengan buzón IMAP asociado.

---

## 6. INVENTARIO TÉCNICO — FRONTEND / TRACKING

**Frontend:** `dashboard.php` + tabs (`analytics.php`, `respuestas.php`, `lanzadera.php`, `smtp.php`, `lista_negra.php`, `editor.php`, `modals.php`) + `js/app.js`.

**Tracking actual:**
- **Aperturas:** píxel 1x1 en `api/track.php` (solo apertura de email).
- **Baja:** enlace en plantillas → `api/baja.php`.
- **NO hay** click tracking.
- **NO hay** tracking web identificado (visitas a FutProtec.com).
- **NO hay** cookies de seguimiento ni tokens de sesión web.

---

## 7. ARQUITECTURA ACTUAL

```text
ENVÍO (cron.php / enviar_lote.php)
   │
   ├── envios (message_id, variant, es_test, resultado)
   ├── comunicaciones_log (evento)
   │
   ├── APERTURA (track.php) → aperturas + envios.estado='abierto'
   ├── BAJA (baja.php) → clubes_crm.estado_lead='Lista Negra'
   ├── RESPUESTA (manual/asistida) → respuestas
   └── REBOTE → rebotes
```

**Limitación:** No hay flujo automático de respuesta (IMAP), ni click, ni web, ni timeline unificada, ni scoring.

---

## 8. ARQUITECTURA FUTURA (por capas)

```text
                         FUTPROTEC CRM
                              |
             +----------------+----------------+
             |                |                |
          CAMPAÑAS           LEADS           KANBAN
             |                |                |
             +----------------+----------------+
                              |
                       MOTOR DE EVENTOS
                              |
       +--------------+-------+-------+--------------+
       |              |       |       |              |
      SMTP           IMAP    WEB    TRACKING      RESPUESTAS
       |              |       |       |              |
       +--------------+-------+-------+--------------+
                              |
                        TIMELINE DEL LEAD
                              |
                           SCORING
                              |
                       NOTIFICACIONES
```

---

## 9. MODELO DE DATOS FUTURO — DISEÑO `lead_events`

**Principio:** Reutilizar lo existente antes de crear tablas nuevas.

### Opción recomendada: tabla `lead_events` (event store unificado)

```sql
CREATE TABLE IF NOT EXISTS lead_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    campaign_id INTEGER,
    envio_id INTEGER,
    tipo_evento VARCHAR(50) NOT NULL,   -- email_sent, email_opened, email_clicked,
                                        -- web_visit, web_page_view, email_received,
                                        -- reply_classified, unsubscribe, bounce,
                                        -- manual_note, kanban_changed, mockup_requested,
                                        -- budget_sent
    fuente VARCHAR(20) DEFAULT 'sistema', -- sistema | imap | web | manual
    variante VARCHAR(1),
    message_id TEXT,
    url TEXT,                            -- para clicks / web_page_view
    ip VARCHAR(45),
    user_agent TEXT,
    metadata TEXT,                       -- JSON auxiliar
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_le_lead ON lead_events(lead_id);
CREATE INDEX IF NOT EXISTS idx_le_fecha ON lead_events(fecha);
CREATE INDEX IF NOT EXISTS idx_le_tipo ON lead_events(tipo_evento);
```

**Idempotencia:** UNIQUE parcial sobre (lead_id, tipo_evento, message_id) o hash auxiliar para evitar duplicados (especialmente en IMAP y web).

### Reutilización (evitar duplicación)

| Evento | Fuente existente | Acción |
|---|---|---|
| email_sent | `envios` + `comunicaciones_log` | Reutilizar (no duplicar) |
| email_opened | `aperturas` | Reutilizar |
| email_received | `respuestas` | Reutilizar |
| unsubscribe | `clubes_crm.estado_lead` | Reutilizar |
| mockup_requested | `mockups` | Reutilizar |
| budget_sent | `presupuestos` | Reutilizar |
| email_clicked | **NO existe** | Crear tabla `clicks` |
| web_visit / web_page_view | **NO existe** | Crear tabla `web_visits` + `tokens` |
| kanban_changed | **NO existe** | Registrar en `lead_events` |

**Recomendación:** `lead_events` debe ser una **capa de agregación** que apunte a los eventos existentes (vía envio_id, message_id) y capture los nuevos (click, web, kanban). No debe duplicar el contenido de `envios`/`respuestas`/`aperturas`.

---

## 10. DISEÑO — FLUJO IMAP (PRIORIDAD 1)

### Flujo objetivo

```text
SMTP → Email enviado → Message-ID → IMAP → Email recibido
  → Identificar remitente → Match lead → Match envío/campaña
  → Registrar respuesta → Notificar
```

### Identificación (prioridad, ya soportada por `resolverEnvioPorCorrelacion()`)
1. `In-Reply-To`
2. `References`
3. email remitente
4. Message-ID relacionado
5. asunto (solo apoyo, nunca única prueba)

### Idempotencia (ya soportada por `registrarRespuesta()`)
- Message-ID (UNIQUE)
- UID IMAP (cuenta + UID)
- hash auxiliar

### Clasificación inicial (sin IA)
- humana / rebote / baja / fuera de oficina / automática / desconocida

### Tabla `respuestas` ya cubre los campos necesarios
`respuesta_id`, `envio_id`, `message_id`, `in_reply_to`, `references`, `remitente`, `destinatario`, `subject`, `cuerpo`, `clasificacion`, `fecha_respuesta`, `estado_procesamiento`.

**Falta:** `id_cuenta_smtp` (para saber qué buzón IMAP se leyó) y `uid_imap` (para idempotencia por cuenta+UID). Se añadirían en FASE F.

### Kanban
- Respuesta humana → `02 Contactado` → `03 Respondió` (transición manual o regla explícita).
- Respuesta automática → NO transición.

---

## 11. DISEÑO — TRACKING WEB IDENTIFICADO (PRIORIDAD 2)

### Método
- Token no predecible: `https://futprotec.com/r/{token}`.
- El servidor resuelve token → lead → campaña → envío.
- Cookie de seguimiento tras la resolución.

### Tablas nuevas propuestas

```sql
CREATE TABLE IF NOT EXISTS tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE NOT NULL,
    envio_id INTEGER,
    lead_id INTEGER,
    campaign_id INTEGER,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    expira_en DATETIME
);

CREATE TABLE IF NOT EXISTS web_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT,
    lead_id INTEGER,
    campaign_id INTEGER,
    envio_id INTEGER,
    session_id TEXT,
    path TEXT,
    ip VARCHAR(45),
    user_agent TEXT,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Niveles de identificación
- **Nivel 1 (anónimo):** IP, timestamp, página, sesión, user agent.
- **Nivel 2 (identificado):** email → enlace → token → cookie → visita.
- **Nivel 3 (identificado + respuesta):** email + apertura + web + respuesta (señal más rica).

### Privacidad (RGPD)
- Revisar consentimiento de cookies.
- Minimización de datos.
- Retención limitada.
- Integración con bajas (si un lead se da de baja, no seguir trackeando).
- No asumir que todo tracking técnicamente posible es legalmente apropiado.

---

## 12. DISEÑO — TIMELINE (PRIORIDAD 3)

La timeline unifica todos los eventos por lead. Se construye consultando `lead_events` (o agregando `envios` + `aperturas` + `respuestas` + `clicks` + `web_visits`).

**Ejemplo de salida:**
```text
17/08 09:42  Email enviado — B
17/08 10:31  Email abierto
17/08 10:35  Visitó futprotec.com
17/08 10:38  Visitó /personalizacion
18/08 08:12  Respondió
18/08 08:12  Clasificación: interés
```

**Recomendación:** Antes de crear `lead_events`, mapear qué eventos ya están en `comunicaciones_log`/`envios`/`aperturas`/`respuestas` para no duplicar. `lead_events` puede ser una vista materializada o una tabla de agregación.

---

## 13. DISEÑO — CLICK TRACKING (PRIORIDAD 4)

### Flujo
```text
Email → link controlado → tracking endpoint → registrar click → redirect → destino
```

### Tabla nueva propuesta

```sql
CREATE TABLE IF NOT EXISTS clicks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    envio_id INTEGER,
    lead_id INTEGER,
    campaign_id INTEGER,
    url TEXT,
    ip VARCHAR(45),
    user_agent TEXT,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**Nota:** Requiere reescribir las plantillas para envolver los enlaces. Debe validarse que no rompe el tracking de aperturas existente.

---

## 14. DISEÑO — SCORING (PRIORIDAD 5, determinista, sin IA)

| Evento | Puntos |
|---|---:|
| Email enviado | 0 |
| Primera apertura | +2 |
| Segunda apertura | +3 |
| Click | +5 |
| Visita web | +4 |
| Visita producto | +5 |
| Visita precio | +6 |
| Visita contacto | +8 |
| Respuesta | +15 |
| Solicita información | +15 |
| Solicita presupuesto | +25 |

**Regla:** El score prioriza, NO declara automáticamente interés ni mueve Kanban.

**Implementación:** Función determinista que calcula el score a partir de `lead_events` (o de las tablas existentes). Puede almacenarse en `clubes_crm.score` o en tabla `lead_score` (con historial).

---

## 15. DISEÑO — NOTIFICACIONES (PRIORIDAD 6)

- **Nueva respuesta:** 🔔 NUEVA RESPUESTA (club, campaña, variante, recibido, [VER RESPUESTA]).
- **Lead con actividad:** 🔥 LEAD CON ACTIVIDAD (score, señales).
- Configurables, sin convertirse en ruido.

---

## 16. DISEÑO — CREADOR DE CAMPAÑAS (PRIORIDAD 7)

Simplificar la UI manteniendo el backend complejo. Pasos:
1. Campaña (nombre, objetivo, audiencia, plantilla).
2. Audiencia (Total CRM, REAL, TEST, Suppression, Duplicados, Ya contactados, Elegibles).
3. A/B/C (A XXX, B XXX, C XXX).
4. Simulación (potenciales, bloqueados, motivos, riesgos).
5. Backup verificable.
6. Microenvío (5/10/25/50).
7. Postcheck (cantidad, leads, variantes, message_id, SMTP, estados, errores, tracking).
8. Escalado (ACTIVAR OPERACIÓN CONTROLADA).

---

## 17. DISEÑO — GESTIÓN SMTP

- Corregir `enviados_hoy` para mostrar uso diario real (no acumulados históricos).
- UI: `cuenta@futprotec.com | Hoy: 12/15 | Disponible: 3`.
- Historial por cuenta consultable.
- Rotación SMTP sigue siendo responsabilidad del backend.

---

## 18. SEGURIDAD (mantener)

- Campaña TEST → lead REAL: bloqueado.
- Campaña REAL → lead TEST: bloqueado.
- Campaña TEST en producción: bloqueada.
- Bypass HTTP/CLI: bloqueado.
- Variante cliente: nunca autoridad (backend recalcula).
- Campaña: validada en backend (`validarCampanaActiva`).
- IMAP: atribución basada en datos verificables (In-Reply-To/References).
- Tokens web: no predecibles (`random_bytes`).
- Webhooks: autenticados.
- Eventos: idempotentes.

---

## 19. PRIVACIDAD (RGPD)

- Revisar consentimiento de cookies para tracking web.
- Minimización de datos.
- Retención limitada.
- Integración con bajas.
- No asumir legalidad de todo tracking técnicamente posible.

---

## 20. OBSERVABILIDAD

Cada capa debe registrar para auditoría:
- **IMAP:** timestamp, cuenta, UID, from, lead, envio, resultado.
- **WEB:** timestamp, token, lead, campaign, path, session.
- **CLICK:** timestamp, envio, lead, campaign, url.

---

## 21. ROADMAP (fases)

| Fase | Descripción | Estado |
|---|---|---|
| **D** | Auditoría arquitectónica READ-ONLY | ✅ Completada (este documento) |
| **E** | IMAP READ-ONLY (estudiar carpetas, UID, Message-ID, In-Reply-To, References) | Pendiente |
| **F** | Registro de respuestas (automatizar email→lead→envio→campaña, idempotente) | Pendiente |
| **G** | Notificaciones de respuestas | Pendiente |
| **H** | Tracking web identificado (token→sesión→visita→lead→campaña) | Pendiente |
| **I** | Timeline unificada | Pendiente |
| **J** | Click tracking | Pendiente |
| **K** | Scoring determinista | Pendiente |
| **L** | Creador de campañas simplificado | Pendiente |
| **M** | IA (solo con suficiente información real) | Pendiente |
| **N** | Evaluación ESP externo | Pendiente |

---

## 22. PRIORIDADES RECOMENDADAS

1. **IMAP/respuestas** (mayor infraestructura ya lista).
2. **Tracking web identificado**.
3. **Timeline**.
4. **Click tracking**.
5. **Scoring**.
6. **Notificaciones**.
7. **Creador de campañas simplificado**.

---

## 23. PLAN DE IMPLEMENTACIÓN (siguiente paso)

**FASE E — IMAP READ-ONLY:**
1. Conectar a una cuenta SMTP vía IMAP (validar que SiteGround lo permite).
2. Estudiar carpetas, UID, Message-ID, In-Reply-To, References, remitentes, estructura de mensajes.
3. Documentar hallazgos.
4. **Sin modificar CRM.**

---

## 24. BUGS NO BLOQUEANTES (confirmados en auditoría)

- **D1:** Métricas con `lead_pipelines.variante_ab` pueden no coincidir con la variante efectiva del envío. Solución futura: derivar de `envios.variant`.
- **D2:** `Math.random()` en frontend calcula variante, pero producción recalcula server-side con `asignarVariante()`. Solución futura: eliminar código muerto.

Ninguno afecta a seguridad, integridad, envío ni A/B/C real.

---

## 25. CRITERIOS DE ÉXITO

El CRM futuro debe responder:
- ¿Qué enviamos a este club? ¿Qué variante recibió?
- ¿Lo abrió? ¿Hizo click? ¿Visitó FutProtec? ¿Qué páginas vio? ¿Volvió?
- ¿Respondió? ¿Qué dijo? ¿Qué acción corresponde?
- ¿Qué leads merecen atención primero?
- ¿Qué campaña genera oportunidades?
- ¿Qué porcentaje termina en propuesta/venta?

---

## 26. NOTA DE CUMPLIMIENTO READ-ONLY

Esta fase D se ha ejecutado **exclusivamente en modo lectura**. No se ha ejecutado ninguna operación de escritura sobre producción (sin UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX, sin envíos, sin campañas). No se ha subido BD modificada. No se ha modificado código de producción.

**Próxima fase recomendada:** FASE E — IMAP READ-ONLY.
