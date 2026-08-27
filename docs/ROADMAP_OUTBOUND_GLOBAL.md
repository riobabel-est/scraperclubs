# ROADMAP GLOBAL — Plataforma `public_html/outbound/`

**Fecha:** 2026-08-26
**Propósito:** Único roadmap operativo consolidado (sustituye el compendio como referencia viva). Coteja TODOS los pendientes documentados (REFACTORIZACIONES, FUTURE_IMPROVEMENTS, auditoría, seguridad, planes) contra lo ya implementado.
**Estado:** Verificado contra el código y la BD de producción (descargada de SiteGround el 2026-08-26).

---

## 0. RESUMEN DE LO HECHO (2026-08-25/26)

| Bloque | Entregable |
|---|---|
| **Seguridad / refactor base** | R-1, R-2, R-4 · S-1 (parcial), S-2 · A-3, A-4 · FI-001, FI-003, FI-004 · A-2 (resuelto de facto) |
| **P-1 F1-F3** | Configurador de Campañas + Categorías de Plantillas (backend + UI en Plantillas y Campañas) |
| **P-3** | Reorganización de tabs (Pipeline, Leads, Plantillas y Campañas, Lanzadera, Bandeja, Seguimiento, Analytics, Lista Negra, Ajustes) |
| **P-4** | Módulo Seguimiento: scorecards, embudo 5 etapas, colas Perseguir/Avanzar/Calentar, scoring de prioridad |
| **P-5** | Analytics Global conectado (embudo 12 niveles, KPIs €/100, objetivo/proyección, A/B/C) + agenda `fecha_proxima_accion` + smart view Calentar + poda legacy |
| **P-6** | Contexto de campaña global (selector header → Pipeline, Seguimiento, Analytics, **Bandeja y Lanzadera — Fase 2 hecha**) |
| **AI-1** | Reordenación de la navegación por bloques lógicos (**Setup → Operación → Análisis**) con labels de grupo |
| **AI-5** | Plantillas por objetivo + plantilla "Seguimiento Caliente - Paso 3" + cola "Calientes sin responder" en Seguimiento (asesor) |
| **AI-6** | Anti "Falsa Automatización": trigger de respuesta por sentimiento (positiva→03, negativa→06), fulfillment de mockups, registro WhatsApp + avance a 03, acciones en lote en Seguimiento (asesor) |
| **AI-7** | Prioridad honesta: eliminado el score de "prioridad" basado en datos inexistentes; ahora deriva de la temperatura (usuario) |
| **AI-8** | Ramal de interés por variante ABC: etiqueta de interés en Seguimiento + borrador IA que continúa el ángulo validado (asesor) |
| **AI-9** | Widget flotante de tutoría (arrastrable/minimizable, guía por tab y pasos de configuración) + análisis UX del tab Plantillas y Campañas |
| **AI-10** | Asistente IA de Plantillas: genera email o variantes A/B/C (categoría, ramal, tono, longitud) y rellena el editor |
| **O-1 (plan)** | Plan dedicado creado: secuencia condicional por ramal ABC (IF/THEN, modo asistido/automático) → `docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md` |
| **O-1 (impl.)** | **Implementado F1-F3 (2026-08-26)**: DDL `secuencias`/`secuencia_pasos` + `envios.secuencia_id/paso_secuencia` + índice único; motor `secuencia_programarYEnviar` en `cli/cron.php`; endpoints CRUD en `api/campanas.php`; configurador en `tabs/editor.php` + `secuenciaConfig()`; cola "📋 Secuencia pendiente" en Seguimiento (aprobar/descartar) |
| **P0 navegación** | Drill-down KPIs/embudos → listas · Bandeja/Gestor por campaña · Lanzadera sincronizada |
| **UI/UX** | Refactor de Plantillas y Campañas (2 columnas, configurador compacto, pills, chips) |

**Commits:** `a6d284c` (UI/UX) · `b157844` (P0 navegación) · `2bff1ec` (fix campaña) · `164dda9` (contexto global) · `68e78ce` (CRM HubSpot).
**Pendiente de subida:** commit + deploy SiteGround (solo con OK explícito).

