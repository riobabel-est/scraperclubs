#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - AUDITORIA BD REMOTA (solo lectura)
Descarga stats.db remoto a temporal y consulta:
  1. Leads TEST disponibles (para pruebas de Lista Negra manual)
  2. Lead TEST con baja real [BAJA] fuente=email (opt-out real protegido)
  3. Cuentas SMTP con limite_diario (fuente unica de verdad)
  4. Estado de la cola (leads elegibles vs suprimidos)
  5. Seguridad: modo_entorno, envios comerciales, campaign 2
NO modifica nada.
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

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_blacklist_{int(time.time())}.db")
    print(f"\nDescargando BD remota a {tmp} ...")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"  Descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # ── 1. LEADS TEST DISPONIBLES ──
    print("\n=== 1. LEADS TEST DISPONIBLES (para pruebas Lista Negra manual) ===")
    try:
        cur.execute("""
            SELECT id, nombre_club, email, estado_lead, observaciones
            FROM clubes_crm
            WHERE (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
            ORDER BY id ASC
        """)
        rows = cur.fetchall()
        print(f"  Total leads TEST: {len(rows)}")
        for r in rows[:30]:
            print(f"    id={r[0]} | {r[1]} | {r[2]} | estado={r[3]}")
    except Exception as e:
        print(f"  [ERR] {e}")

    # ── 2. LEAD TEST CON BAJA REAL [BAJA] fuente=email ──
    print("\n=== 2. LEAD TEST CON BAJA REAL (opt-out real protegido) ===")
    try:
        cur.execute("""
            SELECT id, nombre_club, email, estado_lead, observaciones
            FROM clubes_crm
            WHERE (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
              AND observaciones LIKE '%[BAJA]%'
            ORDER BY id ASC
        """)
        rows = cur.fetchall()
        print(f"  Leads TEST con [BAJA]: {len(rows)}")
        for r in rows:
            print(f"    id={r[0]} | {r[1]} | {r[2]} | estado={r[3]}")
            obs = r[4] or ''
            for line in obs.split('\n'):
                if '[BAJA]' in line:
                    print(f"      BAJA: {line.strip()}")
    except Exception as e:
        print(f"  [ERR] {e}")

    # ── 3. CUENTAS SMTP (limite_diario fuente unica) ──
    print("\n=== 3. CUENTAS SMTP (limite_diario en BD) ===")
    try:
        cur.execute("SELECT id, email, limite_diario, activa, enviados_hoy FROM cuentas_smtp ORDER BY id ASC")
        rows = cur.fetchall()
        for r in rows:
            print(f"    id={r[0]} | {r[1]} | limite_diario={r[2]} | activa={r[3]} | enviados_hoy={r[4]}")
    except Exception as e:
        print(f"  [ERR] {e}")

    # ── 4. ESTADO DE LA COLA (elegibles vs suprimidos) ──
    print("\n=== 4. ESTADO DE LA COLA (leads elegibles vs suprimidos) ===")
    try:
        estados_supresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']
        in_list = "','".join(estados_supresion)
        cur.execute(f"""
            SELECT COUNT(*) FROM clubes_crm
            WHERE email IS NOT NULL AND email != '' AND es_duplicado = 0
              AND estado_lead NOT IN ('{in_list}')
        """)
        elegibles = cur.fetchone()[0]
        cur.execute(f"""
            SELECT COUNT(*) FROM clubes_crm
            WHERE estado_lead IN ('{in_list}')
        """)
        suprimidos = cur.fetchone()[0]
        print(f"  Leads elegibles (sin supresion): {elegibles}")
        print(f"  Leads suprimidos (Lista Negra/opt-out): {suprimidos}")
    except Exception as e:
        print(f"  [ERR] {e}")

    # ── 5. SEGURIDAD ──
    print("\n=== 5. SEGURIDAD ===")
    try:
        cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado','lanzadera_delay')")
        for r in cur.fetchall():
            print(f"    config.{r[0]} = {r[1]}")
    except Exception as e:
        print(f"  [ERR config] {e}")

    try:
        cur.execute("SELECT COUNT(*) FROM envios WHERE campaign = 2")
        print(f"    envios campaign=2 (comerciales): {cur.fetchone()[0]}")
    except Exception as e:
        print(f"  [ERR envios] {e}")

    try:
        cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
        print(f"    envios_email_hoy: {cur.fetchone()[0]}")
    except Exception as e:
        print(f"  [ERR log] {e}")

    db.close()
    os.remove(tmp)
    print("\nAuditoria BD remota completada (solo lectura).")

if __name__ == "__main__":
    main()
