# INFORME — ESTADO DE LEADS DEL CRM Y COBERTURA DE SCRAPING

> Fecha: 2026-08-31 · Fuentes: BD producción `clubes_crm` (1818) + `output/clubs_nova_*.csv` + `config.py EXPECTED_CLUBS`

---

## 1. RESUMEN EJECUTIVO

- **Leads cargados en el CRM:** 1.818 (99,8 % con email · 1.783 con teléfono · 1.731 con WhatsApp).
- **Estado comercial:** 1.414 "01 Sin Contactar" · 395 "02 Contactado" · 7 "03 En Conversación" · 2 Lista Negra.
- **Cobertura de scraping estimada: ~20 %** (2.061 clubes scrapeados de ~10.305 esperados en las federaciones NOVA configuradas).
- **Faltan por scrapear: ~8.244 clubes** en las federaciones ya configuradas, **más federaciones enteras sin scraper** (Comunidad Valenciana, País Vasco, Baleares, Canarias, Navarra, Ceuta/Melilla).
- **Mayor oportunidad inmediata:** **Castilla-La Mancha (0/2.029)**, **Extremadura (19/1.458)** y **Galicia (60/1.426)**.

---

## 2. LEADS EN EL CRM (producción · 1.818)

### 2.1 Por federación

| Clubes | Federación |
|-------:|:-----------|
| 833 | Real Federación Andaluza de Fútbol |
| 404 | Real Federación de Fútbol de Madrid |
| 358 | Real Federación de Castilla y León de Fútbol |
| 59 | Federación Riojana de Fútbol |
| 57 | Real Federación Galega de Fútbol |
| 32 | Real Federación de Fútbol del Principado de Asturias |
| 30 | Federación de Fútbol de la Región de Murcia |
| 19 | Federación Aragonesa de Fútbol |
| 10 | Federación Cántabra de Fútbol |
| 8 | Federación Extremeña de Fútbol |
| 5 | (federación vacía) |
| 1 | Federació Catalana de Futbol |
| 1 | Federació de Futbol de la Comunitat Valenciana |
| 1 | Federación Vasca de Fútbol |

### 2.2 Por estado comercial

| Estado | Clubes |
|:---|---:|
| 01 Sin Contactar | 1.414 |
| 02 Contactado | 395 |
| 03 En Conversación | 7 |
| Lista Negra | 2 |

**Calidad de datos:** 1.818/1.818 con email · 1.783 con teléfono · 1.731 con WhatsApp · 69 marcados duplicados.

---

## 3. COBERTURA DE SCRAPING POR FEDERACIÓN (lo que tenemos vs. lo que falta)

| Federación | Esperados | Scrapeados | % | Con email | CRM | Estado |
|:---|---:|---:|---:|---:|---:|:--|
| Castilla-La Mancha | 2.029 | **0** | 0,0 % | 0 | 0 | 🔴 SIN SCRAPEAR |
| Extremadura | 1.458 | 19 | 1,3 % | 8 | 8 | 🔴 Muy parcial |
| Galicia | 1.426 | 60 | 4,2 % | 59 | 57 | 🔴 Muy parcial |
| Andalucía | 2.702 | 1.000 | 37,0 % | 902 | 833 | 🟠 Parcial (~1.700 pend.) |
| Aragón | 581 | 21 | 3,6 % | 19 | 19 | 🔴 Muy parcial |
| Asturias | 377 | 32 | 8,5 % | 32 | 32 | 🔴 Muy parcial |
| Murcia | 365 | 30 | 8,2 % | 30 | 30 | 🔴 Muy parcial |
| Cantabria | 177 | 11 | 6,2 % | 11 | 10 | 🔴 Muy parcial |
| Madrid | 730 | 440 | 60,3 % | 424 | 404 | 🟠 Parcial (~290 pend.) |
| La Rioja | 79 | 73 | 92,4 % | 73 | 59 | 🟢 Casi completo |
| Castilla y León | 381 | 375 | 98,4 % | 375 | 358 | 🟢 Casi completo |
| **TOTAL NOVA** | **10.305** | **2.061** | **20,0 %** | ~1.933 | 1.810 | **~8.244 pendientes** |

### 3.1 Federaciones SIN scraper (no configuradas)

- **Comunidad Valenciana** (FFCV) · **País Vasco** (FVF/EFF) · **Islas Baleares** (FFIB) · **Islas Canarias** (FIFLP + FIFCT) · **Navarra** (FNF) · **Ceuta** · **Melilla**.
- En el CRM solo hay 1 lead de Valencia y 1 de País Vasco (muestras).

### 3.2 Pendientes sin datos (para reintentar)

- `output/clubs_pendientes.csv` → **335 clubes** con motivo `sin_datos`.
- `output/pendientes_nova_madrid.csv` → **599 clubes** de Madrid sin datos.

---

## 4. QUÉ FALTA — PRIORIDAD RECOMENDADA

| # | Federación | Falta | Scraper | Esfuerzo |
|:--|:---|:---|:---|:---|
| 1 | **Castilla-La Mancha** | 2.029 | `scraper_nova.py --fed "Castilla-La Mancha"` | Alto (0 hecho) |
| 2 | **Extremadura** | 1.439 | `scraper_nova.py --fed "Extremadura"` | Alto |
| 3 | **Galicia** | 1.366 | `scraper_nova.py --fed "Galicia"` | Alto |
| 4 | **Andalucía** | ~1.700 | `scraper_nova.py --fed "Andalucía" --resume` | Alto |
| 5 | **Aragón** | 560 | `scraper_nova.py --fed "Aragón" --resume` | Medio |
| 6 | **Asturias** | 345 | `scraper_nova.py --fed "Asturias" --resume` | Medio |
| 7 | **Murcia** | 335 | `scraper_nova.py --fed "Murcia" --resume` | Medio |
| 8 | **Cantabria** | 166 | `scraper_nova.py --fed "Cantabria" --resume` | Bajo |
| 9 | **Madrid** | ~290 | `scraper_nova.py --fed "Madrid" --resume` | Medio |
| 10 | **Castilla-La Mancha (pendientes)** | 599 sin datos | reintento | Bajo |

> **Nota:** los "esperados" son aproximaciones (`config.py EXPECTED_CLUBS`) para monitorizar progreso. `--resume` reanuda desde el checkpoint de cada federación sin perder lo ya capturado.

---

## 5. PRÓXIMO PASO OPERATIVO

1. Retomar el scraper con **modo seguro** (`--slow` / delay ≥ 2-3 s, 1 worker) para evitar bloqueos:
   `python scraper_nova.py --fed "Castilla-La Mancha"` (primero, federación a 0 %).
2. Los CSV generados se cargan al CRM (`clubes_crm`) manteniendo las columnas: `federacion, nombre, telefono, email`.
3. Evitar superponer el proceso de scraping con la campaña de email en curso (los recursos SMTP no se ven afectados, pero sí la atención de seguimiento).
