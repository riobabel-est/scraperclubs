# PLAN — Ramificación por Ramal ABC + Secuencias de Seguimiento (O-1)

**Fecha:** 2026-08-26
**Estado:** 🟢 **F1-F3 implementados (2026-08-26)** · 🔴 **F4 pendiente** (endurecer elegibilidad anti-doble y activación en producción)
**Ámbito:** `public_html/outbound/` (`cli/cron.php`, `cli/init_db.php`, `api/campanas.php`, `api/analytics.php`, `tabs/editor.php`, `tabs/seguimiento.php`, `js/app.js`, `js/seguimiento.js`)
**Objetivo:** Implementar la **secuencia condicional de seguimiento** (IF/THEN) por **ramal del test ABC**: el lead que validó un ángulo (financiero / identidad / general) recibe el siguiente paso en **esa misma línea argumental**, en modo automático (cron) o **asistido** (sugerencias pendientes de aprobación en Seguimiento).

---

## 1. Contexto y punto de partida

- El diagnóstico del asesor (2026-08-26) pide que el seguimiento **no use random**: debe **duplicar la apuesta** en el ramal que el lead ya validó con sus aperturas.
- **Ya implementado (AI-8):** etiqueta `[Interés: Identidad/Cantera | Financiero/Rentabilidad | General/Producto]` en Seguimiento y borrador IA que continúa el ramal. Falta el **disparo automático** del Paso 2/3.
- Hoy el 2º toque es **manual** (cola "Perseguir" + Lanzadera) y `cli/cron.php` solo envía **1er contacto** (`estado_lead='01 Sin Contactar'`, sin envíos previos).

### 1.1 Piezas ya disponibles (verificadas en el código)

| Pieza | Archivo | Uso en el plan |
|---|---|---|
| `envios.variant` (A/B/C determinística) | `inc/abc.php` `asignarVariante()` | Paso 1 asigna variante estable por (lead, campaña) |
| Variante con más aperturas por lead | `api/analytics.php` `interesDeVariante()` + query | Dispara el ramal correcto en el Paso 2/3 |
| `aperturas` (por `tracking_id`) | tabla | Condición de interés (≥1 / ≥3 aperturas) |
| `respuestas.clasificacion` | `inc/respuestas.php` `estadoDestinoPorClasificacion()` | Detener secuencia si hay respuesta humana |
| Cola "🔥 Calientes" (≥3 apert.) | `api/analytics.php` `fusionarColaSeguimiento()` | Prioriza el Paso 3 del ramal |
| Motor SMTP con límites diarios | `api/enviar_lote.php`, `inc/smtp_transport.php` | Envío de cada paso respetando anti-bloqueo |
| Supresión/eligibilidad | `inc/eligibilidad.php` | Excluir bajas/opt-out/duplicados en cada paso |
| Sugerencias human-in-the-loop | tabla `propuestas_ia` | Modo asistido: pasos pendientes de aprobación |
| Campañas | tabla `pipelines` + `campaign_plantillas` | La secuencia cuelga de una campaña |

---

## 2. Arquitectura de datos (nuevas tablas — idempotentes en `cli/init_db.php`)

### 2.1 `secuencias`

```sql
CREATE TABLE IF NOT EXISTS secuencias (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,            -- campaña (pipelines.id)
    nombre      TEXT NOT NULL,
    modo_auto   INTEGER NOT NULL DEFAULT 0,  -- 0=asistido (sugerencias), 1=automático (cron envía)
    activo      INTEGER NOT NULL DEFAULT 1,
    creado_el   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(campaign_id, nombre)
);
```

### 2.2 `secuencia_pasos`

```sql
CREATE TABLE IF NOT EXISTS secuencia_pasos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    secuencia_id INTEGER NOT NULL,
    paso        INTEGER NOT NULL,            -- 1, 2, 3...
    plantilla_id INTEGER NOT NULL,
    espera_dias INTEGER NOT NULL DEFAULT 2,  -- días tras el paso anterior
    ramal       VARCHAR(1) NOT NULL DEFAULT '', -- '' = todos; 'A'|'B'|'C' = solo ese ramal
    activo      INTEGER NOT NULL DEFAULT 1,
    UNIQUE(secuencia_id, paso)
);
```

