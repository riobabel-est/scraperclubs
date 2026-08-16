# Checkpoint FASE 6F.9B — Corrección controlada del destinatario de pruebas

- Fecha: 2026-08-16
- Entorno: `modo_entorno = test` / `motor_estado = pausado`
- Alcance: exclusivamente destinatario de pruebas (configuración + preflight).
- Carácter de la fase: **NO-SMTP / NO-POST / NO-CRON / NO-EVOLUTION** (solo lectura + 1 UPDATE dirigido a `config.test_emails`).

---

## A. Estado PRE

### Configuración `test_emails` (antes del cambio)

Tabla `config`, fila `rowid = 7`, clave `test_emails`. Separador de línea: `\r\n` (`0d0a`).

Texto exacto PRE:

```
estudioriobabel@hmail.com
ruyelcano@gmail.com
rodrigo@riobabel.com
hola@riobabel.com
```

Valor en bruto (JSON):

```json
"estudioriobabel@hmail.com\r\nruyelcano@gmail.com\r\nrodrigo@riobabel.com\r\nhola@riobabel.com"
```

Hex del prefijo:

```
6573747564696f72696f626162656c40686d61696c2e636f6d0d0a727579656c63616e6f40676d61
```

(`estudioriobabel@hmail.com\r\nruyelcano@gma...`)

Apariciones de `hmail` en toda la tabla `config`: **1** (solo `test_emails`, rowid 7).

Otras claves relevantes PRE (sin cambios en esta fase):

| clave | valor |
|-------|-------|
| `modo_entorno` | `test` |
| `motor_estado` | `pausado` |
| `email_test` | `contactofutprotec@gmail.com` |
| `delay_envio` | `3` |
| `lote_envio` | `10` |
| `lanzadera_delay` | `8` |

### Script `scripts/fase6f9_preflight.php` (antes del cambio)

Línea 51:

```php
$TEST_EMAIL = 'estudioriobabel@hmail.com';
```

Destinatario hardcodeado independiente de la configuración.

---

## B. Cambio

Únicamente:

`estudioriobabel@hmail.com → estudioriobabel@gmail.com`

y la eliminación del hardcode equivalente del preflight.

### B.1. `config.test_emails`

UPDATE dirigido a la fila `clave = 'test_emails'`:

- Ocurrencias reemplazadas del literal `estudioriobabel@hmail.com`: **1**
- Se preservaron los demás destinatarios y el separador `\r\n`:
  - `ruyelcano@gmail.com`
  - `rodrigo@riobabel.com`
  - `hola@riobabel.com`
- Verificación de reversibilidad: OK.

### B.2. `scripts/fase6f9_preflight.php`

Eliminado el literal `'estudioriobabel@hmail.com'`.

Ahora el preflight lee la configuración real:

```php
$testEmailsRaw = (string)($db->querySingle("SELECT valor FROM config WHERE clave = 'test_emails'") ?: '');
$testEmailsParsed = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $testEmailsRaw))));
$TEST_EMAIL = $testEmailsParsed[0] ?? '';
```

Objetivo cumplido:

- configuración = fuente de verdad;
- preflight = lector de configuración;
- frontend = lector de configuración;
- POST = destinatario seleccionado;
- backend = valida y utiliza el destinatario recibido.

No se modificó `enviar_lote.php`, `enviar_smtp_random.php`, `cron.php`, ni ningún otro archivo del flujo de envío.

---

## C. Estado POST

### Configuración `test_emails` (después del cambio)

Texto exacto POST:

```
estudioriobabel@gmail.com
ruyelcano@gmail.com
rodrigo@riobabel.com
hola@riobabel.com
```

Valor en bruto (JSON):

```json
"estudioriobabel@gmail.com\r\nruyelcano@gmail.com\r\nrodrigo@riobabel.com\r\nhola@riobabel.com"
```

Apariciones de `hmail` en toda la tabla `config` tras el cambio: **0**.

### Script `scripts/fase6f9_preflight.php` (después del cambio)

Sin literales de destinatario: obtiene el destinatario desde `config.test_emails` (primer buzón).

---

## D. Validación (todas NO-SMTP)

### A. Configuración

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `modo_entorno` | `test` | PASS |
| `motor_estado` | `pausado` | PASS |
| `test_emails` contiene `estudioriobabel@gmail.com` | `true` | PASS |
| `estudioriobabel@hmail.com` aparece como destinatario activo | `false` (0 filas con `hmail` en config) | PASS |

