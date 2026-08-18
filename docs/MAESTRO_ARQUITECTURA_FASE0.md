# DOCUMENTO MAESTRO DE ARQUITECTURA — CRM OUTBOUND FUTPROTEC

**FASE 0 — CONTRATO DE ARQUITECTURA, CONSISTENCIA E INVARIANTES**

- **Fecha**: 2026-08-18
- **Modo**: READ-ONLY (no se modificó archivo, BD, configuración, cron ni producción)
- **Alcance**: `public_html/outbound/`
- **Propósito**: Documentar el estado real del sistema, sus invariantes y el contrato que toda fase posterior debe respetar. Este documento es la **referencia obligatoria** durante todas las fases de evolución.

> **Regla fundamental**: No se implementa ninguna solución hasta haber demostrado documentalmente por qué es necesaria y cómo preserva los invariantes existentes. Cada fase posterior DEBE actualizar este documento (sección "Registro de fases") con: qué se pretendía, qué se modificó, qué se comprobó, resultados PASS/FAIL, archivos/BD modificados, pruebas, riesgos residuales y rollback.

---

## 1. MAPA REAL DE ENTIDADES Y RELACIONES ACTUALES

### 1.1 Tablas existentes (verificadas en `cli/init_db.php` + código)

| Tabla | Rol | Columnas clave |
|---|---|---|
| `clubes_crm` | **Lead** (club) | `id` (PK), `nombre_club`, `federacion`, `persona_contacto`, `cargo_contacto`, `email` (UNIQUE), `telefono_fijo`, `telefono_movil`, `tiene_whatsapp`, `estado_lead`, `observaciones`, `ultimo_contacto`, `creado_el`, `es_duplicado`, `duplicado_id`, `estado_lead_backup`, `volumen_estimado`, `num_jugadores`, `categorias`, `fecha_decision_prevista`, `objeciones`, `proxima_accion`, `canal_interaccion`, `motivo_perdida` |
| `envios` | **Envío** (email) | `id`, `club`, `email`, `federacion`, `cuenta_emision`, `fecha_envio`, `estado` (pendiente/enviado/abierto/error), `tracking_id` (UNIQUE), `asunto`, `cuerpo_mensaje`, `lead_id`, `campaign_id`, `variant`, `plantilla_id`, `smtp_id`, `message_id`, `es_test`, `resultado_envio`, `fecha_resultado_envio` |
| `aperturas` | **Apertura** (tracking) | `id`, `tracking_id` (FK→envios), `fecha_apertura`, `ip`, `user_agent` |
| `rebotes` | **Rebote** | `id`, `email`, `motivo`, `fecha_rebote` |
| `respuestas` | **Respuesta** (inbound) | `id`, `envio_id`, `fecha_respuesta`, `remitente`, `subject`, `clasificacion`, `estado_procesamiento` |
| `comunicaciones_log` | **Log de eventos / timeline** | `id`, `lead_id`, `club_id`, `tipo_evento` (string libre), `plantilla_id`, `detalles`, `ip_registro`, `fecha`, `id_cuenta_smtp`, `tipo`, `resultado`, `codigo_error`, `variante_ab`, `canal`, `resumen`, `proxima_accion` |
| `pipelines` | **Campaña** (uso actual) | `id`, `nombre`, `identificador`, `estado` (PILOT/ACTIVE/...), `entorno` (test/pilot/production), `activo` |
| `plantillas` | **Plantilla** (contenido) | `id`, `nombre`, `asunto`, `cuerpo`, `tipo` (html/texto_plano/whatsapp), `categoria`, `activo`, `fecha_creacion`, `asunto_b`, `asunto_c`, `cuerpo_b`, `cuerpo_c`, `test_ab` |
| `cuentas_smtp` | **Cuenta SMTP** | `id`, `email`, `host`, `puerto`, `usuario`, `password`, `seguridad`, `activa`, `limite_diario`, `enviados_hoy`, `ultimo_error`, `ultimo_uso`, `nombre_emisor`, `cargo_emisor` |
| `config` | **Configuración global** | `clave` (PK), `valor` |
| `mockups` | **Mockup** (propuesta visual) | `id`, `lead_id`, `pipeline_id`, `estado`, `solicitado_en`, `enviado_en` |
| `presupuestos` | **Presupuesto** | `id`, `lead_id`, `pipeline_id`, `version`, `unidades`, `precio_unitario`, `subtotal`, `descuento_aplicado`, `condiciones_pago`, `transporte`, `importe_total`, `margen_potencial_club`, `estado`, `fecha` |
| `snapshots` | **Snapshot de KPIs** | `fecha`, `total_leads`, `sin_contactar`, `contactado`, `respondio`, `interesado`, `cualificado`, `propuesta`, `negociacion`, `ganado`, `perdido`, `rebotado`, `baja_optout`, `metadata` |
| `destinatarios_test` | **Destinos de prueba** | (aislamiento TEST) |

### 1.2 Relaciones reales (no todas son FK declaradas; muchas son por convención)

