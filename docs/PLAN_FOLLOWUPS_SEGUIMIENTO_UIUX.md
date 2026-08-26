# PLAN — Módulo "Seguimiento" (ex Follow-ups): gestión de leads + KPIs + UI/UX B2B

**Fecha:** 2026-08-26
**Estado:** ✅ **IMPLEMENTADO** (2026-08-26) — ver `docs/checkpoint_modulo_seguimiento.md`
**Ámbito:** `public_html/outbound/` (`api/analytics.php`, `tabs/seguimiento.php`, `js/app.js`, `dashboard.php`)
**Objetivo:** Retomar el módulo huérfano `tabs/followups.php` rediseñándolo como un módulo de **gestión de seguimiento comercial** (quién perseguir, qué avanza, qué se enfría), con KPIs inteligibles y UI/UX al nivel de las plataformas B2B de referencia.

---

## 1. Contexto y punto de partida

- `tabs/followups.php` quedó **huérfano** (sin botón ni include en el dashboard) tras refactors de tabs, pero su **backend `get_followups` sigue operativo** en `api/analytics.php` (líneas 92-183) con funciones puras.
- El rediseño de tabs ya aprobado deja hueco natural para este módulo entre **Bandeja** y **Analytics**.

### 1.1 Qué hay hoy (análisis del código)

| Pieza | Estado | Detalle |
|---|---|---|
| `getFollowupsNoRespondedores` | ✅ Vivo | Leads `estado='02 Contactado'`, con envíos, sin respuesta, sin baja, sin "En Conversación". Devuelve: club, contacto, último envío/asunto, aperturas, nº envíos, días, `proxima_accion` |
| `getFollowupsSinProximaAccion` | ✅ Vivo | Leads `estado IN ('03 En Conversación','04 Propuesta')` con `proxima_accion` vacía. Devuelve: volumen, presupuesto (join a `presupuestos`), días |
| `getFollowupsKpis` | ✅ Vivo | Solo 4 contadores: no_respondedores, sin_proxima_accion, mockups_pendientes, presupuestos_pendientes |
| `tabs/followups.php` | 🟡 Huérfano | Componente Alpine `followupsApp()`; 2 tablas + 4 scorecards; botón "Ficha" → `openLead(id)` |

### 1.2 Datos reales disponibles (modelo `clubes_crm` + tablas auxiliares)

- `clubes_crm`: `estado_lead` (pipeline `01 Sin Contactar → 02 Contactado → 03 En Conversación → 04 Propuesta → 05 Ganado → 06 Perdido → 07 Baja`), `proxima_accion`, `volumen_estimado`, `num_jugadores`, `ultimo_contacto`, `persona_contacto`, `cargo_contacto`, `tiene_whatsapp`, `federacion`, `telefono_movil`.
- Tablas: `envios`, `aperturas` (vía `tracking_id`), `rebotes`, `comunicaciones_log`, `presupuestos` (`importe_total`, `estado`), `mockups` (`estado`), `lead_pipelines`.
- El pipeline numérico ya existe en `analytics.php` (`$stageOrder`): etapa ≥2 contactado, ≥3 respondió, ≥4 propuesta, =5 ganado.

---

## 2. Referencias UX (plataformas B2B líderes)

Patrones extraídos de HubSpot Sales, Lemlist, Pipedrive e Instantly:

1. **Scorecards de KPIs arriba** — 4-6 tarjetas con icono, label, valor grande y subtítulo; la métrica más importante siempre visible.
2. **Embudo (funnel) de conversión** — visualización del pipeline por etapas con % de conversión entre etapas (dónde se pierde).
3. **Cola de trabajo priorizada** — "qué hacer ahora": tareas/leads ordenados por **urgencia + valor** con semáforo de prioridad (Alta/Media/Baja). Es el corazón de sales engagement tools (Lemlist: "leads waiting for follow-up"; HubSpot: "tasks to do today").
4. **Señales calientes** — aperturas = señal de intención; los que abrieron pero no respondieron tienen prioridad (se muestran con dot/icono verde).
5. **Tablas con filtros y ordenación** — búsqueda, federación, rango de días, prioridad; orden por columna.
6. **Acciones inline** — ver ficha, registrar próxima acción, lanzar follow-up, sin abandonar la lista.
7. **Progreso de secuencia** — nº de envíos y días transcurridos como indicador de "cuántos toques lleva y cuánto ha pasado".

