# Checkpoint — Tanda de robustez y saneamiento (2026-08-25)

**Fecha:** 2026-08-25
**Ámbito:** `public_html/outbound/`
**Tipo:** Refactor preventivo + saneamiento de seguridad/BD (sin cambio de comportamiento)

---

## Objetivos de la tanda

Ejecutar los pendientes de prioridad media/baja del compendio que eran seguros y
rápidos, un objetivo a la vez:

1. **R-4** — guardas `if (!function_exists(...))` en funciones duplicadas (evita fatal errors de redeclaración).
2. **R-2** — verificar que la construcción MIME ya está centralizada (resuelto de facto).
3. **S-1** — vaciar `$CUENTAS_SMTP_FALLBACK` (eliminar credenciales en claro del repo).
4. **FI-001** — eliminar tabla huérfana `plantillas_new`.

## Cambios aplicados

### R-4 — Guardas `function_exists` (5 funciones en 5 archivos)
| Archivo | Función envuelta |
|---|---|
| `inc/mime.php` | `convertirContenidoAHtml()`, `enviarSMTPAutenticado()` |
| `api/imap_sync.php` | `imap_cron_log()` |
| `cli/imap_respuestas_cron.php` | `imap_cron_log()` |
| `api/enviar_lote.php` | `escribirLogEnvio()` |
| `cli/cron.php` | `enviarSMTP()` |

Elimina el riesgo latente de colisión (p.ej. si `enviar_smtp_random.php` se reactivara
o dos archivos compartidos se cargaran en el mismo proceso).

### R-2 — `mime.php` (verificado, sin cambios)
`enviarSMTPAutenticado()` ya delega en `futprotec_enviarSMTP()`; la construcción MIME
vive en `inc/smtp_transport.php`. No hay nada que extraer → **resuelto de facto**.

### S-1 — `$CUENTAS_SMTP_FALLBACK` vaciado
`api/enviar_smtp_random.php`: el array de credenciales en claro (7 cuentas reales) se
sustituyó por `$CUENTAS_SMTP_FALLBACK = [];` con comentario explicativo. El
`die("SISTEMA BLOQUEADO...")` de seguridad y las funciones de descifrado quedan
intactos. **0 credenciales en claro restantes** en el archivo.

### FI-001 — `plantillas_new` eliminada
Verificada vacía (0 filas) en `stats.db` y eliminada con `DROP TABLE`. Solo queda la
tabla `plantillas` activa.

## Validación

- `php -l` OK en: `inc/mime.php`, `api/imap_sync.php`, `cli/imap_respuestas_cron.php`,
  `api/enviar_lote.php`, `cli/cron.php`, `api/enviar_smtp_random.php`.
- `grep` credenciales en claro en `enviar_smtp_random.php` → **0 coincidencias**.
- BD: `plantillas_new` eliminada, `plantillas` intacta.
- Sin cambios de comportamiento (solo envolturas de definición y array vacío en un
  script bloqueado).

## Pendiente

- Deploy a producción + commit (requiere OK del usuario).
- `$cuentasDefault` en `cli/init_db.php` (FI-002 resto) — anotado como pendiente.

## Referencias

- `docs/PENDIENTES_OUTBOUND.md` — R-2/R-4/S-1/FI-001 → ✅
- `docs/REFACTORIZACIONES_PENDIENTES.md` — §6.1 (R-2)
