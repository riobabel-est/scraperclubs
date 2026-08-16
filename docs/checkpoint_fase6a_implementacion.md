# CHECKPOINT — FASE 6A IMPLEMENTACIÓN

## 1. Objetivo
Cerrar el defecto de FASE 5E: hacer que P1 exija `campaign_id` válido y trazable, e integrar la transmisión explícita de `campaign_id` desde la lanzadera (UI).

## 2. Estado inicial
- P1 no exigía ni validaba `campaign_id`; la lanzadera no lo enviaba.
- P3 ya exigía y validaba campaña (inline en `cron.php`).
- Política de coherencia de entorno existía en `esEntornoCoherente()`.

## 3. Hallazgo de FASE 5E
P1 podía enviar sin `campaign_id`, dejando `envios.campaign_id/variant` en NULL y rompiendo trazabilidad/trazabilidad A/B/C.

## 4. Auditoría realizada
Inspeccionados: `js/app.js`, `tabs/lanzadera.php`, `api/enviar_lote.php`, `cli/cron.php`, `inc/eligibilidad.php`, `inc/abc.php`, `inc/respuestas.php`, `inc/metricas.php`, `dashboard.php`. Confirmadas las llamadas a `api/enviar_lote.php` desde `enviarCorreoPrueba()` e `iniciarMotor()`, sin `campaign_id`.

## 5. Archivos modificados
- `inc/abc.php` — nueva función central `validarCampanaActiva()`.
- `api/enviar_lote.php` — exige `campaign_id`, valida con la función central, variante determinística.
- `cli/cron.php` — reutiliza `validarCampanaActiva()` (elimina validación inline).
- `js/app.js` — selector de campaña + transmisión de `campaign_id` en `enviarCorreoPrueba()` e `iniciarMotor()`, con bloqueo sin campaña.
- `tabs/lanzadera.php` — campo selector "0. Seleccionar Campaña *".

## 6. Cambios realizados
- P1 rechaza `campaign_id` ausente/0/negativo/inexistente/no activo/incoherente antes de reserva/SMTP.
- UI transmite `campaign_id` y bloquea el envío si no hay campaña seleccionada ("Selecciona una campaña antes de enviar.").
- Sin fallback automático; sin hardcodeo; sin auto-selección.

## 7. Política de campaña reutilizada
`validarCampanaActiva()` (en `inc/abc.php`) centraliza: `NO_CAMPAIGN`, `INVALID_CAMPAIGN`, `CAMPAIGN_NOT_ACTIVE` (estados PILOT/ACTIVE + activo=1), `ENVIRONMENT_MISMATCH` (via `esEntornoCoherente`). Reutilizada por P1 y P3.

## 8. Protección P1
Rechazo ANTES de: reservar, modificar lead, enviar SMTP. Sin ningún fallback.

## 9. Integración js/app.js
- `bootLanzadera()` carga `get_piloto_campanas`.
- `enviarCorreoPrueba()` e `iniciarMotor()` validan y envían `campaign_id`.
- Selector en `tabs/lanzadera.php`.

## 10. Trazabilidad final
Reserva válida contiene: `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`, `message_id`, `snapshot` (asunto/cuerpo). Verificado en tests T11–T17.

## 11. Retry
Conserva `campaign_id`, `variant`, `message_id`, `snapshot`; no crea segundo envío (índice único). Verificado T18–T21/T21b.

## 12. Tests FASE 6A
Harness `scripts/_test_fase6a.php` (33 tests):
| TEST | RESULTADO |
|---|---|
| T1–T3 (sin/negativo/inexistente) | PASS |
| T4–T8 (DRAFT/READY/PAUSED/COMPLETED/ARCHIVED) | PASS |
| T9 entorno incoherente | PASS |
| T10 campaña válida | PASS |
| T11–T17 trazabilidad | PASS |
| T18–T21/T21b retry | PASS |
| T22–T26 UI/campaign_id | PASS |
| T27 P2 bloqueado | PASS |
| T28–T31 regresión funcional | PASS |
| T32 P3 exige campaign-id | PASS |

## 13. Regresión completa
| FASE | RESULTADO |
|---|---|
| 2B | 9/9 PASS |
| 2C | 12/12 PASS |
| 3A | 23/23 PASS |
| 4B | 15/15 PASS |
| 4C | 15/15 PASS |
| 5B | 20/20 PASS |
| 5C E2E | 11/11 PASS |
| 6A | 33/33 PASS |

## 14. Comprobaciones de seguridad
1-20 del checklist cumplidas: P1 exige/rechaza/valida campaign_id sin fallback; JS transmite campaign_id; sin hardcodeo; P2 bloqueado; P3 protegido; A/B/C/Message-ID/snapshot/resultado_envio no alterados; Analytics/respuestas/supresión intactas; sin camino alternativo que salte campaign_id.

## 15. Estado de BD
Productiva intacta: envios=2, respuestas=0. Sin datos de prueba.

## 16. Envíos reales
NO SE REALIZARON ENVÍOS REALES.

## 17. Estado del motor
motor_estado = pausado

## 18. Estado del entorno
modo_entorno = test

## 19. Campaña piloto
NO CREADA.

## 20. Riesgos residuales
- La lanzadera solo podrá usarse si existe y se selecciona una campaña PILOT/ACTIVE coherente (hoy solo existe la TEST DRAFT).
- `get_analytics` legacy permace en `js/app.js` y `dashboard.php` sin uso en la pestaña Analytics (código muerto/vías operativas de scorecards); no bloquea.

## 21. Hallazgos fuera de alcance
Ninguno nuevo que requiera acción en esta fase.

## 22. Veredicto final
PASS

> El bloqueo de FASE 5E queda resuelto: P1 exige `campaign_id` y la trazabilidad LEAD → CAMPAÑA → VARIANTE queda garantizada. NO avanzar a FASE 6B; no crear campaña piloto; no activar motor; no cambiar entorno; no envíos.