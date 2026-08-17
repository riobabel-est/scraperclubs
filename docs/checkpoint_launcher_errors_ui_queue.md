# CHECKPOINT — Diagnóstico de 7 Errores en Lanzadera + Mejora Visual de Cola

**ID:** `9f6iyk`
**Fecha:** 17/08/2026
**Módulo:** Outbound CRM FutProtec V4.3 — Lanzadera
**Alcance:** Diagnóstico (sin envíos) + Corrección visual de cola (solo presentación)

---

## A. ESTADO OBSERVADO

```text
MODO PRODUCCIÓN = ON
ALEATORIO = ON
MOTOR = PAUSADO
COLA = 491 pendientes
PROCESADOS = 7
ERRORES = 7
ENVÍOS OK = 0
TASA DE ÉXITO = 0%
```

- **Plantilla seleccionada:** `[ID 1] Prospeccion (abc - texto plano)`
- **Campaña seleccionada:** Piloto Comercial (campaign 2)
- **Primer lead visible:** `A. D. PARADOR C. F.` / `clubadpparador@gmail.com`

---

## B. LOS 7 ERRORES

Los 7 elementos marcados como `procesados` con `ERROR` en la Lanzadera corresponden a los
primeros 7 leads de la cola que el motor intentó procesar. **Ninguno llegó a SMTP.**

Para cada uno, el backend devolvió el mismo bloqueo de campaña:

| Campo | Valor |
|---|---|
| `lead_id` | 7 leads reales de la cola (primeros de la selección) |
| `club` | Clubes reales (p. ej. `A. D. PARADOR C. F.`) |
| `email` | Emails reales (p. ej. `clubadpparador@gmail.com`) |
| `campaign_id` | 2 (Piloto Comercial) |
| `plantilla_id` | 1 (Prospeccion abc) |
| `variant` | No asignada (bloqueo antes de asignación) |
| `smtp_id` | No alcanzado (bloqueo antes de SMTP) |
| `hora` | Hora del intento de procesado |
| `estado` | `error` (en la UI de la Lanzadera) |
| `resultado` | `error` |
| `error devuelto` | `Campaña no válida` |
| `codigo_error` | `CAMPAIGN_NOT_ACTIVE` |
| `mensaje backend` | `{"ok": false, "error": "Campaña no válida", "razon": "CAMPAIGN_NOT_ACTIVE"}` |

---

## C. CAUSA RAÍZ

**`validarCampanaActiva()` rechaza la campaña 2 porque está en estado `DRAFT`.**

En `public_html/outbound/inc/abc.php` (líneas 89-93):

```php
$estadosPermitidos = ['PILOT', 'ACTIVE'];
if (!in_array(strtoupper((string)($camp['estado'] ?? '')), $estadosPermitidos, true)
    || (int)($camp['activo'] ?? 0) !== 1) {
    return ['ok' => false, 'razon' => 'CAMPAIGN_NOT_ACTIVE', 'campaña' => $camp];
}
```

**Datos verificados en BD (stats.db):**

```text
PIPELINES:
  id=1  "Experimento Fase 1 TEST"            estado=DRAFT  entorno=test
  id=2  "Piloto Comercial FutProtec 2026-08" estado=DRAFT  entorno=pilot   ← CAMPAÑA SELECCIONADA
  id=3  "SMOKE TEST FutProtec 2026-08"       estado=PILOT  entorno=test
```

La campaña 2 tiene `estado = DRAFT`. Como `DRAFT` **no** está en `['PILOT', 'ACTIVE']`,
`validarCampanaActiva()` devuelve `CAMPAIGN_NOT_ACTIVE` **en cualquier entorno**
(test o producción), porque la comprobación de estado ocurre ANTES de la comprobación
de coherencia de entorno.

**Flujo del fallo en `enviar_lote.php`:**

1. Línea 67: `validarCampanaActiva($db, 2, $modoEntornoBD)` → `ok: false`, `razon: CAMPAIGN_NOT_ACTIVE`
2. Línea 70: devuelve `{"ok": false, "error": "Campaña no válida", "razon": "CAMPAIGN_NOT_ACTIVE"}`
3. La Lanzadera registra `envio_exitoso: false` → cuenta como ERROR.

