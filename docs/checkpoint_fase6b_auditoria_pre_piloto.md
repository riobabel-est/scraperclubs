# CHECKPOINT — FASE 6B: AUDITORÍA PRE-PILOTO

**FECHA:** 2026-08-14
**OBJETIVO:** Determinar si el sistema está técnicamente preparado para crear/configurar la campaña piloto (sin crearla), resolviendo las condiciones exactas.

---

## 1. Estado inicial real (verificado)
- `config.modo_entorno = test`.
- `config.motor_estado = pausado`.
- `pipelines` = 1 fila: `id=1`, `identificador=LEGACY_TEST_FASE1`, `estado=DRAFT`, `entorno=test`, `activo=1`.
- `envios=2` (legacy), `respuestas=0`, `aperturas=0`.
- Plantillas no-whatsapp activas: 6.

## 2. Objetivo
Documentar las condiciones técnicas para crear/configurar la campaña piloto y la política de estados/entorno/activo, sin crear la campaña ni autorizar envíos.

## 3. Estado de campaña
NO existe campaña PILOT/ACTIVE. Solo hay la campaña TEST DRAFT (`LEGACY_TEST_FASE1`). No hay campaña candidata para piloto.

## 4. Política de estados y entorno (verificado en `inc/abc.php`)
`validarCampanaActiva()` permite envío SOLO si:
1. `campaignId > 0`;
2. la campaña existe;
3. `estado ∈ {PILOT, ACTIVE}`;
4. `activo = 1`;
5. `esEntornoCoherente(entorno_campaña, modo_entorno)` es true.

### Tabla `esEntornoCoherente()` (solo combinaciones soportadas por código)
| modo_entorno | entorno_campaña | ¿coherente? |
|---|---|---|
| test | test | SÍ |
| test | pilot | NO |
| test | production | NO |
| produccion | test | NO |
| produccion | pilot | SÍ |
| produccion | production | SÍ |

### Combinaciones válidas para una campaña que P1/P3 puedan usar
- **Piloto comercial (producción):** `estado=PILOT`, `entorno=pilot` (o `production`), `activo=1`, con `config.modo_entorno=produccion`.
- **Test/pruebas locales:** `estado=PILOT` (o `ACTIVE`), `entorno=test`, `activo=1`, con `config.modo_entorno=test`.

No se inventa política nueva: es lo que ya aplica `validarCampanaActiva()`.

## 5. Configuración actual (NO modificar)
- `modo_entorno=test`.
- `motor_estado=pausado`.
Estos valores deben permanecer así en esta fase.

## 6. Condición de BLOCKED (verificado)
`validarCampanaActiva()` devuelve `NO_CAMPAIGN` (id ≤ 0 o ausente), `INVALID_CAMPAIGN` (inexistente), `CAMPAIGN_NOT_ACTIVE` (estado no PILOT/ACTIVE o activo≠1), `ENVIRONMENT_MISMATCH` (incoherente). P1 y P3 rechazan en esos casos ANTES de reservar/SMTP. Sin fallback.

## 7. Condición que permitiría declarar GO (requisito futuro)
1. Campaña creada con `estado=PILOT`, `entorno=pilot` (o `test` para smoke), `activo=1`, `identificador` definido.
2. Plantilla(s) fijada(s) y con variantes A/B/C listas.
3. `modo_entorno` y `motor_estado` puestos en el estado decidido por el usuario (no automático).
4. Smoke test a buzones controlados antes de lote real.

## 8. Auditoría P1 (`api/enviar_lote.php`)
PASS — campaign_id obligatorio y validado antes de reserva/SMTP; sin fallback; variante determinística vía `asignarVariante`; reserva conserva campaign_id/variant.

## 9. Auditoría P3 (`cli/cron.php`)
PASS — `--campaign-id` obligatorio; misma política central `validarCampanaActiva()` (sin divergencia con P1).

## 10. Auditoría UI (`js/app.js` + `tabs/lanzadera.php`)
PASS — selector de campaña sin auto-selección; bloqueo "Selecciona una campaña antes de enviar."; `enviarCorreoPrueba` e `iniciarMotor` transmiten `campaign_id`; sin valor por defecto peligroso.

## 11. Auditoría A/B/C
PASS — determinístico e inmutable; retry conserva variante y Message-ID/snapshot; sin reasignación en retry; mismo lead+campaña no duplica reserva; sin fallback de variante.

## 12. Auditoría de plantillas
PASS/PARTIAL — el snapshot queda congelado al reservar (`envios.asunto/cuerpo_mensaje` inmutables por retry). Un cambio posterior de plantilla NO afecta un envío ya reservado. Existen plantillas no-whatsapp activas. **Requiere decisión de negocio:** elegir la(s) plantilla(s) para el piloto y fijarlas antes del primer envío (no hay congelación automática previa, solo para plantillas ya usadas por PILOT/ACTIVE). NOT VERIFIED hasta seleccionar plantilla concreta.

