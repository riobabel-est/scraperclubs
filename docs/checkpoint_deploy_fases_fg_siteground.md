# CHECKPOINT — DEPLOY FASES F/G A SITEGROUND

**Fecha:** 2026-08-19 04:03 (Europe/Madrid)
**Estado:** ✅ DEPLOY COMPLETADO Y VERIFICADO
**Modo:** Deploy de código (no se tocó la BD remota)

---

## 1. CONTEXTO

Las Fases F (IMAP + registro de respuestas) y G (notificaciones) del Plan Maestro
de Evolución Post-Core estaban implementadas y verificadas en local, pero **NO
habían sido desplegadas a SiteGround**.

Antes de desplegar, se realizó una verificación READ-ONLY de la BD remota para
confirmar que la tabla `respuestas` existía (requisito para que
`imap_asegurar_esquema()` funcione correctamente).

---

## 2. VERIFICACIÓN PRE-DEPLOY (READ-ONLY)

**Script:** `scripts/verify_remote_respuestas_readonly.py`

Resultado de la BD remota (`stats.db`, 1.073.152 bytes, mtime 2026-08-19 00:20):

- 18 tablas (idénticas a local)
- Tabla `respuestas` **EXISTE** con las 14 columnas base
- **0 filas** (vacía)

**Conclusión:** El riesgo de fallo de `imap_asegurar_esquema()` es NULO.
La tabla base ya existe en remoto, por lo que el `ALTER TABLE ADD COLUMN`
añadirá las 11 columnas nuevas de forma idempotente al primer uso del CLI IMAP.

**NO fue necesario usar `sync_db_upload.py`** (que sobrescribiría toda la BD
remota con la local).

---

## 3. DEPLOY DE CÓDIGO

**Script:** `scripts/deploy_outbound_full.py`

Resultado: **44/44 archivos subidos OK | 0 MISMATCH | 0 ERROR**
`VEREDICTO: DEPLOY_OUTBOUND_FULL_PASS`

Archivos clave de las Fases F/G incluidos:

| Archivo | Tamaño | MD5 |
|---|---|---|
| `inc/imap_respuestas.php` | 27.018 | 0ba5b641ba845088e491c972223c6e38 |
| `cli/imap_respuestas.php` | 6.665 | e826d09c449bcc3d67b86d9e41b69a26 |
| `api/analytics.php` | 34.051 | 9aab3b7ea4f3fe2460a5b22fea02afb7 |
| `tabs/respuestas.php` | 13.467 | b2eab395198efb35dfa3265a04df7bee |
| `js/app.js` | 84.473 | 3f3f332612075e67ac8e6a5489773c4d |
| `dashboard.php` | 29.104 | 291e23b656a8a0acd14506ea68f774d0 |
| `inc/respuestas.php` | 8.615 | 871d4a623515a9b86d70455f98eb246a |

---

## 4. VERIFICACIÓN POST-DEPLOY (READ-ONLY)

**Script:** `scripts/verify_remote_fg_deploy.py`

Resultado: **TODOS OK** — los 7 archivos clave de las Fases F/G están presentes
en SiteGround con tamaño y MD5 idénticos a local.

---

## 5. ESTADO ACTUAL

- ✅ Código de Fases F/G desplegado y verificado en SiteGround
- ✅ Tabla `respuestas` base confirmada en BD remota (14 columnas, vacía)
- ⏳ **PENDIENTE:** Ejecutar el CLI IMAP en remoto para:
  1. `imap_asegurar_esquema()` añada las 11 columnas nuevas a `respuestas`
  2. Procesar los buzones IMAP y registrar respuestas
  3. Activar notificaciones y Kanban

**⚠️ IMPORTANTE:** Ejecutar el CLI IMAP en remoto es una **operación de
escritura en producción** (INSERT en BD + posible movimiento de Kanban).
Requiere confirmación explícita del usuario antes de ejecutarse.

---

## 6. ARTEFACTOS GENERADOS

- `scripts/verify_remote_respuestas_readonly.py` — verificación READ-ONLY de BD remota
- `scripts/verify_remote_fg_deploy.py` — verificación READ-ONLY del deploy F/G
- `backups_deploy/stats_db_remoto_readonly_20260819_040106.db` — backup de referencia BD remota
- `backups_deploy/deploy_outbound_full_manifest.txt` — manifest del deploy

---

## 7. PRÓXIMO PASO RECOMENDADO

Ejecutar en remoto (requiere aprobación):

```bash
php cli/imap_respuestas.php
```

Esto:
1. Añadirá las columnas nuevas a `respuestas` (idempotente)
2. Procesará los buzones IMAP de las cuentas SMTP activas
3. Registrará respuestas con atribución a lead/envío/campaña
4. Notificará y moverá Kanban para respuestas humanas
