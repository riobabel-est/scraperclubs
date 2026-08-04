"""
Scraper para la Federació Catalana de Futbol (fcf.cat).

FCF.cat usa una SPA propia (no plataforma NOVA), pero dispone de un portal
NOVA a través de intranet.fcf.cat/nfg y www.futbol.cat/fnfg.

Flujo:
  1. Intentar portales NOVA con curl_cffi (TLS impersonation)
  2. Si requiere login → fallback con Playwright (navegador real headless)
  3. Extraer nombre, teléfono, email de NFG_VerClub via NOVA o Playwright

Uso:
  python scraper_fcf_cat.py          # modo seguro
  python scraper_fcf_cat.py --fast   # modo rápido
"""

import csv
import json
import os
import re
import sys
import time
import argparse
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# curl_cffi emula el TLS fingerprint de Chrome real
try:
    from curl_cffi import requests as curl_requests
    HAS_CURL_CFFI = True
except ImportError:
    HAS_CURL_CFFI = False

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
OUTPUT_FILE = OUTPUT_DIR / "clubs_fcf_cat.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

REQUEST_DELAY = 2.0
MAX_RETRIES = 3
TIMEOUT = 15
PAGE_SIZE = 20

# Módulo de pendientes
sys.path.insert(0, str(Path(__file__).parent))
from pendientes import PendientesWriter, MOTIVO_SIN_DATOS, MOTIVO_BLOQUEO, MOTIVO_LOGIN, MOTIVO_TIMEOUT

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ca-ES,ca;q=0.9,es;q=0.8",
}

# Posibles portales NOVA de la FCF
FCF_NOVA_CANDIDATES = [
    {"domain": "https://www.futbol.cat/fnfg", "cod_primaria": "1000118"},
    {"domain": "https://intranet.fcf.cat/nfg",  "cod_primaria": "1000118"},
]

FEDERATION_NAME = "Cataluña"