```
clubes_crm.id ──────────────> envios.lead_id          (envío pertenece a lead)
clubes_crm.email ───────────> envios.email            (join por email, usado en analytics)
clubes_crm.email ───────────> rebotes.email           (join por email)
envios.tracking_id ─────────> aperturas.tracking_id   (apertura pertenece a envío)
envios.id ──────────────────> respuestas.envio_id     (respuesta pertenece a envío)
envios.campaign_id ─────────> pipelines.id            (envío pertenece a campaña)
envios.plantilla_id ────────> plantillas.id           (envío usó plantilla)
envios.smtp_id ─────────────> cuentas_smtp.id         (envío usó cuenta)
comunicaciones_log.lead_id ─> clubes_crm.id           (evento pertenece a lead)
mockups.lead_id ────────────> clubes_crm.id
presupuestos.lead_id ───────> clubes_crm.id
```

**IMPORTANTE**: `envios` se une a `clubes_crm` **por email** en la mayoría de consultas de analytics (no por `lead_id`), porque `lead_id` se añadió en una migración posterior y hay filas legacy. Esto es una fuente de riesgo de inconsistencia (ver §6).

---

## 2. FUENTE DE VERDAD DE CADA DATO IMPORTANTE

| Dato | Fuente de verdad | Dónde se consulta |
|---|---|---|
| Identidad del lead | `clubes_crm.id` (PK) + `clubes_crm.email` (UNIQUE) | Todo |
| Estado comercial | `clubes_crm.estado_lead` | Kanban, gestor, analytics |
| Próxima acción | `clubes_crm.proxima_accion` (texto libre, **sin fecha estructurada**) | Gestor, followups |
| Último contacto | `clubes_crm.ultimo_contacto` | Gestor, followups |
| WhatsApp | `clubes_crm.tiene_whatsapp` + `telefono_movil` | Kanban, gestor |
| Volumen potencial | `clubes_crm.volumen_estimado` | Analytics, presupuestos |
| Envío realizado | `envios` (fila con estado enviado/abierto) | Analytics, timeline |
| Variante usada | `envios.variant` (inmutable) | Analytics A/B/C |
| Apertura | `aperturas` (por tracking_id) | Analytics, timeline |
| Rebote | `rebotes` (por email) | Analytics, timeline |
| Respuesta | `respuestas` (por envio_id) | Respuestas, timeline |
| Baja/opt-out | `clubes_crm.estado_lead` ∈ supresión + marca `[BAJA] fuente=email` en `observaciones` | Lista negra, elegibilidad |
| Campaña | `pipelines` (id, estado, entorno, activo) | Lanzadera, cron, analytics |
| Plantilla | `plantillas` | Editor, lanzadera, cron |
| Cuenta SMTP | `cuentas_smtp` | Lanzadera, cron |
| Límite diario | `cuentas_smtp.limite_diario` + `enviados_hoy` | Lanzadera, cron |
| Modo entorno | `config.modo_entorno` (test/produccion) | Cron, elegibilidad |
| Motor activo | `config.motor_estado` (activo/pausado) | Cron, lanzadera |
| Evento temporal | `comunicaciones_log` | Timeline, followups |

---

## 3. DEPENDENCIAS ENTRE TABLAS, FUNCIONES, ENDPOINTS, UI Y CRON

### 3.1 Módulos PHP centrales (inc/)
| Archivo | Funciones | Depende de | Usado por |
|---|---|---|---|
| `inc/eligibilidad.php` | `esLeadTest`, `esCampanaTest`, `esEnvioTest`, `sqlFiltroComercial`, `sqlFiltroCompatibilidadLeadCampana`, `esElegibleParaEnvio`, `plantillaEstaCongelada`, `reservarEnvioLogico` | `abc.php`, `respuestas.php`, tablas `clubes_crm`, `envios`, `pipelines`, `plantillas` | `cron.php`, `enviar_lote.php`, `get_cola.php`, `dashboard.php` |
| `inc/abc.php` | `asignarVariante`, `resolverContenidoVariante`, `validarCampanaActiva`, `esEntornoCoherente` | tablas `pipelines`, `plantillas` | `cron.php`, `enviar_lote.php`, `get_cola.php` |
| `inc/respuestas.php` | `clasificarRespuesta`, `CLASIFICACIONES_VALIDAS` | tabla `respuestas` | `dashboard.php` |
| `inc/metricas.php` | `calcularMetricas` | tablas `envios`, `aperturas`, `respuestas`, `pipelines` | `dashboard.php` |
| `inc/mime.php` | construcción MIME + tracking | — | `enviar_lote.php` |

