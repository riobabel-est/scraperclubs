# ESTUDIO — Cómo se operan y gestionan las actividades en un CRM moderno

**Fecha:** 2026-08-26
**Propósito:** Marco de referencia para operar el CRM FutProtec Outbound con la misma lógica intuitiva de las plataformas líderes (HubSpot, Pipedrive, Close, Attio, Lemlist/Instantly).
**Aplicación:** módulo `public_html/outbound/` (tabs: Pipeline, Leads, Plantillas y Campañas, Lanzadera, Bandeja, Seguimiento, Analytics, Lista Negra, Ajustes).

---

## 1. LOS 7 PRINCIPIOS DE UN CRM MODERNO

1. **Activity-centric** — *Todo es una actividad* (email, llamada, tarea, reunión, nota). El lead no es una ficha estática: es un **historial de actividades**. Close y Attio construyen el CRM alrededor de esto.
2. **Pipeline como columna vertebral** — Las etapas (`01 Sin Contactar → … → 05 Ganado`) son el lenguaje común. Cada tarjeta del pipeline debe mostrar: **valor + próxima acción + última actividad**.
3. **Colas que empujan, no que esperan** — El CRM **prioriza por ti**: "tareas de hoy", "seguimientos vencidos", "leads sin actividad en 30 días". El usuario no busca trabajo: lo recibe ordenado (Close: *"inbox that prioritizes what needs action now"*).
4. **Contexto en 0 clics** — La ficha del lead lo dice todo sin abrir nada: comunicación, estado, valor, próxima acción, historial (Close: *Lead Page* consolidada).
5. **Cero tool-switching** — Email, SMS, llamadas, tareas y reporting **en el mismo lugar**. Cada cambio de herramienta rompe el flujo.
6. **Automatización de lo repetitivo** — Secuencias y workflows liberan al vendedor del follow-up manual (Close: *"Manual follow-ups are a huge bottleneck"*).
7. **Reporting que empuja acción** — Los dashboards responden **preguntas operativas** ("¿dónde se atasca? ¿quién no responde? ¿cuánto falta para el objetivo?"), no solo muestran tablas.

---

## 2. EL RITUAL OPERATIVO DIARIO (así se trabaja de verdad)

| Momento | Acción | Patrón del CRM moderno |
|---|---|---|
| **Apertura del día** | Inbox unificado + cola de "hoy" | Ver respuestas nuevas y tareas vencidas de un vistazo (Bandeja + Seguimiento) |
| **Priorización** | Cola por prioridad/urgencia | Semáforo (Alta/Media/Baja) + filtros: solo vencidos, solo calientes |
| **Ejecución** | Cada lead = actividad: llamar, enviar, avanzar | Acciones inline sin cambiar de pantalla (Enviar/Ficha) |
| **Logging** | Registrar automático de la actividad | Al enviar desde la Lanzadera, el log se guarda solo |
| **Cierre del día** | Programar la **siguiente acción** de cada lead caliente | `proxima_accion` + fecha → alimenta la cola del día siguiente |
| **Ritmo semanal** | Revisar pipeline y embudo | Analytics: cuellos de botella, objetivo, proyección |

**Clave:** el CRM moderno **invierte la carga** — en vez de "yo consulto el CRM", el CRM **me consulta a mí** con colas inteligentes. Seguimiento, Bandeja y Analytics del panel ya cubren el 80% de este ritual.

---

## 3. GESTIÓN DE LEADS (el arte de la priorización)

### 3.1 Smart Views (patrón Close/HubSpot)
Vistas dinámicas que se auto-actualizan según actividad real. Las 4 más universales:

| Smart View | Regla | En nuestro CRM |
|---|---|---|
| **Leads calientes** | Abrieron emails / respondieron recientemente | Cola **Perseguir** con dot verde de apertura ✅ |
| **Negocios parados (stalled)** | Deals activos sin emails/llamadas/actualización en 30+ días | Cola **Avanzar** (sin próxima acción) ✅ |
| **Seguimiento rápido** | Leads nuevos sin actividad en la 1ª semana | No cubierto → **gap** |
| **Siguientes pasos vencidos** | Tareas con due date pasado | Parcial (badge de días >7) → **gap** |

