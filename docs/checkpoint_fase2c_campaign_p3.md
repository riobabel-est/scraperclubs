# CHECKPOINT — FASE 2C: ANÁLISIS DE campaign_id EN P3 (cron.php)

**FECHA:** 2026-08-14
**ALCANCE:** Solo análisis. NO se modifica código ni BD. No envíos.

---

## 1. Situación actual
- `cli/cron.php` está limitado a CLI (`PHP_SAPI === 'cli'`).
- No recibe argumentos (`getopt` no existe en el archivo).
- Arranca comprobando `config.motor_estado` (clave global) — si no es `activo`, termina.
- Selecciona 1 SMTP disponible (menor `enviados_hoy`) y 1 lead `estado_lead='01 Sin Contactar'` sin envío previo `enviado`.
- Elige la plantilla `html` activa de menor `id`.
- En FASE 2B se añadió el guard de elegibilidad `esElegibleParaEnvio(...)` y la persistencia de `lead_id`/`plantilla_id`/`smtp_id`.
- **NO tiene `campaign_id`**: escribe `campaign_id = NULL` y `variant = NULL`.

## 2. Problema
P3 puede enviar sin pertenecer a ninguna campaña, lo que rompe la trazabilidad `LEAD → CAMPAÑA → ENVÍO` y deja el piloto sin identificador de campaña. Hasta que P3 conozca la campaña explícitamente y la valide, no debe considerarse listo para pilot.

## 3. Arquitectura actual
```
config.motor_estado (global: activo/pausado)
        ↓
cron.php → SELECT cuentas_smtp (activa, con límite)
        ↓
SELECT lead '01 Sin Contactar' sin envío
        ↓
(campo campaign_id prácticamente inexistente)
```

## 4. Opciones consideradas
- **A) `--campaign-id=N` / `--campaign=N`** por CLI: campaña explícita, procedente de `pipelines.id`.
- B) Inferir campaña por nombre/campo: **descartado** (frágil, no es identidad).
- C) "Última campaña activa": **descartado** (ambigüedad, no determinista).
- D) `campaign_id = 1` hardcodeado: **descartado** (prohibido).
- E) Usar `LEGACY_TEST_FASE1` como productiva: **descartado** (prohibido).

## 5. Opción recomendada
**A — `cron.php --campaign-id=N`** (o `--campaign=N`), con validación por `pipelines.id`.

## 6. Justificación
- `pipelines` es la fuente de verdad de campaña (ya extendida en FASE 1 con `estado`, `entorno`, `identificador`).
- El argumento CLI es explícito, trazable y compatible con schedulers/cron del sistema (sin depender de POST).
- No crea nueva tabla ni duplica fuente.
- Permite definir el comportamiento ante estados de campaña (§10).

## 7. Impacto en P3
- `campaign_id` se escribe en `envios` con idempotencia (índice único parcial ya creado en FASE 2B).
- `variant` se podrá poblar en FASE 3 desde la campaña (sin cambiar aquí).
- El guard `esElegibleParaEnvio(..., campaignId)` pasará de `0` a `N` para aplicar la separación TEST/PILOT también en P3.

## 8. Impacto en idempotencia
- Respetar el índice único `(lead_id, campaign_id)`: P3 y P1 que apunten a la MISMA campaña y MISMO lead no duplicarán (comparten garantía).
- Un mismo lead podrá pertenecer a campañAs distintas sin colisión.

## 9. Impacto en campañas múltiples
- Soporta múltiples campañas activas: cada ejecución del cron indica explícitamente cuál campaña procesa.
- La selección del lead debe poder acotarse por campaña (o al menos registrar la campaña indicada). El mecanismo preciso de "a qué lead" por campaña se resuelve al implementar (puede seguir siendo "primer lead elegible" pero con `campaign_id` fijado).

## 10. Comportamiento del cron según estado de campaña (propuesta)
| Estado | ¿cron puede enviar? |
|---|---|
| DRAFT | NO (bloqueado) |
| READY | NO (preparado pero no en ejecución) |
| PILOT | SÍ |
| ACTIVE | SÍ |
| PAUSED | NO |
| COMPLETED | NO |
| ARCHIVED | NO |

- Fuente: `pipelines.estado`. Regla explícita: solo `PILOT` o `ACTIVE` habilitan envío.
- Además debe respetar `pipelines.activo` (si 0 → NO) y `entorno` (un cron en entorno producción no debe procesar una campaña `test`; el guard ya bloquea lead TEST).

## 11. Riesgos
- Scheduler externo (fuera del repo) que invoque `cron.php` sin argumentos → quedaría BLOCKED/NO CAMPAIGN (comportamiento deseado).
- Si el operador pasa un `campaign-id` inexistente o de estado no permitido → BLOCKED/NO CAMPAIGN (no enviar).
- Mantener separados `campaign_id` / `variant` / `plantilla_id` / `smtp_id`: no usar uno por otro.

## 12. Tests necesarios (al implementar, FASE posterior)
- P3 sin `--campaign-id` → BLOCKED/NO CAMPAIGN (no envía).
- P3 con `--campaign-id` inexistente → BLOCKED.
- P3 con campaña DRAFT/PAUSED/COMPLETED/ARCHIVED → BLOCKED.
- P3 con campaña PILOT o ACTIVE → procede.
- P3 escribe `campaign_id` en `envios`; idempotencia conjunto P1+P3 mismo (lead,campaña).

## 13. Recomendación final
Implementar en `cron.php`:
1. Parsear `--campaign-id=N` (obligatorio).
2. Validar existencia y estado/activo/entorno contra `pipelines`.
3. Si no hay campaña válida → `exit` con `BLOCKED / NO CAMPAIGN` (no enviar).
4. Pasar `campaign_id` a `esElegibleParaEnvio` y a `reservarEnvioLogico`/INSERT de `envios`.

---

### Estados del análisis
- Situación actual: **PASS** (documentada).
- Problema: **PASS** (identificado).
- Opción recomendada: **PASS**.
- Impacto idempotencia: **PASS WITH LIMITATIONS** (requiere que P3 fije campaign_id; hoy no lo fija).
- Impacto campañas múltiples: **PASS**.
- Riesgos: **PASS** (acotados).
- Tests: **NOT VERIFIED** (a ejecutar al implementar).

> NO modifico código ni BD. NO avanzo a FASE 3. Espero aprobación.