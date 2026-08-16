# Informe de Adaptación del CRM Outbound al Plan Estratégico FutProtec

**Fecha:** 11 de agosto de 2026
**Versión:** 3.0 — Actualizada con Informe de Ajuste Estratégico y Funcional
**Autor:** Análisis técnico — ScrapperClub + FutProtec CRM
**Documentos de referencia:**
- Informe Estratégico de Captación B2B FutProtec v1.0 (agosto 2026)
- Informe de Ajuste Estratégico y Funcional del CRM v3.0 (agosto 2026)
- Auditoría de código fuente `public_html/outbound/` (11/08/2026)

---

## 0. HISTORIAL DE VERSIONES

| Versión | Fecha | Cambios |
|---------|-------|---------|
| 1.0 | 10/08/2026 | Redactada sin acceso al código. Asumía estructura inexistente (tabla pipelines, estados kanban distintos). |
| 2.0 | 11/08/2026 | Corregida tras auditoría de código. 12 contradicciones detectadas. GAP de inferencia C.D./S.A.D. anulado. |
| **3.0** | **11/08/2026** | **Actualizada con Informe de Ajuste Estratégico v3.0. Funnel a 12 niveles, KPIs económicos, cualificación con umbrales, canal vs estado, proyección objetivo, criterios de escalado.** |

---

## 1. RESUMEN EJECUTIVO

### 1.1. Documentos de referencia

Este informe integra y operacionaliza dos documentos estratégicos:

| Documento | Alcance |
|-----------|---------|
| **Informe Estratégico de Captación B2B v1.0** | Define QUÉ hacer: producto, precios, público, experimento A/B/C, embudo, tono, prohibiciones. |
| **Informe de Ajuste Estratégico y Funcional v3.0** | Define CÓMO medirlo: funnel de 12 niveles, KPIs económicos y temporales, cualificación con umbrales, separación canal/estado, criterios de escalado, entregables. |

### 1.2. Cobertura real del CRM actual

El **CRM Outbound** (`public_html/outbound/`) cubre aproximadamente el **45%** de lo que exigen ambos documentos combinados. Dispone de:

- ✅ Envío SMTP randomizado con anti-blacklist y throttling
- ✅ Lanzadera con control de motor, cola visual y log en tiempo real
- ✅ Editor de plantillas con tags variables y A/B/C de asuntos
- ✅ Tracking de aperturas (pixel) + opt-out
- ✅ Kanban de 7 columnas con ficha modal y notas
- ✅ Analytics con pipeline, timeline, A/B por asunto, aperturas por federación

Pero **NO dispone de**:

- ❌ A/B/C con cuerpos diferentes por variante (solo asuntos)
- ❌ Funnel completo alineado con los 12 niveles del plan
- ❌ Tabla `pipelines` como entidad (no existe)
- ❌ Campos de cualificación (volumen, jugadores, categorías)
- ❌ Sistema de mockups como etapa independiente de presupuesto
- ❌ Cálculo automático de precio B2B + margen desde volumen
- ❌ KPIs económicos (facturación por 100 contactos, ticket medio)
- ❌ Widget de objetivo 20 clubes con proyección
- ❌ Seguimiento / follow-ups (ni manual ni automático)
- ❌ Trazabilidad de interacciones no-email (WhatsApp, llamadas)
- ❌ Plantillas preseed alineadas (contienen certificación CE, pedido mínimo 20≠50, etc.)

### 1.3. Gaps priorizados

| # | Gap | Impacto | Prioridad |
|---|-----|---------|-----------|
| 1 | A/B/C con cuerpos diferentes + asignación en lanzadera | Sin esto no hay experimento real | 🔴 PRIORIDAD 1 |
| 2 | Rediseño de estados kanban al funnel de 12 niveles | La base del CRM está desalineada | 🔴 PRIORIDAD 1 |
| 3 | Corrección de plantillas preseed | Contradicen prohibiciones del plan | 🔴 PRIORIDAD 1 |
| 4 | Tabla `pipelines` | Necesaria para campañas y experimentos | 🔴 PRIORIDAD 1 |
| 5 | Campos de cualificación + cálculo automático de precio | Crítico para mockup y presupuesto | 🟡 PRIORIDAD 2 |
| 6 | Separación Mockup / Presupuesto en kanban | Exigido explícitamente por el plan v3.0 | 🟡 PRIORIDAD 2 |
| 7 | Widget objetivo 20 clubes + proyección | Visibilidad del progreso comercial | 🟡 PRIORIDAD 2 |
| 8 | Funnel analítico con KPIs económicos y temporales | Necesario para decidir tras primer lote | 🟡 PRIORIDAD 2 |
| 9 | Trazabilidad de interacciones (WhatsApp, llamadas, mockups) | Exigido por plan v3.0 Sección 18 | 🟡 PRIORIDAD 2 |
| 10 | Follow-ups asistidos (filtro + cola) | El plan permite que sean manuales inicialmente | 🟢 PRIORIDAD 3 |
| 11 | Importación masiva CSV desde UI | Para escalar a ~7.000 contactos | 🟢 PRIORIDAD 3 |

---

## 2. ESTADO REAL DEL CRM — AUDITORÍA DE CÓDIGO

### 2.1. Lo que YA está implementado ✅

#### 2.1.1. Infraestructura de envío SMTP
- **`api/enviar_smtp_random.php`**: Rotación aleatoria de cuentas SMTP con anti-blacklist.
- **Modo Aleatorio (🎲)**: Toggle en dashboard para rotar entre múltiples cuentas.
- **Modo Pruebas/Producción**: Toggle que redirige a email de prueba.
- **`api/enviar_lote.php`**: Envío individual desde lanzadera con SMTP autenticado nativo (PHP `stream_socket_client`). SiteGround compatible. Ya acepta parámetro `variante_ab` (A/B/C) y selecciona el asunto correspondiente si la plantilla tiene `test_ab=1`.
- **Lanzadera (`tabs/lanzadera.php`)**: UI completa con control de motor (play/pause/stop), throttle configurable (1-60s), cola visual con infinite scroll, log en tiempo real, selector de federación + estado + plantilla.
- **Log en archivo**: `logs/envios_YYYY-MM-DD.log` con trazabilidad completa por envío.
- **Tabla `envios`**: `id`, `club`, `email`, `federacion`, `cuenta_emision`, `fecha_envio`, `estado`, `tracking_id`, `asunto`, `cuerpo_mensaje`.
- **Tabla `aperturas`**: `id`, `tracking_id`, `fecha_apertura`, `ip`, `user_agent`.
- **Tabla `rebotes`**: `id`, `email`, `motivo`, `fecha_rebote`.

