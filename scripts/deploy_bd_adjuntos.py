#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
deploy_bd_adjuntos.py — Sube a SiteGround la BD migrada y la carpeta de adjuntos.

Orden seguro:
  1. Backup remoto: sube la BD remota recién descargada como stats.db.bak_pre_deploy_*.
  2. Sube la BD de deploy (stats.db.deploy_*) como data/stats.db.
  3. Sube la carpeta data/adjuntos/** (estructura + archivos) + .htaccess.
  4. Sube data/.htaccess (protección deny).

USO: python scripts/deploy_bd_adjuntos.py
"""
import ftplib, os, sys, glob, time

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

DATA_DIR = "/getfutprotec.com/public_html/outbound/data"
LOCAL_DATA = "public_html/outbound/data"

# Detectar BD de deploy y la remota descargada más reciente (SOLO archivos SQLite,
# excluyendo los residuos -wal/-shm que crea SQLite al abrir las copias).
def es_sqlite(path):
    try:
        with open(path, "rb") as f:
            return f.read(16) == b"SQLite format 3\x00"
    except Exception:
        return False

deploy = [f for f in glob.glob(LOCAL_DATA + "/stats.db.deploy_*") if not f.endswith(("-wal", "-shm")) and es_sqlite(f)]
remota = [f for f in glob.glob(LOCAL_DATA + "/stats.db.remoto_*") if not f.endswith(("-wal", "-shm")) and es_sqlite(f)]
if not deploy or not remota:
    print("ERROR: no se encontraron BD SQLite válidas para deploy/backup."); sys.exit(1)
deploy = sorted(deploy)[-1]
remota = sorted(remota)[-1]
ts = time.strftime('%Y%m%d_%H%M%S')

print("BD deploy :", deploy)
print("BD remota :", remota)

ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
ftp.cwd(DATA_DIR)

def upload_file(local, remote):
    with open(local, "rb") as f:
        ftp.storbinary("STOR " + remote, f)

# 1) Backup remoto de la BD actual (la remota descargada es su copia exacta).
bak_remote = "stats.db.bak_pre_deploy_" + ts
upload_file(remota, bak_remote)
print("1) Backup remoto ->", bak_remote)

# 2) Subir la BD migrada como stats.db.
upload_file(deploy, "stats.db")
print("2) BD deploy -> data/stats.db")

# 3) Subir data/.htaccess
if os.path.exists(LOCAL_DATA + "/.htaccess"):
    upload_file(LOCAL_DATA + "/.htaccess", ".htaccess")
    print("3) data/.htaccess subido")

# 4) Subir data/adjuntos/** recursivo + su .htaccess
adj_local = LOCAL_DATA + "/adjuntos"
adj_remote = DATA_DIR + "/adjuntos"
if os.path.isdir(adj_local):
    try:
        ftp.mkd("adjuntos")
    except Exception:
        pass  # ya existe (de una subida previa)
    for root, dirs, files in os.walk(adj_local):
        rel = os.path.relpath(root, adj_local).replace(os.sep, "/")
        remote_dir = adj_remote if rel == "." else adj_remote + "/" + rel
        if rel != ".":
            try:
                ftp.mkd(remote_dir)
            except Exception:
                pass
        for fname in files:
            local_path = os.path.join(root, fname)
            remote_file = remote_dir + "/" + fname
            upload_file(local_path, remote_file)
            print("4) adjuntos:", remote_file)
    if os.path.exists(adj_local + "/.htaccess"):
        upload_file(adj_local + "/.htaccess", adj_remote + "/.htaccess")
        print("4) adjuntos/.htaccess subido")

ftp.quit()
print("OK: BD + adjuntos subidos a SiteGround.")
