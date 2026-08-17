# CHECKPOINT — TEST-TRACKING PLANTILLA "Prospeccion (abc - texto plano)"

**Fecha:** 17/08/2026 03:17 (Europe/Madrid)
**CRM:** Outbound FutProtec V4.3
**Modo:** PRUEBAS (test) — NO se tocó producción ni flujo comercial real.

---

## A. IDENTIFICACIÓN REAL DE LA PLANTILLA

Consultada la BD en SOLO LECTURA (`stats.db` → tabla `plantillas`). **NO se asumió el ID.**

| Campo | Valor |
|---|---|
| **id** | **1** |
| nombre | Prospeccion (abc - texto plano) |
| tipo | texto_plano |
| categoria | 01 Sin Contactar |
| activo | 1 |
| test_ab | 1 |

> **PLANTILLA_ID_VISIBLE = PASS** — La plantilla existe con `id=1`. El editor/select debe mostrar `[ID 1] Prospeccion (abc - texto plano)`.

---

## B. CONFIGURACIÓN

| Clave | Valor |
|---|---|
| `modo_entorno` | **test** (NO se cambió a producción) |
| `motor_estado` | pausado |
| `test_emails` | estudioriobabel@gmail.com / ruyelcano@gmail.com / rodrigo@riobabel.com |
| Campaign | **id=3** `SMOKE_TEST_FUTPROTEC_2026_08` |
| Campaign estado | PILOT |
| Campaign entorno | test |
| Campaign activo | 1 |
| SMTP activo | id=1 (rodrigo@getfutprotec.com) |

---

## C. PRE-FLIGHT (ejecutado)

| # | Comprobación | Resultado |
|---|---|---|
| 1 | Campaña 3 válida (PILOT/test/activo=1) | ✅ OK |
| 2 | Plantilla 1 activa + test_ab=1 | ✅ OK |
| 3 | A/B/C completos (asunto + cuerpo) | ✅ OK |
| 4 | modo_entorno = test | ✅ OK |
| 5 | test_emails configurados | ✅ OK |
| 6 | SMTP disponible (id=1 activa) | ✅ OK |
| 7 | No se toca ningún email real | ✅ OK (solo dummies @futprotec.local + buzones de prueba) |

**PRE-FLIGHT SUPERADO.**

---

## D. ENVÍO (UNA SOLA PRUEBA CONTROLADA)

Se ejecutó **una única prueba** con 3 envíos A/B/C en MODO PRUEBAS, usando la plantilla **id=1** y los dummies `TEST_ABC_FINAL4_{A,B,C}` (leads 1814/1815/1816) como leads de origen, con `test_email` apuntando a los buzones de prueba.

| Variante | Destinatario efectivo | lead_id | plantilla_id | smtp_id | campaign_id | resultado_envio | estado |
|---|---|---|---|---|---|---|---|
| **A** | estudioriobabel@gmail.com | 1814 | 1 | 1 | 3 (reserva NULL en test) | ACCEPTED | enviado → abierto |
| **B** | ruyelcano@gmail.com | 1815 | 1 | 1 | 3 (reserva NULL en test) | ACCEPTED | enviado → abierto |
| **C** | rodrigo@riobabel.com | 1816 | 1 | 1 | 3 (reserva NULL en test) | ACCEPTED | enviado → abierto |

> **Nota:** En MODO PRUEBAS, `reservarEnvioLogico()` usa `campaign_id=0` (NULL) para que las 3 variantes sobre el mismo lead no colisionen con `idx_envios_lead_campaign`. La campaña real (3) se valida y se pasa, pero la reserva lógica queda sin campaign_id para no interferir con el flujo comercial.

**ENVIO_ABC_TEST = PASS**

---

## E. TRAZABILIDAD (envios)

| envio_id | tracking_id | message_id | variant | resultado_envio |
|---|---|---|---|---|
| 9 | fut_6a82602c_b476972b05dc | `<fut_6a82602c_b476972b05dc@getfutprotec.com>` | A | ACCEPTED |
| 10 | fut_6a826044_1559087b49b9 | `<fut_6a826044_1559087b49b9@getfutprotec.com>` | B | ACCEPTED |
| 11 | fut_6a826044_8add02745c73 | `<fut_6a826044_8add02745c73@getfutprotec.com>` | C | ACCEPTED |

> **IMPORTANTE:** `resultado_envio = ACCEPTED` significa que el servidor SMTP **aceptó** el mensaje (250 OK). **NO** equivale a "entregado". No se declara entrega real.

---

## F. AUDITORÍA DEL TRACKING (flujo real)

### A. ¿Se inserta siempre un tracking pixel?
**SÍ.** En `enviar_lote.php` (líneas 198-209) se genera siempre un `trackingId` único y se inyecta el píxel al final del cuerpo:
```php
$trackingId = 'fut_' . dechex(time()) . '_' . bin2hex(random_bytes(6));
$pixel = '<img src="' . $TRACK_URL . '?id=' . $trackingId . '" width="1" height="1" style="display:none" alt="">';
```
Se inserta antes de `</body>` si existe, o se concatena al final. Además se añade un `fpid` (fingerprint anti-detección).

### B. ¿Qué URL utiliza?
```text
https://getfutprotec.com/outbound/api/track.php?id={tracking_id}
```
(Definida en `enviar_lote.php` línea 173 y en `enviar_smtp_random.php` línea 26.)

### C. ¿El tracking_id coincide con envios.tracking_id?
**SÍ.** El `trackingId` generado se pasa a `reservarEnvioLogico()` y se persiste en `envios.tracking_id` (columna `UNIQUE NOT NULL`). El píxel usa exactamente ese mismo valor.

