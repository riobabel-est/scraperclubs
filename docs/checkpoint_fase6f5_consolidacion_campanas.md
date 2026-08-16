# CHECKPOINT — FASE 6F.5: CONSOLIDACIÓN DEL MODELO DE CAMPAÑAS Y PLAN DE GESTIÓN MULTICAMPAÑA

**Tipo:** Auditoría cruzada — SOLO LECTURA (sin cambios de código, BD, configuración ni envíos)
**Fecha:** 16/08/2026
**Alcance:** `docs/` completo + `public_html/outbound/` (código) + BD `stats.db` (solo lectura)
**Conclusión:** El modelo de campañas está a medio camino entre la especificación V4.3 (N:M `lead_pipelines`) y la implementación real (variante determinística `envios.variant` + `envios.campaign_id`). Existen inconsistencias de modelo que deben resolverse antes de ampliar a multicampaña/productos.

---

## A. HALLAZGOS HISTÓRICOS

### A.1. Especificación maestra

| Archivo | Sección | Decisión/Requisito | Vigencia |
|---|---|---|---|
| `docs/especificacion_tecnica_definitiva_crm_v4.md` | §7 | **Cambio arquitectónico N:M**: `lead_pipelines` (lead_id, pipeline_id, variante_ab, fecha_asignacion, UNIQUE) permite un club en múltiples campañas. Se elimina `pipeline_id` de `clubes_crm`. | **Vigente como intención**, pero **no operativa** en runtime (ver A.3). |
| — | §8 | A/B/C real: asunto + cuerpo por variante, asignación round-robin, se guarda en `lead_pipelines.variante_ab` y `comunicaciones_log.variante_ab`. | **Parcialmente superado**: la variante real se guarda en `envios.variant` y se asigna por hash determinístico, no round-robin. |
| — | §14 | Atribución CAMPAÑA → VARIANTE → LEAD → INTERACCIONES → OPORTUNIDAD → PRESUPUESTO → PEDIDO vía `lead_pipelines`. | **Intención vigente**; la traza real hoy es `envios.campaign_id` + `envios.variant` + `comunicaciones_log.variante_ab`. |
| — | §12, §19 | WhatsApp = gestión manual (links `wa.me`), sin automatización. `baja.php` se conserva. | **Vigente** en código; no hay automatización. |
| — | §17.1 | Tablas existentes: `envios`, `aperturas`, `rebotes`, `clubes_crm`, `cuentas_smtp`, `config`, `plantillas`, `comunicaciones_log`. | Vigente. |
| — | §20 FASE 6 | F6.1 "Pipeline Experimento Inicial V4"; F6.6 protocolo lanzamiento 3 niveles; F6.7 **ESPERAR AUTORIZACIÓN EXPLÍCITA**. | Vigente como control de envíos. |

### A.2. MEGAPROMPT (plan maestro operativo)

| Archivo | Sección | Decisión/Requisito | Vigencia |
|---|---|---|---|
| `Downloads/MEGAPROMPT_FutProtec_CRM_Outbound_Master_Plan.md` | §3 | Baseline: "la variante no está correctamente integrada con `envios`"; "`lead_pipelines` tiene datos TEST y no constituye por sí sola una fuente fiable del A/B/C real"; "baja marca Lista Negra, pero debe verificarse que el filtro de cola impida envíos". | Vigente (estado heredado). |
| — | FASE 3 | Separar campañas del Kanban; cada envío vinculado a una campaña; "No utilizar únicamente Kanban/pipeline como sustituto de campaña". | **Vigente**. |
| — | FASE 6 | "Una baja debe ser irreversible a efectos de outbound"; comprobar supresión en backend antes de encolar. | **Vigente** (implementado en `esElegibleParaEnvio`). |
| — | FASE 11 | Dashboard debe leer datos REALES de envío, "nunca usar datos TEST de `lead_pipelines` como fuente del experimento"; filtrar por campaña/variante/periodo/SMTP. | **Vigente** y **clave** (relega `lead_pipelines` a datos de prueba). |

