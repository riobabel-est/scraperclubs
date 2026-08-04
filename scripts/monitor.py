#!/usr/bin/env python
"""
Monitor universal de scraping en tiempo real.

Muestra cada nuevo club scrapeado (nombre, teléfono, email) en tiempo real,
barra de progreso por página, checkpoint y tiempo estimado restante.

Uso:
    python monitor.py                          # monitoriza federación activa
    python monitor.py --fed Andalucía          # monitoriza una federación específica
    python monitor.py --csv output/clubs_nova.csv  # monitoriza un CSV concreto
    python monitor.py --all                    # monitoriza TODAS las federaciones

Compatibilidad:
    - scraper_nova.py
    - scraper_rfcylf.py
    - scraper_fcf_cat.py
    - Cualquier scraper que escriba CSV con columnas: federacion,nombre,telefono,email
"""

import csv
import json
import os
import sys
import time
import argparse
from datetime import datetime
from pathlib import Path

# ─── Configuración ───────────────────────────────────────────────
OUTPUT_DIR = Path(__file__).parent / "output"
CHECKPOINT_DIR = Path(__file__).parent / "checkpoints"

# Federaciones conocidas con clubes esperados (para barra de progreso)
KNOWN_FEDS = {
    "Andalucía": 2702,
    "Castilla-La Mancha": 2029,
    "Extremadura": 1458,
    "Galicia": 1426,
    "Aragón": 581,
    "Asturias": 377,
    "Murcia": 365,
    "Cantabria": 177,
    "La Rioja": 79,
    "Cataluña": 1200,
    "Baleares": 200,
}

# ─── Helpers ──────────────────────────────────────────────────────

def sanitize(name):
    """Sanitiza nombre de federación para nombres de archivo."""
    import re
    return re.sub(r"[^a-zA-Z0-9_-]+", "_", name.strip().lower())


def count_csv(path):
    """Cuenta registros en un CSV (sin cabecera)."""
    if not path.exists():
        return 0
    with open(path, "r", encoding="utf-8-sig") as f:
        return sum(1 for _ in csv.DictReader(f))


def get_checkpoint_info(fed_name):
    """Obtiene info de checkpoint para una federación."""
    safe = sanitize(fed_name)
    info = {"page": "?", "processed": 0, "all_ids": 0}

    page_file = CHECKPOINT_DIR / f"{safe}_page.json"
    proc_file = CHECKPOINT_DIR / f"{safe}.json"
    all_file = CHECKPOINT_DIR / f"{safe}_all_ids.json"

    if page_file.exists():
        data = json.loads(page_file.read_text())
        info["page"] = data.get("page", "?")

    if proc_file.exists():
        data = json.loads(proc_file.read_text())
        info["processed"] = len(data) if isinstance(data, list) else 0

    if all_file.exists():
        data = json.loads(all_file.read_text())
        info["all_ids"] = len(data) if isinstance(data, list) else 0

    return info


def detect_active_fed():
    """Detecta qué federación se está scrapeando actualmente (por CSV más reciente)."""
    best = None
    best_time = 0
    for f in OUTPUT_DIR.glob("clubs_nova_*.csv"):
        mtime = f.stat().st_mtime
        if mtime > best_time and "todos" not in f.name:
            best_time = mtime
            best = f

    if best:
        # Extraer nombre de federación del nombre de archivo
        name = best.stem.replace("clubs_nova_", "").replace("_", " ").title()
        # Intentar match con KNOWN_FEDS
        for k in KNOWN_FEDS:
            if sanitize(k) in best.stem:
                return k, best
        return name, best
    return None, None


def get_last_club(csv_path):
    """Obtiene el último club de un CSV."""
    if not csv_path.exists():
        return {}
    with open(csv_path, "r", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    return rows[-1] if rows else {}


def get_new_clubs(csv_path, last_known_count):
    """Obtiene clubes nuevos desde la última lectura."""
    if not csv_path.exists():
        return [], last_known_count

    with open(csv_path, "r", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))

    current_count = len(rows)
    if current_count <= last_known_count:
        return [], last_known_count

    new_rows = rows[last_known_count:]
    return new_rows, current_count


def format_phone(phone):
    """Formatea teléfono para mostrar."""
    if not phone:
        return "—"
    # Limpiar NBSP
    phone = phone.replace("\xa0", " ").replace("\u00a0", " ")
    if len(phone) > 60:
        phone = phone[:60] + "..."
    return phone


def format_email(email):
    """Formatea email para mostrar."""
    if not email:
        return "—"
    return email


# ─── Renderizado ─────────────────────────────────────────────────

