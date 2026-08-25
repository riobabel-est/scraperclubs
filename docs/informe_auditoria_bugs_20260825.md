# Informe de Auditoría — Bugs y deuda técnica (2026-08-25)

**Fecha:** 2026-08-25
**Ámbito:** `public_html/outbound/` (módulo outbound del CRM)
**Método:** Análisis estático + revisión manual de patrones de riesgo
**Resultado:** `php -l` OK en 100% de archivos · `node --check` OK en app.js

---

## 1. CRÍTICO — Credenciales hardcodeadas en el código

| Archivo | Línea | Hallazgo |
|---|---|---|
| `dashboard.php` | 12 | ~~`define('AUTH_KEY', 'FutProtec2026!')`~~ → **✅ RESUELTO (2026-08-25)**: lee `auth_dashboard` de `inc/secret.php` |
| `atribuir_respuestas_runner.php` | 23 | ~~Token hardcodeado~~ → **✅ RESUELTO**: lee `auth_runners` de `inc/secret.php` |
| `verificar_atribucion_runner.php` | 6 | ~~Token hardcodeado~~ → **✅ RESUELTO**: lee `auth_runners` de `inc/secret.php` |

**Riesgo:** acceso no autorizado al panel de producción.
**Estado:** ✅ **RESUELTO (2026-08-25)** — `AUTH_KEY` (nueva pass aleatoria), tokens
de runners y `$CSRF_SECRET` (baja.php) ahora viven en `inc/secret.php` (gitignored +
`.htaccess`). Fallback seguro: si `secret.php` no existe, la autenticación queda
**bloqueada** (no se degrada a valores por defecto).

> Nota: `inc/secret.php` y `data/.futprotec_key` ya están en `.gitignore`. La
> infraestructura para mover credenciales fuera del repo YA existe (ver `inc/crypto.php`).
> Documentación completa: `docs/CONFIGURACION_SEGURIDAD.md`.

---

## 2. MEDIO — Colisiones de funciones frágiles (sin `function_exists`)

Varias funciones están definidas en más de un archivo sin guarda `if (!function_exists(...))`.
Hoy NO colisionan en runtime porque `api/enviar_smtp_random.php` está bloqueado con
`die("SISTEMA BLOQUEADO...")`, pero basta con reactivar ese archivo (o incluir dos
archivos en el mismo request) para un **fatal error de redeclaración**.

| Función | Definida en |
|---|---|
| `enviarSMTP` | `cli/cron.php` (361), `api/enviar_smtp_random.php` (241, bloqueado) |
| `enviarSMTPAutenticado` | `inc/mime.php` (78), `api/enviar_smtp_random.php` (284, bloqueado) |
| `escribirLogEnvio` | `api/enviar_lote.php` (432), `api/enviar_smtp_random.php` (482, bloqueado) |
| `imap_cron_log` | `api/imap_sync.php` (78), `cli/imap_respuestas_cron.php` (74) |

Además, `inc/mime.php`, `inc/abc.php`, `inc/eligibilidad.php`, `inc/helpers.php`,
`inc/pop3_respuestas.php` definen funciones SIN `function_exists` (a diferencia de
`smtp_transport.php` y `crypto.php` que sí lo usan).

**Acción recomendada:** envolver las funciones de `inc/*` compartidas en
`if (!function_exists(...))`. Prioridad media (1h).

---

## 3. BAJO — SQL interpolado (sin SQL injection confirmada, deuda de robustez)

Se detectaron ~20 `$db->exec("...{$var}...")`/`query` con interpolación. En todos los
casos revisados la variable es **segura** (`(int)` casteado o `$db->escapeString()`):
- `leads.php` (merge/delete): `$keepId`, `$dupId`, `$id` → `(int)($_POST[...] ?? 0)`
- `analytics.php:611`: `$estadoEsc = $db->escapeString($estado)` ✓
- `blacklist.php:160`: `$inList` construido de lista hardcodeada + `escapeString` ✓
- `smtp.php`, `mockups.php`, `plantillas.php`: IDs `(int)` ✓
- `imap_respuestas.php:956` (ALTER TABLE): whitelist fija de columnas ✓

**Recomendación:** migrar progresivamente a prepared statements en los endpoints
sensibles de escritura (`leads.php`, `smtp.php`, `analytics.php`). No urgente, pero
reduce riesgo futuro.

---

## 4. BAJO — `$CSRF_SECRET` derivado de ruta conocida (`baja.php:33`)

`$CSRF_SECRET = hash('sha256', $dbPath . '::futprotec_baja_csrf_v1')` — la "clave" se
deriva de una ruta predecible. Funciona como anti-CSRF básico, pero cualquier actor con
acceso al servidor podría derivarla. **Recomendación:** usar una clave aleatoria de
`secret.php`.

---

## 5. Archivos monolíticos pendientes de refactor (deuda estructural)

| Archivo | Líneas | Nota |
|---|---|---|
| `inc/imap_respuestas.php` | 1553 | Ya refactorizado en §3.3/§6.2 pero sigue siendo un monolito enorme; candidato a dividirse en `imap_core.php` + `imap_respuestas.php` |
| `api/leads.php` | 1186 | Endpoints múltiples sin separar (solo `scan_duplicates` se extrajo en §3.2) |
| `dashboard.php` | 962 | Orquestador (ya tiene helpers en `inc/helpers.php`); `showLoginForm()` podría ir a un partial |
| `api/analytics.php` | 945 | Ya refactorizado en §3.1 (11 funciones puras) pero el archivo sigue largo |
| `cli/init_db.php` | 806 | Esquema + migraciones en un solo archivo |
| `tabs/lanzadera.php` | 503 | Vista con mucho JS inline de motor |

**Prioridad:** baja-media. El archivo que más valor aportaría dividir es
`inc/imap_respuestas.php` (1553 líneas).

---

## 6. Basura temporal en la raíz (no trackeada)

- `inspect_db.php` — residuo de diagnóstico (contiene un parse error; sin uso).
- `tmp_schema_check2.txt`, `tmp_schema_check3.txt`, `tmp_schema_check4.txt` — volcados
  de inspección de esquema.

**Acción:** eliminar (no aportan valor y ensucian el working tree).

---

## 7. Veredicto

- **No hay SQL injection ni XSS evidentes** en los flujos revisados (track, baja,
  envío, merge, configuración).
- El único **hallazgo crítico es la contraseña del dashboard hardcodeada** (§1).
- El resto es deuda de robustez (colisiones `function_exists`, prepared statements) y
  estructural (monolitos).

## Archivos de referencia

- `docs/REFACTORIZACIONES_PENDIENTES.md` — seguimiento de refactors
- `public_html/outbound/inc/secret.php` — infraestructura para mover credenciales
- `public_html/outbound/inc/crypto.php` — patrón `function_exists` (referencia)
