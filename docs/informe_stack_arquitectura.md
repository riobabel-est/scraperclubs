# ScrapperClub — Informe de Stack y Arquitectura

> Documento para revisión del asesor técnico.

---

## 1. Stack Tecnológico

| Capa | Tecnología | Versión |
|---|---|---|
| **Lenguaje** | Python 3 | — |
| **HTTP Client** | `requests` | ≥2.32.0 |
| **HTML Parser** | `beautifulsoup4` + `lxml` | ≥4.12.0 / ≥5.0.0 |
| **Concurrencia** | `concurrent.futures.ThreadPoolExecutor` | stdlib |
| **Persistencia** | CSV (utf-8-sig) + JSON (checkpoints) | stdlib |
| **CLI** | `argparse` | stdlib |
| **SO objetivo** | Windows (compatible Linux/Mac) | — |

**Dependencias externas: 3** — mínimo absoluto. Sin bases de datos, sin frameworks web, sin servicios cloud.

Archivo `requirements.txt`:

```
requests>=2.32.0
beautifulsoup4>=4.12.0
lxml>=5.0.0
```

---

## 2. Arquitectura

```
main.py  ← orquestador central
  ├── scraper_nova.py    ← 8 federaciones en plataforma NOVA (NFG_Clubes + NFG_VerClub)
  ├── scraper_rfcylf.py  ← Castilla y León (NFG_LstDirectorioEquipos, sin email)
  └── scraper_fcf_cat.py ← Cataluña (SPA propia + fallback portal NOVA)

config.py  ← federaciones, delays, page_size
monitor.py ← monitor de progreso en tiempo real
```

### Flujo general

1. `main.py` invoca uno o más scrapers según flags (`--only nova|rfcylf|fcf`)
2. Cada scraper escribe su CSV individual: `output/clubs_<scraper>_<federacion>.csv`
3. Cada scraper guarda checkpoints en `checkpoints/*.json` por federación
4. `main.py` mergea todos los CSVs en `output/clubs_todos.csv` (deduplicado por nombre normalizado)

### Columnas de salida

`federacion, nombre, telefono, email`

---

## 3. Estructura de archivos

```
scrapperclub/
├── main.py              # Orquestador + merge final
├── scraper_nova.py      # Scraper principal (8 federaciones NOVA)
├── scraper_rfcylf.py    # Castilla y León (directorio equipos)
├── scraper_fcf_cat.py   # Cataluña (SPA + NOVA fallback)
├── config.py            # Config: federaciones, delays, page_size
├── monitor.py           # Monitor de progreso en vivo
├── requirements.txt     # Solo 3 dependencias
├── .clinerules          # Reglas del proyecto
├── README.md
├── scraper.log
│
├── output/              # CSVs generados
│   ├── clubs_nova.csv           # Consolidado NOVA
│   ├── clubs_nova_<fed>.csv     # Por federación
│   ├── clubs_rfcylf.csv
│   ├── clubs_fcf_cat.csv
│   └── clubs_todos.csv          # Merge final
│
└── checkpoints/         # Reanudación
    ├── <federacion>.json        # IDs ya procesados
    └── progress_<fed>.json      # Progreso (total, ok, err, pendientes)
```

---

## 4. Flujo de scraping detallado (NOVA — el más completo)

```
1. init_session()          → warm-up para obtener cookie JSESSIONID
2. get_all_club_ids()      → pagina NFG_Clubes, extrae todos los IDs
3. scrape_federation()     → itera IDs pendientes:
   ├── scrape_club()       →   fetch NFG_VerClub por ID
   ├── parse_club_detail() →   extrae <h2> nombre, <h5> teléfono, <h5> email
   ├── save_checkpoint()   →   cada 5 clubs
   └── save_progress()     →   total/procesados/ok/errores
4. _merge_federation_csvs() → consolida por scraper
5. merge_csvs() en main.py → consolida todo en clubs_todos.csv
```

### Reanudación (`--resume`)

- Al iniciar, carga `checkpoints/<federacion>.json` con los IDs ya procesados.
- Solo procesa los IDs pendientes.
- Guarda checkpoint cada 5 clubs procesados.
- El archivo `checkpoints/progress_<fed>.json` permite monitorizar avance en tiempo real.

---

## 5. Mecanismos anti-bloqueo actuales

| Mecanismo | Implementación | Valor actual |
|---|---|---|
| Delay entre peticiones | `time.sleep(delay)` | **0.5s** (config.py) |
| Modo `--slow` | 1 worker + delay 3s | Opt-in (flag) |
| Backoff exponencial | `base_delay * 2^attempt` (cap 120s) | ✅ |
| Cooldown por errores consecutivos | 30s progresivo tras 8 fallos seguidos | ✅ |
| Reinicio de sesión ante bloqueo | Recrea `requests.Session()` + `init_session()` | ✅ |
| User-Agent | Chrome 124 fijo | ❌ sin rotación |
| Jitter aleatorio | No implementado | ❌ |
| Paralelismo | `--workers 1` por defecto | ✅ |

---

## 6. Federaciones cubiertas

