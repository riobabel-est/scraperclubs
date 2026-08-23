#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DEPLOY — dashboard.php con cache-busting ?v=14
Sube SOLO el dashboard.php del commit f860e90 con el cache-busting incrementado
a ?v=14 para forzar al navegador a descargar el app.js correcto del commit
(evita que use la versión cacheada rota del sidebar).
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

LOCAL = r"C:\Users\RioBabel\AppData\Local\Temp\restore_commit\dashboard.php"
REMOTE = "/getfutprotec.com/public_html/outbound/dashboard.php"

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
    if not os.path.exists(LOCAL):
        print(f"ERROR: No existe local {LOCAL}")
        sys.exit(1)
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    local_md5 = file_md5(LOCAL)
    local_size = os.path.getsize(LOCAL)
    print(f"Local: {LOCAL} ({local_size} bytes) md5={local_md5}")

    with open(LOCAL, "rb") as f:
        ftp.storbinary("STOR " + REMOTE, f)
    remote_size = ftp.size(REMOTE)
    remote_md5 = download_to_md5(ftp, REMOTE)
    ok = (remote_size == local_size and remote_md5 == local_md5)
    print(f"Remoto: {REMOTE} ({remote_size} bytes) md5={remote_md5}")
    print(f"Resultado: {'OK' if ok else 'MISMATCH'}")
    ftp.quit()
    sys.exit(0 if ok else 1)

if __name__ == "__main__":
    main()