---

## 1. PRIORIDAD 0 — OPERACIÓN COMERCIAL (valor inmediato)

| ID | Ítem | Estado | Beneficio operativo |
|---|---|---|---|
| **O-1** | **Secuencia de follow-up automática por ramal ABC** (IF/THEN: el lead que abrió la variante X recibe el Paso 2 en esa misma línea argumental; modo asistido o automático) | ✅ **Implementado F1-F3** (2026-08-26) — DDL, motor cron, endpoints CRUD, configurador UI y cola "Secuencia" en Seguimiento. **Pendiente F4** (endurecer elegibilidad) y activarlo en producción | Elimina el cuello manual del 2º toque y duplica la apuesta en el ángulo que el club ya validó — el mayor salto comercial. Ver `docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md` |
| **O-2** | **Vínculos cruzados** entre tabs (ficha → Pipeline/Seguimiento; Analytics → acción) | 🔴 Pendiente | El panel se navega en contexto, sin saltos ciegos |
| **O-3** | **Próxima acción en la tarjeta del Kanban** (`proxima_accion` + fecha + semáforo) | 🔴 Pendiente | Contexto en 0 clics en el Pipeline (patrón Pipedrive) |

> O-1 se apoya en lo ya construido: cola Perseguir + plantillas A/B/C + `campaign_id` en envíos.

---

## 2. PRIORIDAD 1 — ENTREGABILIDAD Y SEGURIDAD (proteger el canal)

| ID | Ítem | Estado | Beneficio |
|---|---|---|---|
| **E-1** | **FI-002** — Saneamiento de `$cuentasDefault` en `cli/init_db.php` (credenciales en claro en el repo) | 🔴 Pendiente | Elimina el último secreto en claro versionado |
| **E-2** | **FI-005** — Atomicidad de `cuentas_smtp.enviados_hoy` (recuento real unificado) | 🔴 Pendiente | Evita sobre-envíos por cuenta |
| **E-3** | **Deliverability**: verificación SPF/DKIM/DMARC por dominio + warmup de cuentas | 🟡 Propuesto | Protege la reputación del remitente (mailing B2B efectivo) |
| **E-4** | **Gestión de rebotes** endurecida (hard/soft → supresión automática) | 🟡 Propuesto | Protege la reputación y evita bounces repetidos |

---

## 3. PRIORIDAD 2 — DEUDA TÉCNICA GESTIONADA (ritmo sostenible)

| ID | Ítem | Estado | Nota |
|---|---|---|---|
| **T-1** | **R-3** — Dividir monolitos (`inc/imap_respuestas.php`, `api/leads.php`, `cli/init_db.php`) | 🔴 Pendiente | No bloquea; hacerlo en ventanas de calma |
| **T-2** | **R-5** — Prepared statements en endpoints de escritura | 🟡 Mejora | Sin SQLi confirmada (IDs int + escapeString) |
| **T-3** | **FI-007** — Plantillas versionadas inmutables | 🔴 Pendiente | Evita sobrescribir plantillas usadas en envíos |
| **T-4** | **FI-008** — Índices y saneamiento de esquema | 🔴 Pendiente | Rendimiento con volumen |
| **T-5** | **FI-006 (reformulado)** — Histórico de estados Kanban por campaña | 🔴 Pendiente | `lead_pipelines` ya se usa para filtrado; falta el histórico temporal por campaña |
| **T-6** | **P-2** — Limpiar docs legacy (`FUTURE_IMPROVEMENTS.md`) | 🔴 Pendiente menor | El roadmap lo sustituye |

---

## 4. PRIORIDAD 3 — CONFORT Y PRODUCTIVIDAD

