#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - RUNNER WEB TEMPORAL imap_respuestas_runner.php
Sube el runner temporal a la RAÍZ de /outbound/ remoto (accesible por HTTP)
y elimina la copia errónea que quedó en /outbound/cli/ (protegida por .htaccess).
Verifica (size + MD5).
Este archivo se ELIMINA del servidor tras la verificación.
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

LOCAL_FILE = os.path.join("public_html", "outbound", "imap_respuestas_runner.php")
REMOTE_FILE = "/getfutprotec.com/public_html/outbound/imap_respuestas_runner.php"
REMOTE_CLI_FILE = "/getfutprotec.com/public_html/outbound/cli/imap_respuestas_runner.php"

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

    # Subir a la raíz de outbound/
    with open(LOCAL_FILE, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_FILE, f)
    remote_size = ftp.size(REMOTE_FILE)
    ok = (remote_size == local_size)
    print(f"  [{'OK' if ok else 'SIZE_MISMATCH'}] outbound/imap_respuestas_runner.php ({local_size} bytes)")
    print(f"  local_md5 = {local_md5}")

    # Eliminar la copia errónea en cli/ (protegida por .htaccess)
    try:
        ftp.delete(REMOTE_CLI_FILE)
        print("  [OK] Eliminada copia errónea en cli/imap_respuestas_runner.php")
    except Exception as e:
        print(f"  [INFO] No se pudo eliminar cli/imap_respuestas_runner.php: {e}")

    ftp.quit()
    print("Deploy runner completado.")

if __name__ == "__main__":
    main()
