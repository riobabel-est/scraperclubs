#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA2_ejecutar.py

FASE A.2 — RECONCILIACIÓN Y CORRECCIÓN CONTROLADA DE envios.es_test EN PRODUCCIÓN.

Flujo:
  1. PRECHECK read-only: descarga BD remota, clasificación determinista, sin ambigüedades.
  2. BACKUP: backup local nuevo + backup remoto, verificación de integridad.
  3. ACT MODE: UPDATE solo sobre IDs discrepantes (es_test_actual != determinista).
  4. UPLOAD: sube BD corregida a producción, verifica MD5.
  5. VERIFICACIÓN POST-UPDATE: re-auditoría, 0 discrepancias, 0 NULL, integrity_check, sqlFiltroComercial.
  6. CONTROL DE NO REGRESIÓN: solo cambió envios.es_test.

Reglas de seguridad:
  - NO modifica leads, campañas, estados, emails, timestamps, message_id, respuestas, plantillas, SMTP.
  - NO envía emails. NO lanza campañas.
  - Si cualquier precondición falla -> STOP (no modifica producción).

USO:
  python scripts/faseA2_ejecutar.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import shutil

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
REMOTE_BACKUP_BASE = "/getfutprotec.com/backups_deploy"
LOCAL_BACKUP_DIR = "backups_deploy"

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

def ensure_remote_dir(ftp, path):
    parts = path.strip("/").split("/")
    cur = ""
    for p in parts:
        cur += "/" + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass
            try:
                ftp.cwd(cur)
            except Exception:
                return False
    return True

def auditar(db_path):
    """Devuelve dict con la auditoría completa de la BD."""
    db = sqlite3.connect(db_path)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # Pipelines
    cur.execute("SELECT id, entorno FROM pipelines")
    pipelines = {r['id']: r['entorno'] for r in cur.fetchall()}

    # Envios
    cur.execute("SELECT * FROM envios ORDER BY id")
    envios = cur.fetchall()

    filas = []
    discrepancias = []
    ambiguos = []
    leads_invalidos = []
    camp_invalidos = []
    incoherencias = []

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
            cur.execute("SELECT id, email, nombre_club FROM clubes_crm WHERE id=?", (lead_id,))
            lead = cur.fetchone()
            if lead is None:
                leads_invalidos.append(eid)
            else:
                lead_test = es_lead_test(lead['email'], lead['nombre_club'])
                if lead['email'] and email_envio and lead['email'].lower() != email_envio.lower():
                    incoherencias.append((eid, lead['email'], email_envio))
        else:
            leads_invalidos.append(eid)

        # Campaña
        camp_test = False
        if camp_id:
            if camp_id not in pipelines:
                camp_invalidos.append(eid)
            else:
                camp_test = (pipelines[camp_id] or '').lower() == 'test'

        determ = 'TEST' if (lead_test or camp_test) else 'REAL'
        determ_val = 1 if determ == 'TEST' else 0

        disc = (es_test_actual != determ_val)
        if disc:
            discrepancias.append(eid)

        if lead is None:
            ambiguos.append(eid)

        filas.append({
            'id': eid, 'lead_id': lead_id, 'campaign_id': camp_id,
            'email': email_envio, 'club': club_envio,
            'entorno': pipelines.get(camp_id, '') if camp_id else '',
            'es_test_actual': es_test_actual,
            'lead_es_test': lead_test, 'campana_es_test': camp_test,
            'determinista': determ, 'determinista_val': determ_val,
            'discrepancia': disc,
        })

    # Conteos
    cur.execute("SELECT COUNT(*) FROM envios")
    total = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test=0")
    n_real = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test=1")
    n_test = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    n_null = cur.fetchone()[0]
    cur.execute("PRAGMA integrity_check")
    integrity = cur.fetchone()[0]

    db.close()
    return {
        'filas': filas, 'discrepancias': discrepancias, 'ambiguos': ambiguos,
        'leads_invalidos': leads_invalidos, 'camp_invalidos': camp_invalidos,
        'incoherencias': incoherencias, 'total': total,
        'n_real': n_real, 'n_test': n_test, 'n_null': n_null,
        'integrity': integrity,
    }

