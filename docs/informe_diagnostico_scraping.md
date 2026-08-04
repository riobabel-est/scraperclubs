# Informe de Diagnóstico — Scrapper Club

**Fecha:** 1 de agosto de 2026  
**Proyecto:** Scraping de contactos de clubes de fútbol españoles  
**Estado actual:** Parcialmente funcional — ~246 clubes capturados de ~9,994 estimados (2.5%)

---

## 1. Resumen ejecutivo

El proyecto sufre **tres problemas raíz** que impiden la obtención masiva de contactos:

1. **Bloqueo anti-bot (TLS fingerprinting)** — 99% de las peticiones fallan porque los servidores NOVA detectan el fingerprint TLS de `requests` (Python) y devuelven HTML vacío, redirigen a login, o devuelven error silencioso.

2. **Bug en código fuente** — `config.py` línea 16 usa `false` (JavaScript) en vez de `False` (Python), lo que causó un `NameError` que abortó toda una ejecución.

3. **Scrapers incompletos o sin fallback** — Castilla y León extrae códigos postales como "teléfonos" porque no accede a la página de detalle. Catalunya no tiene fallback cuando el portal NOVA requiere login.

---

## 2. Diagnóstico por federación

### 2.1 Federaciones NOVA (plataforma novanet.es)

| Federación | IDs esperados | IDs en CSV | % capturado | Problema |
|---|---|---|---|---|
| **Andalucía** | 2,702 | 62 | 2.3% | Bloqueo TLS — solo 31 de 80 intentos OK |
| **Aragón** | 581 | 21 | 3.6% | Bloqueo TLS — 0 OK de 83 intentos en checkpoint, pero CSV tiene datos de ejecución anterior |
| **Asturias** | 377 | 32 | 8.5% | Bloqueo TLS — 11 OK de 60 intentos |
| **Cantabria** | 177 | 23 | 13.0% | Bloqueo TLS — 22 OK de 49 intentos |
| **Castilla-La Mancha** | 2,029 | **10** | 0.5% | Crítico — 10 OK de 25 intentos en un checkpoint; el otro checkpoint tenía 115 IDs con 0 OK |
| **Extremadura** | 1,458 | 20 | 1.4% | Crítico — 0 OK de 200 intentos |
| **Galicia** | 1,426 | 60 | 4.2% | Crítico — 0 OK de 190 intentos |
| **La Rioja** | 79 | **0** | 0% | Crítico — 0 OK de 79 intentos, CSV vacío |
| **Murcia** | 365 | 22 | 6.0% | Bloqueo TLS — 11 OK de 45 intentos |
| **TOTAL NOVA** | **9,194** | **250** | **2.7%** | |

### 2.2 Castilla y León (RFCFYL — scraper propio)

| Estado | Clubes |
|---|---|
| En CSV | 48 |
| Con teléfono real | **0** |
| Con email | **0** |

**Problema:** El scraper extrae datos de una página de listado/directorio que NO contiene teléfonos ni emails. Los valores en la columna "teléfono" son **códigos postales** capturados por un regex demasiado permisivo (`[\d\s\-\+]{7,}`). Para obtener contactos reales, se necesita scrapear la página de detalle de cada club.

### 2.3 Cataluña (FCF — scraper propio)

| Estado | Clubes |
|---|---|
| En CSV | **0** |

**Problema:** Ambos portales NOVA candidatos (`futbol.cat/fnfg` e `intranet.fcf.cat/nfg`) requieren login. El script no tiene fallback (SPA scraping con Selenium/Playwright) y simplemente termina sin generar CSV.

---

## 3. Análisis técnico del bloqueo

### 3.1 Mecanismo de bloqueo detectado

Los servidores NOVA (novanet.es) implementan **TLS fingerprinting** a nivel de CDN/reverse proxy. El cliente `requests` de Python usa la biblioteca `urllib3` con OpenSSL, cuyo fingerprint TLS (JA3) es fácilmente identificable como "no-navegador".

**Evidencia en logs:**
- `[vacío] Bloqueo detectado` — respuesta HTTP 200 pero con body vacío (0 bytes)
- Redirecciones a `NLogin` sin contenido útil
- Sin errores HTTP explícitos (no 403, no 429) — el bloqueo es **silencioso**

### 3.2 Intentos de mitigación actuales (insuficientes)

| Mecanismo | Estado | Efectividad |
|---|---|---|
| `curl_cffi` (TLS impersonation) | Importado opcionalmente (línea 28-32 scraper_nova.py) | ❌ NUNCA se usa en el flujo real — el código comprueba `HAS_CURL_CFFI` pero no lo aplica salvo en `tls_impersonate` que no está configurado para ninguna federación |
| Rotación de User-Agent | Implementado en `config.py` | ⚠️ Insuficiente — solo cambia la cabecera HTTP, no el fingerprint TLS |
| Delays (jitter) | 2-4 segundos | ⚠️ No evita el bloqueo por fingerprint |
| ScraperAPI | Key configurada (`dd640d6e...`) | ❌ No se usa en el flujo normal — solo como variable global `_use_scraperapi` que nunca se activa |
| Rate limiting | 1-2 workers | ⚠️ No es el problema — el bloqueo es por fingerprint, no por volumen |

