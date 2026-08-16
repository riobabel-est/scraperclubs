# CHECKPOINT — FASE 4A: CAPTURA INBOUND (análisis pre-implementación)

**FECHA:** 2026-08-14
**ALCANCE:** Solo análisis del envío SMTP, correlación respuesta→envío, acceso IMAP y modelo mínimo de captura/clasificación. Sin cambios de código/BD. Sin envíos.

---

## 1. ARQUITECTURA SMTP ACTUAL (headers reales por motor)

Común: envío vía socket nativo (`stream_socket_client`) con AUTH LOGIN; puerto 465 (ssl) o 587 (tls).

### P1 `api/enviar_lote.php` → `enviarSMTPAutenticado()`
Cabeceras efectivas del mensaje:
- `From: {nombre_emisor|null->usuario} <{email}>`
- `Reply-To: {email}`
- `To: <{destinatario}>`
- `Subject: =?UTF-8?B?...?=`
- `MIME-Version: 1.0`
- `Content-Type: text/html; charset=UTF-8`
- `X-Mailer: FutProtec-Lanzadera/2.0`
- **Sin `Message-ID`**, **sin `In-Reply-To`**, **sin `References`**, **sin header `X-Tracking-ID`** (el tracking solo va embebido como `<img src="...track.php?id=...">`).
- `tracking_id` sí se genera y persiste en `envios.tracking_id`.

### P2 `api/enviar_smtp_random.php` → `enviarSMTPAutenticado()` (DESACTIVADO)
- `From`, `To`, `Subject`, `MIME-Version`, `Content-Type`, `X-Mailer: FutProtec-Outbound/1.0`.
- Sin Message-ID/In-Reply-To/References.

### P3 `cli/cron.php` → `enviarSMTP()`
- `From`, `To`, `Subject`, `MIME-Version`, `Content-Type`.
- Headers personalizados del array: `X-Mailer`, `X-Tracking-ID`, `X-Campaign` (y también los repite/descarta según lógica). No inyecta `Message-ID`/`In-Reply-To`/`References`.
- `tracking_id` persiste en `envios.tracking_id`.

### Firma del mensaje SMTP (enviarSMTP en cron.php)
Al construir el DATA, `cron.php` escribe los headers excepto `mime-version`, `content-type`, `from`; por tanto incluye `Reply-To`, `X-Mailer`, `X-Tracking-ID`, `X-Campaign`, `To`, `Subject`. **Ningún Message-ID propio.**

---

## 2. HEADERS REALES
| Header | P1 | P3 | Persistido |
|---|---|---|---|
| From | sí | sí | `envios.cuenta_emision` (+nombre en P1) |
| Reply-To | sí | sí | no |
| To | sí | sí | `envios.email` |
| Subject | sí | sí | `envios.asunto` |
| Message-ID | **NO** | **NO** | **NO** |
| In-Reply-To | NO | NO | NO |
| References | NO | NO | NO |
| X-Tracking-ID | NO | sí | `envios.tracking_id` (solo P3 lo manda como header) |
| X-Campaign | NO | sí (static 'outbound_v1') | `envios.campaign_id` (solo P1/P3) |

**Conclusión:** hoy NO se genera ni persiste un Message-ID recuperable. Sin él no hay correlación robusta respuesta→envío.

---

## 3. ESTRATEGIA DE CORRELACIÓN RESPUESTA→ENVÍO
Alternativas evaluadas:
- **A. Message-ID / In-Reply-To / References:** la correcta y estándar. Requiere (a) inyectar un `Message-ID` único por envío al SMTP, (b) persisitirlo en `envios` (p.ej. `envios.message_id`), y (c) al capturar inbound leer `In-Reply-To`/`References` de la respuesta. **Atribución inequívoca incluso con múltiples campañas/reenvíos.**
- **B. tracking_id:** sirve para aperturas, NO para respuestas (una respuesta de correo no lleva el tracking píxel ni el id en su texto). No es fiable para inbound.
- **C. email:** fallback ambiguo con múltiples envíos/campañas; NO usar "último envío de ese email" como regla automática.
- **D. combinación:** **recomendada = A como principal + C solo como fallback documentado**, nunca como sustituto automático.

