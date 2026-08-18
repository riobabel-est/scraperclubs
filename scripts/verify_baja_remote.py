#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verifica el estado del lead TEST en la BD remota de producción.
Descarga stats.db remoto a un temporal local y consulta el estado del lead
TEST_ABC_FINAL4_A (id=1814, email=test_abc_final4_a@futprotec.local).

Uso: python scripts/verify_baja_remote.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile

def load_env(path=".env"):
    env = {}
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env

env = load_env()
HOST = env.get("FTP_HOST", "ftp.getfutprotec.com")
USER = env.get("FTP_USER", "")
PASS = env.get("FTP_PASS", "")

REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # Baseline remoto
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  stats.db remoto: size={size} | mtime={mtime}")
    except Exception as e:
        print(f"  [ERR] baseline: {e}")

    # Descargar BD remota a temporal
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_remote_{int(time.time())}.db")
    print(f"\nDescargando BD remota a {tmp} ...")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"  Descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    # Consultar estado del lead TEST
    db = sqlite3.connect(tmp)
    cur = db.cursor()
    print("\n=== LEAD TEST_ABC_FINAL4_A (id=1814) ===")
    cur.execute("SELECT id, nombre_club, email, estado_lead, observaciones, ultimo_contacto FROM clubes_crm WHERE id = 1814")
    row = cur.fetchone()
    if row:
        print(f"  id={row[0]} | nombre={row[1]} | email={row[2]}")
        print(f"  estado_lead={row[3]}")
        print(f"  ultimo_contacto={row[5]}")
        print(f"  observaciones={row[4]}")
    else:
        print("  (no encontrado)")

    # Contar registros [BAJA] en observaciones
    if row and row[4]:
        n_baja = row[4].count("[BAJA]")
        print(f"\n  n_registros_[BAJA]={n_baja}")
        if "Motivo baja:" in row[4]:
            print("  motivo_presente=SI")
        else:
            print("  motivo_presente=NO")

    # Elegibilidad del lead TEST (simular esElegibleParaEnvio)
    print("\n=== ELEGIBILIDAD (simulada) ===")
    if row:
        estado = row[3]
        if estado in ('Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out'):
            print("  esElegibleParaEnvio=false | razon=supresion")
        else:
            print("  esElegibleParaEnvio=true | razon=elegible")

    # Lead normal de control (id=155, clubadpparador@gmail.com)
    print("\n=== LEAD NORMAL CONTROL (id=155) ===")
    cur.execute("SELECT id, nombre_club, email, estado_lead FROM clubes_crm WHERE id = 155")
    row2 = cur.fetchone()
    if row2:
        print(f"  id={row2[0]} | nombre={row2[1]} | email={row2[2]} | estado={row2[3]}")
        if row2[3] in ('Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out'):
            print("  esElegibleParaEnvio=false | razon=supresion")
        else:
            print("  esElegibleParaEnvio=true | razon=elegible")

    db.close()
    os.remove(tmp)
    print("\nVerificacion completada.")

if __name__ == "__main__":
    main()
