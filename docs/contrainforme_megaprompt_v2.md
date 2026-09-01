# CONTRAINFORME — MEGAPROMPT "EVOLUCIÓN CRM FUTPROTEC · INSTRUMENTACIÓN COMERCIAL V2"

> **Tipo:** contrainforme técnico / análisis de viabilidad para revisión de asesor externo.
> **Fecha:** 2026-08-28.
> **Modo de trabajo:** solo lectura (no se modificó ningún archivo, ni la BD, ni se envió nada).
> **Fuentes:** `public_html/outbound/data/stats.db` (abierta en `mode=ro`), código PHP/JS vivo de `public_html/outbound/`, y documentación del repo (`docs/`, `backups/`).
> **Base de datos:** SQLite `stats.db` (12,6 MB) · `integrity_check = ok` · `journal_mode = wal` · `foreign_keys = OFF`.

---

## 1. RESUMEN EJECUTIVO

**El megaprompt es viable en su núcleo y describe correctamente el destino, pero llega tarde en un 30-35 % de lo que pide: ya está construido o ya está documentado en el repo.**

- La base técnica es **sólida**: DB íntegra, aislamiento TEST/REAL operativo, trazabilidad de envíos completa para la campaña 2 (`lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`, `message_id`, `es_rotacion`, `secuencia_id`, `paso_secuencia`), atribución de respuestas por `message_id`/`in_reply_to`, mecanismo de migraciones (`_migraciones`), IA de clasificación conectada (DeepSeek).
- El documento **`docs/auditoria_campana_2026_08_informe_completo.md`** (560 líneas, redactado el propio 28-08) ya identifica **casi todos los gaps** que el megaprompt propone corregir y ya recomienda 10 acciones casi idénticas a las fases 1, 4, 5, 6, 17, 18 y 19.
- **Los gaps reales y bloqueantes** son pocos y concretos:
  1. la asignación A/B/C en la **UI sigue usando `Math.random()`** (`js/app.js:1752,1793`);
  2. **no existe supresión de hard bounces** (3 direcciones rebotadas recibieron reenvío);
  3. **cabecera `From` sin RFC 2047** (causa confirmada del rebote de Yahoo: `"From header invalid"`);
  4. **seguimientos `Re:` huérfanos** (20 envíos REALES con `campaign_id=NULL`);
  5. **aperturas sin deduplicar** (hasta 49 registros por un mismo envío);
  6. **sin tracking de clics**;
  7. **clasificación de respuestas con solo 6 valores** frente a las 22 propuestas.
- **Riesgo nº1 no es tecnológico, es reputacional:** hoy no se debe enviar el siguiente lote hasta corregir supresión de rebotados + RFC 2047 + variante determinista. Cada envío nuevo que se haga sin estos tres bloqueos contamina el experimento y la reputación de las 10 cuentas SMTP.

---

## 2. ESTADO REAL AUDITADO (verificado)

### 2.1 Base de datos `stats.db` (SQLite)

| Aspecto | Valor verificado |
|---|---|
| Integridad | `integrity_check = ok` · `journal_mode = wal` · **`foreign_keys = OFF`** |
| Tablas | 29 (sin vistas). `clubes_crm`(1818) · `envios`(470) · `comunicaciones_log`(547) · `aperturas`(326) · `respuestas`(30) · `pipelines`(3) · `plantillas`(6) · `cuentas_smtp`(10) · `presupuestos`(0) · `mockups`(0) · `rebotes`(**0**) · `lead_pipelines`(5) · `destinatarios_test`(0) · `secuencias`(1) · `secuencia_pasos`(0) · `_migraciones`(1) |
| Config | `motor_estado = pausado` · `modo_entorno = produccion` · delay 3 s · lote 10 · lanzadera 5–45 s · IA `deepseek` |

### 2.2 Campaña 2 (`pipelines.id=2`, `PILOTO_FUTPROTEC_2026_08`, estado `PILOT`)

