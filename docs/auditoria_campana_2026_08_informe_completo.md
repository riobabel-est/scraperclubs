# Auditoría analítica y trazabilidad completa — CRM FutProtec · Campaña "Comerciales FutProtec 2026-08" (campaign_id=2)

> **Tipo**: auditoría READ ONLY / audit. **Fecha**: 2026-08-28.
> **Fuente de datos**: `public_html/outbound/data/stats.db` (SQLite, 12,6 MB). Consultas en modo `mode=ro` (sin escrituras).
> **Aislamiento TEST/REAL respetado**: todos los datos REALES se filtran por `envios.es_test=0` y `campaign_id=2`. Los datos TEST (`es_test=1`: 12 envíos sueltos + 6 del smoke test campaign_id=3) se separaron y no se mezclan.
> **Entorno de la campaña**: `pipelines.id=2` → `PILOTO_FUTPROTEC_2026_08` ("Comerciales FutProtec 2026-08"), estado `PILOT`, entorno `pilot`. `config.modo_entorno = 'produccion'`.

---

## Resumen ejecutivo

- **348 leads reales** recibieron **432 envíos** (348 primer contacto + 84 rotación de no-abridores) entre el 17-08 y el 28-08, todos con `resultado_envio = ACCEPTED`.
- **134 envíos con al menos 1 apertura** (38,5 % de los leads). El tracking **no deduplica** (una misma apertura puede registrarse decenas de veces).
- **5 respuestas reales** de campaign_id=2 (3 `POSITIVE`, 1 `humana`, 1 `fuera_de_oficina`). Todas las respuestas humanas provienen de leads con **4+ aperturas**.
- **0 presupuestos, 0 mockups, 0 ventas** registradas: `presupuestos` y `mockups` están vacías. La conversión **respuesta → presupuesto → venta es el cuello de botella no instrumentado**.
- **21 rebotes hard** (status 5.x.x), 20 atribuibles a campaña 2 (13 confirmados + 7 del 28-08 con cuerpo vacío). 3 direcciones rebotadas recibieron reenvío por rotación.
- **El experimento A/B/C no es concluyente**: 83 de 348 leads (24 %) recibieron **2 variantes distintas** por la rotación, la asignación fue `Math.random()` sin estratificar, y la plantilla cambió entre oleadas.
- **Seguimiento comercial con retraso**: 2 leads de la 1.ª oleada esperaron **~9 días** a su primer seguimiento; los envíos de seguimiento se registran **sin campaña ni plantilla** (`campaign_id=NULL`, `plantilla_id=NULL`, `smtp_id=NULL`, `variant=NULL`).

---

## FASE 1 — Auditoría de esquema

29 tablas. Detalle de las relevantes para el negocio (columna · tipo · null · default · PK/FK/índice).

### `clubes_crm` (1.818 filas) — tabla de leads

| Columna | Tipo | Null | Default |
|---|---|---|---|
| id | INTEGER | PK | AUTOINCREMENT |
| nombre_club | TEXT | NO | — |
| federacion | TEXT | sí | `''` |
| persona_contacto / cargo_contacto | TEXT | sí | `''` |
| email | TEXT **UNIQUE** | NO | — |
| telefono_fijo / telefono_movil | TEXT | sí | `''` |
| tiene_whatsapp | INTEGER | sí | 0 |
| estado_lead | TEXT | sí | `'Sin Contactar'` |
| observaciones | TEXT | sí | `''` |
| ultimo_contacto | DATETIME | sí | NULL |
| volumen_estimado / num_jugadores | INTEGER | sí | NULL |
| categorias | TEXT | sí | `''` |
| provincia / ciudad / cp / direccion / cif | TEXT | sí | `''` |
| motivo_perdida / objeciones / proxima_accion | TEXT | sí | `''` |
| fecha_proxima_accion | DATETIME | sí | NULL |
| es_duplicado / duplicado_id | INT | sí | 0 / NULL |

- Índices: `idx_crm_estado`, `idx_crm_federacion`, `idx_crm_email`.
- **Sin campo `web`**. **Sin campo de variante A/B asignada al lead**. Sin FK a campañas.

### `envios` (470 filas) — núcleo de trazabilidad

- `id` (PK), `club`, `email`, `federacion`, `cuenta_emision`, `fecha_envio` (DATETIME), `estado` (`pendiente`/`enviado`/`abierto`), `tracking_id` (**UNIQUE**), `asunto`, `cuerpo_mensaje`.
- Enriquecimiento: **`lead_id`, `campaign_id`, `variant` (VARCHAR(1)), `plantilla_id`, `smtp_id`, `message_id`, `resultado_envio`, `fecha_resultado_envio`, `es_test` (default 0), `secuencia_id`, `paso_secuencia`, `es_rotacion` (default 0)**.
- Índices: `idx_envios_tracking` (UNIQUE tracking_id), `idx_envios_lead`, `idx_envios_campaign`, `idx_envios_variant`, `idx_envios_sec_paso` (**UNIQUE parcial** `(lead_id, campaign_id, paso_secuencia) WHERE paso_secuencia IS NOT NULL`), `idx_envios_lead_campaign` (**UNIQUE parcial** `(lead_id, campaign_id) WHERE campaign_id IS NOT NULL AND es_rotacion = 0`).
- **No hay FK** de `lead_id` → `clubes_crm.id`, ni de `plantilla_id` → `plantillas.id`, ni de `smtp_id` → `cuentas_smtp.id`. La integridad se apoya en índices UNIQUE, no en constraints.

### `aperturas` (326 filas)

`id` (PK), `tracking_id` (FK declarada → `envios.tracking_id`, sin ON DELETE), `fecha_apertura` (DATETIME), `ip`, `user_agent`. Índice `idx_aperturas_tracking`.
**No hay columna de deduplicación ni de sesión**: cada carga del píxel = 1 fila.

### `respuestas` (30 filas) — respuestas humanas + rebotes + OOO

