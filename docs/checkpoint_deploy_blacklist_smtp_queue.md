# CHECKPOINT — DEPLOY LISTA NEGRA + SMTP + COLA/BATCH

**Fecha:** 2026-08-17 18:59 (Europe/Madrid)
**Entorno:** Producción (getfutprotec.com)
**Veredicto:** `DEPLOY_BLACKLIST_SMTP_QUEUE_PASS`

---

## 1. PRE-FLIGHT LOCAL

Verificación de sintaxis de los 3 archivos autorizados:

```text
php -l public_html/outbound/dashboard.php        → OK
php -l public_html/outbound/tabs/lista_negra.php → OK
node --check public_html/outbound/js/app.js      → OK
```

Los 3 archivos corresponden exactamente al código auditado (integración del tab Lista Negra, lógica blacklist_add/remove, getter lzCuentaActivaLimite, batch_size).

---

## 2. BACKUP REMOTO

Backup de los 3 archivos antes de sobrescribir (en `backups_deploy/`):

| Archivo | Backup |
|---------|--------|
| dashboard.php | backups_deploy/ (pre-deploy) |
| tabs/lista_negra.php | backups_deploy/ (pre-deploy) |
| js/app.js | backups_deploy/js_app.js_pre_*.bak |

Backup íntegro de `stats.db` antes de la prueba funcional:

```text
ruta: backups_deploy/stats_db_blacklist_test_pre_1786985921.db
size: 925696 bytes
MD5:  f496d1dbc0e0e80827a4105a2eb04ed9
mtime: 213 20260817162516
integrity_check: ok
```

---

## 3. DEPLOY

Subidos SOLO los 3 archivos autorizados:

```text
public_html/outbound/dashboard.php
public_html/outbound/tabs/lista_negra.php
public_html/outbound/js/app.js
```

NO se subieron: stats.db, enviar_lote.php, get_cola.php, baja.php, mime.php, lanzadera.php.

---

## 4. VERIFICACIÓN DE INTEGRIDAD

```text
dashboard.php       HTTP 200  → MATCH
lista_negra.php     HTTP 200  → MATCH (disponible dentro del dashboard)
app.js              HTTP 200  → MATCH
```

`lista_negra.php` está integrado como tab en el dashboard (botón "Lista Negra" + `include tabs/lista_negra.php`).

---

## 5. PRUEBA LISTA NEGRA — BLOQUEO MANUAL (TEST A y TEST B)

Lead TEST utilizado: **id=1810** `TEST_CLUB_02_Barcelona` (`test02@futprotec.local`).

### TEST A — Añadir manualmente (blacklist_add)

```text
operación = PASS
1810 estado tras add = Lista Negra (suprimido)
esElegibleParaEnvio(1810, campaign=0) = false | razon = supresion
historial = [BLOQUEO MANUAL] ... | fuente=manual | motivo=QA Lista Negra (registrado)
tipo = bloqueo_manual
```

### TEST B — Quitar bloqueo manual (blacklist_remove)

```text
operación = PASS
1810 estado tras remove = 01 Sin Contactar (reactivado)
esElegibleParaEnvio(1810, campaign=0) = true | razon = elegible
historial = conservado ([BLOQUEO MANUAL] + [REACTIVACIÓN] presentes, NO borrado)
```

Nota: con campaign=2 (entorno=pilot, no-test), el lead TEST 1810 reactivado queda bloqueado por aislamiento TEST/REAL (`lead_test_en_campana_no_test`), regla separada (FASE 6F.6) ya validada. La prueba de supresión se aisló con campaign=0.

---

## 6. PRUEBA CRÍTICA — OPT-OUT REAL PROTEGIDO (TEST C)

Lead TEST con baja real: **id=1814** `TEST_ABC_FINAL4_A` (`test_abc_final4_a@futprotec.local`).

```text
historial: [BAJA] 2026-08-17 16:18:48 | fuente=email | envio_id=9
           [BAJA] Motivo baja: Ya tengo proveedor
```

Intento de `blacklist_remove(1814)`:

```text
ok = false
razon = OPTOUT_REAL_PROTEGIDO
error = "Este lead tiene una BAJA REAL del destinatario (opt-out). No puede reactivarse por esta vía."
```

Verificación de inmutabilidad:

```text
1814 estado tras intento = Lista Negra (sin cambios)
1814 historial = intacto (obs_len=338, sin alteración)
esElegibleParaEnvio(1814, campaign=2) = false | razon = supresion
```

**TEST C: PASS — opt-out real protegido, inmutable.**

---

## 7. PRUEBA SMTP — FUENTE ÚNICA DE VERDAD

Límites en BD (`cuentas_smtp.limite_diario`):