---

## 3. PROPUESTA

### 3.1 Ubicación y nombre

**Tab "Seguimiento"** (icono `alarm-clock` o `mail-question`), entre **Bandeja** y **Analytics**:

> Pipeline · Leads · Plantillas y Campañas · Lanzadera · **Bandeja** · **Seguimiento** ⭐ · Analytics · Lista Negra · Ajustes

Internamente `tab='seguimiento'`; botón + include en `dashboard.php`. Sustituye a `tabs/followups.php` (huérfano), que se elimina.

### 3.2 Arquitectura de UI (de arriba a abajo)

```
┌────────────────────────────────────────────────────────────────┐
│ 1. SCORECARDS (6 KPIs) — fila de tarjetas                      │
│   🔴 No Respondedores · ⏰ Sin Próxima Acción · 👁️ Tasa Apertura │
│   💬 Tasa Respuesta · 🎨 Mockups Pend. · 💶 Pipeline en Juego    │
├────────────────────────────────────────────────────────────────┤
│ 2. EMBUDO DE CONVERSIÓN — barras por etapa con % de conversión  │
│   SinContactar → Contactado → Conversación → Propuesta → Ganado │
├────────────────────────────────────────────────────────────────┤
│ 3. FILTROS — búsqueda, federación, días mín., solo alta prior.  │
├────────────────────────────────────────────────────────────────┤
│ 4. COLA DE TRABAJO (2 pestañas)                                 │
│   A) PERSIGUIR — no respondedores (semáforo prioridad)          │
│   B) AVANZAR  — sin próxima acción (leads calientes parados)    │
└────────────────────────────────────────────────────────────────┘
```

**Scorecards** (patrón de tarjetas del dashboard): icono lucide + label uppercase + valor grande + subtítulo. Nuevos KPIs:
- **Tasa Apertura** = aperturas únicas / entregados (%).
- **Tasa Respuesta** = respondedores (etapa ≥ 03) / contactados (%).
- **Pipeline en Juego** = Σ importe de presupuestos activos (€).

**Embudo** (5 etapas principales): conteo por etapa + % conversión de etapa→etapa (barras `bg-slate-800` con relleno `bg-amber-500` y %).

**Cola A "Perseguir"** — columnas:
`Prioridad(chip semáforo) | Club+email | Contacto | Último envío (fecha+asunto) | Apertura (dot verde=abrió) | Envíos | Días (badge rojo >7) | Acciones`


### 3.3 Lógica de gestión de leads (scoring de prioridad)

Función pura `calcularPrioridadLead(array $lead): array` (testable, como `eligibilidad.php`):

| Señal | +Pts | Fundamento |
|---|---|---|
| Abrió el último envío | +30 | Intención = señal caliente |
| ≥7 días sin respuesta | +25 | Urgencia de 2º toque (sola llega a Media) |
| 3-6 días sin respuesta | +10 | Ventana de persistencia |
| `volumen_estimado ≥ 50` | +15 | Valor del negocio |
| `volumen_estimado 20-49` | +10 | Valor medio |
| Presupuesto en estado `creado` | +25 | Negociación en curso |
| Estado `04 Propuesta` sin próxima acción | +15 | Oferta enfriándose |

**Nivel:** `score ≥ 50` → 🔴 **Alta** · `≥ 25` → 🟠 **Media** · resto → ⚪ **Baja**.
**Orden de la cola:** Alta primero; dentro del nivel, días desc.

### 3.4 Backend (`api/analytics.php`)

