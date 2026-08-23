# FutProtec CRM — Especificación Maestra (Fuente de Verdad del Producto)

> **Documento vivo.** Esta es la fuente de verdad de cómo DEBE funcionar el CRM FutProtec.
> No describe lo que existe hoy (eso está en los checkpoints), sino el modelo objetivo.
> Cualquier desarrollo, refactor o decisión de producto debe contrastarse contra este documento.
>
> **Última revisión:** 22/08/2026

---

## A. OBJETIVO DEL PRODUCTO

### A.1 Problema que resuelve
FutProtec vende **equipación deportiva (calzado/botas) a clubes de fútbol españoles** (B2B).
El problema operativo es: **cómo captar, contactar, cualificar y convertir a cientos de clubes de forma escalable**, sin perder el control comercial ni incumplir el RGPD.

### A.2 Qué hace el CRM
El CRM es la **máquina de prospección y venta** que:
1. **Capta** clubes (scraping de federaciones + alta manual).
2. **Contacta** por email (campañas, secuencias, plantillas, tracking).
3. **Escucha** respuestas (Unibox IMAP/POP3) y detecta interés.
4. **Convierte** por WhatsApp y seguimiento comercial (pipeline).
5. **Mide** el resultado (aperturas, respuestas, conversión, ingresos).
6. **Cumple** el RGPD (bajas, opt-out, lista negra, trazabilidad).

### A.3 Principios rectores
- **Una sola fuente de verdad** para estados, plantillas, elegibilidad y configuración.
- **El operador nunca salta entre 4 pantallas** para hacer una acción de venta.
- **Los datos estructurados** (logs, métricas, eventos) **nunca se mezclan** con notas libres.
- **Cada lead es trazable** de principio a fin (origen → venta → postventa).
- **El dinero se mide**: el CRM sabe cuánto se vende, no solo cuántos leads hay.

---

## B. USUARIOS

| Rol | Qué puede hacer | Qué NO puede hacer |
|-----|-----------------|--------------------|
| **Comercial** | Ver/editar sus leads, moverlos en el pipeline, responder emails/WhatsApp, registrar tareas, ver sus métricas. | No gestiona cuentas SMTP, ni plantillas globales, ni configuración, ni ve leads de otros comerciales. |
| **Supervisor / Jefe de ventas** | Ver todos los leads, asignar leads a comerciales, crear campañas, ver métricas globales, gestionar plantillas. | No toca configuración técnica (SMTP, motor). |
| **Administrador** | Todo lo anterior + cuentas SMTP, configuración del motor, entorno test/producción, lista negra global, usuarios. | — |
| **Sistema (cron/CLI)** | Ejecuta automatizaciones (envíos, sync IMAP, follow-ups) sin sesión. | No accede a la UI. |

> **Nota de diseño:** Hoy NO existe gestión de usuarios. Este rol es objetivo. La autenticación actual es una única contraseña global (`AUTH_KEY`).

---

## C. ENTIDADES

### C.1 Empresa (Club)
Un club de fútbol. Es la entidad raíz del negocio.
- **Atributos:** nombre, federación, categorías deportivas, tamaño (num_jugadores), volumen estimado, dirección, web, redes.
- **Relaciones:** tiene 1..N **Contactos**, tiene 1..N **Oportunidades**, tiene 1..N **Ventas**.
- **Regla:** un club es ÚNICO. Todos sus emails/teléfonos se agrupan bajo la misma Empresa.

### C.2 Contacto
Una persona dentro de una Empresa (presidente, secretario, delegado, entrenador).
- **Atributos:** nombre, cargo, email, teléfono móvil, teléfono fijo, tiene_whatsapp, es_principal.
- **Relaciones:** pertenece a 1 **Empresa**.
- **Regla:** un contacto puede tener varios emails, pero se identifica por persona.

