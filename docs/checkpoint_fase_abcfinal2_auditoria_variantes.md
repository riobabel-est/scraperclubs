# Checkpoint FASE ABC-FINAL.2 — Auditoría final de variantes A/B/C (solo lectura)

- Fecha: 2026-08-16
- Entorno: `modo_entorno = test` / `motor_estado = pausado`
- Carácter de la fase: **SOLO LECTURA** (sin POST, sin SMTP, sin cron, sin Evolution API, sin escritura).

---

## 1. Registros auditados

`campaign_id=3`, `plantilla_id=2`, SMTP `rodrigo@getfutprotec.com` (smtp_id=1).

| envio_id | lead_id | variante registrada | asunto almacenado |
| -------: | ------: | ------------------- | ----------------- |
| 3 | 1809 | A | "Espinilleras personalizadas para TEST_CLUB_01_RealMadrid — Rentabilidad..." (A) |
| 4 | 1813 | B | "TEST_CLUB_05_Bilbao — Una espinillera con identidad propia..." (B) |
| 5 | 1811 | C | "TEST_CLUB_03_Valencia — La solucion mas sencilla para equipar a tu cantera..." (C) |
| 6 | 1810 | A | "Espinilleras personalizadas para TEST_CLUB_02_Barcelona — Rentabilidad..." (A) |
| 7 | 1812 | C | "TEST_CLUB_04_Sevilla — La solucion mas sencilla para equipar a tu cantera..." (C) |

---

## 2. Asignación determinística (función real `asignarVariante()`)

Función usada (real, `inc/abc.php`): `crc32(campaign . ':' . lead)` normalizado a 32-bit, `% 3` → A/B/C.

| lead_id | variante calculada | variante registrada | coincide |
| ------: | ------------------ | ------------------- | -------- |
| 1809 | A | A | SÍ |
| 1813 | B | B | SÍ |
| 1811 | C | C | SÍ |
| 1810 | A | A | SÍ |
| 1812 | C | C | SÍ |

Resultado: **PASS** (coincidencia total).

---

## 3. Cobertura A/B/C (columna `variant`)

- A: presente (envio 3, 6)
- B: presente (envio 4)
- C: presente (envio 5, 7)

Cobertura de etiquetas: **PASS**.

---

## 4. Contenido de plantilla 2

Plantilla `Primer Contacto (ABC - Texto Plano)`: activa (`activo=1`), `test_ab=1`, tipo `texto_plano`, categoría `01 Sin Contactar`.

Ubicación de variantes (columnas de `plantillas`):

| variante | asunto (columna) | cuerpo (columna) | bytes cuerpo | completa |
|----------|------------------|------------------|-------------:|----------|
| A | `asunto` | `cuerpo` | 710 | SÍ |
| B | `asunto_b` | `cuerpo_b` | 970 | SÍ |
| C | `asunto_c` | `cuerpo_c` | 1473 | SÍ |

Los tres cuerpos crudos tienen `md5` distintos y longitudes distintas → plantilla correctamente diferenciada.

Resultado plantilla: **PASS**.

---

## 5. Comparación de cuerpos realmente enviados (`envios.cuerpo_mensaje`)

Comparación por md5 de los cuerpos normalizados (tras eliminar píxel de tracking y fingerprint, variables por envío):

| envio_id | variante registrada | bytes cuerpo | md5 (normalizado) | variante inferida por contenido |
| -------: | ------------------- | -----------: | ----------------- | ------------------------------ |
| 3 | A | 710 | `48e74f...` | A |
| 4 | B | 710 | `48e74f...` | **A** |
| 5 | C | 710 | `48e74f...` | **A** |
| 6 | A | 708 | `8a742d...` | A |
| 7 | C | 1520 | `b38385...` | C |

**Hallazgo crítico**: los cuerpos de `envio_id=4` (etiqueta B) y `envio_id=5` (etiqueta C) son **idénticos al de `envio_id=3` (etiqueta A)** — md5 `48e74f...`, 710 bytes, contenido de la variante A. No contienen el cuerpo B (970 B) ni C (1473 B).

Conclusión de coherencia de contenido:

| envio_id | variante registrada | ¿cuerpo coherente con la variante? |
| -------: | ------------------- | ---------------------------------- |
| 3 | A | SÍ (contenido A) |
| 4 | B | **NO** (contiene contenido A) |
| 5 | C | **NO** (contiene contenido A) |
| 6 | A | SÍ (contenido A) |
| 7 | C | SÍ (contenido C) |

