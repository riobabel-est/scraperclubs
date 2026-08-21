#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - ELIMINAR INFORME UNIBOX de producción
Borra INFORME_UNIBOX.md del directorio /outbound/ remoto.
Solo elimina ese archivo concreto; no toca nada más.
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

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_FILE = REMOTE_BASE + "/INFORME_UNIBOX.md"

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # Comprobar si existe
    try:
        size = ftp.size(REMOTE_FILE)
        print(f"  [INFO] Existe en remoto: {REMOTE_FILE} ({size} bytes)")
    except Exception:
        print(f"  [INFO] No existe en remoto (o no accesible): {REMOTE_FILE}")
        ftp.quit()
        print("Nada que borrar.")
        return

    # Borrar
    try:
        ftp.delete(REMOTE_FILE)
        print(f"  [OK] Eliminado: {REMOTE_FILE}")
    except Exception as e:
        print(f"  [ERR] No se pudo eliminar: {e}")
        ftp.quit()
        return

    # Verificar que ya no existe
    try:
        ftp.size(REMOTE_FILE)
        print("  [WARN] El archivo aún existe tras el borrado.")
    except Exception:
        print("  [OK] Verificado: el archivo ya no existe en remoto.")

    ftp.quit()
    print("Eliminación completada.")

if __name__ == "__main__":
    main()
