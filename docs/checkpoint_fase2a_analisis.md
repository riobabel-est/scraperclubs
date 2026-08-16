# CHECKPOINT — FASE 2A: ANÁLISIS DE SUPRESIÓN + IDEMPOTENCIA + CONTROL DE MOTORES

**FECHA:** 2026-08-14
**ALCANCE:** Solo análisis. NO se modifica código todavía. No envíos.

---

## 1. ARQUITECTURA ACTUAL DE LOS 3 MOTORES

### P1 — `api/enviar_lote.php` (lanzadera, HTTP POST)
- **Obtiene destinatario:** por `id_club` (POST), `SELECT ... FROM clubes_crm WHERE id = {idClub}`.
- **Fuente:** `clubes_crm`.
- **Identifica lead:** por `clubes_crm.id` (solo para validar y para `comunicaciones_log.lead_id`), pero al insertar en `envios` NO guarda `lead_id`; guarda `email`/`club`.
- **Comprueba si ya fue enviado:** NO.
- **Comprueba Lista Negra:** NO.
- **Comprueba opt-out:** NO.
- **Comprueba envíos anteriores:** NO.
- **Registra resultado:** `envios` (estado enviado/error) + `comunicaciones_log` (`envio_email`, `variante_ab`, `id_cuenta_smtp`, `plantilla_id`). NO registra `campaign_id`/`pipeline_id`.
- **Ante retry:** el frontend `iniciarMotor()` re-llama por lead; como no hay guard, **puede duplicar**.
- **Ante timeout:** `fetch` con `signal`; si aborta, no hay marca de estado; un reintento reintenta el mismo lead.
- **Ante error SMTP:** registra estado `'error'` y continúa; no impide reintento.
- **Concurrencia:** dos POST simultáneos al mismo `id_club` generarían dos filas `envios` (sin restricción).

### P2 — `api/enviar_smtp_random.php` (standalone CLI)
- **Obtiene destinatario:** de `clubes.json` (NO de `clubes_crm`).
- **Fuente:** `clubes.json`.
- **Identifica lead:** NO usa `clubes_crm.id`; solo `email` del JSON.
- **Comprueba si ya fue enviado:** solo si `--resume` (filtra `email` presente en `envios`); sin `--resume` no.
- **Comprueba Lista Negra:** NO.
- **Comprueba opt-out:** NO.
- **Comprueba envíos anteriores:** SOLO con `--resume` (por email).
- **Registra resultado:** SOLO en `envios` (NO en `comunicaciones_log`). NO registra variante/campaña/lead_id.
- **Ante retry:** sin `--resume`, reenvía todo.
- **Ante timeout:** sin manejo de timeout de conexión explícito (solo `30s` en socket); no hay checkpoint por lead.
- **Ante error SMTP:** registra `envios.estado='error'` y continúa.
- **Concurrencia:** dos procesos CLI simultáneos leerían el mismo JSON → duplicados.

### P3 — `cli/cron.php` (cron, 1 lead)
- **Obtiene destinatario:** `SELECT c.* FROM clubes_crm c ... WHERE estado_lead='01 Sin Contactar' AND email != '' AND NOT EXISTS (envio enviado por email) LIMIT 1`.
- **Fuente:** `clubes_crm`.
- **Identifica lead:** por `clubes_crm.id` (para `comunicaciones_log.lead_id`), pero `envios` guarda email/club sin `lead_id`.
- **Comprueba si ya fue enviado:** SÍ (LEFT JOIN `envios` con `estado='enviado'` por email).
- **Comprueba Lista Negra:** INDIRECTAMENTE (solo selecciona `estado_lead='01 Sin Contactar'`, que es mutuamente excluyente con `Lista Negra`).
- **Comprueba opt-out:** INDIRECTAMENTE (mismo motivo).
- **Comprueba envíos anteriores:** SÍ (por email + estado).
- **Registra resultado:** `envios` + `comunicaciones_log`. NO registra variante/campaña.
- **Ante retry:** no reintenta el mismo lead (ya no cumple `estado_lead='01 Sin Contactar'` hasta que vuelva a ese estado).
- **Ante error SMTP:** no inserta en `envios`; marca error en cuenta SMTP; el lead sigue en cola.
- **Concurrencia:** dos `cron.php` simultáneos → ambos SELECT devuelven el mismo lead antes de que ninguno haga UPDATE → posible duplicado (ventana TOCTOU).

---

