# MEGAPROMPT V2 — EVOLUCIÓN QUIRÚRGICA CRM FUTPROTEC

## Instrumentación Comercial + Continuidad de Campaña 2

**Fecha:** 29/08/2026
**Proyecto:** FutProtec CRM Outbound
**Objetivo:** evolucionar el CRM existente para continuar la campaña comercial sobre los ~7.000 leads restantes, sin rehacer el sistema, sin perder histórico y sin introducir riesgo operativo o reputacional.

> **Nota de trazabilidad:** documento original aportado por el usuario el 29/08/2026 para cotejo con `docs/megaprompt_v2_crm_futprotec.md`. Las mejoras detectadas se integraron en la versión fusionada `docs/megaprompt_v2_crm_futprotec.md`.

---

# 0. ROL

Actúa simultáneamente como:

* Arquitecto senior de sistemas PHP + SQLite.
* Desarrollador PHP 8 / JavaScript.
* Especialista en CRM B2B y automatización comercial.
* Especialista en email outbound, SMTP, MIME y entregabilidad.
* Ingeniero de bases de datos SQLite.
* Auditor de datos y trazabilidad.
* QA senior especializado en regresión.
* Responsable de seguridad operativa del CRM.

Tu prioridad NO es construir el CRM ideal desde cero.

Tu prioridad es:

> **hacer evolucionar el CRM FutProtec existente con el mínimo cambio necesario para que pueda continuar la campaña real de forma segura, trazable, medible y reversible.**

---

# 1. INSTRUCCIÓN PRINCIPAL

Trabaja sobre el CRM FutProtec existente.

**NO rehagas el CRM.**

**NO sustituyas arquitecturas existentes que ya funcionan.**

**NO migres datos innecesariamente.**

**NO borres histórico.**

**NO rompas compatibilidad con módulos existentes.**

**NO hagas un despliegue global por comodidad.**

**NO envíes correos REAL salvo autorización explícita.**

La estrategia es:

> **AUDITAR → MODIFICAR LO MÍNIMO → PROBAR → DOCUMENTAR → ESPERAR AUTORIZACIÓN → SIGUIENTE FASE**

Cada modificación debe ser:

1. Aditiva siempre que sea posible.
2. Reversible.
3. Compatible con el código existente.
4. Verificable mediante tests.
5. Documentada.
6. Ejecutada primero en TEST.
7. Validada antes de afectar producción.

---

# 2. CONTEXTO DEL SISTEMA

El CRM está basado en:

* PHP 8.
* SQLite.
* JavaScript.
* SiteGround.
* `public_html/outbound/`.
* Base principal:

`public_html/outbound/data/stats.db`

El CRM ya dispone de:

* leads.
* campañas/pipelines.
* Kanban.
* SMTP rotativo.
* A/B/C.
* tracking de aperturas.
* respuestas.
* clasificación IA.
* TEST/REAL.
* `envios.es_test`.
* trazabilidad mediante `lead_id`.
* `campaign_id`.
* `variant`.
* `plantilla_id`.
* `smtp_id`.
* `message_id`.
* `in_reply_to`.
* seguimiento de respuestas.
* backups.
* migraciones.
* analítica.

NO debes asumir que estas funcionalidades necesitan ser reconstruidas.

Primero debes inspeccionar cómo funcionan actualmente.

---

# 3. ESTADO AUDITADO — 28/08/2026

La auditoría existente indica:

### Base de datos

* SQLite íntegra.
* `integrity_check = ok`.
* `journal_mode = wal`.
* `foreign_keys = OFF`.
* 29 tablas.
* ~1.818 clubes/leads.
* ~470 envíos.
* 326 aperturas.
* 30 respuestas.
* 10 cuentas SMTP.

### Campaña 2

`PILOTO_FUTPROTEC_2026_08`

Estado:

`PILOT`

Datos auditados:

* 348 leads reales.
* 432 envíos.
* 348 primeros envíos.
* 84 rotaciones.
* 100 % `ACCEPTED`.
* 134 leads con aperturas.
* 259 aperturas brutas.
* 5 respuestas.
* 21 hard bounces detectados dentro de `respuestas`.
* `rebotes` vacía.
* 0 presupuestos.
* 0 mockups.
* 0 ventas.

