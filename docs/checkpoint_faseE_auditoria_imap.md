# CHECKPOINT — FASE E: Auditoría IMAP READ-ONLY

**Fecha:** 2026-08-19
**Modo:** READ-ONLY (sin modificar producción, sin enviar, sin escribir en BD)
**Estado:** COMPLETADA

---

## 1. OBJETIVO

Conectar a los buzones reales de producción (SiteGround) por IMAP para estudiar:
- carpetas disponibles;
- UID;
- Message-ID;
- In-Reply-To;
- References;
- remitentes;
- estructura de mensajes.

Todo en modo estrictamente READ-ONLY (SELECT readonly + BODY.PEEK, sin STORE/COPY/DELETE/EXPUNGE).

---

## 2. MÉTODO

Script: `scripts/faseE_auditoria_imap.py`

- Conexión IMAP SSL a `mail.getfutprotec.com:993`.
- Credenciales leídas de la BD local `public_html/outbound/data/stats.db` (tabla `cuentas_smtp`).
- SELECT de INBOX en modo `readonly=True`.
- FETCH con `BODY.PEEK[HEADER]` (no marca mensajes como leídos).
- Cierre sin EXPUNGE (no borra nada).

---

## 3. RESULTADOS

### 3.1 Conectividad IMAP — VIABLE ✅

**Las 10 cuentas SMTP se conectan correctamente por IMAP SSL.**

| Cuenta | Login IMAP | INBOX |
|---|---|---|
| rodrigo@getfutprotec.com | OK | 0 mensajes |
| mario.ortiz@getfutprotec.com | OK | 0 mensajes |
| alvaro.ruiz@getfutprotec.com | OK | 0 mensajes |
| carlos.mora@getfutprotec.com | OK | 0 mensajes |
| javier.sanz@getfutprotec.com | OK | 0 mensajes |
| diego.navarro@getfutprotec.com | OK | 0 mensajes |
| pablo.blanco@getfutprotec.com | OK | 0 mensajes |
| gonzalo.vega@getfutprotec.com | OK | 0 mensajes |
| adrian.cano@getfutprotec.com | OK | 0 mensajes |
| sergio.gil@getfutprotec.com | OK | 0 mensajes |

**Conclusión:** SiteGround permite conexiones IMAP externas. El riesgo principal identificado en FASE D queda **descartado**.

### 3.2 Carpetas disponibles (cuenta rodrigo)

```
(\HasChildren) "." INBOX
(\HasNoChildren \Trash) "." INBOX.Trash
(\HasNoChildren \Junk) "." INBOX.Junk
(\HasNoChildren \Sent) "." INBOX.Sent
(\HasNoChildren \Drafts) "." INBOX.Drafts
(\HasNoChildren \Junk) "." INBOX.spam
(\HasNoChildren \Archive) "." INBOX.Archive
```

**Nota:** Existen DOS carpetas de spam: `INBOX.Junk` y `INBOX.spam`. La implementación IMAP debe contemplar ambas para no perder respuestas que caigan en spam.

### 3.3 Estado de los buzones — TODOS VACÍOS

- INBOX: 0 mensajes en las 10 cuentas.
- INBOX.Junk: 0 mensajes.
- INBOX.spam: 0 mensajes.
- INBOX.Sent: 0 mensajes.

**Conclusión:** Actualmente **no hay respuestas de clubes en ningún buzón**. Esto es coherente con el estado del motor (pausado, solo 31 envíos REAL de campaña 2). No hay respuestas que procesar todavía.

---

## 4. IMPLICACIONES PARA LA IMPLEMENTACIÓN

### 4.1 IMAP es viable ✅
La infraestructura está lista. Se puede construir el módulo de respuestas IMAP sin riesgo de conectividad.

### 4.2 No hay datos reales para probar
La FASE F (registro de respuestas) podrá construirse, pero **no habrá respuestas reales que procesar** hasta que los clubes respondan. Se recomienda:
- Probar con mensajes de prueba (enviar un email de prueba a la cuenta y verificar que se detecta).
- Validar la idempotencia con mensajes duplicados simulados.

### 4.3 Carpetas de spam
La implementación debe auditar `INBOX`, `INBOX.Junk` y `INBOX.spam` para no perder respuestas de clubes que caigan en spam.

### 4.4 Atribución
Como no hay mensajes reales, no se pudo validar empíricamente el patrón `In-Reply-To`/`References` con respuestas reales de clubes. La lógica de atribución debe basarse en el estándar (In-Reply-To → References → remitente → Message-ID) y validarse con mensajes de prueba.

---

## 5. RECOMENDACIONES PARA FASE F

1. **Construir el módulo IMAP** sobre la base confirmada (host `mail.getfutprotec.com:993`, IMAP SSL).
2. **Auditar múltiples carpetas** (INBOX + Junk + spam) por cuenta.
3. **Idempotencia** por Message-ID + UID + cuenta+UID.
4. **Probar con mensajes de prueba** antes de depender de respuestas reales.
5. **No mover mensajes** de spam a INBOX automáticamente sin revisión humana (evitar falsos positivos).
6. **Registrar la carpeta de origen** en el evento de respuesta para trazabilidad.

---

## 6. ARCHIVOS

- Script de auditoría: `scripts/faseE_auditoria_imap.py`
- Este checkpoint: `docs/checkpoint_faseE_auditoria_imap.md`

---

## 7. GARANTÍA READ-ONLY

Durante esta fase:
- ✅ No se modificó producción.
- ✅ No se enviaron emails.
- ✅ No se lanzaron campañas.
- ✅ No se hizo UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX.
- ✅ No se subió BD modificada.
- ✅ No se modificó código de producción.
- ✅ SELECT readonly + BODY.PEEK (no se marcaron mensajes como leídos).
- ✅ Cierre sin EXPUNGE (no se borró nada).