| ID | Ítem | Estado |
|---|---|---|
| **C-1** | **Búsqueda global** (club/email desde el header, estilo Cmd+K) | 🔴 Pendiente |
| **C-2** | Persistencia de filtros entre visitas | 🟡 Pendiente |
| **C-3** | Snooze/posponer leads en colas | 🟡 Pendiente |
| **C-4** | **Reorganización de navegación** (síntesis del asesor: 6 secciones operación + ⚙ administración) | 🟡 Parcial — bloques aplicados como **AI-1**; resto de mejoras del asesor en §4b |

---

## 4b. MEJORAS DE ARQUITECTURA DE LA INFORMACIÓN (síntesis del asesor — 2026-08-26)

> Análisis del asesor sobre la arquitectura del CRM (3 bloques: Setup → Operación → Análisis) para reducir la curva de aprendizaje del usuario B2B.

| ID | Ítem | Estado | Beneficio | Nota técnica |
|---|---|---|---|---|
| **AI-1** | **Menú por bloques lógicos** (🛠️ Setup · 📈 Operación · 📊 Análisis) en la navegación | ✅ **IMPLEMENTADO** (2026-08-26) | Reduce la curva de aprendizaje; el panel se recorre en el orden del flujo de trabajo | `dashboard.php` — solo se reordenan/agrupan los botones de la nav; valores internos `tab` intactos (sin tocar `app.js`) |
| **AI-2** | **Importador CSV/Excel en Leads** (captación desde la UI) | 🔴 Pendiente | Lleva al CRM la "Lanzadera de entrada" del asesor (hoy la captación es scraping externo o alta manual) | Nuevo endpoint en `api/leads.php` + UI en `tabs/gestor.php`; reutiliza `lead_validate` (validación MX) y `scanDups` |
| **AI-3** | **Vista "Agenda" en Seguimiento** (próximas acciones por fecha) | 🔴 Pendiente | El vendedor ve el día/vencidas de un vistazo (hoy solo listado operativo) | Sin tabla nueva: agrupar por `clubes_crm.fecha_proxima_accion` + `propuestas_ia.fecha_prevista` |
| **AI-4** | **Pipeline configurable** (etapas del embudo editables en Ajustes) | 🔴 Pendiente (requiere plan dedicado) | Las etapas se definen en Setup, no en código | Migrar literales: `$estadosKanban` (dashboard.php), `estadosPipeline` (seguimiento.js), WHERE en `api/leads.php`, `cli/cron.php`, `api/track.php`, `inc/eligibilidad.php` → tabla `pipeline_etapas` |
| **AI-5** | **Plantillas por objetivo + cola "Calientes sin responder"** (síntesis del asesor 2026-08-26) | ✅ **IMPLEMENTADO** (2026-08-26) | Leads con ≥3 aperturas sin respuesta salen como acción prioritaria; las plantillas se ordenan por objetivo en categorías numeradas (**01 Prospección · 02 Seguimiento · 03 Respuestas**; WhatsApp queda genérica hasta integrar su flujo) | `cli/init_db.php` + `cli/migrar_plantillas_objetivo.php` (migración idempotente de categorías + plantillas "Paso 2" y "Paso 3"), `api/analytics.php` (tipo `calientes` en cola unificada), `js/app.js` (mapeo estado→categoría en Lanzadera), `tabs/seguimiento.php` + `js/seguimiento.js` (vista "🔥 Calientes sin responder") |
| **AI-6** | **Anti "Falsa Automatización": triggers de respuesta + WhatsApp + acciones en lote** (diagnóstico del asesor 2026-08-26) | ✅ **IMPLEMENTADO** (2026-08-26) | La IA positiva mueve el lead a 03, la negativa a 06, la baja a 07; el envío de WhatsApp avanza el lead; Seguimiento tiene acciones en lote SIN quitar el flujo lead a lead | `inc/respuestas.php` (`estadoDestinoPorClasificacion` + `clasificarRespuesta`), `inc/imap_respuestas.php` (`imap_mover_kanban` por sentimiento + `imap_fulfillment_mockup`), `api/leads.php` (`registrar_whatsapp`), `api/get_cola.php` (filtro `ids`), `js/app.js` (`lzBulkIds` + `registrarWhatsApp`), `js/seguimiento.js` + `tabs/seguimiento.php` (checkboxes + barra en lote), `tabs/modals.php` + `tabs/kanban.php` (hook WhatsApp) |
| **AI-7** | **Prioridad honesta: se eliminó el score inventado** (observación del usuario 2026-08-26) | ✅ **IMPLEMENTADO** (2026-08-26) | La columna "Prioridad" se calculaba con volumen/presupuesto que no existen en la BD (0/1818) → prioridad "Alta" falsa. Ahora la prioridad se **deriva de la temperatura** (datos reales: aperturas/respuestas/estado); se eliminaron la columna/filtro "Prioridad" de la tabla (queda Semáforo=urgencia + Temp.=interés) y el checkbox redundante "Solo calientes" | `api/analytics.php` (eliminado `calcularPrioridadLead`; prioridad = `calcularTemperaturaLead()->prioridad`), `tabs/seguimiento.php` + `js/seguimiento.js` (sin columna/filtro de prioridad, tooltip del semáforo con el motivo) |
| **AI-8** | **Ramal de interés por variante ABC** (recomendación del asesor 2026-08-26) | ✅ **IMPLEMENTADO** (2026-08-26) | Etiqueta de interés en Seguimiento (`[Interés: Identidad/Cantera]`, `[Financiero/Rentabilidad]`, `[General/Producto]`) según la variante del test ABC que el lead más abrió; el borrador IA ("Generar con IA") **continúa ese mismo ramal** en vez de cambiar de ángulo; filtro por interés | `api/analytics.php` (`interesDeVariante` + variante dominante por email en `get_seguimiento`), `inc/atencion_lead.php` (prompt con ramal en `generarEmailIA`), `tabs/seguimiento.php` + `js/seguimiento.js` (celda "Variante / Interés" + filtro) |
| **AI-9** | **Widget flotante de tutoría + análisis UX del tab "Plantillas y Campañas"** (2026-08-26) | ✅ **IMPLEMENTADO** | Guía flotante arrastrable/minimizable (posición y estado persistidos) que explica el uso de **cada tab** y los **pasos de configuración** (plantillas → campañas → secuencias) | `dashboard.php` (widget HTML), `js/app.js` (`TUTORIA` + `tutorApp()`) |
| **AI-10** | **Asistente IA de Plantillas** (2026-08-26) | ✅ **IMPLEMENTADO** | Botón "✨ Asistente IA" en el editor: genera el email (asunto+cuerpo) o 3 variantes A/B/C según categoría, ramal, tono, longitud e instrucción, y **rellena el formulario automáticamente** (incluye activar el Test A/B/C) | `api/plantillas.php` (endpoint `generar_plantilla_ia` + `inc/llm.php`), `tabs/editor.php` (botón + modal), `js/app.js` (`abrirAsistente()`/`generarPlantillaIA()`) |

