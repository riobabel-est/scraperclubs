import ftplib, io, os, hashlib, time, sys

def load_env(path=".env"):
    env = {}
    for line in open(path, encoding="utf-8"):
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()
    return env

def es_deployable(p):
    n = os.path.basename(p).lower()
    if (n.startswith(".tmp") or n.startswith("diag_") or n.startswith("migrar_")
            or n.startswith("aplicar_") or n.startswith("verif_") or n.startswith("ver_")
            or n.startswith("login_sim") or "runner" in n
            or n.endswith(".lock") or n.endswith(".py") or n.endswith(".log")):
        return False
    return True

def md5fp(fp):
    with open(fp, "rb") as f:
        return hashlib.md5(f.read()).hexdigest()

env = load_env()
ftp = ftplib.FTP(env.get("FTP_HOST"), env.get("FTP_USER"), env.get("FTP_PASS"))
BASE_L = "public_html/outbound"
BASE_R = "/getfutprotec.com/public_html/outbound"
EXCL_DIRS = {"data", "logs", "backups"}

archivos = []
for root, dirs, files in os.walk(BASE_L):
    dirs[:] = [d for d in dirs if d not in EXCL_DIRS]
    for fn in files:
        lp = os.path.join(root, fn)
        if not es_deployable(lp):
            continue
        ext = os.path.splitext(fn)[1].lower()
        if ext not in (".php", ".js", ".css", ".htaccess", ".md", ".json", ".txt", ".html"):
            continue
        archivos.append(lp)

print("Total archivos a verificar:", len(archivos), flush=True)

def ensure_dir(rel_dir):
    if not rel_dir:
        return True
    cur = BASE_R
    for x in rel_dir.split("/"):
        cur += "/" + x
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass
            try:
                ftp.cwd(cur)
            except Exception:
                return False
    return True

ts = time.strftime("%Y%m%d_%H%M%S")
bk = "/getfutprotec.com/backups_deploy/sync_total_" + ts
try:
    ftp.mkd(bk)
except Exception:
    pass

cambiar = []
iguales = 0
for lp in sorted(archivos):
    rel = os.path.relpath(lp, BASE_L).replace("\\", "/")
    rem = BASE_R + "/" + rel
    try:
        buf = io.BytesIO()
        ftp.retrbinary("RETR " + rem, buf.write)
        if hashlib.md5(buf.getvalue()).hexdigest() == md5fp(lp):
            iguales += 1
            continue
    except Exception:
        pass
    cambiar.append(rel)

print("Iguales ya:", iguales, "| A sincronizar:", len(cambiar), flush=True)

subidos = 0
for rel in cambiar:
    # backup del estado previo remoto (si existe)
    try:
        b = io.BytesIO()
        ftp.retrbinary("RETR " + BASE_R + "/" + rel, b.write)
        cur = bk
        for x in os.path.dirname(rel).split("/") if os.path.dirname(rel) else []:
            cur += "/" + x
            try:
                ftp.cwd(cur)
            except Exception:
                try:
                    ftp.mkd(cur)
                except Exception:
                    pass
                try:
                    ftp.cwd(cur)
                except Exception:
                    break
        try:
            ftp.storbinary("STOR " + cur + "/" + os.path.basename(rel), io.BytesIO(b.getvalue()))
        except Exception as e:
            print("backup warn", rel, e, flush=True)
    except Exception:
        pass
    # subida
    if ensure_dir(os.path.dirname(rel)):
        with open(os.path.join(BASE_L, rel), "rb") as f:
            ftp.storbinary("STOR " + BASE_R + "/" + rel, f)
        subidos += 1
        print(" subido:", rel, flush=True)

print("SUBIDOS:", subidos, "| backup:", bk, flush=True)
ftp.quit()
print("SYNC_FIN", flush=True)