#### 2.1.2. Editor de plantillas con A/B/C de asuntos
- **Tabla `plantillas`**: `id`, `nombre`, `asunto`, `asunto_b`, `asunto_c`, `cuerpo`, `tipo`, `categoria`, `activo`, `fecha_creacion`, `test_ab`.
- Tags: `{{CLUB}}`, `{{CONTACTO}}`, `{{FEDERACION}}`, `{{ANIO}}`, `{{EMAIL}}`, `{{SENDER_NAME}}`, `{{SENDER_TITLE}}`, `{{SENDER_EMAIL}}`.
- **Limitación**: Solo hay UN campo `cuerpo`. Las variantes B y C comparten el mismo cuerpo y solo cambia el asunto. El plan v3.0 (Sección 14) exige cuerpos diferentes por variante.

#### 2.1.3. Kanban CRM
- **7 columnas**: `Sin Contactar`, `Email Enviado / En Secuencia`, `Impactado / Abrio Email`, `En Conversacion / WhatsApp`, `Muestra / Propuesta Enviada`, `Cerrado Ganado`, `Cerrado Perdido`.
- **Ficha modal**: Timeline de comunicaciones, añadir notas con timestamp, cambiar estado, editar contacto/federación/teléfonos, toggle WhatsApp, enlace directo a WhatsApp con plantilla.
- **Tabla `clubes_crm`**: `id`, `nombre_club`, `federacion`, `persona_contacto`, `cargo_contacto`, `email`, `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`, `estado_lead`, `observaciones`, `ultimo_contacto`, `creado_el`, `es_duplicado`, `duplicado_id`.

#### 2.1.4. Analytics
- Pipeline funnel por estado kanban (barras horizontales)
- Timeline 30 días de envíos + aperturas
- A/B Testing por asunto con checkboxes por federación
- Aperturas por federación (19 canónicas)
- Interacciones antes del cierre (histograma)
- Tabla `comunicaciones_log`: `id`, `lead_id`, `club_id`, `tipo_evento`, `plantilla_id`, `detalles`, `ip_registro`, `fecha`, `id_cuenta_smtp`, `tipo`, `resultado`, `codigo_error`, `variante_ab`.

#### 2.1.5. Configuración y SMTP
- **Tabla `config`**: `clave`, `valor` (motor_estado, modo_entorno, email_test, delay_envio, lote_envio)
- **Tabla `cuentas_smtp`**: `id`, `email`, `host`, `puerto`, `usuario`, `password`, `seguridad`, `activa`, `limite_diario`, `enviados_hoy`, `ultimo_error`, `ultimo_uso`, `nombre_emisor`, `cargo_emisor`.

#### 2.1.6. Lo que NO existe
- **NO existe tabla `pipelines`** — el concepto de pipeline/campaña está implícito.
- **NO existe tabla `followups`** — los seguimientos son 100% manuales.
- **NO existe tabla `snapshots`** — no se puede comparar entre campañas.
- **NO existen campos de cualificación** en `clubes_crm` (volumen, jugadores, categorías).

---

### 2.2. GAPS DETALLADOS — Lo que falta

#### GAP 1: A/B/C con cuerpos diferentes (PRIORIDAD 1) 🔴

**Qué exige el plan v3.0 (Sección 14):**
> "Para que exista un verdadero test A/B/C, cada variante debe poder definir: asunto, cuerpo, CTA. [...] Variante A: Asunto A + cuerpo A. Variante B: Asunto B + cuerpo B. Variante C: Asunto C + cuerpo C."

**Qué tiene el CRM:** La tabla `plantillas` tiene `asunto`, `asunto_b`, `asunto_c` pero solo UN `cuerpo`. `enviar_lote.php` sabe seleccionar el asunto según `variante_ab` pero siempre usa el mismo cuerpo.

**Solución recomendada:** Usar 3 PLANTILLAS SEPARADAS agrupadas por un `pipeline_id` + `grupo_experimento`. La lanzadera en "Modo Experimento" rota entre las 3 plantillas asignando variante A/B/C round-robin. Esto evita modificar el schema de `plantillas` y es más flexible.

**Alternativa (más compleja):** Añadir `cuerpo_b` y `cuerpo_c` a `plantillas` + modificar editor + modificar `enviar_lote.php`.

---

#### GAP 2: Rediseño de estados kanban al funnel de 12 niveles (PRIORIDAD 1) 🔴

**Qué exige el plan v3.0 (Secciones 10, 13, 22):**
> "No debe existir una única etapa 'Muestra / Propuesta enviada'. Debe diferenciarse claramente: Mockup (el club está valorando visualmente el producto) y Presupuesto (el club conoce las condiciones económicas concretas)."

> "WhatsApp debe considerarse canal de comunicación y no necesariamente una fase comercial."

**Estados actuales vs requeridos:**

| Estado Actual | Problema | Estado Requerido |
|---------------|----------|------------------|
| `Sin Contactar` | OK | `Sin Contactar` (= Prospecto) |
| `Email Enviado / En Secuencia` | Nombre largo, no coincide con plan | `Contactado` (= Email enviado) |
| `Impactado / Abrio Email` | ❌ La apertura es métrica, no etapa | **ELIMINAR** — la apertura se registra en `aperturas` pero no mueve el lead |
| `En Conversacion / WhatsApp` | ❌ Mezcla estado comercial + canal | Separar en `Respondió` + `Interesado`. El canal se registra aparte. |
| `Muestra / Propuesta Enviada` | ❌ Fusiona Mockup + Presupuesto | `Mockup Solicitado` → `Mockup Enviado` → `Presupuesto Enviado` |
| `Cerrado Ganado` | OK | `Cerrado Ganado` (= Pedido) |
| `Cerrado Perdido` | OK | `Cerrado Perdido` |
| *(no existe)* | Falta | `Rebotado` |
| *(no existe)* | Falta | `Sin Respuesta` |
| *(no existe)* | Falta | `Cualificado` |
| *(no existe)* | Falta | `Negociación` |
| *(no existe)* | Falta | `No Interesado` |
| *(no existe)* | Falta | `Baja / Opt-Out` |

**Nuevo kanban propuesto (13 columnas):**

| # | Estado Kanban | Funnel Stage | Descripción |
|---|---------------|-------------|-------------|
| 1 | `Sin Contactar` | — | Prospecto en base |
| 2 | `Contactado` | 1 | Email enviado, esperando |
| 3 | `Rebotado` | — | Email no entregado |
| 4 | `Sin Respuesta` | 2 | No respondió tras N días |
| 5 | `Respondió` | 3 | Cualquier respuesta |
| 6 | `Interesado` | 4 | Señal comercial positiva |
| 7 | `Cualificado` | 5 | Con volumen estimado |
| 8 | `Mockup Solicitado` | 6 | Diseño solicitado |
| 9 | `Mockup Enviado` | 7 | Diseño entregado |
| 10 | `Presupuesto Enviado` | 8 | Precio según volumen |
| 11 | `Negociación` | 9 | Evaluando / objeciones |
| 12 | `Cerrado Ganado` | 10 | Pedido confirmado |
| 13 | `Cerrado Perdido` | — | Oportunidad cerrada sin pedido |
| 14 | `No Interesado` | — | Rechazo explícito |
| 15 | `Baja / Opt-Out` | — | Solicitud de baja |

