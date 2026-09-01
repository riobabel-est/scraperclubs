# LISTADOS DE CLUBES — FEDERACIONES PENDIENTES / NUEVAS

> Actualizado: 2026-08-31 · Fuente: verificación de acceso directo + URLs facilitadas por el operador.

## 1. AÑADIDAS A `config.py` (listas para scrapear)

| Federación | Dominio | Listado NOVA | cod_primaria |
|---|---|---|---|
| Tenerife | `https://www.ftf.es` | `ftf.es/pnfg/NPcd/NFG_Clubes` | `1000118` |
| Ceuta | `https://www.rffce.es` | `rffce.es/pnfg/NPcd/NFG_Clubes` | `1000118` |

Verificadas: HTTP 200 + HTML NOVA (contiene `NFG_VerClub`), sin login, acceso directo OK.

## 2. GUARDADAS EN `config.py` COMO PENDIENTES (`skip=True`)

| Federación | URL del listado | Notas |
|---|---|---|
| Navarra | `https://www.futnavarra.es/pnfg/NPcd/NFG_Clubes?cod_primaria=1000118&cod_estado_activo=1` | Listado `NFG_Clubes` (dominio `futnavarra.es`, no `fnf.es`). Falta validar detalles `NFG_VerClub`. |
| Las Palmas | `https://www.fiflp.com/pnfg/NPcd/NFG_LstDirectorioEquipos?cod_primaria=1000117` | Usa `NFG_LstDirectorioEquipos` (flujo tipo Castilla y León). Requiere scraper adaptado o prueba de que `NFG_Clubes` no existe. |

## 3. PENDIENTES DE LOCALIZAR (dominios/portales por confirmar)

| Federación | Estado |
|---|---|
| País Vasco (FVF-EFF) | Dominios probados (`fvf.eus`, `eff-fvf.eus`, `federacionvascadefutbol.com`) no resuelven DNS. Ver también provinciales: Bizkaia, Gipuzkoa, Álava. |
| Melilla (RFMF) | `rfmelilla.es` no resuelve. Buscar dominio actual. |
| Valencia (FFCV) | Portal propio (`ffcv.es` no usa NOVA en el path probado). Buscar directorio de clubes. |
| Baleares (FFIB) | `ffib.es` da 404 en NOVA. Portal propio. Buscar directorio. |

## 4. PATRÓN NOVA (para localizar listados de otras federaciones)

```
<dominio>/pnfg/NPcd/NFG_Clubes?cod_primaria=<cod>&NPcd_Page=1&Buscar=1&NPcd_PageLines=10
<dominio>/pnfg/NPcd/NFG_LstDirectorioEquipos?cod_primaria=<cod>   (variante sin NFG_Clubes)
```

**Regla operativa:** las federaciones marcadas `skip=True` en `config.py` NO se scrapean con la ejecución por defecto; se activan cuando se decida (quitando el `skip`).
