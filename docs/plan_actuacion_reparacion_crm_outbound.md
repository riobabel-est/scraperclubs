# Plan de Actuación Definitivo — CRM Outbound FutProtec
## Reparación, medición A/B/C y preparación para producción

**Versión:** 3.0 — integra el Megapalán Potenciado v2 del cliente + auditoría técnica + plan del agente
**Fecha:** 14/08/2026
**Alcance:** `public_html/outbound/` (PHP 8 nativo + SQLite + JS vanilla, SiteGround compatible)
**Naturaleza:** Hoja de ruta operativa. **No se ejecuta ningún cambio** hasta aprobación expresa.

---

# PARTE 1 — VALIDACIÓN DEL MEGAPALÁN POTENCIADO (v2)

## 1.1 Conformidad con la auditoría técnica

El Megapalán v2 es **coherente al 100% con los hallazgos de la auditoría** (`docs/informe_auditoria_tecnica_crm_outbound.md`). Confirma las mismas conclusiones críticas:

1. La variante no viaja en `envios`/`aperturas` → trazabilidad rota.
2. El dashboard A/B/C lee de `lead_pipelines` (desconectada) en vez de los envíos reales.
3. La lanzadera sortea variante en el navegador ignorando `test_ab`.
4. Rebotes nunca se registran.
5. No hay click tracking ni respuestas automáticas.
6. Bajas no bloquean globalmente.

**Se acepta íntegramente el principio rector:** *"Primero que el sistema no pueda mentirnos sobre el experimento. Después automatización."*

## 1.2 Correcciones del Megapalán que se aceptan (mejoran mi plan original)

| # | Corrección | Aceptada | Impacto en el plan |
|---|---|---|---|
| 5.1 | Click tracking NO bloqueante (post-core) | ✅ | Se desplaza a fase tardía. |
| 5.2 | IMAP/webhook NO bloqueante (post-piloto) | ✅ | Respuesta manual primero. |
| 5.3 | `250 OK` ≠ entregado; no llamar "entregado" | ✅ | Renombrar métrica a "Aceptados SMTP". |
| 5.4 | Supresión sube de prioridad (antes de envío real) | ✅ | Supresión → fase crítica temprana. |
| 5.5 | Mover contraseña a SQLite **no** es mejora de seguridad | ✅ | No migrar secretos prematuramente. |
| 5.6 | `lead_pipelines` NO como 2ª fuente de verdad | ✅ | Variante definitiva vive en `envios`. |

Las correcciones 5.1, 5.2, 5.4 y 5.5 **modifican el orden y el alcance** de mi plan anterior; se incorporan en la Parte 2.

## 1.3 Matices y mejoras adicionales que propongo

Estas matizaciones **refuerzan** el Megapalán sin alterar su dirección:

1. **KPI "Positive Reply Rate" necesita definición operativa explícita.**
   El Megapalán la define bien (`respuestas positivas / aceptados SMTP`), pero conviene fijar qué es "respuesta positiva". Recomiendo que sea un **campo explícito `clasificacion`** en la tabla `respuestas` (valores: `positiva/negativa/neutra/fuera_oficina/baja`), **no** derivado del estado Kanban (`estado_lead ≥ 04`), porque hoy "positivo" se mezcla con el estado comercial y no es auditable. Con campo explícito, el KPI queda cerrado por fórmula exacta.

2. **Umbral de "variante ganadora" demasiado bajo en el código actual.**
   Hoy `dashboard.php` declara ganadora con `leads >= 5` por variante. Eso es estadísticamente inválido. Se propone: mostrar el tamaño muestral (`n_A/n_B/n_C`) junto a cada porcentaje, y **no declarar ganadora** salvo muestra mínima por variante (p. ej. ≥30 en piloto descriptivo) y, en producción, delegar la inferencia a un análisis posterior. El CRM **no debe fabricar certeza** (coincide con Fase 16 del Megapalán).

