#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA_auditoria_remota.py

AUDITORÍA READ-ONLY de la BD de producción remota (SiteGround).
Descarga data/stats.db a un temporal local, audita el estado de envios.es_test
y la clasificación TEST/REAL de cada envío. NO modifica nada en producción.

USO:
  python scripts/faseA_auditoria_remota.py
"""
import ftplib
import os
import sys
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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")
    print(f"Ruta temporal: {tmp}\n")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # Schema envios
    print("=== SCHEMA envios ===")
    cur.execute("PRAGMA table_info(envios)")
    cols = [r[1] for r in cur.fetchall()]
    print("Columnas:", cols)
    has_es_test = 'es_test' in cols
    print(f"es_test presente: {has_es_test}\n")

    # Conteo total
    cur.execute("SELECT COUNT(*) FROM envios")
    total = cur.fetchone()[0]
    print(f"total_envios = {total}\n")

    # Clasificación aprobada
    test_ids = list(range(3, 18)) + list(range(20, 23))  # 3-17 y 20-22
    real_ids = [1, 2, 18, 19] + list(range(23, 34))      # 1,2,18,19 y 23-33

    # Listar todos los envíos con su clasificación esperada
    print("=== ENVÍOS (id, club, email, es_test_actual, clasificación_aprobada) ===")
    cur.execute("SELECT id, club, email, es_test FROM envios ORDER BY id")
    rows = cur.fetchall()
    discrepancias = []
    for r in rows:
        eid = r[0]
        club = r[1]
        email = r[2]
        es_test_actual = r[3]
        if eid in test_ids:
            esperado = 1
        elif eid in real_ids:
            esperado = 0
        else:
            esperado = None  # no cubierto
        marca = ""
        if esperado is not None and es_test_actual != esperado:
            marca = "  <<< DISCREPANCIA"
            discrepancias.append(eid)
        print(f"  id={eid} | {club} <{email}> | es_test_actual={es_test_actual} | esperado={esperado}{marca}")

    # Conteos
    if has_es_test:
        cur.execute("SELECT es_test, COUNT(*) FROM envios GROUP BY es_test")
        print("\n=== CONTEOS es_test ===")
        for r in cur.fetchall():
            print(f"  es_test={r[0]}: {r[1]}")
        cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
        print(f"  es_test IS NULL: {cur.fetchone()[0]}")

    # Verificar universo
    print("\n=== VERIFICACIÓN UNIVERSO ===")
    ids_existentes = set(r[0] for r in rows)
    faltantes = [i for i in (test_ids + real_ids) if i not in ids_existentes]
    no_cubiertos = [i for i in ids_existentes if i not in test_ids and i not in real_ids]
    print(f"IDs aprobados que NO existen: {faltantes if faltantes else 'NINGUNO'}")
    print(f"IDs existentes NO cubiertos por clasificación aprobada: {no_cubiertos if no_cubiertos else 'NINGUNO'}")

    # Verificación específica IDs 18 y 19
    print("\n=== VERIFICACIÓN ESPECÍFICA IDs 18 y 19 ===")
    for eid in [18, 19]:
        cur.execute("SELECT id, club, email, es_test, lead_id, campaign_id FROM envios WHERE id=?", (eid,))
        r = cur.fetchone()
        if r:
            print(f"  id={r[0]} | club={r[1]} | email={r[2]} | es_test={r[3]} | lead={r[4]} | campaign={r[5]}")
        else:
            print(f"  id={eid} NO EXISTE")

    # integrity_check
    print("\n=== PRAGMA integrity_check ===")
    cur.execute("PRAGMA integrity_check")
    print("  ", cur.fetchone()[0])

    db.close()
    os.remove(tmp)
    print("\n=== FIN AUDITORÍA REMOTA (READ-ONLY) ===")
    if discrepancias:
        print(f"DISCREPANCIAS DETECTADAS en IDs: {discrepancias}")
        sys.exit(2)
    print("SIN DISCREPANCIAS")

if __name__ == "__main__":
    main()
