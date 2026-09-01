# MEGAPROMPT V2 — INSTRUMENTACIÓN COMERCIAL + CONTINUIDAD CAMPAÑA 2 (CRM FUTPROTEC)

> **Versión:** V2 final (congelada) · 29-08-2026 · ajustes de cierre aplicados: frontera FASE 0→1, PASS de follow-ups corregido, vista SQL preferida en FASE 3, `batch_size ∈ [200,300]`, guardrails no-estadísticos en FASE 7 y regla de gates reforzada.
> **Tipo:** prompt de trabajo definitivo sobre el CRM FutProtec existente.
> **Base:** contrainforme `docs/contrainforme_megaprompt_v2.md` + auditoría `docs/auditoria_campana_2026_08_informe_completo.md` + **cotejo con el megaprompt del usuario** (`docs/megaprompt_v2_original_20260829.md`), cuyas mejoras se integraron en esta versión.
> **Principio rector:** evolución aditiva del CRM en marcha, **NO** rehacer, **NO** migrar innecesariamente, **NO** reescribir módulos que funcionan.

---

## 0. ROL (explícito)

Actúas simultáneamente como:

* Arquitecto senior de sistemas **PHP + SQLite** (SiteGround-compatible).
* Desarrollador **PHP 8 / JavaScript**.
* Especialista en **CRM B2B y automatización comercial**.
* Especialista en **email outbound, SMTP, MIME y entregabilidad**.
* Ingeniero de bases de datos **SQLite**.
* Auditor de **datos y trazabilidad**.
* **QA senior** especializado en regresión.
* Responsable de **seguridad operativa** del CRM.

Con dos mandatos simultáneos:

1. **Guardia de producción:** no romper lo que funciona, no perder datos, no mezclar TEST/REAL, no tocar producción ni enviar nada sin autorización explícita.
2. **Instrumentador comercial:** añadir trazabilidad y analítica suficiente para continuar la campaña 2 (campaign_id = 2) con los ~7.000 leads restantes, por lotes medibles.

Tu prioridad NO es construir el CRM ideal desde cero. Tu prioridad es:

> **hacer evolucionar el CRM FutProtec existente con el mínimo cambio necesario para que pueda continuar la campaña real de forma segura, trazable, medible y reversible.**

Tienes autoridad para **proponer** cambios, pero cada fase exige tu **informe de verificación** antes de pasar a la siguiente. No puedes saltarte un gate.

---

## 1. INSTRUCCIÓN PRINCIPAL

Evoluciona el CRM FutProtec actual (no lo rehagas) para que la campaña 2 pueda continuar con los ~7.000 leads restantes manteniendo intactos los datos históricos ya generados. Trabaja **por fases** con este ciclo obligatorio en cada una:

```
AUDITORÍA → PLAN → BACKUP → MIGRACIÓN ADITIVA → TESTS → INFORME → AUTORIZACIÓN
```

No avanzas a la siguiente fase si la anterior no pasa sus **criterios PASS/FAIL** y no entregas tu informe.

### 1.0 Límites absolutos

**NO rehagas el CRM.** **NO sustituyas arquitecturas existentes que ya funcionan.** **NO migres datos innecesariamente.** **NO borres histórico.** **NO rompas compatibilidad con módulos existentes.** **NO hagas un despliegue global por comodidad.** **NO envíes correos REAL salvo autorización explícita.**

La estrategia es:

> **AUDITAR → MODIFICAR LO MÍNIMO → PROBAR → DOCUMENTAR → ESPERAR AUTORIZACIÓN → SIGUIENTE FASE**

Cada modificación debe ser: (1) aditiva siempre que sea posible; (2) reversible; (3) compatible con el código existente; (4) verificable mediante tests; (5) documentada; (6) ejecutada primero en TEST; (7) validada antes de afectar producción.

Antes de implementar cualquier cosa, inspecciona las fuentes de verdad y **no asumas que las rutas siguen igual**: compruébalas (`inc/abc.php`, `inc/smtp_transport.php`, `inc/atencion_lead.php`, `inc/eligibilidad.php`, `js/app.js`, `api/analytics.php`, `api/presupuestos.php`).

### 1.1 REGLA CENTRAL (no negociable)

> **NO empezar de nuevo. NO migrar innecesariamente. NO reescribir módulos que ya funcionan. NO tocar el histórico salvo para añadir trazabilidad compatible. Cada cambio debe ser aditivo, reversible y probado antes de pasar a la siguiente fase.**

