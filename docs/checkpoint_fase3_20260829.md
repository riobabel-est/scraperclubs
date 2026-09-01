# CHECKPOINT — FASE 3 · TRACKING FIABLE (MEGAPROMPT V2)

> **Fecha:** 2026-08-29 · **Estado:** PASS (pendiente autorización para FASE 4)
> **Base:** `docs/megaprompt_v2_crm_futprotec.md` · `docs/plan_instrumentacion_v2.md`
> **Backup previo:** `public_html/outbound/data/stats.db.bak_fase3_20260829_015305` (verificado, integrity ok)

---

## 1. CAMBIOS APLICADOS

### 1.1 Base de datos (aditivo, registrado en `_migraciones` → id 4)
| Objeto | Cambio |
|---|---|
| `clics` | CREATE (id, envio_id, lead_id, campaign_id, tracking_id, url_original, tipo_cta, fecha, user_agent, ip, es_test) + índices envio/lead/campaign/tracking |
| `vw_aperturas_analiticas` | CREATE VIEW: 1 fila por envío con `primera_apertura`, `ultima_apertura`, `num_aperturas`, `opened`, `apertura_humana_probable` (heurística UA anti-bot, sin certeza). Bruto de `aperturas` intacto |

### 1.2 Código
| Archivo | Cambio |
|---|---|
| `public_html/outbound/api/track.php` | FASE 3: ya no acumula una línea en `clubes_crm.observaciones` por cada carga del píxel; solo la primera apertura por tracking_id (las reaperturas solo actualizan `ultimo_contacto`) |
| `public_html/outbound/api/click.php` | NUEVO redirector de clics: registra en `clics` (deriva envio_id/lead_id/campaign_id por tracking_id), clasifica CTA (`CTA_WEB/CTA_PRESUPUESTO/CTA_MOCKUP/CTA_CONTACTO/BAJA`), whitelist de dominios (anti open-redirect), `html_entity_decode`, redirige 302 |
| `public_html/outbound/inc/mime.php` | `convertirContenidoAHtml()` convierte las URLs de `www.futprotec.com` en enlaces a `api/click.php?t=<tracking>&u=<url>` (CTA medible). La URL de baja (`getfutprotec.com`) NO se reescribe |
| `scripts/test_fase3_tracking.php` | NUEVO test de FASE 3 (TEST 01/02/03/04/07) |

## 2. CONTEO ANTES → DESPUÉS

| Métrica | Antes | Después |
|---|---:|---:|
| `aperturas` (bruto) | 326 | **326 intacto** |
| `vw_aperturas_analiticas` | — | 470 filas (1 por envío) · camp2 opened=134 · Segosala=12 |
| `clics` | — | 0 (tabla nueva; el test limpió su clic de prueba) |
| `_migraciones` | 3 | 4 |
| `integrity_check` | ok | ok |
| TEST/REAL | intactos | sin cambios |

## 3. TESTS (RESULTADO)

- **TEST FASE 3 (`scripts/test_fase3_tracking.php`): 10 PASS / 0 FAIL**
  - TEST 01 DB integrity · TEST 02 apertura dedup (camp2=134, Segosala=12, 470 filas) · TEST 03 bruto intacto (326) · TEST 04 click attribution (clic con envio/lead/campaign/tracking/url/tipo, atribuido por tracking_id; prueba es_test=1 y limpiada) · TEST 07 MIME (CTA reescrito a click.php; URL de baja no reescrita).
- **Regresiones:** FASE 1 (14/14) · FASE 2 (9/9) · eligibilidad (PASS).
- Sintaxis: `php -l` OK en `track.php`, `click.php`, `mime.php`.

## 4. REQUISITOS DE FASE 3 — ESTADO

| Requisito | Estado |
|---|---|
| Bruto de `aperturas` intacto | ✅ 326 (verificado) |
| Métricas dedup correctas (primera/última/N/opened) | ✅ vista `vw_aperturas_analiticas` |
| Apertura humana probable (heurística, sin certeza) | ✅ columna en la vista (UA anti-bot) |
| Clics atribuidos por tracking/envio/lead (nunca email/asunto) | ✅ `api/click.php` |
| CTA principal de FutProtec medible | ✅ reescritura en `mime.php` |

## 5. RIESGOS RESIDUALES

- La heurística `apertura_humana_probable` es una estimación, no certeza (Gmail a veces no expone UA o lo vacía). Documentado en la vista.
- Los clics solo se registran para los envíos que pasan por `convertirContenidoAHtml` (plantillas texto_plano/html del motor). La reescritura aplica sobre `www.futprotec.com`; otras URLs de CTA (si se añaden) habrá que incluirlas.
- `api/click.php` redirige solo a dominios en whitelist (futprotec.com/getfutprotec.com); fuera de la whitelist no registra (mitiga open-redirect).

## 6. ROLLBACK

- **DB:** restaurar `data/stats.db.bak_fase3_20260829_015305` (revierte `clics` y la vista).
- **Código:** revertir `track.php`, `click.php`, `mime.php` (aditivos/reversibles).

## 7. IMPACTO EN PRODUCCIÓN Y ENVÍOS

- **IMPACTO EN PRODUCCIÓN:** ninguno — no se ha subido nada.
- **ENVÍOS REALIZADOS: 0** · `motor_estado` sigue `pausado`.

## 8. SIGUIENTE FASE

**FASE 4 — Operativa comercial** (clasificación rápida de respuestas, cualificación, `presupuestos`/`mockups` operativos con trazabilidad, `oportunidades`).

**AUTORIZACIÓN REQUERIDA: SÍ.**

---

*Checkpoint FASE 3 · 2026-08-29 · MEGAPROMPT V2*
