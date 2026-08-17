#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - FASE 1: INSPECCION REMOTA (solo lectura)
Conecta a ftp.getfutprotec.com y lista la estructura de /outbound/
para confirmar el root remoto y el estado actual antes de cualquier cambio.
NO modifica nada.
"""
import ftplib
import os
import sys

# Cargar credenciales desde .env (sin exponerlas)
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

def list_recursive(ftp, path, depth=0, max_depth=4):
    """Lista recursivamente directorios y archivos."""
    items = []
    try:
        ftp.cwd(path)
    except Exception as e:
        print(f"  [ERR] No se pudo entrar a {path}: {e}")
        return items
    try:
        names = ftp.nlst()
    except Exception as e:
        print(f"  [ERR] nlst en {path}: {e}")
        return items
    for name in names:
        if name in (".", ".."):
            continue
        full = f"{path}/{name}"
        try:
            ftp.cwd(full)
            items.append(("DIR", full))
            if depth < max_depth:
                items.extend(list_recursive(ftp, full, depth + 1, max_depth))
            ftp.cwd(path)
        except Exception:
            items.append(("FILE", full))
    return items

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")
    print(f"Bienvenida: {ftp.getwelcome()}")
    print(f"PWD inicial: {ftp.pwd()}")

    # Confirmar que el root remoto existe
    try:
        ftp.cwd(REMOTE_BASE)
        print(f"OK: root remoto accesible -> {REMOTE_BASE}")
    except Exception as e:
        print(f"ERROR: no se pudo acceder a {REMOTE_BASE}: {e}")
        ftp.quit()
        sys.exit(1)

    print("\n=== ESTRUCTURA REMOTA (outbound) ===")
    items = list_recursive(ftp, REMOTE_BASE)
    for kind, full in items:
        print(f"[{kind}] {full}")

    print(f"\nTotal items: {len(items)}")
    ftp.quit()
    print("Inspeccion completada (solo lectura).")

if __name__ == "__main__":
    main()