### 3.2 Motores de envío
| Motor | Naturaleza | Flujo |
|---|---|---|
| `api/enviar_lote.php` | HTTP (lanzadera navegador) | Recibe lead+plantilla+cuenta+variante+campaña → `reservarEnvioLogico` → SMTP → actualiza `envios` + `comunicaciones_log` + `clubes_crm.estado_lead` + `cuentas_smtp.enviados_hoy` |
| `cli/cron.php` | CLI (worker) | Valida campaña → selecciona cuenta SMTP → selecciona 1 lead (`ORDER BY creado_el LIMIT 1`) → `reservarEnvioLogico` → SMTP → actualiza. **1 envío por ejecución.** |

### 3.3 Endpoints API
| Endpoint | Función |
|---|---|
| `api/get_cola.php` | Genera cola de candidatos (filtros estado/federación/campaña), asigna SMTP round-robin, calcula variante |
| `api/enviar_lote.php` | Ejecuta envío individual (dirigido o desde cola) |
| `api/lead_search.php` | Búsqueda de leads |
| `api/lead_validate.php` | Valida elegibilidad de un lead para una campaña |
| `api/baja.php` | Baja/opt-out del destinatario |
| `api/smtp.php` | Gestión de cuentas SMTP |
| `api/track.php` | Pixel de apertura |
| `dashboard.php` (action=*) | update_lead, blacklist_add/remove, get_blacklist, add_lead, get_lead, save_template, delete_template, get_templates, get_categorias, preview_template, get_last_envios, get_followups, get_respuestas, get_respuesta, clasificar_respuesta, get_piloto_metricas, get_piloto_campanas, get_analytics, mockup_*, presupuesto_crear, snapshot_crear, registrar_interaccion, get_interacciones, update_config |

### 3.4 UI (tabs)
| Tab | Archivo | Depende de |
|---|---|---|
| Kanban | `tabs/kanban.php` | `clubes_crm`, `envios`, `aperturas` |
| Gestor | `tabs/gestor.php` | `clubes_crm`, `envios`, `aperturas`, `respuestas` |
| Lanzadera | `tabs/lanzadera.php` | `get_cola.php`, `enviar_lote.php`, `lead_search.php`, `lead_validate.php` |
| Editor plantillas | `tabs/editor.php` | `get_templates`, `save_template`, `delete_template`, `get_categorias` |
| Respuestas | `tabs/respuestas.php` | `get_respuestas`, `get_respuesta`, `clasificar_respuesta` |
| Analytics | `tabs/analytics.php` | `get_analytics` |
| SMTP | `tabs/smtp.php` | `api/smtp.php` |
| Lista negra | `tabs/lista_negra.php` | `get_blacklist`, `blacklist_add/remove` |
| Modales | `tabs/modals.php` | `get_lead`, `get_interacciones`, `registrar_interaccion`, `mockup_*`, `presupuesto_crear` |
| JS | `js/app.js` | Todos los endpoints (Alpine.js) |

### 3.5 Cadena de dependencia crítica (envío)
```
UI Lanzadera (app.js)
  → api/get_cola.php (selecciona leads + SMTP + variante)
  → api/enviar_lote.php
      → inc/eligibilidad.php::reservarEnvioLogico (idempotencia)
      → inc/abc.php::asignarVariante + resolverContenidoVariante
      → SMTP socket
      → UPDATE envios (estado) + INSERT comunicaciones_log + UPDATE clubes_crm + UPDATE cuentas_smtp
```

---

## 4. DIFERENCIA ENTRE CONCEPTOS (DEFINICIONES OPERATIVAS)

| Concepto | Definición | Dónde vive hoy | Problema actual |
|---|---|---|---|
| **Estado comercial** | Posición del lead en el pipeline de 9 etapas (01→09). Es **manual** (lo mueve el comercial). | `clubes_crm.estado_lead` | Correcto, pero se usa también para codificar actividad |
| **Actividad** | Última acción observable del lead (abrió, respondió, rebotó). Es **derivada** de eventos. | No almacenada; se calcula de `envios`/`aperturas`/`respuestas` | No hay un campo "actividad"; se infiere en cada consulta |
| **Evento** | Hecho puntual en el tiempo (email enviado, abierto, respuesta, cambio de estado). | `comunicaciones_log` (string libre en `tipo_evento`) | Vocabulario no controlado → timeline no estructurado |
| **Próxima acción** | Qué debe hacer el comercial y cuándo. | `clubes_crm.proxima_accion` (texto) | **Sin fecha estructurada** → no se puede ordenar por vencimiento |
| **Campaña** | Ejecución de envío con audiencia, entorno, estado y límites. | `pipelines` | Nombre ambiguo (ver §5) |
| **Plantilla** | Recurso de contenido reutilizable. | `plantillas` | Contiene A/B/C embebido (asunto_b/c, cuerpo_b/c) |
| **Variante** | Versión A/B/C de un mensaje. | Columnas de `plantillas` | No es entidad → no versionable ni declarable ganadora |
| **Envío** | Instancia concreta de un email a un lead en una campaña. | `envios` | Correcto; es la fuente histórica inmutable |

---

## 5. CONFLICTOS CONCEPTUALES ACTUALES

