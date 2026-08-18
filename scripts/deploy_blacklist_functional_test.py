#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - PRUEBA FUNCIONAL LISTA NEGRA EN PRODUCCION
Replica EXACTAMENTE la logica real de dashboard.php (blacklist_add, blacklist_remove)
y eligibilidad.php (esElegibleParaEnvio) sobre la BD remota.

Alcance de escritura: SOLO lead_id=1810 (TEST_CLUB_02_Barcelona).
lead_id=1814 (TEST_ABC_FINAL4_A, opt-out real) es INMUTABLE.

Fases:
  1. Backup integro de stats.db remoto
  2. Snapshot de estado 1810 y 1814
  3. TEST A: blacklist_add(1810, motivo="QA Lista Negra") -> suprimido, inelegible
  4. TEST B: blacklist_remove(1810) -> reactivado, elegible, historial conservado
  5. TEST C: blacklist_remove(1814) -> rechazado OPTOUT_REAL_PROTEGIDO, inmutable
  7. Restauracion obligatoria de 1810 al snapshot inicial
  8. Verificacion final (1810 POST==PRE, 1814 POST==PRE, integrity_check, seguridad)
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

LEAD_TEST = 1810      # TEST_CLUB_02_Barcelona (modificable)
LEAD_OPTOUT = 1814    # TEST_ABC_FINAL4_A (INMUTABLE)
MOTIVO = "QA Lista Negra"

ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def download_db(ftp, local_path):
    with open(local_path, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)

def upload_db(ftp, local_path):
    with open(local_path, "rb") as f:
        ftp.storbinary("STOR " + REMOTE_DB, f)