### C.3 Lead
Un contacto (o empresa) que aún no es cliente y está en proceso de captación.
- **Atributos:** origen (scraping/campaña/manual), segmento, score de validez, estado comercial.
- **Relaciones:** es un **Contacto** (o Empresa) en fase de captación.
- **Regla:** el Lead es la "vista comercial" de un Contacto dentro de una Campaña.

### C.4 Campaña
Un envío masivo planificado a un segmento.
- **Atributos:** nombre, entorno (test/real), estado (DRAFT/PILOT/ACTIVE/PAUSED/FINISHED), segmento objetivo, plantillas, programación, cuentas SMTP.
- **Relaciones:** tiene 1..N **Secuencias**, genera 1..N **Emails**.
- **Regla:** una campaña define QUÉ se envía, A QUIÉN y CUÁNDO.

### C.5 Secuencia
Serie ordenada de pasos de email con esperas.
- **Atributos:** pasos (email + espera en días), condición de salida (respondió → parar).
- **Relaciones:** pertenece a 1 **Campaña**.
- **Regla:** el corazón de la prospección. "Email 1 → espera 3 días → Email 2 → espera 5 días → Email 3".

### C.6 Email
Un mensaje enviado o recibido.
- **Atributos:** asunto, cuerpo, variante A/B/C, estado (pendiente/enviado/abierto/rebotado/error), tracking_id, cuenta SMTP, plantilla, fecha.
- **Relaciones:** pertenece a 1 **Contacto/Lead**, 1 **Campaña**, 1 **Secuencia** (paso).
- **Regla:** inmutable una vez enviado (snapshot del contenido).

### C.7 Conversación WhatsApp
Hilo de mensajes de WhatsApp con un contacto.
- **Atributos:** estado (activa/abandonada/cerrada), último mensaje, última respuesta.
- **Relaciones:** pertenece a 1 **Contacto**, 1 **Oportunidad**.
- **Regla:** se inicia cuando se detecta oportunidad; se registra cada mensaje.

### C.8 Tarea
Acción pendiente para un comercial.
- **Atributos:** tipo (llamar, enviar propuesta, hacer seguimiento), fecha límite, estado (pendiente/hecha), prioridad.
- **Relaciones:** asignada a 1 **Usuario**, vinculada a 1 **Contacto/Oportunidad**.
- **Regla:** alimenta recordatorios y el calendario del comercial.

### C.9 Oportunidad
Un Lead cualificado con potencial de compra real.
- **Atributos:** importe estimado, probabilidad, etapa (propuesta/negociación), fecha prevista de cierre.
- **Relaciones:** pertenece a 1 **Empresa**, 1 **Comercial**, 1 **Campaña** (origen).
- **Regla:** es el objeto de venta. Se crea cuando un lead muestra interés real.

### C.10 Venta
Una oportunidad cerrada con éxito.
- **Atributos:** importe, producto(s), cantidad, margen, fecha de cierre, comercial.
- **Relaciones:** pertenece a 1 **Empresa**, 1 **Oportunidad**, 1 **Comercial**.
- **Regla:** es la fuente de los ingresos. Al cerrarse, la Empresa pasa a "Cliente".

### C.11 Producto
Artículo vendible (modelo de bota, talla, etc.).
- **Atributos:** nombre, referencia, precio B2B, precio PVP, margen, stock.
- **Relaciones:** aparece en 1..N **Ventas** y **Presupuestos**.
- **Regla:** el cálculo de precio/margen (`calcularPrecioYMargen`) se basa en tramos de volumen.

### C.12 Plantilla
Modelo de mensaje reutilizable (email o WhatsApp).
- **Atributos:** nombre, categoría, tipo (html/texto_plano/whatsapp), asunto, cuerpo, variantes A/B/C, activo, congelada.
- **Relaciones:** usada por 1..N **Campañas**.
- **Regla:** una plantilla usada por campaña PILOT/ACTIVE queda **congelada** (no editable).

