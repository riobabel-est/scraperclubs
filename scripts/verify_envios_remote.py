#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verifica envios en la BD remota: schema de tabla envios y conteo de envios
comerciales (campaign/pipeline 2) y SMTP comercial.
"""
import ftplib
import os
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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_envios_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # Schema envios
    print("\n=== SCHEMA envios ===")
    cur.execute("PRAGMA table_info(envios)")
    for r in cur.fetchall():
        print("  ", r)

    # Columnas disponibles
    cur.execute("PRAGMA table_info(envios)")
    cols = [r[1] for r in cur.fetchall()]
    print("\nColumnas envios:", cols)

    # Conteo total envios
    cur.execute("SELECT COUNT(*) FROM envios")
    print("total_envios =", cur.fetchone()[0])

    # Buscar columna de pipeline/campaign
    for c in cols:
        if 'camp' in c.lower() or 'pipe' in c.lower() or 'pipeline' in c.lower():
            try:
                cur.execute(f"SELECT {c}, COUNT(*) FROM envios GROUP BY {c}")
                print(f"\n=== envios por {c} ===")
                for r in cur.fetchall():
                    print("  ", r)
            except Exception as e:
                print(f"  [ERR] {c}: {e}")

    # Envios recientes (ultimas 24h)
    print("\n=== ENVIOS RECIENTES (ultimos 10) ===")
    try:
        cur.execute("SELECT * FROM envios ORDER BY id DESC LIMIT 10")
        for r in cur.fetchall():
            print("  ", r)
    except Exception as e:
        print("  [ERR]", e)

    db.close()
    os.remove(tmp)
    print("\nVerificacion envios completada.")

if __name__ == "__main__":
    main()
