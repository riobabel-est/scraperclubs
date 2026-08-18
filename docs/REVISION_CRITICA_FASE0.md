# REVISIÓN CRÍTICA FASE 0 — CRM OUTBOUND FUTPROTEC

- **Fecha**: 2026-08-18
- **Modo**: READ-ONLY (no se modificó archivo, BD, configuración, cron ni producción)
- **Referencia**: `docs/MAESTRO_ARQUITECTURA_FASE0.md`
- **Propósito**: Determinar si el contrato arquitectónico está suficientemente definido para comenzar una migración, distinguiendo HECHOS comprobados, PROBLEMAS demostrados, DECISIONES propuestas e HIPÓTESIS sin validar.
- **Evidencia**: auditoría read-only sobre `public_html/outbound/data/stats.db` (local) y `backups_deploy/stats_db_LIVE_backup_1786998742.db` (snapshot de producción), más lectura de `inc/eligibilidad.php`, `inc/abc.php`, `inc/metricas.php`, `dashboard.php`, `js/app.js`.

---

## 1. VEREDICTO GENERAL

### APROBADA CON CORRECCIONES

**Motivo**: El MAESTRO FASE 0 describe con precisión el estado real del sistema y sus invariantes, y la mayoría de sus afirmaciones se verifican en código y BD. Sin embargo, la revisión detecta **tres correcciones obligatorias antes de implementar**:

1. **El MAESTRO asume que `envios` se une a `clubes_crm` por email como riesgo §5.5, pero NO cuantifica el riesgo real.** La auditoría demuestra que en la BD actual **todos los envíos tienen `lead_id` válido y consistente** (0 huérfanos, 0 discrepancias email). El riesgo es **de código** (joins por email en `dashboard.php`), no de datos. La estrategia de normalización debe priorizar el código, no una migración de datos que hoy no es necesaria.

2. **El MAESTRO no documenta la divergencia de esquema local vs producción.** La BD local `stats.db` tiene `envios.es_test` (migración de aislamiento aplicada), pero el snapshot de producción `stats_db_LIVE_backup_1786998742.db` **NO tiene `es_test`**. Cualquier fase que dependa de `es_test` (I2) debe primero reconciliar el esquema de producción, o el aislamiento TEST/REAL no está garantizado en producción.

3. **El MAESTRO propone `cola_envios` (§9) como "recomendación" sin demostrar la necesidad real.** La auditoría muestra que el motor actual es **manual y dirigido** (lanzadera JS con cola en memoria, `lzCola`), no un worker automático masivo. La cola persistente es una **decisión de producto** (¿se quiere envío automático programado?), no una necesidad derivada de los datos. Debe validarse antes de construirla.

Además, el MAESTRO mezcla en la matriz C1–C10 **cambios de presentación (C1, C7, C9, C10) con cambios estructurales (C2–C6, C8)** sin separar su criticidad. El roadmap debe reordenarse por riesgo e integridad de datos, no por el orden C1→C10.

---

## 2. HECHOS COMPROBADOS

