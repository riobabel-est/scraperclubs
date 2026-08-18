#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fase_cierre_forense_auditoria.py

FASE FINAL — CIERRE FORENSE (READ-ONLY)
Auditoría integral de producción sobre la BD ACTUAL.

Reutiliza el mecanismo FTP existente. Descarga data/stats.db a temporal local
y ejecuta TODAS las consultas de auditoría de los pasos 3-14.

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
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_cierre_audit_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada: {os.path.getsize(tmp)} bytes")
    print(f"MD5: {file_md5(tmp)}")
    print(f"SHA256: {sha256(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # ============ INTEGRIDAD ============
    cur.execute("PRAGMA integrity_check")
    print("\n=== INTEGRITY CHECK ===")
    print("  ", cur.fetchone()[0])
    cur.execute("PRAGMA foreign_key_check")
    fk = cur.fetchall()
    print(f"  FK violadas: {len(fk)}")

    # ============ 3. LEADS ============
    print("\n" + "="*70)
    print("3. LEADS")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM clubes_crm")
    print(f"TOTAL LEADS: {cur.fetchone()[0]}")
    # TEST detection
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%'
    """)
    print(f"TEST: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
    """)
    print(f"REAL: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM clubes_crm WHERE email IS NULL OR email = ''")
    print(f"Sin email: {cur.fetchone()[0]}")
    # emails inválidos (sin @)
    cur.execute("SELECT COUNT(*) FROM clubes_crm WHERE email IS NOT NULL AND email != '' AND email NOT LIKE '%@%'")
    print(f"Emails inválidos (sin @): {cur.fetchone()[0]}")
    # duplicados
    cur.execute("""
        SELECT COUNT(*) FROM (
            SELECT email FROM clubes_crm
            WHERE email IS NOT NULL AND email != ''
            GROUP BY LOWER(email) HAVING COUNT(*) > 1
        )
    """)
    print(f"Emails duplicados (grupos): {cur.fetchone()[0]}")
    # estados
    print("\nEstados de lead (Kanban):")
    cur.execute("SELECT estado_lead, COUNT(*) FROM clubes_crm GROUP BY estado_lead ORDER BY estado_lead")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 4. PIPELINES ============
    print("\n" + "="*70)
    print("4. PIPELINES")
    print("="*70)
    cur.execute("SELECT * FROM pipelines ORDER BY id")
    cols = [d[0] for d in cur.description]
    for r in cur.fetchall():
        print("  ", dict(zip(cols, r)))

    # ============ 5. ENVÍOS (fuente de verdad) ============
    print("\n" + "="*70)
    print("5. ENVÍOS — FUENTE DE VERDAD")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios")
    print(f"TOTAL ENVÍOS: {cur.fetchone()[0]}")
    cur.execute("SELECT es_test, COUNT(*) FROM envios GROUP BY es_test")
    print("Por es_test:")
    for r in cur.fetchall():
        print(f"  es_test={r[0]}: {r[1]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    print(f"es_test NULL: {cur.fetchone()[0]}")
    print("\nPor campaign_id:")
    cur.execute("SELECT campaign_id, COUNT(*) FROM envios GROUP BY campaign_id ORDER BY campaign_id")
    for r in cur.fetchall():
        print("  ", r)
    print("\nPor estado:")
    cur.execute("SELECT estado, COUNT(*) FROM envios GROUP BY estado ORDER BY estado")
    for r in cur.fetchall():
        print("  ", r)
    print("\nPor variante:")
    cur.execute("SELECT variant, COUNT(*) FROM envios GROUP BY variant ORDER BY variant")
    for r in cur.fetchall():
        print("  ", r)
    print("\nPor smtp_id:")
    cur.execute("SELECT smtp_id, COUNT(*) FROM envios GROUP BY smtp_id ORDER BY smtp_id")
    for r in cur.fetchall():
        print("  ", r)
    cur.execute("SELECT COUNT(*) FROM envios WHERE message_id IS NULL OR message_id = ''")
    print(f"Sin message_id: {cur.fetchone()[0]}")

    # ============ 6. CAMPAÑA 2 ============
    print("\n" + "="*70)
    print("6. CAMPAÑA 2")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2")
    print(f"TOTAL ENVÍOS campaign_id=2: {cur.fetchone()[0]}")
    cur.execute("SELECT es_test, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY es_test")
    print("Por es_test:")
    for r in cur.fetchall():
        print(f"  es_test={r[0]}: {r[1]}")
    cur.execute("SELECT estado, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY estado")
    print("Por estado:")
    for r in cur.fetchall():
        print("  ", r)
    cur.execute("SELECT variant, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY variant ORDER BY variant")
    print("Por variante:")
    for r in cur.fetchall():
        print("  ", r)
    cur.execute("SELECT COUNT(DISTINCT lead_id) FROM envios WHERE campaign_id = 2 AND lead_id IS NOT NULL")
    print(f"Leads distintos afectados: {cur.fetchone()[0]}")
    cur.execute("SELECT MIN(fecha_envio), MAX(fecha_envio) FROM envios WHERE campaign_id = 2")
    print(f"Rango fechas: {cur.fetchone()}")
    cur.execute("SELECT smtp_id, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY smtp_id ORDER BY smtp_id")
    print("Por smtp_id:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 7. A/B/C ============
    print("\n" + "="*70)
    print("7. A/B/C — asignarVariante() vs envios.variant")
    print("="*70)
    cur.execute("SELECT id, lead_id, variant FROM envios WHERE campaign_id = 2 ORDER BY id")
    disc = 0
    for eid, lid, var in cur.fetchall():
        esperada = asignar_variante(lid, 2)
        if var != esperada:
            disc += 1
            print(f"  DISCREPANCIA envio={eid} lead={lid} almacenada={var} esperada={esperada}")
    print(f"Discrepancias A/B/C: {disc}")

    # ============ 8. TEST/REAL ============
    print("\n" + "="*70)
    print("8. TEST/REAL aislamiento")
    print("="*70)
    # campaña TEST -> lead REAL
    cur.execute("""
        SELECT COUNT(*) FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
        WHERE e.campaign_id = 2 AND e.es_test = 1
          AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
    """)
    print(f"Envíos campaña 2 es_test=1 con lead REAL: {cur.fetchone()[0]}")
    # campaña REAL -> lead TEST
    cur.execute("""
        SELECT COUNT(*) FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
        WHERE e.campaign_id = 2 AND e.es_test = 0
          AND (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
    """)
    print(f"Envíos campaña 2 es_test=0 con lead TEST: {cur.fetchone()[0]}")

    # ============ 9. MOTOR ============
    print("\n" + "="*70)
    print("9. MOTOR DE ENVÍO")
    print("="*70)
    cur.execute("SELECT * FROM config")
    cols = [d[0] for d in cur.description]
    for r in cur.fetchall():
        print("  ", dict(zip(cols, r)))

    # ============ 10. TRACKING ============
    print("\n" + "="*70)
    print("10. TRACKING")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM aperturas")
    print(f"Total eventos apertura: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(DISTINCT tracking_id) FROM aperturas")
    print(f"Destinatarios únicos abiertos: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM (
            SELECT tracking_id FROM aperturas GROUP BY tracking_id HAVING COUNT(*) > 1
        )
    """)
    print(f"Con segunda apertura (>1): {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM rebotes")
    print(f"Rebotes registrados: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM respuestas")
    print(f"Respuestas registradas: {cur.fetchone()[0]}")
    # bajas / suppression
    cur.execute("""
        SELECT estado_lead, COUNT(*) FROM clubes_crm
        WHERE estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
        GROUP BY estado_lead
    """)
    print("Leads en estado supresión:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 11. SMTP ============
    print("\n" + "="*70)
    print("11. SMTP")
    print("="*70)
    cur.execute("PRAGMA table_info(cuentas_smtp)")
    smtp_cols = [r[1] for r in cur.fetchall()]
    print(f"  Columnas cuentas_smtp: {smtp_cols}")
    # Seleccionar columnas disponibles
    sel = [c for c in ['id','email','enviados_hoy','limite_diario','activo','estado','nombre'] if c in smtp_cols]
    cur.execute(f"SELECT {', '.join(sel)} FROM cuentas_smtp ORDER BY id")
    cols = [d[0] for d in cur.description]
    for r in cur.fetchall():
        print("  ", dict(zip(cols, r)))

    cur.execute("SELECT COUNT(*) FROM comunicaciones_log")
    print(f"Total comunicaciones_log: {cur.fetchone()[0]}")
    cur.execute("SELECT id_cuenta_smtp, tipo_evento, COUNT(*) FROM comunicaciones_log GROUP BY id_cuenta_smtp, tipo_evento ORDER BY id_cuenta_smtp")
    print("comunicaciones_log por cuenta/tipo:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 13. ELEGIBILIDAD ============
    print("\n" + "="*70)
    print("13. ELEGIBILIDAD — universo campaña 2")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM clubes_crm")
    total = cur.fetchone()[0]
    print(f"TOTAL LEADS: {total}")
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%'
    """)
    test = cur.fetchone()[0]
    print(f"TEST: {test}")
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
    """)
    real = cur.fetchone()[0]
    print(f"REAL: {real}")
    cur.execute("""
        SELECT COUNT(*) FROM (
            SELECT LOWER(email) FROM clubes_crm
            WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
              AND email IS NOT NULL AND email != ''
            GROUP BY LOWER(email) HAVING COUNT(*) > 1
        )
    """)
    print(f"Duplicados REAL (grupos): {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
          AND estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
    """)
    print(f"Suppression REAL: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(DISTINCT lead_id) FROM envios WHERE campaign_id = 2 AND lead_id IS NOT NULL")
    enviados = cur.fetchone()[0]
    print(f"Ya enviados campaña 2 (distintos): {enviados}")
    # elegibles base
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
          AND estado_lead NOT IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
          AND COALESCE(es_duplicado,0) = 0
          AND email IS NOT NULL AND email != ''
    """)
    elegibles_base = cur.fetchone()[0]
    print(f"Elegibles base (sin supresión/dup/email vacío): {elegibles_base}")
    print(f"Elegibles pendientes (aprox): {elegibles_base - enviados}")

    # ============ 14. lead_pipelines variante_ab ============
    print("\n" + "="*70)
    print("14. lead_pipelines.variante_ab (histórico)")
    print("="*70)
    cur.execute("PRAGMA table_info(lead_pipelines)")
    lp_cols = [r[1] for r in cur.fetchall()]
    print(f"  Columnas: {lp_cols}")
    cur.execute("SELECT COUNT(*) FROM lead_pipelines")
    print(f"  Total lead_pipelines: {cur.fetchone()[0]}")
    if 'variante_ab' in lp_cols:
        cur.execute("SELECT variante_ab, COUNT(*) FROM lead_pipelines GROUP BY variante_ab")
        print("  Por variante_ab:")
        for r in cur.fetchall():
            print("   ", r)

    db.close()
    os.remove(tmp)
    print("\n=== AUDITORÍA FORENSE FINALIZADA (READ-ONLY) ===")

if __name__ == "__main__":
    main()