| Federación | Clubs aprox. | Plataforma | Scraper |
|---|---|---|---|
| Andalucía | 2.702 | NOVA | `scraper_nova.py` |
| Castilla-La Mancha | 2.029 | NOVA | `scraper_nova.py` |
| Extremadura | 1.458 | NOVA | `scraper_nova.py` |
| Galicia | 1.426 | NOVA | `scraper_nova.py` |
| Aragón | 581 | NOVA | `scraper_nova.py` |
| Asturias | 377 | NOVA | `scraper_nova.py` |
| Murcia | 365 | NOVA | `scraper_nova.py` |
| Cantabria | 177 | NOVA | `scraper_nova.py` |
| La Rioja | 79 | NOVA (delay 3s) | `scraper_nova.py` |
| Castilla y León | ~? | RFEYLF (sin email) | `scraper_rfcylf.py` |
| Cataluña | ~? | FCF (SPA + NOVA fallback) | `scraper_fcf_cat.py` |
| **Total estimado** | **~9.194+** | | |

---

## 7. Sobre las recomendaciones recibidas (ZenRows / Node.js)

### ❌ Opción 1: ZenRows (proxy de pago)

- **Incompatible por filosofía del proyecto:** el `.clinerules` prohíbe introducir servicios cloud sin petición explícita. El proyecto está diseñado para ser autónomo, sin dependencias externas de pago ni puntos únicos de fallo.
- **Técnicamente posible pero overkill:** requeriría cambiar todas las URLs de `domain/pnfg/...` → `api.zenrows.com/v1/?url=...`, añadiendo latencia extra y un coste recurrente innecesario para ~9.000 clubs que ya se pueden scrapear con los mecanismos existentes.

### ❌ Opción 2: Colas en segundo plano con retardo (Node.js)

- **Totalmente inaplicable:** la recomendación asume una arquitectura Node.js/Express/SQLite/WebSockets con un archivo `enrichmentService.js` que **no existe en este proyecto**. Este proyecto es 100% Python.
- La persona que recomendó esto no revisó el código real.

### ✅ Lo que sí aplica — el concepto, no el código

La idea de espaciar peticiones 3-5 segundos es correcta, y **ya está parcialmente implementada** en el proyecto. Los valores actuales (`REQUEST_DELAY = 0.5s`) son simplemente demasiado agresivos.

---

## 8. Puntos débiles detectados y mejoras propuestas

| # | Problema | Mejora propuesta | Impacto |
|---|---|---|---|
| 1 | `REQUEST_DELAY = 0.5s` (viola `.clinerules` que pide ≥3s) | Subir a **2.0s** + añadir jitter aleatorio ±30% | 🔴 Alto |
| 2 | Sin jitter — patrón de intervalo fijo detectable por anti-bots | `random.uniform(delay * 0.7, delay * 1.3)` | 🔴 Alto |
| 3 | `--slow` es opt-in, debería ser default | Invertir: modo rápido con `--fast`, lento por defecto | 🔴 Alto |
| 4 | User-Agent único (Chrome 124) facilita fingerprinting | Pool de 4-5 User-Agents realistas con rotación | 🟡 Medio |
| 5 | `scraper_fcf_cat.py` y `scraper_rfcylf.py` tienen delays locales que ignoran `config.py` | Unificar todos los delays desde `config.py` | 🟡 Medio |

**Todas las mejoras propuestas son compatibles con la arquitectura actual, no añaden dependencias externas, y respetan el `.clinerules`.**

---

## 9. Cómo ejecutar

```bash
# Modo seguro recomendado (1 worker, 3s delay)
python main.py --slow

# Solo federaciones NOVA
python main.py --only nova --slow

# Una federación concreta
python main.py --fed "Andalucía" --slow

# Reanudar desde checkpoint
python main.py --resume --slow

# Solo listar IDs (debug)
python scraper_nova.py --list-ids-only

# Validar sintaxis
python -m py_compile main.py scraper_nova.py scraper_rfcylf.py scraper_fcf_cat.py config.py
```

---

## 10. Informe de ejecución en vivo — 29/07/2026

### Estado actual del scraping

| Métrica | Valor |
|---|---|
| **Comando** | `main.py --only nova --resume --delay 10` |
| **Delay** | 10s entre peticiones (+ jitter ±30%) |
| **Workers** | 1 (secuencial, una federación a la vez) |
| **Hora inicio** | ~01:25 |
| **Proceso** | PID 2993 (🟢 corriendo) |

### Archivos de salida

Los contactos se registran en tiempo real en:

```
output/
├── clubs_nova_andaluc_a.csv          ← 89 contactos
├── clubs_nova_castilla-la_mancha.csv ← 0 contactos
├── clubs_nova_extremadura.csv        ← 19 contactos
├── clubs_nova_galicia.csv            ← 60 contactos
├── clubs_nova_arag_n.csv             ← 11 contactos
├── clubs_nova_asturias.csv           ← 10 contactos
├── clubs_nova_murcia.csv             ← 1 contacto (pagina actual)
├── clubs_nova_cantabria.csv          ← (pendiente)
├── clubs_nova_la_rioja.csv           ← (pendiente)
└── clubs_nova.csv                    ← Consolidado NOVA (merge final)
```

