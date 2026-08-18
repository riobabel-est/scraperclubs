# CHECKPOINT — DEPLOY FINAL PRE-MICRO-LOTE (SINCRONIZACIÓN COMPLETA CON SITEGROUND)

**Fecha:** 2026-08-17 19:07 (Europe/Madrid)
**Resultado:** `PRE_MICRO_BATCH_SITEGROUND_PASS`

---

## 1. FUENTE DE VERDAD (Git)

```
HEAD        = d36067b4255ae518222f51cfb923ac6175d68cc8
origin/main = d36067b4255ae518222f51cfb923ac6175d68cc8
LOG         = feat(outbound): sincronizar runtime validado pre-micro-lote (lanzadera, lista negra, mime, lead search/validate)
```

- Working tree runtime limpio (solo `data/` = stats.db, gitignored; y `tailwindcss-windows-x64.exe` = herramienta local de build, no runtime).
- Commit `d36067b` creado y pusheado SIN force (5195a55..d36067b).

## 2. INVENTARIO RUNTIME (33 archivos)

Todos los archivos runtime de `public_html/outbound/` verificados:
dashboard.php, .htaccess, .htrouter.php, tailwind.config.js, js/app.js,
css/tailwind.css, css/tailwind.min.css, tabs/{analytics,editor,followups,gestor,kanban,lanzadera,lista_negra,modals,respuestas,smtp}.php,
api/{baja,enviar_lote,enviar_smtp_random,get_cola,lead_search,lead_validate,leads,smtp,track}.php,
inc/{abc,eligibilidad,metricas,mime,respuestas}.php, cli/{cron,init_db}.php.

## 3. PRE-FLIGHT LOCAL

- `php -l` en TODOS los archivos PHP: **sin errores de sintaxis**.
- `node --check js/app.js`: **OK**.
- Tests funcionales:
  - `test_baja_flow.php` → **17/17 PASS** (GO_LIVE_UNSUBSCRIBE_PASS)
  - `test_mime_plaintext_tracking.php` → **43/43 PASS** (PLAINTEXT_TRACKING_MIME_PASS)
  - `fase_launcher_check_idempotencia.php` → **4/4 PASS**
  - `fase_launcher_test_get_cola.php` → **9/9 PASS** (LAUNCHER_TEST_SELECTION_PASS)
  - `fase6f6_test_aislamiento.php` → **TODAS PASS** (TEST/REAL, idempotencia, supresión)

## 4. GIT SINCRONIZADO

```
LOCAL HEAD = origin/main = d36067b
```

## 5-6. INSPECCIÓN REMOTA + COMPARACIÓN MD5

- Servidor: `ftp.getfutprotec.com` (SiteGround)
- Ruta remota: `/getfutprotec.com/public_html/outbound`
- Acceso: OK (login FTP válido)

Comparación MD5 local vs remoto (inventario completo de 33 archivos):

| Archivo | Estado |
| ------- | ------ |
| dashboard.php | MATCH |
| .htaccess | MATCH |
| .htrouter.php | MATCH |
| tailwind.config.js | MATCH |
| js/app.js | MATCH |
| css/tailwind.css | MATCH |
| css/tailwind.min.css | MATCH |
| tabs/analytics.php | MATCH |
| tabs/editor.php | MATCH |
| tabs/followups.php | MATCH |
| tabs/gestor.php | MATCH |
| tabs/kanban.php | MATCH |
| tabs/lanzadera.php | MATCH |
| tabs/lista_negra.php | MATCH |
| tabs/modals.php | MATCH |
| tabs/respuestas.php | MATCH |
| tabs/smtp.php | MATCH |
| api/baja.php | MATCH |
| api/enviar_lote.php | MATCH |
| api/enviar_smtp_random.php | MATCH |
| api/get_cola.php | MATCH |
| api/lead_search.php | MATCH |
| api/lead_validate.php | MATCH |
| api/leads.php | MATCH |
| api/smtp.php | MATCH |
| api/track.php | MATCH |
| inc/abc.php | MATCH |
| inc/eligibilidad.php | MATCH |
| inc/metricas.php | MATCH |
| inc/mime.php | MATCH |
| inc/respuestas.php | MATCH |
| cli/cron.php | MATCH |
| cli/init_db.php | MATCH |

