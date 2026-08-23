#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
RESTAURACIÓN DE EMERGENCIA — SiteGround
Restaura los archivos del commit f860e90 (último commit de GitHub) en producción.
Los archivos ya fueron extraídos del commit a /tmp/restore_commit/.
Sube SOLO los 5 archivos del outbound que se tocaron en el deploy anterior.
1. Crea backup remoto de los archivos actuales (por si acaso).
2. Sube los archivos del commit.
3. Verifica MD5 tras cada subida.
"""
import ftplib
import os
import sys
import time
import hashlib
import io

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

# Archivos extraídos del commit f860e90
# En Git Bash /tmp = C:\Users\RioBabel\AppData\Local\Temp
LOCAL_BASE = r"C:\Users\RioBabel\AppData\Local\Temp\restore_commit"

REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"

# Mapeo: nombre local -> ruta remota relativa
DEPLOY_FILES = [
    "dashboard.php",
    "app.js",
    "respuestas.php",
    "analytics.php",
    "imap_sync.php",
]
# Ruta remota de cada archivo (relativa a REMOTE_BASE)
REMOTE_PATHS = {
    "dashboard.php": "dashboard.php",
    "app.js": "js/app.js",
    "respuestas.php": "tabs/respuestas.php",
    "analytics.php": "api/analytics.php",
    "imap_sync.php": "api/imap_sync.php",
}

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

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def download_to_md5(ftp, remote_path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return hashlib.md5(buf.getvalue()).hexdigest()
    except Exception:
        return None

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # ── 1. BACKUP REMOTO de los archivos actuales ──
    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/outbound_restore_commit_{ts}"
    print(f"\n=== 1. BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)
    backed_up = []
    for name in DEPLOY_FILES:
        rel = REMOTE_PATHS[name]
        remote_src = REMOTE_BASE + "/" + rel
        remote_dst = remote_bk + "/" + rel
        try:
            ftp.size(remote_src)
        except Exception:
            print(f"  [SKIP] No existe en remoto: {rel}")
            continue
        dst_dir = os.path.dirname(remote_dst)
        if not ensure_remote_dir(ftp, dst_dir):
            continue
        buf = io.BytesIO()
        try:
            ftp.retrbinary("RETR " + remote_src, buf.write)
            buf.seek(0)
            ftp.storbinary("STOR " + remote_dst, buf)
            backed_up.append(rel)
            print(f"  [BACKUP] {rel}")
        except Exception as e:
            print(f"  [ERR] backup {rel}: {e}")
    print(f"  Respaldados {len(backed_up)}/{len(DEPLOY_FILES)} archivos")

    # ── 2. SUBIR LOS ARCHIVOS DEL COMMIT ──
    print("\n=== 2. RESTAURACIÓN (archivos del commit f860e90) ===")
    results = []
    for name in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, name)
        rel = REMOTE_PATHS[name]
        remote_path = REMOTE_BASE + "/" + rel
        if not os.path.exists(local_path):
            print(f"  [SKIP] No existe local: {name}")
            continue
        dst_dir = os.path.dirname(remote_path)
        if not ensure_remote_dir(ftp, dst_dir):
            results.append((rel, "ERROR_DIR"))
            continue
        local_md5 = file_md5(local_path)
        local_size = os.path.getsize(local_path)
        try:
            with open(local_path, "rb") as f:
                ftp.storbinary("STOR " + remote_path, f)
            remote_size = ftp.size(remote_path)
            remote_md5 = download_to_md5(ftp, remote_path)
            ok = (remote_size == local_size and remote_md5 == local_md5)
            status = "OK" if ok else "MISMATCH"
            results.append((rel, status, local_size, remote_size, local_md5, remote_md5))
            print(f"  [{'OK' if ok else 'MISMATCH'}] {rel} ({local_size} bytes) md5={local_md5}")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()

    # ── Manifest ──
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "restore_commit_manifest.txt"), "w") as f:
        f.write(f"commit=f860e90\n")
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("ARCHIVOS RESTAURADOS (commit -> remoto):\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    ok_count = sum(1 for r in results if r[1] == "OK")
    print(f"\nRestaurados OK: {ok_count}/{len(results)}")
    print("Restauración del commit completada.")

if __name__ == "__main__":
    main()