3. **Atribución comercial no puede depender solo de `clubes_crm.estado_lead`.**
   Acertado en Fase 13. Se refuerza con: al mover un lead a `04…09` (respuesta/interés/propuesta/venta), registrar en `comunicaciones_log` el `campaign_id`/`pipeline_id` + `variante_ab` de la campaña que lo originó; y poblar `pipeline_id` en `mockups` y `presupuestos` (hoy existe la columna pero siempre va `NULL`). Así un lead multicampaña conserva histórico de resultados por campaña.

4. **Versionado de plantillas (Fase 29) con fuente de auditoría inmutable ya existente.**
   El sistema ya guarda `envios.asunto` y `envios.cuerpo_mensaje` completeos. Eso garantiza el contenido literal. El versionado se implementa como `plantillas.version` + `plantilla_id`/`plantilla_version` en `envios`, sin sobrescribir el cuerpo ya enviado. La inmutabilidad histórica (Fase 30) es obligatoria y se mantiene.

5. **Inventario de "puntos de envío" (Fase 7.0) debe hacerse en Fase 0, no después.**
   Confirmo los 3 mecanismos detectados: `enviar_lote.php` (lanzadera), `cron.php` (CLI cron), `enviar_smtp_random.php` (CLI legacy). El mapa "PUNTO DE ENVÍO → ¿activo? → ¿respeta campaña? → ¿respeta supresión? → ¿registra variante?" es correcto y debe cerrarse en Fase 0.

6. **Seguridad del click tracking (Fase 18/11): evitar open redirect.**
   Correcto. La URL destino debe resolverse por `link_id` autorizado, no por URL arbitraria en el query string.

---

# PARTE 2 — PLAN DE ACTUACIÓN DEFINITIVO (fases reordenadas)

> Orden consensuado (Megapalán Fase 38) con las correcciones 5.1/5.2/5.4/5.5 aplicadas.

## Resumen de fases y prioridad

| # | Fase | Prioridad | Bloqueante para piloto |
|---|---|---|---|
| 0 | Backup + inventario de puntos de envío | 🔴 Crítico | Sí |
| 1 | Modelo de campaña (`pipelines`) | 🔴 Crítico | Sí |
| 2 | Modelo de envío (`envios` + campaña/variante/plantilla) | 🔴 Crítico | Sí |
| 3 | Variante decidida en backend + equilibrada | 🔴 Crítico | Sí |
| 4 | Deduplicación `lead_id + campaign_id` | 🔴 Crítico | Sí |
| 5 | Supresión global + baja por token | 🔴 Crítico | Sí |
| 6 | SMTP: una sola política de límite + trazabilidad | 🔴 Crítico | Sí |
| 7 | Seguridad y secretos (sin migración prematura) | 🔴 Crítico | Sí |
| 8 | Tracking de apertura (semántica correcta) | 🟠 Importante | Parcial |
| 9 | Respuestas manuales estructuradas + clasificación | 🟠 Importante | Sí (base del KPI) |
| 10 | Rebotes inmediatos (SMTP) | 🟠 Importante | Sí (para "Aceptados SMTP") |
| 11 | Kanban y eventos | 🟠 Importante | Sí |
| 12 | Atribución comercial por campaña | 🟠 Importante | Sí |
| 13 | Propuestas (registro, sin PDF) | 🟠 Importante | Parcial |
| 14 | Analítica A/B/C sobre `envios` | 🟠 Importante | Sí |
| 15 | Calidad de datos (auditoría automática) | 🟡 Mejora | Posterior |
| 16 | Tests automáticos y funcionales | 🟡 Mejora | Posterior al core |
| 17 | Smoke test interno | 🟠 Importante | Sí |
| 18 | Piloto comercial (30/30/30) | — | — |
| 19 | Campaña principal (33/33/33) | — | — |
| 20 | Auditoría final | — | — |
| 21 | Mejoras post-piloto (click/IMAP/etc.) | 🟡 Post-core | No |

---

## Fase 0 — Backup + inventario (🔴)