## 2. FUENTE ÚNICA DE DESTINATARIOS
**Verificado numéricamente:**
- `clubes_crm`: 1813 filas, 1813 emails únicos.
- `clubes.json`: 1870 entradas, 1807 emails únicos (63 emails duplicados internamente).
- Emails presentes en JSON pero ausentes en CRM: **0**.
- Emails presentes en CRM pero ausentes en JSON: **6** → `test01..05@futprotec.local` (5 leads TEST) + `info@fsnazareno.es` (1 real).

**Conclusión de riesgo:**
- `clubes.json` **no contiene leads fuera de CRM** (no hay fuga de destinatarios externos), pero es una **instantánea desincronizada y con duplicados internos**.
- El riesgo real de P2 es de **bypass de reglas**: al leer JSON, ignora supresión, campaña, `lead_id` y trazabilidad. Puede enviar a un lead que en CRM ya esté dado de baja (la baja en CRM no se refleja en JSON).
- Objetivo arquitectónico: **CLUBES_CRM → filtros → cola/campaña → envío**. P2 debe migrar a leer de `clubes_crm` (o desactivarse) para no crear una segunda fuente de verdad.

---

## 3. SUPRESIÓN
Estados de supresión/equivalentes identificados en código y BD:
- `Lista Negra` (escrito por `baja.php`).
- `Opt-Out`, `Unsubscribed`, `Baja / Opt-Out` (tratados en `leads.php get_leads_table` y en `dashboard.php`).
- `Email Inválido`, `Perdido` (excluidos en `get_leads_table`; `Perdido` es estado comercial, no supresión estricta).

**Estado actual en BD: 0 registros en cualquiera de esos estados** (las 1813 filas están en `01 Sin Contactar` y `03 Respondió`). La regla de supresión es preventiva.

**Propuesta mínima:** una única consulta central reutilizable `esElegible(campaign_id, lead_id)` que:
1. Descarte `estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')`.
2. Descarte `es_duplicado = 1`.
3. Descarte leads cuyo `email` no pase `filter_var`.
4. (TEST 8) Descarte leads TEST cuando el `entorno` de la campaña ≠ `test`.

---

## 4. BAJA (`baja.php`)
- Comportamiento actual: `UPDATE clubes_crm SET estado_lead='Lista Negra', observaciones += 'Baja automática...' WHERE email=:email`.
- Idempotente: SÍ (re-ejecutar deja el mismo estado; solo añade otra línea de observación). **Mejora posible (fuera de alcance):** no apilar observaciones repetidas.
- No rompe el lead: conserva email y resto de columnas.
- Queda registrada: solo en `observaciones`; no hay un `comunicaciones_log`. **Nota:** no registra evento de baja en timeline (a documentar, no bloquear).
- Bloquea siguiente envío: SÍ, siempre que los motores apliquen la regla central (hoy solo cron la aplica indirectamente; P1/P2 no).

---

## 5. IDEMPOTENCIA
- Necesitamos impedir **mismo lead + misma campaña → duplicado**, sin impedir **nuevas campañas**.
- Identificadores disponibles (ya creados en FASE 1): `envios.lead_id` + `envios.campaign_id`.
- Solución mínima propuesta: índice único **parcial**:
  `CREATE UNIQUE INDEX idx_envios_lead_campaign ON envios(lead_id, campaign_id) WHERE campaign_id IS NOT NULL;`
  - SQLite ignora NULL en índices únicos, por tanto las 2 filas legacy (`campaign_id=NULL`) no entran en conflicto.
  - Para envíos reales con `campaign_id` no nulo, garantiza un único envío por (lead, campaña).
- Reintento de un envío: el retry debe **detectar el envío existente** (INSERT OR IGNORE / capturar UNIQUE) y reutilizarlo, sin crear duplicado. No re-sortear variante (eso queda para Fase 3, pero el esqueleto de campos ya lo permite).

---

## 6. CONCURRENCIA
- SQLite es single-writer con WAL; la única garantía real de idempotencia ante dos procesos es la **restricción UNIQUE** del punto 5, no un `SELECT` previo.
- `INSERT OR IGNORE` / captura de `UNIQUE constraint` permiten comportamiento correcto ante carrera: solo una fila sobrevive.
- `busy_timeout` ya está configurado (5000ms en los tres motores) → reduce error de "database is locked".
- No se requiere tabla de colas compleja; el índice único resuelve la carrera.

---