| Métrica | Valor |
|---|---|
| Leads reales | **348** |
| Envíos | **432** (348 primer envío + **84 rotación**) · 100 % `resultado_envio = ACCEPTED` |
| Aperturas | **134 leads** / **259 aperturas** (bruto, sin dedup; un envío llegó a 49 registros) |
| Respuestas | **5** (3 `POSITIVE`, 1 `humana`, 1 `fuera_de_oficina`) |
| Hard bounces | **21** (viven en `respuestas` con `clasificacion='rebote', es_rebote=1`; la tabla `rebotes` está **vacía**) |
| Presupuestos / Mockups / Ventas | **0 / 0 / 0** |
| A/B/C primer envío | A=121 (28,9 % apertura) · B=105 (38,1 %) · C=122 (41,8 %) — **no concluyente** |
| Envíos REALES sin `campaign_id` | **20** (follow-ups `Re:` y envíos de diagnóstico a `rodrigo@riobabel.com` sin marca `es_test`) |

### 2.3 Hallazgos técnicos clave

1. **`js/app.js:1752` y `js/app.js:1793` asignan la variante A/B/C con `Math.random()`** en la lanzadera frontal. El backend sí tiene `inc/abc.php::asignarVariante()` determinista (crc32 → A/B/C) y `siguienteVariante()` (A→B→C para rotación), pero **la UI no lo usa**. El histórico de 348 leads quedó asignado de forma no estratificada → el A/B/C histórico **no es utilizable como experimento limpio** (83 leads recibieron 2 variantes por rotación).
2. **RFC 2047 pendiente de verdad**: el rebote de Yahoo `pdrociera@yahoo.es` dice literalmente `554 "From header invalid"`; el raw muestra `From: Adrián Cano` sin encoded-word. `inc/smtp_transport.php` no codifica el nombre del emisor.
3. **Los follow-ups manuales pierden trazabilidad**: envíos 368-380 con `campaign_id=NULL, plantilla_id=NULL, smtp_id=NULL, variant=NULL`. El informe los atribuye por `asunto LIKE 'Re:%'` (prohibido por el megaprompt).
4. **No existe** `variant_original`, `campaign_batch_id`, `parent_envio_id`, `respuesta_origen_id`, `oportunidades`, `eventos_comerciales`, `clics`, ni deduplicación de aperturas en ningún lugar del esquema.
5. **`fecha_respuesta` está en RFC 2822** (`Wed, 19 Aug 2026 21:59:39 +0200`) → no comparable en SQL. `atendido_en` **NULL en el 100 %** de las respuestas → la "velocidad de atención" no es medible hoy.
6. **`presupuestos`/`mockups` existen pero con esquema incompatible** con el propuesto (usan `pipeline_id`, no `campaign_id`/`opportunity_id`; sin fechas de aprobación/rechazo, sin `version` en mockups).
7. **`clubes_crm` ya tiene** `provincia`, `ciudad`, `cp`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `motivo_perdida` → las fases 7 y 13 del megaprompt están **parcialmente ya construidas** (falta `web` y la variante A/B fijada en el lead).
8. **FK no enforced**: hay `plantilla_id` 2 y 6 en envíos que **ya no existen** en `plantillas` (el catálogo tiene ids 1,3,4,5,8,9). La columna `envios` con `foreign_keys=OFF` tolera esto.

---

## 3. COMPARATIVA MEGAPROMPT vs REALIDAD

### 3.1 Ya existe y es reutilizable (≈ Fases completas)

| Fase megaprompt | Estado | Evidencia |
|---|---|---|
| **29 · TEST/REAL** | ✅ Operativa | `envios.es_test`, `esLeadTest()`, `esCampanaTest()`, `esEnvioTest()`, `esEntornoCoherente()` |
| **16 · A/B/C determinista (backend)** | ✅ Backend | `inc/abc.php` (crc32) + `siguienteVariante()` — ❌ la UI usa `Math.random()` |
| **1.1 · Envíos trazables** | ✅ Primer envío | 432/432 con `message_id`, `lead_id`, `campaign_id`, `variant`, `smtp_id`, `plantilla_id` — ❌ para follow-ups |
| **5 · Respuestas (parte A)** | ⚠️ Parcial | Atribución por `message_id`/`in_reply_to`/`references` funciona; clasificación limitada a 6 valores |
| **17 · Entregabilidad (estados)** | ✅ Mapeo honesto | `ACCEPTED` ≠ `DELIVERED` (ya correcto en `resultado_envio`) |
| **35 · UI ficha lead** | ⚠️ Parcial | Existe ficha de lead, Kanban, panel de respuestas, `informe_ia.php`; sin timeline completo |
| **20/22/23 · Analítica base** | ⚠️ Parcial | `api/analytics.php` (94 KB) ya agrega por variante/SMTP/dominio/horario |

