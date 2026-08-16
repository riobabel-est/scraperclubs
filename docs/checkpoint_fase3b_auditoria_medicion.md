# CHECKPOINT — FASE 3B: AUDITORÍA DE MEDICIÓN (solo análisis)

**FECHA:** 2026-08-14
**ALCANCE:** Determinar qué puede medir hoy el CRM y qué falta para medir el A/B/C. Sin cambios de código/BD. Sin envíos reales.

---

## 1. INVENTARIO

### Tracking de apertura
- **Endpoint:** `api/track.php` (píxel PNG 1x1).
- **Parámetro:** `?id=TRACKING_ID`.
- **Flujo:** valida `tracking_id` existe en `envios` → inserta en `aperturas` (`tracking_id`, `fecha_apertura`, `ip`, `user_agent`) → actualiza `envios.estado='abierto'` si estaba `enviado`.
- **Tabla:** `aperturas` (FK `tracking_id` → `envios.tracking_id`).
- **Relación con envio:** vía `tracking_id` (sólida).
- **Relación con lead/campaña/variante:** INDIRECTA (hay que unir `aperturas → envios` por tracking_id). `aperturas` no guarda `lead_id`, `campaign_id`, `variant` ni `plantilla_id`.
- **Primera/última/contador:** se inserta una fila por cada petición del píxel; no hay campo "primera apertura" explícito (se puede derivar con MIN/MAX/COUNT por tracking_id). **PARTIAL.**

### Click tracking
- **NOT IMPLEMENTED.** No existe endpoint, tabla ni `link_id`. No hay tabla `clicks` en el esquema (16 tablas verificadas en FASE 0). **FAIL.**

### Respuestas (inbound)
- **No hay IMAP/POP/webhook.** No existen `imap_open`, `stream_socket_client` hacia buzones entrantes, ni endpoint de webhook de respuestas.
- No hay cabeceras de correlación en el envío: no se inyecta `Message-ID`, ni se guarda `In-Reply-To`, `References`.
- Lo único existente es **registro manual** de interacciones (`dashboard.php registrar_interaccion` → `comunicaciones_log` con `tipo_evento`, `resultado`, `resumen`, `proxima_accion`, `canal`). No captura el correo real ni lo asocia por Message-ID. **FAIL / NOT IMPLEMENTED.**

### Clasificación
- **No hay clasificación explícita** POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO.
- No hay columna de clasificación. `comunicaciones_log.resultado` existe (TEXT libre) pero no se usa como clasificador.
- Se usa `estado_lead` como proxy (ver §5). **NOT IMPLEMENTED.**

### Estado del lead
- `clubes_crm.estado_lead` es un campo global único.
- Código que lo modifica: `dashboard.php update_lead` (Kanban drag), `add_lead`, `enviar_lote.php` (→ `02 Contactado`), `cron.php` (→ `02 Contactado`), `baja.php` (→ `Lista Negra`), `init_db.php mapEstadoLegacy`, `mockup_solicitar`/`mockup_enviado`/`presupuesto_crear`.
- **Proxy actual para "respuesta positiva":** `dashboard.php` (funnel y A/B/C) usa `stageOrder >= 5` (equivalente a `04 Interesado`) como "Resp. Positivas" y `stageOrder >= 4` como "Respondió". Esto es EXACTAMENTE el patrón `estado_lead >= 5` prohibido por requisito. **FAIL (como métrica fiable).**

---

## 2. ARCHIVOS
- `api/track.php`, `api/enviar_lote.php`, `api/enviar_smtp_random.php`, `cli/cron.php`, `cli/init_db.php`
- `api/leads.php`, `api/smtp.php`, `api/baja.php`, `api/get_cola.php`
- `dashboard.php`, `tabs/analytics.php`, `tabs/modals.php`, `tabs/lanzadera.php`, `js/app.js`
- `inc/eligibilidad.php`, `inc/abc.php`

