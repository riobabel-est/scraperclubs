# Checkpoint — CRM a nivel HubSpot (P-5): Analítica Global + Agenda + Smart View

**Fecha:** 2026-08-26
**Estado:** ✅ IMPLEMENTADO y validado (test 21/21 + smoke real + render autenticado)
**Referencias:** `docs/ESTUDIO_OPERACION_CRM_MODERNO.md` (marco) · `docs/PENDIENTES_OUTBOUND.md` (P-5)

---

## 1. Objetivo

Llevar el CRM outbound a nivel de las plataformas modernas: conectar la analítica global construida, añadir agenda de próximas acciones y smart views, y podar el código muerto.

## 2. Cambios realizados

| Bloque | Cambio | Archivos |
|---|---|---|
| **Poda** | Eliminados `get_followups` + `getFollowupsNoRespondedores/SinProximaAccion/Kpis` (dead code sustituido por `get_seguimiento`) | `api/analytics.php` |
| **Analítica Global conectada** | Tab Analytics con 2 vistas: **"Piloto A/B/C"** (actual) y **"Efectividad Global"** (`analyticsApp` + `get_analytics&tab=dashboard`): embudo 12 niveles con cuello de botella, KPIs €/100 contactos, objetivo/proyección (20 clubes), comparativa A/B/C ampliada con ganadora | `tabs/analytics.php` |
| **Agenda de próxima acción** | Nueva columna `fecha_proxima_accion` (migración idempotente), en whitelist `CAMPOS_EDITABLES_LEAD`, cola **Avanzar** muestra vencidos (`vencida`/`dias_vencida`) y permite programar fecha desde la UI | `cli/init_db.php`, `dashboard.php`, `api/analytics.php`, `tabs/seguimiento.php`, `js/app.js` |
| **Smart View "Calentar"** | 3ª cola: leads nuevos (7 días) sin envíos (patrón Close) con prioridad y acciones | `api/analytics.php`, `tabs/seguimiento.php`, `js/app.js` |
| **Scoring ampliado** | +15 si la próxima acción está vencida | `api/analytics.php` |

## 3. Validaciones

- **Test funciones puras**: `php scripts/test_seguimiento.php` → **21/21 PASS** (incluye smart view y agenda)
- `php -l` ✅ (api/analytics, dashboard, tabs/seguimiento, tabs/analytics, cli/init_db)
- `node --check js/app.js` ✅
- Balance `<div>`: seguimiento **50/50** · analytics **71/71** ✅
- **Smoke endpoint real** (`stats.db`): ok=true · NR=156 · SPA=2 · KPIs y campos `fecha_proxima_accion`/`vencida`/`score` correctos
- **Render autenticado simulado**: RENDER OK, sin errores, nav completo

## 4. Notas

- La columna `fecha_proxima_accion` se aplicó a `data/stats.db` local; en producción la crea `init_db.php` al re-ejecutarse (migración idempotente).
- `analyticsApp()` estaba construido desde fases anteriores sin UI; ahora queda conectado en Analytics → "Efectividad Global".
- Pendiente: deploy SiteGround y commit (solo con OK explícito).

## 5. Siguientes mejoras (roadmap del estudio)

- Secuencias de follow-up automáticas (2º toque con plantilla) — F4 del plan P-1.
- Mostrar próxima acción en la tarjeta del Kanban.
- Persistencia de filtros y snooze/posponer.

---

## 6. AÑADIDO — Contexto de campaña global (P-6, unificación de tabs)

**Problema detectado por el usuario:** Pipeline, Seguimiento y Analytics parecían islas (datos globales vs por campaña).

**Solución implementada (Fase 1):** selector de campaña en el topbar que actúa como **contexto compartido**:

| Tab | Comportamiento con campaña seleccionada |
|---|---|
| **Pipeline (Kanban)** | Filtrado server-side: leads con `lead_pipelines.pipeline_id = X` |
| **Seguimiento** | `get_seguimiento` con `campaign_id`: colas (Perseguir/Avanzar/Calentar) + KPIs + embudo acotados a la campaña (incluye envíos `envios.campaign_id`) |
| **Analytics → Piloto** | Preselecciona la campaña del contexto |
| **Analytics → Efectividad Global** | Inicia con el filtro pipeline = campaña activa |

**Implementación:**
- `dashboard.php`: endpoint `set_campana_actual` (sesión) + `$campanaActual` + `$campanasSelect` + filtro en la query del Kanban + selector en topbar + `window._campanaActual`.
- `js/app.js`: `campanaActual` en estado, `setCampana()` (guarda sesión + recarga + restaura tab), propaga a `seguimientoApp` y a `analyticsApp.fPipeline`.
- `api/analytics.php`: `get_seguimiento` acepta `campaign_id`; todas las colas/KPIs/embudo filtran por `lead_pipelines` y `envios.campaign_id`.
- `tabs/analytics.php`: `pilotoAnalyticsApp.loadCampanas()` preselecciona el contexto.

**Validaciones:** php -l ✅ · node --check ✅ · test 21/21 ✅ · smoke con `campaign_id=1` (test→0 leads) y `campaign_id=2` ✅ · render autenticado OK (selector + `_campanaActual` + `setCampana` presentes).

**Pendiente Fase 2:** filtrar Bandeja (respuestas) y Lanzadera por la campaña del contexto.