### A/B/C histórico

El primer envío utilizó asignación no determinista desde la UI:

```text
Math.random()
```

Por tanto:

> El histórico A/B/C existente NO debe tratarse como experimento estadístico limpio.

El backend sí dispone de asignación determinista mediante:

```text
inc/abc.php
```

y:

```text
asignarVariante()
siguienteVariante()
```

La nueva campaña debe utilizar exclusivamente asignación determinista.

---

# 4. PRINCIPIO FUNDAMENTAL DEL PROYECTO

## NO empezamos de nuevo.

Los aproximadamente 7.000 leads restantes forman parte de la misma estrategia comercial.

El objetivo es:

```text
CAMPAÑA EXISTENTE
        ↓
CORRECCIONES QUIRÚRGICAS
        ↓
MEJOR INSTRUMENTACIÓN
        ↓
LOTES CONTROLADOS
        ↓
APRENDIZAJE
        ↓
ESCALADO
```

No crear una campaña paralela innecesaria.

No duplicar el CRM.

No migrar todo a una arquitectura nueva.

---

# 5. REGLAS ABSOLUTAS

## 5.1 Regla TEST/REAL

TEST y REAL deben estar completamente separados.

Nunca:

* mezclar métricas.
* contaminar dashboards.
* contabilizar TEST como comercial.
* enviar TEST a un destinatario REAL.
* enviar REAL durante pruebas.

`envios.es_test` es la fuente principal de verdad.

---

## 5.2 Regla de envío

Hasta que las fases bloqueantes tengan PASS:

> **NO enviar ningún lote REAL.**

No importa que el usuario solicite "probar un pequeño lote".

Primero deben estar resueltos los bloqueantes de entregabilidad.

---

## 5.3 Regla de modificaciones

Antes de modificar cualquier archivo:

1. identificar archivo.
2. inspeccionar código actual.
3. identificar dependencias.
4. explicar modificación.
5. determinar riesgo.
6. realizar backup si afecta DB.
7. modificar.
8. ejecutar tests.
9. verificar regresión.

---

## 5.4 Regla de SQL

Nunca ejecutar una migración destructiva si puede realizarse mediante:

```sql
ALTER TABLE ... ADD COLUMN
```

o nuevas tablas/índices.

No:

```text
DROP TABLE
DROP COLUMN
DELETE histórico
UPDATE masivo irreversible
```

salvo autorización explícita.

---

# 6. FUENTES DE VERDAD

Antes de implementar cualquier cosa, inspecciona:

```text
public_html/outbound/
public_html/outbound/inc/
public_html/outbound/api/
public_html/outbound/js/
public_html/outbound/data/stats.db
docs/
backups/
```

Especial atención a:

```text
inc/abc.php
inc/smtp_transport.php
inc/atencion_lead.php
inc/eligibilidad.php
js/app.js
api/analytics.php
api/presupuestos.php
```

# 7. FASE 0 — SNAPSHOT Y AUDITORÍA

## Objetivo

Crear una fotografía verificable del sistema antes de tocar nada.

## NO modificar

Esta fase es exclusivamente de lectura.

## Tareas

Auditar:

### DB

* integridad.
* tablas.
* columnas.
* índices.
* triggers.
* conteos.
* `foreign_keys`.
* WAL.
* campañas.
* leads.
* envíos.
* respuestas.
* aperturas.
* rebotes.
* plantillas.
* SMTP.

### Código

Buscar:

```text
Math.random
asignarVariante
siguienteVariante
es_test
esLeadTest
esCampanaTest
esEnvioTest
campaign_id
message_id
in_reply_to
References
From:
Reply-To
```

### Entregabilidad

Inspeccionar:

* construcción MIME.
* `From`.
* nombre del remitente.
* codificación UTF-8.
* SMTP response.
* gestión de errores.
* bounce handling.

### Follow-ups

Determinar exactamente cómo se generan actualmente.

### Tracking

Auditar:

* aperturas.
* tracking ID.
* píxel.
* deduplicación.
* clicks.

---

## Output obligatorio FASE 0

Generar:

```text
docs/plan_instrumentacion_v2.md
```

Debe contener:

* estado actual.
* archivos afectados.
* tablas afectadas.
* riesgos.
* dependencias.
* cambios previstos.
* tests previstos.
* rollback previsto.

Además:

```text
FASE 0 = PASS
```

solo si:

* DB íntegra.
* snapshot realizado.
* backup verificable.
* ningún dato modificado.

**DETENERSE.**

Esperar autorización explícita para FASE 1.

---

# 8. FASE 1 — CORRECCIONES BLOQUEANTES

Esta fase tiene prioridad absoluta.

Debe resolver:

1. Supresión de hard bounces.
2. RFC 2047.
3. A/B/C determinista.
4. Protección TEST/REAL.
5. Validación MIME.

---

## 8.1 SUPRESIÓN DE HARD BOUNCES

Actualmente existen hard bounces detectados en `respuestas`, pero:

```text
rebotes = 0
```

Esto es peligroso.

Implementar mecanismo de supresión que impida enviar a una dirección marcada como:

```text
HARD_BOUNCE
```

o equivalente inequívoco.

No depender exclusivamente de `rebotes`.

El sistema debe poder consultar el histórico de respuestas/rebotes existentes.

Poblar la estructura `rebotes` de forma aditiva si resulta compatible.

No borrar las respuestas originales.

---

## 8.2 REGLA DE SUPRESIÓN

Antes de reservar/enviar un envío:

```text
¿email está suprimido?
        ↓
       SÍ
        ↓
NO ENVIAR
```

Registrar motivo.

Debe ser auditable.

---

## 8.3 RFC 2047

Corregir el nombre del emisor en:

```text
inc/smtp_transport.php
```

Debe soportar correctamente nombres como:

```text
Adrián Cano
José María
García López
FutProtec España
```

El nombre debe aparecer correctamente codificado en MIME.

Ejemplo conceptual:

```text
From: =?UTF-8?B?...?=
```

No utilizar el ejemplo literalmente si la implementación existente requiere otra estrategia RFC válida.

---

## 8.4 VALIDACIÓN RAW MIME

Crear test que genere el mensaje sin enviarlo REAL.

Validar:

* From.
* nombre.
* dirección.
* Subject.
* UTF-8.
* Reply-To.
* MIME.
* Content-Type.

El test debe inspeccionar el RAW.

---

# 9. FASE 1B — A/B/C DETERMINISTA

Eliminar cualquier ruta de producción que utilice:

```javascript
Math.random()
```

para determinar variante.

La misma combinación:

```text
lead_id + campaign_id
```

debe producir siempre la misma variante.

Utilizar la lógica existente de:

```text
inc/abc.php
```

siempre que sea posible.

No duplicar la lógica innecesariamente en JavaScript.

Preferir backend como fuente de verdad.

---

## Regla

Para un mismo:

```text
lead_id
campaign_id
```

la variante debe ser idéntica aunque:

* se recargue la página.
* se vuelva a abrir el lead.
* se reinicie el proceso.
* se lance otro lote.

---

# 10. FASE 1C — FOLLOW-UPS TRAZABLES

Todo nuevo seguimiento debe conservar:

```text
campaign_id
lead_id
plantilla_id
smtp_id
variant
parent_envio_id
respuesta_origen_id
message_id
in_reply_to
```

Cuando alguno no sea aplicable:

```text
NULL
```

Nunca inventar valores.

Nunca atribuir una campaña mediante:

```text
asunto LIKE 'Re:%'
```

---

# 11. FASE 1D — PASS/FAIL

La fase solo pasa si:

### Bounce

* dirección hard bounce → bloqueada.
* ninguna ruta alternativa puede saltarse la supresión.

### MIME

* nombres con acentos → MIME válido.
* RAW verificable.

### A/B/C

* misma combinación lead/campaign → misma variante.
* cero `Math.random()` para producción.

### Follow-up

* todos los nuevos seguimientos tienen trazabilidad.

### TEST/REAL

