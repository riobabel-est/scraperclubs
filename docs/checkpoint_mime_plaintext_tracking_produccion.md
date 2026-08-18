# CHECKPOINT — FIX DEFINITIVO MULTIPART/ALTERNATIVE PARA TEXTO PLANO + TRACKING

**Fecha:** 17/08/2026
**Estado:** ✅ COMPLETADO — VEREDICTO `PLAINTEXT_TRACKING_MIME_PASS`
**Alcance:** Construcción MIME de `public_html/outbound/api/enviar_lote.php` + `public_html/outbound/inc/mime.php`

---

## 1. OBJETIVO

Corregir el problema de "texto plano enviado como HTML → saltos de línea colapsados" para la plantilla 1 (`Prospeccion (abc - texto plano)`), conservando tracking, baja, A/B/C, placeholders, idempotencia, SMTP, campañas, BD, leads, lógica de selección, Lanzadera y TEST/REAL.

**Solución:** `multipart/alternative` con `text/plain` (contenido original con saltos) + `text/html` (mismo contenido convertido a HTML mínimo + tracking pixel).

---

## 2. CAMBIOS REALIZADOS

### 2.1 `public_html/outbound/inc/mime.php` (NUEVO)
- `convertirContenidoAHtml()`: convierte texto plano → HTML mínimo.
  - `htmlspecialchars()` para escapar.
  - `nl2br()` para conservar saltos de línea como `<br>`.
  - Enlace de baja como `<a href>` simple (no oculto).
  - Píxel de tracking SOLO en la parte HTML.
  - Div mínimo: `white-space:normal; font-family:Arial,sans-serif; font-size:14px; line-height:1.5`.
- `enviarSMTPAutenticado()`: construye el mensaje MIME.
  - `texto_plano` → `multipart/alternative` con `text/plain` + `text/html` + cierre `--boundary--`.
  - `html` → `text/html; charset=UTF-8` (comportamiento histórico intacto).

### 2.2 `public_html/outbound/api/enviar_lote.php`
- Para `texto_plano`: construye `plainPart` (contenido original) y `htmlPart` (HTML mínimo + pixel) desde la MISMA variable base `$cuerpo`.
- Para `html`: comportamiento histórico intacto (pixel + fingerprint inyectados en el cuerpo).
- Pasa `$plainPart` y `$htmlPart` a `enviarSMTPAutenticado()`.

---

## 3. VALIDACIÓN LOCAL (sin SMTP)

`scripts/test_mime_plaintext_tracking.php` → **43/43 PASS**

- Content-Type principal = `multipart/alternative` + boundary.
- Existe `text/plain` y `text/html` + cierre `--boundary--`.
- `plain`: conserva `\n` y `\n\n`, NO contiene `<img`, `<style`, `<script`, `<!--`, `track.php`, `<div`, `<br`. Contiene URL de baja visible y placeholders sustituidos.
- `html`: contiene `<br`, pixel de tracking, `<img`, enlace de baja `<a href>`, div mínimo. NO contiene tablas, script, fuentes externas.
- Tracking: `tracking_id` presente en html, AUSENTE en plain.
- A/B/C: plain y html comparten el texto base (identidad del contenido).
- Regresión plantilla `html`: `text/html` (no multipart), pixel + fingerprint, comportamiento histórico intacto.
- A/B/C: resolución de variantes correcta (A/B/C → asunto/cuerpo correctos).
- `asignarVariante`: determinística e inmutable.
- Baja: enlace simple, no oculto.

---

## 4. DEPLOY A PRODUCCIÓN

`scripts/deploy_mime_tracking.py`:
- Backup remoto: `/getfutprotec.com/backups_deploy/mime_tracking_pre_deploy_20260817_173408`.
- Deploy: `inc/mime.php` (9289 bytes) + `api/enviar_lote.php` (20526 bytes).
- Verificación MD5 local vs remoto: **MATCH** en ambos.
- HTTP 200: dashboard.php, app.js?v=10, track.php.

---

## 5. PRUEBA CONTROLADA EN PRODUCCIÓN (TEST)

### 5.1 Preparación
- **Campaña 3**: `SMOKE TEST FutProtec 2026-08` | PILOT | test | activo=1.
- **Lead dummy 1809**: `TEST_CLUB_01_RealMadrid` | `test01@futprotec.local` | 01 Sin Contactar | no duplicado.
- **Plantilla 1**: `Prospeccion (abc - texto plano)` | texto_plano | test_ab=1 | activo=1.
- **Cuenta SMTP 1**: `rodrigo@getfutprotec.com` | activa=1 | limite_diario=15.
- **Buzones de prueba**: estudioriobabel@gmail.com, ruyelcano@gmail.com, rodrigo@riobabel.com.