`id` (PK), `envio_id`, `fecha_respuesta` (**RFC 2822, no SQLite DATETIME**), `remitente`, `destinatario`, `subject`, `cuerpo`, `message_id` (**UNIQUE parcial**), `in_reply_to`, `"references"`, `clasificacion` (default `PENDING`), `fecha_clasificacion`, `estado_procesamiento`, `lead_id`, `campaign_id`, `id_cuenta_smtp`, `message_id_original`, `contenido_html`, `uid_imap`, `cuenta_uid`, `carpeta`, `notificado`, `kanban_movido`, `estado_conversacion`, `es_rebote` (default 0), `atendido_en`, `archivado_en`, `borrado_en`.

### `comunicaciones_log` (547 filas) — event store parcial

`id`, `lead_id`, `club_id`, `tipo_evento` (`envio_email`, `cambio_estado`, `respuesta_recibida`, `notificacion_respuesta`, `blacklist_*`), `plantilla_id`, `detalles`, `ip_registro`, `fecha`, `id_cuenta_smtp`, `tipo`, `resultado`, `codigo_error`, `variante_ab`, `pipeline_id`, `resumen`, `proxima_accion`, `canal`. Índices por lead, club, cuenta, fecha.

### Tablas vacías o sin uso operativo

- **`presupuestos` → 0 filas** (estructura completa: unidades, precio_unitario, subtotal, descuento, importe_total, estado, fecha, pipeline_id…).
- **`mockups` → 0 filas** (estado, solicitado_en, enviado_en, notas…).
- **`rebotes` → 0 filas** (los rebotes viven en `respuestas` con `clasificacion='rebote'`, `es_rebote=1`).
- `lead_pipelines` → 5 filas, todas del pipeline TEST (nada de campaña 2).
- `contactos_club` → 0 · `propuestas_ia` → 0 · `secuencia_pasos` → 0 · `envios_adjuntos` → 11 · `respuestas_adjuntos` → 1 · `adjuntos_repo` → 0 · `destinatarios_test` → 0 · `snapshots` → 2 (12-08, pre-campaña).

### Otras

- **`pipelines` (3)**: id=1 `LEGACY_TEST_FASE1` (test) · **id=2 `PILOTO_FUTPROTEC_2026_08` = campaña 2** · id=3 `SMOKE_TEST` (test). **La campaña 2 vive en `pipelines`; no existe tabla `campanas`.**
- **`plantillas` (6)**: ids 1, 3, 4, 5, 8, 9. Los envíos usan además `plantilla_id` 2 y 6 **que ya no existen** en el catálogo (FK no enforced). `plantillas_new` vacía.
- **`secuencias` (1)**: "Secuencia Rotación ABC" → campaign_id 2, `rotar_no_abridores=1`, `rotar_espera_dias=7`, `rotar_max_envios=3`, `rotar_plantilla_id=1`.
- **`cuentas_smtp` (10 cuentas, todas activas, límite 15/día, driver IMAP)**.
- **`config` (17 claves)**: `modo_entorno='produccion'`, `motor_estado='pausado'`, delays de lanzadera 5–45 s.
- **Triggers: ninguno.** Constraints UNIQUE en `clubes_crm.email` y `cuentas_smtp.email`.

## FASE 2 — Mapa de trazabilidad

| Evento | Registrado | Tabla | Identificador | Trazabilidad |
|---|---|---|---|---|
| **Envío** | ✅ Sí | `envios` | `tracking_id` (UNIQUE), `message_id`, `lead_id`, `campaign_id` | 1:N (1 envío → N aperturas). Robustecido por `idx_envios_lead_campaign` |
| **Entrega** | ⚠️ Parcial | `envios.resultado_envio` | — | Solo `ACCEPTED` (el SMTP aceptó). **No hay confirmación real de entrega ni de soft/hard bounce en esta tabla** |
| **Apertura** | ✅ Sí (con ruido) | `aperturas` | `tracking_id` → `envios` | 1:N. **Sin deduplicación**: un mismo envío puede tener 1…49 filas |
| **Respuesta** | ✅ Sí | `respuestas` | `envio_id` + `message_id` (UNIQUE) / `in_reply_to` | 1:1 por mensaje. Atribución por In-Reply-To → References → email remitente (ver `imap_atribuir()`) |
| **Follow-up** | ⚠️ Parcial | `envios` (asunto `Re:`) | `lead_id` (sí) / **`campaign_id`, `plantilla_id`, `smtp_id`, `variant` = NULL** | 1:N pero **se pierde la relación con campaña/plantilla** |
| **Cantidad** | ❌ No | — | — | No existe campo ni evento |
| **Presupuesto** | ❌ No | `presupuestos` (0 filas) | `lead_id`, `pipeline_id` | Estructura lista, sin datos |
| **Escudo** | ❌ No | — | — | No existe (ni campo en presupuestos/mockups) |
| **Diseño / Mockup** | ❌ No | `mockups` (0 filas) | `lead_id` | Estructura lista, sin datos |
| **Negociación** | ❌ No | — | — | No hay registro de negociación |
| **Ganado** | ❌ No | — | — | No hay venta registrada |
| **Perdido** | ⚠️ Parcial | `clubes_crm.motivo_perdida` (todas vacías) | `lead_id` | Campo existe, 0 usos |

**Relaciones campo a campo**: `envios.lead_id` → `clubes_crm.id` (1:1 por lead, sin FK) · `envios.campaign_id` → `pipelines.id` (N:1, sin FK) · `envios.tracking_id` → `aperturas.tracking_id` (1:N, FK declarada) · `respuestas.envio_id` → `envios.id` (N:1, índice) · `presupuestos.lead_id` → `clubes_crm.id` (1:N, FK declarada) · `mockups.lead_id` → `clubes_crm.id` (1:N, FK declarada).

**Dónde puede perderse la trazabilidad hoy**:
1. Envíos manuales de seguimiento (`asunto LIKE 'Re:%'`): sin `campaign_id`, sin `plantilla_id`, sin `smtp_id`, sin `variant` → no se pueden unir a la campaña 2 ni a una plantilla.
2. Aperturas de seguimiento manuales: los envíos `trk_*` tienen `message_id = NULL` → la respuesta posterior a ese seguimiento no podrá correlacionarse por `in_reply_to`.
3. Rebotes de la oleada del 28-08: `respuestas.cuerpo` vacío en 7 filas → no se puede extraer el email fallido → no se asocian al envío.
4. `fecha_respuesta` en RFC 2822 → las comparaciones de tiempo requieren parseo externo (el SQL nativo falla).

