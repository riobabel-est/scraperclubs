# FUTPROTEC CRM — MAESTRO DE EJECUCIÓN POR FASES
## Guía operativa para no dispersarse ni entrar en bucle

**Fecha:** 19/08/2026
**Estado:** Documento de control de ejecución
**Propósito:** Este documento es la ÚNICA referencia de orden de trabajo. Cada fase tiene objetivo, alcance, entregables, criterios de salida (Definition of Done) y qué NO hacer. **No se salta ninguna fase. No se mezclan fases. Una fase = un objetivo = un checkpoint.**

---

# ⚠️ REGLAS GLOBALES DE EJECUCIÓN (LEER PRIMERO)

1. **Una fase a la vez.** No avanzar a la siguiente hasta que la actual tenga su checkpoint aprobado.
2. **READ-ONLY primero.** Las fases de auditoría NO modifican producción (sin UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX).
3. **Checkpoint obligatorio** al final de cada fase en `docs/checkpoint_<fase>.md`.
4. **No tocar `output/` ni `checkpoints/`** sin permiso explícito (datos irrecuperables).
5. **No hacer `git push`** sin pedido explícito del usuario.
6. **No deshacer features ya operativas** (validación MX, WhatsApp, modales, etc.).
7. **Backend como autoridad.** El cliente nunca decide TEST/REAL, campaña, variante, elegibilidad, límites SMTP, atribución ni seguridad.
8. **Separar EVENTO de ESTADO.** Abrir un email NO mueve un lead a Interesado automáticamente.
9. **No mezclar auditoría y envío físico.**
10. **Si una fase se atasca >2 intentos, PARAR y documentar el bloqueo.** No entrar en bucle.

---

# 📋 MAPA GENERAL DE FASES (ORDEN DE EJECUCIÓN)

```text
FASE D  →  Auditoría arquitectónica READ-ONLY (inventario completo)
FASE E  →  Auditoría IMAP READ-ONLY (viabilidad técnica)
FASE F  →  Registro de respuestas (IMAP → lead → envío → campaña)
FASE G  →  Notificaciones de respuestas
FASE H  →  Motor de secuencias / follow-ups (P2)
FASE I  →  Tracking web / enlaces /c/ (P3)
FASE J  →  Timeline del lead (P4)
FASE K  →  Scoring determinista (P5)
FASE L  →  Verificación / higiene de emails (P6)
FASE M  →  Creador de campañas simplificado
FASE N  →  Evaluación ESP externo (warmup / SMTP alto volumen)
FASE O  →  IA (solo con datos limpios y suficientes)
```

**Orden lógico de negocio:** Enviar → Escuchar respuesta (IMAP) → Re-impactar si no responde (Secuencia) → Trazar actividad (Tracking/Timeline) → Priorizar (Scoring).

---

# 🟦 FASE D — AUDITORÍA ARQUITECTÓNICA READ-ONLY

## Objetivo
Inventariar TODO lo que existe (tablas, endpoints, eventos, envíos, respuestas, SMTP, IMAP, web, tracking, frontend) para decidir qué reutilizar antes de diseñar nada nuevo.

## Alcance
- Inventariar tablas actuales, columnas y relaciones.
- Inventariar endpoints (API) existentes.
- Inventariar eventos existentes (aperturas, respuestas, envíos).
- Inventariar `envios`, `respuestas`, `comunicaciones_log`.
- Inventariar cuentas SMTP y configuración IMAP.
- Inventariar estructura web, tracking existente, frontend.
- Evaluar posibilidades de cookies/tokens.
- Diseñar conceptualmente `lead_events`, tokens, tracking, IMAP, scoring, notificaciones.
- Evaluar riesgos de privacidad y dependencias.

## Entregables
- `docs/checkpoint_faseD_diseno_actividad.md` con el inventario completo.

## Definition of Done (criterios de salida)
- [ ] Listado completo de tablas con columnas y relaciones.
- [ ] Listado completo de endpoints.
- [ ] Mapa de eventos existentes.
- [ ] Identificación de qué tablas pueden reutilizarse para `lead_events`.
- [ ] Confirmación de que NO se modificó producción.

## Qué NO hacer
- NO UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX.
- NO envíos ni campañas.
- NO cambios de producción.

---

# 🟦 FASE E — AUDITORÍA IMAP READ-ONLY

