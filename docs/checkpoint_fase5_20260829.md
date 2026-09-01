# CHECKPOINT — FASE 5 · CHECKPOINT DE LOTE (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PASS (pendiente autorización para FASE 6)
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase5_20260829_021505` (verificado, integrity ok)

---

## 1. CAMBIOS APLICADOS

### 1.1 Base de datos (aditivo, registrado en `_migraciones` → id 6)
| Objeto | Cambio |
|---|---|
| `batches` | CREATE (id, campaign_id, batch, fecha, estado, tamano) + índice `idx_batches_campaign` |

### 1.2 Código
| Archivo | Cambio |
|---|---|
| `public_html/outbound/cli/auditoria_pre_lote.php` | **NUEVO** checkpoint CLI: selecciona el lote candidato (sin primer envío en la campaña, con `--limite`/`--federacion`), ejecuta las **10 comprobaciones** y devuelve **`READY TO SEND`** o **`BLOCKED`** (decisión inequívoca; warnings solo informativos). Soporta `--json` y `--crear-batch` (INSERT en `batches` solo si READY TO SEND) |
| `scripts/test_fase5_checkpoint.php` | NUEVO test (TEST 01/13) |

### 1.3 Las 10 comprobaciones (FASE 5.1)
```text
TEST/REAL (sin leads TEST) · DUPLICATE (sin emails duplicados) · BOUNCE (sin hard
bounces, consulta rebotes + respuestas.es_rebote=1) · BLACKLIST (sin supresión) ·
EMAIL VALIDITY (filter_var; dominio sin MX = WARNING) · CAMPAIGN (validarCampanaActiva)
· VARIANT (asignarVariante determinista A/B/C) · TEMPLATE (plantilla de la campaña
activa) · SMTP (cuentas activas con límite diario) · TRACKING (generación sin colisión)
```

## 2. EJECUCIÓN REAL DEL CHECKPOINT (lote de 50, campaña 2 — solo lectura)

```
[PASS] CAMPAIGN       Comerciales FutProtec 2026-08 (PILOT/pilot)
[PASS] TEST/REAL      sin leads TEST
[PASS] DUPLICATE      sin emails duplicados
[PASS] BOUNCE         sin hard bounces
[PASS] BLACKLIST      sin leads en supresión
[PASS] EMAIL VALIDITY formatos válidos (sin MX: 0)
[PASS] VARIANT        A=17 · B=15 · C=18 (determinista)
[PASS] TEMPLATE       'Prospección - Paso 1 - Test ABC' activa
[PASS] SMTP           10/10 cuentas disponibles
[PASS] TRACKING       sin colisión
DECISIÓN: READY TO SEND
```

## 3. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `batches` | — | 0 (no se crearon lotes; el checkpoint no inserta sin `--crear-batch`) |
| `envios` | 470 | 470 (sin cambios) |
| `_migraciones` | 5 | 6 |
| `integrity_check` | ok | ok |
| TEST/REAL | intactos | sin cambios |
| Leads reales pendientes de campaña 2 | — | **1.461 candidatos** disponibles para lotes futuros |

## 4. TESTS (RESULTADO)

- **TEST FASE 5 (`scripts/test_fase5_checkpoint.php`): 9 PASS / 0 FAIL**
  - TEST 01 DB integrity · TEST 13 (tabla batches existe y vacía; script JSON; decisión inequívoca; 10 comprobaciones; sin ERROR en lote sano; **campaña inválida → BLOCKED**).
- **Regresiones:** FASE 1 (14/14) · FASE 2 (9/9) · FASE 3 (10/10) · FASE 4 (14/14) · eligibilidad (PASS).
- Sintaxis: `php -l` OK.

## 5. REQUISITOS DE FASE 5 — ESTADO

| Requisito | Estado |
|---|---|
| Auditoría ejecuta las 10 comprobaciones | ✅ verificado (lote real) |
| `BLOCKED` detiene el envío (ERROR crítico) | ✅ campaña inválida → BLOCKED (TEST 13) |
| Decisión inequívoca (nunca WARNING como sustituto) | ✅ READY TO SEND / BLOCKED |
| Confirmación explícita antes de cada lote | ✅ el checkpoint exige autorización del usuario (regla 5.2/3.5) |
| `campaign_batch_id` + tabla `batches` | ✅ tabla creada; `--crear-batch` solo si READY TO SEND |

## 6. RIESGOS RESIDUALES

- El checkpoint valida el lote en el momento de la auditoría; si los datos cambian entre auditoría y envío (nuevo bounce, baja), conviene re-ejecutarlo antes de disparar. Documentado.
- La comprobación de dominio (MX) es WARNING no bloqueante (el DNS local puede variar; el formato sí es bloqueante).
- `batches` se puebla solo con `--crear-batch` y READY TO SEND (flujo de FASE 6).

## 7. ROLLBACK

- **DB:** restaurar `data/stats.db.bak_fase5_20260829_021505`.
- **Código:** eliminar `cli/auditoria_pre_lote.php` y `scripts/test_fase5_checkpoint.php`.

## 8. IMPACTO EN PRODUCCIÓN Y ENVÍOS

- **IMPACTO EN PRODUCCIÓN:** ninguno — no se ha subido nada.
- **ENVÍOS REALIZADOS: 0** · `motor_estado` sigue `pausado`.

## 9. SIGUIENTE FASE

**FASE 6 — Primer lote controlado** (`batch_size ∈ [200,300]`, decidido por el checkpoint, autorizado explícitamente). **Esta fase requiere tu autorización expresa y será la primera que dispare envíos REALES** (previa auditoría `READY TO SEND` + confirmación).

**AUTORIZACIÓN REQUERIDA: SÍ.**

---

*Checkpoint FASE 5 · 2026-08-29 · MEGAPROMPT V2*
