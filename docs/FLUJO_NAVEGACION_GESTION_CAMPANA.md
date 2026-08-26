# FLUJO DE NAVEGACIÓN Y GESTIÓN DE CAMPAÑA — Modelo de las grandes plataformas CRM/Mailing B2B

**Fecha:** 2026-08-26
**Propósito:** Definir cómo debe fluir la navegación de datos, la gestión de campañas y los KPIs en FutProtec Outbound, tomando como referencia HubSpot, Pipedrive, Close, Attio, Lemlist, Instantly y Klaviyo.
**Aplicación:** Panel `public_html/outbound/` (9 tabs + contexto de campaña global).

---

## 1. EL MODELO MENTAL DE LAS GRANDES PLATAFORMAS

Las plataformas líderes no organizan por "pantallas", sino por **un objeto central** y un **contexto de trabajo**:

| Plataforma | Objeto central | Cómo navega el usuario |
|---|---|---|
| **HubSpot** | El **Deal** (negocio) | Todo cuelga del deal: actividades, tareas, emails, reuniones. El *Sales Workspace* agrupa "qué hacer hoy". |
| **Pipedrive** | El **Deal** en el pipeline | El pipeline es la columna vertebral; las **actividades** (llamadas/tareas) se fijan a cada deal con recordatorios. |
| **Close** | El **lead + su conversación** | La **unified inbox** es la puerta de entrada: prioriza "qué requiere acción ahora" y desde ahí saltas al lead. |
| **Lemlist / Instantly** | La **campaña** | Los leads entran en **secuencias**; el dashboard muestra el progreso de la secuencia por lead y los KPIs de campaña. |
| **Klaviyo** | La **campaña / flow** | Dashboard por campaña: enviados → entregados → abiertos → clics → conversión. |

**Patrón común (la regla de oro):** hay **UN contexto de trabajo** (campaña / pipeline / equipo) que filtra TODO, y cada pantalla responde a una pregunta operativa concreta. Nunca se navega "a ciegas": los datos están vinculados entre sí (drill-down de una métrica a la lista y de la lista al lead).

---

## 2. EL CICLO DE VIDA DE UNA CAMPAÑA (el journey del usuario)

Una campaña en mailing B2B vive 8 fases. Cada fase tiene su **tab**, su **pregunta** y su **KPI maestro**:

| Fase | Tab (FutProtec) | Pregunta operativa | KPI maestro |
|---|---|---|---|
| **0. Crear campaña** | Plantillas y Campañas | ¿A quién va dirigida? ¿Qué plantillas A/B/C? | Campañas creadas |
| **1. Segmentar** | Plantillas y Campañas | ¿Qué leads entran? (federaciones, segmento) | Leads asignados |
| **2. Diseñar mensaje** | Plantillas y Campañas | ¿Cuál es el mejor asunto/cuerpo? | Variantes A/B/C definidas |
| **3. Enviar** | Lanzadera | ¿A quién envío hoy y con qué límite? | Enviados / entregados |
| **4. Medir interés** | Analytics (Piloto) | ¿Qué variante abre y responde mejor? | PRR + Open Rate |
| **5. Perseguir** | Seguimiento → Perseguir | ¿Quién abrió y no respondió? | No respondedores priorizados |
| **6. Conversar** | Bandeja | ¿Qué respuestas hay y cómo clasificarlas? | Respuestas positivas |
| **7. Avanzar** | Seguimiento → Avanzar | ¿Qué lead caliente se está enfriando? | Sin próxima acción / vencidos |
| **8. Optimizar** | Analytics (Global) | ¿Dónde se atasca el embudo? ¿Llego al objetivo? | Embudo + objetivo + €/100 |

**La clave:** el usuario **recorre la campaña** (0→8) sin perder el contexto: **siempre sabe en qué campaña está trabajando** porque el selector del header lo filtra todo.

---

## 3. EL PATRÓN DE NAVEGACIÓN: CONTEXTO + DATOS + ACCIÓN

Cada pantalla debe tener 3 zonas bien diferenciadas (patrón de todas las plataformas):

