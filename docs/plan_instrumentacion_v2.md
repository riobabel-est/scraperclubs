# PLAN DE INSTRUMENTACIÓN V2 — CRM FUTPROTEC

> **Tipo:** entregable obligatorio de FASE 0 del MEGAPROMPT V2 (`docs/megaprompt_v2_crm_futprotec.md`).
> **Fecha de snapshot:** 2026-08-29 (auditoría ejecutada en modo **solo lectura**, `mode=ro`).
> **Estado:** FASE 0 técnica completada — **PENDIENTE DE APROBACIÓN del usuario para autorizar FASE 1**.
> **Regla de frontera:** el agente puede crear este plan, pero NO ejecuta FASE 1 hasta que Rodrigo lo autorice explícitamente.
> **Base de referencia:** `docs/contrainforme_megaprompt_v2.md` · `docs/auditoria_campana_2026_08_informe_completo.md`.

---

## 1. SNAPSHOT DE CONTEO (verificado 29-08-2026)

### 1.1 Prágmas

| Check | Valor |
|---|---|
| `PRAGMA integrity_check` | `ok` |
| `PRAGMA journal_mode` | `wal` |
| `PRAGMA foreign_keys` | `0` (OFF) |

### 1.2 Conteo general de tablas

| Tabla | Filas | | Tabla | Filas |
|---|---:|---|---|---:|
| `clubes_crm` | 1.818 | | `secuencias` | 1 |
| `envios` | 470 | | `secuencia_pasos` | 0 |
| `aperturas` | 326 | | `lead_pipelines` | 5 |
| `respuestas` | 30 | | `destinatarios_test` | 0 |
| `comunicaciones_log` | 547 | | `_migraciones` | 1 |
| `pipelines` | 3 | | `envios_adjuntos` | 11 |
| `plantillas` | 6 | | `respuestas_adjuntos` | 1 |
| `cuentas_smtp` | 10 | | `presupuestos` | 0 |
| `rebotes` | **0** | | `mockups` | 0 |

### 1.3 Campaña 2 (`campaign_id=2`, `es_test=0`)

| Métrica | Valor |
|---|---:|
| Leads distintos | 348 |
| Envíos totales | 432 |
| Primeros envíos (`es_rotacion=0`) | 348 |
| Rotaciones (`es_rotacion=1`) | 84 |
| `resultado_envio = ACCEPTED` | 432 (100 %) |
| Leads con ≥1 apertura | 134 |
| Aperturas brutas | 259 |
| Respuestas | 5 |
| Hard bounces (`respuestas.es_rebote=1`) | 21 |
| `message_id` NULL en campaña 2 | 0 |
| Presupuestos / Mockups / Ventas | 0 / 0 / 0 |

### 1.4 Aislamiento TEST/REAL

| Grupo | Filas |
|---|---:|
| REALES campaña 2 (`campaign_id=2, es_test=0`) | 432 |
| REALES sin `campaign_id` (`es_test=0, campaign_id IS NULL`) | **20** (follow-ups `Re:` + diagnósticos sin marca) |
| TEST sueltos (`campaign_id=NULL, es_test=1`) | 12 |
| TEST smoke (`campaign_id=3, es_test=1`) | 6 |

### 1.5 Rango de fechas de envíos

`2026-08-07 03:13:48` → `2026-08-28 18:31:55` (sin actividad posterior al 28-08; **no hay envíos en curso** — `config.motor_estado = pausado`).

---

## 2. CONFIRMACIÓN DE EXACTITUD DEL CONTRAINFORME

Todos los conteos de la sección 1 **coinciden exactamente** con `docs/contrainforme_megaprompt_v2.md` y con `docs/auditoria_campana_2026_08_informe_completo.md` (28-08-2026). **No hay discrepancias ni datos nuevos desde la auditoría.**

Los 10 bloqueantes identificados siguen vigentes (ninguno ha sido corregido todavía):
1. Supresión de hard bounces inexistente (3 rebotados reenviados por rotación).
2. Cabecera `From` sin RFC 2047 (rebote Yahoo confirmado).
3. `Math.random()` vivo en `js/app.js:1752,1793` para A/B/C.
4. Follow-ups manuales sin metadatos (20 envíos REALES con `campaign_id=NULL`).
5. Aperturas sin deduplicación (hasta 49 registros por envío).
6. Sin tracking de clics.
7. Clasificación de respuestas limitada a 6 valores.
8. `fecha_respuesta` en RFC 2822 · `atendido_en` NULL 100 %.
9. `presupuestos`/`mockups` con esquema legacy (`pipeline_id`).
10. Sin `oportunidades`, `campaign_batch_id`, `variant_original`, `parent_envio_id`.

---

## 3. BACKUP DE REFERENCIA

- Backup verificable más reciente en `public_html/outbound/data/`: **`stats.db.bak_local_datos_20260827`** (27-08-2026, `integrity_check=ok`, abre correctamente; 180 filas en `envios`, previo a los envíos del 27-28/08).
- **Acción obligatoria al inicio de FASE 1 (antes de la primera migración):** crear un **backup fresco y verificable** del estado actual (`stats.db`, 470 envíos) con el patrón `stats.db.bak_fase1_<YYYYMMDD_HHMM>`. Sin este backup, ninguna migración puede ejecutarse.
---