### 3.2 Lead Scoring (patrón HubSpot/Attio)
Un **score automático** combina señales de interés (apertura, respuesta, click) + valor (volumen, presupuesto).
→ **Ya implementado** en `calcularPrioridadLead()`: apertura +30, antigüedad +25, volumen +15, presupuesto +25, propuesta parada +15 → semáforo Alta/Media/Baja. Alineado con el estándar.

### 3.3 Tarjetas del pipeline con contexto (patrón Pipedrive)
Cada tarjeta del Kanban debe mostrar 3 cosas sin abrir: **valor** (volumen/presupuesto), **próxima acción** (fecha + tipo) y **última actividad** (hace 2 días, abrió).
→ En nuestro Kanban: chips de federación ✅; falta **próxima acción visible** en la tarjeta → **gap menor**.

---

## 4. SEGUIMIENTO / FOLLOW-UPS (el corazón de la actividad)

### 4.1 Modelo de secuencias (Lemlist/Instantly/HubSpot Sequences)
Un lead que no responde entra en una **secuencia de pasos automáticos**:
`Email 1 → +3 días Email 2 → +5 días Tarea "llamar" → +7 días Email 3 (cierre)`.
Cada paso tiene **tiempo y canal**. El vendedor solo interviene cuando la secuencia lo pide (respuesta, llamada, baja).
→ En nuestro CRM: la **Lanzadera + Campañas** hace envíos en lote; falta el **encadenamiento temporal** de follow-ups por lead (el "2º toque a los no respondedores" se opera hoy desde la cola Perseguir manualmente). → **Gap estratégico** (F4 del plan P-1, sin implementar).

### 4.2 Reglas de re-enganche (Close Smart Views + Instantly)
El sistema **detecta y agrupa**:
- Abrió pero no respondió → 2º toque (señal caliente).
- ≥7 días sin contacto → vencido.
- En conversación sin siguiente paso → se enfría.
→ **Ya operativo en Seguimiento** con scoring y semáforo. ✅

### 4.3 Tareas con vencimiento (Pipedrive/HubSpot)
Cada lead caliente tiene una **próxima acción con fecha**. La cola del día = todas las acciones vencidas o de hoy.
→ Tenemos el campo `proxima_accion` (texto) pero **sin fecha de vencimiento** → no puede generar una agenda "tareas de hoy" automática. → **Gap P1**: añadir `fecha_proxima_accion` y que alimente la cola Avanzar.

---

## 5. ANALÍTICA OPERATIVA (reporting que decide)

### 5.1 Embudo con cuellos de botella
Un CRM moderno calcula **conversión etapa→etapa** y señala la etapa donde más se pierde, para decidir **dónde invertir esfuerzo** (mensaje, segmento, formato).
→ Tenemos embudo de 5 etapas en Seguimiento y el de **12 niveles + cuello de botella** en `analyticsApp` (sin conectar). → **Gap P0**: conectar el dashboard global.

### 5.2 Activity-based reporting (Close)
Reportes de **actividad por rep**: nº llamadas/emails/días con actividad → coaching y ritmo. En nuestro caso (1 usuario), equivale a métricas de acción sobre la cola: nº de 2º toques hechos, nº de próxima-acciones registradas.

### 5.3 Objetivo y proyección (Close forecasting / HubSpot Forecast)
Cierra la analítica: **objetivo de cierre (20 clubes) → tasa de cierre actual → proyección**. El dato que responde "¿vamos a llegar?"
→ **Ya construido** en `getAnalyticsDashboard` (objetivo + tasa_cierre + proyección). → **Gap P0**: conectar.

### 5.4 Ritmo de medición
- **Diario:** Bandeja (respuestas) + Seguimiento (colas).
- **Semanal:** Analytics global (embudo + objetivo + A/B/C).
- **Por campaña:** PRR de variantes (piloto A/B/C) para decidir el mensaje de la siguiente tanda.


---

## 6. PATRONES DE UX QUE HACEN "INTUITIVO" UN CRM