Resultado contenido enviado: **DISCREPANCIA** en envio_id=4 y envio_id=5 (registros históricos).

---

## 6. Diferenciación real A/B/C

- La **plantilla** diferencia A/B/C (cuerpos 710/970/1473, asuntos distintos).
- En los **registros almacenados**, los envíos etiquetados A (3), B (4) y C (5) comparten el **mismo cuerpo** (variante A). Por tanto, contra la evidencia de `envios.cuerpo_mensaje`, B y C **no están diferenciados** en esos registros históricos: son idénticos a A.
- Sólo `envio_id=6` (A) y `envio_id=7` (C) reflejan contenido correcto y diferenciado; no existe ningún registro almacenado con el cuerpo real de la variante B.

Resultado diferenciación: **BLOCKED** (las etiquetas B y C de los registros 4 y 5 no respaldan cuerpos diferentes; el cuerpo B no está presente en ninguno).

---

## 7. Smoke recibido (envio_id=7)

| campo | valor |
|-------|-------|
| variant | C |
| estado | enviado |
| resultado_envio | ACCEPTED |
| message_id | `<fut_6a81d4de_f9b717c1972a@getfutprotec.com>` (presente) |
| smtp_id | 1 |
| cuerpo coherente | SÍ (contenido C) |

Resultado smoke envio_id=7: **PASS** (evidencia disponible; sin conexión SMTP).

---

## 8. Integridad

- `MAX(envios.id)`: **7**
- `envios.id > 7`: **0**
- `envio_id=6` intacto: lead 1810, campaign 3, variant A, plantilla 2, smtp 1, `ACCEPTED`, `enviado`
- `envio_id=7` intacto: lead 1812, campaign 3, variant C, plantilla 2, smtp 1, `ACCEPTED`, `enviado`
- `campaign_id=3` intacta: `PILOT`, entorno `test`, activo `1`
- plantilla 2 intacta: activa, `test_ab=1`
- `modo_entorno`: `test`
- `motor_estado`: `pausado`

Resultado integridad: **PASS**.

---

## 9. Seguridad

```text
SMTP ejecutado: NO
POST ejecutado: NO
cron ejecutado: NO
nuevo envío: NO
nuevo lead: NO
config modificada: NO
plantilla modificada: NO
envio_id=6 modificado: NO
envio_id=7 modificado: NO
```

Todos los accesos fueron con `SQLITE3_OPEN_READONLY`. No se creó, modificó ni eliminó nada.

---

## 10. Tabla resultado

| envio_id | lead_id | variante registrada | variante calculada | plantilla | cuerpo coherente | SMTP |
| -------: | ------: | ------------------- | ------------------ | --------: | ---------------- | ---- |
| 3 | 1809 | A | A | 2 | SÍ (A) | ACCEPTED |
| 4 | 1813 | B | B | 2 | NO (contenido A) | ACCEPTED |
| 5 | 1811 | C | C | 2 | NO (contenido A) | ACCEPTED |
| 6 | 1810 | A | A | 2 | SÍ (A) | ACCEPTED |
| 7 | 1812 | C | C | 2 | SÍ (C) | ACCEPTED |

---

## 11. Veredicto

```text
A -> PASS
B -> BLOCKED
C -> BLOCKED
```

Resumen de criterios:

- Asignación determinística coincide: **PASS**
- Cobertura A/B/C (columna variant): **PASS**
- Plantilla 2 diferenciada: **PASS**
- Contenido almacenado coherente con la variante: **FALLO en envio_id=4 (B) y envio_id=5 (C)**
- Diferenciación real A/B/C en registros: **FALLO** (cuerpos de A/B/C idénticos en 3,4,5; sin cuerpo B real en ningún registro)

Veredicto global:

```text
BLOCKED
```

Existe discrepancia: los registros históricos etiquetados como variante B (envio_id=4) y variante C (envio_id=5) almacenan el cuerpo de la variante A, y ningún registro contiene el cuerpo real de la variante B. Los smokes recientes (envio_id=6 y envio_id=7) sí son correctos, lo que indica que la resolución de contenido por variante quedó corregida con posterioridad a la creación de los registros 3/4/5.

---

## Parada obligatoria

No se realizó ningún envío. No se crearon dummies. No se modificó campaña, plantillas, configuración, ni registros. Fase detenida.