| Hecho | Evidencia | Confianza |
|---|---|---|
| `envios` tiene columnas `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`, `message_id`, `resultado_envio`, `fecha_resultado_envio`, `es_test` | `PRAGMA table_info(envios)` en `stats.db` | ALTA |
| Índice único parcial `idx_envios_lead_campaign ON envios(lead_id, campaign_id) WHERE campaign_id IS NOT NULL` | `PRAGMA index_list(envios)` | ALTA |
| `reservarEnvioLogico()` usa `INSERT OR IGNORE` + índice único para idempotencia (I1) | `inc/eligibilidad.php:267-289` | ALTA |
| `asignarVariante()` es determinística: `crc32(campaignId:leadId) % 3` → A/B/C | `inc/abc.php:24-34` | ALTA |
| `resolverContenidoVariante()` usa `plantillas.asunto_b/c`, `cuerpo_b/c`, `test_ab` | `inc/abc.php:42-66` | ALTA |
| `esLeadTest()` = email `@futprotec.local` O nombre_club `test*` | `inc/eligibilidad.php:26-38` | ALTA |
| `esCampanaTest()` = `pipelines.entorno='test'` | `inc/eligibilidad.php:46-55` | ALTA |
| `esEnvioTest()` = `es_test=1` primario + fallback email/club | `inc/eligibilidad.php:72-90` | ALTA |
| `sqlFiltroComercial()` = `COALESCE(es_test,0)=0` | `inc/eligibilidad.php:102-106` | ALTA |
| `esElegibleParaEnvio()` bloquea supresión, duplicado, email inválido, y aislamiento TEST/REAL simétrico | `inc/eligibilidad.php:148-195` | ALTA |
| `plantillaEstaCongelada()` infiere congelación de `envios JOIN pipelines` (estado PILOT/ACTIVE), sin filtrar entorno | `inc/eligibilidad.php:208-221` | ALTA |
| `validarCampanaActiva()` exige estado PILOT/ACTIVE + activo=1 + `esEntornoCoherente()` | `inc/abc.php:75-101` | ALTA |
| `esEntornoCoherente()` bloquea campaña test en producción y campaña comercial en modo test | `inc/abc.php:117-133` | ALTA |
| `metricas.php` NO une `envios`↔`clubes_crm`; usa solo `envios`+`aperturas`+`respuestas` | `inc/metricas.php` | ALTA |
| `dashboard.php` une `envios`↔`clubes_crm` **por email** en múltiples consultas (get_analytics, noRespondedores, sinProximaAccion, kanban, etc.) | `dashboard.php` (32 coincidencias de JOIN por email) | ALTA |
| BD local `stats.db`: 1817 clubes, emails 100% únicos, 0 duplicados | auditoría read-only | ALTA |
| BD local `stats.db`: 14 envíos, **todos con `lead_id`**, 0 huérfanos, 0 discrepancias email | auditoría read-only | ALTA |
| BD local: `fecha_proxima_accion` NO existe en `clubes_crm` | `PRAGMA table_info(clubes_crm)` | ALTA |
| BD local: `pipelines.plantilla_id` NO existe | `PRAGMA table_info(pipelines)` | ALTA |
| BD local: `comunicaciones_log.tipo_evento` es string libre, sin validación; solo 2 valores usados (`cambio_estado`, `envio_email`) | `PRAGMA` + `GROUP BY tipo_evento` | ALTA |
| BD local: `estado_lead` tiene inconsistencia de formato: 1813 "01 Sin Contactar" + 3 "Sin Contactar" | `GROUP BY estado_lead` | ALTA |
| BD local: `config.modo_entorno=test`, `motor_estado=pausado` | `SELECT * FROM config` | ALTA |
| BD local: `lead_pipelines` solo 5 filas, todas `pipeline_id=1` (legacy/test) | auditoría read-only | ALTA |
| BD local: `envios` 12 es_test=1, 2 es_test=0; 6 con campaign_id=3, 8 sin campaña; 2 legacy con variant/plantilla_id/message_id NULL | auditoría read-only | ALTA |
| Snapshot producción `stats_db_LIVE_backup_1786998742.db`: **NO tiene `envios.es_test`** | `PRAGMA table_info(envios)` sobre el backup | ALTA |
| Snapshot producción: 1818 clubes (1809 reales, 9 test); 32 envíos (14 reales, 18 test); 66 leads `es_duplicado=1` | `tmp_audit_cola.php` sobre el backup | ALTA |
| Snapshot producción: `config.modo_entorno=produccion`, `motor_estado=pausado` | `tmp_audit_cola.php` | ALTA |
| Snapshot producción: 10 cuentas SMTP activas, límite 15/día | `tmp_audit_cola.php` | ALTA |
| Lanzadera mantiene cola en memoria JS (`lzCola`, `lzColaIndex`, `lzBatchSize`, `lzAbortController`), no persistente | `js/app.js:91-96, 705-804` | ALTA |

---

## 3. PROBLEMAS REALES

| Problema | Severidad | Impacto | Evidencia |
|---|---|---|---|
| **Divergencia de esquema local vs producción**: `envios.es_test` existe en local pero NO en el snapshot de producción | CRÍTICA | El aislamiento TEST/REAL (I2) y `sqlFiltroComercial()` dependen de `es_test`. Si producción no tiene la columna, las consultas comerciales en producción fallan o no filtran TEST | `PRAGMA table_info(envios)` local vs backup LIVE |
| **Joins por email en `dashboard.php`** en lugar de `lead_id` | ALTA (código) | Riesgo de atribución incorrecta si un email cambia o hay duplicados; hoy los datos son consistentes, pero el código es frágil | `dashboard.php` (32 coincidencias) |
| **`estado_lead` mezcla estado comercial y actividad** | ALTA | El Kanban no refleja actividad (abrió/respondió) sin consultar tablas derivadas; "03 Respondió" es un estado, no una actividad | `GROUP BY estado_lead` + §5.4 MAESTRO |
| **`comunicaciones_log.tipo_evento` sin vocabulario controlado** | MEDIA | Timeline no estructurado; `respuesta_recibida` no se registra (0 ocurrencias); no se puede renderizar timeline comercial coherente | `GROUP BY tipo_evento` (solo 2 valores) |
| **`pipelines` ambiguo (campaña vs etapa)** | MEDIA | Confusión conceptual en UI y modelo; "pipeline" se usa para campaña y para etapa del Kanban | §5.1 MAESTRO |
| **Campaña↔plantilla desconectadas** | MEDIA | `cron.php` elige la primera plantilla HTML activa, ignora la campaña; `plantillaEstaCongelada()` infiere la relación del histórico | `cron.php` + `plantillaEstaCongelada()` |
| **`plantillaEstaCongelada()` no distingue entorno** | MEDIA | Una campaña TEST en estado PILOT congela plantillas reales (ruido/bloqueo) | `eligibilidad.php:208-221` |
| **Inconsistencia de formato en `estado_lead`** ("01 Sin Contactar" vs "Sin Contactar") | MEDIA | Rompe agrupaciones y filtros del funnel | `GROUP BY estado_lead` |
| **`proxima_accion` sin fecha estructurada** | MEDIA | No se puede ordenar por vencimiento; dashboard "qué debo hacer hoy" no es posible | `PRAGMA table_info(clubes_crm)` (no existe `fecha_proxima_accion`) |
| **`lead_pipelines` casi vacío y no usado para segmentar** | BAJA | No hay segmentación por campaña; solo 5 filas legacy | auditoría read-only |
| **2 envíos legacy sin `variant`/`plantilla_id`/`message_id`** | BAJA | No participan en métricas A/B/C; son históricos de 2026-08-07 | auditoría read-only |

