#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy BAJA.PHP (GO-LIVE UNSUBSCRIBE) — FASE 3/4/5
Sube SOLO:
  - api/baja.php

1. Backup remoto de api/baja.php en /getfutprotec.com/backups_deploy/
   (registra tamaño, MD5 y timestamp del archivo remoto ANTES de sobrescribir)
2. Subida local -> remoto
3. Verificacion MD5 local vs remoto (debe ser MATCH)

NO toca stats.db, campañas, leads, plantillas, SMTP, app.js, lanzadera,
enviar_lote, mime, abc, eligibilidad, track, cron.
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

LOCAL_BASE = os.path.join("public_html", "outbound")
REMOTE_BASE = "/getfutprotec.com/public_html/outbound"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"

# SOLO el archivo autorizado
DEPLOY_FILES = [
    "api/baja.php",
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

    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/baja_go_live_pre_deploy_{ts}"
    print(f"\n=== FASE 3: BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)

    backed_up = []
    backup_info = []
    for rel in DEPLOY_FILES:
        remote_src = REMOTE_BASE + "/" + rel
        remote_dst = remote_bk + "/" + rel
        try:
            remote_size = ftp.size(remote_src)
            remote_mtime = ftp.sendcmd("MDTM " + remote_src)
        except Exception:
            print(f"  [SKIP] No existe en remoto: {rel}")
            continue
        dst_dir = os.path.dirname(remote_dst)
        if not ensure_remote_dir(ftp, dst_dir):
            continue
        # Descargar a local temporal y re-subir al backup remoto
        local_tmp = os.path.join("backups_deploy", "remote_pre_deploy", rel)
        os.makedirs(os.path.dirname(local_tmp), exist_ok=True)
        try:
            with open(local_tmp, "wb") as f:
                ftp.retrbinary("RETR " + remote_src, f.write)
            remote_md5 = file_md5(local_tmp)
            with open(local_tmp, "rb") as f:
                ftp.storbinary("STOR " + remote_dst, f)
            backed_up.append(rel)
            backup_info.append((rel, remote_size, remote_md5, remote_mtime))
            print(f"  [BACKUP] {rel} | size={remote_size} | md5={remote_md5} | mtime={remote_mtime}")
        except Exception as e:
            print(f"  [ERR] backup {rel}: {e}")

    print(f"  Respaldados {len(backed_up)}/{len(DEPLOY_FILES)} archivos")

    # ── FASE 4: DEPLOY (subida) ──
    print("\n=== FASE 4: DEPLOY ===")
    results = []
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
        if not os.path.exists(local_path):
            print(f"  [SKIP] No existe local: {rel}")
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
            ok_size = (remote_size == local_size)
            status = "OK" if ok_size else "SIZE_MISMATCH"
            results.append((rel, status, local_size, remote_size, local_md5))
            print(f"  [{'OK' if ok_size else 'SIZE_MISMATCH'}] {rel} ({local_size} bytes)")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    # ── FASE 5: VERIFICACION MD5 ──
    print("\n=== FASE 5: VERIFICACION MD5 LOCAL vs REMOTO ===")
    all_ok = True
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        if not os.path.exists(local_path):
            continue
        local_md5 = file_md5(local_path)
        remote_md5 = download_to_md5(ftp, REMOTE_BASE + "/" + rel)
        if remote_md5 == local_md5:
            print(f"  [MATCH] {rel} | md5={local_md5}")
        else:
            print(f"  [MISMATCH] {rel} | local={local_md5} remote={remote_md5}")
            all_ok = False

    ftp.quit()

    # Guardar manifest
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "deploy_baja_go_live_manifest.txt"), "w") as f:
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("backup_info (size|md5|mtime):\n")
        for rel, size, md5, mtime in backup_info:
            f.write(f"  {rel} | size={size} | md5={md5} | mtime={mtime}\n")
        f.write("deploy_results:\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")
        f.write(f"md5_all_match={all_ok}\n")

    print("\n=== RESUMEN ===")
    print(f"  Backup remoto: {remote_bk}")
    print(f"  MD5 all match: {all_ok}")
    if all_ok:
        print("  VEREDICTO DEPLOY: OK")
    else:
        print("  VEREDICTO DEPLOY: MISMATCH")
        sys.exit(1)

if __name__ == "__main__":
    main()