### 5.1 Uso ambiguo de `pipelines` (CONFLICTO CRÍTICO)
- **Uso real**: `pipelines` es la tabla de **campañas** (tiene `estado` PILOT/ACTIVE, `entorno`, `activo`). `envios.campaign_id` → `pipelines.id`.
- **Conflicto**: el nombre "pipeline" en CRM normalmente significa "etapa comercial" (el Kanban). En `editor.php` el campo "Pipeline" lista los **estados del Kanban** (`estadosLead`), no las campañas. Es decir, **el mismo término "pipeline" se usa para dos conceptos incompatibles**: campaña de envío y etapa comercial.
- **Riesgo**: confusión en la UI y en el modelo. Un desarrollador nuevo no sabe si "pipeline" es campaña o etapa.
- **Recomendación**: renombrar conceptualmente `pipelines` → `campañas` (o mantener el alias de tabla pero usar "Campaña" en toda la UI y documentación). No mezclar con "etapa comercial".

### 5.2 Campaña ↔ Plantilla desconectadas (CONFLICTO)
- No existe ninguna tabla que relacione `pipelines` con `plantillas`. La lanzadera pide "Seleccionar Campaña" y "Seleccionar Plantilla" como decisiones independientes.
- `plantillaEstaCongelada()` es un **parche** que impide sobrescribir una plantilla usada por una campaña PILOT/ACTIVE, compensando la falta de relación explícita.
- **Riesgo**: lanzar una campaña con la plantilla equivocada; no poder modelar secuencias (email 1, email 2).

### 5.3 Variantes A/B/C como columnas (CONFLICTO)
- `plantillas.asunto_b/c`, `cuerpo_b/c`, `test_ab` embeben las variantes en la plantilla.
- **Riesgo**: no se puede declarar una variante ganadora sin romper el histórico; no se puede reutilizar una variante en otra campaña; no se pueden tener >3 variantes; no se puede versionar.

### 5.4 Estado vs Actividad mezclados (CONFLICTO)
- `estado_lead` intenta ser a la vez estado comercial y actividad. Un lead "02 Contactado" es un estado, pero "abrió" es una actividad que no se refleja en el estado.
- **Riesgo**: el Kanban no muestra si un lead abrió/respondió sin consultar tablas derivadas.

### 5.5 `envios` unido por email en analytics (RIESGO DE CONSISTENCIA)
- Muchas consultas de analytics unen `envios`↔`clubes_crm` por `email` (no por `lead_id`), porque `lead_id` se añadió después y hay filas legacy.
- **Riesgo**: si un email cambia o hay duplicados, el join por email puede producir resultados inconsistentes. `lead_id` es la clave correcta.

### 5.6 `comunicaciones_log` como log técnico, no timeline comercial (CONFLICTO)
- `tipo_evento` es string libre (`envio_email`, `cambio_estado`, `blacklist_add`, `mockup_solicitado`, `presupuesto_creado`, `nota_manual`...).
- **Riesgo**: no se puede renderizar un timeline comercial coherente sin normalizar el vocabulario.

---

## 6. MATRIZ ACTUAL → FUTURO (para cada cambio propuesto)

| # | Cambio propuesto | Estado ACTUAL | Estado FUTURO | Invariantes que preserva |
|---|---|---|---|---|
| C1 | ID visible del lead | `id` interno no mostrado | `#0001847` (padding presentación sobre `id`) | Identidad estable, inmutable |
| C2 | Normalizar `tipo_evento` de `comunicaciones_log` | string libre | vocabulario controlado (enum) | Histórico de eventos intacto |
| C3 | Añadir `fecha_proxima_accion` | solo texto | texto + fecha estructurada | Próxima acción existente |
| C4 | Relación campaña-plantilla | inexistente | tabla `campana_plantillas` | Idempotencia, aislamiento |
| C5 | Variantes como entidades | columnas de plantilla | tabla `variantes` ligada a `campana_plantillas` | Histórico `envios.variant` inmutable |
| C6 | Cola persistente | memoria JS (lanzadera) | tabla `cola_envios` + worker cron | Idempotencia, reanudación |
| C7 | Gestor lista+panel | tabla simple | lista + panel lateral | — |
| C8 | Timeline comercial | log técnico | timeline normalizado | Histórico de eventos |
| C9 | Dashboard orientado a acción | analítico | bandeja de acción | — |
| C10 | Separar Campañas de Plantillas en navegación | mezcladas | entidades separadas | — |

---

## 7. INVARIANTES QUE NUNCA PUEDEN ROMPERSE

Estos son los **contratos no negociables**. Cualquier cambio futuro debe demostrar que los preserva.

### I1. Idempotencia por lead+campaña
- **Regla**: un lead tiene como máximo **UN envío lógico por campaña** (`envios` con `lead_id`+`campaign_id` no nulos). Garantizado por índice único parcial `idx_envios_lead_campaign` + `reservarEnvioLogico()` con `INSERT OR IGNORE`.
- **Estados finales (no reenviar)**: `enviado`, `abierto`.
- **Estados retryables (reintento sobre la MISMA fila)**: `pendiente`, `error`.
- **Nunca**: crear un segundo envío lógico para el mismo (lead, campaña).

