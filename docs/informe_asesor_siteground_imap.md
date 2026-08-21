# INFORME PARA ASESOR EXTERNO — Restricciones IMAP en SiteGround

**Fecha:** 20/08/2026
**Proyecto:** FutProtec CRM Outbound
**Módulo afectado:** Registro automático de respuestas de email (IMAP)
**Solicitante:** Equipo FutProtec
**Objetivo del informe:** Que un asesor externo investigue en internet si SiteGround impone restricciones a las conexiones IMAP que expliquen el comportamiento observado, y proponga soluciones.

---

## 1. RESUMEN EJECUTIVO

Estamos desarrollando un módulo que lee automáticamente los buzones de correo de nuestras cuentas (alojadas en SiteGround) para registrar en nuestro CRM las respuestas que recibimos a nuestros emails comerciales.

El módulo funciona **parcialmente**: puede conectarse al servidor IMAP y listar los mensajes, pero **se cuelga (timeout) cuando intenta leer el contenido de un mensaje concreto** (cabeceras o cuerpo). Solo responde de forma fiable a un comando ligero que devuelve metadatos (remitente, asunto, message-id), pero **no el cuerpo del mensaje**.

Necesitamos saber si esto es una **restricción impuesta por SiteGround** (límites de conexión, de tamaño, de tiempo, de comandos) o un problema de nuestra implementación.

---

## 2. QUÉ NECESITAMOS HACER (REQUISITO FUNCIONAL)

Queremos que un script PHP (alojado en el mismo hosting SiteGround) haga lo siguiente de forma automática y periódica:

1. Conectarse por **IMAP sobre SSL** (puerto 993) a `mail.getfutprotec.com`.
2. Autenticarse con las credenciales de cada cuenta de correo.
3. Seleccionar las carpetas `INBOX`, `INBOX.Junk`, `INBOX.spam`.
4. Listar los mensajes.
5. **Leer de cada mensaje** los siguientes campos:
   - `Message-ID`
   - `In-Reply-To`
   - `References`
   - `From` (remitente)
   - `To` (destinatario)
   - `Subject` (asunto)
   - `Date` (fecha)
   - **Idealmente, el cuerpo del mensaje** (texto plano y/o HTML) para poder mostrar la respuesta en el CRM.
6. Registrar esos datos en una base de datos SQLite local.

**Importante:** el script se ejecuta **en el mismo servidor** donde están alojados los buzones (mismo hosting SiteGround), no desde un servidor externo.

---

## 3. QUÉ ESTÁ FALLANDO (SÍNTOMAS OBSERVADOS)

Hemos implementado un cliente IMAP mínimo por sockets en PHP (sin la extensión `imap`, porque no está garantizada en SiteGround). El comportamiento observado es:

| Comando IMAP | Resultado |
|---|---|
| `LOGIN` | ✅ Funciona |
| `LIST "" "*"` | ✅ Funciona |
| `SELECT INBOX` | ✅ Funciona |
| `SEARCH ALL` | ✅ Funciona |
| `FETCH <seq> (ENVELOPE)` | ✅ Funciona (devuelve metadatos) |
| `FETCH <seq> (UID)` | ⚠️ A veces se cuelga |
| `FETCH <seq> (BODY.PEEK[HEADER])` | ❌ **Se cuelga (timeout)** |
| `FETCH <seq> (BODY.PEEK[TEXT])` | ❌ **Se cuelga (timeout)** |
| `FETCH <seq> (BODY.PEEK[HEADER.FIELDS (...)] )` | ❌ **Se cuelga (timeout)** |

**Síntoma clave:** cualquier comando que pida **el contenido del mensaje** (cabeceras completas o cuerpo) provoca que el servidor no responda y la conexión se quede colgada hasta el timeout (120 segundos). El comando `ENVELOPE`, que solo devuelve metadatos, sí responde.

Esto ocurre **incluso con mensajes pequeños** (un email de prueba de pocos KB).

---

## 4. LO QUE NECESITAMOS QUE EL ASESOR INVESTIGUE

