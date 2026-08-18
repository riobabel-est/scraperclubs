#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - FASE 2: BACKUP REMOTO CONTROLADO
Solo respalda los 3 archivos autorizados:
  dashboard.php
  tabs/lista_negra.php
  js/app.js
El backup se guarda en /getfutprotec.com/backups_deploy/ (fuera de public_html).
NO sube nada. NO toca stats.db ni ningun otro archivo.
"""
import ftplib
import os
import sys
import time
import hashlib
import io

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
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env")
    sys.exit(1)

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"

# SOLO los 3 archivos autorizados
DEPLOY_FILES = [
    "dashboard.php",
    "tabs/lista_negra.php",
    "js/app.js",
]

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
            except Exception as e:
                print(f"  [WARN] mkd {cur}: {e}")
            try:
                ftp.cwd(cur)
            except Exception as e:
                print(f"  [ERR] cwd {cur}: {e}")
                return False
    return True

def download_to_bytes(ftp, remote_path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return buf.getvalue()
    except Exception as e:
        print(f"  [WARN] No se pudo descargar {remote_path}: {e}")
        return None

def upload_bytes(ftp, data, remote_path):
    try:
        ftp.storbinary("STOR " + remote_path, io.BytesIO(data))
        return True
    except Exception as e:
        print(f"  [ERR] No se pudo subir {remote_path}: {e}")
        return False

def md5_bytes(data):
    return hashlib.md5(data).hexdigest()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/blacklist_smtp_queue_pre_{ts}"
    print(f"\n=== CREANDO BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)

    manifest = []
    for rel in DEPLOY_FILES:
        remote_src = REMOTE_BASE + "/" + rel
        remote_dst = remote_bk + "/" + rel
        # Verificar que el archivo remoto existe
        try:
            size = ftp.size(remote_src)
        except Exception:
            print(f"  [SKIP] No existe en remoto: {rel}")
            continue
        data = download_to_bytes(ftp, remote_src)
        if data is None:
            continue
        md5 = md5_bytes(data)
        # Crear dirs remotos de destino
        dst_dir = os.path.dirname(remote_dst)
        if not ensure_remote_dir(ftp, dst_dir):
            continue
        if upload_bytes(ftp, data, remote_dst):
            manifest.append((rel, size, md5, ts, remote_dst))
            print(f"  [OK] {rel} | size={size} | md5={md5}")

    # Guardar manifest local
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "blacklist_backup_manifest.txt"), "w") as f:
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("archivos_respaldados (ruta | size | md5 | ts | backup):\n")
        for rel, size, md5, t, dst in manifest:
            f.write(f"  {rel} | {size} | {md5} | {t} | {dst}\n")

    ftp.quit()
    print(f"\nBackup completado. {len(manifest)} archivos respaldados.")
    print(f"Manifest: backups_deploy/blacklist_backup_manifest.txt")

if __name__ == "__main__":
    main()