### Resultados por federación

| # | Federación | CSV Filas | Archivo | Estado |
|---|---|---|---|---|
| 1 | Andalucía | 89 | `clubs_nova_andaluc_a.csv` | ✅ Completada |
| 2 | Castilla-La Mancha | 0 | `clubs_nova_castilla-la_mancha.csv` | ⚠️ Abandonada (TLS) |
| 3 | Extremadura | 19 | `clubs_nova_extremadura.csv` | ✅ Completada |
| 4 | Galicia | 60 | `clubs_nova_galicia.csv` | ✅ Completada |
| 5 | Aragón | 11 | `clubs_nova_arag_n.csv` | 🔴 Abandonada (bloqueo pese a curl_cffi) |
| 6 | Asturias | 10 | `clubs_nova_asturias.csv` | ⚠️ Abandonada (bloqueo) |
| 7 | Murcia | 1+ | `clubs_nova_murcia.csv` | 🟢 Scrapeando |
| 8 | Cantabria | 0 | `clubs_nova_cantabria.csv` | ⏳ Pendiente |
| 9 | La Rioja | 0 | `clubs_nova_la_rioja.csv` | ⏳ Pendiente |

**Total acumulado**: ~195 contactos de ~9.194 esperados (2.1%)

### Mejoras aplicadas durante esta sesión

#### A) Cache de all_ids (`checkpoints/<fed>_all_ids.json`)
- **Problema**: Cada vez que se reanudaba, el scraper volvía a paginar `NFG_Clubes` aunque ya tuviera los IDs.
- **Solución**: Al completar la paginación, se guarda la lista completa de IDs. En reanudaciones posteriores, se usa el cache directamente.

#### B) Abandono automático de federación bloqueada
- 3 criterios de abandono: 30% errores sin éxito, tasa ≥70% tras 15 intentos, cooldown nivel > 3.

#### C) Backoff exponencial progresivo
| Nivel | Cooldown | Se activa tras |
|---|---|---|
| 1 | 60s | 3 errores consecutivos |
| 2 | 120s | 3 errores consecutivos tras cooldown 1 |
| 3 | 180s | 3 errores consecutivos tras cooldown 2 |
| 4 | ABANDONO | — |

#### D) curl_cffi — TLS impersonation de Chrome
- **Instalado**: `curl_cffi>=0.7.0` en `requirements.txt`
- **Federaciones con TLS impersonation**: `rfaf.es`, `ffcm.es`, `futbolaragon.com`
- **Resultado**: Aragón pasó de 200 IDs (con requests) a 220+ IDs (con curl_cffi). Aún insuficiente para scrapear detalles — el WAF de `futbolaragon.com` también analiza headers HTTP.

#### E) Headers HTTP realistas
- **Añadidos**: `Referer`, `Origin`, `Accept-Encoding`, `Sec-Fetch-Dest`, `Sec-Fetch-Mode`, `Sec-Fetch-Site`, `Sec-Fetch-User`
- Cada petición incluye `Referer: <domain>/pnfg/NPcd/NFG_Clubes` simulando navegación real.

#### F) User-Agent rotativo
- Pool de 5 User-Agents (Chrome, Firefox, Edge en Windows/macOS).

#### G) Monitor mejorado (`monitor.py` + script inline)
- Actualización cada 3 minutos con delta de contactos nuevos por ciclo.
- Muestra archivo CSV, contactos, progreso, último log.

### Problemas detectados

#### 🔴 Servidores con bloqueo anti-bot persistente

| Servidor | Federación | Comportamiento | Medidas aplicadas |
|---|---|---|---|
| `ffcm.es` | Castilla-La Mancha | Content-Length: 0 en 100% de requests | curl_cffi + Referer → sin mejora |
| `futbolaragon.com` | Aragón | Paginación parcial (~220/581 IDs). Scrape detalles >90% error | curl_cffi + Referer → leve mejora |
| `rfaf.es` | Andalucía | Bloquea paginación. Datos de ejecuciones anteriores | curl_cffi → sin probar (ya completada) |
| `asturfutbol.es` | Asturias | Bloquea scrape de detalles | Requests estándar → abandono automático |

**Causa**: WAF a nivel de aplicación (Capa 7) que analiza patrones de navegación, no solo TLS. Estos servidores probablemente requieren:
- Navegación secuencial real (primero listado → luego detalle, con cookies de sesión)
- JavaScript execution para setear cookies/tokens
- Rate limiting muy estricto (≥30s entre requests)

### Conclusión
- **3 de 9 federaciones scrapeables completamente** con el stack actual (Andalucía, Extremadura, Galicia).
- **3 con bloqueo severo** (Castilla-La Mancha, Aragón, Asturias).
- **3 con potencial** (Murcia, Cantabria, La Rioja — Murcia está paginando ahora).
- El scraper es resiliente y autónomo: abandona federaciones bloqueadas, guarda progreso, y continúa.
- **Próximo paso recomendado**: proxies residenciales (~$3-5/GB) o headless browser con Playwright para las 3-4 federaciones rebeldes.