---

## FASE 3 — Campaña 2026-08 (REAL, es_test=0, campaign_id=2)

### Envíos

| Métrica | Valor |
|---|---|
| Leads afectados | **348** |
| Envíos totales | **432** (348 primer envío + 84 rotación) |
| Emails únicos | 348 (1 email por lead; 2 leads comparten email — ver Fase 16) |
| Rango de fechas | 2026-08-17 → 2026-08-28 |
| `resultado_envio` | 432 × `ACCEPTED` |
| Entregados | **NO MEDIBLE** (ACCEPTED ≠ entregado) |

### Por variante (todos los envíos)

| Variante | Envíos | Envíos con ≥1 apertura | Aperturas |
|---|---|---|---|
| A | 147 | 36 | 78 |
| B | 140 | 42 | 86 |
| C | 145 | 56 | 95 |

### Por día

17-ago: 22 · 18-ago: 37 · 19-ago: 100 · 27-ago: 150 (84 rotación + 66 nuevos) · 28-ago: 123.

### Por hora de envío

18h (179) · 14h (59) · 17h (54) · 11h (45) · 15h (35) · 23h (20) · 21h (10) · 13h (10) · 02h (10) · 01h (6) · 10h (2) · 00h (1) · 03h (1). Existen envíos de madrugada 00:00–03:00.

### Por SMTP

10 cuentas con reparto casi uniforme (37–49 envíos cada una).

### Por dominio receptor

gmail.com 204 · hotmail.com 91 · hotmail.es 26 · yahoo.es 13 · outlook.es 3 · outlook.com 2 · yahoo.com 2 · + ~70 dominios corporativos con 1–2 envíos (fsnazareno.es, adarganda.es, burgoscf.es, etc.).

## FASE 4 — Aperturas

### Cómo funciona el tracking (de `api/track.php`)

- Genera una fila en `aperturas` **cada vez que el píxel se carga** (INSERT directo, sin cookie/sesión/dedup).
- Marca `envios.estado='abierto'` solo la primera vez (condición `estado='enviado'`).
- Añade a `clubes_crm.observaciones` una línea `[TRACKING dd/mm HH:MM] Email abierto` **en cada carga** (el comentario del código dice "solo la primera apertura", pero la práctica acumula líneas).
- Registra `ip` (REMOTE_ADDR) y `user_agent` (truncado 500) en el 100 % de las filas.
- **No hay deduplicación**: el tracking `fut_6a826ee0_82df96675a73` (test) acumuló **49** aperturas; el envío real de C.D. Segosala, 12.

### Distribución de aperturas por lead (camp2, 348 leads)

| Aperturas | Leads |
|---|---:|
| 0 | 214 |
| 1 | 69 |
| 2 | 40 |
| 3 | 10 |
| 4 | 7 |
| 5 | 3 |
| 6 | 3 |
| 7 | 1 |
| 12 | 1 |

Media **0,74** aperturas/lead · Mediana **0**.

### Distribución por variante (solo primer envío, es_rotacion=0)

| Variante | Envíos | Abrieron | % apertura | Aperturas totales |
|---|---|---|---|---|
| A | 121 | 35 | **28,9 %** | 75 |
| B | 105 | 40 | **38,1 %** | 81 |
| C | 122 | 51 | **41,8 %** | 86 |

### Probabilidad de respuesta según número de aperturas (prioritario)

| Aperturas | Leads | Respondieron | % respuesta |
|---|---:|---:|---:|
| 0 | 214 | 1 (\*) | 0,5 % |
| 1 | 69 | 0 | 0 % |
| 2 | 40 | 0 | 0 % |
| 3 | 10 | 0 | 0 % |
| 4+ | 15 | 4 | **26,7 %** |

(\*) La única respuesta con 0 aperturas es la **respuesta automática "fuera de oficina"** de A.D. NUEVA CASTILLA. **Todas las respuestas humanas/POSITIVE (4) proceden de leads con 4+ aperturas.** La correlación apertura múltiple → respuesta es fuerte, pero con n muy pequeño (bucket 4+ = 15 leads). Se necesita más muestra antes de declarar causalidad.

---

## FASE 5 — Respuestas reales de campaign_id=2 (5 registros)

| # | id | Lead | Club | Var. | Fecha (Madrid) | Clasificación | Texto esencial |
|---|---|---|---|---|---|---|---|
| 1 | 8 | 1217 | C.D. Segosala | A | 19-08 21:59 | `humana` | "Perfecto, si nos enviáis lo valoramos, muchas gracias" |
| 2 | 11 | 407 | C.D. DURCAL | A | 19-08 23:44 | `POSITIVE` | "Gracias por el ofrecimiento, lo valoramos con chicos/padres y entre directiva y os decimos" |
| 3 | 18 | 1399 | A.D. NUEVA CASTILLA | C | 27-08 16:55 | `fuera_de_oficina` | "Cerrado por vacaciones Re: …" |
| 4 | 19 | 1386 | A.D. EL PARDO | B | 27-08 16:51 | `POSITIVE` | "ok." |
| 5 | 20 | 1407 | A.D. RAYO LATINA | B | 27-08 17:24 | `POSITIVE` | "Envíame muestra y precio para presentarlo en junta y te decimos algo" |

- **Texto completo conservado en `respuestas.cuerpo`** (no alterado). Las 5 conservan `message_id`, `in_reply_to` con el Message-ID del envío original → atribución correcta y reconstruible.
- **Clasificaciones existentes**: `POSITIVE` (3), `humana` (5), `fuera_de_oficina` (1), `rebote` (21). **No existe clasificación fina** (solicita fotos/catálogo/precio/presupuesto, proporciona cantidad/escudo… no implementadas). Las 3 "POSITIVE" son en realidad cualitativamente distintas (pedido de muestra+precio / valoración interna / "ok" escueto) y ninguna expresa cantidad.

## FASE 6 — Velocidad de respuesta (envío → respuesta, hora Madrid)

| Lead | Δ tiempo |
|---|---:|
| C.D. Segosala | 224 min (3 h 44) |
| C.D. DURCAL | 332 min (5 h 32) |
| A.D. NUEVA CASTILLA | 120 min (2 h, respuesta automática) |
| A.D. EL PARDO | 126 min (2 h 06) |
| A.D. RAYO LATINA | 144 min (2 h 24) |

