# INFORME FASE 6.2 — DIAGNÓSTICO DE PLANTILLAS A/B/C — BODY AUSENTE

**Fecha**: 2026-12-08
**Versión base**: v4.3-final (commit `342c01d`)
**Entorno analizado**: Local (`c:\laragon\www\scrapperclub`) + Staging (`https://getfutprotec.com/crm-staging/`)

---

## 1. Diagnóstico

Cuando se activa el Test A/B/C (`edTestAb = 1`) en el editor de plantillas, los **tres campos de asunto (A/B/C)** se muestran correctamente, pero el **textarea del cuerpo del mensaje DESAPARECE** por completo de la interfaz. Esto ocurre tanto en el entorno de staging (v4.3-final sin modificar) como localmente (con modificaciones parciales).

La causa es una **combinación de dos defectos**:

| Defecto | Descripción |
|---------|-------------|
| **Defecto #1 (staging)** | En v4.3-final, el único textarea de cuerpo está condicionado a `x-show="!edTestAb \|\| edPlataforma !== 'email'"`. Al activar A/B/C, esa condición se hace FALSE y el cuerpo desaparece sin que exista una sección alternativa que lo reemplace. |
| **Defecto #2 (local)** | En la versión local se añadió la sección "Cuerpos A/B/C" (3 textareas para variante A, B, C), pero la integración backend↔frontend para `cuerpo_b`/`cuerpo_c` quedó **incompleta**: no se cargan al seleccionar plantilla, no se guardan al persistir, y `get_templates` no los devuelve. |

---

## 2. Causa raíz

### Causa primaria (staging — v4.3-final)

**Archivo**: `public_html/outbound/tabs/editor.php`

En v4.3-final, la estructura del editor es:

```
Líneas 133-159: Sección A/B/C Asuntos (visible si edTestAb=1)
Líneas 180-196: Textarea ÚNICO de cuerpo (visible si edTestAb=0)
```

**No existe** sección de cuerpos A/B/C en v4.3-final. Cuando el usuario activa el toggle A/B/C:
- ✅ Aparecen 3 inputs de asunto (A, B, C)
- ❌ El textarea único del cuerpo se oculta (`!edTestAb` = FALSE)
- ❌ No hay nada que lo reemplace → **el cuerpo desaparece de la UI**

### Causa secundaria (local — integración incompleta)

En la versión local se añadió la sección "Cuerpos A/B/C" (líneas 161-178 de `editor.php`) con textareas para `edCuerpo`, `edCuerpoB` y `edCuerpoC`. Sin embargo, **tres piezas necesarias no se implementaron**:

| Componente | Qué falta | Archivo |
|-----------|-----------|---------|
| **Carga** | `seleccionarPlantilla()` no carga `edCuerpoB`/`edCuerpoC` desde la plantilla | `app.js:137` |
| **Persistencia** | `guardarPlantilla()` no envía `cuerpo_b`/`cuerpo_c` al backend | `app.js:481` |
| **API** | `get_templates` no incluye `cuerpo_b`/`cuerpo_c` en el SELECT | `dashboard.php:446` |

---

## 3. Estado A/B/C

| Variante | Asunto | Body (UI) | Body (DB) | Backend (save) | Backend (load) | Frontend (render) | Frontend (save) |
|----------|--------|-----------|-----------|-----------------|-----------------|--------------------|--------------------|
| A | ✅ | ❌ / ⚠️ | ✅ (columna `cuerpo`) | ✅ | ✅ | ❌ (staging) / ⚠️ vacío (local) | ❌ no envía `cuerpo_b`/`cuerpo_c` |
| B | ✅ | ❌ / ⚠️ | ✅ (columna `cuerpo_b`) | ✅ | ❌ no en SELECT | ❌ (staging) / ⚠️ vacío (local) | ❌ no envía `cuerpo_b`/`cuerpo_c` |
| C | ✅ | ❌ / ⚠️ | ✅ (columna `cuerpo_c`) | ✅ | ❌ no en SELECT | ❌ (staging) / ⚠️ vacío (local) | ❌ no envía `cuerpo_b`/`cuerpo_c` |

**Leyenda**:
- ✅ = Funciona correctamente
- ❌ = No funciona / No existe
- ⚠️ = Existe parcialmente pero con bugs

**Nota sobre el Body A**: La columna `cuerpo` (variante A) SÍ se carga y guarda correctamente tanto en staging como en local. El problema es que en staging, cuando `edTestAb=1`, el textarea se oculta por la condición `x-show="!edTestAb"`.

