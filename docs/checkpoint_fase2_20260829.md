# CHECKPOINT — FASE 2 · TRAZABILIDAD (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PASS (pendiente autorización para FASE 3)
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase2_20260829_013109` (verificado, integrity ok, 470 envíos)

---

## 1. CAMBIOS APLICADOS

### 1.1 Base de datos (aditivo, registrado en `_migraciones` → id 3)
| Tabla | Cambio |
|---|---|
| `envios` | ALTER + `variant_original` VARCHAR(1), + `campaign_batch_id` TEXT, + `parent_envio_id` INTEGER, + `respuesta_origen_id` INTEGER; índice `idx_envios_parent` |
| `comunicaciones_log` | ALTER + `metadata` TEXT (payloads de evento JSON) |
| `respuestas` | ALTER + `fecha_respuesta_iso` DATETIME; **backfill 30/30** desde `fecha_respuesta` RFC 2822 (conservando la columna original) |
| `oportunidades` | CREATE (mínima: id, lead_id, campaign_id, estado, origen, fechas, cantidad_estimada, nivel_interes, proxima_accion, fecha_proxima_accion, motivo_perdida, importe_potencial, es_test, notas) + índices lead/campaign |

### 1.2 Código
| Archivo | Cambio |
|---|---|
| `public_html/outbound/inc/eligibilidad.php` | `reservarEnvioLogico()` y `insertarEnvioLogico()` aceptan y persisten `parent_envio_id` y `respuesta_origen_id` (opcionales; llamadas existentes intactas) |
| `public_html/outbound/api/enviar_lote.php` | Lee `parent_envio_id`/`respuesta_origen_id` del POST y los propaga a la reserva |
| `public_html/outbound/dashboard.php` | `enviarRespuestaSmtpLead()` ampliada: deriva `campaign_id`/`plantilla_id`/`variant`/`es_test`/`parent_envio_id` desde la respuesta de origen (respuesta_id → envio_id → envio) con fallback al último envío del lead; el `INSERT INTO envios` de la Bandeja incluye todos los metadatos (fin de los follow-ups huérfanos) |
| `public_html/outbound/js/app.js` | `rsEnviarRespuesta()` envía `respuesta_id` y `campaign_id` de la conversación al backend |
| `scripts/test_fase2_trazabilidad.php` | NUEVO test de FASE 2 (TEST 01/05/06/14) |

## 2. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `envios` | 470 | 470 (sin cambios) |
| `respuestas` | 30 | 30 (+ `fecha_respuesta_iso` 30/30) |
| `comunicaciones_log` | 547 | 547 (sin cambios; + columna `metadata`) |
| `oportunidades` | — | 0 filas (tabla nueva) |
| `_migraciones` | 2 | 3 |
| `integrity_check` | ok | ok |
| TEST/REAL | 432 reales camp2 · 20 reales sin camp · 18 test | sin cambios |

## 3. TESTS (RESULTADO)

- **TEST FASE 2 (`scripts/test_fase2_trazabilidad.php`): 9 PASS / 0 FAIL**
  - TEST 01 DB integrity · TEST 05 follow-up traceability (la respuesta 8→envío original; `insertarEnvioLogico` persiste parent+origen en BD en memoria) · TEST 06 campaign attribution (envío original con campaign_id/variant/plantilla/smtp/message_id) · TEST 14 estructura de migración (columnas nuevas, metadata, fecha ISO 30/30, tabla oportunidades).
- **Regresión FASE 1: 14/14 PASS** · **Regresión eligibilidad: TEST_ELIGIBILIDAD_PASS**.
- Sintaxis: `php -l` OK en `eligibilidad.php`, `enviar_lote.php`, `dashboard.php` · `node --check` OK en `app.js`.

## 4. BLOQUEANTES DE FASE 2 — ESTADO

| Requisito | Estado |
|---|---|
| 0 nuevos follow-ups con `campaign_id=NULL` en flujo comercial | ✅ El INSERT de la Bandeja y la reserva ahora incluyen metadatos; derivación automática desde la respuesta original |
| Cadena email→respuesta→follow-up reconstruible | ✅ `parent_envio_id` + `respuesta_origen_id` + `In-Reply-To`/`message_id` (verificado TEST 05/06) |
| `fecha_respuesta_iso` comparable en SQL | ✅ 30/30 pobladas, columna original conservada |
| Event store ampliado (sin tabla nueva) | ✅ `comunicaciones_log.metadata` añadida; `ACCEPTED` ≠ `DELIVERED` (regla intacta) |
| `oportunidades` creada (mínima) | ✅ lista para FASE 4 (cualificación) |

## 5. RIESGOS RESIDUALES

- Los 20 envíos REALES históricos con `campaign_id=NULL` siguen como están (histórico intocable); los **nuevos** follow-ups quedarán trazados.
- La derivación de metadatos depende de que la respuesta tenga `envio_id`; en los rebotes sin envío el fallback usa el último envío del lead (si existe). Documentado en el código.
- `oportunidades` está vacía: se poblará en FASE 4 (cualificación).
- `fecha_respuesta_iso` conserva la hora local del header RFC 2822 (sin conversión de zona); documentado en el código.

## 6. ROLLBACK

- **DB:** restaurar `data/stats.db.bak_fase2_20260829_013109` (revierte ALTERs, backfill y tabla `oportunidades`).
- **Código:** revertir ediciones de `eligibilidad.php`, `enviar_lote.php`, `dashboard.php`, `app.js` (aditivas y reversibles).

## 7. IMPACTO EN PRODUCCIÓN Y ENVÍOS

- **IMPACTO EN PRODUCCIÓN:** ninguno — no se ha subido nada a SiteGround.
- **ENVÍOS REALIZADOS: 0** · `motor_estado` sigue `pausado`.

## 8. SIGUIENTE FASE

**FASE 3 — Tracking fiable** (vista SQL de aperturas dedup + tabla `clics` con `CTA_WEB/CTA_PRESUPUESTO/CTA_CONTACTO`).

**AUTORIZACIÓN REQUERIDA: SÍ.**

---

*Checkpoint FASE 2 · 2026-08-29 · MEGAPROMPT V2*