**Media 189 min · Mediana 144 · Mínimo 120 · Máximo 332 (n=5).**

> Nota metodológica: `fecha_respuesta` está en RFC 2822 con offsets distintos (`+0200`, `-0700`, `+0000`); el cálculo exige normalización a zona horaria (aquí Europe/Madrid +02:00). **En SQL puro no se puede calcular** sin parseo externo → problema de instrumentación.

---

## FASE 7 — Seguimiento comercial (qué ocurrió después de cada respuesta)

| Lead | 1ª respuesta | Clasificación | Primer seguimiento | Δ respuesta→seguimiento | ¿Respuesta posterior? |
|---|---|---|---|---|---|
| C.D. Segosala (1217) | 19-08 21:59 | humana | **28-08 16:33** (envío 379, asunto `Re:`) | **210,6 h (~8,8 días)** | ❌ No registrada |
| C.D. DURCAL (407) | 19-08 23:44 | POSITIVE | **28-08 13:26** (envío 369, `Re:`) | **205,7 h (~8,6 días)** | ❌ No registrada |
| A.D. NUEVA CASTILLA (1399) | 27-08 16:55 | fuera_de_oficina | **Ninguno** | — | — |
| A.D. EL PARDO (1386) | 27-08 16:51 | POSITIVE | **28-08 17:04** (envío 380, `Re:`) | **24,2 h** | ❌ No registrada |
| A.D. RAYO LATINA (1407) | 27-08 17:24 | POSITIVE | **28-08 13:04** (envío 368, `Re:`) | **19,7 h** | ❌ No registrada |

- Media respuesta→seguimiento: **115 h (~4,8 días)**; **1 de 5 sin seguimiento**.
- Los dos leads de la primera oleada esperaron **~9 días** a que el equipo contestara.
- Existe `ia_lead_analisis` para DURCAL (28-08 09:54, intención `interesado`, confianza 0,6, próxima acción "seguimiento en 7-10 días") que sí se materializó ~3,5 h después.
- **Importante**: los envíos de seguimiento (`368, 369, 379, 380`) están en `envios` con `campaign_id=NULL`, `plantilla_id=NULL`, `smtp_id=NULL`, `variant=NULL` → **el seguimiento NO queda vinculado a la campaña 2 ni a una plantilla** (solo al `lead_id` y al asunto `Re:`).

---

## FASE 8 — Presupuestos

- **Existen 0 presupuestos** en toda la BD (tabla preparada: unidades, precio_unitario, importe_total, estado, fecha…).
- De las 3 respuestas POSITIVE:
  - **A.D. RAYO LATINA (1407)**: pidió "muestra y precio" → **recibió seguimiento el 28-08 pero NO consta presupuesto** creado/enviado.
  - **A.D. EL PARDO (1386)**: "ok." → **recibió seguimiento, sin presupuesto**.
  - **C.D. DURCAL (407)**: "lo valoramos" → **recibió seguimiento, sin presupuesto**.
- **Ningún lead tiene presupuesto registrado.** La etapa cantidad/precio/importe del embudo es **NO DISPONIBLE**.

---

## FASE 9 — Mockups / Diseños

- **0 mockups** en `mockups`. Ningún lead tiene diseño solicitado, creado ni enviado.
- La medición `presupuesto → escudo → diseño → venta` es **NO DISPONIBLE** por completo.

---

## FASE 10 — Embudo real (campaign_id=2)

| Etapa | Nº | % vs etapa anterior | % vs 348 leads |
|---|---:|---:|---:|
| Leads | 348 | — | 100 % |
| Enviados (1er envío) | 348 | 100 % | 100 % |
| Entregados | **NO DISPONIBLE** (solo `ACCEPTED`) | — | — |
| Abrieron (≥1 apertura) | 134 (126 1er envío + 8 rotación) | 38,5 % | 38,5 % |
| Respondieron | 5 | 3,7 % de abridores | 1,4 % |
| Respuesta positiva | 3 | 60 % de respuestas | 0,9 % |
| Solicitaron presupuesto/muestra | 1 claro (Rayo Latina) + 1 implícito (Segosala) | — | ~0,3–0,6 % |
| Presupuesto enviado | **0 (NO DISPONIBLE)** | — | 0 % |
| Escudo recibido | **NO DISPONIBLE** | — | — |
| Diseño realizado | **0 (NO DISPONIBLE)** | — | 0 % |
| Negociación | **NO DISPONIBLE** | — | — |
| Ganado | **0** | — | 0 % |
| Perdido | **0** (motivo_perdida sin uso) | — | 0 % |

**Cuello de botella medible**: apertura (38,5 %) → respuesta (1,4 %). El resto del embudo (de respuesta a venta) es **NO DISPONIBLE** porque no hay datos.

## FASE 11 — Rebotes (21)

Los 21 rebotes están en `respuestas` (`clasificacion='rebote'`, `es_rebote=1`). **Todos con Status 5.x.x → HARD BOUNCE.**

| Tipo | Cantidad | % |
|---|---:|---:|
| Hard | 21 | 100 % |
| Soft | 0 | 0 % |
| Otro | 0 | 0 % |

**Cruce con envíos (campaign_id=2)**: 13 asociados por tracking del mensaje original + 7 con cuerpo vacío del 28-08 (1 asociable por asunto: ALBOLOTE → envío 399) + **1 perteneciente a un envío de prueba** (`rodrigo@riobabel.com`, envío 363, `lead_id=0`, sin campaña — rechazo SiteGround "High probability of spam").

