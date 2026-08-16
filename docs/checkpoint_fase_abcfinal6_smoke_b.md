# Checkpoint FASE ABC-FINAL.6 — Creación y smoke operativo de variante B

- Fecha: 2026-08-16
- Rol: ingeniero senior CRM Outbound FutProtec V4.3
- Carácter de la fase: **1 escritura de lead + 1 envío SMTP real** al destinatario de `config.test_emails` (única excepción explícita a no-SMTP de las fases anteriores).

---

## 1. Objetivo

Cerrar la validación operativa A/B/C demostrando que la variante **B** llega con **cuerpo B** (no cuerpo A), usando exclusivamente el flujo real `asignarVariante()` → `resolverContenidoVariante()` → `reservarEnvioLogico()` → SMTP de `api/enviar_lote.php`.

---

## 2. Fase 1 — Precheck (solo lectura)

`scripts/fase_abcfinal6_preflight.php` → **READY_FOR_ABC_FINAL6_SMOKE_B**.

| # | Comprobación | Valor | Resultado |
|---|---|---|---|
| 1 | `config.modo_entorno` | `test` | PASS |
| 2 | `config.motor_estado` | `pausado` | PASS |
| 3 | `MAX(clubes_crm.id)` | `1816` | PASS |
| 4 | `lead_id=1817` no existe | `COUNT=0` | PASS |
| 5 | campaña 3 (`validarCampanaActiva`) | `PILOT`/`test`/`activo=1` | PASS |
| 6 | plantilla 2 | `activo=1`, `test_ab=1`, variante B completa | PASS |
| 7 | `asignarVariante(1817,3)` | `B` | PASS |
| 8 | destinatario `config.test_emails[0]` | `estudioriobabel@gmail.com` | PASS |
| 9 | SMTP con capacidad | `id=1` (`rodrigo@getfutprotec.com`, capacidad 48) | PASS |
| 10 | `MAX(envios.id)` | `7` | PASS |
| 11 | `envio_id=6` (A) y `envio_id=7` (C) | intactos | PASS |

---

## 3. Fase 2 — Creación del dummy B (única escritura de lead)

`scripts/fase_abcfinal6_crear_dummy_b.php` → **DUMMY_B_CREATED**.

| Campo | Valor |
|---|---|
| `lead_id` real | **1817** |
| `nombre_club` | `TEST_ABC_FINAL6_B` |
| `email` | `test_abc_final6_b@futprotec.local` |
| `estado_lead` | `01 Sin Contactar` |
| `es_duplicado` | `0` |
| `asignarVariante(1817,3)` | `B` |
| `esElegibleParaEnvio(1817,3)` | `elegible` |
| `COUNT(envios lead 1817 + campaign 3)` | `0` |

No se creó ningún otro lead.

---

## 4. Fase 3 — Smoke SMTP único real

`scripts/fase_abcfinal6_smoke_b.php` ejecuta el flujo REAL `api/enviar_lote.php` (require directo), con:

```text
campaign_id = 3
id_club = 1817
id_plantilla = 2
id_cuenta_smtp = 1
modo_test = 1
test_email = (resuelto desde config.test_emails, primer buzón)
variante_ab = B
```

- Destinatario efectivo: **resuelto desde `config.test_emails`** (sin literal en el wrapper), con `modo_test=1`.
- `variante_ab=B` no fuerza la variante: `enviar_lote.php` recalcula `asignarVariante(1817,3) = B` (inmutabilidad en `reservarEnvioLogico`).

Respuesta JSON del flujo real:

```json
{"ok":true,"envio_exitoso":true,"estado":"enviado","error_smtp":"","club":"TEST_ABC_FINAL6_B","email":"test_abc_final6_b@futprotec.local","cuenta_smtp":"rodrigo@getfutprotec.com","cuenta_id":1,"timestamp":"2026-08-16 18:08:24"}
```

