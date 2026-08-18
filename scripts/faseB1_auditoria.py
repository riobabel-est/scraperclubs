#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseB1_auditoria.py

FASE B.1 — AUDITORÍA SEMÁNTICA DE HALLAZGOS B (READ-ONLY).

Recopila evidencia READ-ONLY sobre:
  - B1: pipeline 3 (SMOKE_TEST_FUTPROTEC_2026_08) — entorno=test, estado=PILOT
  - B2: lead_pipelines 2,4,5 — variantes históricas A/B/C

NO modifica producción. Solo consultas SELECT / PRAGMA read-only.

USO:
  python scripts/faseB1_auditoria.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import zlib

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

def asignar_variante(lead_id, campaign_id):
    s = f"{campaign_id}:{lead_id}"
    h = zlib.crc32(s.encode("utf-8"))
    if h < 0:
        h += 4294967296
    return ["A", "B", "C"][h % 3]

def main():
    print("=" * 80)
    print("FASE B.1 — AUDITORÍA SEMÁNTICA DE HALLAZGOS B (READ-ONLY)")
    print("=" * 80)

    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseB1_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"BD descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── config.modo_entorno ──
    print("=== CONFIG: modo_entorno ===")
    cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    for r in cur.fetchall():
        print(f"  {r['clave']} = {r['valor']}")

    # ── B1: PIPELINE 3 ──
    print("\n=== B1: PIPELINE 3 (SMOKE_TEST_FUTPROTEC_2026_08) ===")
    cur.execute("SELECT * FROM pipelines WHERE id=3")
    p3 = cur.fetchone()
    if p3:
        for k in p3.keys():
            print(f"  {k} = {p3[k]!r}")
    else:
        print("  [FAIL] pipeline 3 no existe")

    # Envios asociados al pipeline 3
    cur.execute("SELECT COUNT(*) AS n FROM envios WHERE campaign_id=3")
    print(f"  Envios con campaign_id=3: {cur.fetchone()['n']}")
    cur.execute("SELECT id, lead_id, es_test, estado, variant FROM envios WHERE campaign_id=3 ORDER BY id")
    for r in cur.fetchall():
        print(f"    envio id={r['id']} lead={r['lead_id']} es_test={r['es_test']} estado={r['estado']} variant={r['variant']}")

    # lead_pipelines asociados al pipeline 3
    cur.execute("SELECT COUNT(*) AS n FROM lead_pipelines WHERE pipeline_id=3")
    print(f"  lead_pipelines con pipeline_id=3: {cur.fetchone()['n']}")

    # Plantillas asociadas (por envios.plantilla_id)
    cur.execute("SELECT DISTINCT plantilla_id FROM envios WHERE campaign_id=3")
    tpls = [r['plantilla_id'] for r in cur.fetchall()]
    print(f"  plantilla_id usados en envios de pipeline 3: {tpls}")

    # ── B2: LEAD_PIPELINES 2,4,5 ──
    print("\n=== B2: LEAD_PIPELINES 2,4,5 ===")
    cur.execute("SELECT * FROM lead_pipelines WHERE id IN (2,4,5) ORDER BY id")
    for r in cur.fetchall():
        print(f"  id={r['id']} lead_id={r['lead_id']} pipeline_id={r['pipeline_id']} variante_ab={r['variante_ab']!r} fecha_asignacion={r['fecha_asignacion']!r}")

    # Pipeline 1 (LEGACY_TEST_FASE1)
    print("\n=== PIPELINE 1 (LEGACY_TEST_FASE1) ===")
    cur.execute("SELECT * FROM pipelines WHERE id=1")
    p1 = cur.fetchone()
    if p1:
        for k in p1.keys():
            print(f"  {k} = {p1[k]!r}")

    # Leads de lead_pipelines 2,4,5
    print("\n=== LEADS DE LEAD_PIPELINES 2,4,5 ===")
    cur.execute("SELECT id, nombre_club, email, estado_lead FROM clubes_crm WHERE id IN (1810,1812,1813)")
    for r in cur.fetchall():
        print(f"  lead id={r['id']} nombre={r['nombre_club']!r} email={r['email']!r} estado={r['estado_lead']!r}")

    # Envios de esos leads
    print("\n=== ENVIOS DE LEADS 1810,1812,1813 ===")
    cur.execute("SELECT id, lead_id, campaign_id, variant, es_test, estado FROM envios WHERE lead_id IN (1810,1812,1813) ORDER BY id")
    for r in cur.fetchall():
        print(f"  envio id={r['id']} lead={r['lead_id']} campaign={r['campaign_id']} variant={r['variant']} es_test={r['es_test']} estado={r['estado']}")

    # ── Comparar variante histórica vs asignarVariante() ──
    print("\n=== COMPARACIÓN VARIANTE HISTÓRICA vs asignarVariante() ===")
    cur.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines WHERE id IN (2,4,5)")
    for r in cur.fetchall():
        hist = r['variante_ab']
        det = asignar_variante(r['lead_id'], r['pipeline_id'])
        marca = "  <<< DIFIERE" if hist != det else ""
        print(f"  LP id={r['id']} lead={r['lead_id']} pipeline={r['pipeline_id']} historica={hist} determinista={det}{marca}")

    # ── ¿lead_pipelines se usa en envios? (no hay FK; verificar si envios.variant coincide con lead_pipelines) ──
    print("\n=== ¿LEAD_PIPELINES SE USA PARA ENVIAR? ===")
    cur.execute("SELECT COUNT(*) AS n FROM envios WHERE campaign_id=1")
    n_camp1 = cur.fetchone()['n']
    print(f"  Envios con campaign_id=1 (LEGACY_TEST_FASE1): {n_camp1}")
    cur.execute("SELECT id, lead_id, variant FROM envios WHERE campaign_id=1 ORDER BY id")
    for r in cur.fetchall():
        print(f"    envio id={r['id']} lead={r['lead_id']} variant={r['variant']}")

    # ── Aislamiento: leads REAL asociados a pipeline 3? ──
    print("\n=== AISLAMIENTO TEST/REAL PIPELINE 3 ===")
    cur.execute("""
        SELECT e.id, e.lead_id, e.es_test, c.email, c.nombre_club
        FROM envios e JOIN clubes_crm c ON c.id=e.lead_id
        WHERE e.campaign_id=3
    """)
    for r in cur.fetchall():
        email_l = (r['email'] or '').lower()
        nombre_l = (r['nombre_club'] or '').lower()
        lead_test = ('@futprotec.local' in email_l) or nombre_l.startswith('test')
        print(f"  envio={r['id']} lead={r['lead_id']} es_test={r['es_test']} leadTEST={lead_test} email={r['email']}")

    db.close()
    print("\n=== FIN AUDITORÍA B.1 (READ-ONLY) ===")
    print(f"BD temporal: {tmp}")

if __name__ == "__main__":
    main()
