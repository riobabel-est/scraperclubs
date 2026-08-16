# FASE 2 — PLAN DE IMPLEMENTACIÓN VALIDADO

**Versión:** 1.0  
**Fecha:** 11 de agosto de 2026  
**Fuente:** Auditoría completa V4.3 → código real → BD real  
**Estado:** PENDIENTE DE APROBACIÓN (no ejecutar envíos)

---

## 1. AUDITORÍA DEL ESTADO ACTUAL

### 1.1. Esquema de BD

| Tabla V4.3 | ¿Existe? | ¿Coincide con V4.3? | Observaciones |
|---|---|---|---|
| `clubes_crm` | ✅ Sí | ✅ Sí | Columnas nuevas presentes: `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida`, `estado_lead_backup` |
| `pipelines` | ✅ Sí | ✅ Sí | 1 pipeline de prueba: "Experimento Fase 1 TEST" |
| `lead_pipelines` | ✅ Sí | ✅ Sí | 5 asignaciones (solo leads TEST) |
| `mockups` | ✅ Sí | ✅ Sí | Tabla vacía (0 registros) |
| `presupuestos` | ✅ Sí | ✅ Sí | Tabla vacía (0 registros) |
| `snapshots` | ✅ Sí | ✅ Sí | Tabla vacía (0 registros) |
| `plantillas` | ✅ Sí | ✅ Sí | Columnas `cuerpo_b`, `cuerpo_c`, `asunto_b`, `asunto_c` presentes |
| `comunicaciones_log` | ✅ Sí | ✅ Sí | Columnas `pipeline_id`, `resumen`, `proxima_accion`, `canal` presentes |
| `envios` | ✅ Sí | ✅ Sí | Sin cambios |
| `aperturas` | ✅ Sí | ✅ Sí | Sin cambios |
| `rebotes` | ✅ Sí | ✅ Sí | Sin cambios |
| `cuentas_smtp` | ✅ Sí | ✅ Sí | 10 cuentas configuradas |
| `config` | ✅ Sí | ✅ Sí | `motor_estado=pausado`, `modo_entorno=test`, `delay_envio=3`, `lote_envio=10` |

**Conclusión esquema: 100% conforme a V4.3.**

### 1.2. Datos reales

| Métrica | Valor |
|---|---|
| Total leads en `clubes_crm` | 1,813 |
| Leads comerciales (excluyendo test/baja/rebotado) | **1,808** |
| Leads TEST | 5 (IDs 1809-1813) |
| Leads con prefijo TEST en nombre | 2 adicionales (IDs 155, 156 — clubes reales con "TEST" en nombre por coincidencia) |
| Envíos realizados | 2 (ambos de prueba) |
| Aperturas | 0 |
| Rebotes | 0 |
| Mockups | 0 |
| Presupuestos | 0 |
| Snapshots | 0 |
| Eventos en `comunicaciones_log` | 15 (13 cambios de estado, 2 envíos) |

### 1.3. Distribución de estados (Kanban real)

| Estado Kanban | Count |
|---|---|
| `01 Sin Contactar` | 1,812 |
| `02 Contactado` | 0 |
| `03 Respondió` | 1 |
| `04 Interesado` | 0 |
| `05 Cualificado` | 0 |
| `06 Propuesta` | 0 |
| `07 Negociación` | 0 |
| `08 Ganado` | 0 |
| `09 Perdido` | 0 |

**El 99.94% de los leads están en "Sin Contactar". El pipeline comercial está vacío.**

### 1.4. Plantillas A/B/C

| ID | Nombre | test_ab | Asunto A | Asunto B | Asunto C | Cuerpo A | Cuerpo B | Cuerpo C |
|---|---|---|---|---|---|---|---|---|
| 1 | Plantilla Principal | ✅ 1 | 81B | 59B | 73B | 629B | 621B | 567B |
| 6 | Prospección con precio | ✅ 1 | 33B | 25B | 0B | 1443B | 0B | 0B |
| 7 | Primera plantilla | ✅ 1 | 8B | 8B | 8B | 108B | 0B | 0B |

**La plantilla #1 tiene A/B/C completo. Las #6 y #7 están incompletas (sin cuerpo B/C).**

---

## 2. QUÉ YA EXISTE (FUNCIONALIDADES VALIDADAS)

