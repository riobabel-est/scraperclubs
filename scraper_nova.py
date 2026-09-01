"""Scraper para federaciones en plataforma NOVA (novanet.es).

Flujo:
  1. Paginar NFG_Clubes → recopilar todos los IDs de clubs
  2. Para cada ID, fetchear NFG_VerClub → extraer nombre, teléfono, email
  3. Guardar en CSV con columnas: federacion, nombre, telefono, email

Uso:
  python scraper_nova.py                    # modo seguro (1 worker, delay 2s)
  python scraper_nova.py --fast             # modo rápido
  python scraper_nova.py --fed "Andalucía"  # una sola federación
  python scraper_nova.py --resume           # reanudar desde checkpoint
"""

import csv
import json
import re
import sys
import time
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import requests
from bs4 import BeautifulSoup

# curl_cffi emula el TLS fingerprint de Chrome real para evadir bloqueos anti-bot
try:
    from curl_cffi import requests as curl_requests
    HAS_CURL_CFFI = True
except ImportError:
    HAS_CURL_CFFI = False

sys.path.insert(0, str(Path(__file__).parent))
from config import (
    NOVA_FEDERATIONS, PAGE_SIZE, REQUEST_DELAY, MAX_RETRIES, TIMEOUT,
    get_random_user_agent, jitter_sleep, SCRAPERAPI_KEY,
)

# Módulo de pendientes para registrar clubes no scrapeados
from pendientes import PendientesWriter, MOTIVO_SIN_DATOS, MOTIVO_BLOQUEO, MOTIVO_LOGIN, MOTIVO_TIMEOUT

# Rotador de IP via ADB (Modo Avión en Android) — DESHABILITADO, solo ScraperAPI
HAS_IP_ROTATOR = False

# Variable global para indicar si la federación actual usa ScraperAPI
_use_scraperapi = False

# Flag global: True → forzar acceso directo (sin ScraperAPI) aunque la federación
# tenga use_scraperapi. Se activa con --directo.
_FORCE_DIRECTO = False

OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"
OUTPUT_FILE = OUTPUT_DIR / "clubs_nova.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

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


def _make_headers(domain=None):
    """Construye headers con Referer/Origin basado en el dominio del servidor."""
    h = {**BASE_HEADERS, "User-Agent": get_random_user_agent()}
    if domain:
        h["Referer"] = f"{domain}/pnfg/NPcd/NFG_Clubes"
        h["Origin"] = domain
    return h


def _create_session(use_tls_impersonation=False):
    """Crea una sesión HTTP. Prioriza curl_cffi para TLS impersonation.
    Conexión directa sin proxy (Tor timeoutea)."""
    if use_tls_impersonation and HAS_CURL_CFFI:
        print("  [tls] Usando curl_cffi con impersonate=chrome120")
        return curl_requests.Session(impersonate="chrome120")
    return requests.Session()


def _scraperapi_url(url, render=True):
    """Envuelve una URL a través de ScraperAPI.
    Por defecto usa JS rendering (render=true) porque NOVA rechaza peticiones sin cookies.
    render=False → proxy rápido (solo para sitios que no bloquean cookies)."""
    from urllib.parse import quote
    base = f"http://api.scraperapi.com?api_key={SCRAPERAPI_KEY}&url={quote(url, safe='')}"
    return base + "&render=true" if render else base


def _http_get(session, url, params=None, domain=None, timeout=TIMEOUT):
    """GET compatible con requests.Session y curl_cffi.Session."""
    if _use_scraperapi and domain:
        # Construir URL completa con params
        from urllib.parse import urlencode, urlparse, urlunparse, parse_qs
        parsed = list(urlparse(url))
        if params:
            existing = parse_qs(parsed[4])
            existing.update({k: str(v) for k, v in params.items()})
            parsed[4] = urlencode(existing, doseq=True)
        full_url = urlunparse(parsed)
        proxy_url = _scraperapi_url(full_url)
        resp = session.get(proxy_url, headers=_make_headers(), timeout=timeout)
    else:
        resp = session.get(url, params=params, headers=_make_headers(domain), timeout=timeout)
    resp.raise_for_status()
    return resp