---

## 4. DECISIONES ARQUITECTÓNICAS QUE TODAVÍA NO ESTÁN DEMOSTRADAS

| Decisión | Motivo | Riesgo | Qué falta demostrar |
|---|---|---|---|
| **`cola_envios` como capa de planificación separada** | El MAESTRO §9 la recomienda, pero el motor actual es manual/dirigido (lanzadera JS con cola en memoria) | Construir infraestructura para un caso de uso que quizá no se necesita | ¿Se quiere envío automático programado con ventana horaria/retries? ¿O basta con la lanzadera manual + `envios` para reanudación? |
| **`campana_plantillas` (tabla intermedia)** | El MAESTRO C4 la propone para relacionar campaña↔plantilla | Cambia el flujo de selección de plantilla; riesgo alto | ¿Basta con `pipelines.plantilla_id` (una columna) para el caso actual de 1 plantilla por campaña? ¿Se necesitan secuencias (paso 1, 2, 3) ya? |
| **`variantes` como entidad** | El MAESTRO C5 la propone para versionar A/B/C | Migración de columnas a tabla; riesgo alto | ¿Se necesita declarar variante ganadora y reutilizar variantes entre campañas YA? ¿O basta con las columnas actuales + `envios.variant` inmutable? |
| **`fecha_proxima_accion`** | El MAESTRO C3 la propone | Bajo | ¿Se necesita una entidad de tareas/acciones o basta con texto + fecha? |
| **Normalizar `tipo_evento`** | El MAESTRO C2 la propone | Medio | ¿Se migra histórico o solo se valida escritura futura + mapeo en lectura? |
| **Renombrar `pipelines`→`campañas`** | El MAESTRO §5.1 lo recomienda | Medio | ¿Se renombra la tabla (migración) o solo el concepto en UI/documentación? |

---

## 5. MODELO CONCEPTUAL DEFINITIVO PROPUESTO

Separación estricta de conceptos, con la fuente de verdad de cada uno:

```
LEAD (clubes_crm)
  ├─ id (PK, inmutable) ──────────────── identidad
  ├─ email (UNIQUE) ──────────────────── identidad de contacto
  ├─ estado_lead ─────────────────────── ESTADO COMERCIAL (manual, 9 etapas + supresión)
  ├─ proxima_accion (texto) ──────────── PRÓXIMA ACCIÓN (qué hacer)
  └─ (futuro) fecha_proxima_accion ───── PRÓXIMA ACCIÓN (cuándo)

CAMPAÑA (pipelines) ──────────────────── ejecución de envío (estado, entorno, activo)
  └─ (futuro) plantilla_id ───────────── recurso de contenido (1 plantilla por campaña)

PLANTILLA (plantillas) ───────────────── recurso de contenido reutilizable
  └─ asunto_b/c, cuerpo_b/c, test_ab ─── VARIANTES A/B/C (embebidas hoy)

ENVÍO (envios) ───────────────────────── RESULTADO inmutable (qué se envió)
  ├─ lead_id → clubes_crm.id
  ├─ campaign_id → pipelines.id
  ├─ variant (inmutable) ─────────────── variante usada
  ├─ cuerpo_mensaje, asunto (inmutable) ─ snapshot del mensaje
  ├─ message_id, tracking_id (inmutable)
  └─ resultado_envio (ACCEPTED) ──────── aceptación SMTP

EVENTO (comunicaciones_log) ──────────── timeline (tipo_evento normalizado)
  ├─ lead_id → clubes_crm.id
  └─ tipo_evento (vocabulario controlado)

APERTURA (aperturas) ─────────────────── tracking por tracking_id → envios
RESPUESTA (respuestas) ───────────────── inbound por envio_id → envios
REBOTE (rebotes) ─────────────────────── por email (esquema LIVE)

PROPUESTA (mockups) ──────────────────── por lead_id
PRESUPUESTO (presupuestos) ───────────── por lead_id
```

**Principio rector**: `envios` es la única fuente de verdad del RESULTADO. `comunicaciones_log` es la única fuente de verdad del TIMELINE. `estado_lead` es SOLO estado comercial (manual). La actividad (abrió/respondió/rebotó) se **deriva** de `envios`/`aperturas`/`respuestas`/`rebotes`, nunca se almacena en `estado_lead`.

---

## 6. `envios` vs `cola_envios`

### Qué representa una fila de `envios` hoy (verificado)

Una fila de `envios` es el **resultado de un envío lógico** (lead, campaña), con su snapshot inmutable. NO es una cola de trabajo:

| Aspecto | `envios` hoy |
|---|---|
| intención | No (no hay "pendiente de programar") |
| trabajo pendiente | Parcial (estado `pendiente`/`error` son retryables sobre la MISMA fila) |
| intento | Parcial (no hay contador de intentos ni backoff) |
| resultado | **SÍ** (estado + `resultado_envio` + `message_id` + snapshot) |
| histórico | **SÍ** (inmutable) |

### ¿Es necesaria `cola_envios`?

**NO está demostrado que sea necesaria hoy.** El motor actual es:
- **Lanzadera manual** (`js/app.js`): cola en memoria JS (`lzCola`), envía dirigido o desde cola, con delay y batch. No necesita cola persistente.
- **Cron** (`cli/cron.php`): 1 envío por ejecución, selecciona lead al vuelo. No usa cola.

`envios` + idempotencia (I1) + reanudación (I7) ya cubren la reanudación: un lead ya enviado no se reenvía; un envío `pendiente`/`error` se reintenta sobre la misma fila.

**Conclusión**: `cola_envios` solo se justifica si se decide implementar **envío automático programado** (ventana horaria, retries con backoff, secuencias de follow-ups, límites diarios por campaña). Es una **decisión de producto**, no una necesidad derivada de los datos. **No construirla hasta validar ese caso de uso.** Si se construye, su responsabilidad es SOLO planificación/orquestación (descartable/regenerable), y `envios` sigue siendo el resultado permanente (I4).

---

## 7. AUDITORÍA lead_id/email

### Resultados (BD local `stats.db`)

| Métrica | Valor |
|---|---|
| Total envíos | 14 |
| Envíos con `lead_id` NOT NULL | 14 (100%) |
| Envíos sin `lead_id` | 0 |
| Envíos con `lead_id` que NO existe en `clubes_crm` | 0 |
| Envíos con `lead_id` cuyo email difiere del club | 0 |
| Emails en `envios` que NO existen en `clubes_crm` | 0 |
| Clubes totales | 1817 |
| Emails únicos en `clubes_crm` | 1817 (100%) |
| Emails duplicados | 0 |

### Interpretación

**El riesgo §5.5 del MAESTRO es de CÓDIGO, no de DATOS.** En la BD actual:
- Todos los envíos tienen `lead_id` válido y consistente con su email.
- No hay emails huérfanos ni duplicados.
- El índice único `idx_envios_lead_campaign` garantiza idempotencia.

El problema real es que **`dashboard.php` une `envios`↔`clubes_crm` por email** en muchas consultas (get_analytics, noRespondedores, sinProximaAccion, kanban, etc.), cuando `lead_id` es la clave correcta. Esto es frágil: si un email cambia o se introduce un duplicado, la atribución se rompe.

### Estrategia segura de normalización

1. **NO migrar datos** (no hay datos que corregir; todos los envíos ya tienen `lead_id`).
2. **Refactorizar el código** de `dashboard.php` para unir por `lead_id` (con fallback a email solo para filas legacy sin `lead_id`, que hoy son 0).
3. **Añadir una consulta de guardia** (test) que verifique que `COUNT(envios sin lead_id) = 0` y que no haya discrepancias lead_id/email, para detectar regresiones futuras.
4. **Prioridad**: este refactor es de bajo riesgo y alto valor, y debe ser la **primera fase de implementación** (ver §13).

---

## 8. CAMPAÑA / PLANTILLA / VARIANTE

### Modelo recomendado (mínima complejidad)

**No introducir `campana_plantillas` ni `variantes` como tablas nuevas todavía.** La complejidad actual (columnas A/B/C en `plantillas` + `envios.variant` inmutable) es suficiente para el caso de uso actual:

- **Campaña** → 1 plantilla: basta con **`pipelines.plantilla_id`** (una columna, ALTER TABLE). No hace falta tabla intermedia salvo que se necesiten secuencias (paso 1, 2, 3) o múltiples plantillas por campaña, que hoy NO se usan.
- **A/B/C**: las columnas `asunto_b/c`, `cuerpo_b/c`, `test_ab` + `asignarVariante()` determinística + `envios.variant` inmutable ya soportan A/B/C correctamente. No se necesita entidad `variantes` salvo que se quiera declarar variante ganadora o reutilizar variantes entre campañas.
- **Congelación**: `plantillaEstaCongelada()` es correcta como regla de seguridad. El problema es que **no distingue entorno** (una campaña TEST en PILOT congela plantillas reales). Corrección de bajo riesgo: filtrar `p.entorno <> 'test'` en la consulta, o documentar la decisión.
- **Reutilización**: la duplicación de plantilla ("Duplicar para editar") resuelve la UX sin tocar el motor ni `envios`.

### Justificación de mínima complejidad

La regla del usuario es "no introducir normalización por sofisticación innecesaria". `campana_plantillas` y `variantes` son **normalización estructural** que solo se justifica cuando exista la necesidad real de secuencias, versionado o variantes ganadoras. Hoy no existe. **Diferir ambas a una fase posterior con aprobación explícita.**

---

## 9. HISTÓRICO Y EVENTOS

### Qué es INMUTABLE en `envios` (una vez enviado)