### 3.2 Gaps reales (falta de verdad)

| Gap | Fase megaprompt | Impacto |
|---|---|---|
| Supresión de hard bounces operativa | 17, 19, 31 | **CRÍTICO** — 3 rebotados reenviados; riesgo reputacional |
| RFC 2047 en `From` | 18 | **CRÍTICO** — rechazo Yahoo confirmado |
| Variante determinista en la UI | 16 | ALTO — sin esto, el siguiente lote sigue siendo ruido A/B/C |
| Follow-ups con `campaign_id`/`plantilla_id`/`smtp_id`/`variant`/`parent` | 1.2 | ALTO — 20 envíos huérfanos y creciendo |
| Deduplicación de aperturas (1.ª/última/N) | 4 | ALTO — el tracking actual infla métricas |
| Tracking de clics | 4 | ALTO — no existe nada |
| Clasificación fina (22 valores + intención + próxima acción) | 5 | MEDIO — 6 valores hoy |
| `fecha_respuesta` normalizada a ISO + `atendido_en` | 6, 23 | MEDIO — velocidad comercial no medible |
| `campaign_batch_id` / lotes | 24, 25, 32 | MEDIO — no existe |
| Modelo `oportunidades` | 2, 8 | MEDIO — no existe; hoy se usa `estado_lead` global |
| Event store coherente | 3 | MEDIO — `comunicaciones_log` cubre parte |
| Presupuestos/mockups operativos con trazabilidad | 9, 10 | MEDIO — estructura básica, 0 filas, esquema a ampliar |
| Export 1 fila = 1 oportunidad | 21 | MEDIO — no existe |
| "Salud de campaña" / auditoría pre-lote | 27, 31, 32 | MEDIO — no existe |
| Pipeline histórico (tiempo en etapa) | 14 | BAJO (pero no existe) |
| Material del club (escudo/colores) con timestamps | 10 | BAJO |
| Ventas / pérdidas | 12, 13 | BAJO — sin datos que poblar aún |

### 3.3 Conflictos o propuestas del megaprompt que conviene matizar

1. **"No duplicar información" vs tabla `oportunidades`:** es correcto crear `oportunidades`, pero hay que decidir su relación con `estado_lead` global (hoy el Kanban vive en `clubes_crm.estado_lead`). Un club con 2 oportunidades no cabe en un único `estado_lead`. **Propuesta:** `oportunidades.estado` como fuente del Kanban cuando exista oportunidad; mantener `estado_lead` como histórico.
2. **Event store (Fase 3):** la opción A (ampliar `comunicaciones_log`) es más aditiva y segura que crear `eventos_comerciales`. `comunicaciones_log` ya tiene `tipo_evento`, `plantilla_id`, `variante_ab`, `pipeline_id`, `proxima_accion`, `canal`. **Añadir columna `metadata TEXT (JSON)`** y eventos normalizados.
3. **`EMAIL_DELIVERED`/`EMAIL_BOUNCED` (Fase 3):** con SMTP nativo **solo se conoce `ACCEPTED`**. No se puede afirmar `DELIVERED`; los bounces se detectan por IMAP/DSN. El megaprompt ya lo reconoce en Fase 17 ("SMTP ACCEPTED" ≠ "DELIVERED") — mantener esa honestidad también en el event store.
4. **`respuestas.fecha_respuesta`:** el megaprompt dice "normalizar". Correcto, pero **conservando la columna original** (auditoría) y añadiendo `fecha_respuesta_iso` + `atendido_en` + `tiempo_*` derivado por SQL, no almacenado.
5. **Presupuestos:** el esquema actual usa `pipeline_id`. Si se crea `oportunidades`, `presupuestos` debería pasar a `opportunity_id` **añadiendo columnas** (nunca renombrar las existentes) para no romper `api/presupuestos.php`.
6. **`clubes_crm` = "club" y "lead" a la vez:** hoy 1 club = 1 email = 1 lead. Si un club tiene 2 contactos, existe `contactos_club` (0 filas) — no tocar este modelo ahora (sobreingeniería).