### A.3. Registro de mejoras futuras (contradicción clave)

| Archivo | Sección | Decisión/Requisito | Vigencia |
|---|---|---|---|
| `docs/FUTURE_IMPROVEMENTS.md` | FI-006 | **"`estado_lead` es un único estado global por lead; no conserva histórico por campaña (la relación N:M `lead_pipelines` no se usa)."** Prioridad ALTA post-piloto. | **Vigente — CONTRADICCIÓN con V4.3 §7**: V4.3 declara `lead_pipelines` como solución multicampaña, pero la propia BD de mejoras admite que **no está en uso** y que el estado no es por campaña. |
| — | FI-007 | Plantillas versionadas inmutables: `save_template` sobrescribe en lugar de versionar. Prioridad ALTA (plan). | **Vigente — no implementado**. La inmutabilidad de plantillas es prerequisito para un modelo campaña→plantilla consistente. |
| — | FI-009 | **Evolution API — PENDIENTE DE EVALUACIÓN** (no integrado). Instalado independiente en `localhost:8080`. WhatsApp del CRM es manual vía `wa.me`. | **Vigente**. |

### A.4. Checkpoints de fases (resumen de evolución del modelo)

| Fase / Archivo | Hallazgo |
|---|---|
| F0–F2 (`checkpoint_fase0.md`, `fase2c_campaign_p3.md`) | La tabla `pipelines` se crea como entidad; `lead_pipelines` se crea con 5 filas TEST (leads 1809–1813, pipeline 1, variantes A/B/C/A/B) pero **no se usa en runtime**. |
| F3 (`fase3a_ab_assignment.md`) | Se sustituye la asignación por `lead_pipelines` por **`asignarVariante()` determinística** (`crc32(campaign_id:lead_id) % 3`) y snapshot en `envios`. `lead_pipelines` queda como **dato TEST/legacy**. |
| F4–F5 (`fase4a` … `fase5e`) | Se consolida `envios.campaign_id` + `envios.variant` como fuente de verdad del A/B/C. `lead_pipelines` queda **prohibida** como fuente de métricas. Supresión = `estado_lead='Lista Negra'` (global). **Cero menciones a "producto"/"espinilleras"/"calcetines" en estos checkpoints.** |
| F6 (`fase6c`, `fase6d`) | Se crean campañas piloto/smoke sin envíos. Se confirma que **no existe API/UI para crear campañas**; se insertó directo en `pipelines`. |

### A.5. Términos buscados sin presencia

- **"calcetines"**: no aparece en ningún documento ni en código. El único producto del proyecto es **espinilleras**.
- **"segmentación"/"segmento"**: no aparece como requisito formal; lo más cercano son los filtros de la lanzadera (federación, estado_lead) y la separación TEST/PILOT.
- **"roadmap"**: no hay roadmap formal; solo la cadena de fases y el backlog post-lanzamiento (FASE 18 del MEGAPROMPT + `BACKLOG_POST_LANZAMIENTO`).
- **"FASE 6F.4"**: **no existe** en `docs/` ni `scripts/`. No hay documento previo con esa numeración. El análisis se consolida sin asumir un texto de 6F.4 inexistente.

---

## B. DECISIONES PREVIAS VIGENTES

