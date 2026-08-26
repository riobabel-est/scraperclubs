# PLAN — Modal de Atención a Medida por Lead (Asistente IA v2)

**Fecha:** 2026-08-25 · **Estado:** ✅ IMPLEMENTADO (local, sin commit) — v1.0
**Objetivo:** sustituir las tarjetas genéricas del Asistente IA por un **modal de atención uno a uno** que parte del contexto real del lead (charla, SMTP heredada, mockups/presupuestos) y usa la IA para redactar el email a medida. Lanzamiento sigue siendo bulk; la atención es a medida.

---

## 1. Punto de entrada

En la cola de Seguimiento (Perseguir / Avanzar / Calentar), **cada fila gana una columna de acción**:

```
| Prioridad | Club / Email          | Última acción | Días | Envíos | Apert. | Temp. |           |
|-----------|------------------------|---------------|------|--------|--------|-------|-----------|
| Alta      | C.D. Fabero            | 17/08 18:06   | 8    | 1      | 5      | ⏳    | 🎯 Atender |
| Media     | A.D. Sancti Petri ...  | 16/08 10:20   | 9    | 1      | 2      | ⏳    | 🎯 Atender |
```

- El botón **🎯 Atender** abre el modal con el contexto del lead ya cargado.
- Las tarjetas del Asistente IA actual se sustituyen por la **"Lista de hoy"**: el motor de reglas solo marca **qué leads atender primero** (por prioridad y tipo de acción recomendada). No genera mensajes en lote (ahorra tokens).

---

