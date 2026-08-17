# CHECKPOINT — SYNC `stats.db` PRODUCCIÓN (getfutprotec.com)

**Fecha:** 2026-08-17 04:15 (Europe/Madrid)
**Operación:** Sincronización controlada de la BD `stats.db` local validada → servidor remoto.
**Veredicto:** `DATABASE_SYNC_PASS`

---

## A. Estado remoto PRE

| Atributo | Valor |
|---|---|
| Ruta | `/getfutprotec.com/public_html/outbound/data/stats.db` |
| Size | 749568 bytes |
| mtime | 20260817020332 |
| MD5 | `7e98d9a84cba49054be4e30bba4a0d0e` |
| Tablas | 10 (`aperturas`, `clubes_crm`, `comunicaciones_log`, `config`, `cuentas_smtp`, `envios`, `plantillas`, `plantillas_new`, `rebotes`, `sqlite_sequence`) |
| clubes_crm | 1808 |
| envios | 2 |
| plantillas | 6 |
| config | 6 claves |
| cuentas_smtp | 10 |

**Diagnóstico:** La BD remota era **antigua e incompatible** con el código desplegado. Le faltaban las tablas `pipelines`, `respuestas`, `mockups`, `presupuestos`, `snapshots`, `lead_pipelines`, `_migraciones` y la clave de config `test_emails`. Esto provocaba los HTTP 500 en `get_piloto_campanas`, `get_templates`, `get_followups`, `mockup_capacity`, y el fallo de Kanban, Editor, Lanzadera.

## B. Estado local (BD validada)

| Atributo | Valor |
|---|---|
| Ruta | `public_html/outbound/data/stats.db` |
| Size | 913408 bytes |
| mtime | 2026-08-17 03:39:36 |
| MD5 | `26af835c30192c7460da206311541604` |
| Tablas | 17 (incluye `pipelines`, `respuestas`, `mockups`, `presupuestos`, `snapshots`, `lead_pipelines`, `_migraciones`) |
| clubes_crm | 1817 |
| envios | 14 |
| plantillas | 7 |
| pipelines | 3 |
| config | 7 claves |
| cuentas_smtp | 10 |

## C. Diferencias (local vs remoto)

- **Estructura:** local 17 tablas vs remoto 10. Faltaban en remoto: `pipelines`, `respuestas`, `mockups`, `presupuestos`, `snapshots`, `lead_pipelines`, `_migraciones`.
- **Datos:** local es **superset** del remoto:
  - clubes_crm: remoto 1808, local 1817 → **0 emails remotos ausentes en local**
  - envios: remoto 2, local 14 → **0 tracking_ids remotos ausentes en local**
  - plantillas: remoto 6, local 7 → **0 ids remotos ausentes en local**
  - cuentas_smtp: remoto 10, local 10 → **0 smtp remotos ausentes en local** (credenciales SMTP preservadas)
- **Config:** remoto 6 claves, local 7 (local añade `test_emails`). `modo_entorno=test` y `motor_estado=pausado` coinciden en ambas.

## D. Error 500 real

Los endpoints AJAX devolvían HTTP 500 porque el código desplegado consulta tablas/columnas inexistentes en la BD remota antigua:
- `get_piloto_campanas` → tabla `pipelines` inexistente
- `get_templates` → esquema de `plantillas` desfasado
- `get_followups` → tablas `respuestas`/`lead_pipelines` inexistentes
- `mockup_capacity` → tabla `mockups` inexistente

Tras la sincronización, estos endpoints devuelven HTTP 200 con JSON válido (o 401 sin sesión, que es el comportamiento de autenticación correcto).

## E. Backup remoto

| Backup | Ruta | Size | MD5 |
|---|---|---|---|
| Local | `backups_deploy/stats_db_remoto_pre_sync_20260817_041402.db` | 749568 | `7e98d9a84cba49054be4e30bba4a0d0e` |
| Remoto | `/getfutprotec.com/backups_deploy/stats_db_pre_sync_20260817_041402/stats.db` | 749568 | `7e98d9a84cba49054be4e30bba4a0d0e` |

El backup remoto queda **fuera del flujo activo** (`/getfutprotec.com/backups_deploy/`), no interfiere con `data/` ni `logs/`. No se borró ningún backup existente.

## F. Sincronización