**Nota sobre WhatsApp:** El canal de interacción (email, WhatsApp, teléfono) se registra en `comunicaciones_log.tipo` y NO debe confundirse con el estado comercial. Un lead puede estar en estado `Interesado` independientemente del canal por el que se consiguió esa interacción.

---

#### GAP 3: Corrección de plantillas preseed (PRIORIDAD 1) 🔴

**Qué exige el plan v3.0 (Sección 35):**
> "Eliminar o revisar cualquier referencia a: {{CONTACTO}} cuando no exista nombre; personalización individual; nombres de jugadores; certificación CE como argumento comercial; pedido mínimo de 20; catálogo PDF como CTA inicial; afirmaciones no demostradas; 'sin riesgo'; 'sin inversión'."

**Contradicciones detectadas en las 4 plantillas preseed (`init_db.php` líneas 337-467):**

| Plantilla | Contradicción | Acción |
|-----------|---------------|--------|
| Email 1 — Primer Contacto | Usa `{{CONTACTO}}` (no hay nombre real) | Cambiar a "Hola:" |
| Email 1 — Primer Contacto | "Me presento: soy {{CONTACTO}}" | Cambiar a "Te escribo desde FutProtec" |
| Email 2 — Catálogo HTML | "certificación CE" | **ELIMINAR** |
| Email 2 — Catálogo HTML | "pedidos superiores a 20 unidades" | Cambiar a 50 pares |
| Email 2 — Catálogo HTML | "Adjunto te envío el catálogo en PDF" | Eliminar mención a catálogo (el CTA es mockup, no catálogo) |
| Email 3 — Objeción | "No pedimos pago por adelantado" | Corregir a "50% adelantado + 50% contra entrega" según Sección 5 |
| WA — Saludo | Usa `{{CONTACTO}}` | Cambiar a saludo genérico |
| WA — Saludo | "catálogo sin compromiso" | Cambiar a "ejemplo personalizado" |

**Acción:** Reescribir las 4 plantillas preseed para que cumplan con el plan v3.0. Las nuevas plantillas del experimento A/B/C usarán como base el email de control del plan original (Sección 39) con los ajustes de la Sección 36 del plan v3.0 sobre CTA.

---

#### GAP 4: Tabla `pipelines` (PRIORIDAD 1) 🔴

No existe. Es necesaria para agrupar leads en campañas/experimentos y medir resultados por pipeline. El plan v3.0 exige trazabilidad por campaña (Sección 17: "Cada email enviado debe registrar como mínimo: lead, campaña, variante...").

---

#### GAP 5: Campos de cualificación + cálculo automático de precio (PRIORIDAD 2) 🟡

**Qué exige el plan v3.0 (Secciones 7, 30-32):**
- "Cuando un club responde positivamente, no necesitamos obtener todos sus datos comerciales. Necesitamos solamente una primera señal de volumen."
- "El CRM debe asociar automáticamente el volumen estimado con el tramo de precio."
- "Si el volumen estimado es 120: Precio B2B 8€, PVP 15€, Margen potencial 7€/par, Margen potencial del club 840€."

**Campos nuevos requeridos en `clubes_crm`:**

| Campo | Tipo | Prioridad | Uso |
|-------|------|-----------|-----|
| `volumen_estimado` | INT | Crítico | Determina tramo de precio, umbral de mockup, potencial económico |
| `num_jugadores` | INT | Alto | Validación cruzada con volumen |
| `categorias` | TEXT | Alto | Contexto comercial |
| `fecha_decision_prevista` | DATE | Medio | Priorización de seguimiento |
| `motivo_interes` | TEXT | Medio | Aprendizaje de mercado |
| `objeciones` | TEXT | Medio | Aprendizaje de mercado |
| `canal_interaccion` | TEXT | Medio | Email / WhatsApp / Teléfono |

**Lógica de cálculo automático (a implementar en PHP):**
```php
function calcularPrecioYMargen(int $volumen): array {
    if ($volumen >= 200) return ['precio_b2b' => 7, 'margen_par' => 8];
    if ($volumen >= 100) return ['precio_b2b' => 8, 'margen_par' => 7];
    if ($volumen >= 50)  return ['precio_b2b' => 9, 'margen_par' => 6];
    return ['precio_b2b' => null, 'margen_par' => null]; // <50: requiere revisión
}
```

---

#### GAP 6: Separación Mockup / Presupuesto (PRIORIDAD 2) 🟡

**Qué exige el plan v3.0 (Sección 13):**
> "No debe existir una única etapa: 'Muestra / Propuesta enviada'. Debe diferenciarse claramente: Mockup (el club está valorando visualmente el producto) y Presupuesto (el club conoce las condiciones económicas concretas). Esto permite medir: Interesado → Mockup y Mockup → Presupuesto y Presupuesto → Pedido."

El estado actual `Muestra / Propuesta Enviada` debe desdoblarse en 3 estados kanban:
- `Mockup Solicitado`
- `Mockup Enviado`
- `Presupuesto Enviado`

Nuevos campos en `clubes_crm`:
- `fecha_mockup_solicitado` (DATETIME)
- `fecha_mockup_enviado` (DATETIME)
- `fecha_presupuesto_enviado` (DATETIME)

---

#### GAP 7: Widget objetivo 20 clubes + proyección (PRIORIDAD 2) 🟡

**Qué exige el plan v3.0 (Secciones 28-29):**
> "El objetivo de 20 clubes antes del 1 de septiembre debe aparecer en el dashboard."
> "El CRM debería poder mostrar: contactos procesados, pedidos conseguidos, ritmo diario, ritmo semanal, proyección de pedidos."

Widget en dashboard:
```
┌─────────────────────────────────┐
│ 🎯 OBJETIVO: 20 CLUBES         │
│ ████████░░░░░░░░░░ 8/20 (40%)  │
│ Ritmo: 2.3 pedidos/semana      │
│ Proyección: 18 pedidos al 1/9  │
│ ⚠️ Por debajo del objetivo     │
└─────────────────────────────────┘
```

---

#### GAP 8: Funnel analítico con KPIs económicos y temporales (PRIORIDAD 2) 🟡

**Qué exige el plan v3.0 (Secciones 23-27):**

El funnel debe medir:
```
Contactos enviados → Emails entregados → Respuestas → Respuestas positivas → 
Leads cualificados → Mockups → Presupuestos → Negociaciones → 
Pedidos → Pares vendidos → Facturación
```