## 13. Auditoría de elegibilidad (`inc/eligibilidad.php`)
PASS — Lista Negra/opt-out bloqueadas, duplicados excluidos, emails inválidos/vacíos bloqueados, leads TEST bloqueados fuera de campañas test, coherencia de entorno en P1/P3.

## 14. Auditoría de métricas (`inc/metricas.php`)
PASS — toda métrica depende de `campaign_id`; A/B/C usa `envios.variant`; `resultado_envio='ACCEPTED'` es el denominador; respuestas por `envio_id`; aperturas por `tracking_id`; legacy excluido (variant/campaign NULL).

## 15. Auditoría Analytics (`tabs/analytics.php`, `dashboard.php`)
PASS — `get_piloto_campanas` y `get_piloto_metricas`; sin lead_pipelines/estado_lead/stageOrder/abc_ganadora; sin ganadora automática; contexto de campaña visible.

## 16. Checklist PRE-FLIGHT (estado del código)
| Elemento | Estado |
|---|---|
| Campaña válida | NOT READY (no existe campaña piloto) |
| Campaña seleccionada explícitamente | PASS (UI lo exige) |
| Entorno coherente | PASS (validación central) |
| Motor en estado correcto | NOT APPLICABLE (pausado; decisión futura) |
| Modo de entorno correcto | NOT APPLICABLE (test; decisión futura) |
| Plantilla validada | PASS (existen plantillas) pero requiere selección |
| A/B/C validado | PASS |
| Lista Negra aplicada | PASS |
| Leads TEST bloqueados | PASS |
| Duplicados excluidos | PASS |
| campaign_id presente | PASS |
| variant presente | PASS |
| lead_id presente | PASS |
| Message-ID presente | PASS |
| snapshot presente | PASS |
| smtp_id presente | PASS |
| tracking_id presente | PASS |
| resultado_envio listo | PASS |
| Analytics accesible | PASS |
| Métricas por campaña accesibles | PASS |

## 17. Tests realizados
- La auditoría no requiere harness nuevo: la política de campaña/entorno ya está cubierta por `_test_fase6a.php` (33 tests) y la regresión histórica.
- T1–T25 solicitados equivalen a los ya cubiertos por FASE 6A (campaña inexistente/ausente/0/negativo/DRAFT/READY/PAUSED/COMPLETED/ARCHIVED/incoherente/válida, UI sin/con campaña, reserva conserva campaign_id/variant, retry conserva variant/Message-ID/snapshot, sin segunda reserva, P2 bloqueado, P3 sin campaign, Analytics consulta campaña/métricas, legacy excluido, A/B/C determinístico): **todos PASS** (33/33 en `_test_fase6a.php`).

## 18. Regresión (ejecutada)
- FASE 2B: 9/9 PASS
- FASE 2C: 12/12 PASS
- FASE 3A: 23/23 PASS
- FASE 4B: 15/15 PASS
- FASE 4C: 15/15 PASS
- FASE 5B: 20/20 PASS
- FASE 5C E2E: 11/11 PASS
- FASE 6A: 33/33 PASS

## 19. Hallazgos
1. Ninguna campaña PILOT/ACTIVE existente → el sistema no puede arrancar piloto aún (correcto, pendiente de creación).
2. `get_analytics` legacy permanece en `js/app.js`/`dashboard.php` como código muerto para la pestaña Analytics (no bloquea; registrado).
3. Congelación automática de plantilla previa al pilot no está automatizada (solo para plantillas ya usadas por PILOT/ACTIVE). No bloqueante técnico.

## 20. Bloqueantes
Ninguno técnico de código. Los dos pendientes son **operativos**: (a) crear la campaña piloto, (b) seleccionar/fijar plantilla.

## 21. Riesgos residuales
- La UI de lanzadera mostrará campañas vía `get_piloto_campanas`, incluidas las que no son enviables (DRAFT, etc.); el bloqueo real lo aplica `validarCampanaActiva` en el backend. Riesgo bajo: la UI bloquea si no hay selección, y el backend bloquea estados no válidos.
- No hay política automática de congelación previa de plantilla (riesgo operativo, no de integridad de datos ya enviados).

## 22. Decisiones pendientes (para la siguiente fase)
1. Crear campaña piloto con `estado=PILOT`, `entorno=pilot` (producción) o `test` (pruebas), `activo=1`.
2. Elegir y fijar plantilla(s) del piloto.
3. Definir `config.modo_entorno` y `motor_estado` para el smoke test.

## 23. Veredicto
**READY FOR CAMPAIGN CREATION**

## 24. Acciones requeridas para la siguiente fase
1. Crear la campaña piloto (según decisión del usuario).
2. Fijar plantilla(s).
3. Configurar entorno/motor para smoke test a buzones controlados (sin envíos comerciales hasta autorización).