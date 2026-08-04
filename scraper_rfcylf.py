"""
Scraper para la Federación de Fútbol de Castilla y León (rfcylf.es).

Castilla y León no expone NFG_Clubes públicamente, pero sí NFG_LstDirectorioEquipos
(listado) y NFG_VerClub (detalle individual), ambos en plataforma NOVA.

Flujo corregido:
  1. Obtener la lista de competiciones del formulario
  2. Para cada competición, obtener los grupos disponibles
  3. Para cada combinación competición+grupo, extraer los IDs de clubs del directorio
  4. Para cada ID de club, fetchear NFG_VerClub → extraer nombre, teléfono, email
  5. Deduplicar por ID de club

Uso:
  python scraper_rfcylf.py          # modo seguro por defecto (delay 2s)
  python scraper_rfcylf.py --fast   # modo rápido (bajo tu responsabilidad)
"""

import argparse
import csv
import json
import re
import sys
import time
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# curl_cffi emula el TLS fingerprint de Chrome real
try:
    from curl_cffi import requests as curl_requests
    HAS_CURL_CFFI = True
except ImportError:
    HAS_CURL_CFFI = False

# Importar configuración centralizada
sys.path.insert(0, str(Path(__file__).parent))
from config import (
    REQUEST_DELAY, MAX_RETRIES, TIMEOUT,
    get_random_user_agent, jitter_sleep, SCRAPERAPI_KEY,
)

# Módulo de pendientes para registrar clubes no scrapeados
from pendientes import PendientesWriter, MOTIVO_SIN_DATOS, MOTIVO_BLOQUEO, MOTIVO_LOGIN, MOTIVO_TIMEOUT

# Rotador de IP via ADB (Modo Avión en Android) — DESHABILITADO, solo ScraperAPI
HAS_IP_ROTATOR = False

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
OUTPUT_FILE = OUTPUT_DIR / "clubs_rfcylf.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

BASE_URL = "https://www.rfcylf.es"
DIRECTORY_URL = f"{BASE_URL}/pnfg/NPcd/NFG_LstDirectorioEquipos"
VER_CLUB_URL = f"{BASE_URL}/pnfg/NPcd/NFG_VerClub"
COD_PRIMARIA = "1000117"
FEDERATION_NAME = "Castilla y León"
CHECKPOINT_FILE = CHECKPOINT_DIR / "rfcylf_checkpoint.json"

BASE_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "es-ES,es;q=0.9",
    "Accept-Encoding": "gzip, deflate, br",
    "Cache-Control": "no-cache",
    "Sec-Fetch-Dest": "document",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "none",
    "Sec-Fetch-User": "?1",
}


def _make_headers():
    """Construye headers con User-Agent aleatorio del pool."""
    return {**BASE_HEADERS, "User-Agent": get_random_user_agent(),
            "Referer": DIRECTORY_URL, "Origin": BASE_URL}


def _create_session():
    """Crea una sesión HTTP, con curl_cffi si está disponible."""
    if HAS_CURL_CFFI:
        print("  [tls] Usando curl_cffi con impersonate=chrome120")
        return curl_requests.Session(impersonate="chrome120")
    return requests.Session()


def load_checkpoint():
    """Carga el set de IDs ya procesados."""
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    if CHECKPOINT_FILE.exists():
        with open(CHECKPOINT_FILE, "r", encoding="utf-8") as f:
            return set(json.load(f))
    return set()


def save_checkpoint(processed_ids):
    """Guarda el set de IDs procesados."""
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    with open(CHECKPOINT_FILE, "w", encoding="utf-8") as f:
        json.dump(list(processed_ids), f)


def get_competitions(session):
    """Obtiene el formulario con las competiciones disponibles."""
    resp = session.get(DIRECTORY_URL, params={"cod_primaria": COD_PRIMARIA},
                       headers=_make_headers(), timeout=TIMEOUT)
    if not resp or resp.status_code != 200:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    select = soup.find("select", {"name": "Sch_CodCompeticion"})
    if not select:
        return []

    competitions = []
    for opt in select.find_all("option"):
        val = opt.get("value", "").strip()
        if val:
            competitions.append((val, opt.get_text(strip=True)))
    return competitions


