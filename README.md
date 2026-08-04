# ScrapperClub — Scraping de clubes de fútbol españoles

Extrae **federación, nombre, teléfono y email** de todos los clubes de las federaciones territoriales de fútbol de España.

---

## Arquitectura

```
scrapperclub/
├── config.py                  # Configuración central (API keys, federaciones, delays)
├── pendientes.py              # Módulo compartido de gestión de clubes pendientes
├── main.py                    # Orquestador principal
│
├── scraper_nova.py            # Scraper plataforma NOVA (10 federaciones)
├── scraper_nova_browser.py    # Versión con navegador real (anti-bloqueo)
├── scraper_madrid.py          # Scraper RFFM (Madrid)
├── scraper_rfcylf.py          # Scraper RFCLYF (Castilla y León)
├── scraper_fcf_cat.py         # Scraper FCF (Cataluña)
│
├── scripts/                   # Utilidades y scripts auxiliares
│   ├── monitor.py             # Monitor de progreso en tiempo real
│   ├── ip_rotator.py          # Rotación de IP / proxies
│   ├── retry_madrid_pendientes.py  # Reintento de clubes pendientes de Madrid
│   └── scraper_fcf.sh         # Script shell para scraping de Cataluña
│
├── docs/                      # Documentación
│   ├── informe_estado_scraping.md        # Estado actual de todas las federaciones
│   ├── informe_diagnostico_scraping.md   # Diagnóstico de problemas
│   ├── informe_stack_arquitectura.md     # Análisis de arquitectura
│   └── informe_bloqueo_tiempo_real.md    # Análisis de bloqueos anti-scraping
│
├── output/                    # Datos generados (CSV)
│   ├── clubs_nova_<federacion>.csv   # Por federación
│   ├── clubs_todos.csv               # Consolidado
│   ├── pendientes_*.csv              # Clubes no scrapeados
│   └── clubs_pendientes.csv          # Pendientes consolidados
│
├── checkpoints/               # Progreso de scraping (JSON)
├── logs/                      # Logs de ejecución
├── debug/                     # Archivos de depuración (HTML, JSON)
├── input/                     # Archivos de entrada
│
├── requirements.txt           # Dependencias Python
├── .gitignore                 # Archivos excluidos de git
└── .clinerules                # Reglas del proyecto para Cline
```

---

## Federaciones y estado

| Federación | Scraper | Estado | Completado |
|---|---|---|---|
| **Castilla y León** | NOVA | ✅ Completo | 98% |
| **La Rioja** | NOVA | ✅ Completo | 92% |
| **Madrid** | RFFM | ✅ Completo | 64% |
| Andalucía | NOVA | ⚠️ Parcial | 37% |
| Asturias | NOVA | ⚠️ Parcial | 8% |
| Cantabria | NOVA | ⚠️ Parcial | 6% |
| Murcia | NOVA | ⚠️ Parcial | 8% |
| Galicia | NOVA | ⚠️ Parcial | 4% |
| Aragón | NOVA | ⚠️ Parcial | 3% |
| Extremadura | NOVA | ⚠️ Parcial | 1% |
| Castilla-La Mancha | NOVA | ❌ Vacío | 0% |
| Cataluña | FCF CAT | ❌ Sin iniciar | 0% |

> ℹ️ Ver `docs/informe_estado_scraping.md` para detalles completos y próximos pasos.

### ⚠️ `clubs_todos.csv`

El archivo consolidado `clubs_todos.csv` contiene **14 clubes con datos de contacto que NO están en los archivos individuales** por federación. **No eliminarlo** — se regenerará al final del scraping con `python main.py --merge-only`, combinando todos los individuales más estos 14.

---

## Uso rápido

### Requisitos
```bash
pip install -r requirements.txt
```

### Scraping de una federación NOVA
```bash
python scraper_nova.py --federacion "Andalucía"
python scraper_nova.py --federacion "Andalucía" --resume   # reanudar
```

### Scraping de Madrid (RFFM)
```bash
python scraper_madrid.py
python scraper_madrid.py --resume
```

### Reintentar pendientes de Madrid
```bash
python scripts/retry_madrid_pendientes.py --delay 4 --retries 5
python scripts/retry_madrid_pendientes.py --start-from 254  # continuar tras interrupción
```

### Monitorear progreso
```bash
python scripts/monitor.py
```

### Consolidar pendientes
```bash
python -c "from pendientes import merge_pending_csvs; merge_pending_csvs()"
```

---

## Configuración

Editar `config.py`:

- `SCRAPERAPI_KEY`: API key de [ScraperAPI](https://scraperapi.com) (necesaria para evitar bloqueos)
- `NOVA_FEDERATIONS`: lista de federaciones a scrapear
- `REQUEST_DELAY`: segundos entre peticiones (default: 2.0)
- `MAX_RETRIES`: reintentos en caso de error (default: 3)

---

## Columnas de salida

Todos los CSV usan las mismas columnas:

| Columna | Descripción |
|---|---|
| `federacion` | Nombre de la federación |
| `nombre` | Nombre del club |
| `telefono` | Teléfono(s) de contacto |
| `email` | Email de correspondencia |

---

## Bloqueante actual

🔴 **API key de ScraperAPI sin créditos** — se necesita una nueva para continuar el scraping.

---

## Ver también

- `docs/informe_estado_scraping.md` — estado detallado y próximos pasos
- `docs/informe_stack_arquitectura.md` — análisis de la arquitectura
- `.clinerules` — reglas y restricciones del proyecto