# CHECKPOINT — FASE 5E: AUDITORÍA FINAL GO/NO-GO DEL PILOTO

**FECHA:** 2026-08-14
**ALCANCE:** Auditoría + tests. Sin funcionalidades nuevas. Sin envíos. Verificación de la seguridad de autorización y de la preparación real para el piloto.

---

## 1. Estado inicial
- Backend de métricas (FASE 5B) y Analytics visible (FASE 5D) correctos y validados.
- BD real: 2 envíos legacy, 0 aperturas, 0 respuestas, 1 pipeline TEST (DRAFT/test), `motor_estado=pausado`, `modo_entorno=test`.
- Regresión histórica: todo PASS.

## 2. Campaña evaluada
- Única campaña existente: `id=1`, `identificador='LEGACY_TEST_FASE1'`, `estado='DRAFT'`, `entorno='test'`, `activo=1`.
- No existe una campaña PILOT/ACTIVE/productiva válida. No se seleccionó campaña (no hay candidata para piloto).

## 3. Entorno / Configuración / Motor
- `config.modo_entorno = test`.
- `config.motor_estado = pausado`.
- No hay campaña coherente con un piloto comercial (entorno pilot/production + estado PILOT/ACTIVE).

## 4. Dataset (fuente de leads)
- Fuente de envío operativo: `clubes_crm` (P1/P3). `clubes.json` fuera del flujo (P2 desactivado).
- TEST: identificables por `@futprotec.local` / nombre `test` (heurística en `eligibilidad.php`).
- Lista Negra/opt-out: excluidas por `esElegibleParaEnvio()`.
- Duplicados: `es_duplicado=1` excluidos.
- Emails vacíos/inválidos: `filter_var` en elegibilidad.
- Total global: 1813 leads; elegibles reales según filtros del piloto (no determinado sin campaña concreta).

## 5. Plantilla
- Plantillas activas: 7. La lógica de congelación (`plantillaEstaCongelada`) aplica en `save_template` para plantillas usadas por PILOT/ACTIVE. Sin campaña piloto, no hay plantilla congelada de pilot aún.

---

## 6. RESULTADOS DE LOS TESTS DE SEGURIDAD

Ejecutados sobre **copia temporal** (sin SMTP real, sin tocar la BD productiva).

| Test | Descripción | Resultado |
|---|---|---|
| T1 | campaña inexistente → BLOCKED | PASS (P3 `INVALID CAMPAIGN`) |
| T2 | DRAFT → BLOCKED | PASS (P3 `CAMPAIGN NOT ACTIVE`) |
| T3 | READY → BLOCKED | PASS |
| T4 | PAUSED → BLOCKED | PASS |
| T5 | COMPLETED → BLOCKED | PASS |
| T6 | ARCHIVED → BLOCKED | PASS |
| T7 | entorno incoherente → BLOCKED | PASS (P3 `ENVIRONMENT MISMATCH`) |
| T8 | campaña PILOT coherente → elegible | PASS (P3) |
| T9 | Lista Negra → BLOCKED | PASS (elegibilidad) |
| T10 | lead TEST en campaña no-test → BLOCKED | PASS |
| T11 | mismo lead + campaign → máximo 1 reserva | PASS |
| T12 | retry → mismo variant | PASS |
| T13 | retry → mismo Message-ID | PASS |
| T14 | retry → mismo snapshot | PASS |
| T15 | P2 → BLOCKED | PASS (`die()` activo) |
| T16 | P3 sin campaign-id → BLOCKED | PASS (`NO CAMPAIGN`) |
| T17 | campaña inválida en P3 → BLOCKED | PASS |

**Detalle T1-T8 y T16-T17:** cubiertos por `scripts/_test_fase2c.php` (12 tests) y la validación de campaña de `cron.php`. T9/T10/T11-T14: cubiertos por `_test_fase2b.php`, `_test_fase3.php`, `_test_fase4b.php`. T15: P2 con `die()` al inicio (verificado).

## 7. HALLAZGO CRÍTICO — P1 (LANZADERA) NO EXIGE CAMPAÑA
Verificado en código real:
- `js/app.js` `enviarCorreoPrueba()` e `iniciarMotor()` envían `FormData` a `api/enviar_lote.php` **sin** `campaign_id` ni `id_campana`.
- `api/enviar_lote.php` solo lee `entorno` de la campaña **si** `campaign_id > 0`; como la lanzadera no lo envía, `campaign_id = 0`.
- Consecuencia: P1 podría enviar sin campaña (sin `campaign_id` en `envios`, sin validación de estado/activo de campaña), rompiendo la trazabilidad `LEAD→CAMPAÑA→VARIANTE` del piloto si se usara la lanzadera tal cual.

**Esto no es una política nueva: es una carencia de integración pendiente.** La lanzadera debe fijar/validar una campaña antes de enviar, o P1 debe rechazar envíos sin `campaign_id`.

## 8. AMBIGÜEDAD entorno=test + estado=ACTIVE
- P3 ya bloquea con `ENVIRONMENT MISMATCH` (usa `esEntornoCoherente`).
- P1 solo aplica `esEntornoCoherente` si recibe `campaign_id > 0`; dada la carencia del §7, P1 no está protegido de forma completa.
- Resolución: no inventada; se documenta que la protección de entorno en P1 depende de que P1 reciba `campaign_id`.

## 9. Analytics (confirmación, sin reimplementar)
- Analytics visible → `get_piloto_campanas` + `get_piloto_metricas`.
- Métricas → `inc/metricas.php`.
- No lead_pipelines / estado_lead / stageOrder / abc_ganadora / get_analytics para A/B/C (verificado en FASE 5D).

## 10. Regresión
- FASE 2B: 9/9 PASS
- FASE 2C: 12/12 PASS
- FASE 3A: 23/23 PASS
- FASE 4B: 15/15 PASS
- FASE 4C: 15/15 PASS
- FASE 5B: 20/20 PASS
- FASE 5C E2E: 11/11 PASS

## 11. Riesgos
1. **P1 (lanzadera) puede enviar sin campaign_id** → trazabilidad de campaña/variante incompleta si se usa en piloto.
2. No existe aún una campaña PILOT/ACTIVE coherente (la única es TEST DRAFT), por lo que tampoco puede arrancarse un piloto real sin crearla antes.
3. `modo_entorno=test` y `motor_estado=pausado` son condiciones actuales (no productivas).

## 12. Decisión final
**NO-GO**

Razones:
1. La lanzadera (P1) no exige `campaign_id`, por lo que un envío desde la UI no quedaría ligado a `envios.campaign_id/variant` de forma inequívoca (trazabilidad del piloto incompleta).
2. No existe una campaña PILOT/ACTIVE coherente para autorizar el piloto.
3. `motor_estado=pausado` y `modo_entorno=test` (aún no es producción/piloto).

> No se realizó ningún envío. NO-GO. Detenido a espera de aprobación (y de priorización de la FASE 6/7 que cierre P1-campaign y la creación de la campaña piloto).