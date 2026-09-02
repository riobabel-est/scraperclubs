# ESTADO DE LISTADOS DE CLUBES — CONSOLIDADO

> Fecha de análisis: 2026-09-01 · Fuentes primarias: `config.py`, `output/*.csv` (conteo real). Los documentos previos de análisis quedaron consolidados aquí (historial git para trazabilidad).

## 0. País Vasco: NO hay datos ni listado

Tras revisar el proyecto, el País Vasco (FVF-EFF) **no tiene data**: dominios probados (`fvf.eus`, `eff-fvf.eus`, `federacionvascadefutbol.com`) no resuelven DNS, y no hay lista de clubes en `output/`, `clubes.json` ni la BD local. El mapeo Álava/Bizkaia/Gipuzkoa → «Federación Vasca de Fútbol» solo existe preparado en `scripts/generar_clubes_json.py`.

---

## 1. FEDERACIONES CON SCRAPER — ESTADO ACTUAL REAL (conteo `output/`)

| Federación | CSV | Esperados | Scrapeados | % | Con email | Con tlf | **Faltan** | Estado |
|:---|---:|---:|---:|---:|---:|---:|---:|:--|
| La Rioja | `clubs_nova_la_rioja.csv` | 79 | 73 | 92 % | 73 | 73 | 6 | 🟢 Casi completo |
| Castilla y León | `clubs_nova_castilla_y_le_n.csv` | 381 | 375 | 98 % | 375 | 374 | 6 | 🟢 Casi completo |
| Madrid | `clubs_nova_madrid.csv` | 730 | 440 | 60 % | 424 | 431 | **~290** | 🟠 Parcial |
| Andalucía | `clubs_nova_andaluc_a.csv` | 2.702 | 1.000 | 37 % | 902 | 990 | **~1.702** | 🟠 Parcial |
| Castilla-La Mancha | `clubs_nova_castilla-la_mancha.csv` | 2.029 | 148 | 7 % | 109 | 127 | **~1.881** | 🔴 Muy parcial |
| Murcia | `clubs_nova_murcia.csv` | 365 | 30 | 8 % | 30 | 28 | **~335** | 🔴 Muy parcial |
| Asturias | `clubs_nova_asturias.csv` | 377 | 32 | 8 % | 32 | 30 | **~345** | 🔴 Muy parcial |
| Cantabria | `clubs_nova_cantabria.csv` | 177 | 11 | 6 % | 11 | 9 | **~166** | 🔴 Muy parcial |
| Galicia | `clubs_nova_galicia.csv` | 1.426 | 60 | 4 % | 59 | 60 | **~1.366** | 🔴 Muy parcial |
| Aragón | `clubs_nova_arag_n.csv` | 581 | 21 | 4 % | 19 | 20 | **~560** | 🔴 Muy parcial |
| Extremadura | `clubs_nova_extremadura.csv` | 1.458 | 19 | 1 % | 8 | 11 | **~1.439** | 🔴 Muy parcial |
| **TOTAL NOVA config.** | — | **10.305** | **2.209** | **21 %** | ~2.042 | ~2.123 | **~8.096** | — |
| Comunitat Valenciana | `clubs_isquad_cv.csv` | — | **700** | — | 635 | 583 | — | 🟢 Cargada (nuevo scraper) |

> «Esperados» = `config.py EXPECTED_CLUBS` (aproximación). El informe de 2026-08-31 estimaba 2.061 scrapeados / ~8.244 pendientes; hoy con CLM (148) y Valencia (700) la cifra real es **~2.909 clubes scrapeados en total**.

---

## 2. FEDERACIONES EN `config.py` SIN NINGÚN CLUB CARGADO (0 clubes)

| Federación | URL del listado | Detalle |
|:---|:---|:---|
| **Tenerife** (FIFCT) | `https://www.ftf.es` → `ftf.es/pnfg/NPcd/NFG_Clubes` (cod 1000118) | ✅ Verificada NOVA HTTP 200; **sin ejecutar** |
| **Ceuta** (RFFCE) | `https://www.rffce.es` → `rffce.es/pnfg/NPcd/NFG_Clubes` (cod 1000118) | ✅ Verificada NOVA HTTP 200; **sin ejecutar** |
| **Navarra** (FNF) | `https://www.futnavarra.es/pnfg/NPcd/NFG_Clubes?cod_primaria=1000118&cod_estado_activo=1` | `skip=True` en config; falta validar detalle `NFG_VerClub` |
| **Las Palmas** (FIFLP) | `https://www.fiflp.com/pnfg/NPcd/NFG_LstDirectorioEquipos?cod_primaria=1000117` | `skip=True`; requiere scraper adaptado (variante `NFG_LstDirectorioEquipos`) |

**Para activarlas:** quitar `"skip": True` en `config.py` (Tenerife y Ceuta ya están activas y listas para `python scraper_nova.py --fed "Tenerife"`).

---

## 3. FEDERACIONES SIN SCRAPER / DOMINIO SIN LOCALIZAR

| Federación | Estado / URL probada | Qué falta |
|:---|:---|:---|
| **País Vasco** (FVF-EFF) | `fvf.eus`, `eff-fvf.eus`, `federacionvascadefutbol.com` → no resuelven DNS | Localizar dominio/portal actual. Provinciales: Bizkaia, Gipuzkoa, Álava |
| **Melilla** (RFMF) | `rfmelilla.es` → no resuelve | Buscar dominio actual |
| **Baleares** (FFIB) | `ffib.es` → 404 en NOVA | Portal propio; buscar directorio de clubes |
| **Cataluña** (FCF) | `futbol.cat/fnfg` e `intranet.fcf.cat/nfg` requieren login | Scraper `scraper_fcf_cat.py` existe; falta fallback SPA o acceso; hay `fcf_links.json` pero sin CSV final en `output/` |

---

## 4. LISTADOS PENDIENTES DE REINTENTAR (sin datos)

| Archivo | Cantidad | Motivo |
|:---|---:|:---|
| `output/clubs_pendientes.csv` | 335 | `sin_datos` |
| `output/pendientes_nova_madrid.csv` | 599 | Madrid sin datos (retry cortado en club 254/599 por créditos ScraperAPI) |

---

## 5. PATRÓN NOVA PARA LOCALIZAR LISTADOS

```
<dominio>/pnfg/NPcd/NFG_Clubes?cod_primaria=<cod>&NPcd_Page=1&Buscar=1&NPcd_PageLines=10
<dominio>/pnfg/NPcd/NFG_LstDirectorioEquipos?cod_primaria=<cod>   (variante sin NFG_Clubes)
```

---

## 6. PRIORIDAD RECOMENDADA (por volumen pendiente)

1. **Castilla-La Mancha** (~1.881) → `python scraper_nova.py --fed "Castilla-La Mancha" --resume`
2. **Extremadura** (~1.439) → `--fed "Extremadura" --resume`
3. **Galicia** (~1.366) → `--fed "Galicia" --resume`
4. **Andalucía** (~1.702) → `--fed "Andalucía" --resume`
5. **Aragón / Asturias / Murcia / Cantabria** (parciales) → `--resume`
6. **Tenerife y Ceuta** (0 hechos, ya verificadas) → primera ejecución
7. **Madrid pendientes** (599) → retry con nueva key ScraperAPI
8. **Navarra y Las Palmas** (skip) → validar y activar
9. **País Vasco, Melilla, Baleares, Cataluña** → localizar acceso/dominio

> Regla de ejecución: modo seguro (`--slow` / delay ≥ 3 s, 1 worker) para evitar bloqueo de IP. No solapar con la campaña de email en curso.
