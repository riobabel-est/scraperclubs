# Checkpoint — P-1 Fases 2-3: Configurador de Campañas (backend + UI)

**Fecha:** 2026-08-26
**Ámbito:** `public_html/outbound/` (api/campanas.php, tabs/smtp.php, dashboard.php)
**Tipo:** Feature de configuración de campañas (sin alterar el esquema de `pipelines`)
**Bloque:** P-1 Fases 2-3 de `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md`

---

## Fase 2 — Backend y esquema

- **Tablas idempotentes** creadas en `api/campanas.php` (al cargar):
  - `campaign_segmentos (campaign_id, tipo 'federacion'|'estado'|'todas', valor)`.
  - `campaign_plantillas (campaign_id, plantilla_id)`.
- **Endpoints** (conectados vía `require __DIR__.'/api/campanas.php'` en `dashboard.php`):
  - `get_federaciones` → federaciones reales de `clubes_crm`.
  - `get_campanas` → campañas con segmento y plantillas.
  - `save_campaign` → upsert de `pipelines` + reemplazo de segmento y plantillas.
  - `delete_campaign` → borra campaña + segmento + plantillas asignadas.

## Fase 3 — UI

- Bloque **"Configurador de Campañas"** en la columna derecha de Configuración (`tabs/smtp.php`).
- Lista de campañas (nombre, identificador, entorno, nº federaciones/plantillas) con Editar/Eliminar.
- Formulario: nombre, identificador, entorno, estado, activa.
- **Checklist de federaciones** (checkbox "Todas" + lista real).
- **Selector de plantillas** del banco central (multi-checkbox).
- Función Alpine `campanasConfig()` (cargar/guardar/editar/eliminar).

## Validación

- `scripts/test_f2_campanas.php` → **12/12 PASS** (subprocesos, copia de BD): get_federaciones · crear · get_campanas con segmento (federaciones/todas/estado) y plantillas · editar · eliminar.
- `php -l` OK (campanas.php, dashboard.php, smtp.php, editor.php, plantillas.php) · `node --check app.js` y del script de smtp.php OK.
- **Render autenticado del panel** (servidor local + login con pass real): HTTP 200, bloque presente, sin errores PHP.
- Sin regresión: f1 (9/9), f2 (12/12), eligibilidad (20/20).
- **Hallazgo menor:** la BD local tenía un `auth_dashboard` residual (de tests previos) con prioridad sobre `secret.php`; se eliminó para que local use la pass de `secret.php`.

## Pendiente

- Deploy a producción + commit (requiere OK del usuario).
- **Fase 4** del P-1: integrar el segmento/plantillas de la campaña en la Lanzadera (pruebas en TEST).

## Referencias

- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` — Fases 2-3 ✅
- `docs/PENDIENTES_OUTBOUND.md` — P-1
- `scripts/test_f2_campanas.php`, `scripts/runner_campanas.php` — tests locales