Por favor, investiga en internet (documentación oficial de SiteGround, foros, Stack Overflow, etc.) y responde a estas preguntas concretas:

### 4.1 Restricciones de SiteGround sobre IMAP
1. ¿SiteGround impone **límites de conexiones IMAP simultáneas** por cuenta o por IP?
2. ¿SiteGround limita el **tamaño máximo** de mensaje que se puede recuperar por IMAP?
3. ¿SiteGround limita el **tiempo de ejecución** de comandos IMAP (timeout del servidor)?
4. ¿SiteGround bloquea o restringe comandos IMAP específicos como `BODY.PEEK[HEADER]`, `BODY.PEEK[TEXT]` o `FETCH` de partes del mensaje?
5. ¿Hay alguna **política de SiteGround** que impida leer el cuerpo de los mensajes vía IMAP desde el propio hosting (por ejemplo, para evitar abuso o consumo de recursos)?
6. ¿SiteGround usa algún **proxy o balanceador** delante del servidor IMAP que pueda cortar conexiones largas o comandos pesados?

### 4.2 Configuración recomendada
7. ¿Cuál es la **configuración IMAP correcta** para SiteGround (host, puerto, SSL/TLS, autenticación)?
8. ¿SiteGround recomienda usar la **extensión PHP `imap`** en lugar de sockets crudos? ¿Está disponible en los planes compartidos (StartUp/GrowBig)?
9. ¿Hay alguna **alternativa** recomendada por SiteGround para leer el correo programáticamente (por ejemplo, API, webhooks, POP3, o un servicio externo)?

### 4.3 Soluciones posibles
10. ¿Es viable usar **POP3** en lugar de IMAP para leer los mensajes (aunque sea menos flexible)?
11. ¿Existe alguna **configuración del servidor de correo** (Dovecot/Exim) que deba activarse para permitir la lectura del cuerpo vía IMAP?
12. ¿Hay algún **límite de tamaño de mensaje** configurable en SiteGround que esté causando el timeout?

---

## 5. CONTEXTO TÉCNICO ADICIONAL (para el asesor)

### 5.1 Entorno
- **Hosting:** SiteGround (plan compartido).
- **Servidor de correo:** `mail.getfutprotec.com`.
- **Puerto IMAP:** 993 (SSL).
- **Lenguaje:** PHP 8.x (nativo, sin extensiones PECL).
- **Base de datos:** SQLite3.
- **El script se ejecuta en el mismo hosting** donde están los buzones.

### 5.2 Cómo estamos conectando (resumen)
- Conexión por socket SSL (`stream_socket_client` con `ssl://`).
- Autenticación con `LOGIN` usando literales IMAP.
- Lectura de respuestas con manejo de literales `{N}`.
- Timeout de socket configurado a 120 segundos.

### 5.3 Código relevante
El cliente IMAP está en `public_html/outbound/inc/imap_respuestas.php`. Los comandos que se cuelgan son los que usan `BODY.PEEK[...]`.

### 5.4 Lo que ya funciona
- Conexión, login, listado de carpetas, selección, búsqueda y `ENVELOPE`.
- Con `ENVELOPE` podemos obtener remitente, asunto, message-id, in-reply-to y fecha, **pero no el cuerpo**.

---

## 6. PREGUNTA PRINCIPAL PARA EL ASESOR

> **¿SiteGround impone restricciones que impiden a un script PHP alojado en el mismo hosting leer el cuerpo (o las cabeceras completas) de los mensajes de correo vía IMAP, y si es así, cuál es la forma correcta de hacerlo (configuración, extensión imap, POP3, API, o servicio externo)?**

---

## 7. QUÉ NECESITAMOS COMO RESPUESTA

1. Confirmación de si es una **restricción de SiteGround** o un **problema de nuestra implementación**.
2. La **configuración IMAP correcta** para SiteGround.
3. La **solución recomendada** para poder leer el cuerpo de los mensajes (o, si no es posible, la mejor alternativa).
4. Referencias/documentación oficial de SiteGround que respalden la respuesta.

---

*Fin del informe.*