### C.13 Automatización
Regla "si evento → entonces acción".
- **Atributos:** disparador (evento), condición, acción, activa.
- **Relaciones:** opera sobre **Emails**, **Tareas**, **Oportunidades**, **Conversaciones**.
- **Regla:** el motor que hace el CRM "inteligente" (follow-ups, alertas, cambios de estado).

### C.14 Entidades de soporte
- **Cuenta SMTP:** buzón emisor (email, user, pass, host, puerto, límite diario, activa).
- **Lista Negra / Baja:** registro de opt-out y bloqueos (RGPD).
- **Config:** parámetros clave/valor (motor, entorno, delays).
- **Log de comunicaciones:** trazabilidad de cada evento (envío, apertura, respuesta, cambio de estado).

---

## D. ESTADOS

### D.1 Estado comercial del Lead (Pipeline) — UNA sola fuente de verdad
| Código | Estado | Significado |
|--------|--------|-------------|
| 01 | Sin Contactar | Captado pero aún no se le ha enviado nada. |
| 02 | Contactado | Se le envió el primer email. |
| 03 | Respondió | Ha respondido (email o WhatsApp). |
| 04 | Interesado | Muestra interés real. |
| 05 | Cualificado | Cumple criterios (presupuesto, necesidad, decisor). |
| 06 | Propuesta | Se le envió propuesta/presupuesto. |
| 07 | Negociación | En conversación de cierre. |
| 08 | Ganado | Compró. Pasa a Cliente. |
| 09 | Perdido | No comprará (con motivo). |

### D.2 Estados de supresión / cumplimiento (RGPD)
| Estado | Significado |
|--------|-------------|
| Lista Negra | Bloqueado manualmente o por baja. |
| Opt-Out | Baja solicitada por el destinatario. |
| Unsubscribed | Baja vía enlace de baja. |
| Baja / Opt-Out | Sinónimo de baja. |
| Email Inválido | Email rebotado o sin MX. |

> **Regla crítica:** los estados de supresión **NO son columnas del pipeline** pero **bloquean el envío** en la elegibilidad. Un lead en supresión no puede reactivarse salvo confirmación explícita (protección opt-out real).

### D.3 Estados de la Oportunidad
| Estado | Significado |
|--------|-------------|
| Abierta | En curso. |
| Ganada | Cerrada con venta. |
| Perdida | Cerrada sin venta (con motivo). |

### D.4 Estados de la Conversación WhatsApp
| Estado | Significado |
|--------|-------------|
| Activa | En curso. |
| Abandonada | Sin respuesta del contacto en X días. |
| Cerrada | Finalizada (venta o pérdida). |

### D.5 Estados de la Tarea
| Estado | Significado |
|--------|-------------|
| Pendiente | Por hacer. |
| Hecha | Completada. |
| Vencida | Pasó la fecha límite sin hacerse. |

### D.6 Estados de la Campaña
| Estado | Significado |
|--------|-------------|
| DRAFT | En edición, no operable. |
| PILOT | Prueba controlada (entorno test). |
| ACTIVE | En producción. |
| PAUSED | Detenida temporalmente. |
| FINISHED | Completada. |

### D.7 Estados del Email
| Estado | Significado |
|--------|-------------|
| pendiente | Reservado, aún no enviado. |
| enviado | Aceptado por SMTP. |
| abierto | Se registró apertura. |
| rebotado | Devolución (bounce). |
| error | Falló el envío. |

---

## E. FLUJO COMERCIAL

```
CAPTACIÓN → PROSPECCIÓN → INTERÉS → CUALIFICACIÓN → PROPUESTA → NEGOCIACIÓN → VENTA → POSTVENTA
```

### E.1 Captación
1. Entra un lead (scraping o manual).
2. Se crea/actualiza la **Empresa** y su **Contacto**.
3. Se identifica **origen** y **segmento**.
4. Se valida (email MX, teléfono, duplicados).
5. Estado inicial: **01 Sin Contactar**.

