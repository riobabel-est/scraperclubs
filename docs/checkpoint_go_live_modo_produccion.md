# CHECKPOINT — GO-LIVE.0 Cambio Controlado del Entorno Remoto

**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Entorno global
**Estado:** **GO_LIVE_ENV_PRODUCTION_PASS**

---

## A. PRE-CAMBIO

Se descargó la BD remota de producción (`/getfutprotec.com/public_html/outbound/data/stats.db`)
y se verificó en SOLO LECTURA:

```text
config.modo_entorno = test
config.motor_estado = pausado

campaign 2:
  estado  = DRAFT
  entorno = pilot
  activo  = 1

envios campaign 2 = 0
```

**Backup realizado:**
```text
ruta      = backups_deploy/stats_db_go_live0_pre_20260817_045809.db
tamaño    = 917504 bytes
md5       = 462ec57ac492e859fcfa07670123ebec
timestamp = 2026-08-17 04:58:09
```

---

## B. CAMBIO

Se ejecutó exclusivamente:

```sql
UPDATE config
SET valor = 'produccion'
WHERE clave = 'modo_entorno';
```

**Verificación del cambio aislado (working copy):**
- `modo_entorno = produccion` ✓ (único campo modificado)
- `motor_estado = pausado` ✓ (sin cambios)
- campaign 2 = DRAFT / pilot / activo=1 ✓ (sin cambios)
- envios campaign 2 = 0 ✓ (sin cambios)
- count `modo_entorno` = 1 ✓
- total config rows = 7 ✓ (sin cambios)
- `PRAGMA integrity_check` = ok ✓

**Subida a remoto:**
```text
size local  = 917504 bytes
md5 local   = 8138962e457d751c5c6283a2d3f3df61
size remoto = 917504 bytes (POST)
mtime remoto = 2026-08-17 02:58:31
```

---

## C. POST-CAMBIO

Se descargó nuevamente la BD remota y se verificó:

```text
config.modo_entorno = produccion   ✓ (cambiado)
config.motor_estado = pausado      ✓ (sin cambios)

campaign 2:
  estado  = DRAFT                   ✓ (sin cambios)
  entorno = pilot                   ✓ (sin cambios)
  activo  = 1                       ✓ (sin cambios)

envios campaign 2 = 0               ✓ (sin cambios)
total config rows = 7               ✓ (sin cambios)
PRAGMA integrity_check = ok         ✓
```

**MD5 remoto POST = `8138962e457d751c5c6283a2d3f3df61`** — coincide exactamente con el
working copy subido, confirmando integridad de la transferencia.

**Coherencia de entorno:**
```text
esEntornoCoherente('produccion', 'pilot') = coherente   ✓
```

---

## D. INTEGRIDAD

- `PRAGMA integrity_check` = `ok` (BD válida tras el cambio).
- MD5 remoto POST == MD5 working copy subido (transferencia íntegra).
- Número de filas en `config` sin cambios (7).
- Solo se modificó la fila `modo_entorno`; ninguna otra fila de `config` ni de otras tablas.

---

## E. SEGURIDAD

```text
SMTP = NO
POST envío = NO
cron = NO
Evolution API = NO
nuevo envio = NO
campaign modificada = NO
leads modificados = NO
plantillas modificadas = NO
motor_estado = pausado (sin cambios)
```

La única escritura deliberada fue `config.modo_entorno: test → produccion`.

---

## F. VERIFICACIÓN HTTP

```text
https://getfutprotec.com/outbound/dashboard.php → HTTP 200
https://getfutprotec.com/                        → HTTP 200
```

El dashboard remoto responde correctamente tras el cambio. No se ejecutó ningún endpoint
de envío.

---

## G. VEREDICTO

```text
GO_LIVE_ENV_PRODUCTION_PASS
```

**PARADA RESPETADA:** No se cambió `campaign 2` de `DRAFT`. No se envió. No se inició motor.
No se ejecutó cron. Se espera la siguiente fase.