**KPIs obligatorios (Sección 24):**

| Categoría | KPIs |
|-----------|------|
| **Adquisición** | contactos enviados, emails entregados, rebotes, bajas, respuestas |
| **Engagement** | aperturas, respuestas, respuesta positiva |
| **Conversión** | interesados, cualificados, mockups solicitados, mockups enviados, presupuestos, negociaciones, pedidos |
| **Económicos** | pares potenciales, pares vendidos, facturación potencial, facturación real, margen potencial del club, ticket medio por pedido |
| **Eficiencia** | tiempo medio respuesta→mockup, tiempo mockup→presupuesto, tiempo presupuesto→pedido, tiempo total hasta cierre |

**KPI fundamental (Sección 25):** Tabla comparativa A vs B vs C con TODOS los KPIs anteriores.

**KPIs económicos clave (Secciones 26-27):**
- Facturación generada por cada 100 contactos
- Pares vendidos por cada 100 contactos
- No optimizar solo por número de clientes (3 pedidos × 50 pares < 2 pedidos × 200 pares)

---

#### GAP 9: Trazabilidad de interacciones no-email (PRIORIDAD 2) 🟡

**Qué exige el plan v3.0 (Sección 18):**
> "El mismo principio debe aplicarse a: email, WhatsApp, llamada, mockup, presupuesto, negociación, pedido. Cada interacción relevante debe quedar asociada al lead."

La tabla `comunicaciones_log` ya existe pero sus `tipo_evento` actuales son limitados. Hay que ampliar los tipos de eventos registrables:

**Nuevos `tipo_evento` a soportar:**
- `llamada_entrante`, `llamada_saliente`
- `whatsapp_entrante`, `whatsapp_saliente`
- `mockup_solicitado`, `mockup_enviado`
- `presupuesto_enviado`
- `negociacion`
- `pedido_confirmado`
- `followup_1`, `followup_2`

**Campos adicionales recomendados en `comunicaciones_log`:**
- `resumen` (TEXT) — resumen de la interacción
- `proxima_accion` (TEXT) — siguiente paso acordado
- `canal` (VARCHAR(20)) — email / whatsapp / telefono / presencial

---

#### GAP 10: Follow-ups asistidos (PRIORIDAD 3) 🟢

El plan v3.0 (Sección 20) dice: "Los follow-ups inicialmente pueden seguir siendo manuales. El CRM debe facilitar la identificación de: leads contactados + sin respuesta + sin baja + sin rebote."

**Solución mínima:** Botón "Filtrar no respondedores" en lanzadera que cargue leads con:
- `estado_lead = 'Contactado'`
- Último envío >= 3 días
- Sin respuesta registrada
- Sin baja, sin rebote

---

#### GAP 11: Importación masiva CSV desde UI (PRIORIDAD 3) 🟢

Para cuando lleguen los ~7.000 contactos adicionales (plan v3.0 Sección 43). El endpoint `api/leads.php` actual no tiene endpoint de importación CSV.

---

## 3. PLAN DE TRABAJO EN 5 FASES (v3.0)

### Cronograma

```
11-14 ago          14-15 ago           16-22 ago            22-31 ago           1 sep+
FASE 0             FASE 1              FASE 2               FASE 3              FASE 4
[Preparación]      [Primer Envío]      [Análisis+Iterar]    [Escalar]           [Evaluación]
  3-4 días           1-2 días             7 días               9 días              1 día
```

---

### FASE 0 — Preparación (11–14 agosto) 🔴 PRIORIDAD 1

**Objetivo:** Dejar el CRM listo para ejecutar el experimento A/B/C con trazabilidad completa.

