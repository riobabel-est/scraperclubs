# CHECKPOINT — FASE 1 · BLOQUEANTES (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PASS (pendiente autorización para FASE 2)
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase1_20260829_011910` (verificado, integrity ok, 470 envíos)

---

## 1. CAMBIOS APLICADOS

### 1.1 Base de datos (aditivo)
- `ALTER TABLE rebotes` + `envio_id`, `lead_id`, `campaign_id`, `smtp_code`, `atribucion_parcial` (DEFAULT 0).
- Poblado de `rebotes` desde `respuestas.es_rebote=1` (21 hard bounces históricos):
  - **14 asociados a campaña 2** por `Message-ID` incrustado en el cuerpo (envio_id, lead_id, campaign_id, email resueltos).
  - **1 de prueba** (`rodrigo@riobabel.com`, sin envío asociado, `atribucion_parcial=1`).
  - **6 sin identificar** (cuerpo vacío del 28-08, `email=''`, `atribucion_parcial=1`). No se inventó email ni código.
  - `smtp_code` y `motivo` extraídos del cuerpo (Status / Diagnostic-Code).
- `INSERT INTO _migraciones` (script `fase1_poblar_rebotes.sql`, fase `FASE 1`, exitoso).

### 1.2 Código
| Archivo | Cambio |
|---|---|
| `public_html/outbound/inc/eligibilidad.php` | +`esEmailHardBounced()` + `esLeadHardBounced()`; integradas en `esElegibleParaEnvio()` (razon `hard_bounce`, sin rutas alternativas) |
| `public_html/outbound/inc/smtp_transport.php` | +`futprotec_encodeHeaderName()` (RFC 2047 `=?UTF-8?B?...?=`); aplicada en la cabecera `From` |
| `public_html/outbound/js/app.js` | Eliminado `Math.random()` en `enviarDirigido` y `enviarCola`; +`lzVarianteParaEnvio()` que consulta el backend (`api/lead_validate.php` → `asignarVariante()`). El backend `api/enviar_lote.php:122` ya imponía `asignarVariante()` en producción (doble garantía) |
| `scripts/test_fase1_bloqueantes.php` | NUEVO test de FASE 1 (TEST 01/07/08/10/11/12) |

## 2. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `rebotes` | 0 | **21** (15 con email · 8 parcial · 14 campaña 2) |
| `_migraciones` | 1 | 2 |
| `envios` / `aperturas` / `respuestas` / `clubes_crm` | 470 / 326 / 30 / 1818 | sin cambios |
| TEST/REAL | 432 reales camp2 · 20 reales sin camp · 18 test | sin cambios |
| `integrity_check` | ok | ok |

## 3. TESTS (RESULTADO)

- **TEST FASE 1 (`scripts/test_fase1_bloqueantes.php`): 14 PASS / 0 FAIL**
  - TEST 01 DB integrity · TEST 07 raw MIME (From encoded-word, Subject UTF-8, Reply-To, cabeceras 100 % ASCII) · TEST 08 RFC 2047 (á é ñ, decode correcto) · TEST 10 hard bounce suppression (lead 881 no elegible; lead sano no bloqueado) · TEST 11 TEST/REAL (lead TEST en campaña REAL bloqueado) · TEST 12 determinismo A/B/C.
- **Regresión `scripts/test_eligibilidad.php`: `VEREDICTO: TEST_ELIGIBILIDAD_PASS`** (18 checks).
- Sintaxis: `php -l` OK en `eligibilidad.php`, `smtp_transport.php`, `enviar_lote.php`, `lead_validate.php` · `node --check` OK en `app.js`.

## 4. BLOQUEANTES DE FASE 1 — ESTADO

| Bloqueante | Estado |
|---|---|
| 1.1 Supresión de hard bounces | ✅ Operativa (rebotes poblada + consulta `respuestas.es_rebote=1` + integrada en elegibilidad) |
| 1.3 RFC 2047 | ✅ Implementada + validada en raw MIME |
| 1.6 A/B/C determinista | ✅ `Math.random` eliminado del flujo de variante; backend recalcula en producción |
| 1.5 TEST/REAL | ✅ Verificado (TEST 11 + regresión) |
| 1.2 Supresión auditable | ✅ `razon='hard_bounce'` registrable y consultable |

## 5. RIESGOS RESIDUALES

- 6 rebotes del 28-08 sin identificar (`email=''`): no suprimen nada (no se conoce la dirección). Se conservan para auditoría.
- `rodrigo@riobabel.com` queda suprimido en `rebotes` (era un envío de prueba, no afecta comercial).
- La tabla `rebotes` tiene `email NOT NULL` (columna original) → los no identificados usan `''` (nunca `NULL`). Documentado.
- `foreign_keys` sigue OFF (regla 6.9: deuda técnica separada, no se activa en esta intervención).
- `Math.random` restante en `app.js` es solo el retardo entre envíos (`lzRandomDelay`), no asignación de variante.

## 6. ROLLBACK

- Restaurar `data/stats.db.bak_fase1_20260829_011910` revierte por completo la migración de `rebotes` y `_migraciones`.
- Los cambios de código son aditivos y reversibles: revertir los 4 archivos desde git (sin push) o deshacer las ediciones.

## 7. IMPACTO EN PRODUCCIÓN Y ENVÍOS

- **IMPACTO EN PRODUCCIÓN:** la DB local migrada y el código quedan listos para validación/deploy; **no se ha subido nada**.
- **ENVÍOS REALIZADOS: 0** · `motor_estado` sigue `pausado` · no se ha enviado ni un email de prueba.

## 8. SIGUIENTE FASE

**FASE 2 — Trazabilidad** (follow-ups con metadatos, `oportunidades`, event store, `fecha_respuesta_iso`, `atendido_en`).

**AUTORIZACIÓN REQUERIDA: SÍ.**

---

*Checkpoint FASE 1 · 2026-08-29 · MEGAPROMPT V2*
