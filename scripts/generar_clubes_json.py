#!/usr/bin/env python3
"""
Script para generar clubes.json a partir de los CSVs de la carpeta export y output/clean.
Aplica mapeo de federaciones, limpieza de datos y deduplicación.
"""

import csv
import json
import os
import re

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Archivos de entrada — solo los dos CSVs de la carpeta export
INPUT_FILES = [
    os.path.join(BASE_DIR, "export", "lote_1_dominios_clubes.csv"),
    os.path.join(BASE_DIR, "export", "lote_2_dominios_genericos.csv"),
]

# Archivo de salida
OUTPUT_FILE = os.path.join(BASE_DIR, "clubes.json")

# ── MAPEO DE REGIÓN/PROVINCIA → NOMBRE OFICIAL DE FEDERACIÓN ──
FEDERACION_MAP = {
    # Andalucía
    "Andalucía": "Real Federación Andaluza de Fútbol",
    "Andalucia": "Real Federación Andaluza de Fútbol",
    "Sevilla": "Real Federación Andaluza de Fútbol",
    "Málaga": "Real Federación Andaluza de Fútbol",
    "Malaga": "Real Federación Andaluza de Fútbol",
    "Cádiz": "Real Federación Andaluza de Fútbol",
    "Cadiz": "Real Federación Andaluza de Fútbol",
    "Granada": "Real Federación Andaluza de Fútbol",
    "Córdoba": "Real Federación Andaluza de Fútbol",
    "Cordoba": "Real Federación Andaluza de Fútbol",
    "Almería": "Real Federación Andaluza de Fútbol",
    "Almeria": "Real Federación Andaluza de Fútbol",
    "Jaén": "Real Federación Andaluza de Fútbol",
    "Jaen": "Real Federación Andaluza de Fútbol",
    "Huelva": "Real Federación Andaluza de Fútbol",
    # Madrid
    "Madrid": "Real Federación de Fútbol de Madrid",
    # Murcia
    "Murcia": "Federación de Fútbol de la Región de Murcia",
    # La Rioja
    "La Rioja": "Federación Riojana de Fútbol",
    # Aragón
    "Aragón": "Real Federación Aragonesa de Fútbol",
    "Aragon": "Real Federación Aragonesa de Fútbol",
    "Zaragoza": "Real Federación Aragonesa de Fútbol",
    "Huesca": "Real Federación Aragonesa de Fútbol",
    "Teruel": "Real Federación Aragonesa de Fútbol",
    # Cataluña
    "Barcelona": "Federació Catalana de Futbol",
    "Girona": "Federació Catalana de Futbol",
    "Lleida": "Federació Catalana de Futbol",
    "Tarragona": "Federació Catalana de Futbol",
    "Cataluña": "Federació Catalana de Futbol",
    "Catalunya": "Federació Catalana de Futbol",
    # Castilla y León
    "Castilla y León": "Real Federación de Castilla y León de Fútbol",
    "Castilla y Leon": "Real Federación de Castilla y León de Fútbol",
    "Castilla-León": "Real Federación de Castilla y León de Fútbol",
    "Burgos": "Real Federación de Castilla y León de Fútbol",
    "León": "Real Federación de Castilla y León de Fútbol",
    "Leon": "Real Federación de Castilla y León de Fútbol",
    "Palencia": "Real Federación de Castilla y León de Fútbol",
    "Salamanca": "Real Federación de Castilla y León de Fútbol",
    "Segovia": "Real Federación de Castilla y León de Fútbol",
    "Soria": "Real Federación de Castilla y León de Fútbol",
    "Valladolid": "Real Federación de Castilla y León de Fútbol",
    "Zamora": "Real Federación de Castilla y León de Fútbol",
    "Ávila": "Real Federación de Castilla y León de Fútbol",
    "Avila": "Real Federación de Castilla y León de Fútbol",
    "CYL": "Real Federación de Castilla y León de Fútbol",
    # Valencia
    "Valencia": "Federació de Futbol de la Comunitat Valenciana",
    "Alicante": "Federació de Futbol de la Comunitat Valenciana",
    "Castellón": "Federació de Futbol de la Comunitat Valenciana",
    "Castellon": "Federació de Futbol de la Comunitat Valenciana",
    # Asturias
    "Asturias": "Real Federación de Fútbol del Principado de Asturias",
    # Cantabria
    "Cantabria": "Real Federación Cántabra de Fútbol",
    # Navarra
    "Navarra": "Federación Navarra de Fútbol",
    # País Vasco
    "Álava": "Federación Vasca de Fútbol",
    "Alava": "Federación Vasca de Fútbol",
    "Bizkaia": "Federación Vasca de Fútbol",
    "Gipuzkoa": "Federación Vasca de Fútbol",
    "País Vasco": "Federación Vasca de Fútbol",
    "Pais Vasco": "Federación Vasca de Fútbol",
    # Extremadura
    "Extremadura": "Federación Extremeña de Fútbol",
    "Badajoz": "Federación Extremeña de Fútbol",
    "Cáceres": "Federación Extremeña de Fútbol",
    "Caceres": "Federación Extremeña de Fútbol",
    # Castilla-La Mancha
    "Toledo": "Federación de Fútbol de Castilla-La Mancha",
    "Ciudad Real": "Federación de Fútbol de Castilla-La Mancha",
    "Albacete": "Federación de Fútbol de Castilla-La Mancha",
    "Cuenca": "Federación de Fútbol de Castilla-La Mancha",
    "Guadalajara": "Federación de Fútbol de Castilla-La Mancha",
    # Islas Baleares
    "Islas Baleares": "Federació de Futbol de les Illes Balears",
    "Baleares": "Federació de Futbol de les Illes Balears",
    # Canarias
    "Las Palmas": "Federación Canaria de Fútbol",
    "Santa Cruz de Tenerife": "Federación Canaria de Fútbol",
    "Canarias": "Federación Canaria de Fútbol",
    # Galicia
    "Galicia": "Federación Gallega de Fútbol",
    "A Coruña": "Federación Gallega de Fútbol",
    "A Coruna": "Federación Gallega de Fútbol",
    "Lugo": "Federación Gallega de Fútbol",
    "Ourense": "Federación Gallega de Fútbol",
    "Pontevedra": "Federación Gallega de Fútbol",
}