def get_with_retry(session, url, params=None, retries=MAX_RETRIES):
    for attempt in range(retries):
        try:
            resp = session.get(url, params=params, headers=HEADERS, timeout=TIMEOUT,
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


def scrape_via_nova(domain, cod_primaria, writer, pendientes_writer=None):
    """Usa el flujo estándar NOVA si el portal es accesible."""
    sys.path.insert(0, str(Path(__file__).parent))
    from scraper_nova import get_all_club_ids, scrape_club

    # Usar curl_cffi si está disponible
    if HAS_CURL_CFFI:
        print("  [tls] Usando curl_cffi con impersonate=chrome120")
        session = curl_requests.Session(impersonate="chrome120")
    else:
        session = requests.Session()

    all_ids = get_all_club_ids(session, domain, cod_primaria, FEDERATION_NAME)

    ok = 0
    err = 0
    for i, club_id in enumerate(all_ids, 1):
        nombre, telefono, email, blocked = scrape_club(session, domain, cod_primaria, club_id)
        if nombre:
            writer.writerow({"federacion": FEDERATION_NAME, "nombre": nombre,
                             "telefono": telefono, "email": email})
            ok += 1
            print(f"  [{i}/{len(all_ids)}] OK: {nombre}")
        else:
            err += 1
            print(f"  [{i}/{len(all_ids)}] ERR: ID {club_id}")
            if pendientes_writer:
                motivo = MOTIVO_BLOQUEO if blocked else MOTIVO_SIN_DATOS
                pendientes_writer.write_error(FEDERATION_NAME, club_id, "", motivo)
        if i % 50 == 0:
            print(f"  Progreso: {i}/{len(all_ids)} — OK:{ok} ERR:{err}")
        time.sleep(REQUEST_DELAY)

    print(f"  {FEDERATION_NAME} (NOVA) — OK:{ok} ERR:{err}")
    return ok


def scrape_via_playwright(domain, cod_primaria, writer, pendientes_writer=None):
    """
    Fallback con Playwright (navegador real headless) para portales NOVA
    que requieren login o bloquean curl_cffi.
    """
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("  [ERROR] Playwright no está instalado. Instálalo con:")
        print("    pip install playwright && python -m playwright install chromium")
        return 0

    print("  [playwright] Iniciando navegador headless...")
    ok = 0
    err = 0
    checkpoint_file = CHECKPOINT_DIR / "fcf_playwright_checkpoint.json"

    # Cargar checkpoint
    processed_ids = set()
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    if checkpoint_file.exists():
        with open(checkpoint_file, "r", encoding="utf-8") as f:
            processed_ids = set(json.load(f))
        print(f"  [playwright] Reanudando: {len(processed_ids)} IDs ya procesados")

    all_ids = []
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            context = browser.new_context(
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                           "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
                locale="ca-ES",
            )
            page = context.new_page()

            # Paso 1: Paginar NFG_Clubes
            print("  [playwright] Paso 1: Obteniendo IDs de clubs...")
            page_num = 1
            while True:
                url = f"{domain}/pnfg/NPcd/NFG_Clubes"
                params = f"cod_primaria={cod_primaria}&NPcd_Page={page_num}&Buscar=1&NPcd_PageLines={PAGE_SIZE}"
                full_url = f"{url}?{params}"
                print(f"    Página {page_num}...")
                try:
                    page.goto(full_url, wait_until="domcontentloaded", timeout=30000)
                except Exception as e:
                    print(f"    Error navegando página {page_num}: {e}")
                    break

                html = page.content()
                soup = BeautifulSoup(html, "lxml")

                # Verificar login
                if "NLogin" in page.url:
                    print("    [playwright] Portal requiere login incluso con navegador real.")
                    break

                # Extraer IDs
                ids_on_page = []
                for link in soup.find_all("a", href=re.compile(r"javascript:Ver\(")):
                    match = re.search(r"Ver\((\d+)\)", link["href"])
                    if match:
                        ids_on_page.append(match.group(1))
                if not ids_on_page:
                    # También buscar enlaces directos
                    for link in soup.find_all("a", href=re.compile(r"codigo_club=")):
                        match = re.search(r"codigo_club=(\d+)", link.get("href", ""))
                        if match:
                            ids_on_page.append(match.group(1))

                if not ids_on_page:
                    print(f"    Sin IDs en página {page_num}. Fin de paginación.")
                    break

                all_ids.extend(ids_on_page)
                total_match = re.search(r"Total\s+(\d+)\s+Registros", soup.get_text(), re.IGNORECASE)
                total = int(total_match.group(1)) if total_match else None
                total_pages = (total // PAGE_SIZE) + (1 if total % PAGE_SIZE else 0) if total else None
                if total_pages:
                    print(f"    Página {page_num}/{total_pages} — {len(all_ids)}/{total} IDs")
                if total and len(all_ids) >= total:
                    break
                page_num += 1
                time.sleep(REQUEST_DELAY)

            print(f"    Total IDs únicos: {len(all_ids)}")

            # Paso 2: Scrapear detalle de cada club
            pending = [id_ for id_ in all_ids if id_ not in processed_ids]
            print(f"  [playwright] Paso 2: {len(pending)} clubs pendientes...")

            for i, club_id in enumerate(pending, 1):
                detail_url = f"{domain}/pnfg/NPcd/NFG_VerClub?cod_primaria={cod_primaria}&codigo_club={club_id}"
                try:
                    page.goto(detail_url, wait_until="domcontentloaded", timeout=15000)
                    html = page.content()
                    soup = BeautifulSoup(html, "lxml")

                    if "NLogin" in page.url:
                        print(f"    [{i}/{len(pending)}] Login requerido — ID {club_id}")
                        continue

                    # Parsear detalle
                    nombre = ""
                    telefono = ""
                    email = ""
                    for tag in ["h2", "h1", "h3"]:
                        el = soup.find(tag)
                        if el:
                            text = el.get_text(strip=True)
                            if text and not any(x in text.lower() for x in
                                                ["nova", "gestión", "intranet",
                                                 "federación", "consulta"]):
                                nombre = text
                                break
                    for strong in soup.find_all("strong"):
                        label = strong.get_text(strip=True).rstrip(":").strip()
                        parent = strong.parent
                        raw = parent.get_text(separator=" ", strip=True)
                        if re.search(r"Teléfono", label, re.IGNORECASE):
                            value = re.sub(r"Teléfonos?\s*:?\s*", "", raw,
                                           flags=re.IGNORECASE).strip()
                            if value:
                                telefono = value
                        elif "Email" in label:
                            value = re.sub(r"Email\s*:?\s*", "", raw,
                                           flags=re.IGNORECASE).strip()
                            if "@" in value:
                                email = value
                    if not email:
                        mailto = soup.find("a", href=re.compile(r"^mailto:", re.I))
                        if mailto:
                            email = re.sub(r"^mailto:", "", mailto["href"], flags=re.I).strip()

                    if nombre:
                        writer.writerow({"federacion": FEDERATION_NAME, "nombre": nombre,
                                         "telefono": telefono, "email": email})
                        ok += 1
                        print(f"    [{i}/{len(pending)}] OK: {nombre}")
                    else:
                        err += 1
                        print(f"    [{i}/{len(pending)}] ERR: ID {club_id}")
                        if pendientes_writer:
                            pendientes_writer.write_error(FEDERATION_NAME, club_id, "", MOTIVO_SIN_DATOS)

                    processed_ids.add(club_id)
                    if i % 10 == 0:
                        with open(checkpoint_file, "w", encoding="utf-8") as f:
                            json.dump(list(processed_ids), f)
                        print(f"    Progreso: {i}/{len(pending)} — OK:{ok}")
                    time.sleep(REQUEST_DELAY)

                except Exception as e:
                    print(f"    [{i}/{len(pending)}] Error: {e} — ID {club_id}")
                    time.sleep(REQUEST_DELAY * 2)

            # Checkpoint final
            with open(checkpoint_file, "w", encoding="utf-8") as f:
                json.dump(list(processed_ids), f)

            browser.close()

    except Exception as e:
        print(f"  [playwright] Error general: {e}")
        import traceback
        traceback.print_exc()

    print(f"  {FEDERATION_NAME} (Playwright) — OK:{ok} ERR:{err}")
    return ok


def main():
    parser = argparse.ArgumentParser(description="Scraper FCF.cat — Cataluña")
    parser.add_argument("--fast", action="store_true", help="Modo rápido")
    parser.add_argument("--delay", type=float, default=None,
                        help="Delay global por petición (segundos)")
    parser.add_argument("--resume", action="store_true", help="Reanudar desde checkpoint")
    args = parser.parse_args()

    global REQUEST_DELAY
    if args.delay:
        REQUEST_DELAY = args.delay

    OUTPUT_DIR.mkdir(exist_ok=True)

    print(f"=== Scraper FCF.cat ({FEDERATION_NAME}) ===")
    print(f"Delay: {REQUEST_DELAY}s")
    print()

    # Crear writer de pendientes
    pendientes = PendientesWriter("fcf_cat")
    pendientes.open()

    # Intentar portales NOVA con curl_cffi
    nova_ok = False
    for candidate in FCF_NOVA_CANDIDATES:
        if try_nova_portal(candidate["domain"], candidate["cod_primaria"]):
            print(f"\n  Usando portal NOVA: {candidate['domain']}")
            mode = "a" if args.resume and OUTPUT_FILE.exists() else "w"
            with open(OUTPUT_FILE, mode, newline="", encoding="utf-8-sig") as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                if mode == "w":
                    writer.writeheader()
                count = scrape_via_nova(candidate["domain"],
                                        candidate["cod_primaria"], writer,
                                        pendientes_writer=pendientes)
            print(f"\nTotal clubes guardados: {count}")
            print(f"Archivo: {OUTPUT_FILE}")
            nova_ok = True
            break

    if not nova_ok:
        # Fallback: intentar NOVA con Playwright (navegador real)
        print("\n[INFO] Portales NOVA no accesibles con requests/curl_cffi.")
        print("       Intentando con Playwright (navegador real headless)...")
        print()

        for candidate in FCF_NOVA_CANDIDATES:
            print(f"  Probando con Playwright: {candidate['domain']}")
            mode = "a" if args.resume and OUTPUT_FILE.exists() else "w"
            with open(OUTPUT_FILE, mode, newline="", encoding="utf-8-sig") as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                if mode == "w":
                    writer.writeheader()
                count = scrape_via_playwright(candidate["domain"],
                                              candidate["cod_primaria"], writer,
                                              pendientes_writer=pendientes)
            if count > 0:
                print(f"\nTotal clubes guardados: {count}")
                print(f"Archivo: {OUTPUT_FILE}")
                break
        else:
            print("\n[INFO] No se pudo acceder a ningún portal NOVA de FCF.")
            print("       Opciones alternativas:")
            print("       1. Contactar a FCF para solicitar acceso a intranet")
            print("       2. Solicitar exportación directa de datos de clubes")

    pendientes.close()
    if pendientes.count > 0:
        print(f"⚠️  Clubes pendientes (no scrapeados): {pendientes.count}")
        print(f"   → {pendientes.filepath}")
        print(f"   Revisa este archivo para completar los datos manualmente.")


if __name__ == "__main__":
    main()