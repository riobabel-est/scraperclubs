# AUDIT_TEST_ISOLATION_COMPLETE — Aislamiento TEST/REAL del CRM Outbound

Fecha: 2026-08-17
Estado: **TEST_ISOLATION_ARCHITECTURE_PASS** (solo local, sin deploy)

---

## 1. Arquitectura actual

El CRM outbound (`public_html/outbound/`) usa SQLite (`data/stats.db`) con las tablas:
`clubes_crm`, `envios`, `aperturas`, `comunicaciones_log`, `pipelines`, `config`,
`cuentas_smtp`, `respuestas`, `rebotes`, `mockups`, `presupuestos`.

La identidad TEST/REAL se centraliza en `inc/eligibilidad.php`:

- `esLeadTest(array $lead): bool` — lead TEST si email contiene `@futprotec.local`
  o `nombre_club` empieza por `test`.
- `esCampanaTest(SQLite3 $db, int $idCampana): bool` — campaña TEST si
  `pipelines.entorno = 'test'`.
- `esEnvioTest(array $envio): bool` — envío TEST si `es_test = 1` (fuente primaria)
  o email/club legacy TEST (red de seguridad).
- `sqlFiltroComercial(string $alias = 'e'): string` — fragmento SQL
  `AND COALESCE(es_test,0) = 0` para todas las consultas comerciales.
- `sqlFiltroCompatibilidadLeadCampana()` — espejo SQL de esLeadTest() para
  get_cola.php y cron.php.

## 2. Registros TEST detectados (12)

| id | lead | club | email | camp | tpl | fecha | estado |
|----|------|------|-------|------|-----|-------|--------|
| 3 | 1809 | TEST_CLUB_01_RealMadrid | test01@futprotec.local | 3 | 2 | 2026-08-14 | enviado |
| 4 | 1813 | TEST_CLUB_05_Bilbao | test05@futprotec.local | 3 | 2 | 2026-08-15 | enviado |
| 5 | 1811 | TEST_CLUB_03_Valencia | test03@futprotec.local | 3 | 2 | 2026-08-15 | enviado |
| 6 | 1810 | TEST_CLUB_02_Barcelona | test02@futprotec.local | 3 | 2 | 2026-08-16 | enviado |
| 7 | 1812 | TEST_CLUB_04_Sevilla | test04@futprotec.local | 3 | 2 | 2026-08-16 | enviado |
| 8 | 1817 | TEST_ABC_FINAL6_B | test_abc_final6_b@futprotec.local | 3 | 2 | 2026-08-16 | enviado |
| 9 | 1814 | TEST_ABC_FINAL4_A | test_abc_final4_a@futprotec.local | NULL | 1 | 2026-08-17 | abierto |
| 10 | 1815 | TEST_ABC_FINAL4_B | test_abc_final4_b@futprotec.local | NULL | 1 | 2026-08-17 | abierto |
| 11 | 1816 | TEST_ABC_FINAL4_C | test_abc_final4_c@futprotec.local | NULL | 1 | 2026-08-17 | abierto |
| 12 | 1809 | TEST_CLUB_01_RealMadrid | test01@futprotec.local | NULL | 1 | 2026-08-17 | enviado |
| 13 | 1817 | TEST_ABC_FINAL6_B | test_abc_final6_b@futprotec.local | NULL | 1 | 2026-08-17 | enviado |
| 14 | 1811 | TEST_CLUB_03_Valencia | test03@futprotec.local | NULL | 1 | 2026-08-17 | enviado |

## 3. Registros REAL detectados (2)

| id | lead | club | email | camp | tpl | fecha |
|----|------|------|-------|------|-----|-------|
| 1 | 155 | A. D. PARADOR C. F. | clubadpparador@gmail.com | NULL | NULL | 2026-08-07 |
| 2 | 156 | A.C.D. ENTRETORRES | entretorresf7@hotmail.com | NULL | NULL | 2026-08-07 |

## 4. Registros ambiguos

**AMBIGUOS = 0.** No hay ningún envío que no pueda clasificarse inequívocamente.
Los 12 TEST cumplen el criterio de email `@futprotec.local` y/o club `test*`.
Los 2 REAL son a emails reales de clubes (clubadpparador@gmail.com,
entretorresf7@hotmail.com) sin marca TEST.

## 5. Causa de contaminación estadística

Los envíos TEST se insertaban en `envios` SIN marca `es_test`. La identificación
de TEST dependía únicamente de criterios de email/club (`@futprotec.local`, `test*`),
que no se aplicaban de forma uniforme en todas las consultas de analytics/followups.
Además, `reservarEnvioLogico()` no escribía ninguna marca TEST en el INSERT, por lo
que un envío en `modo_test` quedaba indistinguible de uno real a nivel de fila.

