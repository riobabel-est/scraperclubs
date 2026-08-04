"""Scraper para la Real Federación de Fútbol de Madrid (rffm.es).

Procesa página a página usando:
  - curl_cffi para listado (p=?search=a) → sin gastar créditos ScraperAPI
  - ScraperAPI para detalle (/fichaclub/{id}) → JS rendering

Uso:
  python scraper_madrid.py
  python scraper_madrid.py --resume
  python scraper_madrid.py --start-page 2
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

try:
    from curl_cffi import requests as curl_requests
    HAS_CURL_CFFI = True
except ImportError:
    HAS_CURL_CFFI = False

sys.path.insert(0, str(Path(__file__).parent))
from config import SCRAPERAPI_KEY
from pendientes import PendientesWriter, MOTIVO_SIN_DATOS, MOTIVO_BLOQUEO, merge_pending_csvs

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
OUTPUT_FILE = OUTPUT_DIR / "clubs_nova_madrid.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]
FEDERATION_NAME = "Madrid"

DOMAIN = "https://www.rffm.es"
REQUEST_DELAY = 1.5
TIMEOUT = 60

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "es-ES,es;q=0.9",
}

CHECKPOINT_FILE = CHECKPOINT_DIR / "madrid_progress.json"


def _extract_next_data(html):
    m = re.search(r'__NEXT_DATA__[^>]*?>(.*?)</script>', html, re.DOTALL)
    if m:
        return json.loads(m.group(1))
    return None


def load_progress():
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    if CHECKPOINT_FILE.exists():
        return json.loads(CHECKPOINT_FILE.read_text(encoding="utf-8"))
    return {"page": 1, "total_pages": 0, "processed_ids": [], "ok": 0, "err": 0}


def save_progress(state):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    CHECKPOINT_FILE.write_text(json.dumps(state, ensure_ascii=False, indent=2),
                               encoding="utf-8")


def scrape_page_clubs(page_num):
    """Obtiene clubs de una página con curl_cffi (sin ScraperAPI)."""
    url = f"{DOMAIN}/competicion/clubes?p={page_num}&search=a"
    if HAS_CURL_CFFI:
        session = curl_requests.Session(impersonate="chrome120")
        r = session.get(url, headers=HEADERS, timeout=TIMEOUT)
    else:
        r = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
    r.raise_for_status()

    data = _extract_next_data(r.text)
    if not data:
        return [], 0

    props = data.get("props", {}).get("pageProps", {})
    clubs_raw = props.get("clubs", {}).get("clubes", [])
    total_pages = int(props.get("clubs", {}).get("total_paginas", 0))

    clubs = [{"codigo_club": c.get("codigo_club", ""),
              "nombre": c.get("nombre", "")} for c in clubs_raw]
    return clubs, total_pages


def scrape_club_detail(codigo_club, retries=2):
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
                print(f"      Reintento {attempt+1}/{retries}: {e}")
                time.sleep(2)
            else:
                print(f"      Error HTTP (tras {retries} intentos): {e}")
                return None, None, None, None

    if not data:
        return None, None, None, None

    club = data.get("props", {}).get("pageProps", {}).get("club", {})
    nombre = club.get("nombre_club", "")
    telefono = club.get("telefonos", "")
    email = club.get("email_correspondencia", "")
    portal_web = club.get("portal_web", "")
    fax = club.get("fax", "")

    # Si no hay email, usar portal_web como referencia
    if not email and portal_web:
        email = portal_web  # lo guardamos en campo email para referencia

    return nombre, telefono, email, portal_web


def main():
    parser = argparse.ArgumentParser(description="Scraper RFFM — Madrid")
    parser.add_argument("--resume", action="store_true", help="Reanudar desde checkpoint")
    parser.add_argument("--start-page", type=int, default=None, help="Comenzar desde esta página")
    parser.add_argument("--delay", type=float, default=None, help="Delay entre peticiones")
    args = parser.parse_args()

    delay = args.delay if args.delay is not None else REQUEST_DELAY
    OUTPUT_DIR.mkdir(exist_ok=True)

    print(f"=== Scraper {FEDERATION_NAME} (rffm.es) ===")
    print(f"Listado: curl_cffi | Detalle: ScraperAPI | Delay: {delay}s")
    print()

    state = load_progress() if args.resume else {"page": 1, "total_pages": 0,
                                                   "processed_ids": [], "ok": 0, "err": 0}
    if args.start_page:
        state["page"] = args.start_page

    processed_set = set(state["processed_ids"])
    if processed_set:
        print(f"Reanudando: {len(processed_set)} IDs ya procesados")

    page = state["page"]
    total_pages = state["total_pages"]
    ok = state["ok"]
    err = state["err"]

    # Crear writer de pendientes
    pendientes = PendientesWriter("nova_madrid")
    pendientes.open()

    mode = "a" if OUTPUT_FILE.exists() else "w"
    with open(OUTPUT_FILE, mode, newline="", encoding="utf-8-sig", buffering=1) as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
        if mode == "w":
            writer.writeheader()

        while True:
            print(f"\n📄 PÁGINA {page}" + (f"/{total_pages}" if total_pages else ""))
            print(f"   Obteniendo lista (curl_cffi)...")

            try:
                clubs, tp = scrape_page_clubs(page)
            except Exception as e:
                print(f"   Error: {e}")
                # Reintentar con ScraperAPI
                print(f"   Reintentando via ScraperAPI...")
                from urllib.parse import quote as q
                url = f"{DOMAIN}/competicion/clubes?p={page}&search=a"
                api_url = f"http://api.scraperapi.com?api_key={SCRAPERAPI_KEY}&url={q(url, safe='')}&render=true"
                r = requests.get(api_url, headers=HEADERS, timeout=TIMEOUT)
                data = _extract_next_data(r.text)
                if data:
                    props = data.get("props", {}).get("pageProps", {})
                    clubs_raw = props.get("clubs", {}).get("clubes", [])
                    tp = int(props.get("clubs", {}).get("total_paginas", 0))
                    clubs = [{"codigo_club": c.get("codigo_club", ""),
                              "nombre": c.get("nombre", "")} for c in clubs_raw]
                else:
                    print(f"   Fallo irrecuperable. Fin.")
                    break

            if tp:
                total_pages = tp

            if not clubs:
                print(f"   Sin clubs. Fin.")
                break

            pending = [c for c in clubs if c["codigo_club"] not in processed_set]
            print(f"   {len(clubs)} clubs | {len(pending)} pendientes")

            for j, club in enumerate(pending, 1):
                cod = club["codigo_club"]
                nombre_lista = club["nombre"]
                nombre, telefono, email, portal_web = scrape_club_detail(cod)

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
                    print(f"   ✅ [{j}/{len(pending)}] {nombre}{contact}")
                else:
                    err += 1
                    print(f"   ❌ [{j}/{len(pending)}] {nombre_lista} (ID {cod})")
                    # Registrar en pendientes
                    pendientes.write_error(FEDERATION_NAME, cod, nombre_lista, MOTIVO_SIN_DATOS)

                processed_set.add(cod)
                time.sleep(delay)

            state = {"page": page, "total_pages": total_pages,
                     "processed_ids": list(processed_set), "ok": ok, "err": err}
            save_progress(state)
            print(f"   💾 Página {page} completada — OK:{ok} ERR:{err} | Total acum: {len(processed_set)} IDs")

            if total_pages and page >= total_pages:
                break
            page += 1

    pendientes.close()
    merge_pending_csvs()

    print(f"\n{'='*60}")
    print(f"TOTAL clubs (Madrid): OK={ok} ERR={err}")
    print(f"Archivo: {OUTPUT_FILE}")
    if pendientes.count > 0:
        print(f"⚠️  Clubes pendientes (no scrapeados): {pendientes.count}")
        print(f"   → {pendientes.filepath}")
        print(f"   Revisa este archivo para completar los datos manualmente.")


if __name__ == "__main__":
    main()