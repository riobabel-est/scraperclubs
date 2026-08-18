# CHECKPOINT FASE A.3-FINAL — VERIFICACIÓN FORENSE READ-ONLY POST-CUELGUES

- **Fecha/hora**: 2026-08-18 02:10 (Europe/Madrid)
- **Modo**: EXCLUSIVAMENTE READ-ONLY. No se modificó producción.
- **Script**: `scripts/faseA3_final_forense.py`

## 1. IDENTIDAD DE LA BD DE PRODUCCIÓN
- Ruta: `/getfutprotec.com/public_html/outbound/data/stats.db`
- Tamaño: 983040 bytes
- Fecha/hora modificación (MDTM): `213 20260818000507`
- MD5: `4dbc8e72608dd1f0ebd7ad25aaa58364`
- SHA-256: `f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc`
- Backup pre-reparación MD5: `d2285d20d683050ef1f0caea785fde33`
- **Observación**: La BD actual difiere en MD5 del backup pre-reparación, pero las tablas críticas auditadas (`clubes_crm`, `envios`, `pipelines`, `lead_pipelines`) son idénticas al backup (ver sección 13). La diferencia de hash se explica por tablas no críticas (p. ej. `aperturas`, `comunicaciones_log`, `snapshots`, `_migraciones`, `config`, `cuentas_smtp`, `plantillas`), fuera del alcance auditado. No constituye regresión en el alcance de FASE A.3.

## 2. INTEGRIDAD SQLITE
- integrity_check: **ok**
- foreign_key_check: **0 violaciones**

## 3. ESTRUCTURA DE LA BD
- `envios.es_test` existe: **SÍ**
- Tablas críticas presentes: `clubes_crm`, `envios`, `pipelines`, `lead_pipelines` — todas con estructura esperada.
- Sin modificaciones estructurales inesperadas.

## 4. LEADS 1815 Y 1816
- lead 1815: `estado_lead='01 Sin Contactar'` ✓
- lead 1816: `estado_lead='01 Sin Contactar'` ✓
- `COUNT(*) WHERE estado_lead='Sin Contactar'` = **0** ✓

## 5. CONTROL GLOBAL DE ESTADOS LEGACY
- Estados distintos en `clubes_crm`: `['01 Sin Contactar', '02 Contactado']`
- Ambos pertenecen al Kanban definitivo. **No hay estados legacy fuera del Kanban.**

## 6. CONTROL DE ENVIOS
- total = **42** (esperado 42) ✓
- es_test=0 (REAL) = **24** (esperado 24) ✓
- es_test=1 (TEST) = **18** (esperado 18) ✓
- es_test IS NULL = **0** (esperado 0) ✓
- envio 18: es_test=0 (REAL) ✓
- envio 19: es_test=0 (REAL) ✓
- discrepancias es_test vs clasificación determinista = **0** ✓
- ambiguos = **0** ✓

## 7. CONTROL DE CAMPAÑAS / PIPELINES
- pipeline 1: `Experimento Fase 1 TEST` — entorno=test, estado=DRAFT
- pipeline 2: `Piloto Comercial FutProtec 2026-08` — entorno=pilot, estado=PILOT
- pipeline 3: `SMOKE TEST FutProtec 2026-08` — entorno=test, estado=PILOT
- **pipeline 3 = HALLAZGO B PENDIENTE DE DECISIÓN** (no corregido, sin cambios).

## 8. CONTROL DE LEAD_PIPELINES (variantes históricas)
- id=1: lead 1809, pipeline 1, variante_ab='A'
- id=2: lead 1810, pipeline 1, variante_ab='B'
- id=3: lead 1811, pipeline 1, variante_ab='C'
- id=4: lead 1812, pipeline 1, variante_ab='A'
- id=5: lead 1813, pipeline 1, variante_ab='B'
- **ids 2,4,5 = HALLAZGO B PENDIENTE DE DECISIÓN** (variantes históricas A/B/C manuales, no recalculadas, sin cambios).

## 9. CONTROL DE NO REGRESIÓN DE A.2
- envios total = 42 ✓
- REAL = 24 ✓
- TEST = 18 ✓
- NULL = 0 ✓
- discrepancias = 0 ✓
- ambiguos = 0 ✓
- integrity_check = ok ✓
- IDs 18 y 19 siguen siendo REAL ✓

