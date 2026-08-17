# CHECKPOINT — GO-LIVE.1B Investigación del Envío Existente `envio_id=18`

**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Campaign 2 (Piloto Comercial)
**Estado:** **GO_LIVE_EXISTING_SEND_RECONCILED**

---

## 1. RECONSTRUCCIÓN `envio_id=18`

Consulta `SELECT * FROM envios WHERE id = 18` (BD remota descargada, SOLO LECTURA):

```text
id                  = 18
lead_id             = 1808
campaign_id         = 2
variant             = B
plantilla_id        = 3
smtp_id             = 9
club                = aaaa
email               = info@fsnazareno.es
cuenta_emision      = adrian.cano@getfutprotec.com
fecha_envio         = 2026-08-17 03:08:09
fecha_resultado_envio = 2026-08-17 03:08:09
estado              = enviado
resultado_envio     = ACCEPTED
tracking_id         = fut_6a827b19_eee21c2aa263
message_id          = <fut_6a827b19_eee21c2aa263@getfutprotec.com>
asunto              = {[CLUB]} -- Espinilleras personalizadas | FutProtec
cuerpo_mensaje      = (plantilla 3 renderizada con tracking)
```

---

## 2. LEAD 1808

Consulta `SELECT * FROM clubes_crm WHERE id = 1808`:

```text
id              = 1808
nombre_club     = aaaa
federacion      = (vacía)
email           = info@fsnazareno.es
telefono        = 697344154
tiene_whatsapp  = 1
estado_lead     = 02 Contactado
observaciones   = [12/08 00:32] assdasdad
                  [LANZADERA 17/08 03:08] Email enviado con plantilla 'Seguimiento - Catalogo V4.3' via adrian.cano@getfutprotec.com
ultimo_contacto = 2026-08-17 03:08:09
creado_el       = 2026-08-06 14:53:15
```

**esLeadTest(lead 1808):**
- email `info@fsnazareno.es` → NO contiene `@futprotec.local`
- nombre_club `aaaa` → NO empieza por `test`
- **Resultado: `esLeadTest = FALSE` → LEAD REAL**

---

## 3. PLANTILLA 3

Consulta `SELECT * FROM plantillas WHERE id = 3`:

```text
id              = 3
nombre          = Seguimiento - Catalogo V4.3
asunto          = {[CLUB]} -- Espinilleras personalizadas | FutProtec
tipo            = html
categoria       = 02 Contactado
activo          = 1
test_ab         = 0
fecha_creacion  = 2026-08-06 11:58:59
```

`envio_id=18` utilizó plantilla 3 porque el operador la seleccionó en la Lanzadera
(categoría `02 Contactado`, coherente con el estado del lead 1808 en ese momento).

---

## 4. COMUNICACIONES_LOG

Registros relacionados con `lead_id = 1808`:

```text
id=41  tipo_evento=cambio_estado  fecha=2026-08-17 03:07:26  detalles="Estado cambiado de '03 Respondió' a '03 Respondió'"
id=42  tipo_evento=cambio_estado  fecha=2026-08-17 03:07:39  detalles="Estado cambiado de '03 Respondió' a '02 Contactado'"
id=43  tipo_evento=envio_email    fecha=2026-08-17 03:08:09  plantilla_id=3  id_cuenta_smtp=9  variante_ab=B  resultado=exito  detalles="Envío a info@fsnazareno.es con plantilla Seguimiento - Catalogo V4.3"
```

No hay registros con `pipeline_id = 2` en `comunicaciones_log`.

---

## 5. LOGS DE ENVÍO

`public_html/outbound/logs/envios_2026-08-17.log` (6 líneas, todas de 01:13 y 01:39,
todas a emails `@futprotec.local` = TEST):

```text
[2026-08-17 01:13:16] ✅ OK | Club: TEST_ABC_FINAL4_A | Email: test_abc_final4_a@futprotec.local | SMTP: rodrigo@getfutprotec.com
[2026-08-17 01:13:40] ✅ OK | Club: TEST_ABC_FINAL4_B | Email: test_abc_final4_b@futprotec.local | SMTP: rodrigo@getfutprotec.com
[2026-08-17 01:13:40] ✅ OK | Club: TEST_ABC_FINAL4_C | Email: test_abc_final4_c@futprotec.local | SMTP: rodrigo@getfutprotec.com
[2026-08-17 01:39:36] ✅ OK | Club: TEST_CLUB_01_RealMadrid | Email: test01@futprotec.local | SMTP: adrian.cano@getfutprotec.com
[2026-08-17 01:39:36] ✅ OK | Club: TEST_ABC_FINAL6_B | Email: test_abc_final6_b@futprotec.local | SMTP: adrian.cano@getfutprotec.com
[2026-08-17 01:39:36] ✅ OK | Club: TEST_CLUB_03_Valencia | Email: test03@futprotec.local | SMTP: adrian.cano@getfutprotec.com
```

