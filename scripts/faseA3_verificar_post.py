#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_verificar_post.py

FASE A.3 — VERIFICACIÓN POST-REPARACIÓN (READ-ONLY).

Confirma:
  1. leads 1815 y 1816 tienen estado_lead = '01 Sin Contactar'
  2. integrity_check = ok
  3. No hubo modificaciones fuera de esos dos campos (comparación con backup pre-reparación)
  4. No se modificaron pipelines, lead_pipelines, ni variantes A/B/C

USO:
  python scripts/faseA3_verificar_post.py
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

# Backup pre-reparación (manifest faseA3)
BACKUP_PRE = "backups_deploy/stats_db_faseA3_pre_20260818_015427.db"

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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_post_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # 1. integrity_check
    cur.execute("PRAGMA integrity_check")
    integ = cur.fetchone()[0]
    print(f"\n[1] integrity_check = {integ}")

    # 2. Leads 1815 y 1816
    print("\n[2] Estado de leads 1815 y 1816:")
    for lid in (1815, 1816):
        cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE id=?", (lid,))
        r = cur.fetchone()
        if r is None:
            print(f"  [FAIL] lead {lid} NO existe")
        else:
            ok = r["estado_lead"] == "01 Sin Contactar"
            print(f"  [{'OK' if ok else 'FAIL'}] lead {lid} estado_lead='{r['estado_lead']}' (esperado '01 Sin Contactar')")

    # 3. Comparación con backup pre-reparación (solo esos dos campos deben diferir)
    print("\n[3] Comparación con backup pre-reparación:")
    if not os.path.exists(BACKUP_PRE):
        print(f"  [WARN] backup pre no encontrado: {BACKUP_PRE}")
    else:
        db_pre = sqlite3.connect(BACKUP_PRE)
        db_pre.row_factory = sqlite3.Row
        cur_pre = db_pre.cursor()
        # Comparar clubes_crm completos
        cur.execute("SELECT * FROM clubes_crm ORDER BY id")
        cur_pre.execute("SELECT * FROM clubes_crm ORDER BY id")
        rows_post = cur.fetchall()
        rows_pre = cur_pre.fetchall()
        diffs = []
        if len(rows_post) != len(rows_pre):
            diffs.append(f"número de filas clubes_crm difiere: pre={len(rows_pre)} post={len(rows_post)}")
        else:
            for rp, rpost in zip(rows_pre, rows_post):
                if rp["id"] != rpost["id"]:
                    diffs.append(f"id difiere: pre={rp['id']} post={rpost['id']}")
                    continue
                for col in rp.keys():
                    if rp[col] != rpost[col]:
                        # Solo se permite la diferencia en estado_lead para 1815/1816
                        if col == "estado_lead" and rpost["id"] in (1815, 1816):
                            continue
                        diffs.append(f"clubes_crm id={rp['id']} col={col}: pre={rp[col]!r} post={rpost[col]!r}")
        if diffs:
            print("  [FAIL] Se detectaron diferencias fuera de los campos permitidos:")
            for d in diffs:
                print(f"    - {d}")
        else:
            print("  [OK] clubes_crm: sin diferencias fuera de estado_lead de 1815/1816")

        # Comparar pipelines, lead_pipelines (no deben cambiar)
        for tabla in ("pipelines", "lead_pipelines"):
            cur.execute(f"SELECT * FROM {tabla} ORDER BY id")
            cur_pre.execute(f"SELECT * FROM {tabla} ORDER BY id")
            rows_post = cur.fetchall()
            rows_pre = cur_pre.fetchall()
            if rows_post != rows_pre:
                print(f"  [FAIL] {tabla} difiere respecto al backup pre-reparación")
            else:
                print(f"  [OK] {tabla}: sin cambios respecto al backup pre-reparación")
        db_pre.close()

    # 4. No se modificaron variantes A/B/C históricas (envios.variant y lead_pipelines.variante_ab)
    print("\n[4] Variantes A/B/C históricas (sin cambios):")
    if os.path.exists(BACKUP_PRE):
        db_pre = sqlite3.connect(BACKUP_PRE)
        db_pre.row_factory = sqlite3.Row
        cur_pre = db_pre.cursor()
        for tabla, col in (("envios", "variant"), ("lead_pipelines", "variante_ab")):
            cur.execute(f"SELECT id, {col} FROM {tabla} ORDER BY id")
            cur_pre.execute(f"SELECT id, {col} FROM {tabla} ORDER BY id")
            if cur.fetchall() != cur_pre.fetchall():
                print(f"  [FAIL] {tabla}.{col} difiere respecto al backup pre-reparación")
            else:
                print(f"  [OK] {tabla}.{col}: sin cambios")
        db_pre.close()

    db.close()
    print(f"\n=== FIN VERIFICACIÓN POST-REPARACIÓN ===")
    print(f"BD temporal conservada en: {tmp}")

if __name__ == "__main__":
    main()
