#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SYNC stats.db - FASE 2: TRANSFERENCIA BD LOCAL -> REMOTO
1. Sube public_html/outbound/data/stats.db (local validada) a /getfutprotec.com/public_html/outbound/data/stats.db
2. Verifica size + MD5 remoto == local.
3. Registra baseline PRE (size/mtime) y POST.
NO toca backups/, logs/, credenciales. Solo sustituye stats.db.
"""
import ftplib
import os
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

LOCAL_DB = "public_html/outbound/data/stats.db"
REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

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

    local_size = os.path.getsize(LOCAL_DB)
    local_md5 = file_md5(LOCAL_DB)
    print(f"\n=== BD LOCAL a subir ===")
    print(f"  size = {local_size} bytes")
    print(f"  md5  = {local_md5}")

    # ── 1. Baseline PRE remoto ──
    print("\n=== BASELINE PRE (remoto) ===")
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        pre_size = ftp.size("stats.db")
        pre_mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  size = {pre_size} bytes")
        print(f"  mtime = {pre_mtime}")
    except Exception as e:
        print(f"  [ERR] baseline pre: {e}")
        ftp.quit()
        return

    # ── 2. Subir BD local -> remoto ──
    print("\n=== SUBIENDO BD local -> remoto ===")
    with open(LOCAL_DB, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)
    print("  Subida completada")

    # ── 3. Verificar POST remoto ──
    print("\n=== VERIFICACION POST (remoto) ===")
    post_size = ftp.size("stats.db")
    post_mtime = ftp.sendcmd("MDTM stats.db")
    print(f"  size = {post_size} bytes (local={local_size})")
    print(f"  mtime = {post_mtime}")

    # Descargar para verificar MD5
    tmp = "backups_deploy/stats_db_verificacion_post.db"
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    post_md5 = file_md5(tmp)
    print(f"  md5 remoto = {post_md5} (local={local_md5})")

    ftp.quit()

    ok = (post_size == local_size) and (post_md5 == local_md5)
    print(f"\n=== RESULTADO: {'OK - BD sincronizada' if ok else 'ERROR - no coincide'} ===")

    with open("backups_deploy/sync_db_result.txt", "w") as f:
        f.write(f"pre_size={pre_size}\n")
        f.write(f"pre_mtime={pre_mtime}\n")
        f.write(f"local_size={local_size}\n")
        f.write(f"local_md5={local_md5}\n")
        f.write(f"post_size={post_size}\n")
        f.write(f"post_mtime={post_mtime}\n")
        f.write(f"post_md5={post_md5}\n")
        f.write(f"sync_ok={ok}\n")

    if not ok:
        print("  [CRITICO] La BD remota NO coincide con la local. Revisar.")

if __name__ == "__main__":
    main()
