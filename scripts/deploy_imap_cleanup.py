#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - Limpieza del runner temporal IMAP.
Elimina imap_respuestas_runner.php de producción (archivo temporal).
"""
import ftplib
import os

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

REMOTE_FILE = "/getfutprotec.com/public_html/outbound/imap_respuestas_runner.php"

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    try:
        ftp.delete(REMOTE_FILE)
        print(f"  [OK] Eliminado {REMOTE_FILE}")
    except Exception as e:
        print(f"  [INFO] No se pudo eliminar: {e}")

    ftp.quit()
    print("Limpieza runner temporal completada.")

if __name__ == "__main__":
    main()