### E.2 Prospección (email)
1. Se asigna a una **Campaña** con **Secuencia**.
2. Se envía **Email 1** → estado **02 Contactado**.
3. Espera configurada.
4. Si no responde → **Email 2** (follow-up).
5. Si abre pero no responde → **Email 3** (re-impacto).
6. Si responde → pasa a **03 Respondió** y se clasifica.

### E.3 Interés y cualificación
1. La respuesta se clasifica (interesado / duda / no interesa).
2. Si hay interés → **04 Interesado**.
3. Se cualifica (presupuesto, necesidad, decisor) → **05 Cualificado**.
4. Se crea la **Oportunidad** y se asigna a un **Comercial**.

### E.4 Propuesta y negociación
1. Se genera **Propuesta/Presupuesto** → **06 Propuesta**.
2. Negociación (precio, volumen, condiciones) → **07 Negociación**.
3. Se registran tareas y seguimientos.

### E.5 Venta
1. Cierre → **08 Ganado**.
2. Se registra la **Venta** (importe, producto, margen).
3. La Empresa pasa a **Cliente**.

### E.6 Postventa
1. Seguimiento postventa (satisfacción, recompra).
2. Posibilidad de nuevas oportunidades (upsell).

### E.7 Pérdida
- En cualquier etapa → **09 Perdido** con **motivo** (precio, no interesado, sin presupuesto, etc.).

---

## F. EMAIL — PROSPECCIÓN Y AUTOMATIZACIÓN

### F.1 Cómo funciona
1. **Campaña** define segmento + secuencia + plantillas.
2. **Secuencia** define los pasos: `[Email 1] → espera 3d → [Email 2] → espera 5d → [Email 3]`.
3. Cada email usa una **plantilla** (con variantes A/B/C).
4. El envío usa **cuentas SMTP** con rotación y límite diario (anti-bloqueo).
5. Cada email lleva **tracking** (apertura) y **enlace de baja** (token seguro).

### F.2 Automatización de seguimiento
| Evento | Acción automática |
|--------|-------------------|
| Email enviado | Estado → 02 Contactado. |
| Email abierto | Registrar apertura; si es 2ª apertura → marcar "Caliente". |
| Email rebotado | Marcar rebote; si es definitivo → Email Inválido. |
| No respuesta en X días | Enviar siguiente email de la secuencia. |
| Respuesta recibida | Estado → 03 Respondió; clasificar intención. |
| Respuesta con interés | Crear Oportunidad; sugerir paso a WhatsApp. |
| Baja (opt-out) | Estado → supresión; detener secuencia; registrar en lista negra. |

### F.3 Reglas de envío (elegibilidad)
- No enviar a: supresión, duplicado, email inválido, lead TEST en campaña real (y viceversa).
- Un lead = un email por paso de secuencia (idempotencia).
- Respetar límite diario por cuenta SMTP.

---

## G. WHATSAPP — CONVERSACIÓN Y SEGUIMIENTO

### G.1 Cómo funciona
1. Se **detecta oportunidad** (respuesta con interés, 2+ aperturas, lead calificado).
2. Se **inicia conversación** con plantilla WhatsApp.
3. Se registra cada **mensaje** y **respuesta**.
4. Se hace **seguimiento** (tareas, recordatorios).
5. Se **negocia** y se cierra.

### G.2 Automatización de WhatsApp
| Evento | Acción automática |
|--------|-------------------|
| Oportunidad detectada | Sugerir iniciar WhatsApp. |
| Mensaje enviado | Registrar en conversación. |
| Sin respuesta en X días | Marcar conversación "Abandonada"; crear tarea de re-contacto. |
| Respuesta recibida | Actualizar conversación; clasificar intención. |
| Interés confirmado | Mover a 05 Cualificado / crear Oportunidad. |

### G.3 Reglas
- WhatsApp requiere número válido (móvil 9 dígitos empezando por 6/7).
- La conversación se vincula a la **Oportunidad** y al **Contacto**.

