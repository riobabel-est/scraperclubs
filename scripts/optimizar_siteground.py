#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Audita y optimiza el directorio outbound en SiteGround vía FTP.

- Lista recursivamente el árbol remoto con tamaños.
- Clasifica candidatos a TRASH (archivos no necesarios o dudosos) y los MUEVE
  (rename) a trash/outbound_<ts>/ preservando la ruta relativa. NO BORRA NADA:
  todo lo dudoso termina en trash hasta confirmar que es realmente inútil.
- Protege por regla del proyecto: api/enviar_smtp_random.php NUNCA se mueve.

USO:
  python scripts/optimizar_siteground.py            # dry-run (solo plan)
  python scripts/optimizar_siteground.py --apply    # mueve a trash
"""
import ftplib, os, sys, datetime

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

APPLY = "--apply" in sys.argv
env = load_env()
HOST = env.get("FTP_HOST", "ftp.getfutprotec.com")
USER = env.get("FTP_USER", "")
PASS = env.get("FTP_PASS", "")
if not USER or not PASS:
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env"); sys.exit(1)

ROOT = "/getfutprotec.com/public_html/outbound"
ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
TRASH = ROOT + "/trash/outbound_" + ts

# Reglas de candidatos a trash (rutas relativas a ROOT)
TRASH_DIRS = {"backups"}   # copias de BD de desarrollo local, no producción
TRASH_FILES = {
    "stats.db", "outbound.db", "crm.db",             # BD legacy vacías
    ".htrouter.php", "tailwind.config.js",           # dev server / compilación
    "tailwindcss-windows-x64.exe",                   # binario local
    "css/tailwind.css",                              # no se carga (solo min.css)
    "atribuir_respuestas_runner.php",                # runners de diagnóstico
    "imap_diag_runner.php",
    "imap_respuestas_runner.php",
    "verificar_atribucion_runner.php",
    "cli/migrar_estructura_local.php",               # herramientas de migración local
    "cli/migrar_plantillas_objetivo.php",
    "cli/migrar_secuencias.php",
    "cli/migracion_live_runner.php",
    # Temporales/diagnóstico detectados en el servidor (no están en el repo):
    "php_errorlog",
    "tmp_audit_dup.php",
    "tmp_audit_respuestas_http.php",
    "tmp_diag_dbpath.php",
    "migracion_diag.php",
    "tabs/followups.php",                            # existe en el servidor, no en el repo
}
PROTECT_FILES = {"enviar_smtp_random.php"}           # regla de credenciales SMTP

ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
ftp.encoding = "utf-8"

print("=== Arbol remoto (con tamanyos) ===")
def walk(rel=""):
    items = []
    cwd = ROOT if not rel else ROOT + "/" + rel
    try:
        ftp.cwd(cwd)
        try:
            listing = list(ftp.mlsd())
        except Exception:
            listing = [(n, {}) for n in ftp.nlst() if n not in (".", "..")]
    except Exception as e:
        print("  ERR listando", cwd, e); return items
    for n, facts in listing:
        if n in (".", ".."):
            continue
        relpath = (rel + "/" + n) if rel else n
        full = cwd + "/" + n
        ftype = facts.get("type")
        try:
            size = int(facts.get("size", -1))
        except Exception:
            size = -1
        if ftype == "dir" or (ftype is None and size < 0):
            items.append(("D", relpath + "/", -1, full))
            for sub in walk(relpath):
                items.append(sub)
        else:
            items.append(("F", relpath, size, full))
    return items

tree = walk()
for kind, rel, size, _ in sorted(tree, key=lambda x: x[1]):
    if kind == "D":
        print(f"  [DIR]  {rel}")
    else:
        print(f"  {size:>10}  {rel}")

# Clasificar
candidatos = []
for kind, rel, size, full in tree:
    if kind != "F":
        continue
    base = rel.split("/")[0]
    if base in TRASH_DIRS:
        candidatos.append((rel, full, "dir " + base))
        continue
    if rel in TRASH_FILES:
        candidatos.append((rel, full, "no necesario"))
    elif rel in PROTECT_FILES:
        pass

print("\n=== Candidatos a trash ===")
for rel, full, razon in sorted(candidatos):
    print(f"  {rel}  [{razon}]")

if not candidatos:
    print("  (nada)")
if not APPLY:
    print("\nDRY-RUN: no se ha movido nada. Usa --apply para mover a trash/outbound_" + ts + "/")
    ftp.quit()
    sys.exit(0)

# Crear trash y mover (rutas relativas al ROOT: SiteGround no acepta RENAME/MKD absolutos)
print(f"\n=== Moviendo a trash/outbound_{ts}/ ===")
ftp.cwd(ROOT)
for d in ["trash", "trash/outbound_" + ts]:
    try: ftp.mkd(d)
    except Exception: pass

for rel, full, _ in candidatos:
    dst_rel = "trash/outbound_" + ts + "/" + rel
    dst_dir = os.path.dirname(dst_rel)
    if dst_dir not in (".", "trash/outbound_" + ts):
        partes = dst_dir.split("/")
        acc = ""
        for p in partes:
            acc = p if not acc else acc + "/" + p
            try: ftp.mkd(acc)
            except Exception: pass
    try:
        ftp.rename(rel, dst_rel)
        print(f"  -> trash  {rel}")
    except Exception as e:
        print(f"  !! no movido {rel}: {e}")

ftp.quit()
print("\nOK optimizacion completada. Todo lo movido esta en trash/outbound_" + ts + "/")
