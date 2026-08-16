# CHECKPOINT — FASE 2C: IMPLEMENTACIÓN DE campaign_id EN P3

**FECHA:** 2026-08-14
**ALCANCE:** `cli/cron.php` recibe `--campaign-id=N` obligatorio, valida contra `pipelines`, propaga a envios e idempotencia. Sin envíos reales.

---

## 1. Archivos modificados
- `public_html/outbound/cli/cron.php` (solo el flujo de campaña + reserva idempotente).
- `scripts/_test_fase2c.php` (NUEVO — harness de tests).

## 2. Cambios realizados
- Parseo CLI: `--campaign-id=N` (alias `--campaign=N`).
- Validación secuencial (antes de procesar lead):
  1. sin campaign_id → `BLOCKED / NO CAMPAIGN`.
  2. no entero positivo → `BLOCKED / NO CAMPAIGN`.
  3. `pipelines.id` inexistente → `BLOCKED / INVALID CAMPAIGN`.
  4. estado no `PILOT`/`ACTIVE` o `activo!=1` → `BLOCKED / CAMPAIGN NOT ACTIVE`.
- Propagación: `campaign_id` se pasa a `esElegibleParaEnvio()` y a `reservarEnvioLogico()`, quedando en `envios.campaign_id`.
- Reserva idempotente antes de SMTP; resultado actualiza la MISMA fila (`enviado`/`error`).

## 3. Comportamiento sin campaign_id
`BLOCKED / NO CAMPAIGN` (exit 1). No procesa lead.

## 4. Comportamiento con campaña inexistente
`BLOCKED / INVALID CAMPAIGN` (exit 1). No procesa lead.

## 5. Estados bloqueados
`DRAFT`, `READY`, `PAUSED`, `COMPLETED`, `ARCHIVED` → `BLOCKED / CAMPAIGN NOT ACTIVE`.

## 6. Estados permitidos
`PILOT`, `ACTIVE` (con `activo=1`) → continúa hacia selección/reserva.

## 7. Propagación del campaign_id
Verificado: la fila insertada contiene `envios.campaign_id = 6` en el test T10.

## 8. Tests ejecutados (sobre estructura aislada; sin SMTP real)
| Test | Resultado | Evidencia |
|---|---|---|
| T1 sin campaign-id | PASS | `NO CAMPAIGN`, code=1 |
| T2 campaign-id inexistente | PASS | `INVALID CAMPAIGN`, code=1 |
| T3 DRAFT | PASS | `CAMPAIGN NOT ACTIVE` |
| T4 READY | PASS | `CAMPAIGN NOT ACTIVE` |
| T5 PAUSED | PASS | `CAMPAIGN NOT ACTIVE` |
| T6 COMPLETED | PASS | `CAMPAIGN NOT ACTIVE` |
| T7 ARCHIVED | PASS | `CAMPAIGN NOT ACTIVE` |
| T8 PILOT | PASS | no bloqueado, llega a Motor PAUSADO |
| T9 ACTIVE | PASS | no bloqueado, llega a Motor PAUSADO |
| T10 propagación campaign_id | PASS | `envio_id=3 lead=1 campaign=6 estado=enviado` |
| T11 mismo lead + misma campaña | PASS | `n=1` (máx un envío lógico) |
| T12 mismo lead + campaña distinta | PASS | `nuevo=true` |

**RESUMEN: 12 tests, 0 fallos.**

## 9. Compatibilidad con idempotencia
Garantía compartida `lead_id + campaign_id` se mantiene (mismo índice único parcial). P3 reserva antes de SMTP y actualiza la misma fila → no duplica y no reintroduce carrera.

## 10. Riesgos
1. Un scheduler externo que invoque `cron.php` sin argumento quedará BLOCKED (deseado).
2. La validación de estado usa el campo `estado` y `activo` reales de `pipelines`; si una campaña productiva no tiene estado correcto, no enviará (fail-safe).

## 11. Limitaciones
- P3 no asigna `variant` todavía (es FASE 3).
- La separación TEST/PILOT en P3 depende del guard `esElegibleParaEnvio` (lead TEST en campaña no-test).

---

## AMBIGÜEDAD DETECTADA — `entorno` vs `estado` (DETENIDO sin resolver)
Existe una combinación teórica: **`entorno=test` + `estado=ACTIVE`**. La validación actual de P3 solo comprueba `estado ∈ {PILOT, ACTIVE}` + `activo=1`, **sin** cruzar `entorno`. Esto significa que una campaña marcada `entorno=test` pero `estado=ACTIVE` **podría** permitir envío por P3, contradiciendo la intención de que el piloto comercial esté claramente separado del entorno TEST.

No invento una política nueva. Documento el caso y **me detengo** en este punto, conforme a la regla del plan: si existe ambigüedad real, no resolverla por cuenta propia.

Recomendación a evaluar en FASE 3: añadir a P3 la verificación de que el `entorno` de la campaña sea coherente con `config.modo_entorno` (o restringir los estados que P3 puede ejecutar según entorno del desplegado: test/pilot/production).

---

## Estados del checkpoint
- Implementación campaign-id en P3: **PASS**.
- Validación de estados bloqueados/permitidos: **PASS**.
- Propagación a `envios.campaign_id`: **PASS**.
- Idempotencia compartida: **PASS**.
- Tests: **PASS (12/12)**.
- Ambigüedad entorno/estado: **NOT VERIFIED / DETENIDO** (documentado, sin resolver).

> NO avanzo a FASE 3. No realizo envíos reales. Detenido a espera de aprobación.