## Objetivo
Confirmar la viabilidad técnica real de la prioridad #1 (IMAP/respuestas) ANTES de implementar nada.

## Alcance
- Conectar por IMAP/SSL (puerto 993) a 1 cuenta SMTP de SiteGround.
- Estudiar carpetas, UID, Message-ID, In-Reply-To, References.
- Confirmar que SiteGround permite conexiones IMAP salientes sin bloqueo de IP.
- Verificar que los `message_id` de los envíos permiten el match con `In-Reply-To`/`References`.
- Estudiar estructura de mensajes (texto plano, HTML, adjuntos).

## Entregables
- `docs/checkpoint_faseE_auditoria_imap.md` con hallazgos y viabilidad confirmada/descartada.

## Definition of Done
- [ ] Conexión IMAP exitosa a al menos 1 cuenta.
- [ ] Confirmado que SiteGround permite IMAP saliente.
- [ ] Confirmado que los `message_id` permiten el match.
- [ ] Documentado el esquema de carpetas y UID.
- [ ] Confirmado que NO se modificó el CRM.

## Qué NO hacer
- NO modificar el CRM.
- NO marcar emails como leídos en producción (solo lectura).
- NO borrar emails.

---

# 🟦 FASE F — REGISTRO DE RESPUESTAS (IMAP → LEAD → ENVÍO → CAMPAÑA)

## Objetivo
Automatizar la detección y registro de respuestas con idempotencia.

## Alcance
- Crear script `cli/imap_sync.php` que conecta por IMAP a cada cuenta SMTP.
- Atribución por prioridad: `In-Reply-To` → `References` → email remitente → Message-ID relacionado → asunto (solo apoyo).
- Idempotencia por `message_id_respuesta` + `UID IMAP` + cuenta+UID + hash auxiliar.
- Clasificación inicial sin IA: humana / rebote / baja / fuera de oficina / automática / desconocida.
- Registrar en BD: respuesta_id, lead_id, campaign_id, envio_id, id_cuenta_smtp, message_id_original, message_id_respuesta, from_email, to_email, subject, fecha_recepcion, fecha_procesamiento, contenido_texto, contenido_html, clasificacion, estado.
- Integrar con la tab "Respuestas" existente (evolucionarla, NO crear tab nueva).

## Entregables
- `cli/imap_sync.php`
- Evolución de `tabs/respuestas.php` + `app.js` (badge de nuevas, botón sincronizar, chips de filtro).
- `docs/checkpoint_faseF_registro_respuestas.md`

## Definition of Done
- [ ] Un email recibido se registra UNA sola vez (idempotencia probada).
- [ ] La atribución lead/envío/campaña funciona con datos reales.
- [ ] La clasificación inicial distingue humana de automática/rebote.
- [ ] La tab Respuestas muestra las nuevas respuestas.
- [ ] No se rompió la clasificación manual existente.

## Qué NO hacer
- NO mover Kanban automáticamente (solo si es humana y configurado).
- NO enviar respuestas automáticas.
- NO borrar emails del buzón.

---

# 🟦 FASE G — NOTIFICACIONES DE RESPUESTAS

## Objetivo
Avisar al comercial cuando llega una respuesta.

## Alcance
- Notificación tipo: 🔔 NUEVA RESPUESTA (club, campaña, variante, recibido, [VER RESPUESTA]).
- Configurable para no convertirse en ruido.
- Integrar con la tab Respuestas (badge de no leídas).

## Entregables
- Mecanismo de notificación (badge + aviso).
- `docs/checkpoint_faseG_notificaciones.md`

## Definition of Done
- [ ] El comercial ve el aviso de nueva respuesta sin entrar a la tab.
- [ ] Las notificaciones son configurables.
- [ ] No genera ruido (solo respuestas humanas por defecto).

---

# 🟦 FASE H — MOTOR DE SECUENCIAS / FOLLOW-UPS (P2)

## Objetivo
Re-impactar a los leads que no responden, con cadencia controlada y stop automático al responder.

## Alcance
- Tabla `secuencias` (pasos, delays, condiciones).
- Tabla `secuencia_lead` (progreso por lead, Estado: En espera, Enviado, Detenido por respuesta).
- Cron que envía el siguiente paso si no hay respuesta (cadencia a 3 y 7 días).
- **Detención automática** cuando IMAP detecta correo entrante del lead.
- Evolucionar tab "Follow-ups" (ya existe `followups.php` como placeholder).
- Email 1 = Texto Plano Puro sin reescritura de enlaces (protección entregabilidad).