| Campo | Inmutable | Motivo |
|---|---|---|
| `variant` | SÍ | I5 — variante determinística e inmutable |
| `cuerpo_mensaje` | SÍ | I4 — snapshot del mensaje enviado |
| `asunto` | SÍ | I4 — snapshot del asunto enviado |
| `message_id` | SÍ | I4 — identidad del mensaje |
| `tracking_id` | SÍ | I4 — identidad del tracking |
| `lead_id`, `campaign_id`, `plantilla_id`, `smtp_id` | SÍ | I4 — atribución del envío |
| `resultado_envio` | SÍ (una vez ACCEPTED) | fuente de aceptación SMTP |

### Qué es MUTABLE durante el ciclo de un envío

| Campo | Mutable | Cuándo |
|---|---|---|
| `estado` | SÍ | `pendiente` → `enviado` → `abierto` / `error` (retryable sobre la misma fila) |
| `fecha_resultado_envio` | SÍ | al registrar el resultado SMTP |

### Cómo distinguir planificación / intento / resultado / apertura / respuesta SIN destruir histórico

- **Planificación**: NO existe hoy (no hay cola). Si se añade `cola_envios`, es una tabla separada descartable.
- **Intento**: `envios.estado` (`pendiente`/`error` retryables sobre la misma fila) + `comunicaciones_log` (`envio_email`).
- **Resultado**: `envios.resultado_envio` (`ACCEPTED`) + `fecha_resultado_envio`.
- **Apertura**: `aperturas` (por `tracking_id`) + `envios.estado='abierto'`.
- **Respuesta**: `respuestas` (por `envio_id`) + `comunicaciones_log` (`respuesta_recibida`, hoy NO se registra).

**Regla**: nunca sobrescribir los campos inmutables. La congelación de plantillas (`plantillaEstaCongelada`) protege que una plantilla usada por campaña PILOT/ACTIVE no se sobrescriba.

---

## 10. KANBAN / ACTIVIDAD / PRÓXIMA ACCIÓN

### Modelo operativo definitivo

- **`estado_lead` = SOLO estado comercial** (manual, movido por el comercial en el Kanban). 9 etapas (01→09) + estados de supresión.
- **Actividad = derivada** de `envios`/`aperturas`/`respuestas`/`rebotes`. Nunca se almacena en `estado_lead`.
- **Eventos que pueden provocar cambio de estado AUTOMÁTICO** (a definir con cuidado):
  - Tras envío aceptado → `02 Contactado` (ya ocurre en `enviar_lote.php`/`cron.php`).
  - Tras respuesta → `03 Respondió` (hoy hay 1 lead en "03 Respondió", pero `respuesta_recibida` no se registra en log).
  - Tras rebote → supresión (hoy 0 rebotes).
  - Tras baja/opt-out → supresión (I3).
- **Eventos que deben ser SIEMPRE manuales**: avance a propuesta, negociación, ganado, perdido, y cualquier cambio de etapa comercial.

### Corrección necesaria

- **Unificar el formato de `estado_lead`**: hoy hay 1813 "01 Sin Contactar" + 3 "Sin Contactar". Debe normalizarse a un único vocabulario (I6). Esto es una migración de datos de bajo riesgo (3 filas) que debe hacerse con cuidado y con backup.

### Próxima acción

- `proxima_accion` (texto) es suficiente para "qué hacer". 
- **`fecha_proxima_accion`** (una columna DATETIME) es suficiente para "cuándo". **No se necesita una entidad de tareas/acciones** salvo que se quiera un sistema de tareas asignadas a usuarios con estados, que hoy no existe.
- **No añadir tabla de tareas** hasta que exista la necesidad real.

---

## 11. INVARIANTES I1–I10

