# CHECKPOINT — FASE 5B: IMPLEMENTACIÓN CONTROLADA DE MÉTRICAS DEL PILOTO A/B/C

**FECHA:** 2026-08-14
**ALCANCE:** Separar resultado SMTP inmutable de estado operativo, fuente única de métricas (`inc/metricas.php`), endpoint de métricas del piloto, y tests. Sin IMAP/click/IA. Sin envíos reales.

---

## 1. Auditoría final de escritores/lectores (FASE 5B.1)
Escritores de `envios.estado`:
- `inc/eligibilidad.php` `reservarEnvioLogico()` → `'pendiente'` (reserva).
- `api/enviar_lote.php` → `'enviado'`/`'error'` (resultado SMTP).
- `cli/cron.php` → `'enviado'`/`'error'` (resultado SMTP).
- `api/track.php` → `'enviado'`→`'abierto'` (apertura; MUTADOR problemático).
- `api/enviar_smtp_random.php` (P2) DESACTIVADO.
Lectores: dashboard (total enviados/aperturas), track, cron (anti-reedición), P2 (desactivado). No hay otro escritor de resultado SMTP.

## 2. Propuesta de migración (FASE 5B.2)
Añadir `envios.resultado_envio` (ACCEPTED/FAILED) y `envios.fecha_resultado_envio`, separados de `estado`. Apertura/clasificación/retry no modifican `resultado_envio`.

## 3. Backup y migración (FASE 5B.3)
- Backup: `public_html/outbound/backups/fase5b_pre_20260814_222611.db` (16 tablas, envios=2).
- Migración: ALTER ADD `resultado_envio`, `fecha_resultado_envio`. Filas legacy quedan NULL (documentado; no se rellenan).

## 4. Implementación resultado_envio (FASE 5B.4)
- P1 (`enviar_lote.php`): al resultado SMTP fija `resultado_envio = ACCEPTED|FAILED` + `fecha_resultado_envio`.
- P3 (`cron.php`): mismo comportamiento.
- `track.php` NO se modifica (sigue actualizando `estado`, que ya no es fuente de "aceptados").

## 5. Fuente única de métricas (FASE 5B.5)
- `inc/metricas.php` → `calcularMetricas(SQLite3, campaignId)`.
- Fuentes: `envios` (resultado_envio para aceptados, variant para A/B/C, campaign_id), `respuestas` (envio_id + clasificacion), `aperturas` (tracking_id).
- Excluye envíos con `variant IS NULL` (legacy) y con `campaign_id` distinto.
- No usa `lead_pipelines`, `estado_lead`, email como atribución ni "último envío".

## 6. Sustitución en dashboard (FASE 5B.6)
- `dashboard.php`: `require inc/metricas.php` + endpoint `get_piloto_metricas` (llama a `calcularMetricas`). No se duplica SQL de métricas. El dashboard legacy (`get_analytics`) permanece intacto (no se modifica ahora).

## 7. Tests aislados (FASE 5B.7)
Harness `scripts/_test_fase5b.php` (copia temporal, sin SMTP):
| Test | Resultado |
|---|---|
| T1 ACCEPTED cuenta | PASS |
| T2 FAILED no cuenta | PASS |
| T3 apertura no elimina ACCEPTED | PASS |
| T4 apertura no modifica resultado_envio | PASS |
| T5 retry conserva resultado | PASS |
| T6 POSITIVE cuenta | PASS |
| T7 NEGATIVE no positiva | PASS |
| T8 NEUTRAL no | PASS |
| T9 OOO no | PASS |
| T10 UNSUBSCRIBE no | PASS |
| T11 PENDING no positiva | PASS |
| T12 A/B/C por variant | PASS |
| T13 campaign_id filtra | PASS |
| T14 legacy sin campaign no entra | PASS |
| T15 legacy sin variant no entra | PASS |
| T16 aperturas por tracking_id | PASS |
| T17 respuestas por envio_id | PASS |
| T18 email en campañas distintas no mezcla | PASS |
| T19 estado abierto no altera aceptados | PASS |
| T20 dashboard usa fuente central | PASS |

**RESUMEN: 20/20 PASS.**

## 8. Regresión completa (FASE 5B.8)
- FASE 2B: 9/9.
- FASE 2C: 12/12.
- FASE 3A: 23/23.
- FASE 4B: 15/15.
- FASE 4C: 15/15.
- BD real intacta (envios=2, respuestas=0).

Nota: se corrigió el harness `_test_fase2c.php` para copiar las nuevas dependencias (`abc.php`, `respuestas.php`, `metricas.php`) a su estructura temporal; no es un defecto de producto.

## 9. Auditoría final (FASE 5B.9)
- El denominador "Aceptados SMTP" ahora se calcula con `resultado_envio='ACCEPTED'` (inmutable), no con `estado`.
- La comparativa A/B/C usa `envios.variant`, no `lead_pipelines`.
- Las respuestas se contabilizan por `envio_id` y se clasifican por `respuestas.clasificacion`.

## 10. Archivos/BD modificados
- BD: `envios.resultado_envio`, `envios.fecha_resultado_envio` (legacy NULL).
- `inc/metricas.php` (nuevo), `api/enviar_lote.php`, `cli/cron.php`, `dashboard.php` (require + endpoint).
- `scripts/_test_fase5b.php` (nuevo), `scripts/_test_fase2c.php` (corrección de harness).

## 11. Limitaciones / riesgos residuales
1. `resultado_envio` solo lo escriben P1/P3 a partir de ahora; envíos legacy tienen NULL (no rellenados).
2. Open Rate es una señal subordinada (píxel; no lectura real).
3. No hay significancia estadística ni umbral de "ganadora" (fuera de alcance).
4. El dashboard legacy (`get_analytics`) sigue usando lead_pipelines/estado_lead; NO se sustituyó en esta fase (la fuente central existe y el nuevo endpoint `get_piloto_metricas` es la vía para el piloto).

## 12. Documentación
- `docs/checkpoint_fase5a_auditoria_metricas.md`
- `docs/checkpoint_fase5b_implementacion.md`

---

## ESTADO DE FASE: PASS

> NO avanzo a la siguiente fase. No realicé envíos. No cambié motor/estados/campañas. Detenido a espera de aprobación.