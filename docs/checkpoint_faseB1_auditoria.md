# CHECKPOINT FASE B.1 — AUDITORÍA SEMÁNTICA DE HALLAZGOS B (READ-ONLY)

- **Fecha/hora**: 2026-08-18 02:13 (Europe/Madrid)
- **Modo**: EXCLUSIVAMENTE READ-ONLY. No se modificó producción.
- **Scripts**: `scripts/faseB1_auditoria.py` (recopilación de evidencia) + inspección de código fuente.

## 1. IDENTIDAD DE LA BD
- Ruta: `/getfutprotec.com/public_html/outbound/data/stats.db`
- Tamaño: 983040 bytes
- MD5: `4dbc8e72608dd1f0ebd7ad25aaa58364` (idéntico al checkpoint FASE A.3-FINAL → sin cambios desde la verificación forense)
- Config: `modo_entorno = produccion`, `motor_estado = pausado`

## 2. CÓDIGO / FUNCIONES INSPECCIONADAS
- `public_html/outbound/inc/eligibilidad.php` — `esLeadTest()`, `esCampanaTest()`, `esEnvioTest()`, `sqlFiltroComercial()`, `sqlFiltroCompatibilidadLeadCampana()`, `esElegibleParaEnvio()`, `plantillaEstaCongelada()`, `reservarEnvioLogico()`
- `public_html/outbound/inc/abc.php` — `asignarVariante()`, `resolverContenidoVariante()`, `validarCampanaActiva()`, `esEntornoCoherente()`
- `public_html/outbound/api/get_cola.php` — selección de cola, `sqlFiltroCompatibilidadLeadCampana()`, `asignarVariante()`
- `public_html/outbound/api/enviar_lote.php` — validación de campaña, variante, elegibilidad, reserva de envío
- `public_html/outbound/cli/cron.php` — validación de campaña, selección de lead, variante, envío

## 3. SEMÁNTICA DE `entorno` Y `estado` (evidencia de código)

### `entorno` (pipelines.entorno)
- `esCampanaTest()`: una campaña es TEST cuando `entorno = 'test'` (case-insensitive). **No depende de `estado`.**
- `esEntornoCoherente()` (abc.php):
  - `modo_entorno=produccion` + `campaign_entorno=test` → **BLOQUEADO** (`campaign_test_en_produccion`)
  - `modo_entorno=test` + `campaign_entorno∈{pilot,production}` → **BLOQUEADO** (`campaign_comercial_en_test`)
- `sqlFiltroCompatibilidadLeadCampana()`: campaña TEST → solo devuelve leads TEST (`@futprotec.local` o `test%`).

### `estado` (pipelines.estado)
- `validarCampanaActiva()`: estados operables para envío = **PILOT, ACTIVE** (y `activo=1`). DRAFT NO es operable.
- `plantillaEstaCongelada()`: una plantilla usada por campaña en PILOT/ACTIVE se considera congelada (no sobrescribible).

### Matriz entorno × estado (combinaciones soportadas por la arquitectura actual)

| entorno | estado | ¿válido? | ¿puede enviar? | significado |
|---|---|---|---|---|
| test | DRAFT | SÍ | NO | campaña de prueba no operable |
| test | PILOT | SÍ | SÍ (solo a leads TEST) | campaña de prueba operable (smoke test) |
| test | ACTIVE | SÍ | SÍ (solo a leads TEST) | campaña de prueba activa |
| pilot | PILOT | SÍ | SÍ (solo en modo test local) | campaña piloto comercial |
| pilot | ACTIVE | SÍ | SÍ (solo en modo test local) | campaña piloto comercial activa |
| production | ACTIVE | SÍ | SÍ (en producción) | campaña comercial real |

**Conclusión**: `entorno=test` + `estado=PILOT` es una combinación **válida y soportada** por la arquitectura. No está prohibida por el código. `estado=PILOT` es un estado operable; `entorno=test` la clasifica como campaña TEST.

## 4. ANÁLISIS B1 — PIPELINE 3 (SMOKE_TEST_FUTPROTEC_2026_08)

