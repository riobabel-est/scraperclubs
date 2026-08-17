# AUDITORÍA DEFINITIVA — AISLAMIENTO TEST/REAL (BD PRODUCCIÓN LIVE)

**Fecha:** 2026-08-17 22:27 (Europe/Madrid)
**Estado:** AUDIT_TEST_ISOLATION_COMPLETE — **BLOCKED** (no desplegar hasta validación)
**BD auditada:** `backups_deploy/stats_db_LIVE_audit_1786998346.db` (descarga read-only de producción vía FTP)

---

## 1. ARQUITECTURA ACTUAL (PRODUCCIÓN)

- **BD:** SQLite `stats.db` en `public_html/outbound/data/`
- **modo_entorno:** `produccion`
- **motor_estado:** `pausado`
- **Tablas:** `_migraciones, aperturas, clubes_crm, comunicaciones_log, config, cuentas_smtp, envios, lead_pipelines, mockups, pipelines, plantillas, plantillas_new, presupuestos, rebotes, respuestas, snapshots, sqlite_sequence`
- **`_migraciones`:** SOLO contiene la migración `fase0_migracion_ddl.py` (2026-08-11). **NO existe ninguna migración de `es_test`.**

### HALLAZGO CRÍTICO #1
**La BD de producción NO tiene la columna `envios.es_test`.** El esquema de `envios` es el antiguo:
`id, club, email, federacion, cuenta_emision, fecha_envio, estado, tracking_id, asunto, cuerpo_mensaje, lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id, resultado_envio, fecha_resultado_envio`

### HALLAZGO CRÍTICO #2
**La BD de producción NO tiene la tabla `destinatarios_test`.** La gestión de destinatarios de prueba nunca se desplegó.

### HALLAZGO CRÍTICO #3
**La BD de producción NO tiene `es_test` en `clubes_crm` ni en `envios`.** No existe ninguna marca de TEST en producción.

### Conclusión arquitectónica
La migración de aislamiento TEST/REAL (`es_test`) se aplicó SOLO en local/backups (`stats_db_pre_test_cleanup.db` con 14 envíos y columna `es_test`), pero **NUNCA se desplegó a producción**. Por eso la interfaz de producción muestra los 32 envíos mezclados sin distinción.

---

## 2. REGISTROS TEST DETECTADOS (20 envíos)

| envio_id | email | club | lead_id | fecha | evidencia |
|---|---|---|---|---|---|
| 3 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-14 | lead TEST |
| 4 | test05@futprotec.local | TEST_CLUB_05_Bilbao | 1813 | 08-15 | lead TEST |
| 5 | test03@futprotec.local | TEST_CLUB_03_Valencia | 1811 | 08-15 | lead TEST |
| 6 | test02@futprotec.local | TEST_CLUB_02_Barcelona | 1810 | 08-16 | lead TEST |
| 7 | test04@futprotec.local | TEST_CLUB_04_Sevilla | 1812 | 08-16 | lead TEST |
| 8 | test_abc_final6_b@futprotec.local | TEST_ABC_FINAL6_B | 1817 | 08-16 | lead TEST |
| 9 | test_abc_final4_a@futprotec.local | TEST_ABC_FINAL4_A | 1814 | 08-17 | lead TEST |
| 10 | test_abc_final4_b@futprotec.local | TEST_ABC_FINAL4_B | 1815 | 08-17 | lead TEST |
| 11 | test_abc_final4_c@futprotec.local | TEST_ABC_FINAL4_C | 1816 | 08-17 | lead TEST |
| 12 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-17 | lead TEST |
| 13 | test_abc_final6_b@futprotec.local | TEST_ABC_FINAL6_B | 1817 | 08-17 | lead TEST |
| 14 | test03@futprotec.local | TEST_CLUB_03_Valencia | 1811 | 08-17 | lead TEST |
| 15 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-17 | lead TEST |
| 16 | test_abc_final6_b@futprotec.local | TEST_ABC_FINAL6_B | 1817 | 08-17 | lead TEST |
| 17 | test03@futprotec.local | TEST_CLUB_03_Valencia | 1811 | 08-17 | lead TEST |
| **18** | **info@fsnazareno.es** | **aaaa** | **1808** | 08-17 | **lead "aaaa" (placeholder), asunto `{[CLUB]}` sin renderizar, log id43 plantilla 3** |
| **19** | **hola@riobabel.com** | **riobabel** | **1818** | 08-17 | **lead "riobabel" (test del desarrollador), log id44** |
| 20 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-17 | lead TEST |
| 21 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-17 | lead TEST |
| 22 | test01@futprotec.local | TEST_CLUB_01_RealMadrid | 1809 | 08-17 | lead TEST |

