#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_inventario.py

FASE A.3 — PASO 1: INVENTARIO COMPLETO READ-ONLY de la BD de producción.
Descarga data/stats.db remota y vuelca el esquema real completo:
  - tablas
  - columnas (tipo, notnull, default, pk)
  - índices
  - triggers
  - foreign keys
  - contadores por tabla
  - PRAGMA integrity_check / foreign_key_check

NO modifica nada. Solo lectura.

USO:
  python scripts/faseA3_inventario.py
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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_inv_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")
    print(f"Ruta temporal: {tmp}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # integrity_check
    cur.execute("PRAGMA integrity_check")
    print("=== PRAGMA integrity_check ===")
    print("  ", cur.fetchone()[0], "\n")

    # foreign_key_check
    cur.execute("PRAGMA foreign_key_check")
    fk_rows = cur.fetchall()
    print("=== PRAGMA foreign_key_check ===")
    if not fk_rows:
        print("  (sin violaciones)")
    else:
        for r in fk_rows:
            print("  ", dict(r))
    print()

    # Tablas
    cur.execute("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    tables = cur.fetchall()
    print(f"=== TABLAS ({len(tables)}) ===")
    for t in tables:
        print(f"  {t['name']}")
    print()

    # Columnas por tabla
    print("=== COLUMNAS ===")
    for t in tables:
        tname = t['name']
        cur.execute(f"PRAGMA table_info('{tname}')")
        cols = cur.fetchall()
        print(f"\n-- {tname} ({len(cols)} columnas) --")
        for c in cols:
            print(f"    {c['name']} | type={c['type']} | notnull={c['notnull']} | default={c['dflt_value']} | pk={c['pk']}")

    # Índices
    print("\n=== ÍNDICES ===")
    cur.execute("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY tbl_name, name")
    idxs = cur.fetchall()
    for i in idxs:
        print(f"  {i['tbl_name']}.{i['name']}: {i['sql']}")

    # Triggers
    print("\n=== TRIGGERS ===")
    cur.execute("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='trigger' ORDER BY name")
    trgs = cur.fetchall()
    if not trgs:
        print("  (sin triggers)")
    for t in trgs:
        print(f"  {t['name']} on {t['tbl_name']}: {t['sql']}")

    # Foreign keys por tabla
    print("\n=== FOREIGN KEYS ===")
    for t in tables:
        tname = t['name']
        cur.execute(f"PRAGMA foreign_key_list('{tname}')")
        fks = cur.fetchall()
        if fks:
            print(f"\n-- {tname} --")
            for fk in fks:
                print(f"    {dict(fk)}")

    # Contadores
    print("\n=== CONTADORES POR TABLA ===")
    for t in tables:
        tname = t['name']
        try:
            cur.execute(f"SELECT COUNT(*) FROM '{tname}'")
            print(f"  {tname}: {cur.fetchone()[0]}")
        except Exception as e:
            print(f"  {tname}: ERROR {e}")

    # sqlite_sequence (autoincrement)
    print("\n=== sqlite_sequence (autoincrement) ===")
    try:
        cur.execute("SELECT name, seq FROM sqlite_sequence ORDER BY name")
        for r in cur.fetchall():
            print(f"  {r['name']}: seq={r['seq']}")
    except Exception as e:
        print("  ", e)

    db.close()
    print(f"\n=== FIN INVENTARIO (READ-ONLY) ===")
    print(f"BD temporal conservada en: {tmp}")

if __name__ == "__main__":
    main()
