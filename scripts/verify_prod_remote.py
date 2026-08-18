#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verifica el estado de PRODUCCION en la BD remota:
- modo_entorno
- motor_estado
- campaign 2 (pipelines / campanas)
- conteo de envios comerciales nuevos (envios con campaign=2)
- estado de leads comerciales (no TEST)
Descarga stats.db remoto a temporal y consulta.
"""
import ftplib
import os
import time
import hashlib
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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_prod_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # Listar tablas
    cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [r[0] for r in cur.fetchall()]
    print(f"\nTablas: {tables}")

    # CONFIG
    if 'config' in tables:
        print("\n=== CONFIG ===")
        try:
            cur.execute("SELECT * FROM config")
            for r in cur.fetchall():
                print("  ", r)
        except Exception as e:
            print("  [ERR]", e)

    # PIPELINES
    for t in ('pipelines', 'campanas', 'campaigns'):
        if t in tables:
            print(f"\n=== {t.upper()} (id=2) ===")
            try:
                cur.execute(f"SELECT * FROM {t} WHERE id = 2")
                for r in cur.fetchall():
                    print("  ", r)
            except Exception as e:
                print("  [ERR]", e)

    # Envios comerciales nuevos (campaign=2)
    if 'envios' in tables:
        print("\n=== ENVIOS campaign=2 (comerciales) ===")
        try:
            cur.execute("SELECT COUNT(*) FROM envios WHERE campaign = 2")
            print("  total_envios_campaign2 =", cur.fetchone()[0])
        except Exception as e:
            print("  [ERR]", e)

    # Leads comerciales modificados (estado Lista Negra no-TEST)
    if 'clubes_crm' in tables:
        print("\n=== LEADS Lista Negra (no TEST) ===")
        try:
            cur.execute("SELECT COUNT(*) FROM clubes_crm WHERE estado_lead LIKE '%Lista Negra%' AND lower(nombre_club) NOT LIKE '%test%' AND lower(email) NOT LIKE '%test%'")
            print("  count_lista_negra_no_test =", cur.fetchone()[0])
        except Exception as e:
            print("  [ERR]", e)

    db.close()
    os.remove(tmp)
    print("\nVerificacion produccion completada.")

if __name__ == "__main__":
    main()
