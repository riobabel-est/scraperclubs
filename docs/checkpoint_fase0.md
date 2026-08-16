# CHECKPOINT — FASE 0 (FutProtec CRM Outbound)

**FECHA:** 2026-08-14 20:02 (Europe/Madrid)
**ALCANCE:** Solo inspección + backup. NO se ha modificado ningún archivo del sistema.
**MODO:** Lectura estricta. Ningún envío real. Ninguna corrección aplicada.

---

## FASE: 0
## OBJETIVO: Backup verificable + inventario real (envío, tablas, relaciones) + identificación de discrepancias. Sin corregir.

---

## 1. QUÉ HAS INSPECCIONADO
- Esquema real de BD (SQLite `public_html/outbound/data/stats.db`) volcado en modo solo-lectura.
- Contenido y recuentos de todas las tablas.
- 4 scripts/endpoints con capacidad de envío SMTP.
- Flujo de la lanzadera (UI `js/app.js` → `api/enviar_lote.php`).
- Flujo standalone (`api/enviar_smtp_random.php`).
- Flujo cron (`cli/cron.php`).
- Endpoints auxiliares: `track.php`, `baja.php`, `get_cola.php`, `smtp.php`, `leads.php`.
- Lógica de asignación de variante A/B/C y de ganadora en `dashboard.php` / `analytics.php`.
- Backup de BD + archivos relevantes (ver §9).

---

## 2. QUÉ HAS ENCONTRADO
Estado real (datos):
- `clubes_crm`: **1813** filas. Distribución estados: `01 Sin Contactar` = 1812, `03 Respondió` = 1. (No hay recorrido comercial real.)
- `envios`: **2** filas (ambas `estado='enviado'`). Sin columna `variante`/`pipeline_id`/`plantilla_id`/`lead_id`.
- `comunicaciones_log`: **25** filas (2 `envio_email` con `variante_ab='A'`; el resto `cambio_estado` manuales de pruebas).
- `aperturas`: **0**. `rebotes`: **0**. `mockups`: **0**. `presupuestos`: **0**.
- `lead_pipelines`: **5** filas TEST (variantes A/B/C), sin código que las alimente.
- `pipelines`: **1** fila: `Experimento Fase 1 TEST` (`activo=1`, `variante_ganadora=NULL`).
- `cuentas_smtp`: **10** cuentas activas (`limite_diario=50`). Credenciales en BD y en fallback hardcodeado de `enviar_smtp_random.php`.
- `config`: `modo_entorno=test`, `motor_estado=pausado`, `email_test`, `test_emails` (multivalor), `delay_envio=3`, `lote_envio=10`, `lanzadera_delay=8`.

Conclusiones clave (detalle en §8):
1. La trazabilidad **campaña→envío→variante→plantilla→SMTP** está **rota**: `envios` no guarda variante, campaña/pipeline, plantilla ni lead_id; se une por `email` (frágil, se rompe con reenvíos).
2. La variante **se sortea en el momento del envío** (`Math.random()` cliente para lanzadera; `mt_rand()` para standalone) y **no se persiste previamente** en `lead_pipelines`. Un reintento puede cambiar de variante.
3. El dashboard A/B/C lee de `lead_pipelines` (5 filas TEST) que **no se alimenta con envíos reales**.
4. No existe clasificación explícita de respuesta (POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO). "Respuesta positiva" se deriva de `estado_lead >= 5`.
5. La supresión (`Lista Negra`) **no se respeta en el momento del envío** en ninguno de los puntos de envío.
6. Criterio de ganadora = `leads >= 5` por variante (simplista, explícitamente prohibido por requisito).

---

## 3. ARCHIVOS REVISADOS
- `public_html/outbound/cli/init_db.php`
- `public_html/outbound/cli/cron.php`
- `public_html/outbound/api/enviar_lote.php`
- `public_html/outbound/api/enviar_smtp_random.php`
- `public_html/outbound/api/get_cola.php`
- `public_html/outbound/api/smtp.php`
- `public_html/outbound/api/leads.php`
- `public_html/outbound/api/track.php`
- `public_html/outbound/api/baja.php`
- `public_html/outbound/dashboard.php`
- `public_html/outbound/tabs/analytics.php`
- `public_html/outbound/js/app.js`

---