| Funcionalidad | Estado | Detalle |
|---|---|---|
| Kanban 9 columnas | ✅ Operativo | `$estadosKanban` con prefijos numéricos, drag & drop, colores por columna |
| Pipeline N:M (`lead_pipelines`) | ✅ Operativo | Tabla creada, 5 asignaciones de prueba, JOINs funcionando |
| A/B/C — esquema de plantillas | ✅ Operativo | Columnas `cuerpo_b`, `cuerpo_c`, `asunto_b`, `asunto_c` en BD |
| A/B/C — asignación | ✅ Operativo | `lead_pipelines.variante_ab` poblado en leads TEST |
| SAFE MODE | ✅ Operativo | `modo_entorno=test`, `motor_estado=pausado`, email_test configurado |
| SMTP (10 cuentas) | ✅ Operativo | Round-robin, límite diario, `enviarSMTPAutenticado()` robusto |
| Tracking pixel (`track.php`) | ✅ Operativo | Registro en `aperturas` vía tracking_id |
| Bajas (`baja.php`) | ✅ Operativo | Cambia estado a `Baja / Opt-Out` |
| Editor de plantillas | ✅ Operativo | CRUD de plantillas con campos A/B/C |
| Gestor de datos | ✅ Operativo | Tabla paginada, búsqueda, filtros, merge de duplicados |
| Lanzadera | ✅ Operativo | Envío secuencial con delay, round-robin SMTP, modo test |
| Autenticación | ✅ Operativo | Login con AUTH_KEY |
| Historial (`comunicaciones_log`) | ✅ Operativo | Columnas nuevas existentes, registro de cambios de estado |
| Duplicados (scan/merge) | ✅ Operativo | Fuzzy matching, merge con conservación de histórico |

---

## 3. QUÉ FALTA (GAPS DETECTADOS)

### 3.1. 🔴 CRÍTICO: Inconsistencia de prefijos de estado

**Problema detectado:** El código usa DOS formatos distintos para `estado_lead`:

| Ubicación | Formato | Ejemplo |
|---|---|---|
| `dashboard.php` — `$estadosKanban` | **Con prefijo** | `'01 Sin Contactar'`, `'02 Contactado'` |
| `dashboard.php` — queries Kanban | **Con prefijo** | `WHERE c.estado_lead = :estado` → `'01 Sin Contactar'` |
| `api/leads.php` — INSERT | **Sin prefijo** | `'Sin Contactar'` (línea 545) |
| `api/leads.php` — WHERE lanzadera | **Sin prefijo** | `c.estado_lead = 'Sin Contactar'` (línea 655) |
| `api/leads.php` — exclusiones | **Sin prefijo** | `'Lista Negra'`, `'Perdido'`, `'Baja / Opt-Out'` |
| `tabs/modals.php` — select Kanban | **Con prefijo** | Renderiza desde `$estadosKanban` |
| `tabs/modals.php` — badges bajas | **Sin prefijo** | `'Opt-Out'`, `'Unsubscribed'` |
| `cli/cron.php` — UPDATE | **Sin prefijo** | `'Email Enviado / En Secuencia'` |
| `api/track.php` — UPDATE | **Sin prefijo** | `'Impactado / Abrio Email'` |
| BD real (`clubes_crm`) | **Con prefijo** | `'01 Sin Contactar'`, `'03 Respondió'` |

**Impacto:** La lanzadera (`api/leads.php` línea 655) busca `estado_lead = 'Sin Contactar'` pero en BD los leads tienen `'01 Sin Contactar'`. **La lanzadera NO encontraría ningún lead para enviar.**

**El Kanban muestra 0 leads en todas las columnas** en la auditoría de consola (sección 19) porque el script buscó strings sin prefijo mientras en BD están con prefijo. Esto NO significa que el Kanban en el navegador falle — el dashboard usa `$estadosKanban` con prefijos.

**Acción necesaria:**
- Opción A (recomendada): Unificar TODO a formato CON prefijo (como ya está en BD y en `$estadosKanban`). Modificar `api/leads.php`, `cli/cron.php`, `api/track.php`, `api/enviar_lote.php`
- Opción B: Unificar TODO a formato SIN prefijo y migrar la BD

**Recomendación: Opción A** — es menos invasiva (la BD ya tiene prefijos, el Kanban ya los usa). Solo hay que corregir ~6 archivos API/CLI.

### 3.2. 🟡 IMPORTANTE: Ficha de cualificación sin UI

**Problema:** Los campos de cualificación existen en la BD pero NO se muestran en el modal de ficha de lead (`tabs/modals.php`):

| Campo BD | ¿En UI? |
|---|---|
| `volumen_estimado` | ❌ No |
| `num_jugadores` | ❌ No |
| `categorias` | ❌ No |
| `fecha_decision_prevista` | ❌ No |
| `objeciones` | ❌ No |
| `proxima_accion` | ❌ No |
| `canal_interaccion` | ❌ No |
| `motivo_perdida` | ❌ No |

