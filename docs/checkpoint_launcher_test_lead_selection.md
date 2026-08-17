# CHECKPOINT — LAUNCHER: Corrección de selección de leads en `enviarCorreoPrueba()`

**Fecha:** 2026-08-17
**Fase:** LAUNCHER_TEST_LEAD_SELECTION
**Veredicto:** `LAUNCHER_TEST_SELECTION_PASS`

---

## 1. Problema diagnosticado

El botón de prueba de la lanzadera devolvía `❌ Lead no elegible para envío` para las
tres variantes (A, B y C) con `campaign_id=3` (SMOKE TEST FutProtec 2026-08, entorno TEST).

### Causa raíz

`enviarCorreoPrueba()` en `public_html/outbound/js/app.js` seleccionaba el lead de prueba
así:

```js
let club = this.lzCola[0];
if (!club) {
    // FALLBACK INCORRECTO: get_leads_table sin filtro de compatibilidad TEST/REAL
    const r = await fetch('api/leads.php?action=get_leads_table&page=1&per_page=1');
    club = (j.ok && j.data && j.data.length) ? j.data[0] : null;
}
```

Cuando `lzCola` estaba vacía, el fallback llamaba a `get_leads_table` que **NO aplica**
`sqlFiltroCompatibilidadLeadCampana()`. Devolvía el primer lead REAL alfabético
(`A. D. PARADOR C. F.`, id=155). Al enviar ese lead REAL con `campaign_id=3` (campaña TEST),
`esElegibleParaEnvio()` lo rechazaba con:

```text
razon = lead_real_en_campana_test
```

### Confirmación empírica

- `esElegibleParaEnvio(155, 3)` → `false` | `razon = lead_real_en_campana_test`
- `esElegibleParaEnvio(1814, 3)` → `true` | `razon = elegible`
- `esElegibleParaEnvio(1815, 3)` → `true` | `razon = elegible`
- `esElegibleParaEnvio(1816, 3)` → `true` | `razon = elegible`
- `esElegibleParaEnvio(1817, 3)` → `true` | `razon = elegible`

Los leads TEST 1814/1815/1816/1817 son todos elegibles. El problema era **solo** que el
fallback elegía un lead REAL.

---

## 2. Corrección aplicada

### 2.1 `public_html/outbound/api/get_cola.php`

- Añadido `require_once __DIR__ . '/../inc/abc.php';` para usar `asignarVariante()`.
- Cada lead de la cola ahora incluye `variante_ab` calculada **server-side** con la
  función real `asignarVariante((int)$lead['id'], $idCampana)` (solo si `$idCampana > 0`).
- Esto elimina la duplicación de la lógica A/B/C en JavaScript y garantiza coherencia
  con la asignación real de variantes.

### 2.2 `public_html/outbound/js/app.js` — `enviarCorreoPrueba()`

- **Eliminado el fallback incorrecto** a `get_leads_table` sin filtro de campaña.
- Nueva lógica de selección de candidatos:
  1. Si `lzCola` tiene leads compatibles (cargados por `get_cola.php`), se reutilizan.
  2. Si `lzCola` está vacía, se obtienen candidatos vía `get_cola.php?campaign_id=...`
     (que aplica `sqlFiltroCompatibilidadLeadCampana()` server-side → solo leads TEST
     para campaña TEST).
- **Selección A/B/C**: se eligen 3 leads distintos que cubran A/B/C usando el campo
  `variante_ab` devuelto por el servidor. No se fuerza variante sobre un lead arbitrario.
- Si no hay cobertura A/B/C completa, se muestra mensaje claro y **no se envía nada**.
- Para mensaje único (no ABC), se usa el primer candidato compatible.

---

## 3. Tests ejecutados (sin SMTP, sin POST, sin envío)

### 3.1 Harness JS — `scripts/fase_launcher_test_lead_selection.js`

Replica el algoritmo de selección y `campanaOperable()`. **12/12 passed.**

| Test | Descripción | Resultado |
| ---: | --- | --- |
| 1 | Campaña 3 + cola vacía → nunca selecciona lead REAL | ✅ |
| 2 | Campaña 3 → candidatos seleccionados son todos TEST | ✅ |
| 3 | Encontrar A/B/C entre candidatos compatibles | ✅ |
| 4 | Un candidato REAL nunca pasa | ✅ |
| 5 | Campaña 2 en DRAFT → bloqueada | ✅ |
| 6 | Campaña 3 → operable | ✅ |
| 7 | Idempotencia intacta (parámetros de envío) | ✅ |
| 8 | `asignarVariante()` no modificada | ✅ |

### 3.2 Harness PHP server-side — `scripts/fase_launcher_test_get_cola.php`

Verifica el comportamiento real de `get_cola.php` con `campaign_id=3`. **9/9 passed.**

- Campaña 3 activa y PILOT, es TEST.
- 9 leads compatibles en la cola, **todos TEST** (ningún REAL).
- Cobertura A/B/C presente.
- El lead REAL 155 NO está en la cola.
- `get_cola.php` usa `asignarVariante()`, expone `variante_ab` y aplica el filtro TEST/REAL.

### 3.3 Regresión

- `php -l` en `get_cola.php` y el harness PHP: OK.
- `node --check` en `app.js` y el harness JS: OK.
- `scripts/fase_launcher_check_idempotencia.php`: **4/4 passed** (idempotencia intacta).

---

## 4. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `public_html/outbound/api/get_cola.php` | `require abc.php` + campo `variante_ab` server-side |
| `public_html/outbound/js/app.js` | `enviarCorreoPrueba()`: elimina fallback incorrecto, selección A/B/C por `variante_ab` |
| `scripts/fase_launcher_test_lead_selection.js` | Nuevo harness JS (12 tests) |
| `scripts/fase_launcher_test_get_cola.php` | Nuevo harness PHP server-side (9 tests) |

---

## 5. Conclusión

La causa raíz era el **fallback a `get_leads_table` sin filtro de compatibilidad TEST/REAL**
en `enviarCorreoPrueba()`, que seleccionaba un lead REAL para una campaña TEST. La corrección
garantiza que la prueba siempre use leads compatibles con la campaña (TEST para campaña TEST)
y que la variante A/B/C se calcule server-side con la función real `asignarVariante()`.

**VEREDICTO: `LAUNCHER_TEST_SELECTION_PASS`**
