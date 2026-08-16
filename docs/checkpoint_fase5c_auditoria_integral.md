# CHECKPOINT — FASE 5C: AUDITORÍA INTEGRAL DE CIERRE Y VALIDACIÓN END-TO-END

**FECHA:** 2026-08-14
**ALCANCE:** Auditoría integral post-FASE 5B. Sin implementar funcionalidades nuevas. Sin envíos. Verificación de READY FOR PILOT.

---

## 1. Estado inicial
Tras FASE 5B: trazabilidad (lead/campaign/variant/template/smtp/message_id) implementada, resultado_envio inmutable, fuente central de métricas `inc/metricas.php`, panel de respuestas y clasificación. BD real: 2 envíos legacy, 0 aperturas, 0 respuestas, 1 pipeline TEST (DRAFT/test), motor pausado.

## 2. Archivos inspeccionados
`dashboard.php`, `js/app.js`, `tabs/analytics.php`, `tabs/respuestas.php`, `inc/metricas.php`, `inc/respuestas.php`, `inc/eligibilidad.php`, `inc/abc.php`, `api/enviar_lote.php`, `cli/cron.php`, `api/track.php`, `api/baja.php`.

## 3. Tablas/columnas inspeccionadas
`envios` (resultado_envio, fecha_resultado_envio, lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id, tracking_id), `respuestas`, `aperturas`, `pipelines`, `clubes_crm`, `config`.

## 4. Flujo completo auditado
P1/P3 → reservarEnvioLogico (variant determinista + message_id + snapshot) → SMTP → resultado_envio ACCEPTED/FAILED → píxel (aperturas) → respuesta (envio_id) → clasificación → métricas (envios+respuestas+aperturas).

## 5. Hallazgos
- Trazabilidad completa sin email como atribución: **PASS** (salvo dashboard legacy, ver §8).
- A/B/C determinístico e inmutable: **PASS** (verificado en FASE 3A).
- SMTP: resultado_envio separado de estado; apertura no lo altera: **PASS** (FASE 5B).
- Supresión: Lista Negra única (baja.php + UNSUBSCRIBE); P1/P3 la respetan; P2 desactivado: **PASS**.
- Respuestas: Message-ID persistente, correlación por In-Reply-To/References, idempotencia: **PASS** (FASE 4C).
- Clasificación: 6 valores, reglas correctas, reclasificación no altera envío: **PASS**.
- Métricas: fuente única correcta, end-to-end exacta: **PASS** (FASE 5C E2E).
- **Dashboard VISIBLE: BLOCKER** — la interfaz sigue usando `get_analytics` legacy (lead_pipelines, estado_lead, `abc_ganadora` con umbral `>=5`). El endpoint nuevo `get_piloto_metricas` NO está conectado a la UI.

## 6. Discrepancias documentación vs código
1. `js/app.js` y `tabs/analytics.php` NO referencian `get_piloto_metricas`/`calcularMetricas` (0 coincidencias en JS).
2. `dashboard.php` aún contiene el bloque `get_analytics` legacy con `lead_pipelines`/`stageOrder`/`abc_ganadora`.
3. El checkpoint FASE 5B afirmó "dashboard usa fuente central" en el sentido de que existe el endpoint, pero la **interfaz visible no lo consume** (esto es una limitación real, no cubierta).

## 7. Riesgos
- Un usuario viendo el dashboard A/B/C actual tomaría decisiones con datos incorrectos (lead_pipelines/estado_lead) aunque el backend nuevo esté correcto.
- Open Rate está sujeto a bloqueo de píxeles/privacidad (documentado), no define interés.

## 8. Bloqueantes
1. **Dashboard visible no consume la fuente nueva** → BLOCKER / NOT READY FOR PILOT (per requisito H).
2. Ambigüedad `entorno=test`+`estado=ACTIVE` documentada (FASE 2C/3A) sigue sin política explícita en la UI que muestre el contexto del piloto.

## 9. No bloqueantes
- IMAP, click tracking, IA, significancia estadística, revenue attribution (post-piloto).

## 10. Pruebas realizadas
- Regresión: FASE 2B 9/9, 2C 12/12, 3A 23/23, 4B 15/15, 4C 15/15, 5B 20/20.
- E2E en copia temporal (`scripts/_test_fase5c_e2e.php`): 11/11 — reproduce exactamente el dataset sintético (A 22.2%, B 44.4%, C 11.1%).
- BD real intacta (envios=2, respuestas=0).

## 11. Resultado PASS/FAIL/NOT VERIFIED
- Trazabilidad, A/B/C, SMTP, supresión, respuestas, clasificación, métricas (backend): **PASS**.
- Dashboard visible (métricas reales para el usuario): **FAIL / NOT READY FOR PILOT**.
- Entorno test+ACTIVE (política): **NOT VERIFIED** (pendiente decisión).

## 12. Recomendación final
**NOT READY FOR PILOT.**

El modelo de datos y el backend de métricas están correctos, pero **el análisis del panel no está conectado a la nueva fuente** (`get_piloto_metricas`). Debe implementarse una interfaz que consuma exclusivamente `get_piloto_metricas` para A/B/C, sin `lead_pipelines` ni `estado_lead`, antes de autorizar el piloto. No se debe mezclar el análisis legacy con el nuevo.

> No modifiqué el dashboard en esta fase (regla anti-dispersión). Espero decisión explícita para una FASE 5D: sustituir el análisis visible por `get_piloto_metricas`.