def get_groups_for_competition(session, cod_competition):
    """Obtiene los grupos disponibles para una competición via POST."""
    data = {
        "cod_primaria": COD_PRIMARIA,
        "Sch_CodCompeticion": cod_competition,
        "Sch_CodGrupo": "",
        "Buscar": "1",
    }
    try:
        resp = session.post(DIRECTORY_URL, data=data, headers=_make_headers(), timeout=TIMEOUT)
        resp.raise_for_status()
    except requests.RequestException:
        return []

    soup = BeautifulSoup(resp.text, "lxml")
    select = soup.find("select", {"name": "Sch_CodGrupo"})
    if not select:
        return [("", "Único")]

    groups = []
    for opt in select.find_all("option"):
        val = opt.get("value", "").strip()
        groups.append((val, opt.get_text(strip=True)))
    return groups if groups else [("", "Único")]


def scrape_directory_for_ids(session, cod_competition, cod_group):
    """
    Extrae los IDs de clubs del directorio para una combinación competición+grupo.
    Busca enlaces javascript:Ver(ID) o enlaces a NFG_VerClub.
    """
    data = {
        "cod_primaria": COD_PRIMARIA,
        "Sch_CodCompeticion": cod_competition,
        "Sch_CodGrupo": cod_group,
        "Buscar": "1",
    }
    try:
        resp = session.post(DIRECTORY_URL, data=data, headers=_make_headers(), timeout=TIMEOUT)
        resp.raise_for_status()
    except requests.RequestException:
        return {}

    soup = BeautifulSoup(resp.text, "lxml")
    club_entries = {}  # club_id → nombre provisional

    # Buscar enlaces javascript:Ver(ID)
    for link in soup.find_all("a", href=re.compile(r"Ver\(\d+\)")):
        match = re.search(r"Ver\((\d+)\)", link.get("href", ""))
        if match:
            club_id = match.group(1)
            nombre = link.get_text(strip=True)
            if nombre and club_id not in club_entries:
                club_entries[club_id] = nombre

    # Fallback: buscar enlaces a NFG_VerClub?codigo_club=ID
    if not club_entries:
        for link in soup.find_all("a", href=re.compile(r"codigo_club=")):
            match = re.search(r"codigo_club=(\d+)", link.get("href", ""))
            if match:
                club_id = match.group(1)
                nombre = link.get_text(strip=True)
                if nombre and club_id not in club_entries:
                    club_entries[club_id] = nombre

    # Segundo fallback: buscar en filas de tabla con enlaces
    if not club_entries:
        for row in soup.find_all("tr"):
            link = row.find("a", href=re.compile(r"\d+"))
            if link:
                text = link.get_text(strip=True)
                # Intentar extraer ID del href
                match = re.search(r"(\d{4,})", link.get("href", ""))
                if match:
                    club_id = match.group(1)
                    if text and club_id not in club_entries:
                        club_entries[club_id] = text

    return club_entries