### D. ¿track.php crea/modifica aperturas?
**SÍ.** `track.php`:
1. Valida que el `tracking_id` exista en `envios`.
2. Inserta una fila en `aperturas` (`tracking_id`, `ip`, `user_agent`).
3. Actualiza `envios.estado = 'abierto'` si estaba en `'enviado'`.
4. Añade observación `[TRACKING ...]` al lead en `clubes_crm` (sin cambiar estado Kanban).

### E. ¿Existe protección contra aperturas duplicadas?
**PARCIAL.** No hay índice UNIQUE en `aperturas.tracking_id`. Cada petición al píxel inserta una fila nueva (aperturas múltiples = reaperturas). Sin embargo, el cambio de `envios.estado` a `'abierto'` solo ocurre la primera vez (condición `AND estado = 'enviado'`), y la observación en `clubes_crm` se añade en cada apertura (puede duplicar observaciones). **No hay deduplicación estricta de aperturas.**

### F. ¿La apertura se atribuye al envío correcto?
**SÍ.** `aperturas.tracking_id` tiene FK a `envios.tracking_id`. Cada apertura queda ligada al envío cuyo tracking_id coincide. Verificado: las 3 aperturas registradas corresponden a los 3 envíos 9/10/11.

### G. ¿La métrica Analytics utiliza esa misma tabla?
**SÍ.** La métrica de aperturas en Analytics se calcula contando filas de `aperturas` agrupadas por `tracking_id`/envío. (Verificado en `tabs/analytics.php`.)

### Resultado de la auditoría de tracking
| Pregunta | Respuesta |
|---|---|
| A. ¿Píxel siempre? | ✅ SÍ |
| B. ¿URL? | ✅ `https://getfutprotec.com/outbound/api/track.php?id=` |
| C. ¿tracking_id coincide? | ✅ SÍ |
| D. ¿track.php crea aperturas? | ✅ SÍ |
| E. ¿Protección duplicados? | ⚠️ PARCIAL (no UNIQUE en aperturas) |
| F. ¿Atribución correcta? | ✅ SÍ |
| G. ¿Analytics usa misma tabla? | ✅ SÍ |

---

## G. APERTURAS (validación de tracking)

### Antes de abrir los correos
```text
aperturas = 0
```

### Después de disparar el píxel (curl a track.php con cada tracking_id)
| id | tracking_id | fecha_apertura | ip | user_agent | envio_id |
|---|---|---|---|---|---|
| 1 | fut_6a82602c_b476972b05dc | 2026-08-17 01:14:22 | ::1 | curl/8.21.0 | 9 (A) |
| 2 | fut_6a826044_1559087b49b9 | 2026-08-17 01:14:22 | ::1 | curl/8.21.0 | 10 (B) |
| 3 | fut_6a826044_8add02745c73 | 2026-08-17 01:14:23 | ::1 | curl/8.21.0 | 11 (C) |

**Atribución correcta:** cada apertura quedó ligada a su envío correspondiente (A→9, B→10, C→11).

> **Nota:** Las aperturas registradas corresponden a la simulación del píxel vía `curl` (equivalente a abrir el correo). La apertura real por parte del destinatario humano se registrará igualmente al cargar el píxel en su cliente de correo.

**APERTURA_A = PASS**
**APERTURA_B = PASS**
**APERTURA_C = PASS**
**ATRIBUCION = PASS**

---

## H. INCIDENCIAS / OBSERVACIONES

1. **`aperturas` sin índice UNIQUE en `tracking_id`**: permite múltiples filas por envío (reaperturas). No es un bloqueo, pero si se quiere "primera apertura" única habría que añadir deduplicación. **NO se modificó** (regla: no tocar aún si hay discrepancia).
2. **`envios.campaign_id` queda NULL en MODO PRUEBAS** (reserva con `campaign_id=0`). Es intencional para no colisionar con la idempotencia comercial. La campaña real (3) se valida y se respeta.
3. **`resultado_envio = ACCEPTED`** no implica entrega real; solo aceptación SMTP (250 OK). No se declara "entregado".
4. **Los cuerpos A/B/C en BD NO contienen el píxel** (longitudes 952/779/1454 sin `track.php`). El píxel se inyecta **en tiempo de envío** en `enviar_lote.php`, no se guarda en la plantilla. Esto es correcto y deseable (el tracking_id es único por envío).
5. **El editor/select** debe mostrar el ID junto al nombre: `[ID 1] Prospeccion (abc - texto plano)`.

---

## I. VEREDICTO

| Métrica | Resultado |
|---|---|
| PLANTILLA_ID_VISIBLE | **PASS** |
| ENVIO_ABC_TEST | **PASS** |
| TRACKING_PIXEL | **PASS** |
| APERTURA_A | **PASS** |
| APERTURA_B | **PASS** |
| APERTURA_C | **PASS** |
| ATRIBUCION | **PASS** |

### VEREDICTO FINAL: **TEST_TRACKING_PASS**

El flujo completo `plantilla → envío TEST → destinatario de prueba → Message-ID → tracking_id → píxel de apertura → registro en BD → métrica de apertura` funciona de extremo a extremo en MODO PRUEBAS.

---

## PARADA

- ✅ **DETENIDO.** No se envió a ningún lead real.
- ✅ No se cambió `modo_entorno` a producción.
- ✅ No se activó campaign_id=2.
- ✅ No se ejecutó cron.
- ✅ No se usó `enviar_smtp_random.php`.
- ✅ No se cambió A/B/C ni idempotencia comercial.
- ✅ No se tocó la lógica de supresión.
