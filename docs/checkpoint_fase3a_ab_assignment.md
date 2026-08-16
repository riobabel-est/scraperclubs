# CHECKPOINT — FASE 3A: ASIGNACIÓN + INMUTABILIDAD A/B/C

**FECHA:** 2026-08-14
**ALCANCE:** Asignación determinística de variante, coherencia entorno/campaña, persistencia inmutable, snapshot de mensaje. Sin envíos reales. Sin dashboard/métricas.

---

## 1. Análisis del entorno
- `config.modo_entorno`: valores reales usados por el sistema → `test` (default en `init_db.php`) y `produccion` (comprobado por `cron.php` y `enviar_lote.php`). No existe `pilot`/`production` en `modo_entorno`.
- `pipelines.entorno`: columna añadida en FASE 1, default `test`. Valores previstos: `test`, `pilot`, `production`.
- Hoy no había ninguna regla que cruzara ambos campos (riesgo detectado en FASE 2C).

## 2. Decisión entorno/campaña
Implementar `esEntornoCoherente(campaignEntorno, modoEntorno)` con regla mínima usando SOLO valores existentes:
- `modo=produccion` + `campaña=test` → **bloqueado** (campaign_test_en_produccion).
- `modo=test` + `campaña=pilot|production` → **bloqueado** (campaign_comercial_en_test).
- resto → coherente.

Aplicada en P1 y P3 ANTES de procesar lead. No inventa valores nuevos.

## 3. Arquitectura de asignación
```
CAMPAÑA → LEAD ELEGIBLE
   → asignarVariante(lead_id, campaign_id)   [determinista]
   → resolverContenidoVariante(plantilla, variant)
   → sustitución de placeholders (snapshot)
   → reservarEnvioLogico(...)                 [persiste variant/plantilla/snapshot]
   → SMTP → actualizar la misma fila
```

## 4. Método elegido
**Hash determinista** `crc32(campaign_id : lead_id)` → módulo 3 → A/B/C.
No random por envío.

## 5. Justificación
- **Inmutabilidad:** mismo (lead, campaña) → misma variante SIEMPRE (retry/reanudación/cambio de plantilla no la alteran).
- **Reproducibilidad/auditoría:** se puede recalcular sin estado previo.
- **Concurrencia:** sin contador compartido ni carrera de asignación.
- **Equilibrio razonable:** distribución ≈33/33/33 sobre ids heterogéneas (verificado: aparecen A, B y C).
- **Simplicidad:** función pura, sin tabla nueva ni estado adicional.
- No busca significancia estadística; la interpretación será posterior.

## 6. Modelo de persistencia
- `envios.variant` (VARCHAR(1), NULL=legacy) guarda A/B/C para campaña real.
- `envios.plantilla_id`, `envios.smtp_id`, `envios.campaign_id`, `envios.lead_id` persistidos.
- La variante se fija dentro de `reservarEnvioLogico()` por idempotencia (independiente del valor que pase el llamador), garantizando que P1 y P3 usen el MISMO asignador.

## 7. Inmutabilidad
- La combinación `lead_id + campaign_id` conserva siempre la misma variante.
- El retry/error SMTP reutiliza la MISMA fila (índice único parcial) y NO cambia `variant`, `plantilla_id`, `asunto` ni `cuerpo_mensaje`. Verificado en tests.

## 8. Plantilla/versionado
- `variant` ≠ `plantilla_id` ≠ `campaign_id` ≠ `smtp_id`. Cada uno conserva su significado.
- La resolución de contenido por variante está centralizada en `resolverContenidoVariante()`.
- `save_template` continúa sobrescribiendo la plantilla (versionado inmutable queda como FI-007 para fase posterior); el snapshot del envío NO depende de eso.

## 9. Snapshot
- `envios.asunto` y `envios.cuerpo_mensaje` almacenan el mensaje exacto sustituido (con píxel/fingerprint en P1). Verificado: tras cambiar la plantilla, el envío histórico conserva su snapshot original.

## 10. P1/P3
- Ambos requieren `inc/abc.php` (via `eligibilidad.php`) y usan `asignarVariante()`, `resolverContenidoVariante()`, `esEntornoCoherente()` y `reservarEnvioLogico()`.
- P1: variante determinística para `campaign_id>0` (ignora `variante_ab` del POST). P3: variante determinística para la campaña CLI.
- No existen dos asignadores distintos.

