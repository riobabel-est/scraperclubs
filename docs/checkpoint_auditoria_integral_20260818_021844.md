==========================================================================================
INFORME FINAL — AUDITORÍA INTEGRAL CRM OUTBOUND FUTPROTEC
==========================================================================================
Fecha/hora: 2026-08-18 02:18:44

1. ESTADO DE PRODUCCIÓN
   BD: /getfutprotec.com/public_html/outbound/data/stats.db
   Tamaño: 983040 bytes
   MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc
   modo_entorno: produccion
   motor_estado: pausado

2. INTEGRIDAD
   integrity_check: ok
   foreign_key_check: 0 violaciones

3. REPARACIONES REALIZADAS
   Ninguna reparación fue necesaria.

4. HALLAZGOS CONSERVADOS (CATEGORÍA B)
   [B] B1: pipeline 3 (SMOKE_TEST_FUTPROTEC_2026_08) entorno=test+estado=PILOT — EXCEPCIÓN DOCUMENTADA, no modificar
   [B] B2: lead_pipelines 2,4,5 variantes históricas — CONSERVAR COMO HISTÓRICO, no modificar
   [B] Pipeline 1 (LEGACY_TEST_FASE1) entorno=test+estado=DRAFT — HISTÓRICO, no modificar

5. HALLAZGOS HISTÓRICOS (CATEGORÍA C)
   [C] lead 1815: ya está en '01 Sin Contactar' (correcto)
   [C] lead 1816: ya está en '01 Sin Contactar' (correcto)
   [C] No hay estados legacy en leads activos
   [C] Tabla legacy vacía: rebotes (0 filas) — INFORMATIVO
   [C] Tabla legacy vacía: plantillas_new (0 filas) — INFORMATIVO
   [C] Tabla legacy vacía: mockups (0 filas) — INFORMATIVO
   [C] Tabla legacy vacía: presupuestos (0 filas) — INFORMATIVO
   [C] Tabla legacy vacía: respuestas (0 filas) — INFORMATIVO
   [C] Tabla legacy vacía: destinatarios_test (0 filas) — INFORMATIVO

6. RIESGOS
   Riesgo de envío comercial accidental: BAJO
   (verificado: aislamiento TEST/REAL en código, motor pausado)

7. SEGURIDAD DE ENVÍO
   Motor de envío: PAUSADO
   Emails enviados durante el proceso: 0
   Campañas lanzadas: 0

8. CONTROL TEST/REAL
   Envios total: 42
   REAL (es_test=0): 24
   TEST (es_test=1): 18
   NULL: 0
   Discrepancias: 0

9. CONTROL A/B/C
   Discrepancias variant vs asignarVariante(): 0

10. CONTROL DE CAMPAÑAS
   Pipelines: 3
   Pipeline 1 (LEGACY_TEST_FASE1): test/DRAFT — HISTÓRICO
   Pipeline 2 (Piloto Comercial): pilot/PILOT
   Pipeline 3 (SMOKE TEST): test/PILOT — EXCEPCIÓN DOCUMENTADA

11. CONTROL DE RESPUESTAS
   Respuestas: 0
   message_id duplicados: 0
   Envios sin message_id: 2

12. REGRESIONES
   Envios backup vs post: 42 vs 42
   Pipelines backup vs post: 3 vs 3
   Lead_pipelines backup vs post: 5 vs 5

13. BACKUP
   Local: C:\laragon\www\scrapperclub\backups_deploy\stats_db_auditoria_integral_pre_20260818_021844.db
   MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc
   integrity_check: ok

14. HASHES
   BD producción MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   BD producción SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc
   Backup MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   Backup SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc

15. CAMBIOS EXACTOS
   Ninguno.

16. CHECKPOINT
   docs/checkpoint_auditoria_integral_20260818_021844.md

17. VEREDICTO FINAL
   READY FOR MARKETING

EMAILS ENVIADOS = 0
CAMPAÑAS LANZADAS = 0
MOTOR DE ENVÍO = PAUSADO
==========================================================================================