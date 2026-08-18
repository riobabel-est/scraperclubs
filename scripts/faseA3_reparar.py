#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_reparar.py

FASE A.3 — REPARACIONES CATEGORÍA A (deterministas y seguras).

SOLO ejecuta reparaciones de categoría A (reparación determinista segura).
Las categorías B (requiere decisión) y C (informativo) NO se tocan.

REPARACIONES A EJECUTADAS:
  1. clubes_crm id=1815: estado_lead 'Sin Contactar' -> '01 Sin Contactar'
  2. clubes_crm id=1816: estado_lead 'Sin Contactar' -> '01 Sin Contactar'
     Regla: estado_lead ∈ Kanban definitivo (9 columnas).
     'Sin Contactar' es un estado legacy de la arquitectura de 7 columnas.

Cada UPDATE es explícito, mínimo, trazable y reversible (backup previo).

USO:
  python scripts/faseA3_reparar.py
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

# Reparaciones A: (tabla, id, campo, valor_anterior, valor_nuevo, motivo)
REPARACIONES_A = [
    ("clubes_crm", 1815, "estado_lead", "Sin Contactar", "01 Sin Contactar",
     "estado_lead legacy de arquitectura 7 columnas; Kanban definitivo exige '01 Sin Contactar'"),
    ("clubes_crm", 1816, "estado_lead", "Sin Contactar", "01 Sin Contactar",
     "estado_lead legacy de arquitectura 7 columnas; Kanban definitivo exige '01 Sin Contactar'"),
]

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # Descargar BD remota
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_rep_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    md5_pre = file_md5(tmp)
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={md5_pre}")

    # Verificar integridad previa
    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()
    cur.execute("PRAGMA integrity_check")
    integ_pre = cur.fetchone()[0]
    print(f"integrity_check PRE = {integ_pre}")
    if integ_pre != "ok":
        print("[FAIL] integridad previa no OK. ABORTANDO.")
        db.close()
        ftp.quit()
        return

    # Verificar que los valores anteriores coinciden (idempotencia / seguridad)
    print("\n=== VERIFICANDO VALORES ANTERIORES ===")
    for tabla, id_, campo, anterior, nuevo, motivo in REPARACIONES_A:
        cur.execute(f"SELECT {campo} FROM {tabla} WHERE id=?", (id_,))
        r = cur.fetchone()
        if r is None:
            print(f"  [WARN] {tabla} id={id_} no existe. Se omite.")
            continue
        actual = r[0]
        if actual != anterior:
            print(f"  [SKIP] {tabla} id={id_} {campo}='{actual}' != esperado '{anterior}'. "
                  f"Ya reparado o valor distinto. Se omite.")
            continue
        print(f"  [OK] {tabla} id={id_} {campo}='{actual}' coincide con esperado '{anterior}'")

    # Aplicar reparaciones
    print("\n=== APLICANDO REPARACIONES A ===")
    ejecutadas = []
    for tabla, id_, campo, anterior, nuevo, motivo in REPARACIONES_A:
        cur.execute(f"SELECT {campo} FROM {tabla} WHERE id=?", (id_,))
        r = cur.fetchone()
        if r is None or r[0] != anterior:
            continue
        cur.execute(f"UPDATE {tabla} SET {campo}=? WHERE id=?", (nuevo, id_))
        ejecutadas.append((tabla, id_, campo, anterior, nuevo, motivo))
        print(f"  [UPDATE] {tabla} id={id_} {campo}: '{anterior}' -> '{nuevo}'")

    db.commit()

    # Verificar post
    print("\n=== VERIFICANDO POST ===")
    for tabla, id_, campo, anterior, nuevo, motivo in ejecutadas:
        cur.execute(f"SELECT {campo} FROM {tabla} WHERE id=?", (id_,))
        r = cur.fetchone()
        ok = (r[0] == nuevo)
        print(f"  [{'OK' if ok else 'FAIL'}] {tabla} id={id_} {campo}='{r[0]}' (esperado '{nuevo}')")

    cur.execute("PRAGMA integrity_check")
    integ_post = cur.fetchone()[0]
    print(f"integrity_check POST = {integ_post}")
    db.close()

    if integ_post != "ok":
        print("[FAIL] integridad post no OK. NO se sube.")
        ftp.quit()
        return

    # Subir BD reparada
    print("\n=== SUBIENDO BD REPARADA ===")
    with open(tmp, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)
    md5_post = file_md5(tmp)
    print(f"Subida. md5_post={md5_post}")

    # Verificar remoto
    print("\n=== VERIFICANDO REMOTO ===")
    tmp2 = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_rep_verify_{int(time.time())}.db")
    with open(tmp2, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    md5_verify = file_md5(tmp2)
    print(f"md5_verify={md5_verify} (esperado {md5_post})")
    db2 = sqlite3.connect(tmp2)
    db2.row_factory = sqlite3.Row
    cur2 = db2.cursor()
    cur2.execute("PRAGMA integrity_check")
    integ_verify = cur2.fetchone()[0]
    print(f"integrity_check VERIFY = {integ_verify}")
    for tabla, id_, campo, anterior, nuevo, motivo in ejecutadas:
        cur2.execute(f"SELECT {campo} FROM {tabla} WHERE id=?", (id_,))
        r = cur2.fetchone()
        print(f"  [{'OK' if r[0]==nuevo else 'FAIL'}] {tabla} id={id_} {campo}='{r[0]}'")
    db2.close()
    ftp.quit()

    print("\n=== RESUMEN ===")
    print(f"Reparaciones ejecutadas: {len(ejecutadas)}")
    for tabla, id_, campo, anterior, nuevo, motivo in ejecutadas:
        print(f"  {tabla} id={id_} {campo}: '{anterior}' -> '{nuevo}' | {motivo}")
    print(f"md5_pre={md5_pre}")
    print(f"md5_post={md5_post}")
    print(f"integrity PRE={integ_pre} POST={integ_post} VERIFY={integ_verify}")

if __name__ == "__main__":
    main()
