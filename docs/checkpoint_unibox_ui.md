# Checkpoint — Unibox Split-View UI (Respuestas y Notificaciones)

**Fecha:** 20/08/2026
**Alcance:** Rediseño completo de la pestaña "Respuestas" a Unibox Split-View + corrección de campana de notificaciones + integración de respuesta SMTP en 1 clic.

---

## Resumen

Se transformó la pestaña "Respuestas" (`tabs/respuestas.php`) de una tabla tradicional con modal flotante a una interfaz **Unibox Split-View** (estándar Instantly/Smartlead/HubSpot) con dos paneles reactivos sin recargas de página. Se corrigió el renderizado de correos (HTML y texto plano), se eliminaron las expresiones huérfanas `{{ rsNuevas }}` / `{{ rsToast }}` de la campana de notificaciones y se conectó el envío de respuesta SMTP al backend.

---

## FASE 1 — Rediseño Unibox Split-View (Frontend)

**Archivo:** `public_html/outbound/tabs/respuestas.php`

- Eliminada la tabla tradicional y el modal flotante (`popup`) de la pestaña de respuestas.
- Implementado layout de dos columnas flexibles en pantalla completa (`height: calc(100vh - 120px)`):
  - **Panel Izquierdo (Lista de Triaje, 35%):** `border-r border-slate-800`.
    - Barra superior con buscador por nombre de club y selector de filtro por clasificación (`Todas`, `Interesado`, `Duda Precio`, `Baja`).
    - Tarjeta de lead con: `nombre_club` (negrita `#f8fafc`) + fecha/hora (`text-xs text-slate-400`), `contacto_nombre` + badge de volumen (`volumen_equipos`), snippet de 110 caracteres de `cuerpo` en `#94a3b8` con `truncate`, y badges de intención por clasificación (verde/amarillo/rojo).
    - Estado seleccionado: `border-l-4 border-orange-500 bg-slate-800/80`.
  - **Panel Derecho (Visor de Conversación, 65%):**
    - Header de ficha rápida fijo con `nombre_club`, `contacto_nombre`, `telefono`, `variante`, `estado_lead` y desplegable para actualizar estado en tiempo real.
    - Cuerpo central con scroll independiente para el hilo de mensajes.
    - Mensaje enviado (FutProtec) alineado a la derecha (`bg-slate-800 text-slate-200 border border-slate-700`).
    - Mensaje recibido (Club) alineado a la izquierda (`bg-slate-900 text-slate-100 border border-slate-700`).
    - Soporte dual de renderizado: si existe `contenido_html` se renderiza en contenedor sanitizado; si existe `cuerpo` (texto plano) se aplica con `white-space: pre-wrap; font-family: sans-serif; font-size: 14px; line-height: 1.6;`.
    - Footer con editor de texto, selector de plantillas rápidas, botón `[Enviar Respuesta SMTP]` (naranja) y botón `[Abrir WhatsApp Directo]` (verde con enlace dinámico `https://wa.me/34...`).

**Verificación Fase 1:** Interfaz dividida 35%/65%, sin modales flotantes al hacer clic en un lead, estructura visual accesible.

---

## FASE 2 — Optimización API y Consulta SQL (Backend)

**Archivo:** `public_html/outbound/api/analytics.php`

- Refactorizada la consulta SQL que alimenta la pestaña de respuestas:
  - `LEFT JOIN` estricto entre `respuestas` y `clubes_crm` vinculando por `lead_id` o `email`.
  - Extracción explícita de: `SUBSTR(respuestas.cuerpo, 1, 150) AS snippet`, `respuestas.cuerpo`, `respuestas.contenido_html`, `respuestas.fecha`, `clubes_crm.nombre_club`, `clubes_crm.contacto_nombre`, `clubes_crm.telefono`, `clubes_crm.volumen_equipos`, `clubes_crm.variante`, `clubes_crm.estado_lead`.
  - `CASE WHEN` para resolver `nombre_club` desde `clubes_crm` o desde el remitente de la respuesta.
  - Campos adicionales: `remitente_email`, `buzon_destino`, `subject`/`asunto_envio`.
- Estructura JSON de salida con lista de conversaciones y hilo contiguo ordenado cronológicamente por `fecha ASC`.

