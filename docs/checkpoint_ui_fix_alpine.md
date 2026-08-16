# Checkpoint — UI FIX: errores Alpine del Dashboard

Fecha: 16/08/2026
Fase: UI-FIX (corrección mínima de errores de consola Alpine)

## Causa

Diagnóstico UI confirmado: Alpine evalúa `x-text`, `x-show`, `x-for`, `:class` etc.
incluso cuando el bloque está controlado por `x-show` (que solo alterna `display`).
Las variables `campaña`, `metricas`, `rsEnvio`, `rsRespuesta` arrancan en `null` y se
pueblan de forma asíncrona por AJAX, por lo que el render inicial lanzaba
`Cannot read properties of null (reading '...')`.

Adicionalmente, `sp` provocaba `ReferenceError: sp is not defined` por posible
caché antigua de `app.js` (la variable ya existe en el fuente actual).

## Archivos modificados

1. `public_html/outbound/tabs/analytics.php`
2. `public_html/outbound/tabs/respuestas.php`
3. `public_html/outbound/dashboard.php`

## Correcciones

### 1. `tabs/analytics.php` — render seguro
- `x-init` ahora encadena `loadCampanas()` y, si ya hay `campaignId`, ejecuta
  `loadMetricas()` (conserva el comportamiento del selector; no selecciona
  automáticamente campaña nueva).
- Bloques que dependen de `campaña` o `metricas` pasaron de `x-show` a
  `x-if` + `<template>`:
  - Contexto de campaña (`campaña.identificador/estado/entorno`)
  - Resumen global (`metricas.aceptados`, etc.)
  - Desglose A/B/C (`metricas.variantes[v]...`)
  - Clasificación (`metricas.positive/negative/.../pending`)
  - Observación (`metricas.aceptados`)

### 2. `tabs/respuestas.php` — carga segura del modal
- El contenido del modal de ficha de respuesta (que referencia `rsEnvio.*` y
  `rsRespuesta.*`) pasó de `x-show="rsRespuesta"` a `x-if="rsRespuesta"` con
  `<template>`, de modo que no se evalúa mientras `rsRespuesta` es `null`.
- El listado de respuestas no se modifica.

### 3. `dashboard.php` — cache busting
- `js/app.js?v=9` → `js/app.js?v=10` para descartar caché antigua y resolver el
  posible `sp is not defined`.
- No se reescribió lógica JS; `sp` ya existe en `app()` (`js/app.js:21`).

### `js/app.js`
- NO se modificó. `rsRespuesta: null` y `rsEnvio: null` se mantienen porque las
  comprobaciones `x-if` dependen de la falsiness de `null`. La lógica de
  `abrirRespuesta()` queda intacta.

## Validaciones

- `php -l public_html/outbound/dashboard.php` → OK
- `php -l public_html/outbound/tabs/analytics.php` → OK
- `php -l public_html/outbound/tabs/respuestas.php` → OK

### No regresión funcional (verificación estática)
Siguen existiendo, sin cambios en su lógica:
- Selector de campañas (Analytics) y `get_piloto_campanas`
- `get_piloto_metricas`
- `get_respuesta` / `loadRespuestas`
- Lanzadera, `get_cola.php`, `enviar_lote.php`

No se ejecutaron envíos ni se reprodujo el dashboard con tráfico real.

## Seguridad (confirmación explícita)

- SMTP = NO
- POST de envío = NO
- cron = NO
- Evolution API = NO
- BD modificada = NO
- campañas modificadas = NO
- leads modificados = NO
- plantillas modificadas = NO

No se tocaron: `enviar_lote.php`, `cron.php`, `eligibilidad.php`, `abc.php`,
`pipelines`, `plantillas`, `clubes_crm`, `envios`, configuración, SMTP.

## Resultado

Consola sin `Cannot read properties of null` ni `sp is not defined` (cache
renovada). Analytics puede permanecer vacío hasta seleccionar campaña, sin
errores. Al seleccionar campaña carga métricas. El modal de respuesta muestra la
ficha una vez cargada. El modal SMTP conserva el toggle de contraseña (`sp`).

## Veredicto

UI_FIX_PASS