### Objetivo
Proteger producción y eliminar canales de envío desconocidos.

### Acciones
- Backup de `public_html/outbound/data/stats.db` → `backups/stats_pre_actuacion_<ts>.db`.
- Backup de los archivos a modificar.
- Confirmar `motor_estado=pausado` y `modo_entorno=test` (actualmente `pausado` + `test`).

### Inventario de puntos de envío (obligatorio cerrar aquí)

| Punto | ¿Activo? | Respeta campaña | Respeta supresión | Registra variante |
|---|---|---|---|---|
| `api/enviar_lote.php` (lanzadera) | Sí (manual) | **No** (hoy) | **No** (hoy) | Sí (en log, no en envios) |
| `cli/cron.php` (cron) | Parado (`pausado`) | **No** | Parcial (solo "01 Sin Contactar") | **No** |
| `api/enviar_smtp_random.php` (legacy) | Manual | **No** | **No** | Sí (solo si `test_ab`) |

**Criterio de salida:** no existe canal activo que envíe fuera de las reglas de campaña/supresión/variante. Los canales legacy que no se corrijan **se deshabilitan para producción**.

---

## Fase 1 — Modelo de campaña (🔴)

### Objetivo
Tener una campaña real referenciable (ej. `OUTBOUND_CLUBES_2026_08`).

### Acciones
- Consolidar `pipelines` como campaña: `nombre`, `descripcion`, `fecha_inicio`, `fecha_fin`, `estado`, `tipo` (`experimento_ab`/`cold_email`), `activo`.
- `lead_pipelines` sigue existiendo para **participación/asignación previa**, pero **no** como fuente de resultados.
- **Criterio de salida:** un envío puede apuntar inequívocamente a una campaña.

---

## Fase 2 — Modelo de envío (🔴)

### Objetivo
`envios` como fuente primaria del experimento.

### Acciones (migración segura, sin borrar datos)
Añadir a `envios`:
- `lead_id` INTEGER (FK → `clubes_crm.id`)
- `campaign_id` / `pipeline_id` INTEGER (FK → `pipelines.id`)
- `variant` CHAR(1)
- `template_id` INTEGER (FK → `plantillas.id`)
- `template_version` INTEGER (opcional, si se implanta versionado)
- `smtp_id` INTEGER (opcional; evaluar si `cuenta_emision` ya basta sin joins ambiguos)

Índices sobre `lead_id`, `campaign_id`, `variant`.

> Evidencia: `envios` hoy solo tiene `club, email, federacion, cuenta_emision, fecha_envio, estado, tracking_id, asunto, cuerpo_mensaje`.

**Criterio:** un `envio_id` identifica club, lead, campaña, variante, plantilla, SMTP y tracking.

---

## Fase 3 — Variante decidida en backend (🔴)

### Objetivo
Eliminar la decisión de variante del navegador y garantizar contenido == variante registrada.

### Acciones
- **Quitar** el sorteo `Math.random()` de `js/app.js::iniciarMotor`.
- Mover la decisión al backend (`enviar_lote.php` o endpoint previo):
  - backend lee campaña → determina variante → obtiene plantilla → guarda envío → envía ese contenido exacto.
- Si la campaña no es A/B/C → mensaje único (variante `A` o `''`).
- Si es A/B/C → distribuir A/B/C.
- **Distribución:** round-robin controlado sobre la cola (≈33/33/33), no `Math.random()` puro. Guardar asignación en `envios.variant` y `comunicaciones_log.variante_ab`.
- **Inmutabilidad:** una vez enviado, `variant` no se modifica.

**Regla de oro (Megapalán):** debe ser imposible `CRM = C` y `EMAIL REAL = A`.

---

## Fase 4 — Deduplicación (🔴)

### Objetivo
Impedir doble envío al mismo club en la misma campaña.