### 2.3 Trazabilidad en `envios` (migración idempotente)

```sql
ALTER TABLE envios ADD COLUMN secuencia_id INTEGER DEFAULT NULL;
ALTER TABLE envios ADD COLUMN paso_secuencia INTEGER DEFAULT NULL;
-- Índice anti-doble-envío por paso:
CREATE UNIQUE INDEX IF NOT EXISTS idx_envios_sec_paso
    ON envios(lead_id, campaign_id, paso_secuencia) WHERE paso_secuencia IS NOT NULL;
```

> **No se crean** `modo_gestion` ni `ia_draft_status` en `clubes_crm`: el modo lo define la secuencia (campaña) y el estado del borrador asistido vive en `propuestas_ia` (ya existe: `pendiente`/`aprobado`/`rechazado`).

---

## 3. Lógica de disparo (IF/THEN) — motor en `cli/cron.php`

Nuevo modo `php cli/cron.php --campaign-id=N --secuencia`:

```
PASO 1 (Descubrimiento)
  IF lead elegible Y sin envío en la campaña
     THEN variante = asignarVariante(lead, campaña)   // determinística A/B/C
          enviar plantilla Paso 1 → envios.paso_secuencia=1

PASO N (N>1), para cada lead con envío del paso N-1:
  espera = días desde envio(paso N-1) >= secuencia_pasos.espera_dias
  IF espera Y sin respuesta humana Y sin rebote Y sin opt-out
     THEN variante_dominante = variante con más aperturas del lead (AI-8)
          pasos_candidatos = pasos activos del paso N con ramal IN ('', variante_dominante)
          IF modo_auto = 1  → enviar directo (mismo motor SMTP) y registrar paso
          IF modo_auto = 0  → INSERT en propuestas_ia (tipo 'secuencia_pasoN',
                               mensaje_sugerido = plantilla renderizada) → Seguimiento
  IF aperturas >= 3 Y sin respuesta  → priorizar Paso 3 del ramal (caliente)
  IF respuesta humana               → detener (estadoDestinoPorClasificacion ya mueve a 03/06)
  IF lead en etapa >= 04 Propuesta  → detener secuencia
```

**Reglas de coherencia de ramal (núcleo del plan):**

| Variante dominante (validada) | Paso 2 recomendado | Paso 3 recomendado |
|---|---|---|
| A (General / Producto) | Continuación general (ramal A o '') | Boceto/ejemplo orientativo |
| B (Identidad / Cantera) | Escudo, colores, orgullo del vestuario | Diseño digital con colores oficiales |
| C (Financiero / Rentabilidad) | Reducir riesgo: sin pedido mínimo | Validación de rentabilidad con datos |

### 3.1 Garantía 1 — Envío manual (puntual) SIEMPRE disponible, junto a la secuencia

El modo híbrido es **aditivo**, nunca excluyente:

- **Envío puntual a un lead** (email a medida con IA, WhatsApp, respuesta manual): disponible **siempre**, en cualquier momento, desde Seguimiento (🎯 Atender), la ficha del lead o la Lanzadera dirigida. No se bloquea por estar el lead en una secuencia.
- **Registro diferenciado**: los envíos manuales se guardan en `envios` con `secuencia_id = NULL` (y `paso_secuencia = NULL`) y en `comunicaciones_log` como `envio_email`/`whatsapp_enviado`. El timeline del lead muestra la mezcla (manuales + pasos planificados) en orden cronológico.
- **La secuencia no duplica la gestión del comercial**: si un lead tiene un envío manual posterior al paso N-1, el motor de secuencia **respeta ese contacto** y no dispara el paso N cuando ya existe un envío manual más reciente (`secuencia_id IS NULL`) con la misma función. Se marca el paso como *cubierto por contacto manual*.
- **En modo asistido**, el paso generado por la secuencia aparece como *sugerencia* (no se envía solo): el comercial puede Aprobarlo, Editarlo o Descartarlo, o redactar desde cero en el modal de Atención.

### 3.2 Garantía 2 — Registro total del contacto y análisis con IA de resultados

