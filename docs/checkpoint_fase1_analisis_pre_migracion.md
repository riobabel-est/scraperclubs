# FASE 1 — ANÁLISIS PRE-MIGRACIÓN (envios / campaña / trazabilidad)

**FECHA:** 2026-08-14
**ALCANCE:** Análisis de estructura, código que lee/escribe `envios`, y propuesta mínima de modelo. **Sin migrar todavía.**
**REGLA:** En esta fase NO se cambia la lógica de asignación A/B/C (eso es Fase 3). Solo se prepara el MODELO DE DATOS.

---

## MODELO ANTERIOR — TABLA `envios`

### Columnas actuales (PRAGMA table_info)
| # | columna | tipo | default | notas |
|---|---|---|---|---|
| 1 | id | INTEGER PK AUTOINCREMENT | — | clave primaria |
| 2 | club | TEXT NOT NULL | — | nombre del club (dato, no identidad) |
| 3 | email | TEXT NOT NULL | — | destinatario (dato) |
| 4 | federacion | TEXT DEFAULT '' | — | dato |
| 5 | cuenta_emision | TEXT DEFAULT '' | — | email del SMTP (no ID estable) |
| 6 | fecha_envio | DATETIME DEFAULT CURRENT_TIMESTAMP | — | timestamp |
| 7 | estado | TEXT DEFAULT 'pendiente' | — | pendiente/enviado/error/abierto |
| 8 | tracking_id | TEXT UNIQUE NOT NULL | — | identidad para píxel |
| 9 | asunto | TEXT DEFAULT '' | — | asunto exacto enviado |
| 10 | cuerpo_mensaje | TEXT DEFAULT '' | — | cuerpo exacto enviado |

### Índices actuales
- `idx_envios_estado` (estado)
- `idx_envios_cuenta` (cuenta_emision)
- `idx_envios_tracking` (tracking_id)
- `sqlite_autoindex_envios_1` (UNIQUE sobre tracking_id, auto-generado)

### Claves foráneas de `envios`
- **ninguna.** (La FK la tiene `aperturas` → `envios(tracking_id)`, NO al revés.)

### Quién ESCRIBE cada columna (verified)
1. `api/enviar_lote.php` — INSERT (club, email, federacion, cuenta_emision, estado, tracking_id, asunto, cuerpo_mensaje).
2. `api/enviar_smtp_random.php` — INSERT mismas columnas.
3. `cli/cron.php` — INSERT (club, email, federacion, cuenta_emision, estado='enviado', tracking_id, asunto, cuerpo_mensaje).
4. `cli/init_db.php` — CREATE TABLE + ALTER ADD `asunto`, `cuerpo_mensaje`.
5. `api/track.php` — UPDATE `estado='abierto'` WHERE tracking_id.

### Quién LEE cada columna (verified)
- `dashboard.php` — múltiples SELECTs; todos unen por `LOWER(e.email)=LOWER(c.email)` (FUNEL, A/B/C, followups, get_lead, get_last_envios, analytics `envios`).
- `api/track.php` — SELECT por `tracking_id`; UPDATE estado.
- `api/leads.php` — `get_estado_lanzadera` lee `cuenta_emision`, `fecha_envio`, `estado`; unión por email.
- `cli/init_db.php` — `mapEstadoLegacy()` lee `estado`, `tracking_id` por email.
- `api/enviar_smtp_random.php` — lee `email` para el filtro `--resume`.

### Registros históricos en `envios`
- 2 filas, ambas `estado='enviado'`, tracking_id únicos.
- Resolución email→lead (verificado, unívoca):
  - id=1 → `clubadpparador@gmail.com` → `clubes_crm.id=155` (A. D. PARADOR C. F.)
  - id=2 → `entretorresf7@hotmail.com` → `clubes_crm.id=156` (A.C.D. ENTRETORRES)
- No hay registros TEST en `envios`. Los TEST están en `lead_pipelines` (5 leads con nombre `TEST_CLUB_*` y email `testXX@futprotec.local`).

---