### I2. Aislamiento TEST/REAL
- **Regla simétrica**: campaña TEST → solo leads TEST; campaña no TEST → nunca leads TEST.
- **Definición lead TEST**: email contiene `@futprotec.local` O `nombre_club` empieza por `test` (case-insensitive). Fuente única: `esLeadTest()`.
- **Definición campaña TEST**: `pipelines.entorno = 'test'`. Fuente única: `esCampanaTest()`.
- **Definición envío TEST**: `envios.es_test = 1` (primaria) + red de seguridad por email/club.
- **Nunca**: mezclar un TEST con el histórico comercial. Toda consulta comercial usa `sqlFiltroComercial()` (`es_test = 0`).

### I3. Opt-out real protegido
- **Regla**: un opt-out real (baja del destinatario vía email, marca `[BAJA] fuente=email` en `observaciones`) **no puede reactivarse** desde el Kanban. Solo `blacklist_remove` con motivo obligatorio y confirmación explícita.
- **Nunca**: deshacer una baja real accidentalmente.

### I4. Histórico inmutable
- **Regla**: `envios` es la fuente histórica del mensaje. `envios.variant`, `envios.cuerpo_mensaje`, `envios.asunto`, `envios.message_id` son **inmutables** una vez enviado.
- **Nunca**: sobrescribir un envío ya realizado. La congelación de plantillas (`plantillaEstaCongelada`) protege que una plantilla usada por campaña PILOT/ACTIVE no se sobrescriba.

### I5. Variantes determinísticas e inmutables
- **Regla**: `asignarVariante(lead_id, campaign_id)` es determinística (hash estable). Mismo (lead, campaña) → misma variante SIEMPRE. Un retry no cambia la variante.
- **Nunca**: asignar variante aleatoria por envío en una campaña real.

### I6. Estados del Kanban
- **Regla**: los 9 estados (`01 Sin Contactar` ... `09 Perdido`) + estados de supresión (`Lista Negra`, `Opt-Out`, `Unsubscribed`, `Baja / Opt-Out`, `Email Inválido`) son el vocabulario de `estado_lead`.
- **Nunca**: introducir un estado nuevo sin actualizar el orden del funnel (`stageOrder`) y el Kanban.

### I7. Reanudación
- **Regla**: el scraping/envío puede relanzarse en cualquier momento sin perder progreso. `envios` + idempotencia garantizan que un lead ya enviado no se reenvía.
- **Nunca**: perder la capacidad de reanudar un envío a mitad de campaña.

### I8. Coherencia de entorno
- **Regla**: `esEntornoCoherente()` impide: campaña test en producción, y campaña pilot/production en modo test local.
- **Nunca**: enviar una campaña comercial en un entorno de pruebas, ni una campaña test a producción.

### I9. Protección de credenciales SMTP
- **Regla**: las contraseñas de `cuentas_smtp` y del array `$CUENTAS_SMTP` en `public_html/outbound/outbound/enviar_smtp_random.php` son sensibles. Nunca exponer en logs/commits ni sobrescribir sin permiso explícito.

### I10. No borrar output/ ni checkpoints/
- **Regla**: nunca borrar/sobrescribir archivos en `output/` ni `checkpoints/` sin permiso explícito. Datos irrecuperables.

---

## 8. MODELO DE TRAZABILIDAD COMPLETA DE UN LEAD

Flujo de vida de un lead desde entrada hasta próxima acción, con las tablas implicadas en cada paso:

```
1. ENTRADA
   clubes_crm (INSERT) → id asignado, estado_lead='01 Sin Contactar', creado_el

2. CAMPAÑA
   pipelines (campaña creada con estado/entorno/activo)
   → (futuro) campana_plantillas + variantes

3. ENVÍO
   envios (INSERT via reservarEnvioLogico: lead_id, campaign_id, variant, plantilla_id, smtp_id, tracking_id, message_id, es_test)
   → estado 'pendiente' → 'enviado'/'error'
   comunicaciones_log (tipo_evento='envio_email', variante_ab, id_cuenta_smtp)
   clubes_crm.estado_lead → '02 Contactado', ultimo_contacto
   cuentas_smtp.enviados_hoy +1

4. APERTURA
   aperturas (INSERT por tracking_id) → fecha_apertura, ip, user_agent
   envios.estado → 'abierto' (si se marca)

5. RESPUESTA
   respuestas (INSERT por envio_id) → remitente, subject, clasificacion
   comunicaciones_log (tipo_evento='respuesta_recibida')
   clubes_crm.estado_lead → '03 Respondió' (manual o automático)

6. PROPUESTA / PRESUPUESTO / MOCKUP
   mockups (INSERT) → estado 'solicitado'/'enviado'
   presupuestos (INSERT) → version, importe_total
   comunicaciones_log (tipo_evento='mockup_solicitado'/'presupuesto_creado')
   clubes_crm.estado_lead → '06 Propuesta'

7. CAMBIO DE ESTADO (manual, Kanban)
   clubes_crm.estado_lead → nueva etapa
   comunicaciones_log (tipo_evento='cambio_estado', detalles='Estado cambiado de X a Y')

8. PRÓXIMA ACCIÓN
   clubes_crm.proxima_accion (texto) + (futuro) fecha_proxima_accion
   → dashboard "qué debo hacer hoy"

9. BAJA / REBOTE / LISTA NEGRA
   rebotes (INSERT por email) → motivo
   clubes_crm.estado_lead → supresión + marca [BAJA]/[LISTA NEGRA] en observaciones
   comunicaciones_log (tipo_evento='blacklist_add'/'baja')
```