| ID | Tarea | Archivo(s) | Esfuerzo | Prioridad |
|----|-------|-----------|----------|-----------|
| **F0.1** | **Crear tabla `pipelines`** + añadir `pipeline_id` a `clubes_crm` y `comunicaciones_log` | `cli/init_db.php` | 1h | 🔴 P1 |
| **F0.2** | **Rediseñar estados kanban** al funnel de 12 niveles (ver tabla Sección 2.2 GAP 2). Array `$estadosKanban` + `$colClasses` + select en modals + filtros en lanzadera. | `dashboard.php`, `modals.php`, `lanzadera.php` | 2h | 🔴 P1 |
| **F0.3** | **Script SQL de migración** de estados antiguos a nuevos preservando historial (ver Apéndice A.3) | Script SQL independiente | 30 min | 🔴 P1 |
| **F0.4** | **Crear 3 plantillas del experimento A/B/C** con cuerpos diferentes. Opción pragmática: 3 registros en `plantillas` agrupados por pipeline. Basadas en email de control del plan original (Sección 39) + ajustes plan v3.0 (Sección 36). | BD directamente o editor.php | 1h | 🔴 P1 |
| **F0.5** | **Modificar lanzadera** para "Modo Experimento": selector de pipeline, asignación round-robin de variantes A/B/C, envío con `variante_ab`. `enviar_lote.php` YA acepta `variante_ab` — solo falta UI y rotación de plantilla según variante. | `tabs/lanzadera.php` | 3h | 🔴 P1 |
| **F0.6** | **Corregir plantillas preseed** — eliminar certificación CE, corregir pedido mínimo a 50, cambiar `{{CONTACTO}}` por "Hola:", eliminar mención catálogo PDF, corregir formas de pago. Las 4 plantillas preseed deben pasar la auditoría de la Sección 35 del plan v3.0. | `cli/init_db.php` (líneas 337-467) | 1.5h | 🔴 P1 |
| **F0.7** | **Añadir campos de cualificación** a `clubes_crm`: `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `motivo_interes`, `objeciones`, `canal_interaccion` | `cli/init_db.php` | 30 min | 🔴 P1 |
| **F0.8** | **Añadir función PHP de cálculo de precio + margen** desde volumen estimado + endpoint `api/leads.php?action=calcular_precio` que devuelva tramo, precio_b2b, margen_par, margen_total. | `dashboard.php` (helper PHP) + `api/leads.php` | 1h | 🔴 P1 |
| **F0.9** | **Añadir campos de fechas de mockup/presupuesto** a `clubes_crm`: `fecha_mockup_solicitado`, `fecha_mockup_enviado`, `fecha_presupuesto_enviado` | `cli/init_db.php` | 15 min | 🟡 P2 |
| **F0.10** | **Endpoint de funnel analítico**: `get_funnel_data` con los 12 niveles, filtrable por `pipeline_id`, `variante_ab`, rango de fechas. Con tasas de conversión entre niveles. | `dashboard.php` (nuevo case en `get_analytics`) | 3h | 🟡 P2 |
| **F0.11** | **Widget objetivo 20 clubes** en dashboard: contador `X/20` con barra de progreso, ritmo diario/semanal, proyección simple. | `dashboard.php` | 1.5h | 🟡 P2 |

**Estimación Fase 0:** ~15 horas de desarrollo.

---

### FASE 1 — Primer Envío del Experimento (14–15 agosto)

**Objetivo:** Ejecutar el primer envío a 300–500 clubes con las 3 variantes.

**Pre-requisito:** Fase 0 completada.

| ID | Tarea | Tiempo |
|----|-------|--------|
| F1.1 | Verificar que los ~1.800 leads están en `clubes_crm` | 15 min |
| F1.2 | Crear pipeline "Experimento Inicial" en la tabla `pipelines` | 5 min |
| F1.3 | Seleccionar 300-500 leads aleatorios estratificados por federación y asignarlos al pipeline | 30 min |
| F1.4 | Configurar el Modo Experimento en lanzadera: pipeline + 3 plantillas | 15 min |
| F1.5 | Activar Modo Pruebas y enviar test a email de control | 10 min |
| F1.6 | Verificar: tracking pixel, variables renderizadas, enlace de baja, firma, asignación correcta de variante | 20 min |
| F1.7 | Activar Modo Producción y lanzar envío (modo lento, delay 5s, 1 worker, 🎲 aleatorio ON) | 5 min |
| F1.8 | Monitorizar entregabilidad en tiempo real | Durante envío |

**Tiempo de envío estimado:** ~25-42 minutos para 300-500 leads a 5s por envío.

---

### FASE 2 — Análisis e Iteración (16–22 agosto) 🟡 PRIORIDAD 2

**Objetivo:** Analizar resultados, detectar variante ganadora, cualificar interesados, gestionar mockups.

| ID | Tarea | Archivo(s) | Esfuerzo |
|----|-------|-----------|----------|
| **F2.1** | **Dashboard de funnel interactivo** en analytics: gráfico de embudo con 12 niveles, tasas de conversión (%), filtrable por pipeline, variante, federación, rango de fechas | `tabs/analytics.php`, `dashboard.php` | 5h |
| **F2.2** | **Tabla comparativa A/B/C** con todos los KPIs: enviados, entregados, respuestas, positivas, cualificados, mockups, presupuestos, pedidos, pares, facturación. Highlight de la variante ganadora en cada nivel. | `tabs/analytics.php`, `dashboard.php` | 3h |
| **F2.3** | **KPIs económicos en analytics**: facturación por 100 contactos, pares por 100 contactos, ticket medio, facturación total potencial vs real | `tabs/analytics.php`, `dashboard.php` | 3h |
| **F2.4** | **KPIs de eficiencia temporal**: tiempo medio respuesta→mockup, mockup→presupuesto, presupuesto→pedido, total hasta cierre | `tabs/analytics.php`, `dashboard.php` | 2h |
| **F2.5** | **Formulario de cualificación en modal**: `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `canal_interaccion`. Con cálculo automático de precio B2B + margen al introducir volumen. Guardado AJAX. | `tabs/modals.php`, `dashboard.php` | 4h |
| **F2.6** | **Botón "Filtrar no respondedores"** en lanzadera: SQL que devuelve leads `Contactado` con fecha >= 3 días, sin respuesta, sin baja, sin rebote. Permitir cargar como nueva cola y enviar plantilla de follow-up. | `tabs/lanzadera.php`, `dashboard.php` | 2h |
| **F2.7** | **Dropdown `motivo_no_interes`** en modal para leads en estado `No Interesado` (opciones: Precio, No quiere gestionar venta, Ya tiene proveedor, No ve demanda, No interesa producto, No interesa personalización, No es el momento, Decisión directiva, Otro) | `tabs/modals.php` | 1h |
| **F2.8** | **Registro ampliado de interacciones**: añadir soporte para `tipo_evento` = `mockup_solicitado`, `mockup_enviado`, `presupuesto_enviado`, `followup_1`, `followup_2`, `llamada`, `whatsapp`. Añadir campo `resumen` a `comunicaciones_log`. | `cli/init_db.php`, `api/enviar_lote.php` | 2h |
| **F2.9** | **Widget de capacidad de mockups**: mockups pendientes / enviados / capacidad semanal (100). Alerta si se supera el 80%. | `dashboard.php` | 1.5h |

**Tareas operativas (F2.10–F2.16):**
- Día 3-4 post-envío: Enviar Follow-up 1 a no respondedores
- Analizar métricas por variante usando la tabla comparativa A/B/C
- Determinar variante ganadora
- Enviar Follow-up 2
- Cualificar leads interesados (preguntar volumen aproximado)
- Aplicar regla de umbral: <50 pares = investigar, 50+ = mockup
- Solicitar y enviar mockups a cualificados

**Estimación Fase 2 (desarrollo):** ~23.5 horas.

---

### FASE 3 — Escalado (22–31 agosto) 🟢 PRIORIDAD 3

**Objetivo:** Escalar con variante ganadora, solo si se cumplen criterios del plan v3.0 (Sección 43).

**Criterios de escalado (checklist antes de ejecutar):**
- [ ] Existe una variante claramente competitiva (datos, no intuición)
- [ ] El funnel es medible en todos los niveles
- [ ] El proceso de mockup es sostenible (≤100/semana)
- [ ] El equipo puede atender las respuestas
- [ ] Se conoce la conversión aproximada

| ID | Tarea | Esfuerzo |
|----|-------|----------|
| **F3.1** | **Endpoint de importación CSV desde UI**: subir archivo, previsualizar, mapear columnas, insertar con validación MX + WhatsApp. | 5h |
| **F3.2** | **Tabla `snapshots`** para guardar estado de métricas al cierre de campaña. | 1h |
| **F3.3** | **Endpoint `export_informe`**: genera CSV/HTML con todas las métricas del experimento para compartir. | 2h |
| **F3.4** | **Pantalla "Informe Final de Campaña"** imprimible con todas las métricas, funnel, comparativa A/B/C, conclusiones y recomendaciones. | 3h |

**Tareas operativas (F3.5–F3.10):**
- Lanzar envío escalado con variante ganadora (~1.300 leads restantes)
- Programar follow-ups para la campaña escalada
- Cualificar y seguimiento intensivo a interesados
- Cerrar primeros pedidos
- Importar ~7.000 clubes adicionales (cuando disponibles)
- Verificar criterios de escalado antes de cada nuevo lote

**Estimación Fase 3 (desarrollo):** ~11 horas.

---

### FASE 4 — Evaluación y Cierre (1 septiembre +)

**Objetivo:** Responder a las 8 decisiones estratégicas del plan v3.0 (Sección 42) con datos.

