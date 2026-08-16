# CHECKPOINT — FASE 6A PRE-IMPLEMENTACIÓN

## 1. Objetivo
Cerrar el defecto detectado en FASE 5E: exigir `campaign_id` válido en P1 (`api/enviar_lote.php`) e integrar el envío de `campaign_id` desde la UI (lanzadera). Garantizar trazabilidad LEAD → CAMPAÑA → VARIANTE sin fallback automático de campaña.

## 2. Estado actual
- `api/enviar_lote.php` lee `campaign_id` (default 0), pero solo aplica entorno/variante si `> 0`; no valida estado/activo y no rechaza ausencia.
- `js/app.js` `enviarCorreoPrueba()` e `iniciarMotor()` NO envían `campaign_id`.
- P2 desactivado. P3 ya exige y valida campaña.

## 3. Archivos inspeccionados
`js/app.js`, `tabs/lanzadera.php`, `api/enviar_lote.php`, `cli/cron.php`, `inc/eligibilidad.php`, `inc/abc.php`, `inc/respuestas.php`, `inc/metricas.php`, `dashboard.php`.

## 4. Flujo actual de P1
```
UI (enviarCorreoPrueba/iniciarMotor) → FormData sin campaign_id
→ api/enviar_lote.php (campaign_id=0)
→ reserva (reservarEnvioLogico)
→ SMTP
```

## 5. Punto exacto donde se pierde campaign_id
El `FormData` de la UI no incluye `campaign_id`; `enviar_lote.php` lo interpreta como 0 y no levanta bloqueo.

## 6. Validación actual de campaña
- P3 (`cron.php`): `--campaign-id` obligatorio; valida existencia, `estado IN (PILOT,ACTIVE)`, `activo=1`, y `esEntornoCoherente()`.
- P1: no valida estado/activo; solo `esEntornoCoherente()` condicionado a `campaign_id>0`.

## 7. Política existente reutilizable
`esEntornoCoherente(campaignEntorno, modoEntorno)` (en `inc/abc.php`) y el patrón de validación de estado/activo ya implementado en `cli/cron.php` (P3). Se reutiliza el mismo criterio.

## 8. Flujo actual de P3
CLI `--campaign-id` → validación `pipelines.id` → estado permitido + activo=1 → coherencia entorno → elegibilidad → reserva → SMTP → resultado_envio.

## 9. Flujo de retry
`reservarEnvioLogico()` con índice único parcial `(lead_id, campaign_id)`: el retry reutiliza la MISMA fila, conservando `variant`, `message_id`, `asunto`, `cuerpo_mensaje`.

## 10. Riesgo técnico
Un envío P1 sin campaign_id quedaría con `envios.campaign_id=NULL` / `variant=NULL`, rompiendo la trazabilidad y las métricas A/B/C.

## 11. Cambios mínimos necesarios
1. P1: exigir y validar `campaign_id` (rechazo antes de reserva/SMTP), reutilizando `esEntornoCoherente` + patrón P3.
2. UI: selector de campaña en la lanzadera + transmitir `campaign_id` en `enviarCorreoPrueba()` e `iniciarMotor()`, bloqueando si no hay campaña.
3. No alterar A/B/C, Message-ID, snapshot ni resultado_envio.

## 12. Archivos que serán modificados
- `api/enviar_lote.php`
- `js/app.js`
- `tabs/lanzadera.php`

## 13. Archivos que NO deben modificarse
`cli/cron.php`, `inc/abc.php`, `inc/eligibilidad.php`, `inc/respuestas.php`, `inc/metricas.php`, `api/enviar_smtp_random.php`, `track.php`, tabla BD.

## 14. Tests que deberán ejecutarse
Harness `scripts/_test_fase6a.php` (T1–T32) + regresión completa (2B/2C/3A/4B/4C/5B/5C E2E).

## 15. Conclusión
Cambio mínimo y localizado: backend P1 + integración UI. No se modifica política de negocio. Procedo a implementar.