**Impacto:** El usuario no puede cualificar leads desde la UI. Sin cualificación, no se puede avanzar a `Propuesta`.

### 3.3. 🟡 IMPORTANTE: Mockups sin UI

**Problema:** Tabla `mockups` existe pero:
- No hay botón "Solicitar Mockup" en la ficha del lead
- No hay widget de capacidad (100/semana, alertas 80%/95%)
- No hay pantalla de decisión previa con volumen/tramo/precio
- No hay formulario de registro de mockup enviado

### 3.4. 🟡 IMPORTANTE: Presupuestos sin UI

**Problema:** Tabla `presupuestos` existe pero:
- No hay botón "Crear Presupuesto"
- No hay `calcularPrecioYMargen()` implementado en ningún endpoint ni en frontend
- No hay formulario de presupuesto con cálculo automático
- No hay versionado visible

### 3.5. 🟡 IMPORTANTE: Snapshots sin lógica de generación

**Problema:** Tabla `snapshots` existe (0 registros). No hay cron/trigger que genere snapshots periódicos del funnel.

### 3.6. 🟡 IMPORTANTE: Analytics no usa `stage_order` numérico

**Problema:** El endpoint `get_analytics` en `dashboard.php` agrupa por `estado_lead` alfabéticamente. V4.3 exige `stage_order` numérico con CASE explícito para el funnel de 12 niveles.

### 3.7. 🟡 IMPORTANTE: Sin widget "Objetivo 20 clubes"

**Problema:** No existe en el dashboard el widget de objetivo que muestre: clubes ganados, %, ritmo necesario, ritmo actual, proyección.

### 3.8. 🟢 MENOR: Plantillas A/B/C incompletas

**Problema:** Las plantillas #6 y #7 tienen `test_ab=1` pero solo tienen cuerpo A (sin B ni C). La #1 está completa.

### 3.9. 🟢 MENOR: Registro manual de interacciones sin UI

**Problema:** `comunicaciones_log` tiene columnas `canal`, `resumen`, `proxima_accion` pero no hay formulario en la ficha del lead para registrar interacciones manuales (WhatsApp, llamada, etc.).

---

## 4. TABLA DE GAPS COMPLETA

| # | Requisito V4.3 | Estado actual | Falta | Acción necesaria | Prioridad |
|---|---|---|---|---|---|
| 1 | Estados unificados (prefijos) | **Inconsistente** — Kanban usa prefijos, API no | Unificar formato | Corregir `api/leads.php`, `api/enviar_lote.php`, `cli/cron.php`, `api/track.php` para usar prefijos | 🔴 CRÍTICA |
| 2 | `calcularPrecioYMargen()` | **No implementado** | Endpoint + función | Crear endpoint `?action=calcular_precio` en `api/leads.php` | 🔴 CRÍTICA |
| 3 | Ficha cualificación en modal | **Sin UI** — campos existen en BD | Mostrar/editar campos | Añadir inputs para `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida` en `modals.php` | 🔴 CRÍTICA |
| 4 | UI Mockups | **Sin UI** — tabla existe | Botón "Solicitar Mockup" + widget capacidad | Añadir sección mockup en ficha lead + widget en dashboard | 🟡 ALTA |
| 5 | UI Presupuestos versionados | **Sin UI** — tabla existe | Formulario + cálculo auto + versionado | Añadir sección presupuesto en ficha lead con `calcularPrecioYMargen()` | 🟡 ALTA |
| 6 | Funnel 12 niveles con stage_order | **No usa stage_order** | Endpoint `get_funnel` | Reescribir query de analytics con CASE numérico | 🟡 ALTA |
| 7 | Widget objetivo 20 clubes | **No existe** | Widget + métricas | Añadir sección en dashboard con: ganados, %, ritmo, proyección | 🟡 ALTA |
| 8 | Registro manual de interacciones | **Sin UI** | Formulario en ficha lead | Botón "Añadir interacción" con canal, tipo, resumen, próxima acción | 🟡 ALTA |
| 9 | Snapshots automáticos | **Sin lógica** | Cron/trigger | Script que genere snapshot diario/semanal del funnel | 🟡 ALTA |
| 10 | Comparativa A/B/C en Analytics | **Endpoint existe pero incompleto** | UI + métricas de conversión | Añadir tabla comparativa A/B/C con tasas de cierre, pares, facturación | 🟡 ALTA |
| 11 | KPIs económicos | **No implementados** | Cálculos | Facturación/100 contactos, pares/100 contactos, margen/100 contactos, ticket medio | 🟡 ALTA |
| 12 | Plantillas A/B/C #6 y #7 incompletas | **Parcial** — solo cuerpo A | Completar cuerpos B/C | Rellenar `cuerpo_b`, `cuerpo_c` en plantillas #6 y #7 | 🟢 MEDIA |
| 13 | SAFA MODE blindado en todas las rutas | **Parcial** — `modo_entorno=test` existe pero no se verifica en todos los endpoints | Verificación server-side en `enviar_lote.php` | Añadir check `modo_entorno` en todas las rutas de envío | 🟢 MEDIA |
| 14 | Trazabilidad completa (todos los eventos) | **Parcial** — solo `cambio_estado` y `envio_email` registrados | Faltan eventos de mockup, presupuesto, interacción | Añadir registro de eventos en cada acción nueva | 🟢 MEDIA |