```
┌────────────────────────────────────────────────────────────────┐
│ CONTEXTO   → Selector de campaña (header, persistente)          │
├────────────────────────────────────────────────────────────────┤
│ DATOS      → Qué veo ahora (KPIs, embudo, cola, tabla)          │
│              con DRILL-DOWN: métrica → lista → ficha del lead   │
├────────────────────────────────────────────────────────────────┤
│ ACCIÓN     → Qué hago (Enviar, Programar, Ficha, Clasificar)    │
│              nunca a más de 1 clic de la fila                   │
└────────────────────────────────────────────────────────────────┘
```

### 3.1 Reglas de navegación (extraídas de las plataformas)
1. **El contexto es persistente** — cambias de campaña una vez y todo el panel la respeta (Fase 1 ✅ implementada).
2. **Drill-down universal** — cada número es clicable: KPI → lista de leads → ficha del lead. (HubSpot/Pipedrive: clic en una métrica = lista filtrada).
3. **Acción en línea** — desde la fila del lead puedes: ver ficha, enviar, programar, cambiar estado. (Close: llamar/email sin salir de la lista ✅).
4. **Vínculos cruzados** — "ver en Pipeline", "ver en Bandeja" desde una ficha; las vistas se complementan, no duplican.
5. **El tab analítico cierra el círculo** — al ver un cuello de botella, el siguiente clic debe llevarte a la acción (Seguimiento).
6. **Cero tool-switching** — la campaña (configuración, envío, bandeja, seguimiento) vive en el mismo panel.

---

## 4. MAPA DE NAVEGACIÓN RECOMENDADO PARA FUTPROTEC (flujo por campaña)

```
[📁 Campaña: PILOTO_FUTPROTEC_2026_08]   ← CONTEXTO GLOBAL (header)
        │
        ▼
  1. PLANTILLAS Y CAMPAÑAS   → crea la campaña: segmento + variantes A/B/C + secuencia
        │
        ▼
  2. PIPELINE (Kanban)       → ve el embudo de ESTA campaña (drag&drop de etapas)
        │
        ▼
  3. LANZADERA               → envía a los leads de ESTA campaña (federación/estado)
        │
        ▼
  4. BANDEJA                 → conversaciones de ESTA campaña (clasificar respuestas)
        │
        ▼
  5. SEGUIMIENTO             → colas de ESTA campaña:
                                • Perseguir (no respondieron)  → Enviar 2º toque
                                • Avanzar (se enfrían)         → Programar/cerrar
                                • Calentar (nuevos sin toque)  → 1er envío
        │
        ▼
  6. ANALYTICS               → KPIs de ESTA campaña:
                                • Piloto A/B/C (qué variante rinde)
                                • Efectividad Global (embudo + objetivo + €)
        │
        ▼
  7. LISTA NEGRA / AJUSTES   → higiene y configuración (sin campaña)
```

**Lectura del flujo:** el usuario entra por el tab donde tiene trabajo, pero el **contexto de campaña le sigue** (Pipeline, Seguimiento y Analytics ya filtran por campaña). El recorrido completo de una campaña es un **bucle**: Lanzadera → Bandeja → Seguimiento → Analytics → (optimizar Plantillas y Campañas) → Lanzadera…

---

## 5. FLUJO DE DATOS (DRILL-DOWN) — el pegamento del panel

La navegación moderna es **profunda, no ancha**: de la métrica al detalle en 2 clics.

| Desde | Clic | Llegas a |
|---|---|---|
| Scorecard "No Respondedores" (Seguimiento) | 1 clic | Cola Perseguir filtrada |
| Embudo (Analytics/Global) | clic en una etapa | Lista de leads de esa etapa |
| Cola → fila | clic "Ficha" | Ficha del lead (actividades, conversación) |
| Bandeja → conversación | clic | Lead + historial completo |
| Analytics → variante ganadora | clic | Leads asignados a esa variante |
| KPI objetivo → proyección | — | Plan de contactos necesarios |

**Estado actual en FutProtec:** los KPIs del Seguimiento **no son clicables** (solo se ven los números); Analytics muestra tablas pero sin drill-down a listas. **Este es el mayor gap de navegación.**


