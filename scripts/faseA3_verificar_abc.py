#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_verificar_abc.py

FASE A.3 — Verificación READ-ONLY de:
  1. Determinismo A/B/C: envios.variant == asignarVariante(lead_id, campaign_id)
     para envíos con campaign_id > 0.
  2. Referencias de aperturas.tracking_id -> envios.tracking_id.
  3. Formato de message_id en envios.
  4. Coherencia envios.email/club vs clubes_crm.
  5. message_id duplicados en envios.

NO modifica nada. Solo lectura.

USO:
  python scripts/faseA3_verificar_abc.py
"""
import ftplib
import os
import time
import hashlib
import sqlite3
import tempfile
import zlib

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
    """Espejo de asignarVariante() en abc.php."""
    s = f"{campaign_id}:{lead_id}"
    h = zlib.crc32(s.encode('utf-8'))
    if h < 0:
        h += 4294967296
    return ['A', 'B', 'C'][h % 3]

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_abc_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── 1. A/B/C determinismo ──
    print("=== A/B/C DETERMINISMO (envios con campaign_id>0) ===")
    cur.execute("SELECT id, lead_id, campaign_id, variant FROM envios WHERE campaign_id IS NOT NULL AND campaign_id > 0 ORDER BY id")
    rows = cur.fetchall()
    disc_abc = []
    for r in rows:
        esperado = asignar_variante(r['lead_id'], r['campaign_id'])
        marca = ""
        if r['variant'] != esperado:
            marca = "  <<< DISCREPANCIA"
            disc_abc.append((r['id'], r['variant'], esperado))
        print(f"  envio={r['id']} lead={r['lead_id']} camp={r['campaign_id']} variant={r['variant']} esperado={esperado}{marca}")
    print(f"  Discrepancias A/B/C: {len(disc_abc)} -> {disc_abc}\n")

    # ── 2. Aperturas -> envios ──
    print("=== APERTURAS -> ENVIOS (tracking_id) ===")
    cur.execute("SELECT tracking_id FROM envios")
    envio_tids = set(r['tracking_id'] for r in cur.fetchall())
    cur.execute("SELECT id, tracking_id FROM aperturas ORDER BY id")
    ap_rows = cur.fetchall()
    huerfanas = []
    for r in ap_rows:
        if r['tracking_id'] not in envio_tids:
            huerfanas.append((r['id'], r['tracking_id']))
    print(f"  Total aperturas: {len(ap_rows)}")
    print(f"  Aperturas con tracking_id sin envío: {len(huerfanas)} -> {huerfanas}\n")

    # ── 3. message_id formato ──
    print("=== MESSAGE_ID FORMATO ===")
    cur.execute("SELECT id, message_id, tracking_id, cuenta_emision FROM envios WHERE message_id IS NOT NULL ORDER BY id")
    mid_rows = cur.fetchall()
    malformados = []
    for r in mid_rows:
        mid = r['message_id']
        # Formato esperado: <tracking_id@dominio>
        if not (mid.startswith('<') and mid.endswith('>') and '@' in mid):
            malformados.append((r['id'], mid))
    print(f"  Envíos con message_id: {len(mid_rows)}")
    print(f"  Malformados: {len(malformados)} -> {malformados}\n")

    # ── 4. message_id duplicados ──
    print("=== MESSAGE_ID DUPLICADOS ===")
    cur.execute("SELECT message_id, COUNT(*) as n FROM envios WHERE message_id IS NOT NULL GROUP BY message_id HAVING n>1")
    dup_mid = cur.fetchall()
    print(f"  Duplicados: {len(dup_mid)} -> {[dict(r) for r in dup_mid]}\n")

    # ── 5. Coherencia envios.email/club vs clubes_crm ──
    print("=== COHERENCIA ENVIOS vs CLUBES_CRM ===")
    cur.execute("SELECT id, email, nombre_club FROM clubes_crm")
    leads = {r['id']: r for r in cur.fetchall()}
    cur.execute("SELECT id, lead_id, email, club FROM envios ORDER BY id")
    env_rows = cur.fetchall()
    incoherencias = []
    for r in env_rows:
        if r['lead_id'] is None:
            continue
        lead = leads.get(r['lead_id'])
        if lead is None:
            incoherencias.append((r['id'], 'lead_no_existe', r['lead_id']))
            continue
        if lead['email'] and r['email'] and lead['email'].lower() != r['email'].lower():
            incoherencias.append((r['id'], 'email_mismatch', f"envio={r['email']} lead={lead['email']}"))
        if lead['nombre_club'] and r['club'] and lead['nombre_club'].lower() != r['club'].lower():
            incoherencias.append((r['id'], 'club_mismatch', f"envio={r['club']} lead={lead['nombre_club']}"))
    print(f"  Incoherencias: {len(incoherencias)}")
    for i in incoherencias:
        print("    ", i)
    print()

    # ── 6. Envíos con campaign_id=2 (PILOT): lead debe ser REAL ──
    print("=== ENVIOS CAMPAÑA 2 (PILOT): lead REAL? ===")
    cur.execute("SELECT e.id, e.lead_id, e.es_test, c.email, c.nombre_club FROM envios e JOIN clubes_crm c ON c.id=e.lead_id WHERE e.campaign_id=2 ORDER BY e.id")
    for r in cur.fetchall():
        email_l = (r['email'] or '').lower()
        nombre_l = (r['nombre_club'] or '').lower()
        lead_test = ('@futprotec.local' in email_l) or nombre_l.startswith('test')
        marca = "  <<< lead TEST en campaña PILOT" if lead_test else ""
        print(f"  envio={r['id']} lead={r['lead_id']} es_test={r['es_test']} leadTEST={lead_test}{marca}")
    print()

    # ── 7. Envíos con campaign_id=3 (SMOKE TEST): lead debe ser TEST ──
    print("=== ENVIOS CAMPAÑA 3 (SMOKE TEST): lead TEST? ===")
    cur.execute("SELECT e.id, e.lead_id, e.es_test, c.email, c.nombre_club FROM envios e JOIN clubes_crm c ON c.id=e.lead_id WHERE e.campaign_id=3 ORDER BY e.id")
    for r in cur.fetchall():
        email_l = (r['email'] or '').lower()
        nombre_l = (r['nombre_club'] or '').lower()
        lead_test = ('@futprotec.local' in email_l) or nombre_l.startswith('test')
        marca = "  <<< lead REAL en campaña TEST" if not lead_test else ""
        print(f"  envio={r['id']} lead={r['lead_id']} es_test={r['es_test']} leadTEST={lead_test}{marca}")
    print()

    # ── 8. Envíos sin campaign_id pero con lead TEST/REAL ──
    print("=== ENVIOS SIN CAMPAÑA: clasificación es_test vs lead ===")
    cur.execute("SELECT e.id, e.lead_id, e.es_test, c.email, c.nombre_club FROM envios e JOIN clubes_crm c ON c.id=e.lead_id WHERE e.campaign_id IS NULL ORDER BY e.id")
    for r in cur.fetchall():
        email_l = (r['email'] or '').lower()
        nombre_l = (r['nombre_club'] or '').lower()
        lead_test = ('@futprotec.local' in email_l) or nombre_l.startswith('test')
        determ = 1 if lead_test else 0
        marca = "  <<< DISCREPANCIA es_test vs lead" if r['es_test'] != determ else ""
        print(f"  envio={r['id']} lead={r['lead_id']} es_test={r['es_test']} leadTEST={lead_test} determ={determ}{marca}")
    print()

    db.close()
    print(f"=== FIN VERIFICACIÓN (READ-ONLY) ===")
    print(f"BD temporal: {tmp}")

if __name__ == "__main__":
    main()