---

## 4. VIABILIDAD GLOBAL POR BLOQUE

| Bloque | Fases | Veredicto |
|---|---|---|
| **A · Seguridad** (0,1,29,37) | — | ✅ **VIABLE YA.** Fase 0 prácticamente hecha (auditoría del 28-08). Fase 29 operativa. Falta: activar `foreign_keys` (con cautela), `docs/plan_instrumentacion_v2.md`, procedimiento de backup previo a migrar. |
| **B · Trazabilidad** (2,3,5,6) | — | ✅ **VIABLE**, es el bloque de mayor valor. Crear `oportunidades` (mínima), ampliar `comunicaciones_log`, ampliar clasificación, normalizar fechas. |
| **C · Marketing** (4,16,17,18,19) | — | ✅ **VIABLE y BLOQUEANTE** antes del próximo lote. Dedup aperturas, clics, supresión bounces, RFC 2047, `variant_original`. |
| **D · Comercial** (7,8,9,10,11,12,13,14,15) | — | ⚠️ **VIABLE pero de baja prioridad de datos.** 0 ventas y 5 respuestas. Poblar `presupuestos`/`mockups` **sí** (es el cuello de botella real); venta/perdidos/negociación pueden quedarse como estructura vacía. |
| **E · Analítica** (20,21,22,23,33,34) | — | ⚠️ **VIABLE incremental.** El dashboard ya existe parcialmente; añadir embudo y export 1 fila=1 lead. La analítica profunda no tendrá datos hasta 2-3 lotes. |
| **F · Escalado** (24,25,26,27,32) | — | ⚠️ **VIABLE, último.** `campaign_batch_id` es barato; el checkpoint de lote y "salud de campaña" son imprescindibles **antes** de escalar a lotes grandes, no antes del primer lote. |

**Conclusión de viabilidad:** ~85 % del megaprompt es implementable con el stack actual (PHP 8 + SQLite, SiteGround-compatible, sin frameworks). La parte **no viable tal cual** no es técnica sino de datos: el histórico A/B/C (Math.random) **no puede convertirse en un experimento limpio retroactivamente** — el propio megaprompt lo respeta con la regla "primer envío + es_rotacion=0". El resto es factible por fases.

---

## 5. RIESGOS PRIORIZADOS

| # | Riesgo | Severidad | Mitigación |
|---|---|---|---|
| 1 | Enviar siguiente lote **sin** supresión de bounces y **sin** RFC 2047 → quemar reputación de las 10 cuentas | **CRÍTICA** | Bloquear envío hasta Fase C mínima |
| 2 | A/B/C del siguiente lote con `Math.random()` en la UI → datos inutilizables de nuevo | ALTA | Sustituir `app.js:1752/1793` por llamada a `asignarVariante()` |
| 3 | Follow-ups manuales huérfanos crecientes (`campaign_id=NULL`) | ALTA | Obligar metadatos en el formulario de seguimiento |
| 4 | `foreign_keys=OFF` + FK inexistentes (plantillas 2 y 6) | MEDIA | Auditoría y decisión sobre activación |
| 5 | Presupuestos/mockups con esquema legacy (`pipeline_id`) | MEDIA | ALTER aditivo, no destructivo |
| 6 | Aperturas infladas (sin dedup) distorsionan open rate | MEDIA | Métricas analíticas separadas del bruto |
| 7 | 7.000 envíos con datos del scraper (muchos `info@`, `administracion@`, dominios corporativos con 1-2 envíos) | MEDIA | Segmentación por dominio/federación y lotes 200-300 |
| 8 | Cumplimiento RGPD/LOPDGDD en 7.000 contactos (el scraper usa datos públicos de federaciones) | MEDIA | Mantener `baja.php` operativo, incluir baja en cada envío (ya existe), auditoría de cesión de datos |