**Verificación Fase 2:** JSON contiene todos los campos necesarios sin valores nulos en datos del club ni en el snippet.

---

## FASE 3 — Corrección Campana de Notificaciones y Binding Alpine.js

**Archivos:** `public_html/outbound/dashboard.php`

- Eliminadas las expresiones textuales huérfanas `{{ rsNuevas }}` y `{{ rsToast }}`.
- Binding correcto con Alpine.js en la campana de notificaciones:
  - `<span x-show="rsNuevas > 0" x-cloak x-text="rsNuevas" class="...">`.
- Estilos de insignia con alto contraste (WCAG AA):
  - `background-color: #f97316;` (naranja brillante).
  - `color: #ffffff;` (texto blanco en negrita).
  - `font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 9999px; border: 2px solid #0f172a; shadow: 0 0 10px rgba(249,115,22,0.5);`.

**Verificación Fase 3:** Cuando `rsNuevas` es 0 no se muestra la insignia; cuando es > 0 resalta en naranja sin llaves visibles.

---

## FASE 4 — Integración Respuesta en 1-Clic y Prueba de Flujo

**Archivos:** `public_html/outbound/dashboard.php`, `public_html/outbound/js/app.js`, `public_html/outbound/tabs/respuestas.php`

### Backend (`dashboard.php`)

Se añadieron dos endpoints AJAX nuevos (antes de la autenticación, igual que `get_lead`/`update_lead`):

1. **`actualizar_estado_lead`** — Actualiza `clubes_crm.estado_lead` en tiempo real desde el desplegable del panel derecho.
   - Parámetros: `lead_id`, `estado`.
   - Estados permitidos: `Interesado`, `Duda Precio`, `Baja`, `Neutral`, `No Interesa`, `Pendiente`.

2. **`enviar_respuesta_smtp`** — Envía una respuesta SMTP al lead usando una cuenta activa de `cuentas_smtp`.
   - Parámetros: `lead_id`, `email`, `cuerpo`, `asunto`, `envio_id`.
   - Usa `futprotec_enviarSMTP()` del transporte centralizado (`inc/smtp_transport.php`).
   - Selecciona cuenta SMTP activa con rotación y límite diario (`ORDER BY RANDOM()`).
   - Incrementa `enviados_hoy` de la cuenta.
   - Registra en `envios` con `lead_id` para trazabilidad (la columna `es_respuesta` NO existe en la tabla `envios`, se usa `lead_id` en su lugar).

### Frontend (`app.js`)

- `rsEnviarRespuesta()` envía `action=enviar_respuesta_smtp` con `lead_id`, `email`, `cuerpo`, `asunto`, `envio_id`.
- `rsActualizarEstadoLead()` envía `action=actualizar_estado_lead` con `lead_id`, `estado`.
- Enlace WhatsApp dinámico: `https://wa.me/34` + `telefono_limpio` + `?text=Hola%20` + `contacto_nombre` + `,%20vi%20tu%20respuesta%20sobre%20las%20espinilleras...`.

**Verificación Fase 4:** Comprobación de errores sintácticos superada:
- `php -l dashboard.php` ✓
- `php -l smtp_transport.php` ✓
- `php -l tabs/respuestas.php` ✓
- `php -l api/analytics.php` ✓
- `node --check js/app.js` ✓

---

## Notas Técnicas

- **Columna `es_respuesta`:** No existe en la tabla `envios`. Se usa `lead_id` para vincular la respuesta al lead.
- **Transporte SMTP:** Se reutiliza `futprotec_enviarSMTP()` de `inc/smtp_transport.php` (única definición en el proyecto, sin conflictos de redeclaración).
- **Compatibilidad:** PHP 8.x nativo, SQLite3, Alpine.js + Tailwind CSS en modo oscuro. Sin frameworks externos.
- **Sin cambios de esquema BD:** No se crearon ni eliminaron tablas.

---

## Archivos Modificados

| Archivo | Cambio |
|---------|--------|
| `public_html/outbound/tabs/respuestas.php` | Rediseño Unibox Split-View |
| `public_html/outbound/api/analytics.php` | Refactor SQL + JSON |
| `public_html/outbound/dashboard.php` | Campana notificaciones + endpoints backend |
| `public_html/outbound/js/app.js` | Funciones `rsEnviarRespuesta` / `rsActualizarEstadoLead` |
