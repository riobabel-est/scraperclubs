# ROADMAP OUTBOUND — TODO (HECHO + PENDIENTE)

**Ámbito:** `public_html/outbound/` (CRM FutProtec · PHP 8 + SQLite + JS vanilla, SiteGround)
**Fecha de consolidación:** 2026-09-02
**Regla:** Este es el **único** documento de tareas del módulo outbound. Consolida todo
lo **hecho** y todo lo **pendiente**. Los antiguos `PENDIENTES_OUTBOUND.md`,
`ROADMAP_OUTBOUND_GLOBAL.md`, `REFACTORIZACIONES_PENDIENTES.md` y `FUTURE_IMPROVEMENTS.md`
fueron **eliminados** al quedar vacíos (ver historial git si se necesita trazabilidad).

> **Criterio:** cada ítem está **verificado contra el código** (`public_html/outbound/`).
> Lo ya implementado aparece marcado ✅ **HECHO** con la evidencia (archivo/función).
> Lo pendiente aparece solo si la feature NO existe aún. Lo no verificable se elimina.

---

## 1. ✅ HECHO (verificado en el código — no reintentar)

| Bloque | Ítem | Evidencia (dónde está) |
|---|---|---|
| **Secuencias ABC (O-1)** | Motor secuencial IF/THEN por ramal (asistido/automático), DDL + cola + anti-doble | `cli/cron.php` → `secuencia_programarYEnviar()` (líneas 96, 106, 333) · tablas `secuencias`/`secuencia_pasos` |
| **Kanban (O-3)** | Próxima acción + semáforo en la tarjeta | `fecha_proxima_accion` + `proxima_accion` en `clubes_crm` y `window._kanbanLeads` |
| **Seguridad (E-1 / FI-002)** | Credenciales SMTP fuera de código | `cli/init_db.php:244` → cuentas bootstrap **sin credenciales** (UI) · `enviar_smtp_random.php` `$CUENTAS_SMTP_FALLBACK` vacío |
| **Atomicidad (E-2 / FI-005)** | Recuento real `enviados_hoy` unificado | `inc/eligibilidad.php` → `enviadosHoyDeCuenta()`/`elegirCuentaSmtpDisponible()`/`sincronizarEnviadosHoyCuenta()` |
| **Rebotes (E-4, parcial)** | Supresión de **hard** bounces | `inc/eligibilidad.php:317,320` → `esEmailHardBounced()`/`esLeadHardBounced()` + tabla `rebotes` |
| **Importador CSV (AI-2)** | Captación de leads desde la UI | `api/leads.php:1221-1224` → `action=importar_csv` · UI en `js/app.js:3429` |
| **Agenda (AI-3)** | Próximas acciones por fecha (vencidas) | `fecha_proxima_accion` + cola **Avanzar** con vencidos |
| **Navegación (AI-1 / C-4)** | Menú por bloques Setup → Operación → Análisis | `dashboard.php` (nav agrupada) |
| **Plantillas por objetivo (AI-5)** | Categorías 01/02/03 + cola "Calientes" | `cli/migrar_plantillas_objetivo.php` · `api/analytics.php` |
| **Anti falsa automatización (AI-6)** | Triggers respuesta→estado, WhatsApp, lote | `inc/respuestas.php` · `inc/imap_respuestas.php` · `api/leads.php` |
| **Prioridad honesta (AI-7)** | Prioridad derivada de temperatura | `inc/lead_scoring.php` → `calcularTemperaturaLead()` |
| **Ramal interés ABC (AI-8)** | Interés por variante + borrador IA en ramal | `api/analytics.php` → `interesDeVariante()` · `inc/atencion_lead.php` |
| **Tutoría (AI-9)** | Widget flotante guía | `js/app.js` → `tutorApp()` |
| **Asistente IA plantillas (AI-10)** | Generación con IA que rellena el editor | `api/plantillas.php` → `generar_plantilla_ia` |
| **P-1 configurador campañas** | Plantillas + campañas + A/B/C | `tabs/editor.php` + `campanasConfig()` |
| **P-3 tabs** | Reorganización de pestañas | `dashboard.php` |
| **P-4 Seguimiento** | Scorecards, embudo, colas | `api/analytics.php` → `get_seguimiento` · `tabs/seguimiento.php` |
| **P-5 CRM HubSpot** | Analytics global + agenda + smart view | `tabs/analytics.php` (Efectividad Global) |
| **P-6 Contexto campaña** | Selector global que filtra todo | `api/config.php` → `set_campana_actual` |
| **FASE F** | Registro de respuestas IMAP → lead/envío/campaña | `inc/imap_respuestas.php` |
| **FASE G** | Notificaciones de respuestas | `checkpoint_faseG_notificaciones_globales.md` |
| **FASE H** | Motor de secuencias / follow-ups | `cli/cron.php` (O-1) |
| **FASE I** | Tracking de clicks `/c/` | `api/click.php` |
| **FASE J** | Timeline del lead | `dashboard.php` → `get_interacciones` · `tabs/modals.php` |
| **FASE K** | Scoring determinista (temperatura) | `inc/lead_scoring.php` |
| **FASE L** | Verificación MX / higiene de emails | `api/lead_validate.php` (`filter_var` + `checkdnsrr`) · `tiene_whatsapp` automático |
| **FASE 1-6 (2026-08-29)** | Adjuntos por plantilla, trazabilidad de envíos, RFC 2047, auditoría pre-lote | `inc/adjuntos.php` · tabla `plantillas_adjuntos` · `respuestas_adjuntos.ruta` · `futprotec_encodeHeaderName()` · `cli/auditoria_pre_lote.php` · `cli/enviar_lote_batch.php` (commit `b813721`) |
| **FASE ADJUNTOS-INBOUND (2026-09-02)** | Adjuntos de respuestas entrantes (clientes) recuperados | `api/imap_sync.php` (BODY.PEEK[]+`imap_extraer_cuerpo_partes`) · `inc/imap_respuestas.php` (`imap_completar_cuerpo_duplicado` inserta adjuntos) · `inc/adjuntos.php` (rutas `\`→`/`). Commit `f6cd0a2`, desplegado a SiteGround, 54 adjuntos recuperados |

| **Refactors (R-1, R-2, R-4)** | eligibilidad puras · MIME centralizado · `function_exists` | `inc/smtp_transport.php` (transporte único) |
| **FI-001, FI-003, FI-004** | Tabla huérfana · URL tracking · 3 motores SMTP | verificado en código |

---

## 2. 🔴 PENDIENTE REAL (verificado: NO existe aún en el código)

### Prioridad 0 — Operación comercial
| ID | Ítem | Nota |
|---|---|---|
| **O-2** | Vínculos cruzados entre tabs (ficha → Pipeline/Seguimiento; Analytics → acción) | ✅ Completo 2026-09-02 · Ficha → **Bandeja/Pipeline/Seguimiento** (`tabs/modals.php`+`app.js`) y **Analytics → acción** (botón *Abrir* por fila en scorecards → ficha del lead o búsqueda en Pipeline). Desplegado a SiteGround. |

### Prioridad 1 — Entregabilidad / seguridad
| ID | Ítem | Nota |
|---|---|---|
| **E-3** | Deliverability: verificación SPF/DKIM/DMARC por dominio + warmup de cuentas | 🔍 **Auditoría hecha** (2026-09-02) `getfutprotec.com`: SPF ✅ (`+a +mx include:dnssmarthost ~all`) · DKIM ✅ (CNAME SiteGround autodns + selector google) · **DMARC ⚠️ `p=none`** → recomendado subir a `p=quarantine` en DNS cuando esté estable (acción del usuario, no código). Warmup sin implementar. |
| **E-4** | Gestión de rebotes **soft** + supresión automática | Hard ✅ hecho · 🔍 Auditoría 2026-09-02: **22 rebotes en BD, todos 5xx (hard); 0 soft actuales** → implementar gestión soft es **opcional/no urgente** (prioridad baja). Cuando existan, clasificar `smtp_code 4xx` como soft y solo suprimir tras N (p. ej. 3) en el mismo periodo. |
| **E-5** | ~~Rotar contraseñas SMTP en producción~~ | ✅ **Descartado por decisión del usuario** (2026-09-02): ya está todo OK. No rotar. El CLI `cli/rotar_password_smtp.php` queda disponible por si algún día se necesita. |

### Prioridad 2 — Deuda técnica
| ID | Ítem | Nota |
|---|---|---|
| **T-1** | Dividir monolitos: `inc/imap_respuestas.php`, `api/leads.php`, `cli/init_db.php` | No bloquea; ventanas de calma |
| **T-2** | Prepared statements en endpoints de escritura | ✅ Verificado 2026-09-02: patrón prepared + whitelist de campos + casts `(int)`; sin interpolación cruda de `$_GET/$_POST` en SQL de escritura (auditoría por patrones). |
| **T-3** | Plantillas versionadas inmutables | ⏸️ En curso/planificado: evitar sobrescribir plantillas ya enviadas. |
| **T-4** | Índices y saneamiento de esquema | ✅ Hecho 2026-09-02 · nuevo `cli/optimizar_esquema.php` (idempotente: 6 índices `respuestas` + 3 `rebotes`, ANALYZE, integridad) · reflejado en `cli/init_db.php` y `scripts/preparar_bd_deploy.py` · aplicado a BD local/deploy (9 índices, integrity ok, FK 0) |
| **T-5** | Histórico de estados Kanban **por campaña** | ✅ Hecho 2026-09-02 · nueva tabla `lead_estado_hist` (lead/campaña/estado_anterior/nuevo/origen/fecha) registrada en `api/leads.php update_lead` · esquema en `optimizar_esquema.php`, `init_db.php`, `preparar_bd_deploy.py` · validado |
| **T-6** | Limpiar docs legacy de pendientes | Este documento la resuelve |

### Prioridad 3 — Confort / productividad
| ID | Ítem | Nota |
|---|---|---|
| **C-1** | Búsqueda global Cmd+K desde el header | Existe `api/lead_search.php` y buscador en Leads, pero **no** la búsqueda global del header |
| **C-2** | Persistencia de filtros entre visitas | No implementado |
| **C-3** | Snooze / posponer leads en colas | No implementado |
| **AI-4** | Pipeline configurable (etapas editables en Ajustes) | Requiere plan dedicado (migración de literales) |

### Bugs / ajustes nuevos (2026-09-02)
| ID | Ítem | Nota |
|---|---|---|
| **T-7** | `cli/init_db.php` no inicializa BD **fresca** (falla en tabla legacy `plantillas`, columnas `envios.es_test/lead_id/campaign_id`) | ✅ Resuelto 2026-09-02 (commit `eb17d76` + push): 3 guards `CREATE/ALTER IF NOT EXISTS` backporteados al repo y verificados en BD vacía. |
| **T-8** | La Bandeja muestra adjuntos técnicos de NDR/rebotes (`adjunto_N.bin` de Mailer-Daemon) | ✅ Resuelto 2026-09-02: los mensajes de rebote quedan **ocultos** en Por responder/Todos y **solo se muestran en la pestaña Rebotados** (con sus adjuntos, para verificar). Cambios en `api/analytics.php`, `js/app.js`, `tabs/respuestas.php` |

### Futuras / evaluación
| ID | Ítem | Estado |
|---|---|---|
| **F-1** | Evolution API (WhatsApp automatizado) | ⏸️ Evaluación de producto |
| **F-2** | Notificaciones por email de KPIs (reporte semanal) | Propuesto |
| **FASE N** | Evaluación ESP externo (warmup / SMTP alto volumen) | Propuesto |

---

## 3. REFERENCIAS
- Documentación por módulos: `../README.md` (índice raíz de docs/).
- Plan de ajustes resumido de lo pendiente (outbound): `PLAN_AJUSTES_OUTBOUND.md`.
- Escalado del CRM: `public_html/outbound/README.md`.
- Reglas de ejecución y protección: `.clinerules` (no tocar `output/`/`checkpoints/`; no push sin orden).