## 4. ARCHIVOS AFECTADOS POR FASE (código)

| Fase | Archivos afectados |
|---|---|
| **FASE 1** | `public_html/outbound/inc/smtp_transport.php` (RFC 2047) · `public_html/outbound/inc/eligibilidad.php` (supresión bounces) · `public_html/outbound/js/app.js` (A/B/C determinista) · posible endpoint `api/` para exponer `asignarVariante()` · test local de raw MIME |
| **FASE 2** | `public_html/outbound/inc/atencion_lead.php` (follow-ups con metadatos) · `public_html/outbound/api/leads.php` (seguimiento) · `public_html/outbound/inc/imap_respuestas.php` (fecha ISO) · `public_html/outbound/api/respuestas.php` (clasificación) · `public_html/outbound/api/analytics.php` (lectura eventos) |
| **FASE 3** | `public_html/outbound/api/track.php` (dedup observaciones) · nuevo `api/click.php` (redirector de clics) · `inc/mime.php` (reescritura URLs CTA) · vista SQL en `stats.db` |
| **FASE 4** | `public_html/outbound/tabs/modals.php` y `inc/respuestas.php` (clasificación rápida 9 estados) · `api/presupuestos.php` (ALTER aditivo) · `api/mockups.php` (ALTER aditivo) · `inc/atencion_lead.php` (crear oportunidad) |
| **FASE 5** | nuevo `public_html/outbound/cli/auditoria_pre_lote.php` (checkpoint 10 checks) · `inc/eligibilidad.php` (reutiliza supresión) |
| **FASE 6-7** | `cli/cron.php` y `api/enviar_lote.php` (batch + límites operativos) · `inc/abc.php` (variante determinista ya disponible) |

---

## 5. TABLAS AFECTADAS POR FASE (BD)

| Fase | Tabla | Cambio (aditivo) |
|---|---|---|
| FASE 1 | `rebotes` | ALTER: +`envio_id`, +`lead_id`, +`campaign_id`, +`smtp_code`, +`atribucion_parcial`; poblar desde `respuestas.es_rebote=1` (21 filas) |
| FASE 2 | `envios` | ALTER: +`variant_original`, +`campaign_batch_id`, +`parent_envio_id`, +`respuesta_origen_id`; índice `idx_envios_parent` |
| FASE 2 | `comunicaciones_log` | ALTER: +`metadata` (JSON); eventos normalizados |
| FASE 2 | `respuestas` | ALTER: +`fecha_respuesta_iso`, +`atendido_en`, +`intencion`, +`proxima_accion` |
| FASE 2 | `oportunidades` | CREATE (mínima) |
| FASE 3 | `aperturas` | SIN cambios (bruto intocable); **vista SQL derivada** para métricas dedup |
| FASE 3 | `clics` | CREATE (si no existe equivalente) |
| FASE 4 | `presupuestos` | ALTER: +`campaign_id`, +`opportunity_id`, +`respuesta_origen_id`, +`envio_origen_id`, +fechas; **conservar `pipeline_id`** |
| FASE 4 | `mockups` | ALTER: +`campaign_id`, +`opportunity_id`, +`presupuesto_id`, +`version`, +`fecha_envio` |
| FASE 5 | `batches` | CREATE (id, campaign_id, batch, fecha, estado, tamano) |
| FASE 1-7 | `_migraciones` | INSERT de registro por cada migración |

---

## 6. CAMBIOS PREVISTOS POR FASE (resumen ejecutivo)

1. **FASE 1 — Bloqueantes:** supresión de hard bounces (tabla `rebotes` + histórico `respuestas`), RFC 2047 en `From`, A/B/C determinista en UI, validación RAW MIME, protección TEST/REAL.
2. **FASE 2 — Trazabilidad:** follow-ups con `campaign_id/plantilla_id/smtp_id/variant/parent_envio_id/respuesta_origen_id`, evento `metadata` en `comunicaciones_log`, `fecha_respuesta_iso`/`atendido_en`, tabla `oportunidades`.
3. **FASE 3 — Tracking:** vista SQL de aperturas dedup (primera/última/N), tracking de clics con `CTA_WEB/CTA_PRESUPUESTO/CTA_CONTACTO` vía `api/click.php`.
4. **FASE 4 — Operativa:** clasificación rápida (9 estados), cualificación en segundos, `presupuestos`/`mockups` operativos con trazabilidad.
5. **FASE 5 — Checkpoint:** auditoría pre-lote (`READY TO SEND`/`BLOCKED`) con 10 comprobaciones + tabla `batches`.
6. **FASE 6 — Primer lote:** `batch_size ∈ [200,300]`, decidido por checkpoint y autorizado explícitamente.
---

## 7. TESTS PREVISTOS (TEST MATRIX del MEGAPROMPT V2)

