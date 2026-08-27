#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Deploy del código outbound a producción (SiteGround) vía FTP.

Sube el árbol public_html/outbound (PHP/JS/CSS/HTML) al servidor.
- Lee credenciales del .env (FTP_HOST/USER/PASS).
- Excluye por seguridad: data/ (BD ya desplegada), logs/, backups/,
  api/enviar_smtp_random.php (credenciales SMTP de producción — NUNCA se
  sobrescriben según reglas del proyecto), stats.db/outbound.db legacy vacíos,
  binario tailwindcss-windows-x64.exe.
- Solo sube archivos que difieran en tamaño o no existan en el remoto.

USO: python scripts/deploy_outbound_siteground.py
"""
import ftplib, os, sys

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
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env"); sys.exit(1)

LOCAL_ROOT = "public_html/outbound"
REMOTE_ROOT = "/getfutprotec.com/public_html/outbound"

EXCLUDE_DIRS = {"data", "logs", "backups", "node_modules", ".git", ".idea", ".vscode"}
EXCLUDE_FILES = {
    "enviar_smtp_random.php",  # credenciales SMTP de producción (regla del proyecto)
    "stats.db", "outbound.db", # BD legacy vacías
    ".gitignore", ".env", ".htrouter.php",
    "tailwindcss-windows-x64.exe",  # binario local
    # Mismos candidatos que optimizar_siteground.py (se mantienen fuera del server):
    "tailwind.config.js",
    "css/tailwind.css",
    "tabs/followups.php",
    "atribuir_respuestas_runner.php",
    "imap_diag_runner.php",
    "imap_respuestas_runner.php",
    "verificar_atribucion_runner.php",
    "cli/migracion_live_runner.php",
    "cli/migrar_estructura_local.php",
    "cli/migrar_plantillas_objetivo.php",
    "cli/migrar_secuencias.php",
}

subidos = 0
iguales = 0
errores = []

ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
ftp.encoding = "utf-8"

def asegurar_dirs(rel_dir):
    if not rel_dir:
        return
    partes = rel_dir.replace("\\", "/").split("/")
    acc = REMOTE_ROOT
    for p in partes:
        acc += "/" + p
        try:
            ftp.mkd(acc)
        except Exception:
            pass  # ya existe

print("=== Deploy codigo outbound -> SiteGround ===")
for root, dirs, files in os.walk(LOCAL_ROOT):
    # Podar directorios excluidos
    dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
    rel_root = os.path.relpath(root, LOCAL_ROOT)
    rel_root = "" if rel_root == "." else rel_root.replace("\\", "/")

    for f in sorted(files):
        local_path = os.path.join(root, f)
        rel = f if not rel_root else rel_root + "/" + f
        # Excluir por nombre base (raíz) o por ruta relativa completa (subdirs)
        if f in EXCLUDE_FILES or rel in EXCLUDE_FILES:
            continue
        remote_path = REMOTE_ROOT + "/" + rel
        local_size = os.path.getsize(local_path)

        # Tamaño remoto
        try:
            remote_size = ftp.size(remote_path)
        except Exception:
            remote_size = -1  # no existe

        if remote_size == local_size:
            iguales += 1
            continue

        asegurar_dirs(rel_root)
        try:
            with open(local_path, "rb") as fh:
                ftp.storbinary("STOR " + remote_path, fh)
            subidos += 1
            print(f"  OK {rel} ({local_size} bytes)")
        except Exception as e:
            errores.append((rel, str(e)))
            print(f"  ERR {rel}: {e}")

ftp.quit()
print(f"\nSubidos: {subidos} | Ya iguales: {iguales} | Errores: {len(errores)}")
for r, e in errores[:10]:
    print(f"  ERROR {r}: {e}")
print("OK deploy código completado.")