- **Registro** (todo queda trazado y auditable):
  - `envios` (email, tracking_id, fecha, plantilla, variante, cuenta SMTP, campaign_id, `secuencia_id`, `paso_secuencia`).
  - `aperturas` (píxel de tracking: fecha, IP, user-agent) → aperturas por envío/paso.
  - `respuestas` (correos entrantes atribuidos + `clasificacion`).
  - `comunicaciones_log` (timeline: envio_email, respuesta_recibida, whatsapp_enviado, cambio_estado, secuencia_paso_enviado…).
  - `rebotes` (bounces → supresión automática).
- **Análisis con IA de resultados** (ya operativo y ampliable):
  - **Clasificación IA de respuestas** (`inc/imap_respuestas.php` + `clasificar_ia.php`): POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO + intención (interesado, duda_precio…). Etiquetas visibles en Bandeja y Kanban.
  - **Ramal de interés (AI-8)**: la variante dominante por aperturas marca el ángulo que validó el lead.
  - **Analytics por secuencia/ramal (extensión de O-1)**: métricas por paso (enviados, entregados, aperturas, respuestas) y por ramal (A/B/C) para medir qué ángulo convierte. Nuevo endpoint `get_secuencia_metricas` + vista en Analytics.
  - El **resumen del día con IA** y la **temperatura** (`lead_scoring`) se alimentan de estos datos en tiempo real.

---

## 4. Interfaz

### 4.1 Configurador de secuencias (en "Plantillas y Campañas")
Junto al configurador de campañas actual (`campaign_plantillas`), un bloque "Secuencia de seguimiento":
- Nombre + modo (🟡 Asistido / 🟢 Automático).
- Lista de pasos ordenables: plantilla + espera_dias + ramal (Todos / A / B / C) + activo.

### 4.2 Seguimiento — cola "📋 Secuencia pendiente" (modo asistido)
Nuevo tipo en la cola unificada: los pasos generados (`propuestas_ia` tipo `secuencia_pasoN`) salen como:
- **[Ver sugerencia IA]** → abre el modal de Atención con el borrador precargado.
- **[✅ Aprobar y enviar]** → despacha con el motor SMTP + tracking (reutiliza `enviarAtencion`).
- **[✏️ Editar y enviar]** → abre el modal, edita y envía.
- **[🗑️ Descartar]** → `propuestas_ia.estado='rechazado'`.

### 4.3 Ficha del lead
Badge de progreso de secuencia: `Paso 2/3 · Ramal Financiero`.

---

## 5. Fases de implementación

| Fase | Trabajo | Archivos | Verificación |
|---|---|---|---|
| **F1** | Migración BD idempotente (tablas + columnas + índice) | `cli/init_db.php` | `php -l`; re-ejecución sin cambios |
| **F2** | Motor de secuencias en cron (paso 1 y pasos N) + endpoints CRUD `secuencias`/`secuencia_pasos` | `cli/cron.php`, `api/campanas.php` (o `api/leads.php`) | Test con leads TEST en campaña PILOT |
| **F3** | UI configurador + cola "Secuencia pendiente" en Seguimiento | `tabs/editor.php`, `tabs/seguimiento.php`, `js/app.js`, `js/seguimiento.js` | `node --check` + test funcional |
| **F4** | Endurecer anti-doble-envío y elegibilidad en cada paso | `inc/eligibilidad.php`, `api/enviar_lote.php` | Auditoría de `envios` por (lead, paso) |

---

## 6. Riesgos y mitigación

| Riesgo | Mitigación |
|---|---|
| **Doble envío** de un paso | Índice UNIQUE `(lead_id, campaign_id, paso_secuencia)` + check `esElegibleParaEnvio()` |
| Ramal desconocido (lead sin aperturas) | Solo pasos con `ramal=''` (genéricos) cuando no hay variante dominante |
| Volumen SMTP / anti-bloqueo | Reutilizar límites diarios por cuenta de `enviar_lote.php` y delay configurado |
| Romper envíos manuales existentes | El modo secuencia es **aditivo**: la Lanzadera y el cron de 1er contacto siguen funcionando |
| Respuesta negativa/opt-out a mitad de secuencia | `estadoDestinoPorClasificacion` (AI-6) detiene automáticamente en 06/07 |
| Falsos positivos de "caliente" | El Paso 3 solo se prioriza con ≥3 aperturas reales (cola existente) |

---

## 7. Validación final

