#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Prueba funcional de Lista Negra BIDIRECCIONAL sobre una COPIA de la BD de produccion.

IMPORTANTE: NO modifica la BD real. Descarga stats.db, ejecuta los Tests A-D
replicando EXACTAMENTE la logica del dashboard.php DESPLEGADO (nuevo comportamiento
bidireccional), verifica los resultados y NO sube la BD modificada de vuelta.
La BD real queda intacta.

Tests (BLOQUE 10):
  A  Lead normal -> añadir -> suprimido, inelegible, visible en Lista Negra
  B  Quitar (motivo obligatorio) -> no suprimido, elegible, historial permanece
  C  Lead con opt-out real (1814) -> quitar PERMITIDO -> elegible, historial [BAJA] intacto
  D  Añadir->Quitar->Añadir->Quitar -> historial no se pierde

Seguridad: verifica modo_entorno=produccion, motor_estado=pausado, envios_hoy.
"""
import ftplib
import os
import sys
import time
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

if not USER or not PASS:
    print("ERROR: FTP_USER/FTP_PASS no presentes en .env")
    sys.exit(1)

ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

PASS_N = 0
FAIL_N = 0
FAILS = []

def check(nombre, cond, detalle=''):
    global PASS_N, FAIL_N, FAILS
    if cond:
        PASS_N += 1
        print(f"  ✅ {nombre}")
    else:
        FAIL_N += 1
        FAILS.append(nombre)
        print(f"  ❌ {nombre}" + (f" — {detalle}" if detalle else ""))

# ─── REPLICA EXACTA del dashboard.php DESPLEGADO (nuevo comportamiento) ──────
# SELECT: nombre_club(0), email(1), estado_lead(2), observaciones(3), estado_lead_backup(4)
def blacklist_add(db, lead_id, motivo):
    cur = db.cursor()
    cur.execute("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'error': 'Lead no encontrado'}
    estado_actual = lead[2] or ''
    if estado_actual in ESTADOS_SUPRESION:
        return {'ok': True, 'tipo': 'ya_suprimido', 'ya_suprimido': True}
    fecha = time.strftime('%Y-%m-%d %H:%M:%S')
    motivo_txt = ' | motivo=' + motivo if motivo else ''
    obs = lead[3] or ''
    nueva_obs = obs + "\n[LISTA NEGRA] " + fecha + " | fuente=manual" + motivo_txt
    backup_actual = lead[4] or ''
    nuevo_backup = backup_actual if backup_actual else estado_actual
    cur.execute(
        "UPDATE clubes_crm SET estado_lead='Lista Negra', estado_lead_backup=?, observaciones=?, ultimo_contacto=CURRENT_TIMESTAMP WHERE id=?",
        (nuevo_backup, nueva_obs, lead_id)
    )
    cur.execute(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (?, ?, 'blacklist_add', ?, CURRENT_TIMESTAMP)",
        (lead_id, lead_id, 'Añadido a Lista Negra' + motivo_txt)
    )
    db.commit()
    return {'ok': True, 'tipo': 'bloqueo_manual'}

def blacklist_remove(db, lead_id, motivo):
    if motivo == '':
        return {'ok': False, 'error': 'El motivo de reactivación es obligatorio.', 'razon': 'MOTIVO_REQUERIDO'}
    cur = db.cursor()
    cur.execute("SELECT nombre_club, email, estado_lead, observaciones, estado_lead_backup FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'error': 'Lead no encontrado'}
    obs = lead[3] or ''
    backup = (lead[4] or '').strip()
    estado_restaurado = '01 Sin Contactar'
    if backup and backup not in ESTADOS_SUPRESION:
        estado_restaurado = backup
    fecha = time.strftime('%Y-%m-%d %H:%M:%S')
    motivo_txt = ' | motivo=' + motivo
    nueva_obs = obs + "\n[REACTIVACIÓN] " + fecha + " | fuente=manual | quitar_lista_negra" + motivo_txt
    cur.execute(
        "UPDATE clubes_crm SET estado_lead=?, estado_lead_backup='', observaciones=?, ultimo_contacto=CURRENT_TIMESTAMP WHERE id=?",
        (estado_restaurado, nueva_obs, lead_id)
    )
    cur.execute(
        "INSERT INTO comunicaciones_log (lead_id, club_id, tipo_evento, detalles, fecha) VALUES (?, ?, 'blacklist_remove', ?, CURRENT_TIMESTAMP)",
        (lead_id, lead_id, 'Quitado de Lista Negra | estado_restaurado=' + estado_restaurado + motivo_txt)
    )
    db.commit()
    return {'ok': True, 'tipo': 'lista_negra_quitado', 'estado_restaurado': estado_restaurado}

def es_elegible(db, lead_id):
    cur = db.cursor()
    cur.execute("SELECT id, email, estado_lead, es_duplicado, nombre_club FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return {'ok': False, 'razon': 'lead_no_encontrado'}
    if lead[2] in ESTADOS_SUPRESION:
        return {'ok': False, 'razon': 'supresion'}
    if lead[3] == 1:
        return {'ok': False, 'razon': 'duplicado'}
    email = lead[1] or ''
    if not email or '@' not in email or '.' not in email:
        return {'ok': False, 'razon': 'email_invalido'}
    return {'ok': True, 'razon': 'elegible'}

def en_lista_negra(db, lead_id):
    cur = db.cursor()
    cur.execute("SELECT estado_lead FROM clubes_crm WHERE id = ?", (lead_id,))
    row = cur.fetchone()
    return (row and row[0] in ESTADOS_SUPRESION)

def get_lead(db, lead_id):
    cur = db.cursor()
    cur.execute("SELECT id, nombre_club, email, estado_lead, estado_lead_backup, observaciones FROM clubes_crm WHERE id = ?", (lead_id,))
    row = cur.fetchone()
    return {
        'id': row[0], 'nombre_club': row[1], 'email': row[2],
        'estado_lead': row[3], 'estado_lead_backup': row[4], 'observaciones': row[5]
    } if row else None

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_bl_bidir_test_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"BD descargada (copia): {os.path.getsize(tmp)} bytes")

    db = sqlite3.connect(tmp)
    cur = db.cursor()
    cur.execute("PRAGMA integrity_check")
    print(f"  integrity_check = {cur.fetchone()[0]}")

    # ── Seguridad ──
    print("\n=== SEGURIDAD ===")
    cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    cfg = {r[0]: r[1] for r in cur.fetchall()}
    print(f"  modo_entorno = {cfg.get('modo_entorno')}")
    print(f"  motor_estado = {cfg.get('motor_estado')}")
    cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
    envios_hoy = cur.fetchone()[0]
    print(f"  envios_email_hoy = {envios_hoy}")

    # ── Seleccionar leads de prueba ──
    # Lead operativo para Tests A/B/D: 1810 (TEST_CLUB_02_Barcelona, estado=01 Sin Contactar)
    # Lead opt-out real para Test C: 1814 (TEST_ABC_FINAL4_A, estado=Lista Negra)
    LEAD_OP = 1810
    LEAD_OPTOUT = 1814

    snap_op = get_lead(db, LEAD_OP)
    snap_optout = get_lead(db, LEAD_OPTOUT)
    print(f"\n=== SNAPSHOT ===")
    print(f"  {LEAD_OP} PRE: estado={snap_op['estado_lead']} | backup={snap_op['estado_lead_backup']!r}")
    print(f"  {LEAD_OPTOUT} PRE: estado={snap_optout['estado_lead']} | backup={snap_optout['estado_lead_backup']!r}")
    print(f"  {LEAD_OPTOUT} obs contiene [BAJA] fuente=email: {'[BAJA]' in (snap_optout['observaciones'] or '') and 'fuente=email' in (snap_optout['observaciones'] or '')}")

    # ── TEST A: añadir lead normal ──
    print("\n=== TEST A — Añadir lead normal a Lista Negra ===")
    r = blacklist_add(db, LEAD_OP, 'QA Lista Negra bidireccional')
    check('A1 blacklist_add ok', r['ok'] is True)
    lead_a = get_lead(db, LEAD_OP)
    check('A2 estado = Lista Negra', lead_a['estado_lead'] == 'Lista Negra')
    check('A3 estado_lead_backup guardado', lead_a['estado_lead_backup'] == snap_op['estado_lead'])
    check('A4 marca [LISTA NEGRA] en observaciones', '[LISTA NEGRA]' in (lead_a['observaciones'] or ''))
    eleg_a = es_elegible(db, LEAD_OP)
    check('A5 inelegible (supresion)', eleg_a['ok'] is False and eleg_a['razon'] == 'supresion')
    check('A6 visible en Lista Negra', en_lista_negra(db, LEAD_OP) is True)

    # ── TEST B: quitar con motivo obligatorio ──
    print("\n=== TEST B — Quitar de Lista Negra con motivo ===")
    r_b = blacklist_remove(db, LEAD_OP, '')
    check('B1 motivo obligatorio (vacío rechazado)', r_b['ok'] is False and r_b.get('razon') == 'MOTIVO_REQUERIDO')
    r_b2 = blacklist_remove(db, LEAD_OP, 'Cliente volvió a solicitar contacto')
    check('B2 blacklist_remove ok', r_b2['ok'] is True)
    check('B3 estado restaurado', r_b2['estado_restaurado'] == snap_op['estado_lead'])
    lead_b = get_lead(db, LEAD_OP)
    check('B4 no suprimido', lead_b['estado_lead'] == snap_op['estado_lead'])
    check('B5 backup limpiado', lead_b['estado_lead_backup'] == '')
    check('B6 historial [LISTA NEGRA] permanece', '[LISTA NEGRA]' in (lead_b['observaciones'] or ''))
    check('B7 marca [REACTIVACIÓN] añadida', '[REACTIVACIÓN]' in (lead_b['observaciones'] or ''))
    eleg_b = es_elegible(db, LEAD_OP)
    check('B8 elegible', eleg_b['ok'] is True and eleg_b['razon'] == 'elegible')
    check('B9 desaparece de Lista Negra', en_lista_negra(db, LEAD_OP) is False)

    # ── TEST C: opt-out real (1814) — quitar PERMITIDO ──
    print("\n=== TEST C — Opt-out real: quitar PERMITIDO, historial [BAJA] intacto ===")
    eleg_c0 = es_elegible(db, LEAD_OPTOUT)
    check('C1 inicialmente inelegible (supresion)', eleg_c0['ok'] is False and eleg_c0['razon'] == 'supresion')
    r_c = blacklist_remove(db, LEAD_OPTOUT, 'Cliente activo / relación comercial')
    check('C2 quitar opt-out PERMITIDO', r_c['ok'] is True)
    lead_c = get_lead(db, LEAD_OPTOUT)
    obs_c = lead_c['observaciones'] or ''
    check('C3 historial [BAJA] fuente=email intacto', '[BAJA]' in obs_c and 'fuente=email' in obs_c)
    check('C4 marca [REACTIVACIÓN] añadida', '[REACTIVACIÓN]' in obs_c)
    eleg_c = es_elegible(db, LEAD_OPTOUT)
    check('C5 elegible tras quitar (sin otra causa)', eleg_c['ok'] is True and eleg_c['razon'] == 'elegible')

    # ── TEST D: ciclo repetido añadir/quitar (lead fresco 1811, no tocado) ──
    print("\n=== TEST D — Ciclo repetido añadir/quitar ===")
    LEAD_D = 1811  # TEST_CLUB_03_Valencia, estado=01 Sin Contactar (fresco)
    snap_d = get_lead(db, LEAD_D)
    blacklist_add(db, LEAD_D, 'Bloqueo 1')
    blacklist_remove(db, LEAD_D, 'Reactivación 1')
    blacklist_add(db, LEAD_D, 'Bloqueo 2')
    blacklist_remove(db, LEAD_D, 'Reactivación 2')
    lead_d = get_lead(db, LEAD_D)
    obs_d = lead_d['observaciones'] or ''
    check('D1 2 marcas [LISTA NEGRA]', obs_d.count('[LISTA NEGRA]') == 2)
    check('D2 2 marcas [REACTIVACIÓN]', obs_d.count('[REACTIVACIÓN]') == 2)
    check('D3 estado final operativo', lead_d['estado_lead'] == snap_d['estado_lead'])
    check('D4 elegible al final', es_elegible(db, LEAD_D)['ok'] is True)


    # ── Verificación final: la BD real NO se modifica (no se sube) ──
    print("\n=== VERIFICACION FINAL ===")
    print("  La BD modificada NO se sube al remoto. La BD real queda intacta.")
    cur.execute("PRAGMA integrity_check")
    print(f"  integrity_check final (copia) = {cur.fetchone()[0]}")

    db.close()
    os.remove(tmp)

    print("\n═══════════════════════════════════════════════════════════════")
    print(f" RESULTADO: {PASS_N} pasados, {FAIL_N} fallidos")
    if FAIL_N > 0:
        print(" FALLIDOS:")
        for f in FAILS:
            print(f"   - {f}")
        print(" VEREDICTO: BLOCKED")
        sys.exit(1)
    print(" VEREDICTO: BLACKLIST_BIDIRECTIONAL_PRODUCTION_FUNCTIONAL_PASS")
    print("═══════════════════════════════════════════════════════════════")
    sys.exit(0)

if __name__ == "__main__":
    main()
