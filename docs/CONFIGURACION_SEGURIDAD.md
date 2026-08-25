# CONFIGURACIÓN DE SEGURIDAD — Centro único de secretos

**Fecha:** 2026-08-25
**Ámbito:** `public_html/outbound/` (CRM FutProtec)
**Objetivo:** dejar asentado **dónde se guarda cada credencial** y cómo rotarla.

---

## 1. Arquitectura de secretos (2 niveles)

```
NIVEL 1 · inc/secret.php          → secretos ESTÁTICOS de la aplicación
        (gitignored + bloqueado por .htaccess)

NIVEL 2 · BD SQLite (stats.db)    → credenciales de SERVICIOS, cifradas
        (tablas cuentas_smtp y config)  con AES-256-GCM (prefijo FP1:)
        usando la 'clave' de secret.php
```

- El cifrado/descifrado lo hace **`inc/crypto.php`** (`futprotec_cifrarPassword` /
  `futprotec_descifrarPassword` / `futprotec_estaCifrado`).
- `futprotec_descifrarPassword()` es **retrocompatible**: si un valor no tiene
  prefijo `FP1:` (texto plano legacy), lo devuelve tal cual. Permite migrar sin
  romper nada.

---

## 2. Inventario — dónde se guarda cada credencial

| Credencial | Dónde se guarda | Formato | ¿En git? |
|---|---|---|---|
| Clave maestra AES-256-GCM | `inc/secret.php['clave']` | hex 64 | ❌ gitignored |
| Contraseña del panel (dashboard) | **BD `config['auth_dashboard']`** (cifrada `FP1:`) con fallback a `inc/secret.php['auth_dashboard']` | texto aleatorio | ❌ (BD no se commitea / secret.php gitignored) |
| Token runners atribución/verificación | `inc/secret.php['auth_runners']` | hex 64 | ❌ gitignored |
| Secreto HMAC de `api/baja.php` (unsubscribe) | `inc/secret.php['csrf_secret']` | hex 64 | ❌ gitignored |
| SMTP / IMAP / POP3 | BD `cuentas_smtp.password` | `FP1:` AES-256-GCM | ❌ (BD no se commitea) |
| API keys de IA (DeepSeek, OpenAI…) | BD `config.<proveedor>_api_key` | `FP1:` AES-256-GCM | ❌ (BD no se commitea) |
| FTP de despliegue | `.env` (raíz, local) | texto plano local | ❌ gitignored |
| Fallback SMTP (`$CUENTAS_SMTP_FALLBACK`) | `api/enviar_smtp_random.php` | texto plano | ⚠️ en git, pero **script bloqueado con `die()`** |

> **Nota crítica:** `api/enviar_smtp_random.php` contiene un array fallback con
> credenciales SMTP **en claro** dentro del código. El archivo está **bloqueado** por
> `die("SISTEMA BLOQUEADO...")` y por `.htaccess` (`<FilesMatch ...enviar_smtp_random...>`),
> por lo que no se ejecuta ni es accesible por HTTP. Pendiente de saneamiento (ver §6).

---

## 3. Cómo se usa cada secreto en el código

| Uso | Archivo | Lectura |
|---|---|---|
| Login del panel | `dashboard.php` | `require inc/secret.php` → `AUTH_KEY = $secretos['auth_dashboard']` |
| Runner atribución | `atribuir_respuestas_runner.php` | `AUTH_KEY = $secretos['auth_runners']` |
| Runner verificación | `verificar_atribucion_runner.php` | `AUTH_KEY = $secretos['auth_runners']` |
| Unsubscribe CSRF | `api/baja.php` | `$CSRF_SECRET = $secretos['csrf_secret']` (fallback: derivado de ruta) |
| Envío SMTP/IMAP/POP3 | `inc/smtp_transport.php`, `api/smtp.php`, `cli/cron.php`, `imap_*` | leen BD `cuentas_smtp` → `futprotec_descifrarPassword()` |

---

## 4. Rotación de credenciales

### 4.1 Rotar la contraseña del panel (`auth_dashboard`)
1. Generar nueva: `php -r "echo bin2hex(random_bytes(12));"` (o usar gestor de contraseñas).
2. Editar `inc/secret.php` → cambiar `'auth_dashboard'`.
3. Desplegar `inc/secret.php` a SiteGround (FTP).
4. Guardar la nueva contraseña en el gestor de contraseñas.

### 4.2 Rotar un token de runners (`auth_runners`)
Mismo procedimiento que 4.1 (cambiar `'auth_runners'` en `secret.php`).

### 4.3 Rotar una API key de IA
1. En el panel (tab SMTP → bloque IA), pegar la nueva key y pulsar "Guardar".
2. `update_config` la cifra automáticamente (prefijo `FP1:`) antes de guardarla en `config`.

### 4.4 Rotar una contraseña SMTP
1. En el panel (tab SMTP → Añadir/Editar cuenta) → `api/smtp.php::save_account` cifra al guardar.