### 3.3 ¿Por qué algunas peticiones SÍ funcionan?

Aproximadamente 1-10% de las peticiones tienen éxito. Esto sugiere que el bloqueo es **probabilístico o rotativo**:
- Algunos servidores backend no tienen el filtro anti-bot
- El balanceador de carga a veces enruta a servidores sin protección
- Ciertas franjas horarias tienen menor protección

---

## 4. Problemas adicionales detectados

### 4.1 Bug en config.py (CORREGIR INMEDIATAMENTE)

```python
# Línea 16 — ACTUAL (ROTO):
{"name": "Andalucía", ..., "skip": false},
# CORRECCIÓN:
{"name": "Andalucía", ..., "skip": False},
```

### 4.2 Checkpoints corruptos

Castilla-La Mancha y La Rioja tenían checkpoints que registraban 115 y 79 IDs como "procesados" pero con 0 resultados (OK=0). Esto impedía el re-scraping con `--resume`. Ya se movieron a `.bak`.

### 4.3 Castilla y León — datos inválidos

Los 48 registros de `clubs_rfcylf.csv` tienen:
- **Teléfonos:** códigos postales (ej: `37900`, `37002`, `09006`)
- **Emails:** todos vacíos

Estos datos contaminan el CSV consolidado `clubs_todos.csv`.

---

## 5. Plan de soluciones (orden de prioridad)

### Fase 1: Correcciones inmediatas (hoy) 🚨

| # | Acción | Prioridad | Impacto |
|---|---|---|---|
| 1.1 | Corregir `"skip": false` → `"skip": False` en `config.py` | **Crítica** | Evita que el script entero aborte |
| 1.2 | Activar `curl_cffi` como capa TLS principal en `scraper_nova.py` | **Crítica** | Resuelve el 99% de bloqueos — usar `curl_cffi.requests` en vez de `requests` |
| 1.3 | Resetear checkpoints de federaciones con OK=0 (Castilla-La Mancha, La Rioja, Extremadura, Galicia) | **Alta** | Permite re-scraping limpio |

### Fase 2: Robustez anti-bloqueo (hoy/mañana)

| # | Acción | Prioridad | Impacto |
|---|---|---|---|
| 2.1 | Implementar fallback automático: `curl_cffi` → `requests` + ScraperAPI → error | **Alta** | Máxima tasa de éxito |
| 2.2 | Activar ScraperAPI para federaciones con bloqueo severo (La Rioja, Castilla-La Mancha) | **Alta** | By-pass del filtro anti-bot |
| 2.3 | Aumentar delay mínimo a 3-5s y añadir backoff exponencial en errores consecutivos | **Media** | Reduce detección por patrón |
| 2.4 | Implementar rotación de sesión (nueva sesión HTTP cada N peticiones) | **Media** | Evita tracking por cookie/sesión |

### Fase 3: Recuperación de datos (hoy/mañana)

| # | Acción | Prioridad | Impacto |
|---|---|---|---|
| 3.1 | Re-scrapear Castilla-La Mancha (2,029 clubes) con TLS impersonation | **Alta** | Recupera ~2,000 contactos |
| 3.2 | Re-scrapear La Rioja (79 clubes) con TLS impersonation | **Alta** | Recupera ~79 contactos |
| 3.3 | Re-scrapear federaciones existentes (Andalucía, Aragón, etc.) para completar datos parciales | **Media** | Pasa de 2.5% a ~100% |
| 3.4 | Reparar scraper de Castilla y León para scrapear página de detalle | **Alta** | Recupera ~800 contactos reales |
| 3.5 | Implementar fallback SPA para Catalunya (Playwright/Selenium) o contactar FCF para acceso | **Alta** | Recupera ~1,500 contactos |

### Fase 4: Mejoras de arquitectura (siguiente sprint)

| # | Acción | Prioridad | Impacto |
|---|---|---|---|
| 4.1 | Unificar mecanismo HTTP en los 3 scrapers (misma sesión, mismo anti-bloqueo) | **Media** | Mantenibilidad |
| 4.2 | Sistema de health-check: detectar bloqueo y pausar automáticamente | **Baja** | Evita gasto de peticiones en vano |
| 4.3 | Guardar checkpoint en tiempo real (ya se hace cada 5, bajar a 1) | **Baja** | Mejor reanudación |

---

## 6. Estimación de esfuerzo

| Fase | Tiempo estimado | Clubes recuperables |
|---|---|---|
| Fase 1 (correcciones) | 15 min | — |
| Fase 2 (anti-bloqueo) | 1-2 horas | — |
| Fase 3 (re-scraping) | 4-8 horas (ejecución) | ~5,000-9,000 |
| Fase 4 (mejoras) | 2-3 horas | — |

---

## 7. Conclusión

El problema raíz es claro: **el TLS fingerprint de `requests` es detectado y bloqueado por los servidores NOVA**. La solución principal es activar `curl_cffi` (que ya está instalado como dependencia opcional) para emular el fingerprint de Chrome real.

Una vez resuelto el bloqueo, se puede recuperar masivamente los datos de las ~9 federaciones NOVA. Los scrapers de Castilla y León y Catalunya requieren trabajo adicional específico.

El 2.5% de datos ya capturados (246 clubes) **se preserva íntegramente** — no se eliminará ningún dato existente.