1. **Campaña ≠ Kanban**: cada envío queda vinculado a una `pipelines.id` (campo `envios.campaign_id`). El Kanban (`estado_lead`) es el estado comercial del lead, independiente de la campaña. *(MEGAPROMPT FASE 3; V4.3 §7)*
2. **Fuente de verdad del A/B/C = `envios`**: `envios.variant` + `envios.plantilla_id` + snapshot `envios.asunto`/`envios.cuerpo_mensaje`. `lead_pipelines.variante_ab` es dato TEST y **no** debe alimentar el dashboard. *(MEGAPROMPT FASE 11; checkpoints F3–F5)*
3. **Asignación determinística e inmutable**: `asignarVariante(lead_id, campaign_id)` = `crc32("campaign_id:lead_id") % 3`. Un retry no cambia variante. *(inc/abc.php)*
4. **Campaña operable solo si**: `estado ∈ {PILOT, ACTIVE}` + `activo=1` + entorno coherente con `config.modo_entorno`. *(inc/abc.php `validarCampanaActiva`)*
5. **Supresión global por estado**: `clubes_crm.estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')` bloquea envío en backend. `baja.php` marca `Lista Negra` por email (global, idempotente). *(inc/eligibilidad.php; api/baja.php)*
6. **Idempotencia por (lead, campaña)**: un lead tiene como máximo **un envío lógico por campaña** (`INSERT OR IGNORE` sobre `envios` con campaña). Estados finales `enviado`/`abierto` no se reenvían. *(inc/eligibilidad.php `reservarEnvioLogico`)*
7. **Separación TEST/PILOT/PRODUCTION**: un lead TEST no entra en campaña no-test; una campaña `test` no corre en `produccion`; una campaña `pilot/production` no corre en `test`. *(inc/abc.php; inc/eligibilidad.php)*
8. **Plantillas son mutables** (FI-007 pendiente): el snapshot de `envios` es la fuente histórica del mensaje; la plantilla editable es la fuente "actual". No hay versionado.

---

## C. CONTRADICCIONES DETECTADAS

| # | Contradicción | Evidencia | Impacto sobre la consolidación |
|---|---|---|---|
| C1 | V4.3 §7 declara `lead_pipelines` como solución multicampaña (N:M), pero la implementación real **no la usa** (variante y trazabilidad viven en `envios`). | `FUTURE_IMPROVEMENTS.md` FI-006; checkpoints F3–F5 | El modelo multicampaña **no puede apoyarse en `lead_pipelines`** tal como está. O se migra a ella con estado por campaña, o se descarta. |
| C2 | V4.3 §8 dice "asignación round-robin"; el código usa **hash determinístico**. | `inc/abc.php:24-34` | No es una contradicción de negocio, pero la fuente de verdad es el código (determinístico), no la doc. |
| C3 | V4.3 §8 dice "se guarda en `lead_pipelines.variante_ab`"; el código guarda en `envios.variant` y `comunicaciones_log.variante_ab`. | `inc/eligibilidad.php:135-137`, `api/enviar_lote.php:76` | La traza cierta es `envios.variant`; `lead_pipelines.variante_ab` es TEST. |
| C4 | Checkpoint Fase 6C dice `modo_entorno=test`, pero la BD actual tiene `modo_entorno=produccion`. | BD `config` (solo lectura): `modo_entorno='produccion'`, `motor_estado='pausado'` | **Riesgo operativo**: con `produccion` activo, cualquier campaña `pilot` en estado `PILOT` quedaría **enviable** en cuanto se active el motor. Hoy ninguna lo es (todas DRAFT o entorno test). Controlar antes de tocar nada. |
| C5 | V4.3 §2 define "UN DISEÑO EXCLUSIVO POR CLUB Y LOTE" para espinilleras, pero el modelo de campañas **no contempla producto** en la BD (`pipelines` no tiene `producto`). | `PRAGMA table_info(pipelines)` — sin columna producto | Los casos A–F del prompt (ESPINILLERAS vs CALCETINES) **no son representables hoy**. |
| C6 | Exigen dos conceptos de "estado": V4.3 Kanban (estado comercial global) vs. proceso por campaña. La BD solo tiene `clubes_crm.estado_lead` (global). | FI-006; `PRAGMA table_info(clubes_crm)` | Falta **estado por campaña** para resolver el caso E. |

---

## D. MODELO CONCEPTUAL RECOMENDADO

Basado en código + documentación, **una campaña debe representar**:

> Un envío/trabajo comercial **acotado en el tiempo** y **asociado a un producto, objetivo y segmento**, que lanza una o varias **plantillas con variantes A/B/C** a un conjunto de **leads** a través de **cuentas SMTP**, registrando para cada lead un **estado propio dentro de la campaña** y respetando **supresiones** (global / por producto / por campaña).

| Dimensión | Estado actual | Recomendación |
|---|---|---|
| **Producto** | No existe. | Añadir `pipelines.producto` (texto normalizado, p. ej. `espinilleras`), clave para baja/segmentación por producto. |
| **Objetivo** | Columna `pipelines.objetivo` existe (INTEGER, sin usar). | Definir catálogo de objetivos (prospección, reenganche, cross-sell, reactivación) o dejar como texto libre etiquetado. |
| **Segmento / audiencia** | Implícito (federación, `estado_lead`, separación TEST/PILOT). | Hacerlo explícito y **persistente** en campaña (filtros de lanzamiento guardados), para que sea auditable y reutilizable. |
| **Plantillas** | Elegida a mano por lote en lanzadera; `cron.php` toma la primera HTML activa (ignora campaña). | Vincular campaña→plantilla(s) explícitamente. |
| **Variantes A/B/C** | Por plantilla (`test_ab`, `asunto_b/c`, `cuerpo_b/c`), asignación determinística por (lead, campaña). | Mantener determinismo; añadir trazabilidad campaña→plantilla→variante en el esquema. |
| **SMTP** | `envios.smtp_id` + cuenta rotativa. | Mantener. |
| **Fechas** | `fecha_inicio`, `fecha_fin` existen (sin uso). | Usar como ventana de validez de la campaña. |
| **Estado** | `DRAFT`/`PILOT`/`ACTIVE` (solo PILOT/ACTIVE envían). | Conservar máquina de estados + añadir `archivada` (no borrado). |
| **Entorno** | `test`/`pilot`/`production`, cruzado con `config.modo_entorno`. | Mantener la política de coherencia. |
| **Leads asociados** | `envios.lead_id` ↔ `envios.campaign_id` (por envío). | Normalizar a `lead_pipelines` o mantener `envios` como fuente operativa y añadir `lead_pipelines.estado`. |
| **Estado del lead en la campaña** | **No existe** (solo `estado_lead` global). | Añadir estado del lead **dentro** de la campaña (ver Sección E). |
| **Exclusiones** | Solo global (supresión) + TEST/PILOT + duplicados + email inválido. | Añadir exclusión por campaña y por producto (ver Sección F). |
| **Bajas** | Global (`Lista Negra`). | Modelo de 3 niveles (ver Sección F). |
| **Métricas** | Calculables por `envios.campaign_id`. | Mantener agregación por campaña; separar histórico por campaña. |
| **Pipeline comercial** | Kanban global `estado_lead`. | Mantener Kanban global como vista comercial; añadir estado por campaña sin romper el Kanban. |

---

## E. MODELO MULTICAMPAÑA RECOMENDADO

### E.1. Respuestas a los casos A–F

| Caso | Respuesta recomendada | Razón |
|---|---|---|
| **A)** Recibe ESPINILLERAS → `CONTACTADO`. ¿Puede entrar en CALCETINES? | **SÍ**, salvo supresión global o por producto "calcetines". | El estado `Contactado` es de ESPINILLERAS, no una prohibición transversal. Cada producto/campaña es independiente. |
| **B)** Baja de ESPINILLERAS. ¿Puede recibir CALCETINES? | **SÍ** (si la baja es solo de espinilleras). | La baja de un producto no debe arrastrar los demás. Hoy `baja.php` es global → **habría que distinguir**. |
| **C)** Baja GLOBAL FutProtec. ¿Puede recibir cualquier producto? | **NO**. | La baja global bloquea todo. Es exactamente lo que hoy hace `Lista Negra`. |
| **D)** Gana ESPINILLERAS. ¿Puede entrar en CALCETINES? | **SÍ** (cross-sell). | Un `Ganado` es cierre exitoso de UNA campaña, no supresión; al contrario, es señal para futuras campañas. |
| **E)** En dos campañas simultáneas. ¿Cómo gestionar estado en cada una? | **Estado del lead por campaña**, independiente del `estado_lead` global. | Hoy `estado_lead` es único y global → conflicto. Se debe añadir estado por campaña. |
| **F)** Métricas e histórico | Agregar SIEMPRE por `campaign_id`. El histórico de estado debe quedar por (lead, campaña), no solo global. | `envios.campaign_id` ya lo permite; falta el histórico de estado por campaña. |