---

## 5. FUTURAS / EVALUACIÓN

| ID | Ítem | Estado |
|---|---|---|
| **F-1** | FI-009 — Evolution API (WhatsApp automatizado) | ⏸️ Evaluación de producto |
| **F-2** | Notificaciones por email de KPIs (reporte semanal) | 🟡 Propuesto |

---

## 6. COTEJO DE PENDIENTES DOCUMENTADOS (verificado contra el código)

| ID original | Estado en docs | Estado REAL (2026-08-26) | Acción |
|---|---|---|---|
| R-1, R-2, R-4 | ✅ Resueltos | ✅ Confirmado | Marcar resuelto |
| R-3, R-5 | 🔴 Pendientes | 🔴 Pendiente | → T-1, T-2 |
| FI-001, FI-003, FI-004 | ✅ Resueltos | ✅ Confirmado | Marcar resuelto |
| FI-002 | 🟡 Parcial | 🔴 Pendiente (`$cuentasDefault` sigue en `init_db.php`) | → E-1 |
| FI-005 | 🔴 Pendiente | 🔴 Pendiente | → E-2 |
| **FI-006** | 🔴 Pendiente ("`lead_pipelines` sin uso") | **Desactualizado**: `lead_pipelines` ya se usa (filtro campaña en 3 archivos). Falta el histórico temporal | → T-5 reformulado |
| FI-007, FI-008, FI-009 | 🔴/⏸️ | Sin cambios | → T-3, T-4, F-1 |
| S-1 | ✅ (parcial FI-002) | ✅ `$CUENTAS_SMTP_FALLBACK` vaciado | OK |
| S-2 | ✅ | ✅ | OK |
| A-2 | 🔴 Pendiente | **✅ Resuelto de facto** (basura temporal ya no existe) | Marcar resuelto |
| A-3, A-4 | ✅ | ✅ | OK |
| P-1 | F1-F3 ✅ | F4 **parcialmente absorbida** por el contexto de campaña (Lanzadera hereda campaña) | → O-1 (secuencias) |
| P-2 | 🔴 | 🔴 menor | → T-6 |
| P-3, P-4, P-5, P-6 | ✅ Implementados | ✅ + Fase 2 (Bandeja/Lanzadera) completada | Confirmado |