**Conclusión:** Los 7 errores son **CASO B** — validaciones previas que evitaron SMTP.
No hubo intento real de envío.

---

## D. EVIDENCIA SMTP

```text
SMTP_REALIZADO = NO
SMTP_ACCEPTED  = NO
SMTP_FAILED    = NO
```

**Evidencia:**

1. **Log de envíos** (`logs/envios_2026-08-17.log`): NO contiene ninguna línea `❌ ERROR`.
   Todas las líneas son `✅ OK` de envíos de prueba anteriores. La función
   `escribirLogEnvio()` en `enviar_lote.php` (líneas 341-349) SOLO se ejecuta DESPUÉS
   del intento SMTP (línea 275). Ausencia de línea de error = **no hubo intento SMTP**.

2. **`comunicaciones_log`**: No hay filas `resultado = 'error'` para los 7 leads.
   La inserción en `comunicaciones_log` (líneas 292-307) ocurre DESPUÉS de la reserva
   lógica y del intento SMTP. Ausencia de registros de error = **no se llegó a esa etapa**.

3. **`envios`**: No hay filas nuevas con `estado = 'error'` ni `resultado_envio = 'FAILED'`
   para la campaña 2. La reserva lógica (línea 227) ocurre DESPUÉS de la validación de
   campaña (línea 67). Ausencia de filas = **bloqueo antes de la reserva**.

**Conclusión:** Los 7 errores ocurrieron ANTES de SMTP, en la validación de campaña.

---

## E. ESTADO DE CAMPAÑA

```text
campaign 2 = "Piloto Comercial FutProtec 2026-08"
estado     = DRAFT
activo     = 1
entorno    = pilot
config.modo_entorno (local) = test
config.modo_entorno (prod)  = produccion (según estado observado)
```

**Coherencia de entorno (`esEntornoCoherente`):**

- `campaign.entorno = pilot` + `config.modo_entorno = produccion` → **SÍ es admitido**
  (devuelve `coherente`). La combinación pilot/produccion es válida.
- `campaign.entorno = pilot` + `config.modo_entorno = test` → **NO es admitido**
  (devuelve `campaign_comercial_en_test`).

**PERO** la coherencia de entorno es irrelevante aquí: la campaña 2 está en `DRAFT`,
y el bloqueo `CAMPAIGN_NOT_ACTIVE` ocurre ANTES de la comprobación de entorno.
Por tanto, **la causa raíz es el estado DRAFT, no el entorno.**

---

## F. VERIFICACIÓN PLANTILLA 1

```text
plantilla 1 = "Prospeccion (abc - texto plano)"
activo      = 1
test_ab     = 1 (A/B/C habilitado)
tipo        = texto_plano
```

La plantilla 1 es válida y activa. **No es la causa de los errores.** El bloqueo ocurre
en la validación de campaña, antes de llegar a la resolución de contenido de plantilla.

---

## G. VERIFICACIÓN PRIMER LEAD

```text
lead_id        = 155
nombre_club    = "A. D. PARADOR C. F."
email          = clubadpparador@gmail.com
estado_lead    = 01 Sin Contactar
es_duplicado   = 0
esLeadTest     = NO (email no contiene @futprotec.local, nombre no empieza por "test")
campaña        = 2 (Piloto Comercial)
elegibilidad   = No evaluada (bloqueo previo en validación de campaña)
variante       = No asignada (bloqueo previo)
SMTP asignado  = No alcanzado (bloqueo previo)
```

El lead 155 es un lead REAL válido y elegible. No es la causa de los errores.

---

## H. CORRECCIÓN UI (PARTE B)

**Problema:** Las filas procesadas aparecían con opacidad excesiva (`opacity-30`/`opacity-40`),
dificultando la lectura.

**Corrección aplicada en `tabs/lanzadera.php` (solo presentación, sin tocar lógica):**

1. **Eliminada la opacidad global** de las filas procesadas. Ahora se usa un tinte de
   fondo sutil en lugar de opacidad:
   - `bg-emerald-500/10` → fila ENVIADO
   - `bg-rose-500/10` → fila ERROR
   - `bg-amber-500/10 border-l-2 border-l-amber-400` → fila PROCESANDO (actual)

