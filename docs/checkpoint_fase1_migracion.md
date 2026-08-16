# CHECKPOINT — FASE 1: MIGRACIÓN COMPLETADA

**FECHA:** 2026-08-14
**ALCANCE:** Modelo de datos de campaña y trazabilidad (solo DDL + backfill). Sin envíos. Sin cambios en motores, A/B/C, supresión, tracking, dashboard ni IMAP.

---

## FASE: 1 — MIGRACIÓN

### 1. Estado inicial detectado
**COMPLETA** — Al inspeccionar la BD posterior a la interrupción, la migración ya se había aplicado por completo:
- `envios`: columnas `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id` presentes.
- `pipelines`: columnas `identificador`, `estado`, `entorno`, `tipo`, `objetivo` presentes.
- Índices `idx_envios_lead`, `idx_envios_campaign`, `idx_envios_variant`, `idx_pipelines_identificador` presentes.
- Backfill de `lead_id` (155/156) y de `pipelines.id=1` aplicado.
No existía estado parcial que completar; por tanto **no se repitió ningún ALTER**.

### 2. Backup
**PASS** — Backups específicos de BD creados antes de la migración:
- `public_html/outbound/backups/fase1_pre_migration_20260814_202746.db` (consistente vía API `sqlite3.Connection.backup`; 16 tablas, envios=2, pipelines=1 verificados).
- Backup de FASE 0: `public_html/outbound/backups/fase0_20260814_195950/` (md5 verificado).

### 3. Migración
**PASS** — Cambios estructurales ejecutados (solo ALTER ADD COLUMN + CREATE INDEX, sin DROP):
- `pipelines` +5 columnas: `identificador`, `estado`, `entorno`, `tipo`, `objetivo`.
- `envios` +5 columnas: `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`.
- `envios.variant` definido como `VARCHAR(1)` **sin `DEFAULT ''`** → admite NULL (interpretación: NULL=legacy/no asignada; A/B/C variantes).
- Índices nuevos creados.

### 4. Backfill
**PASS WITH LIMITATIONS**
- `envios.id=1` → `lead_id=155`; `envios.id=2` → `lead_id=156` (mapeo 1:1 verificado previamente). Correcto.
- `variant`, `campaign_id`, `plantilla_id`, `smtp_id` de los 2 registros legacy **permanecen NULL** (no se inventó información).
- `pipelines.id=1` → `identificador='LEGACY_TEST_FASE1'`, `estado='DRAFT'`, `entorno='test'`, `tipo='outbound'`, `objetivo=NULL`. Verificado previamente que la fila es TEST ("Experimento Fase 1 TEST", descripción "NO REAL", 5 lead_pipelines TEST) y sin conflictos de identificador.
- Limitación: `objetivo` quedó NULL (no existía valor histórico fiable).

### 5. Integridad BD
**PASS**
- Filas antes/después conservadas: `envios` = 2, `pipelines` = 1 (sin cambios de cardinalidad).
- `clubes_crm` = 1813 (no tocada).
- `aperturas` FK hacia `envios(tracking_id)` sigue válida.
- BD abre correctamente en solo-lectura y escritura.
- La prueba de escritura con ROLLBACK devolvió `envios` a 2 filas (sin residuos).

### 6. Compatibilidad legacy
**PASS**
- Los 2 registros legacy conservan `asunto`/`cuerpo_mensaje`/`tracking_id`/`cuenta_emision` intactos.
- SELECTs usados por `dashboard.php` siguen devolviendo resultados (`total enviados=2`, agrupación por cuenta correcta).
- `track.php` puede resolver por `tracking_id` sin cambios.

### 7. Tests
**TESTS DE MODELO (Fase 1), ejecutados en transacción controlada con ROLLBACK (sin persistir, sin envío real):**
| Test | Resultado |
|---|---|
| Crear campaña PILOT (modelo `pipelines`) | **PASS** — estructura permite `estado`/`entorno`/`identificador`; campaign_id inequívoco por PK id. |
| Envío asociado a campaña recuperando lead+campaign+variant+subject+body | **PASS** — INSERT con `lead_id`,`campaign_id`,`variant`,`plantilla_id`,`smtp_id` recuperable (ver salida). |
| Tres envíos A/B/C con variante almacenada | **PASS** — `variant` = 'A','B','C' (y NULL) almacenados correctamente. |
| Dos envíos del mismo lead en campañas distintas | **PASS (por modelo)** — `campaign_id` independiente de `lead_id`; sin restricción que impida multi-campaña. |
| Reconstruir envío sin búsqueda por email | **PASS** — SELECT por `lead_id`/`campaign_id` con JOIN a `clubes_crm` funcionó. |
| Asunto/cuerpo almacenados permiten conocer el contenido exacto | **PASS** — `asunto`/`cuerpo_mensaje` presentes en la fila. |
| Compatibilidad registros legacy | **PASS** — 2 filas legacy intactas, nuevas columnas NULL. |

**Ejemplo real de registro (durante test, luego revertido):**
```
lead_id=1809, campaign_id=1, variant='A', plantilla_id=1, smtp_id=1,
asunto='ASUNTO_A', cuerpo='BODY_A', club='TEST_CLUB_01_RealMadrid'
```

### 8. Archivos modificados
- **Base de datos:** `public_html/outbound/data/stats.db` (solo estructura + backfill aprobados).
- **Código fuente:** NINGÚN archivo PHP/JS modificado.
- **Documentación generada:** `docs/checkpoint_fase1_analisis_pre_migracion.md`, `docs/checkpoint_fase1_migracion.md`.

### 9. Riesgos / limitaciones
- **RISGO:** `cli/init_db.php` NO reproduce las nuevas columnas al inicializar desde cero (la migración se aplicó directo sobre la BD). Debe sincronizarse en una fase posterior para que un despliegue limpio cree el mismo esquema.
- **LIMITACIÓN:** No hay FK declarativas (decisión aprobada); la trazabilidad se garantiza por id a nivel de aplicación.
- **LIMITACIÓN:** `campaign_id`/`plantilla_id`/`smtp_id` de los 2 envíos legacy quedan NULL (no había evidencia histórica fiable).
- **PENDIENTE (fuera de alcance):** los escritores (enviar_lote/enviar_smtp_random/cron) aún no persisten las nuevas columnas; la asignación A/B/C no se toca (Fase 3).

### 10. Conclusión
**PASS** — El modelo de datos mínimo para trazabilidad está implantado y verificado: `envios` ahora puede identificar inequívocamente lead→campaña→variante→plantilla→SMTP sin depender de la unión por email, y `pipelines` representa la campaña con identificador/estado/entorno. Los históricos legacy se conservan y quedan identificados con NULL donde no hay evidencia. No se realizaron envíos ni se modificó lógica de producto.

> NO avanzo a Fase 2. Espero aprobación.