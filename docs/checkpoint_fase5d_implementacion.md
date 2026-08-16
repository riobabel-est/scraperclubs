# CHECKPOINT — FASE 5D: SUSTITUCIÓN CONTROLADA DEL ANALYTICS LEGACY

**FECHA:** 2026-08-14
**OBJETIVO:** Conectar la interfaz visible de Analytics exclusivamente con la fuente de métricas del piloto (`get_piloto_campanas` + `get_piloto_metricas` → `inc/metricas.php`), eliminando de la vista del piloto cualquier dependencia de `lead_pipelines`/`estado_lead`/`stageOrder`/`abc_ganadora`.

---

## 1. Estado inicial real detectado
- `dashboard.php` ya contenía `get_piloto_metricas` (FASE 5B) pero la UI no lo consumía.
- `tabs/analytics.php` usaba `x-data="analyticsApp()"` legacy (lead_pipelines/estado_lead/abc_ganadora).
- `js/app.js` contenía `analyticsApp()` legacy como componente de la pestaña.
- `get_piloto_campanas` NO existía.

## 2. Archivos modificados
- `public_html/outbound/dashboard.php` — añadido endpoint `get_piloto_campanas` (lista `id, nombre, identificador, estado, entorno, activo`).
- `public_html/outbound/tabs/analytics.php` — reescrito: consume `get_piloto_campanas` (selector) + `get_piloto_metricas` (métricas). Incluye componente JS `pilotoAnalyticsApp()` inline.
- No se modificaron P1/P2/P3/A-B-C/eligibilidad/respuestas/abc/tracking/IMAP/click/IA/SMTP/supresión/BD.

## 3. Cambios realizados
- Selector de campaña explícito (sin auto-selección). Si no hay selección → "NO HAY CAMPAÑA SELECCIONADA".
- Contexto de campaña: identificador, estado, entorno (con advertencia si `entorno=test` + `estado=ACTIVE`, sin inventar política).
- Resumen global: Aceptados SMTP, Aperturas únicas, Respuestas, Positive Reply Rate, Open Rate, Reply Rate (con numerador/denominador en el desglose).
- Desglose A/B/C desde `envios.variant`: aceptados, aperturas, respuestas, positivas, negativas, neutrales, unsubscribe, ooo, PRR.
- Clasificación con contadores POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO/PENDING.
- Sin "ganadora"; se muestra "Mayor PRR observado actualmente: X" (descriptivo). Si muestra == 0 → "OBSERVACIÓN INSUFICIENTE".

## 4. Endpoints utilizados
- `?action=get_piloto_campanas` → selector de campañas.
- `?action=get_piloto_metricas&campaign_id=N` → `calcularMetricas()` (única fuente).

## 5. Fuente única de métricas
`inc/metricas.php::calcularMetricas()` usando `envios` (`variant`, `campaign_id`, `resultado_envio`), `respuestas` (`envio_id`, `clasificacion`), `aperturas` (`tracking_id`). Sin SQL duplicado en la vista.

## 6. Comprobaciones de prohibiciones (analytics.php visible)
- `get_analytics`: 0
- `lead_pipelines`: 0
- `estado_lead`: 0
- `stageOrder`: 0
- `abc_ganadora`: 0

## 7. Tests ejecutados y resultado
- Test específico 5D (por inspección): Analytics visible consume `get_piloto_campanas` y `get_piloto_metricas`; no contiene prohibiciones. PASS.
- `analyticsApp()` legacy queda como definición muerta en `js/app.js` (sin `x-data` vivo); no alimenta la pestaña. Documentado.

## 8. Regresión
- FASE 2B: 9/9 PASS.
- FASE 2C: 12/12 PASS.
- FASE 3A: 23/23 PASS.
- FASE 4B: 15/15 PASS.
- FASE 4C: 15/15 PASS.
- FASE 5B: 20/20 PASS.

## 9. E2E
- FASE 5C E2E: 11/11 PASS (reproduce A 22.2%, B 44.4%, C 11.1%).

## 10. Sintaxis
- PHP: `dashboard.php`, `tabs/analytics.php` sin errores.
- JS: `js/app.js` y el script inline de analytics OK.

## 11. Limitaciones residuales
- `get_analytics` legacy permanece en `dashboard.php` y `js/app.js` como código no usado por la pestaña Analytics del piloto (los scorecards operativos envios/aperturas/rebotes/bajas usan `abrirAnalytics`, que es de conteos operativos, fuera del alcance A/B/C). No se eliminó para no dispersar.
- `analyticsApp()` legacy sigue definido en `js/app.js` sin uso activo en la vista (código muerto); pendiente de limpieza opcional (FUTURE_IMPROVEMENTS).

---

## ESTADO DE FASE: PASS

> NO avanzo a FASE 6. No realicé envíos. Detenido a espera de aprobación.

---

## FUTURE_IMPROVEMENTS (registradas, no implementadas)
- Retirar el código legacy `analyticsApp()` y el bloque `get_analytics` de A/B/C si se confirma que ninguna otra vista lo usa.