- **SMTP:** `ACCEPTED` (respuesta `250` a DATA del relay `mail.getfutprotec.com:465`).
- **Único POST/ejecución** de la fase. No se usó `cron.php`, `enviar_smtp_random.php` ni Evolution API.

---

## 5. Fase 4 — Postcheck (solo lectura)

`scripts/fase_abcfinal6_postcheck.php` → **ABC_OPERATIONAL_PASS**.

### 5.1 Envío nuevo (exactamente uno)

| Campo | Valor |
|---|---|
| `envio.id` | **8** |
| `lead_id` | 1817 |
| `campaign_id` | 3 |
| `variant` | **B** |
| `plantilla_id` | 2 |
| `smtp_id` | 1 |
| `estado` | `enviado` |
| `resultado_envio` | `ACCEPTED` |
| `message_id` | `<fut_6a81fc98_3dde4b459d69@getfutprotec.com>` |
| `tracking_id` | `fut_6a81fc98_3dde4b459d69` |
| `asunto` | `TEST_ABC_FINAL6_B — Una espinillera con identidad propia | FutProtec` |
| `cuerpo_mensaje` | 1149 bytes |

### 5.2 Contenido por variante

| Comprobación | Resultado |
|---|---|
| `asunto` almacenado == `asunto_b` resuelto | SÍ |
| `cuerpo_mensaje` == `cuerpo_b` resuelto | SÍ |
| `cuerpo_mensaje` != `cuerpo` (A) | SÍ |
| `cuerpo_mensaje` != `cuerpo_c` (C) | SÍ |

Demuestra operativamente que **B llega con cuerpo B, no con cuerpo A**.

### 5.3 Integridad

| Comprobación | Resultado |
|---|---|
| `MAX(envios.id)` | `8` (solo +1) |
| `COUNT(envios campaign_id=3 AND id>7)` | `1` |
| `envio_id=6` (A, lead 1810) | intacto |
| `envio_id=7` (C, lead 1812) | intacto |
| Ningún otro lead enviado | SÍ |

---

## 6. Fase 5 — Estado operativo intacto

```text
modo_entorno = test        (sin cambios)
motor_estado = pausado     (sin cambios)
campaña 3    = PILOT / test / activo=1  (sin cambios)
plantilla 2  = activo=1 / test_ab=1     (sin cambios)
```

No se modificó `modo_entorno`, `motor_estado`, campaña, plantilla, destinatarios de test ni motor de envío.

---

## 7. Seguridad

```text
leads creados            = 1 (lead 1817, dummy B)
envíos SMTP realizados   = 1 (envio_id=8, variante B)
cron                     = NO
enviar_smtp_random.php   = NO
Evolution API            = NO
segundo POST de envío    = NO
campaña/plantilla/config = NO modificadas
destinatarios reales     = NO usados (solo config.test_emails[0])
```

---

## 8. Veredicto

```text
ABC_OPERATIONAL_PASS
```

Evidencia operativa completa del ciclo A/B/C:

| Variante | Evidencia |
|---|---|
| A | `envio_id=6` (lead 1810, cuerpo A) — smoke previo |
| B | `envio_id=8` (lead 1817, cuerpo B) — **esta fase** |
| C | `envio_id=7` (lead 1812, cuerpo C) — smoke previo |

La variante B quedó validada en ejecución real: `asignarVariante(1817,3)=B` → `resolverContenidoVariante()` selecciona `asunto_b`/`cuerpo_b` → `reservarEnvioLogico()` persiste ese cuerpo → SMTP recibe y devuelve `ACCEPTED`.

---

## 9. Artefactos de la fase

- `scripts/fase_abcfinal6_preflight.php` (solo lectura)
- `scripts/fase_abcfinal6_crear_dummy_b.php` (única escritura de lead)
- `scripts/fase_abcfinal6_smoke_b.php` (smoke SMTP único vía flujo real `enviar_lote.php`)
- `scripts/fase_abcfinal6_postcheck.php` (solo lectura)
- este documento de checkpoint

Sintaxis verificada (`php -l`) en los cuatro scripts.