### E.2. Diferenciación conceptual explícita

| Concepto | Almacenamiento recomendado | Notas |
|---|---|---|
| **Estado global del lead** | `clubes_crm.estado_lead` (Kanban comercial) | No tocar. Sigue siendo la vista operativa. |
| **Estado del lead dentro de una campaña** | `lead_pipelines.estado` (o columna equivalente en `envios`/tabla de participación) | NUEVO. Permite que un lead esté `CONTACTADO` en campaña A y `SIN_CONTACTAR` en campaña B. |
| **Supresión global** | `clubes_crm.estado_lead='Lista Negra'` (o tabla `optout_global`) | Ya existe (Lista Negra). Bloquea todo. |
| **Supresión por producto** | Tabla/pendiente `optout_producto (lead_id, producto)` | NUEVO. Resuelve caso B. |
| **Supresión por campaña** | Tabla/pendiente `exclusiones_campana (lead_id, pipeline_id, motivo)` o `lead_pipelines.estado='excluido'` | NUEVO. Permite excluir un lead de una campaña concreta sin afectar el resto. |

### E.3. Regla de elegibilidad ampliada (conceptual, NO implementar)

```
¿Suprimido global?          → NO ENVIAR (ningún producto/campaña)
¿Duplicado/email inválido?  → NO ENVIAR
¿Lead TEST en campaña real? → NO ENVIAR
¿Baja de este PRODUCTO?     → NO ENVIAR esta campaña
¿Excluido de esta CAMPAÑA?  → NO ENVIAR esta campaña
¿Ya participa y el estado de campaña es terminal? → NO reenviar en esta campaña
Sino                        → ENVIAR (asignar variante determinística)
```

---

## F. MODELO DE BAJAS RECOMENDADO

Tres niveles, de mayor a menor alcance:

1. **Supresión global** (baja FutProtec completa): el lead no recibe **ningún** producto. → Ya soportado por `clubes_crm.estado_lead='Lista Negra'`. Es irreversible salvo intervención explícita autorizada (coherente con MEGAPROMPT FASE 6).
2. **Supresión por producto** (baja de un producto, p. ej. "no más espinilleras"): el lead no recibe campañas de ese producto, pero sí de otros. → **No existe hoy**; requiere `pipelines.producto` + tabla de opt-out por producto, o `clubes_crm.observaciones` estructurado (no recomendado).
3. **Supresión por campaña** (excluir de una campaña concreta): el lead no recibe esa campaña en concreto. → **No existe hoy**; requiere exclusión por (lead, pipeline_id).

El enlace público actual (`api/baja.php`) **solo puede traducirse como baja global** (no sabe de productos ni campañas). Al introducir productos, el enlace de baja deberá llevar contexto (`?producto=...` o `?campana=...`) para distinguir nivel, manteniendo la baja global como opción por defecto/señal más fuerte.

---

## G. MODELO CAMPAÑA → PLANTILLA → VARIANTE

### G.1. Comportamiento actual

```
lanzadera.php (UI)  → elige manualmente: campaña + federación + estado + plantilla → api/enviar_lote.php
   enviar_lote.php   → valida campaña → asignarVariante(lead, campaña) → resolverContenidoVariante(plantilla, variante)
   cron.php           → campaña obligatoria → plantilla = "primera HTML activa (ORDER BY id ASC)" → misma variante determinística
```

**Contradicciones detectadas en el flujo actual:**

