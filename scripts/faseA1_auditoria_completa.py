#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA1_auditoria_completa.py

AUDITORÍA READ-ONLY COMPLETA de los 42 envíos de producción (FASE A.1).
Descarga data/stats.db a un temporal local y construye la matriz determinista
TEST/REAL de cada envío cruzando envios + clubes_crm (leads) + pipelines (campañas).

Clasificación determinista (espejo de esLeadTest/esCampanaTest):
  - lead TEST  = email contiene '@futprotec.local' OR nombre_club empieza por 'test'
  - camp TEST  = pipelines.entorno == 'test'
  - envio TEST = lead TEST OR camp TEST

NO modifica nada en producción. NO ejecuta UPDATE.

USO:
  python scripts/faseA1_auditoria_completa.py
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

def es_lead_test(email, nombre_club):
    email_l = (email or '').lower()
    nombre_l = (nombre_club or '').lower()
    if email_l and '@futprotec.local' in email_l:
        return True
    if nombre_l and nombre_l.startswith('test'):
        return True
    return False

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA1_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # Cargar pipelines (campañas)
    cur.execute("SELECT id, nombre, entorno, estado, identificador FROM pipelines")
    pipelines = {r['id']: r for r in cur.fetchall()}
    print("=== PIPELINES (campañas) ===")
    for pid, p in sorted(pipelines.items()):
        print(f"  id={pid} | {p['nombre']} | entorno={p['entorno']} | estado={p['estado']} | id={p['identificador']}")
    print()

    # Cargar todos los envíos
    cur.execute("SELECT * FROM envios ORDER BY id")
    envios = cur.fetchall()

    print(f"=== MATRIZ COMPLETA DE {len(envios)} ENVÍOS ===")
    print(f"{'id':>3} | {'lead':>5} | {'camp':>4} | {'entorno':>7} | {'es_test':>7} | {'leadTEST':>8} | {'campTEST':>8} | {'DETERM':>6} | {'disc':>4} | club/email")
    print("-" * 130)

    discrepancias = []
    ambiguos = []
    huerfanos = []
    leads_invalidos = []
    camp_invalidos = []
    incoherencias_lead_email = []

    for e in envios:
        eid = e['id']
        lead_id = e['lead_id']
        camp_id = e['campaign_id']
        es_test_actual = e['es_test']
        email_envio = e['email'] or ''
        club_envio = e['club'] or ''

        # Lead
        lead = None
        lead_test = False
        if lead_id:
            cur.execute("SELECT id, email, nombre_club, estado_lead, es_duplicado FROM clubes_crm WHERE id=?", (lead_id,))
            lead = cur.fetchone()
            if lead is None:
                leads_invalidos.append(eid)
            else:
                lead_test = es_lead_test(lead['email'], lead['nombre_club'])
                # Coherencia lead/email
                if lead['email'] and email_envio and lead['email'].lower() != email_envio.lower():
                    incoherencias_lead_email.append((eid, lead['email'], email_envio))
        else:
            leads_invalidos.append(eid)

        # Campaña
        camp_test = False
        if camp_id:
            p = pipelines.get(camp_id)
            if p is None:
                camp_invalidos.append(eid)
            else:
                camp_test = (p['entorno'] or '').lower() == 'test'
        else:
            # Sin campaña: no es campaña test
            camp_test = False

        # Clasificación determinista
        determ = 'TEST' if (lead_test or camp_test) else 'REAL'
        determ_val = 1 if determ == 'TEST' else 0

        # Discrepancia vs es_test actual
        disc = (es_test_actual != determ_val)
        if disc:
            discrepancias.append(eid)

        # Ambiguo: lead no encontrado o lead_id nulo (no se puede determinar)
        if lead is None:
            ambiguos.append(eid)

        entorno = ''
        if camp_id and camp_id in pipelines:
            entorno = pipelines[camp_id]['entorno'] or ''

        motivo = []
        if lead_test:
            motivo.append('leadTEST')
        if camp_test:
            motivo.append('campTEST')
        if not motivo:
            motivo.append('comercial')
        motivo_str = '+'.join(motivo)

        marca_disc = 'SI' if disc else 'no'
        marca_amb = 'AMB' if lead is None else ''

        print(f"{eid:>3} | {str(lead_id):>5} | {str(camp_id):>4} | {entorno:>7} | {str(es_test_actual):>7} | {str(lead_test):>8} | {str(camp_test):>8} | {determ:>6} | {marca_disc:>4} | {marca_amb} {club_envio} <{email_envio}> [{motivo_str}]")

    # Resumen
    print("\n=== RESUMEN ===")
    print(f"Total envíos: {len(envios)}")
    print(f"Discrepancias (es_test actual != determinista): {len(discrepancias)} -> {discrepancias}")
    print(f"Leads inválidos (lead_id nulo o no encontrado): {len(leads_invalidos)} -> {leads_invalidos}")
    print(f"Campaign_id inexistentes: {len(camp_invalidos)} -> {camp_invalidos}")
    print(f"Incoherencias lead/email: {len(incoherencias_lead_email)} -> {incoherencias_lead_email}")
    print(f"Casos ambiguos (lead no determinable): {len(ambiguos)} -> {ambiguos}")

    # Conteos deterministas
    n_test = sum(1 for e in envios if (es_lead_test(
        (cur.execute("SELECT email FROM clubes_crm WHERE id=?", (e['lead_id'],)).fetchone() or [None])[0] if e['lead_id'] else None,
        (cur.execute("SELECT nombre_club FROM clubes_crm WHERE id=?", (e['lead_id'],)).fetchone() or [None])[0] if e['lead_id'] else None
    ) or (e['campaign_id'] and pipelines.get(e['campaign_id']) and (pipelines[e['campaign_id']]['entorno'] or '').lower() == 'test')))
    print(f"Determinista TEST: {n_test}")
    print(f"Determinista REAL: {len(envios) - n_test}")

    # integrity_check
    print("\n=== PRAGMA integrity_check ===")
    cur.execute("PRAGMA integrity_check")
    print("  ", cur.fetchone()[0])

    db.close()
    os.remove(tmp)
    print("\n=== FIN AUDITORÍA COMPLETA (READ-ONLY) ===")

if __name__ == "__main__":
    main()
