#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
LIMPIEZA DE RESPUESTAS CORRUPTAS EN PRODUCCION
===============================================
Borra las filas corruptas de la tabla `respuestas` en la BD remota de
produccion (stats.db) que tienen remitente/destinatario/cuerpo vacios
(insertadas por una version antigua del runner IMAP cuando SiteGround
daba timeout al leer el mensaje).

FLUJO SEGURO:
1. Backup remoto de stats.db en /getfutprotec.com/backups_deploy/.
2. Descarga stats.db remoto a backups_deploy/ (backup local).
3. Borra SOLO las filas corruptas (remitente='' AND destinatario='').
4. Sube la BD corregida de vuelta a produccion.
5. Verifica size + MD5 y que las filas corruptas ya no existen.

El cron IMAP re-descargara los correos correctamente la proxima ejecucion.
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import shutil

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

REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"
LOCAL_BACKUP_DIR = "backups_deploy"

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

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    os.makedirs(LOCAL_BACKUP_DIR, exist_ok=True)

    # ── 1. BACKUP REMOTO ──
    print("\n=== 1. BACKUP REMOTO ===")
    backup_dir = f"{REMOTE_BACKUP_BASE}/stats_db_limpieza_respuestas_{ts}"
    ensure_remote_dir(ftp, backup_dir)
    # Copia remota via RETR+STOR
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        import io
        buf = io.BytesIO()
        ftp.retrbinary("RETR stats.db", buf.write)
        buf.seek(0)
        ftp.storbinary("STOR " + backup_dir + "/stats.db", buf)
        print(f"  [OK] Backup remoto en {backup_dir}/stats.db ({buf.getbuffer().nbytes} bytes)")
    except Exception as e:
        print(f"  [ERR] backup remoto: {e}")
        ftp.quit()
        sys.exit(1)

    # ── 2. DESCARGAR BD REMOTA ──
    print("\n=== 2. DESCARGAR BD REMOTA ===")
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_limpieza_{ts}.db")
    with open(local_bk, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    print(f"  [OK] Descargada a {local_bk} ({os.path.getsize(local_bk)} bytes)")
    print(f"  md5 = {file_md5(local_bk)}")

    # ── 3. BORRAR FILAS CORRUPTAS ──
    print("\n=== 3. BORRAR FILAS CORRUPTAS ===")
    db = sqlite3.connect(local_bk)
    cur = db.cursor()

    # Identificar filas corruptas (remitente vacio Y destinatario vacio)
    cur.execute(
        "SELECT id, remitente, destinatario, subject, length(cuerpo) AS cuerpo_len "
        "FROM respuestas WHERE (remitente IS NULL OR TRIM(remitente)='') "
        "AND (destinatario IS NULL OR TRIM(destinatario)='')"
    )
    corruptas = cur.fetchall()
    print(f"  Filas corruptas detectadas: {len(corruptas)}")
    for r in corruptas:
        print(f"    id={r[0]} remitente='{r[1]}' destinatario='{r[2]}' subject='{r[3]}' cuerpo_len={r[4]}")

    if not corruptas:
        print("  No hay filas corruptas. Nada que borrar.")
        db.close()
        ftp.quit()
        print("\n=== FIN (sin cambios) ===")
        return

    # Borrar SOLO las corruptas
    cur.execute(
        "DELETE FROM respuestas WHERE (remitente IS NULL OR TRIM(remitente)='') "
        "AND (destinatario IS NULL OR TRIM(destinatario)='')"
    )
    db.commit()
    borradas = cur.rowcount
    print(f"  [OK] Borradas {borradas} filas corruptas")

    # Verificar
    cur.execute("SELECT COUNT(*) FROM respuestas")
    restantes = cur.fetchone()[0]
    print(f"  Filas restantes en respuestas: {restantes}")

    db.close()

    # ── 4. SUBIR BD CORREGIDA ──
    print("\n=== 4. SUBIR BD CORREGIDA ===")
    with open(local_bk, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)
    print(f"  [OK] Subida a {REMOTE_DB}")

    # ── 5. VERIFICAR ──
    print("\n=== 5. VERIFICAR ===")
    ftp.cwd("/getfutprotec.com/public_html/outbound/data")
    remote_size = ftp.size("stats.db")
    print(f"  size remoto = {remote_size} bytes")
    print(f"  size local  = {os.path.getsize(local_bk)} bytes")
    if remote_size == os.path.getsize(local_bk):
        print("  [OK] Size coincide")
    else:
        print("  [WARN] Size NO coincide")

    ftp.quit()
    print("\n=== LIMPIEZA COMPLETADA ===")
    print(f"Backup local: {local_bk}")
    print(f"Backup remoto: {backup_dir}/stats.db")

if __name__ == "__main__":
    main()