## 6. Cambios locales

1. **Migración de esquema** (`scripts/fase_test_aislamiento_migracion.php`):
   - Crea columna `envios.es_test` (default 0).
   - Backfill: 12 envíos TEST → `es_test=1`; 2 REAL → `es_test=0`.
   - Modo auditoría (solo lectura) + `--apply` para aplicar.
2. **Regla central** (`inc/eligibilidad.php`):
   - `esEnvioTest()` y `sqlFiltroComercial()` ya existían y se mantienen.
   - `reservarEnvioLogico()` ahora acepta `int $esTest = 0` y lo escribe en ambos
     INSERT (campaña y sin campaña).
3. **API de envío** (`api/enviar_lote.php`):
   - Pasa `$modoTest ? 1 : 0` como `esTest` a `reservarEnvioLogico()`, de modo que
     todo envío en modo pruebas queda marcado `es_test=1` en el momento de insertar.
4. **Tabla de destinatarios TEST** (`destinatarios_test`):
   - Creada con columnas `id, email, nombre, activo, creado_en`.
5. **API SMTP** (`api/smtp.php`):
   - Endpoints `get_test_recipients`, `add_test_recipient`, `delete_test_recipient`,
     `get_test_leads` para la gestión de pruebas.
6. **Snapshot** (`backups_deploy/stats_db_pre_test_cleanup.db`):
   - Copia íntegra de la BD antes de la migración. `PRAGMA integrity_check = ok`.

## 7. Archivos modificados

- `public_html/outbound/inc/eligibilidad.php` (reservarEnvioLogico + es_test)
- `public_html/outbound/api/enviar_lote.php` (pasa esTest)
- `public_html/outbound/api/smtp.php` (gestión destinatarios/leads TEST)
- `public_html/outbound/tabs/smtp.php` (UI GESTIÓN DE PRUEBAS)
- `public_html/outbound/dashboard.php` (histórico comercial excluye TEST)
- `public_html/outbound/inc/metricas.php` (analytics excluye TEST)
- `scripts/fase_test_aislamiento_migracion.php` (nuevo)
- `scripts/fase_test_aislamiento_verificacion.php` (nuevo)
- `backups_deploy/stats_db_pre_test_cleanup.db` (snapshot)

## 8. Tests

`scripts/fase_test_aislamiento_verificacion.php` → **PASS: 11, FAIL: 0**

- TEST A: Histórico comercial no contiene TEST (2 REAL).
- TEST B: Aceptados/aperturas comerciales excluyen TEST (comercial=0, test=12/3).
- TEST C: No Respondedores no contiene leads TEST.
- TEST D: REAL sigue funcionando (A. D. PARADOR y ENTRETORRES presentes).
- TEST E: 100% de envíos en histórico comercial son REALES.
- TEST F: Bajas comerciales excluyen bajas TEST.

## 9. Estrategia de limpieza

Se eligió **marcar `es_test=1`** (no borrado físico) para el histórico TEST, tal y
como recomienda el BLOQUE 11. Los 12 envíos TEST quedan excluidos de todas las
estadísticas comerciales vía `sqlFiltroComercial()`. No se borró ningún envío REAL.
No se eliminaron filas huérfanas porque no se borró nada físicamente; las aperturas
asociadas a TEST (3) quedan excluidas por JOIN con `envios.es_test=1`.

## 10. Estrategia de aislamiento futuro

- Todo envío en `modo_test` se marca `es_test=1` en el INSERT (fuente de verdad).
- Todas las consultas comerciales (analytics, followups, envios, aperturas,
  rebotes, bajas) usan `sqlFiltroComercial()` = `es_test=0`.
- `esLeadTest()` / `esEnvioTest()` / `esCampanaTest()` centralizan la identidad.
- La UI SMTP separa "CUENTAS SMTP" de "GESTIÓN DE PRUEBAS" con destinatarios,
  leads TEST, histórico y limpieza con confirmación.

---

## VEREDICTO

**TEST_ISOLATION_ARCHITECTURE_PASS**

No hay riesgo de mezclar TEST con REAL: la marca `es_test` es la fuente de verdad
primaria, se escribe en el momento del envío, y todas las métricas comerciales la
excluyen de forma centralizada.

## PARADA

- NO desplegado.
- NO se borraron registros en producción.
- NO se envió.
- NO se ejecutó cron.
- Pendiente de validación del usuario antes de cualquier deploy.
