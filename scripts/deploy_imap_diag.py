#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - Runner de diagnóstico IMAP temporal.
Sube imap_diag_runner.php a producción (archivo temporal).
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

LOCAL_FILE = "public_html/outbound/imap_diag_runner.php"
REMOTE_FILE = "/getfutprotec.com/public_html/outbound/imap_diag_runner.php"

def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    with open(LOCAL_FILE, "rb") as f:
        ftp.storbinary(f"STOR {REMOTE_FILE}", f)
    print(f"  [OK] {REMOTE_FILE} ({os.path.getsize(LOCAL_FILE)} bytes)")
    print(f"  local_md5 = {md5(LOCAL_FILE)}")

    ftp.quit()
    print("Deploy runner diagnóstico completado.")

if __name__ == "__main__":
    main()
