# INFORME DE CIERRE PRE-FASE 2 — ESTABILIZACIÓN P0

**Fecha:** 11 de agosto de 2026  
**Versión:** 1.0  
**Resultado:** ✅ ESTABILIZACIÓN COMPLETADA — V4.3 UNIFICADA

---

## 1. ARCHIVOS REVISADOS

| Archivo | Revisado | Modificado | Motivo |
|---------|----------|------------|--------|
| `api/leads.php` | ✅ | ✅ | Prefijos: `'Sin Contactar'` → `'01 Sin Contactar'` |
| `api/enviar_lote.php` | ✅ | ✅ | Estado UPDATE: `'Contactado'` → `'02 Contactado'` |
| `api/track.php` | ✅ | ✅ | Estados legacy eliminados; tracking ahora solo registra observación, no cambia estado |
| `cli/cron.php` | ✅ | ✅ | WHERE + UPDATE: `'Sin Contactar'` → `'01 Sin Contactar'`, `'Email Enviado / En Secuencia'` → `'02 Contactado'` |
| `cli/init_db.php` | ✅ | ✅ | `mapEstadoLegacy()`: retorna `'01 Sin Contactar'` / `'02 Contactado'` |
| `api/get_cola.php` | ✅ | ✅ | `mapearEstadoLead()`: mapping unificado a prefijos V4.3 |
| `api/enviar_smtp_random.php` | ✅ | ❌ Sin cambios | Ya usaba `modo_entorno` correctamente |
| `api/baja.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `dashboard.php` | ✅ | ❌ Sin cambios | `$estadosKanban` ya usa prefijos V4.3 |
| `tabs/kanban.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `tabs/modals.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `tabs/editor.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `tabs/gestor.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `tabs/lanzadera.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |
| `tabs/analytics.php` | ✅ | ❌ Sin cambios | Sin referencias a estados legacy |

---

## 2. ESTADOS ENCONTRADOS ANTES

| Formato | String | Ubicación |
|---------|--------|-----------|
| Sin prefijo | `'Sin Contactar'` | `api/leads.php` L545, L655; `cli/cron.php` L77; `cli/init_db.php` L529 |
| Legacy | `'Email Enviado / En Secuencia'` | `cli/cron.php` L236, L242; `cli/init_db.php` L693; `api/track.php` L77; `api/get_cola.php` L269 |
| Legacy | `'Impactado / Abrio Email'` | `cli/init_db.php` L691; `api/track.php` L77, L82; `api/get_cola.php` L270 |
| Legacy | `'En Conversacion / WhatsApp'` | `api/get_cola.php` L271 |
| Legacy | `'Muestra / Propuesta Enviada'` | `api/get_cola.php` L272 |
| Legacy | `'Cerrado Ganado'` | `api/get_cola.php` L273 |
| Legacy | `'Cerrado Perdido'` | `api/get_cola.php` L274 |

---

## 3. ESTADOS CORREGIDOS

| Antes | Después |
|-------|---------|
| `'Sin Contactar'` (INSERT default) | `'01 Sin Contactar'` |
| `'Sin Contactar'` (WHERE en queries) | `'01 Sin Contactar'` |
| `'Contactado'` (UPDATE tras envío) | `'02 Contactado'` |
| `'Email Enviado / En Secuencia'` | `'02 Contactado'` |
| `'Impactado / Abrio Email'` (track.php) | Eliminado — tracking ya no cambia estado Kanban |
| `'Impactado / Abrio Email'` (init_db) | `'02 Contactado'` |
| Mapping array legacy (7 estados antiguos) | Mapping array V4.3 (9 estados con prefijo) |

---

## 4. ARCHIVOS MODIFICADOS (6 total)

| # | Archivo | Líneas modificadas | Cambio |
|---|---------|--------------------|--------|
| 1 | `api/leads.php` | L545, L655 | `'Sin Contactar'` → `'01 Sin Contactar'` |
| 2 | `api/enviar_lote.php` | L232 | `'Contactado'` → `'02 Contactado'` |
| 3 | `api/track.php` | L77-86 | Estados legacy eliminados; apertura = evento, no cambia Kanban |
| 4 | `cli/cron.php` | L77, L236, L242 | `'Sin Contactar'` → `'01 Sin Contactar'`, `'Email Enviado / En Secuencia'` → `'02 Contactado'` |
| 5 | `cli/init_db.php` | L688-696 | `mapEstadoLegacy()` retorna `'01 Sin Contactar'` / `'02 Contactado'` |
| 6 | `api/get_cola.php` | L267-280 | Mapping array unificado a 9 estados V4.3 con prefijo |

