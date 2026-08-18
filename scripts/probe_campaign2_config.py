#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Sonda de solo lectura: inspecciona donde se guarda campaign2 y el estado de envios."""
import ftplib, os, sqlite3, tempfile, time

def load_env(path=".env"):
    env = {}
    if os.path.exists(path):
        for line in open(path, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env

env = load_env()
ftp = ftplib.FTP(env.get("FTP_HOST", "ftp.getfutprotec.com"))
ftp.login(env["FTP_USER"], env["FTP_PASS"])
tmp = os.path.join(tempfile.gettempdir(), "probe_" + str(int(time.time())) + ".db")
with open(tmp, "wb") as f:
    ftp.retrbinary("RETR /getfutprotec.com/public_html/outbound/data/stats.db", f.write)
ftp.quit()

db = sqlite3.connect(tmp)
cur = db.cursor()
print("=== TABLAS ===")
for r in cur.execute("SELECT name FROM sqlite_master WHERE type='table'"):
    print(" ", r[0])
print("\n=== config keys ===")
try:
    for r in cur.execute("SELECT clave, valor FROM config"):
        print(" ", r)
except Exception as e:
    print("  [err]", e)
print("\n=== columnas clubes_crm ===")
for r in cur.execute("PRAGMA table_info(clubes_crm)"):
    print(" ", r[1])
print("\n=== buscar 'campaign2' en config ===")
try:
    for r in cur.execute("SELECT clave, valor FROM config WHERE clave LIKE '%campaign%' OR clave LIKE '%entorno%' OR clave LIKE '%motor%'"):
        print(" ", r)
except Exception as e:
    print("  [err]", e)
db.close()
os.remove(tmp)
