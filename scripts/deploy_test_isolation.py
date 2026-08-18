#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FASE 7 — DEPLOY CONTROLADO TEST/REAL + VALIDACIÓN LIVE
Sube SOLO los 6 archivos corregidos del aislamiento TEST/REAL a SiteGround.

1. BACKUP remoto de los 6 archivos LIVE actuales (en /getfutprotec.com/backups_deploy/).
2. Registra SHA-256 de los archivos LIVE actuales (antes del deploy).
3. Sube los 6 archivos corregidos.
4. Verifica SHA-256 LOCAL vs LIVE tras cada subida.

NO sube stats.db, backups, runners, scripts de migración ni archivos de diagnóstico.
NO ejecuta SMTP, cron, enviar_lote, enviar_smtp_random ni Evolution API.
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

# SOLO los 6 archivos corregidos del aislamiento TEST/REAL
DEPLOY_FILES = [
    "dashboard.php",
    "inc/eligibilidad.php",
    "inc/metricas.php",
    "api/enviar_lote.php",
    "api/smtp.php",
    "tabs/smtp.php",
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

def file_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def download_to_sha256(ftp, remote_path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + remote_path, buf.write)
        return hashlib.sha256(buf.getvalue()).hexdigest()
    except Exception:
        return None

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    remote_bk = f"{REMOTE_BACKUP_BASE}/outbound_test_isolation_{ts}"
    print(f"\n=== PASO 1: BACKUP REMOTO en {remote_bk} ===")
    if not ensure_remote_dir(ftp, remote_bk):
        print("  [ERR] No se pudo crear directorio de backup remoto")
        ftp.quit()
        sys.exit(1)

    backed_up = []
    pre_hashes = {}
    for rel in DEPLOY_FILES:
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
            pre_hashes[rel] = hashlib.sha256(buf.getvalue()).hexdigest()
            buf.seek(0)
            ftp.storbinary("STOR " + remote_dst, buf)
            backed_up.append(rel)
            print(f"  [BACKUP] {rel} sha256={pre_hashes[rel][:16]}...")
        except Exception as e:
            print(f"  [ERR] backup {rel}: {e}")
    print(f"  Respaldados {len(backed_up)}/{len(DEPLOY_FILES)} archivos")

    print("\n=== PASO 3: SUBIDA DE LOS 6 ARCHIVOS CORREGIDOS ===")
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
        local_sha = file_sha256(local_path)
        local_size = os.path.getsize(local_path)
        try:
            with open(local_path, "rb") as f:
                ftp.storbinary("STOR " + remote_path, f)
            remote_size = ftp.size(remote_path)
            remote_sha = download_to_sha256(ftp, remote_path)
            ok = (remote_size == local_size and remote_sha == local_sha)
            status = "OK" if ok else "MISMATCH"
            results.append((rel, status, local_size, remote_size, local_sha, remote_sha))
            print(f"  [{'OK' if ok else 'MISMATCH'}] {rel} ({local_size} bytes) sha256={local_sha[:16]}...")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()

    os.makedirs("backups_deploy", exist_ok=True)
    manifest = os.path.join("backups_deploy", "deploy_test_isolation_manifest.txt")
    with open(manifest, "w") as f:
        f.write(f"backup_remote={remote_bk}\n")
        f.write(f"timestamp={ts}\n")
        f.write("SHA-256 PRE-DEPLOY (LIVE actual):\n")
        for rel, sha in pre_hashes.items():
            f.write(f"  {rel} = {sha}\n")
        f.write("ARCHIVOS SUBIDOS (local -> remoto):\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")
    print(f"\nManifest: {manifest}")

    ok_count = sum(1 for r in results if r[1] == "OK")
    print(f"Subidos OK: {ok_count}/{len(results)}")
    if ok_count == len(DEPLOY_FILES):
        print("DEPLOY_TEST_ISOLATION_UPLOAD = PASS")
    else:
        print("DEPLOY_TEST_ISOLATION_UPLOAD = FAIL")
        sys.exit(1)

if __name__ == "__main__":
    main()
