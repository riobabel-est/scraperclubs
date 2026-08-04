# Informe de bloqueo — 1/8/2026 16:22

## Estado actual

| Método | Hora | Federación | Resultado |
|---|---|---|---|
| `requests` directo | 15:59 | rfcf.es | ✅ 25,545 bytes (funcionó) |
| `curl_cffi` directo | 16:07 | rfcf.es | ✅ 25,545 bytes (funcionó) |
| **Playwright networkidle** | 16:08 | rfcf.es | ✅ 92,173 bytes (funcionó) |
| Playwright domcontentloaded | 16:09-16:14 | rfcf.es | ✅ Extrajo 10 contactos reales |
| **Playwright domcontentloaded** | 16:21 | rfcf.es | ❌ 39 bytes (BLOQUEADO) |
| `curl_cffi` directo | 16:07 | rfaf.es (Andalucía) | ❌ 0 bytes (BLOQUEADO) |

## Diagnóstico

**No es un bug de código. Es rate-limiting por IP.**

El patrón es claro:
1. Después de ~1 hora sin requests → el rate-limit se resetea
2. Las primeras ~30-50 peticiones funcionan perfectamente
3. Luego el servidor bloquea TODAS las peticiones (devuelve HTML vacío)
4. Hay que esperar otra hora para que se resetee

**Evidencia:** Los 10 contactos de Cantabria se extrajeron en los primeros ~30 segundos de Playwright. Después, 80+ IDs seguidos devolvieron HTML vacío. Ahora incluso IDs que antes funcionaban (1001) devuelven 39 bytes.

## ¿Por qué NO es un bloqueo de TLS/User-Agent?

Playwright usa un navegador Chromium real — indistinguible de un humano. Si fuera bloqueo por fingerprint, Playwright nunca habría funcionado. Pero funcionó perfectamente durante 30 segundos y luego dejó de funcionar. Esto es **rate-limiting clásico basado en volumen de requests por IP**.

## Soluciones viables

### Opción 1 — Delay extremo (gratis, lento)
Esperar 1 hora. Ejecutar con `--delay 30` (30 segundos entre peticiones). 
Pros: Gratis. Contras: Extremadamente lento (~17 horas para 2,000 IDs).

### Opción 2 — Proxy residencial rotativo (pago, rápido)
Servicios como BrightData, Oxylabs, Webshare rotan IPs automáticamente.
Pros: Sin límites. Contras: Cuesta dinero (~$10-50/mes).

### Opción 3 — Tor con pausas (gratis)
Abrir Tor Browser manualmente, usar SOCKS5 con delay 10-15s.
Pros: Gratis. Contras: Muy lento, Tor timeoutea a veces.

### Opción 4 — Dividir entre días (gratis)
Ejecutar 1 federación por noche (cuando el rate-limit se resetea).
Pros: Gratis. Contras: Tardará ~9 noches.

## Datos recuperados hasta ahora

| Federación | Contactos | Fuente |
|---|---|---|
| Galicia | 60 | Ejecución anterior (curl_cffi) |
| Castilla y León | 49 | Ejecución anterior (datos parciales) |
| Asturias | 32 | Ejecución anterior |
| La Rioja | 32 | Ejecución anterior |
| Murcia | 30 | Playwright + anterior |
| Cantabria | **10 nuevos** + 22 anteriores = 32 | Playwright (hoy) |
| Aragón | 21 | Ejecución anterior |
| Extremadura | 19 | Ejecución anterior |
| **TOTAL** | **275** | |

## Recomendación

Para escalar a 9,000+ clubes, la única opción viable es un **proxy residencial rotativo**. Con delay de 3s y rotación de IP, se pueden scrapear todas las federaciones en ~8 horas sin bloqueos.