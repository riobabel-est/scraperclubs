# CHECKPOINT — FASE C.1: AUDITORÍA READ-ONLY DEL FLUJO REAL DE LANZADERA WEB

**Fecha:** 2026-08-18 02:50
**Modo:** READ-ONLY (sin envíos, sin modificaciones, sin campañas)

---

## 1. FLUJO REAL DE LA LANZADERA

El envío real se inicia desde el **navegador autenticado** en el panel Lanzadera (`tabs/lanzadera.php`), NO desde un script externo.

**Acción de usuario:** pulsar el botón **"🟢 INICIAR LANZADERA"** (`tabs/lanzadera.php:325` → `@click="iniciarMotor()"`).

Hay 3 vías de envío en `js/app.js`:
- **CASO A — Envío dirigido (1 lead):** `app.js:725-759`. Se envía SOLO al lead seleccionado en "Envío Dirigido".
- **CASO B — Cola normal con límite de lote:** `app.js:761-801`. Recorre la cola cargada respetando `lzBatchSize`.
- **Correos de prueba:** `app.js:617-697` → `enviarCorreoPrueba()`.

## 2. ENDPOINT REAL DE ENVÍO

**`api/enviar_lote.php`** — el único endpoint que ejecuta SMTP.

Llamado desde el navegador vía `fetch()`:
- `app.js:741` (CASO A): `fetch('api/enviar_lote.php', { method: 'POST', body: fd, signal: signal })`
- `app.js:775` (CASO B): `fetch('api/enviar_lote.php', { method: 'POST', body: fd, signal: signal })`
- `app.js:688` (prueba): `fetch('api/enviar_lote.php', { method: 'POST', body: fd })`

## 3. MÉTODO HTTP

**POST** con `FormData` (multipart/form-data).

## 4. AUTENTICACIÓN

- `dashboard.php:11` → `session_start()`.
- `dashboard.php:14-21` → login por POST `password`; si coincide con `AUTH_KEY`, `$_SESSION['auth_outbound'] = true`.
- `dashboard.php:49-54` → los endpoints AJAX (`action`) requieren `$_SESSION['auth_outbound']`; si no, HTTP 401.
- **`enviar_lote.php` NO requiere sesión** — valida campaña desde BD (anti-bypass, `enviar_lote.php:53-55`). El envío se ejecuta desde el navegador autenticado, pero la validación de seguridad no depende de la sesión.

## 5. CSRF

**No existe token CSRF explícito.** La protección se basa en:
- Sesión PHP (`$_SESSION['auth_outbound']`) para endpoints AJAX de `dashboard.php`.
- Validación de campaña desde BD en `enviar_lote.php` (anti-bypass).
- WAF de SiteGround que bloquea peticiones no-navegador (User-Agent).

## 6. PARÁMETROS EXACTOS (FormData)

Enviados por la UI a `enviar_lote.php`:
- `id_club` (lead id)
- `id_plantilla` (plantilla id)
- `id_cuenta_smtp` (cuenta SMTP id)
- `modo_test` ('1' si modo test, '0' en producción)
- `variante_ab` (A/B/C — calculado en cliente con `Math.random()`)
- `campaign_id` (campaña id)
- `test_email` (solo en modo test)

## 7. CADENA COMPLETA DE EJECUCIÓN

```
Navegador autenticado (sesión PHP)
  → usuario pulsa "INICIAR LANZADERA"
  → js/app.js iniciarMotor() (CASO A o B)
  → fetch('api/enviar_lote.php', {method:'POST', body: FormData})
  → enviar_lote.php:
      - Lee modo_entorno desde BD (anti-bypass) [L53-55]
      - validarCampanaActiva($db, $idCampana, $modoEntornoBD) [L68]
      - Determina variante: $modoTest ? $varianteAb : asignarVariante($idClub,$idCampana) [L85]
      - Valida club, email, elegibilidad esElegibleParaEnvio() [L114]
      - Valida plantilla, cuenta SMTP activa, límite diario [L122-167]
      - reservarEnvioLogico() (idempotencia) [L267]
      - Si ya enviado/abierto → devuelve dup=true sin reenviar [L296]
      - enviarSMTPAutenticado() [L317]
      - Actualiza envios, comunicaciones_log, cuentas_smtp, estado club [L337-390]
```

## 8. FUNCIONES DE SEGURIDAD EJECUTADAS

| Función | Dónde | Propósito |
|---|---|---|
| `validarCampanaActiva()` | `enviar_lote.php:68` | Valida estado/activo/entorno de campaña desde BD |
| `esElegibleParaEnvio()` | `enviar_lote.php:114` | Supresión + TEST/REAL + duplicado |
| `asignarVariante()` | `enviar_lote.php:85` | Variante determinista en producción |
| `reservarEnvioLogico()` | `enviar_lote.php:267` | Idempotencia + concurrencia |
| `resolverContenidoVariante()` | `enviar_lote.php:134` | Contenido A/B/C por variante |
| `enviarSMTPAutenticado()` | `enviar_lote.php:317` | Envío SMTP nativo |

## 9. SELECCIÓN DE LEADS

- **CASO A (dirigido):** el lead se selecciona manualmente en "Envío Dirigido" (`lzSelectedLead`).
- **CASO B (cola):** la cola se carga vía `api/get_cola.php` (`app.js:710`), que aplica `sqlFiltroCompatibilidadLeadCampana()` en SQL (solo leads compatibles TEST/REAL con la campaña).
- **Pruebas:** `enviarCorreoPrueba()` usa `get_cola.php` con `campaign_id` para obtener leads compatibles.

