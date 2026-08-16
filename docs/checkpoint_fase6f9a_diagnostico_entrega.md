# FASE 6F.9A — DIAGNÓSTICO DE ENTREGA (SOLO LECTURA)

> Fase exclusivamente de diagnóstico. NO se ha ejecutado SMTP, ni `enviar_lote.php`,
> ni `cron.php`, ni POST, ni escritura en BD, ni modificación de código, configuración
> o credenciales. Toda la evidencia proviene de lecturas en `SQLITE3_OPEN_READONLY`
> y de inspección de código.

---

## A. Estado del envío 6

Reconstrucción exacta desde BD (`envios` + `comunicaciones_log` + `clubes_crm` + `cuentas_smtp`):

### Tabla `envios` — fila `id = 6`

| Campo | Valor |
|---|---|
| id | 6 |
| club | TEST_CLUB_02_Barcelona |
| email | test02@futprotec.local |
| federacion | Federació Catalana de Futbol |
| cuenta_emision | rodrigo@getfutprotec.com |
| fecha_envio | 2026-08-16 01:27:00 |
| estado | enviado |
| tracking_id | fut_6a8111e4_2306cee0a376 |
| asunto | Espinilleras personalizadas para TEST_CLUB_02_Barcelona — Rentabilidad para el club \| FutProtec |
| cuerpo_mensaje | 878 bytes (texto HTML con píxel de tracking) |
| lead_id | 1810 |
| campaign_id | 3 |
| variant | A |
| plantilla_id | 2 |
| smtp_id | 1 |
| message_id | `<fut_6a8111e4_2306cee0a376@getfutprotec.com>` |
| resultado_envio | ACCEPTED |
| fecha_resultado_envio | 2026-08-16 01:27:01 |

### Registro de comunicación — `comunicaciones_log` (único para este envío)

| Campo | Valor |
|---|---|
| id | 29 |
| lead_id | 1810 |
| club_id | 1810 |
| tipo_evento | envio_email |
| plantilla_id | 2 |
| detalles | `Envío a test02@futprotec.local con plantilla Primer Contacto (ABC - Texto Plano)` |
| fecha | 2026-08-16 01:27:01 |
| id_cuenta_smtp | 1 |
| tipo | email |
| resultado | exito |
| codigo_error | (vacío) |
| variante_ab | A |
| canal | email |

> **Observación clave:** `comunicaciones_log.detalles` registra el email **lógico** del lead
> (`test02@futprotec.local`), NO el destinatario efectivo del SMTP. La tabla
> `comunicaciones_log` NO conserva columna de destinatario efectivo. El destinatario
> efectivo SOLO queda registrado en la nota de `clubes_crm.observaciones`.

### Nota en `clubes_crm` (lead 1810)

`observaciones = "[TEST 16/08 01:27] Email de prueba enviado a estudioriobabel@hmail.com con plantilla 'Primer Contacto (ABC - Texto Plano)' (lead original: test02@futprotec.local)"`

Este es el **único registro persistente del destinatario efectivo**: `estudioriobabel@hmail.com`.

### SMTP utilizado (sin credenciales)

| Campo | Valor |
|---|---|
| id | 1 |
| email | rodrigo@getfutprotec.com |
| host | mail.getfutprotec.com |
| puerto | 465 |
| seguridad | ssl |
| activa | 1 |
| limite_diario | 50 |
| enviados_hoy | 4 (tras el smoke) |
| ultimo_error | NULL |
| ultimo_uso | 2026-08-16 01:27:01 |

### Resumen del envío 6

- **Destinatario lógico del lead:** `test02@futprotec.local`
- **Destinatario efectivo registrado (SMTP):** `estudioriobabel@hmail.com`
- **Remitente:** `rodrigo@getfutprotec.com` vía `mail.getfutprotec.com:465` (SSL)
- **Message-ID:** `<fut_6a8111e4_2306cee0a376@getfutprotec.com>`
- **Resultado SMTP:** `ACCEPTED` (respuesta `250` a DATA)
- **Timestamp:** envio 01:27:00, resultado 01:27:01 (2026-08-16)
- **Código/respuesta SMTP almacenado:** NO hay. `resultado_envio` solo guarda el enum `ACCEPTED`; la respuesta raw SMTP no se persiste. El campo `codigo_error` del log está vacío.