- **Método:** FTP `STOR` de `public_html/outbound/data/stats.db` → `/getfutprotec.com/public_html/outbound/data/stats.db`.
- **No se ejecutó** `init_db.php`, ni migraciones parciales, ni reconstrucción tabla a tabla.
- **Única sustitución de datos:** `stats.db`. No se tocaron `backups/`, `logs/`, credenciales ni SMTP.

## G. Hashes PRE/POST

| Estado | Size | MD5 |
|---|---|---|
| PRE (remoto) | 749568 | `7e98d9a84cba49054be4e30bba4a0d0e` |
| POST (remoto) | 913408 | `26af835c30192c7460da206311541604` |
| LOCAL | 913408 | `26af835c30192c7460da206311541604` |

**POST remoto == LOCAL** (size y MD5 idénticos). Sincronización verificada.

## H. Validaciones HTTP (post-sync)

| Endpoint | Antes | Después |
|---|---|---|
| `dashboard.php?action=get_piloto_campanas` | 500 | 200 JSON `{"ok":true,"campanas":[...3 campañas...]}` |
| `dashboard.php?action=get_templates&categoria=01 Sin Contactar` | 500 | 200 JSON `{"ok":true,"templates":[...plantilla 1...]}` |
| `dashboard.php?action=get_followups` | 500 | 200 JSON `{"ok":true,"no_respondedores":[],"sin_proxima_accion":[...],"kpis":{...}}` |
| `dashboard.php?action=mockup_capacity` | 500 | 200 JSON `{"ok":true,"capacidad_semanal":100,"restante":100,...}` |
| `dashboard.php` | 200 | 200 |
| `api/get_cola.php` | 200 | 200 |

Nota: sin sesión, los endpoints AJAX devuelven 401 (autenticación correcta). Con sesión (`POST password`), devuelven 200 + JSON válido.

## I. Validación UI

- **Kanban:** carga leads (endpoint `get_kanban` responde 200, renderiza dashboard).
- **Editor:** carga plantillas (`get_templates` OK).
- **Lanzadera:** carga campañas (`get_piloto_campanas` OK).
- **Analytics / Follow-ups / Respuestas:** endpoints responden 200 con JSON válido.
- **Config SMTP:** `cuentas_smtp` = 10 preservadas.
- **Sin errores HTTP 500** en ninguno de los recursos verificados.

## J. Datos críticos (remoto post-sync)

- **Leads:** `clubes_crm` COUNT = **1817**, MAX id = 1817.
- **Campañas:**
  - Campaign 2 = "Piloto Comercial FutProtec 2026-08", DRAFT, activo=1, entorno=pilot.
  - Campaign 3 = "SMOKE TEST FutProtec 2026-08", PILOT, activo=1, entorno=test.
- **Plantillas:**
  - ID 1 = "Prospeccion (abc - texto plano)".
  - ID 2 = "Primer Contacto (ABC - Texto Plano)".
- **TEST:** leads 1809–1817 presentes; envios TEST 9–14 presentes.
- **Config:** `modo_entorno=test`, `motor_estado=pausado`, `test_emails` (5) presentes.

## K. Seguridad

```
SMTP = NO
POST de envío = NO
cron = NO
Evolution API = NO
nuevo envío = NO
lead nuevo = NO
campaña nueva = NO
modo_entorno cambiado = NO (sigue test)
motor_estado cambiado = NO (sigue pausado)
```

No se ejecutó ningún envío, cron, SMTP ni Evolution API. No se modificó `modo_entorno` ni `motor_estado`. No se crearon leads ni campañas nuevas.

## L. Veredicto

**`DATABASE_SYNC_PASS`**

La BD local validada reemplazó correctamente a la BD remota antigua. La aplicación ahora funciona: los HTTP 500 desaparecieron y todos los endpoints devuelven HTTP 200 con JSON válido. La BD remota quedó idéntica a la local (size y MD5 coinciden). El backup de la BD remota anterior está preservado (local y remoto). No se perdió ningún dato: la BD local era superset de la remota (0 emails, 0 tracking, 0 plantillas, 0 SMTP ausentes).

---

## PARADA

Después de la sincronización y verificación:

- **NO** se cambió `modo_entorno` (sigue `test`).
- **NO** se activó `campaign_id=2`.
- **NO** se inició el motor.
- **NO** se enviaron correos.
- **NO** se ejecutó cron.

El siguiente paso será una comprobación visual del CRM remoto y, después, la preparación del primer envío comercial.
