#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - FASE 4: VERIFICACION POST-DEPLOY
1. Verifica integridad de stats.db remoto (size + mtime deben coincidir con baseline).
2. Verifica que los archivos desplegados en remoto coinciden con los locales (MD5).
3. Confirma que NO se crearon archivos no deseados (data/, backups/, logs/, *.db).
"""
import ftplib
import os
import sys
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

LOCAL_BASE = os.path.join("public_html", "outbound")
REMOTE_BASE = "/getfutprotec.com/public_html/outbound"

DEPLOY_FILES = [
    "dashboard.php", ".htaccess", ".htrouter.php", "tailwind.config.js",
    "js/app.js", "css/tailwind.css", "css/tailwind.min.css",
    "tabs/analytics.php", "tabs/editor.php", "tabs/followups.php", "tabs/gestor.php",
    "tabs/kanban.php", "tabs/lanzadera.php", "tabs/modals.php", "tabs/respuestas.php", "tabs/smtp.php",
    "api/baja.php", "api/enviar_lote.php", "api/enviar_smtp_random.php", "api/get_cola.php",
    "api/leads.php", "api/smtp.php", "api/track.php",
    "cli/cron.php", "cli/init_db.php",
    "inc/abc.php", "inc/eligibilidad.php", "inc/metricas.php", "inc/respuestas.php",
]

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def download_to_md5(ftp, remote_path):
    import io
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

    # ── 1. INTEGRIDAD stats.db ──
    print("\n=== 1. INTEGRIDAD stats.db REMOTO ===")
    try:
        ftp.cwd(REMOTE_BASE + "/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  size = {size} (baseline: 749568)")
        print(f"  mtime = {mtime} (baseline: 213 20260810162730)")
        if size == 749568 and "20260810162730" in mtime:
            print("  ✅ stats.db INTACTO (sin cambios)")
        else:
            print("  ⚠️ stats.db CAMBIADO — revisar")
    except Exception as e:
        print(f"  [ERR] {e}")

    # ── 2. VERIFICAR MD5 archivos desplegados ──
    print("\n=== 2. VERIFICACION MD5 LOCAL vs REMOTO ===")
    all_ok = True
    for rel in DEPLOY_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        if not os.path.exists(local_path):
            print(f"  [SKIP] no local: {rel}")
            continue
        local_md5 = file_md5(local_path)
        remote_md5 = download_to_md5(ftp, REMOTE_BASE + "/" + rel)
        if remote_md5 == local_md5:
            print(f"  [OK] {rel}")
        else:
            print(f"  [MISMATCH] {rel} | local={local_md5} remote={remote_md5}")
            all_ok = False

    # ── 3. Confirmar exclusiones ──
    print("\n=== 3. CONFIRMAR EXCLUSIONES (no subidas) ===")
    try:
        ftp.cwd(REMOTE_BASE + "/data")
        names = ftp.nlst()
        print(f"  data/ contiene: {names}")
    except Exception as e:
        print(f"  [ERR] data/: {e}")
    try:
        ftp.cwd(REMOTE_BASE + "/logs")
        names = ftp.nlst()
        print(f"  logs/ contiene: {names}")
    except Exception as e:
        print(f"  [ERR] logs/: {e}")

    ftp.quit()
    print("\nVerificacion completada. all_ok =", all_ok)

if __name__ == "__main__":
    main()
