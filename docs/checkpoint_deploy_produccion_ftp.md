# CHECKPOINT — DEPLOY PRODUCCIÓN VÍA FTP (getfutprotec.com)

**Fecha/hora:** 2026-08-17 03:59 (Europe/Madrid, UTC+2)
**Operador:** Cline (DevOps)
**Veredicto:** `DEPLOY_PRODUCTION_CODE_PASS`

---

## A. Estado local PRE

| Parámetro | Valor |
|-----------|-------|
| Branch | `main` |
| HEAD | `c86959102db5421be14200bc55a82febc6db7170` |
| Último commit | `fix: finalize launcher test flow and dashboard UI` |
| Cambios pendientes (tracked) | `api/get_cola.php`, `js/app.js`, `tabs/lanzadera.php` (modificados) |
| Cambios pendientes (untracked runtime) | `data/` (BD local, NO desplegada), `tailwindcss-windows-x64.exe` (NO desplegado) |
| Sintaxis PHP/JS | ✅ Todos los archivos `php -l` y `node --check` OK |

**Nota:** No se realizó commit indiscriminado. Se desplegó el estado funcional actual validado (working tree), que corresponde al HEAD `c869591` más los cambios funcionales aprobados en `get_cola.php`, `js/app.js` y `lanzadera.php`.

---

## B. Commit/HEAD desplegado

- **HEAD local:** `c86959102db5421be14200bc55a82febc6db7170`
- **Estado desplegado:** working tree actual (incluye cambios funcionales aprobados no commiteados en `get_cola.php`, `js/app.js`, `lanzadera.php`)

---

## C. Destino remoto

| Parámetro | Valor |
|-----------|-------|
| Host | `ftp.getfutprotec.com` (FTP, puerto 21) |
| Usuario | `cline-outbound@getfutprotec.com` |
| Root remoto | `/getfutprotec.com/public_html/outbound/` |
| URL | `https://getfutprotec.com/outbound/` |
| Confirmación destino | ✅ `getfutprotec.com` (bienvenida FTP + estructura verificada) |
| Hosting | SiteGround (PHP 8.2.33) |

**Credenciales NO expuestas en este informe.**

---

## D. Backup remoto

| Parámetro | Valor |
|-----------|-------|
| Ruta backup | `/getfutprotec.com/backups_deploy/outbound_pre_deploy_20260817_035649/` |
| Ubicación | Fuera de `public_html/` (no web-accessible), fuera del flujo activo |
| Archivos respaldados | 18 (los que existían en remoto antes del deploy) |
| Fecha/hora | 2026-08-17 03:56:49 |
| Backups existentes | NO se borraron |

---

## E. Archivos desplegados (29/29 OK)

```
dashboard.php, .htaccess, .htrouter.php, tailwind.config.js,
js/app.js,
css/tailwind.css, css/tailwind.min.css,
tabs/analytics.php, tabs/editor.php, tabs/followups.php, tabs/gestor.php,
tabs/kanban.php, tabs/lanzadera.php, tabs/modals.php, tabs/respuestas.php, tabs/smtp.php,
api/baja.php, api/enviar_lote.php, api/enviar_smtp_random.php, api/get_cola.php,
api/leads.php, api/smtp.php, api/track.php,
cli/cron.php, cli/init_db.php,
inc/abc.php, inc/eligibilidad.php, inc/metricas.php, inc/respuestas.php
```

Todos verificados por tamaño (FTP `size`) y MD5 local==remoto.

---

## F. Archivos excluidos (NO desplegados)

```
data/            (BD runtime remota — INTACTA)
backups/         (no existe en remoto)
logs/            (runtime remoto — INTACTO)
*.db             (stats.db remoto NO tocado)
tailwindcss-windows-x64.exe
README.md, .gitignore
php_errorlog     (runtime remoto — INTACTO)
```

---

## G. Hashes local/remoto

- **29/29 archivos runtime:** MD5 local == MD5 remoto ✅
- **`js/app.js`:** MD5 `d2329e56d8192807492bcd598e9a78e5` (local == remoto) ✅
- **`dashboard.php`:** MD5 `c66d3bc876dc413d6155c2463af6106c` (local == remoto) ✅

---

## H. Verificación dashboard

- `https://getfutprotec.com/outbound/dashboard.php` → **HTTP 200**
- Sirve la pantalla de login del dashboard nuevo (autenticación propia con `AUTH_KEY`), no la antigua.
- `dashboard.php` desplegado coincide con local (MD5).

---

## I. Verificación JS/CSS

| Recurso | HTTP | Contenido |
|---------|------|-----------|
| `js/app.js?v=10` | 200 | Contiene `enviarCorreoPrueba` (1) y `campanaOperable` (2) ✅ |
| `css/tailwind.min.css` | 200 | ✅ |
| `css/tailwind.css` | 200 | ✅ |

---

## J. Verificación tracking endpoint

- `https://getfutprotec.com/outbound/api/track.php` → **HTTP 200**, `Content-Type: image/png`, body 70 bytes (píxel válido) ✅
- No se generó apertura artificial asociada a envío real.
- `api/get_cola.php` → HTTP 200, JSON válido con datos reales de la BD remota (confirma lectura correcta de BD).

---

## K. Integridad BD remota

| Parámetro | Baseline (pre) | Post-deploy | Estado |
|-----------|----------------|-------------|--------|
| `stats.db` size | 749568 | 749568 | ✅ INTACTO |
| `stats.db` mtime | 20260810162730 | 20260810162730 | ✅ INTACTO |
| Nueva BD creada | — | No | ✅ |
| Migración de tablas | — | No | ✅ |

La BD remota NO fue sobrescrita, ni se creó nueva BD, ni se migró ninguna tabla.

---

## L. Seguridad

| Control | Estado |
|---------|--------|
| SMTP | NO ejecutado |
| POST de envío | NO ejecutado |
| cron | NO ejecutado |
| Evolution API | NO ejecutado |
| envío nuevo | NO ejecutado |
| BD remota modificada | NO |
| credenciales modificadas | NO (credenciales SMTP idénticas local/remoto) |
| campaña modificada | NO |
| lead modificado | NO |
| `enviar_smtp_random.php` | HTTP 403 (bloqueado por .htaccess) |
| `cron.php` | HTTP 403 (bloqueado por .htaccess) |
| `data/stats.db` | HTTP 403 (bloqueado por .htaccess) |

**Nota credenciales SMTP:** El `api/enviar_smtp_random.php` local contiene las mismas credenciales de producción que el remoto (verificado por comparación). Además, la versión local activa el bloqueo `die("SISTEMA BLOQUEADO...")` (estado seguro FASE 2B), que es más restrictivo que el remoto (que lo tenía comentado). No se alteró ninguna credencial.

---

## M. Resultado

```
DEPLOY_PRODUCTION_CODE_PASS
```

- Código runtime actual desplegado correctamente en `getfutprotec.com/outbound/` (29/29 archivos).
- BD remota intacta.
- Credenciales SMTP preservadas.
- Endpoints críticos responden HTTP 200.
- Endpoints sensibles bloqueados (403).
- Tracking endpoint operativo.
- Cache-busting `app.js?v=10` confirmado.

---

## PARADA

**DETENIDO.** No se cambió `modo_entorno`. No se activó campaña 2. No se inició motor. No se enviaron correos. No se ejecutó cron.

El siguiente paso será una comprobación separada del dashboard remoto y del tracking real en `getfutprotec.com`.
