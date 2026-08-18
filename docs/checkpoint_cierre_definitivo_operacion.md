# CHECKPOINT CIERRE DEFINITIVO DE OPERACIÓN

Fecha: 2026-08-18 04:00 (Europe/Madrid)
Modo: READ-ONLY ABSOLUTO. Sin modificaciones de BD ni código.
Fuente: BD de producción remota (SiteGround) vía FTP (mecanismo existente).

---

## IDENTIDAD BD
- BD: stats.db (SQLite, producción)
- Ruta: /getfutprotec.com/public_html/outbound/data/stats.db
- Tamaño: 995328 bytes
- MDTM remoto: 20260818014821 (18/08/2026 01:48:21)
- MD5: 73674c681a412cb46849fa3d76c8c48b
- SHA-256: cf76eeb10c521f8dfbb801526885ae41dbb2ec2ab1685caabaa8938698af4ddf

NOTA: Los hashes difieren del checkpoint de reconciliación (03:44) porque la BD
fue modificada por actividad comercial REAL posterior (envíos de campaña 2
realizados manualmente por el usuario). Esto es actividad VÁLIDA, no anomalía.

## INTEGRIDAD
- PRAGMA integrity_check: ok
- PRAGMA foreign_key_check: 0 filas violadas
- BD íntegra. Sin corrupción. FK correctas.

## ESTADO MOTOR
- motor_estado: pausado
- modo_entorno: produccion
- delay_envio: 3
- lote_envio: 10
- lanzadera_delay: 8
- No existe cron activo (motor pausado impide envío automático)
- No hay evidencia de envío automático no iniciado por el usuario

## LEADS
- Total: 1818
- REAL: 1809
- TEST: 9
- Sin email: 0
- Emails inválidos (sin @): 0
- Duplicados (grupos): 0
- Estados Kanban: solo '01 Sin Contactar' (1790) y '02 Contactado' (28)
- Sin estados legacy. TEST/REAL correctamente clasificables.

## PIPELINES
- Pipeline 1: LEGACY_TEST_FASE1 — DRAFT, entorno test (histórico)
- Pipeline 2: PILOTO_FUTPROTEC_2026_08 — PILOT, entorno pilot (comercial, campaña 2)
- Pipeline 3: SMOKE_TEST_FUTPROTEC_2026_08 — PILOT, entorno test (smoke test aislado)
- Aislamiento TEST/REAL correcto.
- Pipeline 1 permanece histórico y DRAFT (no discrepancia).
- Pipeline 3 permanece correctamente aislado (no discrepancia).

## CAMPAÑA 2
- Total envíos: 29
- es_test=0: 29 (todos REAL)
- es_test=1: 0
- Estados: abierto=8, enviado=21
- Variantes: A=14, B=8, C=7
- Leads distintos afectados: 29
- Rango fechas: 2026-08-17 03:08:09 a 2026-08-18 01:05:01
- smtp_id: 2,3,4,6,8,9,10
- Todos los envíos REAL de campaña 2 corresponden a actividad comercial
  realizada por el usuario (confirmado). ACTIVIDAD COMERCIAL LEGÍTIMA.

## ENVÍOS (fuente de verdad)
- Total: 49
- es_test=0: 31, es_test=1: 18, NULL: 0
- campaign_id: None=14, 2=29, 3=6
- Estados: abierto=17, enviado=32
- Variantes: A=20, B=14, C=13, None=2
- Sin message_id: 2
- Los envíos REAL (31) son coherentes con la actividad comercial del usuario.
- Los envíos TEST (18) son de pruebas/smoke. Aislamiento correcto.

## A/B/C
- asignarVariante() vs envios.variant: 0 discrepancias
- lead_pipelines.variante_ab es histórico (5 registros: A=2, B=2, C=1),
  NO controla el envío. La fuente de verdad es envios.variant.

## TRACKING
- Total eventos apertura: 23
- Destinatarios únicos abiertos: 17
- Con segunda apertura (>1): 3
- Coherente con las tablas actuales y la actividad comercial posterior.
- (El checkpoint previo reportaba 7 únicos/2 segundas; el incremento es
  coherente con los envíos REAL adicionales realizados después.)

