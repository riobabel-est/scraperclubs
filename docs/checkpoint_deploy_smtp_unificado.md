# CHECKPOINT — DEPLOY SMTP UNIFICADO A PRODUCCIÓN

**Fecha:** 2026-08-22 22:25 (Europe/Madrid)
**Entorno:** Producción (SiteGround — getfutprotec.com)
**Estado:** ✅ Deploy completado y verificado. Test de microenvío PENDIENTE (lo ejecutará el usuario).

---

## 1. Objetivo

Desplegar a producción el refactor de **transporte SMTP centralizado** (`futprotec_enviarSMTP`), que unifica el envío de correos a través de `inc/smtp_transport.php`, eliminando la duplicación de lógica SMTP en `cli/cron.php`, `api/enviar_smtp_random.php` y `dashboard.php`.

---

## 2. Archivos desplegados (5/5)

| Archivo | Rol | Tamaño (bytes) | MD5 |
|---|---|---|---|
| `inc/smtp_transport.php` | Transporte centralizado (nuevo) | 9.859 | `123963c3386254bf9108c02051f51479` |
| `inc/mime.php` | Construcción MIME (texto plano + HTML) | 5.060 | `7f7063205f297dcd5d6065334273077d` |
| `cli/cron.php` | Cron de envío (usa transporte centralizado) | 19.441 | `b95045ba3464711c3cac08650b83ced4` |
| `api/enviar_smtp_random.php` | Envío SMTP aleatorio (usa transporte centralizado) | 20.342 | `a830cd82dc0775deca62f28c43b509f2` |
| `dashboard.php` | Panel (usa transporte centralizado) | 41.799 | `f4c6dacecdb21e2801825bbc5b017dc5` |

**Resultado subida:** 5/5 OK (verificación size + MD5 tras cada upload).

---

## 3. Verificación de sintaxis PHP (pre-deploy)

```
No syntax errors detected in public_html/outbound/inc/smtp_transport.php
No syntax errors detected in public_html/outbound/inc/mime.php
No syntax errors detected in public_html/outbound/cli/cron.php
No syntax errors detected in public_html/outbound/api/enviar_smtp_random.php
No syntax errors detected in public_html/outbound/dashboard.php
```

---

## 4. Backup remoto

- **Ruta:** `/getfutprotec.com/backups_deploy/outbound_smtp_unificado_20260822_222513/`
- **Archivos respaldados:** 5/5 (versión previa de cada archivo antes de sobrescribir).

---

## 5. Verificación remota post-deploy

Se descargaron los 5 archivos desde producción y se comprobó que contienen la referencia al transporte centralizado:

| Archivo | `futprotec_enviarSMTP` | `smtp_transport` |
|---|---|---|
| `inc/smtp_transport.php` | ✅ | ✅ |
| `inc/mime.php` | ✅ | ✅ |
| `cli/cron.php` | ✅ | ✅ |
| `api/enviar_smtp_random.php` | ✅ | ✅ |
| `dashboard.php` | ✅ | ✅ |

**Conclusión:** El transporte centralizado está correctamente referenciado en todos los puntos de envío en producción.

---

## 6. Script de deploy creado

- **Ruta:** `scripts/deploy_smtp_unificado.py`
- **Función:** Deploy selectivo FTP del SMTP unificado (backup remoto + subida + verificación MD5).
- **Manifest:** `backups_deploy/smtp_unificado_deploy_manifest.txt`

---

## 7. Protección de credenciales SMTP

Se respetó la regla del `.clinerules`: **NO se modificaron los valores del array `$CUENTAS_SMTP`** en `api/enviar_smtp_random.php`. El refactor solo centraliza el transporte; las credenciales (`email`, `user`, `pass`, `nombre`, `smtp`, `puerto`) se preservan intactas.

---

## 8. Estado del test de microenvío — PENDIENTE

El test de microenvío **NO se ejecutó** en esta fase. El usuario realizará los envíos de validación y cotejará que no haya fallos.

**Para validar el transporte SMTP unificado en producción**, el usuario puede:
- Ejecutar el microenvío controlado de campaña 2: `python scripts/microenvio_campana2_5leads.py` (envía 5 emails reales a leads 2,3,4,6,8).
- O bien hacer un envío de prueba a un destinatario TEST vía el panel (tab de pruebas).

**Criterios de éxito del test:**
- Los emails se envían correctamente a través del transporte centralizado.
- Se generan `message_id` en los registros de `envios`.
- No hay errores de conexión SMTP ni de construcción MIME.
- El motor de envío permanece pausado (si se usa el microenvío controlado).
- No se rompe ninguna funcionalidad previa (validación MX, WhatsApp, modales, etc.).

---

## 9. Notas

- El deploy se realizó con backup remoto previo para permitir rollback si fuera necesario.
- No se ejecutó `git push` (regla del `.clinerules`: solo se sube a GitHub cuando el usuario lo solicita explícitamente).
- Los cambios quedan en local y en producción (SiteGround).