def init_session(session, domain):
    url = f"{domain}/pnfg/NPcd/NFG_Clubes"
    params = {"cod_primaria": "1000118", "NPcd_Page": 1, "Buscar": "1", "NPcd_PageLines": 1}
    try:
        _http_get(session, url, params=params, domain=domain)
    except Exception:
        pass


def get_with_retry(session, url, params=None, retries=MAX_RETRIES, domain=None, base_delay=2.0):
    for attempt in range(retries):
        try:
            # Si usamos ScraperAPI, envolver la URL
            if _use_scraperapi and domain:
                from urllib.parse import urlencode, urlparse, urlunparse, parse_qs, quote
                parsed = list(urlparse(url))
                if params:
                    existing = parse_qs(parsed[4])
                    existing.update({k: str(v) for k, v in params.items()})
                    parsed[4] = urlencode(existing, doseq=True)
                full_url = urlunparse(parsed)
                proxy_url = _scraperapi_url(full_url)
                resp = session.get(proxy_url, headers=_make_headers(), timeout=TIMEOUT)
            else:
                resp = session.get(url, params=params, headers=_make_headers(domain), timeout=TIMEOUT)
            resp.raise_for_status()
            if len(resp.text) == 0 and domain:
                wait = min(base_delay * (2 ** attempt), 120)
                if attempt < retries - 1:
                    print(f"    [vacío] Bloqueo detectado. Re-inicializando sesión y esperando {wait:.0f}s (intento {attempt+1}/{retries})...")
                    init_session(session, domain)
                    time.sleep(wait)
                    continue
                print(f"    [vacío] Bloqueo persistente tras {retries} intentos. Rindiéndose.")
                return None
            return resp
        except requests.RequestException as e:
            wait = min(base_delay * (2 ** attempt), 120)
            if attempt < retries - 1:
                print(f"    [reintento {attempt+1}/{retries}] Error: {e}. Esperando {wait:.0f}s...")
                time.sleep(wait)
            else:
                print(f"    [ERROR] Falló después de {retries} intentos: {e}")
                return None


def get_club_ids_from_page(soup, domain, cod_primaria):
    club_ids = []
    for link in soup.find_all("a", href=re.compile(r"javascript:Ver\(")):
        match = re.search(r"Ver\((\d+)\)", link["href"])
        if match:
            club_ids.append(match.group(1))
    text = soup.get_text()
    total_match = re.search(r"Total\s+(\d+)\s+Registros", text, re.IGNORECASE)
    total = int(total_match.group(1)) if total_match else None
    return club_ids, total


