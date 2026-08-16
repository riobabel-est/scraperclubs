# Checkpoint FASE 6F.9D — Segundo Smoke Controlado: envío único al destinatario correcto

- Fecha: 2026-08-16
- Entorno: `modo_entorno = test` / `motor_estado = pausado`
- Carácter de la fase: **1 solo envío SMTP real + solo lectura post-envío** (no cron, no Evolution API, no segundo POST).

---

## A. Pre-flight

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `config.modo_entorno` | `test` | PASS |
| `config.motor_estado` | `pausado` | PASS |
| Campaña 3 (`validarCampanaActiva(3,'test')`) | `ok=true`, `razon=CAMPANIA_VALIDA`, estado=PILOT, entorno=test, activo=1 | PASS |
| Lead 1812 existe | `TEST_CLUB_04_Sevilla`, `test04@futprotec.local` | PASS |
| `esLeadTest(1812)` | `true` | PASS |
| `esElegibleParaEnvio(1812,3)` | `ok=true`, `razon=elegible` | PASS |
| Idempotencia previa `(1812,3)` | `0` filas (limpio) | PASS |
| Plantilla 2 | activa, `test_ab=1`, variantes A/B/C completas | PASS |
| Variante `asignarVariante(1812,3)` | `C` | PASS |
| Destinatario `config.test_emails[0]` | `estudioriobabel@gmail.com` | PASS |
| SMTP seleccionado | `id=1`, `rodrigo@getfutprotec.com`, capacidad 49 | PASS |
| `envio_id=6` previo | intacto (`lead_id=1810`, variante A) | PASS |
| `MAX(envios.id)` previo | `6` | PASS |

**Veredicto pre-flight**: `READY_FOR_SECOND_SMOKE` (sin BLOCKED).

---

## B. Parámetros utilizados

POST único a `enviar_lote.php`:

```text
campaign_id = 3
id_club = 1812
id_plantilla = 2
id_cuenta_smtp = 1
modo_test = 1
test_email = estudioriobabel@gmail.com   (obtenido de config.test_emails)
```

- `config.test_emails` (bruto) = `estudioriobabel@gmail.com`, `ruyelcano@gmail.com`, `rodrigo@riobabel.com`, `hola@riobabel.com`. Primer buzón = destinatario efectivo.
- Variante **NO forzada**: producida por el algoritmo `asignarVariante(1812, 3)` → `C`.

---

## C. Ejecución única

Se realizó **UNA sola** llamada POST:

- HTTP status: `200`
- JSON respuesta:

```json
{"ok":true,"envio_exitoso":true,"estado":"enviado","error_smtp":"","club":"TEST_CLUB_04_Sevilla","email":"test04@futprotec.local","cuenta_smtp":"rodrigo@getfutprotec.com","cuenta_id":1,"timestamp":"2026-08-16 15:18:54"}
```

No se ejecutó segundo POST, cron, `enviar_smtp_random.php` ni Evolution API.

---

## D. Resultado SMTP

| campo | valor |
|-------|-------|
| `envio_exitoso` | `true` |
| `estado` | `enviado` |
| `error_smtp` | (vacío) |
| cuenta SMTP utilizada | `rodrigo@getfutprotec.com` |
| `cuenta_id` | `1` |
| `message_id` | `<fut_6a81d4de_f9b717c1972a@getfutprotec.com>` |
| `envio_id` | `7` |
| Resultado SMTP | `ACCEPTED` |

**Veredicto SMTP**: `SMTP_ACCEPTED` (aceptación SMTP por el flujo; la entrega final se comprueba manualmente en Gmail).

---

## E. Trazabilidad `envios`

Nueva fila `envio_id=7`:

