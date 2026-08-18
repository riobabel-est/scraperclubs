#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseC2_precheck.py — PRECHECK READ-ONLY de la Fase C.2 (microenvío 5 leads).

Descarga la BD de producción, crea backup verificable y verifica TODAS las
precondiciones de la Fase C.2 SIN enviar ningún email:

  - motor pausado
  - campaña 2 (PILOTO_FUTPROTEC_2026_08): entorno=pilot, estado=PILOT, activo=1
  - plantilla 1 A/B/C con contenido + enlace de baja
  - los 5 leads autorizados (2,3,4,6,8): REAL, email válido, no suppression,
    no duplicado, no envío previo en campaña 2, variante esperada B/B/B/A/C
  - integridad SQLite (integrity_check, foreign_key_check)
  - conteo actual de envíos campaña 2 (esperado 22)

NO envía emails. NO modifica BD. NO lanza campañas.
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import shutil
import zlib
from datetime import datetime

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
BACKUP_DIR = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "backups_deploy"))

CAMPAIGN_ID = 2
PLANTILLA_ID = 1
LEADS_AUTORIZADOS = [2, 3, 4, 6, 8]
VARIANTES_ESPERADAS = {2: 'B', 3: 'B', 4: 'B', 6: 'A', 8: 'C'}
ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def file_sha256(path):
    h = hashlib.sha256()
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

def es_lead_test(email, nombre_club):
    email_l = (email or "").lower()
    nombre_l = (nombre_club or "").lower()
    if email_l and "@futprotec.local" in email_l:
        return True
    if nombre_l and nombre_l.startswith("test"):
        return True
    return False

