"""
Script principal — combina todos los scrapers y genera un CSV único.

Uso:
  python main.py                          # modo seguro por defecto (1 worker, delay 2s)
  python main.py --fast                   # modo rápido (bajo tu responsabilidad)
  python main.py --only nova             # solo federaciones NOVA
  python main.py --only rfcylf           # solo Castilla y León
  python main.py --only fcf              # solo Cataluña
  python main.py --fed "Andalucía"       # una federación NOVA concreta
  python main.py --resume                # reanudar desde checkpoints
  python main.py --test --fed "La Rioja" # prueba rápida con 5 clubs
  python main.py --only nova --fast --workers 2 --delay 1.5
"""

import argparse
import csv
import sys
from pathlib import Path

OUTPUT_DIR = Path(__file__).parent / "output"
FINAL_CSV = OUTPUT_DIR / "clubs_todos.csv"
CSV_HEADERS = ["federacion", "nombre", "telefono", "email"]

# Módulo de pendientes
sys.path.insert(0, str(Path(__file__).parent))
from pendientes import merge_pending_csvs


def merge_csvs():
    """Combina todos los CSVs de output/ en un único archivo."""
    OUTPUT_DIR.mkdir(exist_ok=True)
    all_rows = []

    preferred_sources = [
        OUTPUT_DIR / "clubs_nova.csv",
        OUTPUT_DIR / "clubs_rfcylf.csv",
        OUTPUT_DIR / "clubs_fcf_cat.csv",
    ]

    source_files = [p for p in preferred_sources if p.exists()]
    if not source_files:
        # Fallback por compatibilidad
        source_files = [p for p in OUTPUT_DIR.glob("clubs_*.csv") if p != FINAL_CSV]

    for csv_file in source_files:
        with open(csv_file, "r", encoding="utf-8-sig") as f:
            reader = csv.DictReader(f)
            for row in reader:
                all_rows.append(row)

    # Deduplicar por nombre normalizado
    seen = set()
    unique_rows = []
    for row in all_rows:
        key = (row.get("federacion", "").strip().lower(),
               row.get("nombre", "").strip().upper())
        if key not in seen and key[1]:
            seen.add(key)
            unique_rows.append(row)

    with open(FINAL_CSV, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
        writer.writeheader()
        writer.writerows(unique_rows)

    print(f"\n{'='*60}")
    print(f"CSV combinado: {FINAL_CSV}")
    print(f"Total filas únicas: {len(unique_rows)}")

    # Resumen por federación
    from collections import Counter
    counts = Counter(r.get("federacion", "?") for r in unique_rows)
    print("\nClubs por federación:")
    for fed, n in sorted(counts.items(), key=lambda x: -x[1]):
        print(f"  {fed:30s}: {n:>5}")


def run_nova(args):
    """Ejecuta el scraper NOVA."""
    import scraper_nova
    # Patch sys.argv para los argumentos del sub-script
    argv_backup = sys.argv
    sys.argv = ["scraper_nova.py"]
    if hasattr(args, "fed") and args.fed:
        sys.argv += ["--fed", args.fed]
    if hasattr(args, "resume") and args.resume:
        sys.argv += ["--resume"]
    if hasattr(args, "workers") and args.workers is not None:
        sys.argv += ["--workers", str(args.workers)]
    if hasattr(args, "delay") and args.delay is not None:
        sys.argv += ["--delay", str(args.delay)]
    if hasattr(args, "fast") and args.fast:
        sys.argv += ["--fast"]
    if hasattr(args, "test") and args.test:
        # Modo test: limitar a 5 clubs por federación
        scraper_nova.MAX_RETRIES = 1
        import config
        if hasattr(args, "fed") and args.fed:
            feds = [f for f in config.NOVA_FEDERATIONS
                    if f["name"].lower() == args.fed.lower()]
        else:
            feds = config.NOVA_FEDERATIONS

        OUTPUT_DIR.mkdir(exist_ok=True)
        import scraper_nova as sn
        with open(OUTPUT_DIR / "clubs_nova.csv", "w", newline="", encoding="utf-8-sig") as f:
            writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
            writer.writeheader()
            for fed in feds:
                import requests
                session = requests.Session()
                ids = sn.get_all_club_ids(session, fed["domain"], fed["cod_primaria"], fed["name"])
                for club_id in ids[:5]:
                    nombre, telefono, email = sn.scrape_club(
                        session, fed["domain"], fed["cod_primaria"], club_id)
                    if nombre:
                        writer.writerow({
                            "federacion": fed["name"],
                            "nombre": nombre,
                            "telefono": telefono,
                            "email": email,
                        })
                        print(f"  TEST: {fed['name']} — {nombre} | {telefono} | {email}")
        sys.argv = argv_backup
        return

    scraper_nova.main()
    sys.argv = argv_backup


def run_rfcylf(args=None):
    """Ejecuta el scraper de Castilla y León."""
    import scraper_rfcylf
    # Pasar --fast si corresponde
    if args and hasattr(args, "fast") and args.fast:
        argv_backup = sys.argv
        sys.argv = ["scraper_rfcylf.py", "--fast"]
        scraper_rfcylf.main()
        sys.argv = argv_backup
    else:
        scraper_rfcylf.main()


def run_fcf(args=None):
    """Ejecuta el scraper de Cataluña."""
    import scraper_fcf_cat
    if args and hasattr(args, "fast") and args.fast:
        argv_backup = sys.argv
        sys.argv = ["scraper_fcf_cat.py", "--fast"]
        scraper_fcf_cat.main()
        sys.argv = argv_backup
    else:
        scraper_fcf_cat.main()


def main():
    parser = argparse.ArgumentParser(description="Scraper de clubes de fútbol españoles")
    parser.add_argument("--only", choices=["nova", "rfcylf", "fcf"],
                        help="Ejecutar solo un scraper concreto")
    parser.add_argument("--fed", metavar="NOMBRE",
                        help="Federación NOVA concreta (p.ej. 'Andalucía')")
    parser.add_argument("--resume", action="store_true",
                        help="Reanudar desde checkpoints existentes")
    parser.add_argument("--test", action="store_true",
                        help="Modo prueba: solo 5 clubs por federación")
    parser.add_argument("--merge-only", action="store_true",
                        help="Solo combinar CSVs existentes sin scrapear")
    parser.add_argument("--workers", type=int, default=1,
                        help="Federaciones en paralelo (solo NOVA)")
    parser.add_argument("--delay", type=float,
                        help="Delay global por petición en segundos")
    parser.add_argument("--fast", action="store_true",
                        help="Modo rápido (workers y delay sin restricciones)")
    args = parser.parse_args()

    OUTPUT_DIR.mkdir(exist_ok=True)

    if args.merge_only:
        merge_csvs()
        return

    if args.only == "nova" or (not args.only):
        print(">>> Ejecutando scraper NOVA (9 federaciones)...")
        run_nova(args)

    if args.only == "rfcylf" or (not args.only and not args.fed):
        print("\n>>> Ejecutando scraper Castilla y León (rfcylf.es)...")
        run_rfcylf(args)

    if args.only == "fcf" or (not args.only and not args.fed):
        print("\n>>> Ejecutando scraper Cataluña (fcf.cat)...")
        run_fcf(args)

    if not args.fed:
        print("\n>>> Combinando resultados...")
        merge_csvs()

    # Consolidar archivos de pendientes de todos los scrapers
    print("\n>>> Consolidando clubes pendientes...")
    pendientes_file = merge_pending_csvs()
    if pendientes_file:
        # Contar líneas
        with open(pendientes_file, "r", encoding="utf-8-sig") as f:
            pendientes_count = sum(1 for _ in f) - 1  # restar header
        print(f"⚠️  {pendientes_count} clubes pendientes en total")
        print(f"   → {pendientes_file}")
        print(f"   Revisa este archivo para completar los datos manualmente o por otros medios.")
    else:
        print("✅ No hay clubes pendientes — todos los datos se extrajeron correctamente.")


if __name__ == "__main__":
    main()
