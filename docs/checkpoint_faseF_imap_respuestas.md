# CHECKPOINT — FASE F: Registro de respuestas IMAP

**Fecha:** 2026-08-19
**Estado:** Implementado y validado en local (dry-run + test unitario)
**Modo:** Desarrollo local — NO desplegado a producción

---

## 1. Objetivo

Implementar la **Prioridad 1** del Plan Maestro de Evolución Post-Core: leer los buzones IMAP de las cuentas SMTP y registrar las respuestas recibidas en la tabla `respuestas`, con atribución a lead/envío/campaña e idempotencia.

## 2. Contexto de partida

- El CRM ya dispone de infraestructura SMTP y acceso IMAP.
- La tabla `respuestas` ya existe en la BD (verificada en FASE D).
- No existía ningún módulo de lectura IMAP.
- El cron usa `enviarSMTP()` con sockets directos (no la extensión `mail()`), por lo que el módulo IMAP debe seguir el mismo patrón para compatibilidad SiteGround.

## 3. Archivos creados

| Archivo | Descripción |
|---|---|
| `public_html/outbound/inc/imap_respuestas.php` | Módulo IMAP: cliente IMAP por sockets, parsing, clasificación, atribución, registro con idempotencia |
| `public_html/outbound/cli/imap_respuestas.php` | CLI que recorre todas las cuentas SMTP activas y procesa sus buzones |
| `scripts/test_imap_respuestas.php` | Test unitario de parsing, clasificación, atribución e idempotencia |

## 4. Diseño del módulo IMAP

### 4.1 Compatibilidad SiteGround

- **NO depende de la extensión PHP `imap`** (puede no estar habilitada en hosting compartido).
- Usa **sockets directos** (mismo patrón que `enviarSMTP()` en `cron.php`).
- PHP 8.x nativo + SQLite3.

### 4.2 Modo READ-ONLY sobre el buzón

- `SELECT` en modo readonly (no marca mensajes como leídos).
- `FETCH` con `BODY.PEEK` (no altera el flag `\Seen`).
- NO ejecuta `STORE` / `COPY` / `MOVE` / `DELETE` / `EXPUNGE` / `APPEND`.
- La única escritura es en la BD local (tabla `respuestas` + `comunicaciones_log`).

### 4.3 Cliente IMAP (`ClienteIMAP`)

Métodos implementados:
- `conectar($user, $pass)` — conexión SSL + LOGIN.
- `listarCarpetas()` — LIST.
- `seleccionar($carpeta)` — SELECT readonly, devuelve total de mensajes.
- `buscarTodos()` — SEARCH ALL.
- `fetchCabeceras($seq)` — FETCH BODY.PEEK[HEADER].
- `fetchCuerpo($seq)` — FETCH BODY.PEEK[TEXT].
- `cerrar()` — LOGOUT.

Soporta **literales IMAP** (respuestas `{N}`) y timeouts de lectura.

### 4.4 Parsing de mensaje (`imap_parsear_mensaje`)

Extrae:
- `message_id`
- `in_reply_to`
- `references`
- `from` / `from_email`
- `to` / `to_email`
- `subject`
- `date`
- `cuerpo`

Incluye decodificación MIME (RFC 2047) para cabeceras codificadas.

### 4.5 Clasificación inicial sin IA (`imap_clasificar`)

| Clasificación | Criterio |
|---|---|
| `rebote` | remitente mailer-daemon / postmaster |
| `baja` | subject contiene unsubscribe / baja |
| `fuera_de_oficina` | subject contiene out of office / vacaciones / ausencia |
| `automatica` | subject contiene automatic reply / auto-reply |
| `desconocida` | sin In-Reply-To ni References |
| `humana` | por defecto (con In-Reply-To/References) |

### 4.6 Atribución (`imap_atribuir`)

Prioridad:
1. **In-Reply-To** → match con `envios.message_id`.
2. **References** → match con `envios.message_id` (recorre todos los refs).
3. **Email remitente** → último envío a ese email con estado `enviado`.

### 4.7 Registro con idempotencia (`imap_registrar_respuesta`)

- Verifica si el `message_id` ya existe en `respuestas` → si existe, devuelve `duplicado`.
- Inserta en `respuestas` con `estado_procesamiento = 'pendiente'`.
- Registra evento `respuesta_recibida` en `comunicaciones_log`.

## 5. CLI (`cli/imap_respuestas.php`)

```
php cli/imap_respuestas.php
php cli/imap_respuestas.php --cuenta=email@getfutprotec.com
php cli/imap_respuestas.php --dry-run
php cli/imap_respuestas.php --verbose
```

Opciones:
- `--cuenta=EMAIL` — procesar solo una cuenta.
- `--dry-run` — no escribir en BD, solo mostrar lo que se detectaría.
- `--verbose` — mostrar detalle por mensaje.

Carpetas auditadas: `INBOX`, `INBOX.Junk`, `INBOX.spam`.

## 6. Validación

### 6.1 Sintaxis PHP

```
php -l public_html/outbound/inc/imap_respuestas.php  → OK
php -l public_html/outbound/cli/imap_respuestas.php  → OK
```

### 6.2 Dry-run real (conexión IMAP)

```
php cli/imap_respuestas.php --cuenta=rodrigo@getfutprotec.com --dry-run --verbose
```

Resultado:
- ✅ Login IMAP correcto
- ✅ Carpetas INBOX, INBOX.Junk, INBOX.spam seleccionadas
- ✅ 0 mensajes en todas (coherente con FASE E)
- ✅ Sin errores

### 6.3 Test unitario (`scripts/test_imap_respuestas.php`)

**Resultado: 19 pasos, 0 fallos.**

- TEST 1 — Parsing de mensaje: 6/6 ✅
- TEST 2 — Clasificación: 6/6 ✅
- TEST 3 — Atribución: 3/3 ✅
- TEST 4 — Idempotencia: 4/4 ✅

## 7. Correcciones realizadas durante el desarrollo

1. **`references` palabra reservada en SQLite**: la columna `references` en el INSERT de `imap_registrar_respuesta` se escapó con comillas dobles (`"references"`). Detectado en el test unitario.

## 8. Seguridad

- **Atribución basada en datos verificables** (Message-ID, In-Reply-To, References, email).
- **Idempotencia** por Message-ID: un email recibido no se registra dos veces.
- **Modo READ-ONLY** sobre el buzón: no altera flags, no borra, no mueve.
- **Sin IA**: clasificación determinista y auditable.
- **No mueve Kanban automáticamente**: una respuesta humana se registra como `pendiente`; la transición a `03 Respondió` queda para decisión humana (según el plan maestro).

## 9. Pendiente / Próximos pasos

- [ ] **FASE G — Notificaciones**: avisar cuando se reciba una respuesta (🔔 NUEVA RESPUESTA).
- [ ] **FASE F.2 — Kanban**: decidir si una respuesta humana debe mover el lead a `03 Respondió` (con componente humano).
- [ ] **Deploy a producción** (solo con petición explícita del usuario).
- [ ] **Configurar cron** para ejecutar `cli/imap_respuestas.php` periódicamente (solo con petición explícita).

## 10. Notas

- El host IMAP configurado es `mail.getfutprotec.com:993` (SSL). Verificar en FASE E que coincide con el host real de las cuentas.
- Las carpetas auditadas (`INBOX.Junk`, `INBOX.spam`) pueden no existir en todas las cuentas; el módulo las ignora si no son accesibles.
- El módulo procesa todas las cuentas SMTP activas (`activa = 1`).