## Entregables
- Tablas `secuencias` y `secuencia_lead`.
- Cron de secuencias.
- Evolución de `tabs/followups.php`.
- `docs/checkpoint_faseH_secuencias.md`

## Definition of Done
- [ ] La secuencia envía el paso 2 a los 3 días y el paso 3 a los 7 si no hay respuesta.
- [ ] La secuencia se DETIENE automáticamente cuando el lead responde.
- [ ] Respeta límites SMTP diarios y aislamiento TEST/REAL.
- [ ] Email 1 sin reescritura de enlaces.

## Qué NO hacer
- NO enviar sin respetar límites SMTP.
- NO enviar a leads TEST desde campaña REAL ni viceversa.
- NO automatización agresiva (cadencia mínima 3 días).

---

# 🟦 FASE I — TRACKING WEB / ENLACES /c/ (P3)

## Objetivo
Saber qué leads visitan FutProtec tras recibir un email de Fase 2.

## Alcance
- Token no predecible en enlaces de Fase 2 → `https://futprotec.com/c/{token}`.
- Endpoint que resuelve token → lead → campaña → envío.
- Cookie de seguimiento.
- Eventos: session_start, page_view, session_end.
- Niveles: anónimo → identificado → identificado+respuesta.
- **SOLO en Fase 2 (tras respuesta o follow-up). NO en Email 1 frío.**

## Entregables
- Endpoint `/c/index.php` en futprotec.com.
- Registro de visitas.
- `docs/checkpoint_faseI_tracking_web.md`

## Definition of Done
- [ ] Un lead identificado por token se registra correctamente.
- [ ] El Email 1 frío NO lleva enlaces reescritos.
- [ ] Revisado RGPD/cookies/consentimiento/minimización.

## Qué NO hacer
- NO reescribir URLs en campañas frías.
- NO asumir que todo tracking técnicamente posible es legalmente apropiado.

---

# 🟦 FASE J — TIMELINE DEL LEAD (P4)

## Objetivo
Unificar toda la actividad del lead en una línea temporal.

## Alcance
- Tabla `lead_events` (event store) — REVISAR primero qué tablas reutilizar (FASE D).
- Eventos: email_sent, email_opened, email_clicked, web_visit, web_page_view, email_received, reply_classified, unsubscribe, bounce, manual_note, kanban_changed, mockup_requested, budget_sent.
- Panel de timeline en la ficha del lead (modal).

## Entregables
- Tabla `lead_events` (si no se reutiliza otra).
- Panel de timeline en la ficha del lead.
- `docs/checkpoint_faseJ_timeline.md`

## Definition of Done
- [ ] La timeline muestra el contexto completo del lead sin revisar múltiples pantallas.
- [ ] No duplica datos que ya existen en otras tablas.

## Qué NO hacer
- NO crear estructuras sin comprobar antes qué tablas existen.

---

# 🟦 FASE K — SCORING DETERMINISTA (P5)

## Objetivo
Priorizar leads calientes con puntos por evento, sin IA.

## Alcance
- Tabla `lead_score` o columna calculada.
- Puntos: apertura +2, segunda apertura +3, click +5, visita web +4, visita producto +5, visita precio +6, visita contacto +8, respuesta +15, solicita información +15, solicita presupuesto +25.
- Badge de score en kanban y en ficha del lead.

## Entregables
- Mecanismo de scoring.
- Badge de score en UI.
- `docs/checkpoint_faseK_scoring.md`

## Definition of Done
- [ ] El score se calcula de forma determinista y auditable.
- [ ] El score NO mueve Kanban automáticamente.
- [ ] El score sirve para priorizar, no para declarar interés.

## Qué NO hacer
- NO usar IA en el scoring inicial.
- NO mover leads automáticamente por score.

---

# 🟦 FASE L — VERIFICACIÓN / HIGIENE DE EMAILS (P6)

## Objetivo
Reducir rebotes y proteger entregabilidad.

## Alcance
- Ampliar validación existente (MX con `checkdnsrr`) con sintaxis + dominio + MX + (opcional) SMTP handshake.
- Badge de "email verificado" en la ficha del lead.

