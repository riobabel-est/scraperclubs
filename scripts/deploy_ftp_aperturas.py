#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy UI-FIX APERTURAS - SOLO tabs/modals.php
1. Inspecciona el archivo remoto (size + MD5) ANTES del deploy.
2. Crea backup remoto de modals.php en /getfutprotec.com/backups_deploy/.
3. Sube SOLO public_html/outbound/tabs/modals.php.
4. Verifica size + MD5 tras el upload.
NO toca stats.db, backups, logs, campañas, leads, plantillas, dashboard.php,
app.js, track.php, metricas.php, abc.php, eligibilidad.php, SMTP, cron, Evolution API.
NO ejecuta envíos ni POST.
"""
import ftplib
import os
import sys
import time
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

if not USER or not PASS:
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env")
    sys.exit(1)

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"
LOCAL_FILE = os.path.join("public_html", "outbound", "tabs", "modals.php")
REMOTE_FILE = REMOTE_BASE + "/tabs/modals.php"

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def ensure_remote_dir(ftp, path):
    parts = path.strip("/").split("/")
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass
            try:
                ftp.cwd(cur)
            except Exception as e:
                print(f"  [ERR] cwd {cur}: {e}")
                return False
    return True

def remote_md5(ftp, remote_path):
    """Descarga a memoria y calcula MD5."""
    import io
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return hashlib.md5(buf.getvalue()).hexdigest()
    except Exception as e:
        print(f"  [ERR] No se pudo leer remoto {remote_path}: {e}")
        return None

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    local_md5 = file_md5(LOCAL_FILE)
    local_size = os.path.getsize(LOCAL_FILE)
    print(f"\nLOCAL modals.php: size={local_size} md5={local_md5}")

    # ── 1. INSPECCION REMOTA (antes) ──
    print("\n=== 1. INSPECCION REMOTA (antes) ===")
    try:
        remote_size_before = ftp.size(REMOTE_FILE)
        remote_md5_before = remote_md5(ftp, REMOTE_FILE)
        print(f"  REMOTO modals.php: size={remote_size_before} md5={remote_md5_before}")
    except Exception as e:
        print(f"  [ERR] No se pudo inspeccionar remoto: {e}")
        ftp.quit()
        sys.exit(1)

    # ── 2. BACKUP REMOTO ──
    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk_dir = f"{REMOTE_BACKUP_BASE}/modals_pre_deploy_{ts}"
    print(f"\n=== 2. BACKUP REMOTO en {remote_bk_dir} ===")
    if not ensure_remote_dir(ftp, remote_bk_dir):
        print("  [ERR] No se pudo crear dir backup remoto")
        ftp.quit()
        sys.exit(1)
    remote_bk_file = remote_bk_dir + "/modals.php"
    try:
        with open(LOCAL_FILE, "rb") as f:
            ftp.storbinary("STOR " + remote_bk_file, f)
        print(f"  Backup remoto creado: {remote_bk_file}")
    except Exception as e:
        print(f"  [ERR] No se pudo crear backup remoto: {e}")
        ftp.quit()
        sys.exit(1)

    # ── 3. SUBIR SOLO modals.php ──
    print("\n=== 3. SUBIENDO modals.php ===")
    try:
        with open(LOCAL_FILE, "rb") as f:
            ftp.storbinary("STOR " + REMOTE_FILE, f)
        print("  Upload OK")
    except Exception as e:
        print(f"  [ERR] Upload fallo: {e}")
        ftp.quit()
        sys.exit(1)

    # ── 4. VERIFICACION (despues) ──
    print("\n=== 4. VERIFICACION (despues) ===")
    remote_size_after = ftp.size(REMOTE_FILE)
    remote_md5_after = remote_md5(ftp, REMOTE_FILE)
    print(f"  REMOTO modals.php: size={remote_size_after} md5={remote_md5_after}")
    ok_size = (remote_size_after == local_size)
    ok_md5 = (remote_md5_after == local_md5)
    print(f"  size match: {ok_size} | md5 match: {ok_md5}")

    ftp.quit()

    # ── Manifest ──
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "deploy_aperturas_manifest.txt"), "w") as f:
        f.write("DEPLOY UI-FIX APERTURAS (solo tabs/modals.php)\n")
        f.write(f"timestamp={ts}\n")
        f.write(f"local_size={local_size}\n")
        f.write(f"local_md5={local_md5}\n")
        f.write(f"remote_size_before={remote_size_before}\n")
        f.write(f"remote_md5_before={remote_md5_before}\n")
        f.write(f"remote_size_after={remote_size_after}\n")
        f.write(f"remote_md5_after={remote_md5_after}\n")
        f.write(f"backup_remote={remote_bk_file}\n")
        f.write(f"size_match={ok_size}\n")
        f.write(f"md5_match={ok_md5}\n")

    if ok_size and ok_md5:
        print("\nDEPLOY_OK: hash y tamaño coinciden.")
    else:
        print("\nDEPLOY_MISMATCH: revisar.")

if __name__ == "__main__":
    main()