| Patrón | Ejemplo en plataformas | Aplicable a FutProtec |
|---|---|---|
| **Command palette / búsqueda global** | Cmd+K de Attio/HubSpot | Búsqueda de club por cualquier campo en 1 campo |
| **Drag & drop del pipeline** | Pipedrive | Ya hay Kanban drag&drop ✅ |
| **Acciones inline** (sin abrir ficha) | Close: llamar/email desde la fila | Seguimiento: Enviar/Ficha inline ✅ |
| **Filtros persistentes** | HubSpot: filtros que se recuerdan | Filtros de colas recargables ✅ (sin persistencia entre visitas → gap menor) |
| **Empty states con CTA** | "No hay leads — importa CSV" | Seguimiento: mensajes vacíos informativos ✅ |
| **Snooze / posponer** | Pipedrive: posponer actividad | No existe → gap menor (mover lead a "en espera") |
| **Notificaciones en tiempo real** | HubSpot/Close | Notificador de respuestas nuevas ✅ (campana) |
| **Logging automático** | Close: llamadas se registran solas | Envíos desde Lanzadera ya generan `envios` ✅ |

---

## 7. APLICACIÓN AL CRM FUTPROTEC — Estado por patrón

| Patrón (estándar) | Dónde está en FutProtec | Estado |
|---|---|---|
| Pipeline + etapas canónicas | Kanban (7 etapas) | ✅ |
| Lead scoring con señales | `calcularPrioridadLead` (Seguimiento) | ✅ |
| Cola de no-respondedores priorizada | Tab Seguimiento → Perseguir | ✅ |
| Cola de leads parados | Tab Seguimiento → Avanzar | ✅ |
| Smart View "nuevos sin actividad" | — | 🔴 **Gap** |
| Tareas con fecha de vencimiento | `proxima_accion` sin fecha | 🟡 **Gap P1** |
| Secuencias de follow-up automáticas | — | 🔴 **Gap estratégico** (F4 P-1) |
| Embudo global con cuello de botella | `analyticsApp` (construido, sin UI) | 🟡 **Gap P0: conectar** |
| Objetivo + proyección de cierre | `getAnalyticsDashboard` (construido, sin UI) | 🟡 **Gap P0: conectar** |
| A/B/C por campaña (PRR) | Tab Analytics piloto | ✅ |
| Notificación de respuestas | Campana (rsNuevas) | ✅ |
| Siguiente acción visible en tarjeta | Kanban | 🟡 Gap menor |

---

## 8. ROADMAP RECOMENDADO (orden de impacto)

| Prioridad | Acción | Impacto operativo |
|---|---|---|
| **P0** | Conectar el **dashboard analítico global** (embudo 12 niveles + KPIs € + objetivo/proyección) como 2ª pestaña de Analytics | Decidir con datos dónde invertir esfuerzo |
| **P1** | Añadir **`fecha_proxima_accion`** y que la cola Avanzar muestre "vencido hoy" | Agenda diaria real de seguimiento |
| **P1** | Smart View **"nuevos leads sin actividad (1ª semana)"** en Seguimiento | No dejar enfriar los recién importados |
| **P2** | **Secuencias de follow-up** encadenadas por lead (2º toque automático con plantilla) | Eliminar el cuello de botella del seguimiento manual |
| **P2** | Mostrar **próxima acción en la tarjeta del Kanban** | Contexto en 0 clics |
| **P3** | Persistencia de filtros + snooze/posponer | Confort del día a día |

---

## 9. CONCLUSIÓN

El CRM FutProtec Outbound ya implementa **~70% de los patrones operativos de un CRM moderno** (scoring, colas priorizadas, pipeline, envíos con logging, notificaciones). Los mayores saltos de efectividad están en:
1. **Conectar lo ya construido** (dashboard analítico global: P0) — es código listo sin UI.
2. **Añadir fechas a las próximas acciones** (P1) — transforma la cola en una **agenda diaria**.
3. **Automatizar el 2º toque** (secuencias, P2) — el siguiente nivel de operación.

Este estudio es la guía de producto; cada acción del roadmap se implementa por separado (con su plan y validación).