---

## 5. QUÉ NO SE VA A TOCAR

| Componente | Motivo |
|---|---|
| Kanban 9 columnas | Definitivo, validado, operativo |
| Pipeline N:M (`lead_pipelines`) | Arquitectura correcta, operativa |
| SMTP (`enviar_smtp_random.php`) | Robusto, sin cambios necesarios |
| Motor SMTP (`enviarSMTPAutenticado()`) | Robusto, sin cambios |
| Tracking pixel (`track.php`) | Funciona correctamente |
| Bajas (`baja.php`) | Funciona correctamente |
| Autenticación | Funciona correctamente |
| Duplicados (scan/merge) | Funciona correctamente |
| Cuentas SMTP (10 cuentas) | Configuración intacta |
| SAFE MODE (`modo_entorno=test`) | Ya operativo |
| Estructura de columnas de salida | No se modifica |
| Archivos en `output/` y `checkpoints/` | Protegidos, no se tocan |
| Credenciales SMTP | Protegidas, no se tocan |

---

## 6. ARQUITECTURA DE CUALIFICACIÓN (FASE 2)

### 6.1. Campos en `clubes_crm` (ya existen en BD)

```
volumen_estimado     INTEGER  — pares estimados (dato principal)
num_jugadores        INTEGER  — auxiliar
categorias           TEXT     — ej: "Benjamín, Alevín, Infantil"
fecha_decision_prevista DATE  — timeline de decisión del club
objeciones           TEXT     — objeciones registradas
proxima_accion       TEXT     — próxima acción comercial
canal_interaccion    TEXT     — email / whatsapp / telefono / presencial
motivo_perdida       TEXT     — solo si estado = Perdido
```

### 6.2. Flujo de cualificación

```
Interesado → usuario rellena ficha de cualificación → estado cambia a Cualificado
                                                                │
                                          ┌─────────────────────┤
                                          │ volumen < 50        │ volumen >= 50
                                          ▼                     ▼
                                   No priorizar            Botón "Solicitar Mockup"
                                   mockup                  → estado = Propuesta
```

### 6.3. `calcularPrecioYMargen()`

```php
function calcularPrecioYMargen(?int $volumen, int $pvp = 15): array {
    if (!$volumen || $volumen <= 0) return [
        'precio_b2b' => null, 'facturacion' => null,
        'margen_par' => null, 'margen_total' => null, 'tramo' => 'Desconocido'
    ];
    if ($volumen >= 200)      [$precio, $tramo] = [7, '200+ pares'];
    elseif ($volumen >= 100)  [$precio, $tramo] = [8, '100-199 pares'];
    elseif ($volumen >= 50)   [$precio, $tramo] = [9, '50-99 pares'];
    else return [
        'precio_b2b' => null, 'facturacion' => null,
        'margen_par' => null, 'margen_total' => null, 'tramo' => '<50 pares'
    ];
    return [
        'precio_b2b'   => $precio,
        'facturacion'  => $volumen * $precio,
        'margen_par'   => $pvp - $precio,
        'margen_total' => $volumen * ($pvp - $precio),
        'tramo'        => $tramo
    ];
}
```

**Endpoint:** `api/leads.php?action=calcular_precio&volumen=120` → devuelve JSON con el array completo.

---

## 7. ARQUITECTURA MOCKUP (FASE 2)

### 7.1. Tabla `mockups` (ya existe)

```sql
mockups (
    id, lead_id, pipeline_id, estado, solicitado_en, enviado_en, notas
)
```

### 7.2. Flujo

```
Cualificado → click "Solicitar Mockup" → INSERT en mockups (estado='solicitado')
                                         → UPDATE clubes_crm.estado_lead = '06 Propuesta'
                                         → INSERT en comunicaciones_log (tipo='mockup_solicitado')

Propuesta   → click "Mockup Enviado"   → UPDATE mockups.estado = 'enviado', enviado_en = NOW()
                                         → estado_lead NO cambia (sigue en Propuesta)
                                         → INSERT en comunicaciones_log (tipo='mockup_enviado')
```

