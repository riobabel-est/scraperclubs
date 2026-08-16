# CHECKPOINT — FASE 4C: PANEL OPERATIVO DE RESPUESTAS + VALIDACIÓN DE ATRIBUCIÓN

**FECHA:** 2026-08-14
**ALCANCE:** Interfaz de respuestas en el CRM + endpoints de listado/contexto/clasificación + validación integral de atribución por Message-ID. Sin IMAP. Sin métricas. Sin envíos reales.

---

## 1. Objetivo
Que una respuesta pueda aparecer en el CRM, revisarse con el contexto exacto del envío original, clasificarse, activar supresión (UNSUBSCRIBE) y demostrar atribución inequívoca respuesta→envío→lead→campaña→variante.

## 2. Estado inicial
Backend de respuestas ya existía (FASE 4B): `inc/respuestas.php` (registro idempotente, Message-ID, contexto), tabla `respuestas`, columna `envios.message_id`. Faltaba UI/endpoints y resolución de correlación por In-Reply-To/References.

## 3. Archivos revisados
`inc/respuestas.php`, `inc/eligibilidad.php`, `dashboard.php`, `js/app.js`, `tabs/*.php`, `envios`, `respuestas`.

## 4. Archivos modificados
- `public_html/outbound/inc/respuestas.php` — añadidos `clasificarRespuesta()` y `resolverEnvioPorCorrelacion()`.
- `public_html/outbound/dashboard.php` — acciones AJAX `get_respuestas`, `get_respuesta`, `clasificar_respuesta` + tab "Respuestas".
- `public_html/outbound/tabs/respuestas.php` (NUEVO) — lista + modal ficha con clasificación.
- `public_html/outbound/js/app.js` — estado y métodos `loadRespuestas`, `abrirRespuesta`, `clasificarRespuesta`.
- `scripts/_test_fase4c.php` (NUEVO) — harness integral.

## 5. Cambios BD
Ninguno en esta fase (tabla `respuestas` y `envios.message_id` ya creadas en FASE 4B). No se crearon columnas/tablas nuevas.

## 6. Cambios UI
- Nueva tab "Respuestas" (lista con Club, Email, Campaña, Variante, Clasificación).
- Modal de ficha: contexto del envío original, respuesta recibida, botones de clasificación (PENDING/POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO).

## 7. Arquitectura de atribución
- `envio_id` es la única FK operativa desde UI; lead/campaña/variante/plantilla/smtp se derivan de `envios` (sin joins por email).
- Correlación estándar: `resolverEnvioPorCorrelacion(In-Reply-To, References)` → `envios.message_id`.
- Email solo como búsqueda auxiliar; nunca "último envío" automático.

## 8. Clasificación
- Valores y definiciones percomercial (POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO), PENDING por defecto.
- Rec lasificación permitida; solo actualiza clasificación/fecha/estado; no toca `envios`.

## 9. Integración UNSUBSCRIBE
- `clasificarRespuesta()` UNSUBSCRIBE → `clubes_crm.estado_lead = 'Lista Negra'` (misma fuente de supresión). Idempotente.

## 10. Tests (harness `scripts/_test_fase4c.php`)
| Test | Resultado |
|---|---|
| T1 In-Reply-To=A → envio A | PASS |
| T2 In-Reply-To=B → campaña B, variante correcta | PASS |
| T3 References=B no atribuye al último | PASS |
| T4 respuesta duplicada no duplica | PASS |
| T5 POSITIVE cuenta | PASS |
| T6 NEGATIVE no POSITIVE | PASS |
| T7 NEUTRAL no POSITIVE | PASS |
| T8 OOO no POSITIVE | PASS |
| T9 UNSUBSCRIBE activa supresión | PASS |
| T10 UNSUBSCRIBE repetido idempotente | PASS |
| T11 rec lasificar no modifica envío | PASS |
| T12 respuesta variante B | PASS |
| T13 plantilla concreta | PASS |
| T14 SMTP concreto | PASS |
| T15 Message-ID no cambia tras retry | PASS |

**RESUMEN: 15/15 PASS.**

## 11. Resultado de cada test
Ver §10 (todos PASS).

## 12. Regresión
- `scripts/_test_fase2b.php`: 9/9 PASS.
- `scripts/_test_fase3.php`: 23/23 PASS.
- `scripts/_test_fase4b.php`: 15/15 PASS.
- BD real intacta: envios=2, respuestas=0.

## 13. Riesgos
1. La captura sigue siendo manual/asistida (sin IMAP) → posible lag humano.
2. La correlación por Message-ID depende de que P1/P3 generen y persistan `message_id` (ya implementado); envíos legacy tienen `message_id=NULL`.

## 14. Limitaciones
- No hay auditoría histórica de cambios de clasificación (solo estado actual + fecha). Registrado para FUTURE_IMPROVEMENTS.
- No hay UI para "crear" una respuesta manual (solo visualizar/clasificar); la creación manual se hace vía lógica `registrarRespuesta()` (a conectar en fase posterior si se desea).

## 15. FUTURE_IMPROVEMENTS
- Auditoría de cambios de clasificación (historial de reclasificación).
- UI de creación manual de respuesta (pegar un correo entrante y asociarlo a un envío buscando por email/club).
- IMAP/polling automático (post-piloto).

## 16. Conclusión
**PASS** — El panel operativo de respuestas permite visualizar el contexto del envío, clasificar, conservar la trazabilidad, activar supresión UNSUBSCRIBE y la atribución por Message-ID/In-Reply-To está validada de forma inequívoca mediante 15 tests integrales. No se realizaron envíos reales ni se tocó la BD productiva (solo se añadió la UI y endpoints).

> NO avanzo a FASE 5. No implemento métricas/IMAP/click/IA. Detenido a espera de aprobación.