---

## 4. Archivo responsable

```text
Archivo:  public_html/outbound/tabs/editor.php
Función:  renderizado condicional del textarea de cuerpo
Variable: edTestAb (toggle Alpine)

Archivo:  public_html/outbound/js/app.js
Función:  guardarPlantilla() — no envía cuerpo_b/cuerpo_c
Función:  seleccionarPlantilla() — no carga cuerpo_b/cuerpo_c
Variable: edCuerpo, edCuerpoB, edCuerpoC

Archivo:  public_html/outbound/dashboard.php
Función:  get_templates (línea 442-458)
Variable: SELECT sin cuerpo_b/cuerpo_c
```

---

## 5. Último estado correcto

```text
Commit:  342c01d (tag: v4.3-final)
Fecha:   2026-12-07 aproximadamente
Archivo: public_html/outbound/tabs/editor.php (v4.3-final)
Cambio:  En v4.3-final, cuando edTestAb=0, el cuerpo se mostraba correctamente
         en un único textarea. El bug se activa SOLO cuando edTestAb=1.
```

**No existe un commit anterior donde A/B/C con cuerpos funcionara correctamente.** La funcionalidad de cuerpos por variante nunca se completó. En v4.3-final, el A/B/C testing solo aplicaba a ASUNTOS, no a cuerpos. El cuerpo era único y compartido para todas las variantes.

---

## 6. Qué cambió

### En v4.3-final (staging actual)

El defecto es de **diseño original**: la condición `x-show="!edTestAb || edPlataforma !== 'email'"` en la línea 181 de `editor.php` oculta el textarea del cuerpo cuando se activa el test A/B. No se contempló que al activar el test, el cuerpo único debía seguir visible o desdoblarse en variantes.

**Línea 181 de editor.php (v4.3-final)**:
```html
<div x-show="!edTestAb || edPlataforma !== 'email'">
```
Cuando `edTestAb = 1` y `edPlataforma = 'email'`:
- `!edTestAb` → `false`
- `edPlataforma !== 'email'` → `false`
- Resultado: `false || false` → **oculto**

### Modificaciones locales (NO en staging)

Se añadió la sección "Cuerpos A/B/C" (líneas 161-178 de `editor.php` actual) como intento de solucionar el problema, pero quedó incompleta porque:

1. **`app.js:guardarPlantilla()`** solo envía `cuerpo` (variante A), no `cuerpo_b` ni `cuerpo_c`
2. **`app.js:seleccionarPlantilla()`** solo carga `edCuerpo`, no `edCuerpoB` ni `edCuerpoC`
3. **`dashboard.php:get_templates`** SELECT no incluye `cuerpo_b`/`cuerpo_c`

---

## 7. ¿Afecta al envío real?

```text
NO
```

**Explicación**: `enviar_lote.php` (líneas 83-97) SÍ tiene la lógica completa de A/B/C para cuerpos:
```php
if ($varianteAb === 'B') {
    $cuerpoTpl = !empty($plantilla['cuerpo_b']) ? $plantilla['cuerpo_b'] : $plantilla['cuerpo'];
} elseif ($varianteAb === 'C') {
    $cuerpoTpl = !empty($plantilla['cuerpo_c']) ? $plantilla['cuerpo_c'] : $plantilla['cuerpo'];
}
```

El envío real:
- **Variante A**: usa `cuerpo` (OK, siempre se ha guardado)
- **Variante B**: usa `cuerpo_b` si existe, sino hace fallback a `cuerpo`
- **Variante C**: usa `cuerpo_c` si existe, sino hace fallback a `cuerpo`

Como `cuerpo_b` y `cuerpo_c` probablemente están vacíos (porque nunca se han guardado desde el frontend), el sistema hará fallback al `cuerpo` de la variante A. Por tanto, **los emails se envían con el cuerpo de la variante A en los 3 casos**. No se pierde el cuerpo en el envío real, pero las variantes B y C no tendrán cuerpos diferenciados.

---

## 8. Corrección mínima propuesta

Para recuperar el comportamiento correcto, se requieren **3 cambios** (en este orden):

### Cambio 1: `dashboard.php` — Devolver `cuerpo_b`/`cuerpo_c` en `get_templates`

**Archivo**: `public_html/outbound/dashboard.php`, línea 446

```diff
- $sql = "SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, tipo, categoria, activo FROM plantillas";
+ $sql = "SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria, activo FROM plantillas";
```

