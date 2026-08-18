#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Lista Negra BIDIRECCIONAL - FASE 4/5/6: BACKUP + UPLOAD + VERIFY
Sube SOLO los 4 archivos funcionales autorizados de la funcionalidad
"Lista Negra bidireccional" (BLACKLIST_BIDIRECTIONAL_MANAGEMENT_PASS):
  dashboard.php
  tabs/lista_negra.php
  tabs/modals.php
  js/app.js

NO sube stats.db, enviar_lote.php, get_cola.php, baja.php, mime.php,
track.php, cron.php ni ningun otro archivo.

Flujo:
  1) BACKUP remoto de los 4 archivos (en /getfutprotec.com/backups_deploy/)
  2) UPLOAD controlado con verificacion size + MD5
  3) VERIFY final LOCAL == REMOTE (MD5) para cada archivo
Cualquier mismatch => exit code 1 (BLOCKED).
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

LOCAL_BASE = os.path.join("public_html", "outbound")
REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"

# SOLO los 4 archivos funcionales autorizados
DEPLOY_FILES = [
    "dashboard.php",
    "tabs/lista_negra.php",
    "tabs/modals.php",
    "js/app.js",
]

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

def download_to_bytes(ftp, remote_path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return buf.getvalue()
    except Exception as e:
        print(f"  [WARN] No se pudo descargar {remote_path}: {e}")
        return None

def upload_bytes(ftp, data, remote_path):
    try:
        ftp.storbinary("STOR " + remote_path, io.BytesIO(data))
        return True
    except Exception as e:
        print(f"  [ERR] No se pudo subir {remote_path}: {e}")
        return False

def md5_bytes(data):
    return hashlib.md5(data).hexdigest()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/blacklist_bidirectional_pre_{ts}"
    print(f"\n=== FASE 1: BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)

    backup_manifest = []
    for rel in DEPLOY_FILES:
        remote_src = REMOTE_BASE + "/" + rel
        remote_dst = remote_bk + "/" + rel
        try:
            size = ftp.size(remote_src)
        except Exception:
            print(f"  [SKIP] No existe en remoto: {rel}")
            continue
        data = download_to_bytes(ftp, remote_src)
        if data is None:
            continue
        md5 = md5_bytes(data)
        dst_dir = os.path.dirname(remote_dst)
        if not ensure_remote_dir(ftp, dst_dir):
            continue
        if upload_bytes(ftp, data, remote_dst):
            backup_manifest.append((rel, size, md5, ts, remote_dst))
            print(f"  [OK] backup {rel} | size={size} | md5={md5}")

    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "blacklist_bidirectional_backup_manifest.txt"), "w") as f:
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("archivos_respaldados (ruta | size | md5 | ts | backup):\n")
        for rel, size, md5, t, dst in backup_manifest:
            f.write(f"  {rel} | {size} | {md5} | {t} | {dst}\n")
    print(f"Backup completado. {len(backup_manifest)} archivos respaldados.")

    print(f"\n=== FASE 2: UPLOAD CONTROLADO ===")
    upload_results = []
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
        if not os.path.exists(local_path):
            print(f"  [SKIP] No existe local: {rel}")
            continue
        dst_dir = os.path.dirname(remote_path)
        if not ensure_remote_dir(ftp, dst_dir):
            upload_results.append((rel, "ERROR_DIR"))
            continue
        local_md5 = file_md5(local_path)
        local_size = os.path.getsize(local_path)
        try:
            with open(local_path, "rb") as f:
                ftp.storbinary("STOR " + remote_path, f)
            remote_size = ftp.size(remote_path)
            ok_size = (remote_size == local_size)
            remote_md5 = md5_bytes(download_to_bytes(ftp, remote_path))
            ok_md5 = (remote_md5 == local_md5)
            status = "OK" if (ok_size and ok_md5) else "MISMATCH"
            upload_results.append((rel, status, local_size, remote_size, local_md5, remote_md5))
            print(f"  [{'OK' if status=='OK' else 'MISMATCH'}] {rel} ({local_size} bytes) md5={local_md5}")
        except Exception as e:
            upload_results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    with open(os.path.join("backups_deploy", "blacklist_bidirectional_upload_manifest.txt"), "w") as f:
        f.write("ARCHIVOS DESPLEGADOS (local -> remoto):\n")
        for r in upload_results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    print(f"\n=== FASE 3: VERIFY FINAL (LOCAL == REMOTE) ===")
    all_ok = True
    verify_results = []
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
        if not os.path.exists(local_path):
            continue
        local_md5 = file_md5(local_path)
        remote_data = download_to_bytes(ftp, remote_path)
        if remote_data is None:
            verify_results.append((rel, "NO_REMOTE"))
            all_ok = False
            print(f"  [FAIL] {rel}: no se pudo leer remoto")
            continue
        remote_md5 = md5_bytes(remote_data)
        ok = (remote_md5 == local_md5)
        verify_results.append((rel, "OK" if ok else "MISMATCH", local_md5, remote_md5))
        if ok:
            print(f"  [OK] {rel} LOCAL==REMOTE md5={local_md5}")
        else:
            all_ok = False
            print(f"  [MISMATCH] {rel} local={local_md5} remote={remote_md5}")

    with open(os.path.join("backups_deploy", "blacklist_bidirectional_verify_manifest.txt"), "w") as f:
        f.write("VERIFY FINAL (local | estado | md5_local | md5_remote):\n")
        for r in verify_results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    ftp.quit()

    ok_count = sum(1 for r in upload_results if r[1] == "OK")
    print(f"\nSubidos OK: {ok_count}/{len(upload_results)}")
    if all_ok and ok_count == len(DEPLOY_FILES):
        print("VEREDICTO: BLACKLIST_BIDIRECTIONAL_PRODUCTION_PASS")
        sys.exit(0)
    else:
        print("VEREDICTO: BLOCKED (mismatch o archivo no subido)")
        sys.exit(1)

if __name__ == "__main__":
    main()
