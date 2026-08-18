#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_backup.py

FASE A.3 — BACKUP OBLIGATORIO ANTES DE REPARACIONES.

1. Descarga stats.db remoto a backups_deploy/ (backup local verificable).
2. Crea copia de backup remota en /getfutprotec.com/backups_deploy/.
3. Verifica integrity_check del backup local.
4. Registra manifest con MD5, size, mtime.

NO sube nada a la BD de producción. NO modifica la BD remota.
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

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
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"
LOCAL_BACKUP_DIR = "backups_deploy"

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def ensure_remote_dir(ftp, path):
    parts = path.strip("/").split("/")
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass
            try:
                ftp.cwd(cur)
            except Exception:
                return False
    return True

def verify_integrity(path):
    db = sqlite3.connect(path)
    cur = db.cursor()
    cur.execute("PRAGMA integrity_check")
    r = cur.fetchone()
    db.close()
    return r[0] if r else "ERROR"

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
        print(f"  size = {size} bytes")
        print(f"  mtime = {mtime}")
    except Exception as e:
        print(f"  [ERR] baseline: {e}")
        ftp.quit()
        return

    ts = time.strftime("%Y%m%d_%H%M%S")
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_faseA3_pre_{ts}.db")
    print(f"\n=== DESCARGANDO BD remota a {local_bk} ===")
    with open(local_bk, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    md5 = file_md5(local_bk)
    print(f"  Descargada: {os.path.getsize(local_bk)} bytes, md5={md5}")

    # Backup remoto
    remote_bk_dir = f"{REMOTE_BACKUP_BASE}/stats_db_faseA3_pre_{ts}"
    print(f"\n=== CREANDO BACKUP REMOTO en {remote_bk_dir} ===")
    remote_bk_path = None
    if ensure_remote_dir(ftp, remote_bk_dir):
        remote_bk_path = remote_bk_dir + "/stats.db"
        with open(local_bk, "rb") as f:
            ftp.storbinary("STOR " + remote_bk_path, f)
        print(f"  Backup remoto creado: {remote_bk_path}")
    else:
        print("  [ERR] No se pudo crear backup remoto")

    ftp.quit()

    # Verificar integridad del backup local
    print("\n=== VERIFICANDO INTEGRIDAD DEL BACKUP LOCAL ===")
    integ = verify_integrity(local_bk)
    print(f"  integrity_check = {integ}")

    # Manifest
    manifest = os.path.join(LOCAL_BACKUP_DIR, "faseA3_backup_manifest.txt")
    with open(manifest, "w") as f:
        f.write(f"timestamp={ts}\n")
        f.write(f"remote_size={size}\n")
        f.write(f"remote_mtime={mtime}\n")
        f.write(f"local_backup={local_bk}\n")
        f.write(f"local_backup_md5={md5}\n")
        f.write(f"remote_backup={remote_bk_path}\n")
        f.write(f"integrity_check={integ}\n")
    print(f"\nManifest guardado en {manifest}")

    if integ != "ok":
        print("\n[FAIL] integrity_check != ok. NO proceder con reparaciones.")
    else:
        print("\n[OK] Backup verificado. Listo para reparaciones.")

if __name__ == "__main__":
    main()
