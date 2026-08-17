# CHECKPOINT — FASE 6F.6 — AUDITORÍA FINAL AISLAMIENTO TEST/REAL

Fecha: 2026-08-17
Estado: **PRE_DEPLOY_TEST_ISOLATION = PASS**

---

## 1. Objetivo

Verificar que el aislamiento TEST/REAL está correctamente implementado en el
código LOCAL (aún NO desplegado) y que las consultas del dashboard devuelven
los valores correctos contra una COPIA de la BD LIVE
(`backups_deploy/stats_db_TEST_MIGRACION.db`).

**NO se ha modificado la BD LIVE. NO se ha enviado ningún email. NO se ha
ejecutado cron. NO se ha hecho deploy/commit/push.**

---

## 2. Datos base de la copia LIVE (verificados)

| Métrica | Valor |
|---|---|
| envios total | 32 |
| envios TEST (es_test=1) | 20 |
| envios REAL (es_test=0) | 12 |
| REAL estado='enviado' | 9 |
| REAL estado='abierto' | 3 |

---

## 3. Consultas del dashboard verificadas (dashboard.php)

### 3.1 KPI "Envíos Realizados" (línea 895)
```sql
SELECT COUNT(*) FROM envios e WHERE e.estado='enviado' AND COALESCE(e.es_test,0)=0
```
→ Devuelve **9** (REAL enviados). Excluye los 20 TEST. ✅

### 3.2 Tabla "Histórico Comercial" (línea 898)
```sql
SELECT e.id, e.club, e.email, ... FROM envios e
WHERE 1=1 AND COALESCE(e.es_test,0)=0 ORDER BY e.id DESC LIMIT 50
```
→ Devuelve **12** (todos los REAL). Excluye los 20 TEST. ✅

### 3.3 get_last_envios (línea 727)
```sql
SELECT e.id, e.club, e.email, ... FROM envios e
WHERE 1=1 AND COALESCE(e.es_test,0)=0 ORDER BY e.id DESC LIMIT 10
```
→ Devuelve **10** REAL (LIMIT 10). ✅

### 3.4 get_test_history (línea 1134)
```sql
SELECT ... FROM envios WHERE COALESCE(es_test,0)=1 ORDER BY id DESC LIMIT 200
```
→ Devuelve **20** TEST. ✅

### 3.5 Rebotes (joins corregidos por email)
Todas las consultas de rebotes usan `JOIN envios e ON LOWER(r.email)=LOWER(e.email)`
(líneas 535, 909, 911, 943, 1011, 1191) + `sqlFiltroComercial('e')`. ✅
No lanzan error SQL.

### 3.6 Aperturas (línea 902, 1189)
```sql
SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a
JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1 AND COALESCE(e.es_test,0)=0
```
→ Devuelve **3** aperturas comerciales. ✅

---

## 4. Resultados de las suites de test

### 4.1 fase6f6_test_live_copy.php (contra copia LIVE)
```
envios total=32, TEST=20, REAL=12
analytics KPI total (estado=enviado, comercial) = 9 ✅
analytics tabla total (todos REAL, comercial)   = 12 ✅
KPI totalEnviados (estado=enviado, comercial)   = 9 ✅
get_last_envios count (comercial)               = 10 ✅
get_test_history count (TEST)                   = 20 ✅
Rebotes joins email: sin error SQL ✅
VEREDICTO: PASS
```

### 4.2 fase6f6_test_aislamiento.php (lógica de aislamiento)
```
8/8 checks de aislamiento PASS
6/6 checks de regresión PASS
✅ TODAS LAS PRUEBAS PASARON
```
(Se añadió la columna `es_test` al esquema en memoria del harness para que
`reservarEnvioLogico()` funcione; el esquema de producción ya la tenía.)

### 4.3 fase_test_aislamiento_verificacion.php (contra copia LIVE)
```
PASS: 11  FAIL: 0
- Histórico comercial 100% REAL
- A. D. PARADOR C. F. y A.C.D. ENTRETORRES presentes en histórico comercial
- Aperturas comerciales excluyen aperturas TEST
- Bajas comerciales excluyen bajas TEST
- No Respondedores no contiene leads TEST
```

---

## 5. Archivos modificados (LOCAL, sin desplegar)

| Archivo | Cambio |
|---|---|
| `public_html/outbound/dashboard.php` | get_analytics tab=envios usa `sqlFiltroComercial('e')`; joins de rebotes por email; KPIs con filtro comercial |
| `public_html/outbound/inc/eligibilidad.php` | `sqlFiltroComercial()`, `esEnvioTest()`, `esLeadTest()`, `esCampanaTest()`, `sqlFiltroCompatibilidadLeadCampana()` |
| `public_html/outbound/inc/metricas.php` | filtros comerciales en métricas |
| `public_html/outbound/api/enviar_lote.php` | aislamiento TEST/REAL antes de SMTP |
| `public_html/outbound/api/smtp.php` | filtros comerciales |
| `public_html/outbound/tabs/smtp.php` | filtros comerciales |
| `scripts/fase6f6_test_aislamiento.php` | añadida columna `es_test` al esquema del harness |
| `scripts/fase6f6_test_live_copy.php` | NUEVO test contra copia LIVE |

---

## 6. Veredicto

**PRE_DEPLOY_TEST_ISOLATION = PASS**

- La BD LIVE está correcta (32 = 20 TEST + 12 REAL).
- El código LOCAL aplica correctamente `COALESCE(es_test,0)=0` en todas las
  consultas comerciales (KPI, Histórico Comercial, aperturas, rebotes, bajas,
  follow-ups, get_lead).
- El Histórico Comercial muestra SOLO los 12 REAL (excluye los 20 TEST).
- El Histórico de Pruebas (get_test_history) muestra SOLO los 20 TEST.
- No hay errores SQL en los joins de rebotes (corregidos a email).

**PENDIENTE (fuera de alcance de esta auditoría):** desplegar estos cambios a
LIVE para que la interfaz LIVE deje de mostrar los TEST. El deploy NO se ha
realizado (regla: no deploy sin petición explícita).