### 7.3. Widget de capacidad

```
Mockups esta semana: 0/100
  [████████████░░░░░░░░] 0%
  Solicitados: 0 | En producción: 0 | Enviados: 0
```

---

## 8. ARQUITECTURA PRESUPUESTO (FASE 2)

### 8.1. Tabla `presupuestos` (ya existe)

```sql
presupuestos (
    id, lead_id, pipeline_id, version, unidades, precio_unitario,
    subtotal, descuento_aplicado, condiciones_pago, transporte,
    importe_total, margen_potencial_club, estado, fecha
)
```

### 8.2. Flujo

```
Propuesta → click "Crear Presupuesto" → modal con:
                                          - volumen (pre-rellenado de cualificación)
                                          - precio unitario (calculado)
                                          - subtotal
                                          - descuento (5% si 100% adelantado)
                                          - condiciones de pago
                                          - importe total
                                          - margen potencial
                                        → INSERT en presupuestos (version=N+1)
                                        → estado_lead NO cambia
                                        → INSERT en comunicaciones_log

Propuesta → modificar presupuesto → INSERT nueva versión (v2, v3...)
                                     → versiones anteriores se conservan
```

### 8.3. Reglas de negocio

- 50-99 pares → 9 €/par
- 100-199 pares → 8 €/par
- 200+ pares → 7 €/par
- PVP recomendado: 15 €/par
- Pago: 50% adelantado + 50% contraentrega, o 100% adelantado con 5% dto.

---

## 9. EVENTOS Y TRAZABILIDAD

### 9.1. Eventos a registrar en `comunicaciones_log`

| Evento | tipo_evento | ¿Cambia Kanban? |
|---|---|---|
| Email enviado | `envio_email` | No (excepto Sin Contactar → Contactado) |
| Email rebotado | `rebote` | No |
| Respuesta recibida | `respuesta_recibida` | Manual (Respondió) |
| Cambio de estado manual | `cambio_estado` | Sí |
| WhatsApp enviado/recibido | `whatsapp_enviado` / `whatsapp_recibido` | No |
| Llamada realizada/recibida | `llamada_realizada` / `llamada_recibida` | No |
| Cualificación registrada | `cualificacion` | Sí (Interesado → Cualificado) |
| Mockup solicitado | `mockup_solicitado` | Sí (Cualificado → Propuesta) |
| Mockup enviado | `mockup_enviado` | No |
| Presupuesto creado | `presupuesto_creado` | No |
| Presupuesto enviado | `presupuesto_enviado` | No |
| Negociación | `negociacion` | Sí (Propuesta → Negociación) |
| Ganado | `ganado` | Sí (→ Ganado) |
| Perdido | `perdido` | Sí (→ Perdido) |
| Nota añadida | `nota` | No |

### 9.2. Columnas de `comunicaciones_log` a poblar

```
lead_id, club_id, tipo_evento, detalles, pipeline_id,
variante_ab, canal, resumen, proxima_accion, fecha, ip_registro
```

---

## 10. KPIs

### 10.1. Embudo (Funnel 12 niveles)

| # | Nivel | Cálculo |
|---|---|---|
| 1 | Contactados | `stage_order >= 2` |
| 2 | Entregados | Contactados − Rebotes |
| 3 | Abrieron | JOIN `aperturas` |
| 4 | Respondieron | `stage_order >= 4` |
| 5 | Resp. positivas | `stage_order >= 5` |
| 6 | Cualificados | `volumen_estimado >= 50 AND stage_order >= 6` |
| 7 | Oportunidades (Propuesta+) | `stage_order >= 7` |
| 8 | Mockups enviados | `mockups.estado = 'enviado'` |
| 9 | Presupuestos | `presupuestos.id IS NOT NULL` |
| 10 | Negociaciones | `stage_order >= 8` |
| 11 | Ganados | `stage_order = 9` |
| 12 | Perdidos | `stage_order = 10` |

### 10.2. Conversión

| KPI | Fórmula |
|---|---|
| % respuesta | Respondieron / Entregados × 100 |
| % respuesta positiva | Resp. positivas / Respondieron × 100 |
| % cualificación | Cualificados / Resp. positivas × 100 |
| % propuesta | Oportunidades / Cualificados × 100 |
| % negociación | Negociaciones / Oportunidades × 100 |
| % cierre | Ganados / Negociaciones × 100 |
| % global | Ganados / Contactados × 100 |

