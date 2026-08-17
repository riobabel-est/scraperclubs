#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - FASE 2: BACKUP REMOTO + BASELINE BD + COMPARACION
1. Registra baseline de stats.db remoto (size + mtime) ANTES de cualquier cambio.
2. Descarga los archivos runtime remotos que seran sobrescritos a backups_deploy/local.
3. Crea backup remoto de los archivos runtime en /getfutprotec.com/backups_deploy/ (fuera de public_html).
NO sube nada. NO toca data/, backups/, logs/.
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

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"
LOCAL_BACKUP = os.path.join("backups_deploy", "remote_pre_deploy")

# Archivos runtime a desplegar (relativos a outbound/)
DEPLOY_FILES = [
    "dashboard.php",
    ".htaccess",
    ".htrouter.php",
    "tailwind.config.js",
    "js/app.js",
    "css/tailwind.css",
    "css/tailwind.min.css",
    "tabs/analytics.php",
    "tabs/editor.php",
    "tabs/followups.php",
    "tabs/gestor.php",
    "tabs/kanban.php",
    "tabs/lanzadera.php",
    "tabs/modals.php",
    "tabs/respuestas.php",
    "tabs/smtp.php",
    "api/baja.php",
    "api/enviar_lote.php",
    "api/enviar_smtp_random.php",
    "api/get_cola.php",
    "api/leads.php",
    "api/smtp.php",
    "api/track.php",
    "cli/cron.php",
    "cli/init_db.php",
    "inc/abc.php",
    "inc/eligibilidad.php",
    "inc/metricas.php",
    "inc/respuestas.php",
]

def ensure_remote_dir(ftp, path):
    """Crea directorio remoto recursivamente si no existe."""
    parts = path.strip("/").split("/")
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception as e:
                print(f"  [WARN] mkd {cur}: {e}")
            try:
                ftp.cwd(cur)
            except Exception as e:
                print(f"  [ERR] cwd {cur}: {e}")
                return False
    return True

def download_file(ftp, remote_path, local_path):
    os.makedirs(os.path.dirname(local_path), exist_ok=True)
    try:
        with open(local_path, "wb") as f:
            ftp.retrbinary("RETR " + remote_path, f.write)
        return True
    except Exception as e:
        print(f"  [WARN] No se pudo descargar {remote_path}: {e}")
        return False

def upload_file(ftp, local_path, remote_path):
    try:
        with open(local_path, "rb") as f:
            ftp.storbinary("STOR " + remote_path, f)
        return True
    except Exception as e:
        print(f"  [ERR] No se pudo subir {remote_path}: {e}")
        return False

def file_md5(path):
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

    # ── 1. BASELINE stats.db remoto ──
    print("\n=== BASELINE stats.db REMOTO (antes de cambios) ===")
    try:
        ftp.cwd(REMOTE_BASE + "/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  stats.db size = {size} bytes")
        print(f"  stats.db mtime = {mtime}")
        with open(os.path.join("backups_deploy", "stats_db_baseline.txt"), "w") as f:
            f.write(f"size={size}\nmtime={mtime}\n")
    except Exception as e:
        print(f"  [ERR] No se pudo obtener baseline stats.db: {e}")

    # ── 2. Descargar runtime remoto a local (para comparacion) ──
    print("\n=== DESCARGANDO runtime remoto a local (comparacion) ===")
    downloaded = []
    for rel in DEPLOY_FILES:
        remote_path = REMOTE_BASE + "/" + rel
        local_path = os.path.join(LOCAL_BACKUP, rel)
        if download_file(ftp, remote_path, local_path):
            downloaded.append(rel)
    print(f"  Descargados {len(downloaded)}/{len(DEPLOY_FILES)} archivos remotos")

    # ── 3. Backup remoto en /getfutprotec.com/backups_deploy/ ──
    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/outbound_pre_deploy_{ts}"
    print(f"\n=== CREANDO BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)

    backed_up = []
    for rel in DEPLOY_FILES:
        remote_src = REMOTE_BASE + "/" + rel
        remote_dst = remote_bk + "/" + rel
        # Verificar que el archivo remoto existe antes de respaldarlo
        try:
            ftp.size(remote_src)
        except Exception:
            print(f"  [SKIP] No existe en remoto: {rel}")
            continue
        # Crear dirs remotos de destino
        dst_dir = os.path.dirname(remote_dst)
        if not ensure_remote_dir(ftp, dst_dir):
            continue
        if upload_file(ftp, os.path.join(LOCAL_BACKUP, rel), remote_dst):
            backed_up.append(rel)

    print(f"  Respaldados {len(backed_up)} archivos en backup remoto")
    with open(os.path.join("backups_deploy", "backup_manifest.txt"), "w") as f:
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("archivos_respaldados:\n")
        for rel in backed_up:
            f.write(f"  {rel}\n")

    ftp.quit()
    print("\nBackup + baseline + comparacion completados.")

if __name__ == "__main__":
    main()
