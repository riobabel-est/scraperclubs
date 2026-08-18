#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_precheck.py

FASE A.3 — PRE-CHECK ANTES DEL UPDATE (READ-ONLY).

Verifica las condiciones previas exigidas antes de ejecutar el UPDATE:
  1. lead 1815 existe
  2. lead 1816 existe
  3. ambos tienen estado_lead = 'Sin Contactar'
  4. no existe ninguna otra fila que vaya a ser modificada
  5. fotografía de control de los registros 1815 y 1816
  6. métricas de control de la BD

Si alguna condición falla, se indica STOP (no ejecutar UPDATE).

USO:
  python scripts/faseA3_precheck.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_pre_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # Métricas de control de la BD
    print("\n=== MÉTRICAS DE CONTROL DE LA BD ===")
    cur.execute("PRAGMA integrity_check")
    integ = cur.fetchone()[0]
    print(f"  integrity_check = {integ}")
    for tabla in ("clubes_crm", "pipelines", "lead_pipelines", "envios"):
        cur.execute(f"SELECT COUNT(*) AS n FROM {tabla}")
        print(f"  {tabla}: {cur.fetchone()['n']} filas")

    # 1 y 2. Existencia de leads 1815 y 1816
    print("\n=== PRE-CHECK LEADS 1815 y 1816 ===")
    ok = True
    for lid in (1815, 1816):
        cur.execute("SELECT * FROM clubes_crm WHERE id=?", (lid,))
        r = cur.fetchone()
        if r is None:
            print(f"  [FAIL] lead {lid} NO existe")
            ok = False
        else:
            print(f"  [OK] lead {lid} existe")
            # Fotografía de control
            print(f"        estado_lead='{r['estado_lead']}'")
            print(f"        nombre_club='{r['nombre_club']}'")
            print(f"        email='{r['email']}'")

    # 3. Ambos deben tener estado_lead = 'Sin Contactar'
    print("\n=== CONDICIÓN: estado_lead = 'Sin Contactar' ===")
    for lid in (1815, 1816):
        cur.execute("SELECT estado_lead FROM clubes_crm WHERE id=?", (lid,))
        r = cur.fetchone()
        if r is None:
            continue
        cond = r["estado_lead"] == "Sin Contactar"
        print(f"  lead {lid}: estado_lead='{r['estado_lead']}' -> {'OK' if cond else 'NO (ya reparado o distinto)'}")
        if not cond:
            ok = False

    # 4. No existe ninguna otra fila que vaya a ser modificada
    #    (el UPDATE solo afecta a id IN (1815,1816) con estado_lead='Sin Contactar')
    print("\n=== OTRAS FILAS CON estado_lead='Sin Contactar' ===")
    cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE estado_lead='Sin Contactar'")
    otras = cur.fetchall()
    if not otras:
        print("  [OK] No hay filas con estado_lead='Sin Contactar'")
    else:
        for r in otras:
            print(f"  [INFO] id={r['id']} estado_lead='{r['estado_lead']}'")

    # Simular el UPDATE para contar filas afectadas (sin ejecutarlo)
    print("\n=== SIMULACIÓN DE FILAS AFECTADAS POR EL UPDATE ===")
    cur.execute("""
        SELECT COUNT(*) AS n FROM clubes_crm
        WHERE id IN (1815, 1816) AND estado_lead='Sin Contactar'
    """)
    n = cur.fetchone()["n"]
    print(f"  Filas que afectaría el UPDATE: {n} (esperado 2)")

    db.close()
    print(f"\n=== FIN PRE-CHECK ===")
    if not ok:
        print("RESULTADO: STOP — condición previa no cumplida (los leads ya tienen '01 Sin Contactar' o no existen).")
    else:
        print("RESULTADO: OK — condiciones previas cumplidas. Proceder con UPDATE.")
    print(f"BD temporal conservada en: {tmp}")

if __name__ == "__main__":
    main()
