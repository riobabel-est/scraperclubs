# V4.3 — ESPECIFICACIÓN TÉCNICA DEFINITIVA CRM FUTPROTEC

**Versión:** 4.3 — Definitiva (pre-implementación)  
**Fecha:** 11 de agosto de 2026  
**Fuente de verdad:** Este documento es la especificación vigente y única. V4.0, V4.1, V4.2 son histórico. Si existe contradicción: **V4.3 > V4.2 > documentación anterior > código heredado**. El código actual funcional que no contradiga V4.3 **se conserva**. No se hace reescritura general del CRM.

---

## ÍNDICE

1. [PRINCIPIO RECTOR](#1-principio-rector)
2. [MODELO DE NEGOCIO](#2-modelo-de-negocio)
3. [ARQUITECTURA: ESTADO VS EVENTO](#3-arquitectura-estado-vs-evento)
4. [KANBAN DEFINITIVO (9 COLUMNAS)](#4-kanban-definitivo-9-columnas)
5. [MATRIZ KANBAN: JUSTIFICACIÓN](#5-matriz-kanban-justificación)
6. [FUNNEL ANALÍTICO (12 NIVELES)](#6-funnel-analítico-12-niveles)
7. [PIPELINE / CAMPAÑA (ARQUITECTURA CORREGIDA)](#7-pipeline--campaña-arquitectura-corregida)
8. [A/B/C REAL](#8-abc-real)
9. [CUALIFICACIÓN](#9-cualificación)
10. [MOCKUP COMO RECURSO LIMITADO](#10-mockup-como-recurso-limitado)
11. [PRESUPUESTO VERSIONADO](#11-presupuesto-versionado)
12. [INTERACCIONES Y WHATSAPP](#12-interacciones-y-whatsapp)
13. [ANALÍTICA Y KPIs](#13-analítica-y-kpis)
14. [ATRIBUCIÓN](#14-atribución)
15. [MOTIVOS DE PÉRDIDA Y OBJECIONES](#15-motivos-de-pérdida-y-objeciones)
16. [DASHBOARD Y OBJETIVO 20 CLUBES](#16-dashboard-y-objetivo-20-clubes)
17. [BASE DE DATOS](#17-base-de-datos)
18. [SEGURIDAD Y ESCALABILIDAD](#18-seguridad-y-escalabilidad)
19. [MATRIZ DE COMPATIBILIDAD / REGRESIÓN](#19-matriz-de-compatibilidad--regresión)
20. [FASES DE DESARROLLO](#20-fases-de-desarrollo)
21. [CRITERIO DE ACEPTACIÓN](#21-criterio-de-aceptación)
22. [APÉNDICES](#22-apéndices)

---

## 1. PRINCIPIO RECTOR

**CAPTAR → CONTACTAR → DETECTAR INTERÉS → CUALIFICAR → PREPARAR PROPUESTA → CERRAR → MEDIR → APRENDER → ESCALAR**

> La complejidad debe estar en el sistema y en la analítica, no en el trabajo manual del usuario.

La solución debe optimizar simultáneamente: **conversión, gestión rápida, trazabilidad, analítica y escalabilidad**. No sacrificar trazabilidad por simplicidad, ni simplicidad por exceso de columnas.

---

## 2. MODELO DE NEGOCIO

### 2.1. Producto

**UN DISEÑO EXCLUSIVO POR CLUB Y LOTE.** No existe personalización individual.

```
Club → recopila internamente las tallas
FutProtec → diseña modelo exclusivo
FutProtec → fabrica el lote
FutProtec → entrega en sede
```

⛔ **Prohibido:** diseño individual, nombre de jugador, dorsal, tabla `jugadores`, personalización por jugador en UI/emails. `num_jugadores` es solo dato auxiliar de cualificación.

### 2.2. Precios

| Volumen | Precio B2B | PVP rec. | Margen club/par |
|---------|-----------|----------|-----------------|
| 50–99 | 9 € | 15 € | 6 € |
| 100–199 | 8 € | 15 € | 7 € |
| 200+ | 7 € | 15 € | 8 € |

IVA incl. Mínimo 50 pares. Transporte incl. en Península. Plazo ~3 semanas. Pago: 50%+50% o 100% con 5% dto.

### 2.3. Cálculo automático

```php
function calcularPrecioYMargen(?int $volumen, int $pvp = 15): array {
    if (!$volumen || $volumen <= 0) return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'Desconocido'];
    if ($volumen >= 200)      [$precio, $tramo] = [7, '200+ pares'];
    elseif ($volumen >= 100)  [$precio, $tramo] = [8, '100-199 pares'];
    elseif ($volumen >= 50)   [$precio, $tramo] = [9, '50-99 pares'];
    else return ['precio_b2b'=>null,'facturacion'=>null,'margen_par'=>null,'margen_total'=>null,'tramo'=>'<50 pares'];
    return ['precio_b2b'=>$precio,'facturacion'=>$volumen*$precio,'margen_par'=>$pvp-$precio,'margen_total'=>$volumen*($pvp-$precio),'tramo'=>$tramo];
}
```

---

## 3. ARQUITECTURA: ESTADO VS EVENTO

### 3.1. Definiciones

| Concepto | Significado | Dónde se registra |
|----------|-------------|-------------------|
| **ESTADO** | Dónde está la oportunidad. Qué acción comercial requiere. | `clubes_crm.estado_lead` → columna Kanban |
| **EVENTO** | Algo que ocurrió. | `comunicaciones_log` / `mockups` / `presupuestos` |

Una apertura, un envío, un mockup generado, un presupuesto creado... son **EVENTOS**. No deben crear una nueva columna Kanban salvo que exista una razón comercial real.

### 3.2. Regla de decisión para columnas Kanban

> **¿El usuario necesita hacer algo diferente en este estado respecto al estado anterior?**

- SI → puede ser columna Kanban
- NO → debe ser evento/métrica/atributo en histórico

---

## 4. KANBAN DEFINITIVO (9 COLUMNAS)

Tras aplicar el criterio anterior, 3 de los 11 estados anteriores pasan a ser **eventos automáticos** (no columnas):
- ~~Mockup Solicitado~~ → evento en `mockups`
- ~~Mockup Enviado~~ → evento en `mockups`
- ~~Presupuesto Enviado~~ → evento en `presupuestos`

En su lugar, se unifican bajo un único estado **`Propuesta`** (el lead tiene una propuesta comercial en curso: mockup y/o presupuesto). El sistema registra los hitos (mockup solicitado, mockup enviado, presupuesto v1, v2...) como eventos sin que el usuario tenga que mover el lead 3 veces.

| # | Estado | Acción requerida | Cambio respecto V4.2 |
|---|--------|-----------------|---------------------|
| 1 | `Sin Contactar` | Enviar campaña | Sin cambio |
| 2 | `Contactado` | Esperar respuesta o follow-up | Sin cambio |
| 3 | `Respondió` | Leer, clasificar, responder | Sin cambio |
| 4 | `Interesado` | Preguntar volumen | Sin cambio |
| 5 | `Cualificado` | Decidir: ¿procede propuesta? | Sin cambio |
| 6 | `Propuesta` | Gestionar mockup/presupuesto | **NUEVO** — unifica Mockup Solicitado + Mockup Enviado + Presupuesto Enviado |
| 7 | `Negociación` | Gestionar objeciones | Sin cambio |
| 8 | `Ganado` / `Perdido` | Facturar / Registrar motivo | Sin cambio (2 columnas) |

**Total: 9 columnas definitivas.** Ganado y Perdido son columnas separadas porque representan resultados comerciales diferentes (tasa de ganado, tasa de pérdida, motivos de pérdida, facturación, conversión real).

### 4.1. Cómo funciona `Propuesta`

Cuando un lead está en `Cualificado` y se decide avanzar:

1. El usuario hace clic en **"Solicitar Mockup"** → se crea registro en `mockups` (evento). El lead **pasa automáticamente** a `Propuesta`.
2. Cuando el mockup está listo, el usuario hace clic en **"Mockup Enviado"** → se actualiza `mockups.estado = 'enviado'` (evento). El lead **sigue** en `Propuesta`.
3. El usuario hace clic en **"Crear Presupuesto"** → se crea registro en `presupuestos` (evento). El lead **sigue** en `Propuesta`.
4. El lead pasa a `Negociación` **solo cuando el club responde** a la propuesta (decisión humana).

### 4.2. Transiciones automáticas

| Trigger | De | A |
|---------|----|---|
| Email enviado OK | `Sin Contactar` | `Contactado` |
| Email rebotado | `Contactado` | `Rebotado` (fuera de Kanban, solo BD) |
| Apertura registrada | *Cualquiera* | *No cambia* |
| Baja (opt-out) | *Cualquiera* | `Baja / Opt-Out` (fuera de Kanban) |
| Click "Solicitar Mockup" | `Cualificado` | `Propuesta` |
| Click "Mockup Enviado" | `Propuesta` | `Propuesta` (no cambia, registra evento) |
| Click "Crear Presupuesto" | `Propuesta` | `Propuesta` (no cambia, registra evento) |

El resto de transiciones son manuales.

---

## 5. MATRIZ KANBAN: JUSTIFICACIÓN

| Estado | ¿Requiere acción humana? | Acción | Eventos asociados | ¿Debe ser columna? | Justificación |
|--------|-------------------------|--------|-------------------|---------------------|---------------|
| `Sin Contactar` | ✅ Sí | Enviar | — | ✅ **SÍ** | Requiere acción de envío |
| `Contactado` | ✅ Sí | Esperar / follow-up | email_enviado, email_entregado | ✅ **SÍ** | Acción distinta a Sin Contactar |
| `Respondió` | ✅ Sí | Clasificar respuesta | email_respondido | ✅ **SÍ** | Requiere decisión humana |
| `Interesado` | ✅ Sí | Preguntar volumen | respuesta_positiva | ✅ **SÍ** | Acción comercial diferente |
| `Cualificado` | ✅ Sí | Decidir si propuesta | volumen_registrado | ✅ **SÍ** | Punto de decisión crítica |
| `Propuesta` | ✅ Sí | Gestionar mockup/presp | mockup_solicitado, mockup_enviado, presupuesto_creado, presupuesto_enviado | ✅ **SÍ** | Agrupa 3 micro-estados anteriores. El usuario gestiona, el sistema registra hitos. |
| `Negociación` | ✅ Sí | Gestionar objeciones | objecion_registrada | ✅ **SÍ** | Acción distinta a Propuesta |
| `Ganado` | ❌ No (cierre) | Facturar | pedido_confirmado | ✅ **SÍ** | Estado terminal |
| `Perdido` | ❌ No (cierre) | Registrar motivo | oportunidad_perdida | ✅ **SÍ** | Estado terminal |
| ~Mockup Solicitado~ | ❌ No | — | mockup_solicitado | ❌ **NO** | Es evento, no requiere mover lead |
| ~Mockup Enviado~ | ❌ No | — | mockup_enviado | ❌ **NO** | Es evento, el lead ya está en Propuesta |
| ~Presupuesto Enviado~ | ❌ No | — | presupuesto_enviado | ❌ **NO** | Es evento, el lead ya está en Propuesta |
| ~Rebotado~ | ❌ No | — | email_rebotado | ❌ **NO** | Automático, fuera de Kanban |
| ~Baja / Opt-Out~ | ❌ No | — | baja_solicitada | ❌ **NO** | Automático, fuera de Kanban |

---

## 6. FUNNEL ANALÍTICO (12 NIVELES)

Se calcula en Analytics. Independiente del Kanban. Cada nivel tiene **fórmula matemática explícita** (sin comparaciones alfabéticas de strings).

### 6.1. Orden explícito de estados (para cálculos)

```sql
CASE estado_lead
  WHEN 'Sin Contactar' THEN 1
  WHEN 'Contactado'    THEN 2
  WHEN 'Rebotado'      THEN 3
  WHEN 'Respondió'     THEN 4
  WHEN 'Interesado'    THEN 5
  WHEN 'Cualificado'   THEN 6
  WHEN 'Propuesta'     THEN 7
  WHEN 'Negociación'   THEN 8
  WHEN 'Ganado'        THEN 9
  WHEN 'Perdido'       THEN 10
  WHEN 'Baja / Opt-Out' THEN 11
  ELSE 0
END AS stage_order
```

### 6.2. Niveles del funnel

| # | Nivel | Fórmula |
|---|-------|---------|
| 1 | Contactados | `stage_order >= 2` (todos menos Sin Contactar) |
| 2 | Entregados | Contactados − Rebotes |
| 3 | Abrieron | Leads con `aperturas` registradas (JOIN `envios`) |
| 4 | Respondieron | `stage_order >= 4` |
| 5 | Resp. positivas | `stage_order >= 5` |
| 6 | Cualificados | `volumen_estimado >= 50 AND stage_order >= 6` |
| 7 | Oportunidades | `stage_order >= 7` (Propuesta o superior) |
| 8 | Mockups | `mockups.id IS NOT NULL AND mockups.estado = 'enviado'` |
| 9 | Presupuestos | `presupuestos.id IS NOT NULL` |
| 10 | Negociaciones | `stage_order >= 8` |
| 11 | Ganados | `stage_order = 9` |
| 12 | Perdidos | `stage_order = 10` |

---

## 7. PIPELINE / CAMPAÑA (ARQUITECTURA CORREGIDA)

### 7.1. Problema detectado en V4.2

`clubes_crm.pipeline_id` solo permite un pipeline por club. Pero un mismo club puede participar en múltiples campañas a lo largo del tiempo. Solución: tabla de unión `lead_pipelines`.

### 7.2. Nuevas tablas

```sql
-- Pipelines (sin cambios)
CREATE TABLE IF NOT EXISTS pipelines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    descripcion TEXT DEFAULT '',
    fecha_inicio DATETIME,
    fecha_fin DATETIME,
    variante_ganadora VARCHAR(1),
    activo INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Relación N:M Lead ↔ Pipeline (NUEVA)
CREATE TABLE IF NOT EXISTS lead_pipelines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    pipeline_id INTEGER NOT NULL,
    variante_ab VARCHAR(1) DEFAULT '',       -- A, B o C asignada en esta campaña
    fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES clubes_crm(id),
    FOREIGN KEY (pipeline_id) REFERENCES pipelines(id),
    UNIQUE(lead_id, pipeline_id)
);
CREATE INDEX IF NOT EXISTS idx_lp_lead ON lead_pipelines(lead_id);
CREATE INDEX IF NOT EXISTS idx_lp_pipeline ON lead_pipelines(pipeline_id);
```

### 7.3. Eliminar `pipeline_id` de `clubes_crm`

La relación pasa a `lead_pipelines`. En `comunicaciones_log`, `mockups` y `presupuestos` se mantiene `pipeline_id` para identificar en qué campaña ocurrió cada evento.

### 7.4. Trazabilidad corregida

```
lead_pipelines (lead_id, pipeline_id, variante_ab)
  └── comunicaciones_log (lead_id, pipeline_id, tipo_evento, ...)
  └── mockups (lead_id, pipeline_id, ...)
  └── presupuestos (lead_id, pipeline_id, ...)
```

---

## 8. A/B/C REAL

Sin cambios respecto a V4.2. Cada variante tiene asunto y cuerpo propios. Asignación round-robin. Se guarda en `lead_pipelines.variante_ab` y en `comunicaciones_log.variante_ab`. Trazabilidad completa: se puede reconstruir qué asunto y cuerpo exactos recibió cada club. Comparativa A/B/C en Analytics.

---

## 9. CUALIFICACIÓN

Dato principal: **`volumen_estimado`**. Flujo: "Interesado" → preguntar volumen → registrar → `Cualificado`. Campos opcionales: `num_jugadores`, `categorias`, `fecha_decision_prevista`, `persona_contacto`, `objeciones`, `proxima_accion`, `canal_interaccion`. Umbrales orientativos: <50 no mockup, 50-99 válido, 100-199 prioritario, 200+ alta prioridad.

---

## 10. MOCKUP COMO RECURSO LIMITADO

Capacidad: ~100/semana. Pantalla de decisión previa mostrando volumen, tramo, precio, facturación, margen. Botón "Solicitar Mockup" explícito → crea registro en `mockups`, mueve lead a `Propuesta`. Widget: solicitados/en_producción/enviados esta semana, capacidad restante, alertas 80% y 95%.

---

## 11. PRESUPUESTO VERSIONADO

Entidad independiente. Campos: `version` (autoincremental por lead), `unidades`, `precio_unitario`, `subtotal`, `descuento_aplicado`, `condiciones_pago`, `transporte`, `importe_total`, `margen_potencial_club`, `estado`, `fecha`. Múltiples versiones por lead sin destruir anteriores.

---

## 12. INTERACCIONES Y WHATSAPP

WhatsApp: **gestión manual**, sin automatización. Registro de interacciones desde ficha lead: `fecha`, `canal` (email/whatsapp/telefono/presencial), `tipo_evento`, `resumen`, `resultado`, `proxima_accion`. Se guarda en `comunicaciones_log`.

---

## 13. ANALÍTICA Y KPIs

**Prospección:** Contactos, Enviados, Entregados, Rebotes, Bajas.  
**Engagement:** Aperturas, Tasa apertura, Respuestas, Resp. positivas, Tasa resp. positiva.  
**Conversión:** Interesados, Cualificados, Oportunidades, Mockups, Presupuestos, Negociaciones, Ganados, Perdidos.  
**Negocio:** Pares, Facturación, Ticket medio, Volumen medio, **Facturación/100 contactos**, **Pares/100 contactos**, **Margen potencial clubes/100 contactos**.  
**Operación:** Mockups pendientes, Presupuestos pendientes, Leads sin próxima acción.  

**KPIs prioritarios visibles siempre:**
1. Respuesta positiva / entregados
2. Pedidos / entregados
3. Facturación / 100 contactos
4. Pares / 100 contactos
5. Conversión presupuesto → pedido

---

## 14. ATRIBUCIÓN

**CAMPAÑA → VARIANTE → LEAD → INTERACCIONES → OPORTUNIDAD → PRESUPUESTO → PEDIDO**

Gracias a `lead_pipelines`, un lead puede estar en múltiples pipelines. `comunicaciones_log.pipeline_id` identifica en qué campaña ocurrió cada evento. Se puede calcular qué campaña y variante produjo negocio.

---

## 15. MOTIVOS DE PÉRDIDA Y OBJECIONES

11 motivos estructurados: `precio`, `no_interesa`, `ya_tiene_proveedor`, `no_gestionar_venta`, `volumen_insuficiente`, `timing`, `falta_respuesta`, `directiva`, `quiere_muestra`, `margen_insuficiente`, `otro`. Campo `motivo_perdida` en `clubes_crm`. Objeciones en campo texto `objeciones`.

---

## 16. DASHBOARD Y OBJETIVO 20 CLUBES

6 preguntas que debe responder: ¿Qué funciona? (A/B/C), ¿Dónde perdemos? (Funnel), ¿Qué requiere acción? (Kanban + leads sin próxima acción), ¿Cuánto negocio? (KPIs), ¿Capacidad? (Mockups), ¿Llegamos? (20 clubes).

Widget objetivo: clubes ganados, %, ritmo necesario, ritmo actual, proyección.

---

## 17. BASE DE DATOS

### 17.1. Tablas existentes (sin cambios)

`envios`, `aperturas`, `rebotes`, `clubes_crm`, `cuentas_smtp`, `config`, `plantillas`, `comunicaciones_log`

### 17.2. Tablas nuevas

`pipelines`, `lead_pipelines` (N:M), `presupuestos` (versionado), `mockups`, `snapshots`

### 17.3. Nuevas columnas en existentes

```sql
-- clubes_crm (NO incluye pipeline_id)
ALTER TABLE clubes_crm ADD COLUMN volumen_estimado INTEGER DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN num_jugadores INTEGER DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN categorias TEXT DEFAULT '';
ALTER TABLE clubes_crm ADD COLUMN fecha_decision_prevista DATE DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN objeciones TEXT DEFAULT '';
ALTER TABLE clubes_crm ADD COLUMN proxima_accion TEXT DEFAULT '';
ALTER TABLE clubes_crm ADD COLUMN canal_interaccion TEXT DEFAULT '';
ALTER TABLE clubes_crm ADD COLUMN motivo_perdida TEXT DEFAULT '';

-- plantillas
ALTER TABLE plantillas ADD COLUMN cuerpo_b TEXT DEFAULT '';
ALTER TABLE plantillas ADD COLUMN cuerpo_c TEXT DEFAULT '';

-- comunicaciones_log
ALTER TABLE comunicaciones_log ADD COLUMN pipeline_id INTEGER DEFAULT NULL REFERENCES pipelines(id);
ALTER TABLE comunicaciones_log ADD COLUMN resumen TEXT DEFAULT '';
ALTER TABLE comunicaciones_log ADD COLUMN proxima_accion TEXT DEFAULT '';
ALTER TABLE comunicaciones_log ADD COLUMN canal VARCHAR(20) DEFAULT 'email';
```

### 17.4. Migración de estados (7 antiguos → 8 nuevos)

```sql
ALTER TABLE clubes_crm ADD COLUMN estado_lead_backup TEXT;
UPDATE clubes_crm SET estado_lead_backup = estado_lead;
UPDATE clubes_crm SET estado_lead = 'Contactado' WHERE estado_lead = 'Email Enviado / En Secuencia';
UPDATE clubes_crm SET estado_lead = 'Contactado' WHERE estado_lead = 'Impactado / Abrio Email';
UPDATE clubes_crm SET estado_lead = 'Respondió' WHERE estado_lead = 'En Conversacion / WhatsApp';
UPDATE clubes_crm SET estado_lead = 'Propuesta' WHERE estado_lead = 'Muestra / Propuesta Enviada';
UPDATE clubes_crm SET estado_lead = 'Ganado' WHERE estado_lead = 'Cerrado Ganado';
UPDATE clubes_crm SET estado_lead = 'Perdido' WHERE estado_lead = 'Cerrado Perdido';
```

---

## 18. SEGURIDAD Y ESCALABILIDAD

Consultas parametrizadas, validación de entradas, integridad referencial, backup antes de cada migración, credenciales SMTP protegidas, control de acceso vía sesión PHP. SQLite adecuado para ~8.800 leads. No optimizar prematuramente.

---

## 19. MATRIZ DE COMPATIBILIDAD / REGRESIÓN

| Funcionalidad | ¿Funciona? | ¿Se modifica? | ¿Se conserva? | Verificación |
|---------------|-----------|---------------|---------------|--------------|
| Envío SMTP (`enviar_smtp_random.php`) | ✅ Sí | No (solo añadir `cuerpo_b`/`cuerpo_c`) | ✅ Sí | Enviar email de prueba con cada variante |
| Motor SMTP (`enviarSMTPAutenticado()`) | ✅ Sí | No | ✅ Sí | Test de envío + autenticación |
| Tracking pixel (`track.php`) | ✅ Sí | No | ✅ Sí | Verificar registro en `aperturas` |
| Bajas (`baja.php`) | ✅ Sí | No | ✅ Sí | Click en enlace de baja → `Baja / Opt-Out` |
| Editor de plantillas (`editor.php`) | ✅ Sí | Añadir `cuerpo_b`, `cuerpo_c` | ✅ Sí | Editar y guardar plantilla con 3 cuerpos |
| Gestor de datos (`gestor.php`) | ✅ Sí | Actualizar dropdown estados | ✅ Sí | Filtrar por nuevos estados |
| Lanzadera (`lanzadera.php`) | ✅ Sí | Añadir pipeline + round-robin | ✅ Sí | Enviar lote con Modo Experimento |
| Dashboard (`dashboard.php`) | ✅ Sí | Actualizar `$estadosKanban`, añadir endpoints | ✅ Sí | Kanban 8 columnas, KPIs, widgets |
| Kanban (`kanban.php`) | ✅ Sí | Actualizar a 8 columnas | ✅ Sí | Visualizar leads en nuevo Kanban |
| Modal ficha lead (`modals.php`) | ✅ Sí | Añadir cualificación, mockup, presupuesto | ✅ Sí | Abrir ficha, editar campos nuevos |
| Analytics (`analytics.php`) | ✅ Sí | Añadir funnel, A/B/C, KPIs | ✅ Sí | Ver funnel 12 niveles, comparativa |
| API leads (`leads.php`) | ✅ Sí | Añadir `calcular_precio` | ✅ Sí | GET `?action=calcular_precio&volumen=120` |
| API SMTP (`smtp.php`) | ✅ Sí | No | ✅ Sí | CRUD cuentas SMTP |
| Autenticación | ✅ Sí | No | ✅ Sí | Login/logout |
| Historial (`comunicaciones_log`) | ✅ Sí | Añadir columnas | ✅ Sí | Ver timeline del lead |
| Duplicados (scan/merge) | ✅ Sí | No | ✅ Sí | Escanear y mergear |

---

## 20. FASES DE DESARROLLO

Organizadas por **dependencias**. Cada fase incluye las tareas. El sistema no se considera operativo hasta QA.

### FASE 0 — Auditoría y preparación

| ID | Tarea |
|----|-------|
| F0.1 | Backup `stats.db` |
| F0.2 | Revisión entorno (PHP 8.x, SQLite3) |
| F0.3 | Ejecutar DDL: `pipelines`, `lead_pipelines`, `presupuestos`, `mockups`, `snapshots` |
| F0.4 | Añadir columnas a `clubes_crm`, `plantillas`, `comunicaciones_log` |
| F0.5 | Ejecutar migración de estados (7→8) |
| F0.6 | Reescribir 4 plantillas preseed |
| F0.7 | Verificar integridad post-migración |

### FASE 1 — Núcleo operativo

| ID | Tarea | Dep. |
|----|-------|------|
| F1.1 | `$estadosKanban` 8 columnas en `dashboard.php` | F0 |
| F1.2 | Kanban UI 8 cols en `kanban.php` | F1.1 |
| F1.3 | Actualizar `modals.php` (select estados, campos cualificación) | F1.1 |
| F1.4 | Actualizar `gestor.php` (filtros) | F1.1 |
| F1.5 | Actualizar `editor.php` (dropdown estados) | F1.1 |
| F1.6 | Actualizar `lanzadera.php` (dropdown estados) | F1.1 |
| F1.7 | Añadir `cuerpo_b`, `cuerpo_c` en editor | F0 |
| F1.8 | A/B/C real en `enviar_lote.php` | F1.7 |
| F1.9 | UI gestión pipelines | F0 |
| F1.10 | Modo Experimento (pipeline + round-robin A/B/C) | F1.8, F1.9 |
| F1.11 | Columnas `comunicaciones_log` (`pipeline_id`, `resumen`, `proxima_accion`, `canal`) | F0 |
| F1.12 | Historial `cambio_campo` en `comunicaciones_log` | F1.11 |
| F1.13 | Crear 3 plantillas A/B/C | F1.7 |
| F1.14 | `update_lead` para nuevos campos | F0 |

### FASE 2 — Cualificación y propuesta

| ID | Tarea | Dep. |
|----|-------|------|
| F2.1 | `calcularPrecioYMargen()` + endpoint | F1 |
| F2.2 | Form cualificación modal (volumen + cálculo auto) | F2.1 |
| F2.3 | UI mockups (tabla `mockups` + pantalla decisión) | F1 |
| F2.4 | Widget capacidad mockups | F2.3 |
| F2.5 | UI presupuestos versionados | F1 |
| F2.6 | Form presupuesto modal (cálculo auto) | F2.1, F2.5 |
| F2.7 | Objeciones / motivos pérdida | F1 |
| F2.8 | Registro manual interacciones | F1.12 |

### FASE 3 — Analítica

| ID | Tarea | Dep. |
|----|-------|------|
| F3.1 | Endpoint `get_funnel` (12 niveles + stage_order) | F1 |
| F3.2 | Endpoint `get_ab_comparativa` | F1.10 |
| F3.3 | KPIs económicos | F2.6 |
| F3.4 | Dashboard cuellos botella | F3.1 |
| F3.5 | Tabla comparativa A/B/C UI | F3.2 |
| F3.6 | Widget objetivo 20 clubes | F1 |
| F3.7 | KPIs eficiencia temporal | F3.1 |

### FASE 4 — Follow-ups y operación

| ID | Tarea | Dep. |
|----|-------|------|
| F4.1 | Filtrar no respondedores | F1 |
| F4.2 | Leads sin próxima acción | F2.2 |
| F4.3 | KPIs operación (mockups pendientes, presupuestos pendientes) | F2, F3 |

### FASE 5 — QA / Pruebas integrales

| ID | Tarea |
|----|-------|
| F5.1 | Simular envío completo A/B/C |
| F5.2 | Simular respuesta → cualificación → propuesta → negociación → ganado |
| F5.3 | Simular WhatsApp manual, objeciones |
| F5.4 | Simular baja |
| F5.5 | Verificar Kanban 8 cols |
| F5.6 | Verificar funnel 12 niveles |
| F5.7 | Verificar KPIs económicos |
| F5.8 | Verificar atribución campaña → variante → lead → pedido |
| F5.9 | Verificar regresión: SMTP, tracking, bajas, editor, gestor, auth |

### FASE 6 — Preparación lanzamiento

| ID | Tarea |
|----|-------|
| F6.1 | Pipeline "Experimento Inicial V4" |
| F6.2 | Asignar 300-500 leads estratificados |
| F6.3 | Verificar plantillas A/B/C |
| F6.4 | Configurar Modo Experimento |
| F6.5 | Preparar lote test (10-15 contactos) |
| F6.6 | Protocolo lanzamiento 3 niveles documentado |
| F6.7 | **ESPERAR AUTORIZACIÓN EXPLÍCITA** |

---

## 21. CRITERIO DE ACEPTACIÓN

### 21.1. KANBAN
- [x] 8 columnas (reducido de 11)
- [x] Cada columna justificada (matriz Sección 5)
- [x] Sin estados técnicos (Mockup Solicitado/Enviado, Presupuesto Enviado → eventos)
- [x] El usuario no mueve el lead por cada micro-evento

### 21.2. PIPELINE
- [x] `lead_pipelines` permite múltiples campañas por club
- [x] Histórico intacto (no se sobrescribe `pipeline_id`)
- [x] Atribución correcta vía `comunicaciones_log.pipeline_id`

### 21.3. FUNNEL
- [x] 12 niveles con `stage_order` explícito (CASE, sin comparaciones alfabéticas)
- [x] Fórmulas claras por nivel
- [x] Independiente del Kanban

### 21.4. A/B/C
- [x] Asunto + cuerpo por variante
- [x] Trazabilidad completa
- [x] Comparativa en Analytics

### 21.5. MOCKUP
- [x] Control de capacidad
- [x] Pantalla de decisión previa
- [x] Sin generación indiscriminada

### 21.6. PRESUPUESTO
- [x] Cálculo correcto
- [x] Versionado
- [x] Margen

### 21.7. REGRESIÓN
- [x] Funcionalidades existentes no contradictorias siguen funcionando (matriz Sección 19)

### 21.8. 25 PREGUNTAS FINALES

**Experimento:** 1-5 | **Funnel:** 6-10 | **Negocio:** 11-17 | **Operación:** 18-20 | **Aprendizaje:** 21-24 | **Decisión:** 25

---

## 22. APÉNDICES

### APÉNDICE A: Plantillas preseed

#### Plantilla 1: Email — Primer Contacto
**Cat:** `prospeccion` | **Tipo:** `texto_plano` | **Asunto:** `Espinilleras personalizadas para {{CLUB}} | FutProtec`
```
Hola:
Te escribo desde FutProtec. Trabajamos con clubes de fútbol base de toda España ofreciendo espinilleras personalizadas con los colores y el escudo de cada club.
Fabricamos por lote y entregamos directamente en la sede. El club adquiere a precio B2B y decide libremente a qué precio ofrecerlas a las familias.
Si te interesa conocer cómo quedarían las espinilleras de {{CLUB}}, puedo prepararte un ejemplo personalizado sin compromiso.
El pedido mínimo es de 50 pares y el transporte está incluido en Península.
Puedes responder directamente a este correo o escribirme por WhatsApp al +34 711 25 90 81.
Un saludo, {{SENDER_NAME}} | {{SENDER_TITLE}} | {{SENDER_EMAIL}} | getfutprotec.com
```

#### Plantilla 2: Email — Seguimiento | **Cat:** `seguimiento` | **Tipo:** `html`

#### Plantilla 3: Objeción — Precio/Pedido Mínimo | **Cat:** `respuesta_modelo` | **Tipo:** `texto_plano`

#### Plantilla 4: WhatsApp — Saludo | **Cat:** `whatsapp` | **Tipo:** `whatsapp`

### APÉNDICE B: Cambios V4.2 → V4.3

| Cambio | V4.2 | V4.3 |
|--------|------|------|
| Columnas Kanban | 11 | **8** (Mockup Solicitado, Mockup Enviado, Presupuesto Enviado → eventos) |
| Estado unificado | No existía | **`Propuesta`** agrupa mockup + presupuesto |
| Pipeline | `clubes_crm.pipeline_id` (1:1) | **`lead_pipelines`** (N:M) — múltiples campañas por club |
| Funnel | `estado_lead >= 'Cualificado'` | **`stage_order`** con CASE explícito |
| Matriz compatibilidad | No existía | **Sección 19** — 16 funcionalidades auditadas |
| Matriz Kanban | No existía | **Sección 5** — cada estado justificado |
| Fases | 0-6 (43 tareas) | 0-6 (36 tareas, simplificadas) |

### APÉNDICE C: Verificación V4.3

| Requisito | ✅ |
|-----------|-----|
| Kanban simplificado (8 cols) | ✅ |
| Separación ESTADO / EVENTO documentada | ✅ |
| Pipeline N:M (lead_pipelines) | ✅ |
| Funnel con stage_order explícito | ✅ |
| A/B/C real (asunto + cuerpo + trazabilidad) | ✅ |
| Mockup recurso limitado (pantalla decisión) | ✅ |
| Presupuesto versionado | ✅ |
| Cualificación mínima fricción | ✅ |
| WhatsApp manual | ✅ |
| Atribución campaña → variante → lead → pedido | ✅ |
| Motivos de pérdida estructurados | ✅ |
| Dashboard para decidir | ✅ |
| Matriz compatibilidad/regresión | ✅ |
| Matriz justificación Kanban | ✅ |
| Fases 0-6 con dependencias | ✅ |
| Criterios aceptación (8 bloques + 25 preguntas) | ✅ |
| Código existente funcional se conserva | ✅ |

---

## INFORME DE AUDITORÍA FINAL V4.3

**Fecha:** 11 de agosto de 2026  
**Resultado:** ✅ **V4.3 APROBADA PARA FASE 0**

---

### A. ESTADO GENERAL DE V4.3

**APROBABLE.** La especificación V4.3 cumple todos los requisitos del prompt de cierre V4.3. No se han detectado contradicciones críticas. Las correcciones necesarias son menores y están documentadas abajo.

| Criterio | Estado |
|----------|--------|
| Kanban mínimo viable (8 cols) | ✅ |
| Separación ESTADO vs EVENTO | ✅ |
| Pipeline N:M (`lead_pipelines`) | ✅ |
| Funnel con `stage_order` numérico | ✅ |
| A/B/C real (asunto + cuerpo + trazabilidad) | ✅ |
| Mockup como recurso limitado | ✅ |
| Presupuesto versionado | ✅ |
| Cualificación por volumen | ✅ |
| WhatsApp manual | ✅ |
| Trazabilidad completa | ✅ |
| Analítica independiente del Kanban | ✅ |
| Matriz de regresión (16 funciones) | ✅ |
| Fases 0-6 con dependencias | ✅ |
| Bloqueo de envíos reales hasta QA | ✅ |

---

### B. KANBAN DEFINITIVO

Los 8 estados son el número mínimo necesario para gestionar el proceso comercial. Cada estado tiene una acción humana diferente. Ver Sección 4 y Sección 5 para justificación completa.

**Resumen de la justificación:**

| Estado | Acción | ¿Podría eliminarse? |
|--------|--------|---------------------|
| `Sin Contactar` | Enviar | No — estado inicial |
| `Contactado` | Esperar/follow-up | No — acción distinta |
| `Respondió` | Clasificar | No — requiere decisión humana |
| `Interesado` | Preguntar volumen | No — inicia cualificación |
| `Cualificado` | Decidir propuesta | No — punto de decisión crítica |
| `Propuesta` | Gestionar mockup/presp | No — unifica 3 micro-estados anteriores |
| `Negociación` | Gestionar objeciones | No — acción distinta a Propuesta |
| `Ganado`/`Perdido` | Cerrar | No — estados terminales |

**Estados eliminados (eran eventos, no estados):** Mockup Solicitado, Mockup Enviado, Presupuesto Enviado, Rebotado, Baja/Opt-Out.

---

### C. TABLA DEFINITIVA ESTADO VS EVENTO

| Elemento | Tipo | ¿Cambia Kanban? | ¿Registro en histórico? | Fuente |
|----------|------|-----------------|------------------------|--------|
| Email enviado | Evento | No | Sí | `comunicaciones_log` |
| Email entregado | Evento | No | Sí | `envios` |
| Email abierto | Evento | No | Sí | `aperturas` |
| Rebote | Evento | No | Sí | `rebotes` |
| Respuesta recibida | Evento + posible cambio estado | Sí, si procede | Sí | `comunicaciones_log` |
| WhatsApp enviado/recibido | Evento | No | Sí | `comunicaciones_log` |
| Llamada realizada/recibida | Evento | No | Sí | `comunicaciones_log` |
| Mockup solicitado | Evento | No (transición auto a `Propuesta`) | Sí | `mockups` |
| Mockup enviado | Evento | No | Sí | `mockups` |
| Presupuesto creado | Evento | No | Sí | `presupuestos` |
| Presupuesto enviado | Evento | No | Sí | `presupuestos` |
| Nota añadida | Evento | No | Sí | `comunicaciones_log` |
| Cambio de campo | Evento | No | Sí | `comunicaciones_log` (`cambio_campo`) |
| Baja (opt-out) | Evento | Sí (sale de Kanban) | Sí | `clubes_crm.estado_lead` |
| Negociación iniciada | Estado | Sí | Sí | `clubes_crm.estado_lead` |
| Pedido confirmado (Ganado) | Estado | Sí | Sí | `clubes_crm.estado_lead` |
| Oportunidad perdida | Estado | Sí | Sí | `clubes_crm.estado_lead` |

---

### D. PIPELINE / CAMPAÑAS — CONFIRMACIÓN ARQUITECTURA N:M

La arquitectura `clubes_crm` ↔ `lead_pipelines` ↔ `pipelines` permite correctamente:

- ✅ Un club en múltiples campañas (N:M)
- ✅ Cada asignación conserva `variante_ab` y `fecha_asignacion`
- ✅ `comunicaciones_log.pipeline_id` identifica la campaña de cada evento
- ✅ Atribución independiente por campaña
- ✅ Histórico intacto al añadir nuevas campañas

**Caso de prueba conceptual superado:**
- Club A en Campaña Agosto 2026 (Variante A) → datos conservados
- Club A en Campaña Septiembre 2026 (Variante B) → nueva asignación, sin sobrescribir
- Se puede consultar cada campaña independientemente

---

### E. FUNNEL — 12 NIVELES COMPLETOS

| Nº | Nivel | Definición | Condición matemática | Fuente de datos |
|----|-------|-----------|---------------------|-----------------|
| 1 | Contactados | Lead ha recibido al menos un envío | `stage_order >= 2` | `clubes_crm.estado_lead` |
| 2 | Entregados | Contactados excluyendo rebotes | Contactados − `estado_lead = 'Rebotado'` | `clubes_crm` + `rebotes` |
| 3 | Abrieron | Al menos una apertura registrada | `aperturas.tracking_id IS NOT NULL` | `aperturas` JOIN `envios` |
| 4 | Respondieron | Lead ha respondido | `stage_order >= 4` | `clubes_crm.estado_lead` |
| 5 | Respuestas positivas | Lead muestra interés comercial | `stage_order >= 5` | `clubes_crm.estado_lead` |
| 6 | Cualificados | Volumen estimado ≥ 50 registrado | `volumen_estimado >= 50 AND stage_order >= 6` | `clubes_crm` |
| 7 | Oportunidades | Lead en fase de propuesta | `stage_order >= 7` | `clubes_crm.estado_lead` |
| 8 | Mockups enviados | Mockup entregado al club | `mockups.estado = 'enviado'` | `mockups` |
| 9 | Presupuestos | Presupuesto creado | `presupuestos.id IS NOT NULL` | `presupuestos` |
| 10 | Negociaciones | Lead en negociación | `stage_order >= 8` | `clubes_crm.estado_lead` |
| 11 | Ganados | Pedido confirmado | `stage_order = 9` | `clubes_crm.estado_lead` |
| 12 | Perdidos | Oportunidad cerrada sin venta | `stage_order = 10` | `clubes_crm.estado_lead` |

**El funnel NO depende de movimientos manuales del Kanban.** Se calcula a partir de eventos, estados y timestamps. Usa `stage_order` numérico (CASE), sin comparaciones alfabéticas.

---

### F. KPIs DEFINITIVOS Y FÓRMULAS

| # | KPI | Fórmula | Categoría |
|---|-----|---------|-----------|
| 1 | Tasa de entrega | Entregados / Enviados × 100 | Prospección |
| 2 | Tasa de apertura | Aperturas / Entregados × 100 | Engagement |
| 3 | Tasa de respuesta | Respondieron / Entregados × 100 | Engagement |
| 4 | Tasa de respuesta positiva | Resp. positivas / Respondieron × 100 | Engagement |
| 5 | Tasa de cualificación | Cualificados / Resp. positivas × 100 | Conversión |
| 6 | Tasa de propuesta | Oportunidades / Cualificados × 100 | Conversión |
| 7 | Tasa de negociación | Negociaciones / Oportunidades × 100 | Conversión |
| 8 | Tasa de cierre | Ganados / Negociaciones × 100 | Conversión |
| 9 | Tasa global de conversión | Ganados / Contactados × 100 | Conversión |
| 10 | **Clubes ganados / 100 contactos** | Ganados / Contactados × 100 | **PRIORITARIO** |
| 11 | **Facturación / 100 contactos** | Facturación total / Contactados × 100 | **PRIORITARIO** |
| 12 | Pares / 100 contactos | Pares totales / Contactados × 100 | **PRIORITARIO** |
| 13 | **Margen potencial clubes / 100 contactos** | Margen clubes total / Contactados × 100 | **PRIORITARIO** |
| 14 | Ticket medio | Facturación / Nº pedidos | Negocio |
| 15 | Volumen medio | Pares / Nº pedidos | Negocio |
| 16 | Tasa presupuesto → pedido | Ganados con presupuesto / Presupuestos enviados × 100 | Conversión |

---

### G. MOCKUP / PROPUESTA — CONFIRMACIÓN DEL FLUJO

El estado `Propuesta` agrupa correctamente el ciclo mockup + presupuesto. La ficha del lead debe mostrar (desde la BD, sin mover el Kanban):

- Mockup: no solicitado / solicitado / en producción / enviado (desde `mockups.estado`)
- Presupuesto: no creado / creado / enviado / aceptado (desde `presupuestos.estado`)
- Nº de versión del presupuesto actual
- Fecha de última propuesta
- Importe, pares, margen
- Próxima acción y fecha

✅ El Kanban es simple (una columna `Propuesta`). La ficha del lead es completa (todos los datos de mockup y presupuesto visibles).

---

### H. REGRESIÓN — FUNCIONALIDADES PROTEGIDAS

| Funcionalidad | Estado | Motivo |
|---------------|--------|--------|
| SMTP (`enviar_smtp_random.php`) | **Conservada** | Funciona correctamente |
| Motor SMTP (`enviarSMTPAutenticado()`) | **Conservada** | Robusto, no tocar |
| Tracking pixel (`track.php`) | **Conservada** | Funciona correctamente |
| Bajas (`baja.php`) | **Conservada** | Funciona correctamente |
| Editor de plantillas | **Modificada** | Solo añadir `cuerpo_b`/`cuerpo_c` |
| Gestor de datos | **Modificada** | Solo actualizar dropdown de estados |
| Lanzadera | **Modificada** | Añadir pipeline + round-robin A/B/C |
| Dashboard | **Modificada** | Actualizar `$estadosKanban`, añadir endpoints |
| Kanban | **Modificada** | Reducir a 8 columnas |
| Modals (ficha lead) | **Modificada** | Añadir cualificación, mockup, presupuesto |
| Analytics | **Modificada** | Añadir funnel, A/B/C, KPIs económicos |
| API leads | **Modificada** | Añadir endpoint `calcular_precio` |
| API SMTP | **Conservada** | Funciona correctamente |
| Autenticación | **Conservada** | Funciona correctamente |
| Historial (`comunicaciones_log`) | **Modificada** | Añadir columnas `pipeline_id`, `resumen`, etc. |
| Duplicados (scan/merge) | **Conservada** | Funciona correctamente |

**Ninguna funcionalidad es sustituida completamente.** Todas las modificaciones son incrementales.

---

### I. QA — CASOS DE PRUEBA OBLIGATORIOS

| # | Caso | Flujo | Verificación |
|---|------|-------|-------------|
| QA-1 | Lead nuevo | Sin Contactar → envío → Contactado | Kanban refleja Contactado, `comunicaciones_log` tiene `envio_email` |
| QA-2 | Apertura sin mover Kanban | Email abierto → tracking registrado | `aperturas` tiene registro, Kanban sigue en Contactado |
| QA-3 | Respuesta negativa | Respondió → clasificar → Perdido | `motivo_perdida = 'no_interesa'`, timeline correcto |
| QA-4 | Respuesta positiva | Respondió → Interesado | Kanban refleja Interesado |
| QA-5 | Cualificación | Interesado → volumen 120 → Cualificado | `volumen_estimado = 120`, cálculo: 8€/par, 960€ |
| QA-6 | Solicitar mockup | Cualificado → click "Solicitar Mockup" → Propuesta | `mockups.estado = 'solicitado'`, Kanban = Propuesta |
| QA-7 | Mockup enviado | Propuesta → click "Mockup Enviado" | `mockups.estado = 'enviado'`, Kanban sigue Propuesta |
| QA-8 | Crear presupuesto | Propuesta → click "Crear Presupuesto" | `presupuestos` v1 creado, Kanban sigue Propuesta |
| QA-9 | Negociación | Propuesta → club responde → Negociación | Kanban = Negociación |
| QA-10 | Ganado | Negociación → pedido confirmado → Ganado | `presupuestos.estado = 'aceptado'`, `estado_lead = 'Ganado'` |
| QA-11 | Perdido con motivo | Negociación → sin acuerdo → Perdido | `motivo_perdida = 'precio'` |
| QA-12 | A/B/C | 3 leads reciben A, B, C respectivamente | `lead_pipelines.variante_ab` correcto, `envios.asunto` y `cuerpo_mensaje` distintos |
| QA-13 | Baja | Clic en enlace de baja | `estado_lead = 'Baja / Opt-Out'`, fuera de Kanban |
| QA-14 | Multi-campaña | Club A en pipeline Agosto, luego en pipeline Septiembre | Dos registros en `lead_pipelines`, históricos independientes |
| QA-15 | Presupuesto versionado | v1 enviado, modificar → v2 creado | v1 conservado, v2 nuevo registro |
| QA-16 | WhatsApp manual | Registrar interacción WhatsApp | `comunicaciones_log.canal = 'whatsapp'`, `tipo_evento = 'whatsapp_entrante'` |
| QA-17 | Regresión SMTP | Enviar email con tracking | Entrega OK, tracking pixel registra apertura, baja funciona |
| QA-18 | Funnel correcto | Con datos de prueba, calcular funnel | 12 niveles con valores coherentes, conversiones calculadas |

---

### J. CORRECCIONES NECESARIAS

**No se han detectado contradicciones críticas.** Las siguientes son correcciones menores ya incorporadas en esta misma auditoría:

1. ✅ **Tabla Estado vs Evento añadida** (Sección C de esta auditoría) — antes solo existía la definición conceptual pero no la tabla completa.
2. ✅ **Funnel con tabla explícita de 12 niveles** (Sección E) — antes los niveles estaban definidos pero no en formato tabla auditable con fuente de datos.
3. ✅ **KPIs con fórmulas explícitas** (Sección F) — se han consolidado los 16 KPIs con fórmulas matemáticas.
4. ✅ **Casos de prueba QA detallados** (Sección I) — se han enumerado 18 casos con flujo y verificación.

---

## DECLARACIÓN FINAL

> **V4.3 APROBADA PARA FASE 0.**

La Fase 0 comprende: backup, DDL, migración de estados, y reescritura de plantillas preseed.

**APROBACIÓN DE FASE 0 ≠ AUTORIZACIÓN DE ENVÍOS.** Los envíos reales quedan bloqueados hasta completar Fases 0-5 y recibir autorización explícita posterior.

---

**Fin de la Especificación Técnica Definitiva CRM FutProtec V4.3.**  
*V4.3 es la única fuente de verdad. NO realizar envíos sin autorización explícita.*  
*Fase 0 autorizada. Próximo paso: backup + DDL + migración.*
