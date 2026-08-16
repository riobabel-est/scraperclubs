# Checkpoint FASE ABC-FINAL.3 — Auditoría solo lectura del flujo actual A/B/C

- Fecha: 2026-08-16
- Rol: ingeniero senior CRM Outbound FutProtec V4.3
- Carácter de la fase: **SOLO LECTURA** (sin POST, sin SMTP, sin cron, sin Evolution API, sin escritura en BD).
- Único artefacto creado: este documento de checkpoint (requerido por la sección J).

---

## 1. Objetivo

Determinar si, con el código actualmente instalado, el flujo:

```
asignarVariante()
→ resolverContenidoVariante()
→ sustitución de variables
→ generación de $asunto / $cuerpo
→ almacenamiento en envios.cuerpo_mensaje
→ envío SMTP
```

utiliza realmente la variante seleccionada (A→`cuerpo`, B→`cuerpo_b`, C→`cuerpo_c`), y si ese contenido seleccionado es el que se persiste en `envios.cuerpo_mensaje`.

---

## 2. Archivos inspeccionados

| Archivo | Rol en el flujo |
|---------|-----------------|
| `public_html/outbound/inc/abc.php` | `asignarVariante()` y `resolverContenidoVariante()` (única fuente de verdad) |
| `public_html/outbound/inc/eligibilidad.php` | `reservarEnvioLogico()` — persiste en `envios` (incluye `cuerpo_mensaje` y `variant`) |
| `public_html/outbound/api/enviar_lote.php` | Motor P1 — endpoint de envío individual (lanzadera) |
| `public_html/outbound/cli/cron.php` | Motor P3 — envío automático por campaña |
| `public_html/outbound/api/enviar_smtp_random.php` | Motor P2 heredado — **punto de fallo histórico** (desactivado) |
| `public_html/outbound/dashboard.php` | Panel — `preview_template` (solo vista previa, no envío) |

---

## 3. `asignarVariante()` (sección A)

Código real (`inc/abc.php`, líneas 24–34):

```php
function asignarVariante(int $leadId, int $campaignId): string
{
    $h = crc32((string)$campaignId . ':' . (string)$leadId);
    if ($h < 0) {
        $h += 4294967296; // 2^32
    }
    $map = ['A', 'B', 'C'];
    return $map[$h % 3];
}
```

Determinística e inmutable: mismo `(lead_id, campaign_id)` → misma variante siempre. Sin random por envío.

Verificación de los dummies (comprobada por ejecución solo lectura del script de auditoría, leyendo la BD con `SQLITE3_OPEN_READONLY`):

| llamada | variante calculada | variante almacenada en envío | coincide |
|---------|--------------------|------------------------------|----------|
| `asignarVariante(1809,3)` | A | A (envio_id=3) | SÍ |
| `asignarVariante(1813,3)` | B | B (envio_id=4) | SÍ |
| `asignarVariante(1811,3)` | C | C (envio_id=5) | SÍ |
| `asignarVariante(1810,3)` | A | A (envio_id=6) | SÍ |
| `asignarVariante(1812,3)` | C | C (envio_id=7) | SÍ |

Resultado: **PASS** (coincidencia total entre variante calculada y variante registrada).

---

## 4. `resolverContenidoVariante()` (sección B)

Código real (`inc/abc.php`, líneas 42–66):

```php
function resolverContenidoVariante(array $plantilla, string $variant): array
{
    $asunto = (string)($plantilla['asunto'] ?? '');
    $cuerpo = (string)($plantilla['cuerpo'] ?? '');

    if ((int)($plantilla['test_ab'] ?? 0) === 1) {
        if ($variant === 'B') {
            if (($plantilla['asunto_b'] ?? '') !== '') {
                $asunto = (string)$plantilla['asunto_b'];
            }
            if (($plantilla['cuerpo_b'] ?? '') !== '') {
                $cuerpo = (string)$plantilla['cuerpo_b'];
            }
        } elseif ($variant === 'C') {
            if (($plantilla['asunto_c'] ?? '') !== '') {
                $asunto = (string)$plantilla['asunto_c'];
            }
            if (($plantilla['cuerpo_c'] ?? '') !== '') {
                $cuerpo = (string)$plantilla['cuerpo_c'];
            }
        }
    }

    return ['asunto' => $asunto, 'cuerpo' => $cuerpo];
}
```

Comportamiento exacto:

| `test_ab` | variante | resultado |
|-----------|----------|-----------|
| `0` | A/B/C | usa siempre `asunto` / `cuerpo` (variante A). No aplica la resolución por variante. |
| `1` | A | usa `asunto` / `cuerpo` |
| `1` | B | usa `asunto_b` / `cuerpo_b` (si la columna no está vacía; si está vacía, conserva A) |
| `1` | C | usa `asunto_c` / `cuerpo_c` (si la columna no está vacía; si está vacía, conserva A) |

