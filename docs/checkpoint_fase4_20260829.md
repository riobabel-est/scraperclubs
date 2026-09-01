# CHECKPOINT — FASE 4 · OPERATIVA COMERCIAL (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PASS (pendiente autorización para FASE 5)
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase4_20260829_020456` (verificado, integrity ok)

---

## 1. CAMBIOS APLICADOS

### 1.1 Base de datos (aditivo, registrado en `_migraciones` → id 5)
| Tabla | Cambio |
|---|---|
| `respuestas` | ALTER + `intencion` TEXT, + `proxima_accion` TEXT + índice `idx_respuestas_intencion` |
| `presupuestos` | ALTER + `campaign_id`, `opportunity_id`, `respuesta_origen_id`, `envio_origen_id`, `fecha_envio`, `fecha_aprobacion`, `fecha_rechazo`, `motivo_rechazo` + índice `idx_presupuestos_opportunity` (se conserva `pipeline_id` por compatibilidad) |
| `mockups` | ALTER + `campaign_id`, `opportunity_id`, `presupuesto_id`, `fecha_creacion`, `version` DEFAULT 1 + índice `idx_mockups_opportunity` |

### 1.2 Código
| Archivo | Cambio |
|---|---|
| `inc/respuestas.php` | `CLASIFICACIONES_VALIDAS` ampliado (set rápido de 9 estados + vocabulario legacy). `estadoDestinoPorClasificacion` mapea SOLICITA_*/INTERESADO → '03 En Conversación', NO_INTERESADO → '06 Perdido'. **NUEVAS:** `clasificarRespuestaCompleta()` (clasificación + intención + próxima acción + auto-oportunidad) y `crearOportunidadDesdeRespuesta()` (idempotente, evento `oportunidad_creada` en `comunicaciones_log`) |
| `api/analytics.php` | `clasificar_respuesta` usa `clasificarRespuestaCompleta` (acepta `intencion`/`proxima_accion`). **NUEVA** acción `crear_oportunidad` |
| `api/presupuestos.php` | `presupuesto_crear` vincula opcionalmente `campaign_id`/`opportunity_id` |
| `api/mockups.php` | `mockup_solicitar` vincula opcionalmente `campaign_id`/`opportunity_id`; inserta `fecha_creacion`/`version` |
| `tabs/respuestas.php` | Botones de clasificación rápida ampliados al set comercial (13 chips: 9 estados + legacy) |
| `js/app.js` | `rsClasBotonLabel` y `rsClasColor` ampliados con los estados nuevos |
| `scripts/test_fase4_operativa.php` | NUEVO test (TEST 01/04/06/07/08) |

## 2. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `oportunidades` | 0 | **0** (histórico intacto, sin creación retroactiva) |
| `presupuestos` / `mockups` | 0 / 0 | 0 / 0 (estructura ampliada, sin datos) |
| `respuestas` | 30 | 30 · `intencion`/`proxima_accion` NULL (histórico no tocado) |
| `_migraciones` | 4 | 5 |
| `integrity_check` | ok | ok |
| TEST/REAL | intactos | sin cambios |

## 3. TESTS (RESULTADO)

- **TEST FASE 4 (`scripts/test_fase4_operativa.php`): 14 PASS / 0 FAIL**
  - TEST 01 DB integrity · TEST 04 clasificación rápida (9 estados válidos, mapeos) · TEST 06 oportunidad (respuesta POSITIVE → oportunidad, idempotente, evento, es_test=0) · TEST 07 presupuesto vinculado a lead+campaña+oportunidad · TEST 08 mockups con trazabilidad · verificación de que NO se crearon oportunidades retroactivas.
- **Regresiones:** FASE 1 (14/14) · FASE 2 (9/9) · FASE 3 (10/10) · eligibilidad (PASS).
- Sintaxis: `php -l` 5/5 OK · `node --check` app.js OK.

## 4. REQUISITOS DE FASE 4 — ESTADO

| Requisito | Estado |
|---|---|
| Una respuesta POSITIVE genera oportunidad en el flujo | ✅ `clasificarRespuestaCompleta` → `crearOportunidadDesdeRespuesta` (TEST 06) |
| Presupuesto vinculado a lead+campaña+oportunidad | ✅ `presupuesto_crear` + columnas nuevas (TEST 07) |
| `comunicaciones_log` registra cada evento | ✅ `oportunidad_creada`, `presupuesto_creado`, `mockup_solicitado` (event store) |
| Clasificación en 1-2 clics (set rápido) | ✅ 9 estados comerciales en la Bandeja (UI) + backend |
| `oportunidades.estado` fuente comercial; `estado_lead` conservado | ✅ modelo implementado |

## 5. RIESGOS RESIDUALES

- La auto-oportunidad se dispara al clasificar POSITIVE/INTERESADO/SOLICITA_*; no afecta a clasificaciones ya hechas (histórico intacto). Si el equipo reclasifica una respuesta histórica, crearía la oportunidad en el presente (comportamiento deseado).
- Los estados de `presupuestos` (`creado`, legacy) y `mockups` (`solicitado`/`enviado`) se conservan; el vocabulario completo del megaprompt (BORRADOR/ENVIADO/...) se puede ir adoptando sin migración.
- La UI de cualificación completa (cantidad/muestra/escudo/colores/fecha decisión) queda para el siguiente ciclo de operativa; la base (clasificación + oportunidad + presupuesto/mockup) ya está.

## 6. ROLLBACK

- **DB:** restaurar `data/stats.db.bak_fase4_20260829_020456`.
- **Código:** revertir `inc/respuestas.php`, `api/analytics.php`, `api/presupuestos.php`, `api/mockups.php`, `tabs/respuestas.php`, `js/app.js` (aditivos/reversibles).

## 7. IMPACTO EN PRODUCCIÓN Y ENVÍOS

- **IMPACTO EN PRODUCCIÓN:** ninguno — no se ha subido nada.
- **ENVÍOS REALIZADOS: 0** · `motor_estado` sigue `pausado`.

## 8. SIGUIENTE FASE

**FASE 5 — Checkpoint de lote** (auditoría automática `READY TO SEND`/`BLOCKED` con 10 comprobaciones + tabla `batches`).

**AUTORIZACIÓN REQUERIDA: SÍ.**

---

*Checkpoint FASE 4 · 2026-08-29 · MEGAPROMPT V2*
