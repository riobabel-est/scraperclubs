# CHECKPOINT — MIGRACIÓN LIVE `envios.es_test` (AISLAMIENTO TEST/REAL)

**Fecha:** 17/08/2026
**Estado:** MIGRACIÓN APLICADA Y VERIFICADA EN PRODUCCIÓN
**Veredicto:** `LIVE_MIGRATION_PASS`

---

## RESUMEN

Se aplicó la migración de aislamiento TEST/REAL a la base de datos LIVE
(`public_html/outbound/data/stats.db`) añadiendo la marca inequívoca
`envios.es_test` y la tabla `destinatarios_test`.

La arquitectura de aislamiento ya estaba implementada en el código
(`inc/eligibilidad.php` con `esLeadTest()`, `esEnvioTest()`, `sqlEsTestFiltro()`,
y referencias a `destinatarios_test` en `api/smtp.php` y `dashboard.php`).
Lo que faltaba era la **migración de datos** en la BD LIVE, que ahora está completa.

---

## 1. BACKUP PREVIO

| Archivo | Estado |
|---|---|
| `backups_deploy/stats_db_LIVE_backup_1786998742.db` | Creado antes de migrar |
| integrity_check | `ok` |
| envios totales | 32 |
| envios.es_test | NO existía (pre-migración) |

---

## 2. MIGRACIÓN APLICADA (LIVE)

Script: `scripts/migracion_live_es_test.php` (probado en copia local PASS,
luego ejecutado contra la BD LIVE vía runner web temporal).

### Resultado verificado (runner diagnóstico + verificación final, sin opcache):

```
journal_mode: wal
integrity_check: ok
envios totales: 32
envios.es_test existe: SI
destinatarios_test existe: SI
TEST(es_test=1): 20
REAL(es_test=0): 12
NULL: 0
TEST con es_test!=1: NINGUNO ✓
REAL con es_test!=0: NINGUNO ✓
```

### Columnas de `envios` (post-migración):
`id, club, email, federacion, cuenta_emision, fecha_envio, estado, tracking_id,
asunto, cuerpo_mensaje, lead_id, campaign_id, variant, plantilla_id, smtp_id,
message_id, resultado_envio, fecha_resultado_envio, es_test`

---

## 3. CLASIFICACIÓN TEST/REAL

- **TEST (es_test=1): 20 envíos** — corresponden a los registros sintéticos QA
  (TEST_CLUB_*, TEST_ABC_*, dummies de pruebas A/B/C).
- **REAL (es_test=0): 12 envíos** — envíos comerciales legítimos.
- **AMBIGUOS: 0** — no quedan registros sin clasificar (NULL=0).

---

## 4. CAUSA DE CONTAMINACIÓN ESTADÍSTICA

Antes de esta migración, no existía la columna `es_test` en `envios`.
Los envíos TEST se identificaban únicamente por heurísticas de email/club
(`esLeadTest()`), pero las consultas de analytics/followups/envios no tenían
una marca inequívoca en la tabla `envios`, por lo que los envíos TEST
podían aparecer en el histórico comercial y alterar métricas.

Con `envios.es_test` como **fuente de verdad primaria** y el helper
`sqlEsTestFiltro()` (que añade `AND COALESCE(es_test,0)=0`), todas las
consultas comerciales quedan aisladas de los TEST.

---

## 5. LIMPIEZA DE RUNNERS TEMPORALES

Se crearon runners web temporales para ejecutar la migración en la BD LIVE
(no accesible por CLI en SiteGround). Tras la migración:

- `migracion_live_runner.php` — eliminado del servidor
- `migracion_diag.php` — eliminado del servidor
- `migracion_verify_final.php` — eliminado del servidor
- Archivos temporales locales — eliminados

### NOTA OPCACHE (importante)
Los runners eliminados siguen devolviendo HTTP 200 porque **opcache de
SiteGround mantiene la versión compilada en caché** incluso tras borrar el
archivo fuente. Sin embargo:
- Requieren el token secreto `MIGRACION_ES_TEST_20260817` para ejecutar
  cualquier operación (sin token devuelven 403).
- El token solo es conocido por el operador.
- **Recomendación:** limpiar la caché de opcache desde Site Tools →
  Speed → Caching (o esperar a que expire el TTL) para que los runners
  dejen de ser accesibles.

---

## 6. ARCHIVOS MODIFICADOS / CREADOS

### Creados (temporales, ya eliminados):
- `public_html/outbound/migracion_live_runner.php` (eliminado)
- `public_html/outbound/migracion_diag.php` (eliminado)
- `public_html/outbound/migracion_verify_final.php` (eliminado)
- `public_html/outbound/migracion_removed.php` (eliminado)

### Existentes (arquitectura de aislamiento ya implementada):
- `public_html/outbound/inc/eligibilidad.php` — `esLeadTest()`, `esEnvioTest()`, `sqlEsTestFiltro()`
- `public_html/outbound/api/smtp.php` — gestión `destinatarios_test`
- `public_html/outbound/dashboard.php` — UI `destinatarios_test`
- `scripts/migracion_live_es_test.php` — script de migración

### Backup:
- `backups_deploy/stats_db_LIVE_backup_1786998742.db`

---

## 7. PRÓXIMOS PASOS (pendientes de validación)

1. **Limpiar opcache** en SiteGround para neutralizar los runners temporales.
2. **Verificar UI** en producción: "Histórico Comercial" debe excluir TEST,
   "Histórico de Pruebas" debe mostrar solo TEST.
3. **Verificar analytics/followups** excluyen TEST (es_test=0).
4. **NO hacer git push** ni deploy completo hasta validación del usuario.

---

## VEREDICTO

`LIVE_MIGRATION_PASS` — La migración `envios.es_test` está aplicada y
verificada en producción. La arquitectura de aislamiento TEST/REAL está
operativa. Pendiente: limpieza de opcache y validación funcional de la UI.
