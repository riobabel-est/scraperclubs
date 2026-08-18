#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Inspeccion de la BD remota para localizar leads de prueba (TEST_* / @futprotec.local)
y su estado actual, para preparar la prueba funcional de Lista Negra bidireccional.
NO modifica nada. Solo lectura.
"""
import ftplib
import os
import sys
import time
import sqlite3
import tempfile

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
REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

if not USER or not PASS:
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env")
    sys.exit(1)

print(f"Conectando a {HOST} ...")
ftp = ftplib.FTP(HOST)
ftp.login(USER, PASS)
print("Login OK")

tmp = os.path.join(tempfile.gettempdir(), f"futprotec_inspect_{int(time.time())}.db")
with open(tmp, "wb") as f:
    ftp.retrbinary("RETR " + REMOTE_DB, f.write)
ftp.quit()
print(f"BD descargada: {os.path.getsize(tmp)} bytes")

db = sqlite3.connect(tmp)
cur = db.cursor()

# Leads de prueba
print("\n=== LEADS DE PRUEBA (TEST_* o @futprotec.local) ===")
cur.execute("""
    SELECT id, nombre_club, email, estado_lead, estado_lead_backup,
           substr(observaciones,1,120) as obs
    FROM clubes_crm
    WHERE nombre_club LIKE 'TEST%' OR email LIKE '%@futprotec.local'
    ORDER BY id
""")
for r in cur.fetchall():
    print(f"  id={r[0]} | {r[1]} | {r[2]} | estado={r[3]} | backup={r[4]!r}")
    print(f"      obs: {r[5]!r}")

# Leads actualmente en Lista Negra
print("\n=== LEADS EN ESTADO DE SUPRESION ===")
estados = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']
inlist = ",".join("?" for _ in estados)
cur.execute(f"""
    SELECT id, nombre_club, email, estado_lead, estado_lead_backup,
           substr(observaciones,1,120) as obs
    FROM clubes_crm WHERE estado_lead IN ({inlist}) ORDER BY id LIMIT 30
""", estados)
for r in cur.fetchall():
    print(f"  id={r[0]} | {r[1]} | {r[2]} | estado={r[3]} | backup={r[4]!r}")
    print(f"      obs: {r[5]!r}")

# Config de seguridad
print("\n=== CONFIG SEGURIDAD ===")
cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado','campaign_id','campaign2_id')")
for r in cur.fetchall():
    print(f"  config.{r[0]} = {r[1]}")

print("\n=== ENVIOS HOY ===")
cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
print(f"  envios_email_hoy = {cur.fetchone()[0]}")

db.close()
os.remove(tmp)
print("\nInspeccion completada (solo lectura).")