| Rebotado (email) | Código/Diag | Var | SMTP | Fecha rebote |
|---|---|---|---|---|
| pdrociera@yahoo.es | 5.0.0 / 554 "From header invalid" | A | 9 | 19-08 |
| franciscolozanoval@gmail.com (×2) | 5.1.1 cuenta inexistente | C / A | 2 / 10 | 19/27-08 |
| ad_el_naranjo@hotmail.es (×2) | 5.5.0 mailbox unavailable | A / B | 4 / 4 | 19/27-08 |
| direcciondeportiva@adpiquenas.es (×2) | 5.0.0 "retry timeout" | B / C | 3 / 5 | 20/28-08 |
| cdcescolapios@escolapiosemaus.org (×2) | 5.7.193 política de grupo | C / A | 10 / 6 | 19/27-08 |
| administracion@adcolmenarviejo.es | 5.0.0 / 554 | A | 9 | 27-08 |
| info@fsnazareno.es | 5.0.0 dominio no existe | C | 5 | 27-08 |
| dd@adorcasitas.es | 5.0.0 / 554 | C | 1 | 27-08 |
| futbolbase@adparla.com | 5.0.0 / 554 | A | 9 | 27-08 |
| rodrigo@riobabel.com (TEST) | 5.0.0 / 550 spam | — | — | 27-08 |
| albolotecf@hotmail.com | (cuerpo vacío) | B | 1 | 28-08 |
| 6 sin identificar (28-08) | (cuerpo vacío) | ? | ? | 28-08 |

**Hallazgos de entregabilidad**:
1. **3 direcciones rebotadas recibieron 2 envíos** (franciscolozanoval, ad_el_naranjo, adpiquenas, escolapios): el reenvío por rotación no excluye rebotados → **se mandó correo a direcciones inválidas** (riesgo reputacional).
2. El rebote de Yahoo (`pdrociera`) es por **"From header is invalid or contains unacceptable characters"**: el nombre del emisor con acento ("Adrián Cano") se está enviando mal codificado (sin RFC 2047). Verificado en el raw del rebote: `From: Adri□n Cano`.
3. 7 rebotes del 28-08 **sin cuerpo registrado** → imposible extraer email/código.

---

## FASE 12 — SMTP (rendimiento por cuenta, camp2)

| SMTP | Cuenta | Envíos | Abrieron | Aperturas | Respuestas |
|---|---|---:|---:|---:|---:|
| 1 | rodrigo@getfutprotec.com | 40 | 12 | 29 | 0 |
| 2 | mario.ortiz | 39 | 12 | 17 | 1 (fuera_oficina) |
| 3 | alvaro.ruiz | 45 | 15 | 25 | 0 |
| 4 | carlos.mora | 49 | 14 | 22 | 0 |
| 5 | javier.sanz | 37 | 8 | 28 | 1 (Segosala) |
| 6 | diego.navarro | 45 | 15 | 26 | 0 |
| 7 | pablo.blanco | 37 | 13 | 20 | 0 |
| 8 | gonzalo.vega | 47 | **20** | **37** | **2 (El Pardo, Rayo Latina)** |
| 9 | adrian.cano | 46 | 10 | 25 | 0 |
| 10 | sergio.gil | 47 | 15 | 30 | 1 (DURCAL) |

Solo datos. **Sin conclusiones causales** (reparto casi uniforme, n por cuenta ~40; la cuenta 1 aparece también en el rebote de test).

---

## FASE 13 — Dominios receptores

| Grupo | Envíos | Rebotes asociados | Abrieron | Respuestas |
|---|---:|---:|---:|---:|
| Gmail (+googlemail) | 204 | 2 (franciscolozanoval ×2) | ~45–50 | 4 (Segosala, DURCAL, El Pardo, Rayo Latina) |
| Hotmail/Outlook/MSN (hotmail.com, hotmail.es, outlook.es, outlook.com) | 122 | 3 (ad_el_naranjo ×2 + albolote) | ~40 | 0 |
| Yahoo (yahoo.es, yahoo.com) | 15 | 1 (pdrociera) | ~5 | 0 |
| Corporativos (.es, .com, .org, .edu, .net) | ~91 | 7 (adpiquenas ×2, escolapios ×2, adcolmenarviejo, fsnazareno, adorcasitas, adparla) | ~35 | 0 (N. Castilla corporativo → fuera_de_oficina) |

**Gmail concentra el 47 % de los envíos y TODAS las respuestas humanas.** n pequeño → sin conclusiones causales.

## FASE 14 — Horarios

Existe información suficiente (timestamps en `fecha_envio`, `fecha_apertura`, `fecha_respuesta`):

- **Envios**: 58 % entre 17:00–18:59 h; pico a las 18 h (179). Existen envíos de 00:00–03:00 (22) y de 23 h (20).
- **Aperturas**: picos 18-19 h (32) y 14-15 h (26) el día de envío; aperturas matinales 07-09 h en días posteriores (revisión de bandeja).
- **Respuestas**: 4 de 5 entre 16:51–23:44; ninguna a primera hora.
- **Días**: envíos concentrados en 5 días (17-19 y 27-28 ago); sin envíos del 20-26 (pausa de una semana) — probablemente límite diario SMTP (15/día × 10 cuentas ≈ 150/día máx).

**Conclusión**: hay timestamps suficientes para optimizar horarios, pero **no modificar aún**.

---

## FASE 15 — Datos del club (segmentación)

Cobertura global (1.818 clubes) y sobre los 348 leads de camp2:

| Campo | Total (1818) | % | Leads camp2 (348) | % |
|---|---:|---:|---:|---:|
| Federación | 1.813 | 99,7 % | 347 | 99,7 % |
| Provincia | **0** | **0 %** | 0 | 0 % |
| Ciudad | **0** | **0 %** | 0 | 0 % |
| Teléfono móvil | 1.733 | 95,3 % | 335 | 96,3 % |
| WhatsApp (tiene_whatsapp) | 1.731 | 95,2 % | 333 | 95,7 % |
| Teléfono fijo | 366 | 20,1 % | — | — |
| Persona de contacto | 5 | 0,3 % | 0 | 0 % |
| nº jugadores | **0** | **0 %** | 0 | 0 % |
| Categorías | **0** | **0 %** | 0 | 0 % |
| Web | **NO EXISTE el campo** | — | — | — |
| Teléfonos en `telefonos_club` | 3.704 | — | 344/348 | 98,9 % |

Distribución por federación en camp2: Andalucía 124 · Madrid 105 · Castilla y León 72 · Riojana 15 · Galega 11 · Murcia 10 · Asturias 7 · Aragón 2 · Extremadura 1 · sin federación 1.

