#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SYNC stats.db - FASE 1: BACKUP REMOTO OBLIGATORIO + INSPECCION
1. Descarga stats.db remoto a backups_deploy/ (backup local verificable).
2. Crea copia de backup remota en /getfutprotec.com/backups_deploy/ (fuera de public_html).
3. Registra size + mtime + MD5 del remoto.
NO sube nada. NO modifica la BD remota.
"""
import ftplib
import os
import time
import hashlib
import sqlite3
import shutil

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

def inspect_db(path, label):
    print(f"\n=== INSPECCION BD {label}: {path} ===")
    print(f"  size = {os.path.getsize(path)} bytes")
    print(f"  mtime = {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(os.path.getmtime(path)))}")
    print(f"  md5 = {file_md5(path)}")
    db = sqlite3.connect(path)
    cur = db.cursor()
    cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [r[0] for r in cur.fetchall()]
    print(f"  tablas ({len(tables)}): {tables}")
    for t in tables:
        if t.startswith("sqlite_"):
            continue
        try:
            cur.execute(f'SELECT COUNT(*) FROM "{t}"')
            print(f"    {t}: {cur.fetchone()[0]} filas")
        except Exception as e:
            print(f"    {t}: ERROR {e}")
    db.close()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # ── 1. Baseline remoto ──
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

    # ── 2. Descargar BD remota a local (backup) ──
    ts = time.strftime("%Y%m%d_%H%M%S")
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_remoto_pre_sync_{ts}.db")
    print(f"\n=== DESCARGANDO BD remota a {local_bk} ===")
    with open(local_bk, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    print(f"  Descargada: {os.path.getsize(local_bk)} bytes, md5={file_md5(local_bk)}")

    # ── 2b. Descargar archivos WAL/SHM si existen (modo WAL) ──
    # Si la BD remota opera en modo WAL (Write-Ahead Logging), los datos más
    # recientes pueden residir en stats.db-wal / stats.db-shm. Para obtener una
    # copia de lectura CONSISTENTE y completa, se descargan junto a la BD.
    # IMPORTANTE: flujo unidireccional estricto (Prod ➔ Local). NUNCA se resube.
    for sufijo in ("-wal", "-shm"):
        remoto_aux = REMOTE_DB + sufijo
        local_aux = local_bk + sufijo
        try:
            ftp.size(remoto_aux)
            with open(local_aux, "wb") as f:
                ftp.retrbinary("RETR " + remoto_aux, f.write)
            print(f"  Descargado auxiliar {sufijo}: {os.path.getsize(local_aux)} bytes")
        except Exception:
            print(f"  No existe {sufijo} en remoto (BD sin WAL pendiente) — omitido")


    # ── 3. Backup remoto en /getfutprotec.com/backups_deploy/ ──
    remote_bk_dir = f"{REMOTE_BACKUP_BASE}/stats_db_pre_sync_{ts}"
    print(f"\n=== CREANDO BACKUP REMOTO en {remote_bk_dir} ===")
    if ensure_remote_dir(ftp, remote_bk_dir):
        remote_bk_path = remote_bk_dir + "/stats.db"
        with open(local_bk, "rb") as f:
            ftp.storbinary("STOR " + remote_bk_path, f)
        print(f"  Backup remoto creado: {remote_bk_path}")
    else:
        print("  [ERR] No se pudo crear backup remoto")

    ftp.quit()

    # ── 4. Inspeccion BD remota descargada ──
    inspect_db(local_bk, "REMOTA (descargada)")

    # ── 5. Inspeccion BD local ──
    inspect_db("public_html/outbound/data/stats.db", "LOCAL")

    # ── 6. Guardar manifest ──
    with open(os.path.join(LOCAL_BACKUP_DIR, "sync_db_manifest.txt"), "w") as f:
        f.write(f"remote_size={size}\n")
        f.write(f"remote_mtime={mtime}\n")
        f.write(f"remote_backup_local={local_bk}\n")
        f.write(f"remote_backup_remote={remote_bk_dir}/stats.db\n")
        f.write(f"timestamp={ts}\n")

    print("\nBackup + inspeccion completados.")

if __name__ == "__main__":
    main()