---

## 6. PLAN DE IMPLEMENTACIÓN RECOMENDADO (orden concreto)

El megaprompt pide 40 fases en 6 bloques. Recomendación, alineada con su "ORDEN REAL DE IMPLEMENTACIÓN" pero **más quirúrgica**:

### BLOQUE 0 — PREPARACIÓN (1-2 días)
1. Backup verificable de `stats.db` (repetir el patrón ya existente `stats.db.bak_*`).
2. Redactar `docs/plan_instrumentacion_v2.md` (entregable Fase 0) reutilizando la auditoría del 28-08.
3. Crear la **matriz de test de regresión** (TEST 1-14 del megaprompt) como script de verificación reejecutable.

### BLOQUE 1 — CORRECCIONES BLOQUEANTES (antes de cualquier envío nuevo)

**Migración 1 (aditiva, `envios`):**
```sql
ALTER TABLE envios ADD COLUMN variant_original VARCHAR(1);
ALTER TABLE envios ADD COLUMN campaign_batch_id TEXT;
ALTER TABLE envios ADD COLUMN parent_envio_id INTEGER;
ALTER TABLE envios ADD COLUMN respuesta_origen_id INTEGER;
CREATE INDEX idx_envios_parent ON envios(parent_envio_id);
```

**Migración 2 — supresión de bounces:**
```sql
-- Poblar rebotes (estructura legacy) con los 21 históricos desde respuestas
ALTER TABLE rebotes ADD COLUMN envio_id INTEGER;
ALTER TABLE rebotes ADD COLUMN lead_id INTEGER;
ALTER TABLE rebotes ADD COLUMN campaign_id INTEGER;
ALTER TABLE rebotes ADD COLUMN smtp_code TEXT;
ALTER TABLE rebotes ADD COLUMN atribucion_parcial INTEGER DEFAULT 0;
-- INSERT...SELECT desde respuestas (es_rebote=1) — atribución parcial en los 7 sin cuerpo
```

**Migración 3 — RFC 2047** en `inc/smtp_transport.php` (`futprotec_encodeHeaderName()` con `=?UTF-8?B?...?=`) + test con á/é/í/ó/ú/ñ/ü sobre **raw MIME** (nunca enviar REAL hasta validar).

**Migración 4 — UI determinista:** en `js/app.js` sustituir `Math.random()` por `asignarVariante(lead_id, campaign_id)` (exponer `inc/abc.php` vía endpoint o calcular en backend).

**Migración 5 — follow-ups con metadatos:** en `inc/atencion_lead.php`/formulario de seguimiento, propagar `campaign_id`, `plantilla_id`, `smtp_id`, `variant`, `parent_envio_id`, `respuesta_origen_id`.

### BLOQUE 2 — TRAZABILIDAD COMERCIAL (Fases 2, 3, 5, 6)
- Crear `oportunidades` (mínimo viable del megaprompt, añadiendo `clubes_crm.estado_lead` como referencia histórica, no borrar).
- Ampliar `comunicaciones_log` con `metadata TEXT` y eventos normalizados (`EMAIL_SENT`, `EMAIL_OPENED`, `REPLY_RECEIVED`, `REPLY_CLASSIFIED`, `QUOTE_CREATED`, `MOCKUP_SENT`, `SALE_WON/LOST`, `NEXT_ACTION_*`, …). **No crear** `eventos_comerciales` (opción A más aditiva).
- `respuestas`: `fecha_respuesta_iso` (derivada, conservando la original) + `atendido_en` + nueva clasificación (22 valores) **sin tocar** los valores históricos `POSITIVE/humana/fuera_de_oficina/rebote` (mapeo de lectura).
- `presupuestos`/`mockups`: ALTER aditivo con `campaign_id`, `opportunity_id`, `respuesta_origen_id`, `envio_origen_id`, fechas de aprobación/rechazo, `version`, `notas`.

