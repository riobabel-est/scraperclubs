# CHECKPOINT — LISTA NEGRA BIDIRECCIONAL (FutProtec V4.3)

**Fecha:** 17/08/2026
**Estado:** ✅ DESPLEGADO EN PRODUCCIÓN (solo UI, sin tocar BD real)
**Veredicto:** `BLACKLIST_BIDIRECTIONAL_MANAGEMENT_PASS`

---

## 1. OBJETIVO

Permitir al operador **añadir y quitar** cualquier contacto de Lista Negra, tanto desde la
ficha del lead como desde la propia Lista Negra, conservando siempre el historial y
manteniendo la supresión efectiva mientras el contacto siga en Lista Negra.

Se elimina por completo el concepto visual **"Protegido"**.

---

## 2. ARCHIVOS MODIFICADOS (4)

| Archivo | Cambio |
|---------|--------|
| `public_html/outbound/dashboard.php` | Integración de tab Lista Negra y modals |
| `public_html/outbound/js/app.js` | Lógica bidireccional: `blacklist_add`, `blacklist_remove`, confirmación con motivo obligatorio, refresh de UI |
| `public_html/outbound/tabs/lista_negra.php` | Columna Acción = `[ Quitar de Lista Negra ]` para TODOS los contactos. Sin "Protegido" |
| `public_html/outbound/tabs/modals.php` | Modal de ficha: `[ Añadir a Lista Negra ]` / `[ Quitar de Lista Negra ]` con motivo obligatorio |

**Commit:** `b20c1d9` (pushed a origin/main)

---

## 3. CAMBIOS FUNCIONALES

### blacklist_add (dashboard.php / app.js)
- Añade **cualquier lead** a Lista Negra.
- Parámetros: `lead_id`, `motivo`, `fecha`, `origen=manual`.
- Resultado: `estado_lead = 'Lista Negra'`, `suprimido = true`.
- Guarda `estado_lead_backup` (estado anterior) para restauración segura.
- Registra en `observaciones`: `[LISTA NEGRA] <fecha> | fuente=manual | motivo=...`.
- Registra en `comunicaciones_log` tipo `blacklist_add`.

### blacklist_remove (dashboard.php / app.js)
- Quita **cualquier lead actualmente suprimido** (bloqueo manual, opt-out real, etc.).
- Parámetros: `lead_id`, `motivo_reactivacion` (**obligatorio**).
- Restaura `estado_lead` al `estado_lead_backup` si existe y no es supresión; si no, usa regla explícita `01 Sin Contactar`.
- Limpia `estado_lead_backup`.
- **NUNCA borra historial**: añade `[REACTIVACIÓN] <fecha> | fuente=manual | quitar_lista_negra | motivo=...`.
- Registra en `comunicaciones_log` tipo `blacklist_remove`.

### Elegibilidad (`esElegibleParaEnvio`)
- Tras quitar de Lista Negra, devuelve `ok=true, razon=elegible` si no existe otra causa
  (otra supresión, duplicado, email inválido, incompatibilidad TEST/REAL, campaña inválida).
- **No se modificó** el resto de reglas de elegibilidad.

### Cola
- Excluye únicamente contactos **ACTUALMENTE** en Lista Negra.
- Al quitar de Lista Negra, el contacto puede volver a aparecer en cola si cumple el resto de criterios.
- **No se borran** registros históricos de envíos, aperturas ni comunicaciones.

### Seguridad
- Toda modificación vía POST/API autenticada (nunca GET).
- SQL preparado, validación de datos del lead, motivo obligatorio para reactivación.
- Sin hardcodear credenciales.

---

## 4. TESTS EJECUTADOS

### 4.1 Validación local (pre-deploy)
- `php -l` en dashboard.php, lista_negra.php, modals.php → OK
- `node --check` en app.js → OK
- Test local `scripts/test_blacklist_bidirectional.php` → **32/32 PASS**

### 4.2 Deploy y verificación
- Backup remoto de 4 archivos → OK
- Deploy FTP 4/4 → OK
- Verificación MD5 (LOCAL == REMOTE) → **PASS**

### 4.3 HTTP (producción) — `BLACKLIST_BIDIRECTIONAL_HTTP_PASS` (7/7)
- `js/app.js?v=10` → 200, contiene "Quitar de Lista Negra", sin "Protegido"
- dashboard.php, lista_negra.php, modals.php → responden (auth requerida, no 500)

### 4.4 Prueba funcional en producción (copia de BD, no subida) — `BLACKLIST_BIDIRECTIONAL_PRODUCTION_FUNCTIONAL_PASS` (24/24)

| Test | Descripción | Resultado |
|------|-------------|-----------|
| **A** | Añadir lead normal → suprimido, inelegible, visible en Lista Negra | ✅ A1-A6 |
| **B** | Quitar con motivo obligatorio → no suprimido, elegible, historial permanece | ✅ B1-B9 |
| **C** | Opt-out real (1814) → quitar PERMITIDO → elegible, historial `[BAJA] fuente=email` intacto | ✅ C1-C5 |
| **D** | Ciclo añadir→quitar→añadir→quitar → historial no se pierde | ✅ D1-D4 |

### 4.5 Seguridad / Regresión — `BLACKLIST_BIDIRECTIONAL_SECURITY_PASS` (13/13)
- `modo_entorno = produccion` ✅
- `motor_estado = pausado` ✅ (este deploy no dispara envios)
- `campaign2` no es clave de config (gestionado en UI) ✅
- Leads de prueba 1810, 1811, 1814 en estado ORIGINAL en BD real ✅
- Sin marcas `[LISTA NEGRA]`/`[REACTIVACIÓN]` de prueba en BD real ✅
- `integrity_check = ok` ✅
- Archivos "no tocar" (enviar_lote, mime, track, get_cola, cron, baja, eligibilidad) sin diff en git ✅

---

## 5. SEGURIDAD DE LA BD REAL

- La prueba funcional se ejecutó sobre una **COPIA** descargada de `stats.db`.
- La copia modificada **NO se subió** al remoto.
- La BD real quedó **intacta** (verificado: leads de prueba en estado original, sin marcas de prueba).

---

## 6. NO TOCADO (BLOQUE 12)

No se modificaron: `enviar_lote.php`, `mime.php`, `track.php`, `get_cola.php`, `cron.php`,
`baja.php`, `eligibilidad.php`, SMTP, A/B/C, idempotencia, campaign2, modo_entorno, cron.

---

## 7. VEREDICTO

```
BLACKLIST_BIDIRECTIONAL_MANAGEMENT_PASS
```

La gestión de Lista Negra es ahora **bidireccional**: el operador puede añadir y quitar
cualquier contacto desde la ficha y desde la Lista Negra, con motivo obligatorio para
reactivación, historial completo conservado, y supresión efectiva mientras el contacto
siga en Lista Negra. Sin "Protegido".

**Pendiente de decisión del usuario:** no se ha hecho `git push` adicional ni se ha
desplegado nada más allá de lo solicitado. La BD real no se ha modificado.