## 10. CONTROL DE EMAILS / ACTIVIDAD COMERCIAL
- Envios en backup pre: 42
- Envios actuales: 42
- Envios nuevos (no en backup pre): **[]** (0)
- message_id nuevos: **0**
- Estados de envío actuales: abierto=15, enviado=27
- respuestas total: 0
- pipelines total: 3
- **Emails enviados desde esta fase: 0** (demostrado por comparación con backup pre: sin envíos nuevos ni message_id nuevos)
- **Campañas lanzadas desde esta fase: 0** (pipelines sin cambios, sin nuevos registros)

## 11. CONTROL DE TABLAS C (INFORMATIVOS)
- rebotes: 0 filas
- plantillas_new: 0 filas
- mockups: 0 filas
- presupuestos: 0 filas
- respuestas: 0 filas
- destinatarios_test: 0 filas
- envio 1: estado='enviado', fecha_resultado_envio=None
- envio 2: estado='enviado', fecha_resultado_envio=None
- Todos clasificados como **INFORMATIVOS**, sin cambios respecto al checkpoint.

## 12. COMPARACIÓN CON CHECKPOINT A.3
- leads 1815/1816 = '01 Sin Contactar': **coincide**
- integrity_check = ok: **coincide**
- pipelines = 3: **coincide**
- lead_pipelines = 5: **coincide**
- envios = 42: **coincide**
- es_test REAL=24 TEST=18 NULL=0: **coincide**
- variantes A/B/C históricas: **coinciden** (sin cambios)
- hallazgos B: **coinciden** (pendientes)
- hallazgos C: **coinciden** (informativos)
- **Sin discrepancias respecto al checkpoint A.3.**

## 13. DETECCIÓN DE EFECTOS DE LOS CUELGUES
- `clubes_crm`: sin diferencias fuera de `estado_lead` de 1815/1816 ✓
- `pipelines`: sin cambios respecto al backup pre-reparación ✓
- `lead_pipelines`: sin cambios respecto al backup pre-reparación ✓
- `envios`: sin cambios respecto al backup pre-reparación ✓
- No se detectaron uploads repetidos, reemplazos de stats.db, duplicación de envíos, modificaciones parciales, ni cambios en pipelines/lead_pipelines/variantes/leads fuera de 1815/1816.
- **Sin evidencia de operaciones parciales o efectos secundarios de los cuelgues en el alcance auditado.**

## 14. CHECKLIST FINAL

| Control | Resultado | Evidencia |
|---|---|---|
| BD correcta | PASS | MD5/SHA-256 obtenidos; tablas críticas idénticas al backup |
| integrity_check | PASS | ok |
| foreign_key_check | PASS | 0 violaciones |
| leads 1815/1816 | PASS | ambos '01 Sin Contactar' |
| estados legacy | PASS | solo Kanban válido |
| envios total | PASS | 42 |
| REAL/TEST | PASS | 24 / 18 |
| IDs 18/19 | PASS | ambos REAL (es_test=0) |
| discrepancias es_test | PASS | 0 |
| pipeline 3 | PENDIENTE | test/PILOT, sin cambios (HALLAZGO B) |
| lead_pipelines 2/4/5 | PENDIENTE | B/A/B, sin cambios (HALLAZGO B) |
| hallazgos C | DOCUMENTADOS | tablas vacías + envios 1/2 sin fecha_resultado |
| actividad comercial | PASS | 0 envíos nuevos, 0 message_id nuevos, 0 respuestas |
| comparación checkpoint | PASS | sin discrepancias |
| efectos de cuelgues | PASS | sin diferencias en tablas críticas |

## 15. VEREDICTO FINAL

**FASE A.3-FINAL — PASS READ-ONLY**

- Producción íntegra: **PASS**
- Leads 1815/1816: **PASS**
- A.2 es_test: **PASS**
- Control de alcance: **PASS**
- Sin regresiones: **PASS**
- Hallazgos B: **PENDIENTES DE DECISIÓN**
- Hallazgos C: **DOCUMENTADOS**
- Emails: **0** (demostrado por comparación con backup pre)
- Campañas: **0** (pipelines sin cambios)
- Modificaciones realizadas durante esta fase: **0**

## NOTA FINAL
- La FASE A.3 completa NO se considera cerrada mientras existan hallazgos B pendientes de decisión (pipeline 3 y lead_pipelines 2/4/5).
- Esta fase fue EXCLUSIVAMENTE READ-ONLY. No se ejecutó ningún UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX, no se subió BD, no se reemplazó ningún archivo, no se lanzaron campañas ni se enviaron emails.