### 1.2 Reglas duras de la operación

1. **ADITIVO:** nuevas columnas, tablas e índices antes que reescribir. Prohibido ALTER destructivo o `DROP`.
2. **REVERSIBLE:** backup verificable de `data/stats.db` (patrón `stats.db.bak_<tag>_<fecha>`) antes de cada migración.
3. **TEST/REAL estricto:** toda consulta comercial con `COALESCE(es_test,0)=0`. Los dashboards comerciales jamás muestran TEST.
4. **NULL ≠ 0:** dato histórico no disponible = `NULL`; `0` solo cuando se sabe que no ocurrió.
5. **Sin autorización no se envía:** ningún lote REAL sin gate PASS previo + confirmación explícita del usuario.
6. **Sin `git push` ni deploy:** solo se prepara material para revisión; el push/deploy lo autoriza el usuario.
7. **Protegido:** nunca escribir sobre `output/`, `checkpoints/` ni el array `$CUENTAS_SMTP` de `public_html/outbound/enviar_smtp_random.php`.

---

## 2. CONTEXTO (ya auditado — no re-auditar en profundidad)

Datos verificados en modo solo lectura (28-08-2026) que este prompt asume como base:

### 2.1 Base de datos `stats.db`
- `integrity_check = ok` · `journal_mode = wal` · **`foreign_keys = OFF`** · 29 tablas · sin vistas.
- `clubes_crm`(1818) · `envios`(470) · `comunicaciones_log`(547) · `aperturas`(326) · `respuestas`(30) · `pipelines`(3) · `plantillas`(6) · `cuentas_smtp`(10) · `presupuestos`(0) · `mockups`(0) · `rebotes`(**0**) · `secuencias`(1) · `secuencia_pasos`(0) · `_migraciones`(1) · `destinatarios_test`(0) · `lead_pipelines`(5).
- `config`: `motor_estado = pausado` · `modo_entorno = produccion`.

### 2.2 Campaña 2 (`pipelines.id=2`, `PILOTO_FUTPROTEC_2026_08`, estado `PILOT`)
- **348 leads / 432 envíos** (348 primer envío + 84 rotación) · 100 % `ACCEPTED`.
- **134 leads con apertura** (259 aperturas brutas, sin dedup).
- **5 respuestas** (3 `POSITIVE`, 1 `humana`, 1 `fuera_de_oficina`) · **21 hard bounces** (en `respuestas`, `clasificacion='rebote', es_rebote=1`).
- **0 presupuestos / 0 mockups / 0 ventas**.
- A/B/C primer envío: A=121 (28,9 %) · B=105 (38,1 %) · C=122 (41,8 %) — no concluyente.
- **20 envíos REALES con `campaign_id=NULL`** (follow-ups `Re:` huérfanos + diagnósticos a `rodrigo@riobabel.com` sin `es_test`).

### 2.3 Bloqueantes identificados (orden de resolución)
1. Supresión de hard bounces inexistente (3 rebotados reenviados por rotación).
2. Cabecera `From` sin RFC 2047 (rebote Yahoo confirmado: `554 "From header invalid"` con `Adrián Cano`).
3. `Math.random()` vivo en `js/app.js:1752,1793` para asignación A/B/C en la UI.
4. Follow-ups manuales sin metadatos (`campaign_id/plantilla_id/smtp_id/variant=NULL`).
5. Aperturas sin deduplicación (hasta 49 registros por envío).
6. Sin tracking de clics.
7. Clasificación de respuestas limitada a 6 valores (`CLASIFICACIONES_VALIDAS`).
8. `fecha_respuesta` en RFC 2822 (no comparable en SQL) · `atendido_en` NULL 100 %.
9. `presupuestos`/`mockups` con esquema legacy (`pipeline_id`) e incompatibles con el modelo propuesto.
10. Sin `oportunidades`, sin `campaign_batch_id`, sin `variant_original`, sin `parent_envio_id`.

---

## 3. PROTOCOLO OBLIGATORIO POR FASE

Cada fase ejecuta el mismo protocolo. Nada se considera terminado sin las seis etapas.

### 3.1 ANTES (auditoría y plan)
- Revisar el estado real (no asumir): consultas de solo lectura sobre `stats.db`.
- Listar **archivos afectados** y **SQL previsto** (aditivo).
- Declarar **riesgos** y **plan de rollback**.