### Acciones
- Antes de enviar: ¿`lead_id + campaign_id` ya tiene envío (`envios.estado='enviado'`)? → **BLOQUEAR**, salvo reenvío autorizado explícitamente.
- Garantizar unicidad lógica `lead_id + campaign_id`.

---

## Fase 5 — Supresión global (🔴, antes del piloto)

### Objetivo
Una baja es global y permanente hasta acción administrativa.

### Acciones
- Consolidar `lista_supresion` (o reutilizar estado de baja como fuente única): `email` normalizado UNIQUE, `fecha`, `origen`, `motivo`.
- Comprobar supresión **en profundidad** en: `get_cola.php`, `enviar_lote.php`, `cron.php`, `enviar_smtp_random.php` y en el alta/importación de leads.
- **Baja por token aleatorio por lead** (no `sha1(email+secreto)` como única protección, ni `email` en claro en la URL).
  - Ej.: `baja.php?token=<random>` resolviendo a lead por token almacenado.
- Si un mecanismo legacy no respeta supresión → deshabilitar para producción o corregir.

---

## Fase 6 — SMTP (🔴)

### Objetivo
Trazabilidad exacta y límites coherentes.

### Acciones
- **Una única política de límite diario.** Hoy coexisten `cuentas_smtp.enviados_hoy` (no reiniciado) y `COUNT(comunicaciones_log)` por fecha. Decidir **una** fuente (preferencia: conteo por fecha de envíos aceptados) y aplicarla en todos los motores.
- Registrar `smtp_id`, `smtp_email`, `timestamp`, `resultado`, `codigo_error`.
- **Legacy:** elegir un motor principal y evitar que dos mecanismos compitan. Los demás se deshabilitan o corrigen.
- **Retry:** máximo 1–2 reintentos solo en errores transitorios; nunca en rechazo permanente, baja o destinatario inválido.

---

## Fase 7 — Seguridad y secretos (🔴, matizada)

### Objetivo
Reducir exposición **sin romper** la autenticación SMTP.

### Acciones (orden correcto según Megapalán 5.5)
1. Localizar todas las copias de credenciales SMTP (BD + hardcodes en `enviar_smtp_random.php` e `init_db.php`).
2. Eliminar hardcodes duplicados **dejando la BD como única fuente** y preservando los valores de producción (no cambiar contraseñas).
3. `api/smtp.php::get_accounts` **no** debe devolver la contraseña en claro (hoy la expone). Enmascarar (`***`) salvo edición.
4. Verificar que las contraseñas no vayan a logs ni a JSON de respuesta.
5. **No** intentar una migración de secretos prematura ni cifrado casero: si el secreto ya está en SQLite en claro, la prioridad es eliminar duplicados y no exponerlo en API/logs.

> Regla estricta: preservar las claves `email/user/pass/nombre/smtp/puerto` de `$CUENTAS_SMTP` sin alterar sus valores de producción.

---

## Fase 8 — Tracking de apertura (🟠)

### Objetivo
Una apertura = "solicitud HTTP del píxel", no "persona leyó".

### Acciones
- Mantener píxel, pero registrar: `envio_id`, `tracking_id`, `timestamp`, `ip`, `user_agent`, `clasificacion`.
- `first_open` por envío; cargas posteriores se conservan como evento sin inflar el KPI.
- Clasificación opcional: `open_registered`, `open_automated`, `open_unknown`, `open_human_likely`.
- **No prometer apertura humana real.** No usar apertura como KPI principal.

---

## Fase 9 — Respuestas (🟠, núcleo del KPI)

### Objetivo
Registro manual estructurado de respuestas con clasificación y vínculo a campaña/variante.

### Acciones
- Tabla `respuestas`: `lead_id`, `campaign_id`, `envio_id`, `variant`, `fecha`, `asunto_original`, `cuerpo_texto`, `clasificacion`, `origen`.
- Clasificaciones mínimas: `positiva/negativa/neutra/fuera_oficina/baja` (opcional `interesado/no_interesado`).
- Registrar también en `comunicaciones_log` (`tipo_evento='respuesta'`).
- IMAP/webhook → **post-piloto** (no bloqueante).

