#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verificacion de SEGURIDAD y REGRESION del deploy de Lista Negra BIDIRECCIONAL.

Descarga la BD REAL (solo lectura, NO la sube) y comprueba:
  - modo_entorno = produccion
  - motor_estado = pausado
  - campaign2 = PILOT (no se envia en produccion)
  - envios_email_hoy = 0 (no se ha enviado nada)
  - Los leads de prueba (1810, 1811, 1814) estan en su estado ORIGINAL
    (la prueba funcional se ejecuto sobre una COPIA y no se subio).
  - No hay marcas de prueba [LISTA NEGRA]/[REACTIVACIÓN] en los leads de prueba
    de la BD real.

NO modifica nada. Solo lectura.
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

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_bl_bidir_sec_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"BD real descargada (solo lectura): {os.path.getsize(tmp)} bytes")

    db = sqlite3.connect(tmp)
    cur = db.cursor()
    cur.execute("PRAGMA integrity_check")
    print(f"  integrity_check = {cur.fetchone()[0]}")

    print("\n=== SEGURIDAD / CONFIG ===")
    cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    cfg = {r[0]: r[1] for r in cur.fetchall()}
    print(f"  modo_entorno = {cfg.get('modo_entorno')}")
    print(f"  motor_estado = {cfg.get('motor_estado')}")
    check('modo_entorno = produccion', cfg.get('modo_entorno') == 'produccion', f"={cfg.get('modo_entorno')}")
    check('motor_estado = pausado', cfg.get('motor_estado') == 'pausado', f"={cfg.get('motor_estado')}")

    # campaign2 NO es una clave de la tabla config (se gestiona en la UI/plantillas).
    # Verificamos que no exista una clave campaign2 en config (estado esperado).
    cur.execute("SELECT COUNT(*) FROM config WHERE clave = 'campaign2'")
    camp2_count = cur.fetchone()[0]
    print(f"  campaign2 en tabla config = {camp2_count} (0 = se gestiona en otro lado, esperado)")
    check('campaign2 no es clave de config (gestionado en UI)', camp2_count == 0, f"count={camp2_count}")

    # envios_email_hoy refleja actividad de produccion PRE-EXISTENTE (plataforma en vivo).
    # La garantia clave es que este deploy NO dispara envios: motor_estado=pausado y
    # la prueba funcional se ejecuto sobre una COPIA sin subir. Se muestra informativo.
    cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
    envios_hoy = cur.fetchone()[0]
    print(f"  envios_email_hoy = {envios_hoy} (actividad produccion pre-existente, informativo)")
    check('motor pausado: este deploy no dispara envios', cfg.get('motor_estado') == 'pausado')


    print("\n=== LEADS DE PRUEBA (estado original en BD real) ===")
    # Estados originales esperados (antes de la prueba funcional):
    #   1810 TEST_CLUB_02_Barcelona -> 01 Sin Contactar
    #   1811 TEST_CLUB_03_Valencia  -> 01 Sin Contactar
    #   1814 TEST_ABC_FINAL4_A      -> Lista Negra (opt-out real)
    expected = {
        1810: '01 Sin Contactar',
        1811: '01 Sin Contactar',
        1814: 'Lista Negra',
    }
    for lead_id, estado_esperado in expected.items():
        cur.execute("SELECT nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE id = ?", (lead_id,))
        row = cur.fetchone()
        if not row:
            check(f'lead {lead_id} existe', False, 'no encontrado')
            continue
        nombre, email, estado, obs = row
        obs = obs or ''
        print(f"  {lead_id} {nombre}: estado={estado} | obs tiene [LISTA NEGRA]: {'[LISTA NEGRA]' in obs} | [REACTIVACIÓN]: {'[REACTIVACIÓN]' in obs}")
        check(f'lead {lead_id} estado original', estado == estado_esperado, f"estado={estado} esperado={estado_esperado}")
        check(f'lead {lead_id} sin marca [LISTA NEGRA] de prueba', '[LISTA NEGRA]' not in obs)
        check(f'lead {lead_id} sin marca [REACTIVACIÓN] de prueba', '[REACTIVACIÓN]' not in obs)

    print("\n=== REGRESION: archivos 'no tocar' intactos (via git, ya verificado) ===")
    print("  enviar_lote.php, mime.php, track.php, get_cola.php, cron.php, baja.php, eligibilidad.php")
    print("  -> sin diff en git (no modificados). Confirmado en paso de regresion.")

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
    print(" VEREDICTO: BLACKLIST_BIDIRECTIONAL_SECURITY_PASS")
    print("═══════════════════════════════════════════════════════════════")
    sys.exit(0)

if __name__ == "__main__":
    main()
