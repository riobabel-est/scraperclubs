# CHECKPOINT — FASE 4C PRE-IMPLEMENTACIÓN (auditoría)

**FECHA:** 2026-08-14
**ALCANCE:** Auditoría breve del estado actual antes de añadir el panel de respuestas. No se ha modificado código ni BD.

---

## Estado actual verificado
- **Lógica backend de respuestas:** existe en `public_html/outbound/inc/respuestas.php` (FASE 4B) con:
  - `CLASIFICACIONES_VALIDAS` (PENDING, POSITIVE, NEGATIVE, NEUTRAL, UNSUBSCRIBE, OOO).
  - `generarMessageIdEnvio()`, `registrarRespuesta()` (idempotente + UNSUBSCRIBE→supresión), `contextoEnvio()`.
- **Tabla `respuestas`:** creada en FASE 4B con `envio_id`, campos de respuesta, `clasificacion`, `fecha_clasificacion`, `estado_procesamiento`, `message_id` (UNIQUE parcial), `in_reply_to`, `references`.
- **`envios.message_id`:** añadido y persistido en reserva (P1/P3).
- **UI/endpoints conectados a respuestas:** **NO EXISTEN.** `dashboard.php` no tiene `$action` para listar/ver/clasificar respuestas; no hay tab "Respuestas"; `js/app.js` no tiene estado ni funciones de respuesta.

## Archivos revisados
- `inc/respuestas.php`, `inc/eligibilidad.php`, `api/enviar_lote.php`, `cli/cron.php`
- `dashboard.php` (lista completa de `$action === '...'`), `tabs/*.php` (no hay `respuestas.php`)
- `js/app.js` (boot/estructura de estado)

## Acciones AJAX existentes en dashboard.php
update_lead, add_lead, update_config, get_lead, mockup_capacity, mockup_solicitar, mockup_enviado, get_interacciones, registrar_interaccion, snapshot_crear, presupuesto_crear, save_template, delete_template, get_templates, get_categorias, preview_template, get_last_envios, get_followups, get_analytics.

## Brechas a cubrir (mínimas)
1. Backend: listar respuestas con contexto (club, email, campaña, variante, asunto original, timestamp envío, respuesta), leer una respuesta con contexto, y clasificar/rec clasificar.
2. UI: nueva tab "Respuestas" (lista + ficha con clasificación).
3. Endpoint de clasificación que NO permita modificar lead_id/campaign_id/variant/plantilla_id/smtp_id/message_id; solo `clasificacion`.
4. UNSUBSCRIBE reutiliza la misma supresión (Lista Negra), idempotente.
5. Harness integral de atribución (15 tests de la fase).

## Decisiones de diseño (mínimas, reutilizando lo existente)
- `envio_id` es la única FK operativa desde UI; el resto se deriva del envío (no campos arbitrarios en UI).
- La clasificación se actualiza vía UPDATE sobre `respuestas` (sin tocar `envios`).
- Rec lasificación de UNSUBSCRIBE también debe disparar supresión (aunque la respuesta ya esté insertada). Añadiré `clasificarRespuesta()` en `inc/respuestas.php`.

## Riesgos
- La UI nueva no debe exponer ni permitir editar campos de trazabilidad del envío.
- El cambio de clasificación debe conservar `fecha_clasificacion` y actualizar `estado_procesamiento`.

---

> Conclusión: el backend de respuestas está preparado (FASE 4B); falta exclusivamente la capa de panel/endpoints y los tests integrales de atribución. No hay deuda que bloquee la implementación mínima.

> NO se avanza a FASE 5. No IMAP. No métricas. No envíos reales.