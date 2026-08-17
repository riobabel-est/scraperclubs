#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy FTP - FASE 3: SUBIDA DE ARCHIVOS RUNTIME
Sube la version actual (local) de los archivos runtime a /outbound/ remoto.
EXCLUYE: data/, backups/, logs/, *.db, tailwindcss-windows-x64.exe, README.md, .gitignore.
Verifica cada subida (size + MD5) tras el upload.
NO ejecuta SMTP, cron, enviar_lote, enviar_smtp_random ni Evolution API.
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

# Archivos runtime a desplegar (relativos a outbound/)
DEPLOY_FILES = [
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
    "tabs/modals.php",
    "tabs/respuestas.php",
    "tabs/smtp.php",
    "api/baja.php",
    "api/enviar_lote.php",
    "api/enviar_smtp_random.php",
    "api/get_cola.php",
    "api/leads.php",
    "api/smtp.php",
    "api/track.php",
    "cli/cron.php",
    "cli/init_db.php",
    "inc/abc.php",
    "inc/eligibilidad.php",
    "inc/metricas.php",
    "inc/respuestas.php",
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
        # Crear dirs remotos
        dst_dir = os.path.dirname(remote_path)
        if not ensure_remote_dir(ftp, dst_dir):
            results.append((rel, "ERROR_DIR"))
            continue
        local_md5 = file_md5(local_path)
        local_size = os.path.getsize(local_path)
        try:
            with open(local_path, "rb") as f:
                ftp.storbinary("STOR " + remote_path, f)
            # Verificar
            remote_size = ftp.size(remote_path)
            ok_size = (remote_size == local_size)
            status = "OK" if ok_size else "SIZE_MISMATCH"
            results.append((rel, status, local_size, remote_size, local_md5))
            print(f"  [{'OK' if ok_size else 'SIZE_MISMATCH'}] {rel} ({local_size} bytes)")
        except Exception as e:
            results.append((rel, f"ERROR: {e}"))
            print(f"  [ERR] {rel}: {e}")

    ftp.quit()

    # Guardar manifest
    os.makedirs("backups_deploy", exist_ok=True)
    with open(os.path.join("backups_deploy", "deploy_manifest.txt"), "w") as f:
        f.write("ARCHIVOS DESPLEGADOS (local -> remoto):\n")
        for r in results:
            f.write("  " + " | ".join(str(x) for x in r) + "\n")

    ok_count = sum(1 for r in results if r[1] == "OK")
    print(f"\nSubidos OK: {ok_count}/{len(results)}")
    print("Deploy completado.")

if __name__ == "__main__":
    main()
