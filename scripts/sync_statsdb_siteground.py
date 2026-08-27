#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Descarga stats.db remota (SiteGround) para comparar/sincronizar con la local.
Lee credenciales del .env (FTP_HOST/USER/PASS). NO modifica el servidor."""
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
LOCAL_DB = "public_html/outbound/data/stats.db"
ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
BACKUP_REMOTE = f"public_html/outbound/data/stats.db.remoto_{ts}"

ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
ftp.encoding = "utf-8"

print("=== Directorio data/ remoto ===")
try:
    ftp.cwd(REMOTE_DIR)
    names = ftp.nlst()
    for n in names:
        size = 0
        try: size = ftp.size(n)
        except Exception: pass
        print(f"  {n} ({size} bytes)")
except Exception as e:
    print("  ERR listando:", e)

print("\n=== Descargando stats.db remota ===")
try:
    with open(BACKUP_REMOTE, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    md5_remote = hashlib.md5(open(BACKUP_REMOTE, "rb").read()).hexdigest()
    md5_local = hashlib.md5(open(LOCAL_DB, "rb").read()).hexdigest()
    print(f"  Descargada a : {BACKUP_REMOTE}")
    print(f"  MD5 remota   : {md5_remote} ({os.path.getsize(BACKUP_REMOTE)} bytes)")
    print(f"  MD5 local    : {md5_local} ({os.path.getsize(LOCAL_DB)} bytes)")
    print(f"  ¿Iguales?    : {md5_remote == md5_local}")
except Exception as e:
    print("  ERR descargando:", e)

ftp.quit()