| Test | Fase | Descripción | Sin SMTP |
|---|---|---|---|
| TEST 01 | 1-7 | DB integrity (`integrity_check=ok`) | ✅ |
| TEST 02 | 3 | apertura dedup (bruto intacto + métrica correcta) | ✅ |
| TEST 03 | 3 | tracking (píxel registra, primera apertura) | ✅ |
| TEST 04 | 3 | click attribution (lead/campaña/envío/URL/timestamp) | ✅ |
| TEST 05 | 2 | follow-up traceability (`parent_envio_id`, `in_reply_to`) | ✅ |
| TEST 06 | 2 | campaign attribution (envío con metadatos completos) | ✅ |
| TEST 07 | 1 | MIME UTF-8 (raw sin enviar REAL) | ✅ |
| TEST 08 | 1 | RFC 2047 (`Adrián Cano` → encoded-word válido) | ✅ |
| TEST 09 | 1 | SMTP error handling (respuestas 4xx/5xx simuladas) | ✅ |
| TEST 10 | 1 | hard bounce suppression (email suprimido → no enviar) | ✅ |
| TEST 11 | 1-7 | TEST/REAL isolation (TEST nunca en comercial) | ✅ |
| TEST 12 | 1 | deterministic A/B/C (misma combinación → misma variante) | ✅ |
| TEST 13 | 5 | batch checkpoint (`READY TO SEND`/`BLOCKED`) | ✅ |
| TEST 14 | 1-7 | backup + migration integrity (rollback verificable) | ✅ |

**Regla:** los tests 01, 07, 08, 09 y 14 no requieren conexión SMTP ni envío REAL. Los tests 02-06, 10-13 se ejecutan sobre datos TEST o copia local de `stats.db`, nunca sobre producción real.

---

## 8. RIESGOS PREVISTOS Y MITIGACIÓN

| # | Riesgo | Fase | Mitigación |
|---|---|---|---|
| 1 | Reenvío a hard bounces (riesgo reputacional) | 1 | Supresión en `eligibilidad.php` consultando `rebotes` + `respuestas.es_rebote=1` |
| 2 | `From` inválido → rechazos (Yahoo ya rebotó) | 1 | RFC 2047 + validación RAW MIME antes de cualquier envío |
| 3 | A/B/C aleatorio si la UI no usa backend | 1 | Sustituir `Math.random()` en `js/app.js:1752,1793`; endpoint de `asignarVariante()` |
| 4 | Seguir generando follow-ups huérfanos | 2 | Obligar metadatos; prohibir atribución por `subject LIKE 'Re:%'` |
| 5 | Aperturas infladas distorsionan open rate | 3 | Vista SQL dedup; bruto intocable |
| 6 | Romper `api/presupuestos.php` o `api/mockups.php` | 4 | ALTER aditivo, conservar `pipeline_id`; regla de compatibilidad (buscar referencias) |
| 7 | Activar FK globalmente → efectos secundarios | 1-7 | NO activar `foreign_keys`; deuda técnica separada (regla 6.9) |
| 8 | Envíos sin autorización | 5-6 | Checkpoint + confirmación explícita; `motor_estado` sigue `pausado` hasta autorización |

---

## 9. ROLLBACK PREVISTO

- **Por fase:** cada migración se registra en `_migraciones`; las ALTER ADD COLUMN son reversibles restaurando el backup previo de esa fase.
- **Regla:** nunca borrar el backup anterior inmediatamente; conservar al menos el backup de la fase previa.
- **Procedimiento estándar:** restaurar `stats.db` desde el backup verificado de la fase, ejecutar `integrity_check` y verificar conteos y TEST/REAL.
- **Primer backup obligatorio:** al iniciar FASE 1, crear `stats.db.bak_fase1_<fecha_hora>` del estado actual (470 envíos) antes de la primera migración.

---

## 10. DEPENDENCIAS Y ORDEN DE EJECUCIÓN

Cadena estricta con gate de autorización entre fases (regla 3.5 del MEGAPROMPT V2):

```
FASE 0 (completada, pendiente aprobación)
   ↓ AUTORIZACIÓN de Rodrigo
FASE 1 — Bloqueantes
   ↓ AUTORIZACIÓN
FASE 2 — Trazabilidad
   ↓ AUTORIZACIÓN
FASE 3 — Tracking
   ↓ AUTORIZACIÓN
FASE 4 — Operativa comercial
   ↓ AUTORIZACIÓN
FASE 5 — Checkpoint
   ↓ AUTORIZACIÓN
FASE 6 — Primer lote (batch_size ∈ [200,300])
   ↓ ANÁLISIS + AUTORIZACIÓN
FASE 7 — Escalado progresivo
```

**Regla de frontera y no-invención de trabajo:**
- Ninguna fase comienza sin PASS de la anterior y sin autorización explícita.
- Si tras una fase el sistema ya permite enviar de forma segura y trazable, **no se añade trabajo solo porque esté contemplado**: las fases posteriores se ejecutan solo si aportan valor demostrable a la campaña.
- **DETENERSE** tras este plan: esperar aprobación explícita para FASE 1.

---

*Fin del plan de instrumentación V2 · 2026-08-29 · FASE 0 completada en modo solo lectura (ningún dato modificado).*

---
