#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
deploy_migracion_live_runner.py

Sube el runner web temporal a SiteGround, ejecuta la migración in-place sobre
data/stats.db (auditoría + apply), verifica resultados y ELIMINA el runner.

NO descarga/modifica/re-subir stats.db. NO reemplaza la BD LIVE.
NO ejecuta cron, NO envía emails, NO lanza campañas.

USO:
  python scripts/deploy_migracion_live_runner.py
"""
import ftplib
import os
import sys
import time
import urllib.request
import urllib.error

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
RUNNER_REMOTE = REMOTE_BASE + "/migracion_live_runner.php"
RUNNER_LOCAL = os.path.join("public_html", "outbound", "migracion_live_runner.php")
HTTP_BASE = "https://getfutprotec.com/outbound"
RUNNER_URL = HTTP_BASE + "/migracion_live_runner.php"
TOKEN = "MIGRACION_ES_TEST_20260817"

BACKUP = os.path.join("backups_deploy", "stats_db_LIVE_backup_1786998742.db")

def http_get(url, timeout=60):
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36",
            "Accept": "text/plain,text/html,*/*",
        },
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.status, resp.read().decode("utf-8", errors="replace")

def main():
    print("=== DEPLOY MIGRACIÓN LIVE (runner temporal) ===\n")

    # 0. Verificar backup
    if not os.path.exists(BACKUP):
        print(f"[FAIL] Backup no encontrado: {BACKUP}")
        sys.exit(1)
    print(f"[OK] Backup existe: {BACKUP} ({os.path.getsize(BACKUP)} bytes)")

    # 1. Conectar FTP
    print(f"\nConectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("[OK] Login FTP")

    # 2. Subir runner
    print(f"\nSubiendo runner a {RUNNER_REMOTE} ...")
    with open(RUNNER_LOCAL, "rb") as f:
        ftp.storbinary("STOR " + RUNNER_REMOTE, f)
    remote_size = ftp.size(RUNNER_REMOTE)
    local_size = os.path.getsize(RUNNER_LOCAL)
    if remote_size != local_size:
        print(f"[FAIL] Tamaño runner remoto {remote_size} != local {local_size}")
        ftp.quit()
        sys.exit(1)
    print(f"[OK] Runner subido ({remote_size} bytes)")

    # 3. Auditoría (sin apply)
    print("\n=== AUDITORÍA (sin apply) ===")
    try:
        status, body = http_get(RUNNER_URL + "?token=" + TOKEN)
        print(f"HTTP {status}")
        print(body)
    except Exception as e:
        print(f"[FAIL] No se pudo acceder al runner: {e}")
        ftp.quit()
        sys.exit(1)

    # 4. Aplicar migración
    print("\n=== APLICAR MIGRACIÓN (apply=1) ===")
    try:
        status, body = http_get(RUNNER_URL + "?token=" + TOKEN + "&apply=1")
        print(f"HTTP {status}")
        print(body)
    except Exception as e:
        print(f"[FAIL] Error al aplicar migración: {e}")
        ftp.quit()
        sys.exit(1)

    # 5. Verificación post-migración (auditoría de nuevo)
    print("\n=== VERIFICACIÓN POST-MIGRACIÓN ===")
    try:
        status, body = http_get(RUNNER_URL + "?token=" + TOKEN)
        print(f"HTTP {status}")
        print(body)
    except Exception as e:
        print(f"[FAIL] Error en verificación: {e}")
        ftp.quit()
        sys.exit(1)

    # 6. Eliminar runner
    print("\n=== ELIMINAR RUNNER ===")
    try:
        ftp.delete(RUNNER_REMOTE)
        print("[OK] Runner eliminado del servidor")
    except Exception as e:
        print(f"[FAIL] No se pudo eliminar runner: {e}")
        ftp.quit()
        sys.exit(1)
    ftp.quit()

    # 7. Verificar que ya no es accesible
    print("\n=== VERIFICAR RUNNER NO ACCESIBLE ===")
    try:
        status, body = http_get(RUNNER_URL + "?token=" + TOKEN)
        print(f"[FAIL] Runner aún accesible (HTTP {status})")
        sys.exit(1)
    except urllib.error.HTTPError as e:
        print(f"[OK] Runner no accesible (HTTP {e.code})")
    except Exception as e:
        print(f"[OK] Runner no accesible ({e})")

    print("\n=== DEPLOY MIGRACIÓN LIVE COMPLETADO ===")

if __name__ == "__main__":
    main()
