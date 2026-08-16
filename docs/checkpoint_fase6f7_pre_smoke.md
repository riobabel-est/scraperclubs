# FASE 6F.7 — Checkpoint PRE-SMOKE (SOLO LECTURA)

> NO se ejecutó ningún POST, ningún `enviar_lote.php`, ningún `cron`, ningún SMTP.
> Ninguna escritura en BD. Toda la información proviene de lectura (`SQLITE3_OPEN_READONLY`).

## A. Estado del sistema

### Configuración global
| Clave | Valor |
|---|---|
| `modo_entorno` | `produccion` |
| `motor_estado` | `pausado` |
| `lanzadera_delay` | `8` |
| `test_emails` | `estudioriobabel@hmail.com`, `ruyelcano@gmail.com`, `rodrigo@riobabel.com`, `hola@riobabel.com` |

### Campañas
| id | nombre | estado | entorno | activo |
|---|---|---|---|---|
| 1 | Experimento Fase 1 TEST | DRAFT | test | 1 |
| 2 | Piloto Comercial FutProtec 2026-08 | DRAFT | pilot | 1 |
| 3 | SMOKE TEST FutProtec 2026-08 | PILOT | test | 1 |

### Leads TEST 1809–1813
| id | nombre_club | email | estado_lead | es_duplicado |
|---|---|---|---|---|
| 1809 | TEST_CLUB_01_RealMadrid | test01@futprotec.local | 01 Sin Contactar | 0 |
| 1810 | TEST_CLUB_02_Barcelona | test02@futprotec.local | 01 Sin Contactar | 0 |
| 1811 | TEST_CLUB_03_Valencia | test03@futprotec.local | 01 Sin Contactar | 0 |
| 1812 | TEST_CLUB_04_Sevilla | test04@futprotec.local | 01 Sin Contactar | 0 |
| 1813 | TEST_CLUB_05_Bilbao | test05@futprotec.local | 01 Sin Contactar | 0 |

### Envíos existentes para campaign_id=3
| envio_id | lead_id | email | estado | resultado | variant | plantilla | smtp |
|---|---|---|---|---|---|---|---|
| 3 | 1809 | test01@futprotec.local | enviado | ACCEPTED | A | 2 | 1 |
| 4 | 1813 | test05@futprotec.local | enviado | ACCEPTED | B | 2 | 1 |
| 5 | 1811 | test03@futprotec.local | enviado | ACCEPTED | C | 2 | 1 |

### Idempotencia bloqueante (lead vs campaign_id=3)
| lead | estado |
|---|---|
| 1809 | BLOQUEANTE (envio_id=3, estado=enviado) |
| 1810 | LIMPIO |
| 1811 | BLOQUEANTE (envio_id=5, estado=enviado) |
| 1812 | LIMPIO |
| 1813 | BLOQUEANTE (envio_id=4, estado=enviado) |

### Plantillas activas
| id | nombre | tipo | categoria | test_ab | activo |
|---|---|---|---|---|---|
| 1 | Plantilla Principal | html | 01 Sin Contactar | 1 | 1 |
| 2 | Primer Contacto (ABC - Texto Plano) | texto_plano | 01 Sin Contactar | 1 | 1 |
| 3 | Seguimiento - Catalogo V4.3 | html | 02 Contactado | 0 | 1 |
| 4 | Objecion - Precio/Pedido Minimo V4.3 | texto_plano | 03 Respondió | 0 | 1 |
| 5 | WhatsApp - Saludo V4.3 | whatsapp | whatsapp | 0 | 1 |
| 6 | Prospección con precio | texto_plano | 01 Sin Contactar | 0 | 1 |
| 7 | Primera plantilla | texto_plano | 01 Sin Contactar | 1 | 1 |

Plantilla id=2: asunto, cuerpo, asunto_b, cuerpo_b, asunto_c, cuerpo_c presentes. test_ab=1.

### Cuentas SMTP activas (sin credenciales)
| id | email | host | puerto | seg | enviados_hoy | límite |
|---|---|---|---|---|---|---|
| 1 | rodrigo@getfutprotec.com | mail.getfutprotec.com | 465 | ssl | 3 | 50 |
| 2 | mario.ortiz@getfutprotec.com | mail.getfutprotec.com | 465 | ssl | 0 | 50 |
| 3 | alvaro.ruiz@getfutprotec.com | mail.getfutprotec.com | 465 | ssl | 1 | 50 |
| 4 | carlos.mora@getfutprotec.com | mail.getfutprotec.com | 465 | ssl | 0 | 50 |
| 5–10 | (resto activas) | mail.getfutprotec.com | 465 | ssl | 0 | 50 |

### modo_test efectivo
`modo_entorno` = `produccion` → `modo_test_efectivo` = **false** en BD.
(Puede forzarse `modo_test=1` vía POST, pero NO altera la validación de entorno de campaña.)

---

## B. Campaña exacta recomendada
`campaign_id = 3` — **SMOKE_TEST_FUTPROTEC_2026_08** (estado=PILOT, entorno=test, activo=1).

