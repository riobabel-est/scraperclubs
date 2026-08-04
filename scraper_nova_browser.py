"""
Scraper NOVA con Playwright (navegador real) — bypassea TODOS los bloqueos.
Usa curl_cffi para paginación (rápida) y Playwright solo para páginas de detalle.

Uso:
  python scraper_nova_browser.py --fed "Cantabria"
  python scraper_nova_browser.py --resume
"""

import csv
import json
import re
import sys
import time
import argparse
from pathlib import Path

import requests
from bs4 import BeautifulSoup

try:
    from curl_cffi import requests as curl_requests
    from playwright.sync_api import sync_playwright
    HAS_PLAYWRIGHT = True
except ImportError:
    HAS_PLAYWRIGHT = False
    print("ERROR: playwright no instalado. Ejecuta: pip install playwright && python -m playwright install chromium")
    sys.exit(1)

sys.path.insert(0, str(Path(__file__).parent))
from config import NOVA_FEDERATIONS, PAGE_SIZE, REQUEST_DELAY, TIMEOUT

# Rotador de IP via ADB
try:
    from ip_rotator import rotate_ip_adb, get_current_ip
    HAS_IP_ROTATOR = True
except (ImportError, RuntimeError):
    HAS_IP_ROTATOR = False

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

BASE_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "es-ES,es;q=0.9",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
}


def load_checkpoint(name):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    safe = name.strip().replace(" ", "_")
    path = CHECKPOINT_DIR / f"{safe}_pw.json"
    if path.exists():
        with open(path, "r", encoding="utf-8") as f:
            return set(json.load(f))
    return set()


def save_checkpoint(name, processed):
    safe = name.strip().replace(" ", "_")
    path = CHECKPOINT_DIR / f"{safe}_pw.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(list(processed), f)


def load_all_ids(name):
    safe = name.strip().replace(" ", "_")
    path = CHECKPOINT_DIR / f"{safe}_all_ids.json"
    if path.exists():
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    return None


def save_all_ids(name, ids):
    safe = name.strip().replace(" ", "_")
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = CHECKPOINT_DIR / f"{safe}_all_ids.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(ids, f)