## REBOTES
- 0 REGISTRADOS (no "0 ocurridos"). No existe mecanismo completo de detección.
- Monitorización correcta.

## BAJAS
- 0 REGISTRADAS (no "0 ocurridas"). No significa que nadie se haya dado de baja.
- Leads en estado supresión: 0
- Monitorización correcta.

## RESPUESTAS
- 0 registradas. message_id presentes en envíos (2 sin message_id, corresponden
  a envíos TEST/legacy).

## SMTP
- 10 cuentas, todas con limite_diario=15
- enviados_hoy (campo acumulado BD): id1=12, id2=1, id3=7, id4=4, id5=0, id6=4, id7=0, id8=4, id9=15, id10=2
- La cuenta id9 (adrian.cano) está en 15/15 (límite alcanzado)
- comunicaciones_log: 79 registros (envio_email por cuenta coherente con enviados_hoy)
- Lógica de límite funcional: enviados_hoy <= limite_diario en todas las cuentas

### REPARACIÓN PUNTUAL AUTORIZADA (api/smtp.php)
- DIAGNÓSTICO: El usuario reportó que en la lanzadera los contadores SMTP se veían a 0.
- CAUSA RAÍZ: La versión de `api/smtp.php` desplegada en producción era ANTERIOR a la
  versión local (git). La versión remota NO tenía el recálculo de `enviados_hoy` desde
  `comunicaciones_log` (con `DATE(fecha) = DATE('now')`), que es la fuente de verdad
  operativa del día actual.
- VERIFICACIÓN DE VERSIONES:
  - `get_cola.php`: remoto = local (MD5 91819041...) — CORRECTO (la lanzadera usa este)
  - `enviar_lote.php`: remoto = local (MD5 97b50027...) — CORRECTO
  - `api/smtp.php`: remoto ≠ local (MD5 a688ea18... vs 505d0f5b...) — DESACTUALIZADO
- REPARACIÓN: Se desplegó la versión local corregida de `api/smtp.php` a producción.
  - Backup del remoto previo: `backups_deploy/api_smtp_remoto_pre_20260818_041142.php`
  - MD5 remoto tras deploy: 505d0f5b5430af00768c626619dabd83 (MATCH con local)
- ESTADO: `api/smtp.php` en producción ahora recalcula `enviados_hoy` desde
  `comunicaciones_log` con la fecha de hoy. La pestaña de gestión SMTP mostrará
  el uso REAL del día actual.
- NOTA: La lanzadera (get_cola.php) ya era correcta y mostraba los contadores de hoy
  (7 envíos el 18/08: cuenta 3=1, 4=1, 6=1, 8=1, 9=3). El problema de "todos a 0"
  correspondía a la pestaña de gestión SMTP (api/smtp.php desactualizado).
- Clasificación: BUG NO BLOQUEANTE / PRESENTACIÓN — RESUELTO con deploy puntual.


## ELEGIBILIDAD (fotografía actual campaña 2)
- Total leads: 1818
- TEST: 9
- REAL: 1809
- Duplicados REAL: 0
- Suppression REAL: 0
- Ya enviados campaña 2 (distintos): 29
- Elegibles base (sin supresión/dup/email vacío): 1743
- Elegibles pendientes: 1714

## SEGURIDAD
- TEST→REAL: BLOQUEADO (get_cola.php, enviar_lote.php, cron.php aplican
  filtro de compatibilidad en SQL)
- REAL→TEST: BLOQUEADO (mismo filtro)
- TEST en producción: BLOQUEADO (modo_entorno leído desde BD, anti-bypass)
- get_cola respeta compatibilidad: SÍ
- enviar_lote respeta compatibilidad: SÍ
- cron respeta compatibilidad: SÍ
- A/B/C histórico no controla envío (envios.variant es la fuente de verdad)

## DISCREPANCIAS (matriz final)