* TEST nunca aparece en métricas comerciales.

### DB

```text
integrity_check = ok
```

**Si cualquier punto falla → FASE 1 = FAIL.**

No avanzar.

---

# 12. FASE 2 — TRAZABILIDAD COMERCIAL

Solo ejecutar después de PASS FASE 1.

Objetivo:

> saber exactamente qué ocurrió con cada lead desde primer contacto hasta oportunidad.

---

## 12.1 ENVÍOS

Añadir aditivamente si no existen:

```text
variant_original
campaign_batch_id
parent_envio_id
respuesta_origen_id
```

Crear índices necesarios.

No alterar histórico existente.

---

# 13. FASE 2B — OPORTUNIDADES

Crear una tabla mínima:

```text
oportunidades
```

No convertirla en un ERP.

Debe permitir como mínimo:

```text
id
lead_id
campaign_id
estado
fecha_creacion
fecha_actualizacion
origen
proxima_accion
fecha_proxima_accion
motivo_perdida
notas
```

El estado de oportunidad será la fuente de verdad comercial cuando exista oportunidad.

`clubes_crm.estado_lead` se conserva como histórico/compatibilidad.

---

# 14. FASE 2C — EVENTOS

No crear otra infraestructura innecesaria si `comunicaciones_log` puede ampliarse.

Añadir:

```text
metadata TEXT
```

si no existe.

Normalizar progresivamente eventos como:

```text
EMAIL_SENT
EMAIL_OPENED
REPLY_RECEIVED
REPLY_CLASSIFIED
QUOTE_CREATED
MOCKUP_SENT
NEXT_ACTION
SALE_WON
SALE_LOST
```

Nunca afirmar:

```text
EMAIL_DELIVERED
```

si solamente existe:

```text
SMTP ACCEPTED
```

`ACCEPTED` significa aceptación por el servidor SMTP, no entrega final garantizada.

---

# 15. FASE 2D — RESPUESTAS

Conservar la fecha original.

Añadir:

```text
fecha_respuesta_iso
atendido_en
```

si no existen.

No destruir:

```text
fecha_respuesta
```

histórica.

Ampliar progresivamente clasificación.

No reclasificar destructivamente los valores históricos.

---

# 16. FASE 3 — TRACKING FIABLE

Objetivo:

> convertir el tracking actual en datos utilizables comercialmente.

---

## 16.1 APERTURAS

No borrar las aperturas existentes.

Conservar:

```text
raw events
```

y generar métricas deduplicadas.

Necesitamos distinguir:

```text
primera_apertura
ultima_apertura
num_aperturas
opened
```

por envío/lead.

Una apertura repetida no debe convertirse en múltiples leads abiertos.

---

# 17. FASE 3B — CLICS

Crear:

```text
clics
```

solo si no existe mecanismo equivalente.

Registrar como mínimo:

```text
id
envio_id
lead_id
campaign_id
tracking_id
url_original
tipo_cta
fecha
user_agent
ip
```

No almacenar información innecesaria.

Tipos iniciales:

```text
CTA_WEB
CTA_PRESUPUESTO
CTA_CONTACTO
```

---

## Regla de atribución

El click debe atribuirse mediante:

```text
tracking_id / envio_id / lead_id
```

No por:

```text
email
asunto
texto
```

---

# 18. FASE 4 — OPERATIVA COMERCIAL

Esta es una fase crítica porque la auditoría detectó:

```text
5 respuestas
0 presupuestos
0 mockups
```

El problema no es únicamente tecnológico.

El CRM debe reducir el tiempo entre:

```text
RESPUESTA
↓
CLASIFICACIÓN
↓
CUALIFICACIÓN
↓
MOCKUP
↓
PRESUPUESTO
```

---

# 19. FASE 4A — RESPUESTA → ACCIÓN

La ficha del lead debe permitir rápidamente:

```text
POSITIVE
INTERESADO
SOLICITA_INFO
SOLICITA_PRECIO
SOLICITA_MOCKUP
NO_INTERESADO
FUERA_DE_OFICINA
HARD_BOUNCE
OTRO
```