## C. Lead exacto recomendado
`id_club = 1810` (TEST_CLUB_02_Barcelona, test02@futprotec.local).
- Primero de `{1810, 1812}` que está LIMPIO de envío lógico en campaign_id=3.
- 1809, 1811, 1813 están bloqueados por idempotencia (envíos `enviado`).

## D. Plantilla exacta
`id_plantilla = 2` — Primer Contacto (ABC - Texto Plano).
- test_ab=1 y contiene las 3 variantes A/B/C. Coincide con la usada en los envíos previos de campagne 3.

## E. SMTP que se utilizaría (sin credenciales)
`id_cuenta_smtp = 1` — rodrigo@getfutprotec.com (3/50 hoy, capacidad disponible).
Alternativa con 0/50: `id=2` (mario.ortiz) o cualquiera activa con capacidad.

## F. Destinatario override
`test_email = estudioriobabel@hmail.com` (primer buzón de `test_emails`).
Con `modo_test=1`, `enviar_lote.php` sustituye el destinatario real por `test_email` (o `contactofutprotec@gmail.com` si no se envía override).

## G. Cadena completa del envío (estática)

Para `campaign_id=3`, `id_club=1810`, `modo_test=1`, `test_email=...`:

1. **Validación de campaña** → `validarCampanaActiva($db, 3, $modoEntornoBD)`
   - Con `modo_entorno=produccion`: `esEntornoCoherente('test','produccion')` → **ENVIRONMENT_MISMATCH** (BLOQUEO).
   - Con `modo_entorno=test`: OK → continua.
2. **Validación TEST/REAL** → `esElegibleParaEnvio(1810, 3)`
   - campaña TEST + lead TEST → `elegible` (PERMITIDO).
3. **Elegibilidad** → sin supresión, sin duplicado, email válido (test02@futprotec.local es sintácticamente válido).
4. **Plantilla** → id=2 activa.
5. **Variante** → `asignarVariante(1810, 3)` = **A** (determinístico).
6. **Selección SMTP** → cuenta elegida activa y con límite disponible.
7. **Sustitución de destinatario** → `modo_test=1` + `test_email` válido → envía a `test_email` (no al lead real).
8. **Reserva de envío** → `reservarEnvioLogico(1810, 3, ...)` crea fila `pendiente` (nuevo).
9. **Escritura en `envios`** → se actualiza a `enviado`/`ACCEPTED` tras SMTP.
10. **`comunicaciones_log`** → se inserta evento `envio_email` con resultado.
11. **Tracking** → píxel con `tracking_id` único.
12. **Estado del lead** → en `modo_test` NO se cambia `estado_lead` (solo nota de observaciones).

## H. Comprobaciones de seguridad (sin ejecutar)

- Prueba negativa: `campaign_id=3` + `id_club=1` (lead REAL) + `modo_test=1`
  → `esElegibleParaEnvio(1, 3)` = `lead_real_en_campana_test` (BLOQUEADO).
  El bloqueo es ANTES de SMTP.
  - Nota: con `modo_entorno=produccion`, la validación de campaña bloquearía antes por `ENVIRONMENT_MISMATCH`.
- `lead 1810 + campaign_id=2 (pilot)` → `lead_test_en_campana_no_test` (BLOQUEADO).
- `lead 1810 + campaign_id=3 (test)` → `elegible` (PERMITIDO).

## I. Resultado esperado
- Si `modo_entorno=test`: el smoke de `lead 1810` enviaría UN correo al override `test_email`, crearía `envios` (estado `enviado`, `ACCEPTED`, variant=A), registró en `comunicaciones_log`, y NO cambiaría el estado del lead. Repetir la misma llamada → bloqueado por idempotencia (la fila ya está `enviado`).
- Si `modo_entorno` permanece en `produccion`: el smoke quedaría BLOQUEADO en la validación de campaña (`ENVIRONMENT_MISMATCH`), antes de cualquier SMTP.

## J. Riesgos residuales
- `modo_entorno=produccion` es INCOMPATIBLE con la campaña TEST (id=3). Mientras no se cambie a `test`, el smoke no puede ejecutarse por coherencia de entorno.
- La plantilla id=2 es `texto_plano`; el contenido se envía como HTML por `enviar_lote.php` (MIME text/html). Comportamiento preexistente, no modificado.
- Los leads TEST `@futprotec.local` no reciben correo real (pasan por override en modo_test), pero su dirección sintáctica es válida; en la cadena positiva el destinatario real es sustituido.

## K. VEREDICTO

# BLOCKED

**Motivo:** `modo_entorno = produccion` + campaña smoke `entorno = test` producen
`ENVIRONMENT_MISMATCH` en `validarCampanaActiva()`. El smoke no puede ejecutarse
en el estado actual del sistema sin cambiar la configuración, lo cual NO está
autorizado en este checkpoint.

**Para desbloquear (requiere autorización explícita):** fijar
`config.modo_entorno = test` (o lanzar el smoke bajo un entorno coherente). No se
realiza aquí por estar fuera del alcance de SOLO LECTURA de esta fase.

NO SE HA REALIZADO NINGÚN ENVÍO SMTP.