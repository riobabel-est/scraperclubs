"""
Módulo compartido para registrar clubes que no se pudieron scrapear.
Genera archivos de pendientes por scraper y un consolidado final.

Columnas: federacion, club_id, nombre_provisional, motivo_error

Uso:
  from pendientes import PendientesWriter
  
  pw = PendientesWriter("nova")  # o "rfcylf", "fcf_cat"
  pw.write_error("Andalucía", "12345", "Club X", "sin_datos")
  pw.write_error("Andalucía", "67890", "", "bloqueo")
  # Al cerrar, se escribe el CSV automáticamente
"""

import csv
import os
from pathlib import Path

OUTPUT_DIR = Path(__file__).parent / "output"
CSV_HEADERS = ["federacion", "club_id", "nombre_provisional", "motivo_error"]

# Motivos de error normalizados
MOTIVO_SIN_DATOS = "sin_datos"
MOTIVO_BLOQUEO = "bloqueo"
MOTIVO_LOGIN = "login_requerido"
MOTIVO_TIMEOUT = "timeout"
MOTIVO_DESCONOCIDO = "desconocido"


class PendientesWriter:
    """Escribe clubes pendientes en un CSV por scraper."""

    def __init__(self, scraper_name: str):
        """
        scraper_name: identificador del scraper, ej: "nova", "rfcylf", "fcf_cat"
        """
        self.scraper_name = scraper_name
        self.filepath = OUTPUT_DIR / f"pendientes_{scraper_name}.csv"
        self._file = None
        self._writer = None
        self._count = 0
        OUTPUT_DIR.mkdir(exist_ok=True)

    def open(self):
        """Abre el archivo y escribe la cabecera si es nuevo."""
        mode = "a" if self.filepath.exists() else "w"
        self._file = open(self.filepath, mode, newline="", encoding="utf-8-sig", buffering=1)
        self._writer = csv.DictWriter(self._file, fieldnames=CSV_HEADERS)
        if mode == "w":
            self._writer.writeheader()
        return self

    def write_error(self, federacion: str, club_id: str,
                    nombre_provisional: str = "", motivo: str = MOTIVO_SIN_DATOS):
        """
        Registra un club que no se pudo scrapear.

        Args:
            federacion: nombre de la federación
            club_id: ID del club en la plataforma
            nombre_provisional: nombre que se pudo extraer parcialmente (o "")
            motivo: motivo del error (usar constantes MOTIVO_*)
        """
        if not self._writer:
            self.open()
        self._writer.writerow({
            "federacion": federacion,
            "club_id": club_id,
            "nombre_provisional": nombre_provisional,
            "motivo_error": motivo,
        })
        self._count += 1

    def close(self):
        """Cierra el archivo."""
        if self._file:
            self._file.close()
            self._file = None
            self._writer = None

    def __enter__(self):
        return self.open()

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.close()
        return False

    @property
    def count(self):
        return self._count


def merge_pending_csvs(target_file: Path = None):
    """
    Combina todos los archivos pendientes_*.csv en un único consolidado.

    Args:
        target_file: ruta del archivo de salida. Por defecto: output/clubs_pendientes.csv

    Returns:
        Path del archivo consolidado, o None si no hay pendientes.
    """
    if target_file is None:
        target_file = OUTPUT_DIR / "clubs_pendientes.csv"

    source_files = sorted(OUTPUT_DIR.glob("pendientes_*.csv"))
    if not source_files:
        return None

    all_rows = []
    for src in source_files:
        if not src.exists():
            continue
        with open(src, "r", encoding="utf-8-sig") as f:
            reader = csv.DictReader(f)
            for row in reader:
                # Asegurar solo las columnas esperadas
                clean = {k: row.get(k, "") for k in CSV_HEADERS}
                if clean["federacion"] and clean["club_id"]:
                    all_rows.append(clean)

    if not all_rows:
        return None

    # Deduplicar por federacion + club_id
    seen = set()
    unique_rows = []
    for row in all_rows:
        key = (row["federacion"].strip().lower(), row["club_id"].strip())
        if key not in seen:
            seen.add(key)
            unique_rows.append(row)

    with open(target_file, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_HEADERS)
        writer.writeheader()
        writer.writerows(unique_rows)

    return target_file


if __name__ == "__main__":
    # Test rápido
    with PendientesWriter("test") as pw:
        pw.write_error("Testlandia", "99999", "Club de Prueba", MOTIVO_SIN_DATOS)
        pw.write_error("Testlandia", "88888", "", MOTIVO_BLOQUEO)
    print(f"Escritos {pw.count} pendientes de prueba en {pw.filepath}")
    merged = merge_pending_csvs()
    if merged:
        print(f"Consolidado en: {merged}")
    else:
        print("Sin pendientes para consolidar")