"""
scraper_isquad.py — Directorio público de clubes de la Comunidad Valenciana (iSquad / FFCV).

Extrae los clubes del listado de iSquad (https://www.isquad.es) y genera un CSV
completo alineado a las columnas de `clubes_crm` del CRM FutProtec.

Campos de la web -> columnas CRM:
  Nombre              -> nombre_club
  Presidente          -> persona_contacto (+ cargo_contacto='Presidente')
  Teléfono            -> telefono_movil si 6/7 + 9 dígitos (tiene_whatsapp=1), si no telefono_fijo
  Correo Electrónico  -> email
  Dirección           -> direccion (+ ciudad/provincia inferidos del texto)
  Código Postal       -> cp
  (fijo)              -> federacion = 'Federació de Futbol de la Comunitat Valenciana'
  (fijo)              -> estado_lead = '01 Sin Contactar'

Uso:
  python scraper_isquad.py                          # URL por defecto (id_ambito=3, CV)
  python scraper_isquad.py --url "..." --output output/clubs_isquad_cv.csv
  python scraper_isquad.py --html docs/_isquad.html # usar un HTML ya descargado
"""

import argparse
import csv
import html
import re
import sys
from pathlib import Path

import requests

DEFAULT_URL = "https://www.isquad.es/competiciones_publico_clubs.php?id_ambito=3&asdfg=999"
FEDERACION = "Federació de Futbol de la Comunitat Valenciana"
ESTADO_INICIAL = "01 Sin Contactar"
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")

# Columnas objetivo, alineadas con clubes_crm (sin id ni timestamps; se rellenan al importar)
COLUMNAS = [
    "nombre_club", "federacion", "persona_contacto", "cargo_contacto", "email",
    "telefono_fijo", "telefono_movil", "tiene_whatsapp", "estado_lead",
    "direccion", "cp", "ciudad", "provincia", "observaciones",
]

ITEMCLUB = "class='col-md-6 m-t-20 itemclub'"


def normaliza_telefono(tel):
    """Limpia un teléfono dejando solo dígitos."""
    return re.sub(r"\D", "", tel or "")


def arregla_encoding(texto):
    """Repara mojibake de la fuente iSquad (UTF-8 mal leído como Latin-1).

    Ej: 'CastellÃ³n' -> 'Castellón', 'AlbocÃ sser' -> 'Albocàsser'.
    Si la conversión falla, devuelve el texto original (no empeora).
    """
    if not texto:
        return texto
    try:
        return texto.encode("latin-1", errors="strict").decode("utf-8", errors="strict")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return texto


def extraer_campo(bloque, label):
    """Extrae el valor de un <strong>label:</strong>valor de un bloque de club."""
    m = re.search(re.escape(label) + r":\s*(?:&nbsp;)?</strong>(.*?)(?:<br|<div|$)", bloque, re.S)
    if not m:
        return ""
    valor = html.unescape(re.sub(r"<[^>]+>", "", m.group(1)).strip())
    return arregla_encoding(valor)


def inferir_ciudad_provincia(direccion):
    """Intenta extraer CP, ciudad y provincia del texto de la dirección.
    Patrón típico de iSquad: 'Calle, ... <CP>, <Ciudad>, <Provincia>'."""
    cp = ciudad = provincia = ""
    m = re.search(r"\b(\d{5})\b(?:\s*,\s*([^,]+?))(?:\s*,\s*([^,]+?))?\s*$", direccion or "")
    if m:
        cp = m.group(1)
        ciudad = m.group(2).strip()
        provincia = m.group(3).strip() if m.group(3) else ""
    return cp, ciudad, provincia

def parsear_html(html):
    """Devuelve la lista de dicts con las columnas objetivo."""
    bloques = re.split(ITEMCLUB, html)[1:]
    clubes = []
    for b in bloques:
        nombre = extraer_campo(b, "Nombre")
        if not nombre:
            continue
        direccion = extraer_campo(b, "Dirección")
        cp_web = extraer_campo(b, "Código Postal")
        telefono = normaliza_telefono(extraer_campo(b, "Teléfono"))
        email = extraer_campo(b, "Correo Electrónico")
        presidente = extraer_campo(b, "Presidente").split("|")[0].strip()

        # Teléfono: móvil si 6/7 + 9 dígitos (whatsapp); si no, fijo
        tel_movil = tel_fijo = ""
        if telefono and telefono.startswith(("6", "7")) and len(telefono) == 9:
            tel_movil = telefono
        elif telefono:
            tel_fijo = telefono

        cp, ciudad, provincia = inferir_ciudad_provincia(direccion)
        if not cp:
            cp = cp_web

        clubes.append({
            "nombre_club": nombre,
            "federacion": FEDERACION,
            "persona_contacto": presidente,
            "cargo_contacto": "Presidente" if presidente else "",
            "email": email,
            "telefono_fijo": tel_fijo,
            "telefono_movil": tel_movil,
            "tiene_whatsapp": 1 if tel_movil else 0,
            "estado_lead": ESTADO_INICIAL,
            "direccion": direccion,
            "cp": cp,
            "ciudad": ciudad,
            "provincia": provincia,
            "observaciones": "",
        })
    return clubes


def main():
    parser = argparse.ArgumentParser(description="Scraper directorio de clubes CV (iSquad/FFCV)")
    parser.add_argument("--url", default=DEFAULT_URL, help="URL del directorio")
    parser.add_argument("--html", default=None, help="Usar un HTML local en vez de descargar")
    parser.add_argument("--output", default=str(Path(__file__).parent / "output" / "clubs_isquad_cv.csv"))
    args = parser.parse_args()

    if args.html:
        html = Path(args.html).read_text(encoding="utf-8")
        print(f"[isquad] Leyendo HTML local: {args.html}")
    else:
        print(f"[isquad] Descargando {args.url} ...")
        r = requests.get(args.url, headers={"User-Agent": UA}, timeout=60)
        r.raise_for_status()
        html = r.text
        print(f"[isquad] HTTP {r.status_code} | bytes: {len(r.content)}")

    clubes = parsear_html(html)
    if not clubes:
        print("[isquad] ERROR: no se extrajo ningún club (¿cambió el HTML?).")
        sys.exit(1)

    salida = Path(args.output)
    salida.parent.mkdir(exist_ok=True)
    with open(salida, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=COLUMNAS)
        writer.writeheader()
        writer.writerows(clubes)

    # Resumen de calidad y duplicados
    con_email = sum(1 for c in clubes if c["email"])
    con_tel = sum(1 for c in clubes if c["telefono_movil"] or c["telefono_fijo"])
    con_pres = sum(1 for c in clubes if c["persona_contacto"])
    emails = [c["email"].lower() for c in clubes if c["email"]]
    dups = len(emails) - len(set(emails))

    print(f"\n[isquad] Clubes extraídos: {len(clubes)}")
    print(f"[isquad] Con email: {con_email} · con teléfono: {con_tel} · con presidente: {con_pres}")
    print(f"[isquad] Emails duplicados internos: {dups}")
    print(f"[isquad] Guardado en: {salida}")


if __name__ == "__main__":
    main()