---

## H. AUTOMATIZACIONES — EVENTOS → ACCIONES

| # | Disparador (evento) | Condición | Acción |
|---|---------------------|-----------|--------|
| 1 | Lead captado | Email válido + no duplicado | Estado 01; asignar segmento. |
| 2 | Email enviado | — | Estado 02 Contactado. |
| 3 | Email abierto | 1ª apertura | Registrar; chip "Leído". |
| 4 | Email abierto | 2ª apertura | Marcar "Caliente"; priorizar. |
| 5 | Email rebotado | definitivo | Estado Email Inválido; detener secuencia. |
| 6 | Sin respuesta | pasó espera de paso N | Enviar paso N+1. |
| 7 | Respuesta recibida | — | Estado 03; clasificar. |
| 8 | Respuesta con interés | clasificación = interesado | Estado 04; crear Oportunidad; sugerir WhatsApp. |
| 9 | Oportunidad creada | — | Asignar comercial; crear tarea de contacto. |
| 10 | Propuesta enviada | — | Estado 06 Propuesta. |
| 11 | Sin respuesta WhatsApp | X días | Conversación abandonada; tarea de re-contacto. |
| 12 | Baja / opt-out | — | Supresión; detener secuencias; lista negra. |
| 13 | Venta cerrada | — | Estado 08 Ganado; Empresa → Cliente; registrar ingresos. |
| 14 | Tarea vencida | — | Notificación al comercial. |

---

## I. INTERFAZ — QUÉ DEBE APARECER EN CADA PANTALLA

### I.1 Dashboard (Home)
- KPIs: Total Leads, Envíos, Tasa Apertura, Tasa Rebote, Bajas, **Ingresos**, **Conversión**.
- Pipeline resumido (leads por etapa).
- Alertas: tareas vencidas, conversaciones abandonadas, respuestas sin clasificar.

### I.2 Pipeline (Kanban)
- Columnas por estado comercial (01-09).
- Tarjetas con: nombre, federación, WhatsApp, nº aperturas, última acción.
- Filtros: por comercial, federación, segmento, "Calientes", "Pendiente WhatsApp".
- Drag & drop entre columnas (cambio de estado con log).

### I.3 Ficha de Lead (3 secciones)
- **Datos del Club:** nombre, federación, categorías, tamaño, volumen.
- **Contactos:** personas, cargos, emails, teléfonos, WhatsApp.
- **Pipeline de Venta:** estado, oportunidad, propuesta, objeciones, próxima acción, motivo pérdida.
- **Cumplimiento:** estado de baja/lista negra (bloqueado si aplica).
- **Timeline:** historial completo de comunicaciones.

### I.4 Campañas
- Lista de campañas con estado y resultados.
- Crear/editar: segmento, secuencia, plantillas, programación.
- Vista de resultados por campaña (enviados, abiertos, respondidos, convertidos, ingresos).

### I.5 Bandeja de Entrada (Unibox)
- Conversaciones (email + WhatsApp unificadas).
- Clasificar respuesta (interesado/duda/no interesa).
- Responder y cambiar estado del lead (mismo vocabulario que el pipeline).

### I.6 Tareas / Calendario
- Tareas del comercial con fecha límite y prioridad.
- Recordatorios de próxima acción.

### I.7 Analytics
- Embudo de conversión (Contactado → Ganado).
- Métricas por campaña, por comercial, por federación.
- Ingresos y margen.

### I.8 Configuración
- Cuentas SMTP, motor, entorno, usuarios, lista negra global, plantillas.

---

## J. UX — DÓNDE DEBE ESTAR CADA FUNCIÓN Y POR QUÉ

### Principio: **el flujo de venta manda sobre la estructura técnica.**

