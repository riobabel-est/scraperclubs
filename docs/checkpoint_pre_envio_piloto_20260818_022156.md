==========================================================================================
CHECKPOINT PRE-ENVÍO — CAMPAÑA 2 (PILOTO_FUTPROTEC_2026_08)
==========================================================================================
Fecha/hora: 2026-08-18 02:21:56

1. IDENTIDAD BD
   Ruta: /getfutprotec.com/public_html/outbound/data/stats.db
   Tamaño: 983040 bytes
   MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc
   modo_entorno: produccion
   motor_estado: pausado

2. CAMPAÑA OBJETIVO
   campaign_id: 2
   nombre: 'riobabel'
   entorno: 'pilot'
   estado: '01 Sin Contactar'
   activo: 1
   validarCampanaActiva: CAMPANIA_VALIDA

3. PLANTILLA A/B/C
   plantilla_id: 1
   nombre: 'Prospeccion (abc - texto plano)'
   test_ab: 1
   congelada: True

4. UNIVERSO ELEGIBLE
   TOTAL LEADS CRM: 1818
   TOTAL TEST: 9
   TOTAL REAL: 1809
   TOTAL ELEGIBLES: 1721
   TOTAL BLOQUEADOS: 97

5. DISTRIBUCIÓN A/B/C
   A = 548
   B = 597
   C = 576

6. SUPPRESSION / BLACKLIST
   Excluidos por suppression: 0
   Excluidos por envío previo: 22
   Excluidos por incompatibilidad: 9
   Excluidos por TEST: 9
   Finalmente elegibles: 1721

7. PRUEBA CONTROLADA (3-5 leads reales)
   Número de destinatarios: 5
   IDs de leads:
     - lead_id=2 | A.D. CEUTI ATLETICO | ceutiatleticoad@gmail.com | variante=B | fed=Federación de Fútbol de la Región de Murcia
     - lead_id=3 | AGRUPACION DEPORTIVA AZARBE | asociaciondeportivaazarbe@gmail.com | variante=B | fed=Federación de Fútbol de la Región de Murcia
     - lead_id=4 | AGUILAS F.C. | info@aguilasfc.es | variante=B | fed=Federación de Fútbol de la Región de Murcia
     - lead_id=6 | ASOCIACION DEPORTIVA FRANCISCANOS | martinml34@hotmail.com | variante=A | fed=Federación de Fútbol de la Región de Murcia
     - lead_id=8 | ASOCIACION DEPORTIVA GUADALUPE VETERANOS | alegriasoler100@hotmail.com | variante=C | fed=Federación de Fútbol de la Región de Murcia

8. FILTROS APLICADOS
   - lead REAL (no TEST)
   - campaña comercial (campaign_id=2)
   - no blacklist/suppression
   - no envío previo incompatible
   - idempotencia (lead_id+campaign_id)
   - estado elegible (01 Sin Contactar)
   - compatibilidad lead/campaña
   - variante determinista

9. BACKUP
   Local: C:\laragon\www\scrapperclub\backups_deploy\stats_db_prep_campana2_pre_20260818_022156.db
   MD5: 4dbc8e72608dd1f0ebd7ad25aaa58364
   SHA-256: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc
   integrity_check: ok

10. SEGURIDAD
   pipeline 3 TEST = BLOQUEADO
   pipeline 1 TEST = BLOQUEADO
   campaign_id permitido = 2
   lead TEST = BLOQUEADO
   lead REAL = permitido
   es_test del envío real = 0
   NO se ejecutó cron general
   NO se activó envío masivo
   NO se envió ningún email

EMAILS ENVIADOS DURANTE AUDITORÍA = 0
EMAILS ENVIADOS EN ESTA PRUEBA = 0 (NO SE HA ENVIADO NADA)
ESPERAR AUTORIZACIÓN ANTES DE CONTINUAR.
==========================================================================================