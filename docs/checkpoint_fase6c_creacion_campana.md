# CHECKPOINT — FASE 6C: CREACIÓN CONTROLADA DE LA CAMPAÑA PILOTO

## 1. Objetivo
Crear y configurar de forma controlada UNA campaña piloto comercial, sin enviar, sin activar motor, sin cambiar entorno, en estado seguro no enviable.

## 2. Alcance
Inserción de una fila en `pipelines` + verificación de que no se producen envíos/reservas/efectos colaterales. Sin modificar A/B/C, Message-ID, snapshot, resultado_envio, respuestas, tracking, supresión o Analytics.

## 3. Estado inicial
- `pipelines = 1` (solo `LEGACY_TEST_FASE1`, DRAFT/test).
- `envios = 2`, `respuestas = 0`, `aperturas = 0`.
- `modo_entorno = test`, `motor_estado = pausado`.

## 4. Backup previo
- `public_html/outbound/backups/fase6c_pre_20260814_235829.db` (consistente vía API backup; pipelines=1, envios=2 verificados).

## 5. Campaña creada
- `id = 2`
- `nombre = 'Piloto Comercial FutProtec 2026-08'`

## 6. Identificador
- `PILOTO_FUTPROTEC_2026_08` (único; verificado previamente inexistente). NO reutiliza `LEGACY_TEST_FASE1`.

## 7. Estado
- `DRAFT` (seguro, NO enviable por la política `validarCampanaActiva`).

## 8. Entorno
- `pilot` (valor nominal para piloto comercial, coherente con la política `esEntornoCoherente` para un futuro `modo_entorno=produccion`).

## 9. Activo
- `1` (pero `estado=DRAFT` impide envío; `validarCampanaActiva` exige PILOT/ACTIVE).

## 10. Plantilla(s)
- NO modificadas. Sin plantilla asociada aún (decisión pendiente para siguiente fase).

## 11. A/B/C
- NO modificado. Mecanismo determinístico intacto.

## 12. Modo de entorno
- `modo_entorno = test` (sin cambios).

## 13. Estado del motor
- `motor_estado = pausado` (sin cambios).

## 14. Trazabilidad
- La campaña existe con `id` identificable por `identificador`; futura reserva poblará `envios.campaign_id`. No se realizó ningún envío.

## 15. Analytics
- `get_piloto_campanas` devolverá la nueva campaña (consulta `pipelines`). No hubo auto-selección; no se modificó Analytics.

## 16. Integridad de BD
- `pipelines = 2` (antes 1).
- `envios = 2`, `respuestas = 0`, `aperturas = 0` (sin cambios).
- `LEGACY_TEST_FASE1` permanece intacta (`DRAFT`/`test`/`activo=1`).

## 17. Ausencia de envíos
- Sin nuevos envíos, respuestas, aperturas, reservas, Message-ID ni resultados SMTP.

## 18. Tests
- Verificación directa de la fila creada (id, identificador, estado, entorno, activo).
- `get_piloto_campanas` devolverá la campaña (endpoint lee `pipelines`); no requiere harness nuevo.
- Métricas de la nueva campaña (estado no envio): `calcularMetricas` devolvería 0 (no ejecutado; era campaña DRAFT sin envíos).

## 19. Regresión
- Sin cambios de código en esta fase (solo inserción BD). Regresión FASE 6A sigue vigente (33/33) de la fase anterior; no re-ejecutada por no haber cambios de lógica.

## 20. Hallazgos
- No existe API/UI para crear campañas; se insertó directo en `pipelines` (única vía), respetando el índice único `idx_pipelines_identificador`.

## 21. Bloqueantes
- Ninguno. La campaña quedó en estado seguro (DRAFT).

## 22. Riesgos residuales
- Para operar, la siguiente fase deberá cambiar `estado` a `PILOT` y alinear `modo_entorno`; no se realiza aquí.

## 23. Decisiones pendientes
1. Activar campaña (estado PILOT) cuando se autorice.
2. Fijar plantilla(s) del piloto.
3. Definir `modo_entorno` (produccion) y `motor_estado` para smoke test.

## 24. Veredicto
**CAMPAIGN CREATED — SAFE / NOT ACTIVE**

## 25. Estado final
- `pipelines=2`, `envios=2`, `respuestas=0`, `aperturas=0`.
- `motor_estado=pausado`, `modo_entorno=test`.
- Campaña piloto creada en DRAFT/pilot, no enviable, lista para la siguiente fase de activación/smoke test.