# CHECKPOINT — PRE-GO-LIVE FINAL (Verificación GitHub + SiteGround + CRM)

**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Primer envío comercial intencional
**Estado:** **PRE_GO_LIVE_SITEGROUND_PASS** (tras deploy final de app.js y lanzadera.php)

---

## A. GITHUB

```text
branch  = main
HEAD    = e576ea8ff65da9d5f2088ec10fcaef42795363fa
origin  = e576ea8ff65da9d5f2088ec10fcaef42795363fa (sincronizado)
```

Commit creado y pusheado: `chore: final pre-go-live checkpoint` (13 archivos,
+1633/-9). Push SIN `--force` (d1d23db..e576ea8).

**Working tree:** limpio salvo exclusiones deliberadas:
- `backups_deploy/` (backups)
- `public_html/outbound/tailwindcss-windows-x64.exe` (binario)
- `tmp_*.php` (temporales)

**GITHUB_CHECKPOINT_PASS**

---

## B. COMMIT

```text
e576ea8 chore: final pre-go-live checkpoint
```

Archivos funcionales incluidos:
- `public_html/outbound/js/app.js` (lzColaResultados — estados de fila en cola)
- `public_html/outbound/tabs/lanzadera.php` (columna Estado ENVIADO/ERROR/PROCESANDO)
- `public_html/outbound/tabs/modals.php` (fix aperturas: `aqData.ultimos.length > 0`)

Documentación/QA incluida (7 checkpoints) + 3 scripts funcionales de deploy/sync.

**NO incluidos:** stats.db, backups, logs, temporales, binarios, credenciales.

---

## C. HASH

```text
LOCAL_COMMIT = e576ea8ff65da9d5f2088ec10fcaef42795363fa
```

---

## D. SITEGROUND — VERIFICACIÓN DE CÓDIGO (MD5 local vs remoto)

Se ejecutó `deploy_ftp_verify.py` (solo lectura, descarga y compara MD5).

**Resultado POST-DEPLOY (tras sincronizar app.js y lanzadera.php):**

```text
[OK] dashboard.php
[OK] .htaccess
[OK] .htrouter.php
[OK] tailwind.config.js
[OK] js/app.js
[OK] css/tailwind.css
[OK] css/tailwind.min.css
[OK] tabs/analytics.php
[OK] tabs/editor.php
[OK] tabs/followups.php
[OK] tabs/gestor.php
[OK] tabs/kanban.php
[OK] tabs/lanzadera.php
[OK] tabs/modals.php
[OK] tabs/respuestas.php
[OK] tabs/smtp.php
[OK] api/baja.php
[OK] api/enviar_lote.php
[OK] api/enviar_smtp_random.php
[OK] api/get_cola.php
[OK] api/leads.php
[OK] api/smtp.php
[OK] api/track.php
[OK] cli/cron.php
[OK] cli/init_db.php
[OK] inc/abc.php
[OK] inc/eligibilidad.php
[OK] inc/metricas.php
[OK] inc/respuestas.php
```

**all_ok = True — TODOS los archivos funcionales LOCAL == REMOTO. Sin MISMATCH.**

**Historial del bloqueo:** Inicialmente `js/app.js` y `tabs/lanzadera.php` presentaban
MISMATCH (los cambios de UI de la Lanzadera estaban commiteados en git pero no
desplegados). Se resolvió con el deploy final (ver sección DEPLOY FINAL).

---

## E. HASHES REMOTO/LOCAL (POST-DEPLOY)

```text
js/app.js        local=b42ab1be4f082f5a5fa2acaff371f2ea  remote=b42ab1be4f082f5a5fa2acaff371f2ea  → COINCIDE ✓
tabs/lanzadera.php local=ecc6f9359660cea007c39cc74983360f remote=ecc6f9359660cea007c39cc74983360f → COINCIDE ✓
```

---

## F. HTTP

```text
dashboard.php        200
js/app.js?v=10       200
css/tailwind.min.css 200
api/track.php        200
api/get_cola.php     200
```

**Sin HTTP 500.**

---

## G. ERRORES DEL SITE

Endpoints protegidos (sin sesión autenticada):

```text
get_piloto_campanas.php  404 (no existe como archivo standalone)
get_piloto_metricas.php  404 (no existe como archivo standalone)
get_followups.php        404 (no existe como archivo standalone)
mockup_capacity.php      404 (no existe como archivo standalone)
get_templates.php        404 (no existe como archivo standalone)
get_cola.php             200
```

Los endpoints `get_piloto_campanas`, `get_piloto_metricas`, `get_followups`,
`mockup_capacity`, `get_templates` no existen como archivos `.php` independientes en
`api/` (solo existen: baja, enviar_lote, enviar_smtp_random, get_cola, leads, smtp,
track). Devuelven 404 (no encontrado), NO 500.

**NO HTTP 500.** Los 404 no son errores de servidor.

---

## H. BD REMOTA (solo lectura)

Descargada `stats_db_pre_go_live_final_20260817_161604.db` (921600 bytes,
mtime 20260817030809).

```text
config.modo_entorno = produccion   ✓
config.motor_estado = pausado      ✓
PRAGMA integrity_check = ok        ✓
```

---

## I. CAMPAÑA 2

