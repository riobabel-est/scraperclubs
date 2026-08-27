# CHECKPOINT — Sync `stats.db` SiteGround → Local (2026-08-27)

**Operación:** Revisión de cambios en la BD de producción (SiteGround) y **reconstrucción de la BD local** con la estrategia decidida por el usuario: **DATOS = producción (remoto) · ESTRUCTURA = local**.

## 1. Backups previos (obligatorios)

| Backup | Archivo |
|---|---|
| Local (antes del sync, con datos locales) | `stats.db.bak_pre_sync_20260827_023450` |
| Local (datos locales completos, antes de reconstruir) | `stats.db.bak_local_datos_20260827` |
| Online descargada (copia de seguridad de producción) | `stats.db.remoto_20260827_023455` (MD5 `c70f42d65fc76ab7758c9f451b700851`) |

## 2. Comparación (local vs remoto)

- La **local** era estructuralmente más avanzada (tablas `secuencias`, `secuencia_pasos`, `propuestas_ia`, `campaign_*`, plantillas reorganizadas).
- La **remota** tenía los **datos operativos reales de producción** (clubes 1818, envíos 179, aperturas 173, respuestas 9, pipelines 3, `lanzadera_delay=8`).

## 3. Estrategia aplicada (decisión del usuario)

> *"Todos los datos que estén en la base de datos online son los buenos, pero mantén la estructura de local para subir todo a producción sin conflictos."*

1. **Datos**: se partió de la BD **remota descargada** (datos de producción íntegros).
2. **Estructura local aplicada** sobre esos datos:
   - Tablas nuevas: `propuestas_ia` (con `fecha_prevista`), `secuencias`, `secuencia_pasos`, `campaign_segmentos`, `campaign_plantillas`.
   - `envios.secuencia_id` + `envios.paso_secuencia` + índice único `idx_envios_sec_paso`.
   - **Plantillas reorganizadas** (script `migrar_plantillas_objetivo.php`): categorías `01 Prospección · 02 Seguimiento · 03 Respuestas`, nombres "Paso N", plantillas Paso 2 y Paso 3 creadas. Se conservan las plantillas legacy de producción (`Prospección con precio`, `Primera plantilla`) en `01 Prospección`.

## 4. Estado final de la BD local (`data/stats.db`)

- `PRAGMA integrity_check = ok`
- **Datos de producción**: clubes 1818 · envios 179 · aperturas 173 · respuestas 9 · pipelines 3 · `lanzadera_delay = 8`.
- **Estructura local**: propuestas_ia, secuencias, secuencia_pasos, campaign_segmentos, campaign_plantillas (vacías, listas para configurar) + columnas de secuencia en `envios` + índice.
- **Plantillas**: 8 (Paso 1 + Paso 2 + Paso 3 + Respuestas ×2 + WhatsApp genérica + Prospección con precio + Primera plantilla).

## 5. Herramientas usadas (scripts/)

- `scripts/sync_statsdb_siteground.py` — descarga la BD remota vía FTP (credenciales del `.env`).
- `scripts/comparar_bd.php` / `scripts/diff_bd.php` — comparación y diff fino.
- `cli/migrar_estructura_local.php` — aplica la estructura local a una BD con datos de producción.
- `cli/migrar_plantillas_objetivo.php` — reorganización idempotente de plantillas.

## 6. Siguiente paso (cuando el usuario lo confirme)

- Subir la BD local reconstruida a producción (deploy) para que producción tenga la estructura avanzada sin perder datos.