**Segmentación útil hoy**: solo federación (completa). **No se puede segmentar por provincia, tamaño de club ni categorías** (dato inexistente). WhatsApp es rastreable solo como flag.

---

## FASE 16 — Calidad del experimento A/B/C

| Comprobación | Resultado |
|---|---|
| Distribución real | A 147 · B 140 · C 145 (34 % / 32 % / 34 %) — balanceada |
| Método de asignación | **`Math.random()` con 33 % por variante en el frontend** (`js/app.js` 1793) — aleatoria, **no estratificada por federación/SMTP/hora** |
| Leads con >1 envío | **83 de 348 (24 %)** |
| Leads que recibieron **>1 variante** | **83 de 83** (el 100 % de los reenviados recibió una variante DISTINTA) |
| Mecanismo | Rotación automática "Secuencia Rotación ABC" (27-08, 10:53–14:17), solo no-abridores (**0** rotados con apertura previa — correcto); 8 de 83 abrieron el 2º envío |
| Contaminación entre variantes | **SÍ**: un lead "asignado a A" puede haber abierto/respondido un envío B (rotación). Con 24 % de leads contaminados, las métricas agregadas por variante (que incluyen `es_rotacion=1`) **no comparan variantes puras** |
| Diferencias de segmentación | La asignación no controló federación/SMTP → los tamaños por federación y cuenta pueden sesgar el resultado |
| Diferencias de fechas | Oleada 1 (17-19 ago, plantilla "Prospeccion abc texto plano") y oleada 2 (27-28 ago, plantilla "Prospección Paso 1 Test ABC" con asunto A modificado: "para el {{CLUB}}") → **la plantilla cambió entre oleadas** |
| Duplicados | 2 emails duplicados (2 leads con el mismo email → 1 email recibió 2 envíos de leads distintos) |
| Reenvío a rebotados | Sí: 3 direcciones rebotadas recibieron 2 envíos |

**Veredicto**: **NO se puede declarar ganador estadístico.** Los datos soportan una lectura *descriptiva* separando `es_rotacion=0` (primer contacto) para aperturas, y las respuestas son demasiado pocas (A=2, B=2, C=1) para cualquier test de significación. Con tasa de apertura C (41,8 %) > B (38,1 %) > A (28,9 %) y 3 respuestas POSITIVE (B=2, A=1, C=0), la muestra no permite diferenciar del azar.

## FASE 17 — Instrumentación que falta

### ✅ YA EXISTE
- Envío por lead/campaña/variante/SMTP/plantilla (`envios`)
- Aperturas con timestamp, IP, user-agent (`aperturas`)
- Respuestas con texto completo, message_id, in_reply_to, clasificación básica (`respuestas`)
- Rebotes (como respuestas clasificadas, no en tabla `rebotes`)
- Rotación de no abridores con `es_rotacion` y variante rotada
- Event store (`comunicaciones_log`) con envío, respuesta, cambio de estado
- Estructura lista (vacía) para `presupuestos` y `mockups`

### ⚠️ EXISTE PARCIALMENTE
- **Entrega real** (solo `ACCEPTED`; falta estado de entregado/fallido/rebote por envío y timestamp)
- **Deduplicación de aperturas** (el estado `abierto` dedup, pero la tabla `aperturas` no)
- **Seguimiento comercial** (registrado en `envios` pero sin campaña/plantilla/SMTP/variante; sin vínculo con la respuesta a la que contesta)
- **Clasificación de respuestas** (solo POSITIVE/humana/fuera_de_oficina/rebote; falta clasificación fina)
- **Estados del lead** (solo 4: Sin Contactar/Contactado/En Conversación/Lista Negra; no hay "Presupuesto/Propuesta/Negociación/Ganado/Perdido" en el flujo real)
- **Fecha de respuesta** (RFC 2822 en texto → no comparable en SQL)

### ❌ NO EXISTE
- **Cantidad** indicada por el lead (nº jugadores/tallas)
- **Escudo / colores** recibidos
- **Presupuesto** creado/enviado/aprobado (tabla vacía)
- **Diseño/mockup** solicitado/enviado (tabla vacía)
- **Negociación** (contraofertas, objeciones con fechas)
- **Ganado/Perdido** (motivo, importe, fecha)
- **Web / provincia / ciudad / nº jugadores / categorías** en clubes_crm
- **Cliente de correo y dispositivo** normalizados (solo raw user_agent)
- **IP de respuesta** (el lead responde → no se guarda IP del contestador)
- **Tiempo de respuesta del equipo** (`atendido_en` está NULL en las 5 respuestas)
- **Tracking de clics** (no existe, solo aperturas)

**Dónde registrar cada dato faltante (propuesta, sin implementar)**:
- Cantidad/escudo/colores → campos en `presupuestos` (+ `envio_id` de la conversación) y/o en un `detalles_venta`.
- Presupuesto → insertar fila en `presupuestos` con `lead_id`, `campaign_id` (hoy no tiene columna campaign → **añadir `campaign_id`**), `envio_id` origen, importe, estado (creado/enviado/aprobado/rechazado), fecha.
- Mockup → insertar en `mockups` con `lead_id`, `presupuesto_id`, estado, fechas.
- Ganado/perdido → poblar `clubes_crm.motivo_perdida` + `estado_lead` + flag/fecha de cierre e importe de venta.
- Seguimiento → registrar en `envios` con `campaign_id`, `plantilla_id`, `smtp_id`, `variant=NULL` e `in_reply_to` del mensaje original para encadenar la conversación.
- Todo debe relacionarse por **`lead_id` + `campaign_id`** (ya soportado por `idx_envios_lead_campaign`) y por `envio_id` en el caso de respuestas/presupuestos.

## FASE 18 — Exportación analítica por lead (SQL de referencia, solo lectura)

Campo por campo marcado con lo que **existe hoy** vs `NULL — NO DISPONIBLE`:

```sql
SELECT
  c.id                                      AS lead_id,
  c.nombre_club                             AS club,
  c.email                                   AS email,
  e.campaign_id                             AS campaign_id,
  e.variant                                 AS variant,
  e.id                                      AS envio_id,
  e.fecha_envio                             AS fecha_envio,
  strftime('%H', e.fecha_envio)             AS hora_envio,
  CASE WHEN e.resultado_envio = 'ACCEPTED' THEN 1 END  AS entregado,      -- NO real: solo aceptado
  NULL                                      AS rebote,                     -- NO DISPONIBLE en envios
  NULL                                      AS tipo_rebote,                -- NO DISPONIBLE
  (SELECT COUNT(*) FROM aperturas a WHERE a.tracking_id = e.tracking_id) AS num_aperturas,
  (SELECT MIN(a.fecha_apertura) FROM aperturas a WHERE a.tracking_id = e.tracking_id) AS primera_apertura,
  (SELECT MAX(a.fecha_apertura) FROM aperturas a WHERE a.tracking_id = e.tracking_id) AS ultima_apertura,
  CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS respondio,
  r.fecha_respuesta                         AS fecha_respuesta,            -- RFC2822: normalizar
  r.cuerpo                                  AS respuesta_texto,
  r.clasificacion                           AS respuesta_clasificacion,
  -- Seguimiento: primer envio posterior con asunto 'Re:%'
  (SELECT 1 FROM envios e2 WHERE e2.lead_id = e.lead_id AND e2.id > e.id AND e2.asunto LIKE 'Re:%' LIMIT 1) AS followup_enviado,
  (SELECT MIN(e2.fecha_envio) FROM envios e2 WHERE e2.lead_id = e.lead_id AND e2.id > e.id AND e2.asunto LIKE 'Re:%') AS fecha_followup,
  NULL                                      AS cantidad_indicada,          -- NO DISPONIBLE
  NULL                                      AS presupuesto_solicitado,     -- NO DISPONIBLE (no hay campo)
  NULL                                      AS presupuesto_enviado,        -- tabla vacía
  NULL                                      AS importe_presupuesto,        -- tabla vacía
  NULL                                      AS escudo_recibido,            -- NO DISPONIBLE
  NULL                                      AS colores_recibidos,          -- NO DISPONIBLE
  NULL                                      AS mockup_solicitado,          -- tabla vacía
  NULL                                      AS mockup_enviado,             -- tabla vacía
  NULL                                      AS negociacion,                -- NO DISPONIBLE
  NULL                                      AS ganado,                     -- NO DISPONIBLE
  NULL                                      AS perdido,                    -- NO DISPONIBLE
  c.motivo_perdida                          AS motivo_perdida              -- campo existente, sin datos
FROM envios e
JOIN clubes_crm c ON c.id = e.lead_id
LEFT JOIN respuestas r ON r.envio_id = e.id
WHERE e.campaign_id = 2 AND e.es_test = 0 AND e.es_rotacion = 0;
```

(En la consulta real ejecutada: 348 leads, `respondio=1` en 5, `followup_enviado=1` en 4.)

## FASE 19 — Informe final

### 1. Estado actual del CRM
CRM operativo y funcional para **captura de envío/apertura/respuesta** en producción. Los módulos de venta (presupuestos, mockups, estados comerciales avanzados) están **construidos pero vacíos**. El equipo usa: lanzadera (envíos) + panel de seguimiento con envíos manuales "Re:" + kanban de estados (4 estados).

### 2. Datos disponibles
Envíos, variante, SMTP, aperturas (con ruido), respuestas completas con texto, rebotes hard, rotación de no abridores, federación/teléfono/WhatsApp por lead.

### 3. Datos faltantes
Cantidad, presupuesto, escudo/colores, diseño, negociación, venta, motivo de pérdida, provincia, tamaño de club, clic-tracking, deduplicación de aperturas, vínculo de seguimientos con campaña/plantilla, tiempo de atención del equipo, cuerpo de 7 rebotes.

### 4. Calidad de los datos
Buena en envío/apertura/respuesta; **mala en**: asignación de variante no estratificada + contaminación por rotación (24 % de leads con 2 variantes), `fecha_respuesta` en RFC 2822, seguimientos sin metadatos de campaña, rebotes del 28-08 sin cuerpo, plantillas 2 y 6 desaparecidas, envío id=18 con asunto sin reemplazar (`{[CLUB]}`), envíos con `lead_id=0/NULL` en pruebas.

### 5. Funnel real campaign_id=2
348 leads → 348 enviados → ~134 abrieron (38,5 %) → 5 respondieron (1,4 %) → 3 positivas (0,9 %) → **resto NO DISPONIBLE** (0 presupuestos, 0 mockups, 0 ventas).

### 6. Análisis A/B/C
**No concluyente.** Descriptivo: apertura A 28,9 % / B 38,1 % / C 41,8 %; respuestas A=2, B=2, C=1; POSITIVE A=1, B=2, C=0. Contaminación por rotación (83 leads con 2 variantes), plantilla cambiada entre oleadas, n insuficiente → **no declarar ganador**.

### 7. Análisis de aperturas
Sin dedup (1 tracking llegó a 49 filas). 38,5 % de leads abrieron. **Las aperturas múltiples predicen respuesta en esta muestra**: 26,7 % de respuesta con 4+ aperturas vs 0,5 % con 0 (que era una auto-respuesta de vacaciones).

### 8. Análisis de respuestas
5 respuestas reales; 3 clasificadas POSITIVE pero cualitativamente distintas (1 pide muestra+precio, 1 "valoramos", 1 "ok"). Falta clasificación fina.

### 9. Seguimiento comercial
4 de 5 respondientes recibieron seguimiento, pero **2 de ellos tras ~9 días** (210 h y 206 h). 1 (NUEVA CASTILLA) sin seguimiento (era fuera_de_oficina). Seguimientos sin metadatos de campaña/plantilla.

### 10. Rebotes y entregabilidad
21 hard bounces (100 % hard; 0 soft). 20 atribuibles a camp2 (13 confirmados + 7 del 28-08 con cuerpo vacío). 3 direcciones rebotadas reenviadas por rotación. 1 rebote de Yahoo por From mal codificado. 1 de test.

### 11. Segmentación
Solo federación útil (99,7 %). WhatsApp/móvil 95-96 %. **Provincia, nº jugadores, categorías, web: NO DISPONIBLE**.

### 12. Problemas de instrumentación
Ver Fase 17. Resumen: sin dedup de aperturas, sin clics, seguimientos sin campaña/plantilla, RFC2822, rebotes sin cuerpo, presupuestos/mockups sin uso, estados comerciales incompletos, sin campo web/provincia/jugadores.

