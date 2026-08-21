#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VERIFY REMOTE respuestas - MODO READ-ONLY ESTRICTO
===================================================
Descarga la BD stats.db remota a local (backups_deploy/) y la inspecciona
para verificar si la tabla `respuestas` existe y su esquema.

NO escribe NADA en el servidor remoto. Solo RETR (descarga).
"""
import ftplib
import os
import time
import hashlib
import sqlite3

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
LOCAL_BACKUP_DIR = "backups_deploy"

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def inspect_db(path, label):
    print(f"\n=== INSPECCION BD {label}: {path} ===")
    print(f"  size = {os.path.getsize(path)} bytes")
    print(f"  md5  = {file_md5(path)}")
    db = sqlite3.connect(path)
    cur = db.cursor()
    cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [r[0] for r in cur.fetchall()]
    print(f"  tablas ({len(tables)}): {tables}")

    # Verificar tabla respuestas
    if "respuestas" in tables:
        print("\n  >>> TABLA 'respuestas' EXISTE <<<")
        cur.execute("PRAGMA table_info(respuestas)")
        cols = cur.fetchall()
        print(f"  columnas ({len(cols)}):")
        for c in cols:
            print(f"    {c[1]} | {c[2]} | default={c[4]} | pk={c[5]}")
        cur.execute("SELECT COUNT(*) FROM respuestas")
        print(f"  filas: {cur.fetchone()[0]}")
    else:
        print("\n  >>> TABLA 'respuestas' NO EXISTE <<<")

    db.close()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # Baseline remoto (solo lectura)
    print("\n=== BASELINE stats.db REMOTO ===")
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  size = {size} bytes")
        print(f"  mtime = {mtime}")
    except Exception as e:
        print(f"  [ERR] baseline: {e}")
        ftp.quit()
        return

    # Descargar BD remota a local (solo lectura, no escribe en remoto)
    ts = time.strftime("%Y%m%d_%H%M%S")
    os.makedirs(LOCAL_BACKUP_DIR, exist_ok=True)
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_remoto_readonly_{ts}.db")
    print(f"\n=== DESCARGANDO BD remota a {local_bk} (solo lectura) ===")
    with open(local_bk, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    print(f"  Descargada: {os.path.getsize(local_bk)} bytes, md5={file_md5(local_bk)}")

    ftp.quit()
    print("\n=== CONEXION FTP CERRADA (no se escribio nada en remoto) ===")

    # Inspeccionar BD remota descargada
    inspect_db(local_bk, "REMOTA (descargada)")

    print("\n=== FIN ===")

if __name__ == "__main__":
    main()