No existe ninguna condición adicional. La función devuelve exactamente el par `asunto`/`cuerpo` de la variante seleccionada.

La plantilla 2 tiene `test_ab = 1` y las columnas `cuerpo`, `cuerpo_b`, `cuerpo_c` con contenido distinto (710 / 970 / 1473 bytes, md5 distintos). Por tanto:

```
A → asunto / cuerpo
B → asunto_b / cuerpo_b
C → asunto_c / cuerpo_c
```

Resultado: **PASS**.

---

## 5. Flujo actual de `enviar_lote.php` (sección C)

Reconstrucción estática del motor P1 (`public_html/outbound/api/enviar_lote.php`):

1. **Dónde se calcula la variante (línea 76):**

   ```php
   $varianteUsada = asignarVariante($idClub, $idCampana);
   ```

2. **Dónde se carga la plantilla con todas las variantes (líneas 113–116):**

   ```php
   SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria
   FROM plantillas WHERE id = {$idPlantilla} AND activo = 1
   ```

3. **Dónde se llama `resolverContenidoVariante()` (línea 125):**

   ```php
   $contenido = resolverContenidoVariante($plantilla, $varianteUsada);
   ```

4. **Qué variables reciben el contenido seleccionado (líneas 126–127):**

   ```php
   $asuntoTpl = $contenido['asunto'];
   $cuerpoTpl = $contenido['cuerpo'];
   ```

   `$cuerpoTpl` recibe el cuerpo **ya resuelto** según la variante (A→`cuerpo`, B→`cuerpo_b`, C→`cuerpo_c`).

5. **Dónde se realizan los `str_replace` (líneas 187–188):**

   ```php
   $asunto = str_replace(array_keys($replacements), array_values($replacements), $asuntoTpl);
   $cuerpo = str_replace(array_keys($replacements), array_values($replacements), $cuerpoTpl);
   ```

6. **Dónde se genera `$cuerpo` final y se reserva el envío (líneas 188, 214–228):**

   ```php
   $reserva = reservarEnvioLogico($db, ..., $asunto, $cuerpo, ($idCampana > 0) ? $varianteUsada : null, $idPlantilla, $idSmtp);
   ```

   La variable `$cuerpo` (ya resuelta y sustituida) se entrega a `reservarEnvioLogico()`.

7. **Dónde se guarda `$cuerpo` en `envios.cuerpo_mensaje`** — dentro de `reservarEnvioLogico()` (`inc/eligibilidad.php`, líneas 205–234):

   ```php
   if ($campaignId > 0) {
       $variant = asignarVariante($leadId, $campaignId);   // INMUTABILIDAD
   }
   ...
   $stmt = $db->prepare(
       "INSERT OR IGNORE INTO envios
            (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje,
             lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id)
         VALUES (..., :asunto, :cuerpo, ..., :variant, ...)");
   $stmt->bindValue(':asunto', $asunto, SQLITE3_TEXT);
   $stmt->bindValue(':cuerpo', $cuerpo, SQLITE3_TEXT);   // ← $cuerpo (variante ya resuelta)
   $stmt->bindValue(':variant', $variant, SQLITE3_TEXT); // ← variante determinística
   ```

   `envios.cuerpo_mensaje` se rellena con el parámetro `$cuerpo` recibido, que es el cuerpo ya resuelto por la variante.

8. **Qué variable se entrega finalmente a la función SMTP (líneas 260–262):**

   ```php
   $asuntoEnvio  = $envioRow['asunto'] !== '' ? $envioRow['asunto'] : $asunto;
   $cuerpoEnvio  = $envioRow['cuerpo_mensaje'] !== '' ? $envioRow['cuerpo_mensaje'] : $cuerpo;
   $resultado = enviarSMTPAutenticado($cuenta, $emailDestino, $asuntoEnvio, $cuerpoEnvio, $envioRow['message_id'] ?? null);
   ```

   La variable entregada a SMTP es `$cuerpoEnvio`, que prioriza `$envioRow['cuerpo_mensaje']` (el cuerpo persistido). Como el envío es determinístico por `(lead_id, campaign_id)`, el cuerpo persistido es siempre coherente con la variante.

El motor P3 (`cli/cron.php`) replica exactamente el mismo patrón correcto (líneas 170–171: `asignarVariante` + `resolverContenidoVariante`; líneas 187–196: `str_replace` sobre `$contenido['cuerpo']`; líneas 208–222: `reservarEnvioLogico`). No introduce ninguna ruta alternativa.

Resultado: **PASS**.

---

## 6. Punto exacto donde se selecciona A/B/C

