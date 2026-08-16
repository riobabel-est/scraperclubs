# Checkpoint FASE ABC-FINAL.1 — Selección de 3 dummies A/B/C (solo lectura)

- Fecha: 2026-08-16
- Entorno: `modo_entorno = test` / `motor_estado = pausado`
- Carácter de la fase: **SOLO LECTURA** (sin POST, sin SMTP, sin cron, sin Evolution API, sin escritura en BD).

---

## A. Función real utilizada

Se cargó la función REAL existente `asignarVariante()` desde `public_html/outbound/inc/abc.php`:

```php
function asignarVariante(int $leadId, int $campaignId): string
{
    $h = crc32((string)$campaignId . ':' . (string)$leadId);
    if ($h < 0) { $h += 4294967296; }
    return ['A', 'B', 'C'][$h % 3];
}
```

Es determinística (hash `crc32` normalizado a 32-bit, `% 3` → A/B/C). No se forzó variante.

Verificación de coherencia con smokies previos:

- `asignarVariante(1810, 3)` = `A` (coincide con envio_id=6, primer smoke)
- `asignarVariante(1812, 3)` = `C` (coincide con envio_id=7, segundo smoke)

---

## B. Contexto confirmado

| elemento | valor |
|----------|-------|
| `modo_entorno` | `test` |
| `motor_estado` | `pausado` |
| campaña 3 | id=3, `SMOKE TEST FutProtec 2026-08`, estado `PILOT`, entorno `test`, activo `1` |
| plantilla 2 | `Primer Contacto (ABC - Texto Plano)`, activo `1`, `test_ab=1` |
| destinatario de pruebas | de `config.test_emails` (primer buzón `estudioriobabel@gmail.com`) |

---

## C. Universo de leads TEST

Total leads en `clubes_crm`: **1813**.

Leads TEST (según `esLeadTest()`: email `%@futprotec.local%` o nombre `test%`): **exactamente 5**:

| lead_id | club | email | estado_lead | duplicado |
|---|---|---|---|---|
| 1809 | TEST_CLUB_01_RealMadrid | test01@futprotec.local | 01 Sin Contactar | 0 |
| 1810 | TEST_CLUB_02_Barcelona | test02@futprotec.local | 01 Sin Contactar | 0 |
| 1811 | TEST_CLUB_03_Valencia | test03@futprotec.local | 01 Sin Contactar | 0 |
| 1812 | TEST_CLUB_04_Sevilla | test04@futprotec.local | 01 Sin Contactar | 0 |
| 1813 | TEST_CLUB_05_Bilbao | test05@futprotec.local | 01 Sin Contactar | 0 |

No existen más leads TEST fuera de este rango.

---

## D. Envíos lógicos existentes en campaign_id=3

| envio_id | lead_id | variant | estado |
|---|---|---|---|
| 3 | 1809 | A | enviado |
| 4 | 1813 | B | enviado |
| 5 | 1811 | C | enviado |
| 6 | 1810 | A | enviado |
| 7 | 1812 | C | enviado |

Los 5 leads TEST ya tienen envío lógico en `campaign_id=3`.

---

## E. Candidatos descartados y motivo

| lead_id | club | motivo |
|---|---|---|
| 1809 | TEST_CLUB_01_RealMadrid | excluido por rango 1809-1813 + ya con envío (envio_id=3) |
| 1810 | TEST_CLUB_02_Barcelona | excluido por rango 1809-1813 + ya con envío (envio_id=6) |
| 1811 | TEST_CLUB_03_Valencia | excluido por rango 1809-1813 + ya con envío (envio_id=5) |
| 1812 | TEST_CLUB_04_Sevilla | excluido por rango 1809-1813 + ya con envío (envio_id=7) |
| 1813 | TEST_CLUB_05_Bilbao | excluido por rango 1809-1813 + ya con envío (envio_id=4) |

Candidatos limpios restantes: **0**.

---

## F. Tabla resultado

| Rol dummy | lead_id | club | email | variante | elegible | motivo |
|---|---:|---|---|---|---|---|
| DUMMY A | — | — | — | — | — | no existe candidato limpio |
| DUMMY B | — | — | — | — | — | no existe candidato limpio |
| DUMMY C | — | — | — | — | — | no existe candidato limpio |

No se pudieron seleccionar dummies A/B/C limpios: los únicos 5 leads TEST están excluidos por rango y ya tienen envío lógico en `campaign_id=3`.

---

## G. Integridad y ausencia de escritura

- `MAX(envios.id)` **antes** de la consulta: `7`
- `MAX(envios.id)` **después** de la consulta: `7` (sin cambios)

`envio_id=6` y `envio_id=7` permanecen intactos:

| envio_id | estado | resultado_envio | lead_id | campaign_id | variant | plantilla_id | smtp_id |
|---|---|---|---|---|---|---|---|
| 6 | enviado | ACCEPTED | 1810 | 3 | A | 2 | 1 |
| 7 | enviado | ACCEPTED | 1812 | 3 | C | 2 | 1 |

Confirmación de no escritura: todos los accesos fueron con `SQLITE3_OPEN_READONLY`. No se ejecutó `enviar_lote.php`, ni POST, ni SMTP, ni cron, ni `enviar_smtp_random.php`, ni Evolution API. No se creó, modificó ni eliminó ningún lead, config, campaña, plantilla, envío o log.

---

## H. Veredicto

```text
BLOCKED
```

No se encontraron tres dummies A/B/C limpios para `campaign_id=3`: solo existen 5 leads TEST (1809–1813), todos excluidos por el rango indicado y todos con envío lógico previo en la campaña. No hay candidatos adicionales.

No se ejecutó ningún envío. Fase detenida.