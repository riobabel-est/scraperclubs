"""Reintenta solo los clubes pendientes de Madrid (ScraperAPI).
Lee pendientes_nova_madrid.csv y reintenta cada club_id con 5 intentos.

Uso:
  python retry_madrid_pendientes.py
  python retry_madrid_pendientes.py --delay 4
"""

import argparse
import csv
import json
import re
import sys
import time
from pathlib import Path
from urllib.parse import quote

import requests

sys.path.insert(0, str(Path(__file__).parent))
from config import SCRAPERAPI_KEY
from pendientes import PendientesWriter, MOTIVO_SIN_DATOS, merge_pending_csvs

OUTPUT_DIR = Path(__file__).parent / "output"
MAIN_CSV = OUTPUT_DIR / "clubs_nova_madrid.csv"
PENDING_FILE = OUTPUT_DIR / "pendientes_nova_madrid.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]
FEDERATION_NAME = "Madrid"

DOMAIN = "https://www.rffm.es"
TIMEOUT = 60
DEFAULT_RETRIES = 5
DEFAULT_DELAY = 4

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "es-ES,es;q=0.9",
}


def _extract_next_data(html):
    m = re.search(r'__NEXT_DATA__[^>]*?>(.*?)</script>', html, re.DOTALL)
    if m:
        return json.loads(m.group(1))
    return None


def scrape_club_detail(codigo_club, retries=5):
    """Obtiene detalle del club via ScraperAPI (JS rendering), con reintentos.
    Retorna (nombre, telefono, email, web)."""
    url = f"{DOMAIN}/fichaclub/{codigo_club}"
    api_url = f"http://api.scraperapi.com?api_key={SCRAPERAPI_KEY}&url={quote(url, safe='')}&render=true"

    for attempt in range(retries):
        try:
            r = requests.get(api_url, headers=HEADERS, timeout=TIMEOUT)
            r.raise_for_status()
            data = _extract_next_data(r.text)
            if data:
                break
        except Exception as e:
            if attempt < retries - 1:
                wait = 2 + attempt * 2  # 2, 4, 6, 8s backoff
                print(f"      Reintento {attempt+1}/{retries}: {e} — esperando {wait}s")
                time.sleep(wait)
            else:
                print(f"      ❌ Fallo definitivo tras {retries} intentos: {e}")
                return None, None, None, None

    if not data:
        return None, None, None, None

    club = data.get("props", {}).get("pageProps", {}).get("club", {})
    nombre = club.get("nombre_club", "")
    telefono = club.get("telefonos", "")
    email = club.get("email_correspondencia", "")
    portal_web = club.get("portal_web", "")

    if not email and portal_web:
        email = portal_web

    return nombre, telefono, email, portal_web


def load_pending_ids():
    """Carga los club_id pendientes desde el CSV."""
    if not PENDING_FILE.exists():
        print(f"❌ No existe {PENDING_FILE}")
        return []

    ids = []
    with open(PENDING_FILE, "r", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        for row in reader:
            club_id = row.get("club_id", "").strip()
            nombre = row.get("nombre_provisional", "").strip()
            if club_id:
                ids.append((club_id, nombre))
    return ids


def main():
    parser = argparse.ArgumentParser(description="Reintentar pendientes de Madrid")
    parser.add_argument("--delay", type=float, default=DEFAULT_DELAY,
                        help=f"Delay entre peticiones (default: {DEFAULT_DELAY}s)")
    parser.add_argument("--retries", type=int, default=DEFAULT_RETRIES,
                        help=f"Número de reintentos por club (default: {DEFAULT_RETRIES})")
    parser.add_argument("--start-from", type=int, default=0,
                        help="Empezar desde el índice N (0-based)")
    args = parser.parse_args()

    pending = load_pending_ids()
    if not pending:
        print("✅ Sin pendientes que procesar.")
        return

    print(f"=== Reintento de pendientes Madrid ===")
    print(f"Pendientes a reintentar: {len(pending)}")
    print(f"Reintentos por club: {args.retries}")
    print(f"Delay: {args.delay}s")
    print(f"Desde índice: {args.start_from}")
    print()

    if args.start_from > 0:
        pending = pending[args.start_from:]
        print(f"Reanudando desde índice {args.start_from}: {len(pending)} restantes")
        print()

    ok = 0
    err = 0
    total = len(pending)
    recuperados = []

    # Abrir CSV principal en append
    OUTPUT_DIR.mkdir(exist_ok=True)
    with open(MAIN_CSV, "a", newline="", encoding="utf-8-sig", buffering=1) as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)

        for i, (club_id, nombre_lista) in enumerate(pending, 1):
            idx_global = args.start_from + i
            print(f"[{idx_global}/{args.start_from + total}] ID {club_id} — {nombre_lista}")

            nombre, telefono, email, portal_web = scrape_club_detail(club_id, retries=args.retries)

            if nombre:
                writer.writerow({
                    "federacion": FEDERATION_NAME,
                    "nombre": nombre,
                    "telefono": telefono,
                    "email": email,
                })
                f.flush()
                ok += 1
                contact = f" | 📞 {telefono}" if telefono else ""
                contact += f" | 📧 {email}" if email else ""
                print(f"   ✅ {nombre}{contact}")
                recuperados.append(club_id)
            else:
                err += 1
                print(f"   ❌ Sigue fallando")

            time.sleep(args.delay)

    print(f"\n{'='*60}")
    print(f"RESULTADO reintento: OK={ok} ERR={err}")
    print(f"Recuperados: {len(recuperados)}")
    print(f"Archivo principal: {MAIN_CSV}")

    # Consolidar pendientes
    merge_pending_csvs()
    print(f"Pendientes consolidados en: {OUTPUT_DIR / 'clubs_pendientes.csv'}")


if __name__ == "__main__":
    main()
