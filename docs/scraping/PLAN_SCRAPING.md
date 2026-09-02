# PLAN DE SCRAPING — pendientes y comandos

> Módulo: scraping de clubes (Python, raíz del repo). Estado detallado: `ESTADO_LISTADOS_CLUBES.md`.

## Estado resumido (2026-09-02)
- **Hecho:** ~2.909 clubes en output/ (NOVA parcial + CV isquad). Consolidados regenerados al momento (2.172 NOVA).
- **Falta:** ≈ **8.000 clubes NOVA** + Madrid + federaciones sin acceso.

## Pendiente por volumen
| Federación | Pendiente | Comando |
|---|---:|---|
| Castilla-La Mancha | ~1.880 | `python scraper_nova.py --fed "Castilla-La Mancha" --resume --directo --delay 5` ⚠️ anti-bot por IP |
| Andalucía | ~1.700 | `--fed "Andalucía" --resume --delay 3` |
| Extremadura / Galicia | ~2.800 | `--fed … --resume --delay 3` |
| Aragón / Asturias / Murcia / Cantabria | ~1.400 | `--resume` |
| Tenerife / Ceuta | primera vez | verificadas NOVA, acceso directo OK |
| Madrid | ~290 + 599 | `python scraper_madrid.py --resume` |
| Navarra / Las Palmas | validar | `skip=True` en `config.py` |

## Reglas de ejecución
- `PYTHONUTF8=1` (evita error de emojis en consola Windows).
- Delay ≥ 3 s (≥ 5 s en dominios con anti-bot como `ffcm.es`), 1 worker.
- Siempre `--resume` (checkpoints por federación; nunca borrar `output/`/`checkpoints/`).
- `--directo` evita gastar créditos ScraperAPI (la key vive en `.env`).
- Tras cerrar federaciones: regenerar consolidados con `python main.py --merge-only`.

## Nota anti-bot (2026-09-02)
`ffcm.es` bloquea la IP tras ~12 peticiones en ráfaga (HTTP 200 vacío). Requiere enfriamiento 30-60 min y tandas cortas, u otra IP/VPN. Otros dominios (rfaf, ftf, rffce…) respondieron bien por acceso directo.