## 11. Tests (harness `scripts/_test_fase3.php`, copia temporal, sin SMTP)
| Test | Resultado |
|---|---|
| T1 variante válida | PASS |
| T2 repetir lead+campaña conserva variante | PASS |
| T3 aparecen A/B/C (equilibrio) | PASS |
| T4 misma lead campaña distinta (función por campaña) | PASS |
| T10a..f coherencia entorno | PASS (6 casos) |
| T6 variant no NULL | PASS |
| T7 plantilla_id persiste | PASS |
| T8 snapshot exacto | PASS |
| T3retry error+retry misma fila | PASS |
| T3retry conserva variant+plantilla+snapshot | PASS |
| T11/T12 retry tras cambio conserva snapshot original | PASS |
| T5 concurrencia máx 1 envío lógico | PASS |

**RESUMEN: 23 tests, 0 fallos.** (incluye 6 nuevos de congelación de plantillas C1–C6)

## 11b. AJUSTES APLICADOS (post-revisión)
- **AJUSTE 1 — Normalización del hash:** `asignarVariante()` normaliza `crc32` a entero sin signo (sumando 2^32 si es negativo) antes de `% 3`, eliminando dependencia del signo/representación del entero de PHP. No cambia la arquitectura ni el método. Re-tests de asignación/inmutabilidad: PASS.
- **AJUSTE 2 — Congelación de plantillas en PILOT:** `plantillaEstaCongelada(SQLite3, plantillaId)` devuelve true si existe un `envios.plantilla_id` ligado a una campaña `estado ∈ {PILOT, ACTIVE}`. Conectado al guardado de plantilla (`dashboard.php save_template`): si la plantilla está congelada, el guardado se rechaza con mensaje para crear una nueva plantilla. El snapshot de `envios` sigue siendo la fuente histórica del mensaje; son dos capas independientes.

## 12. Riesgos
1. `crc32` es determinista pero dependiente de plataforma para el valor crudo; mitigado con la normalización a entero sin signo. La asignación es estable en PHP 8.x. Documentado, no bloqueante.
2. El versionado inmutable de plantillas (nueva versión en lugar de sobrescribir) sigue pendiente (FI-007); la congelación mínima de plantillas en PILOT lo mitiga para la campaña, y el snapshot del envío protege el histórico.
3. `modo_entorno` no tiene valor `pilot`/`production` — se mantiene la dualidad `test`/`produccion` existente y `pipelines.entorno` aporta el matiz de campaña. No se inventó valor nuevo.

## 13. Limitaciones
- La asignación es determinista (no "rotativa secuencial"); el equilibrio es observable pero no garantizado bit a bit en muestras pequeñas.
- P1 solo persiste `variant` cuando `campaign_id>0` (en envíos/test sin campaña queda NULL).
- La interpretación estadística de ganadora queda FUERA de esta fase.

## 14. Estados del checkpoint
- Análisis entorno: PASS
- Decisión entorno/campaña: PASS
- Arquitectura asignación: PASS
- Método elegido: PASS
- Normalización del hash: PASS
- Congelación de plantillas PILOT: PASS
- Persistencia/inmutabilidad: PASS
- Snapshot: PASS
- P1/P3 unificado: PASS
- Tests: PASS (23/23)
- Dashboard/métricas: NOT IMPLEMENTED (fuera de alcance, como se pide)

## 15. Archivos modificados (FASE 3A)
- `public_html/outbound/inc/abc.php` (NUEVO)
- `public_html/outbound/inc/eligibilidad.php` (require abc + plantillaEstaCongelada)
- `public_html/outbound/api/enviar_lote.php` (P1: entorno + variante determinística + contenido por variante)
- `public_html/outbound/cli/cron.php` (P3: entorno + variante determinística + contenido por variante)
- `public_html/outbound/dashboard.php` (guard de congelación en save_template)
- `scripts/_test_fase3.php` (NUEVO — harness)

> NO avanzo a dashboard/métricas. No realizo envíos reales. Espero aprobación.