### 10.3. Económicos

| KPI | Fórmula |
|---|---|
| Clubes ganados / 100 contactos | Ganados / Contactados × 100 |
| Facturación / 100 contactos | Σ facturación / Contactados × 100 |
| Pares / 100 contactos | Σ pares / Contactados × 100 |
| Margen potencial clubes / 100 contactos | Σ margen / Contactados × 100 |
| Ticket medio | Facturación / Nº pedidos |
| Volumen medio | Pares / Nº pedidos |

### 10.4. A/B/C

Para cada variante: contactos, respuestas, resp. positivas, cualificados, propuestas, negociaciones, ganados, tasa de cierre, pares vendidos, facturación, margen.

---

## 11. OBJETIVO 20 CLUBES

Widget en dashboard:

```
┌─────────────────────────────────────────┐
│ 🎯 OBJETIVO: 20 CLUBES (antes 1 Sep)   │
│                                         │
│ Ganados: 0/20 (0%)                      │
│ Restantes: 20                           │
│ Días hasta 1 Sep: 21                     │
│ Ritmo necesario: 0.95 club/día           │
│ Ritmo actual: 0.00 club/día              │
│ Proyección: 0 clubes al 1 Sep           │
│                                         │
│ Para llegar:                            │
│ Contactar ~X leads (est. 2% cierre)     │
└─────────────────────────────────────────┘
```

---

## 12. QA — PLAN DE PRUEBAS

### 12.1. Leads TEST existentes (5)

| ID | Nombre | Email | Estado | Variante |
|---|---|---|---|---|
| 1809 | TEST_CLUB_01_RealMadrid | test01@futprotec.local | 01 Sin Contactar | A |
| 1810 | TEST_CLUB_02_Barcelona | test02@futprotec.local | 01 Sin Contactar | B |
| 1811 | TEST_CLUB_03_Valencia | test03@futprotec.local | 01 Sin Contactar | C |
| 1812 | TEST_CLUB_04_Sevilla | test04@futprotec.local | 01 Sin Contactar | A |
| 1813 | TEST_CLUB_05_Bilbao | test05@futprotec.local | 01 Sin Contactar | B |

### 12.2. Casos de prueba Fase 2

| # | Caso | Flujo | Verificación |
|---|---|---|---|
| QA-1 | Lead sin contactar → Contactado | Enviar email a TEST_CLUB_01 | `estado_lead = '02 Contactado'` |
| QA-2 | Respondió | TEST_CLUB_02 → `03 Respondió` | Kanban actualizado |
| QA-3 | Interesado → Cualificado | TEST_CLUB_03 → `04 Interesado` → rellenar cualificación | `volumen_estimado = 120`, cálculo 8€/par |
| QA-4 | Solicitar Mockup | TEST_CLUB_03 → click "Solicitar Mockup" | `estado = '06 Propuesta'`, `mockups.estado = 'solicitado'` |
| QA-5 | Mockup enviado | TEST_CLUB_03 → click "Mockup Enviado" | `mockups.estado = 'enviado'`, Kanban sigue Propuesta |
| QA-6 | Crear presupuesto v1 | TEST_CLUB_03 → modal presupuesto | `presupuestos` v1 con 120 pares × 8€ = 960€ |
| QA-7 | Presupuesto versionado | Modificar → v2 con 150 pares | v1 conservado, v2 = 150 × 8€ = 1,200€ |
| QA-8 | Negociación | TEST_CLUB_03 → `07 Negociación` | Kanban actualizado |
| QA-9 | Ganado | TEST_CLUB_03 → `08 Ganado` | `presupuestos.estado = 'aceptado'` |
| QA-10 | Perdido con motivo | TEST_CLUB_02 → `09 Perdido` | `motivo_perdida = 'precio'` |
| QA-11 | A/B/C traza | Verificar 5 TEST leads | Cada uno con variante correcta en `lead_pipelines` |
| QA-12 | Widget mockups | Solicitar 3 mockups | Widget muestra 3/100, 3% |
| QA-13 | Widget objetivo | Marcar 1 Ganado | Widget: 1/20 (5%), proyección actualizada |

### 12.3. Aislamiento de TEST

- Todos los leads TEST tienen email `@futprotec.local` (no envían a destinatarios reales)
- Pipeline "Experimento Fase 1 TEST" está marcado como NO REAL
- `modo_entorno = test` redirige todos los envíos a `email_test`

---