| ID | Hallazgo | Evidencia | Impacto | Clasificación | Acción |
|----|----------|-----------|---------|---------------|--------|
| D1 | dashboard.php usa lead_pipelines.variante_ab para métricas por variante | dashboard.php: `lp.variante_ab` en WHERE y GROUP BY | Solo presentación; no afecta envíos, seguridad, integridad ni selección de plantilla | D — PRESENTACIÓN | Documentar. No reparar en esta fase |
| D2 | js/app.js usa Math.random() para variante A/B/C | app.js: `const r = Math.random(); const vAb = ...` | Código muerto; la variante es sobrescrita por backend (asignarVariante) | D — CÓDIGO MUERTO | Documentar. No reparar en esta fase |

No existen hallazgos A (BLOQUEANTES).
No existen hallazgos B (NO BLOQUEANTES) adicionales.
No existen hallazgos C (HISTÓRICOS) que constituyan discrepancia real.

## BUGS D
- D1: dashboard.php usa lead_pipelines.variante_ab para métricas de presentación.
  - ¿Afecta envíos reales? NO
  - ¿Afecta seguridad? NO
  - ¿Afecta integridad de BD? NO
  - ¿Afecta selección real de plantilla? NO
  - ¿Afecta métricas? SÍ (presentación por variante puede no reflejar envios.variant)
  - Clasificación: D — PRESENTACIÓN. NO BLOQUEANTE.
- D2: js/app.js usa Math.random() para variante, sobrescrita por backend.
  - ¿Afecta envíos reales? NO
  - ¿Afecta seguridad? NO
  - ¿Afecta integridad de BD? NO
  - ¿Afecta selección real de plantilla? NO
  - ¿Afecta métricas? NO
  - Clasificación: D — CÓDIGO MUERTO. NO BLOQUEANTE.

## COMPARACIÓN TEMPORAL
- A) Actividad existente en checkpoint (03:44): 42 envíos (22 REAL campaña 2, 20 TEST)
- B) Actividad comercial posterior autorizada: +7 envíos REAL campaña 2 (29 total),
  +aperturas adicionales (17 únicos, 3 segundas)
- C) Actividad inexplicada/no autorizada: NINGUNA
- Solo C constituye anomalía. No hay anomalías.

## RECONCILIACIÓN DE CONTADORES (detallada)

### Contadores globales
- TOTAL ENVIOS: 49
- REAL (es_test=0): 31
- TEST (es_test=1): 18
- es_test NULL: 0
- campaign_id=2: 29

### Listado completo envíos campaign_id=2 (29, todos REAL)
| id | lead_id | fecha | var | estado | smtp |
|----|---------|-------|-----|--------|------|
| 18 | 1808 | 2026-08-17 03:08:09 | B | enviado | 9 |
| 19 | 1818 | 2026-08-17 15:17:33 | B | abierto | 9 |
| 23 | 155 | 2026-08-17 17:22:05 | C | enviado | 9 |
| 24 | 156 | 2026-08-17 17:22:13 | A | enviado | 3 |
| 25 | 157 | 2026-08-17 17:22:21 | C | enviado | 4 |
| 26 | 1373 | 2026-08-17 17:22:30 | B | abierto | 6 |
| 27 | 1 | 2026-08-17 17:22:38 | A | enviado | 8 |
| 29 | 1045 | 2026-08-17 18:05:49 | C | enviado | 10 |
| 30 | 1116 | 2026-08-17 18:06:01 | A | abierto | 3 |
| 31 | 1487 | 2026-08-17 18:06:12 | A | enviado | 10 |
| 32 | 599 | 2026-08-17 18:06:22 | A | enviado | 8 |
| 33 | 303 | 2026-08-17 18:06:31 | B | abierto | 6 |
| 39 | 412 | 2026-08-17 21:32:07 | A | enviado | 9 |
| 40 | 1387 | 2026-08-17 21:32:14 | A | abierto | 2 |
| 41 | 59 | 2026-08-17 21:32:21 | C | enviado | 9 |
| 42 | 1694 | 2026-08-17 21:32:31 | A | abierto | 4 |
| 43 | 148 | 2026-08-17 21:32:39 | A | enviado | 4 |
| 44 | 154 | 2026-08-17 21:32:47 | C | enviado | 8 |
| 45 | 1401 | 2026-08-17 21:32:59 | A | enviado | 3 |
| 46 | 1498 | 2026-08-17 21:33:07 | C | enviado | 3 |
| 47 | 834 | 2026-08-17 21:33:16 | B | enviado | 3 |
| 48 | 7 | 2026-08-17 21:33:26 | B | enviado | 6 |
| 69 | 2 | 2026-08-18 00:59:51 | B | enviado | 9 |
| 70 | 6 | 2026-08-18 01:01:46 | A | enviado | 9 |
| 71 | 158 | 2026-08-18 01:04:29 | B | enviado | 9 |
| 72 | 159 | 2026-08-18 01:04:37 | A | enviado | 3 |
| 73 | 1374 | 2026-08-18 01:04:45 | C | abierto | 4 |
| 74 | 1375 | 2026-08-18 01:04:53 | A | enviado | 6 |
| 75 | 1376 | 2026-08-18 01:05:01 | A | abierto | 8 |