| Función | Dónde debe estar | Por qué |
|---------|------------------|---------|
| Pipeline | **Home / primer tab** | Es la vista operativa diaria del comercial. |
| Ficha de lead | **Modal desde el pipeline** | El comercial trabaja desde el pipeline, no desde una tabla. |
| Campañas | **Tab de primer nivel** | El negocio piensa en campañas; es una entidad de negocio. |
| Plantillas | **Dentro de Campañas** | Son un recurso de campaña, no una entidad independiente. |
| Lanzadera | **Vista de ejecución de una campaña** | No es un tab suelto; es "lanzar esta campaña". |
| Bandeja de entrada | **Tab de primer nivel** | Es un canal de entrada que el comercial revisa a diario. |
| Tareas | **Tab de primer nivel** | Es la agenda del comercial. |
| Analytics | **Tab de primer nivel** | Medición continua. |
| Gestor de datos / duplicados | **Sub-módulo de Configuración** | Es mantenimiento, no operación diaria. |
| Lista negra | **Integrada en ficha + Configuración** | Es control de cumplimiento, no una pantalla aislada. |
| Cuentas SMTP | **Configuración técnica** | Solo administrador. |
| Usuarios | **Configuración** | Solo administrador. |

### Reglas de UX
- **Máximo 2 clics** para cualquier acción de venta (responder, cambiar estado, crear tarea).
- **Un solo vocabulario de estados** en toda la app (pipeline = Unibox = filtros).
- **El comercial nunca ve datos técnicos** (SMTP, motor, entorno) salvo que sea admin.
- **Feedback inmediato** en cada acción (toast, actualización del pipeline).
- **Los datos de cumplimiento** (baja) son visibles pero no editables por error.

---

## K. DATOS — QUÉ INFORMACIÓN DEBE ALMACENARSE

### K.1 Empresas
`id, nombre, federacion, categorias, num_jugadores, volumen_estimado, direccion, web, creado_el`

### K.2 Contactos
`id, empresa_id, nombre, cargo, email, telefono_movil, telefono_fijo, tiene_whatsapp, es_principal, creado_el`

### K.3 Leads (vista comercial)
`id, contacto_id, empresa_id, origen, segmento, score_validez, estado_lead, comercial_id, ultimo_contacto`

### K.4 Campañas
`id, nombre, entorno, estado, segmento, programacion, creado_el`

### K.5 Secuencias
`id, campana_id, nombre, pasos (json: [{email, espera_dias}])`

### K.6 Emails
`id, lead_id, contacto_id, campana_id, secuencia_id, paso, plantilla_id, variante, asunto, cuerpo, estado, tracking_id, cuenta_smtp, message_id, es_test, fecha_envio, resultado_envio`

### K.7 Aperturas / Rebotes / Clics
`id, tracking_id, email, fecha` (aperturas)
`id, email, tipo, fecha` (rebotes)
`id, tracking_id, url, fecha` (clics)

### K.8 Conversaciones WhatsApp
`id, contacto_id, oportunidad_id, estado, ultimo_mensaje, ultima_respuesta, creado_el`

### K.9 Mensajes WhatsApp
`id, conversacion_id, direccion (in/out), texto, fecha`

### K.10 Tareas
`id, usuario_id, contacto_id, oportunidad_id, tipo, descripcion, fecha_limite, estado, prioridad`

### K.11 Oportunidades
`id, empresa_id, contacto_id, comercial_id, campana_id, importe_estimado, probabilidad, etapa, fecha_cierre_prevista, estado`

### K.12 Ventas
`id, oportunidad_id, empresa_id, comercial_id, importe, margen, fecha_cierre`

### K.13 Productos
`id, nombre, referencia, precio_b2b, precio_pvp, margen, stock`

### K.14 Plantillas
`id, nombre, categoria, tipo, asunto, cuerpo, asunto_b/c, cuerpo_b/c, test_ab, activo, congelada`

### K.15 Cuentas SMTP
`id, email, usuario, password, host, puerto, seguridad, enviados_hoy, limite_diario, activa, ultimo_error, nombre_emisor, cargo_emisor`

