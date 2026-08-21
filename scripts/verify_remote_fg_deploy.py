#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VERIFY REMOTE F/G DEPLOY - MODO READ-ONLY
==========================================
Verifica que los archivos clave de las Fases F/G estan presentes y con
el tamano correcto en el servidor remoto (SiteGround).

Solo lectura: usa SIZE y MDTM. No escribe nada.
"""
import ftplib
import os
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

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
LOCAL_BASE = "public_html/outbound"

# Archivos clave de las Fases F/G
ARCHIVOS_FG = [
    "inc/imap_respuestas.php",
    "cli/imap_respuestas.php",
    "api/analytics.php",
    "tabs/respuestas.php",
    "js/app.js",
    "dashboard.php",
    "inc/respuestas.php",
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
    print("Login OK\n")

    print("=== VERIFICACION ARCHIVOS FASE F/G EN REMOTO ===")
    all_ok = True
    for rel in ARCHIVOS_FG:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
        local_size = os.path.getsize(local_path)
        local_md5 = file_md5(local_path)
        try:
            remote_size = ftp.size(remote_path)
            remote_md5 = download_to_md5(ftp, remote_path)
            ok = (remote_size == local_size and remote_md5 == local_md5)
            status = "OK" if ok else "MISMATCH"
            if not ok:
                all_ok = False
            print(f"  [{'OK' if ok else 'MISMATCH'}] {rel}")
            print(f"        local:  {local_size} bytes, md5={local_md5}")
            print(f"        remote: {remote_size} bytes, md5={remote_md5}")
        except Exception as e:
            all_ok = False
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()
    print(f"\n=== RESULTADO: {'TODOS OK' if all_ok else 'HAY DISCREPANCIAS'} ===")

if __name__ == "__main__":
    main()