---

## 6. KPIs POR ETAPA DEL FLUJO (qué medir y dónde)

| Etapa | KPIs | Dónde |
|---|---|---|
| **Entrega** | Enviados · Aceptados SMTP · Rebotes | Analytics Global / Modal rápido |
| **Interés** | Open Rate · Aperturas únicas | Seguimiento (scorecard) + Piloto |
| **Respuesta** | Reply Rate · PRR (positivas) · Clasificación | Piloto (por variante) |
| **Conversión** | En conversación · Propuestas · Mockups · Presupuestos | Seguimiento (colas) + Global (embudo) |
| **Cierre** | Ganados · Objetivo (20) · Proyección | Global (objetivo) |
| **Económico** | Facturación · Pares · Margen · €/100 contactos · Ticket medio | Global (KPIs económicos) |
| **Operativo** | No respondedores · Vencidos · Nuevos sin actividad | Seguimiento (scorecards + colas) |
| **Mensaje** | PRR por variante A/B/C | Piloto |

**Regla de los grandes:** cada KPI debe poder **explicarse** con un clic ("¿por qué 156?" → la lista de los 156).

---

## 7. ESTADO ACTUAL vs. RECOMENDADO (gaps de navegación)

| Capacidad | Estado FutProtec | Plataformas |
|---|---|---|
| Contexto de campaña global | ✅ Implementado (Fase 1) | Estándar |
| Filtro de campaña en Pipeline/Seguimiento/Analytics | ✅ Implementado | Estándar |
| **Bandeja por campaña** | ❌ Global | Close/HubSpot filtran |
| **Lanzadera sincronizada con el contexto** | 🟡 Tiene selector propio (no sincronizado) | Lemlist liga la campaña al envío |
| **Drill-down de KPIs → listas** | ❌ KPIs no clicables | Estándar |
| **Vínculos cruzados** (Analytics↔Seguimiento↔Bandeja) | ❌ No existen | Estándar |
| **Secuencia de follow-up automática** (2º toque) | ❌ Manual desde la cola | Lemlist/Instantly |
| Búsqueda global | 🟡 Por tab (no global) | HubSpot/Attio Cmd+K |
| Próxima acción en tarjeta del Kanban | ❌ Solo en Seguimiento | Pipedrive |
| Notificaciones en tiempo real | ✅ Campana (respuestas) | Estándar |

---

## 8. MEJORAS PRIORIZADAS PARA ALCANZAR EL FLUJO RECOMENDADO

| Prioridad | Acción | Impacto en navegación |
|---|---|---|
| **P0** | **Drill-down de KPIs**: clic en scorecard/embudo → lista de leads filtrada (misma campaña) | El panel pasa de "informativo" a "operativo" — la navegación profunda |
| **P0** | **Bandeja y Lanzadera por campaña** (Fase 2 del contexto global) | Cierra el círculo: TODOS los tabs respetan la campaña |
| **P1** | **Vínculos cruzados**: botones "Ver en Pipeline/Seguimiento" desde fichas y Analytics | Navegación entre contextos complementarios |
| **P1** | **Sincronizar Lanzadera** con el contexto global (que `lzCampaignId` herede la campaña del header) | Sin re-seleccionar al entrar a enviar |
| **P2** | **Secuencia de follow-up** (2º toque automático con plantilla tras X días) | El bucle 5→3 del ciclo se automatiza |
| **P2** | **Próxima acción en la tarjeta del Kanban** + búsqueda global | Contexto en 0 clics y acceso instantáneo |

---

## 9. CONCLUSIÓN

El panel FutProtec ya tiene el **80% de la estructura** del flujo recomendado (contexto de campaña global, colas priorizadas, analítica de embudo/objetivo, A/B/C). Lo que falta para la **paridad de navegación** con HubSpot/Pipedrive/Lemlist es **conectar los puntos**: drill-down de KPIs, Bandeja/Lanzadera por campaña y vínculos cruzados. Es decir: **no falta más contenido, falta que los datos naveguen entre sí**.

Este documento es la guía; cada mejora del roadmap se implementa por separado (con su plan y validación).