def main():
    print("=" * 90)
    print("FASE C.2 — PRECHECK READ-ONLY (microenvío 5 leads)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Leads autorizados: {LEADS_AUTORIZADOS}")
    print(f"Variantes esperadas: {VARIANTES_ESPERADAS}")
    print()

    # ── 1. Descargar BD ──────────────────────────────────────────────────────
    print("1. DESCARGAR BD DE PRODUCCIÓN")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"  MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"  MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_c2pre_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()

    size = os.path.getsize(tmp)
    md5 = file_md5(tmp)
    sha256 = file_sha256(tmp)
    print(f"  Tamaño: {size} bytes")
    print(f"  MD5: {md5}")
    print(f"  SHA-256: {sha256}")

    # ── 2. Backup verificable ────────────────────────────────────────────────
    print("\n2. BACKUP VERIFICABLE")
    os.makedirs(BACKUP_DIR, exist_ok=True)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_local = os.path.join(BACKUP_DIR, f"stats_db_faseC2_pre_{timestamp}.db")
    shutil.copy2(tmp, backup_local)
    backup_md5 = file_md5(backup_local)
    backup_sha256 = file_sha256(backup_local)
    print(f"  Backup local: {backup_local}")
    print(f"  MD5: {backup_md5}")
    print(f"  SHA-256: {backup_sha256}")

    # ── 3. Integridad ────────────────────────────────────────────────────────
    print("\n3. INTEGRIDAD SQLITE")
    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()
    integrity = cur.execute("PRAGMA integrity_check").fetchone()[0]
    fk = cur.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check: {integrity}")
    print(f"  foreign_key_check: {len(fk)} violaciones")
    if integrity != "ok" or len(fk) > 0:
        print("  [CRÍTICO] Integridad comprometida. STOP.")
        db.close()
        sys.exit(1)

    # ── 4. Config / motor ────────────────────────────────────────────────────
    print("\n4. CONFIG / MODO ENTORNO / MOTOR")
    config = {}
    try:
        cur.execute("SELECT clave, valor FROM config")
        for r in cur.fetchall():
            config[r["clave"]] = r["valor"]
    except Exception as e:
        print(f"  [WARN] config: {e}")
    modo_entorno = config.get("modo_entorno", "test")
    motor_estado = config.get("motor_estado", "pausado")
    print(f"  modo_entorno = {modo_entorno}")
    print(f"  motor_estado = {motor_estado}")
    if motor_estado != "pausado":
        print("  [CRÍTICO] motor_estado NO pausado. STOP.")
        db.close()
        sys.exit(1)

    # ── 5. Campaña 2 ─────────────────────────────────────────────────────────
    print("\n5. CAMPAÑA 2")
    cur.execute("SELECT * FROM pipelines WHERE id = ?", (CAMPAIGN_ID,))
    camp = cur.fetchone()
    if not camp:
        print("  [CRÍTICO] Campaña 2 NO existe. STOP.")
        db.close()
        sys.exit(1)
    cd = dict(camp)
    print(f"  nombre: {cd.get('nombre')!r}")
    print(f"  entorno: {cd.get('entorno')!r}")
    print(f"  estado: {cd.get('estado')!r}")
    print(f"  activo: {cd.get('activo')!r}")
    print(f"  tipo: {cd.get('tipo')!r}")
    ok = True
    if (cd.get('entorno') or '').lower() != 'pilot':
        print("  [CRÍTICO] entorno ≠ pilot. STOP."); ok = False
    if (cd.get('estado') or '').upper() != 'PILOT':
        print("  [CRÍTICO] estado ≠ PILOT. STOP."); ok = False
    if int(cd.get('activo') or 0) != 1:
        print("  [CRÍTICO] activo ≠ 1. STOP."); ok = False
    if not ok:
        db.close(); sys.exit(1)

    # ── 6. Plantilla 1 ───────────────────────────────────────────────────────
    print("\n6. PLANTILLA 1 (A/B/C)")
    cur.execute("SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo FROM plantillas WHERE id = ?", (PLANTILLA_ID,))
    pl = cur.fetchone()
    if not pl:
        print("  [CRÍTICO] Plantilla 1 NO existe. STOP.")
        db.close(); sys.exit(1)
    p = dict(pl)
    print(f"  nombre: {p['nombre']!r}")
    print(f"  tipo: {p['tipo']!r}")
    print(f"  test_ab: {p['test_ab']!r}")
    for var, a, c in [('A', p['asunto'], p['cuerpo']), ('B', p['asunto_b'], p['cuerpo_b']), ('C', p['asunto_c'], p['cuerpo_c'])]:
        if not a or not c:
            print(f"  [CRÍTICO] Variante {var} sin contenido. STOP.")
            db.close(); sys.exit(1)
        if 'baja.php' not in (c or ''):
            print(f"  [CRÍTICO] Variante {var} sin enlace de baja. STOP.")
            db.close(); sys.exit(1)
        print(f"  Variante {var}: contenido ✓ + enlace baja ✓")

    # ── 7. Los 5 leads ───────────────────────────────────────────────────────
    print("\n7. PRECHECK DE LOS 5 LEADS")
    precheck_ok = True
    for lid in LEADS_AUTORIZADOS:
        cur.execute("SELECT id, nombre_club, email, federacion, persona_contacto, estado_lead, es_duplicado FROM clubes_crm WHERE id = ?", (lid,))
        lead = cur.fetchone()
        if not lead:
            print(f"  [CRÍTICO] lead {lid} NO existe. STOP.")
            precheck_ok = False
            continue
        ld = dict(lead)
        is_test = es_lead_test(ld['email'], ld['nombre_club'])
        variante_calc = asignar_variante(lid, CAMPAIGN_ID)
        variante_esp = VARIANTES_ESPERADAS[lid]
        email_ok = bool(ld['email']) and '@' in ld['email']
        supresion = ld['estado_lead'] in ESTADOS_SUPRESION
        duplicado = int(ld['es_duplicado'] or 0) == 1
        cur.execute("SELECT COUNT(*) FROM envios WHERE lead_id = ? AND campaign_id = ?", (lid, CAMPAIGN_ID))
        n_prev = cur.fetchone()[0]

        print(f"  lead {lid} | {ld['nombre_club']} | {ld['email']}")
        print(f"    TEST/REAL: {'TEST' if is_test else 'REAL'} | variante calc={variante_calc} (esp={variante_esp}) | email_ok={email_ok} | supresión={supresion} | duplicado={duplicado} | envíos_prev_camp2={n_prev}")

        if is_test:
            print(f"    [CRÍTICO] lead {lid} es TEST. STOP."); precheck_ok = False
        if variante_calc != variante_esp:
            print(f"    [CRÍTICO] variante {variante_calc} ≠ {variante_esp}. STOP."); precheck_ok = False
        if not email_ok:
            print(f"    [CRÍTICO] email inválido. STOP."); precheck_ok = False
        if supresion:
            print(f"    [CRÍTICO] lead en supresión. STOP."); precheck_ok = False
        if duplicado:
            print(f"    [CRÍTICO] lead duplicado. STOP."); precheck_ok = False
        if n_prev > 0:
            print(f"    [CRÍTICO] lead ya tiene envío en campaña 2. STOP."); precheck_ok = False

    if not precheck_ok:
        print("\n  [CRÍTICO] Precheck falló. STOP. NO enviar nada.")
        db.close(); sys.exit(1)
    print("\n  Precheck de los 5 leads: TODOS PASS ✓")

    # ── 8. Conteo actual campaña 2 ───────────────────────────────────────────
    print("\n8. CONTEO ACTUAL CAMPAÑA 2")
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ?", (CAMPAIGN_ID,))
    n_camp2 = cur.fetchone()[0]
    print(f"  Envíos campaña 2 actuales: {n_camp2} (esperado 22)")
    if n_camp2 != 22:
        print(f"  [WARN] Se esperaban 22, hay {n_camp2}. Verificar antes de enviar.")

    # ── 9. Cuentas SMTP activas ──────────────────────────────────────────────
    print("\n9. CUENTAS SMTP ACTIVAS")
    cur.execute("SELECT id, email, host, puerto, activa, limite_diario, enviados_hoy FROM cuentas_smtp WHERE activa = 1 ORDER BY id")
    cuentas = cur.fetchall()
    if not cuentas:
        print("  [CRÍTICO] No hay cuentas SMTP activas. STOP.")
        db.close(); sys.exit(1)
    for c in cuentas:
        print(f"  id={c['id']} {c['email']} {c['host']}:{c['puerto']} ({c['enviados_hoy']}/{c['limite_diario']})")

    db.close()

    # ── Resumen ──────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("RESUMEN PRECHECK FASE C.2")
    print("=" * 90)
    print(f"  BD: {size} bytes | MD5: {md5} | SHA-256: {sha256}")
    print(f"  Backup: {backup_local}")
    print(f"  Backup MD5: {backup_md5}")
    print(f"  Backup SHA-256: {backup_sha256}")
    print(f"  integrity_check: {integrity} | foreign_key_check: {len(fk)}")
    print(f"  modo_entorno: {modo_entorno} | motor_estado: {motor_estado}")
    print(f"  Campaña 2: {cd.get('nombre')!r} entorno={cd.get('entorno')!r} estado={cd.get('estado')!r} activo={cd.get('activo')!r}")
    print(f"  Envíos campaña 2 actuales: {n_camp2}")
    print(f"  Leads autorizados: {LEADS_AUTORIZADOS} → variantes {[VARIANTES_ESPERADAS[l] for l in LEADS_AUTORIZADOS]}")
    print(f"  EMAILS ENVIADOS: 0 (precheck read-only)")
    print(f"  CAMPAÑAS LANZADAS: 0")
    print("=" * 90)
    print("PRECHECK PASS — LISTO PARA MICROENVÍO VÍA LANZADERA WEB AUTENTICADA")
    print("=" * 90)

    # Limpiar temp
    try:
        os.remove(tmp)
    except Exception:
        pass

if __name__ == "__main__":
    main()
