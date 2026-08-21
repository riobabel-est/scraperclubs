# Checkpoint — Unibox: Mapeo SQL corregido + Cron IMAP automatizado

**Fecha:** 2026-08-20
**Alcance:** `/public_html/outbound/`
**Objetivo:** Completar el megaprompt de reparación y rediseño de la Unibox (split-view): corregir el mapeo de columnas SQL de `clubes_crm` y `respuestas`, implementar fallback dinámico para evitar el caracter `—`, habilitar la visualización del cuerpo del correo (HTML/Texto) y automatizar el Cron IMAP.

---

## Resumen de estado frente al megaprompt

### TAREA 1 — Refactorizar consulta en `api/analytics.php` ✅ COMPLETADA
La consulta del endpoint `get_respuestas` ya usa las columnas reales del esquema de `stats.db`:

- `respuestas` NO tiene columna `email` → se usa `remitente`/`destinatario`.
- `clubes_crm` NO tiene `contacto_nombre`/`volumen_equipos`/`variante` → se usan `persona_contacto`, `volumen_estimado`/`num_jugadores`.
- JOIN con `clubes_crm` por `lead_id` O por `remitente` (email).
- `COALESCE` blinda los campos clave del club y el snippet contra nulos.

**Validación:** La consulta se ejecutó contra `stats.db` real → `SQL OK`, devolviendo 2 filas reales (id=5 `rodrigo@riobabel.com`, id=6 `Mailer-Daemon@...`). El mapeo de columnas es correcto.

### TAREA 2 — Fallback dinámico para evitar el caracter `—` ✅ COMPLETADA
- En la consulta SQL: `COALESCE(c.nombre_club, ...)`, `COALESCE(c.persona_contacto, 'Sin Contacto')`, `COALESCE(c.telefono_movil, c.telefono_fijo, 'Sin teléfono')`, `COALESCE(c.volumen_estimado, c.num_jugadores, '10')`, `COALESCE(c.estado_lead, '03 Respondió')`.
- En el agrupamiento PHP: fallback en cascada `nombre_club` → `club` → `remitente_email` → `'Club Desconocido'` (nunca `—`).

### TAREA 3 — Visualización del cuerpo del correo (HTML/Texto) ✅ COMPLETADA
En `tabs/respuestas.php` (visor split-view):
- Si existe `contenido_html` → se renderiza sanitizado con `x-html="rsSanitizarHtml(m.contenido_html)"`.
- Si no → se muestra `cuerpo_texto`/`cuerpo` con `x-text` (texto plano, `pre-wrap`).
- La función `limpiarCuerpoMime()` en `api/analytics.php` reduce el MIME crudo a texto plano legible.

### TAREA 4 — Automatizar el Cron IMAP ✅ COMPLETADA (nuevo runner web)
**Archivo nuevo:** `public_html/outbound/cli/imap_respuestas_cron.php`

Runner web **permanente** para Cron Job HTTP (SiteGround no permite CLI directo en planes compartidos). Reutiliza la lógica completa del CLI `cli/imap_respuestas.php` (IMAP + POP3, atribución, idempotencia, detención de secuencias, logs).

**Características de seguridad y robustez:**
- **Token secreto** con `hash_equals()` (timing-safe). Se lee de la variable de entorno `IMAP_CRON_SECRET` o de la constante por defecto.
- **Protección anti-concurrencia** con `flock()`: evita que dos cron solapados procesen el mismo buzón a la vez.
- **Modo auditoría** (sin `?apply=1`) vs **modo aplicar** (con `?apply=1`).
- **Logs** con marcas de tiempo en `logs/imap_sync.log`.
- **PHP 8.x nativo** — SiteGround compatible (sin extensiones PECL externas).

---

## Configuración del Cron Job en SiteGround

### Opción A — Cron HTTP (recomendado para planes compartidos)
En el panel de SiteGround → **Site Tools → Devs → Cron Jobs**, crear un cron que haga una petición HTTP al runner:

```
*/5 * * * * curl -s "https://TU_DOMINIO/outbound/cli/imap_respuestas_cron.php?token=TU_SECRETO&apply=1" > /dev/null 2>&1
```

- **Frecuencia sugerida:** cada 5 minutos (`*/5 * * * *`).
- **`TU_SECRETO`:** debe coincidir con `IMAP_CRON_SECRET` (variable de entorno) o con la constante por defecto del archivo.
- **`apply=1`:** necesario para que escriba en la BD. Sin él, solo audita.

### Opción B — Cron CLI (solo planes GoGeek/Cloud)
Si el plan permite CLI directo:

```
*/5 * * * * php /ruta/absoluta/public_html/outbound/cli/imap_respuestas.php > /dev/null 2>&1
```

---

## Verificación de sintaxis (local)

```
php -l public_html/outbound/cli/imap_respuestas_cron.php   → No syntax errors
php -l public_html/outbound/api/analytics.php              → No syntax errors
php -l public_html/outbound/cli/imap_respuestas.php        → No syntax errors
```

---

## Notas de compatibilidad SiteGround

- Sin extensiones PECL externas (no depende de la extensión PHP `imap`).
- PHP 8.x nativo + SQLite3.
- Sockets directos (mismo patrón que `enviarSMTP()` en `cron.php`).
- MODO READ-ONLY sobre el buzón: no se ejecuta STORE/COPY/MOVE/DELETE/EXPUNGE/DELE.
- El runner web usa `flock` para evitar ejecuciones concurrentes.

---

## Archivos implicados

| Archivo | Acción |
|---|---|
| `public_html/outbound/api/analytics.php` | Ya refactorizado (TAREA 1-3) — verificado |
| `public_html/outbound/tabs/respuestas.php` | Ya implementado (TAREA 3) — verificado |
| `public_html/outbound/cli/imap_respuestas_cron.php` | **NUEVO** — runner web permanente para Cron IMAP |
| `public_html/outbound/cli/imap_respuestas.php` | CLI existente (fuente de verdad) — verificado |

---

## Deploy a producción (SiteGround) — COMPLETADO ✅

**Fecha:** 2026-08-20 04:48 (Europe/Madrid)

### Deploy 1 — Runner Cron IMAP (`scripts/deploy_imap_cron_runner.py`)
| Archivo | Estado | Tamaño | MD5 |
|---|---|---|---|
| `cli/imap_respuestas_cron.php` | ✅ OK | 11403 bytes | `51ae1f0771a49d1c69ba099be786a462` |

### Deploy 2 — Unibox (`scripts/deploy_ftp_selective.py`)
| Archivo | Estado | Tamaño | MD5 |
|---|---|---|---|
| `api/analytics.php` | ✅ OK | 46302 bytes | `f1bd6cd260b171f51935868152572c9c` |
| `tabs/respuestas.php` | ✅ OK | 12955 bytes | `6530816e0b02a70980022a2d108ce3b2` |
| `js/app.js` | ✅ OK | 97687 bytes | `84bb384a4532dd507a0a10835262374f` |
| `dashboard.php` | ✅ OK | 35380 bytes | `747462ee16da596b302efa971004c070` |

**Backups remotos creados antes de sobrescribir:**
- `/getfutprotec.com/backups_deploy/outbound_imap_cron_20260820_044758`
- `/getfutprotec.com/backups_deploy/outbound_pre_micro_20260820_044805`

**Nota:** El runner cron IMAP era un archivo nuevo (no existía versión previa en remoto), por lo que no requirió backup previo.

---

*Fin del checkpoint.*