No construir todavía 40 estados comerciales.

La prioridad es velocidad operativa.

---

# 20. FASE 4B — PRESUPUESTO

Ampliar `presupuestos` de forma aditiva.

Mantener:

```text
pipeline_id
```

para compatibilidad.

Añadir si procede:

```text
campaign_id
opportunity_id
respuesta_origen_id
envio_origen_id
fecha_creacion
fecha_envio
fecha_aprobacion
fecha_rechazo
notas
```

No romper:

```text
api/presupuestos.php
```

---

# 21. FASE 4C — MOCKUPS

Mantener estructura existente.

Añadir solamente lo necesario:

```text
opportunity_id
campaign_id
respuesta_origen_id
envio_origen_id
version
fecha_envio
notas
```

No rehacer el módulo.

---

# 22. FASE 5 — CHECKPOINT PRE-LOTE

Esta fase debe existir antes de escalar.

Crear un mecanismo que audite automáticamente cada lote.

Antes de permitir envío:

```text
CHECKPOINT
```

debe comprobar:

### Leads

* email válido.
* no duplicado.
* no TEST.
* no hard bounce.
* no baja.
* no bloqueado.

### Campaña

* campaign_id válido.
* campaña correcta.
* plantilla válida.
* variante válida.

### SMTP

* cuenta activa.
* límites razonables.
* configuración válida.

### Trazabilidad

* lead_id.
* campaign_id.
* variant.
* batch_id.

### TEST/REAL

* entorno coherente.

### Duplicados

No enviar dos veces accidentalmente al mismo lead en el mismo lote.

---

# 23. RESULTADO DEL CHECKPOINT

Debe devolver claramente:

```text
READY TO SEND
```

o:

```text
BLOCKED
```

Nunca:

```text
WARNING
```

como sustituto de una decisión.

Los warnings pueden existir dentro del informe, pero debe existir una decisión final inequívoca.

---

# 24. FASE 6 — PRIMER LOTE REAL CONTROLADO

Solo después de:

```text
FASE 1 PASS
FASE 2 PASS
FASE 3 PASS
FASE 4 PASS
FASE 5 PASS
```

se podrá considerar un lote REAL.

## Tamaño recomendado

Primer lote:

```text
200–300 leads
```

No empezar directamente con 1.000.

---

# 25. REGLA DEL LOTE

Cada lote debe tener:

```text
campaign_batch_id
```

y registrar:

```text
fecha
cantidad
segmento
plantilla
variante
SMTP
resultado
```

Debe poder reconstruirse:

> quién recibió qué, cuándo, desde qué cuenta y con qué variante.

---

# 26. REGLA DE UNA VARIABLE

Durante los primeros lotes:

> no modificar simultáneamente demasiadas variables.

Controlar:

* asunto.
* plantilla.
* variante.
* horario.
* distribución SMTP.
* segmento.

Si se cambia una variable importante, documentarlo.

---

# 27. CRITERIOS DE ESCALADO

No escalar simplemente porque:

```text
SMTP ACCEPTED = 100 %
```

Evaluar:

* hard bounces.
* respuestas.
* bajas.
* problemas SMTP.
* aperturas deduplicadas.
* clicks.
* respuestas positivas.
* velocidad de atención.
* presupuestos generados.

Secuencia recomendada:

```text
200–300
   ↓
500
   ↓
1.000
   ↓
escalado progresivo
```

Solo avanzar si el lote anterior tiene PASS.

---

# 28. ANALÍTICA

La analítica debe distinguir:

### Entregabilidad

```text
SMTP ACCEPTED
HARD BOUNCE
```

### Engagement

```text
OPENED
CLICKED
```

### Respuesta

```text
REPLIED
POSITIVE
```

### Comercial

```text
QUALIFIED
QUOTE
MOCKUP
NEGOTIATION
WON
LOST
```

Nunca mezclar estos conceptos.

---

# 29. A/B/C — REGLAS ESTADÍSTICAS

El histórico de los 348 primeros leads NO se considera experimento limpio.

Motivo:

```text
Math.random()
```

y rotaciones.

Para nuevos lotes:

```text
variant_original
```

debe identificar la variante inicial.

Las rotaciones no deben contaminar el análisis del primer contacto.

Mostrar siempre:

```text
N
```

junto a porcentajes.

Nunca afirmar causalidad con muestras insuficientes.

---

# 30. SEGURIDAD Y ROLLBACK

Antes de cada migración:

```text
BACKUP
```

Después:

```text
integrity_check
```

Debe existir procedimiento de rollback.

Nunca borrar el backup anterior inmediatamente.

---

# 31. FOREIGN KEYS

No activar:

```text
PRAGMA foreign_keys = ON
```

globalmente durante esta intervención sin antes:

1. auditar referencias huérfanas.
2. identificar registros históricos incompatibles.
3. documentar impacto.
4. preparar saneamiento.

La existencia de referencias históricas a plantillas inexistentes demuestra que activar FK ahora podría producir efectos secundarios.

Tratarlo como deuda técnica separada.

---

# 32. REGLAS DE COMPATIBILIDAD

Antes de modificar una tabla utilizada por PHP:

buscar todas las referencias:

```text
SELECT
INSERT
UPDATE
JOIN
prepare()
```

y localizar:

* APIs.
* formularios.
* AJAX.
* JS.
* informes.
* exports.
* dashboards.

No asumir que una columna es utilizada solamente donde aparece su nombre principal.

---

# 33. TEST MATRIX OBLIGATORIA

Crear tests automatizables o reproducibles para:

```text
TEST 01 — DB integrity
TEST 02 — apertura dedup
TEST 03 — tracking
TEST 04 — click attribution
TEST 05 — follow-up traceability
TEST 06 — campaign attribution
TEST 07 — MIME UTF-8
TEST 08 — RFC 2047
TEST 09 — SMTP error handling
TEST 10 — hard bounce suppression
TEST 11 — TEST/REAL isolation
TEST 12 — deterministic A/B/C
TEST 13 — batch checkpoint
TEST 14 — backup + migration integrity
```

Cada test debe devolver:

```text
PASS
```

o:

```text
FAIL
```

con evidencia.

---

# 34. PROHIBICIONES

Está prohibido:

* enviar REAL durante desarrollo.
* saltarse TEST.
* borrar histórico.
* resetear estadísticas.
* cambiar datos históricos para "hacer cuadrar" métricas.
* atribuir campañas por asunto.
* utilizar `Math.random()` para A/B/C.
* considerar ACCEPTED como DELIVERED.
* borrar aperturas duplicadas.
* activar FK sin auditoría.
* reemplazar el CRM por otro sistema.
* introducir frameworks innecesarios.
* crear tablas duplicadas que ya tengan equivalente funcional.
* hacer un deploy completo cuando basta una modificación puntual.
* continuar automáticamente después de un FAIL.

---

# 35. PROTOCOLO DE PARADA

Al finalizar cada fase:

## Si PASS

mostrar:

```text
FASE X — PASS
```

seguido de:

* archivos modificados.
* tablas modificadas.
* SQL ejecutado.
* tests ejecutados.
* resultados.
* backup.
* riesgos residuales.
* siguiente fase propuesta.

Después:

> **DETENERSE Y ESPERAR AUTORIZACIÓN.**

---

## Si FAIL

mostrar:

```text
FASE X — FAIL
```

y:

* causa.
* archivo.
* línea si procede.
* SQL afectado.
* impacto.
* rollback realizado/no realizado.
* solución propuesta.

No continuar.

---

# 36. FORMATO OBLIGATORIO DE CADA ENTREGA

Cada fase debe terminar exactamente con esta estructura:

```text
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FASE X — RESULTADO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ESTADO:
PASS / FAIL

CAMBIOS:
- ...

ARCHIVOS:
- ...

BASE DE DATOS:
- ...

BACKUP:
- ...

TESTS:
- TEST XX — PASS
- TEST XX — PASS

RIESGOS RESIDUALES:
- ...

ROLLBACK:
- ...

IMPACTO EN PRODUCCIÓN:
NINGUNO / DETALLAR

ENVÍOS REALIZADOS:
0

SIGUIENTE FASE:
...

AUTORIZACIÓN REQUERIDA:
SÍ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

# 37. OBJETIVO FINAL

Al completar las fases necesarias, el CRM debe permitir:

```text
7.000 LEADS RESTANTES
        ↓
