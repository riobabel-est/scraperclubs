# Checkpoint — Módulo "Seguimiento" (ex Follow-ups) rediseñado

**Fecha:** 2026-08-26
**Estado:** ✅ IMPLEMENTADO y validado (backend + frontend + dashboard + tests)
**Plan:** `docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md` · Compromiso en `docs/PENDIENTES_OUTBOUND.md`

---

## 1. Objetivo

Retomar el módulo huérfano `tabs/followups.php` como un módulo de **gestión de seguimiento comercial** (quién perseguir, qué avanza, qué se enfría), con KPIs inteligibles y UI/UX al nivel de plataformas B2B (HubSpot/Lemlist/Pipedrive).

## 2. Cambios realizados

| Archivo | Cambio |
|---|---|
| `public_html/outbound/api/analytics.php` | Nuevo action **`get_seguimiento`** + funciones puras: `calcularPrioridadLead`, `getSeguimientoNoRespondedores`, `getSeguimientoSinProximaAccion`, `getSeguimientoKpis`, `getSeguimientoFunnel`. `get_followups` intacto |
| `public_html/outbound/tabs/seguimiento.php` | **Nuevo tab**: 6 scorecards (KPIs), embudo de 5 etapas con % conversión, filtros (búsqueda/federación/días/solo alta), cola de trabajo con pestañas **Perseguir** (no respondedores) y **Avanzar** (sin próxima acción), semáforo de prioridad |
| `public_html/outbound/js/app.js` | Nuevo componente **`seguimientoApp()`** (load con filtros, openFicha→openLead, perseguir→Lanzadera) |
| `public_html/outbound/dashboard.php` | Botón tab **"Seguimiento"** (entre Bandeja y Lista Negra) + include del tab |
| `public_html/outbound/tabs/followups.php` | **Eliminado** (huérfano, sustituido por `seguimiento.php`) |
| `scripts/test_seguimiento.php` | Test de funciones puras (16 casos) |
| `docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md` | Marcado como IMPLEMENTADO |

## 3. Lógica de scoring implementada

`calcularPrioridadLead()`: apertura **+30** · ≥7 días **+25** · 3-6 días **+10** · volumen ≥50 **+15** · volumen 20-49 **+10** · presupuesto creado **+25** · estado `04 Propuesta` sin próxima acción **+15** → score ≥50 **Alta** · ≥25 **Media** · resto **Baja**.
Orden de cola: prioridad → días desc.

> Ajuste durante validación: el peso de ≥7 días subió de +20 a **+25** para que la urgencia por sí sola alcance "Media" (un no-respondedor antiguo no debe quedar en Baja). Reflejado en el plan y el test.

## 4. Validaciones ejecutadas

- **Test funciones puras**: `php scripts/test_seguimiento.php` → **16/16 PASS** (scoring, colas, filtros, funnel, KPIs)
- `php -l` ✅ en `api/analytics.php`, `dashboard.php`, `tabs/seguimiento.php`
- `node --check js/app.js` ✅
- Balance de `<div>` en `tabs/seguimiento.php`: **47/47**
- **Smoke test endpoint real** contra `data/stats.db`: `ok=true`, 156 no respondedores, 2 sin próxima acción, KPIs y funnel con datos reales; top lead priorizado Alta (score 55)
- Sin referencias rotas a `followups.php` (solo comentarios)

## 5. Pendientes fuera de este checkpoint

- Deploy SiteGround (solo con OK explícito del usuario).
- Commit/push (solo con OK explícito del usuario).
- Test manual del render autenticado en servidor (verificar scorecards/embudo/colas en pantalla).
