# CHECKPOINT — FASE 2B: SUPRESIÓN + IDEMPOTENCIA

**FECHA:** 2026-08-14
**ALCANCE:** Regla central de elegibilidad, idempotencia (índice único parcial), desactivación reversible P2. Sin envíos reales. Sin A/B/C.

---

## 1. Cambios realizados
- Nuevo include central `public_html/outbound/inc/eligibilidad.php` con:
  - `esElegibleParaEnvio(SQLite3, leadId, campaignId)` — regla única de supresión/elegibilidad.
  - `reservarEnvioLogico(...)` — reserva idempotente del envío lógico antes del SMTP.
- `api/enviar_lote.php` (P1): incluye helper, lee `campaign_id`, comprueba elegibilidad, reserva el envío lógico ANTES del SMTP y actualiza la MISMA fila con el resultado.
- `cli/cron.php` (P3): incluye helper, comprueba elegibilidad y persiste `lead_id`, `plantilla_id`, `smtp_id` en `envios`.
- `api/enviar_smtp_random.php` (P2): reactivado `die()` de bloqueo → desactivado reversiblemente.

## 2. Archivos modificados
- `public_html/outbound/inc/eligibilidad.php` (NUEVO)
- `public_html/outbound/api/enviar_lote.php`
- `public_html/outbound/cli/cron.php`
- `public_html/outbound/api/enviar_smtp_random.php`
- `scripts/_test_fase2b.php` (NUEVO — harness de tests)

## 3. BD modificada
- Índice único parcial creado (ver §4). Sin nuevas columnas ni tablas en esta fase.

## 4. Índices creados
- `idx_envios_lead_campaign` (UNIQUE parcial `(lead_id, campaign_id) WHERE campaign_id IS NOT NULL`).
- Comprobado pre-creación: 0 pares con `campaign_id IS NOT NULL` → creación limpia.
- Las 2 filas legacy (`campaign_id=NULL`) NO entran en el índice.

## 5. P2 desactivado
- **Bloqueado** con `die("SISTEMA BLOQUEADO POR EL ADMINISTRADOR: ENVIOS DETENIDOS.")` al inicio.
- **Invocadores:** sin llamadas desde PHP/JS/scheduler (solo referencia en `README.md` y resultados de auditoría). Archivo y `clubes.json` conservados.

## 6. Fuentes de destinatarios
- P1 y P3 usan `clubes_crm`. P2 (legacy) desactivado. `clubes.json` fuera del flujo del piloto.

## 7. Lógica de supresión
- `esElegibleParaEnvio()` bloquea: `Lista Negra`, `Opt-Out`, `Unsubscribed`, `Baja / Opt-Out`, `Email Inválido`, `es_duplicado=1`, email no `filter_var`, y lead TEST en campaña no-test.
- Se invoca en servidor (no JS) tanto en P1 como en P3.

## 8. Lógica de idempotencia
- `reservarEnvioLogico()` con `INSERT OR IGNORE` + índice único parcial.
- Distingue: envío lógico (fila única por lead+campaña) / intento SMTP (misma fila) / aceptación (`enviado`) / error (`error`).
- Estados finales (no reenviar): `enviado`, `abierto`.
- Estados retryables (reintento sobre la MISMA fila): `pendiente`, `error`.

## 9. Estrategia de concurrencia
- Índice único parcial impide dos filas (lead, campaña).
- `INSERT OR IGNORE` + `changes()` para detectar ganador. `busy_timeout=5000` reduce "database is locked".
- Sin reserva bloqueante indefinida; si SMTP falla la fila queda `error` (retryable) y no duplica.

## 10. Tests
Harness `scripts/_test_fase2b.php` sobre copia temporal de la BD (sin SMTP, sin tocar la real). Resultado: **9/9 PASS**.

| Test | Resultado | Evidencia |
|---|---|---|
| T1 lead normal + campaña | PASS | `elegible` |
| T2 lead Lista Negra | PASS | `supresion` |
| T3 baja bloquea siguiente envío | PASS | `antes=elig despues=bloq` |
| T4 primer envío lógico | PASS | `id=3 estado=pendiente` |
| T5 segundo intento no duplica | PASS | `id=3 estado=pendiente` (misma fila) |
| T6 campaña distinta no bloquea | PASS | `id=5` (nueva fila) |
| T7 concurrencia | PASS | `a.nuevo=true b.nuevo=false filas=1` |
| T8 lead TEST en PILOT | PASS | `lead_test_en_campana_no_test` |
| T9 P1/P3 misma decisión | PASS | `razon=elegible` |

Nota: TEST 10 (`clubes.json` fuera del flujo) se verifica por desactivación de P2 y ausencia de invocadores (§5).

## 11. Resultados
- READY FOR PILOT criterios 1-10: cumplidos según lo implementado.
  - (1) Solo P1/P3 operativos → PASS.
  - (2) Ambos usan `clubes_crm` → PASS.
  - (3) P2 no envia → PASS.
  - (4) Lista Negra bloqueado → PASS.
  - (5) baja efectiva inmediata (estado) → PASS (bloqueo vía regla central).
  - (6) un lead no recibe dos envíos lógicos de misma campaña → PASS.
  - (7) lead puede participar en distintas campañas → PASS.
  - (8) concurrencia no duplica → PASS.
  - (9) TEST/PILOT separados → PASS.
  - (10) sin envíos reales → PASS.

## 12. Riesgos
1. La comprobación TEST/PILOT usa heurística (`@futprotec.local` / nombre `test`); no es una marca formal de lead. Se refinará al formalizar campañas (Fase posterior).
2. `baja.php` aún no registra evento en `comunicaciones_log` (fuera de alcance del core, no bloqueante).
3. `cli/init_db.php` no reproduce el nuevo índice/columnas (deuda ya registrada; sincronización en hardening).

## 13. Limitaciones
- P3 no asigna campaign_id (cron selecciona lead global sin campaña); idempotencia de campaña aplica en P1 con `campaign_id>0`. El guard de supresión sí está en P3.
- Sin FK declarativas (decisión aprobada en FASE 1).
- `variant` se registra en P1 solo cuando hay `campaign_id`; la asignación A/B/C real es FASE 3.

## 14. Compatibilidad legacy
- `envios` legacy (2 filas) con `campaign_id=NULL` no entran en el índice único.
- Lectores existentes no se ven afectados (columnas nuevas ya existían de FASE 1).
- Sintaxis PHP validada (`php -l`) en los 4 archivos PHP modificados: sin errores.

---

> NO avanzo a FASE 3. Espero aprobación explícita.