# Checkpoint — Baseline Definitivo de Plataforma (FASE 6F.FINAL)

- Fecha: 2026-08-16
- Rol: responsable senior QA/operaciones CRM Outbound FutProtec V4.3
- Carácter: **solo lectura**. No se modificó código, BD, SMTP, campañas, plantillas, config ni leads.

---

## 1. Estado global

```text
modo_entorno = test
motor_estado = pausado
```

Resto de configuración verificada (sin cambios):

| Clave | Valor |
|---|---|
| modo_entorno | `test` |
| motor_estado | `pausado` |
| email_test | `contactofutprotec@gmail.com` |
| test_emails | 4 buzones (estudioriobabel, ruyelcano, rodrigo@riobabel, hola@riobabel) |
| delay_envio | `3` |
| lanzadera_delay | `8` |
| lote_envio | `10` |

---

## 2. Campañas

| id | nombre | estado | entorno | activo |
|---|---|---|---|---|
| 1 | Experimento Fase 1 TEST | DRAFT | test | 1 |
| 2 | Piloto Comercial FutProtec 2026-08 | DRAFT | **pilot** | 1 |
| 3 | SMOKE TEST FutProtec 2026-08 | **PILOT** | **test** | 1 |

- Campaña 2: `Piloto Comercial FutProtec 2026-08` / `DRAFT` / `pilot` / `activo=1` ✓
- Campaña 3: `SMOKE TEST FutProtec 2026-08` / `PILOT` / `test` / `activo=1` ✓

---

## 3. Envíos

```text
SELECT MAX(id), COUNT(*) FROM envios  →  8 / 8
```

| envio_id | lead | variant | resultado | campaign |
|---|---|---|---|---|
| 6 | 1810 | A | ACCEPTED | 3 |
| 7 | 1812 | C | ACCEPTED | 3 |
| 8 | 1817 | B | ACCEPTED | 3 |

- No existen envíos posteriores: `envios con id > 8 = 0` ✓
- Cuenta de emisión de los tres envíos: `rodrigo@getfutprotec.com` (smtp_id=1).

---

## 4. ABC

Evidencia operativa ya cerrada (checkpoint `docs/checkpoint_fase_abcfinal6_smoke_b.md`):

```text
A → envio 6 (lead 1810)
B → envio 8 (lead 1817)
C → envio 7 (lead 1812)
```

```text
ABC_OPERATIONAL_PASS
```

Checkpoint ABC-FINAL existente: **`docs/checkpoint_fase_abcfinal6_smoke_b.md`** (`ABC_OPERATIONAL_PASS` emitido por `scripts/fase_abcfinal6_postcheck.php`).

---

## 5. Recepción

No se realizó ninguna conexión en esta fase. Solo se documenta lo ya demostrado:

- A: smoke anterior.
- C: correo recibido en Gmail.
- B: correo recibido en Gmail y visible en captura aportada por el operador.

No se afirma DSN ni entrega automática.

```text
RECEPCION_TEST_COMPROBADA_MANUALMENTE = YES
```

---

## 6. Dummies TEST

| id | nombre_club | email | estado_lead |
|---|---|---|---|
| 1814 | TEST_ABC_FINAL4_A | test_abc_final4_a@futprotec.local | Sin Contactar |
| 1815 | TEST_ABC_FINAL4_B | test_abc_final4_b@futprotec.local | Sin Contactar |
| 1816 | TEST_ABC_FINAL4_C | test_abc_final4_c@futprotec.local | Sin Contactar |
| 1817 | TEST_ABC_FINAL6_B | test_abc_final6_b@futprotec.local | 01 Sin Contactar |

Todos siguen siendo TEST (nombre `TEST...` y/o email `@futprotec.local`).

---

## 7. Aislamiento TEST/REAL

Comprobado con la función real `esLeadTest()`:

```text
esLeadTest(1814) = true
esLeadTest(1815) = true
esLeadTest(1816) = true
esLeadTest(1817) = true
```

Contra campaña NO TEST (`campaign_id=2`), la elegibilidad bloquea a los cuatro:

```text
razon = lead_test_en_campana_no_test
```

No se envió nada.

---

## 8. Plantilla 2

```text
activo = 1
test_ab = 1
cuerpo  != ''  (710 bytes)
cuerpo_b != '' (967 bytes)
cuerpo_c != '' (1447 bytes)
```

---

## 9. SMTP

- Cuentas activas: **10**.
- Cuenta utilizada en los últimos envíos: **`rodrigo@getfutprotec.com`** (id=1), 6 envíos, último `envio_id=8`.
- Límite diario: `50`.
- `enviados_hoy` (cuenta id=1): `6`.

No se abrió ninguna conexión SMTP.

---

## 10. Evolution API

```text
Integración CRM ↔ Evolution API = NO EXISTE
```

---

## 11. Integridad

```text
envios MAX = 8                ✓
envios con id > 8 = 0         ✓
campaign 3 intacta            ✓
campaign 2 intacta            ✓
plantilla 2 intacta           ✓
config intacta                ✓
MAX(clubes_crm.id) = 1817     ✓
leads con id > 1817 = 0       ✓
```

---

## 12. Estado final operativo

| Área | Estado |
|---|---|
| SMTP | PASS |
| A/B/C | PASS |
| Recepción TEST | PASS (manual) |
| Tracking | NO VERIFICADO |
| Baja | NO VERIFICADA |
| Idempotencia | PASS |
| TEST/REAL isolation | PASS |
| Campaña TEST | PASS |
| Campaña comercial | PASS |
| Configuración | PASS |
| Evolution API | NO INTEGRADA |

---

## 13. Veredicto

```text
PLATFORM_BASELINE_PASS
```

---

## 14. Incidencias reales

Ninguna. Todas las comprobaciones básicas resultaron coherentes con el estado documentado.

## 15. Parada absoluta

No se modificó `modo_entorno`, `motor_estado`, campañas 2/3, plantillas, leads, SMTP ni Evolution API. No se comenzó producción ni se envió ningún correo.