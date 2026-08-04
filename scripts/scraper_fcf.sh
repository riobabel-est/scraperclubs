"""
Scraper para la Federació Catalana de Futbol (fcf.cat).

FCF.cat usa una SPA propia (no plataforma NOVA), pero dispone de un portal
NOVA a través de intranet.fcf.cat/nfg y www.futbol.cat/fnfg.

Se intenta primero el portal NOVA; si no funciona, se indica que se necesita
el modo browser (playwright).

Uso:
  python scraper_fcf_cat.py          # modo seguro por defecto (delay 2s)
  python scraper_fcf_cat.py --fast   # modo rápido (bajo tu responsabilidad)
"""

import argparse
import csv
import json
import os
import re
import sys
import time
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# Importar configuración centralizada
sys.path.insert(0, str(Path(__file__).parent))
from config import (
    REQUEST_DELAY, MAX_RETRIES, TIMEOUT,
    get_random_user_agent, jitter_sleep,
)

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
OUTPUT_FILE = OUTPUT_DIR / "clubs_fcf_cat.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

PAGE_SIZE = 20

BASE_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ca-ES,ca;q=0.9,es;q=0.8",
}

# Posibles portales NOVA de la FCF
FCF_NOVA_CANDIDATES = [
    {"domain": "https://www.futbol.cat/fnfg", "cod_primaria": "1000118"},
    {"domain": "https://intranet.fcf.cat/nfg",  "cod_primaria": "1000118"},
]


def _make_headers():
    """Construye headers con User-Agent aleatorio del pool."""
    return {**BASE_HEADERS, "User-Agent": get_random_user_agent()}


def get_with_retry(session, url, params=None, retries=MAX_RETRIES):
    for attempt in range(retries):
        try:
            resp = session.get(url, params=params, headers=_make_headers(), timeout=TIMEOUT,
                               allow_redirects=True)
            resp.raise_for_status()
            return resp
        except requests.RequestException as e:
            if attempt < retries - 1:
                time.sleep(2 ** attempt)
            else:
                return None


def try_nova_portal(domain, cod_primaria):
    """Intenta acceder al portal NOVA de FCF. Devuelve True si es accesible."""
    session = requests.Session()
    url = f"{domain}/pnfg/NPcd/NFG_Clubes"
    params = {"cod_primaria": cod_primaria, "NPcd_Page": 1, "Buscar": "1", "NPcd_PageLines": 1}
    print(f"  Probando NOVA en {domain} ...")
    resp = get_with_retry(session, url, params=params)
    if not resp:
        print("    Sin respuesta")
        return False
    if "NLogin" in resp.url or "NLogin" in resp.text:
        print("    Requiere login")
        return False
    soup = BeautifulSoup(resp.text, "lxml")
    total_match = re.search(r"Total\s+(\d+)\s+Registros", soup.get_text(), re.IGNORECASE)
    if total_match:
        print(f"    ¡Accesible! Total: {total_match.group(1)} registros")
        return True
    print("    Respuesta sin datos reconocibles")
    return False


def scrape_via_nova(domain, cod_primaria, writer, effective_delay=REQUEST_DELAY):
    """Usa el flujo estándar NOVA si el portal es accesible."""
    # Importar funciones del scraper NOVA (ya usan _make_headers y jitter_sleep)
    from scraper_nova import get_all_club_ids, scrape_club

    session = requests.Session()
    all_ids = get_all_club_ids(session, domain, cod_primaria, "Cataluña", request_delay=effective_delay)

    ok = 0
    for i, club_id in enumerate(all_ids, 1):
        nombre, telefono, email = scrape_club(session, domain, cod_primaria, club_id)
        if nombre:
            writer.writerow({"federacion": "Cataluña", "nombre": nombre,
                             "telefono": telefono, "email": email})
            ok += 1
        if i % 50 == 0:
            print(f"  Progreso: {i}/{len(all_ids)} — OK:{ok}")
        jitter_sleep(effective_delay)

    print(f"  Cataluña (NOVA) — OK:{ok}")
    return ok


def main():
    parser = argparse.ArgumentParser(description="Scraper FCF.cat — Cataluña")
    parser.add_argument("--fast", action="store_true", help="Modo rápido (delay sin restricciones)")
    args = parser.parse_args()

    # Por defecto modo lento seguro
    effective_delay = args.delay if hasattr(args, 'delay') and args.delay is not None else REQUEST_DELAY

    OUTPUT_DIR.mkdir(exist_ok=True)

    print("=== Scraper FCF.cat (Cataluña) ===")
    print(f"Delay: {effective_delay}s | Modo: {'rápido' if args.fast else 'seguro (por defecto)'}")
    print()

    # Intentar portales NOVA
    for candidate in FCF_NOVA_CANDIDATES:
        if try_nova_portal(candidate["domain"], candidate["cod_primaria"]):
            print(f"\n  Usando portal NOVA: {candidate['domain']}")
            with open(OUTPUT_FILE, "w", newline="", encoding="utf-8-sig") as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                writer.writeheader()
                count = scrape_via_nova(candidate["domain"], candidate["cod_primaria"], writer,
                                        effective_delay=effective_delay)
            print(f"\nTotal clubes guardados: {count}")
            print(f"Archivo: {OUTPUT_FILE}")
            return

    print("\n[INFO] Los portales NOVA de FCF no son accesibles sin login.")
    print("       FCF.cat usa una SPA con API propia.")
    print()
    print("Opciones para obtener datos de Cataluña:")
    print("  1. Usar el script scraper_fcf_browser.py (requiere playwright)")
    print("  2. Contactar directamente a la FCF para exportación de datos")
    print("  3. Solicitar acceso a la intranet NOVA de FCF")


if __name__ == "__main__":
    main()