## 3. TABLAS
`envios`, `aperturas`, `rebotes`, `comunicaciones_log`, `clubes_crm`, `pipelines`, `lead_pipelines`, `plantillas`, `mockups`, `presupuestos`, `snapshots`, `cuentas_smtp`, `config`, `_migraciones`, `plantillas_new`, `sqlite_sequence`.

## 4. ENDPOINTS
`track.php` (apertura), `baja.php` (baja), `smtp.php` (test conexión), `leads.php` (CRUD/lead/timeline/config), `get_cola.php` (cola), `enviar_lote.php` (envío P1), `enviar_smtp_random.php` (P2 desactivado), `cron.php` (P3), `dashboard.php` (AJAX multiple).

## 5. FLUJO ACTUAL (medición)
```
P1/P3 → reservarEnvioLogico → envios (lead_id, campaign_id, variant, plantilla_id, smtp_id, asunto, cuerpo_mensaje, tracking_id, estado)
       → SMTP → estado 'enviado' | 'error'
píxel → track.php → aperturas (tracking_id) → envios.estado='abierto'
(no hay inbound) → NADA automático
manual → estado_lead (Kanban) / registrar_interaccion
```

## 6. TRAZABILIDAD (evaluada por separado)
| Relación | Estado | Notas |
|---|---|---|
| RESPUESTA → ENVÍO | **FAIL** | No se capturan respuestas; no hay Message-ID/In-Reply-To/References. |
| ENVÍO → lead_id | **PASS** | `envios.lead_id` (FASE 1); 2 legacy backfilleados. |
| ENVÍO → campaign_id | **PASS** | `envios.campaign_id` (P1/P3). |
| ENVÍO → variant | **PASS** | `envios.variant` determinista (FASE 3A). |
| ENVÍO → plantilla_id | **PASS** | `envios.plantilla_id`. |
| ENVÍO → smtp_id | **PASS** | `envios.smtp_id`. |
| APERTURA → ENVÍO | **PASS** | `aperturas.tracking_id` → `envios.tracking_id`. |
| APERTURA → lead/campaña/variante | **PARTIAL** | requiere JOIN vía envios; no hay campos directos en aperturas. |

Consideraciones:
- **Múltiples envíos al mismo email:** la unión por `email` entre `envios` y `clubes_crm`/`aperturas` se vuelve ambigua con reenvíos; ahora `envios.lead_id` resuelve la identidad del envío, pero `aperturas`/`rebotes` siguen sin `lead_id`.
- **Campañas diferentes:** `envios.campaign_id` lo soporta (mismo lead puede estar en varias campañas).
- **Respuestas tardías / aliases / In-Reply-To / References:** no soportado (no hay inbound ni correlación). **FAIL.**

## 7. TRACKING (aperturas)
- PARTIAL: registra apertura por píxel, timestamp, IP, UA; sin deduplicación robusta por lead (cada petición inserta fila), sin `lead_id`/`campaign_id` directos, y sin link tracking.

## 8. RESPUESTAS
- **NOT IMPLEMENTED** (sin IMAP/POP/webhook, sin captura, sin correlación por Message-ID).

## 9. CLASIFICACIÓN
- **NOT IMPLEMENTED.** No hay POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO. Se usa `estado_lead` como proxy (no válido).

## 10. MÉTRICAS (qué permite hoy)
| Métrica | Estado | Notas |
|---|---|---|
| ACEPTADOS POR SMTP | **PARTIAL** | `envios.estado='enviado'` = aceptación 250; NO es "entregado". |
| OPEN RATE | **PARTIAL** | `aperturas` join `envios`; sin dedup por lead sólida y sin click. |
| CLICK RATE | **FAIL** | no implementado. |
| RESPUESTAS | **FAIL** | no capturadas. |
| RESPUESTAS POSITIVAS | **FAIL** | proxy `estado_lead>=5`, prohibido. |
| NEGATIVAS / NEUTRALES / UNSUB / OOO | **FAIL** | no implementado. |

Separación conceptual exigida:
- **ENTREGA** → solo SMTP accept (no 250=entregado).
- **ENGAGEMENT** → apertura/click (open ≠ interés).
- **INTERÉS COMERCIAL** → requiere captura + clasificación reales.