---

## B. Destinatario real utilizado

**Valor exacto:** `estudioriobabel@hmail.com`

**Fuente del valor (prioridad real del código):** el override POST `test_email`.

Lógica real en `public_html/outbound/api/enviar_lote.php` (líneas 203-211):

```php
$testEmailOverride = trim($_POST['test_email'] ?? '');
if ($modoTest && $testEmailOverride !== '' && filter_var($testEmailOverride, FILTER_VALIDATE_EMAIL)) {
    $emailDestino = $testEmailOverride;                       // ← PRIORIDAD 1 (POST test_email)
} elseif ($modoTest) {
    $emailDestino = 'contactofutprotec@gmail.com';            // ← PRIORIDAD 2 (fallback LITERAL)
} else {
    $emailDestino = $emailClub;                               // ← solo modo real
}
```

El smoke se ejecutó con `modo_test=1` y `test_email=estudioriobabel@hmail.com` en el body del POST.
Como `filter_var('estudioriobabel@hmail.com', FILTER_VALIDATE_EMAIL)` devuelve válido y
`$modoTest === true`, el código eligió **el override POST** (prioridad 1).

### Prioridad efectiva del destinatario (demostrada con código)

1. `$_POST['test_email']` (si `modo_test` activo y el valor es email válido) → **usado**
2. literal hardcodeado `contactofutprotec@gmail.com` (si `modo_test` activo y no hay override válido)
3. email original del lead `test02@futprotec.local` (solo si NO es modo test)

> **Aclaración sobre `config.email_test`:** la clave `config.email_test` (`contactofutprotec@gmail.com`)
> está sembrada por `init_db.php`, pero **NO es leída por `enviar_lote.php`**. El fallback de modo test
> es un **literal hardcodeado** `'contactofutprotec@gmail.com'` en el propio código (línea 208), no la clave de config.
>
> **Aclaración sobre `config.test_emails`:** la clave `config.test_emails` **tampoco es leída por el backend**.
> Se consume SOLO en el frontend (`js/app.js`, getter `testEmailsList`) para rellenar el `POST test_email`
> de la lanzadera. Es decir, `test_emails` influye de forma indirecta (a través del POST) y nunca es un
> fallback automático del backend.

---

## C. Destinatario correcto esperado

`estudioriobabel@gmail.com`

---

## D. Por qué se utilizó el destinatario incorrecto

Se utilizó `estudioriobabel@hmail.com` **únicamente** porque ese fue el valor literal
pasado como `test_email` en el POST del smoke y como `$TEST_EMAIL` hardcodeado en el
pre-flight. Evidencias:

1. **`scripts/fase6f9_preflight.php` (línea 51)** hardcodea:
   ```php
   $TEST_EMAIL = 'estudioriobabel@hmail.com';
   ```
   y lo usa para mostrar "destinatario final previsto" y validar que coincida. No lee la BD para este valor.

2. **`docs/checkpoint_fase6f9_smoke_controlado.md` (línea 22)** documenta explícitamente el POST ejecutado:
   ```
   POST enviar_lote.php con ... test_email=estudioriobabel@hmail.com — UNA SOLA VEZ.
   ```

3. **`config.test_emails`** ya contenía `estudioriobabel@hmail.com` como **primer buzón** antes del smoke
   (verificado en `docs/checkpoint_fase6f7_pre_smoke.md`, sección A y F). Es decir, el valor
   `@hmail.com` **ya estaba presente en configuración ANTES del smoke**; NO fue introducido
   aleatoriamente por el backend. El backend simplemente obedeció el override POST con ese valor.

4. **La cadena `estudioriobabel@gmail.com` NO existe en ningún punto del repositorio**
   (ni código, ni scripts, ni configuración, ni documentación). Búsqueda exhaustiva: 0 coincidencias.