### K.16 Lista Negra / Bajas
`id, email, motivo, fuente (email/manual), fecha, token`

### K.17 Log de comunicaciones
`id, lead_id, contacto_id, tipo_evento, canal, resultado, detalles, fecha`

### K.18 Usuarios
`id, nombre, email, rol, activo`

### K.19 Config
`clave, valor`

---

## L. PERMISOS

| Acción | Comercial | Supervisor | Admin |
|--------|-----------|------------|-------|
| Ver sus leads | ✅ | ✅ | ✅ |
| Ver todos los leads | ❌ | ✅ | ✅ |
| Mover lead en pipeline | ✅ (suyos) | ✅ | ✅ |
| Responder email/WhatsApp | ✅ | ✅ | ✅ |
| Crear/editar tareas | ✅ | ✅ | ✅ |
| Crear campañas | ❌ | ✅ | ✅ |
| Editar plantillas | ❌ | ✅ | ✅ |
| Ver métricas globales | ❌ | ✅ | ✅ |
| Ver sus métricas | ✅ | ✅ | ✅ |
| Gestionar cuentas SMTP | ❌ | ❌ | ✅ |
| Configurar motor/entorno | ❌ | ❌ | ✅ |
| Gestionar usuarios | ❌ | ❌ | ✅ |
| Gestionar lista negra global | ❌ | ❌ | ✅ |
| Reactivar lead con opt-out real | ❌ | ❌ | ✅ (con confirmación) |

---

## M. MÉTRICAS — QUÉ DEBE MEDIRSE

### M.1 Métricas de captación
- Leads captados por origen / por federación / por segmento.
- Tasa de validez (emails válidos / total).

### M.2 Métricas de email
- Enviados, entregados, abiertos, clics, rebotes.
- **Tasa de apertura** = abiertos / entregados.
- **Tasa de clic** = clics / abiertos.
- **Tasa de rebote** = rebotes / enviados.
- **Tasa de respuesta** = respuestas / entregados.

### M.3 Métricas de conversión (embudo)
- Leads por etapa del pipeline.
- **Tasa de conversión** por etapa (Contactado→Respondió→Interesado→Ganado).
- **Tiempo medio** de cada etapa.

### M.4 Métricas de WhatsApp
- Conversaciones iniciadas, activas, abandonadas.
- Tasa de respuesta por WhatsApp.

### M.5 Métricas de venta (ingresos)
- **Ingresos totales** y por comercial.
- **Margen** total y por venta.
- **Ticket medio**.
- **ROI por campaña** = ingresos / coste de la campaña.

### M.6 Métricas de cumplimiento (RGPD)
- Bajas por campaña.
- Tasa de baja (bajas / enviados).
- Rebotes definitivos (emails inválidos).

### M.7 Métricas por comercial
- Leads asignados, oportunidades, ventas, ingresos, tasa de cierre.

---

## ANEXO — GAPS ENTRE LO ACTUAL Y ESTA ESPECIFICACIÓN

| Área | Estado actual | Requerido por esta especificación |
|------|---------------|-----------------------------------|
| Estados | 3 vocabularios incoherentes | 1 sola fuente de verdad (D.1). |
| Empresa/Contacto | Solo `clubes_crm` | Modelo Empresa ↔ Contactos (C.1/C.2). |
| Secuencias | No existen | Secuencias con esperas (C.5/F.2). |
| WhatsApp | Sin motor | Conversación real (C.7/G). |
| Clics | No se trackean | Tracking de clics (K.7). |
| Ingresos | No se miden | Ventas y métricas de dinero (C.10/M.5). |
| Usuarios | 1 contraseña global | Roles y permisos (B/L). |
| Campañas | Solo CLI | UI de campañas (I.4). |
| Productos | No existe | Catálogo (C.11). |
| Postventa | No existe | Flujo postventa (E.6). |
| Tareas | Solo campo `proxima_accion` | Entidad Tarea con recordatorios (C.8). |