## 4. TABLAS REVISADAS
| Tabla | Filas | Rol | Estado |
|---|---|---|---|
| `_migraciones` | 1 | Registro DDL fase 0 (`fase0_migracion_ddl.py`) | PASS (legacy) |
| `aperturas` | 0 | Aperturas píxel (FK `tracking_id`) | VACÍA |
| `clubes_crm` | 1813 | Leads | PASS |
| `comunicaciones_log` | 25 | Timeline eventos (`variante_ab` presente) | PASS (con limitaciones) |
| `config` | 7 | Config global | PASS |
| `cuentas_smtp` | 10 | Cuentas SMTP | PASS |
| `envios` | 2 | Envíos (sin variante/campaña/plantilla) | PASS WITH LIMITATIONS |
| `lead_pipelines` | 5 | N:M lead↔pipeline↔variante | NO ALIMENTADA |
| `mockups` | 0 | Propuestas de diseño | VACÍA |
| `pipelines` | 1 | Campañas/pipelines | PASS (1 TEST) |
| `plantillas` | 7 | Plantillas (A/B/C cuerpos) | PASS |
| `plantillas_new` | 0 | Tabla huérfana duplicada | DISCREPANCIA |
| `presupuestos` | 0 | Presupuestos | VACÍA |
| `rebotes` | 0 | Rebotes | VACÍA |
| `snapshots` | 2 | Snapshots funnel | PASS |

---

## 5. RELACIONES DETECTADAS
```
LEAD (clubes_crm.id)
  ├─[N:M]→ PIPELINE (pipelines) vía lead_pipelines (lead_id, pipeline_id, variante_ab)  ← NO alimentado en runtime
  ├─[email join, sin FK]→ ENVÍO (envios.email)
  │      ├─[FK tracking_id]→ APERTURA (aperturas.tracking_id)
  │      └─[email join, sin FK]→ REBOTE (rebotes.email)   ← no hay FK
  ├─[lead_id/club_id, sin FK]→ COMUNICACIÓN (comunicaciones_log)
  │      └─ variante_ab / plantilla_id / id_cuenta_smtp / pipeline_id (pipeline_id siempre NULL)
  ├─[FK lead_id]→ MOCKUP (mockups.lead_id, pipeline_id NULL)
  └─[FK lead_id]→ PRESUPUESTO (presupuestos.lead_id, pipeline_id NULL)

PLANTILLA (plantillas.id)  ← independiente, sin FK desde envios
CUENTA_SMTP (cuentas_smtp.id) ← independiente, sin FK desde envios
```
**Roturas de trazabilidad:**
- `envios` NO tiene `lead_id`, `pipeline_id`, `plantilla_id`, `variante_ab`. Se relaciona con lead solo por `email`.
- `comunicaciones_log.pipeline_id` existe pero **siempre NULL** (ningún código lo escribe).
- `mockups.pipeline_id` y `presupuestos.pipeline_id` existen pero **siempre NULL**.

---

## 6. PUNTOS DE ENVÍO IDENTIFICADOS

### P1. `api/enviar_lote.php` (LANZADERA — activo)
- **Función:** envío individual desde la lanzadera (POST `id_club`, `id_plantilla`, `id_cuenta_smtp`, `variante_ab`).
- **Activo:** SÍ (endpoint web, usado por `js/app.js`).
- **Cómo se ejecuta:** HTTP POST desde UI (`iniciarMotor()` y `enviarCorreoPrueba()`).
- **Puede enviar realmente:** SÍ (socket SMTP autenticado `enviarSMTPAutenticado`).
- **SMTP:** cuenta indicada por POST, validada `activa=1` y límite diario.
- **Selección destinatarios:** por `id_club` individual.
- **Respeta campaña:** NO (no maneja `pipeline_id`).
- **Respeta supresión:** **NO** (no filtra `Lista Negra`/`Opt-Out` antes de enviar).
- **Registra envío:** SÍ (`envios` + `comunicaciones_log`).
- **Registra variante:** SÍ (`comunicaciones_log.variante_ab`), NO en `envios`.
- **Registra tracking:** SÍ (píxel + `tracking_id`).
- **Puede generar duplicados:** SÍ (sin verificación de envío previo al club).

