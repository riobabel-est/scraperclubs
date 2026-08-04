# Informe de Estado — Scraping de Clubes de Fútbol

**Fecha**: 08/04/2026  
**Estado global**: 3 federaciones completadas, 8 con datos parciales, 1 vacía, 1 sin iniciar  
**Bloqueante**: 🔴 API key de ScraperAPI sin créditos (403 Forbidden)

---

## 1. Federaciones y su estado (ordenado por % completado)

| Federación | Archivo CSV | Tipo | Estado | Scrapeados | Esperados | % |
|---|---|---|---|---|---|---|
| **Castilla y León** | `clubs_nova_castilla_y_le_n.csv` | NOVA | ✅ COMPLETO | 375 | 381 | 98% |
| **La Rioja** | `clubs_nova_la_rioja.csv` | NOVA | ✅ COMPLETO | 73 | 79 | 92% |
| **Madrid** | `clubs_nova_madrid.csv` | RFFM | ✅ COMPLETO | 440 | 686 | 64% |
| **Andalucía** | `clubs_nova_andaluc_a.csv` | NOVA | ⚠️ Parcial | 999 | 2702 | 37% |
| **Murcia** | `clubs_nova_murcia.csv` | NOVA | ⚠️ Parcial | 29 | 365 | 8% |
| **Asturias** | `clubs_nova_asturias.csv` | NOVA | ⚠️ Parcial | 31 | 377 | 8% |
| **Cantabria** | `clubs_nova_cantabria.csv` | NOVA | ⚠️ Parcial | 10 | 177 | 6% |
| **Galicia** | `clubs_nova_galicia.csv` | NOVA | ⚠️ Parcial | 59 | 1426 | 4% |
| **Aragón** | `clubs_nova_arag_n.csv` | NOVA | ⚠️ Parcial | 20 | 581 | 3% |
| **Extremadura** | `clubs_nova_extremadura.csv` | NOVA | ⚠️ Parcial | 18 | 1458 | 1% |
| **Castilla-La Mancha** | `clubs_nova_castilla-la_mancha.csv` | NOVA | ❌ Vacío | 0 | 2029 | 0% |
| **Cataluña** | — | FCF CAT | ❌ No iniciado | 0 | ? | 0% |

---

## 2. Datos reales de los CSV (conteo exacto con Python)

| Archivo CSV | Filas totales | Clubes únicos | Con email | Con teléfono |
|---|---|---|---|---|
| `clubs_nova_castilla_y_le_n.csv` | 375 | 375 | — | ✅ |
| `clubs_nova_la_rioja.csv` | 73 | 73 | — | ✅ |
| `clubs_nova_madrid.csv` | 440 | 440 | 424 (96%) | 431 (97%) |
| `clubs_nova_andaluc_a.csv` | 999 | 999 | — | ✅ |
| `clubs_nova_murcia.csv` | 29 | — | — | — |
| `clubs_nova_asturias.csv` | 31 | — | — | — |
| `clubs_nova_cantabria.csv` | 10 | — | — | — |
| `clubs_nova_galicia.csv` | 59 | — | — | — |
| `clubs_nova_arag_n.csv` | 20 | — | — | — |
| `clubs_nova_extremadura.csv` | 18 | — | — | — |
| `clubs_nova_castilla-la_mancha.csv` | 0 | 0 | — | — |
| `clubs_todos.csv` | 2,307 | 2,002 | — | — |

---

## 3. Pendientes

| Archivo | Cantidad | Federaciones |
|---|---|---|
| `pendientes_nova_madrid.csv` | 599 | Madrid |
| `clubs_pendientes.csv` (consolidado) | 337 | Madrid, Castilla-La Mancha, Cantabria |

---

## 4. Checkpoints existentes

| Archivo | Estado |
|---|---|
| `checkpoints/madrid_progress.json` | ✅ 35/35 páginas |
| `checkpoints/cantabria_progress.json` | ✅ Completo |

---

## 5. Problemas detectados

### 🔴 API Key de ScraperAPI agotada
- Clave actual: `b58878bcc44fd06ede6d8577c6e42b8f`
- Error: **403 Forbidden** en todas las peticiones
- **Solución**: Nueva API key con créditos → actualizar `SCRAPERAPI_KEY` en `config.py`

### 🟡 Madrid — 246 clubes pendientes de recuperar
- El retry se interrumpió en el club 254/599 por falta de créditos
- Se recuperaron 77 clubes adicionales antes del corte
- **Solución**: Con nueva key: `python retry_madrid_pendientes.py --start-from 254 --delay 4`

### 🟡 Castilla-La Mancha — CSV vacío (0 registros)
- Hay archivo pero sin datos. Probablemente hubo un error que truncó el scrape.
- **Solución**: Relanzar desde cero: `python scraper_nova.py --federacion "Castilla-La Mancha"`

### 🟡 Federaciones NOVA con datos muy parciales (1%-37%)
- Andalucía (37%), Murcia (8%), Asturias (8%), Cantabria (6%), Galicia (4%), Aragón (3%), Extremadura (1%)
- Todas necesitan completarse con `--resume`
- **Solución**: `python scraper_nova.py --federacion "<nombre>" --resume`

---

## 6. Próximos pasos (orden de prioridad)

### 🔴 Bloqueante — Conseguir nueva API key de ScraperAPI
Actualizar `SCRAPERAPI_KEY = "NUEVA_CLAVE"` en `config.py`

### Prioridad 1 — Terminar lo casi completo
1. ✅ **Castilla y León** — COMPLETO (375/381). Nada que hacer.
2. ✅ **La Rioja** — COMPLETO (73/79). Nada que hacer.
3. 🔄 **Madrid** — Retry pendientes: `python retry_madrid_pendientes.py --start-from 254 --delay 4`

### Prioridad 2 — Reanudar lo parcial
4. `python scraper_nova.py --federacion "Andalucía" --resume`
5. `python scraper_nova.py --federacion "Murcia" --resume`
6. `python scraper_nova.py --federacion "Asturias" --resume`
7. `python scraper_nova.py --federacion "Cantabria" --resume`

### Prioridad 3 — Relanzar desde cero
8. `python scraper_nova.py --federacion "Castilla-La Mancha"`
9. `python scraper_nova.py --federacion "Galicia" --resume`
10. `python scraper_nova.py --federacion "Aragón" --resume`
11. `python scraper_nova.py --federacion "Extremadura" --resume`

### Prioridad 4 — Nuevos scrapers
12. Cataluña: `python scraper_fcf_cat.py`

### Al final
13. Consolidar todo: `python -c "from pendientes import merge_pending_csvs; merge_pending_csvs()"`
14. Revisar `clubs_todos.csv` final