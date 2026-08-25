# COMPENDIO DE PENDIENTES — Plataforma `public_html/outbound/`

**Fecha de consolidación:** 2026-08-25
**Ámbito:** Módulo outbound del CRM FutProtec (PHP 8 + SQLite + JS vanilla, SiteGround)
**Propósito:** Único documento que consolida TODO lo pendiente (refactors, mejoras, seguridad, auditoría y plan activo).
**Estado verificado contra el código y la BD en la fecha indicada.**

> Los documentos de origen se conservan (`docs/REFACTORIZACIONES_PENDIENTES.md`,
> `docs/FUTURE_IMPROVEMENTS.md`, `docs/informe_auditoria_bugs_20260825.md`,
> `docs/CONFIGURACION_SEGURIDAD.md`) pero **este es el compendio de referencia**.

---

## 1. REFACTORS PENDIENTES (de REFACTORIZACIONES_PENDIENTES.md)

| ID | Ítem | Estado | Prioridad | Estimación | Notas |
|---|---|---|---|---|---|
| R-1 | `inc/eligibilidad.php` §6.3: separar SQL de lógica | ✅ **RESUELTO** (2026-08-25, test 20/20) | — | ~1-2h hecho | `checkpoint_refactor_eligibilidad.md` |
| R-2 | `inc/mime.php` §6.1: extraer `construirMensajeMIME()` como función pura | ✅ **RESUELTO de facto** (2026-08-25): la construcción MIME ya vive en `inc/smtp_transport.php` (transporte centralizado); `mime.php` solo delega | — | — | Verificado |
| R-3 | Dividir monolitos: `inc/imap_respuestas.php` (1553 lín.), `api/leads.php` (1186), `cli/init_db.php` (806) | 🔴 Pendiente | Media-Baja | 3-5h | El principal candidato es imap_respuestas |
| R-4 | Colisiones de funciones sin `function_exists` | ✅ **RESUELTO** (2026-08-25): guardas en `inc/mime.php` (2), `api/imap_sync.php`, `cli/imap_respuestas_cron.php`, `api/enviar_lote.php`, `cli/cron.php`. `php -l` OK | — | ~1h hecho | Funciones duplicadas reales cubiertas |
| R-5 | SQL interpolado → prepared statements en endpoints de escritura (`leads.php`, `smtp.php`, `analytics.php`) | 🟡 Mejora | Baja | 2-3h | Sin SQLi confirmada (IDs (int) + escapeString) |

## 2. MEJORAS POSPUESTAS (de FUTURE_IMPROVEMENTS.md — ACTUALIZADO 2026-08-25)

| ID | Ítem | Estado | Prioridad |
|---|---|---|---|
| FI-001 | Eliminar tabla huérfana `plantillas_new` | ✅ **RESUELTO** (2026-08-25): verificada vacía y eliminada (`DROP`) | — |
| FI-002 | Eliminar credenciales SMTP duplicadas hardcodeadas (`$CUENTAS_SMTP_FALLBACK` en `enviar_smtp_random.php`, `$cuentasDefault` en `init_db.php`) | 🔴 **Parcial**: script bloqueado con `die()`, pero el código sigue en el repo | Media |
| FI-003 | Corregir URL de tracking en `cli/cron.php` | ✅ **RESUELTO** (ya usa `/outbound/api/track.php`) | — |
| FI-004 | Unificar los 3 motores de envío SMTP | ✅ **RESUELTO** (refactor §2: `inc/smtp_transport.php`) | — |
| FI-005 | Atomicidad de `cuentas_smtp.enviados_hoy` (recuento real unificado) | 🔴 Pendiente | Media |
| FI-006 | Histórico de estados Kanban por campaña (`lead_pipelines` sin uso) | 🔴 Pendiente | Alta (post-piloto) |
| FI-007 | Plantillas versionadas inmutables (no sobrescribir `save_template`) | 🔴 Pendiente | Alta (post-piloto) |
| FI-008 | Índices y saneamiento de esquema (FK por email, índices redundantes, `snapshots`) | 🔴 Pendiente | Baja |
| FI-009 | Evolution API: evaluar integración WhatsApp automatizado | ⏸️ Evaluación de producto | Futura |

## 3. DEUDA DE SEGURIDAD (de CONFIGURACION_SEGURIDAD.md §6)

| ID | Ítem | Estado |
|---|---|---|
| S-1 | `$CUENTAS_SMTP_FALLBACK` con credenciales en claro dentro de `enviar_smtp_random.php` | ✅ **RESUELTO** (2026-08-25): array vaciado (0 credenciales en claro), `die()` de seguridad intacto. Pendiente FI-002 `$cuentasDefault` en `init_db.php` |
| S-2 | AUTH_KEY dashboard, tokens runners, CSRF, API keys IA | ✅ **RESUELTO** (2026-08-25, centralizados/cifrados) |

## 4. AUDITORÍA DE BUGS (de informe_auditoria_bugs_20260825.md)

| ID | Ítem | Estado |
|---|---|---|
| A-1 | Monolitos (imap_respuestas/leads/init_db) | 🔴 Duplicado con R-3 (ver §1) |
| A-2 | Basura temporal en la raíz: `inspect_db.php`, `tmp_schema_check2/3/4.txt` | 🔴 Pendiente (limpiar) |
| A-3 | `$CSRF_SECRET` débil (derivado de ruta) | ✅ RESUELTO (movido a secret.php) |
| A-4 | SQL injection / XSS | ✅ No detectado en flujos revisados |

## 5. PLAN ACTIVO PENDIENTE DE APROBACIÓN

| ID | Ítem | Estado |
|---|---|---|
| P-1 | **Configurador de Campañas + Categorías de Plantillas** (Fases F0-F6 en `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md`) | ⏸️ Pendiente de OK del usuario (~6-8h) |
| P-2 | Actualizar `FUTURE_IMPROVEMENTS.md` (eliminar FI-003/FI-004 resueltos) o dejar de mantenerlo (este compendio lo sustituye) | 🔴 Pendiente menor |

---

## 6. RESUMEN EJECUTIVO (orden sugerido)

1. **Limpieza rápida (30 min):** A-2 (basura temporal) + FI-001 (plantillas_new) + R-4 (function_exists) — bajo riesgo, desbloquean mantenibilidad.
2. **Plan activo P-1** (configurador de campañas + categorías de plantillas) — feature principal, pendiente de tu OK.
3. **R-1** (`eligibilidad.php`) — refactor pendiente del documento original.
4. **S-1 / FI-002** (credenciales en claro) — saneamiento de seguridad.
5. **R-3 monolitos + R-5 prepared statements + FI-005/006/007/008** — deuda estructural a ritmo sostenible.

---

## 7. REFERENCIAS

- `docs/REFACTORIZACIONES_PENDIENTES.md` — refactors detallados
- `docs/FUTURE_IMPROVEMENTS.md` — mejoras pospuestas (histórico)
- `docs/informe_auditoria_bugs_20260825.md` — auditoría de bugs
- `docs/CONFIGURACION_SEGURIDAD.md` — deuda y gestión de secretos
- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` — plan de ejecución activo
