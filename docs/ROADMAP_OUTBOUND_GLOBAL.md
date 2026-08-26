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
| **P0 navegación** | Drill-down KPIs/embudos → listas · Bandeja/Gestor por campaña · Lanzadera sincronizada |
| **UI/UX** | Refactor de Plantillas y Campañas (2 columnas, configurador compacto, pills, chips) |

**Commits:** `a6d284c` (UI/UX) · `b157844` (P0 navegación) · `2bff1ec` (fix campaña) · `164dda9` (contexto global) · `68e78ce` (CRM HubSpot).
**Pendiente de subida:** commit + deploy SiteGround (solo con OK explícito).

---

## 1. PRIORIDAD 0 — OPERACIÓN COMERCIAL (valor inmediato)

| ID | Ítem | Estado | Beneficio operativo |
|---|---|---|---|
| **O-1** | **Secuencia de follow-up automática** (2º toque: "no respondió en X días → cola Perseguir con plantilla B / tarea llamada") | 🔴 Pendiente | Elimina el cuello manual del 2º toque — el mayor salto comercial |
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
| **C-4** | **Reorganización de navegación** (síntesis del asesor: 6 secciones operación + ⚙ administración) | 🟡 En debate |

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
6. **Reorganización de navegación del asesor** → Válida en lo esencial (saturación de 9 tabs), pero **se aplicará después de O-1/O-2** para no mezclar cambios estructurales con la feature comercial clave.

---

## 8. ORDEN DE EJECUCIÓN SUGERIDO

1. **O-1 → O-2 → O-3** (P0 comercial).
2. **E-1 → E-2 → E-3** (entregabilidad/seguridad) — en paralelo si hay ventana.
3. **C-4** (reorganización de navegación) tras estabilizar O-*.
4. **T-*** (deuda técnica) a ritmo sostenible.
5. **Commit + deploy** de lo acumulado (pendiente de OK).

---

## 9. REFERENCIAS

- `docs/PENDIENTES_OUTBOUND.md` — compendio histórico (sustituido por este roadmap como referencia viva).
- `docs/ESTUDIO_OPERACION_CRM_MODERNO.md` · `docs/FLUJO_NAVEGACION_GESTION_CAMPANA.md` — guías de producto.
- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` · `docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md` — planes de las features.
- Checkpoints `docs/checkpoint_*.md` — trazabilidad de cada implementación.