### BLOQUE 3 — MARKETING MEDIBLE (Fases 4, 17, 19)
- Dedup de aperturas: vistas/columnas `primera_apertura`, `ultima_apertura`, `num_aperturas`, `opened` derivadas del bruto (**sin borrar filas**).
- Tabla `clics` + reescritura de URLs en el envío (`CTA_WEB`, `CTA_PRESUPUESTO`, `CTA_CONTACTO`).
- `campaign_batch_id` operativo con tabla `batches` y **checkpoint pre-lote** (Fases 31-32): TEST/REAL, duplicados, bounces, blacklist, validez email, variante, plantilla, SMTP → `READY TO SEND` / `BLOCKED`.

### BLOQUE 4 — ANALÍTICA (Fases 20, 21, 22, 23)
- Export **1 fila = 1 lead/oportunidad** (el SQL de Fase 21 del megaprompt es correcto y ejecutable sobre este esquema).
- Dashboard: embudo comercial + velocidad (mediana) sobre datos reales; mostrar `N` junto a cada %.

### BLOQUE 5 — ESCALADO (Fases 24-27, 32-34)
- Lotes: **200-300 primero** (no 500), luego 500-1.000 si entregabilidad y seguimiento lo permiten.
- Regla de una variable a la vez por lote (Fase 26).
- Recién aquí, venta/perdidos/negociación con datos (Fases 11-13).

---

## 7. CRITERIOS PASS/FAIL POR BLOQUE

| Check | PASS | FAIL |
|---|---|---|
| TEST/REAL | `envios.es_test` y dashboards comerciales excluyen TEST (TEST 11 del megaprompt) | cualquier TEST en comercial |
| A/B/C | mismo (lead,campaña) → misma variante en UI y backend (TEST 12) | `Math.random` en cualquier ruta |
| Bounce | email rebotado excluido de futuros envíos (TEST 10) | reenvío a HARD_BOUNCE |
| Follow-up | `campaign_id`, `parent_envio_id`, `message_id`, `in_reply_to` (TEST 5) | envío `Re:` con `campaign_id=NULL` |
| Aperturas | bruto intacto + métrica dedup correcta (TEST 2) | borrado de filas |
| Fechas | `fecha_respuesta_iso` comparable en SQL | comparación sobre RFC 2822 |
| Integridad | `integrity_check=ok` tras cada migración + backup previo (TEST 14) | migración sin backup o irrecuperable |
| Export | 1 fila = 1 lead, sin duplicados por apertura | 1 fila por apertura |

---

## 8. CONTRAINFORME — OPINIÓN DEL ASESOR EXTERNO

**Valoración global: 8/10 como especificación de destino. 6/10 como plan de ejecución si se aplicara literalmente.**

### Lo que el megaprompt acierta
1. **Filosofía aditiva y no destructiva** (nuevas columnas/tablas antes que reescribir). Es la única forma de no romper lo que ya funciona.
2. **NULL vs 0** como principio de calidad de datos. Está bien internalizado y es ejecutable.
3. **Aislamiento TEST/REAL como regla dura** y `es_test` como fuente única. Ya operativo.
4. **Regla de interpretación estadística** (no afirmar causalidad con n pequeña, mostrar N). Necesaria y escasa en la industria.
5. **Reconocer que el histórico A/B/C está contaminado** y exigir `variant_original`/`es_rotacion=0` para análisis. Honesto.
6. **El orden por bloques** (seguridad → trazabilidad → marketing → comercial → analítica → escalado) es correcto.