**Conclusión demostrable:** el origen es un **dato incorrecto del buzón** (se tecleó `@hmail.com`
en lugar de `@gmail.com`), que se propagó a `config.test_emails` (primer elemento) y al script
`fase6f9_preflight.php`/POST. La lógica de selección de destinatario del backend es correcta y
hizo exactamente lo que se le indicó.

---

## E. Estado SMTP

`resultado_envio = ACCEPTED` **significa únicamente que el servidor SMTP emisor aceptó el mensaje**,
no que se entregara en buzón.

En `enviarSMTPAutenticado()` (líneas 488-501), el único criterio de éxito es:

```php
$dataResp = $read();                                  // respuesta tras enviar el mensaje
$sendOk = str_contains($dataResp, '250');             // 250 OK esperado
```

### Distinción de etapas

| Etapa | ¿La conoce el CRM? | Evidencia |
|---|---|---|
| aplicación → servidor SMTP | SÍ (conexión `stream_socket_client`) | línea 414 |
| servidor SMTP → aceptación (`250` a DATA) | SÍ (**esto es `ACCEPTED`**) | línea 492 |
| servidor SMTP → entrega final al dominio destino | **NO** | no hay consulta posterior |
| entrega final → buzón Gmail del destinatario | **NO** | no hay webhook/IMAP/notificación |

El CRM **únicamente** distingue "el servidor SMTP emisor aceptó el mensaje" (`ACCEPTED`) de
"no lo aceptó" (`FAILED`). No existen tablas de estado de entrega final: la tabla `rebotes`
está vacía (0 filas), `respuestas` vacía (0 filas) y `aperturas` vacía (0 filas). No hay
ningún mecanismo de `DSN`/bounce processing configurado.

Por tanto: **`ACCEPTED` ≠ entregado en buzón Gmail**. `ACCEPTED` solo garantiza que
`mail.getfutprotec.com` devolvió `250 OK` y tomó posesión del mensaje para encolarlo.

---

## F. Evidencia de logs

### Log de archivo local
`public_html/outbound/logs/envios_2026-08-16.log` contiene UNA única línea:

```
[2026-08-16 01:27:01] ✅ OK | Club: TEST_CLUB_02_Barcelona | Email: test02@futprotec.local | SMTP: rodrigo@getfutprotec.com | Tracking: fut_6a8111e4_2306cee0a376
```

- Confirma resultado OK (éxito del proceso de envío).
- Registra email **lógico** del lead (`test02@futprotec.local`), NO el destinatario efectivo.
- El Message-ID **no** se escribe en este log (solo el `tracking_id`).

### Búsqueda del Message-ID
- `<fut_6a8111e4_2306cee0a376@getfutprotec.com>` aparece **solo en `envios.message_id` (id=6)**. Coincidencia: 1 fila.
- El Message-ID se genera en `generarMessageIdEnvio()` (en `inc/respuestas.php`), derivado de
  `tracking_id + dominio del SMTP` → `<tracking_id@getfutprotec.com>`.
- SÍ se envía por SMTP: en el bloque de construcción del mensaje (líneas 478-480) se añade
  `Message-ID: <...>` al cuerpo del mensaje enviado. Por tanto el Message-ID registrado en BD
  fue realmente incluido en el email transmitido.

### Búsqueda de destinatarios en logs/BD
- `estudioriobabel@hmail.com`: presente en
  - `clubes_crm.observaciones` (lead 1810) — nota de test,
  - `config.test_emails` (primer elemento),
  - scripts/docs (`fase6f9_preflight.php`, `checkpoint_fase6f7_pre_smoke.md`, `checkpoint_fase6f9_smoke_controlado.md`).
- `estudioriobabel@gmail.com`: **0 coincidencias** en todo el repositorio.
- `ACCEPTED`: en `envios.resultado_envio` de los envíos 3, 4, 5 y 6 (todos campaign_id=3).

### Respuestas/bounces/aperturas
- `rebotes`: 0 filas. `respuestas`: 0 filas. `aperturas`: 0 filas.
- No existe evidencia local de bounce, error posterior ni acuse de entrega para `envio_id=6`.

---

## G. Causa probable