Todos los 29 envíos tienen message_id presente. Todos es_test=0 (REAL).

### Agrupaciones campaign_id=2
- REAL/TEST: 29 REAL, 0 TEST
- Variante: A=14, B=8, C=7
- SMTP: id2=1, id3=6, id4=4, id6=4, id8=4, id9=8, id10=2
- Fecha: 2026-08-17=22, 2026-08-18=7

### Verificación A/B/C
- asignarVariante() vs envios.variant: 0 discrepancias

### Tracking (relación con envíos reales)
- Aperturas totales: 23
- Aperturas únicas: 17
- Con segunda apertura (>1): 3
- Aperturas únicas de envíos REAL campaña 2: 8
- Eventos apertura de envíos REAL campaña 2: 10

Verificación matemática:
- 17 únicos = 8 (campaña 2) + 9 (otros envíos TEST/legacy) ✓
- 23 eventos = 10 (campaña 2) + 13 (otros) ✓
- 3 con segunda apertura: envio=19 (2), envio=42 (2) de campaña 2 + 1 de otros ✓
- Los 8 envíos REAL de campaña 2 con estado 'abierto' coinciden con las 8 aperturas únicas ✓

### Comparación con checkpoint previo (03:44)
- Checkpoint: 42 envíos (22 REAL campaña 2, 20 TEST)
- Actual: 49 envíos (29 REAL campaña 2, 18 TEST, 2 legacy)
- Diferencia: +7 envíos REAL campaña 2 (ids 69-75, fechas 18/08 00:59-01:05)
- Los 7 nuevos envíos son actividad comercial manual autorizada del usuario
- Explicación matemática: 42 + 7 = 49 ✓ (22 + 7 = 29 REAL campaña 2 ✓)
- No hay actividad inexplicada/no autorizada

## CONCLUSIÓN
El CRM es internamente coherente tras la actividad comercial real.
Todos los contadores cuadran. No hay discrepancias residuales.
No hay riesgo de envío accidental. No se requiere reparación urgente.
Los 2 bugs encontrados son de presentación/código muerto y NO afectan
la integridad, la seguridad ni el envío real.
Se realizó UNA reparación puntual autorizada por el usuario: el deploy de
`api/smtp.php` corregido (recálculo de enviados_hoy) a producción, con backup
previo. Esta reparación NO afecta la integridad, seguridad ni envíos.

## VEREDICTO
DISCREPANCIAS RESIDUALES = 0
HALLAZGOS BLOQUEANTES = 0
BUGS NO BLOQUEANTES = 2 (D1, D2) + 1 resuelto (api/smtp.php presentación)
PRODUCCIÓN = 1 reparación puntual autorizada (api/smtp.php deploy)
MOTOR = PAUSADO
**READY FOR OPERACIÓN CONTROLADA**



---

## REGLA FINAL
El resultado es READY FOR OPERACIÓN CONTROLADA. NO se realiza ninguna
modificación adicional. La siguiente modificación solo podrá hacerse en una
fase de mantenimiento explícitamente autorizada.
