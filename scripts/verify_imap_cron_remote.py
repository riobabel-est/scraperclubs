#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verificación del Cron IMAP en SiteGround (solo lectura, no modifica nada).
1. Descarga y muestra el log remoto `logs/imap_sync.log` (si existe) para ver
   si el cron se ha ejecutado y con qué frecuencia.
2. Muestra la fecha de última modificación del log y del runner cron.
3. Opcionalmente (--audit) ejecuta el runner en modo auditoría vía HTTP para
   comprobar que conecta a los buzones y detecta mensajes (sin escribir en BD).
"""
import ftplib
import os
import sys
import io
import time
import urllib.request

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
LOG_REMOTE = REMOTE_BASE + "/logs/imap_sync.log"
RUNNER_REMOTE = REMOTE_BASE + "/cli/imap_respuestas_cron.php"

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK\n")

    # ── 1. Estado del runner cron ──
    print("=== 1. RUNNER CRON (cli/imap_respuestas_cron.php) ===")
    try:
        size = ftp.size(RUNNER_REMOTE)
        mtime = ftp.sendcmd("MDTM " + RUNNER_REMOTE).split()[1]
        print(f"  Existe: SI | tamaño={size} bytes | última modificación={mtime}")
    except Exception as e:
        print(f"  Existe: NO o error ({e})")

    # ── 2. Log de sincronización IMAP ──
    print("\n=== 2. LOG DE SINCRONIZACIÓN (logs/imap_sync.log) ===")
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + LOG_REMOTE, buf.write)
        data = buf.getvalue().decode("utf-8", errors="replace")
        print(f"  Log existe: SI | tamaño={len(data)} bytes")
        try:
            mtime = ftp.sendcmd("MDTM " + LOG_REMOTE).split()[1]
            print(f"  Última modificación del log: {mtime}")
        except Exception:
            pass
        lines = data.strip().splitlines()
        print(f"  Total líneas de log: {len(lines)}")
        print("\n  ── ÚLTIMAS 40 LÍNEAS DEL LOG ──")
        for ln in lines[-40:]:
            print("  " + ln)
    except Exception as e:
        print(f"  Log NO existe o no accesible: {e}")
        print("  → Esto indica que el cron IMAP NUNCA se ha ejecutado en SiteGround.")

    ftp.quit()

    # ── 3. (Opcional) Auditoría HTTP del runner ──
    if "--audit" in sys.argv:
        print("\n=== 3. AUDITORÍA HTTP DEL RUNNER (solo lectura) ===")
        token = env.get("IMAP_CRON_SECRET", "IMAP_RESPUESTAS_CRON_20260820")
        url = f"https://getfutprotec.com/outbound/cli/imap_respuestas_cron.php?token={token}"
        print(f"  Invocando (modo auditoría, sin apply=1): {url}")
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(req, timeout=120) as resp:
                body = resp.read().decode("utf-8", errors="replace")
            print("  HTTP", resp.status)
            print("  ── SALIDA (primeras 60 líneas) ──")
            for ln in body.splitlines()[:60]:
                print("  " + ln)
        except Exception as e:
            print(f"  ERROR al invocar el runner: {e}")

    print("\nVerificación completada.")

if __name__ == "__main__":
    main()
