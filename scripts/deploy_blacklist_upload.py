#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - FASE 3: UPLOAD CONTROLADO
Sube SOLO los 3 archivos autorizados:
  dashboard.php
  tabs/lista_negra.php
  js/app.js
NO sube stats.db, enviar_lote.php, get_cola.php, baja.php, mime.php, lanzadera.php
ni ningun otro archivo.
Verifica cada subida (size + MD5) tras el upload.
"""
import ftplib
import os
import sys
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

# SOLO los 3 archivos autorizados
DEPLOY_FILES = [
    "dashboard.php",
    "tabs/lista_negra.php",
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
            # Verificar size
            remote_size = ftp.size(remote_path)
            ok_size = (remote_size == local_size)
            # Verificar MD5
            remote_md5 = download_to_md5(ftp, remote_path)
            ok_md5 = (remote_md5 == local_md5)
            status = "OK" if (ok_size and ok_md5) else "MISMATCH"
            results.append((rel, status, local_size, remote_size, local_md5, remote_md5))
            print(f"  [{'OK' if status=='OK' else 'MISMATCH'}] {rel} ({local_size} bytes) md5={local_md5}")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()

    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "blacklist_upload_manifest.txt"), "w") as f:
        f.write("ARCHIVOS DESPLEGADOS (local -> remoto):\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    ok_count = sum(1 for r in results if r[1] == "OK")
    print(f"\nSubidos OK: {ok_count}/{len(results)}")
    print("Deploy controlado completado.")

if __name__ == "__main__":
    main()
