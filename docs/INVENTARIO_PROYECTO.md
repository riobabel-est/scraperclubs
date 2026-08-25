# INVENTARIO DEL PROYECTO — Scrapper Club + CRM Outbound

**Fecha:** 2026-08-25
**Ámbito:** Repositorio completo
**Propósito:** Documentar para qué sirve cada parte del proyecto y qué se movió a `archivo_inactivo/`.

---

## 1. Estructura actual (EN USO)

### Núcleo del SCRAPER (proyecto original)
| Ruta | Función |
|---|---|
| `main.py` | Orquestador del scraping |
| `config.py` | Configuración central (federaciones, delays, API key) |
| `pendientes.py` | Gestión de clubes pendientes |
| `scraper_nova.py` / `scraper_rfcylf.py` / `scraper_fcf_cat.py` / `scraper_madrid.py` / `scraper_nova_browser.py` | Scrapers por plataforma/federación |
| `requirements.txt` / `README.md` / `.clinerules` | Dependencias, docs, reglas |
| `input/` | Entrada de datos para scraping |
| `output/` | CSVs de resultados por federación (**protegidos**) |
| `checkpoints/` | Progreso JSON para reanudar (**protegidos**) |
| `logs/` | Logs de ejecución |
| `clubes.json` | Fuente de contactos legada; la usa `cli/init_db.php` (migración) → **mantener** |

### CRM Outbound (`public_html/outbound/`)
| Ruta | Función |
|---|---|
| `dashboard.php` | Orquestador + login + endpoints de auth/recuperación |
| `api/*.php` | Endpoints AJAX (envíos, leads, plantillas, config, IA, baja, track…) |
| `cli/*.php` | CLI/cron (init_db, cron, imap_respuestas…) |
| `inc/*.php` | Helpers, crypto, smtp_transport, eligibilidad, imap, pop3… |
| `js/app.js` | Frontend Alpine (refactorizado §5) |
| `tabs/*.php` | Vistas (kanban, gestor, editor, smtp/config, lanzadera…) |
| `data/` | BD SQLite (`stats.db`) — gitignored |
| `inc/secret.php` | Centro único de secretos — gitignored |

### `scripts/` (EN USO — 32 archivos)
| Grupo | Archivos | Función |
|---|---|---|
| Scraper | `monitor.py`, `ip_rotator.py`, `retry_madrid_pendientes.py`, `scraper_fcf.sh`, `validate_emails.py` | Monitor, proxies, reintentos, validación |
| Deploy/verify | `deploy_outbound_full.py`, `verify_*.py` (10) | Deploy estándar + verificación remota |
| Tests | `test_app_js_refactor.js`, `test_config_ia_toggle.js`, `test_seguridad_toggle.js`, `test_baja_flow.php`, `test_blacklist_bidirectional.php`, `test_imap_respuestas.php`, `test_mime_plaintext_tracking.php`, `test_smtp_cifrado_local.php` | Tests funcionales locales |
| Refactor/migración | `refactor_contraste_ui.py`, `cifrar_imap_conectar.py`, `migrar_passwords_smtp.php`, `migrar_api_keys.php`, `validate_emails.php` | Herramientas de refactor/migración |
| Datos de contacto | `generar_clubes_json.py` | **Genera `clubes.json`** (fuente de `init_db.php`) → mantener |
| Sync BD | `sync_db_backup_remote.py`, `sync_db_upload.py` | Backup/upload de la BD local↔remoto → utilidad de operación |

### `docs/` (EN USO)
- `PENDIENTES_OUTBOUND.md` — **compendio único de pendientes**
- `PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` — plan de ejecución (F0-F6)
- `CONFIGURACION_SEGURIDAD.md`, `REFACTORIZACIONES_PENDIENTES.md`, `informe_auditoria_bugs_20260825.md`
- Checkpoints por fase (históricos, referencia)

### Otras carpetas
- `backups/`, `backups_deploy/` — respaldos de BD/manifests de deploy (rollback)
- `archivo_inactivo/` — **huérfanos movidos (gitignored)**

---

## 2. Movido a `archivo_inactivo/` (196 archivos — sin borrar nada)

| Origen | Qué | Cantidad aprox. |
|---|---|---|
| Raíz | `tmp_*.php` (auditorías/diagnósticos), `tmp_*.txt`, `inspect_db.php`, `_*.txt/_*.py/_*.php`, `audit_*.txt`, archivos rotos (`c`, `1202`, `fetchArray*`), `debug/`, `export/` | ~60 |
| `scripts/` | `deploy_*.py` de fases puntuales (37), `_test_*.php` de fases (8), `fase_*`/`faseA*`/`faseC*`/`fase6f*` (fases completadas), `a.py`, `_ae.py`, `_audit_*.py`, `atribuir_respuestas_retroactivo.php`, `add_smtp_sender_fields.py`, `fase_cierre_forense_*.py`, `auditar_todos_buzones.py`, `auditoria_integral_final.py`, operaciones puntuales (microenvío, campaña 2, restores) | ~136 |

### Análisis previo de funcionalidad (antes de mover)
Se revisaron las cabeceras/docstrings de los huérfanos. **Ninguno tiene funcionalidad no cubierta** actualmente:
- `add_smtp_sender_fields.py` → campos Nombre/Cargo Emisor **ya integrados** en el modal SMTP.
- `_fix_modal.py` → fix de modal **ya aplicado**.
- `atribuir_respuestas_retroactivo.php` → atribución cubierta por `atribuir_respuestas_runner.php` (web, con secret.php).
- `fase_cierre_forense_*`, `auditar_todos_buzones.py`, `auditoria_integral_final.py`, `_audit_*` → análisis read-only puntuales ya realizados.
- `deploy_*.py` → cubiertos por `deploy_outbound_full.py`.
- `_test_*.php`, `fase_*` → fases cerradas.

### Excepciones mantenidas por funcionalidad real
- `clubes.json` → lo usa `cli/init_db.php` (migración de contactos).
- `scripts/generar_clubes_json.py` → genera ese JSON.
- `scripts/sync_db_backup_remote.py` / `sync_db_upload.py` → backup/sync de la BD (utilidad de operación no duplicada).

---

## 3. Notas de estado git

- Los archivos trackeados que se movieron (p.ej. `scripts/fase_*.php`, `scripts/deploy_*.py` de fases, `_test_*.php`) aparecen como **deleted** en `git status` (el archivo sigue existiendo en `archivo_inactivo/`).
- `archivo_inactivo/` está en `.gitignore` (no se sube).
- Para cerrar el ciclo: `git add -A` + commit reflejará la retirada de huérfanos del repo (los archivos no se pierden).
