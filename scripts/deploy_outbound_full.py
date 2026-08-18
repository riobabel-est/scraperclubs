#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - OUTBOUND COMPLETO
Sube TODOS los archivos de código del módulo outbound a SiteGround:
  - dashboard.php, .htaccess, .htrouter.php, README.md
  - api/*.php
  - cli/*.php
  - inc/*.php
  - js/*.js
  - css/*.css
  - tabs/*.php
Excluye: bases de datos (*.db, *.db-shm, *.db-wal), backups/, logs/,
binarios (tailwindcss-windows-x64.exe), .gitignore y temporales.
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

# Extensiones de código a subir
ALLOWED_EXT = {".php", ".js", ".css", ".html", ".htaccess", ".json", ".md", ".txt"}
# Directorios a excluir (no se suben)
EXCLUDE_DIRS = {"backups", "logs", "data", ".git", "node_modules"}
# Archivos a excluir
EXCLUDE_FILES = {".gitignore", "tailwindcss-windows-x64.exe", "outbound.db"}

def collect_files(base):
    """Recorre LOCAL_BASE y devuelve lista de rutas relativas de código."""
    files = []
    for root, dirs, names in os.walk(base):
        # Filtrar directorios excluidos
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
        for name in names:
            if name in EXCLUDE_FILES:
                continue
            ext = os.path.splitext(name)[1].lower()
            if ext not in ALLOWED_EXT:
                continue
            full = os.path.join(root, name)
            rel = os.path.relpath(full, base).replace("\\", "/")
            files.append(rel)
    return sorted(files)

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
    files = collect_files(LOCAL_BASE)
    print(f"Archivos de código a desplegar: {len(files)}")
    for f in files:
        print(f"  - {f}")

    print(f"\nConectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    results = []
    for rel in files:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
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
            print(f"  [{'OK' if ok else 'MISMATCH'}] {rel} ({local_size} bytes)")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()

    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "deploy_outbound_full_manifest.txt"), "w") as f:
        f.write("DEPLOY OUTBOUND COMPLETO (local -> remoto):\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    ok_count = sum(1 for r in results if r[1] == "OK")
    mismatch = sum(1 for r in results if r[1] == "MISMATCH")
    err = sum(1 for r in results if r[1] not in ("OK", "MISMATCH"))
    print(f"\nSubidos OK: {ok_count}/{len(results)} | MISMATCH: {mismatch} | ERROR: {err}")
    if ok_count == len(results):
        print("VEREDICTO: DEPLOY_OUTBOUND_FULL_PASS")
        return 0
    else:
        print("VEREDICTO: DEPLOY_OUTBOUND_FULL_BLOCKED")
        return 1

if __name__ == "__main__":
    sys.exit(main())