| ID | Decisión a tomar | Datos necesarios |
|----|-----------------|------------------|
| D1 | ¿Qué variante utilizar? | Comparativa A/B/C completa |
| D2 | ¿Hay que modificar el precio? | Tasa de conversión + objeciones de precio |
| D3 | ¿El PVP de 15€ funciona? | Ticket medio, margen real |
| D4 | ¿El pedido mínimo de 50 frena oportunidades? | Leads con <50 pares vs leads que no compraron |
| D5 | ¿Mockup antes o después de cualificar? | Tasa Mockup→Presupuesto, Mockup→Pedido |
| D6 | ¿Argumento principal: económico o identidad? | Tasa de respuesta positiva por variante |
| D7 | ¿Qué volumen de club tiene mayor probabilidad? | Tasa de conversión por tramo de volumen |
| D8 | ¿Merece la pena escalar a 7.000? | Facturación/100 contactos, pares/100 contactos |

| ID | Tarea | Esfuerzo |
|----|-------|----------|
| F4.1 | Pantalla de "Informe Final" con respuesta documentada a las 8 decisiones | 3h |
| F4.2 | Guardar snapshot de métricas para comparar con futuras campañas | 30 min |

---

## 4. ESTIMACIÓN DE ESFUERZO TOTAL (v3.0)

| Fase | Fecha | Desarrollo | Tipo |
|------|-------|-----------|------|
| Fase 0 | 11–14 ago | ~15h | 🔴 PRIORIDAD 1 — Imprescindible |
| Fase 1 | 14–15 ago | ~1.5h | 🟢 Operativa |
| Fase 2 | 16–22 ago | ~23.5h | 🟡 PRIORIDAD 2 — Analítica |
| Fase 3 | 22–31 ago | ~11h | 🟢 PRIORIDAD 3 — Escalado |
| Fase 4 | 1 sep+ | ~3.5h | 🟢 Cierre |
| **TOTAL** | | **~54.5h** | |

**Nota:** El incremento respecto a v2.0 (~40.5h → ~54.5h) refleja los nuevos requisitos del plan v3.0:
- KPIs económicos y de eficiencia temporal (+5h)
- Tabla comparativa A/B/C completa (+3h)
- Trazabilidad de interacciones no-email (+2h)
- Widget objetivo 20 clubes con proyección (+1.5h)
- Cálculo automático de precio + margen (+1h)
- Pantalla de informe final con 8 decisiones (+1.5h)

---

## 5. PRIORIZACIÓN SEGÚN PLAN V3.0

### PRIORIDAD 1 — Imprescindible para primer envío (Fase 0)

| ID | Feature |
|----|---------|
| F0.1 | Tabla `pipelines` |
| F0.2 | Rediseño estados kanban (12 niveles) |
| F0.3 | Script migración estados |
| F0.4 | 3 plantillas A/B/C con cuerpos diferentes |
| F0.5 | Modo Experimento en lanzadera |
| F0.6 | Corrección plantillas preseed |
| F0.7 | Campos de cualificación |
| F0.8 | Cálculo automático precio + margen |

### PRIORIDAD 2 — Necesario para analizar el primer lote (Fase 2)

| ID | Feature |
|----|---------|
| F0.10 | Endpoint funnel 12 niveles |
| F0.11 | Widget objetivo 20 clubes |
| F2.1 | Dashboard funnel interactivo |
| F2.2 | Tabla comparativa A/B/C |
| F2.3 | KPIs económicos |
| F2.4 | KPIs eficiencia temporal |
| F2.5 | Formulario cualificación en modal |
| F2.6 | Botón "Filtrar no respondedores" |
| F2.7 | Dropdown motivo_no_interes |
| F2.8 | Registro ampliado interacciones |
| F2.9 | Widget capacidad mockups |

### PRIORIDAD 3 — Necesario para escalar (Fase 3)

| ID | Feature |
|----|---------|
| F3.1 | Importación CSV desde UI |
| F3.2 | Tabla snapshots |
| F3.3 | Exportación informe |
| F3.4 | Pantalla informe final |

### WON'T HAVE (por ahora)

- CRON jobs automáticos (SiteGround + plan v3.0 Sección 20: "No es necesario automatizar completamente")
- Scoring ML/IA (plan v3.0 Sección 45: "PRIORIDAD 3 — futuro")
- Integración WhatsApp Business API
- Panel multi-usuario
- Enriquecimiento por C.D./S.A.D. (plan v3.0 Sección 34: PROHIBIDO)

---

## 6. RIESGOS Y MITIGACIONES

| Riesgo | Prob. | Impacto | Mitigación |
|--------|-------|---------|------------|
| No llegar con Fase 0 antes del 14 ago | Media | Alto | Priorizar F0.2+F0.4+F0.5 (estados+plantillas+experimento). El resto puede iterarse durante el envío. |
| Rotura de funcionalidades al migrar estados kanban | Alta | Alto | Backup de BD antes de F0.2. Script de migración con rollback. Los estados se referencian en: kanban.php, modals.php, lanzadera.php, analytics.php, dashboard.php. |
| SiteGround limita envíos masivos | Baja | Alto | Modo lento (delay 5s), 1 worker, 🎲 aleatorio ON. Probado. |
| Capacidad de mockups insuficiente (100/semana) | Media | Medio | Widget F2.9 alerta al 80%. Aplicar umbral de cualificación (<50 pares = no mockup automático). |
| Baja tasa de respuesta | Media | Bajo | Es aprendizaje, no fracaso. El CRM debe medirlo, no prevenirlo. |
| Las plantillas del experimento no diferencian suficiente | Media | Alto | Pre-redactar y revisar los 3 cuerpos antes de tocar código. Validar con el equipo comercial. |
| ~7.000 contactos no llegan a tiempo | Media | Medio | Los ~1.800 actuales son suficientes para el experimento. Escalar solo cuando haya datos. |

---

## 7. CONCLUSIÓN

El **CRM Outbound de FutProtec** es una base sólida de envío SMTP y tracking que cubre ~45% de las necesidades combinadas del plan estratégico v1.0 y del plan de ajuste v3.0. Tras la auditoría de código y la integración de ambos documentos, se concluye que:

1. **La base de envío y tracking funciona** — el foco del desarrollo debe estar en trazabilidad, medición y cualificación.

2. **El cambio más crítico es el rediseño de estados kanban** de 7 a 15 columnas alineadas con el funnel de 12 niveles. Esto afecta a 5 archivos y requiere migración de datos existentes.

3. **El A/B/C actual (solo asuntos) no es suficiente** — el plan v3.0 exige cuerpos diferentes. La solución de 3 plantillas separadas con rotación round-robin es la más pragmática.

4. **La cualificación mínima (volumen estimado) es el dato más valioso** — determina umbral de mockup, tramo de precio, potencial económico y prioridad del lead.