- **Cálculo de variante:** `enviar_lote.php` línea 76 → `asignarVariante($idClub, $idCampana)`.
- **Refuerzo de inmutabilidad:** `eligibilidad.php` línea 206 → `reservarEnvioLogico()` recalcula `asignarVariante($leadId, $campaignId)`, de modo que la variante persistida no depende de ningún valor pasado por el cliente.

---

## 7. Punto exacto donde se almacena `cuerpo_mensaje`

`inc/eligibilidad.php`, en `reservarEnvioLogico()`:

- `INSERT INTO envios (... cuerpo_mensaje ...)` con `bindValue(':cuerpo', $cuerpo)` (línea 228).
- `$cuerpo` es el parámetro que `enviar_lote.php` construyó a partir de `$contenido['cuerpo']` (ya resuelto por `resolverContenidoVariante`).

No existe ninguna re-lectura de la plantilla entre la resolución de la variante y el INSERT.

---

## 8. Punto de fallo histórico identificado (sección D)

Se buscaron patrones equivalentes a `cuerpo`, `cuerpo_b`, `cuerpo_c`, `resolverContenidoVariante`, `variant`, `cuerpo_mensaje` en todo `public_html/outbound`.

**Defecto encontrado (solo lectura):** `public_html/outbound/api/enviar_smtp_random.php` (motor P2 heredado).

- Línea 181: `SELECT asunto, asunto_b, asunto_c, test_ab, cuerpo FROM plantillas` — **no selecciona `cuerpo_b` ni `cuerpo_c`**.
- Línea 187: `$CUERPO_HTML_TEMPLATE = $tpl['cuerpo'];` — fija el cuerpo a la variante A.
- Líneas 428–447: selecciona la variante del **asunto** A/B/C con `mt_rand()`, pero deja el cuerpo fijo en `$CUERPO_HTML_TEMPLATE` (A).
- Líneas 455–459: `$cuerpo = str_replace(..., $CUERPO_HTML_TEMPLATE)` — cuerpo siempre A para B y C.
- Líneas 492–502: INSERT en `envios` con el asunto B/C pero el `cuerpo_mensaje` = A.

Este es el patrón exacto de la anomalía histórica:

```
variante = B/C (asunto)
        ↓
resolver asunto B/C
        ↓
cuerpo = cuerpo A  (porque $tpl['cuerpo'] está fijo)
```

**Estado actual:** el archivo está **desactivado permanentemente** por un guard de seguridad en la primera línea ejecutable:

```php
die("SISTEMA BLOQUEADO POR EL ADMINISTRADOR: ENVIOS DETENIDOS.");
```

Además, no usa `asignarVariante()` ni `resolverContenidoVariante()`, no escribe `variant`/`lead_id`/`campaign_id`, y lee de `clubes.json` (fuente desincronizada). **No forma parte del flujo operativo actual** (`enviar_lote.php` / `cron.php`).

**Veredicto del barrido en el flujo actual:** no existe ningún camino en `enviar_lote.php` ni en `cron.php` que, tras seleccionar B o C, recupere posteriormente `$p['cuerpo']` o `$plantilla['cuerpo']` (A). La única aparición de `$tpl['cuerpo']` en `dashboard.php` corresponde al action `preview_template` (líneas 486–508), que es una vista previa y no persiste ni envía nada.

---

## 9. Comparación con los smokes 6 y 7 (sección E)

Datos reales de BD (lectura con `SQLITE3_OPEN_READONLY`, sin modificar):

| envio_id | lead_id | variant | cuerpo_mensaje coherente | resultado SMTP |
|----------|---------|---------|--------------------------|----------------|
| 3 | 1809 | A | SÍ (contenido A) | ACCEPTED |
| 4 | 1813 | B | **NO** (contenido A) | ACCEPTED |
| 5 | 1811 | C | **NO** (contenido A) | ACCEPTED |
| 6 | 1810 | A | SÍ (contenido A) | ACCEPTED |
| 7 | 1812 | C | SÍ (contenido C) | ACCEPTED |

**Por qué 6→A→A y 7→C→C funcionan mientras 4→B→A y 5→C→A no:**

- Los registros 3, 4 y 5 se generaron con la lógica duplicada **anterior** a la centralización de FASE 3. Esa lógica antigua seleccionaba el asunto por variante, pero **el cuerpo permanecía fijo en la variante A** — el mismo patrón que todavía conserva (desactivado) `enviar_smtp_random.php`.
- Los registros 6 y 7 se generaron con el flujo **actual** (`asignarVariante()` → `resolverContenidoVariante()` → `reservarEnvioLogico()`), que resuelve cuerpo y asunto de forma conjunta por variante. Por eso el cuerpo C del envío 7 es correcto (1520 bytes en BD, frente a los 710 bytes de A).

Es decir, la anomalía es **histórica** y no se reproduce en el flujo actual.

