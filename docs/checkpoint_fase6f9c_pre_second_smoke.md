# Checkpoint FASE 6F.9C — Pre-Second-Smoke y preparación del único reenvío válido

- Fecha: 2026-08-16
- Entorno: `modo_entorno = test` / `motor_estado = pausado`
- Carácter de la fase: **SOLO LECTURA / PREPARACIÓN** (no-SMTP, no-POST, no-cron, no envío).
- Objetivo: preparar el segundo smoke con `campaign_id=3`, `lead_id=1812`, `plantilla_id=2`, `modo_test=1`, destinatario tomado de `config.test_emails`.

---

## A. Estado del entorno

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `config.modo_entorno` | `test` | PASS |
| `config.motor_estado` | `pausado` | PASS |

---

## B. Campaña seleccionada

Campaña 3 (`pipelines`):

| campo | valor |
|-------|-------|
| `id` | `3` |
| `nombre` | `SMOKE TEST FutProtec 2026-08` |
| `identificador` | `SMOKE_TEST_FUTPROTEC_2026_08` |
| `estado` | `PILOT` |
| `entorno` | `test` |
| `activo` | `1` |
| `tipo` | `outbound` |

`validarCampanaActiva($db, 3, 'test')`:

- `ok = true`
- `razon = CAMPANIA_VALIDA`

Resultado: **PASS**.

---

## C. Lead seleccionado (`lead_id=1812`)

Fila completa relevante de `clubes_crm`:

| campo | valor |
|-------|-------|
| `id` | `1812` |
| `nombre_club` | `TEST_CLUB_04_Sevilla` |
| `federacion` | `Real Federación Andaluza de Fútbol` |
| `email` | `test04@futprotec.local` |
| `telefono_movil` | `600000004` |
| `tiene_whatsapp` | `1` |
| `estado_lead` | `01 Sin Contactar` |
| `es_duplicado` | `0` |
| `duplicado_id` | `NULL` |

Comprobaciones:

| comprobación | valor | resultado |
|--------------|-------|-----------|
| existe | `true` | PASS |
| `esLeadTest(1812)` | `true` | PASS |
| no duplicado (`es_duplicado=0`) | `true` | PASS |
| no suprimido | `true` (estado no está en lista de supresión) | PASS |
| email válido (`filter_var`) | `true` | PASS |
| compatible con campaña TEST (`esCampanaTest(3) === esLeadTest(1812)`) | `true` | PASS |
| `SELECT COUNT(*) FROM envios WHERE lead_id=1812 AND campaign_id=3` | `0` | PASS |

No hay ningún envío lógico previo para `lead_id=1812 + campaign_id=3`.

---

## D. Idempotencia

| comprobación | valor | resultado |
|--------------|-------|-----------|
| `envios(lead_id=1812, campaign_id=3)` count | `0` | PASS (candidato limpio) |
| `MAX(envios.id)` global | `6` | PASS |
| `envio_id > 6` count | `0` | PASS |

No apareció ningún envío nuevo desde la FASE 6F.9B.

---

## E. Elegibilidad

`esElegibleParaEnvio($db, 1812, 3)`:

- `ok = true`
- `razon = elegible`

Resultado: **PASS**.

---

## F. Variante

`asignarVariante(1812, 3)` = **`C`**

No se forzó A/B/C; se documenta el resultado determinístico exacto.

---

## G. Plantilla

Plantilla 2 (`plantillas`):

| campo | valor |
|-------|-------|
| `id` | `2` |
| `nombre` | `Primer Contacto (ABC - Texto Plano)` |
| `activo` | `1` |
| `test_ab` | `1` |

Variantes:

| comprobación | valor | resultado |
|--------------|-------|-----------|
| Variante A (asunto + cuerpo) | completa | PASS |
| Variante B (asunto_b + cuerpo_b) | completa | PASS |
| Variante C (asunto_c + cuerpo_c) | completa | PASS |

---

## H. Destinatario efectivo previsto

El destinatario NO se introduce manualmente: se obtiene de `config.test_emails`.

Valor de `config.test_emails` (en bruto):

```
estudioriobabel@gmail.com
ruyelcano@gmail.com
rodrigo@riobabel.com
hola@riobabel.com
```

Primer buzón (destinatario seleccionado en modo test):

`estudioriobabel@gmail.com`

Demostración de procedencia de configuración: el valor se leyó con `SELECT valor FROM config WHERE clave='test_emails'` (solo lectura), y el primer elemento tras dividir por `\r\n`/`\n`/`,` es `estudioriobabel@gmail.com`.

Resultado: **PASS**.

---

## I. SMTP (solo capacidad/selección; sin conexión)

Solo lectura; NO se abrió conexión SMTP.

Capacidad disponible (cuentas activas, top 5 por id):

| cuenta | capacidad disponible |
|--------|----------------------|
| `smtp1` rodrigo@getfutprotec.com | `49` (límite 50 − real 1) |
| `smtp2` mario.ortiz@getfutprotec.com | `50` (límite 50 − real 0) |
| `smtp3` alvaro.ruiz@getfutprotec.com | `50` (límite 50 − real 0) |
| `smtp4` carlos.mora@getfutprotec.com | `50` (límite 50 − real 0) |
| `smtp5` javier.sanz@getfutprotec.com | `50` (límite 50 − real 0) |

En el primer smoke se usó `smtp_id=1` (`rodrigo@getfutprotec.com`), que conserva capacidad disponible (`49`).

Resultado: **PASS** (capacidad verificada en lectura, sin conexión SMTP).

---

## J. Integridad

- `envio_id=6` sigue intacto:
  - `estado = enviado`
  - `resultado_envio = ACCEPTED`
  - `campaign_id = 3`
  - `lead_id = 1810`
  - `variant = A`
  - `plantilla_id = 2`
  - `smtp_id = 1`
  - `message_id = <fut_6a8111e4_2306cee0a376@getfutprotec.com>`
- No existe `envio_id > 6` (`MAX(envios.id) = 6`).
- No se creó ningún envío nuevo.
- No se modificó ningún lead.

---

## K. Seguridad

Confirmación explícita:

`SMTP ejecutado: NO`

`POST de envío: NO`

`cron ejecutado: NO`

`Evolution API ejecutada: NO`

`nuevo envio_id: NO`

Todos los accesos fueron con `SQLITE3_OPEN_READONLY` y funciones de solo lectura (`validarCampanaActiva`, `esLeadTest`, `esElegibleParaEnvio`, `asignarVariante`). No se ejecutó `enviar_lote.php`, `enviar_smtp_random.php`, `cron.php` ni ningún flujo de envío.

---

## L. Preparación

`READY_FOR_SECOND_SMOKE`

---

## PARADA OBLIGATORIA

No se ejecuta el segundo smoke en esta fase. Se termina únicamente con el checkpoint y el veredicto.

Parámetros preparados para la ejecución posterior (UNA SOLA VEZ, en una instrucción independiente):

- `campaign_id = 3`
- `lead_id = 1812`
- `plantilla_id = 2`
- `modo_test = 1`
- destinatario obtenido de `config.test_emails` → `estudioriobabel@gmail.com`
- variante determinística esperada: `C`