### Datos
- id=3, nombre='SMOKE TEST FutProtec 2026-08', identificador='SMOKE_TEST_FUTPROTEC_2026_08'
- `entorno=test`, `estado=PILOT`, `activo=1`, `tipo=outbound`
- created_at = 2026-08-14 22:38:45
- Envios con campaign_id=3: **6**, TODOS `es_test=1` (TEST), TODOS `estado=enviado`
  - envio 3 → lead 1809 (test01@futprotec.local) variant=A
  - envio 4 → lead 1813 (test05@futprotec.local) variant=B
  - envio 5 → lead 1811 (test03@futprotec.local) variant=C
  - envio 6 → lead 1810 (test02@futprotec.local) variant=A
  - envio 7 → lead 1812 (test04@futprotec.local) variant=C
  - envio 8 → lead 1817 (test_abc_final6_b@futprotec.local) variant=B
- lead_pipelines con pipeline_id=3: **0**
- plantilla_id usados: [2]

### ¿Puede el pipeline 3 provocar un envío comercial REAL?
**NO.** Evidencia:
1. `esCampanaTest()` → pipeline 3 es campaña TEST (entorno=test).
2. `esEntornoCoherente()` con `modo_entorno=produccion` → `campaign_test_en_produccion` → **BLOQUEADO** en `validarCampanaActiva()` (usada por `enviar_lote.php` y `cron.php`). Un TEST no sale a producción.
3. Incluso si se forzara, `esElegibleParaEnvio()` bloquea CAMPAÑA TEST + LEAD REAL (`lead_real_en_campana_test`), y `sqlFiltroCompatibilidadLeadCampana()` solo devuelve leads TEST.
4. Los 6 envíos existentes son todos TEST (es_test=1) a leads TEST. No hay ningún lead REAL ni envío REAL asociado.
5. `motor_estado = pausado` (motor de envío pausado).

### Clasificación B1
**EXCEPCIÓN DOCUMENTADA** — El pipeline 3 es una campaña de smoke test deliberadamente creada en estado PILOT (operable para pruebas) con entorno test (aislada de producción). La combinación `entorno=test` + `estado=PILOT` es válida y soportada. No es una incoherencia que afecte al envío comercial.

## 5. ANÁLISIS B2 — LEAD_PIPELINES 2, 4, 5 (variantes históricas)

### Datos
- id=2: lead 1810, pipeline 1, variante_ab='B', fecha_asignacion=2026-08-11 13:54:40
- id=4: lead 1812, pipeline 1, variante_ab='A', fecha_asignacion=2026-08-11 13:54:40
- id=5: lead 1813, pipeline 1, variante_ab='B', fecha_asignacion=2026-08-11 13:54:40
- Pipeline 1 (LEGACY_TEST_FASE1): `entorno=test`, `estado=DRAFT`, `activo=1`, descripcion='Pipeline de prueba Fase 1 - NO REAL', created_at=2026-08-11 13:54:40
- Leads 1810, 1812, 1813: todos TEST (`test02@futprotec.local`, `test04@futprotec.local`, `test05@futprotec.local`), estado='01 Sin Contactar'

### Comparación variante histórica vs asignarVariante()
| LP | lead | pipeline | histórica | determinista | ¿difiere? |
|---|---|---|---|---|---|
| 2 | 1810 | 1 | B | C | SÍ |
| 4 | 1812 | 1 | A | C | SÍ |
| 5 | 1813 | 1 | B | A | SÍ |

### ¿Se usan las variantes históricas para enviar?
**NO.** Evidencia:
1. `reservarEnvioLogico()` (eligibilidad.php): para campaign_id>0, la variante se **recalcula** con `asignarVariante($leadId, $campaignId)`. No usa `lead_pipelines.variante_ab`.
2. `enviar_lote.php` (línea 85): `$varianteUsada = $modoTest ? $varianteAb : asignarVariante($idClub, $idCampana)`. En producción, siempre `asignarVariante()`.
3. `cron.php` (línea 170): `$variantUsada = asignarVariante(...)`.
4. `get_cola.php` (línea 200): `asignarVariante()` server-side.
5. **Envios con campaign_id=1 (LEGACY_TEST_FASE1): 0** — el pipeline 1 no tiene ningún envío.
6. Los envíos reales de los leads 1810/1812/1813 están en campaign 3 con variantes A/C/B (deterministas de campaign 3), NO las históricas B/A/B de pipeline 1.

### ¿Las variantes históricas afectan a envíos actuales?
**NO.** No se usan para selección de plantilla, medición A/B/C, trazabilidad de envíos, atribución ni envíos futuros. El pipeline 1 es DRAFT, entorno=test, "NO REAL", sin envíos. Son datos de una fase anterior (Fase 1) creados antes de la función determinista actual.