1. **No hay relación campaña→plantilla** en BD. La lanzadera deja la plantilla **desacoplada** de la campaña (se puede enviar cualquier plantilla en cualquier campaña). El `cron.php` elige una plantilla **ignorando** la campaña. Esto rompe la trazabilidad del experimento: la misma campaña podría enviar plantillas distintas según el motor.
2. **Plantilla mutable** (FI-007) frente a snapshot inmutable de `envios`: si una plantilla cambia a mitad de campaña, los envíos ya realizados conservan su texto (bien), pero los siguientes llevarán otro (mal para el experimento).
3. `envios.plantilla_id` sí se registra, por lo que la traza histórica de qué plantilla usó cada envío **sí existe**.

### G.2. Modelo recomendado

```
CAMPAÑA (pipelines)
   │ 1---N
   ▼
PLANTILLA(S) DE CAMPAÑA  (relación explícita, p. ej. tabla pipeline_plantillas o columna plantilla_id en pipelines)
   │  └─ variantes A/B/C (asunto, asunto_b, asunto_c, cuerpo, cuerpo_b, cuerpo_c + test_ab)
   ▼
ENVÍO (envios): snapshot asunto + cuerpo + variant + plantilla_id + campaign_id
```

Reglas:
- Una campaña referencia **una (o varias, tipadas por etapa) plantilla(s)** de forma explícita y **congelada** en el momento de pasar a `PILOT` (coherente con `plantillaEstaCongelada()` existente).
- La variante se sigue asignando de forma **determinística** por (lead, campaña).
- El contenido por variante se resuelve **server-side** (`resolverContenidoVariante`), nunca en JS.
- El snapshot en `envios` sigue siendo la fuente inmutable del mensaje enviado.

---

## H. GESTOR DE CAMPAÑAS RECOMENDADO

Panel mínimo conceptual (NO implementar ahora). Sustituye la vía manual actual de "insertar directo en `pipelines`":

| Acción | Detalle |
|---|---|
| Crear campaña | nombre, identificador único, producto, objetivo, entorno, fechas, estado inicial `DRAFT`. |
| Editar campaña | solo en `DRAFT` (congelar al pasar a `PILOT`/`ACTIVE`). |
| Duplicar campaña | copiar configuración, nuevo identificador, estado `DRAFT`, sin envíos ni leads. |
| Archivar campaña | estado `archivada` (no borrado). |
| Activar/Desactivar | transición `DRAFT → PILOT → ACTIVE` (con validación de coherencia de entorno + motor pausado). |
| Definir producto | campo `producto` normalizado. |
| Definir objetivo | campo `objetivo` (catálogo) + descripción. |
| Definir segmento | filtros persistidos (federación, estado, exclusión TEST) que se aplican al encolar. |
| Configurar plantillas | asignar plantilla(s) a la campaña y congelarlas. |
| Configurar A/B/C | visualizar qué plantilla y qué variante recibirá cada lead (vista previa determinística). |
| Ver leads asociados | listado de `envios.lead_id` (o `lead_pipelines`) de esa campaña. |
| Ver estado de la campaña | DRAFT/PILOT/ACTIVE/archivada + progreso (N enviados/pendientes). |
| Validar antes del lanzamiento | checklist: producto definido, plantilla asignada y congelada, entorno coherente, motor pausado, segmento cargado, 0 bloqueantes (heredado de la puerta FASE 16/17 del MEGAPROMPT). |
| Ver métricas | embudo por campaña (enviados/abiertos/respondidos/positivas/propuestas/ganados) leyendo `envios` reales, nunca `lead_pipelines` TEST. |

---

## I. TRATAMIENTO DE CAMPAÑAS 1 / 2 / 3

Datos actuales de la BD (solo lectura):