5. **La trazabilidad debe cubrir todos los canales** — email, WhatsApp, teléfono, mockups, presupuestos. Cada interacción debe registrarse en `comunicaciones_log`.

6. **No escalar sin datos** — el plan v3.0 establece 5 criterios que deben cumplirse antes de procesar los ~7.000 contactos adicionales.

**Recomendación:** Ejecutar Fase 0 inmediatamente (11-14 agosto). El orden crítico es: F0.2 (estados) → F0.4 (plantillas) → F0.5 (modo experimento) → F0.1 (pipelines) → F0.7+F0.8 (cualificación + cálculo precio). Con esto el primer envío puede lanzarse el 14-15 agosto.

---

## APÉNDICE A: DDL Completo (v3.0)

### A.1. Nueva tabla `pipelines`

```sql
CREATE TABLE IF NOT EXISTS pipelines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    descripcion TEXT DEFAULT '',
    fecha_inicio DATETIME,
    fecha_fin DATETIME,
    variante_ganadora VARCHAR(1) DEFAULT NULL,
    activo INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### A.2. Nuevas columnas en `clubes_crm`

```sql
ALTER TABLE clubes_crm ADD COLUMN pipeline_id INTEGER DEFAULT NULL REFERENCES pipelines(id);
ALTER TABLE clubes_crm ADD COLUMN volumen_estimado INTEGER DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN num_jugadores INTEGER DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN categorias TEXT DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN fecha_decision_prevista DATE DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN motivo_interes TEXT DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN objeciones TEXT DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN canal_interaccion TEXT DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN fecha_mockup_solicitado DATETIME DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN fecha_mockup_enviado DATETIME DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN fecha_presupuesto_enviado DATETIME DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN motivo_no_interes TEXT DEFAULT NULL;
ALTER TABLE clubes_crm ADD COLUMN motivo_compra TEXT DEFAULT NULL;
```

### A.3. Migración de estados kanban (con respaldo)

```sql
-- PASO 1: Crear columna de respaldo
ALTER TABLE clubes_crm ADD COLUMN estado_lead_backup TEXT;
UPDATE clubes_crm SET estado_lead_backup = estado_lead;

-- PASO 2: Migrar estados antiguos → nuevos
-- 'Sin Contactar' → 'Sin Contactar' (sin cambio)
-- 'Email Enviado / En Secuencia' → 'Contactado'
UPDATE clubes_crm SET estado_lead = 'Contactado' WHERE estado_lead = 'Email Enviado / En Secuencia';

-- 'Impactado / Abrio Email' → 'Contactado' (la apertura es métrica, no etapa)
UPDATE clubes_crm SET estado_lead = 'Contactado' WHERE estado_lead = 'Impactado / Abrio Email';

-- 'En Conversacion / WhatsApp' → 'Respondió' (se recalificará manualmente si hay interés)
UPDATE clubes_crm SET estado_lead = 'Respondió' WHERE estado_lead = 'En Conversacion / WhatsApp';

-- 'Muestra / Propuesta Enviada' → 'Presupuesto Enviado' (se desdoblará manualmente)
UPDATE clubes_crm SET estado_lead = 'Presupuesto Enviado' WHERE estado_lead = 'Muestra / Propuesta Enviada';

-- 'Cerrado Ganado' → 'Cerrado Ganado' (sin cambio)
-- 'Cerrado Perdido' → 'Cerrado Perdido' (sin cambio)

-- PASO 3 (opcional, tras verificar): eliminar columna de respaldo
-- ALTER TABLE clubes_crm DROP COLUMN estado_lead_backup;
```

### A.4. Nuevas columnas en `comunicaciones_log`

```sql
ALTER TABLE comunicaciones_log ADD COLUMN pipeline_id INTEGER DEFAULT NULL REFERENCES pipelines(id);
ALTER TABLE comunicaciones_log ADD COLUMN resumen TEXT DEFAULT NULL;
ALTER TABLE comunicaciones_log ADD COLUMN proxima_accion TEXT DEFAULT NULL;
ALTER TABLE comunicaciones_log ADD COLUMN canal VARCHAR(20) DEFAULT 'email';
```

### A.5. Nueva tabla `snapshots`

```sql
CREATE TABLE IF NOT EXISTS snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    pipeline_id INTEGER DEFAULT NULL,
    datos_json TEXT NOT NULL
);
```

---

## APÉNDICE B: Cálculo de Precio y Margen (PHP)

```php
/**
 * Calcula precio B2B, margen por par y margen total a partir del volumen estimado.
 * Usa PVP recomendado fijo de 15€.
 * 
 * @return array{precio_b2b: ?int, margen_par: ?int, margen_total: ?float, tramo: string}
 */
function calcularPrecioYMargen(?int $volumen, int $pvpRecomendado = 15): array {
    if ($volumen === null || $volumen <= 0) {
        return ['precio_b2b' => null, 'margen_par' => null, 'margen_total' => null, 'tramo' => 'Desconocido'];
    }
    
    if ($volumen >= 200) {
        $precio = 7;
        $tramo = '200+ pares';
    } elseif ($volumen >= 100) {
        $precio = 8;
        $tramo = '100-199 pares';
    } elseif ($volumen >= 50) {
        $precio = 9;
        $tramo = '50-99 pares';
    } else {
        return ['precio_b2b' => null, 'margen_par' => null, 'margen_total' => null, 'tramo' => '<50 pares'];
    }
    
    $margenPar = $pvpRecomendado - $precio;
    
    return [
        'precio_b2b'    => $precio,
        'margen_par'    => $margenPar,
        'margen_total'  => $volumen * $margenPar,
        'tramo'         => $tramo,
        'facturacion'   => $volumen * $precio,
    ];
}
```

---

## APÉNDICE C: Query del Funnel de 12 Niveles

```sql
WITH funnel AS (
    SELECT 
        l.pipeline_id,
        cl.variante_ab,
        -- Nivel 1: Contactados (email enviado)
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Contactado','Sin Respuesta','Respondió','Interesado','Cualificado','Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n1_contactados,
        -- Nivel 2: Sin Respuesta
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Sin Respuesta','Respondió','Interesado','Cualificado','Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n2_sin_respuesta,
        -- Nivel 3: Respondió (cualquier respuesta)
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Respondió','Interesado','Cualificado','Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n3_respondio,
        -- Nivel 4: Interesado (respuesta positiva)
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Interesado','Cualificado','Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n4_interesado,
        -- Nivel 5: Cualificado (con volumen)
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Cualificado','Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n5_cualificado,
        -- Nivel 6: Mockup Solicitado
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Mockup Solicitado','Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n6_mockup_solicitado,
        -- Nivel 7: Mockup Enviado
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Mockup Enviado','Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n7_mockup_enviado,
        -- Nivel 8: Presupuesto Enviado
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Presupuesto Enviado','Negociación','Cerrado Ganado') THEN l.id END) as n8_presupuesto,
        -- Nivel 9: Negociación
        COUNT(DISTINCT CASE WHEN l.estado_lead IN ('Negociación','Cerrado Ganado') THEN l.id END) as n9_negociacion,
        -- Nivel 10: Pedido (Cerrado Ganado)
        COUNT(DISTINCT CASE WHEN l.estado_lead = 'Cerrado Ganado' THEN l.id END) as n10_pedido,
        -- Económicos
        COALESCE(SUM(CASE WHEN l.estado_lead = 'Cerrado Ganado' THEN l.volumen_estimado ELSE 0 END), 0) as pares_vendidos,
        COALESCE(SUM(CASE WHEN l.estado_lead = 'Cerrado Ganado' AND l.volumen_estimado >= 200 THEN l.volumen_estimado * 7
                          WHEN l.estado_lead = 'Cerrado Ganado' AND l.volumen_estimado >= 100 THEN l.volumen_estimado * 8
                          WHEN l.estado_lead = 'Cerrado Ganado' AND l.volumen_estimado >= 50 THEN l.volumen_estimado * 9
                          ELSE 0 END), 0) as facturacion_real
    FROM clubes_crm l
    LEFT JOIN comunicaciones_log cl ON (cl.lead_id = l.id OR cl.club_id = l.id) AND cl.tipo_evento = 'envio_email'
    WHERE l.pipeline_id = :pipeline_id
    GROUP BY l.pipeline_id, cl.variante_ab
)
SELECT * FROM funnel;
```

---

## APÉNDICE D: Estructura de las 3 Plantillas del Experimento

### Plantilla A — Hipótesis ECONÓMICA (PRIORIDAD 1)

**Asunto:** `{{CLUB}} / una forma de generar ingresos con la cantera`

**Cuerpo (énfasis: margen económico):**
```
Hola:

