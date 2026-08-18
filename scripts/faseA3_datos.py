#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_datos.py

FASE A.3 — PASO 2: EXPLORACIÓN READ-ONLY de datos clave de producción.
Vuelca valores reales de las tablas críticas para poder construir la auditoría
completa con reglas deterministas correctas.

NO modifica nada. Solo lectura.

USO:
  python scripts/faseA3_datos.py
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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_dat_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── pipelines ──
    print("=== PIPELINES ===")
    cur.execute("SELECT * FROM pipelines ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── lead_pipelines ──
    print("=== LEAD_PIPELINES ===")
    cur.execute("SELECT * FROM lead_pipelines ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── config ──
    print("=== CONFIG ===")
    cur.execute("SELECT * FROM config ORDER BY clave")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── plantillas ──
    print("=== PLANTILLAS ===")
    cur.execute("SELECT id, nombre, tipo, categoria, activo, test_ab FROM plantillas ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── cuentas_smtp (sin password) ──
    print("=== CUENTAS_SMTP (sin password) ===")
    cur.execute("SELECT id, email, host, puerto, usuario, seguridad, activa, limite_diario, enviados_hoy, nombre_emisor, cargo_emisor FROM cuentas_smtp ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── envios: estados y variantes ──
    print("=== ENVIOS: estados ===")
    cur.execute("SELECT estado, COUNT(*) as n FROM envios GROUP BY estado ORDER BY n DESC")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()
    print("=== ENVIOS: variant ===")
    cur.execute("SELECT variant, COUNT(*) as n FROM envios GROUP BY variant ORDER BY variant")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()
    print("=== ENVIOS: resultado_envio ===")
    cur.execute("SELECT resultado_envio, COUNT(*) as n FROM envios GROUP BY resultado_envio ORDER BY n DESC")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── envios: IDs faltantes (seq=68, 42 filas) ──
    print("=== ENVIOS: IDs existentes ===")
    cur.execute("SELECT id FROM envios ORDER BY id")
    ids = [r['id'] for r in cur.fetchall()]
    print("  IDs:", ids)
    faltantes = [i for i in range(1, 69) if i not in ids]
    print("  IDs faltantes (1-68):", faltantes)
    print()

    # ── clubes_crm: estados ──
    print("=== CLUBES_CRM: estado_lead ===")
    cur.execute("SELECT estado_lead, COUNT(*) as n FROM clubes_crm GROUP BY estado_lead ORDER BY n DESC")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── clubes_crm: emails duplicados / inválidos / vacíos ──
    print("=== CLUBES_CRM: emails ===")
    cur.execute("SELECT COUNT(*) as n FROM clubes_crm WHERE email IS NULL OR TRIM(email)=''")
    print("  vacíos:", cur.fetchone()['n'])
    cur.execute("SELECT email, COUNT(*) as n FROM clubes_crm GROUP BY LOWER(email) HAVING n>1")
    dups = cur.fetchall()
    print("  emails duplicados (por lower):", len(dups))
    for d in dups[:20]:
        print("    ", dict(d))
    print()

    # ── clubes_crm: es_duplicado ──
    print("=== CLUBES_CRM: es_duplicado ===")
    cur.execute("SELECT es_duplicado, COUNT(*) as n FROM clubes_crm GROUP BY es_duplicado")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── clubes_crm: leads TEST ──
    print("=== CLUBES_CRM: leads TEST (email @futprotec.local o nombre test*) ===")
    cur.execute("SELECT id, nombre_club, email, estado_lead FROM clubes_crm WHERE LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%' ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── aperturas ──
    print("=== APERTURAS ===")
    cur.execute("SELECT * FROM aperturas ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── comunicaciones_log ──
    print("=== COMUNICACIONES_LOG: tipo_evento ===")
    cur.execute("SELECT tipo_evento, COUNT(*) as n FROM comunicaciones_log GROUP BY tipo_evento ORDER BY n DESC")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()
    print("=== COMUNICACIONES_LOG: lead_id / pipeline_id ===")
    cur.execute("SELECT id, lead_id, club_id, pipeline_id, tipo_evento, plantilla_id, id_cuenta_smtp, variante_ab, resultado, fecha FROM comunicaciones_log ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── snapshots ──
    print("=== SNAPSHOTS ===")
    cur.execute("SELECT * FROM snapshots ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── _migraciones ──
    print("=== _MIGRACIONES ===")
    cur.execute("SELECT * FROM _migraciones")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    # ── envios detalle completo ──
    print("=== ENVIOS DETALLE ===")
    cur.execute("SELECT id, club, email, federacion, cuenta_emision, fecha_envio, estado, tracking_id, lead_id, campaign_id, variant, plantilla_id, smtp_id, message_id, resultado_envio, fecha_resultado_envio, es_test FROM envios ORDER BY id")
    for r in cur.fetchall():
        print("  ", dict(r))
    print()

    db.close()
    print(f"=== FIN EXPLORACIÓN (READ-ONLY) ===")
    print(f"BD temporal: {tmp}")

if __name__ == "__main__":
    main()