- Nuevo action **`get_seguimiento`** (se **mantiene `get_followups` intacto** para no romper nada).
- Nuevas funciones puras (mismo patrón que `getFollowups*`):
  - `getSeguimientoNoRespondedores($db, $whereCommercial, $filtros)` → añade `prioridad`/`score`, filtros `busqueda`, `federacion`, `dias_min`, `solo_alta`.
  - `getSeguimientoSinProximaAccion(...)` → idem.
  - `getSeguimientoKpis($db, ...)` → 6 KPIs (añade tasas de apertura/respuesta y pipeline value).
  - `getSeguimientoFunnel($db, $whereCommercial)` → conteos por etapa + % conversión.
  - `calcularPrioridadLead(array $lead): array` (pura).
- Uso de `COALESCE` para `volumen_estimado` (puede ser NULL) y protección de fechas NULL.

### 3.5 Frontend

- **Crear `tabs/seguimiento.php`** (reemplaza a `followups.php`): componente Alpine `seguimientoApp()`.
- **Mover/definir `seguimientoApp()` en `js/app.js`** (patrón establecido en la reorganización de tabs: funciones de componentes en app.js).
- Métodos: `load()` (fetch `?action=get_seguimiento`), `filtrar()`, `ordenar()`, `perseguir()` (lanzadera), `openFicha()` (delegar a `openLead`).
- Respetar reglas de legibilidad del panel: fuentes ≥12px, `font-semibold` solo en títulos, contrastes `text-slate-300/400` sobre `bg-slate-900/950`.

### 3.6 Integración `dashboard.php`

- Botón `tab='seguimiento'` entre Bandeja y Analytics + `include tabs/seguimiento.php`.
- Valores internos de tabs intactos (solo se añade uno nuevo).

---

## 4. Plan de ejecución

| Fase | Acción | Archivos |
|---|---|---|
| **F1** | Backend: action `get_seguimiento` + funciones puras (prioridad, KPIs, funnel) + tests | `api/analytics.php`, `scripts/test_seguimiento.php` |
| **F2** | Frontend: `tabs/seguimiento.php` + `seguimientoApp()` en app.js (scorecards, embudo, colas, filtros) | `tabs/seguimiento.php`, `js/app.js` |
| **F3** | Dashboard: botón + include; eliminar `tabs/followups.php` | `dashboard.php`, `tabs/followups.php` |
| **F4** | Acción "Enviar follow-up" → preselección de lead en Lanzadera | `js/app.js`, `tabs/lanzadera.php` |
| **F5** | Validación (`php -l`, `node --check`, tests, balance divs) + docs + checkpoint | — |

## 5. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Romper `get_followups` | Se mantiene intacto; se añade `get_seguimiento` aparte |
| `volumen_estimado`/fechas NULL | `COALESCE` + protección de NULL en backend |
| Consultas pesadas | COUNT/agregados sobre índices existentes (`idx_crm_estado`); top-N en colas |
| Violar regla de oro (no tocar otros archivos) | Solo: `analytics.php`, `seguimiento.php` (nuevo), `app.js`, `dashboard.php` |
| Volver a dejar un tab huérfano | Se elimina `followups.php`; el módulo queda integrado en el dashboard |

## 6. Validación mínima (antes de cerrar)

- `php -l` en archivos tocados · `node --check app.js`
- Tests de funciones puras (`scripts/test_seguimiento.php`): prioridad, KPIs, funnel
- Render autenticado: scorecards con datos, embudo, colas ordenadas por prioridad, filtros
- Balance de `<div>` en el nuevo tab · `x-data` referenciados definidos

**Cola B "Avanzar"** — columnas:
`Prioridad | Club + estado(chip pipeline) | Volumen | Presupuesto(€) | Días sin contacto (rojo >7) | Acciones`

**Acciones inline:** [Ficha] (reutiliza `openLead`) + [Enviar follow-up] (prepara el lead en la Lanzadera) + [Registrar próxima acción] (desde la ficha).