def main():
    print("=" * 70)
    print("FASE A.2 — RECONCILIACIÓN Y CORRECCIÓN DE envios.es_test")
    print("=" * 70)

    # ── 1. PRECHECK READ-ONLY ──
    print("\n[1] PRECHECK READ-ONLY")
    print("Conectando a", HOST)
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA2_pre_{ts}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    pre_md5 = file_md5(tmp)
    print(f"BD remota descargada: {os.path.getsize(tmp)} bytes, md5={pre_md5}")

    pre = auditar(tmp)
    print(f"  total_envios = {pre['total']}")
    print(f"  es_test=0 (REAL) = {pre['n_real']}")
    print(f"  es_test=1 (TEST) = {pre['n_test']}")
    print(f"  es_test IS NULL = {pre['n_null']}")
    print(f"  integrity_check = {pre['integrity']}")
    print(f"  leads_invalidos = {pre['leads_invalidos']}")
    print(f"  camp_invalidos = {pre['camp_invalidos']}")
    print(f"  incoherencias lead/email = {pre['incoherencias']}")
    print(f"  casos_ambiguos = {pre['ambiguos']}")
    print(f"  discrepancias = {pre['discrepancias']}")

    # STOP conditions
    if pre['ambiguos']:
        print("\n[STOP] Casos ambiguos detectados. No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)
    if pre['leads_invalidos']:
        print("\n[STOP] Leads inexistentes. No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)
    if pre['camp_invalidos']:
        print("\n[STOP] Campaign_id inexistentes. No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)
    if pre['incoherencias']:
        print("\n[STOP] Incoherencias lead/email. No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)
    if pre['integrity'] != 'ok':
        print("\n[STOP] integrity_check != ok. No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)

    if not pre['discrepancias']:
        print("\n[INFO] No hay discrepancias. No se requiere corrección.")
        os.remove(tmp); ftp.quit(); sys.exit(0)

    print(f"\n[OK] Precheck superado. Discrepancias a corregir: {pre['discrepancias']}")

    # ── 2. BACKUP ──
    print("\n[2] BACKUP")
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_faseA2_pre_{ts}.db")
    shutil.copyfile(tmp, local_bk)
    bk_md5 = file_md5(local_bk)
    print(f"  Backup local: {local_bk}")
    print(f"  size = {os.path.getsize(local_bk)} bytes, md5 = {bk_md5}")

    # Verificar integridad del backup
    bk_audit = auditar(local_bk)
    print(f"  Backup integrity_check = {bk_audit['integrity']}")
    if bk_audit['integrity'] != 'ok':
        print("\n[STOP] Backup no verificable (integrity != ok). No se modifica producción.")
        os.remove(tmp); ftp.quit(); sys.exit(2)

    # Backup remoto
    remote_bk_dir = f"{REMOTE_BACKUP_BASE}/stats_db_faseA2_pre_{ts}"
    if ensure_remote_dir(ftp, remote_bk_dir):
        remote_bk_path = remote_bk_dir + "/stats.db"
        with open(local_bk, "rb") as f:
            ftp.storbinary("STOR " + remote_bk_path, f)
        print(f"  Backup remoto: {remote_bk_path}")
    else:
        print("  [WARN] No se pudo crear backup remoto (continúa con backup local)")

    # ── 3. ACT MODE: corregir solo IDs discrepantes ──
    print("\n[3] ACT MODE — CORRECCIÓN DE es_test")
    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # Snapshot de todos los campos de envios ANTES (para control de no regresión)
    cur.execute("SELECT * FROM envios ORDER BY id")
    antes = {r['id']: dict(r) for r in cur.fetchall()}

    # Aplicar UPDATE solo a los IDs discrepantes
    ids_a_corregir = pre['discrepancias']
    cambios = []
    for eid in ids_a_corregir:
        fila = next(f for f in pre['filas'] if f['id'] == eid)
        valor_anterior = fila['es_test_actual']
        valor_nuevo = fila['determinista_val']
        cur.execute("UPDATE envios SET es_test=? WHERE id=?", (valor_nuevo, eid))
        cambios.append({
            'id': eid, 'anterior': valor_anterior, 'nuevo': valor_nuevo,
            'motivo': f"es_test_actual={valor_anterior} != determinista={fila['determinista']}"
        })
        print(f"  UPDATE envios SET es_test={valor_nuevo} WHERE id={eid} (antes={valor_anterior})")

    db.commit()

    # ── 4. CONTROL DE NO REGRESIÓN (solo es_test cambió) ──
    print("\n[4] CONTROL DE NO REGRESIÓN")
    cur.execute("SELECT * FROM envios ORDER BY id")
    despues = {r['id']: dict(r) for r in cur.fetchall()}
    regresiones = []
    for eid in despues:
        for campo in despues[eid]:
            if campo == 'es_test':
                continue
            if antes[eid][campo] != despues[eid][campo]:
                regresiones.append((eid, campo, antes[eid][campo], despues[eid][campo]))
    if regresiones:
        print("  [STOP] Se detectaron cambios fuera de es_test:")
        for r in regresiones:
            print(f"    {r}")
        db.close(); os.remove(tmp); ftp.quit(); sys.exit(2)
    print("  [OK] Solo cambió envios.es_test. Sin regresiones.")

    db.close()

    # ── 5. UPLOAD a producción ──
    print("\n[5] UPLOAD a producción")
    post_md5 = file_md5(tmp)
    print(f"  BD corregida: {os.path.getsize(tmp)} bytes, md5={post_md5}")
    with open(tmp, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)
    print("  Subida completada")

    # Verificar MD5 remoto
    tmp_verify = os.path.join(tempfile.gettempdir(), f"futprotec_faseA2_post_{ts}.db")
    with open(tmp_verify, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    verify_md5 = file_md5(tmp_verify)
    print(f"  MD5 remoto verificado: {verify_md5}")
    if verify_md5 != post_md5:
        print("\n[STOP] MD5 remoto != local. La subida falló. Rollback necesario.")
        os.remove(tmp); os.remove(tmp_verify); ftp.quit(); sys.exit(2)
    print("  [OK] MD5 coincide. Subida correcta.")

    ftp.quit()

    # ── 6. VERIFICACIÓN POST-UPDATE ──
    print("\n[6] VERIFICACIÓN POST-UPDATE")
    post = auditar(tmp_verify)
    print(f"  total_envios = {post['total']}")
    print(f"  es_test=0 (REAL) = {post['n_real']}")
    print(f"  es_test=1 (TEST) = {post['n_test']}")
    print(f"  es_test IS NULL = {post['n_null']}")
    print(f"  integrity_check = {post['integrity']}")
    print(f"  discrepancias restantes = {post['discrepancias']}")
    print(f"  casos_ambiguos = {post['ambiguos']}")

    # sqlFiltroComercial: SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0
    db = sqlite3.connect(tmp_verify)
    cur = db.cursor()
    cur.execute("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0")
    n_comercial = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=1")
    n_no_comercial = cur.fetchone()[0]
    db.close()
    print(f"  sqlFiltroComercial() -> REALES (es_test=0): {n_comercial}")
    print(f"  TEST (es_test=1): {n_no_comercial}")

    # ── 7. CHECKPOINT ──
    print("\n[7] CHECKPOINT FASE A.2")
    ok = (post['discrepancias'] == [] and post['ambiguos'] == []
          and post['n_null'] == 0 and post['integrity'] == 'ok'
          and n_comercial == post['n_real'] and n_no_comercial == post['n_test'])
    print(f"  VEREDICTO: {'PASS' if ok else 'FAIL'}")

    # Guardar checkpoint
    checkpoint = os.path.join("docs", f"checkpoint_faseA2_{ts}.md")
    with open(checkpoint, "w", encoding="utf-8") as f:
        f.write(f"# CHECKPOINT FASE A.2 — {ts}\n\n")
        f.write("## PRE-CHECK\n")
        f.write(f"- total_envios: {pre['total']}\n")
        f.write(f"- REAL actuales (es_test=0): {pre['n_real']}\n")
        f.write(f"- TEST actuales (es_test=1): {pre['n_test']}\n")
        f.write(f"- NULL: {pre['n_null']}\n")
        f.write(f"- discrepancias: {pre['discrepancias']}\n")
        f.write(f"- casos_ambiguos: {pre['ambiguos']}\n")
        f.write(f"- integrity_check: {pre['integrity']}\n\n")
        f.write("## BACKUP\n")
        f.write(f"- local: {local_bk}\n")
        f.write(f"- md5: {bk_md5}\n")
        f.write(f"- integrity_check: {bk_audit['integrity']}\n")
        f.write(f"- remoto: {remote_bk_path if 'remote_bk_path' in dir() else 'N/A'}\n\n")
        f.write("## CAMBIOS\n")
        for c in cambios:
            f.write(f"- ID {c['id']}: es_test {c['anterior']} -> {c['nuevo']} ({c['motivo']})\n")
        f.write("\n## POST-CHECK\n")
        f.write(f"- total_envios: {post['total']}\n")
        f.write(f"- REAL (es_test=0): {post['n_real']}\n")
        f.write(f"- TEST (es_test=1): {post['n_test']}\n")
        f.write(f"- NULL: {post['n_null']}\n")
        f.write(f"- discrepancias restantes: {post['discrepancias']}\n")
        f.write(f"- integrity_check: {post['integrity']}\n")
        f.write(f"- sqlFiltroComercial() REALES: {n_comercial}\n")
        f.write(f"- TEST: {n_no_comercial}\n\n")
        f.write(f"## VEREDICTO: {'PASS' if ok else 'FAIL'}\n")
    print(f"  Checkpoint guardado: {checkpoint}")

    os.remove(tmp)
    os.remove(tmp_verify)
    print("\n=== FIN FASE A.2 ===")

if __name__ == "__main__":
    main()