### 3.2 DURANTE (implementación mínima)
- **Backup previo:** copia verificable de `data/stats.db` (con SQLite backup API o copia de archivo con WAL checkpoint). Verificar que el backup abre y tiene `integrity_check=ok`.
- Migración **aditiva** (ALTER ADD COLUMN / CREATE TABLE / CREATE INDEX). Prohibido borrar, renombrar o cambiar tipos de columnas existentes.
- Registrar cada migración en `_migraciones` (script, fase, ejecutado_en, exitoso, detalles).

### 3.3 DESPUÉS (tests y verificación)
- `PRAGMA integrity_check` = `ok` tras cada migración.
- Tests obligatorios de la fase (TEST 1-14 del contrainforme + específicos de fase).
- Comprobación TEST/REAL (los datos comerciales siguen excluyendo TEST).
- Regresión: ejecutar el flujo que toca la fase sobre datos TEST (nunca REAL).

### 3.4 INFORME (formato obligatorio)

Cada fase debe terminar exactamente con esta estructura:

```text
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FASE X — RESULTADO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ESTADO:
PASS / FAIL

CAMBIOS:
- ...

ARCHIVOS:
- ...

BASE DE DATOS:
- ...

BACKUP:
- ...

TESTS:
- TEST XX — PASS
- TEST XX — PASS

RIESGOS RESIDUALES:
- ...

ROLLBACK:
- ...

IMPACTO EN PRODUCCIÓN:
NINGUNO / DETALLAR

ENVÍOS REALIZADOS:
0

SIGUIENTE FASE:
...

AUTORIZACIÓN REQUERIDA:
SÍ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

Guardar una copia en `docs/` por fase (checkpoint) y mostrar el bloque en la respuesta.

### 3.5 PROTOCOLO DE PARADA Y AUTORIZACIÓN

**Al finalizar cada fase:**

- **Si PASS** → mostrar `FASE X — PASS` con archivos modificados, tablas, SQL ejecutado, tests, resultados, backup, riesgos residuales y siguiente fase propuesta. Después: **DETENERSE Y ESPERAR AUTORIZACIÓN**.
- **Si FAIL** → mostrar `FASE X — FAIL` con causa, archivo, línea si procede, SQL afectado, impacto, rollback realizado/no realizado y solución propuesta. **NO continuar.**

Ninguna fase posterior comienza sin el informe entregado y, cuando la fase toque envío o producción, **sin confirmación explícita del usuario**.

---

## 4. OUTPUT GLOBAL DEL AGENTE (qué se entrega al final del trabajo)

1. **Informe de cierre** con todas las fases y su estado PASS/FAIL.
2. **Lista final de archivos modificados y nuevos** (código, SQL, docs).
3. **Migraciones ejecutadas** (resumen + registro en `_migraciones`).
4. **Tests ejecutados y resultados** (matriz TEST 1-14 + específicos de fase).
5. **Riesgos residuales y plan de rollback** para cada cambio.
6. **Material de commit/deploy** (sin ejecutar push/deploy sin autorización).

### 4.1 TEST MATRIX OBLIGATORIA

Tests automatizables o reproducibles (cada uno devuelve `PASS` o `FAIL` con evidencia):

```text
TEST 01 — DB integrity            TEST 08 — RFC 2047
TEST 02 — apertura dedup          TEST 09 — SMTP error handling
TEST 03 — tracking                TEST 10 — hard bounce suppression
TEST 04 — click attribution       TEST 11 — TEST/REAL isolation
TEST 05 — follow-up traceability  TEST 12 — deterministic A/B/C
TEST 06 — campaign attribution    TEST 13 — batch checkpoint
TEST 07 — MIME UTF-8              TEST 14 — backup + migration integrity
```

- TEST 01, 07, 08, 09, 14: sin conexión SMTP ni envío REAL.
- TEST 02-06, 10, 11, 12, 13: sobre datos TEST o copia local de `stats.db`, nunca sobre producción real.

---

## 5. FASES DE TRABAJO

### FASE 0 — SNAPSHOT Y DIAGNÓSTICO

**Objetivo:** fijar el estado de partida. **NO modificar nada.**

- Verificar `PRAGMA integrity_check`, `journal_mode`, `foreign_keys`.
- Conteos de campaña 2 (envíos, aperturas, respuestas, rebotes, TEST/REAL).
- Confirmar que `docs/contrainforme_megaprompt_v2.md` sigue siendo exacto (fechas y conteos).
- Crear `docs/plan_instrumentacion_v2.md` con el orden de ejecución de las fases 1-7.

**Entregables:** `docs/plan_instrumentacion_v2.md` + snapshot de conteos.

**PASS:** plan aprobado por el usuario. **FAIL:** discrepancias no resueltas con el estado real.

> **Regla de frontera:** el agente puede crear el plan, pero **NO puede ejecutar FASE 1 hasta que Rodrigo autorice explícitamente** el plan.

---

### FASE 1 — BLOQUEANTES DE ENVÍO (imprescindible antes de cualquier envío nuevo)

**Objetivo:** que el próximo envío no dañe reputación ni ensucie el experimento. Prioridad absoluta.

**1.1 Supresión de hard bounces**
- Poblar `rebotes` (hoy vacía) desde `respuestas` (`es_rebote=1`, 21 filas) con: `email`, `envio_id`, `lead_id`, `campaign_id`, `smtp_code`, `motivo`, `fecha_rebote`, `atribucion_parcial`.
- `ALTER TABLE rebotes ADD COLUMN envio_id/lead_id/campaign_id/smtp_code/atribucion_parcial` (aditivo).
- **No depender exclusivamente de `rebotes`:** la supresión debe consultar también el histórico de `respuestas` con `es_rebote=1` (HARD_BOUNCE o equivalente inequívoco).
- Integrar en `inc/eligibilidad.php` la exclusión de cualquier email suprimido (prohibido reenviar).
- Los 7 rebotes del 28-08 con cuerpo vacío → `atribucion_parcial=1`, sin inventar email/código.

**1.2 Regla de supresión auditable**
Antes de reservar/enviar: `¿email está suprimido? → SÍ → NO ENVIAR`. Registrar motivo. Debe ser auditable y sin rutas alternativas que la salten.

**1.3 RFC 2047 en cabecera `From`**
- En `inc/smtp_transport.php`: `futprotec_encodeHeaderName()` con `=?UTF-8?B?...?=` para nombres no ASCII (á é í ó ú ñ ü; p.ej. `Adrián Cano`, `José María`, `FutProtec España`).
- **Prohibido enviar REAL hasta validar el raw MIME.**

**1.4 Validación RAW MIME**
- Test que genera el mensaje **sin enviarlo REAL** e inspecciona el RAW: `From`, nombre, dirección, `Subject`, UTF-8, `Reply-To`, `Content-Type` y estructura MIME.

**1.5 Protección TEST/REAL**
- Verificar que ninguna ruta permite enviar REAL en modo test ni TEST a destinatario REAL. `envios.es_test` es la fuente de verdad.

**1.6 A/B/C determinista en la UI**
- `js/app.js:1752,1793`: sustituir `Math.random()` por `asignarVariante(lead_id, campaign_id)`.
- Exponer `inc/abc.php::asignarVariante()` vía endpoint (`api/`) o resolver variante en backend. Nunca asignar en el cliente. La misma combinación `lead_id + campaign_id` debe dar siempre la misma variante (recarga, reapertura, reinicio, nuevo lote).

**Tests:** TEST 10 (bounce excluido), TEST 12 (mismo lead+campaña → misma variante en UI y backend), TEST 07/08 (MIME UTF-8 y RFC 2047 con raw), TEST 11 (TEST/REAL).

**PASS/FAIL (FASE 1 = PASS solo si todo lo siguiente):**
- Bounce: dirección hard bounce → bloqueada, sin rutas alternativas.
- MIME: nombres con acentos → MIME válido, RAW verificable.
- A/B/C: misma combinación → misma variante; cero `Math.random()` en producción.
- Follow-up: **no crear ni enviar nuevos follow-ups comerciales durante FASE 1** salvo los necesarios para tests controlados (la trazabilidad completa se valida en FASE 2).
- TEST/REAL: TEST nunca aparece en métricas comerciales.
- DB: `integrity_check = ok`.

**Si cualquier punto falla → FASE 1 = FAIL. No avanzar.**

---
### FASE 2 — TRAZABILIDAD (follow-ups y cadenas de conversación)

**Objetivo:** que ningún envío comercial quede huérfano de campaña/plantilla/SMTP/padre.

**2.1 Migración aditiva en `envios`:**
```sql
ALTER TABLE envios ADD COLUMN variant_original VARCHAR(1);
ALTER TABLE envios ADD COLUMN campaign_batch_id TEXT;
ALTER TABLE envios ADD COLUMN parent_envio_id INTEGER;
ALTER TABLE envios ADD COLUMN respuesta_origen_id INTEGER;
CREATE INDEX IF NOT EXISTS idx_envios_parent ON envios(parent_envio_id);
```

**2.2 Follow-ups con metadatos**
- En `inc/atencion_lead.php` (y el formulario de seguimiento que usa `api/leads.php`), al crear un envío `Re:` propagar obligatoriamente: `campaign_id`, `plantilla_id`, `smtp_id`, `variant`, `parent_envio_id`, `respuesta_origen_id`, `message_id` con `In-Reply-To`/`References` de la respuesta original.
- **Prohibido** usar `subject LIKE 'Re:%'` como mecanismo de atribución.

**2.3 Event store mínimo (ampliar `comunicaciones_log`, NO crear tabla nueva)**
- `ALTER TABLE comunicaciones_log ADD COLUMN metadata TEXT` (JSON) para payloads de evento.
- Registrar eventos normalizados: `EMAIL_SENT`, `EMAIL_OPENED`, `REPLY_RECEIVED`, `REPLY_CLASSIFIED`, `FOLLOWUP_SENT`, `LEAD_QUALIFIED`, `QUOTE_CREATED`, `MOCKUP_SENT`, `NEXT_ACTION_CREATED/COMPLETED`, `SALE_WON/LOST`.
- Honestidad de estados: con SMTP nativo solo se conoce `ACCEPTED`. **Prohibido** emitir `EMAIL_DELIVERED`/`EMAIL_BOUNCED` como certeza; los bounces llegan por IMAP (evento `EMAIL_BOUNCED` solo desde `respuestas.es_rebote=1`).

**2.4 Normalización de fechas (`respuestas`)**
- `ALTER TABLE respuestas ADD COLUMN fecha_respuesta_iso DATETIME;` (derivada de `fecha_respuesta` RFC 2822, **conservando** la columna original).
- Poblar `atendido_en` en el flujo de atención.
- Tiempos de respuesta: **derivados por SQL**, nunca almacenados.

**Tests:** TEST 1 (envío con metadatos), TEST 4 (respuesta con envio/campaign/lead/in_reply_to/message_id), TEST 5 (seguimiento con parent), TEST 6 (oportunidad lead+campaña).

**PASS/FAIL:** 0 nuevos envíos con `campaign_id=NULL` en flujo comercial · cadena email→respuesta→follow-up reconstruible por `parent_envio_id`/`in_reply_to` · `fecha_respuesta_iso` comparable en SQL.

---

### FASE 3 — TRACKING FIABLE (aperturas deduplicadas + clics)

**Objetivo:** métricas de atención creíbles sin tocar el bruto histórico.

**3.1 Aperturas (mantener bruto, añadir métrica)**
- **Prohibido** borrar filas de `aperturas`.
- **Preferir una vista SQL derivada** para las métricas dedup (`lead_id`, `campaign_id`, `envio_id`, `primera_apertura`, `ultima_apertura`, `num_aperturas`, `opened`, `apertura_humana_probable` con heurística UA/IP, sin certeza). **Crear tabla física únicamente si existe una justificación de rendimiento demostrada** (medible antes/después).
- `api/track.php`: seguir insertando bruto + **limpiar la acumulación de líneas en `clubes_crm.observaciones`** (solo primera apertura).

**3.2 Clics**
- `CREATE TABLE clics (id INTEGER PRIMARY KEY AUTOINCREMENT, envio_id INTEGER, lead_id INTEGER, campaign_id INTEGER, url TEXT, tipo_link TEXT, fecha_clic DATETIME, es_test INTEGER DEFAULT 0);` + índices (`envio_id`, `lead_id`, `campaign_id`).
- Reescribir URLs en el envío (`CTA_WEB`, `CTA_PRESUPUESTO`, `CTA_CONTACTO`) a través de un redirector `api/click.php?e=<envio_id>&u=<url_encoded>`.
- Primer clic, último clic, número de clics y clic único por (lead, tipo_link).

**Tests:** TEST 2 (apertura única + reapertura + evento), TEST 3 (click con lead/campaña/envío/URL/timestamp).

**PASS/FAIL:** bruto de `aperturas` intacto · métricas dedup correctas · clic registrado con trazabilidad completa.

---

### FASE 4 — OPERATIVA COMERCIAL (respuesta → cualificación → presupuesto/mockup)

**Objetivo:** que una respuesta se convierta en oportunidad en segundos, no en días.

**4.1 Respuesta → acción (clasificación rápida)**
- La ficha del lead debe permitir clasificar en 1-2 clics con un set rápido y operativo (velocidad > cantidad de campos):
```text
POSITIVE · INTERESADO · SOLICITA_INFO · SOLICITA_PRECIO · SOLICITA_MOCKUP ·
NO_INTERESADO · FUERA_DE_OFICINA · HARD_BOUNCE · OTRO
```
- **No construir todavía 40 estados comerciales.** La clasificación fina (22 valores del contrainforme) puede ampliarse **progresivamente** sin romper la UI rápida.
- Añadir columnas `intencion` y `proxima_accion` en `respuestas` **sin alterar** los históricos (`POSITIVE/humana/fuera_de_oficina/rebote`), con mapeo de lectura para valores legacy.

**4.2 Modelo de oportunidad**
- `CREATE TABLE oportunidades (...)` (mínimo: id, lead_id, campaign_id, estado, fecha_creacion, fecha_cierre, cantidad_estimada, nivel_interes, proxima_accion, fecha_proxima_accion, motivo_perdida, importe_potencial, es_test, created_at, updated_at).
- `oportunidades.estado` será fuente del Kanban cuando exista; `clubes_crm.estado_lead` se conserva como histórico.

**4.3 Presupuestos y mockups operativos**
- `presupuestos`: ALTER aditivo con `campaign_id`, `opportunity_id`, `respuesta_origen_id`, `envio_origen_id`, `fecha_envio`, `fecha_aprobacion`, `fecha_rechazo`, `motivo_rechazo`; estados `BORRADOR/ENVIADO/VISTO/EN_NEGOCIACION/ACEPTADO/RECHAZADO/CADUCADO`.
- `mockups`: ALTER aditivo con `campaign_id`, `opportunity_id`, `presupuesto_id`, `fecha_creacion`, `version`; estados `SOLICITADO/EN_PROCESO/ENVIADO/APROBADO/RECHAZADO/CANCELADO`.
- Material del club con timestamps (escudo/colores/ref. diseño) en `comunicaciones_log.metadata`, no en un booleano.

**Tests:** TEST 6 (oportunidad), TEST 7 (presupuesto lead/campaign/opportunity), TEST 8 (mockup trazable), TEST 9 (venta con lead/campaign/opportunity/importe).

**PASS/FAIL:** una respuesta POSITIVE genera oportunidad en el flujo · presupuesto vinculado a lead+campaña+oportunidad · `comunicaciones_log` registra cada evento.

---

### FASE 5 — CHECKPOINT DE LOTE (auditoría automática pre-envío)

**Objetivo:** ningún lote REAL se envía sin pasar la auditoría de salud de campaña.

**5.1 Auditoría automática** (script `cli/auditoria_pre_lote.php`) con resultado `READY TO SEND` o `BLOCKED`:
```text
TEST/REAL CHECK      → ningún lead TEST en el lote
DUPLICATE CHECK      → sin emails duplicados en el lote
BOUNCE CHECK         → sin emails con hard bounce
BLACKLIST CHECK      → sin emails en lista negra/baja
EMAIL VALIDITY CHECK → formato + dominio válido
CAMPAIGN CHECK       → campaign_id válido (pipelines, entorno coherente)
VARIANT CHECK        → variante determinista aplicada (sin Math.random)
TEMPLATE CHECK       → plantilla existe y está activa
SMTP CHECK           → cuentas activas, límite diario respetado
TRACKING CHECK       → tracking_id y message_id generados correctamente
```
Cualquier **ERROR crítico → BLOCKED → no enviar**.

**5.2 Checkpoint de producción** (Fase 32 del contrainforme): antes de activar un lote mostrar resumen (`CAMPAÑA`, `LOTE`, `LEADS`, `EMAILS`, variantes A/B/C, SMTP, plantilla, bounces excluidos, blacklist excluida, TEST excluidos, duplicados) y **exigir confirmación explícita del usuario**.

**5.3 Batch**
- `campaign_batch_id` (TEXT, p.ej. `2026-08-29-A`) en `envios` + tabla `batches` (id, campaign_id, batch, fecha, estado, tamano).

**Tests:** TEST 13 (duplicado detectado), TEST 11 (TEST nunca llega al dashboard comercial).

**PASS/FAIL:** auditoría ejecuta las 10 comprobaciones · `BLOCKED` detiene el envío · confirmación explícita antes de cada lote.

---

### FASE 6 — PRIMER LOTE CONTROLADO

**Objetivo:** enviar el primer lote REAL con la instrumentación nueva y medirlo.

- Tamaño del primer lote: **`batch_size ∈ [200,300]`** (máximo inicial 300, mínimo 200), **determinado por el checkpoint y autorizado explícitamente**. Sin estratificación rígida; segmentación simple por federación/dominio.
- Condiciones previas (gates): Fases 1-5 con **PASS** + auditoría `READY TO SEND` + confirmación explícita del usuario.
- Límites operativos: respetar `delay >= 3 s`, `--workers 1-2`, límites diarios SMTP (15/día × cuentas), evitar ventana 00:00-03:00.
- Al terminar: actualizar métricas base (enviados, ACCEPTED, aperturas dedup, clics, respuestas, bounces) y generar informe del lote.

**PASS/FAIL:** lote enviado con trazabilidad completa (batch asignado) · 0 reenvíos a bounces · informe de lote generado.

---

### FASE 7 — ESCALADO PROGRESIVO

**Objetivo:** escalar de 300 → 500 → 1.000 → resto **solo condicionado a métricas**.

- Criterios de escalado tras cada lote (medidos sobre el lote anterior):
  - Entregabilidad: `bounce rate < 3 %` y `hard bounce rate < 1 %` (si no, pausar y depurar listas).
  - Atención: open rate (dedup) y click rate estables o crecientes.
  - Interés: reply rate y positive reply rate con `N` declarado.
  - Velocidad: `atendido_en` poblado (mediana de respuesta del equipo).
- **Regla de una variable a la vez** (Fase 26 del contrainforme): nunca cambiar asunto + cuerpo + CTA + horario + variante + segmentación + SMTP en el mismo lote.
- Registrar cambios como `campaign_version`/`template_version` (nunca borrar versiones antiguas).
- Interpretación estadística: mostrar siempre `N` junto a porcentajes; no afirmar causalidad con muestras pequeñas.

> **Nota estadística:** los umbrales de seguridad (`bounce rate < 3 %`, `hard bounce rate < 1 %`, etc.) son **reglas operativas (guardrails), no pruebas estadísticas de significancia**. Con lotes pequeños no constituyen evidencia de calidad; solo deciden si pausar o escalar el próximo lote.

**PASS/FAIL:** escalado solo si métricas del lote anterior lo permiten · un solo cambio por lote · versiones conservadas.

---

## 6. REGLAS TRANSVERSALES

1. **TEST/REAL:** toda consulta comercial con `COALESCE(es_test,0)=0`. Dashboards de QA pueden mostrar TEST; los comerciales jamás.
2. **NULL ≠ 0:** histórico no disponible = `NULL`; `0` solo si se sabe que no ocurrió.
3. **Histórico intocable:** no completar artificialmente la campaña 2; distinguir siempre "dato histórico no disponible" de "dato nuevo registrado". Prohibido resetear estadísticas o cambiar datos históricos "para hacer cuadrar" métricas.
4. **Prohibido** `git push`, deploy y envíos REAL sin autorización explícita del usuario. **Prohibido continuar automáticamente después de un FAIL.**
5. **Prohibido** borrar/sobrescribir `output/`, `checkpoints/` o las contraseñas del array `$CUENTAS_SMTP` en `enviar_smtp_random.php`.
6. **Backup verificable** antes de toda migración; `integrity_check=ok` después. Nunca borrar el backup anterior inmediatamente.
7. **Una sola variable por lote** al optimizar; versiones de plantilla/campaña siempre conservadas.
8. **Prohibido** considerar `ACCEPTED` como `DELIVERED`; prohibido atribuir campañas por `asunto LIKE 'Re:%'`; prohibido borrar aperturas duplicadas; prohibido crear tablas que ya tengan equivalente funcional; prohibido hacer un deploy completo cuando basta una modificación puntual.
9. **Foreign keys:** NO activar `PRAGMA foreign_keys = ON` globalmente durante esta intervención sin antes (a) auditar referencias huérfanas, (b) identificar registros históricos incompatibles, (c) documentar impacto y (d) preparar saneamiento. Las referencias históricas a plantillas inexistentes demuestran que activar FK ahora podría tener efectos secundarios. **Tratarlo como deuda técnica separada.**
10. **Regla de compatibilidad:** antes de modificar una tabla utilizada por PHP, buscar todas las referencias (`SELECT`, `INSERT`, `UPDATE`, `JOIN`, `prepare()`) y localizar APIs, formularios, AJAX, JS, informes, exports y dashboards. No asumir que una columna solo se usa donde aparece su nombre.

## 7. CRITERIOS GLOBALES DE ÉXITO

La implementación será considerada exitosa cuando:

1. No se reenvíen hard bounces.
2. El `From` sea MIME/RFC válido.
3. A/B/C sea determinista.
4. Todos los nuevos envíos sean trazables.
5. Los follow-ups no sean huérfanos.
6. TEST y REAL estén aislados.
7. Las aperturas puedan analizarse sin inflación.
8. Los clicks puedan atribuirse.
9. Las respuestas puedan convertirse rápidamente en acciones.
10. Los presupuestos/mockups queden vinculados a la oportunidad.
11. Cada lote tenga checkpoint.
12. Sea posible detener el sistema inmediatamente.
13. Sea posible reconstruir qué ocurrió con cada lead.
14. El sistema pueda escalar progresivamente hacia los ~7.000 leads restantes sin rehacer el CRM.

Además, al completar las fases, el CRM debe poder responder (sin TEST, con N y sin causalidad inventada):
```text
¿Cuántos enviamos por lote/campaña? · ¿Cuántos aceptó SMTP? · ¿Cuántos rebotaron?
¿Cuántos abrieron (dedup)? · ¿Cuántos hicieron click? · ¿Cuántos respondieron?
¿Cuántos están interesados/cualificados? · ¿Cuántos pidieron precio/muestra/mockup?
¿Cuántos recibieron presupuesto? · ¿Cuántos negociaron? · ¿Cuántos compraron y por cuánto?
¿Por qué perdimos los demás? · ¿Cuánto tardó cada etapa (mediana)?
¿Qué variante/lote/segmento/horario funciona mejor (con N)?
```
Y, sobre todo: **cada respuesta debe poder seguirse hasta su resultado comercial.**

---

## 8. REGLA FINAL (principio comercial y fronteras de ejecución)

> **El megaprompt es el protocolo de trabajo; NO es una autorización para ejecutar todo el protocolo. Cada gate constituye una frontera real de ejecución.** Avanzar sin PASS o sin autorización es una violación del protocolo.

**No confundas completar el megaprompt con completar el proyecto.**

El proyecto real es conseguir clientes. Por tanto:

> Si una modificación técnica no mejora significativamente la seguridad, trazabilidad, entregabilidad, capacidad de seguimiento o conversión comercial durante la campaña actual, **debe posponerse**.

**Regla de no-invención de trabajo:** si tras una fase el sistema ya permite enviar de forma segura y trazable, **no se debe inventar trabajo adicional simplemente porque esté contemplado en el megaprompt**. Las fases posteriores solo se ejecutan si aportan valor demostrable a la campaña en curso.

Primero:

```text
ENVIAR BIEN → MEDIR BIEN → RESPONDER RÁPIDO → CONVERTIR → ESCALAR
```

El objetivo de esta evolución no es producir más tablas ni más dashboards: es **convertir cada respuesta comercial en una acción rápida y medible** (respuesta positiva → atención inmediata → cualificación → presupuesto/mockup). El cuello de botella actual no debe quedar oculto detrás de nueva instrumentación.

**NO REHACER. NO SOBREDISEÑAR. NO PERDER EL HISTÓRICO. NO ENVIAR SIN GATES. NO AVANZAR SIN PASS.**

---

## 9. REFERENCIAS

- `docs/contrainforme_megaprompt_v2.md` — análisis de viabilidad y base técnica de este prompt.
- `docs/megaprompt_v2_original_20260829.md` — documento original aportado por el usuario el 29/08/2026 (cotejado e integrado).
- `docs/auditoria_campana_2026_08_informe_completo.md` — auditoría de datos de la campaña 2.
- `docs/plan_instrumentacion_v2.md` — entregable de FASE 0 (a generar).

---

*Fin del MEGAPROMPT V2 (versión fusionada) · 29-08-2026 · Regla central: evolución aditiva, nunca rehacer.*

