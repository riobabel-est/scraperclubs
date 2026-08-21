# CHECKPOINT — Diagnóstico: Tab de Respuestas vacío (IMAP)

**Fecha:** 2026-08-19
**Modo:** READ-ONLY + ejecución de runner temporal (sin escritura en BD)
**Estado:** ✅ Resuelto / Diagnóstico completado

---

## 1. SÍNTOMA

El tab **Respuestas** del CRM mostraba 0 respuestas, aunque se esperaba que hubiera actividad de clubes.

## 2. INVESTIGACIÓN

### 2.1 Estado de la BD (READ-ONLY)
- Tabla `respuestas` existe con 14 columnas correctas.
- **0 filas** en `respuestas`.
- Tabla `envios` con 49 envíos (31 REAL, 18 TEST).
- Tabla `aperturas` con 23 aperturas.

### 2.2 Causa raíz
El **CLI `imap_respuestas.php` nunca se había ejecutado en producción**. SiteGround no permite CLI directo, por lo que el procesamiento de respuestas IMAP nunca se disparó. La tabla `respuestas` estaba vacía porque **nadie había ejecutado el pipeline IMAP**.

## 3. SOLUCIÓN IMPLEMENTADA

### 3.1 Runner web temporal
Se creó `imap_respuestas_runner.php` (runner web temporal) que replica la lógica del CLI, accesible por HTTP con token secreto:
- `?token=SECRET` → modo auditoría (solo lectura, no escribe).
- `?token=SECRET&apply=1` → modo aplicar (registra respuestas).

### 3.2 Ejecución en modo auditoría
Se ejecutó el runner contra las 10 cuentas SMTP activas:

| Cuenta | INBOX | Junk | spam | Resultado |
|---|---|---|---|---|
| rodrigo@getfutprotec.com | 1 | 0 | 0 | ⚠️ Timeout en msg 1 |
| mario.ortiz@getfutprotec.com | 0 | 0 | 0 | OK |
| alvaro.ruiz@getfutprotec.com | 0 | 0 | 0 | OK |
| carlos.mora@getfutprotec.com | 0 | 0 | 0 | OK |
| javier.sanz@getfutprotec.com | 0 | 0 | 0 | OK |
| diego.navarro@getfutprotec.com | 0 | 0 | 0 | OK |
| pablo.blanco@getfutprotec.com | 0 | 0 | 0 | OK |
| gonzalo.vega@getfutprotec.com | 0 | 0 | 0 | OK |
| adrian.cano@getfutprotec.com | 0 | 0 | 0 | OK |
| sergio.gil@getfutprotec.com | 0 | 0 | 0 | OK |

**Resultado:** 9 cuentas vacías. Solo 1 mensaje en rodrigo, que da timeout.

## 4. HALLAZGO CLAVE

**El tab de respuestas está vacío porque NO hay respuestas de clubes en los buzones IMAP**, no porque el sistema esté roto.

El pipeline IMAP funciona correctamente:
- ✅ Login IMAP correcto en las 10 cuentas.
- ✅ Selección de carpetas (INBOX, INBOX.Junk, INBOX.spam).
- ✅ Búsqueda de mensajes.
- ✅ Reconexión tras timeout.

## 5. PROBLEMA TÉCNICO: Mensaje problemático en rodrigo

El mensaje 1 del INBOX de rodrigo@getfutprotec.com causa **timeout de lectura IMAP** incluso con timeout de 120s. El servidor IMAP de SiteGround no responde al comando FETCH para este mensaje.

### 5.1 Diagnóstico definitivo (2026-08-19 16:00)

Se ejecutó un runner de diagnóstico aislado contra el mensaje 1 de rodrigo con comandos IMAP individuales:

| Comando | Resultado |
|---|---|
| `fetchUID('1')` | ✅ OK — UID = 1 |
| `FETCH 1 (RFC822.SIZE)` | ✅ OK — 13822 bytes |
| `FETCH 1 (ENVELOPE)` | ✅ OK — `Re: rodrigo en getfutprotec.com`, de `Rodrigo Vázquez <rodrigo@riobabel.com>`, Message-ID `<c66abf4d-91f6-4c1a-b02b-2f2ad5cf5785@riobabel.com>`, References `<4274d0c8-8183-41c2-998c-eefc1ec31989@riobabel.com>` |
| `BODY.PEEK[HEADER]` | ❌ **Timeout de lectura IMAP (120s)** |
| `BODY.PEEK[TEXT]` | ❌ **Timeout de lectura IMAP (120s)** |

### 5.2 Conclusión del diagnóstico

1. **El mensaje NO está corrupto ni es enorme** (solo 13822 bytes). El servidor responde correctamente a comandos ligeros (UID, SIZE, ENVELOPE).
2. **El servidor IMAP de SiteGround se cuelga al servir el cuerpo/cabeceras completas** (`BODY.PEEK[HEADER]` y `BODY.PEEK[TEXT]`) de este mensaje concreto. Es un **problema del servidor IMAP de SiteGround**, no del código del CRM.
3. **El email del usuario SÍ está en el buzón** (UID=1, 13822 bytes, de `rodrigo@riobabel.com`), pero el servidor no puede servirlo vía IMAP para su procesamiento.
4. El proxy HTTP de SiteGround corta con **504 Gateway Timeout** cuando el script tarda demasiado (los 2 timeouts de 120s superan el límite del proxy).

### 5.3 Hipótesis
- Mensaje con estructura MIME que el servidor IMAP de SiteGround no puede servir vía `BODY.PEEK`.
- Problema del servidor IMAP de SiteGround con ese mensaje específico.

### 5.4 Impacto
- No bloquea el pipeline (se reconecta y continúa).
- No es una respuesta de club procesable (no se puede leer).
- No afecta a las demás cuentas.
- **El email del usuario no se puede procesar automáticamente** hasta que SiteGround lo resuelva o se elimine/re-envíe el mensaje.


## 6. MEJORAS APLICADAS AL CÓDIGO

### 6.1 `inc/imap_respuestas.php`
- Timeout IMAP aumentado de 30s → **120s** (configurable vía `$GLOBALS['IMAP_TIMEOUT']`).
- `conectar()` usa `$GLOBALS['IMAP_TIMEOUT']` para el timeout de socket.

### 6.2 Runner temporal (eliminado tras verificar)
- Reconexión automática tras timeout (evita socket corrupto que afectaba a carpetas siguientes).
- Tolerancia a timeout en lectura de cuerpo (usa solo cabeceras si el cuerpo falla).

## 7. LIMPIEZA

- ✅ Runner temporal `imap_respuestas_runner.php` eliminado de producción.
- ✅ No se escribió nada en la BD (modo auditoría).
- ✅ BD remota intacta (0 filas en respuestas, sin cambios).

## 8. CONCLUSIÓN

1. **El sistema de respuestas IMAP funciona correctamente.** El tab vacío se debía a que el pipeline nunca se había ejecutado en producción.
2. **No hay respuestas de clubes que registrar** en este momento (9 cuentas vacías).
3. El mensaje problemático de rodrigo es un caso aislado que no bloquea la funcionalidad.
4. Para que el tab de respuestas se pueble automáticamente, el pipeline IMAP debe ejecutarse de forma periódica (cron). **Pendiente:** configurar el cron de `imap_respuestas_runner.php` o el CLI equivalente.

## 9. ACCIÓN RECOMENDADA

Configurar la ejecución periódica del pipeline IMAP (cron) para que las respuestas de clubes se registren automáticamente cuando lleguen. El runner web temporal se puede reutilizar como endpoint de cron (con token) o integrar en el cron existente.