### P2. `api/enviar_smtp_random.php` (STANDALONE CLI — activo, bloqueo comentado)
- **Función:** lote desde `clubes.json` (CLI, `--lote`, `--delay`, `--resume`, `--test`).
- **Activo:** SÍ (el `die()` de bloqueo está **comentado**; script ejecutable).
- **Cómo se ejecuta:** `php api/enviar_smtp_random.php [--lote N --delay N --resume --test]`.
- **Puede enviar realmente:** SÍ (socket SMTP `enviarSMTPAutenticado`; `mail()` como fallback no usado en el bucle, queda definido).
- **SMTP:** rotación aleatoria `obtenerCuentaSMTP()`; fallback hardcodeado `$CUENTAS_SMTP_FALLBACK` con credenciales.
- **Selección destinatarios:** `clubes.json` (NO la BD `clubes_crm`).
- **Respeta campaña:** NO.
- **Respeta supresión:** **NO** (lee `clubes.json` directo).
- **Registra envío:** SÍ (`envios`), **NO** en `comunicaciones_log`.
- **Registra variante:** **NO** (sortea A/B/C pero no la guarda en ningún sitio).
- **Registra tracking:** SÍ.
- **Puede generar duplicados:** SÍ (sin `--resume`, reenvía todo).

### P3. `cli/cron.php` (CRON — activo si motor `activo`)
- **Función:** 1 lead por ejecución (CLI).
- **Activo:** SÍ (solo si `config.motor_estado='activo'`; hoy `pausado`).
- **Cómo se ejecuta:** `php cron.php` (solo CLI).
- **Puede enviar realmente:** SÍ (socket SMTP `enviarSMTP`; `mail()` fallback).
- **SMTP:** cuenta `enviados_hoy < limite` (menor uso primero).
- **Selección destinatarios:** 1 lead `estado_lead='01 Sin Contactar'` sin envío previo.
- **Respeta campaña:** NO.
- **Respeta supresión:** **NO** (no comprueba `Lista Negra`).
- **Registra envío:** SÍ (`envios` + `comunicaciones_log`), cambia estado.
- **Registra variante:** **NO** (no soporta A/B/C).
- **Registra tracking:** SÍ (pero URL apunta a `/outbound/track.php`, sin `api/` — posible ruta rota).
- **Puede generar duplicados:** NO (verifica no envío previo por email).

### P4. `api/smtp.php` (solo test de conexión)
- **Función:** `test_smtp` autentica contra el servidor SMTP (EHLO/AUTH). **NO envía email** a destinatario.
- **Puede enviar realmente:** NO (solo prueba de conexión/autenticación).

**Otras rutas con `stream_socket_client`/`mail()`:** ninguna adicional (búsqueda sobre `public_html/outbound/*.php`, excluyendo `backups/`).

**Mecanismo de baja:** `api/baja.php` (público) fija `estado_lead='Lista Negra'`. `get_leads_table` sí excluye Lista Negra, pero **los puntos de envío no la consultan**.

---

## 7. RIESGOS
1. **Trazabilidad rota** — imposible reconstruir envío→variante→campaña de forma unívoca con reenvíos.
2. **Cambio de variante entre reintentos** — viola regla de inmutabilidad de variante.
3. **Dashboard A/B/C sobre datos de prueba** — conclusiones falsas si se usa en piloto.
4. **Supresión no aplicada al enviar** — riesgo legal/comercial (enviar a bajas/Lista Negra).
5. **Doble vía de envío** (lanzadera + standalone) sin coordinación → duplicados.
6. **Credenciales hardcodeadas** en `enviar_smtp_random.php` (`$CUENTAS_SMTP_FALLBACK`) y en `init_db.php`.
7. **`cron.php` tracking URL** usa `/outbound/track.php` (sin `api/`) → posible 404.
8. **Estado global único (`estado_lead`)** — un lead no puede llevar histórico por campaña.

---

