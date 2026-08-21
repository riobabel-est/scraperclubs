#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - inc/imap_respuestas.php (timeout 30 -> 120s)
Sube el módulo IMAP actualizado a producción y verifica (size + MD5).
"""
import ftplib
import os
import hashlib

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

LOCAL_FILE = os.path.join("public_html", "outbound", "inc", "imap_respuestas.php")
REMOTE_FILE = "/getfutprotec.com/public_html/outbound/inc/imap_respuestas.php"

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    if not os.path.exists(LOCAL_FILE):
        print(f"[ERR] No existe local: {LOCAL_FILE}")
        return

    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    local_md5 = file_md5(LOCAL_FILE)
    local_size = os.path.getsize(LOCAL_FILE)

    with open(LOCAL_FILE, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_FILE, f)
    remote_size = ftp.size(REMOTE_FILE)
    ok = (remote_size == local_size)
    print(f"  [{'OK' if ok else 'SIZE_MISMATCH'}] inc/imap_respuestas.php ({local_size} bytes)")
    print(f"  local_md5 = {local_md5}")

    ftp.quit()
    print("Deploy inc/imap_respuestas.php completado.")

if __name__ == "__main__":
    main()