Te escribo desde FutProtec porque hemos desarrollado una propuesta específica para clubes de fútbol base como {{CLUB}}.

Las familias de vuestros jugadores ya compran espinilleras cada temporada. La diferencia es que ahora esa compra puede convertirse también en un ingreso adicional para el club.

Fabricamos espinilleras personalizadas con el escudo y los colores de {{CLUB}}. El club las adquiere por lote a precio B2B y decide el precio al que ofrecerlas a las familias.

Con un PVP recomendado de 15 € por par, el margen para el club puede llegar hasta 8 € por unidad, según el volumen del pedido. Por ejemplo, 120 pares generarían 840 € adicionales para el club.

El pedido mínimo es de 50 pares, nos encargamos del diseño, fabricación y entrega en vuestra sede, y el transporte está incluido en Península.

Si te interesa, puedo preparar un ejemplo de cómo quedarían las espinilleras de {{CLUB}} y calcular la propuesta según vuestro número de jugadores.

Puedes responder directamente a este correo o escribirme por WhatsApp al +34 711 25 90 81.

Un saludo,
{{SENDER_NAME}}
{{SENDER_TITLE}}
{{SENDER_EMAIL}}
getfutprotec.com
```

### Plantilla B — Hipótesis IDENTIDAD

**Asunto:** `{{CLUB}} / espinilleras exclusivas con vuestra identidad`

**Cuerpo (énfasis: identidad y pertenencia):**
```
Hola:

Te escribo desde FutProtec porque creemos que {{CLUB}} merece algo más que espinilleras genéricas para sus jugadores.

Imagina a todos los equipos de vuestra cantera compitiendo con espinilleras diseñadas exclusivamente para {{CLUB}}: con vuestro escudo, vuestros colores, vuestra identidad. Un producto que refuerza el sentimiento de pertenencia y diferencia al club.

Eso es lo que hacemos en FutProtec: diseñamos y fabricamos espinilleras personalizadas y exclusivas para cada club, con un diseño propio por temporada. Nos encargamos de todo el proceso: diseño, producción y entrega directa en vuestra sede.

Además, el club puede generar ingresos adicionales adquiriendo por lote a precio B2B y ofreciéndolas a las familias al precio que considere oportuno.

El pedido mínimo es de 50 pares y el transporte está incluido en Península.

Si te interesa, puedo preparar un ejemplo de cómo quedarían las espinilleras de {{CLUB}} y calcular la propuesta según vuestro número de jugadores.

Puedes responder directamente a este correo o escribirme por WhatsApp al +34 711 25 90 81.

Un saludo,
{{SENDER_NAME}}
{{SENDER_TITLE}}
{{SENDER_EMAIL}}
getfutprotec.com
```

### Plantilla C — Hipótesis COMBINADA (CONTROL)

**Asunto:** `{{CLUB}} / espinilleras personalizadas para la cantera`

**Cuerpo (énfasis: solución completa — identidad + ingresos + facilidad):**
```
Hola:

Te escribo desde FutProtec con una propuesta para {{CLUB}} que combina tres cosas: identidad, ingresos y facilidad.

1. Espinilleras personalizadas y exclusivas con el escudo y los colores de {{CLUB}}. Un diseño propio que refuerza la identidad de todos vuestros equipos.

2. Una oportunidad de generar ingresos adicionales: el club las adquiere por lote a precio B2B y decide libremente a qué precio ofrecerlas a las familias.

3. Cero complicaciones: FutProtec se encarga del diseño, la fabricación y la entrega directamente en vuestra sede. El club solo recopila las tallas y gestiona el cobro.

El pedido mínimo es de 50 pares y el transporte está incluido en Península.

Si te interesa, puedo preparar un ejemplo de cómo quedarían las espinilleras de {{CLUB}} y calcular la propuesta según vuestro número de jugadores.

Puedes responder directamente a este correo o escribirme por WhatsApp al +34 711 25 90 81.

Un saludo,
{{SENDER_NAME}}
{{SENDER_TITLE}}
{{SENDER_EMAIL}}
getfutprotec.com
```

---

## APÉNDICE E: Las 15 Preguntas que el CRM Debe Poder Responder

Al finalizar la primera campaña, el CRM debe poder responder con datos a:

1. ¿Qué enviamos?
2. ¿A quién?
3. ¿Cuándo?
4. ¿Qué variante recibió?
5. ¿Quién abrió?
6. ¿Quién respondió?
7. ¿Quién mostró interés?
8. ¿Quién dio volumen?
9. ¿Quién recibió mockup?
10. ¿Quién recibió presupuesto?
11. ¿Quién compró?
12. ¿Cuántos pares compró?
13. ¿Cuánto facturamos?
14. ¿Qué variante convirtió mejor?
15. ¿Dónde se atasca el funnel?

Si el sistema responde estas 15 preguntas de forma fiable, cumple su objetivo.

---

**Fin del informe (v3.0).**