**Clasificación: configuración incorrecta (el buzón literal es erróneo), agravada por
parámetro POST incorrecto.**

- No es un bug de lógica de selección: la prioridad del código es correcta y se respetó.
- No es un problema de fallback: el fallback (`contactofutprotec@gmail.com`) ni siquiera se activó.
- No es un problema SMTP: el servidor aceptó el mensaje (`250`) y no hay error registrado.
- Es un **dato incorrecto** del buzón de prueba: se usó el literal `estudioriobabel@hmail.com`
  (no existente/incorrecto) en lugar de `estudioriobabel@gmail.com`. Ese literal mal escrito
  ya estaba en `config.test_emails` (primer elemento) antes del smoke y se reutilizó como
  `test_email` en el POST.

Matiz de evidencia: podemos **demostrar** que el backend usó `@hmail.com` porque fue lo que
recibió por POST (código + nota en BD + docs). No podemos **demostrar desde logs SMTP** si el
mensaje acabó rebotado por destinatario inexistente, porque el CRM no persiste respuestas SMTP
raw ni procesa bounces.

---

## H. Riesgo

- **Este smoke (envio_id=6):** afectado (el correo fue dirigido al buzón erróneo `@hmail.com`).
- **Todos los smoke TEST de la lanzadera (UI, `modo_test=1`):** en RIESGO mientras el primer
  elemento de `config.test_emails` siga siendo `estudioriobabel@hmail.com`, porque la lanzadera
  hace round-robin `testEmailsList[i % length]` empezando por la primera entrada. Un smoke desde
  la UI volvería a elegir `@hmail.com` como primer destino.
- **Envíos comerciales futuros (producción):** NO afectados por este dato. En modo real
  (`modo_test` = false), `enviar_lote.php` usa `$emailClub` (el email real del lead). La clave
  `test_emails` y el override `test_email` solo se aplican cuando `modo_test` está activo.
  > A excepción de `enviar_smtp_random.php`, que tiene su propio override de TEST a
  > `contactofutprotec@gmail.com` (independiente de `test_emails`); no usa `@hmail.com`.

---

## I. Corrección recomendada (NO implementada en esta fase)

1. Corregir el buzón mal escrito en `config.test_emails`: sustituir `estudioriobabel@hmail.com`
   por `estudioriobabel@gmail.com` (vía UI de "Destinos de Prueba" o update_config autorizado).
2. Inventariar el resto de buzones de `test_emails` para detectar otros dominios inexistentes/incorrectos.
3. Corregir el literal hardcodeado en `scripts/fase6f9_preflight.php` (y cualquier futuro script
   de smoke) para que lea el buzón desde config o use `estudioriobabel@gmail.com`.
4. (Mejora a valorar aparte, NO en esta fase) Persistir el destinatario efectivo en
   `envios`/`comunicaciones_log`, y añadir captura de bounces/DSN para poder distinguir
   `ACCEPTED` de "entregado" / "rebotado".

> Todo lo anterior queda PENDIENTE de autorización explícita. No se aplica en esta fase.

---

## J. Integridad

Confirmado durante todo el diagnóstico:

- **BD sin modificaciones:** todas las consultas con `SQLITE3_OPEN_READONLY`. Cero escrituras.
- **Configuración sin modificaciones:** `modo_entorno=test`, `motor_estado=pausado`,
  `email_test=contactofutprotec@gmail.com`, `test_emails` sin cambios.
- **Código sin modificaciones:** ningún archivo `public_html/` ni `scripts/` alterado.
- **SMTP sin ejecución:** no se abrió ninguna conexión SMTP.
- **Ningún nuevo envío:** no se llamó a `enviar_lote.php` ni se creó ningún lead/envío.
- **Ningún cron:** no se ejecutó `cron.php` ni `enviar_smtp_random.php`.
- **`envio_id=6` intacto:** no se eliminó ni alteró.

---

## PARADA OBLIGATORIA

Diagnóstico finalizado. No se corrige, no se reenvía, no se toca `modo_entorno`, no se
elimina `envio_id=6`, no se crea otro registro. Se espera autorización explícita para la
fase de corrección.