| ID | nombre | identificador | estado | entorno | activo | created_at | Propósito real |
|---|---|---|---|---|---|---|---|
| **1** | `Experimento Fase 1 TEST` | `LEGACY_TEST_FASE1` | DRAFT | test | 1 | 2026-08-11 | Pipeline de prueba Fase 1 — **NO REAL**. |
| **2** | `Piloto Comercial FutProtec 2026-08` | `PILOTO_FUTPROTEC_2026_08` | DRAFT | pilot | 1 | 2026-08-14 | Campaña piloto comercial (espinilleras). Sin plantilla asignada. Sin envíos. |
| **3** | `SMOKE TEST FutProtec 2026-08` | `SMOKE_TEST_FUTPROTEC_2026_08` | PILOT | test | 1 | 2026-08-14 | Smoke test. Estado PILOT + entorno test. |

**Recomendación (sin cambios ahora):**

- **ID 1 (`LEGACY_TEST_FASE1`)**: **Archivar** (es residuo de Fase 1, TEST). Conservar porque `lead_pipelines` (5 filas) y datos TEST de A/B/C apuntan a ella; no borrar para no romper referencias históricas. Renombrar opcional a `[ARCHIVED]`.
- **ID 2 (`PILOTO_FUTPROTEC_2026_08`)**: **Mantener** como campaña piloto real de espinilleras. Está en `DRAFT` (no enviable), que es el estado correcto hasta autorización explícita y hasta asignar plantilla + segmento. **No activar sin autorización.**
- **ID 3 (`SMOKE_TEST_FUTPROTEC_2026_08`)**: **Mantener con precaución** mientras sea útil para smoke tests. Nota de riesgo: está en estado `PILOT` (enviable) con entorno `test`; con `modo_entorno=produccion` actual, `esEntornoCoherente` la **bloquea** (campaña test en producción). Si se quiere reutilizar, alinear entorno/estado. Si ya no es útil, **archivar**.

**Migración/eliminación:** no eliminar ninguna (integridad). `variante_ganadora` está nulo en las tres → no declarada aún.

---

## J. EVOLUTION API COMO DESARROLLO FUTURO

- **Sí aparece** como desarrollo futuro en `docs/FUTURE_IMPROVEMENTS.md` **FI-009**.
- **Estado:** **PENDIENTE DE EVALUACIÓN** (no integrado).
- **Qué función se preveía:** "Evaluar una futura integración con Evolution API para determinar si puede aportar valor a la automatización de WhatsApp del CRM FutProtec. **No se asume que se vaya a integrar.**"
- **Relación con campañas:** ninguna implementada. Los posibles usos futuros listados son: automatización de WhatsApp y envío programado, recepción de respuestas, trazabilidad de conversaciones, integración con pipeline/CRM. En el modelo de campañas recomendado, WhatsApp seguiría como **canal** (junto a email), registrado en `comunicaciones_log.canal`, sin alterar el núcleo email A/B/C.
- **Conclusión:** registrado como **PROPUESTA FUTURA**, no como integración existente. No hay referencias a Evolution API en el código del CRM. No resolver en esta fase.

---

## K. RIESGOS

1. **R1 — `modo_entorno` en `produccion` con motor pausado**: combinación segura hoy (motor pausado + ninguna campaña operativa), pero un solo cambio (activar motor o poner una campaña en `PILOT` con entorno `pilot`) abre envíos reales. Controlar antes de cualquier tarea de esta fase.
2. **R2 — Campaña sin producto**: los casos multicampaña/producto del prompt no son representables; cualquier implementación de baja/segmentación por producto requiere primero el campo `pipelines.producto`.
3. **R3 — Plantillas mutables**: sin versionado, una campaña puede cambiar de copy a mitad. Mitigar con congelación (`plantillaEstaCongelada`) y snapshot en `envios`.
4. **R4 — `lead_pipelines` como "zombie arquitectónico"**: existe, tiene datos TEST, la doc de diseño la declara solución multicampaña, pero el código no la usa. Riesgo de falso supuesto (que la multicampaña "ya está hecha").
5. **R5 — Baja global sin granularidad**: el enlace `baja.php` solo hace baja global; no distingue producto/campaña. Riesgo de sobre-supresión al añadir productos.
6. **R6 — Tres motores SMTP divergentes** (`enviar_lote.php`, `enviar_smtp_random.php`, `cron.php`, FI-004): comportamiento no idéntico entre lanzadera y cron; cron elige plantilla por `ORDER BY id` y usa `mail()` como fallback parcial. Riesgo de que una campaña se comporte distinto según motor.
7. **R7 — Ausencia de API/UI de campañas**: hoy se inserta directo en `pipelines`. Riesgo de campañas mal formadas sin validación.