**El envío de `envio_id=18` (03:08:09, lead REAL) NO aparece en este log.** El log solo
contiene envíos TEST (`@futprotec.local`). El envío real de la Lanzadera no se registró
en este archivo de log (o se registró en otro canal).

---

## 6. ORIGEN DEL PROCESO

**Evidencia directa en `clubes_crm.observaciones` del lead 1808:**
```text
[LANZADERA 17/08 03:08] Email enviado con plantilla 'Seguimiento - Catalogo V4.3' via adrian.cano@getfutprotec.com
```

El prefijo `[LANZADERA ...]` indica que el envío fue generado por la **Lanzadera**
(UI del dashboard, botón Play individual), no por `cron.php`, `enviar_lote.php` en modo
automático, ni por un script externo.

**Proceso identificado: Lanzadera (UI).** No se ejecutó ningún proceso durante esta
investigación (solo lectura).

---

## 7. SMTP

```text
smtp_id        = 9
email          = adrian.cano@getfutprotec.com
host           = mail.getfutprotec.com
puerto         = 465
seguridad      = ssl
activa         = 1
limite_diario  = 50
enviados_hoy   = 8
ultimo_uso     = 2026-08-17 03:08:09
ultimo_error   = null
```

Message-ID: `<fut_6a827b19_eee21c2aa263@getfutprotec.com>`
Resultado SMTP: `ACCEPTED` (sin error).

---

## 8. TRACKING

Consulta `aperturas WHERE tracking_id = 'fut_6a827b19_eee21c2aa263'`:

```text
Número de aperturas = 0
```

No se registró ninguna apertura del correo.

---

## 9. TIMELINE

```text
GO-LIVE.0 (04:58)  → modo_entorno: test → produccion (camp2=DRAFT, total envios=17)
GO-LIVE.1A pre     → snapshot stats_db_go_live1a_pre_20260817_045946.db (04:59:46)
                     camp2=DRAFT, total envios=17, envio18=0
GO-LIVE.1A working → stats_db_go_live1a_working.db (camp2=PILOT, total envios=17, envio18=0)
                     → subido a remoto
03:08:09 (UTC)     → envio_id=18 creado vía LANZADERA (lead 1808 REAL, plantilla 3,
                     SMTP 9, variante B, ACCEPTED)
GO-LIVE.1A post    → snapshot stats_db_go_live1a_post.db (15:56)
                     camp2=PILOT, total envios=18, envio18=1
```

**Determinación:** `envio_id=18` NO existía en ningún snapshot pre-cambio
(`go_live1a_pre`, `go_live0_post`, `go_live0_working` — todos con total=17, envio18=0).
Fue creado **durante** la ventana GO-LIVE.1A (después del snapshot pre-cambio y antes de
la descarga post-cambio), mediante la **Lanzadera**.

---

## 10. CONCLUSIÓN

```text
ENVIO_18_EXISTIA_ANTES_DE_GO_LIVE = NO
ORIGEN = Lanzadera (UI)
LEAD = REAL
DESTINATARIO = info@fsnazareno.es
PLANTILLA = 3 (Seguimiento - Catalogo V4.3)
SMTP = 9 (adrian.cano@getfutprotec.com)
MESSAGE_ID = <fut_6a827b19_eee21c2aa263@getfutprotec.com>
TRACKING = fut_6a827b19_eee21c2aa263 (0 aperturas)
```

**¿Es un envío generado durante GO-LIVE.1A o un envío previo?**
Es un envío generado **durante** la ventana GO-LIVE.1A (después del snapshot pre-cambio,
antes de la descarga post-cambio), mediante la **Lanzadera**. NO es un envío previo
(no existía en los snapshots pre-cambio). GO-LIVE.1A en sí fue solo verificación en
solo lectura; el envío fue una acción externa de la Lanzadera.

---

## VEREDICTO

```text
GO_LIVE_EXISTING_SEND_RECONCILED
```

El origen del envío `envio_id=18` está claramente identificado: la **Lanzadera** (UI),
con lead REAL, plantilla 3, SMTP 9, variante B, resultado ACCEPTED, sin aperturas.

---

## PARADA

Después del diagnóstico: **DETENIDO.** No se envió, no se revirtió, no se borraron
registros, no se cambió configuración. Solo lectura.
