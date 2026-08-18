#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fase_cierre_forense_contadores.py

FASE FINAL — CIERRE DEFINITIVO (READ-ONLY)
RECONCILIACIÓN DE CONTADORES contra actividad real confirmada.

Reutiliza el mecanismo FTP existente. Descarga data/stats.db a temporal local
y ejecuta la reconciliación detallada de contadores de envios y tracking.

NO modifica NADA en producción.
"""
import ftplib, os, time, hashlib, sqlite3, tempfile, zlib

def load_env(path=".env"):
    env = {}
    if os.path.exists(path):
        for line in open(path, encoding="utf-8"):
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def asignar_variante(lead_id, campaign_id):
    h = zlib.crc32(f"{campaign_id}:{lead_id}".encode())
    if h < 0:
        h += 4294967296
    return ['A','B','C'][h % 3]

def main():
    env = load_env()
    HOST = env.get("FTP_HOST", "ftp.getfutprotec.com")
    USER = env.get("FTP_USER", "")
    PASS = env.get("FTP_PASS", "")
    REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_contadores_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada: {os.path.getsize(tmp)} bytes")
    print(f"MD5: {file_md5(tmp)}")
    print(f"SHA256: {sha256(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # ============ 1. COUNT total envios ============
    print("\n" + "="*70)
    print("1. CONTADORES GLOBALES ENVIOS")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios")
    print(f"TOTAL ENVIOS: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 0")
    print(f"REAL (es_test=0): {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 1")
    print(f"TEST (es_test=1): {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    print(f"es_test NULL: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2")
    print(f"campaign_id=2: {cur.fetchone()[0]}")

    # ============ 2. Listado completo campaign_id=2 ============
    print("\n" + "="*70)
    print("2. LISTADO COMPLETO ENVIOS campaign_id=2")
    print("="*70)
    cur.execute("""
        SELECT id, lead_id, fecha_envio, variant, es_test, estado, message_id, smtp_id
        FROM envios WHERE campaign_id = 2 ORDER BY id
    """)
    cols = [d[0] for d in cur.description]
    print(f"{'id':<4}{'lead_id':<8}{'fecha':<20}{'var':<5}{'test':<5}{'estado':<10}{'message_id':<40}{'smtp'}")
    for r in cur.fetchall():
        mid = (r[6] or '')[:38]
        print(f"{r[0]:<4}{r[1]:<8}{r[2]:<20}{r[3]:<5}{r[4]:<5}{r[5]:<10}{mid:<40}{r[7]}")

    # ============ 3. Agrupaciones campaign_id=2 ============
    print("\n" + "="*70)
    print("3. AGRUPACIONES campaign_id=2")
    print("="*70)
    cur.execute("SELECT es_test, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY es_test")
    print("Por REAL/TEST:")
    for r in cur.fetchall():
        print(f"  es_test={r[0]}: {r[1]}")
    cur.execute("SELECT variant, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY variant ORDER BY variant")
    print("Por variante:")
    for r in cur.fetchall():
        print(f"  {r[0]}: {r[1]}")
    cur.execute("SELECT smtp_id, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY smtp_id ORDER BY smtp_id")
    print("Por cuenta SMTP:")
    for r in cur.fetchall():
        print(f"  smtp_id={r[0]}: {r[1]}")
    cur.execute("SELECT substr(fecha_envio,1,10) as dia, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY dia ORDER BY dia")
    print("Por fecha (día):")
    for r in cur.fetchall():
        print(f"  {r[0]}: {r[1]}")

    # ============ 4. Verificación A/B/C ============
    print("\n" + "="*70)
    print("4. VERIFICACIÓN A/B/C (asignarVariante vs envios.variant)")
    print("="*70)
    cur.execute("SELECT id, lead_id, variant FROM envios WHERE campaign_id = 2 ORDER BY id")
    disc = 0
    for eid, lid, var in cur.fetchall():
        esperada = asignar_variante(lid, 2)
        if var != esperada:
            disc += 1
            print(f"  DISCREPANCIA envio={eid} lead={lid} almacenada={var} esperada={esperada}")
    print(f"Discrepancias A/B/C: {disc}")

    # ============ 5. Tracking ============
    print("\n" + "="*70)
    print("5. TRACKING")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM aperturas")
    print(f"Aperturas totales: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(DISTINCT tracking_id) FROM aperturas")
    print(f"Aperturas únicas (destinatarios): {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM (
            SELECT tracking_id FROM aperturas GROUP BY tracking_id HAVING COUNT(*) > 1
        )
    """)
    print(f"Con segunda apertura (>1): {cur.fetchone()[0]}")
    # Aperturas relacionadas con envios REAL de campaña 2
    cur.execute("""
        SELECT COUNT(DISTINCT a.tracking_id)
        FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2 AND e.es_test = 0
    """)
    print(f"Aperturas únicas de envios REAL campaña 2: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(a.id)
        FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2 AND e.es_test = 0
    """)
    print(f"Eventos apertura de envios REAL campaña 2: {cur.fetchone()[0]}")
    # Aperturas por envio real
    print("\nAperturas por envio REAL campaña 2:")
    cur.execute("""
        SELECT e.id, e.lead_id, e.variant, e.email, COUNT(a.id) as n_ap
        FROM envios e LEFT JOIN aperturas a ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2 AND e.es_test = 0
        GROUP BY e.id ORDER BY e.id
    """)
    for r in cur.fetchall():
        print(f"  envio={r[0]} lead={r[1]} var={r[2]} aperturas={r[4]}")

    # ============ 6. Rebotes / Bajas / Respuestas ============
    print("\n" + "="*70)
    print("6. REBOTES / BAJAS / RESPUESTAS")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM rebotes")
    print(f"Rebotes registrados: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM respuestas")
    print(f"Respuestas registradas: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT estado_lead, COUNT(*) FROM clubes_crm
        WHERE estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
        GROUP BY estado_lead
    """)
    print("Leads en estado supresión:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 7. SMTP enviados_hoy vs envios ============
    print("\n" + "="*70)
    print("7. SMTP — enviados_hoy vs envios registrados")
    print("="*70)
    cur.execute("SELECT id, email, enviados_hoy, limite_diario FROM cuentas_smtp ORDER BY id")
    for r in cur.fetchall():
        print(f"  id={r[0]} {r[1]} enviados_hoy={r[2]} limite={r[3]}")

    db.close()
    os.remove(tmp)
    print("\n=== RECONCILIACIÓN DE CONTADORES FINALIZADA (READ-ONLY) ===")

if __name__ == "__main__":
    main()
