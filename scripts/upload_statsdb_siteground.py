#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Sube la stats.db local reconstruida a producción (SiteGround).
- Lee credenciales del .env (FTP_HOST/USER/PASS).
- Hace backup remoto (rename) antes de sobrescribir.
- Elimina -wal/-shm remotos para evitar re-aplicar un WAL obsoleto.
- Verifica tamaño del archivo subido.

USO: python scripts/upload_statsdb_siteground.py
Previo (según checkpoint sync 2026-08-27): la local ya contiene DATOS=producción
y ESTRUCTURA=local. Este script es el paso "Subir BD local a producción".
"""
import ftplib, os, sys, hashlib, datetime

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
if not USER or not PASS:
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env"); sys.exit(1)

REMOTE_DIR = "/getfutprotec.com/public_html/outbound/data"
REMOTE_DB = REMOTE_DIR + "/stats.db"
LOCAL_UPLOAD = "public_html/outbound/data/stats_upload.db"
ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")

if not os.path.exists(LOCAL_UPLOAD):
    print(f"ERROR: no existe el snapshot {LOCAL_UPLOAD} (genera con VACUUM INTO)"); sys.exit(1)

local_size = os.path.getsize(LOCAL_UPLOAD)
local_md5 = hashlib.md5(open(LOCAL_UPLOAD, "rb").read()).hexdigest()
print(f"Local a subir: {LOCAL_UPLOAD} ({local_size} bytes, MD5 {local_md5})")

ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
ftp.encoding = "utf-8"

print(f"=== Directorio data/ remoto (antes) ===")
try:
    ftp.cwd(REMOTE_DIR)
    for n in ftp.nlst():
        size = 0
        try: size = ftp.size(n)
        except Exception: pass
        print(f"  {n} ({size} bytes)")
except Exception as e:
    print("  ERR listando:", e); sys.exit(1)

# 1) Backup remoto: rename stats.db -> stats.db.bak_pre_upload_<ts>
backup_name = f"stats.db.bak_pre_upload_{ts}"
try:
    ftp.rename("stats.db", backup_name)
    print(f"Backup remoto OK: stats.db -> {backup_name}")
except Exception as e:
    print(f"  No se pudo renombrar stats.db ({e}); intento STOR directo")

# 2) Eliminar WAL/SHM remotos obsoletos (producción sin cambios desde el checkpoint)
for ext in ("stats.db-wal", "stats.db-shm", "stats.db-journal"):
    try:
        ftp.delete(ext)
        print(f"  Eliminado remoto: {ext}")
    except Exception:
        pass

# 3) Subida
try:
    with open(LOCAL_UPLOAD, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)
    print(f"Subida OK: stats.db ({local_size} bytes)")
except Exception as e:
    print("  ERR subiendo:", e); sys.exit(1)

# 4) Verificación
try:
    remote_size = ftp.size("stats.db")
    print(f"  Tamaño remoto tras subir: {remote_size} bytes (esperado {local_size})")
    print(f"  ¿Coincide? : {remote_size == local_size}")
except Exception as e:
    print("  No se pudo verificar tamaño:", e)

print("\n=== Directorio data/ remoto (después) ===")
for n in ftp.nlst():
    size = 0
    try: size = ftp.size(n)
    except Exception: pass
    print(f"  {n} ({size} bytes)")

ftp.quit()
print("\nOK: BD local subida a producción.")