**Punto clave**: la trazabilidad completa YA existe dispersa en estas tablas. El problema no es que falten datos, sino que **no hay una vista unificada** (timeline) ni un vocabulario controlado. El trabajo de trazabilidad es de **agregación y normalización**, no de creación de datos nuevos.

---

## 9. ANÁLISIS: ¿ES NECESARIA `cola_envios` O BASTA CON `envios`?

### Pregunta
¿Puede `envios` cubrir persistencia, concurrencia, reanudación, programación, retries y secuencias, o hace falta una tabla `cola_envios` separada?

### Análisis de capacidades de `envios`
| Capacidad | ¿`envios` la cubre? | Detalle |
|---|---|---|
| **Persistencia** | ✅ | Cada envío es una fila persistente |
| **Idempotencia** | ✅ | Índice único lead+campaign |
| **Reanudación** | ⚠️ Parcial | `estado='pendiente'/'error'` permite reintento, pero no hay "cola de pendientes por campaña" explícita; el cron selecciona leads al vuelo |
| **Concurrencia** | ⚠️ Parcial | `reservarEnvioLogico` con `INSERT OR IGNORE` evita duplicados, pero no hay bloqueo de "este lead está siendo procesado ahora" |
| **Programación (ventana horaria)** | ❌ | No hay campo de "cuándo debe enviarse" ni ventana |
| **Retries con backoff** | ⚠️ Parcial | `estado='error'` es retryable, pero no hay contador de intentos ni backoff |
| **Secuencias (paso 1, 2, 3)** | ⚠️ Parcial | `campaign_id` agrupa, pero no hay `paso` para follow-ups |
| **Límites diarios** | ⚠️ | Se gestiona en `cuentas_smtp.enviados_hoy`, no en la cola |

### Conclusión
**`envios` es la fuente de verdad del RESULTADO (qué se envió), pero NO es una cola de trabajo.** Mezclar ambas responsabilidades en `envios` contaminaría la tabla histórica con estado de planificación (pendiente de programar, reintentos, ventanas) que no pertenece al registro de envíos.

**Recomendación**: introducir `cola_envios` como **capa de planificación/orquestación** separada, que referencia a `envios` como resultado:
```
cola_envios (planificación)
  id, lead_id, campaign_id, paso, estado (pendiente/enviado/error/saltado),
  intentos, proxima_ejecucion, ventana_horaria, fecha_creacion
      │
      └── al procesar → crea/actualiza envios (resultado inmutable)
```

**Beneficios**:
- `envios` queda limpio como histórico inmutable (invariante I4).
- `cola_envios` permite programación (ventana horaria), reintentos con backoff, secuencias (paso) y límites diarios sin tocar el histórico.
- El worker cron procesa `cola_envios` (planificación) y delega el resultado a `envios` (histórico).

**Regla de diseño**: `cola_envios` es **descartable/regenerable** (se puede reconstruir desde `envios` + campaña), mientras que `envios` es **permanente**. Esto garantiza que un fallo en la cola nunca corrompe el histórico.

---

## 10. CONTRADICCIONES ENTRE LA AUDITORÍA ANTERIOR Y ESTA PROPUESTA

La auditoría previa (informe de producto/UI-UX) proponía una serie de evoluciones. Esta FASE 0 las contrasta con el estado real y detecta las siguientes tensiones:

| Propuesta de la auditoría previa | Contraste con la realidad (FASE 0) | Veredicto |
|---|---|---|
| "Introducir ID numérico visible #0001847" | `clubes_crm.id` ya existe y es estable/único. Solo falta presentación (padding). | ✅ Compatible, sin cambio de modelo |
| "Timeline del lead" | `comunicaciones_log` ya existe pero con `tipo_evento` libre. | ⚠️ Requiere normalizar vocabulario, no crear tabla nueva |
| "Separar Campañas de Plantillas en navegación" | Correcto: hoy están desconectadas (sin relación). | ✅ Coherente con §5.2 |
| "Variantes como entidades" | Hoy son columnas de `plantillas`. | ⚠️ Requiere migración cuidadosa (invariante I5) |
| "Lanzadera persistente con cron" | `cli/cron.php` ya existe (1 envío/ejecución). | ✅ Evolución natural hacia `cola_envios` |
| "Próxima acción con fecha" | Hoy solo texto en `clubes_crm.proxima_accion`. | ⚠️ Añadir `fecha_proxima_accion` sin romper texto |
| "Dashboard orientado a acción" | Hoy es analítico. | ✅ No contradice; es aditivo |
| "Gestor lista + panel lateral" | Hoy es tabla simple. | ✅ Aditivo, sin cambio de modelo |