## 11. DASHBOARD (auditado, sin modificar)
- **Tablas/consultas:** `clubes_crm`, `envios` (unión por `email` en funnel/A/B/C/followups), `lead_pipelines` (A/B/C comparativa), `aperturas`, `rebotes`, `presupuestos`, `mockups`, `config`.
- **A/B/C:** calcula por `lead_pipelines.variante_ab` → **desconectado de `envios.variant` real** (lead_pipelines no se alimenta). **FAIL.**
- **Interpreta respuestas:** vía `stageOrder` (estado Kanban) como proxy. **FAIL.**
- **Ganadora:** `leads >= 5` por variante (umbral simplista prohibido). **FAIL.**
- **Mezcla TEST/producción:** parcialmente excluye por `nombre_club NOT LIKE '%TEST%'` (heurística, no por `entorno`/`campaign`). **PARTIAL.**

## 12. IMAP
- **NOT IMPLEMENTED** (sin lectura de buzones entrantes).

## 13. CLICK TRACKING
- **NOT IMPLEMENTED** (sin tabla `clicks`, sin endpoint).

## 14. RIESGOS
1. Medir A/B/C hoy produciría conclusiones falsas (dashboard lee `lead_pipelines` vacías de realidad; respuestas no existen).
2. `estado_lead` es estado global único; no histórico por campaña.
3. `aperturas` sin `lead_id`/`campaign_id`; unión por email frágil con reenvíos.
4. Sin Message-ID/In-Reply-To/References no se puede correlacionar respuesta con envío.
5. 250 OK se confunde con "entregado" (el sistema no registra rebotes automáticos).

## 15. DISCREPANCIAS
- Documentación (spec V4.3) habla de A/B/C trazable por `lead_pipelines` y de métricas; el código real del dashboard no se conecta a `envios.variant` ni captura respuestas.
- `comunicaciones_log.resultado` (TEXT) no es un clasificador; no se usa para POSITIVE/etc.

## 16. BLOQUEANTES PARA PILOTO
1. **No hay captura de respuestas** (inbound ausente) → no se puede calcular Positive Reply Rate.
2. **No hay clasificación explícita** → el proxy `estado_lead` no es válido.
3. **Dashboard A/B/C desconectado** → lee `lead_pipelines` no alimentada.
4. **Criterio de ganadora simplista** (`leads>=5`).
5. **Unión por email frágil** para aperturas/respuestas; falta propagar `lead_id`/`campaign_id`.
6. **250 OK ≠ entregado** y open ≠ interés: separación conceptual no implementada en métricas.

## 17. MEJORAS POST-PILOTO (no ahora)
- Click tracking (tabla `clicks`: link_id, tracking_id, timestamp).
- IMAP/inbound para capturar respuestas reales y correlación por Message-ID/In-Reply-To/References.
- Almacenar `lead_id`/`campaign_id` en `aperturas`/`rebotes`.
- Clasificador explícito POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO.
- Dashboard que lea `envios` real (no `lead_pipelines`) y muestre n_A/n_B/n_C + positivas y tasas.

---

### Estados de capacidades
- Tracking aperturas: PARTIAL
- Click tracking: NOT IMPLEMENTED
- Respuestas: NOT IMPLEMENTED
- Clasificación: NOT IMPLEMENTED
- estado_lead como proxy: FAIL (para métrica fiable)
- Trazabilidad envío→lead/campaña/variante/plantilla/smtp: PASS
- Trazabilidad respuesta→envío: FAIL
- A/B/C atribución de respuesta a variante: FAIL (respuestas no capturadas)
- Dashboard A/B/C: FAIL (fuente incorrecta + umbral simplista)
- Métricas POSITIVE/NEGATIVE/NEUTRAL/UNSUB/OOO: FAIL
- Métricas OPEN/CLICK: PARTIAL / FAIL

> Solo análisis. No modifiqué PHP/JS/BD. No realicé envíos. Espero aprobación.