def render_single(fed_name, csv_path, expected, last_count_ref):
    """Renderiza vista detallada de una federación."""
    safe = sanitize(fed_name)
    cp = get_checkpoint_info(fed_name)
    count = count_csv(csv_path)
    new_clubs, new_count = get_new_clubs(csv_path, last_count_ref[0])
    last_count_ref[0] = new_count

    pct = min(count * 100 // max(expected, 1), 100)
    bar_len = 30
    filled = pct * bar_len // 100
    bar = "█" * filled + "░" * (bar_len - filled)

    # Estimar páginas: 10 clubes por página
    estimated_pages = (expected + 9) // 10
    current_page = cp["page"]

    lines = []
    lines.append(f"\n{'='*80}")
    lines.append(f"  📡 MONITOR: {fed_name}")
    lines.append(f"  {'='*80}")
    lines.append(f"  Clubes:   {count:5d} / {expected}  [{bar}] {pct:3d}%")
    lines.append(f"  Página:   {current_page} / ~{estimated_pages}")
    lines.append(f"  IDs acumulados: {cp['all_ids']} | IDs con detalle: {cp['processed']}")
    lines.append(f"  Actualizado: {datetime.now().strftime('%H:%M:%S')}")
    lines.append(f"  {'─'*78}")

    # Mostrar nuevos clubes
    if new_clubs:
        for r in new_clubs:
            nombre = r.get("nombre", "?")
            telefono = format_phone(r.get("telefono", ""))
            email = format_email(r.get("email", ""))
            lines.append(f"  🆕 {nombre}")
            if telefono != "—":
                lines.append(f"     📞 {telefono}")
            if email != "—":
                lines.append(f"     📧 {email}")
    else:
        # Mostrar último club conocido
        last = get_last_club(csv_path)
        if last:
            lines.append(f"  ▶ Último: {last.get('nombre', '?')}")

    lines.append(f"  {'─'*78}")
    lines.append(f"  ⌨️  Ctrl+C para salir | Refresco: cada 5s")
    lines.append(f"{'='*80}")

    return "\n".join(lines)


def render_all():
    """Renderiza vista resumen de todas las federaciones."""
    lines = []
    lines.append(f"\n{'='*80}")
    lines.append(f"  📡 MONITOR GLOBAL — {datetime.now().strftime('%H:%M:%S')}")
    lines.append(f"  {'='*80}")

    total = 0
    total_expected = 0
    for fed_name, expected in KNOWN_FEDS.items():
        safe = sanitize(fed_name)
        csv_path = OUTPUT_DIR / f"clubs_nova_{safe}.csv"
        count = count_csv(csv_path)
        total += count
        total_expected += expected

        pct = count * 100 // max(expected, 1)
        bar_len = 20
        filled = pct * bar_len // 100
        bar = "█" * filled + "░" * (bar_len - filled)

        cp = get_checkpoint_info(fed_name)
        page_info = f"p.{cp['page']}" if cp['page'] != '?' else ""

        lines.append(f"  {fed_name:<22s} {count:5d}/{expected:<5d} [{bar}] {pct:3d}%  {page_info}")

    pct_total = total * 100 // max(total_expected, 1)
    total_bar_len = 30
    total_filled = pct_total * total_bar_len // 100
    total_bar = "█" * total_filled + "░" * (total_bar_len - total_filled)

    lines.append(f"  {'─'*78}")
    lines.append(f"  TOTAL:    {total:5d}/{total_expected}  [{total_bar}] {pct_total:3d}%")
    lines.append(f"  {'='*80}")
    lines.append(f"  ⌨️  Ctrl+C para salir | Refresco: cada 5s")

    return "\n".join(lines)


# ─── Main ────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Monitor universal de scraping en tiempo real"
    )
    parser.add_argument("--fed", metavar="NOMBRE", help="Federación a monitorizar")
    parser.add_argument("--csv", metavar="RUTA", help="Ruta a CSV específico")
    parser.add_argument("--all", action="store_true", help="Monitorizar todas las federaciones")
    args = parser.parse_args()

    # Determinar qué monitorizar
    if args.all:
        mode = "all"
        fed_name = None
    elif args.fed:
        mode = "single"
        fed_name = args.fed
        safe = sanitize(fed_name)
        csv_path = OUTPUT_DIR / f"clubs_nova_{safe}.csv"
        if not csv_path.exists():
            # Intentar otros patrones
            for f in OUTPUT_DIR.glob(f"*{safe}*.csv"):
                csv_path = f
                break
    elif args.csv:
        mode = "single"
        csv_path = Path(args.csv).resolve()
        # Deducir fed_name del nombre
        stem = csv_path.stem
        fed_name = stem.replace("clubs_nova_", "").replace("_", " ").title()
    else:
        # Auto-detectar
        mode = "single"
        fed_name, csv_path = detect_active_fed()
        if not fed_name:
            print("No se detectó scraping activo. Usa --fed o --all.")
            print("Archivos CSV disponibles:")
            for f in sorted(OUTPUT_DIR.glob("clubs_nova_*.csv")):
                if "todos" not in f.name:
                    count = count_csv(f)
                    print(f"  {f.name} ({count} clubes)")
            sys.exit(1)

    expected = KNOWN_FEDS.get(fed_name, 500) if fed_name else 500

    print(f"\n🔍 Iniciando monitor...")
    if mode == "single":
        print(f"   Federación: {fed_name}")
        print(f"   CSV: {csv_path}")
        print(f"   Esperados: ~{expected} clubes")
        if fed_name:
            cp = get_checkpoint_info(fed_name)
            print(f"   Checkpoint: página {cp['page']}, {cp['all_ids']} IDs, {cp['processed']} con detalle")
    else:
        print(f"   Modo: TODAS las federaciones")

    # Contador de referencia para detectar nuevos clubes
    last_count_ref = [0]  # mutable para pasar por referencia
    if mode == "single" and csv_path:
        last_count_ref[0] = count_csv(csv_path)
        print(f"   Clubes actuales: {last_count_ref[0]}")
    print(f"   ⌨️  Ctrl+C para salir\n")

    try:
        while True:
            if mode == "single":
                output = render_single(fed_name, csv_path, expected, last_count_ref)
            else:
                output = render_all()
            print(output, flush=True)
            time.sleep(5)
    except KeyboardInterrupt:
        print("\n\n👋 Monitor detenido.\n")


if __name__ == "__main__":
    main()