## 8. DISCREPANCIAS (documentación vs código vs BD vs comportamiento)
| # | Documentación dice | Realidad (código/BD) | Estado |
|---|---|---|---|
| D1 | A/B/C asignado round-robin y persistido en `lead_pipelines` | `Math.random()` cliente (lanzadera) / `mt_rand()` (standalone), `lead_pipelines` sin código que la alimente | **DOC>=CÓDIGO** |
| D2 | Variante guardada en `envios.variant` (plan de reparación) | `envios` NO tiene columna `variant`; variante solo en `comunicaciones_log.variante_ab` | **DOC≠BD** |
| D3 | Dashboard A/B/C operativo | Lee `lead_pipelines` (5 filas TEST) desconectada de envíos | **CÓDIGO≠COMPORTAMIENTO** |
| D4 | `lead_pipelines.variante_ab` guarda asignación definitiva | 5 filas prueba; no actualizada por envíos | **BD≠COMPORTAMIENTO** |
| D5 | Supresión implementada (baja.php + filtros) | Baja fija `Lista Negra`, pero envío no la consulta | **COMPORTAMIENTO≠DOC** |
| D6 | `plantillas` como tabla única | Existe `plantillas_new` huérfana (0 filas) | **DISCREPANCIA esquema** |
| D7 | Respuesta positiva "clasificada" | Se deriva de `estado_lead>=5`, no de clasificación explícita | **DOC≠CÓDIGO** |

---

## 9. BACKUP REALIZADO
- **Ruta:** `public_html/outbound/backups/fase0_20260814_195950/`
- **Contenido:** `data/stats.db` (y `data/`), `api/`, `cli/`, `tabs/`, `js/`, `dashboard.php`, `.htaccess`, `.htrouter.php`.
- **Verificación:** md5 `stats.db` origen == backup = `4d0e93187076184c9da54aea3b17462f`; 16 tablas legibles en copia; WAL 0 bytes (sin transacciones pendientes).
- **Rollback:** restaurar copiando `stats.db` + archivos sobre las rutas originales.

---

## 10. TESTS REALIZADOS
- Volcado SQLite solo-lectura (`mode=ro`) del esquema y datos — **PASS**.
- Recuento de tablas y distribución de `estado_lead` — **PASS**.
- Búsqueda de puntos de envío (`mail(`, `stream_socket_client`, `fsockopen`) — **PASS** (4 archivos).
- Búsqueda de INSERT en `pipelines`/`lead_pipelines` — **PASS** (no hay código que inserte).
- Búsqueda de `variante` en JS/PHP — **PASS** (asignación cliente + standalone).
- **No se ejecutó `init_db.php`, `enviar_lote.php`, `enviar_smtp_random.php`, `cron.php` ni ningún envío.** — **PASS (abstención deliberada).**

---

## 11. RESULTADO POR PUNTO
| Ítem | Estado |
|---|---|
| Backup verificable | PASS |
| Inventario puntos de envío | PASS |
| Inventario tablas | PASS |
| Mapa de relaciones | PASS WITH LIMITATIONS (Fks rotas) |
| Identificación discrepancias | PASS |
| Variante A/B/C inmutable y persistida pre-envío | FAIL (no implementado) |
| Separación TEST/PILOTO/PROD inequívoca | FAIL (solo `modo_entorno` test/produccion; sin "piloto") |
| Inmutabilidad del experimento (envío inmutable) | NOT VERIFIED (no hay mecanismo de bloqueo; nada lo impide vía SQL directo) |
| Clasificación explícita POSITIVE/NEGATIVE/NEUTRAL/UNSUB/OOO | FAIL (no existe) |
| Dashboard con n_A/n_B/n_C y muestra insuficiente | FAIL (solo % y umbral simplista `>=5`) |
| Respeta supresión en envío | FAIL |
| Reconstrucción individual Club→Campaña→Variante→…→Resultado | FAIL (vínculos rotos) |

---

## CONCLUSIÓN DE FASE 0
El CRM tiene una capa de negocio (Kanban, pipelines, presupuestos, mockups) parcialmente modelada en BD pero **desconectada de la operación real de envío**. No existe trazabilidad fiable envío→variante→campaña, la variante no es inmutable, la clasificación de respuesta positiva no está implementada y la métrica A/B/C se calcula sobre datos de prueba. El backup verificable se ha realizado. Las discrepancias quedan documentadas y **no corregidas**, conforme a las reglas de FASE 0.

---

## SIGUIENTE ACCIÓN PROPUESTA: FASE 1
Definir (sobre esta base) el modelo de datos mínimo de trazabilidad y el rediseño del mecanismo de asignación A/B/C (persistencia previa e inmutable en el registro de envío), sin tocar todavía los archivos del sistema.

> NO ejecuto FASE 1. Espero confirmación.