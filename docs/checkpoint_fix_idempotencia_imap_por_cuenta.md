# CHECKPOINT: Fix idempotencia IMAP por cuenta+UID

**Fecha:** 21/08/2026
**Commit:** a940269
**Estado:** ✅ RESUELTO Y VERIFICADO EN PRODUCCIÓN

---

## Problema detectado

El chequeo de idempotencia en `inc/imap_respuestas.php` (función `imap_registrar_respuesta`)
consultaba `WHERE uid_imap = :uid` **sin filtrar por cuenta**.

Como los UIDs IMAP son secuenciales por buzón (1, 2, 3...), mensajes de **cuentas distintas**
con el mismo UID colisionaban con filas de otras cuentas y se descartaban como
**falsos duplicados**.

### Síntoma en producción
- La BD solo tenía **4 respuestas** registradas.
- Respuestas humanas reales (segosala, cddurcal) y rebotes de múltiples cuentas
  se perdían silenciosamente.
- El log mostraba `⏭ Duplicado (ya registrado)` para mensajes que nunca se habían insertado.

---

## Corrección aplicada

La idempotencia ahora usa `cuenta_uid` (formato `cuentaEmail:uidImap`), que es **único por buzón**:

```sql
-- Antes (bug): colisionaba entre cuentas
WHERE uid_imap = :uid

-- Después (fix): único por cuenta + UID
WHERE cuenta_uid = :cuenta_uid
```

Se añadió la columna `cuenta_uid` a la tabla `respuestas` (migración idempotente)
y se rellena con `{$cuenta_email}:{$uid}`.

---

## Verificación en producción

### Sync re-ejecutado (apply=1)
```
Mensajes procesados: 9
Insertados: 7
Duplicados: 2   ← solo duplicados reales por Message-ID
Errores: 0
```

### BD tras la corrección (9 respuestas)
| id | remitente | subject | cuenta_uid | clasificación |
|----|-----------|---------|------------|---------------|
| 3  | rodrigo@riobabel.com | Re: rodrigo en getfutprotec.com | rodrigo@getfutprotec.com:1 | humana |
| 4  | Mailer-Daemon | Mail delivery failed | adrian.cano@getfutprotec.com:2 | rebote |
| 5  | Mailer-Daemon | Mail delivery failed | mario.ortiz@getfutprotec.com:1 | rebote |
| 6  | Mailer-Daemon | Mail delivery failed | alvaro.ruiz@getfutprotec.com:1 | rebote |
| 7  | Mailer-Daemon | Mail delivery failed | carlos.mora@getfutprotec.com:1 | rebote |
| 8  | **segosala@gmail.com** | **Re: Espinilleras C.D. Segosala** | javier.sanz@getfutprotec.com:1 | **humana** |
| 9  | {143} | | adrian.cano@getfutprotec.com:1 | humana |
| 10 | {86} | | sergio.gil@getfutprotec.com:1 | humana |
| 11 | **cddurcal2026@gmail.com** | **Re: Espinilleras C.D. DURCAL** | sergio.gil@getfutprotec.com:2 | **humana** |

**Resultado:** la BD pasó de 4 a 9 respuestas. Las respuestas humanas reales
(segosala, cddurcal) y los rebotes de mario.ortiz, alvaro.ruiz y carlos.mora
ahora se registran correctamente.

---

## Archivos modificados
- `public_html/outbound/inc/imap_respuestas.php` (función `imap_registrar_respuesta`)

## Despliegue
- ✅ Subido a SiteGround vía FTP (tamaño verificado: 50835 bytes local = remoto)
- ✅ Sync re-ejecutado con token y `apply=1`
- ✅ Commit `a940269` pusheado a GitHub (`main`)