```text
id=1  rodrigo@getfutprotec.com        limite_diario=15
id=2  mario.ortiz@getfutprotec.com    limite_diario=15
id=3  alvaro.ruiz@getfutprotec.com    limite_diario=15
id=4  carlos.mora@getfutprotec.com    limite_diario=15
id=5  javier.sanz@getfutprotec.com    limite_diario=15
id=6  diego.navarro@getfutprotec.com  limite_diario=15
id=7  pablo.blanco@getfutprotec.com   limite_diario=15
id=8  gonzalo.vega@getfutprotec.com   limite_diario=15
id=9  adrian.cano@getfutprotec.com    limite_diario=15
id=10 sergio.gil@getfutprotec.com     limite_diario=15
```

La UI muestra `enviados_hoy / limite_diario` (ej: `0 / 15`), tomando `limite_diario` directamente de BD vía `get_cola.php` → `lzCuentasSmtp` → `lzCuentaActivaLimite`.

El `50` de `sf.limite_diario: 50` es SOLO el default del formulario de nueva cuenta (`openSmtp` con id=0) y de `save_account` (`$_POST['limite_diario'] ?? 50`). NO es una fuente funcional del límite mostrado en la UI.

**Nota:** Todas las cuentas tienen actualmente el mismo límite (15). No hay cuentas con límites diferentes para comparar en esta fase (no se modifican límites). La lógica de UI está confirmada: usa `limite_diario` de BD, no hardcodeado.

---

## 8. PRUEBA COLA/BATCH — SIN ENVIAR

Configuración: `batch_size = 1` (sin pulsar INICIAR).

```text
Label UI: "4. Tamaño de Lote (máx. envíos)" | placeholder "1" | "1 = un único envío por ejecución"
app.js:   const batchSize = Math.max(1, parseInt(this.lzBatchSize) || 1);
          doble salvaguarda: if (this.lzSendCalls >= batchSize) { this.lzMotorEstado = 'PAUSADO'; break; }
```

La cola muestra `'(' + lzCola.length + ' candidatos)'` — el contador de candidatos NO es el número de envíos. El batch_size limita los envíos reales de la ejecución.

---

## 9. EXPLICACIÓN DEL CONTADOR

La UI distingue inequívocamente:

```text
CANDIDATOS DISPONIBLES  → lzCola.length (leads elegibles cargados en la cola)
ENVÍOS DE ESTA EJECUCIÓN → batchSize (límite de envíos por ejecución, salvaguarda lzSendCalls)
```

No se cambió el comportamiento de la cola durante esta fase.

---

## 10. TEST DE COLA — SUPRESIÓN

Leads en Lista Negra en BD: **2** (`id=1809` TEST_CLUB_01_RealMadrid, `id=1814` TEST_ABC_FINAL4_A).

`get_cola.php` excluye leads con `estado_lead` en estados de supresión (espejo SQL de `esElegibleParaEnvio()`):

```text
Candidatos elegibles (espejo get_cola) = 1747
Leads suprimidos (Lista Negra/opt-out) = 2  → NO aparecen como candidatos
```

La cola es coherente con `esElegibleParaEnvio()`.

---

## 11. SEGURIDAD

```text
SMTP comercial = 0 (sin envíos comerciales nuevos)
envíos comerciales = 0 (envios_email_hoy=14, sin cambios respecto a baseline)
cron = NO
Lanzadera = NO (motor_estado = pausado)
campaign 2 modificada = NO (entorno=pilot, estado=PILOT, sin cambios)
modo_entorno = produccion (sin cambios)
```

Solo se modificó temporalmente el lead TEST 1810 (restaurado al snapshot inicial al final).

---

## 12. RESTAURACIÓN Y VERIFICACIÓN FINAL

```text
1810 POST = idéntico a PRE (estado=01 Sin Contactar, obs_len=162)
1814 POST = idéntico a PRE (estado=Lista Negra, obs_len=338)
PRAGMA integrity_check = ok
campaign 2 sin cambios
modo_entorno = produccion
motor_estado = pausado
envios = sin cambios
SMTP = sin cambios
```

---

## VEREDICTO

```text
DEPLOY_BLACKLIST_SMTP_QUEUE_PASS
```

Todos los puntos pasaron:
- opt-out real protegido ✓
- bloqueo manual correcto ✓
- límite UI = BD (15, no 50) ✓
- supresión no aparece en cola ✓
- batch UI no ambiguo (candidatos vs envíos) ✓
- MD5 match ✓

---

## PARADA FINAL

- NO se envió ningún correo.
- NO se inició la Lanzadera.
- NO se ejecutó cron.
- NO se hizo el micro-lote.
- NO se modificó campaign 2.
- NO se cambió modo_entorno.

## Scripts de soporte

```text
scripts/deploy_blacklist_audit_db.py        → auditoría BD remota (solo lectura)
scripts/deploy_blacklist_functional_test.py → prueba funcional Lista Negra (TEST A/B/C + restauración)
scripts/deploy_blacklist_postverify.py      → verificación post-deploy (leads, SMTP, cola, seguridad)
```
