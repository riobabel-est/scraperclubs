# CHECKPOINT — FASE 6 · PRIMER LOTE CONTROLADO (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PREPARADO / READY TO SEND · **disparo PENDIENTE de confirmación y ventana horaria**
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase5_20260829_021505` (FASE 5)

---

## 1. CHECKPOINT DE PRODUCCIÓN (resumen del lote)

```text
CAMPAÑA:           2 — Comerciales FutProtec 2026-08 (PILOT / pilot)
LOTE:              2026-08-30-A  (batch id 1 · estado AUTORIZADO)
LEADS:             200 auditados
EMAILS:            200 (capacidad diaria SMTP real: 150 → día 1: 150, día 2: 50)
VARIANTE A:        60
VARIANTE B:        66
VARIANTE C:        74
SMTP:              10/10 cuentas activas (límite 15/día c/u → 150/día máx)
PLANTILLA:         'Prospección - Paso 1 - Test ABC (Dolor/Beneficio)' (activa)
BOUNCES EXCLUIDOS: 0 en el lote (21 globales ya suprimidos en la fuente)
BLACKLIST EXCLUIDA:0
TEST EXCLUIDOS:    0
DUPLICADOS:        0
AUDITORÍA:         10/10 comprobaciones PASS
DECISIÓN:          READY TO SEND
```

## 2. CONDICIONES PARA EL DISPARO DEL ENVÍO REAL

1. **Ventana horaria:** ahora 02:25 (zona servidor) — el protocolo FASE 6 prohíbe enviar en la ventana **00:00-03:00**. Disparo posible a partir de las **03:00** (recomendado: horario comercial 09:00-18:00, consistente con el histórico de la campaña).
2. **Confirmación explícita del checkpoint** por el usuario (regla 5.2/3.5 del megaprompt).
3. **Capacidad diaria:** 150 emails/día (10 cuentas × 15). El lote de 200 se entregará en **2 días** (150 el día 1 + 50 el día 2), mismo batch `2026-08-30-A`.
4. **Delay:** ≥ 3 s entre envíos (respetado por el motor).

## 3. CÓMO SE DISPARARÁ (cuando lo confirmes)

- El motor respeta `config.motor_estado` (ahora `pausado`). El disparo se hace activando el motor con la cola del lote o ejecutando el runner de cola con el batch, y se monitoriza en tiempo real.
- El batch `2026-08-30-A` ya está registrado (estado `AUTORIZADO`), con los 200 leads y sus variantes deterministas.

## 4. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `envios` | 470 | **470** (aún no se ha disparado ningún envío) |
| `batches` | 0 | 1 (`2026-08-30-A`, AUTORIZADO, 200) |
| `integrity_check` | ok | ok |
| `motor_estado` | pausado | pausado |
| TEST/REAL | intactos | sin cambios |

## 5. TESTS

- Auditoría pre-lote 10/10 PASS (TEST 13) · Regresiones FASE 1-5: 14/9/10/14/9 PASS · eligibilidad PASS.

## 6. RIESGOS Y NOTAS

- **Smoke test real pendiente:** RFC 2047 y supresión de bounces están implementados y probados localmente (raw MIME), pero **aún no contra SMTP real** (TEST 09). Recomendación: 1-2 envíos TEST a cuentas propias (modo test) antes del lote comercial para confirmar la cabecera `From` en recepción.
- Si se dispara el lote de 200 sin el smoke test, el riesgo es bajo (el rebote de Yahoo se corrigió con RFC 2047 y los bounces están suprimidos) pero no nulo.

## 7. SIGUIENTE PASO

**Confirmar el disparo** del lote `2026-08-30-A` (día 1: 150 emails) indicando:
- **"Dispara el lote"** → activaré el envío real fuera de la ventana 00:00-03:00 (a partir de las 03:00) respetando delay ≥ 3 s y límites diarios, y generaré el informe de métricas del lote.
- o **"Antes, smoke test"** → haré 1-2 envíos TEST a cuentas propias (modo test) y confirmaré el raw en recepción antes del lote.

**AUTORIZACIÓN DE DISPARO: PENDIENTE (la fase quedó preparada y auditada).**

---

*Checkpoint FASE 6 · 2026-08-29 · MEGAPROMPT V2 · 0 envíos realizados · motor pausado*