---

## Fase 10 — Rebotes (🟠)

### Objetivo
Diferenciar "aceptado SMTP" de "entregado".

### Acciones
- Registrar rechazos inmediatos SMTP: `550/551/553` → hard; `450/451/452` → soft.
- Insertar en `rebotes` (+ `tipo`) y en `comunicaciones_log`.
- **Dashboard:** mientras no haya retorno de rebotes posteriores, mostrar **"Aceptados por SMTP"**, nunca "Entregados = enviados".

---

## Fase 11 — Kanban y eventos (🟠)

### Acciones
- Mantener columnas `01…09`.
- **Apertura NO mueve Kanban.** **Click NO mueve Kanban.**
- Respuesta positiva puede mover `02→03 / 03→04` por intervención comercial.
- Propuesta `05→06`; venta `07→08`.
- Todo movimiento comercial queda en `comunicaciones_log`.

---

## Fase 12 — Atribución comercial por campaña (🟠, refuerzo añadido)

### Objetivo
Poder responder "¿qué campaña/variante generó esta oportunidad/venta?" sin depender de `estado_lead` global.

### Acciones
- Al mover estado a `04…09`, registrar `campaign_id`/`pipeline_id` + `variant` en `comunicaciones_log`.
- Poblar `pipeline_id` en `mockups` y `presupuestos` (hoy siempre `NULL`).
- Un lead multicampaña conserva histórico de resultados por campaña.

---

## Fase 13 — Propuestas (🟠)

### Acciones
- Sin PDF. Registrar: `proposal_requested`, `proposal_created`, `volume`, `players`, `categories`, `price`, `margin`, `date`, `campaign_id`.
- `presupuestos` ya existe; solo poblar `pipeline_id`/`campaign_id` y versionar.

---

## Fase 14 — Analítica A/B/C (🟠)

### Objetivo
La comparativa sale de `envios`, no de `lead_pipelines`.

### Acciones
- Cambiar `dashboard.php::get_analytics` para leer `envios.variant`.
- Métricas por variante:
  - Enviados, Aceptados SMTP, Errores, Open registrado, Clicks, Respuestas, Respuestas positivas, Propuestas, Oportunidades, Ganados.
- **Métrica principal:** `Positive Reply Rate = respuestas positivas / aceptados SMTP`.
  - "respuesta positiva" se define por `respuestas.clasificacion` explícito, no por `estado_lead`.
- Secundarias: Reply Rate, Proposal Rate, Opportunity Rate, Win Rate.
- Mostrar **tamaño muestral (`n`)** junto a porcentajes; no declarar ganadora con muestras pequeñas.

---

## Fase 15 — Calidad de datos (🟡)

### Acciones
Auditoría automática de integridad:
- No: envío sin lead/campaña/variante/tracking (salvo legacy documentado).
- Variante ∈ {A,B,C}; SMTP existe; no envío posterior a baja; no doble envío campaña/lead; contenido == variante; histórico inmutable.

---

## Fase 16 — Tests automáticos (🟡)

Batería reproducible (coincide con Megapalán Fase 18):
- TEST A/B/C: backend asigna y contenido coincide.
- TEST SMTP, TEST TRACKING (tracking→envío→campaña→variante), TEST DUPLICADO (BLOCK), TEST BAJA (BLOCK), TEST LEGACY, TEST KANBAN, TEST RESPONSE.

---

## Fase 17 — Smoke test (🟠)

- 10–30 emails internos a buzones controlados.
- Verificar contenido, variante, asunto, SMTP, tracking, logs, dashboard, baja, duplicados.
- **Criterio absoluto:** `CRM dice X == buzón recibe X`.

---

## Fase 18 — Piloto comercial

- `A=30, B=30, C=30` (total 90). No es muestra estadística definitiva; valida operación/copy/respuestas/tracking/SMTP/workflow.

## Fase 19 — Campaña principal