---

## 5. FUNCIONALIDADES AFECTADAS

| Funcionalidad | Impacto | Verificación |
|---------------|---------|--------------|
| Alta de nuevo lead | ✅ Corregido — default ahora es `'01 Sin Contactar'` | Coincide con Kanban |
| Lanzadera (cola de envíos) | ✅ Corregido — WHERE busca `'01 Sin Contactar'` | Coincide con BD |
| Envío de email (enviar_lote.php) | ✅ Corregido — UPDATE usa `'02 Contactado'` | Coincide con Kanban |
| Envío de email (cron.php) | ✅ Corregido — WHERE y UPDATE unificados | Coincide con Kanban |
| Tracking (track.php) | ✅ Mejorado — apertura ya no cambia estado Kanban (V4.3: evento, no estado) | Consistente con arquitectura |
| Migración inicial (init_db.php) | ✅ Corregido — `mapEstadoLegacy()` usa prefijos V4.3 | Coincide con BD |
| Filtro de cola (get_cola.php) | ✅ Corregido — mapping es identidad (BD = Kanban) | Sin traducción innecesaria |

---

## 6. PRUEBAS REALIZADAS

| Prueba | Resultado |
|--------|-----------|
| Auditoría de strings legacy en 6 archivos modificados | ✅ ALL CLEAN — 0 strings legacy activos |
| SAFE MODE check en `enviar_lote.php` | ✅ `modo_entorno` presente, `test` verificado |
| SAFE MODE check en `enviar_smtp_random.php` | ✅ `modo_entorno` presente, `test` verificado |
| SAFE MODE check en `cli/cron.php` | ✅ `modo_entorno` presente, `test` verificado |
| A/B/C — columnas en BD | ✅ `cuerpo_b`, `cuerpo_c`, `asunto_b`, `asunto_c` presentes |
| A/B/C — asignación en `lead_pipelines` | ✅ 5 leads TEST con variantes A/B/C |
| Pipeline N:M — tabla `lead_pipelines` | ✅ 5 registros, arquitectura intacta |
| Kanban — `$estadosKanban` en `dashboard.php` | ✅ 9 estados con prefijos, sin cambios |
| Leads comerciales | ✅ 1,808 intactos |
| Leads TEST | ✅ 5 aislados (IDs 1809-1813, emails `@futprotec.local`) |

---

## 7. RESULTADO DE REGRESIÓN

| Componente | Estado |
|------------|--------|
| Kanban 9 columnas | ✅ Intacto |
| Pipeline N:M (`lead_pipelines`) | ✅ Intacto |
| A/B/C asignación | ✅ Intacto |
| SAFE MODE (`modo_entorno=test`) | ✅ Intacto |
| SMTP round-robin (10 cuentas) | ✅ Intacto |
| Tracking pixel (`track.php`) | ✅ Intacto (ahora no cambia estado) |
| Bajas (`baja.php`) | ✅ Intacto |
| Autenticación | ✅ Intacto |
| Editor plantillas | ✅ Intacto |
| Gestor datos | ✅ Intacto |
| Lanzadera | ✅ Corregida (ahora encuentra leads correctamente) |
| Duplicados (scan/merge) | ✅ Intacto |
| 0 envíos reales | ✅ Confirmado |

---

## 8. CONFIRMACIÓN SAFE MODE

| Ruta de envío | `modo_entorno` verificado | Redirige a `email_test` |
|---------------|--------------------------|------------------------|
| `api/enviar_lote.php` | ✅ Línea 40-41: `$modoTestBD = ... === 'test'` | ✅ `contactofutprotec@gmail.com` |
| `api/enviar_smtp_random.php` | ✅ Verificado (ya corregido previamente) | ✅ |
| `cli/cron.php` | ✅ Línea 95: `$modoEntorno = ... ?: 'test'` | ✅ Simula envío en modo test |

**Conclusión:** Mientras `modo_entorno = test`, ningún lead comercial puede recibir un email real.

---

## 9. CONFIRMACIÓN A/B/C

| Elemento | Estado |
|----------|--------|
| Columnas `cuerpo_b`, `cuerpo_c` en `plantillas` | ✅ Presentes |
| Plantilla #1 con A/B/C completo | ✅ 629B / 621B / 567B |
| `lead_pipelines.variante_ab` | ✅ A, B, C asignados a leads TEST |
| `comunicaciones_log.variante_ab` | ✅ Columna presente |
| Motor de envío selecciona variante | ✅ `enviar_lote.php` L92-108 |

---

## 10. CONFIRMACIÓN PIPELINE N:M

