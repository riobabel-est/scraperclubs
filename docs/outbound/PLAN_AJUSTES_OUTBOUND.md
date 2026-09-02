# PLAN DE AJUSTES PENDIENTES — Outbound (CRM FutProtec)

> Módulo: `public_html/outbound` (PHP 8 + SQLite, SiteGround). Fuente detallada y evidencia: `ROADMAP_OUTBOUND.md` (este es el resumen priorizado). Fecha: 2026-09-02.

## ✅ Resuelto / auditado recientemente
- Adjuntos de respuestas entrantes recuperados (54) y fix desplegado · BD local estabilizada · `init_db.php` BD fresca (T-7) · Bandeja: rebotes ocultos + pestaña Rebotados (T-8) · `SCRAPERAPI_KEY` a `.env` · **O-2** vínculos cruzados entre tabs · **E-3** auditoría SPF/DKIM/DMARC · **E-4** auditoría rebotes (0 soft) · **E-5** descartado por decisión del usuario.

## 🔴 Pendiente por prioridad
### Deuda técnica
- **T-1** Dividir monolitos (`inc/imap_respuestas.php`, `api/leads.php`, `cli/init_db.php`).
- **T-2** Prepared statements en endpoints de escritura.
- **T-3** Plantillas versionadas inmutables.
- **T-4** Índices y saneamiento de esquema.
- **T-5** Histórico de estados Kanban por campaña.

### Confort / productividad
- **C-1** Búsqueda global Cmd+K · **C-2** Persistencia de filtros · **C-3** Snooze en colas · **AI-4** Pipeline configurable.

### Futuras / opcional
- **F-1** Evolution API WhatsApp · **F-2** KPIs por email · **FASE N** ESP externo (warmup).
- Warmup de cuentas (opcional) · rebotes *soft* (opcional; hoy 0).

### Infraestructura / limpieza
- Quitar `tmp_audit_mega.php` (raíz) y reducir peso de `public_html/` (backups de BD + exe Tailwind).

## Referencias
- Detalle y evidencia: `ROADMAP_OUTBOUND.md`.
- Escalado del CRM: `public_html/outbound/README.md`.
