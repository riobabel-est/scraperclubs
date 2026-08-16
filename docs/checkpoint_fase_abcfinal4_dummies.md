# Checkpoint FASE ABC-FINAL.4 — Creación controlada de dummies A/B/C

- Fecha: 2026-08-16
- Rol: ingeniero senior CRM Outbound FutProtec V4.3
- Carácter de la fase: **creación controlada de 3 leads TEST** (única escritura permitida).
  - **NO SMTP. NO POST de envío. NO cron. NO Evolution API. NO `enviar_lote.php`. NO `enviar_smtp_random.php`.**
  - **NO envíos nuevos. NO `reservarEnvioLogico()`. NO cambios en campaña / plantilla / configuración.**
- Artefactos creados en esta fase:
  - `scripts/fase_abcfinal4_preflight.php` (solo lectura)
  - `scripts/fase_abcfinal4_crear_dummies.php` (única escritura permitida: 3 leads)
  - `scripts/fase_abcfinal4_validacion.php` (solo lectura)
  - este documento de checkpoint

---

## A. Estado previo

Verificado antes de insertar (preflight solo lectura `SQLITE3_OPEN_READONLY`):

| Concepto | Valor observado | Esperado | OK |
|---|---|---|---|
| `config.modo_entorno` | `test` | `test` | SÍ |
| `config.motor_estado` | `pausado` | `pausado` | SÍ |
| Campaña `id=3` | `SMOKE TEST FutProtec 2026-08`, `estado=PILOT`, `entorno=test`, `activo=1` | PILOT / test / activo=1 | SÍ |
| Plantilla `id=2` | `Primer Contacto (ABC - Texto Plano)`, `activo=1`, `test_ab=1` | activa / test_ab=1 | SÍ |
| `cuerpo` (A) | 710 bytes | no vacío | SÍ |
| `cuerpo_b` (B) | 970 bytes | no vacío | SÍ |
| `cuerpo_c` (C) | 1473 bytes | no vacío | SÍ |
| `MAX(envios.id)` previo | 7 | 7 | SÍ |
| `COUNT(envios)` previo | 7 | 7 | SÍ |
| `COUNT(envios WHERE campaign_id=3)` previo | 5 | 5 | SÍ |

Comprobación de no existencia previa de dummies A/B/C (por `nombre_club` y por `email`):

| Dummy | nombre_club | email | COUNT(previo) |
|---|---|---|---|
| A | `TEST_ABC_FINAL4_A` | `test_abc_final4_a@futprotec.local` | 0 / 0 |
| B | `TEST_ABC_FINAL4_B` | `test_abc_final4_b@futprotec.local` | 0 / 0 |
| C | `TEST_ABC_FINAL4_C` | `test_abc_final4_c@futprotec.local` | 0 / 0 |

No existía ningún lead con prefijo `ABC_FINAL4` antes de esta fase.

---

## B. IDs creados

Se insertaron exactamente **3** leads TEST (sin crear más, sin envíos, sin `reservarEnvioLogico`):

| Dummy | lead_id real |
|---|---|
| DUMMY A | **1814** |
| DUMMY B | **1815** |
| DUMMY C | **1816** |

---

## C. Datos de los tres dummies

Estado verificado pos-creación (solo lectura):

| Dummy | lead_id | nombre_club | email interno | esLeadTest | es_duplicado | estado_lead |
|---|---|---|---|---|---|---|
| A | 1814 | `TEST_ABC_FINAL4_A` | `test_abc_final4_a@futprotec.local` | SÍ | NO | `Sin Contactar` |
| B | 1815 | `TEST_ABC_FINAL4_B` | `test_abc_final4_b@futprotec.local` | SÍ | NO | `Sin Contactar` |
| C | 1816 | `TEST_ABC_FINAL4_C` | `test_abc_final4_c@futprotec.local` | SÍ | NO | `Sin Contactar` |

Los tres son leads limpios: TEST, no duplicados, estado por defecto `Sin Contactar`.

---

## D. Variante calculada de cada dummy

Calculada exclusivamente con la función real `asignarVariante($leadId, 3)` (`inc/abc.php`), que aplica `crc32(campaign_id:lead_id) % 3`:

| Dummy | lead_id | `asignarVariante(lead_id, 3)` |
|---|---|---|
| A | 1814 | **C** |
| B | 1815 | **A** |
| C | 1816 | **C** |

Conteo resultante:

| Variante | Conteo | Esperado |
|---|---|---|
| A | 1 | 1 |
| B | 0 | 1 |
| C | 2 | 1 |

**Resultado: NO se obtuvo A/B/C exactamente una vez cada uno (falta B; hay dos C).**

---

## E. Elegibilidad

Elegibilidad real (`esElegibleParaEnvio($db, lead_id, 3)` del módulo `inc/eligibilidad.php`):

| Dummy | lead_id | Elegibilidad para campaign_id=3 |
|---|---|---|
| A | 1814 | **elegible** |
| B | 1815 | **elegible** |
| C | 1816 | **elegible** |

Los tres son elegibles para `campaign_id=3` (campaña TEST + leads TEST, sin supresión, sin duplicado, email válido).

---

## F. Correspondencia A/B/C

La correspondencia conceptual propuesta en la tarea exige como condición previa:

> "Si producen A/B/C exactamente una vez cada uno → asigna conceptualmente DUMMY A/B/C a los tres destinatarios."

Como el resultado fue **C, A, C** (no A/B/C exacto), **no aplica la asignación conceptual** a destinatarios reales. No se persiguió ni se persiste ningún destinatario real en los leads, y **no se modificó `test_emails`**.

Estado real de variantes (para referencia):

| Dummy | lead_id | variante calculada |
|---|---|---|
| DUMMY A | 1814 | C |
| DUMMY B | 1815 | A |
| DUMMY C | 1816 | C |