### Lo que el megaprompt subestima o donde el asesor discrepa
1. **Redunda con trabajo ya hecho.** La auditoría del 28-08 (en el repo) ya diagnostica casi todo. El plan debería partir de ese documento, no de una hoja en blanco. El megaprompt funciona mejor como "lista de verificación de destino" que como "plan de trabajo".
2. **40 fases es sobre-alcance para el momento actual.** Con 5 respuestas y 0 ventas, instrumentar ventas/perdidos/negociación (Fases 11-13, bloque D completo) es construir UI sobre tablas vacías. **Prioridad real: supresión de bounces, RFC 2047, variante determinista en UI, dedup de aperturas, clics, follow-ups con metadatos y operativizar presupuestos.** Eso es el 80 % del valor con el 30 % del esfuerzo.
3. **El cuello de botella no es la instrumentación, es el proceso comercial.** El dato más duro de la auditoría: **0 presupuestos a partir de 3 respuestas positivas** y ~9 días de retraso en seguimiento. Se pueden añadir todas las tablas del mundo; si el equipo no clasifica una respuesta y no crea el presupuesto en minutos, el embudo seguirá en 0. La Fase 8 (pantalla de cualificación rápida) y la Fase 35 (timeline en ficha) valen más que el event store completo.
4. **El megaprompt pide `EMAIL_DELIVERED/BOUNCED`** pero el SMTP nativo solo da `ACCEPTED`. Hay que aceptar esa limitación en el modelo de eventos (ya la reconoce en Fase 17, pero no en la lista de eventos de la Fase 3). No se puede afirmar lo que no se sabe.
5. **El tema del nombre del emisor (`Adrián Cano`) es sintomático:** es un problema conocido desde el 19-08 (rebote de Yahoo) y sigue sin corregir el 28-08. La disciplina del megaprompt de "verificar antes de enviar REAL" es correcta y debe ser el **primer gate**.
6. **7.000 leads es una decisión comercial con riesgo legal y reputacional, no solo técnica.** El scraper extrae emails públicos de federaciones, pero eso no garantiza consentimiento para mailing B2B en España (LOPDGDD). Recomendación: mantener el enlace de baja operativo (ya existe), limitar reenvíos, y que el lote inicial sea **200-300**, no 500, hasta validar entregabilidad tras corregir el `From`.
7. **`foreign_keys=OFF` es una deuda técnica que algún día se cobrará.** Si se crean `oportunidades` y `clics` con FKs, habrá que decidir cómo convivir con el historial que ya referencia plantillas inexistentes. Activar FK requiere saneamiento previo.

### Alcance mínimo recomendado para la campaña 2 (los próximos ~7.000 leads)

```
PASO 1 (bloqueante)  →  supresión de HARD_BOUNCE + RFC 2047 + variante determinista en UI + verificación raw MIME
PASO 2 (1 semana)    →  follow-ups con metadatos + clasificación fina manual + dedup aperturas + clics
PASO 3               →  operativizar presupuestos/mockups + pantalla de cualificación rápida (Fase 8)
PASO 4               →  export analítico + embudo + lote 200-300 con checkpoint pre-lote
PASO 5               →  event store + oportunidades + pipeline histórico (solo si PASO 1-4 pasan)
```

El resto (venta, pérdidas, negociación, dashboard de velocidad) **se construye cuando haya datos**, no antes.

---

## 9. CONCLUSIÓN

- **Viable:** sí. El CRM FutProtec tiene una base sólida y la instrumentación V2 es alcanzable con PHP+SQLite nativos, por fases y sin reescribir.
- **Recomendación al asesor:** aprobar el plan con dos condiciones: (a) **no enviar ningún lote REAL hasta pasar el Bloque C mínimo** (supresión de bounces, RFC 2047, variante determinista); (b) **reducir el alcance a los PASOS 1-5 de la sección 8** en el primer ciclo, dejando las fases 11-13 para cuando existan datos comerciales.
- **Riesgo a vigilar de cerca:** la velocidad de atención al lead (respuesta → presupuesto), hoy el verdadero cuello de botella, y la reputación SMTP durante el escalado a 7.000.
- **No se ha modificado nada en producción.** Este documento es el entregable de la "Fase 0 - Auditoría y Snapshot" del megaprompt, en formato contrainforme para revisión del asesor externo.

---

*Fin del contrainforme · 2026-08-28 · Análisis realizado en modo solo lectura sobre `public_html/outbound/data/stats.db` y el código vivo de `public_html/outbound/`.*