**Recomendación:** implantar Message-ID propagado:
1. En el envío: generar `Message-ID` (p.ej. `<{tracking_id}@getfutprotec.com>` o variante con dominio del emisor) e incluir en el DATA SMTP.
2. Persistir `envios.message_id` (nueva columna). Esto conecta `tracking_id` ↔ `message_id` ↔ envío.
3. En inbound: leer `In-Reply-To`/`References` y resolver contra `envios.message_id`.

---

## 4. ACCESO IMAP (buzones entrantes)
- `cuentas_smtp` **no tiene campos IMAP** (solo: id, email, host, puerto, usuario, password, seguridad, activa, limite_diario, enviados_hoy, ultimo_error, ultimo_uso, nombre_emisor, cargo_emisor).
- `config` **no tiene claves inbound** (solo envío/test).
- **Proveedor:** por datos, SMTP es `mail.getfutprotec.com` (dominio propio FutProtec); se desconoce si el mismo servidor ofrece IMAP y en qué puerto (993/143).
- **Credenciales/configuración existentes:** solo SMTP. No hay config IMAP ni secretos inbound.
- **Múltiples cuentas:** 10 cuentas SMTP activas; una solución inbound tendría que mapear a qué buzón entrante corresponde cada una (o un buzón de captura único).
- **Polling vs webhook:** no hay webhook actual; lo más realista para SiteGround-puro es **polling IMAP** (o un webhook de terceros), fuera del piloto mínimo.
- **Riesgo de duplicados:** sí, si no hay idempotencia por Message-ID.

**Estado: NOT VERIFIED / NOT IMPLEMENTED.** No se puede afirmar disponibilidad IMAP sin datos del proveedor. No almacenar contraseñas en código.

---

## 5. PROPUESTA MÍNIMA DE CAPTURA
Para el primer piloto, **NO es imprescindible IMAP automático**. Como la clasificación será EXPLÍCITA y HUMANA, la captura mínima puede ser **manual/asistida**:
- Un endpoint/panel que registre una respuesta entrante indicando: `envio_id` (resuelto por Message-ID/In-Reply-To o, en casos manuales, elegido por el operador), `remitente`, `subject`, `fecha_respuesta`, `message_id`, `in_reply_to`, `references`, `cuerpo/resumen`, `clasificacion=PENDING`.
- Esto permite construir Positive Reply Rate sin depender de IMAP. IMAP/polling se difiere como mejora post-piloto.

## 6. IDEMPOTENCIA INBOUND
- **Identificador único recomendado:** `Message-ID` de la respuesta. Si el sistema de correo no garantiza unicidad global, usar `Message-ID + cuenta/buzón`. Unicidad garantizada con UNIQUE.
- Alternativa robusta sin confiar solo en el proveedor: `hash(message_id + from)` con UNIQUE.
- Si no hay Message-ID (raro), marcar `NOT VERIFIED` y no crear dos registros del mismo contenido.

## 7. MODELO DE DATOS (propuesta mínima a evaluar)
Nueva tabla `respuestas` (solo si se aprueba; no creada en esta fase):
| columna | tipo | notas |
|---|---|---|
| id | INTEGER PK | respuesta_id |
| envio_id | INTEGER | FK lógica → envios.id |
| lead_id | INTEGER | derivable de envios.lead_id (se copia para consulta rápida; no duplica si se normaliza) |
| campaign_id | INTEGER | derivable de envios.campaign_id |
| variant | VARCHAR(1) | derivable de envios.variant |
| fecha_respuesta | DATETIME | captura |
| remitente | TEXT | from de la respuesta |
| destinatario | TEXT | to (cuenta que recibió) |
| subject | TEXT | asunto respuesta |
| message_id | TEXT UNIQUE | id único de la respuesta |
| in_reply_to | TEXT | correlación → envios.message_id |
| references | TEXT | correlación |
| cuerpo_resumen | TEXT | para revisión humana |
| clasificacion | TEXT DEFAULT 'PENDING' | PENDING/POSITIVE/NEGATIVE/NEUTRAL/UNSUBSCRIBE/OOO |
| fecha_clasificacion | DATETIME | null hasta clasificar |
| clasificador | TEXT | usuario/operador |
| estado_procesamiento | TEXT DEFAULT 'nuevo' | nuevo/clasificado/procesado |
| externo_id | TEXT | si existe id del proveedor |