def get_federacion(region: str) -> str:
    """Devuelve el nombre oficial de la federación según la región/provincia."""
    key = region.strip()
    if key in FEDERACION_MAP:
        return FEDERACION_MAP[key]
    # Fallback: intentar sin tilde
    key_sin_tilde = key.replace("á", "a").replace("é", "e").replace("í", "i").replace("ó", "o").replace("ú", "u")
    if key_sin_tilde in FEDERACION_MAP:
        return FEDERACION_MAP[key_sin_tilde]
    # Default según la regla
    return f"la federación territorial de {key}"


def clean_club_name(name: str) -> str:
    """Limpia el nombre del club: elimina espacios dobles y normaliza."""
    if not name:
        return ""
    # Reemplazar espacios múltiples (incluyendo tabs, non-breaking spaces) por un solo espacio
    name = re.sub(r'\s+', ' ', name.strip())
    return name


def clean_phone(phone: str) -> str:
    """Normaliza el formato de teléfono manteniendo separadores legibles."""
    if not phone:
        return ""
    phone = phone.strip()
    # Reemplazar secuencias de espacios+guion+espacios por " - "
    phone = re.sub(r'\s*[-–—]\s*', ' - ', phone)
    # Normalizar espacios múltiples que puedan haber quedado
    phone = re.sub(r'\s+', ' ', phone)
    return phone


def clean_email(email: str) -> str:
    """Convierte email a minúsculas y elimina espacios."""
    if not email:
        return ""
    return email.strip().lower()


def main():
    seen = set()  # Para deduplicar por (club, email) normalizado
    registros = []

    for filepath in INPUT_FILES:
        if not os.path.exists(filepath):
            print(f"⚠️  Archivo no encontrado, saltando: {filepath}")
            continue

        print(f"📂 Procesando: {filepath}")
        with open(filepath, "r", encoding="utf-8") as fh:
            reader = csv.DictReader(fh)
            for row in reader:
                region_raw = row.get("federacion", "").strip()
                club_raw = row.get("nombre", "").strip()
                phone_raw = row.get("telefono", "").strip()
                email_raw = row.get("email", "").strip()

                # Sanitizar
                provincia = region_raw
                federacion = get_federacion(region_raw)
                club = clean_club_name(club_raw)
                telefono = clean_phone(phone_raw)
                email = clean_email(email_raw)

                # Clave de deduplicación (club + email)
                dedup_key = (club.lower(), email)
                if dedup_key in seen:
                    continue
                seen.add(dedup_key)

                registros.append({
                    "federacion": federacion,
                    "club": club,
                    "email": email,
                    "estado": "pendiente",
                })

    # Ordenar por federación, luego por club
    registros.sort(key=lambda r: (r["federacion"].lower(), r["club"].lower()))

    # Escribir JSON con pretty-print y UTF-8
    with open(OUTPUT_FILE, "w", encoding="utf-8") as fh:
        json.dump(registros, fh, ensure_ascii=False, indent=2)

    print(f"\n✅ clubes.json generado correctamente.")
    print(f"   Registros totales (deduplicados): {len(registros)}")
    print(f"   Archivo: {OUTPUT_FILE}")

    # Resumen por federación
    from collections import Counter
    fed_count = Counter(r["federacion"] for r in registros)
    print("\n📊 Distribución por federación:")
    for fed, count in sorted(fed_count.items()):
        print(f"   {fed}: {count}")


if __name__ == "__main__":
    main()