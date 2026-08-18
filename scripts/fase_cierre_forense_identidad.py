#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fase_cierre_forense_identidad.py

FASE FINAL — CIERRE FORENSE (READ-ONLY)
PASO 2: IDENTIDAD DE BD DE PRODUCCIÓN

Reutiliza el mecanismo FTP existente (faseA_auditoria_remota.py / verify_prod_remote.py)
para descargar data/stats.db a un temporal local y obtener:

- ruta remota
- tamaño
- fecha/modificación (si FTP lo permite)
- MD5
- SHA-256
- PRAGMA integrity_check
- PRAGMA foreign_key_check

NO modifica NADA en producción. Solo descarga y consulta una copia temporal local.
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

def file_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    print("=" * 60)
    print("FASE FINAL — CIERRE FORENSE | PASO 2: IDENTIDAD BD")
    print("=" * 60)
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # Intentar obtener fecha/modificación remota
    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_cierre_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()

    size = os.path.getsize(tmp)
    mtime = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(os.path.getmtime(tmp)))
    md5 = file_md5(tmp)
    sha = file_sha256(tmp)

    print("\n=== IDENTIDAD BD DE PRODUCCIÓN ===")
    print(f"Ruta remota : {REMOTE_DB}")
    print(f"Tamaño      : {size} bytes")
    print(f"Fecha local : {mtime}")
    print(f"MD5         : {md5}")
    print(f"SHA-256     : {sha}")

    # Referencia del último checkpoint conocido
    print("\n=== REFERENCIA CHECKPOINT CONOCIDO ===")
    print("MD5 esperado    : 4dbc8e72608dd1f0ebd7ad25aaa58364")
    print("SHA-256 esperado: f87e6d7028612fc9b4b747eacae858a554c99fa87046c808afa93de58ef9cfdc")
    print("Ruta esperada   : /getfutprotec.com/public_html/outbound/data/stats.db")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    print("\n=== PRAGMA integrity_check ===")
    cur.execute("PRAGMA integrity_check")
    integrity = cur.fetchone()[0]
    print("  ", integrity)

    print("\n=== PRAGMA foreign_key_check ===")
    cur.execute("PRAGMA foreign_key_check")
    fk_rows = cur.fetchall()
    print(f"  filas FK violadas: {len(fk_rows)}")
    for r in fk_rows[:20]:
        print("   ", r)

    print("\n=== TABLAS ===")
    cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [r[0] for r in cur.fetchall()]
    print("  ", tables)

    db.close()
    os.remove(tmp)
    print("\n=== FIN IDENTIDAD (READ-ONLY) ===")

    # Clasificación
    if integrity != "ok":
        print("RESULTADO: BLOQUEANTE — integrity_check != ok")
        sys.exit(2)
    if len(fk_rows) > 0:
        print("RESULTADO: BLOQUEANTE — foreign_key_check != 0")
        sys.exit(2)
    print("RESULTADO: INTEGRIDAD OK")

if __name__ == "__main__":
    main()
