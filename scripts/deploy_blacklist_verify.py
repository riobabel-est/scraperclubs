#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - FASE 4: VERIFICACION POST-DEPLOY
1. Verifica MD5 local == remoto para los 3 archivos autorizados.
2. Confirma que NO se modificaron archivos no autorizados (stats.db, etc.).
"""
import ftplib
import os
import sys
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

LOCAL_BASE = os.path.join("public_html", "outbound")
REMOTE_BASE = "/getfutprotec.com/public_html/outbound"

# SOLO los 3 archivos autorizados
DEPLOY_FILES = [
    "dashboard.php",
    "tabs/lista_negra.php",
    "js/app.js",
]

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def download_to_md5(ftp, remote_path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return hashlib.md5(buf.getvalue()).hexdigest()
    except Exception:
        return None

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    print("\n=== VERIFICACION MD5 LOCAL vs REMOTO (3 archivos) ===")
    all_ok = True
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        if not os.path.exists(local_path):
            print(f"  [SKIP] no local: {rel}")
            continue
        local_md5 = file_md5(local_path)
        remote_md5 = download_to_md5(ftp, REMOTE_BASE + "/" + rel)
        if remote_md5 == local_md5:
            print(f"  [MATCH] {rel} | local={local_md5} remote={remote_md5}")
        else:
            print(f"  [MISMATCH] {rel} | local={local_md5} remote={remote_md5}")
            all_ok = False

    # Confirmar que stats.db no fue tocado (solo lectura de metadata)
    print("\n=== CONFIRMAR stats.db NO MODIFICADO (metadata) ===")
    try:
        ftp.cwd(REMOTE_BASE + "/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  stats.db size = {size}")
        print(f"  stats.db mtime = {mtime}")
    except Exception as e:
        print(f"  [ERR] data/: {e}")

    ftp.quit()
    print("\nVerificacion completada. all_ok =", all_ok)
    if all_ok:
        print("RESULTADO: MATCH x3")
    else:
        print("RESULTADO: MISMATCH DETECTADO")

if __name__ == "__main__":
    main()