### 4.5 Rotar la clave maestra (`clave`) — ⚠️ riesgo alto
1. Descifrar todas las credenciales con la clave antigua.
2. Cambiar `'clave'` en `secret.php`.
3. Re-cifrar todas las credenciales (SMTP + API keys).
4. Desplegar. **Si se pierde la clave, los datos cifrados no tienen recuperación.**

---

## 5. Qué pasa si se pierde la clave maestra

- Las contraseñas SMTP/IMAP/POP3 y API keys de IA cifradas (`FP1:`) **no podrán
  descifrarse** → habrá que re-introducirlas manualmente desde el panel.
- **Guardar siempre una copia de `inc/secret.php` en un gestor de contraseñas.**

---

## 6. Deuda de seguridad conocida (pendiente)

| Ítem | Estado |
|---|---|
| `AUTH_KEY` dashboard hardcodeado | ✅ **RESUELTO** (2026-08-25) → movido a `secret.php` |
| Tokens de runners hardcodeados | ✅ **RESUELTO** (2026-08-25) → movido a `secret.php` |
| `$CSRF_SECRET` derivado de ruta | ✅ **RESUELTO** (2026-08-25) → movido a `secret.php` |
| API keys IA en claro en BD | ✅ **RESUELTO** (2026-08-25) → cifradas `FP1:` |
| `$CUENTAS_SMTP_FALLBACK` en claro en `enviar_smtp_random.php` | ⚠️ Latente (script bloqueado). Saneamiento futuro: eliminarlo o leerlo de la BD cifrada |

---

## 7. Notas de despliegue

- `inc/secret.php` **debe existir en producción** con los mismos valores que local
  (si no, el login del panel y los runners fallan). Está bloqueado por
  `.htaccess` (`RewriteRule ^inc/secret\.php$ - [F,L]`).
- Los archivos que leen secretos (`dashboard.php`, `atribuir_respuestas_runner.php`,
  `verificar_atribucion_runner.php`, `api/baja.php`) tienen **fallback seguro**: si
  `secret.php` no existe, `AUTH_KEY`/token queda vacío → acceso denegado (no fallback
  inseguro).
- `inc/secret.php` se despliega por FTP junto con el resto del módulo
  (`deploy_outbound_full.py`), pero **nunca se commitea a GitHub** (`.gitignore`).

---

## 8. Gestión de la contraseña del panel desde la UI (2026-08-25)

### Cambiar contraseña
- **Panel → Configuración → bloque "Seguridad del Panel" → "Cambiar contraseña de acceso"**.
- Los 3 campos (actual / nueva / confirmar) tienen **toggle mostrar/ocultar** (icono ojo) para
  ver los caracteres antes de guardar.
- Endpoint: `?action=change_password` (requiere sesión + contraseña actual correcta).
- La nueva pass se guarda **cifrada** en BD `config['auth_dashboard']` (prefijo `FP1:`).
  Tiene **prioridad** sobre `secret.php['auth_dashboard']` (que queda como fallback de
  transición mientras no exista en BD).

### Email de recuperación
- **Panel → Configuración → "Email de recuperación"** (editable; se guarda en
  `config['reset_email']`). Default actual: `contactofutprotec@gmail.com`.

### Recuperación por email (si olvidas la contraseña)
1. En el login: **"¿Olvidaste la contraseña?"** → introduce el email de recuperación.
2. El servidor genera un **token de un solo uso** (expira en 30 min) en
   `config['reset_token']` / `config['reset_token_exp']`.
3. Envía un **email con el enlace** usando la cuenta SMTP activa de la BD.
4. El enlace (`dashboard.php?reset=TOKEN`) muestra un formulario para fijar nueva pass.
5. `?action=reset_password` valida el token (`hash_equals` + expiración), cifra la
   nueva pass y **borra el token** (un solo uso).

### Notas de seguridad
- No se revela si el email existe en el sistema (respuesta genérica).
- El token es de un solo uso y expira (30 min).
- **Nunca se envía la contraseña por email**: solo un enlace de reseteo.
- Cambiar la pass desde la UI no modifica `inc/secret.php`; la BD tiene prioridad.
- Endpoints públicos: `request_reset`, `reset_password` y `GET ?reset=` (sin sesión).
  Endpoints autenticados: `change_password`, `update_reset_email`.

---

## 9. Referencias

- `public_html/outbound/inc/secret.php` — centro de secretos
- `public_html/outbound/inc/crypto.php` — cifrado AES-256-GCM
- `public_html/outbound/api/config.php` — cifrado/descifrado de API keys
- `scripts/migrar_api_keys.php` — migración de API keys a cifrado
- `scripts/migrar_passwords_smtp.php` — migración de SMTP a cifrado
- `docs/informe_auditoria_bugs_20260825.md` — auditoría origen

| Clasificación IA | `api/clasificar_ia.php`, `inc/imap_respuestas.php` | leen BD `config` → `futprotec_descifrarPassword()` |
| Editor API keys (UI) | `api/config.php` | `get_config` descifra `*_api_key` · `update_config` cifra `*_api_key` |
