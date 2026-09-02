# ROADMAP OUTBOUND — HECHO + PENDIENTE

**Ámbito:** `public_html/outbound/` (CRM FutProtec · PHP 8 + SQLite + JS vanilla, SiteGround)
**Fecha de consolidación:** 2026-09-03
**Regla:** Único documento de tareas del módulo outbound. Ítems **verificados contra el código**; lo hecho **no se reintenta**, lo pendiente aparece solo si NO existe aún. (Documentos legacy de tareas eliminados; trazabilidad en historial git.)

---

## 1. ✅ HECHO (no reintentar)

### 1.1 Features desplegadas — hasta 2026-08-31

| Bloque | Qué | Dónde |
|---|---|---|
| O-1 · H | Secuencias ABC (IF/THEN por ramal, asistido/automático, DDL + cola + anti-doble) + follow-ups | `cli/cron.php` → `secuencia_programarYEnviar()` · tablas `secuencias`/`secuencia_pasos` |
| O-3 · P-4 | Kanban: próxima acción + semáforo · Seguimiento (scorecards, embudo, colas) | `clubes_crm` (`fecha_proxima_accion`/`proxima_accion`) · `api/analytics.php` `get_seguimiento` |
| E-1 · FI-002 | Credenciales SMTP fuera de código | `cli/init_db.php` (bootstrap sin credenciales) · `enviar_smtp_random.php` `$CUENTAS_SMTP_FALLBACK` vacío |
| E-2 · FI-005 | Atomicidad `enviados_hoy` | `inc/eligibilidad.php` (`enviadosHoyDeCuenta`/`elegirCuentaSmtpDisponible`) |
| E-4 (hard) | Supresión de **hard bounces** | `inc/eligibilidad.php` (`esEmailHardBounced`/`esLeadHardBounced`) + `rebotes` |
| AI-1..AI-10 | Navegación por bloques · plantillas por objetivo · anti falsa automatización · prioridad/temperatura · ramal ABC · tutoría · asistente IA | `dashboard.php` · `js/app.js` · `inc/respuestas.php` · `inc/lead_scoring.php` · `api/plantillas.php` |
| P-1 · P-3 · P-5 · P-6 | Configurador campañas (A/B/C) · pestañas · Analytics global/agenda · contexto de campaña | `tabs/editor.php` · `tabs/analytics.php` · `api/config.php` |
| FASE F | Registro de respuestas IMAP → lead/envío/campaña | `inc/imap_respuestas.php` |
| FASE G | Notificaciones de respuestas | checkpoint fase G (historial) |
| FASE I | Tracking de clics `/c/` | `api/click.php` |
| FASE J | Timeline del lead | `dashboard.php` `get_interacciones` |
| FASE K | Scoring determinista (temperatura) | `inc/lead_scoring.php` |
| FASE L | Validación MX + WhatsApp automático | `api/lead_validate.php` · `tiene_whatsapp` |
| FASE 1-6 | Adjuntos por plantilla · trazabilidad envíos · RFC 2047 · auditoría pre-lote | `inc/adjuntos.php` · `plantillas_adjuntos` · `respuestas_adjuntos.ruta` · `cli/auditoria_pre_lote.php` |
| R-1/R-2/R-4 | Refactors: eligibilidad puras · MIME centralizado · `function_exists` | `inc/smtp_transport.php` |
| FI-001/003/004 | Tabla huérfana · URL tracking · 3 motores SMTP | verificado |

### 1.2 Estabilidad, fixes y deuda técnica — 2026-09-02/03 (todo desplegado a producción)

| Bloque | Qué | Evidencia |
|---|---|---|
| Adjuntos inbound | Fix respuestas entrantes (BODY.PEEK[] + extracción MIME) y **54 adjuntos recuperados** | `api/imap_sync.php` · `inc/imap_respuestas.php` · `inc/adjuntos.php` |
| Bandeja | Rebotes **ocultos** en Por responder/Todos + pestaña **Rebotados** | `api/analytics.php` · `js/app.js` · `tabs/respuestas.php` |
| O-2 | Vínculos cruzados: Ficha → Bandeja/Pipeline/Seguimiento y Analytics → acción | `tabs/modals.php` · `js/app.js` |
| T-7 | `init_db.php` inicializa BD **fresca** | guards en `cli/init_db.php` |
| T-2 | Prepared statements verificados (whitelist + casts) | auditoría de patrones |
| T-3 | Plantillas **versionadas** (copia si usada; borrado bloqueado) | `api/plantillas.php` |
| T-4 | Índices y saneamiento (9 índices + integridad) | `cli/optimizar_esquema.php` · `init_db.php` |
| T-5 | Histórico Kanban (`lead_estado_hist`) | `api/leads.php` · esquema |
| T-1 (fase 1) | Monolito `imap_respuestas` 2.126→1.820 líneas (`ClienteIMAP` extraído) | `inc/imap_cliente.php` |
| E-3 | Auditoría SPF ✅ / DKIM ✅ / **DMARC `p=none`** | DNS `getfutprotec.com` |
| E-4 | Auditoría rebotes: todos 5xx (hard); 0 soft | BD |
| E-5 | Passwords SMTP: **descartado** por decisión del usuario (CLI disponible) | `cli/rotar_password_smtp.php` |

---

## 2. 🔴 PENDIENTE REAL (verificado: no existe aún en el código)

### 2.1 Deuda técnica
| ID | Ítem | Nota |
|---|---|---|
| **T-1** | Dividir monolitos (resto) | ✅ Fase 1 hecha (`ClienteIMAP`). Pendiente por partes y con pruebas: `api/leads.php`, `cli/init_db.php`, `inc/respuestas.php`. |

### 2.2 Confort / productividad
| ID | Ítem | Nota |
|---|---|---|
| **C-1** | Búsqueda global **Cmd+K** en el header | Existe buscador en Leads, no global |
| **C-2** | Persistencia de filtros entre visitas | No implementado |
| **C-3** | Snooze / posponer leads en colas | No implementado |
| **AI-4** | Pipeline configurable (etapas editables) | Requiere plan dedicado (migración de literales) |

### 2.3 Entregabilidad (opcional / requiere acción externa)
| Ítem | Nota |
|---|---|
| **DMARC `p=none` → `p=quarantine`** | Acción **tuya en el DNS** cuando esté estable |
| Warmup de cuentas | Opcional |
| Rebotes *soft* | Opcional (hoy 0 soft) |

### 2.4 Futuras / evaluación
| ID | Ítem | Estado |
|---|---|---|
| **F-1** | Evolution API (WhatsApp automatizado) | ⏸️ Evaluación de producto |
| **F-2** | KPIs por email (reporte semanal) | Propuesto |
| **FASE N** | ESP externo (warmup / SMTP alto volumen) | Propuesto |

---

## 3. REFERENCIAS
- Documentación por módulos: `../README.md` (índice docs/).
- Plan resumido de lo pendiente (outbound): `PLAN_AJUSTES_OUTBOUND.md`.
- Escalado del CRM: `public_html/outbound/README.md`.
- Reglas de ejecución: `.clinerules` (no tocar `output/`/`checkpoints/`; no push sin orden).
- Deploy producción 2026-09-03: deuda técnica + BD migrada (índices y `lead_estado_hist`) — backup `backups_deploy/deuda_tecnica_20260903_011253/`; sync IMAP 0 errores.

