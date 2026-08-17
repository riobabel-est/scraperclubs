# Checkpoint — GO-LIVE UNSUBSCRIBE (Flujo de Baja con Confirmación)

**Fecha:** 17/08/2026
**Estado:** ✅ GO_LIVE_UNSUBSCRIBE_PASS (validado en local, SIN deploy)

---

## Objetivo

Rediseñar el flujo de baja/opt-out para cumplir RGPD/LOPDGDD con confirmación
explícita, sin romper el envío multipart/alternative (texto_plano + tracking) ya
implementado.

## Cambios realizados

### 1. `public_html/outbound/api/baja.php` (rediseño completo)

- **Confirmación explícita:** el primer GET del enlace NO ejecuta la baja; solo
  muestra una página de confirmación clara.
- **Baja efectiva SOLO por POST** (`accion=confirmar`).
- **Motivo opcional** (nunca condición para completar la baja).
- **Idempotente:** confirmar dos veces no duplica ni reactiva.
- **Identificación segura:**
  - Nuevo enlace: `baja.php?t=TOKEN` (TOKEN = tracking_id del envío, no expone email).
  - Compatibilidad: `baja.php?email=EMAIL` (enlaces antiguos siguen funcionando).
- **Registro CRM:** marca `estado_lead = 'Lista Negra'` (mecanismo de supresión
  existente, ya bloqueado por `esElegibleParaEnvio`) y registra historial
  (fecha, fuente, campaign_id, envio_id, motivo) en `observaciones`.
- **Seguridad:** POST + CSRF (HMAC), token, SQLi (prepared statements), XSS (escapado).
- **Fix crítico:** `SQLite3::querySingle()` NO soporta named parameters (`:t`, `:e`).
  Se reemplazó por `prepare()` + `bindValue()` + `execute()` + `fetchArray()` en
  `resolverDestinatario()` y en la consulta de email en `ejecutarBaja()`.

### 2. `public_html/outbound/inc/mime.php` (sin cambios — ya correcto)

- `multipart/alternative` para `texto_plano` (text/plain + text/html).
- Píxel de tracking SOLO en text/html.
- Enlace de baja convertido a `<a>` simple en HTML.
- Plantillas `html` mantienen comportamiento histórico.

### 3. `public_html/outbound/api/enviar_lote.php` (sin cambios — ya correcto)

- Genera `bajaUrlToken = ...baja.php?t={trackingId}` y sustituye el `?email=` antiguo.
- `plainPart = cuerpo` (texto original con saltos).
- `htmlPart = convertirContenidoAHtml(cuerpo, TRACK_URL, trackingId)`.

## Tests ejecutados

### `scripts/test_baja_flow.php` — 17/17 PASS → GO_LIVE_UNSUBSCRIBE_PASS

| Test | Resultado |
|------|-----------|
| TEST 1: GET no modifica BD | ✅ |
| TEST 2: Muestra confirmación | ✅ |
| TEST 3: Cancelar no modifica BD | ✅ |
| TEST 4: Confirmar registra baja | ✅ |
| TEST 4b: Muestra "Baja realizada" | ✅ |
| TEST 5: Confirmar dos veces idempotente | ✅ |
| TEST 5b: Muestra "ya estabas dado de baja" | ✅ |
| TEST 6: Motivo registrado | ✅ |
| TEST 7: Motivo omitido → baja registrada | ✅ |
| TEST 7b: Motivo omitido → sin motivo | ✅ |
| TEST 8a: Token inválido rechazado | ✅ |
| TEST 8b: CSRF incorrecto rechazado | ✅ |
| TEST 9: Lead dado de baja no elegible | ✅ |
| TEST 9b: Lead normal sigue elegible | ✅ |
| TEST 10a: GET ?email= muestra confirmación | ✅ |
| TEST 10b: GET ?email= no modifica BD | ✅ |
| TEST 10c: POST ?email= registra baja | ✅ |

### `scripts/test_mime_plaintext_tracking.php` — 43/43 PASS → PLAINTEXT_TRACKING_MIME_PASS

Regresión del envío multipart/alternative (A/B/C, placeholders, tracking, baja).

### Sintaxis (php -l)

- `baja.php` ✅
- `enviar_lote.php` ✅
- `mime.php` ✅
- `eligibilidad.php` ✅

## Regresión de elegibilidad

- `esElegibleParaEnvio` bloquea correctamente un lead dado de baja (`razon=supresion`).
- Un lead normal sigue siendo elegible (`razon=elegible`).
- El aislamiento TEST/REAL (FASE 6F.6) se preserva intacto.

## Veredicto

```
GO_LIVE_UNSUBSCRIBE_PASS
```

## Pendiente (NO ejecutado — esperando validación)

- **NO** se ha hecho deploy a producción.
- **NO** se ha enviado ningún email.
- **NO** se ha modificado la BD real.
- **NO** se ha realizado prueba SMTP.
- **NO** se ha hecho `git push`.

## Archivos modificados

- `public_html/outbound/api/baja.php` (rediseño + fix prepared statements)
- `scripts/test_baja_flow.php` (nuevo, tests del flujo de baja)

## Archivos verificados (sin cambios)

- `public_html/outbound/inc/mime.php`
- `public_html/outbound/api/enviar_lote.php`
- `public_html/outbound/inc/eligibilidad.php`