- ≈33/33/33. No cambiar asunto/cuerpo/reglas/KPI durante el experimento sin registrar un nuevo experimento.

## Fase 20 — Auditoría final
## Fase 21 — Mejoras post-piloto (click/IMAP/estadística avanzada) — registro en `FUTURE_IMPROVEMENTS.md`

---

# PARTE 3 — CRITERIOS DE DECISIÓN

## 3.1 KPI del experimento

**Principal:** Positive Reply Rate = `respuestas positivas ÷ aceptados SMTP`.

**Orden de importancia:**
1. Respuesta positiva (`respuestas.clasificacion`).
2. Propuesta solicitada.
3. Oportunidad cualificada.
4. Venta.
5. Facturación/margen.

No usar como KPI principal: aperturas, clicks, visitas web.

## 3.2 Bloqueantes para piloto (🔴)

- variante real ≠ variante registrada
- campaña no vinculada al envío
- baja no bloquea
- duplicado posible
- dashboard usa datos de prueba (`lead_pipelines`)
- SMTP no trazable
- contenido enviado no recuperable
- canal legacy puede enviar fuera de reglas
- backup no verificable
- tests de seguridad fallidos

## 3.3 No bloqueantes (🟡)

- click tracking inexistente
- IMAP inexistente
- clasificación automática de respuestas
- detección perfecta de Apple MPP
- estadística avanzada / IA comercial

(siempre que el tracking básico funcione, las respuestas puedan registrarse manualmente y la trazabilidad principal sea fiable).

---

# PARTE 4 — READY FOR PILOT / PRODUCTION

## 4.1 Ready for pilot
Backup, campaña, `lead_id`, `campaign_id`, `variant`, `template/version`, SMTP, tracking, backend controla A/B/C, distribución equilibrada, contenido coincide, deduplicación, supresión global, legacy controlado, métricas reales, dashboard real, smoke test, sin errores críticos.

## 4.2 Ready for production
Piloto revisado, SMTP estable, bajas verificadas, duplicados verificados, métricas verificadas, atribución comercial verificada, backup actualizado, rollback disponible, sin FAIL bloqueantes.

---

# PARTE 5 — REGLAS TRANSVERSALES

- **Inmutabilidad histórica:** no modificar variante/asunto/cuerpo/campaña/timestamp/SMTP tras el envío. Si hay error → nueva versión/campaña.
- **Versionado de plantillas:** `A v1`, `B v1`, `C v1`; no sobrescribir versiones anteriores.
- **Minimalismo:** PHP + SQLite + migraciones pequeñas + tests. Sin frameworks/SaaS/cloud/IA/colas complejas.
- **Anti-dispersión:** mejoras no esenciales → `FUTURE_IMPROVEMENTS.md`.
- **Seguridad de producción:** no DROP TABLE, no borrar datos, no modificar históricos, no envíos reales en tests sin autorización, no cambiar contraseñas.
- **Observabilidad:** cada fase deja logs, métricas, tests y evidencia (`OUTBOUND_FUTPROTEC_PROGRESS.md`, `POST_IMPLEMENTATION_AUDIT.md`).

---

# PARTE 6 — CONCLUSIÓN

Ambos planes convergen. El objetivo no es el CRM más sofisticado, sino **el experimento A/B/C más fiable ejecutable con el sistema actual**.

La intervención de mayor impacto, sin orden discutible, es:

1. **Fase 1–3** (campaña + variante en `envios` + backend decide A/B/C).
2. **Fase 5** (supresión global antes de cualquier envío).
3. **Fase 14** (analítica leyendo `envios`, no `lead_pipelines`, con KPI `Positive Reply Rate`).

El orden de prioridad final queda: **TRAZABILIDAD → SEGURIDAD → MEDICIÓN → VENTAS → OPTIMIZACIÓN.**

Este documento es la confirmación de lo que se hará. La ejecución queda pendiente de aprobación explícita y no modifica ningún archivo.