**Nota sobre la whitelist del usuario:** `info@fsnazareno.es` y `hola@riobabel.com` estaban en la lista de "REAL salvo evidencia de prueba". Existe evidencia inequívoca de que fueron pruebas:
- id 18: lead 1808 se llama **"aaaa"** (placeholder), asunto `{[CLUB]} -- Espinilleras personalizadas` (plantilla sin renderizar), log id43 con plantilla 3 "Seguimiento - Catalogo V4.3".
- id 19: lead 1818 se llama **"riobabel"** (lead de prueba del desarrollador), email propio del desarrollador.

Por tanto se clasifican como **TEST_CONFIRMADO**.

---

## 3. REGISTROS REAL DETECTADOS (12 envíos)

| envio_id | email | club | lead_id | fecha | evidencia |
|---|---|---|---|---|---|
| 1 | clubadpparador@gmail.com | A. D. PARADOR C. F. | 155 | 08-07 | histórico REAL |
| 2 | entretorresf7@hotmail.com | A.C.D. ENTRETORRES | 156 | 08-07 | histórico REAL |
| 23 | clubadpparador@gmail.com | A. D. PARADOR C. F. | 155 | 08-17 17:22 | lote REAL camp2 |
| 24 | entretorresf7@hotmail.com | A.C.D. ENTRETORRES | 156 | 08-17 17:22 | lote REAL camp2 |
| 25 | vnavamari@hotmail.com | A.C.D. LICEO SAGRADO CORAZON | 157 | 08-17 17:22 | lote REAL camp2 |
| 26 | isanchez10790@gmail.com | A.C.R. ATLETICO ALCOBENDAS | 1373 | 08-17 17:22 | lote REAL camp2 |
| 27 | acrefaguilas07@gmail.com | A.C.R. ESCUELA DE FUTBOL DE AGUILAS | 1 | 08-17 17:22 | lote REAL camp2 |
| 29 | atleticoserrada@hotmail.com | Atlético Serrada | 1045 | 08-17 18:05 | lote REAL camp2 |
| 30 | cdfabero1953@gmail.com | C.D. Fabero | 1116 | 08-17 18:06 | lote REAL camp2 |
| 31 | cdcondeorgaz@hotmail.es | C.D. CONDE ORGAZ | 1487 | 08-17 18:06 | lote REAL camp2 |
| 32 | clubsanbernabe@gmail.com | C.D. SAN BERNABE | 599 | 08-17 18:06 | lote REAL camp2 |
| 33 | clubatleticobahia@gmail.com | C.D. ATLETICO BAHÍA | 303 | 08-17 18:06 | lote REAL camp2 |

**Evidencia de que los lotes 17:22 y 18:05-18:06 son REALES (no TEST):**
- Van a emails de clubes reales (todos en la whitelist REAL del usuario).
- Usan leads reales (id 1, 155, 156, 157, 303, 599, 1045, 1116, 1373, 1487), todos con `estado_lead = 02 Contactado`.
- En `comunicaciones_log` (id 50-54, 57-61) NO llevan el marcador `[TEST campaña 3]` que sí llevan los envíos TEST.
- Tienen aperturas reales (aperturas 16, 17, 18) desde emails reales de clubes.
- `modo_entorno = produccion`.

---

## 4. REGISTROS AMBIGUOS

**AMBIGUOS = 0**

No hay ningún envío cuya clasificación TEST/REAL sea dudosa. Los 32 envíos se clasifican de forma inequívoca (20 TEST + 12 REAL).

---

## 5. CAUSA DE CONTAMINACIÓN ESTADÍSTICA

1. **La migración `es_test` nunca se desplegó a producción.** La BD LIVE no tiene la columna `envios.es_test`, ni `destinatarios_test`, ni marca TEST en `clubes_crm`.
2. Por tanto, los 20 envíos TEST (incluidos los 2 a emails reales: fsnazareno/aaaa y riobabel) son **indistinguibles** de los REAL en producción.
3. Las consultas de Analytics, Follow-ups, "Envíos Realizados", Aperturas, Rebotes y Bajas **no pueden filtrar TEST** porque no existe la columna.
4. Resultado: los 20 TEST aparecen en "Envíos Realizados" y **contaminan** Envíos, Aceptados SMTP, Aperturas, Open Rate, Respuestas, Reply Rate, PRR, A/B/C, Follow-ups.