```text
campaign 2:
  id      = 2
  nombre  = Piloto Comercial FutProtec 2026-08
  estado  = PILOT   ✓
  entorno = pilot   ✓
  activo  = 1       ✓
```

---

## J. PLANTILLA 1

```text
plantilla 1:
  id      = 1
  nombre  = Prospeccion (abc - texto plano)
  activo  = 1       ✓
  test_ab = 1       ✓
  tipo    = texto_plano
```

---

## K. LANZADERA

La interfaz de la Lanzadera requiere sesión autenticada (no verificable por CLI sin
login). El backend `get_cola.php` responde 200.

**POST-DEPLOY:** La versión de `lanzadera.php` y `app.js` desplegada en SiteGround SÍ
incluye la columna "Estado" (ENVIADO/ERROR/PROCESANDO) y `lzColaResultados`. Verificado
en el contenido remoto:
- `app.js`: `enviarCorreoPrueba` ✓, `campanaOperable` ✓, `lzColaResultados` ✓,
  `get_cola.php` ✓, selección TEST/REAL ✓.
- `lanzadera.php`: `PROCESANDO` ✓, `ENVIADO` ✓, `ERROR` ✓, badges de estado ✓,
  sin opacidad excesiva (sin opacity-50/60/70/80) ✓.

---

## L. TRACKING

```text
track.php HTTP 200  ✓ (endpoint desplegado)
No se realizó ninguna apertura nueva.
```

---

## M. ERRORES ENCONTRADOS

1. **RESUELTO — SITEGROUND_CODE_MISMATCH** en `js/app.js` y `tabs/lanzadera.php`:
   los cambios de UI de la Lanzadera no estaban desplegados. Se resolvió con el
   DEPLOY FINAL (ver sección DEPLOY FINAL). Tras el deploy, `deploy_ftp_verify.py`
   reporta `all_ok = True` (sin MISMATCH).
2. Endpoints `get_piloto_campanas`, `get_piloto_metricas`, `get_followups`,
   `mockup_capacity`, `get_templates` devuelven 404 (no existen como archivos
   standalone). No son errores 500.

---

## N. SEGURIDAD

```text
SMTP = NO
POST de envío = NO
cron = NO
Lanzadera Play = NO
Iniciar motor = NO
Evolution API = NO
cambiar campaña = NO
cambiar modo_entorno = NO
modificar BD = NO (solo descarga de solo lectura)
```

La única escritura fue la descarga de la BD remota a un backup local
(`backups_deploy/stats_db_pre_go_live_final_*.db`) para inspección de solo lectura.
**No se modificó la BD remota.**

---

## O. DEPLOY FINAL (sincronización app.js y lanzadera.php)

**Autorizado por el usuario:** sobrescribir únicamente `js/app.js` y
`tabs/lanzadera.php` en SiteGround.

**1. Backup remoto** (antes de sobrescribir), en
`/getfutprotec.com/backups_deploy/pre_deploy_<ts>/`:
```text
js/app.js        size=62797  md5=d2329e56d8192807492bcd598e9a78e5
tabs/lanzadera.php size=27019 md5=1d85650a99edfed81e60c82fef4eb637
```
Backup local también guardado en `backups_deploy/`.

**2. Pre-flight local** (commit e576ea8):
```text
MD5_LOCAL_APP       = b42ab1be4f082f5a5fa2acaff371f2ea
MD5_LOCAL_LANZADERA = ecc6f9359660cea007c39cc74983360f
```

**3. Despliegue:** subidos `js/app.js` (63080 bytes) y `tabs/lanzadera.php`
(28944 bytes). No se subió ningún otro archivo.

**4. Verificación post-deploy (MD5 local == remoto):**
```text
js/app.js        local=b42ab1be... remote=b42ab1be...  MATCH ✓
tabs/lanzadera.php local=ecc6f935... remote=ecc6f935... MATCH ✓
```

**5. Verificación específica:** contenido remoto confirmado (ver PARTE K).

**6. HTTP:** `https://getfutprotec.com/outbound/js/app.js?v=10` → 200, MD5 del
servido = `b42ab1be...` (coincide con local).

**7. Recheck completo:** `deploy_ftp_verify.py` → `all_ok = True`, sin MISMATCH.

**8. BD:** sin cambios (solo lectura). `modo_entorno=produccion`,
`motor_estado=pausado`, campaign 2 `PILOT/pilot/activo=1`, `integrity_check=ok`.

---

## VEREDICTO

```text
PRE_GO_LIVE_SITEGROUND_PASS
```

**Motivo:** Tras el DEPLOY FINAL, `js/app.js` y `tabs/lanzadera.php` coinciden
exactamente (LOCAL == REMOTO). `deploy_ftp_verify.py` reporta `all_ok = True` sin
ningún MISMATCH. Todos los archivos funcionales de SiteGround coinciden con el código
validado commiteado (`e576ea8`).

**PARADA RESPETADA:** No se envió, no se pulsó Play, no se inició motor, no se ejecutó
cron, no se modificó BD remota, no se cambió configuración, no se cambió campaña, no
se modificaron leads ni plantillas.

**Siguiente paso (fuera de este checkpoint):** preparar y ejecutar el primer envío
comercial controlado de UN SOLO LEAD.


