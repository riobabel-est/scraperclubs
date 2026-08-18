#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FASE C.3 — Auditoría completa de reconciliación post-envío (READ-ONLY).
Descarga la BD de producción remota y ejecuta TODAS las consultas de
reconciliación de la fase C.3. NO modifica nada.
"""
import ftplib, os, time, hashlib, sqlite3, tempfile, json

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

def main():
    env = load_env()
    HOST = env.get("FTP_HOST", "ftp.getfutprotec.com")
    USER = env.get("FTP_USER", "")
    PASS = env.get("FTP_PASS", "")
    REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseC3_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada: {os.path.getsize(tmp)} bytes")
    print(f"MD5: {file_md5(tmp)}")
    print(f"SHA256: {sha256(tmp)}")
    print(f"PATH: {tmp}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # integrity_check
    cur.execute("PRAGMA integrity_check")
    print("\n=== INTEGRITY CHECK ===")
    print("  ", cur.fetchone()[0])

    # ============ 1. BASELINE: ENVIOS CAMPAÑA 2 ============
    print("\n" + "="*70)
    print("1. BASELINE — ENVIOS CAMPAÑA 2")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2")
    total_c2 = cur.fetchone()[0]
    print(f"TOTAL ENVIOS campaign_id=2: {total_c2}")

    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2 AND es_test = 0")
    print(f"  es_test=0: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2 AND es_test = 1")
    print(f"  es_test=1: {cur.fetchone()[0]}")

    # ============ 2. RECONCILIACIÓN DE LOS 31 ENVÍOS ============
    print("\n" + "="*70)
    print("2. RECONCILIACIÓN DE ENVÍOS CAMPAÑA 2")
    print("="*70)
    cur.execute("""
        SELECT e.id, e.lead_id, e.email, e.club, e.federacion, e.fecha_envio,
               e.estado, e.es_test, e.variant, e.plantilla_id, e.smtp_id,
               e.message_id, e.resultado_envio, e.tracking_id, e.campaign_id
        FROM envios e WHERE e.campaign_id = 2 ORDER BY e.id
    """)
    rows = cur.fetchall()
    cols = [d[0] for d in cur.description]
    print(f"Filas: {len(rows)}")
    for r in rows:
        print("  ", dict(zip(cols, r)))

    # ============ 3. RECONCILIACIÓN A/B/C ============
    print("\n" + "="*70)
    print("3. RECONCILIACIÓN A/B/C")
    print("="*70)
    # Recalcular asignarVariante en Python (espejo de abc.php)
    def asignar_variante(lead_id, campaign_id):
        import zlib
        h = zlib.crc32(f"{campaign_id}:{lead_id}".encode())
        if h < 0:
            h += 4294967296
        return ['A','B','C'][h % 3]

    cur.execute("SELECT id, lead_id, variant FROM envios WHERE campaign_id = 2 ORDER BY id")
    disc = 0
    for eid, lid, var in cur.fetchall():
        esperada = asignar_variante(lid, 2)
        if var != esperada:
            disc += 1
            print(f"  DISCREPANCIA envio={eid} lead={lid} almacenada={var} esperada={esperada}")
    print(f"Discrepancias A/B/C: {disc}")

    # Distribución
    cur.execute("SELECT variant, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY variant ORDER BY variant")
    print("Distribución variantes:")
    for v, c in cur.fetchall():
        print(f"  {v}: {c}")

    # ============ 4. TRACKING DE APERTURAS ============
    print("\n" + "="*70)
    print("4. TRACKING DE APERTURAS CAMPAÑA 2")
    print("="*70)
    cur.execute("""
        SELECT a.id, a.tracking_id, a.fecha_apertura, a.ip, a.user_agent,
               e.id as envio_id, e.lead_id, e.message_id, e.variant, e.email
        FROM aperturas a
        JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2
        ORDER BY a.fecha_apertura
    """)
    ap_rows = cur.fetchall()
    ap_cols = [d[0] for d in cur.description]
    print(f"EVENTOS TOTALES DE APERTURA: {len(ap_rows)}")
    for r in ap_rows:
        print("  ", dict(zip(ap_cols, r)))

    # Destinatarios únicos
    cur.execute("""
        SELECT COUNT(DISTINCT a.tracking_id)
        FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2
    """)
    print(f"DESTINATARIOS ÚNICOS ABIERTOS: {cur.fetchone()[0]}")

    # Aperturas por destinatario
    print("\nAperturas por destinatario:")
    cur.execute("""
        SELECT e.email, e.lead_id, e.variant, COUNT(a.id) as n_ap,
               MIN(a.fecha_apertura) as primera, MAX(a.fecha_apertura) as ultima
        FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2
        GROUP BY a.tracking_id ORDER BY n_ap DESC
    """)
    for r in cur.fetchall():
        print("  ", r)

    # ============ 5. DUPLICADOS DE TRACKING ============
    print("\n" + "="*70)
    print("5. DUPLICADOS DE TRACKING (mismo tracking_id con >1 apertura)")
    print("="*70)
    cur.execute("""
        SELECT a.tracking_id, e.email, e.lead_id, COUNT(a.id) as n
        FROM aperturas a JOIN envios e ON a.tracking_id = e.tracking_id
        WHERE e.campaign_id = 2
        GROUP BY a.tracking_id HAVING n > 1
    """)
    dups = cur.fetchall()
    if dups:
        for r in dups:
            print("  ", r)
    else:
        print("  Sin duplicados (ningún tracking con >1 apertura)")

    # ============ 7. REBOTES ============
    print("\n" + "="*70)
    print("7. REBOTES")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM rebotes")
    print(f"Total rebotes tabla: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(*) FROM rebotes WHERE email IN (SELECT email FROM envios WHERE campaign_id = 2)")
    print(f"Rebotes de campaña 2: {cur.fetchone()[0]}")

    # ============ 8. BAJAS ============
    print("\n" + "="*70)
    print("8. BAJAS / SUPPRESSION")
    print("="*70)
    # Estados de supresión en clubes_crm
    cur.execute("""
        SELECT estado_lead, COUNT(*) FROM clubes_crm
        WHERE estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
        GROUP BY estado_lead
    """)
    print("Leads en estado supresión:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 9. MESSAGE_ID ============
    print("\n" + "="*70)
    print("9. MESSAGE_ID")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2 AND (message_id IS NULL OR message_id = '')")
    print(f"Envíos campaña 2 sin message_id: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT message_id, COUNT(*) FROM envios WHERE campaign_id = 2
        GROUP BY message_id HAVING COUNT(*) > 1
    """)
    dup_mid = cur.fetchall()
    print(f"Message_id duplicados: {len(dup_mid)}")
    for r in dup_mid:
        print("  ", r)

    # ============ 10. RESPUESTAS ============
    print("\n" + "="*70)
    print("10. RESPUESTAS")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM respuestas")
    print(f"Total respuestas tabla: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM respuestas r JOIN envios e ON e.id = r.envio_id
        WHERE e.campaign_id = 2
    """)
    print(f"Respuestas campaña 2: {cur.fetchone()[0]}")

    # ============ 11. SMTP ============
    print("\n" + "="*70)
    print("11. SMTP — comunicaciones_log")
    print("="*70)
    cur.execute("""
        SELECT id_cuenta_smtp, COUNT(*) as n, tipo_evento
        FROM comunicaciones_log
        WHERE tipo_evento LIKE '%envio%' OR tipo_evento LIKE '%send%' OR tipo_evento LIKE '%email%'
        GROUP BY id_cuenta_smtp, tipo_evento
    """)
    print("comunicaciones_log por cuenta:")
    for r in cur.fetchall():
        print("  ", r)

    # Envíos por smtp_id
    cur.execute("SELECT smtp_id, COUNT(*) FROM envios WHERE campaign_id = 2 GROUP BY smtp_id ORDER BY smtp_id")
    print("\nEnvios campaña 2 por smtp_id:")
    for r in cur.fetchall():
        print("  ", r)

    # cuentas_smtp enviados_hoy
    cur.execute("SELECT id, email, enviados_hoy, limite_diario FROM cuentas_smtp ORDER BY id")
    print("\ncuentas_smtp.enviados_hoy:")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 13. ELEGIBILIDAD ============
    print("\n" + "="*70)
    print("13. ELEGIBILIDAD — universo campaña 2")
    print("="*70)
    # Total leads reales (no TEST)
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
    """)
    total_real = cur.fetchone()[0]
    print(f"TOTAL LEADS REALES: {total_real}")

    # Excluir supresión
    cur.execute("""
        SELECT COUNT(*) FROM clubes_crm
        WHERE NOT (LOWER(email) LIKE '%@futprotec.local%' OR LOWER(nombre_club) LIKE 'test%')
          AND estado_lead NOT IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
          AND COALESCE(es_duplicado,0) = 0
          AND email IS NOT NULL AND email != ''
    """)
    elegibles_base = cur.fetchone()[0]
    print(f"ELEGIBLES (sin supresión/dup/email vacío): {elegibles_base}")

    # Ya enviados en campaña 2
    cur.execute("SELECT COUNT(DISTINCT lead_id) FROM envios WHERE campaign_id = 2 AND lead_id IS NOT NULL")
    enviados_c2 = cur.fetchone()[0]
    print(f"Leads distintos ya enviados en campaña 2: {enviados_c2}")

    # ============ 14. LEADS YA CONTACTADOS ============
    print("\n" + "="*70)
    print("14. LEADS YA CONTACTADOS (estado actual)")
    print("="*70)
    cur.execute("""
        SELECT e.lead_id, c.nombre_club, c.email, c.estado_lead, e.estado as envio_estado,
               e.resultado_envio, e.fecha_envio
        FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
        WHERE e.campaign_id = 2 ORDER BY e.lead_id
    """)
    for r in cur.fetchall():
        print("  ", r)

    # ============ 15. KANBAN / PIPELINES ============
    print("\n" + "="*70)
    print("15. KANBAN / PIPELINES")
    print("="*70)
    cur.execute("SELECT id, nombre, identificador, estado, entorno, activo, tipo FROM pipelines ORDER BY id")
    print("Pipelines:")
    for r in cur.fetchall():
        print("  ", r)
    cur.execute("SELECT DISTINCT estado_lead FROM clubes_crm ORDER BY estado_lead")
    print("\nEstados de lead (Kanban):")
    for r in cur.fetchall():
        print("  ", r[0])

    # ============ 16. TEST/REAL ============
    print("\n" + "="*70)
    print("16. TEST/REAL")
    print("="*70)
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = 2 AND es_test = 1")
    print(f"Envíos campaña 2 con es_test=1: {cur.fetchone()[0]}")
    cur.execute("""
        SELECT COUNT(*) FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
        WHERE e.campaign_id = 2 AND (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
    """)
    print(f"Envíos campaña 2 con lead TEST: {cur.fetchone()[0]}")

    # ============ 18. PLANTILLAS ============
    print("\n" + "="*70)
    print("18. PLANTILLAS")
    print("="*70)
    cur.execute("SELECT id, nombre, asunto, test_ab, activo, categoria FROM plantillas ORDER BY id")
    for r in cur.fetchall():
        print("  ", r)

    # ============ 20. TABLAS LEGACY ============
    print("\n" + "="*70)
    print("20. TABLAS LEGACY (conteos)")
    print("="*70)
    for t in ['mockups','presupuestos','snapshots','plantillas_new','destinatarios_test']:
        try:
            cur.execute(f"SELECT COUNT(*) FROM {t}")
            print(f"  {t}: {cur.fetchone()[0]}")
        except Exception as e:
            print(f"  {t}: ERR {e}")

    # ============ 21. POSTCHECK variante_ab ============
    print("\n" + "="*70)
    print("21. lead_pipelines.variante_ab")
    print("="*70)
    cur.execute("PRAGMA table_info(lead_pipelines)")
    lp_cols = [r[1] for r in cur.fetchall()]
    print(f"  Columnas lead_pipelines: {lp_cols}")
    cur.execute("SELECT COUNT(*) FROM lead_pipelines")
    print(f"  Total lead_pipelines: {cur.fetchone()[0]}")

    db.close()
    print("\n=== AUDITORÍA COMPLETA FINALIZADA ===")

if __name__ == "__main__":
    main()
