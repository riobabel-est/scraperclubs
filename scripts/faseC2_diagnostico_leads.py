#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseC2_diagnostico_leads.py — Diagnóstico READ-ONLY de los 5 leads y SMTP.

Investiga por qué los leads 2,3,4,6,8 podrían no aparecer como elegibles en la
lanzadera, y recupera el historial de envíos por cuenta SMTP.

Verifica:
  - estado_lead real de los 5 leads
  - envíos de los 5 leads en CUALQUIER campaña (no solo campaña 2)
  - estructura de la tabla envios (qué columnas registran la cuenta SMTP)
  - historial de comunicaciones_log por cuenta SMTP (envios por cuenta)
  - cuentas_smtp.enviados_hoy vs comunicaciones_log real
  - si los leads cumplen el filtro de get_cola (email, es_duplicado, supresión)

NO envía emails. NO modifica BD. Solo lectura.
"""
import ftplib
import os
import sys
import time
import sqlite3
import tempfile
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

LEADS = [2, 3, 4, 6, 8]
ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

def main():
    print("=" * 90)
    print("FASE C.2 — DIAGNÓSTICO DE LEADS Y SMTP (read-only)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print()

    # ── 1. Descargar BD ──────────────────────────────────────────────────────
    print("1. DESCARGAR BD DE PRODUCCIÓN")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_c2diag_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"  Descargada: {os.path.getsize(tmp)} bytes")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── 2. Estado de los 5 leads ─────────────────────────────────────────────
    print("\n2. ESTADO DE LOS 5 LEADS")
    for lid in LEADS:
        cur.execute("SELECT id, nombre_club, email, estado_lead, es_duplicado, federacion FROM clubes_crm WHERE id = ?", (lid,))
        r = cur.fetchone()
        if not r:
            print(f"  lead {lid}: NO EXISTE")
            continue
        print(f"  lead {lid} | {r['nombre_club']} | {r['email']}")
        print(f"    estado_lead={r['estado_lead']!r} | es_duplicado={r['es_duplicado']} | federacion={r['federacion']!r}")
        # ¿Cumple filtro get_cola?
        email_ok = bool(r['email']) and '@' in r['email']
        no_supresion = r['estado_lead'] not in ESTADOS_SUPRESION
        no_dup = int(r['es_duplicado'] or 0) == 0
        print(f"    Filtro get_cola: email_ok={email_ok} | no_supresion={no_supresion} | no_dup={no_dup}")

    # ── 3. Envíos de los 5 leads en CUALQUIER campaña ────────────────────────
    print("\n3. ENVÍOS DE LOS 5 LEADS EN CUALQUIER CAMPAÑA")
    cur.execute("PRAGMA table_info(envios)")
    envios_cols = [r['name'] for r in cur.fetchall()]
    print(f"  Columnas envios: {envios_cols}")

    for lid in LEADS:
        cur.execute("SELECT id, campaign_id, estado, es_test, variant, plantilla_id, smtp_id, cuenta_emision, message_id, fecha_envio FROM envios WHERE lead_id = ? ORDER BY id", (lid,))
        envios = cur.fetchall()
        if not envios:
            print(f"  lead {lid}: SIN envíos en ninguna campaña")
        else:
            print(f"  lead {lid}: {len(envios)} envío(s)")
            for e in envios:
                print(f"    envio_id={e['id']} campaign={e['campaign_id']} estado={e['estado']} es_test={e['es_test']} variant={e['variant']} plantilla={e['plantilla_id']} smtp_id={e['smtp_id']} cuenta={e['cuenta_emision']} fecha={e['fecha_envio']}")


    # ── 4. Conteo de envíos por campaña ──────────────────────────────────────
    print("\n4. ENVÍOS POR CAMPAÑA")
    cur.execute("SELECT campaign_id, COUNT(*) as n FROM envios GROUP BY campaign_id ORDER BY campaign_id")
    for r in cur.fetchall():
        print(f"  campaign_id={r['campaign_id']}: {r['n']} envíos")

    # ── 5. Historial SMTP por cuenta (comunicaciones_log) ────────────────────
    print("\n5. HISTORIAL SMTP POR CUENTA (comunicaciones_log)")
    try:
        cur.execute("PRAGMA table_info(comunicaciones_log)")
        log_cols = [r['name'] for r in cur.fetchall()]
        print(f"  Columnas comunicaciones_log: {log_cols}")
    except Exception as e:
        print(f"  [WARN] comunicaciones_log: {e}")
        log_cols = []

    if 'id_cuenta_smtp' in log_cols and 'tipo_evento' in log_cols:
        # Total por cuenta
        cur.execute("""
            SELECT id_cuenta_smtp, COUNT(*) as n
            FROM comunicaciones_log
            WHERE tipo_evento = 'envio_email'
            GROUP BY id_cuenta_smtp
            ORDER BY id_cuenta_smtp
        """)
        print("  Envíos por cuenta (todos los tiempos):")
        for r in cur.fetchall():
            print(f"    smtp_id={r['id_cuenta_smtp']}: {r['n']}")

        # Hoy por cuenta
        cur.execute("""
            SELECT id_cuenta_smtp, COUNT(*) as n
            FROM comunicaciones_log
            WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')
            GROUP BY id_cuenta_smtp
            ORDER BY id_cuenta_smtp
        """)
        print("  Envíos por cuenta (hoy):")
        for r in cur.fetchall():
            print(f"    smtp_id={r['id_cuenta_smtp']}: {r['n']}")

        # Últimos 20 registros
        cur.execute("SELECT * FROM comunicaciones_log ORDER BY id DESC LIMIT 20")
        print("  Últimos 20 registros comunicaciones_log:")
        for r in cur.fetchall():
            print(f"    {dict(r)}")
    else:
        print("  comunicaciones_log no tiene id_cuenta_smtp/tipo_evento o no existe")

    # ── 6. cuentas_smtp.enviados_hoy vs real ─────────────────────────────────
    print("\n6. CUENTAS SMTP (enviados_hoy vs comunicaciones_log)")
    cur.execute("SELECT id, email, enviados_hoy, limite_diario, activa FROM cuentas_smtp ORDER BY id")
    for r in cur.fetchall():
        # Contar real en comunicaciones_log
        try:
            real = cur.execute("""
                SELECT COUNT(*) FROM comunicaciones_log
                WHERE id_cuenta_smtp = ? AND tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')
            """, (r['id'],)).fetchone()[0]
        except Exception:
            real = 'N/A'
        print(f"  smtp_id={r['id']} {r['email']} | enviados_hoy={r['enviados_hoy']} | real_log={real} | limite={r['limite_diario']} | activa={r['activa']}")

    # ── 7. Envíos con smtp_id en tabla envios ────────────────────────────────
    print("\n7. ENVÍOS CON smtp_id EN TABLA envios")
    if 'smtp_id' in envios_cols:
        cur.execute("SELECT smtp_id, COUNT(*) as n FROM envios WHERE smtp_id IS NOT NULL GROUP BY smtp_id ORDER BY smtp_id")
        for r in cur.fetchall():
            print(f"  smtp_id={r['smtp_id']}: {r['n']} envíos")
        cur.execute("SELECT COUNT(*) FROM envios WHERE smtp_id IS NULL")
        print(f"  smtp_id NULL: {cur.fetchone()[0]} envíos")
    else:
        print("  No existe columna smtp_id en envios")

    # ── 8. Estados de leads en clubes_crm (distribución) ─────────────────────
    print("\n8. DISTRIBUCIÓN DE ESTADOS DE LEADS")
    cur.execute("SELECT estado_lead, COUNT(*) as n FROM clubes_crm GROUP BY estado_lead ORDER BY n DESC")
    for r in cur.fetchall():
        print(f"  {r['estado_lead']!r}: {r['n']}")

    db.close()
    try:
        os.remove(tmp)
    except Exception:
        pass

    print("\n" + "=" * 90)
    print("DIAGNÓSTICO COMPLETADO (read-only, sin envíos)")
    print("=" * 90)

if __name__ == "__main__":
    main()
