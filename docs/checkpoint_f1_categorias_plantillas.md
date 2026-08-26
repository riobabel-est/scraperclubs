# Checkpoint — P-1 Fase 1: Categorías de plantillas editables y opcionales

**Fecha:** 2026-08-26
**Ámbito:** `public_html/outbound/` (tabs/editor.php, api/plantillas.php, js/app.js)
**Tipo:** Feature de mantenimiento de plantillas (sin cambio de esquema BD)
**Bloque:** P-1 Fase 1 de `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md`

---

## Objetivo

Resolver lo que el usuario reportó: **"las categorías no pueden editarse"**. Se permite:
- Crear/editar plantillas **sin categoría** (genéricas, "Sin pipeline").
- Elegir o **escribir una categoría libre** al guardar.
- **Renombrar y eliminar** categorías (eliminar reasigna a sin-categoría, no borra plantillas).
- La Lanzadera ofrece las plantillas de la categoría (estado) **+ las genéricas**.

## Cambios aplicados

### `api/plantillas.php`
- `save_template`: default de categoría `'General'` → `''` (sin categoría permitida).
- `get_templates`: soporte `incluir_genericas=1` → `WHERE (categoria = :cat OR categoria = '')` (prepared statement).
- `get_categorias`: excluye la categoría vacía.
- Nuevos endpoints: `rename_categoria` (renombra en todas las plantillas) y `delete_categoria` (reasigna a '').

### `tabs/editor.php`
- Botón "Nueva plantilla": quitado `:disabled="!ec"` (ya no exige categoría).
- Campo "Pipeline" (select deshabilitado) → **"Categoría (Pipeline)" editable** (input + `<datalist>` de categorías existentes).

### `js/app.js`
- Nueva propiedad `edCategoria`.
- `seleccionarPlantilla(t)` → `edCategoria = t.categoria || ''` (ya no fuerza el filtro).
- `nuevaPlantilla()` → `edCategoria = ec || ''`.
- `guardarPlantilla()` → envía `edCategoria` (en vez de `ec`).
- `onCategoriaChange()` → "Todas" carga todas las plantillas.
- `lzOnEstadoChange()` (Lanzadera) → `&incluir_genericas=1`.

## Validación

- `scripts/test_f1_categorias.php` → **9/9 PASS** (sobre copia de BD, vía subprocesos):
  get_categorias sin vacía · get_templates por categoría · +genéricas · save sin categoría ·
  rename · delete (reasigna, no borra) · "Todas" devuelve todo.
- `php -l` OK (plantillas.php, editor.php) · `node --check app.js` OK.
- Sin regresión: `test_eligibilidad` 20/20 · `test_app_js_refactor` 38/38.
- Backup preventivo de `stats.db` creado y **eliminado tras validar** (la Fase 1 no tocó la BD real).

## Pendiente

- Deploy a producción + commit (requiere OK del usuario).
- **Fase 2** del P-1 (configurador de campañas: tablas `campaign_segmentos`/`campaign_plantillas` + endpoints).

## Referencias

- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` — Fase 1 ✅
- `docs/PENDIENTES_OUTBOUND.md` — P-1
- `scripts/test_f1_categorias.php`, `scripts/runner_plantillas.php` — tests locales