## 13. RIESGOS

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Inconsistencia de prefijos rompe envíos | ALTA | ALTO | Corregir ANTES de cualquier otra tarea |
| Modificar `api/leads.php` rompe lanzadera | MEDIA | ALTO | Test con 1 solo envío en modo test |
| Añadir campos al modal rompe layout | BAJA | MEDIO | Tailwind responsive, test visual |
| `calcularPrecioYMargen()` con valores extremos | BAJA | BAJO | Validar null, 0, negativos, >10000 |
| Regresión en Kanban al añadir endpoints | BAJA | ALTO | Test drag & drop después de cada cambio |

---

## 14. DEPENDENCIAS

```
Fase 2.0: CORRECCIÓN DE PREFIJOS (PRERREQUISITO)
    │
    ├── Fase 2.1: calcularPrecioYMargen() + endpoint
    │       │
    │       └── Fase 2.2: Ficha cualificación en modal
    │               │
    │               ├── Fase 2.3: UI Mockups + widget capacidad
    │               │
    │               └── Fase 2.4: UI Presupuestos versionados
    │
    ├── Fase 2.5: Registro manual interacciones
    │
    ├── Fase 2.6: Funnel 12 niveles (stage_order)
    │
    ├── Fase 2.7: KPIs económicos
    │
    ├── Fase 2.8: Widget objetivo 20 clubes
    │
    └── Fase 2.9: QA y regresión
```

---

## 15. ORDEN EXACTO DE IMPLEMENTACIÓN

| Paso | Tarea | Archivos | Prioridad |
|---|---|---|---|
| **P0** | Unificar prefijos de estado en API | `api/leads.php`, `api/enviar_lote.php`, `cli/cron.php`, `api/track.php` | 🔴 BLOQUEANTE |
| **P1** | Implementar `calcularPrecioYMargen()` + endpoint | `api/leads.php` → `?action=calcular_precio` | 🔴 |
| **P2** | Añadir campos de cualificación al modal | `tabs/modals.php`, `dashboard.php` (endpoint `update_lead`) | 🔴 |
| **P3** | Añadir sección Mockup en ficha lead | `tabs/modals.php`, `dashboard.php` (nuevos endpoints) | 🟡 |
| **P4** | Widget capacidad mockups en dashboard | `tabs/analytics.php` o `dashboard.php` | 🟡 |
| **P5** | Añadir sección Presupuesto en ficha lead | `tabs/modals.php`, `dashboard.php` (nuevos endpoints) | 🟡 |
| **P6** | Registro manual de interacciones | `tabs/modals.php`, `dashboard.php` | 🟡 |
| **P7** | Funnel 12 niveles con `stage_order` | `dashboard.php` (endpoint `get_analytics`), `tabs/analytics.php` | 🟡 |
| **P8** | KPIs económicos en analytics | `dashboard.php`, `tabs/analytics.php` | 🟡 |
| **P9** | Widget objetivo 20 clubes | `dashboard.php`, `tabs/analytics.php` | 🟡 |
| **P10** | Comparativa A/B/C en analytics | `dashboard.php`, `tabs/analytics.php` | 🟡 |
| **P11** | Snapshots automáticos | `cli/cron.php` o nuevo script | 🟢 |
| **P12** | Completar plantillas #6 y #7 A/B/C | `tabs/editor.php` o script SQL | 🟢 |
| **QA** | Pruebas integrales con leads TEST | Todos | 🔴 |

---

## 16. CRITERIOS DE ACEPTACIÓN

### 16.1. Corrección de prefijos (P0)
- [ ] `api/leads.php`: `'Sin Contactar'` → `'01 Sin Contactar'`
- [ ] `api/enviar_lote.php`: UPDATE usa prefijo correcto
- [ ] `cli/cron.php`: UPDATE usa prefijo correcto
- [ ] `api/track.php`: UPDATE usa prefijo correcto
- [ ] Lanzadera encuentra leads correctamente
- [ ] Kanban muestra conteos correctos

### 16.2. Cualificación (P1-P2)
- [ ] `calcularPrecioYMargen(120)` → `{precio_b2b:8, facturacion:960, margen_par:7, margen_total:840, tramo:'100-199 pares'}`
- [ ] `calcularPrecioYMargen(40)` → `{precio_b2b:null, tramo:'<50 pares'}`
- [ ] Modal ficha lead muestra campo `volumen_estimado` con cálculo automático al cambiar
- [ ] Modal muestra `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`
- [ ] `motivo_perdida` visible solo si `estado_lead = '09 Perdido'`
- [ ] Guardar cambios actualiza BD correctamente