## QUÉ PUEDE AÑADIRSE SIN ROMPER LEGACY
- SQLite permite `ALTER TABLE envios ADD COLUMN` sin bloquear y sin tocar filas existentes (nuevas columnas con DEFAULT/NULL).
- Las nuevas columnas serán **NULL** en las 2 filas legacy → no se inventa información.
- Ningún SELECT existente hace `SELECT *` de forma que dependa de un número fijo de columnas (se listan columnas explícitas). No se rompe lectura.
- Los INSERT existentes enumeran columnas explícitas, así que no fallarán al añadir columnas con DEFAULT o NULL.

---

## MODELO NUEVO PROPUESTO (mínimo)

### A. Campaña → reutilizar `pipelines`
`pipelines` ya representa conceptualmente una campaña/experimento (nombre, descripción, f_inicio, f_fin, variante_ganadora, activo, created_at). **No creo tabla nueva.**

Columnas a AÑADIR a `pipelines` (mínimas):
| columna | tipo/default | propósito |
|---|---|---|
| `identificador` | TEXT (NULL) | slug único legible `OUTBOUND_CLUBES_2026_08` |
| `estado` | TEXT DEFAULT 'DRAFT' | máquina de estados mínima |
| `entorno` | TEXT DEFAULT 'test' | test / pilot / production |
| `tipo` | TEXT DEFAULT 'outbound' | tipo de campaña |
| `objetivo` | INTEGER (NULL) | objetivo numérico (p.ej. 20 clubes) |

Estados permitidos (documentados; no impongo CHECK rígido para no romper inserts existentes):
`DRAFT, READY, PILOT, ACTIVE, PAUSED, COMPLETED, ARCHIVED`.

Índice nuevo: UNIQUE parcial sobre `identificador` donde no sea NULL:
`CREATE UNIQUE INDEX ... ON pipelines(identificador) WHERE identificador IS NOT NULL;`

Backfill de la fila existente: asignar `identificador='LEGACY_TEST_FASE1'`, `estado='DRAFT'`, `entorno='test'`, `tipo='outbound'`. (No toca el resto de campos.)

### B. Envío → añadir columnas de identidad a `envios`
Columnas a AÑADIR (todas NULL por defecto, no rompen legacy):
| columna | tipo/default | propósito | ¿se puede derivar ya? |
|---|---|---|---|
| `lead_id` | INTEGER (NULL) | referencia principal al lead (`clubes_crm.id`) | NO (hoy se deriva por email) |
| `campaign_id` | INTEGER (NULL) | campaña (`pipelines.id`) | NO |
| `variant` | VARCHAR(1) DEFAULT '' | A/B/C inmutables del envío | solo en `comunicaciones_log`, no en envios |
| `plantilla_id` | INTEGER (NULL) | plantilla usada (`plantillas.id`) | NO (asunto/cuerpo están, pero no el id) |
| `smtp_id` | INTEGER (NULL) | cuenta SMTP usada (`cuentas_smtp.id`) | parcial (`cuenta_emision` email es UNIQUE, pero no es id estable) |

Índices nuevos:
- `CREATE INDEX idx_envios_lead ON envios(lead_id);`
- `CREATE INDEX idx_envios_campaign ON envios(campaign_id);`
- `CREATE INDEX idx_envios_variant ON envios(variant);`

Notas de decisión:
- `asunto` y `cuerpo_mensaje` **se reutilizan** (contenido exacto ya guardado). No añadir campos duplicados.
- `fecha_envio` ya es el timestamp. `tracking_id` ya es la identidad de tracking. `estado` ya existe.
- `cuenta_emision` (email) se conserva como dato; `smtp_id` añade identidad estable. Si se prefiere lo más simple, `smtp_id` podría omitirse porque `cuenta_emision` es UNIQUE en `cuentas_smtp`. **Recomiendo añadirlo** para que la identidad no dependa de un email editable; pero queda como decisión reversible (admite NULL).

### C. lead_id vs email
- `lead_id` pasa a ser la referencia principal. `email` se conserva como dato de destinatario.
- Las 2 filas legacy se pueden rellenar con `lead_id` 155 y 156 de forma **segura** (mapeo unívoco ya verificado). La propuesta de backfill es opcional: se puede dejar NULL y documentarlas como LEGACY/NOT AVAILABLE. **Recomiendo backfillear únicamente estas 2 filas** porque el mapeo es 1:1 y mejora la trazabilidad sin inventar datos.
- No se normalizan emails ni se reasignan duplicados en esta fase (fuera de alcance).