def get_all_ids_pw(page, domain, cod_primaria, fed_name):
    """Paginación con Playwright (más lento pero 100% fiable)."""
    all_ids = []
    page_num = 1

    while True:
        url = f"{domain}/pnfg/NPcd/NFG_Clubes"
        params = f"cod_primaria={cod_primaria}&NPcd_Page={page_num}&Buscar=1&NPcd_PageLines={PAGE_SIZE}"
        full_url = f"{url}?{params}"
        print(f"  [{fed_name}] Pagina {page_num}...")
        try:
            page.goto(full_url, wait_until="domcontentloaded", timeout=30000)
        except Exception as e:
            print(f"  Error: {e}")
            break

        html = page.content()
        soup = BeautifulSoup(html, "lxml")

        ids = []
        for link in soup.find_all("a", href=re.compile(r"Ver\(\d+\)")):
            m = re.search(r"Ver\((\d+)\)", link["href"])
            if m:
                ids.append(m.group(1))

        if not ids:
            print(f"  Sin IDs. Fin.")
            break

        all_ids.extend(ids)
        text = soup.get_text()
        total_m = re.search(r"Total\s+(\d+)\s+Registros", text, re.IGNORECASE)
        total = int(total_m.group(1)) if total_m else None
        total_pages = (total // PAGE_SIZE) + (1 if total % PAGE_SIZE else 0) if total else None
        if total_pages:
            print(f"  Pagina {page_num}/{total_pages} — {len(all_ids)}/{total} IDs")
        if total and len(all_ids) >= total:
            break
        page_num += 1
        time.sleep(1)

    seen = set()
    unique = []
    for id_ in all_ids:
        if id_ not in seen:
            seen.add(id_)
            unique.append(id_)
    print(f"  [{fed_name}] Total IDs unicos: {len(unique)}")
    return unique


def parse_detail(soup):
    nombre = ""
    telefono = ""
    email = ""

    # Buscar nombre en h2
    for h2 in soup.find_all("h2"):
        t = h2.get_text(strip=True)
        if t and len(t) > 2 and not any(x in t.lower() for x in ["consulta", "nova", "gestión"]):
            nombre = t
            break

    # Formato h5><strong> (RFCF y similares)
    for h5 in soup.find_all("h5"):
        strong = h5.find("strong")
        if strong:
            label = strong.get_text(strip=True).rstrip(":").strip()
            full = h5.get_text(strip=True)
            valor = full.replace(strong.get_text(strip=True), "").strip().replace("\xa0", " ")
            if re.search(r"Teléfono", label, re.IGNORECASE) and not telefono and re.search(r"\d", valor):
                telefono = valor
            elif "Email" in label and not email and "@" in valor:
                email = valor

    # Fallback: formato <strong> inline
    if not telefono or not email:
        for strong in soup.find_all("strong"):
            label = strong.get_text(strip=True).rstrip(":").strip()
            raw = strong.parent.get_text(separator=" ", strip=True)
            if re.search(r"Teléfono", label, re.IGNORECASE) and not telefono:
                value = re.sub(r"Teléfonos?\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
                if value and re.search(r"\d", value): telefono = value
            elif "Email" in label and not email:
                value = re.sub(r"Email\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
                if value and "@" in value: email = value

    # Fallback: mailto
    if not email:
        mailto = soup.find("a", href=re.compile(r"^mailto:", re.I))
        if mailto: email = re.sub(r"^mailto:", "", mailto["href"], flags=re.I).strip()

    return nombre, telefono, email


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--fed", help="Federacion especifica")
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--delay", type=float, default=3.0)
    args = parser.parse_args()

    OUTPUT_DIR.mkdir(exist_ok=True)

    if args.fed:
        feds = [f for f in NOVA_FEDERATIONS if f["name"].lower() == args.fed.lower()]
        if not feds:
            print(f"Federacion '{args.fed}' no encontrada.")
            return
    else:
        feds = [f for f in NOVA_FEDERATIONS if not f.get("skip", False)]

    print(f"=== Scraper NOVA con Playwright ({len(feds)} federaciones) ===")
    print(f"Delay: {args.delay}s\n")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            locale="es-ES",
        )
        page = context.new_page()

        for fed in feds:
            name = fed["name"]
            domain = fed["domain"]
            cod_primaria = fed["cod_primaria"]

            if fed.get("skip", False):
                print(f"\n[{name}] SKIP — saltando.")
                continue

            print(f"\n{'='*60}")
            print(f"Federacion: {name} ({domain})")
            print(f"{'='*60}")

            processed = load_checkpoint(name) if args.resume else set()
            if processed:
                print(f"  Reanudando: {len(processed)} procesados")

            # Obtener IDs
            cached = load_all_ids(name) if args.resume else None
            if cached:
                print(f"  [cache] {len(cached)} IDs cacheados")
                all_ids = cached
            else:
                all_ids = get_all_ids_pw(page, domain, cod_primaria, name)
                if all_ids:
                    save_all_ids(name, all_ids)

            if not all_ids:
                print(f"  [ERROR] Sin IDs. Saltando.")
                continue

            pending = [id_ for id_ in all_ids if id_ not in processed]
            print(f"  Pendientes: {len(pending)}/{len(all_ids)}")

            if not pending:
                print(f"  Completado!")
                continue

            # CSV output
            safe_name = re.sub(r"[^a-zA-Z0-9_-]+", "_", name.strip().lower())
            out_file = OUTPUT_DIR / f"clubs_nova_{safe_name}.csv"
            mode = "a" if args.resume and out_file.exists() else "w"

            ok = 0
            err = 0
            consecutive_errors = 0

            with open(out_file, mode, newline="", encoding="utf-8-sig", buffering=1) as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                if mode == "w":
                    writer.writeheader()

                idx = 0
                while idx < len(pending):
                    club_id = pending[idx]
                    detail_url = f"{domain}/pnfg/NPcd/NFG_VerClub?cod_primaria={cod_primaria}&codigo_club={club_id}"

                    blocked = False
                    try:
                        page.goto(detail_url, wait_until="domcontentloaded", timeout=20000)
                        html = page.content()
                        
                        # Detectar bloqueo: HTML muy corto o NLogin
                        if len(html) <= 39 or "NLogin" in page.url:
                            blocked = True
                        else:
                            soup = BeautifulSoup(html, "lxml")
                            if "NLogin" in page.url or soup.find("input", {"name": "clave_acceso"}):
                                print(f"  [{idx+1}/{len(pending)}] Login requerido — ID {club_id}")
                                err += 1
                                consecutive_errors += 1
                            else:
                                nombre, telefono, email = parse_detail(soup)
                                if nombre:
                                    writer.writerow({"federacion": name, "nombre": nombre,
                                                     "telefono": telefono, "email": email})
                                    f.flush()
                                    ok += 1
                                    consecutive_errors = 0
                                    print(f"  [{idx+1}/{len(pending)}] OK: {nombre}")
                                else:
                                    err += 1
                                    consecutive_errors += 1
                                    print(f"  [{idx+1}/{len(pending)}] ERR: sin datos — ID {club_id}")

                    except Exception as e:
                        err += 1
                        consecutive_errors += 1
                        # Timeout en goto también es bloqueo
                        if "timeout" in str(e).lower() or "timeout" in str(e).lower():
                            blocked = True
                        print(f"  [{idx+1}/{len(pending)}] ERROR: {e} — ID {club_id}")

                    # Rotar IP si hay bloqueo
                    if blocked and HAS_IP_ROTATOR:
                        print(f"\n⚠️  Bloqueo detectado (club {idx+1}/{len(pending)}, ID {club_id}). Rotando IP...")
                        try:
                            nueva_ip = rotate_ip_adb()
                            if nueva_ip:
                                print(f"    ✅ Nueva IP: {nueva_ip}. Reintentando...")
                            else:
                                print(f"    ⚠️ Reintentando sin confirmar IP...")
                        except RuntimeError as e:
                            print(f"    ❌ Error al rotar IP: {e}")
                            save_checkpoint(name, processed)
                            print(f"    Checkpoint guardado. Relanza con --resume.")
                            browser.close()
                            return
                        # No avanzamos idx — reintentamos el mismo club
                        continue

                    if not blocked:
                        processed.add(club_id)

                    if (idx + 1) % 10 == 0:
                        save_checkpoint(name, processed)
                        print(f"  Progreso: {idx+1}/{len(pending)} — OK:{ok} ERR:{err}")

                    idx += 1
                    time.sleep(args.delay)

                save_checkpoint(name, processed)
                print(f"\n  [{name}] OK:{ok} ERR:{err}")

            # Regenerar clubs_nova.csv consolidado
            _merge_nova_csvs()

        browser.close()

    _merge_nova_csvs()
    print("\nListo. Datos en output/clubs_nova.csv y clubs_todos.csv")


def _merge_nova_csvs():
    output_dir = Path(__file__).parent / "output"
    nova_csv = output_dir / "clubs_nova.csv"

    all_rows = []
    seen = set()
    for f in sorted(output_dir.glob("clubs_nova_*.csv")):
        with open(f, "r", encoding="utf-8-sig") as fh:
            reader = csv.DictReader(fh)
            for row in reader:
                key = (row.get("federacion", "").strip().lower(),
                       row.get("nombre", "").strip().upper())
                if key not in seen and row.get("nombre", "").strip():
                    seen.add(key)
                    all_rows.append(row)

    with open(nova_csv, "w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_HEADERS)
        writer.writeheader()
        writer.writerows(all_rows)

    # También clubs_todos.csv
    todos_csv = output_dir / "clubs_todos.csv"
    rfcylf_csv = output_dir / "clubs_rfcylf.csv"
    fcf_csv = output_dir / "clubs_fcf_cat.csv"

    for extra in [rfcylf_csv, fcf_csv]:
        if extra.exists():
            with open(extra, "r", encoding="utf-8-sig") as fh:
                reader = csv.DictReader(fh)
                for row in reader:
                    key = (row.get("federacion", "").strip().lower(),
                           row.get("nombre", "").strip().upper())
                    if key not in seen and row.get("nombre", "").strip():
                        seen.add(key)
                        all_rows.append(row)

    with open(todos_csv, "w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_HEADERS)
        writer.writeheader()
        writer.writerows(all_rows)

    print(f"  CSV consolidado: {len(all_rows)} clubes totales")


if __name__ == "__main__":
    main()