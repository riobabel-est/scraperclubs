# CHECKPOINT — FASE 5D PRE-IMPLEMENTACIÓN (análisis)

**FECHA:** 2026-08-14
**OBJETIVO:** Conectar el Analytics visible con `get_piloto_metricas`, eliminando la dependencia de `get_analytics` legacy para el piloto.

---

## Evidencia del bloqueante (verificado en código)
- `tabs/analytics.php` usa `x-data="analyticsApp()"` → `get_analytics?tab=dashboard` (legacy).
- `js/app.js` define `analyticsApp()` con `abc`, `abc_ganadora`, `get abcFilas`, y consume `get_analytics`.
- `dashboard.php`:
  - `get_analytics` (legacy) usa `lead_pipelines`, `stageOrder` (estado_lead), `abc_ganadora` con `leads >= 5`.
  - `get_piloto_metricas` (nuevo) llama a `calcularMetricas()`. **No consumido por la UI.**
- El modal "Analytics" de los scorecards (`abrirAnalytics`) usa `get_analytics&tab=envios|aperturas|rebotes|bajas` (conteos operativos, NO A/B/C). Fuera del alcance del piloto; se conservan.

## Alcance exacto de cambio
- Reemplazar SOLO el contenido de la pestaña Analytics (`tabs/analytics.php` + su componente JS) para que consuma:
  1. `get_piloto_campanas` (nuevo, listado de campañas con estado/entorno/identificador) → selector.
  2. `get_piloto_metricas?campaign_id=N` → `calcularMetricas()`.
- NO tocar el resto del CRM. NO duplicar SQL de métricas. NO tocar tracking/SMTP/A/B/C/campañas/supresión.

## Decisiones
1. **Selector de campaña explícito**: nuevo endpoint `get_piloto_campanas` lista `id, nombre, identificador, estado, entorno`. No auto-seleccionar campaña. Si no hay selección → "NO HAY CAMPAÑA SELECCIONADA".
2. **Ambigüedad test+ACTIVE**: se muestra `estado` y `entorno` de la campaña; la UI NO declara "PILOT válido" por sí sola. La validez real la determinan P1/P3 con `esEntornoCoherente`. Si el usuario selecciona una campaña `entorno=test` y `estado=ACTIVE`, se mostrará una advertencia "entorno incoherente para piloto" (sin inventar política; se documenta). El caso se mantiene como observación, no como bloqueo de renderizado.
3. **Sin ganadora**: se muestra "Mayor PRR observado: X" descriptivo, nunca "Ganadora".
4. **PENDING** separado de POSITIVE y de respuestas totales.

## Estructura de datos disponible de `calcularMetricas()`
`ok`, `campaña{id,nombre,identificador,estado,entorno}`, `aceptados`, `aperturas_totales`, `abiertos_unicos`, `respuestas`, `positive`, `negative`, `neutral`, `unsubscribe`, `ooo`, `pending`, `variantes{A/B/C}` con `envios, aceptados, aperturas, respuestas, positivas, negativas, neutrales, unsubscribe, ooo, prr`.

## Pruebas previstas
- End-to-end con dataset sintético (reutilizando `_test_fase5c_e2e.php`).
- Test explícito: Analytics → get_piloto_metricas (inspección de referencias).
- Regresión completa FASE 2B..5C.
- Verificar que la UI no permite editar campos de trazabilidad (solo lectura).

## Archivos a modificar (propuesto)
- `dashboard.php` (nuevo endpoint `get_piloto_campanas`).
- `tabs/analytics.php` (nueva vista piloto).
- `js/app.js` (nuevo componente `pilotoAnalyticsApp`; el viejo `analyticsApp` queda sin uso en la pestaña).
- Harness de verificación (puede ser por inspección/script).

> No se implementa nada hasta completar este análisis. A continuación ejecuto la implementación mínima aprobada por FASE 5D.