### B. Preflight (NO-SEND / NO-SMTP)

- El preflight abre `stats.db` con `SQLITE3_OPEN_READONLY` y no invoca `reservarEnvioLogico` (que es la única función con escritura en `inc/eligibilidad.php`); las funciones invocadas (`validarCampanaActiva`, `esLeadTest`, `esElegibleParaEnvio`, `asignarVariante`) son de solo lectura/puras.
- `php -l scripts/fase6f9_preflight.php`: `No syntax errors detected`.
- Ejecución del preflight (solo lectura) demuestra:

```
config.test_emails (crudo)                     : estudioriobabel@gmail.com
ruyelcano@gmail.com
rodrigo@riobabel.com
hola@riobabel.com
config.test_emails (buzones)                   : ["estudioriobabel@gmail.com","ruyelcano@gmail.com","rodrigo@riobabel.com","hola@riobabel.com"]
destinatario test (primer buzón)              : estudioriobabel@gmail.com
destinatario FINAL previsto                    : estudioriobabel@gmail.com
```

- El preflight termina con `SMOKE_ABORTED_BEFORE_SMTP` porque `(lead_id=1810, campaign_id=3)` ya tiene el `envio_id=6` (idempotencia). Esto es lo esperado: confirma que el bloqueo por idempotencia sigue activo y que NO se reenvía.

### C. Integridad del smoke anterior (`envio_id=6`) — SOLO LECTURA

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `envio_id=6` existe | `true` | PASS |
| `estado` | `enviado` | PASS |
| `resultado_envio` | `ACCEPTED` | PASS |
| `campaign_id` | `3` | PASS |
| `lead_id` | `1810` | PASS |
| `variant` | `A` | PASS |
| `plantilla_id` | `2` | PASS |
| `smtp_id` | `1` | PASS |
| `message_id` | `<fut_6a8111e4_2306cee0a376@getfutprotec.com>` | PASS (idéntico) |
| no existe ningún `envio_id > 6` | `count = 0`, `max(id) = 6` | PASS |

### D. Integridad de campaña y entorno

| comprobación | valor | resultado |
|--------------|-------|-----------|
| campaña 3 `nombre` | `SMOKE TEST FutProtec 2026-08` | PASS |
| campaña 3 `identificador` | `SMOKE_TEST_FUTPROTEC_2026_08` | PASS |
| campaña 3 `estado` | `PILOT` | PASS |
| campaña 3 `entorno` | `test` | PASS |
| campaña 3 `activo` | `1` | PASS |
| `config.modo_entorno` | `test` | PASS |
| `config.motor_estado` | `pausado` | PASS |

### E. Idempotencia

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `envios(lead_id=1810, campaign_id=3)` count | `1` | PASS |
| `envios(lead_id=1810, campaign_id=3)` ids | `6` | PASS (bloqueado por envio_id=6) |

No se intentó reenvío.

---

## E. Integridad

- `envio_id=6` **no fue alterado**: conserva `estado=enviado`, `resultado_envio=ACCEPTED`, mismo `tracking_id`, `message_id`, `campaign_id=3`, `lead_id=1810`, `variant=A`, `plantilla_id=2`, `smtp_id=1`.
- No existe ningún `envio_id > 6`.
- No se creó ningún envío nuevo (el único INSERT de la fase fue el UPDATE dirigido a `config.test_emails`, no a `envios`).

---

## F. Seguridad

Confirmación explícita de que durante esta fase:

- `SMTP ejecutado: NO`
- `POST de envío ejecutado: NO`
- `cron ejecutado: NO`
- `Evolution API ejecutada: NO`
- `nuevo envio_id: NO`

El preflight finalizó en `SMOKE_ABORTED_BEFORE_SMTP` sin abrir conexión SMTP, sin ejecutar `enviar_lote.php` ni `cron.php`.

---

## G. Veredicto

`READY_FOR_SECOND_SMOKE`

El destinatario de pruebas ya es `estudioriobabel@gmail.com` en la configuración, el preflight lo obtiene desde configuración sin hardcode, y todas las comprobaciones no-SMTP pasan. No se realizó ningún envío nuevo y `envio_id=6` permanece intacto como evidencia.

> No se avanza al segundo smoke. Esta fase termina aquí.