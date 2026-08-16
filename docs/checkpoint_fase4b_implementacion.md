# CHECKPOINT — FASE 4B: IMPLEMENTACIÓN DE CAPTURA/CLASIFICACIÓN DE RESPUESTAS

**FECHA:** 2026-08-14
**ALCANCE:** Message-ID persistente en envíos, tabla `respuestas` idempotente, clasificación explícita/humana, supresión UNSUBSCRIBE. Sin IMAP. Sin dashboard. Sin envíos reales.

---

## 1. Backup
- `public_html/outbound/backups/fase4b_pre_20260814_220408.db` (consistente, 16 tablas, envios=2). PASS.

## 2. Migraciones
- `envios.message_id` (TEXT, NULL). Añadida condicionalmente (no re-añadida si existía).
- Nueva tabla `respuestas` con columnas: `id`, `envio_id`, `fecha_respuesta`, `remitente`, `destinatario`, `subject`, `cuerpo`, `message_id`, `in_reply_to`, `references`, `clasificacion`, `fecha_clasificacion`, `estado_procesamiento`, `creado_el`.
- Índice único parcial `idx_respuestas_message_id` (`message_id` WHERE NOT NULL) + índice `idx_respuestas_envio`.
- Nota: `references` es palabra reservada de SQLite; se citó como `"references"` en DDL e INSERT.

## 3. Archivos modificados
- `public_html/outbound/inc/respuestas.php` (NUEVO) — `generarMessageIdEnvio()`, `registrarRespuesta()`, `contextoEnvio()`, `CLASIFICACIONES_VALIDAS`.
- `public_html/outbound/inc/eligibilidad.php` — `reservarEnvioLogico()` ahora persiste `message_id` (derivado del tracking_id, inmutable en retries) y requiere `respuestas.php`.
- `public_html/outbound/api/enviar_lote.php` — lee `message_id` de la fila reservada y lo inyecta como header `Message-ID` en SMTP.
- `public_html/outbound/cli/cron.php` — añade `Message-ID` a los headers SMTP desde la fila reservada.
- `scripts/_test_fase4b.php` (NUEVO) — harness.

## 4. Modelo
- `envio_id` es la relación principal; `lead_id`/`campaign_id`/`variant`/`plantilla_id`/`smtp_id` NO se duplican en `respuestas` (se obtienen de `envios` vía `contextoEnvio`).

## 5. Message-ID
- `generarMessageIdEnvio(trackingId, smtpEmail)` → `<{tracking_id}@{dominio}>`, válido para email.
- Se persiste en `envios.message_id` en la reserva; un retry (mismo envío lógico) conserva el mismo valor porque la fila ya existe.

## 6. Captura manual/asistida
- `registrarRespuesta()` (backend) registra una respuesta con `envio_id` y los datos de la respuesta. No requiere introducir manualmente lead/campaign/variant (se derivan).
- La interfaz visual mínima no se construyó (fuera del alcance funcional inmediato), pero el endpoint de lógica está listo para conectarse.

## 7. Idempotencia
- Prioridad: `message_id` de la respuesta (UNIQUE). Fallback: hash estable `h:sha1(envio_id|remitente|cuerpo)` si no hay message_id.
- `registrarRespuesta()` detecta duplicado y no inserta segunda fila. PASS (T4).

## 8. Clasificación
- Definidas: PENDING, POSITIVE, NEGATIVE, NEUTRAL, UNSUBSCRIBE, OOO.
- PENDING no cuenta como positiva; NEGATIVE/NEUTRAL/OOO tampoco. UNSUBSCRIBE activa supresión.
- Definiciones (documentadas en FASE 4B): POSITIVE = interés comercial o voluntad de avanzar; NEGATIVE = rechazo explícito; NEUTRAL = sin señal clara; UNSUBSCRIBE = baja; OOO = ausencia.

## 9. Supresión
- UNSUBSCRIBE → `clubes_crm.estado_lead = 'Lista Negra'` (la MISMA fuente que `baja.php`). No se creó segunda lista negra.
- T9 verificado: lead pasa a `Lista Negra`.

## 10. Trazabilidad
- `respuesta → envio_id → lead_id/campaign_id/variant/plantilla_id/smtp_id` sin joins por email. `contextoEnvio()` lo resuelve.

## 11. Tests (harness `scripts/_test_fase4b.php`)
| Test | Resultado |
|---|---|
| T1 envío con Message-ID | PASS |
| T2 retry misma identidad lógica | PASS |
| T3 contexto envio (lead/campaign/variant) | PASS |
| T4 message_id duplicado no duplica | PASS |
| T5 PENDING no POSITIVE | PASS |
| T6 POSITIVE | PASS |
| T7 NEGATIVE no POSITIVE | PASS |
| T8 NEUTRAL no POSITIVE | PASS |
| T9 UNSUBSCRIBE activa supresión | PASS |
| T10 OOO no POSITIVE | PASS |
| T11 sin envio_id no auto-atribuye | PASS |
| T12 campaña A variant | PASS |
| T13 campaña B variant | PASS |
| T14 P1/P3 formato Message-ID | PASS |
| T15 snapshot histórico intacto | PASS |

**RESUMEN: 15/15 PASS.**

## 12. Riesgos
1. Sin IMAP, la captura depende de registro manual/asistido (posible subregistro).
2. `references` reservada en SQLite queda citada en DDL/INSERT; validado.
3. `message_id` fallback por hash podría colisionar teóricamente en cuerpos idénticos del mismo remitente+envío; aceptable para captura manual.

## 13. Limitaciones
- No hay interfaz UI de captura todavía (se entregará en fase de panel); la capa de lógica está completa.
- No se implementó IMAP/POP/webhook/IA/click tracking (fuera de alcance).
- KPI (Positive Reply Rate) no se calcula aún (pendiente de dashboard/fase de métricas).

## 14. Estados
- Backup: PASS
- Migración: PASS
- Message-ID: PASS
- Idempotencia: PASS
- Clasificación: PASS
- Supresión: PASS
- Trazabilidad: PASS
- Captura manual: PASS WITH LIMITATIONS (lógica lista, UI pendiente)
- IMAP/click/IA/dashboard: NOT IMPLEMENTED (fuera de alcance)

---

> NO avanzo a dashboard/IMAP/metrics. NO realizo envíos reales. Espero aprobación.