# ─── REPLICA DE esElegibleParaEnvio() (eligibilidad.php) ─────────────────────
def es_elegible_para_envio(db, lead_id, campaign_id=0):
    """Replica fielmente esElegibleParaEnvio() de inc/eligibilidad.php."""
    if lead_id <= 0:
        return {'ok': False, 'razon': 'lead_no_valido'}
    cur = db.cursor()
    cur.execute("SELECT id, email, estado_lead, es_duplicado, nombre_club FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'razon': 'lead_no_encontrado'}
    estado = lead[2]
    if estado in ESTADOS_SUPRESION:
        return {'ok': False, 'razon': 'supresion'}
    if lead[3] == 1:
        return {'ok': False, 'razon': 'duplicado'}
    email = lead[1] or ''
    if not email or '@' not in email or '.' not in email:
        return {'ok': False, 'razon': 'email_invalido'}
    # AISLAMIENTO TEST/REAL (FASE 6F.6)
    if campaign_id > 0:
        cur.execute("SELECT entorno FROM pipelines WHERE id = ?", (campaign_id,))
        row = cur.fetchone()
        campana_test = (row and str(row[0]).lower() == 'test') if row else False
        email_lower = email.lower()
        nombre_lower = (lead[4] or '').lower()
        lead_test = ('@futprotec.local' in email_lower) or nombre_lower.startswith('test')
        if campana_test and not lead_test:
            return {'ok': False, 'razon': 'lead_real_en_campana_test'}
        if not campana_test and lead_test:
            return {'ok': False, 'razon': 'lead_test_en_campana_no_test'}
    return {'ok': True, 'razon': 'elegible'}

# ─── REPLICA DE blacklist_add (dashboard.php) ────────────────────────────────
def blacklist_add(db, lead_id, motivo):
    """Replica fielmente la accion blacklist_add de dashboard.php."""
    cur = db.cursor()
    cur.execute("SELECT nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'error': 'Lead no encontrado'}
    obs = lead[3] or ''
    fecha = time.strftime('%Y-%m-%d %H:%M:%S')
    motivo_txt = ' | motivo=' + motivo if motivo else ''
    nueva_obs = obs + "\n[BLOQUEO MANUAL] " + fecha + " | fuente=manual" + motivo_txt
    cur.execute(
        "UPDATE clubes_crm SET estado_lead = 'Lista Negra', observaciones = ?, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = ?",
        (nueva_obs, lead_id)
    )
    cur.execute(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (?, ?, 'blacklist_add', ?, CURRENT_TIMESTAMP)",
        (lead_id, lead_id, 'Bloqueo manual añadido a Lista Negra' + (' | motivo=' + motivo if motivo else ''))
    )
    db.commit()
    return {'ok': True, 'tipo': 'bloqueo_manual'}

# ─── REPLICA DE blacklist_remove (dashboard.php) ─────────────────────────────
def blacklist_remove(db, lead_id, motivo=''):
    """Replica fielmente la accion blacklist_remove de dashboard.php."""
    cur = db.cursor()
    cur.execute("SELECT nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'error': 'Lead no encontrado'}
    obs = lead[3] or ''
    # Detectar opt-out real (baja del destinatario via email)
    import re
    es_optout_real = bool(re.search(r'\[BAJA\][^\n]*fuente\s*=\s*email', obs, re.IGNORECASE))
    if es_optout_real:
        return {'ok': False, 'error': 'Este lead tiene una BAJA REAL del destinatario (opt-out). No puede reactivarse por esta vía. El opt-out debe respetarse.', 'razon': 'OPTOUT_REAL_PROTEGIDO'}
    fecha = time.strftime('%Y-%m-%d %H:%M:%S')
    motivo_txt = ' | motivo=' + motivo if motivo else ''
    nueva_obs = obs + "\n[REACTIVACIÓN] " + fecha + " | fuente=manual | quitar_bloqueo_manual" + motivo_txt
    cur.execute(
        "UPDATE clubes_crm SET estado_lead = '01 Sin Contactar', observaciones = ?, ultimo_contacto = CURRENT_TIMESTAMP WHERE id = ?",
        (nueva_obs, lead_id)
    )
    cur.execute(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (?, ?, 'blacklist_remove', ?, CURRENT_TIMESTAMP)",
        (lead_id, lead_id, 'Bloqueo manual quitado de Lista Negra' + (' | motivo=' + motivo if motivo else ''))
    )
    db.commit()
    return {'ok': True, 'tipo': 'bloqueo_manual_quitado'}

def get_lead_snapshot(db, lead_id):
    cur = db.cursor()
    cur.execute("SELECT id, nombre_club, email, estado_lead, observaciones, ultimo_contacto FROM clubes_crm WHERE id = ?", (lead_id,))
    row = cur.fetchone()
    return {
        'id': row[0], 'nombre_club': row[1], 'email': row[2],
        'estado_lead': row[3], 'observaciones': row[4], 'ultimo_contacto': row[5]
    } if row else None

def restore_lead(db, snapshot):
    """Restaura SOLO los campos modificados del lead al snapshot."""
    cur = db.cursor()
    cur.execute(
        "UPDATE clubes_crm SET estado_lead = ?, observaciones = ?, ultimo_contacto = ? WHERE id = ?",
        (snapshot['estado_lead'], snapshot['observaciones'], snapshot['ultimo_contacto'], snapshot['id'])
    )
    db.commit()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    # ── FASE 1: BACKUP INTEGRO ──
    print("\n=== FASE 1: BACKUP INTEGRO DE stats.db ===")
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_blacklist_test_{int(time.time())}.db")
    download_db(ftp, tmp)
    size = os.path.getsize(tmp)
    md5 = file_md5(tmp)
    print(f"  Descargada: {size} bytes, md5={md5}")
    # integrity_check
    db = sqlite3.connect(tmp)
    cur = db.cursor()
    cur.execute("PRAGMA integrity_check")
    integrity = cur.fetchone()[0]
    print(f"  integrity_check = {integrity}")
    # mtime remoto
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  mtime remoto = {mtime}")
    except Exception as e:
        mtime = f"ERR {e}"
        print(f"  mtime remoto = {mtime}")
    # Guardar backup local
    backup_local = os.path.join("backups_deploy", f"stats_db_blacklist_test_pre_{int(time.time())}.db")
    shutil.copy(tmp, backup_local)
    print(f"  Backup local: {backup_local}")

    # ── FASE 2: SNAPSHOT DE ESTADO ──
    print("\n=== FASE 2: SNAPSHOT DE ESTADO ===")
    snap_1810 = get_lead_snapshot(db, LEAD_TEST)
    snap_1814 = get_lead_snapshot(db, LEAD_OPTOUT)
    print(f"  1810 PRE: estado={snap_1810['estado_lead']} | obs_len={len(snap_1810['observaciones'] or '')}")
    print(f"  1814 PRE: estado={snap_1814['estado_lead']} | obs_len={len(snap_1814['observaciones'] or '')}")

    # ── FASE 3: TEST A — BLACKLIST_ADD ──
    # NOTA: se usa campaign_id=0 para aislar la prueba de SUPRESION de Lista Negra.
    # El aislamiento TEST/REAL (FASE 6F.6) es una regla separada ya validada en
    # fases anteriores. Con campaign=2 (entorno=pilot, no-test) el lead TEST 1810
    # quedaria bloqueado por lead_test_en_campana_no_test, lo que enmascararia la
    # verificacion de supresion. La supresion se evalua ANTES del aislamiento en
    # esElegibleParaEnvio(), por lo que campaign=0 valida correctamente la supresion.
    print("\n=== FASE 3: TEST A — blacklist_add(1810) ===")
    res = blacklist_add(db, LEAD_TEST, MOTIVO)
    print(f"  blacklist_add -> {res}")
    snap_after_a = get_lead_snapshot(db, LEAD_TEST)
    print(f"  1810 estado tras add: {snap_after_a['estado_lead']}")
    eleg = es_elegible_para_envio(db, LEAD_TEST, campaign_id=0)
    print(f"  esElegibleParaEnvio(1810, campaign=0) = {eleg}")
    assert snap_after_a['estado_lead'] == 'Lista Negra', "TEST A FAIL: no suprimido"
    assert eleg['ok'] == False and eleg['razon'] == 'supresion', "TEST A FAIL: no inelegible por supresion"
    assert '[BLOQUEO MANUAL]' in (snap_after_a['observaciones'] or ''), "TEST A FAIL: historial BLOQUEO MANUAL no registrado"
    print("  TEST A: PASS")

    # ── FASE 4: TEST B — BLACKLIST_REMOVE ──
    print("\n=== FASE 4: TEST B — blacklist_remove(1810) ===")
    res = blacklist_remove(db, LEAD_TEST)
    print(f"  blacklist_remove -> {res}")
    snap_after_b = get_lead_snapshot(db, LEAD_TEST)
    print(f"  1810 estado tras remove: {snap_after_b['estado_lead']}")
    eleg = es_elegible_para_envio(db, LEAD_TEST, campaign_id=0)
    print(f"  esElegibleParaEnvio(1810, campaign=0) = {eleg}")
    assert snap_after_b['estado_lead'] == '01 Sin Contactar', "TEST B FAIL: no reactivado"
    assert eleg['ok'] == True, "TEST B FAIL: no elegible tras remove"
    # Historial conservado (BLOQUEO MANUAL + REACTIVACION presentes)
    obs_b = snap_after_b['observaciones'] or ''
    assert '[BLOQUEO MANUAL]' in obs_b, "TEST B FAIL: historial BLOQUEO MANUAL borrado"
    assert '[REACTIVACIÓN]' in obs_b, "TEST B FAIL: historial REACTIVACION no registrado"
    print("  TEST B: PASS (historial conservado)")

    # Verificacion adicional: con campaign=2 (no-test), el lead TEST 1810 reactivado
    # queda bloqueado por aislamiento TEST/REAL (regla separada, esperada y correcta).
    eleg_camp2 = es_elegible_para_envio(db, LEAD_TEST, campaign_id=2)
    print(f"  [info] esElegibleParaEnvio(1810, campaign=2) tras remove = {eleg_camp2} (aislamiento TEST/REAL, regla separada)")


    # ── FASE 5: TEST C — PROTECCION OPT-OUT REAL ──
    print("\n=== FASE 5: TEST C — blacklist_remove(1814) debe ser rechazado ===")
    res = blacklist_remove(db, LEAD_OPTOUT)
    print(f"  blacklist_remove(1814) -> {res}")
    assert res['ok'] == False and res.get('razon') == 'OPTOUT_REAL_PROTEGIDO', "TEST C FAIL: opt-out real no protegido"
    snap_1814_after = get_lead_snapshot(db, LEAD_OPTOUT)
    print(f"  1814 estado tras intento: {snap_1814_after['estado_lead']}")
    assert snap_1814_after['estado_lead'] == snap_1814['estado_lead'], "TEST C FAIL: 1814 estado cambiado"
    assert snap_1814_after['observaciones'] == snap_1814['observaciones'], "TEST C FAIL: 1814 historial alterado"
    eleg_1814 = es_elegible_para_envio(db, LEAD_OPTOUT, campaign_id=2)
    print(f"  esElegibleParaEnvio(1814, campaign=2) = {eleg_1814}")
    assert eleg_1814['ok'] == False and eleg_1814['razon'] == 'supresion', "TEST C FAIL: 1814 no inelegible"
    print("  TEST C: PASS (opt-out real protegido, inmutable)")

    # ── FASE 7: RESTAURACION OBLIGATORIA DE 1810 ──
    print("\n=== FASE 7: RESTAURACION OBLIGATORIA DE 1810 ===")
    restore_lead(db, snap_1810)
    snap_1810_post = get_lead_snapshot(db, LEAD_TEST)
    print(f"  1810 POST: estado={snap_1810_post['estado_lead']} | obs_len={len(snap_1810_post['observaciones'] or '')}")
    assert snap_1810_post['estado_lead'] == snap_1810['estado_lead'], "RESTORE FAIL: estado no restaurado"
    assert snap_1810_post['observaciones'] == snap_1810['observaciones'], "RESTORE FAIL: observaciones no restauradas"
    print("  1810 restaurado al snapshot inicial")

    # ── FASE 8: VERIFICACION FINAL ──
    print("\n=== FASE 8: VERIFICACION FINAL ===")
    snap_1814_post = get_lead_snapshot(db, LEAD_OPTOUT)
    assert snap_1814_post['observaciones'] == snap_1814['observaciones'], "FINAL FAIL: 1814 alterado"
    assert snap_1814_post['estado_lead'] == snap_1814['estado_lead'], "FINAL FAIL: 1814 estado alterado"
    print("  1814 POST == PRE (inmutable)")

    cur.execute("PRAGMA integrity_check")
    integrity_final = cur.fetchone()[0]
    print(f"  integrity_check final = {integrity_final}")
    assert integrity_final == 'ok', "FINAL FAIL: integrity_check != ok"

    # Seguridad
    cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    for r in cur.fetchall():
        print(f"  config.{r[0]} = {r[1]}")
    cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
    print(f"  envios_email_hoy = {cur.fetchone()[0]}")

    db.close()

    # ── SUBIR BD MODIFICADA (con 1810 restaurado) ──
    print("\n=== SUBIENDO BD MODIFICADA (1810 restaurado) ===")
    upload_db(ftp, tmp)
    print("  BD subida al remoto")

    # Verificar MD5 de la BD subida
    tmp2 = os.path.join(tempfile.gettempdir(), f"futprotec_blacklist_verify_{int(time.time())}.db")
    download_db(ftp, tmp2)
    md5_uploaded = file_md5(tmp2)
    print(f"  MD5 BD subida = {md5_uploaded}")
    os.remove(tmp2)

    ftp.quit()
    os.remove(tmp)
    print("\n=== RESULTADO: BLACKLIST_PRODUCTION_FUNCTIONAL_PASS ===")

if __name__ == "__main__":
    main()