| Invariante | ¿Demostrada? | Dónde se implementa | ¿Cómo se testea? | ¿Qué podría romperla? |
|---|---|---|---|---|
| **I1 Idempotencia** | SÍ | `idx_envios_lead_campaign` (único parcial) + `reservarEnvioLogico()` con `INSERT OR IGNORE` | Insertar 2 envíos para mismo (lead, campaña) → solo 1 fila | Quitar el índice único; cambiar `reservarEnvioLogico` a `INSERT` directo; migrar `envios` sin preservar el índice |
| **I2 Aislamiento TEST/REAL** | ⚠️ PARCIAL | `esLeadTest`/`esCampanaTest`/`esEnvioTest`/`sqlFiltroComercial`/`sqlFiltroCompatibilidadLeadCampana` | Enviar lead TEST a campaña no-test → bloqueado; y viceversa | **Producción NO tiene `envios.es_test`** (divergencia de esquema). Si no se reconcilia, `sqlFiltroComercial()` falla o no filtra en producción |
| **I3 Opt-out protegido** | SÍ (parcial) | `esElegibleParaEnvio` bloquea estados de supresión; `api/baja.php` marca baja | Baja real → no reactivable desde Kanban; solo `blacklist_remove` con motivo | Permitir reactivar una baja real desde el Kanban; borrar la marca `[BAJA]` |
| **I4 Histórico inmutable** | SÍ | `envios` snapshot + `plantillaEstaCongelada` | Verificar que `cuerpo_mensaje`/`variant`/`message_id` no cambian tras envío | Sobrescribir `envios`; quitar congelación sin alternativa |
| **I5 Variantes determinísticas** | SÍ | `asignarVariante()` = `crc32(campaignId:leadId) % 3` | Mismo (lead, campaña) → misma variante en retry | Cambiar el algoritmo de hash; asignar variante aleatoria por envío |
| **I6 Estados Kanban** | ⚠️ PARCIAL | `estado_lead` + `stageOrder` | Verificar los 9 estados + supresión | **Inconsistencia de formato** ("01 Sin Contactar" vs "Sin Contactar"); introducir estado nuevo sin actualizar `stageOrder` |
| **I7 Reanudación** | SÍ | `envios` + idempotencia | Relanzar envío a mitad de campaña → no reenvía leads ya enviados | Perder el índice único; borrar `envios` |
| **I8 Coherencia de entorno** | SÍ | `esEntornoCoherente()` | Campaña test en producción → bloqueado; campaña comercial en modo test → bloqueado | Cambiar la lógica de `esEntornoCoherente`; no validar en cron/enviar_lote |
| **I9 Credenciales SMTP** | SÍ | `cuentas_smtp` + `$CUENTAS_SMTP` | No exponer en logs/commits | Loggear passwords; sobrescribir el array sin permiso |
| **I10 Protección output/checkpoints** | SÍ | regla de proceso | No borrar/sobrescribir sin permiso | Cualquier `rm`/`write` sobre output/checkpoints |

**Conclusión**: I1, I4, I5, I7, I8, I9, I10 están demostradas y son robustas. **I2 e I6 tienen correcciones pendientes** (divergencia de esquema `es_test` en producción; inconsistencia de formato `estado_lead`). Estas dos deben resolverse antes de cualquier migración estructural.

---

## 12. CORRECCIONES NECESARIAS AL MAESTRO FASE 0

Antes de implementar, el MAESTRO debe actualizarse con:

1. **§5.5 (envios unido por email)**: reescribir para distinguir que el riesgo es de CÓDIGO (joins por email en `dashboard.php`), no de datos. Añadir la evidencia de la auditoría (0 huérfanos, 0 discrepancias, 100% lead_id).

2. **Nueva sección: Divergencia de esquema local vs producción**. Documentar que `envios.es_test` existe en local pero NO en el snapshot de producción. Esto es crítico para I2.

3. **§9 (cola_envios)**: cambiar de "recomendación" a "decisión de producto pendiente de validación". No construir hasta confirmar el caso de uso de envío automático programado.

4. **§11 (C4, C5)**: marcar `campana_plantillas` y `variantes` como "diferidas, requieren aprobación explícita y necesidad real demostrada". Recomendar `pipelines.plantilla_id` (una columna) como solución mínima.

5. **§11 (C3)**: confirmar que `fecha_proxima_accion` es suficiente y NO se necesita entidad de tareas.

6. **§11 (C2)**: aclarar que la normalización de `tipo_evento` debe ser en escritura futura + mapeo en lectura, NO migrar histórico (solo 2 valores usados hoy).

7. **§7 (I6)**: añadir la inconsistencia de formato `estado_lead` como corrección pendiente.

8. **§12 (Registro de fases)**: añadir esta REVISIÓN CRÍTICA como FASE 0.1 (read-only).

---

## 13. ROADMAP RECOMENDADO (por riesgo e integridad de datos)

Reordenado por riesgo e integridad de datos, no por el orden C1→C10 del MAESTRO. Cada fase es independiente, reversible y con su propio checkpoint.

### FASE A — Reconciliar esquema de producción (CRÍTICA, bloqueante)
- **Objetivo**: garantizar que producción tenga `envios.es_test` (I2) y que `sqlFiltroComercial()` funcione en producción.
- **Problema**: la BD local tiene `es_test`, el snapshot de producción NO. Sin `es_test`, el aislamiento TEST/REAL no está garantizado en producción.
- **Decisiones D**: D1 (aislamiento TEST/REAL), D2 (entorno).
- **Cambios C**: C2 (es_test), C6 (aislamiento).
- **Invariantes I**: I2, I8.
- **Tablas/archivos**: `envios` (ALTER TABLE ADD es_test), `scripts/migracion_live_es_test.php`, `cli/migracion_live_runner.php`.
- **Migración**: ALTER TABLE ADD COLUMN `es_test INTEGER NOT NULL DEFAULT 0`; backfill de filas legacy según `esLeadTest()`/`esEnvioTest()` (email `@futprotec.local` o club `test*`).
- **Riesgos**: backfill incorrecto de filas legacy; romper consultas que no esperan la columna.
- **Rollback**: backup previo de la BD; DROP COLUMN (SQLite 3.35+) o restaurar backup.
- **Pruebas**: verificar que `sqlFiltroComercial()` devuelve solo REALES en producción; que las 18 filas TEST se marcan `es_test=1`.
- **PASS**: producción tiene `es_test`; `SELECT COUNT(*) FROM envios WHERE es_test=1` = 18 (TEST) y `es_test=0` = 14 (REALES) en el snapshot.
- **FAIL**: la columna no se crea; el backfill marca filas REALES como TEST o viceversa.