### Cambio 2: `app.js` — Cargar `edCuerpoB`/`edCuerpoC` al seleccionar plantilla

**Archivo**: `public_html/outbound/js/app.js`, línea 137 (en `seleccionarPlantilla()`)

```diff
  this.edCuerpo = t.cuerpo || '';
+ this.edCuerpoB = t.cuerpo_b || '';
+ this.edCuerpoC = t.cuerpo_c || '';
```

### Cambio 3: `app.js` — Enviar `cuerpo_b`/`cuerpo_c` al guardar

**Archivo**: `public_html/outbound/js/app.js`, línea 481 (en `guardarPlantilla()`)

```diff
  f.append('test_ab', this.edTestAb); f.append('cuerpo', this.edCuerpo);
+ f.append('cuerpo_b', this.edCuerpoB); f.append('cuerpo_c', this.edCuerpoC);
```

### Cambio 4 (opcional): `app.js` — Resetear `edCuerpoB`/`edCuerpoC` en `nuevaPlantilla()`

**Archivo**: `public_html/outbound/js/app.js`, línea 466 (en `nuevaPlantilla()`)

```diff
- this.edCuerpo = ''; this.edTipo = this.edPlataforma === 'whatsapp' ? 'whatsapp' : 'html';
+ this.edCuerpo = ''; this.edCuerpoB = ''; this.edCuerpoC = ''; this.edTipo = this.edPlataforma === 'whatsapp' ? 'whatsapp' : 'html';
```

---

## 9. Riesgo

```text
MEDIO
```

**Justificación**: El envío real no está comprometido (usa fallback a `cuerpo`). El riesgo es funcional: no se pueden crear ni visualizar cuerpos diferenciados por variante, lo que invalida parcialmente el propósito del test A/B/C (solo se testean asuntos diferentes, no cuerpos diferentes). La corrección es mínima (4 líneas de código) y no afecta a la estructura de BD ni a otros módulos.

---

## 10. Recomendación

```text
NO ENVIAR TODAVÍA
```

**Motivo**: Aunque el envío real conserva el cuerpo (con fallback a variante A), el test A/B/C de 300 contactos no tendría validez estadística para cuerpos diferentes porque:

1. Las variantes B y C están enviando el mismo cuerpo que la variante A (por fallback)
2. Si el objetivo del test es comparar tasas de respuesta con cuerpos diferentes, el test actual NO mide eso
3. Si el objetivo es solo comparar asuntos, el test SÍ es válido

**Acción recomendada**: Aplicar los 3 cambios mínimos (sección 8) → verificar visualmente en el editor → ejecutar `python -m py_compile` para validar sintaxis PHP → hacer un envío de prueba de 3 emails (1 por variante) para confirmar que cada variante recibe su cuerpo correcto → proceder con el test de 300 contactos.

---

## Resumen ejecutivo

| Pregunta | Respuesta |
|----------|-----------|
| ¿Dónde está el body A? | Columna `cuerpo` en tabla `plantillas`. Se carga/guarda OK. Pero en staging se oculta cuando `edTestAb=1` por la condición `x-show="!edTestAb"`. |
| ¿Dónde está el body B? | Columna `cuerpo_b` en tabla `plantillas`. Existe en DB pero nunca se carga ni se guarda desde el frontend. |
| ¿Dónde está el body C? | Columna `cuerpo_c` en tabla `plantillas`. Existe en DB pero nunca se carga ni se guarda desde el frontend. |
| ¿Por qué ya no aparece? | Defecto de diseño en v4.3-final: el textarea único se oculta al activar A/B/C. La sección "Cuerpos A/B/C" añadida localmente no está cableada al backend. |
| ¿Commit que provocó la situación? | No es una regresión. Es un defecto presente desde que se implementó el toggle A/B/C (el cuerpo nunca se desdobló en variantes). El commit `342c01d` (v4.3-final) ya contiene el bug. |
| ¿El envío real conserva el body? | SÍ. `enviar_lote.php` tiene fallback: si `cuerpo_b`/`cuerpo_c` están vacíos, usa `cuerpo` (variante A). |
| ¿Corrección mínima? | 3 líneas de código: añadir `cuerpo_b,cuerpo_c` al SELECT en `get_templates`, cargar `edCuerpoB`/`edCuerpoC` en `seleccionarPlantilla()`, y enviarlos en `guardarPlantilla()`. |