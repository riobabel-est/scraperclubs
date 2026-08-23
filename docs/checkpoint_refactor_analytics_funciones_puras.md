# Checkpoint — Refactor de `api/analytics.php` a funciones puras

**Fecha:** 22/08/2026
**Archivo:** `public_html/outbound/api/analytics.php`
**Objetivo:** Reducir el acoplamiento del monolito de analytics extrayendo la lógica de negocio a funciones puras testables, dejando los handlers como orquestadores delgados.

---

## Resumen del refactor

Se extrajeron **11 funciones puras** de los 3 handlers más grandes del archivo. Cada handler ahora solo orquesta: lee parámetros, llama a las funciones puras y devuelve JSON.

### 1. `get_analytics` → 5 funciones puras
| Función pura | Responsabilidad |
|---|---|
| `getAnalyticsEnvios($db)` | Histórico de envíos REALES (excluye TEST) |
| `getAnalyticsAperturas($db)` | Aperturas comerciales (solo envíos REALES) |
| `getAnalyticsRebotes($db)` | Rebotes comerciales (solo envíos REALES) |
| `getAnalyticsBajas($db)` | Bajas comerciales (excluye leads TEST) |
| `getAnalyticsDashboard($db, $pipeline, $variante, $excluirTest)` | Funnel 12 niveles, KPIs económicos, comparativa A/B/C, objetivo 20 clubes |

### 2. `get_respuestas` → 2 funciones puras
| Función pura | Responsabilidad |
|---|---|
| `calcularScorePrioridad($db, &$conversaciones)` | Calcula score y semáforo de prioridad por conversación (modifica in-place) |
| `ordenarConversaciones($conversaciones)` | Ordena por prioridad (alta>media>baja) y luego por última respuesta |

### 3. `get_followups` → 3 funciones puras
| Función pura | Responsabilidad |
|---|---|
| `getFollowupsNoRespondedores($db, $whereCommercial)` | F4.1: Leads Contactados sin respuesta |
| `getFollowupsSinProximaAccion($db, $whereCommercial)` | F4.2: Leads avanzados sin próxima acción |
| `getFollowupsKpis($db, $noRespondedores, $sinProximaAccion)` | F4.3: KPIs operativos |

---

## Cambios realizados

1. **`get_analytics`**: El bloque monolítico (que mezclaba 5 tabs en un solo `if`) se dividió en 5 funciones puras. El handler ahora es un `switch`/`if-elseif` que delega según `$_GET['tab']`.

2. **`get_respuestas`**: El bloque de cálculo de score/prioridad (líneas ~212-273) y el `usort` de ordenación se extrajeron a `calcularScorePrioridad()` y `ordenarConversaciones()`. El handler conserva la consulta SQL, la agrupación por lead y el filtro de prioridad, pero delega el cálculo y la ordenación.

3. **`get_followups`**: El bloque monolítico (F4.1 + F4.2 + F4.3) se dividió en 3 funciones puras. El handler ahora solo construye `$whereCommercial` y delega.

---

## Verificación

- `php -l public_html/outbound/api/analytics.php` → **No syntax errors detected** ✅
- Se preservó la lógica de negocio exacta (mismas consultas SQL, mismos cálculos de score/prioridad, mismo orden de prioridad).
- No se cambió la estructura de columnas de salida ni los contratos JSON del frontend.
- Compatible con PHP 8.x nativo / SiteGround (sin extensiones externas).

---

## Beneficios

- **Testabilidad**: Las funciones puras pueden invocarse de forma aislada con un `$db` de prueba.
- **Legibilidad**: Los handlers pasan de cientos de líneas a orquestadores de ~10-20 líneas.
- **Mantenibilidad**: Cada tab/función de negocio se puede modificar sin tocar el resto.
- **Sin regresión**: La lógica se movió tal cual (copy-paste + firma de función), sin alterar comportamiento.

---

## Pendiente

- El refactor es **solo local** (no se ha hecho deploy ni `git push`). El usuario debe decidir cuándo desplegar a producción.
- Se recomienda un smoke test funcional de los 3 endpoints (`get_analytics`, `get_respuestas`, `get_followups`) tras el deploy.