### 16.3. Mockups (P3-P4)
- [ ] Botón "Solicitar Mockup" visible en leads en estado `05 Cualificado`
- [ ] Click → `mockups` INSERT + `estado_lead = '06 Propuesta'` + evento en log
- [ ] Botón "Mockup Enviado" visible en leads en `06 Propuesta` con mockup solicitado
- [ ] Click → `mockups.estado = 'enviado'`, Kanban NO cambia
- [ ] Widget capacidad: X/100, porcentaje, alerta si >80%

### 16.4. Presupuestos (P5)
- [ ] Botón "Crear Presupuesto" visible en leads en `06 Propuesta`
- [ ] Modal muestra volumen pre-rellenado, precio calculado, subtotal, descuento, total, margen
- [ ] Condiciones de pago seleccionables (50%+50% o 100% con 5% dto)
- [ ] Insert crea v1; segundo presupuesto crea v2 sin sobrescribir v1
- [ ] Presupuestos visibles en ficha lead con nº de versión, fecha, importe

### 16.5. KPIs y Analytics (P7-P10)
- [ ] Funnel 12 niveles con `stage_order` CASE
- [ ] KPIs de conversión calculados correctamente
- [ ] KPIs económicos: facturación/100, pares/100, margen/100, ticket medio
- [ ] Comparativa A/B/C con tasas de cierre, pares, facturación
- [ ] Widget objetivo 20 clubes con ganados, %, ritmo, proyección

### 16.6. Regresión
- [ ] Kanban 9 columnas funciona (drag & drop)
- [ ] Pipeline N:M intacto
- [ ] A/B/C asignación intacta
- [ ] SAFE MODE intacto
- [ ] SMTP round-robin intacto
- [ ] Tracking pixel intacto
- [ ] Bajas intactas
- [ ] Autenticación intacta
- [ ] Editor plantillas intacto
- [ ] Gestor datos intacto
- [ ] Lanzadera intacta
- [ ] 0 envíos reales realizados
- [ ] Leads comerciales (1,808) intactos

---

## 17. CONTRADICCIONES DETECTADAS

### ❌ Contradicción #1: Prefijos de estado inconsistentes

**Evidencia:**
- BD: `'01 Sin Contactar'`, `'03 Respondió'`
- `$estadosKanban`: `'01 Sin Contactar'`, `'02 Contactado'`, etc.
- `api/leads.php` línea 655: `c.estado_lead = 'Sin Contactar'` (SIN prefijo)
- `api/leads.php` línea 545: `'Sin Contactar'` (SIN prefijo)

**Impacto:** La lanzadera no encuentra leads para enviar. El Kanban funciona porque usa los strings con prefijo del array `$estadosKanban`. Pero cualquier código que use strings sin prefijo fallará silenciosamente (WHERE no matchea).

**Recomendación:** Unificar a formato con prefijo. Modificar `api/leads.php`, `api/enviar_lote.php`, `cli/cron.php`, `api/track.php`.

### ❌ Contradicción #2: Estados legacy en track.php y cron.php

**Evidencia:**
- `cli/cron.php`: `estado_lead = 'Email Enviado / En Secuencia'` y `'Impactado / Abrio Email'`
- `api/track.php`: `estado_lead = 'Impactado / Abrio Email'`

**Impacto:** Estos estados NO existen en V4.3 (`Contactado` los reemplaza). Si se ejecutan, escribirán estados legacy que no aparecerán en el Kanban.

**Recomendación:** Cambiar a `'02 Contactado'` en ambos archivos.

---

## 18. RESUMEN

| Métrica | Valor |
|---|---|
| Tablas V4.3 presentes | 15/15 ✅ |
| Columnas V4.3 presentes | Todas ✅ |
| Leads comerciales | 1,808 ✅ |
| Leads TEST aislados | 5 ✅ |
| Envíos reales | 0 ✅ |
| SAFE MODE | Activo ✅ |
| Gaps críticos | 2 (prefijos + estados legacy) |
| Gaps de UI | 5 (cualificación, mockup, presupuesto, interacciones, objetivo) |
| Gaps de analytics | 3 (funnel, KPIs, A/B/C) |
| Funcionalidades protegidas | 12 (sin cambios) |
| Archivos a modificar | ~8 |
| Riesgo de regresión | BAJO (cambios incrementales, no reescritura) |

---

## 19. PRÓXIMO PASO

**ESPERAR APROBACIÓN EXPLÍCITA DEL USUARIO** antes de modificar cualquier archivo.

Una vez aprobado, la implementación seguirá el orden exacto de la sección 15, comenzando por P0 (corrección de prefijos).

**SAFE MODE se mantendrá en `test` durante toda la Fase 2.**

---

*Fin del Plan de Implementación Validado — Fase 2*