### Clasificación B2
**CONSERVAR COMO HISTÓRICO** — Las variantes A/B/C de lead_pipelines 2/4/5 son datos históricos legítimos de la Fase 1 (LEGACY_TEST_FASE1), creados el 2026-08-11 antes de la función `asignarVariante()` determinista. No se usan para envíos actuales. Modificarlas destruiría trazabilidad sin beneficio funcional.

## 6. AISLAMIENTO TEST/REAL (comprobación prioritaria)

| Hallazgo | entorno | ¿lead REAL asociado? | ¿envío REAL asociado? | ¿excluido por sqlFiltroComercial? | ¿ruta a campaña comercial? |
|---|---|---|---|---|---|
| B1 pipeline 3 | test | NO (solo leads TEST) | NO (solo es_test=1) | SÍ (es_test=1 → excluido) | NO (bloqueado en producción) |
| B2 LP 2/4/5 | test (pipeline 1) | NO (solo leads TEST) | NO (0 envíos en pipeline 1) | SÍ | NO (pipeline 1 DRAFT, sin envíos) |

**Riesgo REAL de envío comercial accidental: BAJO.**

## 7. COMPROBACIÓN DE REGRESIONES
- Comparado contra `docs/checkpoint_faseA3_final_verificacion.md`:
  - MD5 de la BD idéntico (`4dbc8e72...`) → sin cambios desde la verificación forense.
  - No se modificó envios, es_test, leads 1815/1816, pipelines, lead_pipelines, variantes, respuestas, message_id ni estados históricos.
- **Sin regresiones.**

## 8. MATRIZ FINAL

| Hallazgo | Evidencia | Riesgo actual | ¿Afecta envíos? | Clasificación | Acción recomendada |
|---|---|---|---|---|---|
| B1 pipeline 3 | entorno=test+estado=PILOT válido; 6 envíos TEST a leads TEST; bloqueado en producción | BAJO | NO (solo TEST) | EXCEPCIÓN DOCUMENTADA | Conservar; documentar como smoke test |
| B2 LP 2 | variante B histórica (pipeline 1, DRAFT, sin envíos); no usada para enviar | BAJO | NO | CONSERVAR COMO HISTÓRICO | No modificar |
| B2 LP 4 | variante A histórica (pipeline 1, DRAFT, sin envíos); no usada para enviar | BAJO | NO | CONSERVAR COMO HISTÓRICO | No modificar |
| B2 LP 5 | variante B histórica (pipeline 1, DRAFT, sin envíos); no usada para enviar | BAJO | NO | CONSERVAR COMO HISTÓRICO | No modificar |

## 9. CONCLUSIONES
1. **B1**: `entorno=test` + `estado=PILOT` es una combinación válida y soportada. El pipeline 3 es una campaña de smoke test deliberada, aislada de producción. **No puede provocar un envío comercial REAL.**
2. **B2**: Las variantes históricas de lead_pipelines 2/4/5 son datos legacy de la Fase 1, no se usan para envíos actuales (el envío siempre recalcula con `asignarVariante()`). **Deben conservarse como histórico.**
3. **Riesgo de envío comercial accidental: BAJO.**
4. No se requiere ninguna reparación en esta fase.

## 10. DECISIONES PENDIENTES
- Ninguna reparación es necesaria según la evidencia. No se requiere decisión del usuario para B1 ni B2.
- (Opcional, no bloqueante) Si se desea, se puede documentar formalmente el pipeline 3 como "smoke test" en su descripción, pero NO es necesario y NO se ejecuta en esta fase.

## 11. CONFIRMACIÓN DE NO MODIFICACIÓN
- Producción NO fue modificada. No se ejecutó UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX, no se subió BD, no se reemplazó ningún archivo, no se lanzaron campañas ni se enviaron emails.

---

## VEREDICTO FINAL

FASE B.1 — AUDITORÍA SEMÁNTICA READ-ONLY

- Producción modificada: 0
- Envíos realizados: 0
- Campañas lanzadas: 0
- B1: EXCEPCIÓN DOCUMENTADA
- B2: CONSERVAR COMO HISTÓRICO
- Riesgo de envío comercial accidental: BAJO
- Integridad BD: PASS
- FASE A.3-FINAL: SIN REGRESIONES
- Producción: READ-ONLY / SIN CAMBIOS
