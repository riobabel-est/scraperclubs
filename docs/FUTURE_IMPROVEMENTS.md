# FUTURE_IMPROVEMENTS — FutProtec CRM Outbound

> Registro de mejoras detectadas durante la ejecución que **NO son necesarias para el CORE del piloto A/B/C**.
> Se posponen para no dispersar la ejecución (REGLA 1).

---

## FI-001 — Eliminar tabla huérfana `plantillas_new`
- **Descripción:** `plantillas_new` quedó vacía tras la migración en `init_db.php` (la tabla vieja se renombró a `plantillas`), pero la tabla `plantillas_new` sigue existiendo en el esquema.
- **Beneficio:** Evita confusión entre dos tablas de plantillas.
- **Prioridad:** Baja.
- **Motivo para posponerla:** No afecta al flujo; riesgoso de tocar en mitad del piloto.

---

## FI-002 — Eliminar credenciales hardcodeadas duplicadas
- **Descripción:** `init_db.php` y `api/enviar_smtp_random.php` contienen arrays hardcodeados ($CUENTAS_SMTP_FALLBACK y $cuentasDefault) duplicando las credenciales que ya están en `cuentas_smtp`.
- **Beneficio:** Una única fuente de verdad; menos superficie de exposición.
- **Prioridad:** Media.
- **Motivo para posponerla:** Es un cambio de seguridad amplio; se hará con cuidado y sin tocar los valores existentes, fuera del piloto.

---

## FI-003 — Corregir URL de tracking en `cli/cron.php`
- **Descripción:** `cron.php` genera `.../outbound/track.php?id=...` (sin `/api/`), mientras `track.php` vive en `api/track.php`.
- **Beneficio:** Que las aperturas vía cron registren correctamente.
- **Prioridad:** Media.
- **Motivo para posponerla:** El flujo principal actual es lanzadera / standalone; cron no es el camino crítico del experimento.

---

## FI-004 — Unificar los 3 motores de envío
- **Descripción:** `enviar_lote.php`, `enviar_smtp_random.php` y `cron.php` implementan cada uno su propia función SMTP (`enviarSMTPAutenticado`/`enviarSMTP`) con pequeñas diferencias.
- **Beneficio:** Menos duplicación, un único punto de comportamiento de envío.
- **Prioridad:** Media.
- **Motivo para posponerla:** Refactor amplio; el CORE del piloto exige cambios mínimos.

---

## FI-005 — Atomaticidad de `enviados_hoy`
- **Descripción:** El contador `cuentas_smtp.enviados_hoy` se incrementa por SQL separado y puede divergir del recuento real por `comunicaciones_log`/`envios`.
- **Beneficio:** Límite diario preciso.
- **Prioridad:** Media.
- **Motivo para posponerla:** Ya existe recuento real en varios puntos; unificar es una mejora no bloqueante.

---

## FI-006 — Histórico de estados Kanban por campaña
- **Descripción:** `estado_lead` es un único estado global por lead; no conserva histórico por campaña (la relación N:M `lead_pipelines` no se usa).
- **Beneficio:** Trazabilidad multicampaña completa.
- **Prioridad:** Alta (post-piloto).
- **Motivo para posponerla:** Requiere rediseño de modelo; fuera del alcance mínimo del experimento.

---

## FI-007 — Plantillas versionadas inmutables
- **Descripción:** `save_template` sobrescribe una plantilla en lugar de crear una versión nueva.
- **Beneficio:** Inmutabilidad de plantillas ya enviadas.
- **Prioridad:** Alta (post-piloto).
- **Motivo para posponerla:** Incluida como requisito del plan; se abordará como parte de FASE correspondiente, no aislada aquí.

---

## FI-008 — Índices y saneamiento general de esquema
- **Descripción:** Algunas relaciones usan `email` en vez de FK; varios índices redundantes; `snapshots` sin uso demostrado.
- **Beneficio:** Rendimiento y claridad.
- **Prioridad:** Baja.
- **Motivo para posponerla:** Cosmético/estructural, no bloquea el piloto.

---

## FI-009 — EVOLUTION API — PENDIENTE DE EVALUACIÓN

- **Estado actual:** PENDIENTE DE EVALUACIÓN FUTURA (no integrado con el CRM).
- **Descripción:** Evaluar una futura integración con Evolution API para determinar si puede aportar valor a la automatización de WhatsApp del CRM FutProtec. **No se asume que se vaya a integrar.**

### Qué existe
- El CRM envía email mediante **SMTP** (motor `enviarSMTPAutenticado`/`enviarSMTP`).
- El WhatsApp del CRM funciona de forma **manual** mediante enlaces `wa.me` (apertura del chat desde el navegador del operador, con plantilla precargada).
- Evolution API está instalada y funcionando de forma **independiente** en `localhost:8080`.
- Evolution Manager es la interfaz de administración de esa instalación externa.

### Qué no existe
- No hay integración de código entre el CRM y Evolution API.
- No hay referencias a Evolution API en el código del CRM.
- No existen endpoints de Evolution integrados al CRM.
- No existen webhooks de Evolution conectados al CRM.
- No existen tablas de conversaciones/instancias de Evolution en la BD del CRM.
- No existe lógica de persistencia de conversaciones de Evolution dentro del CRM.

### Posibles usos futuros (a evaluar, no decididos)
- Automatización de WhatsApp y envío programado.
- Recepción de respuestas.
- Trazabilidad de conversaciones.
- Integración con el pipeline/CRM.
- Automatizaciones posteriores.

### Dependencias / riesgos (a evaluar)
- utilidad real para FutProtec;
- ventajas frente al sistema actual `wa.me`;
- arquitectura de integración;
- gestión de instancia WhatsApp;
- envío y recepción de mensajes;
- webhooks;
- trazabilidad dentro del CRM y asociación de conversaciones con leads;
- estados/eventos;
- seguridad de API Keys;
- impacto sobre la arquitectura actual y mantenimiento.

### Decisión pendiente
- Evaluar si Evolution API aporta valor al desarrollo de WhatsApp automatizado del CRM (decisión de producto, antes de cualquier desarrollo).

- **Prioridad:** Futura (evaluación previa, no implementación).
- **Motivo para posponerla:** Fuera del alcance del CORE del piloto A/B/C; requiere decisión de producto antes de cualquier desarrollo.