def get_all_club_ids(session, domain, cod_primaria, fed_name, request_delay=REQUEST_DELAY):
    all_ids = []
    page = 1
    page_failures = 0
    empty_page_retries = 0
    max_page_failures = 5
    max_empty_page_retries = 4

    while True:
        url = f"{domain}/pnfg/NPcd/NFG_Clubes"
        params = {"cod_primaria": cod_primaria, "NPcd_Page": page, "Buscar": "1", "NPcd_PageLines": PAGE_SIZE}
        print(f"  [{fed_name}] Página {page} de clubs...")
        resp = get_with_retry(session, url, params=params, domain=domain)
        if not resp:
            page_failures += 1
            if page_failures <= max_page_failures:
                print(f"  [{fed_name}] Fallo temporal en página {page}. Reintento {page_failures}/{max_page_failures}...")
                init_session(session, domain)
                time.sleep(max(2.0, request_delay))
                continue
            print(f"  [{fed_name}] Error persistente en página {page}. Se detiene paginación.")
            break
        page_failures = 0

        soup = BeautifulSoup(resp.text, "lxml")
        ids, total = get_club_ids_from_page(soup, domain, cod_primaria)

        if not ids:
            if total and len(all_ids) < total and empty_page_retries < max_empty_page_retries:
                empty_page_retries += 1
                print(f"  [{fed_name}] Página {page} vacía antes de completar total ({len(all_ids)}/{total}). Reintento {empty_page_retries}/{max_empty_page_retries}...")
                init_session(session, domain)
                time.sleep(max(2.0, request_delay))
                continue
            print(f"  [{fed_name}] Sin más IDs en página {page}. Fin.")
            break
        empty_page_retries = 0

        all_ids.extend(ids)
        total_pages = (total // PAGE_SIZE) + (1 if total % PAGE_SIZE else 0) if total else None
        if total_pages:
            print(f"  [{fed_name}] Página {page}/{total_pages} — {len(all_ids)}/{total} IDs")
        if total and len(all_ids) >= total:
            break
        page += 1
        jitter_sleep(request_delay)

    seen = set()
    unique_ids = []
    for id_ in all_ids:
        if id_ not in seen:
            seen.add(id_)
            unique_ids.append(id_)

    print(f"  [{fed_name}] Total IDs únicos: {len(unique_ids)}")
    return unique_ids


def parse_club_detail(soup):
    """
    Extrae nombre, teléfono y email de la página NFG_VerClub.
    Soporta dos formatos:
      - Formato A: nombre en <h1>/<h2>, datos en <strong> + texto inline
      - Formato B: nombre como primer <h5> (Código: XXXXX), datos en pares <h5>/<span>
    """
    nombre = ""
    telefono = ""
    email = ""

    # --- FORMATO A: buscar nombre en headings (h1-h4) y datos en <strong> ---
    for tag in ["h1", "h2", "h3", "h4"]:
        for el in soup.find_all(tag):
            text = el.get_text(strip=True)
            if text and len(text) > 3 and not any(
                x in text.lower() for x in ["nova", "gestión", "intranet",
                                             "federación", "consulta", "datos",
                                             "equipación", "correspondencia",
                                             "otros datos", "privacidad",
                                             "cookies", "aceptar",
                                             "consentimiento", "política de",
                                             "socios almacenamos"]
            ):
                nombre = text
                break
        if nombre:
            break

    # Extraer teléfono y email de labels <strong> (formato A)
    for strong in soup.find_all("strong"):
        label = strong.get_text(strip=True).rstrip(":").strip()
        raw = strong.parent.get_text(separator=" ", strip=True)

        if re.search(r"Teléfono", label, re.IGNORECASE):
            value = re.sub(r"Teléfonos?\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
            if not value:
                next_sib = strong.next_sibling
                if next_sib:
                    value = next_sib.get_text(strip=True) if hasattr(next_sib, 'get_text') else str(next_sib).strip()
            if value and re.search(r"\d", value):
                telefono = value
        elif re.search(r"Email", label, re.IGNORECASE):
            value = re.sub(r"Email\s*:?\s*", "", raw, flags=re.IGNORECASE).strip()
            if not value:
                next_sib = strong.next_sibling
                if next_sib:
                    value = next_sib.get_text(strip=True) if hasattr(next_sib, 'get_text') else str(next_sib).strip()
            if value and "@" in value:
                email = value

    # --- FORMATO B: datos en <h5><strong>Label</strong> VALOR o <h5> + <span> ---
    # BUG FIX #1: Buscar SIEMPRE los 3 campos independientemente (no con OR)
    for h5 in soup.find_all("h5"):
        strong = h5.find("strong")
        if strong:
            label = strong.get_text(strip=True).rstrip(":").strip()
            full_text = h5.get_text(strip=True)
            valor = full_text.replace(strong.get_text(strip=True), "").strip()
            valor = valor.replace("\xa0", " ").strip()

            if re.search(r"Teléfono", label, re.IGNORECASE) and not telefono:
                if valor and re.search(r"\d", valor):
                    telefono = valor
                # BUG FIX #2: buscar en <span> hermano si el valor no está inline (rfaf.es)
                elif not valor or not re.search(r"\d", valor):
                    # Buscar siguiente hermano con texto
                    for sibling in h5.find_next_siblings():
                        if sibling.name in ("span", "p", "div") and sibling.get_text(strip=True):
                            txt = sibling.get_text(strip=True).replace("\xa0", " ")
                            if re.search(r"\d", txt):
                                telefono = txt
                                break
            elif re.search(r"Email", label, re.IGNORECASE) and not email:
                if valor and "@" in valor:
                    email = valor
                elif not valor or "@" not in valor:
                    for sibling in h5.find_next_siblings():
                        if sibling.name in ("span", "p", "div") and sibling.get_text(strip=True):
                            txt = sibling.get_text(strip=True).replace("\xa0", " ")
                            if "@" in txt:
                                email = txt
                                break
            elif label == "Código:" and not nombre:
                if valor:
                    nombre = f"Club {valor}"
        else:
            # Sin <strong>: etiquetas h5 planas (otras federaciones)
            label = h5.get_text(strip=True).rstrip(":").strip()
            next_el = h5.find_next_sibling()
            if next_el:
                valor = next_el.get_text(strip=True) if hasattr(next_el, 'get_text') else str(next_el).strip()
            else:
                valor = ""
            if re.search(r"Teléfono", label, re.IGNORECASE) and not telefono and re.search(r"\d", valor):
                telefono = valor
            elif re.search(r"Email", label, re.IGNORECASE) and not email and "@" in valor:
                email = valor

    # Fallback: buscar mailto
    if not email:
        mailto = soup.find("a", href=re.compile(r"^mailto:", re.I))
        if mailto:
            email = re.sub(r"^mailto:", "", mailto["href"], flags=re.I).strip()

    return nombre, telefono, email


def _detect_block(resp_text, resp_url):
    """Detecta si la respuesta indica bloqueo por cuota (39 bytes o redirección a NLogin)."""
    if not resp_text and not resp_url:
        return False
    # Respuesta vacía/capada (típico bloqueo NOVA: ~39 bytes)
    if resp_text is not None and len(resp_text) <= 39:
        return True
    # Redirección a login
    if resp_url and "NLogin" in resp_url:
        return True
    return False


def scrape_club(session, domain, cod_primaria, club_id, use_rotate=False):
    """Scrapea el detalle de un club. Si use_rotate=True, el caller maneja la rotación de IP en caso de bloqueo."""
    url = f"{domain}/pnfg/NPcd/NFG_VerClub"
    params = {"cod_primaria": cod_primaria, "codigo_club": club_id}
    resp = get_with_retry(session, url, params=params, domain=domain)
    if not resp:
        # Fallback via ScraperAPI
        if SCRAPERAPI_KEY:
            print(f"    [scraperapi] Intentando via proxy para ID {club_id}...")
            from urllib.parse import urlencode, urlparse, urlunparse, parse_qs
            parsed = list(urlparse(url))
            if params:
                existing = parse_qs(parsed[4])
                existing.update({k: str(v) for k, v in params.items()})
                parsed[4] = urlencode(existing, doseq=True)
            full_url = urlunparse(parsed)
            proxy_url = _scraperapi_url(full_url)
            try:
                r = requests.get(proxy_url, headers=_make_headers(), timeout=TIMEOUT + 15)
                r.raise_for_status()
                if len(r.text) > 0:
                    resp = r
            except Exception:
                pass
        if not resp:
            # Si get_with_retry se rindió, asumimos bloqueo y forzamos rotación
            return None, None, None, HAS_IP_ROTATOR

    # Detección de bloqueo por cuota
    if _detect_block(resp.text, resp.url):
        return None, None, None, True  # blocked=True

    soup = BeautifulSoup(resp.text, "lxml")
    if soup.find("input", {"name": "clave_acceso"}):
        print(f"    [WARN] Requiere login — ID {club_id}")
        return None, None, None, False

    nombre, telefono, email = parse_club_detail(soup)
    # Descartar emails institucionales de la propia federación (p.ej. contacto@ffcm.es)
    # que se cuelan vía fallback mailto cuando el club no publica email.
    if email and domain:
        from urllib.parse import urlparse
        fed_domain = urlparse(domain).netloc.replace("www.", "").lower()
        if email.lower().endswith("@" + fed_domain):
            email = ""
    return nombre, telefono, email, False


def _safe_fed_name(fed_name):
    """Normaliza nombre de federación para nombres de archivo (solo espacios → _)."""
    return fed_name.strip().replace(" ", "_")


def load_checkpoint(fed_name):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = CHECKPOINT_DIR / f"{_safe_fed_name(fed_name)}.json"
    if path.exists():
        with open(path, "r", encoding="utf-8") as f:
            return set(json.load(f))
    return set()


def save_checkpoint(fed_name, processed_ids):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = CHECKPOINT_DIR / f"{_safe_fed_name(fed_name)}.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(list(processed_ids), f)


def load_all_ids(fed_name):
    """Carga la lista completa de IDs de una federación (guardada tras paginar)."""
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = CHECKPOINT_DIR / f"{_safe_fed_name(fed_name)}_all_ids.json"
    if path.exists():
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    return None


def save_all_ids(fed_name, all_ids):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = CHECKPOINT_DIR / f"{_safe_fed_name(fed_name)}_all_ids.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(all_ids, f)


def _federation_progress_file(federation_name):
    safe = re.sub(r"[^a-zA-Z0-9_-]+", "_", federation_name.strip().lower())
    return CHECKPOINT_DIR / f"progress_{safe}.json"


def save_progress(federation_name, total_ids, processed_count, ok_count, err_count):
    CHECKPOINT_DIR.mkdir(exist_ok=True)
    path = _federation_progress_file(federation_name)
    payload = {
        "federacion": federation_name,
        "total_ids": total_ids,
        "procesados": processed_count,
        "ok": ok_count,
        "errores": err_count,
        "pendientes": max(total_ids - processed_count, 0),
        "updated_at_epoch": int(time.time()),
    }
    with open(path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)


def scrape_federation(federation, writer, resume=False, delay_override=None, flush_callback=None, start_page_override=None, pendientes_writer=None):
    name = federation["name"]
    domain = federation["domain"]
    cod_primaria = federation["cod_primaria"]
    delay = delay_override if delay_override is not None else federation.get("delay", REQUEST_DELAY)

    print(f"\n{'='*60}")
    print(f"Federación: {name} ({domain})")
    print(f"{'='*60}")

    if federation.get("skip", False):
        print(f"  [SKIP] Federación marcada como skip — saltando.")
        return 0

    use_tls = federation.get("tls_impersonate", False)
    use_scraper = federation.get("use_scraperapi", False)
    
    global _use_scraperapi
    if _FORCE_DIRECTO:
        _use_scraperapi = False
        print("  [directo] Modo acceso directo (sin ScraperAPI) — curl_cffi/requests")
    else:
        _use_scraperapi = use_scraper and bool(SCRAPERAPI_KEY)
        if _use_scraperapi:
            print("  [scraperapi] Usando ScraperAPI como proxy anti-bloqueo")
    
    session = _create_session(use_tls)
    processed = load_checkpoint(name) if resume else set()
    if processed:
        print(f"  Reanudando: {len(processed)} IDs ya procesados")

    # Paso 1 + 2 combinados: paginar y extraer detalles página por página
    ok_count = 0
    err_count = 0
    block_count = 0
    all_ids = load_all_ids(name) if resume else []
    if not all_ids:
        all_ids = []
    page = start_page_override if start_page_override else 1
    page_failures = 0
    max_page_failures = 5

    # Si reanudamos, saltar páginas ya procesadas
    if resume and processed and not start_page_override:
        # Calcular desde qué página continuar: cada página tiene ~PAGE_SIZE IDs
        page = (len(all_ids) // PAGE_SIZE) + 1
        print(f"  [resume] Continuando desde página {page} ({len(processed)} IDs ya procesados)")

    if start_page_override:
        print(f"  [start] Iniciando desde página {page}")

    while True:
        # --- Obtener IDs de esta página ---
        url = f"{domain}/pnfg/NPcd/NFG_Clubes"
        params = {"cod_primaria": cod_primaria, "NPcd_Page": page, "Buscar": "1", "NPcd_PageLines": PAGE_SIZE}
        print(f"\n  [{name}] 📄 Página {page} — obteniendo IDs...")
        resp = get_with_retry(session, url, params=params, domain=domain)
        if not resp:
            page_failures += 1
            if page_failures <= max_page_failures:
                print(f"  [{name}] Fallo temporal. Reintento {page_failures}/{max_page_failures}...")
                init_session(session, domain)
                time.sleep(max(2.0, delay))
                continue
            print(f"  [{name}] Error persistente. Fin de paginación.")
            break
        page_failures = 0

        soup = BeautifulSoup(resp.text, "lxml")
        page_ids, total = get_club_ids_from_page(soup, domain, cod_primaria)
        if not page_ids:
            print(f"  [{name}] Sin IDs en página {page}. Fin.")
            break

        all_ids.extend(page_ids)
        total_pages = (total // PAGE_SIZE) + (1 if total % PAGE_SIZE else 0) if total else None
        total_str = f"página {page}/{total_pages}" if total_pages else f"página {page}"
        print(f"  [{name}] {total_str} — {len(page_ids)} IDs en esta página | total acumulado: {len(all_ids)}")

        # --- Extraer detalles de los IDs de esta página ---
        pending_page = [id_ for id_ in page_ids if id_ not in processed]
        if pending_page:
            print(f"  [{name}] 🔍 Extrayendo {len(pending_page)} clubes de esta página...")
        for j, club_id in enumerate(pending_page):
            nombre, telefono, email, blocked = scrape_club(session, domain, cod_primaria, club_id)

            if blocked and HAS_IP_ROTATOR:
                block_count += 1
                print(f"  [{name}] ⚠️ Bloqueo ID {club_id}. Rotando IP...")
                try:
                    rotate_ip_adb()
                except RuntimeError:
                    save_checkpoint(name, processed)
                    save_all_ids(name, all_ids)
                    return ok_count
                session = _create_session(use_tls)
                init_session(session, domain)
                jitter_sleep(delay)
                # Reintentar este ID
                nombre, telefono, email, blocked = scrape_club(session, domain, cod_primaria, club_id)
                if blocked:
                    err_count += 1
                    processed.add(club_id)
                    continue

            if nombre:
                writer.writerow({"federacion": name, "nombre": nombre, "telefono": telefono, "email": email})
                if flush_callback:
                    flush_callback()
                ok_count += 1
                print(f"    ✅ [{j+1}/{len(pending_page)}] {nombre}")
            else:
                err_count += 1
                print(f"    ❌ [{j+1}/{len(pending_page)}] sin datos — ID {club_id}")
                # Registrar en pendientes para completar manualmente
                if pendientes_writer:
                    motivo = MOTIVO_BLOQUEO if blocked else MOTIVO_SIN_DATOS
                    pendientes_writer.write_error(name, club_id, "", motivo)
            processed.add(club_id)
            jitter_sleep(delay)

        # Guardar checkpoint y all_ids tras cada página
        save_checkpoint(name, processed)
        save_all_ids(name, all_ids)
        print(f"  [{name}] ✅ Página {page} completada — OK:{ok_count} ERR:{err_count} | Total acumulado: {len(all_ids)} IDs")

        if total and len(all_ids) >= total:
            break
        page += 1

    save_checkpoint(name, processed)
    save_all_ids(name, all_ids)
    print(f"\n  [{name}] 🏁 COMPLETADO — OK:{ok_count} ERR:{err_count} | Total IDs: {len(all_ids)}")
    return ok_count


def _federation_output_file(federation_name):
    safe = re.sub(r"[^a-zA-Z0-9_-]+", "_", federation_name.strip().lower())
    return OUTPUT_DIR / f"clubs_nova_{safe}.csv"


def _merge_federation_csvs(source_files, target_file):
    with open(target_file, "w", newline="", encoding="utf-8-sig") as out_f:
        writer = csv.DictWriter(out_f, fieldnames=CSV_HEADERS)
        writer.writeheader()
        for src in source_files:
            if not src.exists():
                continue
            with open(src, "r", newline="", encoding="utf-8-sig") as in_f:
                reader = csv.DictReader(in_f)
                for row in reader:
                    # Limpiar NBSP y asegurar solo las 4 columnas esperadas
                    clean = {}
                    for k in CSV_HEADERS:
                        v = row.get(k, "")
                        if v:
                            v = v.replace("\xa0", " ").replace("\u00a0", " ")
                        clean[k] = v
                    # Si todas las columnas están vacías, saltar
                    if not any(clean.values()):
                        continue
                    writer.writerow(clean)


def main():
    parser = argparse.ArgumentParser(description="Scraper NOVA de clubes de fútbol")
    parser.add_argument("--fed", metavar="NOMBRE", help="Scrapear solo esta federación")
    parser.add_argument("--resume", action="store_true", help="Reanudar desde checkpoints")
    parser.add_argument("--list-ids-only", action="store_true", help="Solo listar IDs sin detalles")
    parser.add_argument("--workers", type=int, default=1, help="Federaciones en paralelo")
    parser.add_argument("--delay", type=float, default=None, help="Delay global por petición")
    parser.add_argument("--fast", action="store_true", help="Modo rápido")
    parser.add_argument("--start-page", type=int, default=1, help="Comenzar desde esta página")
    parser.add_argument("--directo", action="store_true",
                        help="Acceso directo sin ScraperAPI (curl_cffi/requests). Útil si el crédito de proxy está agotado.")
    args = parser.parse_args()

    global _FORCE_DIRECTO
    _FORCE_DIRECTO = args.directo
    if args.directo:
        _use_scraperapi = False
        print("  [directo] Flag activado: se ignorará ScraperAPI en todas las federaciones")

    OUTPUT_DIR.mkdir(exist_ok=True)

    feds_to_scrape = NOVA_FEDERATIONS
    if args.fed:
        feds_to_scrape = [f for f in NOVA_FEDERATIONS if f["name"].lower() == args.fed.lower()]
        if not feds_to_scrape:
            print(f"Federación '{args.fed}' no encontrada. Disponibles:")
            for f in NOVA_FEDERATIONS:
                print(f"  - {f['name']}")
            sys.exit(1)

    if args.fast:
        effective_workers = max(1, args.workers)
        effective_delay = args.delay
    else:
        effective_workers = 1
        effective_delay = args.delay if args.delay is not None else 2.0

    if args.list_ids_only:
        for fed in feds_to_scrape:
            session = requests.Session()
            ids = get_all_club_ids(session, fed["domain"], fed["cod_primaria"], fed["name"],
                                   request_delay=effective_delay if effective_delay is not None else REQUEST_DELAY)
            print(f"{fed['name']}: {len(ids)} IDs")
        return

    # Crear writer de pendientes para esta ejecución
    pendientes = PendientesWriter("nova")
    pendientes.open()

    if effective_workers == 1:
        source_files = []
        total_clubs = 0
        for fed in feds_to_scrape:
            fed_output = _federation_output_file(fed["name"])
            mode = "a" if fed_output.exists() else "w"
            write_header = not fed_output.exists()
            with open(fed_output, mode, newline="", encoding="utf-8-sig", buffering=1) as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                if write_header:
                    writer.writeheader()
                count = scrape_federation(fed, writer, resume=args.resume,
                                          delay_override=effective_delay, flush_callback=f.flush,
                                          start_page_override=args.start_page,
                                          pendientes_writer=pendientes)
                total_clubs += count
                f.flush()
            source_files.append(fed_output)
        _merge_federation_csvs(source_files, OUTPUT_FILE)
    else:
        print(f"Ejecutando en paralelo por federación (workers={effective_workers})")
        source_files = []
        total_clubs = 0

        def run_one_federation(fed):
            fed_output = _federation_output_file(fed["name"])
            mode = "a" if args.resume and fed_output.exists() else "w"
            with open(fed_output, mode, newline="", encoding="utf-8-sig", buffering=1) as f:
                writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
                if mode == "w":
                    writer.writeheader()
                count = scrape_federation(fed, writer, resume=args.resume,
                                          delay_override=effective_delay, flush_callback=f.flush,
                                          pendientes_writer=pendientes)
            return fed["name"], count, fed_output

        with ThreadPoolExecutor(max_workers=effective_workers) as executor:
            futures = [executor.submit(run_one_federation, fed) for fed in feds_to_scrape]
            for fut in as_completed(futures):
                fed_name, count, fed_output = fut.result()
                source_files.append(fed_output)
                total_clubs += count
                print(f"[{fed_name}] finalizada en paralelo: {count} clubs")
        _merge_federation_csvs(source_files, OUTPUT_FILE)

    pendientes.close()

    print(f"\n{'='*60}")
    print(f"TOTAL clubs guardados: {total_clubs}")
    print(f"Archivo: {OUTPUT_FILE}")
    if pendientes.count > 0:
        print(f"⚠️  Clubes pendientes (no scrapeados): {pendientes.count}")
        print(f"   → {pendientes.filepath}")
        print(f"   Revisa este archivo para completar los datos manualmente.")


if __name__ == "__main__":
    main()