### FASE B — Refactorizar joins por email → lead_id en dashboard.php (ALTA, bajo riesgo)
- **Objetivo**: eliminar el riesgo §5.5 de atribución incorrecta.
- **Problema**: `dashboard.php` une `envios`↔`clubes_crm` por email en 32 consultas; `lead_id` es la clave correcta.
- **Decisiones D**: D3 (identidad del lead).
- **Cambios C**: C6 (normalización de joins).
- **Invariantes I**: I1, I4.
- **Tablas/archivos**: `dashboard.php` (get_analytics, noRespondedores, sinProximaAccion, kanban, etc.).
- **Migración**: NO hay migración de datos (todos los envíos ya tienen `lead_id`). Solo refactor de código con fallback a email para filas legacy sin `lead_id` (hoy 0).
- **Riesgos**: cambiar el resultado de una consulta sin querer; romper el Kanban.
- **Rollback**: revertir el diff de `dashboard.php` (git).
- **Pruebas**: comparar resultados antes/después en una BD de prueba; verificar que los KPIs no cambian.
- **PASS**: las consultas usan `lead_id`; los KPIs (contactados, abiertos, respondidos) son idénticos antes/después.
- **FAIL**: algún KPI cambia; alguna consulta devuelve error.

### FASE C — Unificar formato de `estado_lead` (MEDIA, bajo riesgo)
- **Objetivo**: resolver la inconsistencia "01 Sin Contactar" vs "Sin Contactar" (I6).
- **Problema**: 1813 "01 Sin Contactar" + 3 "Sin Contactar" rompen agrupaciones.
- **Decisiones D**: D4 (estados Kanban).
- **Cambios C**: C7 (estados).
- **Invariantes I**: I6.
- **Tablas/archivos**: `clubes_crm.estado_lead` (UPDATE de 3 filas).
- **Migración**: UPDATE `clubes_crm SET estado_lead='01 Sin Contactar' WHERE estado_lead='Sin Contactar'` (3 filas). Con backup.
- **Riesgos**: bajo (3 filas); asegurar que no hay otros valores legacy.
- **Rollback**: UPDATE inverso o restaurar backup.
- **Pruebas**: `SELECT estado_lead, COUNT(*) GROUP BY estado_lead` → sin valores sin prefijo numérico.
- **PASS**: 0 filas con "Sin Contactar" sin prefijo; el funnel agrupa correctamente.
- **FAIL**: quedan valores legacy; el Kanban muestra columnas duplicadas.

### FASE D — Conectar campaña↔plantilla (MEDIA)
- **Objetivo**: que la campaña tenga una plantilla explícita (no inferida del histórico).
- **Problema**: `cron.php` elige la primera plantilla HTML activa; `plantillaEstaCongelada()` infiere la relación.
- **Decisiones D**: D5 (campaña↔plantilla).
- **Cambios C**: C4 (mínimo: `pipelines.plantilla_id`).
- **Invariantes I**: I4.
- **Tablas/archivos**: `pipelines` (ALTER TABLE ADD plantilla_id), `cron.php`, `enviar_lote.php`, `plantillaEstaCongelada()`.
- **Migración**: ALTER TABLE ADD COLUMN `plantilla_id`; backfill según el histórico de `envios` (la plantilla más usada por campaña).
- **Riesgos**: backfill incorrecto; romper el flujo de selección de plantilla.
- **Rollback**: revertir ALTER + diff de código.
- **Pruebas**: una campaña con `plantilla_id` usa SIEMPRE esa plantilla; `plantillaEstaCongelada()` filtra por entorno.
- **PASS**: `cron.php`/`enviar_lote.php` usan `pipelines.plantilla_id`; `plantillaEstaCongelada()` no congela por campañas TEST.
- **FAIL**: se sigue eligiendo plantilla al azar; una campaña TEST congela plantillas reales.

### FASE E — Normalizar `tipo_evento` en comunicaciones_log (MEDIA)
- **Objetivo**: timeline estructurado.
- **Problema**: `tipo_evento` es string libre; solo 2 valores usados; `respuesta_recibida` no se registra.
- **Decisiones D**: D6 (timeline).
- **Cambios C**: C2 (vocabulario controlado).
- **Invariantes I**: I4.
- **Tablas/archivos**: `comunicaciones_log.tipo_evento` (validación en escritura + mapeo en lectura), `api/baja.php`, `enviar_lote.php`, `cron.php`, `respuestas.php`.
- **Migración**: NO migrar histórico (solo 2 valores). Validar escritura futura + mapeo en lectura.
- **Riesgos**: bajo.
- **Rollback**: revertir diffs de código.
- **Pruebas**: registrar `respuesta_recibida` al recibir una respuesta; el timeline lo muestra.
- **PASS**: `respuesta_recibida` aparece en `comunicaciones_log`; el timeline renderiza eventos normalizados.
- **FAIL**: se siguen escribiendo valores libres; el timeline no muestra respuestas.

