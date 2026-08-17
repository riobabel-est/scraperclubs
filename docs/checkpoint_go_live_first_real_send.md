# CHECKPOINT — GO-LIVE.1 Primer Envío Real (BLOQUEADO)

**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Primer envío comercial real
**Estado:** **BLOCKED** — Pre-flight fallido

---

## A. ESTADO PRE (verificado en BD remota de producción)

Se descargó la BD remota de producción (`/getfutprotec.com/public_html/outbound/data/stats.db`,
917504 bytes, mtime 2026-08-17 02:46:38) y se inspeccionó en SOLO LECTURA.

```text
config.modo_entorno = test        ← REQUERIDO: produccion  → FALLA
config.motor_estado = pausado     ← REQUERIDO: pausado     → OK

campaign 2:
    estado  = DRAFT               ← REQUERIDO: DRAFT       → OK
    entorno = pilot
    activo  = 1

plantilla 1:
    activo  = 1
    test_ab = 1
    tipo    = texto_plano

envios campaign 2 = 0             ← OK (sin envíos previos)
envios pendientes = 0             ← OK (sin proceso activo)
envios error      = 0             ← OK
```

**Primer lead real disponible (01 Sin Contactar, no test):**
```text
lead_id = 1
club    = A.C.R. ESCUELA DE FUTBOL DE AGUILAS
email   = acrefaguilas07@gmail.com
```

**Cuentas SMTP activas:** 10 cuentas (rodrigo@, mario.ortiz@, alvaro.ruiz@, carlos.mora@,
javier.sanz@, diego.navarro@, pablo.blanco@, gonzalo.vega@, adrian.cano@, sergio.gil@),
todas con límite diario 50.

---

## B. CAMBIO DE CAMPAÑA

**NO REALIZADO.** El pre-flight falló, por lo que NO se modificó la campaña 2.

La campaña 2 permanece en `DRAFT`.

---

## C. CONFIGURACIÓN DE LANZADERA

**NO APLICADA.** No se configuró la Lanzadera ni se cargó la cola.

---

## D. LEAD SELECCIONADO

**NO SELECCIONADO.** No se seleccionó ningún lead para envío.

---

## E. EJECUCIÓN ÚNICA

**NO REALIZADA.** No se envió ningún correo.

---

## F. RESULTADO SMTP

```text
SMTP_REALIZADO = NO
SMTP_ACCEPTED  = NO
SMTP_FAILED    = NO
```

No hubo ningún intento de envío.

---

## G. TRAZABILIDAD `envios`

Sin cambios. `envios` no fue modificado.

---

## H. `comunicaciones_log`

Sin cambios. No se insertó ningún evento.

---

## I. ESTADO DEL LEAD

Sin cambios. Ningún lead fue modificado.

---

## J. SEGURIDAD

```text
envíos realizados durante esta fase = 0
otros leads enviados durante esta fase = 0
segundo POST de envío = NO
cron = NO
enviar_smtp_random.php = NO
Evolution API = NO
campaign_id distinto = NO
modo_entorno cambiado durante esta fase = NO
motor_estado cambiado durante esta fase = NO
campaña 2 modificada = NO
```

La única escritura realizada fue la descarga de la BD remota a un backup local
(`backups_deploy/stats_db_go_live_preflight.db`) para inspección de solo lectura.
**No se modificó la BD remota.**

---

## K. VEREDICTO

```text
BLOCKED
```

**Motivo:** El pre-flight (PARTE A) falla porque `config.modo_entorno = test` en la BD
remota de producción, pero la fase GO-LIVE.1 requiere `config.modo_entorno = produccion`.

**Consecuencia adicional:** Aunque se cambiara la campaña 2 a `PILOT`, el envío sería
bloqueado por `esEntornoCoherente()` con `campaign_comercial_en_test` (campaña pilot +
modo test = incoherente). Por tanto, el envío NO llegaría a SMTP y generaría más errores.

**Acción requerida antes de reintentar:**
1. Confirmar el estado real de `config.modo_entorno` en la BD remota de producción.
2. Si debe ser `produccion`, corregirlo (con autorización explícita del usuario, ya que
   la fase prohíbe cambiar `modo_entorno` por sí misma).
3. Re-ejecutar el pre-flight y verificar que `modo_entorno = produccion`.
4. Solo entonces proceder con el cambio de campaña 2 a `PILOT` y el envío único.

**PARADA RESPETADA:** No se envió, no se inició motor, no se ejecutó cron, no se modificó
BD remota, no se cambió campaña, no se cambió configuración, no se cambió `modo_entorno`.