---

## L. CAMBIOS QUE NO DEBEN HACERSE

1. No borrar/renombrar/truncar `pipelines` ni `lead_pipelines` (integridad histórica).
2. No eliminar `envios` ni su snapshot `asunto`/`cuerpo_mensaje`.
3. No cambiar la política de supresión global (`estado_lead='Lista Negra'`) ni debilitar `esElegibleParaEnvio`.
4. No cambiar `asignarVariante()` determinística por round-robin/random por envío.
5. No alimentar el dashboard A/B/C con `lead_pipelines`.
6. No tocar `modo_entorno` ni `motor_estado`.
7. No activar campañas 1/2/3.
8. No ejecutar envíos ni smoke tests.
9. No integrar Evolution API.
10. No modificar el array `$CUENTAS_SMTP` / credenciales SMTP (protección del módulo outbound).

---

## M. CAMBIOS QUE SÍ SERÍAN NECESARIOS (para la siguiente fase, NO en esta)

1. Añadir `pipelines.producto` (y opcional `pipelines.plantilla_id`/tabla `pipeline_plantillas`).
2. Añadir **estado del lead por campaña** (preferible revivir `lead_pipelines` con columna `estado`, o añadir `estado_campana` a la relación lead↔campaña).
3. Añadir tablas de **opt-out por producto** y **exclusiones por campaña**.
4. Vincular campaña→plantilla(s) y **congelar plantillas** al pasar a `PILOT` (completar FI-007).
5. Extender `esElegibleParaEnvio()` para incluir la nueva granularidad de supresión (respetando el orden: global → producto → campaña).
6. Construir el **gestor de campañas** (Sección H) con API/UI que sustituya la inserción directa.
7. Unificar los tres motores de envío (FI-004) para que compartan idéntica selección de plantilla, variante y SMTP.
8. Añadir `archivada` como estado de campaña (transición sin borrado).

---

## N. ORDEN RECOMENDADO DE IMPLEMENTACIÓN

1. **Modelo de datos base** (M1–M3): `pipelines.producto`, estado por campaña en `lead_pipelines` (o relación equivalente), tablas de opt-out por producto y exclusiones por campaña.
2. **Campaña→plantilla→variante** (M4, M7): relación explícita + congelación al activar; unificar selección de plantilla en `cron.php` y lanzadera.
3. **Elegibilidad ampliada** (M5): integrar supresión global → por producto → por campaña en `esElegibleParaEnvio`, con prueba de regresión de que Lista Negra sigue bloqueando.
4. **Gestor de campañas** (M6): CRUD + estados + validación pre-lanzamiento, reutilizando `validarCampanaActiva` y `esEntornoCoherente`.
5. **Métricas por campaña** (Sección F/E): garantizar que el dashboard lee `envios.campaign_id` real (ya lo hace) y añadir histórico de estado por campaña.
6. **Tratamiento campañas 1/2/3** (Sección I): archivar ID 1, mantener ID 2 en DRAFT, revisar/archivar ID 3 — solo tras los cambios de modelo y sin afectar históricos.
7. **Evolution API**: fuera de alcance; solo re-evaluación de producto si el usuario lo pide.

---

### Nota final
Esta fase es **exclusivamente de especificación**. No se ha modificado código, BD, configuración, envíos ni entorno. Se recomienda validar este documento antes de pasar a implementación (FASE 6F.6).