**Conclusión**: No hay contradicciones destructivas entre la auditoría previa y esta FASE 0. La auditoría previa proponía **vistas y presentación** (UI/UX), mientras que esta FASE 0 establece el **contrato de datos** que esas vistas deben respetar. La única tensión real es que la auditoría previa asumía implícitamente que "campaña" y "plantilla" eran entidades relacionadas, cuando en realidad están desconectadas (§5.2) — esto debe resolverse en el modelo de datos ANTES de construir la UI de campañas.

---

## 11. ANÁLISIS DE IMPACTO POR CAMBIO FUTURO

Para cada cambio propuesto: archivos afectados, tablas, funciones, dependencias, riesgo, migración, rollback y pruebas.

### C1 — ID visible del lead
- **Archivos**: `tabs/gestor.php`, `tabs/kanban.php`, `tabs/modals.php`, `js/app.js`
- **Tablas**: ninguna (solo presentación de `clubes_crm.id`)
- **Funciones**: ninguna
- **Dependencias**: ninguna
- **Riesgo**: bajo
- **Migración**: ninguna
- **Rollback**: revertir presentación
- **Pruebas**: mostrar `#0001847` en listados y detalle; ordenar por id

### C2 — Normalizar `tipo_evento` de `comunicaciones_log`
- **Archivos**: `inc/eligibilidad.php`, `dashboard.php` (registrar_interaccion), `cli/cron.php`, `api/enviar_lote.php`
- **Tablas**: `comunicaciones_log` (solo escritura futura; no tocar histórico)
- **Funciones**: `registrar_interaccion`, `reservarEnvioLogico`
- **Dependencias**: timeline, followups
- **Riesgo**: medio (afecta a todos los puntos de escritura de eventos)
- **Migración**: añadir columna `tipo_evento_norm` o validar en escritura; mapear valores legacy en lectura
- **Rollback**: revertir validación de escritura
- **Pruebas**: registrar cada tipo de evento; verificar timeline

### C3 — Añadir `fecha_proxima_accion`
- **Archivos**: `dashboard.php` (update_lead), `tabs/modals.php`, `tabs/gestor.php`
- **Tablas**: `clubes_crm` (ALTER ADD COLUMN)
- **Funciones**: `update_lead`
- **Dependencias**: dashboard "qué debo hacer hoy"
- **Riesgo**: bajo
- **Migración**: `ALTER TABLE clubes_crm ADD COLUMN fecha_proxima_accion DATETIME`
- **Rollback**: `ALTER TABLE ... DROP COLUMN` (SQLite 3.35+)
- **Pruebas**: guardar/leer fecha; ordenar por vencimiento

### C4 — Relación campaña-plantilla (`campana_plantillas`)
- **Archivos**: `inc/eligibilidad.php`, `inc/abc.php`, `tabs/editor.php`, `tabs/lanzadera.php`, `cli/cron.php`, `api/get_cola.php`, `api/enviar_lote.php`
- **Tablas**: nueva `campana_plantillas` (id, campaign_id, plantilla_id, paso, activo)
- **Funciones**: `reservarEnvioLogico`, `asignarVariante`, `esElegibleParaEnvio`
- **Dependencias**: lanzadera, cron, editor
- **Riesgo**: alto (cambia el flujo de selección de plantilla)
- **Migración**: crear tabla; backfill desde campañas PILOT/ACTIVE existentes; mantener compatibilidad con selección manual
- **Rollback**: desactivar tabla (volver a selección manual)
- **Pruebas**: campaña con plantilla fija; secuencia de 2 pasos; idempotencia

### C5 — Variantes como entidades
- **Archivos**: `inc/abc.php`, `tabs/editor.php`, `api/get_cola.php`, `api/enviar_lote.php`
- **Tablas**: nueva `variantes` (id, campana_plantilla_id, letra, asunto, cuerpo, activa, ganadora)
- **Funciones**: `asignarVariante`, `resolverContenidoVariante`
- **Dependencias**: A/B/C, histórico `envios.variant`
- **Riesgo**: alto (migración de columnas a tabla)
- **Migración**: crear `variantes`; backfill desde `plantillas.asunto_b/c`; `envios.variant` queda como referencia inmutable
- **Rollback**: mantener `envios.variant` como fuente; desactivar tabla
- **Pruebas**: A/B/C determinístico; declarar ganadora; histórico intacto