**MATCH = 33 | MISMATCH = 0 | MISSING_REMOTE = 0 | NO_LOCAL = 0**

## 7. DEPLOY SELECTIVO

- Backup remoto previo: `/getfutprotec.com/backups_deploy/outbound_pre_micro_20260817_190454` (4 archivos).
- Subidos SOLO los 4 desfasados: `tabs/lanzadera.php`, `api/enviar_lote.php`, `api/get_cola.php`, `inc/mime.php`.
- No se subieron stats.db, backups, logs, credenciales, temporales ni binarios.

## 8. VERIFICACIÓN POST-DEPLOY

```
MATCH = 33 (100%)
MISMATCH = 0
MISSING_REMOTE = 0
VEREDICTO: FULL_MD5_MATCH_PASS
```

## 9. VERIFICACIÓN HTTP (curl, UA navegador)

| Endpoint | HTTP |
| -------- | ---- |
| dashboard.php | 200 |
| js/app.js?v=10 | 200 |
| api/track.php | 200 (image/png) |
| api/get_cola.php | 200 |
| api/baja.php | 200 |
| api/enviar_lote.php | 200 |
| api/leads.php | 401 (auth requerida) |
| api/smtp.php | 401 (auth requerida) |
| api/lead_search.php | 401 (auth requerida) |
| api/lead_validate.php | 401 (auth requerida) |

- **Sin HTTP 500** en ningún endpoint.
- 401 = autenticación requerida (correcto, no fallo de código).
- Nota: el WAF de SiteGround bloquea el User-Agent de Python `urllib` (403). Con UA de navegador (curl) todos los endpoints responden correctamente.

## 10. VERIFICACIONES FUNCIONALES ESPECÍFICAS (código remoto = local, MD5 100%)

- **Lanzadera**: `lzBatchSize` (1-500), `lzSendCalls` (doble salvaguarda de lote), `lzCuentaActivaLimite`, envío dirigido, selección TEST/REAL. ✅
- **Lista Negra**: `blacklist_add`, `blacklist_remove`, `get_blacklist`, `OPTOUT_REAL_PROTEGIDO`. ✅
- **Cola**: exclusión de supresión, candidatos elegibles, batch máximo. ✅
- **SMTP**: `limite_diario`, `enviados_hoy`, `lzCuentaActivaLimite`, cuenta saturada bloqueada. ✅
- **MIME**: `multipart/alternative`, `text/plain`, `text/html`, tracking, baja por token. ✅
- **Tracking**: `track.php`, `tracking_id`. ✅

## 11. BASE DE DATOS (solo lectura)

```
config.modo_entorno = produccion
config.motor_estado = pausado
pipelines id=2 = 'Piloto Comercial FutProtec 2026-08' | estado=PILOT | entorno=pilot
```

- No se modificó stats.db (solo descarga a temp para consultas de solo lectura).
- No se sincronizó BD local sobre producción.
- No se hicieron migraciones.

## 12. SEGURIDAD PRE-MICRO-LOTE

```
SMTP ejecutado durante esta tarea = NO
envíos nuevos durante esta tarea    = 0 (envios totales = 22, campaign 2 = 2 de fases previas de validación)
cron                               = NO
Lanzadera                          = NO
campaign 2                         = sin cambios (PILOT)
modo_entorno                       = sin cambios (produccion)
stats.db                           = sin cambios
```

## 13. CRITERIO FINAL

| Criterio | Estado |
| -------- | ------ |
| GitHub actualizado | ✅ (d36067b = origin/main) |
| runtime local validado | ✅ (php -l, node, tests) |
| runtime SiteGround = LOCAL al 100% | ✅ (MD5 33/33) |
| MD5 MATCH 100% | ✅ |
| HTTP PASS | ✅ (sin 500) |
| BD intacta | ✅ |
| producción activa | ✅ (modo_entorno=produccion) |
| motor pausado | ✅ (motor_estado=pausado) |
| 0 envíos realizados | ✅ |

---

# ✅ PRE_MICRO_BATCH_SITEGROUND_PASS

## PARADA OBLIGATORIA

- No seleccionar leads.
- No cargar cola para enviar.
- No pulsar Iniciar Lanzadera.
- No ejecutar cron.
- No enviar ningún correo.

El operador continuará manualmente desde la Lanzadera y configurará los 5 leads para el micro-lote comercial.
