# Checkpoint — GO-LIVE UNSUBSCRIBE (Flujo de Baja con Confirmación)

**Fecha:** 17/08/2026
**Estado:** ✅ GO_LIVE_UNSUBSCRIBE_PRODUCTION_PASS (deploy + prueba real controlada en producción)

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

## Veredicto local (pre-deploy)

```
GO_LIVE_UNSUBSCRIBE_PASS
```

---

# GO-LIVE PRODUCCIÓN — DEPLOY Y PRUEBA REAL CONTROLADA

**Fecha deploy:** 17/08/2026 18:16 (Europe/Madrid)
**Estado final:** ✅ GO_LIVE_UNSUBSCRIBE_PRODUCTION_PASS

## 1. Pre-flight local

- `git status --short` / `git rev-parse HEAD` ✅
- `php -l` baja.php / enviar_lote.php / mime.php / eligibilidad.php ✅
- `scripts/test_baja_flow.php` → **17/17 PASS** ✅
- `scripts/test_mime_plaintext_tracking.php` → **43/43 PASS** ✅

## 2. Git

- Commit: `fix: harden unsubscribe confirmation flow`
- Hash: `7acd5fbf473b3b52524d9c759276b50cfd706740`
- Push: `git push origin main` (SIN `--force`) ✅
- `origin/main = HEAD` ✅
- Working tree limpio salvo exclusiones conocidas (backups_deploy/, tmp_*, scripts de verificación, etc.) ✅
- Archivos incluidos en commit: `baja.php`, `test_baja_flow.php`, `checkpoint_go_live_unsubscribe.md`
- NO incluidos: stats.db, backups, logs, temporales, binarios, credenciales ✅

## 3. Backup remoto (pre-deploy)

- Ruta: `/getfutprotec.com/backups_deploy/baja_go_live_pre_deploy_20260817_181658/api/baja.php`
- Tamaño: 2506 bytes
- MD5: `d42fc4c8ac72f1ef338adbe9c6ca2f31`
- Timestamp: 2026-08-17 18:16:58

## 4. Deploy

- Subido SOLO `public_html/outbound/api/baja.php` (17944 bytes) ✅
- NO desplegado: enviar_lote.php, mime.php, app.js, lanzadera.php ✅

## 5. Verificación post-deploy

- MD5 LOCAL == MD5 REMOTO: `ac92fe0a1140c5af46df18404bfe135b` → **MATCH** ✅
- `https://getfutprotec.com/outbound/api/baja.php` → **HTTP 200** ✅

## 6. Prueba real controlada — TEST

Destinatario TEST: `TEST_ABC_FINAL4_A` (id=1814, email=test_abc_final4_a@futprotec.local)
Token: `fut_6a82602c_b476972b05dc` (envio_id=9)

| Test | Resultado |
|------|-----------|
| **A — GET** `baja.php?t=...` | ✅ Muestra confirmación explícita (CONFIRMAR BAJA, email visible). BD NO modificada (estado=Sin Contactar, 0 registros [BAJA]). |
| **B — Cancelar** (Seguir recibiendo) | ✅ Enlace simple a getfutprotec.com, sin POST. NO baja, NO cambio de estado, NO opt-out. |
| **C — Confirmar** (POST accion=confirmar) | ✅ "Baja realizada correctamente". BD: estado=Lista Negra, historial `[BAJA] 2026-08-17 16:18:48 \| fuente=email \| envio_id=9`. |
| **D — Motivo** (POST accion=motivo) | ✅ "Baja completada". BD: `[BAJA] Motivo baja: Ya tengo proveedor` registrado. |
| **E — Omitir** (baja sin motivo) | ✅ Confirmado en TEST C (baja sin motivo registrada correctamente). |
| **F — Idempotencia** (repetir confirmar) | ✅ "Ya estabas dado de baja". BD sin cambios (estado=Lista Negra, sin duplicar registros). |
| **G — Elegibilidad** | ✅ Lead suprimido → `esElegibleParaEnvio=false, razon=supresion`. Lead normal (id=155) → `esElegibleParaEnvio=true, razon=elegible`. |

## 7. Compatibilidad enlace antiguo `?email=`

- `baja.php?email=test_abc_final4_a@futprotec.local` → **HTTP 200**, muestra confirmación (CONFIRMAR BAJA, email visible) ✅
- GET NO ejecuta baja (BD sin cambios tras el GET) ✅

## 8. Producción intacta

- `modo_entorno = produccion` ✅
- `motor_estado = pausado` ✅
- Pipeline id=2 = `PILOT` (PILOTO_FUTPROTEC_2026_08, tipo pilot) ✅
- No se modificó nada más ✅

## 9. Seguridad

- Envíos comerciales nuevos = 0 (los 2 envios campaign_id=2 son pre-existentes de fases anteriores; los recientes id 20-22 son TEST con campaign_id=None) ✅
- SMTP comercial = 0 ✅
- cron = NO (motor_estado=pausado) ✅
- campaign 2 modificada = NO ✅
- leads comerciales modificados = NO (count_lista_negra_no_test = 0) ✅
- Único cambio de datos: lead TEST id=1814 (baja de validación) ✅

## 10. Veredicto final

```
GO_LIVE_UNSUBSCRIBE_PRODUCTION_PASS
```

## PARADA FINAL

- ✅ DETENERSE. No enviar todavía.
- ✅ No ejecutar Lanzadera.
- ✅ No ejecutar cron.
- ✅ No cambiar campaign 2.
- Siguiente paso: **MICRO-LOTE COMERCIAL = 5 LEADS** (plantilla 1 + control de lote).

## Archivos modificados

- `public_html/outbound/api/baja.php` (rediseño + fix prepared statements)
- `scripts/test_baja_flow.php` (nuevo, tests del flujo de baja)
- `docs/checkpoint_go_live_unsubscribe.md` (este checkpoint, actualizado con go-live producción)

## Archivos verificados (sin cambios)

- `public_html/outbound/inc/mime.php`
- `public_html/outbound/api/enviar_lote.php`
- `public_html/outbound/inc/eligibilidad.php`