### C6 — Cola persistente (`cola_envios`)
- **Archivos**: `cli/cron.php`, `api/get_cola.php`, `api/enviar_lote.php`, `tabs/lanzadera.php`
- **Tablas**: nueva `cola_envios`
- **Funciones**: `reservarEnvioLogico` (delegar a cola), nuevo worker
- **Dependencias**: cron, lanzadera
- **Riesgo**: medio (aditivo; no toca `envios`)
- **Migración**: crear tabla; el cron pasa a leer de cola
- **Rollback**: cron vuelve a selección al vuelo
- **Pruebas**: reanudación, concurrencia, límites diarios, ventana horaria, retries

### C7 — Gestor lista + panel lateral
- **Archivos**: `tabs/gestor.php`, `tabs/modals.php`, `js/app.js`
- **Tablas**: ninguna
- **Funciones**: `get_lead`, `get_interacciones`
- **Dependencias**: timeline
- **Riesgo**: bajo
- **Migración**: ninguna
- **Rollback**: revertir UI
- **Pruebas**: seleccionar lead → panel detalle

### C8 — Timeline comercial normalizado
- **Archivos**: `tabs/modals.php`, `js/app.js`, `dashboard.php` (get_interacciones)
- **Tablas**: `comunicaciones_log` (lectura normalizada)
- **Funciones**: `get_interacciones`
- **Dependencias**: C2
- **Riesgo**: medio
- **Migración**: mapeo de `tipo_evento` legacy → categoría
- **Rollback**: revertir mapeo
- **Pruebas**: timeline con eventos automáticos/comerciales/manuales

### C9 — Dashboard orientado a acción
- **Archivos**: `dashboard.php`, `tabs/analytics.php`, `js/app.js`
- **Tablas**: ninguna (consultas)
- **Funciones**: `calcularMetricas`, nuevas consultas de "bandeja de acción"
- **Dependencias**: C3 (fecha próxima acción)
- **Riesgo**: bajo
- **Migración**: ninguna
- **Rollback**: revertir UI
- **Pruebas**: "qué debo hacer hoy", "quién respondió", "propuestas pendientes"

### C10 — Separar Campañas de Plantillas en navegación
- **Archivos**: `dashboard.php` (navegación), `tabs/editor.php`, nuevo `tabs/campanas.php`
- **Tablas**: `pipelines` (renombrar conceptualmente a campañas)
- **Funciones**: `get_piloto_campanas`
- **Dependencias**: C4
- **Riesgo**: medio (UX)
- **Migración**: nueva pestaña; alias de tabla
- **Rollback**: revertir navegación
- **Pruebas**: crear/editar campaña sin tocar plantillas

---

## 12. REGISTRO DE FASES (BITÁCORA OBLIGATORIA)

> Cada fase posterior DEBE añadir una entrada aquí. Formato mínimo:

```
### FASE <N> — <Nombre> — <fecha>
- **Objetivo**: qué se pretendía
- **Modificado**: archivos / tablas / funciones
- **Comprobado**: qué se verificó
- **Resultado**: PASS / FAIL (+ evidencia)
- **Pruebas**: realizadas
- **Riesgos residuales**: pendientes
- **Rollback**: disponible / ejecutado
- **Invariantes verificadas**: I1..I10 (marcar cuáles se comprobaron)
```

### FASE 0 — Contrato de arquitectura — 2026-08-18
- **Objetivo**: Documentar estado real, invariantes y contrato para fases posteriores.
- **Modificado**: NINGUNO (modo READ-ONLY). Solo se creó este documento.
- **Comprobado**: esquema real de BD (`cli/init_db.php`), dependencias de módulos, endpoints, UI y cron.
- **Resultado**: PASS — documento maestro creado.
- **Pruebas**: lectura de `cli/init_db.php` (esquema completo verificado).
- **Riesgos residuales**: `envios` unido por email en analytics (§5.5); `pipelines` ambiguo (§5.1); campaña-plantilla desconectadas (§5.2).
- **Rollback**: N/A (no se modificó nada).
- **Invariantes verificadas**: I1–I10 documentadas como contrato.

---

## 13. RESUMEN EJECUTIVO

1. **El sistema ya tiene la trazabilidad completa** dispersa en `clubes_crm`, `envios`, `aperturas`, `respuestas`, `comunicaciones_log`, `mockups`, `presupuestos`. El problema es de **agregación y normalización**, no de falta de datos.
2. **Los invariantes críticos** (idempotencia, aislamiento TEST/REAL, opt-out, histórico inmutable, variantes determinísticas, reanudación, coherencia de entorno) están implementados y deben preservarse a toda costa.
3. **Los conflictos conceptuales reales** son: `pipelines` ambiguo (campaña vs etapa), campaña-plantilla desconectadas, variantes como columnas, estado vs actividad mezclados, `envios` unido por email, y `comunicaciones_log` como log técnico.
4. **`cola_envios` SÍ es necesaria** como capa de planificación separada de `envios` (histórico inmutable). No contaminar `envios` con estado de planificación.
5. **Ningún cambio futuro debe implementarse** sin actualizar este documento y demostrar que preserva I1–I10.