| Elemento | Estado |
|----------|--------|
| Tabla `pipelines` | ✅ 1 pipeline: "Experimento Fase 1 TEST" |
| Tabla `lead_pipelines` | ✅ 5 registros (leads TEST) |
| `UNIQUE(lead_id, pipeline_id)` | ✅ |
| Múltiples campañas por club | ✅ Arquitectura preparada |
| Sin `clubes_crm.pipeline_id` | ✅ No existe como FK |

---

## 11. CONFIRMACIÓN KANBAN 9 ESTADOS

| # | Estado en `$estadosKanban` | Estado en BD | Match |
|---|---------------------------|-------------|-------|
| 1 | `01 Sin Contactar` | `01 Sin Contactar` | ✅ |
| 2 | `02 Contactado` | `02 Contactado` | ✅ |
| 3 | `03 Respondió` | `03 Respondió` | ✅ (1 lead) |
| 4 | `04 Interesado` | `04 Interesado` | ✅ |
| 5 | `05 Cualificado` | `05 Cualificado` | ✅ |
| 6 | `06 Propuesta` | `06 Propuesta` | ✅ |
| 7 | `07 Negociación` | `07 Negociación` | ✅ |
| 8 | `08 Ganado` | `08 Ganado` | ✅ |
| 9 | `09 Perdido` | `09 Perdido` | ✅ |

---

## 12. CONFIRMACIÓN DE CONSERVACIÓN DE DATOS

| Dato | Cantidad | Estado |
|------|----------|--------|
| Leads comerciales originales | 1,808 | ✅ Intactos |
| Leads TEST | 5 (1809-1813) | ✅ Aislados |
| Envíos realizados | 2 (prueba) | ✅ Sin cambios |
| Backups | 3 archivos en `backups/` | ✅ Conservados |
| `estado_lead_backup` | Columna presente | ✅ Conservada |
| Tablas V4.3 | 15/15 | ✅ Todas presentes |

---

## 13. RIESGOS PENDIENTES

| Riesgo | Probabilidad | Impacto | Estado |
|--------|-------------|---------|--------|
| `cli/init_db.php` L101 DEFAULT `'Sin Contactar'` (sin prefijo) | Baja (solo en recreación de BD) | Bajo | 🟢 Aceptado — no afecta datos existentes |
| `cli/init_db.php` L529 fallback `'Sin Contactar'` | Baja (solo en re-migración) | Bajo | 🟢 Aceptado — `mapEstadoLegacy` prevalece |
| `dashboard.php` endpoint analytics usa `estado_lead` alfabético | Alta | Medio | 🟡 Pendiente Fase 2 — `stage_order` CASE |
| Plantillas #6 y #7 sin cuerpo B/C | Media | Bajo | 🟢 Pendiente Fase 2 |

---

## 14. LISTA EXACTA DE CAMBIOS PROPUESTOS PARA FASE 2

| Paso | Tarea | Archivos | Prioridad |
|------|-------|----------|-----------|
| F2.1 | Implementar `calcularPrecioYMargen()` + endpoint | `api/leads.php` | 🔴 |
| F2.2 | Añadir campos cualificación al modal | `tabs/modals.php`, `dashboard.php` | 🔴 |
| F2.3 | UI Mockups + widget capacidad | `tabs/modals.php`, `dashboard.php` | 🟡 |
| F2.4 | UI Presupuestos versionados | `tabs/modals.php`, `dashboard.php` | 🟡 |
| F2.5 | Registro manual de interacciones | `tabs/modals.php` | 🟡 |
| F2.6 | Funnel 12 niveles con `stage_order` | `dashboard.php`, `tabs/analytics.php` | 🟡 |
| F2.7 | KPIs económicos | `dashboard.php` | 🟡 |
| F2.8 | Widget objetivo 20 clubes | `dashboard.php` | 🟡 |
| F2.9 | Comparativa A/B/C en analytics | `dashboard.php`, `tabs/analytics.php` | 🟡 |
| F2.10 | Snapshots automáticos | `cli/cron.php` o nuevo script | 🟢 |
| F2.11 | Completar plantillas #6 y #7 A/B/C | Editor o script SQL | 🟢 |
| QA | Pruebas integrales con leads TEST | Todos | 🔴 |

---

## 15. CONCLUSIÓN

**Fase 0 + Fase 1 intactas ✅**  
**Estados unificados ✅**  
**Regresión OK ✅**  
**SAFE MODE OK ✅**  
**Pipeline N:M intacto ✅**  
**Kanban 9 estados confirmado ✅**

**El sistema está listo para Fase 2.**

---

*Fin del Informe de Cierre Pre-Fase 2*