## Entregables
- Módulo de verificación ampliado.
- Badge de verificación en UI.
- `docs/checkpoint_faseL_verificacion_emails.md`

## Definition of Done
- [ ] La verificación distingue válido / inválido / dudoso.
- [ ] No rompe la validación MX existente.

---

# 🟦 FASE M — CREADOR DE CAMPAÑAS SIMPLIFICADO

## Objetivo
Simplificar la UX del creador de campañas sin tocar la complejidad del backend.

## Alcance
- Paso 1: Campaña (nombre, objetivo, audiencia, plantilla).
- Paso 2: Audiencia (Total CRM, REAL, TEST, Suppression, Duplicados, Ya contactados, Elegibles).
- Paso 3: A/B/C (A XXX, B XXX, C XXX).
- Paso 4: Simulación (potenciales, bloqueados, motivos, riesgos).
- Paso 5: Backup verificable.
- Paso 6: Microenvío (5/10/25/50).
- Paso 7: Postcheck (cantidad, leads, variantes, message_id, SMTP, estados, errores, tracking).
- Paso 8: Escalado (ACTIVAR OPERACIÓN CONTROLADA).

## Entregables
- UI simplificada del creador.
- `docs/checkpoint_faseM_creador_campanas.md`

## Definition of Done
- [ ] El flujo guía al usuario paso a paso.
- [ ] Los controles anti-envío accidental se mantienen intactos.
- [ ] El backend sigue siendo la autoridad.

## Qué NO hacer
- NO simplificar los controles de seguridad del backend.

---

# 🟦 FASE N — EVALUACIÓN ESP EXTERNO

## Objetivo
Comparar SMTP propio con proveedores externos (Brevo/Instantly) para entregabilidad.

## Alcance
- Evaluar warmup de dominios (externalizar si >1k/día).
- Evaluar SMTP transaccional de alto volumen (Brevo API/webhooks).
- El CRM sigue siendo el núcleo comercial.

## Entregables
- Informe de evaluación ESP.
- `docs/checkpoint_faseN_evaluacion_esp.md`

## Definition of Done
- [ ] Decisión documentada: mantener SMTP propio, externalizar, o híbrido.
- [ ] No se migró el CRM.

## Qué NO hacer
- NO migrar el CRM a una plataforma externa.
- NO externalizar sin necesidad real de escala.

---

# 🟦 FASE O — IA (SOLO CON DATOS LIMPIOS Y SUFICIENTES)

## Objetivo
Añadir inteligencia solo cuando exista suficiente actividad real.

## Alcance
- Resumir respuestas.
- Clasificar intención.
- Detectar objeciones.
- Extraer volumen.
- Sugerir próxima acción.
- Resumir timeline.
- Proponer respuesta.

## Entregables
- Módulo de IA (opcional).
- `docs/checkpoint_faseO_ia.md`

## Definition of Done
- [ ] Hay suficientes datos limpios (Fases D-L completadas).
- [ ] La IA NO controla el envío automáticamente en la primera implementación.

## Qué NO hacer
- NO introducir IA antes de tener datos limpios.
- NO dejar que la IA controle el envío automático inicialmente.

---

# 🚦 PROTOCOLO ANTI-BUCLE

Si en cualquier fase:
1. **El mismo problema se repite >2 veces** → PARAR.
2. **Documentar el bloqueo** en `docs/checkpoint_<fase>_bloqueo.md` con: qué se intentó, qué falló, hipótesis, datos.
3. **No seguir probando a ciegas.** Presentar el bloqueo al usuario con opciones.
4. **Solo continuar** cuando el usuario decida el camino.

**Regla de oro:** Es mejor un checkpoint honesto con un bloqueo documentado que 10 intentos fallidos que dispersan el trabajo.

---

# ✅ CHECKLIST GLOBAL DE CIERRE

Antes de dar por cerrada cualquier fase:
- [ ] Checkpoint creado en `docs/checkpoint_<fase>.md`.
- [ ] Definition of Done de la fase cumplida.
- [ ] No se rompió ninguna feature previa.
- [ ] No se tocó `output/` ni `checkpoints/` sin permiso.
- [ ] No se hizo `git push`.
- [ ] Backend sigue siendo la autoridad.
- [ ] EVENTO ≠ ESTADO (no se movieron leads automáticamente sin configurarlo).