---

## 7. COMENTARIOS CRÍTICOS (lo que NO encaja con nuestro enfoque)

1. **"Dashboard ejecutivo" como 1ª pestaña (propuesta del asesor)** → **NO lo adoptamos**: duplicaría Analytics (Efectividad Global) + Seguimiento (scorecards). El panel ya tiene el resumen ejecutivo; un 4º sistema de métricas violaría la regla de no acumular.
2. **Separar Plantillas/Campañas en 2 tabs del header** → **Sí, pero como sub-tabs internos** (C-4), no como pestañas adicionales (saturación). Y solo cobra valor real cuando existan **secuencias** (O-1).
3. **FI-006 literal ("lead_pipelines sin uso")** → **Desactualizado**: esa tabla es ahora el pilar del contexto de campaña. El ítem se reformula como histórico temporal por campaña.
4. **P-1 F4 ("integración Lanzadera")** → **Mayormente absorbida** por el contexto global (la Lanzadera ya hereda la campaña y registra `campaign_id`). El hueco real es la **secuencia de follow-up** (O-1), no la integración.
5. **R-3 (monolitos)** → Prioridad **Media-Baja** (mantenida). No bloquea el roadmap de producto; se ejecuta en ventanas de calma.
6. **Reorganización de navegación del asesor** → Válida en lo esencial (saturación de 9 tabs). La agrupación por bloques (Setup → Operación → Análisis) **se aplicó como AI-1 (2026-08-26)** por ser de bajo riesgo (solo HTML de la nav, valores `tab` intactos). Las mejoras funcionales del asesor (AI-2..AI-4) sí se posponen detrás de O-*.

---

## 8. ORDEN DE EJECUCIÓN SUGERIDO

1. **O-1** (secuencia por ramal ABC) según `docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md` → **O-2 → O-3** (P0 comercial).
2. **E-1 → E-2 → E-3** (entregabilidad/seguridad) — en paralelo si hay ventana.
3. **AI-1 ✅ hecho** (navegación por bloques) · **AI-2 → AI-3** (captación CSV + agenda) tras estabilizar O-* · **AI-4** (pipeline configurable) requiere ventana dedicada (migración de literales).
4. **T-*** (deuda técnica) a ritmo sostenible.
5. **Commit + deploy** de lo acumulado (pendiente de OK).

---

## 9. REFERENCIAS

- `docs/PENDIENTES_OUTBOUND.md` — compendio histórico (sustituido por este roadmap como referencia viva).
- `docs/ESTUDIO_OPERACION_CRM_MODERNO.md` · `docs/FLUJO_NAVEGACION_GESTION_CAMPANA.md` — guías de producto.
- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` · `docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md` — planes de las features.
- `docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md` — **plan dedicado (O-1)**: secuencia condicional de seguimiento por ramal del test ABC (IF/THEN), modo asistido/automático.
- Checkpoints `docs/checkpoint_*.md` — trazabilidad de cada implementación.