### FASE F — `fecha_proxima_accion` (BAJA)
- **Objetivo**: ordenar por vencimiento de próxima acción.
- **Problema**: `proxima_accion` es texto sin fecha.
- **Decisiones D**: D7 (próxima acción).
- **Cambios C**: C3 (mínimo: columna `fecha_proxima_accion`).
- **Invariantes I**: I6.
- **Tablas/archivos**: `clubes_crm` (ALTER TABLE ADD fecha_proxima_accion), dashboard.
- **Migración**: ALTER TABLE ADD COLUMN; sin backfill (campo nuevo).
- **Riesgos**: bajo.
- **Rollback**: revertir ALTER.
- **Pruebas**: el dashboard ordena por `fecha_proxima_accion`.
- **PASS**: la columna existe; el dashboard muestra "qué hacer hoy".
- **FAIL**: la columna no se crea; el dashboard no ordena.

### FASE G — Diferidas (requieren aprobación explícita y necesidad demostrada)
- **`cola_envios`**: solo si se decide envío automático programado. NO construir hoy.
- **`campana_plantillas`** (tabla intermedia): solo si se necesitan secuencias/múltiples plantillas por campaña. Hoy basta `pipelines.plantilla_id`.
- **`variantes`** (entidad): solo si se necesita declarar variante ganadora o reutilizar variantes entre campañas. Hoy basta columnas + `envios.variant`.
- **Renombrar `pipelines`→`campañas`**: decisión de nomenclatura; si se hace, solo en UI/documentación primero, migración de tabla después.

---

## 14. CRITERIOS GLOBALES PASS/FAIL (aplicables a cualquier fase)

### PASS
- Ninguna invariante I1–I10 se rompe.
- `envios` sigue siendo la única fuente de verdad del resultado; los campos inmutables no cambian.
- El aislamiento TEST/REAL (I2) se mantiene en local Y producción.
- La idempotencia (I1) se mantiene: un lead no se reenvía en la misma campaña.
- El opt-out (I3) se mantiene: una baja real no se reactiva.
- Las variantes (I5) siguen siendo determinísticas e inmutables.
- La reanudación (I7) sigue funcionando: relanzar no pierde progreso.
- La coherencia de entorno (I8) se mantiene.
- No se exponen credenciales SMTP (I9).
- No se borran/sobrescriben archivos de output/checkpoints (I10).
- La BD de producción se respalda antes de cualquier migración.
- El checkpoint de la fase documenta evidencia verificable (consultas SQL, conteos, diffs).

### FAIL
- Cualquier invariante I1–I10 se rompe.
- Se pierde o corrompe histórico de `envios`.
- Se mezcla un TEST con el histórico comercial (I2).
- Un lead se reenvía en la misma campaña (I1).
- Una baja real se reactiva (I3).
- Una variante cambia entre retry (I5).
- Se pierde progreso al relanzar (I7).
- Se expone una credencial SMTP (I9).
- Se borra/sobrescribe un archivo de output/checkpoints sin permiso (I10).
- La fase se implementa sin backup previo de la BD de producción.

---

## 15. REGISTRO DE FASES

| Fase | Estado | Fecha | Evidencia |
|---|---|---|---|
| FASE 0 (MAESTRO) | Aprobada con correcciones | 2026-08-18 | `docs/MAESTRO_ARQUITECTURA_FASE0.md` |
| FASE 0.1 (REVISIÓN CRÍTICA) | Completada (read-only) | 2026-08-18 | `docs/REVISION_CRITICA_FASE0.md` (este documento) |
| FASE A (reconciliar es_test en producción) | Pendiente | — | — |
| FASE B (joins por lead_id) | Pendiente | — | — |
| FASE C (unificar estado_lead) | Pendiente | — | — |
| FASE D (campaña↔plantilla) | Pendiente | — | — |
| FASE E (normalizar tipo_evento) | Pendiente | — | — |
| FASE F (fecha_proxima_accion) | Pendiente | — | — |
| FASE G (diferidas) | Pendiente de aprobación | — | — |

---

## 16. CONCLUSIÓN

El MAESTRO FASE 0 es un contrato arquitectónico sólido y verificable. La revisión crítica confirma que **la mayoría de las invariantes están demostradas y robustas** (I1, I4, I5, I7, I8, I9, I10), y que el modelo conceptual propuesto es correcto.

**Antes de implementar cualquier migración estructural, deben resolverse dos correcciones pendientes**:
1. **I2**: reconciliar `envios.es_test` en producción (FASE A) — bloqueante.
2. **I6**: unificar el formato de `estado_lead` (FASE C) — no bloqueante pero necesaria.

**Y deben diferirse** las normalizaciones estructurales que hoy no tienen necesidad demostrada (`cola_envios`, `campana_plantillas`, `variantes`), priorizando el refactor de código de bajo riesgo (FASE B: joins por `lead_id`) que elimina el riesgo §5.5 sin tocar datos.

**Regla de oro**: no implementar una solución de UI para compensar un problema de modelo de datos. Si una fase revela una contradicción entre la arquitectura actual y la propuesta, detenerse, documentarla y resolver primero la decisión arquitectónica.


