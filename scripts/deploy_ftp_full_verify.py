#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - VERIFICACION COMPLETA MD5 LOCAL vs REMOTO (solo lectura)
Compara TODOS los archivos runtime del inventario completo entre local y SiteGround.
Clasifica cada archivo como MATCH / MISMATCH / MISSING_REMOTE / EXTRA_REMOTE.
NO modifica nada en remoto.
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

# Inventario COMPLETO de archivos runtime (relativos a outbound/)
RUNTIME_FILES = [
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
    "tabs/lista_negra.php",
    "tabs/modals.php",
    "tabs/respuestas.php",
    "tabs/smtp.php",
    "api/baja.php",
    "api/enviar_lote.php",
    "api/enviar_smtp_random.php",
    "api/get_cola.php",
    "api/lead_search.php",
    "api/lead_validate.php",
    "api/leads.php",
    "api/smtp.php",
    "api/track.php",
    "inc/abc.php",
    "inc/eligibilidad.php",
    "inc/metricas.php",
    "inc/mime.php",
    "inc/respuestas.php",
    "cli/cron.php",
    "cli/init_db.php",
]

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
    print(f"Root remoto: {REMOTE_BASE}\n")

    print("=== COMPARACION MD5 LOCAL vs REMOTO (inventario completo) ===")
    results = []
    for rel in RUNTIME_FILES:
        local_path = os.path.join(LOCAL_BASE, rel)
        remote_path = REMOTE_BASE + "/" + rel
        if not os.path.exists(local_path):
            results.append((rel, "NO_LOCAL", "-", "-"))
            print(f"  [NO_LOCAL] {rel}")
            continue
        local_md5 = file_md5(local_path)
        remote_md5 = download_to_md5(ftp, remote_path)
        if remote_md5 is None:
            results.append((rel, "MISSING_REMOTE", local_md5, "-"))
            print(f"  [MISSING_REMOTE] {rel} | local={local_md5}")
        elif remote_md5 == local_md5:
            results.append((rel, "MATCH", local_md5, remote_md5))
            print(f"  [MATCH] {rel}")
        else:
            results.append((rel, "MISMATCH", local_md5, remote_md5))
            print(f"  [MISMATCH] {rel} | local={local_md5} remote={remote_md5}")

    ftp.quit()

    # ── Resumen ──
    match = sum(1 for r in results if r[1] == "MATCH")
    mismatch = sum(1 for r in results if r[1] == "MISMATCH")
    missing = sum(1 for r in results if r[1] == "MISSING_REMOTE")
    no_local = sum(1 for r in results if r[1] == "NO_LOCAL")
    print("\n=== RESUMEN ===")
    print(f"  MATCH          = {match}")
    print(f"  MISMATCH       = {mismatch}")
    print(f"  MISSING_REMOTE = {missing}")
    print(f"  NO_LOCAL       = {no_local}")
    print(f"  TOTAL          = {len(results)}")

    # Guardar tabla
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "full_verify_manifest.txt"), "w") as f:
        f.write("| Archivo | Local MD5 | Remote MD5 | Estado |\n")
        f.write("| ------- | --------- | ---------- | ------ |\n")
        for rel, estado, lmd5, rmd5 in results:
            f.write(f"| {rel} | {lmd5} | {rmd5} | {estado} |\n")

    if mismatch == 0 and missing == 0:
        print("\nVEREDICTO: FULL_MD5_MATCH_PASS")
        return 0
    else:
        print("\nVEREDICTO: FULL_MD5_MATCH_BLOCKED")
        return 1

if __name__ == "__main__":
    sys.exit(main())
