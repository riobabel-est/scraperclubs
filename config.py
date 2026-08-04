"""
Configuración de las federaciones a scrapear.
"""

import random

# Federaciones en plataforma NOVA (NFG_Clubes + NFG_VerClub)
# "tls_impersonate": True → federación que bloquea el TLS fingerprint de requests
NOVA_FEDERATIONS = [
    {"name": "Andalucía",         "domain": "https://www.rfaf.es",                  "cod_primaria": "1000118", "skip": False, "use_scraperapi": True},
    {"name": "Castilla-La Mancha","domain": "https://www.ffcm.es",                  "cod_primaria": "1000118", "skip": False, "use_scraperapi": True},
    {"name": "Extremadura",       "domain": "https://www.fexfutbol.com",             "cod_primaria": "1000118", "use_scraperapi": True},
    {"name": "Galicia",           "domain": "http://www.ffgalicia.novanet.es",       "cod_primaria": "1000118", "use_scraperapi": True},
    {"name": "Aragón",            "domain": "https://www.futbolaragon.com",          "cod_primaria": "1000118", "skip": False, "use_scraperapi": True},
    {"name": "Asturias",          "domain": "https://www.asturfutbol.es",            "cod_primaria": "1000118", "skip": False, "use_scraperapi": True},
    {"name": "Murcia",            "domain": "https://www.ffrm.es",                   "cod_primaria": "3001859", "use_scraperapi": True},
    {"name": "Cantabria",         "domain": "https://www.rfcf.es",                  "cod_primaria": "1000118", "use_scraperapi": True},
    # frfutbol.com bloquea temporalmente tras muchas peticiones; usar delay mayor
    {"name": "La Rioja",          "domain": "https://www.frfutbol.com",             "cod_primaria": "1000118", "delay": 3.0, "use_scraperapi": True},
    {"name": "Castilla y León",   "domain": "https://www.rfcylf.es",               "cod_primaria": "1000118", "use_scraperapi": True},
]

# Clubes totales aproximados por federación (para monitorizar progreso)
EXPECTED_CLUBS = {
    "Andalucía":          2702,
    "Castilla-La Mancha": 2029,
    "Extremadura":        1458,
    "Galicia":            1426,
    "Aragón":              581,
    "Asturias":            377,
    "Murcia":              365,
    "Cantabria":           177,
    "La Rioja":             79,
    "Madrid":              730,
    "Castilla y León":     381,
}

# ScraperAPI — proxy anti-bloqueo (plan gratuito: 5000 requests/mes)
SCRAPERAPI_KEY = "b58878bcc44fd06ede6d8577c6e42b8f"

# Configuración del scraper
PAGE_SIZE = 10         # registros por página (reducido para ahorrar créditos ScraperAPI)
REQUEST_DELAY = 2.0    # segundos entre peticiones (subido de 0.5 → 2.0 para evitar bloqueo)
MAX_RETRIES = 3        # reintentos en caso de error
TIMEOUT = 90           # segundos de timeout por petición (ScraperAPI render=true necesita ~30s)

# Pool de User-Agents realistas para rotación (evita fingerprinting)
USER_AGENTS = [
    # Chrome 131 — Windows 11
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    # Firefox 133 — Windows 11
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0",
    # Edge 131 — Windows 11
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0",
    # Chrome 131 — macOS Sequoia
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    # Firefox 133 — macOS
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:133.0) Gecko/20100101 Firefox/133.0",
]


def get_random_user_agent():
    """Devuelve un User-Agent aleatorio del pool para rotación."""
    return random.choice(USER_AGENTS)


def jitter_sleep(base_delay):
    """
    Duerme entre base_delay*0.7 y base_delay*1.3 segundos.
    Introduce variación aleatoria para romper patrones detectables por anti-bots.
    """
    import time
    time.sleep(random.uniform(base_delay * 0.7, base_delay * 1.3))