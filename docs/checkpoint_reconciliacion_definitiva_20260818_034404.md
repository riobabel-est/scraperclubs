# CHECKPOINT RECONCILIACIÓN DEFINITIVA

Fecha: 2026-08-18 03:44 (Europe/Madrid)
Modo: READ-ONLY. Sin modificaciones de BD ni código.
Fuente: backups_deploy/stats_db_faseC2_pre_20260818_025142.db (BD producción)

---

## IDENTIDAD BD
- BD: stats_db (SQLite, producción)
- integrity_check: OK
- foreign_key_check: OK
- Modo entorno: producción
- Motor: SQLite3

## HASHES
- MD5 / SHA256: calculados en BLOQUE 0 (ver docs/auditoria_definitiva_bloque0.txt)

## INTEGRIDAD
- BD íntegra. Sin corrupción. FK correctas.

## LEADS
- Total: 1818
- REAL: 1809
- TEST: 9
- Duplicados REAL: 66
- Supresión REAL: 0
- Email vacío REAL: 0

## PIPELINES
- 1 histórico
- 1 comercial (campaign_id=2)
- 1 smoke test
- Aislamiento TEST/REAL correcto

## ENVÍOS
- Total: 42
- REAL: 22 (todos campaña 2)
- TEST: 20
- es_test coherente (0 discrepancias)

## CAMPAÑA 2
- 22 envíos REAL legítimos (actividad comercial del usuario)
- 1721 elegibles pendientes

## ES_TEST
- 0 discrepancias. PASS.

## A/B/C
- asignarVariante() = envios.variant. 0 discrepancias.
- lead_pipelines.variante_ab es histórico, no controla envío.

## APERTURAS
- 7 abiertos únicos
- 2 con segunda apertura
- Coherente con los datos observados.

## REBOTES
- 0 REGISTRADOS (no 0 ocurridos). Monitorización correcta.

## BAJAS
- 0 REGISTRADAS (no 0 ocurridas). Monitorización correcta.

## RESPUESTAS
- 0 registradas. message_id presentes en envíos.

## SMTP
- Límite real: 15/cuenta/día
- UI coherente con el límite real
- Modificación previa api/smtp.php (enviados_hoy) APLICADA y correcta

## SEGURIDAD
- TEST→REAL: BLOQUEADO
- REAL→TEST: BLOQUEADO
- TEST en producción: BLOQUEADO
- A/B/C histórico no controla envío

## ELEGIBILIDAD
- 1721 elegibles pendientes campaña 2
- Cálculo correcto (universo REAL - duplicados - ya enviados)

## BUGS
- dashboard.php usa lead_pipelines.variante_ab para métricas por variante
  (bug de presentación, NO bloqueante)
- js/app.js usa Math.random() para variante (código muerto, sobreescrito
  por el backend, NO bloqueante)

## DISCREPANCIAS
- Ninguna real. Los 22 envíos REAL de campaña 2 son actividad comercial
  legítima, no discrepancias.

## VEREDICTO
**B) SISTEMA COHERENTE — SOLO BUGS NO BLOQUEANTES**

El CRM es internamente coherente tras la actividad comercial real.
No hay riesgo de envío accidental. No se requiere reparación urgente.
Los 2 bugs encontrados son de presentación/código muerto y NO afectan
la integridad, la seguridad ni el envío real.

---

## ARCHIVOS DE BLOQUE GENERADOS
- docs/auditoria_definitiva_bloque0.txt a bloque15.txt
- docs/checkpoint_reconciliacion_definitiva_20260818_034404.md (este)