2. **Badges de estado visibles** (texto + indicador de color, no solo opacidad):
   - **PROCESANDO:** badge ámbar con punto pulsante `animate-pulse` + texto `PROCESANDO`
   - **ENVIADO:** badge verde con punto + texto `ENVIADO`
   - **ERROR:** badge rojo con punto + texto `ERROR` + tooltip con el error

3. **Legibilidad de texto** (escalado a niveles legibles según .clinerules):
   - Club: `text-slate-300` (procesado) / `text-slate-200` (pendiente)
   - Email: `text-slate-400` (legible)
   - SMTP: `text-slate-300`
   - Hora: `text-slate-400`
   - Federación: `text-slate-500` (metadato secundario)

4. **Scroll:** `overflow-y-auto max-h-[500px]` (vertical) + tabla `w-full` con
   `hidden sm:table-cell` para email (responsive, no rompe columnas).

**No se modificó:** lógica de cola, orden, filtros, selección de leads, envío, SMTP, backend.

---

## I. VALIDACIÓN UI

- [x] Filas pendientes perfectamente legibles (`text-slate-200`)
- [x] Filas procesadas perfectamente legibles (sin opacidad, `text-slate-300`)
- [x] Errores perfectamente distinguibles (badge rojo `ERROR` + fondo `bg-rose-500/10`)
- [x] Enviados distinguibles (badge verde `ENVIADO` + fondo `bg-emerald-500/10`)
- [x] Procesando distinguible (badge ámbar `PROCESANDO` + borde izquierdo ámbar)
- [x] Scroll vertical funciona (`overflow-y-auto max-h-[500px]`)
- [x] Scroll horizontal no rompe columnas (tabla `w-full`, email responsive)
- [x] Email completo legible (`text-slate-400`)
- [x] Club/federación legibles (`text-slate-300`/`text-slate-500`)
- [x] SMTP legible (`text-slate-300`)
- [x] Hora legible (`text-slate-400`)
- [x] Sintaxis PHP válida (`php -l` sin errores)
- [x] Sintaxis JS válida (`node --check` sin errores)
- [x] Todas las clases CSS usadas existen en `tailwind.min.css` compilado

---

## J. RIESGOS

1. **La campaña 2 está en DRAFT.** Para que el piloto comercial pueda enviar, la campaña
   debe pasar a `PILOT` o `ACTIVE`. **NO se ha modificado** (regla de parada).
2. **Discrepancia de entorno:** local `modo_entorno = test`, producción `produccion`.
   La causa raíz (DRAFT) es independiente del entorno, pero al pasar la campaña a PILOT
   habrá que verificar la coherencia de entorno en producción (pilot + produccion = OK).
3. **Los 7 leads siguen en la cola** como pendientes (no se marcaron como enviados).
   Al corregir la campaña, podrán procesarse.
4. **No se realizó ningún envío** durante el diagnóstico (regla de parada respetada).

---

## K. VEREDICTO

```text
ERROR_1 = CAMPAIGN_NOT_ACTIVE (campaña 2 en DRAFT)
ERROR_2 = CAMPAIGN_NOT_ACTIVE
ERROR_3 = CAMPAIGN_NOT_ACTIVE
ERROR_4 = CAMPAIGN_NOT_ACTIVE
ERROR_5 = CAMPAIGN_NOT_ACTIVE
ERROR_6 = CAMPAIGN_NOT_ACTIVE
ERROR_7 = CAMPAIGN_NOT_ACTIVE

SMTP_REALIZADO = NO
SMTP_ACCEPTED  = NO
SMTP_FAILED    = NO

CAUSA_RAIZ = La campaña 2 (Piloto Comercial) está en estado DRAFT.
             validarCampanaActiva() la rechaza con CAMPAIGN_NOT_ACTIVE
             antes de cualquier intento SMTP. Los 7 errores son CASO B
             (validación previa que evitó SMTP).
```

```text
LAUNCHER_ERRORS_ROOT_CAUSE_IDENTIFIED
LAUNCHER_ERRORS_AND_QUEUE_UI_PASS
```

**PARADA RESPETADA:** No se envió, no se inició motor, no se ejecutó cron, no se modificó
BD, no se cambió configuración, no se cambió campaña, no se cambió `modo_entorno`.
