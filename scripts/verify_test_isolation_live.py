#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FASE 7 — VERIFICACIÓN FUNCIONAL LIVE (SOLO LECTURA)
Descarga stats.db LIVE por FTP y ejecuta consultas READ-ONLY para verificar
el aislamiento TEST/REAL tras el deploy.

Verifica:
  - BD: TOTAL=32, TEST=20, REAL=12, NULL=0
  - Histórico Comercial (sqlFiltroComercial): 12 REAL, sin emails TEST
  - Histórico de Pruebas (es_test=1): 20 TEST
  - Analytics comerciales excluyen TEST (envios, aperturas, rebotes, bajas)

NO modifica la BD. NO envía emails. NO ejecuta cron.
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

fails = 0
def check(label, got, expected):
    global fails
    ok = (got == expected)
    if not ok:
        fails += 1
    print(f"[{'PASS' if ok else 'FAIL'}] {label}: got={got} expected={expected}")

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_verify_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada stats.db LIVE: {os.path.getsize(tmp)} bytes")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── PASO 5: BD base ──
    print("\n=== PASO 5: BD LIVE ===")
    cur.execute("SELECT COUNT(*) FROM envios")
    total = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=1")
    test = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0")
    real = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    null = cur.fetchone()[0]
    check("envios TOTAL", total, 32)
    check("envios TEST (es_test=1)", test, 20)
    check("envios REAL (es_test=0)", real, 12)
    check("envios NULL", null, 0)

    # integrity_check
    cur.execute("PRAGMA integrity_check")
    ic = cur.fetchone()[0]
    check("integrity_check", ic, "ok")

    # ── Histórico Comercial (sqlFiltroComercial = COALESCE(es_test,0)=0) ──
    print("\n=== Histórico Comercial (COALESCE(es_test,0)=0) ===")
    cur.execute("SELECT id, club, email, es_test FROM envios WHERE 1=1 AND COALESCE(es_test,0)=0 ORDER BY id DESC LIMIT 50")
    rows = cur.fetchall()
    check("Histórico Comercial count", len(rows), 12)
    # Ningún email TEST
    test_emails = [r['email'] for r in rows if '@futprotec.local' in (r['email'] or '') or 'TEST_' in (r['email'] or '').upper() or 'riobabel' in (r['email'] or '').lower() or 'aaaa' in (r['club'] or '').lower()]
    check("Histórico Comercial sin emails TEST", len(test_emails), 0)
    # Todos es_test=0
    bad_es = [r['id'] for r in rows if r['es_test'] != 0]
    check("Histórico Comercial todos es_test=0", len(bad_es), 0)
    print("  Emails comerciales:", [r['email'] for r in rows])

    # ── PASO 9: SQL EXACTO de los endpoints (dashboard.php) ──
    print("\n=== PASO 9: SQL EXACTO de los endpoints ===")
    # get_last_envios (dashboard.php L727): sqlFiltroComercial('e')
    cur.execute("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado FROM envios e WHERE 1=1 AND COALESCE(e.es_test,0)=0 ORDER BY e.id DESC LIMIT 10")
    last_envios = cur.fetchall()
    check("get_last_envios count (SQL exacto)", len(last_envios), 10)
    le_test = [r['email'] for r in last_envios if '@futprotec.local' in (r['email'] or '') or 'TEST_' in (r['email'] or '').upper() or 'riobabel' in (r['email'] or '').lower()]
    check("get_last_envios sin emails TEST (SQL exacto)", len(le_test), 0)
    # get_analytics tab=envios (dashboard.php L898): sqlFiltroComercial('e')
    cur.execute("SELECT e.id, e.club, e.email, e.cuenta_emision, e.fecha_envio, e.estado, e.asunto, e.cuerpo_mensaje FROM envios e WHERE 1=1 AND COALESCE(e.es_test,0)=0 ORDER BY e.id DESC LIMIT 50")
    anal_envios = cur.fetchall()
    check("get_analytics tab=envios count (SQL exacto)", len(anal_envios), 12)
    ae_test = [r['email'] for r in anal_envios if '@futprotec.local' in (r['email'] or '') or 'TEST_' in (r['email'] or '').upper() or 'riobabel' in (r['email'] or '').lower()]
    check("get_analytics tab=envios sin emails TEST (SQL exacto)", len(ae_test), 0)
    # get_test_history (dashboard.php L1134): COALESCE(es_test,0)=1
    cur.execute("SELECT id, club, email, fecha_envio, estado, campaign_id, plantilla_id, tracking_id FROM envios WHERE COALESCE(es_test,0)=1 ORDER BY id DESC LIMIT 200")
    test_hist = cur.fetchall()
    check("get_test_history count (SQL exacto)", len(test_hist), 20)
    # El WHERE COALESCE(es_test,0)=1 garantiza que todos son TEST.
    # Heurística de email/club TEST (incluye club 'aaaa' de info@fsnazareno.es).
    th_test = [r['email'] for r in test_hist if '@futprotec.local' not in (r['email'] or '') and 'TEST_' not in (r['email'] or '').upper() and 'riobabel' not in (r['email'] or '').lower() and 'aaaa' not in (r['club'] or '').lower()]
    check("get_test_history todos TEST (SQL exacto)", len(th_test), 0)

    # ── Histórico de Pruebas (es_test=1) ──
    print("\n=== Histórico de Pruebas (es_test=1) ===")
    cur.execute("SELECT id, club, email, es_test FROM envios WHERE COALESCE(es_test,0)=1 ORDER BY id DESC LIMIT 200")
    rows_test = cur.fetchall()
    check("Histórico de Pruebas count", len(rows_test), 20)
    bad_es_test = [r['id'] for r in rows_test if r['es_test'] != 1]
    check("Histórico de Pruebas todos es_test=1", len(bad_es_test), 0)

    # ── PASO 6: Analytics comerciales excluyen TEST ──
    print("\n=== PASO 6: Analytics comerciales (excluyen TEST) ===")
    # Envíos (KPI estado=enviado, comercial)
    cur.execute("SELECT COUNT(*) FROM envios e WHERE e.estado='enviado' AND COALESCE(e.es_test,0)=0")
    kpi_envios = cur.fetchone()[0]
    check("KPI Envíos Realizados (comercial)", kpi_envios, 9)
    # Envíos totales comerciales (tabla)
    cur.execute("SELECT COUNT(*) FROM envios e WHERE 1=1 AND COALESCE(e.es_test,0)=0")
    tabla_envios = cur.fetchone()[0]
    check("Tabla Histórico Comercial (comercial)", tabla_envios, 12)
    # Aperturas comerciales
    cur.execute("SELECT COUNT(DISTINCT a.tracking_id) FROM aperturas a JOIN envios e ON a.tracking_id=e.tracking_id WHERE 1=1 AND COALESCE(e.es_test,0)=0")
    aperturas = cur.fetchone()[0]
    check("Aperturas comerciales", aperturas, 3)
    # Rebotes comerciales (join por email)
    cur.execute("SELECT COUNT(*) FROM rebotes r JOIN envios e ON LOWER(r.email)=LOWER(e.email) WHERE 1=1 AND COALESCE(e.es_test,0)=0")
    rebotes = cur.fetchone()[0]
    check("Rebotes comerciales", rebotes, 0)
    # Bajas comerciales
    try:
        cur.execute("SELECT COUNT(*) FROM bajas b JOIN envios e ON LOWER(b.email)=LOWER(e.email) WHERE COALESCE(e.es_test,0)=0")
        bajas = cur.fetchone()[0]
        check("Bajas comerciales", bajas, 0)
    except Exception as e:
        print(f"  [INFO] bajas: {e}")

    db.close()
    os.remove(tmp)

    print("\n=== RESUMEN ===")
    if fails == 0:
        print("TODOS LOS CHECKS PASARON")
        print("DEPLOY_TEST_ISOLATION_LIVE = PASS")
    else:
        print(f"FALLOS: {fails}")
        print("DEPLOY_TEST_ISOLATION_LIVE = FAIL")
    sys.exit(0 if fails == 0 else 1)

if __name__ == "__main__":
    main()