---

## 10. Validación específica de la variante B (sección F)

La pregunta principal: ¿el código actual permite demostrar estáticamente que B funcionará, sin un smoke reciente de B?

**Sí.** Cadena de razonamiento estática:

1. `asignarVariante(lead_id, campaign_id)` es determinística. Un lead cuyo hash caiga en `%3 == 1` obtiene `'B'`.
2. En `resolverContenidoVariante()`, con `test_ab === 1`: `$variant === 'B'` ⇒ `$cuerpo = $plantilla['cuerpo_b']` (si `cuerpo_b !== ''`).
   - La plantilla 2 cumple: `test_ab = 1` y `cuerpo_b` no vacío (970 bytes, distinto de A y C).
3. En `enviar_lote.php`: `$cuerpoTpl = $contenido['cuerpo']` (cuerpo B), `$cuerpo = str_replace(..., $cuerpoTpl)`.
4. En `reservarEnvioLogico()`: `envios.cuerpo_mensaje = $cuerpo` (cuerpo B) y `envios.variant = 'B'` (recalculado determinísticamente).
5. En el envío SMTP: `$cuerpoEnvio = $envioRow['cuerpo_mensaje']` (cuerpo B).

No hay ninguna rama condicional que dependa de la existencia de un envío previo, ni ninguna re-lectura de `cuerpo` (A) tras la resolución. El camino de B está conectado de forma idéntica al de C, que ya quedó validado por el smoke 7.

Resultado:

```
B_CODE_PATH = PASS
```

(La ausencia de un smoke reciente de B es una carencia de **evidencia operativa**, no un defecto de código. El código demuestra estáticamente el camino completo de B.)

---

## 11. Seguridad (sección H)

```text
SQLITE WRITE = NO
POST = NO
SMTP = NO
enviar_lote.php ejecutado = NO
cron = NO
Evolution API = NO
envios nuevos = NO
leads nuevos = NO
archivos MODIFICADOS = NO
```

Aclaración: el único archivo tocado es el nuevo documento `docs/checkpoint_fase_abcfinal3_auditoria_flujo.md`, creado explícitamente por la sección J de la fase. No se modificó ningún archivo de código, ni `abc.php`, ni `enviar_lote.php`, ni la plantilla, ni los registros 3–7, ni las tablas de BD.

Todos los accesos a BD de esta fase se hicieron en modo solo lectura (`SQLITE3_OPEN_READONLY` en el script de auditoría que se reejecutó para confirmar el estado actual).

---

## 12. Resultado final (sección I)

```text
ASIGNACIÓN A/B/C          PASS
RESOLUCIÓN DE CONTENIDO   PASS
FLUJO A                   PASS
FLUJO B                   PASS
FLUJO C                   PASS
ALMACENAMIENTO            PASS
SMTP INPUT                PASS
```

Explicación:

- **ASIGNACIÓN A/B/C**: `asignarVariante()` determinística e inmutable, coincidente con los 5 dummies auditados.
- **RESOLUCIÓN DE CONTENIDO**: `resolverContenidoVariante()` selecciona `cuerpo` / `cuerpo_b` / `cuerpo_c` correctamente según variante y `test_ab`.
- **FLUJO A/B/C**: los motores P1 (`enviar_lote.php`) y P3 (`cron.php`) conectan `asignarVariante()` → `resolverContenidoVariante()` → `$cuerpo` sin rutas alternas que regresen a `cuerpo` (A).
- **ALMACENAMIENTO**: `reservarEnvioLogico()` persiste en `envios.cuerpo_mensaje` el `$cuerpo` ya resuelto, junto con la `variant` determinística.
- **SMTP INPUT**: SMTP recibe el contenido desde `envios.cuerpo_mensaje` (el ya almacenado), garantizando que lo almacenado es exactamente lo enviado.

### Veredicto

```text
ABC_CODE_PATH_PASS
```

El código actualmente instalado demuestra, por inspección estática, que A/B/C llegan correctamente desde la asignación de variante hasta el contenido que se almacena en `envios.cuerpo_mensaje` y se entrega a SMTP.

---

## 13. Nota de la anomalía histórica (sin corregir nada)

La anomalía histórica (envio_id=4 → etiqueta B con cuerpo A; envio_id=5 → etiqueta C con cuerpo A) queda explicada por el patrón defectuoso del motor P2 heredado (`enviar_smtp_random.php`): variante por asunto con `mt_rand()` y cuerpo fijo en `$tpl['cuerpo']`. Ese motor está desactivado con `die()` y **no** participa en el flujo actual.

Conforme a la regla de parada de esta fase (ABC-FINAL.3), **no se corrige nada**, **no se envía nada**, **no se crean dummies**, **no se modifica la plantilla, `abc.php` ni `enviar_lote.php`**.