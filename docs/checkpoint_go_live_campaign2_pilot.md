# CHECKPOINT — GO-LIVE.1A Verificación Final Campaign 2 (DRAFT → PILOT)

**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Campaign 2 (Piloto Comercial)
**Estado:** **BLOCKED**

---

## A. PRE

El cambio de `campaign 2` de `DRAFT → PILOT` fue aplicado y subido al servidor remoto
antes de esta verificación. No se reanalizó código ni se reintentaron pasos previos.

Se descargó la BD remota de producción (`stats.db`) en SOLO LECTURA y se verificó el
estado post-cambio.

**Archivo verificado (solo lectura):**
```text
ruta      = backups_deploy/stats_db_go_live1a_post.db
tamaño    = 921600 bytes
md5       = d1b8696d1ce572713d50584884120d77
timestamp = 2026-08-17 15:56
```

---

## B. CAMBIO (DRAFT → PILOT)

Cambio declarado y ya subido al remoto:
```text
campaign 2 (pipelines.id=2):
  estado  = DRAFT → PILOT
  entorno = pilot (sin cambios)
  activo  = 1 (sin cambios)
```

---

## C. POST-CAMBIO (VALORES REALES)

Verificación en SOLO LECTURA de la BD remota descargada:

```text
campaign 2:
  estado  = PILOT      ✓ (coincide)
  entorno = pilot      ✓ (coincide)
  activo  = 1          ✓ (coincide)

config:
  modo_entorno = produccion   ✓ (coincide)
  motor_estado = pausado      ✓ (coincide)

envios campaign 2 = 1         ✗ (NO coincide — se esperaba 0)

PRAGMA integrity_check = ok   ✓ (coincide)
```

### Detalle del envío detectado en campaign 2
```text
id                = 18
club              = aaaa
email             = info@fsnazareno.es
federacion        = (vacía)
cuenta_emision    = adrian.cano@getfutprotec.com
fecha_envio       = 2026-08-17 03:08:09
estado            = enviado
tracking_id       = fut_6a827b19_eee21c2aa263
lead_id           = 1808
campaign_id       = 2
variant           = B
plantilla_id      = 3
smtp_id           = 9
resultado_envio   = ACCEPTED
```

Total de registros en `envios` = 18.

---

## D. INTEGRIDAD

- `PRAGMA integrity_check` = `ok` (BD válida).
- La BD se abrió en modo SOLO LECTURA (`SQLITE3_OPEN_READONLY`); no se realizó ninguna
  escritura sobre la BD remota ni sobre la copia local.

---

## E. SEGURIDAD

```text
SMTP = NO
POST envío = NO
cron = NO
Play = NO
Lanzadera = NO
modificar BD adicionalmente = NO
modificar config = NO
modificar leads = NO
modificar plantillas = NO
```

No se ejecutó ninguna acción de envío ni modificación. Únicamente lectura de la BD
descargada.

---

## F. VEREDICTO

```text
BLOCKED
```

**Motivo:** El valor `envios campaign 2` es `1`, no `0` como se esperaba. Existe un
registro de envío real (id=18, `campaign_id=2`, `estado=enviado`, `resultado_envio=ACCEPTED`,
fecha 2026-08-17 03:08:09) en la BD remota post-cambio. Al no coincidir todos los valores
esperados, el veredicto es **BLOCKED**.

**PARADA RESPETADA:** Tras el veredicto, no se realiza ninguna acción adicional.
