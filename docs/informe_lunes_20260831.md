# INFORME FINAL — PRIMER ENVÍO REAL (FASE 6) · LUNES 2026-08-31

> Fuente: BD producción (verificada al cierre del día) · formato sección 18 del plan del lunes.

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PRIMER ENVÍO REAL — RESULTADO
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ESTADO: PASS
CAMPAÑA: 2 · Comerciales FutProtec 2026-08
BATCH: 2026-08-30-A
LÍMITE DIARIO: 150
ENVÍOS COMERCIALES REALIZADOS HOY: 150 / 150
ENVÍOS DEL BATCH REALIZADOS: 150 / 200
RESTANTES: 50
ACCEPTED: 150
ERROR: 0
HARD BOUNCES: 0
APERTURAS: 43
APERTURAS DEDUP: 43
CLICS: 0
RESPUESTAS: 1
POSITIVAS: 0
NEGATIVAS: 0
VARIANTES: A=45 · B=46 · C=59
TEST: 0
DUPLICADOS: 0
CAMPAIGN_ID NULL: 0
CAMPAIGN_BATCH VINCULADO: 150 / 150
INTEGRIDAD DB: PASS
MOTOR: PAUSADO
IMPACTO: NINGUNO negativo — 0 errores, 0 bounces, contadores SMTP 15/15 (límite diario respetado)
CHECKPOINT: docs/checkpoint_lunes_20260831_1607.md
SIGUIENTE PASO: Día 2 (50 restantes) tras nuevo checkpoint · preparar escalado FASE 7
AUTORIZACIÓN REQUERIDA: SÍ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

## Resumen operativo

- **150/150** del límite diario comercial enviados (test de 10 + lanzadera 140), **0 errores**, **0 hard bounces**.
- **43 aperturas dedup** en las primeras horas → open rate preliminar **~28,7 %** (seguirá subiendo 24-48 h).
- **1 respuesta** recibida (sin clasificar aún como positiva/negativa).
- **SMTP**: las 10 cuentas a 15/15 (límite diario consumido correctamente, rotación de carga).
- **Trazabilidad**: 150/150 con `campaign_id=2`, `campaign_batch_id='2026-08-30-A'`, variante determinista, `tracking_id` único, `es_test=0`.

## Guardrails FASE 7 (preliminares sobre el lote de hoy)

| Métrica | Valor | Umbral FASE 7 | ✅ |
|---|---:|---|---|
| Bounce rate | 0 % | < 3 % | ✅ |
| Hard bounce rate | 0 % | < 1 % | ✅ |
| Open rate | 28,7 % | estable/creciente | ✅ |
| Reply rate | 0,7 % (N=1) | con N declarado | ⚠️ N insuficiente aún |

**Conclusión:** entregabilidad OK. El **día 2** (los 50 restantes del batch, hasta 200) se autoriza con este informe. El escalado más allá del batch (300/500/1.000, FASE 7) requiere acumular más `N` de respuestas (24-72 h).

## Trazabilidad — novedad de hoy

- **Automatizado `campaign_batch_id`**: `inc/eligibilidad.php` → `reservarEnvioLogico()` ahora auto-detecta el batch `AUTORIZADO` de la campaña (función `batchActivoDeCampana`) y lo persiste. Los 150 de hoy se vincularon por UPDATE puntual; **los próximos envíos quedarán trazados sin intervención**.
- **Históricos intocables** (regla *NULL ≠ 0 / histórico intocable*): 32 envíos sin `campaign_id` y 28 respuestas sin `envio_id` son pre-instrumentación → documentados, no se modifican.

## Nota de cierre

El motor queda **pausado**. La lanzadera (UI) y las analíticas siguen operativas. Próximo bloque recomendado: informe de aperturas/respuestas a las 48 h → decisión de escalado (FASE 7).