def parse_club_detail(soup):
    """Extrae nombre, teléfono y email de la página de detalle (NFG_VerClub)."""
    nombre = ""
    telefono = ""
    email = ""

    # Intentar extraer nombre de h1, h2, h3
    for tag in ["h2", "h1", "h3"]:
        el = soup.find(tag)
        if el:
            text = el.get_text(strip=True)
            if text and not any(x in text.lower() for x in ["nova", "gestión",
                                                             "intranet", "federación",
                                                             "consulta", "directorio"]):
                nombre = text
                break

    # Buscar Teléfono y Email en labels <strong>
    for strong in soup.find_all("strong"):
        label = strong.get_text(strip=True).rstrip(":").strip()
        parent = strong.parent
        raw = parent.get_text(separator=" ", strip=True)
        if re.search(r"Teléfono", label, re.IGNORECASE):
            value = re.sub(r"Teléfonos?\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
            if value:
                telefono = value
        elif "Email" in label:
            value = re.sub(r"Email\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
            if "@" in value:
                email = value

    # Fallback: mailto
    if not email:
        mailto = soup.find("a", href=re.compile(r"^mailto:", re.I))
        if mailto:
            email = re.sub(r"^mailto:", "", mailto["href"], flags=re.I).strip()

    return nombre, telefono, email



def _detect_block(resp_text, resp_url):
    """Detecta si la respuesta indica bloqueo por cuota (39 bytes o redirección a NLogin)."""
    if not resp_text and not resp_url:
        return False
    if resp_text is not None and len(resp_text) <= 39:
        return True
    if resp_url and "NLogin" in resp_url:
        return True
    return False


def scrape_club_detail(session, club_id, retries=MAX_RETRIES, base_delay=2.0):
    """Visita la página de detalle de un club (NFG_VerClub).
    Retorna (nombre, telefono, email, blocked)."""
    url = VER_CLUB_URL
    params = {"cod_primaria": COD_PRIMARIA, "codigo_club": club_id}

    for attempt in range(retries):
        try:
            resp = session.get(url, params=params, headers=_make_headers(), timeout=TIMEOUT)
            resp.raise_for_status()

            # Detección de bloqueo por cuota (respuesta capada)
            if _detect_block(resp.text, resp.url):
                return None, None, None, True  # blocked=True

            if len(resp.text) == 0:
                wait = min(base_delay * (2 ** attempt), 120)
                if attempt < retries - 1:
                    print(f"    [vacío] Reintento {attempt+1}/{retries} — esperando {wait:.0f}s...")
                    time.sleep(wait)
                    continue
                return None, None, None, False

            soup = BeautifulSoup(resp.text, "lxml")
            if soup.find("input", {"name": "clave_acceso"}):
                print(f"    [WARN] Requiere login — ID {club_id}")
                return None, None, None, False

            nombre, telefono, email = parse_club_detail(soup)
            return nombre, telefono, email, False

        except requests.RequestException as e:
            wait = min(base_delay * (2 ** attempt), 120)
            if attempt < retries - 1:
                print(f"    [reintento {attempt+1}/{retries}] Error: {e}. Esperando {wait:.0f}s...")
                time.sleep(wait)
            else:
                print(f"    [ERROR] Falló tras {retries} intentos: {e}")
                return None, None, None, False


def main():
    parser = argparse.ArgumentParser(description="Scraper RFCFYL — Castilla y León")
    parser.add_argument("--fast", action="store_true", help="Modo rápido (delay sin restricciones)")
    parser.add_argument("--delay", type=float, default=None, help="Delay global por petición (segundos)")
    parser.add_argument("--resume", action="store_true", help="Reanudar desde checkpoint")
    args = parser.parse_args()

    effective_delay = args.delay if args.delay is not None else REQUEST_DELAY

    OUTPUT_DIR.mkdir(exist_ok=True)
    CHECKPOINT_DIR.mkdir(exist_ok=True)

    print(f"=== Scraper {FEDERATION_NAME} (rfcylf.es) ===")
    print(f"Delay: {effective_delay}s | Modo: {'rápido' if args.fast else 'seguro (por defecto)'}")
    print()

    # Crear writer de pendientes
    pendientes = PendientesWriter("rfcylf")
    pendientes.open()

    # Cargar checkpoint si se reanuda
    processed_ids = load_checkpoint() if args.resume else set()
    if processed_ids:
        print(f"Reanudando: {len(processed_ids)} IDs ya procesados")

    session = _create_session()

    # Paso 1: Obtener todos los IDs de clubs desde el directorio
    print("Paso 1: Obteniendo IDs de clubs desde el directorio...")
    competitions = get_competitions(session)
    if not competitions:
        print("[ERROR] No se pudieron obtener competiciones. Puede que requiera login.")
        return

    print(f"  {len(competitions)} competiciones encontradas")

    all_club_entries = {}  # club_id → nombre provisional
    for comp_val, comp_name in competitions:
        print(f"\n  Competición: {comp_name} ({comp_val})")
        jitter_sleep(effective_delay)

        groups = get_groups_for_competition(session, comp_val)
        print(f"    {len(groups)} grupos")

        for grp_val, grp_name in groups:
            jitter_sleep(effective_delay)
            entries = scrape_directory_for_ids(session, comp_val, grp_val)
            new = 0
            for club_id, nombre in entries.items():
                if club_id not in all_club_entries:
                    all_club_entries[club_id] = nombre
                    new += 1
            if entries:
                print(f"      Grupo '{grp_name}': {len(entries)} clubs ({new} nuevos)")

    # Deducir IDs ya vistos
    pending_ids = [cid for cid in all_club_entries if cid not in processed_ids]
    print(f"\n  Total IDs únicos: {len(all_club_entries)}")
    print(f"  IDs ya procesados: {len(processed_ids)}")
    print(f"  IDs pendientes: {len(pending_ids)}")

    if not pending_ids:
        print("\n  Todos los clubs ya fueron procesados.")
        return

    # Paso 2: Scrapear la página de detalle de cada club pendiente (con rotación de IP)
    print(f"\nPaso 2: Scrapeando detalle de {len(pending_ids)} clubs...")

    # Modo append si estamos reanudando
    mode = "a" if args.resume and OUTPUT_FILE.exists() else "w"
    ok_count = 0
    err_count = 0
    block_count = 0
    consecutive_errors = 0

    with open(OUTPUT_FILE, mode, newline="", encoding="utf-8-sig", buffering=1) as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
        if mode == "w":
            writer.writeheader()

        idx = 0
        while idx < len(pending_ids):
            club_id = pending_ids[idx]
            nombre_prov = all_club_entries.get(club_id, f"Club {club_id}")
            nombre, telefono, email, blocked = scrape_club_detail(session, club_id)

            if blocked and HAS_IP_ROTATOR:
                block_count += 1
                print(f"\n⚠️  Bloqueo por cuota detectado (club {idx+1}/{len(pending_ids)}, ID {club_id}).")
                print(f"    Rotando IP via ADB...")
                try:
                    nueva_ip = rotate_ip_adb()
                    if nueva_ip:
                        print(f"    ✅ Nueva IP: {nueva_ip}. Reinicializando sesión y reintentando...")
                    else:
                        print(f"    ⚠️ No se pudo confirmar la nueva IP. Reintentando igualmente...")
                except RuntimeError as e:
                    print(f"    ❌ Error al rotar IP: {e}")
                    print(f"    Guardando checkpoint y pausando. Relanza con --resume tras reconectar el móvil.")
                    save_checkpoint(processed_ids)
                    print(f"\n{'='*60}")
                    print(f"TOTAL clubs (RFCFYL) — PARCIAL: OK={ok_count} ERR={err_count}")
                    return

                # Reinicializar sesión con la nueva IP
                session = _create_session()
                jitter_sleep(effective_delay)
                consecutive_errors = 0
                # No avanzamos idx — reintentamos el mismo club_id
                continue

            if nombre:
                writer.writerow({
                    "federacion": FEDERATION_NAME,
                    "nombre": nombre,
                    "telefono": telefono,
                    "email": email,
                })
                f.flush()
                ok_count += 1
                consecutive_errors = 0
                print(f"  [{idx+1}/{len(pending_ids)}] OK: {nombre}")
            else:
                err_count += 1
                consecutive_errors += 1
                print(f"  [{idx+1}/{len(pending_ids)}] ERR: {nombre_prov} (ID {club_id})")
                # Registrar en pendientes
                motivo = MOTIVO_BLOQUEO if blocked else MOTIVO_SIN_DATOS
                pendientes.write_error(FEDERATION_NAME, club_id, nombre_prov, motivo)

            processed_ids.add(club_id)

            # Guardar checkpoint cada 10 clubs
            if (idx + 1) % 10 == 0:
                save_checkpoint(processed_ids)
                print(f"  Progreso: {idx+1}/{len(pending_ids)} — OK:{ok_count} ERR:{err_count} BLK:{block_count}")

            # Cooldown si muchos errores consecutivos (sin rotación)
            if consecutive_errors >= 5 and not HAS_IP_ROTATOR:
                cooldown = 60
                print(f"  [!] {consecutive_errors} errores consecutivos — cooldown {cooldown}s...")
                time.sleep(cooldown)
                session = _create_session()
                consecutive_errors = 0

            idx += 1
            jitter_sleep(effective_delay)

        # Guardar checkpoint final
        save_checkpoint(processed_ids)

    pendientes.close()

    print(f"\n{'='*60}")
    print(f"TOTAL clubs (RFCFYL): OK={ok_count} ERR={err_count}")
    print(f"Archivo: {OUTPUT_FILE}")
    if pendientes.count > 0:
        print(f"⚠️  Clubes pendientes (no scrapeados): {pendientes.count}")
        print(f"   → {pendientes.filepath}")
        print(f"   Revisa este archivo para completar los datos manualmente.")


if __name__ == "__main__":
    main()