**Nota:** El código local (`dashboard.php`, `inc/metricas.php`, etc.) YA tiene los filtros `es_test = 0` implementados (ver cambios locales), pero **no sirven de nada en producción porque la columna no existe** → las consultas fallarían o no filtrarían.

---

## 6. CAMBIOS LOCALES (ya implementados, NO desplegados)

- `public_html/outbound/dashboard.php`: `sqlFiltroComercial()` con `e.es_test = 0`; "Envíos Realizados" → "Histórico Comercial" (solo REAL); filtro [Todos][Comerciales][Pruebas] con default Comerciales; sección "Histórico de Pruebas".
- `public_html/outbound/inc/metricas.php`: todas las métricas comerciales con `es_test = 0`.
- `public_html/outbound/tabs/analytics.php`, `tabs/followups.php`, `tabs/smtp.php`, `tabs/lanzadera.php`, `tabs/modals.php`, `api/enviar_lote.php`, `api/get_cola.php`, `inc/eligibilidad.php`, `js/app.js`: filtros `es_test = 0` en consultas comerciales.
- Helper central: `esLeadTest()` / `esEnvioTest()` (regla única centralizada).

**IMPORTANTE:** Estos cambios dependen de la columna `envios.es_test`, que **NO existe en producción**. Desplegar el código sin la migración de BD rompería las consultas.

---

## 7. ARCHIVOS MODIFICADOS (LOCAL)

- `public_html/outbound/dashboard.php`
- `public_html/outbound/inc/metricas.php`
- `public_html/outbound/tabs/analytics.php`
- `public_html/outbound/tabs/followups.php`
- `public_html/outbound/tabs/smtp.php`
- `public_html/outbound/tabs/lanzadera.php`
- `public_html/outbound/tabs/modals.php`
- `public_html/outbound/api/enviar_lote.php`
- `public_html/outbound/api/get_cola.php`
- `public_html/outbound/inc/eligibilidad.php`
- `public_html/outbound/js/app.js`

---

## 8. TESTS

Pendientes de ejecución (requieren despliegue de migración + código). Ver BLOQUE 13 del task.

---

## 9. ESTRATEGIA DE LIMPIEZA (RECOMENDADA)

**NO borrar físicamente.** Preferir `es_test = 1` + exclusión total de estadísticas comerciales.

1. **Backup** de la BD LIVE (ya descargada: `stats_db_LIVE_audit_1786998346.db`). Crear snapshot `stats_db_pre_test_cleanup.db` de la BD LIVE real.
2. **Migración controlada** en producción: `ALTER TABLE envios ADD COLUMN es_test INTEGER DEFAULT 0;` + `ALTER TABLE clubes_crm ADD COLUMN es_test INTEGER DEFAULT 0;` + crear tabla `destinatarios_test`.
3. **Marcar TEST** los 20 envíos (id 3-22) y los 11 leads TEST (1808-1818) con `es_test = 1`.
4. **Dejar REAL** los 12 envíos (id 1,2,23-27,29-33) y los 10 leads reales con `es_test = 0`.
5. **Verificar** `PRAGMA integrity_check = ok`.
6. **Desplegar código** con filtros `es_test = 0`.
7. **Validar** con los tests A-F del BLOQUE 13.

**No tocar:** `enviar_lote.php` (lógica de envío), `mime.php`, `track.php`, `baja.php`, `eligibilidad.php`, A/B/C, SMTP real, campaign 2, modo_entorno, cron, Lanzadera comercial.

---

## 10. ESTRATEGIA DE AISLAMIENTO FUTURO

- **Fuente de verdad única:** `envios.es_test` (1 = TEST, 0 = REAL) y `clubes_crm.es_test`.
- **Regla central:** `esLeadTest()` / `esEnvioTest()` en un helper común.
- **Todas las consultas comerciales** usan `es_test = 0`.
- **Histórico de Pruebas** usa `es_test = 1`.
- **Destinatarios TEST** gestionados en `destinatarios_test` (sin contraseñas).
- **Prueba A/B/C** usa exclusivamente leads TEST + destinatarios TEST, marca `es_test = 1`, no consume cuota comercial.
- **Cuota TEST** separada de la cuota comercial (documentar limitación si SMTP externo no la distingue).

---

## VEREDICTO

**BLOCKED**

Existe riesgo real de mezclar TEST con REAL si se despliega el código actual sin la migración de BD. La BD de producción NO tiene `es_test`. **NO desplegar, NO borrar registros, NO enviar, NO ejecutar cron** hasta validación del plan de migración + limpieza.