## 7. LOS TRES MOTORES (estado tras análisis)
| Motor | Puede cumplir supresión+idempotencia con cambios mínimos | Estado |
|---|---|---|
| P1 `enviar_lote.php` | SÍ — añadir guard central + escribir `lead_id`/`campaign_id`/`variant`/`plantilla_id`/`smtp_id` | LISTO PARA PASE 2B |
| P2 `enviar_smtp_random.php` | PARCIAL — lee `clubes.json`, no `clubes_crm`; su rediseño requiere cambiar fuente de datos o desactivarlo | BLOCKED (requiere decisión de fuente) |
| P3 `cron.php` | SÍ — añadir guard central (ya filtra estado) + escribir nuevos campos | LISTO PARA PASE 2B |

P2 queda **BLOCKED** hasta decidir si se migra a `clubes_crm` o se desactiva (por bypass de supresión/campaña/trazabilidad).

---

## 8. MODO TEST
- Campañas ya tienen `entorno` (test/pilot/production) en `pipelines`.
- **TEST 8:** un lead TEST no debe entrar en PILOT. La regla central debe comparar la naturaleza del lead (nombre/email `TEST`) o, mejor, la procedencia de la asignación, con `campaign.entorno`. Mínimo: la consulta de elegibilidad incluirá `NOT (lead es TEST)` cuando `campaign.entorno IN ('pilot','production')`.

---

## 9. NO IMPLEMENTAR (permanece fuera de alcance)
A/B/C, dashboard, Positive Reply Rate, click tracking, IMAP, rebotes, clasificación de respuestas.

---

## 10. `init_db.php` — esquema a reproducir (documentado, sin ejecutar)
Para que una BD limpia sea equivalente, `init_db.php` deberá añadir:
- `pipelines`: `identificador TEXT`, `estado TEXT DEFAULT 'DRAFT'`, `entorno TEXT DEFAULT 'test'`, `tipo TEXT DEFAULT 'outbound'`, `objetivo INTEGER`.
- `envios`: `lead_id INTEGER`, `campaign_id INTEGER`, `variant VARCHAR(1)`, `plantilla_id INTEGER`, `smtp_id INTEGER`.
- Índices: `idx_pipelines_identificador` (parcial UNIQUE), `idx_envios_lead`, `idx_envios_campaign`, `idx_envios_variant`.
- (Pendiente de Fase 2B) `idx_envios_lead_campaign` UNIQUE parcial.

---

## 11. TABLAS AFECTADAS (propuesta mínima Fase 2B)
- `envios` (índice único parcial nuevo; sin nuevas columnas).
- `clubes_crm` (solo lectura en la regla de elegibilidad).
- `pipelines` (lectura de `entorno`).
- `baja.php` (opcional: registrar evento de baja en `comunicaciones_log`; no obligatorio para el core).

## 12. FUNCIONES A MODIFICAR (propuesta Fase 2B)
- Añadir helper central de elegibilidad (en un include compartido o función duplicada mínima, a decidir): `esElegibleParaEnvio(SQLite3 $db, int $leadId, int $campaignId)`.
- P1 y P3: invocar el guard + escribir los 5 campos nuevos en `envios`.
- P2: decidir fuente (`clubes_crm`) o desactivación.

## 13. RIESGOS
1. P2 (JSON) puede bypassear supresión/campaña/trazabilidad → BLOCKED.
2. Carrera TOCTOU sin índice único.
3. Estados de supresión aún vacíos → la regla no se ha ejercitado con datos reales; tests sintéticos necesarios.
4. `init_db.php` desincronizado (deuda ya registrada).

## 14. PLAN DE TESTS (FASE 2B, sintético, sin envío real)
- T1 Lead normal → elegible.
- T2 Lead Lista Negra → bloqueado.
- T3 Lead dado de baja tras ser elegible → siguiente bloqueado.
- T4 Mismo lead + misma campaña + 2º intento → bloqueado/reutiliza.
- T5 Mismo lead + campaña distinta → no bloqueado.
- T6 Dos intentos concurrentes → un solo envío lógico (índice único).
- T7 Los tres motores producen la misma decisión (regla central).
- T8 Lead TEST no entra en PILOT.

---

## CONCLUSIÓN FASE 2A
La arquitectura actual tiene una **divergencia de fuentes** (P2 usa `clubes.json`), ausencia de **regla central de elegibilidad/supresión**, ausencia de **idempotencia a nivel BD** y riesgo de **carrera** en los tres motores. La solución mínima reutiliza las columnas ya creadas en FASE 1: una consulta central de elegibilidad + un índice único parcial `(lead_id, campaign_id)` donde `campaign_id IS NOT NULL`, aplicado a P1 y P3, y una decisión sobre P2 (migrar a `clubes_crm` o desactivar).

> NO ejecuto FASE 2B. Espero aprobación explícita.