## 10. DETERMINACIÓN A/B/C

- **En producción (no test):** `enviar_lote.php:85` → `$varianteUsada = asignarVariante($idClub, $idCampana)`. **El `variante_ab` enviado por el cliente (Math.random) es IGNORADO.** La variante es determinista e inmutable.
- **En modo test:** `$varianteUsada = $varianteAb` (respeta la variante explícita de la UI para pruebas A/B/C).

## 11. DIFERENCIA ENTRE UI Y SCRIPT PYTHON

| Aspecto | UI (navegador) | Script Python (`microenvio_campana2_5leads.py`) |
|---|---|---|
| User-Agent | Chrome/Edge real | `Python-urllib/3.x` |
| Headers | Accept, Accept-Language, Referer, etc. | Ninguno |
| Sesión | PHP autenticada | CookieJar sin headers |
| WAF | Acepta | **Bloquea (403)** |
| Variante | `asignarVariante()` en servidor (producción) | Calculada en Python (espejo) |

## 12. MOTIVO TÉCNICO DEL 403

El script Python (`microenvio_campana2_5leads.py:200-204, 655`) usa `urllib.request` con `http.cookiejar.CookieJar()` **sin headers de navegador**. El User-Agent por defecto de urllib (`Python-urllib/3.x`) es bloqueado por el WAF de SiteGround, tanto en el login (`dashboard.php`) como en el envío (`enviar_lote.php`). El 403 ocurre ANTES de llegar al PHP.

**NO es un problema de la BD ni de la lógica de envío.** Es un bloqueo de red del WAF contra clientes no-navegador.

## 13. FORMA CORRECTA DE EJECUTAR EL MICROENVÍO POSTERIOR

El microenvío de los 5 leads (2,3,4,6,8) debe ejecutarse **desde la lanzadera web autenticada**, NO desde un script Python externo:

1. Abrir `https://getfutprotec.com/outbound/dashboard.php` en el navegador.
2. Autenticarse con la contraseña.
3. Ir a la pestaña **Lanzadera**.
4. Seleccionar campaña **2 (PILOTO_FUTPROTEC_2026_08)**.
5. Seleccionar plantilla **1 (Prospeccion abc - texto plano)**.
6. En "Envío Dirigido", seleccionar cada lead (2, 3, 4, 6, 8) y pulsar "INICIAR LANZADERA" (CASO A, 1 lead a la vez), o cargar la cola con esos 5 leads y usar CASO B con `lzBatchSize=5`.
7. El navegador hace `fetch('api/enviar_lote.php')` con headers de navegador → el WAF acepta.
8. `enviar_lote.php` recalcula `asignarVariante()` en producción → variantes correctas (B,B,B,A,C).

**Nota sobre variantes:** en producción, `enviar_lote.php:85` recalcula la variante con `asignarVariante()`, por lo que las variantes serán B,B,B,A,C automáticamente (independiente del `Math.random()` del cliente).

## 14. QUÉ NO DEBE HACERSE

- **NO** intentar evadir el WAF añadiendo headers falsos de navegador al script Python (User-Agent Chrome, Accept, Referer, etc.) para eludir controles de seguridad.
- **NO** ejecutar `enviar_lote.php` desde un script externo no autenticado.
- **NO** activar el motor (`motor_estado`) para el microenvío.
- **NO** ejecutar `cron.php` ni `enviar_lote.php` con intención de envío masivo.
- **NO** modificar pipelines, lead_pipelines, plantillas, es_test, ni variantes.
- **NO** usar `lead_pipelines.variante` (columna inexistente); usar `lead_pipelines.variante_ab`.

## 15. ESTADO DE LA BD ANTES/DESPUÉS

**ANTES (auditoría integral, 02:46):**
- MD5: `4dbc8e72608dd1f0ebd7ad25aaa58364`
- SHA-256: `f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc`
- Envíos campaña 2: **22**
- integrity_check: ok | foreign_key_check: 0

**DESPUÉS (Fase C.1, read-only):**
- No se ejecutó ningún envío, no se modificó BD, no se lanzó campaña.
- La BD permanece con el mismo hash (no se subió nada).
- Envíos campaña 2: **22** (sin cambios).

## 16. CONFIRMACIÓN DE 0 EMAILS ENVIADOS

- **EMAILS ENVIADOS = 0**
- **CAMPAÑAS LANZADAS = 0**
- **MOTOR DE ENVÍO = pausado** (sin cambios)

---

## VEREDICTO

**PASS READ-ONLY**

Se identificó inequívocamente el flujo real de la lanzadera web sin modificar producción:
- El envío real se ejecuta desde el navegador autenticado vía `fetch('api/enviar_lote.php')`.
- El endpoint `enviar_lote.php` valida campaña desde BD (anti-bypass) y recalcula la variante con `asignarVariante()` en producción.
- El 403 del WAF es un bloqueo de red contra el User-Agent de urllib (Python), no un problema de BD ni de lógica.
- El microenvío posterior debe ejecutarse desde la lanzadera web, no desde un script Python externo.
- No se requirió enviar, modificar BD ni saltarse controles de seguridad para determinarlo.