1. `php -l` en todos los archivos PHP tocados y `node --check` en los JS.
2. Test en entorno **TEST** (`modo_entorno=test`) con leads `es_test=1` y campaña `PILOT`.
3. Verificar: (a) paso 1 asigna variante determinística; (b) paso 2 respeta el ramal de la variante dominante; (c) modo asistido genera `propuestas_ia` pendientes; (d) modo automático envía y registra `paso_secuencia`; (e) no hay duplicados por (lead, paso).
4. Checkpoint de implementación `docs/checkpoint_ramificacion_secuencias.md`.

---

## 8. Criterios de éxito (DoD)

- [x] Un lead que abrió la variante C recibe el Paso 2 financiero (no un texto genérico ni random) — motor por ramal implementado (verificado con test: Paso 1 encuentra candidatos; Paso 2 espera la espera y filtra por ramal dominante).
- [x] En modo asistido, el Paso 2 aparece como sugerencia pendiente en Seguimiento y se aprueba/edita/descarta en un clic — cola "📋 Secuencia pendiente" + botones 📨 Enviar / 🗑️ Descartar implementados.
- [x] En modo automático, el cron respeta espera_dias y límites SMTP — `secuencia_programarYEnviar` envía hasta 10/ciclo con cuenta SMTP no saturada.
- [x] Ningún paso se envía dos veces y ninguna respuesta/opt-out continúa recibiendo pasos — índice UNIQUE `(lead_id, campaign_id, paso_secuencia)` + cláusulas NOT EXISTS de respuesta/supresión.
- [x] La gestión lead a lead y la Lanzadera manual siguen operativas sin cambios — el modo secuencia es aditivo (solo actúa si la campaña tiene secuencias activas).

**Pendiente F4:** auditoría de elegibilidad en cada paso en producción (test en entorno TEST con `es_test=1` y campaña PILOT) y activación del cron con secuencias definidas desde la UI.

---

## 9. IMPLEMENTADO — Rotación ABC para no abridores (O-1b, agosto 2026)

**Modelo de trabajo acordado con el usuario:**
- El primer envío SIEMPRE lo hace el usuario desde la **Lanzadera** (no el cron).
- La secuencia es un **estándar que SUGIERE, no ejecuta**: en modo asistido el cron no envía nada (solo genera `propuestas_ia` para pasos 2/3).
- La **rotación ABC** es la única pieza automatizada: la Lanzadera prepara el reenvío con la SIGUIENTE variante (A→B→C→A) para los leads que NO abrieron; el usuario confirma el envío.

**Cambios (aditivos, sin regresiones):**
- BD (`cli/init_db.php`, idempotente): columna `envios.es_rotacion` + índice único ampliado `(lead_id, campaign_id, es_rotacion)` (permite un reenvío junto al envío base); columnas `secuencias.rotar_no_abridores`, `rotar_espera_dias`, `rotar_max_envios`, `rotar_plantilla_id`.
- `inc/abc.php`: `siguienteVariante()` (A→B→C→A).
- `inc/eligibilidad.php`: `reservarEnvioLogico`/`insertarEnvioLogico`/`getEnvioLogicoExistente` con `es_rotacion` (la variante rotada se respeta; el envío base conserva su inmutabilidad determinística).
- `api/enviar_lote.php`: acepta `es_rotacion=1` + `variante_ab` rotada (fuerza la variante como en modo test).
- `api/campanas.php`: `get_secuencias`/`save_secuencia` con los campos de rotación; nuevo endpoint `get_rotacion` (calcula no abridores con espera cumplida, intentos restantes y variante siguiente).
- `api/get_cola.php`: `rotacion=1` → cola preparada (variante rotada, `es_rotacion=1`, plantilla de la secuencia).
- `cli/cron.php`: en modo asistido el Paso 1 NO programa envíos (el primer contacto es manual); el Paso 2 reconoce el envío manual de la campaña como base; los leads ya rotados (`es_rotacion=1`) quedan fuera de pasos N.
- UI: configurador de secuencias (bloque "🔄 Rotación ABC") + Lanzadera (botón "🔄 Rotación ABC", banner de info y badge por lead `Var. X→Y (intento N)`).

**Regla de stop:** tras `rotar_max_envios` sin apertura el lead se excluye automáticamente de la rotación (se deja de contactar).