---

## G. Ausencia de envíos

| Comprobación | Valor | Esperado | OK |
|---|---|---|---|
| `COUNT(envios WHERE campaign_id=3 AND lead_id IN (1814,1815,1816))` | **0** | 0 | SÍ |
| `COUNT(envios WHERE id > 7)` | **0** | 0 | SÍ |
| `MAX(envios.id)` | **7** | 7 | SÍ |

No existe ningún envío lógico nuevo para `campaign_id=3` asociado a los tres dummies.

---

## H. MAX(envios.id)

```
MAX(envios.id) = 7
```

Se mantiene en 7, es decir, **no se creó ningún registro nuevo en `envios`**. La tabla `envios` conserva exactamente los mismos 7 registros previos.

Detalle de `envios` con `campaign_id=3` (sin cambios):

| envio_id | lead_id | variant | plantilla_id | smtp_id | estado | resultado_envio |
|---|---|---|---|---|---|---|
| 3 | 1809 | A | 2 | 1 | enviado | ACCEPTED |
| 4 | 1813 | B | 2 | 1 | enviado | ACCEPTED |
| 5 | 1811 | C | 2 | 1 | enviado | ACCEPTED |
| 6 | 1810 | A | 2 | 1 | enviado | ACCEPTED |
| 7 | 1812 | C | 2 | 1 | enviado | ACCEPTED |

---

## I. Seguridad

Confirmación explícita de que esta fase:

```text
SMTP                    = NO
POST de envío           = NO
cron                    = NO
Evolution API           = NO
enviar_lote.php         = NO
enviar_smtp_random.php  = NO
reservarEnvioLogico()   = NO
envios nuevos           = 0
campaña modificada      = NO
plantilla modificada    = NO
configuración modificada = NO
test_emails modificados = NO
```

Única escritura realizada: inserción de **3 leads TEST** en `clubes_crm` (`id` 1814, 1815, 1816), que es el objetivo explícito de la fase.

Verificación pos-creación de que no se alteró nada más:

| Concepto | Valor | Esperado |
|---|---|---|
| campaña 3 | `{"id":3,"estado":"PILOT","entorno":"test","activo":1}` | intacto |
| plantilla 2 | `{"id":2,"activo":1,"test_ab":1}` | intacto |
| `config.modo_entorno` | `test` | intacto |
| `config.motor_estado` | `pausado` | intacto |

---

## J. Parámetros preparados para los tres futuros smoke tests

> Acceso directo a BD habilitado. Todo el estado está preparado a excepción de la **variante B**, que no fue alcanzada con los 3 IDs creados.

Parámetros base preparados:

| Parámetro | Valor |
|---|---|
| `campaign_id` | 3 |
| `plantilla_id` | 2 |
| `modo_entorno` | test |
| `motor_estado` | pausado |
| `campaign.estado` | PILOT |
| `campaign.entorno` | test |
| `plantilla.test_ab` | 1 |

Destinatarios reales previstos (NO enviados, NO persistidos en leads):

| Rol futuro | Destinatario real | Requiere variante | ¿Cubierta por dummy creado? |
|---|---|---|---|
| SMOKE A | `estudiioriobabel@gmail.com` | A | SÍ (lead_id=1815, variante A) |
| SMOKE B | `ruyelcano@gmail.com` | B | **NO** (ningún dummy creado produce B) |
| SMOKE C | `rodrigo@riobabel.com` | C | SÍ (lead_id=1814 y 1816, variante C) |

Dummies creados con variante real:

| Dummy | lead_id | email interno | variante | Podría servir para |
|---|---|---|---|---|
| A | 1814 | `test_abc_final4_a@futprotec.local` | C | SMOKE C |
| B | 1815 | `test_abc_final4_b@futprotec.local` | A | SMOKE A |
| C | 1816 | `test_abc_final4_c@futprotec.local` | C | SMOKE C |

**Bloqueo para el smoke B:** se necesita un lead TEST cuyo hash `crc32(3:lead_id) % 3 == 1` (variante B). Ninguno de los tres IDs creados (1814, 1815, 1816) produce B. Según la regla de parada, **no se crearon más leads automáticamente**.

---

## K. Veredicto

```text
BLOCKED
```

Motivo: se crearon exactamente 3 dummies limpios y elegibles (1814, 1815, 1816), y todos los chequeos de integridad/seguridad pasan, **pero las variantes calculadas por `asignarVariante()` no son exactamente A/B/C**:

```
lead_id=1814 -> C
lead_id=1815 -> A
lead_id=1816 -> C
=> A=1, B=0, C=2
```

Por la regla de parada de la fase: NO se crearon más leads automáticamente y NO se realizó ningún envío. Se detiene la fase y se devuelve este informe completo.

### IDs reales

| Rol | lead_id real | variante calculada |
|---|---|---|
| DUMMY A | **1814** | C |
| DUMMY B | **1815** | A |
| DUMMY C | **1816** | C |

---

## Nota de estado final

- La tabla `clubes_crm` ahora contiene 3 leads TEST adicionales (1814, 1815, 1816) identificables inequívocamente por `nombre_club` = `TEST_ABC_FINAL4_{A,B,C}` y email `@futprotec.local`.
- `envios` permanece con **7 registros** y `MAX(envios.id)=7`.
- No se ejecutó ningún envío, no se tocó campaña/plantilla/configuración y no se alteraron los destinatarios reales ni `test_emails`.
- Para alcanzar el hito `ABC_DUMMIES_READY` (variantes A/B/C exactas), se requiere en una fase posterior la creación/identificación de un lead TEST cuya `asignarVariante(lead_id, 3) = 'B'`, con aprobación explícita.