| campo | valor |
|-------|-------|
| id | 7 |
| campaign_id | 3 |
| lead_id | 1812 |
| club | TEST_CLUB_04_Sevilla |
| email | test04@futprotec.local |
| cuenta_emision | rodrigo@getfutprotec.com |
| estado | enviado |
| resultado_envio | ACCEPTED |
| variant | C |
| plantilla_id | 2 |
| smtp_id | 1 |
| message_id | `<fut_6a81d4de_f9b717c1972a@getfutprotec.com>` |
| tracking_id | `fut_6a81d4de_f9b717c1972a` |
| fecha_resultado_envio | 2026-08-16 15:18:54 |

---

## F. `comunicaciones_log`

Nuevo evento de envío asociado al lead 1812:

| campo | valor |
|-------|-------|
| id | 30 |
| lead_id | 1812 |
| club_id | 1812 |
| tipo_evento | envio_email |
| plantilla_id | 2 |
| id_cuenta_smtp | 1 |
| tipo | email |
| resultado | exito |
| codigo_error | (vacío) |
| variante_ab | C |
| detalles | Envío a test04@futprotec.local con plantilla Primer Contacto (ABC - Texto Plano) |
| fecha | 2026-08-16 15:18:54 |

---

## G. Estado del lead 1812

| campo | antes | después |
|-------|-------|---------|
| estado_lead | 01 Sin Contactar | 01 Sin Contactar (**NO cambió**, `modo_test=1`) |
| ultimo_contacto | NULL (limpio) | 2026-08-16 15:18:54 (**cambió**) |
| observaciones | (vacío) | `[TEST 16/08 15:18] Email de prueba enviado a estudioriobabel@gmail.com con plantilla 'Primer Contacto (ABC - Texto Plano)' (lead original: test04@futprotec.local)` |

Comportamiento preexistente respetado: en `modo_test=1` no se modifica `estado_lead`; sí se actualiza `ultimo_contacto` y se añade nota TEST.

---

## H. Idempotencia

```text
COUNT(envios WHERE lead_id=1812 AND campaign_id=3) = 1
```

Se confirma exactamente **1** fila (envio_id=7). No se intentó repetir el envío.

---

## I. Integridad de `envio_id=6`

`envio_id=6` sigue intacto:

| campo | valor |
|-------|-------|
| estado | enviado |
| resultado_envio | ACCEPTED |
| campaign_id | 3 |
| lead_id | 1810 |
| variant | A |
| plantilla_id | 2 |
| smtp_id | 1 |
| message_id | `<fut_6a8111e4_2306cee0a376@getfutprotec.com>` |
| tracking_id | `fut_6a8111e4_2306cee0a376` |

No se modificó.

---

## J. Seguridad

```text
envíos realizados durante esta fase = 1  (envio_id=7, lead 1812)
otros leads enviados durante esta fase = 0
cron ejecutado = NO
enviar_smtp_random.php = NO
Evolution API = NO
segundo POST de envío = NO
campaign_id distinto = NO
modo_entorno cambiado = NO  (sigue 'test')
motor_estado cambiado = NO  (sigue 'pausado')
```

Conteos globales de respaldo:

- `envios` totales: `7`
- `envios` campaign_id=3: `5`
- `comunicaciones_log` con `tipo_evento='envio_email'` hoy: `2` (envio_id=6 previo + envio_id=7 de este smoke)

---

## K. Veredicto

```text
SECOND_SMOKE_SMTP_ACCEPTED
```

El SMTP aceptó el mensaje (`ACCEPTED`) y toda la trazabilidad es correcta:

- destinatario efectivo: `estudioriobabel@gmail.com` (procedente de `config.test_emails`)
- variante determinística: `C`
- idempotencia: `1` fila para `(1812,3)`
- `envio_id=6` preservado
- `MAX(envios.id) = 7`

**No se usa `DELIVERED`**: la entrega final al buzón se comprueba manualmente en Gmail.

---

## Parada obligatoria

Se detiene la fase. No se ejecuta otro envío, cron, ni cambios de configuración. Se espera comprobación manual del buzón `estudioriobabel@gmail.com`.

Ciclo validado: lead TEST → campaña TEST → modo TEST → destinatario Gmail correcto → SMTP → ACCEPTED → trazabilidad → idempotencia.