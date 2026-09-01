# PLAN DEL LUNES — PRIMER ENVÍO REAL CONTROLADO (FASE 6)

> **Fecha:** 2026-08-31 (preparación) · **Ejecución prevista:** lunes
> **Base:** PROMPT OPERATIVO del primer envío real + protocolo del MEGAPROMPT V2
> **Estado:** despliegue completado y verificado · motor `pausado` · kit preparado

---

## 1. CONTEXTO REAL (ya desplegado en producción)

| Ítem | Valor |
|---|---|
| Código fases 1-5 | ✅ En SiteGround (`getfutprotec.com/outbound`) |
| BD de producción | ✅ Migrada (F1-F5 + adjuntos) · integrity ok |
| `envios` | 496 · `aperturas` 489 · `respuestas` 34 · `rebotes` 22 |
| Backup remoto | `data/stats.db.bak_pre_deploy_20260831_155432` |
| Adjuntos en disco | ✅ `data/adjuntos/**` + `.htaccess` |
| Motor | `pausado` |

## 2. HERRAMIENTAS DEL KIT

- **Auditoría pre-lote:** `public_html/outbound/cli/auditoria_pre_lote.php` (ya en el servidor; CLI).
- **Kit de preparación:** `scripts/kit_lunes.py` (descarga/usa BD fresca → 10 checks → límite diario → pendientes → informe en `docs/checkpoint_lunes_*.md`).
- **Informe final:** plantilla (formato del PROMPT, sección 18) → `docs/informe_lunes_*.md`.

## 3. FLUJO DEL LUNES (paso a paso)

1. **Descargar BD fresca de producción** (`sync_statsdb_siteground.py`).
2. **Ejecutar el kit:** `python scripts/kit_lunes.py <bd_fresca> --crear-batch --limite=200`
   → si `READY TO SEND` y no existe el batch, lo crea y genera `stats.db.lunes_<ts>` **lista para subir**.
3. **Subir la BD con el batch** (deploy_bd_adjuntos.py con la BD lunes, backup remoto previo) — **solo si hace falta** el batch en producción.
4. **Calcular límite:** `150 − envíos_comerciales_hoy` (sobre BD de producción). Si < 150, hoy se envía ese número.
5. **Disparar el envío** desde la **lanzadera del panel de producción** (máx el límite calculado), con el delay configurado.
6. **Parada automática** al llegar al límite / fin de pendientes / BLOCKED / TEST / duplicado / SMTP inesperado.
7. **Dejar el motor `pausado`** y verificar: ACCEPTED/ERROR, bounces, TEST/REAL, duplicados, campaign_id, batch, variantes, tracking, integridad.
8. **Informe final** (sección 18) + checkpoint en `docs/`.

## 4. REGLAS DURAS

- **Máximo 150 comerciales/día** (global, no por SMTP). Nunca los 200 en un día.
- Día 1: máx 150 · Día 2: los 50 restantes (con nuevo checkpoint + cálculo de límite).
- **Idempotencia:** si se interrumpe en 87, continuar desde 88; nunca reiniciar.
- **No modificar código/BD** el lunes salvo bloqueo real y autorización.
- **No follow-ups** · no respuestas automáticas · no otras campañas.
- Los umbrales son **reglas operativas, no estadística**.

## 5. PLANTILLA DEL INFORME FINAL (sección 18)

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PRIMER ENVÍO REAL — RESULTADO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ESTADO: PASS / BLOCKED / PARTIAL
CAMPAÑA: 2
BATCH: 2026-08-30-A
LÍMITE DIARIO: 150
ENVÍOS COMERCIALES REALIZADOS HOY: X / 150
ENVÍOS DEL BATCH REALIZADOS: X / 200
RESTANTES: X
ACCEPTED: X
ERROR: X
HARD BOUNCES: X
APERTURAS: X
APERTURAS DEDUP: X
CLICS: X
RESPUESTAS: X
POSITIVAS: X
NEGATIVAS: X
VARIANTES: A=X · B=X · C=X
TEST: 0
DUPLICADOS: 0
CAMPAIGN_ID NULL: 0
INTEGRIDAD DB: PASS / FAIL
MOTOR: PAUSADO / ESTADO
IMPACTO: DESCRIBIR
CHECKPOINT: RUTA DEL DOCUMENTO
SIGUIENTE PASO: DESCRIBIR
AUTORIZACIÓN REQUERIDA: SÍ / NO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

## 6. NOTAS OPERATIVAS PARA EL LUNES

- **Contador SMTP:** en la BD desplegada, las cuentas tienen `enviados_hoy` alto (varios ~13-15). El kit muestra cuántas cuentas tienen límite disponible. **Verificar antes de disparar** que la capacidad SMTP real permite los 150 (o que el contador se resetea/sincroniza).
- **Batch en producción:** hoy `batches` está vacía en producción (el batch `2026-08-30-A` se creó solo en la BD local anterior). El paso 3 del flujo lo crea en la BD que se sube.
- **Disparo:** la lanzadera del panel de producción carga la cola de la campaña 2 (pendientes sin envío) y envía con delay; el corte se hace manualmente al alcanzar el límite diario o automático al finalizar la cola cargada.

---

*Fin del plan del lunes · 2026-08-31 · despliegue completado · motor pausado · kit listo.*