**Normalización:** `lead_id`, `campaign_id`, `variant` se pueden derivar de `envios` vía `envio_id`. Recomendación: guardar `envio_id` como FK y **no duplicar** lead/campaign/variant (mejor trazabilidad y menos riesgo de inconsistencia); incluir columnas de caché solo si se justifica por rendimiento.

**envios.message_id** (nueva columna) es necesario para correlacionar `In-Reply-To`/`References`.

## 8. CLASIFICACIÓN
- **Explícita y humana.** Valores: `PENDING, POSITIVE, NEGATIVE, NEUTRAL, UNSUBSCRIBE, OOO`.
- NO usar `estado_lead` ni Kanban como proxy. NO IA todavía.
- UNSUBSCRIBE nunca cuenta como positiva; OOO tampoco.

## 9. SUPRESIÓN
- Una respuesta clasificada `UNSUBSCRIBE` debe conectar con la lógica de supresión existente (`baja.php` → `Lista Negra`). Se documenta como integración en fase de implementación; **no se modifica el motor de envío ahora** salvo imprescindible.

## 10. MÉTRICAS (definición, no dashboard)
- Accepted SMTP = `envios.estado='enviado'` (aceptación 250; NO = entregado).
- Reply Rate = replies / accepted.
- Positive Reply Rate = POSITIVE / ACEPTADOS SMTP.
- Negative / Neutral / Unsubscribe / OOO = categoria / aceptados.
- Open Rate = aperturas únicas ligadas a envío / aceptados.
- No declarar ganador; mostrar n_A/n_B/n_C, positive_A/B/C, reply_A/B/C y tasas ("observado", no "ganador estadístico").

## 11. RIESGOS
1. Sin IMAP, el piloto depende de captura manual/asistida (posible lag humano, subregistro).
2. Sin Message-ID en envíos actuales, no se puede correlacionar inbound automáticamente; hay que añadirlo (P1/P3) y persistirlo.
3. Config IMAP inexistente → no hay credenciales inbound; no almacenar secretos en código.
4. Idempotencia exige Message-ID UNIQUE; proveedor podría colisionar.
5. Múltiples cuentas SMTP → mapeo a buzones entrantes aún no definido.

## 12. BLOQUEANTES (para cerrar antes de pilot)
1. Generar y persistir `Message-ID` en los envíos (P1/P3).
2. Definir vía de captura inbound (manual/asistida como mínimo viable, IMAP como post-piloto).
3. Tabla `respuestas` con idempotencia por Message-ID.
4. Clasificación explícita/humana con los 6 valores.
5. Integrar UNSUBSCRIBE con supresión.

## 13. Estados
- Arquitectura SMTP actual: PASS (documentada).
- Headers reales (Message-ID): FAIL (no generado/persistido).
- Estrategia de correlación: A recomendada (Message-ID) — FAIL hoy, requiere cambio.
- Acceso IMAP: NOT VERIFIED / NOT IMPLEMENTED.
- Modelo de datos: PROPUESTO (no implementado).
- Clasificación explícita: NOT IMPLEMENTED (diseñada aquí).
- Supresión inbound: NOT IMPLEMENTED (diseñada).
- Métricas: DEFINIDAS (no implementadas).

---

> Solo análisis. NO modifiqué código ni BD. NO realicé envíos. Espero aprobación explícita antes de implementar.