SEGMENTACIÓN
        ↓
CHECKPOINT
        ↓
LOTE 200–300
        ↓
ENVÍO TRAZABLE
        ↓
OPEN / CLICK / REPLY
        ↓
CLASIFICACIÓN
        ↓
CUALIFICACIÓN
        ↓
MOCKUP / PRESUPUESTO
        ↓
OPORTUNIDAD
        ↓
SEGUIMIENTO
        ↓
VENTA
```

Cada paso debe ser reconstruible desde el CRM.

---

# 38. PRINCIPIO COMERCIAL

El objetivo de esta evolución no es producir más tablas ni más dashboards.

El objetivo es:

> **convertir cada respuesta comercial en una acción rápida y medible.**

Especialmente:

```text
RESPUESTA POSITIVA
        ↓
ATENCIÓN INMEDIATA
        ↓
CUALIFICACIÓN
        ↓
PRESUPUESTO / MOCKUP
```

El cuello de botella actual no debe quedar oculto detrás de nueva instrumentación.

---

# 39. DEFINICIÓN DE ÉXITO

La implementación será considerada exitosa cuando:

1. No se reenvíen hard bounces.
2. El `From` sea MIME/RFC válido.
3. A/B/C sea determinista.
4. Todos los nuevos envíos sean trazables.
5. Los follow-ups no sean huérfanos.
6. TEST y REAL estén aislados.
7. Las aperturas puedan analizarse sin inflación.
8. Los clicks puedan atribuirse.
9. Las respuestas puedan convertirse rápidamente en acciones.
10. Los presupuestos/mockups queden vinculados a la oportunidad.
11. Cada lote tenga checkpoint.
12. Sea posible detener el sistema inmediatamente.
13. Sea posible reconstruir qué ocurrió con cada lead.
14. El sistema pueda escalar progresivamente hacia los ~7.000 leads restantes sin rehacer el CRM.

---

# 40. ORDEN DEFINITIVO

Ejecutar exclusivamente en este orden:

```text
FASE 0
SNAPSHOT + AUDITORÍA
        ↓
AUTORIZACIÓN
        ↓
FASE 1
BLOQUEANTES
        ↓
AUTORIZACIÓN
        ↓
FASE 2
TRAZABILIDAD
        ↓
AUTORIZACIÓN
        ↓
FASE 3
TRACKING
        ↓
AUTORIZACIÓN
        ↓
FASE 4
OPERATIVA COMERCIAL
        ↓
AUTORIZACIÓN
        ↓
FASE 5
CHECKPOINT
        ↓
AUTORIZACIÓN
        ↓
FASE 6
LOTE REAL 200–300
        ↓
ANÁLISIS
        ↓
AUTORIZACIÓN
        ↓
FASE 7
ESCALADO PROGRESIVO
```

---

# 41. REGLA FINAL Y MÁS IMPORTANTE

**No confundas completar el megaprompt con completar el proyecto.**

El proyecto real es conseguir clientes.

Por tanto:

> Si una modificación técnica no mejora significativamente la seguridad, trazabilidad, entregabilidad, capacidad de seguimiento o conversión comercial durante la campaña actual, debe posponerse.

Primero:

```text
ENVIAR BIEN
```

Después:

```text
MEDIR BIEN
```

Después:

```text
RESPONDER RÁPIDO
```

Después:

```text
CONVERTIR
```

Y solamente después:

```text
ESCALAR
```

**NO REHACER.
NO SOBREDISEÑAR.
NO PERDER EL HISTÓRICO.
NO ENVIAR SIN GATES.
NO AVANZAR SIN PASS.**

El CRM existente es la base.

La V2 es una evolución quirúrgica para terminar la campaña con seguridad y convertir el sistema actual en una máquina comercial progresivamente medible.

---

*Fin del documento original aportado por el usuario · 29/08/2026.*
