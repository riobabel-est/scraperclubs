# Checkpoint — Módulo IMAP/POP3 Sync Automático de Respuestas y Cancelación de Secuencias

**Fecha:** 2026-08-20
**Alcance:** `/public_html/outbound/`
**Objetivo:** Resolver el fallo de timeout por manejo de literales IMAP en SiteGround, procesar las respuestas entrantes en SQLite y detener automáticamente los follow-ups de los leads que hayan respondido.

---

## Resumen de cambios aplicados

### FASE 1 — Refactorización del socket IMAP con ENVELOPE
**Archivo:** `public_html/outbound/inc/imap_respuestas.php`

- Nuevo método `fetchEnvelopeCompleto(string $seq)` que ejecuta el comando único:
  `FETCH <seq> (UID ENVELOPE FLAGS)`.
- Nuevo método `extraerUID(array $resp)` que extrae el UID de la respuesta FETCH
  (para idempotencia cuenta+UID sin una ronda extra de `FETCH UID`).
- `imap_procesar_buzon()` y el CLI usan ahora `fetchEnvelopeCompleto()` como fuente
  PRIMARIA de metadatos (Message-ID, In-Reply-To, From, Subject, Date, UID).
- Matching en SQLite: `imap_atribuir()` busca en `envios` por `In-Reply-To` →
  `References` → email remitente.
- Nueva función `imap_detener_secuencia(SQLite3 $db, int $leadId)` que actualiza
  `secuencia_lead` a `estado='DETENIDO'` y `motivo='RESPUESTA_IMAP'` (idempotente).

### FASE 2 — Corrección del parser de literales IMAP `{N}`
**Archivo:** `public_html/outbound/inc/imap_respuestas.php`

- `leerRespuesta()` detecta la sintaxis `{N}` al final de una línea de respuesta IMAP,
  extrae el entero $N y consume EXACTAMENTE $N bytes con `fread()` (sin esperar `\n`).
- Nuevo método `leerBytes(int $n)` que lee el bloque literal completo respetando el
  timeout de socket.
- Timeout estricto de 5 segundos: `$IMAP_TIMEOUT = 5` y `stream_set_timeout($socket, 5)`.
- Degradado elegante: si `BODY.PEEK[TEXT]` o `BODY.PEEK[HEADER]` falla/timeout, se
  captura la excepción, se reconecta y se mantienen los datos de ENVELOPE (Fase 1).
- El cuerpo procesado se guarda en la columna `cuerpo` de `respuestas`.

### FASE 3 — Fallback secundario con protocolo POP3 (puerto 995)
**Archivo:** `public_html/outbound/inc/pop3_respuestas.php` (NUEVO)

- Clase `ClientePOP3` sobre SSL (`ssl://mail.getfutprotec.com:995`).
- Secuencia estándar POP3: `USER`, `PASS`, `STAT`, `LIST`, `TOP <n> 10`, `RETR <n>`.
- `TOP <msg_num> 10` lee solo las primeras 10 líneas de cabecera para extraer
  Message-ID, In-Reply-To y From.
- `RETR <msg_num>` extrae el cuerpo completo finalizado en la línea `.\r\n`.
- Modificador de driver: columna `driver_sync` en `cuentas_smtp` (default `'IMAP'`).
  El CLI selecciona POP3 si `driver_sync='POP3'`.

### FASE 4 — Automatización, logs y deploy
**Archivo:** `public_html/outbound/cli/imap_respuestas.php`

- Logs con marcas de tiempo en `public_html/outbound/logs/imap_sync.log` (helper `imap_log()`).
- Formato de log resumido por cuenta:
  `[ACCOUNT: info@getfutprotec.com] OK: 25 mensajes analizados, 2 respuestas detectadas, 2 secuencias detenidas.`
- Invocable sin interacción humana mediante Cron Job:
  `php /ruta/absoluta/public_html/outbound/cli/imap_respuestas.php > /dev/null 2>&1`
- Migración idempotente de la columna `driver_sync` en `cuentas_smtp`.

---

## Verificación de sintaxis (local)

```
php -l public_html/outbound/inc/imap_respuestas.php   → No syntax errors
php -l public_html/outbound/inc/pop3_respuestas.php   → No syntax errors
php -l public_html/outbound/cli/imap_respuestas.php   → No syntax errors
```

---

## Verificación funcional pendiente (en SiteGround)

1. **FASE 1:** `php public_html/outbound/cli/imap_respuestas.php`
   - Comprobar que no hay timeouts, que se conecta, lee `ENVELOPE` en <3s y detiene
     la secuencia del lead correspondiente en SQLite.
2. **FASE 2:** Ejecutar contra un buzón con mensajes reales de prueba. Confirmar que
   el bucle procesa los literales `{N}` sin quedarse colgado a los 120 segundos.
3. **FASE 3:** Test con POP3 enviando un correo de respuesta. Verificar que el cuerpo
   se descarga limpiamente sin cuelgues de buffer.
4. **FASE 4:** Configurar el Cron Job y revisar `logs/imap_sync.log`.

---

## Notas de compatibilidad SiteGround

- Sin extensiones PECL externas (no depende de la extensión PHP `imap`).
- PHP 8.x nativo + SQLite3.
- Sockets directos (mismo patrón que `enviarSMTP()` en `cron.php`).
- MODO READ-ONLY sobre el buzón: no se ejecuta STORE/COPY/MOVE/DELETE/EXPUNGE/DELE.