### 5.2 Ajuste temporal de entorno
- `modo_entorno` remoto estaba en `produccion`, lo que bloqueaba la campaña test (`campaign_test_en_produccion`).
- **Autorizado por el usuario**: se cambió temporalmente a `test` para la prueba.
- Backup previo: `backups_deploy/stats_db_remote_pre_modo_test.db`.

### 5.3 Envíos TEST (3 variantes A/B/C)
| Variante | Destino | Resultado | Envío ID | Tracking ID |
|----------|---------|-----------|----------|-------------|
| A | estudioriobabel@gmail.com | ✅ enviado | 20 | fut_6a832aee_a738db62d382 |
| B | ruyelcano@gmail.com | ✅ enviado | 21 | fut_6a832aee_4f18f5c3b4d7 |
| C | rodrigo@riobabel.com | ✅ enviado | 22 | fut_6a832aef_2b9f3e241bde |

- Los 3 con `campaign_id = NULL` (correcto en modo_test, evita colisión de idempotencia).
- `comunicaciones_log`: resultado `exito`, detalle `[TEST campaña 3]`.

### 5.4 Validación de cuerpos A/B/C
Los 3 cuerpos comerciales correctos:
- Placeholders sustituidos (`TEST_CLUB_01_RealMadrid`).
- Saltos de línea y párrafos conservados.
- Enlace de baja visible: `https://getfutprotec.com/outbound/api/baja.php?email=test01@futprotec.local`.
- Contenido A/B/C distinto (prospección estándar / diseño exclusivo / propuesta detallada con precios).

### 5.5 Tracking (extremo a extremo)
- Los 3 pixels respondieron HTTP 200.
- Aperturas registradas en `aperturas`:
  - id=8: tracking A (curl)
  - id=9: tracking B (curl)
  - id=10: tracking C (curl)
  - **id=7: tracking C (Thunderbird — apertura REAL del correo en el buzón)** ✅
- Estados de envíos 20-22 actualizados a `abierto`.

**La apertura real en Thunderbird confirma que el correo llegó al buzón y el HTML con el pixel se renderizó correctamente en un cliente de correo real.**

### 5.6 Analytics
- Dashboard `analytics.php`: HTTP 200.
- Aperturas visibles en BD (ids 8, 9, 10 para envíos 20-22).

### 5.7 Seguridad
- Cuenta SMTP 1 intacta (email, usuario, host, puerto 465, SSL, activa).
- Password presente (no expuesto).
- Sin secretos en logs/commits.

---

## 6. REVERSIÓN DEL ENTORNO

- `modo_entorno` remoto revertido a `produccion`.
- Verificado: `modo_entorno = produccion` tras revertir.
- Datos de la prueba conservados: `envios` = 22, `aperturas` = 10.
- Backup previo a revertir: `backups_deploy/stats_db_remote_pre_revert.db`.

---

## 7. CRITERIOS DE ACEPTACIÓN

| Criterio | Estado |
|----------|--------|
| MIME = multipart/alternative | ✅ |
| TEXT/PLAIN = correcto | ✅ |
| TEXT/HTML = correcto | ✅ |
| Saltos de línea = correctos | ✅ |
| Tracking presente en HTML | ✅ |
| Tracking ausente en plain | ✅ |
| Baja correcta | ✅ |
| A/B/C intacto | ✅ |

**VEREDICTO: `PLAINTEXT_TRACKING_MIME_PASS`**

---

## 8. REGRESIÓN

- `php -l public_html/outbound/api/enviar_lote.php` → sin errores.
- `php -l public_html/outbound/inc/mime.php` → sin errores.
- `node --check public_html/outbound/js/app.js` → sin errores.
- Plantilla `html` (regresión): comportamiento histórico intacto (text/html + pixel + fingerprint).
- A/B/C, TEST/REAL, idempotencia, tracking, elegibilidad: intactos.

---

## 9. PARADA

- ✅ No se desplegó nada adicional.
- ✅ No se envió a leads reales (solo buzones de prueba).
- ✅ No se modificó BD de forma permanente (solo ajuste temporal de entorno revertido).
- ✅ No se realizó una nueva prueba SMTP no controlada.
- ✅ Esperando validación del usuario.