### D. Entorno TEST / PILOT / PRODUCTION
- `config.modo_entorno` se mantiene como **interruptor global del motor de envío** (hoy: test/pausado). 
- `pipelines.entorno` clasifica **la campaña** (test/pilot/production) y es lo que permitirá excluir datos de métricas.
- No duplico: son dos conceptos distintos (modo operativo global vs. atributo de campaña). No se añade un tercer campo redundante.
- Ampliar `modo_entorno` a soportar `piloto` es decisión de Fase 3 (no se toca en Fase 1).

---

## RELACIONES NUEVAS (lógica)
```
clubes_crm(lead) 1──N envios.lead_id
pipelines(campaign) 1──N envios.campaign_id
plantillas 1──N envios.plantilla_id
cuentas_smtp 1──N envios.smtp_id
```
Se documentan como relaciones lógicas (FK declarativas opcionales; SQLite + `foreign_keys=ON` ya está activado en init_db, pero la ausencia de FK declarativas no impide la trazabilidad por id). Para minimizar riesgo, **no se crean FK declarativas** en esta fase (evita errores si hay ids legacy nulos); la integridad se conserva por id.

---

## MIGRACIÓN PROPUESTA (solo ALTER ADD, sin DROP)
1. `ALTER TABLE pipelines ADD COLUMN identificador TEXT;`
2. `ALTER TABLE pipelines ADD COLUMN estado TEXT DEFAULT 'DRAFT';`
3. `ALTER TABLE pipelines ADD COLUMN entorno TEXT DEFAULT 'test';`
4. `ALTER TABLE pipelines ADD COLUMN tipo TEXT DEFAULT 'outbound';`
5. `ALTER TABLE pipelines ADD COLUMN objetivo INTEGER;`
6. Backfill 1 fila `pipelines`: identificador/estado/entorno/tipo.
7. `CREATE UNIQUE INDEX idx_pipelines_identificador ON pipelines(identificador) WHERE identificador IS NOT NULL;`
8. `ALTER TABLE envios ADD COLUMN lead_id INTEGER;`
9. `ALTER TABLE envios ADD COLUMN campaign_id INTEGER;`
10. `ALTER TABLE envios ADD COLUMN variant VARCHAR(1) DEFAULT '';`
11. `ALTER TABLE envios ADD COLUMN plantilla_id INTEGER;`
12. `ALTER TABLE envios ADD COLUMN smtp_id INTEGER;`
13. `CREATE INDEX idx_envios_lead ON envios(lead_id);`
14. `CREATE INDEX idx_envios_campaign ON envios(campaign_id);`
15. `CREATE INDEX idx_envios_variant ON envios(variant);`
16. (Opcional) Backfill `lead_id` en 2 filas legacy (155, 156).

**Rollback:** backup específico de `stats.db` antes de migrar; restaurar copiando el archivo. No DROP TABLE. No se borran datos. No se modifican históricos salvo el backfill opcional justificado.

---

## IMPACTO Y COMPATIBILIDAD
- Los INSERT/UPDATE/SELECT existentes **siguen funcionando** (columnas nuevas con NULL/DEFAULT).
- `track.php` (UPDATE estado) no se ve afectado.
- Ningún lector existente falla por columnas nuevas.
- Compatibilidad SiteGround: SQLite3 + ALTER TABLE ADD COLUMN + partial index = soportado (SQLite ≥3.8, disponible en PHP 8.x).

---

## DECISIONES QUE REQUIEREN APROBACIÓN
1. ¿Añadir `smtp_id` o mantener solo `cuenta_emision` (email UNIQUE)? **Recomendado: añadir.**
2. ¿Backfillear `lead_id` en las 2 filas legacy? **Recomendado: sí (mapeo 1:1).**
3. ¿Confirmar reutilizar `pipelines` como entidad de campaña (sin tabla nueva)? **Recomendado: sí.**
4. ¿Crear FK declarativas? **Recomendado: no en esta fase.**

> No ejecuto la migración hasta recibir aprobación explícita.