### 13. Recomendaciones (para decidir más adelante, sin implementar)
1. **A/B/C limpio en la próxima campaña**: asignación estratificada por federación y SMTP; excluir rotaciones del análisis o asignar variante única al lead; prohibir reenvío a rebotados.
2. **Deduplicar aperturas** (cookie/firma/ventana temporal) y separar "abiertos únicos" de "reaperturas".
3. **Registrar el seguimiento comercial con campaña/plantilla/SMTP** y encadenarlo a la respuesta por `in_reply_to`.
4. **Usar `presupuestos` y `mockups`** desde el primer pedido de "muestra y precio" (RAYO LATINA ya lo pide).
5. **Poblar `atendido_en`** en respuestas → medir tiempo de respuesta del equipo (hoy 2 leads esperaron ~9 días).
6. **Corregir el encoding del From** (RFC 2047) para evitar rechazos de Yahoo.
7. **Normalizar `fecha_respuesta`** a DATETIME SQLite.
8. **Capturar cuerpo completo de rebotes** (6-7 se pierden).
9. **Ampliar datos de segmentación**: provincia, nº jugadores, categorías, web.
10. **Clasificación fina de respuestas** (las 14 categorías sugeridas).

### 14. SQL / consultas necesarias
Incluidas en la Fase 18 (export por lead) + las agregaciones ejecutadas a lo largo de esta auditoría (todas re-ejecutables en `mode=ro`).

## Respuestas directas a las 14 preguntas del resultado esperado

1. **¿Qué variante genera más atención?** Descriptivamente C (41,8 % apertura vs B 38,1 % y A 28,9 %), pero **no es concluyente** (contaminación y n pequeño).
2. **¿Qué variante genera más respuestas?** Empate A=2 y B=2, C=1 (incl. fuera_de_oficina). Sin significación.
3. **¿Qué variante genera más respuestas positivas?** B=2 (El Pardo, Rayo Latina), A=1 (DURCAL), C=0. Muestra insuficiente.
4. **¿Las aperturas múltiples predicen respuesta?** En esta muestra sí: 26,7 % de respuesta en 4+ aperturas vs ~0 % en 1-3. n=15 en el bucket alto → validar en próximas oleadas.
5. **¿Qué comportamiento precede a una respuesta?** Reaperturas del mismo correo (todas las respuestas humanas tuvieron 4+ aperturas) y, en 2 casos, respuesta en el mismo día por la tarde-noche.
6. **¿Qué tipo de lead responde mejor?** Gmail (4 de 4 humanas), clubes de Madrid/Andalucía; federación no determinante. Solo 5 respuestas → sin base.
7. **¿Qué ocurre después de una respuesta?** El lead pasa a "03 En Conversación" (5/5), recibe un seguimiento manual "Re:" sin metadatos de campaña (4/5), y **nada más** (0 presupuestos, 0 mockups).
8. **¿Estamos perdiendo oportunidades por falta de seguimiento?** Probablemente sí: 2 leads esperaron ~9 días; 1 (fuera_de_oficina) no recibió seguimiento; de 3 POSITIVE, 0 se convirtieron en presupuesto. **El eslabón respuesta→presupuesto es el cuello de botella real** (hoy no se ejecuta).
9. **¿Cuánto tarda el equipo en responder?** Respuesta del lead → primer seguimiento: media 115 h (19-27 h para los de la 2ª oleada; **206-211 h** para los de la 1ª). El tiempo de atención (`atendido_en`) no está poblado.
10. **¿Cuántos presupuestos se generan?** **0.** (3 respuestas positivas no produjeron ningún presupuesto registrado.)
11. **¿Cuántos diseños se realizan?** **0** (tabla mockups vacía).
12. **¿Cuántas oportunidades terminan en venta?** **0** — no hay venta registrada (y 0 perdidas con motivo: no se registra el cierre).
13. **¿Dónde está el cuello de botella?** Medible hoy: apertura→respuesta (1,4 %). **No medible pero sospechoso**: respuesta→presupuesto→venta (0 % de conversión registrada, con 5 oportunidades reales). La velocidad de seguimiento (~9 días en los 2 primeros) es un riesgo directo.
14. **¿Qué datos necesitamos empezar a registrar?** Cantidad, escudo/colores, presupuesto (fecha, importe, estado), mockup (solicitado/enviado), seguimientos con campaña/plantilla, `atendido_en`, motivo de cierre ganado/perdido, deduplicación de aperturas, clics, y segmentación (provincia, nº jugadores, categorías, web).

---

## Limitaciones declaradas

- **"Entregados" no es medible**: `ACCEPTED` solo indica aceptación del servidor; no hay DSN de entrega. No se ha estimado el dato.
- Los **7 rebotes del 28-08 sin cuerpo** no se pudieron atribuir individualmente (1 se atribuyó por asunto: ALBOLOTE).
- Las comparaciones A/B/C incluyen 24 % de leads con doble variante; las métricas "por variante" agregadas mezclan primer envío y rotación — por eso se presenta el desglose limpio (`es_rotacion=0`) en las Fases 4 y 16.
- Los números de "respuestas por SMTP" usan la atribución por `respuestas.envio_id` (correcta para las 5 humanas); las 7 rebotes sin cuerpo no contribuyen.
- La muestra de respuestas (n=5) impide cualquier test estadístico de significación entre variantes.

---

## Garantía de no-modificación

Esta auditoría se ejecutó **100 % en modo lectura**:
- Conexiones a `stats.db` con `mode=ro` (solo `SELECT` / `PRAGMA`).
- Scripts de análisis creados en `/tmp` (fuera del repositorio), sin tocar `output/`, `checkpoints/` ni tablas.
- No se envió ningún email, no se ejecutó lanzadera, no se modificaron campañas/plantillas/leads/SMTP, no se hizo deploy ni `git push`.
- Este documento es el único archivo creado (`docs/auditoria_campana_2026_08_informe_completo.md`).

**Próximo paso**: esperando instrucciones para, si procede, implementar la instrumentación propuesta en la Fase 17 (requiere aprobación explícita antes de tocar el esquema o los datos).