## 2. Mockup del modal (esquema en pantalla)

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  ✕                                                                                    │
│  ┌────────────────────────────  CABECERA  ──────────────────────────────────────────┐  │
│  │  C.D. FABERO                                                      [04 Propuesta] │  │
│  │  Real Federación de Castilla y León · cdfabero1953@gmail.com      ⏳ Tibio · Alta │  │
│  │  Contacto: — (email genérico → la IA no inventará nombre)        Volumen: 150     │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
│                                                                                        │
│  ┌─────────────── IZQUIERDA: LA CHARLA ───────────────┐  ┌────────── DERECHA: REDACTAR + ENVIAR ──────────┐ │
│  │  💬 Charla con el club        (scroll, máx 340px) │  │  ✨ [Generar respuesta con IA]                  │ │
│  │                                                  │  │  ┌ Asunto ─────────────────────────────────┐     │ │
│  │  📤 17/08 18:05 · variante C · sergio.gil@…     │  │  │ [¿Te ayudo a simplificar la gestión…]     │     │ │
│  │  └ "Asunto: Presentación de FutProtec…"         │  │  │ ──────────────────────────────────────── │     │ │
│  │                                                  │  │  └──────────────────────────────────────────┘     │ │
│  │  👁 17/08 22:10 · 1ª apertura                    │  │  ┌ Cuerpo (editable, base: plantilla) ────┐       │ │
│  │  👁 18/08 09:30 · 2ª apertura … (5 total)        │  │  │ Hola, responsables del club:            │       │ │
│  │                                                  │  │  │                                        │       │ │
│  │  📥 19/08 11:00 · respuesta                      │  │  │ Veo que habéis consultado varias veces  │       │ │
│  │  └ "Hola, ¿podéis mandar un presupuesto…"        │  │  │ nuestra propuesta…                       │       │ │
│  │                                                  │  │  │                                        │       │ │
│  │  🎨 20/08 · mockup solicitado                    │  │  └────────────────────────────────────────┘       │ │
│  │  🧾 21/08 · presupuesto v1 · 3.450 €            │  │                                                  │ │
│  │                                                  │  │  [⟳ Regenerar] [📋 Elegir plantilla ▼]          │ │
│  │  📅 Próxima acción: — (pendiente)                │  │                                                  │ │
│  │  (la IA ve TODO esto para escribir)              │  │  ── ENVÍO ──                                    │ │
│  │                                                  │  │  Cuenta SMTP (heredada del último envío):        │ │
│  └──────────────────────────────────────────────────┘  │  [sergio.gil@getfutprotec.com      ▼]             │ │
│                                                        │  ☑ Incluir mockup (solicitado)                    │ │
│                                                        │  ☑ Incluir proforma (3.450 €)                     │ │
│                                                        │  ────────────────────────────────────              │ │
│                                                        │  [🚀 ENVIAR a medida]                              │ │
│                                                        └───────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────────────────┘
```


## 3. Comportamiento detallado

### 3.1 Cabecera
- **Club, federación, email** (datos reales de `clubes_crm`).
- Badges: estado pipeline, temperatura (🥶/⏳/🔥/🌋), prioridad, volumen estimado.
- **Contacto**: si `persona_contacto` está vacío → se muestra *"email genérico"* y la IA **nunca** usa saludo personal ("Hola [Nombre]" está PROHIBIDO en ese caso).

### 3.2 Zona izquierda — La charla (timeline real)
Fuentes (todo de la BD, sin inventar):
- `envios` → 📤 email saliente (fecha, variante A/B/C, asunto, cuenta emisora).
- `aperturas` → 👁 nº de aperturas (con fecha de la 1ª).
- `respuestas` → 📥 texto de la respuesta recibida (truncado a 300 car.).
- `mockups` → 🎨 estado (solicitado/en producción/enviado) + fechas.
- `presupuestos` → 🧾 versión, importe total, estado.
- `clubes_crm` → 📅 próxima acción / fecha límite.

### 3.3 Zona derecha — Redactar y enviar
- **✨ Generar respuesta con IA**: endpoint `generar_email_ia(lead_id)` → construye el prompt con:
  1. Historial real (la charla completa de la izquierda, resumida).
  2. Conocimiento de producto (`config.ia_conocimiento_producto`).
  3. Plantilla base seleccionada (si el usuario eligió una) → "re-escribe esta plantilla adaptándola al contexto".
  - Regla de salida: **asunto + cuerpo**, saludo sin inventar nombre cuando no hay contacto, máximo 120 palabras, tono comercial B2B.
- **Regenerar**: nueva llamada con el mismo contexto.
- **Editable**: el usuario SIEMPRE puede corregir antes de enviar (human-in-the-loop).
- **Elegir plantilla**: selector de plantilla de la campaña → prefill del cuerpo (estandarizado), sobre el que la IA puede adaptar.

### 3.4 Envío a medida
- **Cuenta SMTP heredada**: `SELECT smtp_id FROM envios WHERE lead_id = ? ORDER BY id DESC LIMIT 1` → nombre del remitente de `cuentas_smtp`. Selector editable con el resto de cuentas.
- **☑ Incluir mockup**: visible solo si el lead tiene mockup en `solicitado`/`en_producción`. Al enviar → `UPDATE mockups SET estado='enviado'`.
- **☑ Incluir proforma**: visible solo si tiene `presupuestos.estado='creado'`. Al enviar → referencia el importe/versión en el cuerpo (cuando exista PDF, se adjuntará aquí).
- **🚀 ENVIAR** → reutiliza `api/enviar_lote.php` (`id_club, id_plantilla, id_cuenta_smtp`) → registra en `envios` + `comunicaciones_log` y actualiza `ultimo_contacto`.

---

## 4. Backend nuevo (PHP core / SQLite — SiteGround compatible)

| Endpoint | Función |
|---|---|
| `get_charla_lead(lead_id)` | Junta timeline completo + SMTP heredada + mockup/presupuesto del lead |
| `generar_email_ia(lead_id, plantilla_id?)` | Prompt con historial real + conocimiento + plantilla → asunto+cuerpo |
| (reutilizado) `api/enviar_lote.php` | Envío individual con `id_cuenta_smtp` |

- `inc/llm.php` ya existe y sirve para `generar_email_ia` (mismo cliente multi-proveedor).
- `inc/motor_propuestas.php` se **simplifica**: ya no redacta en lote; solo reglas → "Lista de hoy".

---

## 5. Lo que se elimina / conserva

- **Se elimina**: tarjetas del Asistente IA con mensajes pre-escritos en lote (`propuestas_ia.mensaje_sugerido` deja de usarse).
- **Se conserva**: el motor de reglas (perseguir/avanzar/calentar/mockup/proforma) como ordenador de la "Lista de hoy"; tabla `propuestas_ia` para el historial de aprobaciones/rechazos si se quiere.
- **Se conserva**: botón ENVIAR con el mismo motor que el bulk (coherencia de tracking, aperturas, logs).

## 6. Validación
- `php -l` en los archivos tocados · `node --check app.js`
- Test `scripts/smoke_charla_lead.php` (BD copia sin API key)
- Render del dashboard en local con el modal.
