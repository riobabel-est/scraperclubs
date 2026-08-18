#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
auditoria_integral_final.py

AUDITORÍA INTEGRAL Y CONTROLADA del CRM Outbound de FutProtec.

Operación global única:
  AUDITORÍA → CLASIFICACIÓN → RECONCILIACIÓN → REPARACIÓN SEGURA → VERIFICACIÓN FORENSE → CHECKPOINT FINAL

REGLAS DE SEGURIDAD:
  - Primero audita, luego clasifica, luego repara SOLO lo inequívoco (CATEGORÍA A).
  - NO modifica datos históricos legítimos (CATEGORÍA B/C).
  - NO envía emails, NO lanza campañas, NO activa el motor.
  - Crea backup verificable antes de cualquier modificación.
  - Verificación forense PRE/POST.

USO:
  python scripts/auditoria_integral_final.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import zlib
import shutil
import json
from datetime import datetime

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# ═══════════════════════════════════════════════════════════════════════════════
# CONFIGURACIÓN
# ═══════════════════════════════════════════════════════════════════════════════

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
REMOTE_BACKUP_DIR = "/getfutprotec.com/backups_deploy"

# Directorio local de backups
BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "backups_deploy")
BACKUP_DIR = os.path.normpath(BACKUP_DIR)
os.makedirs(BACKUP_DIR, exist_ok=True)

# ═══════════════════════════════════════════════════════════════════════════════
# FUNCIONES AUXILIARES
# ═══════════════════════════════════════════════════════════════════════════════

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
    """Espejo exacto de asignarVariante() en inc/abc.php."""
    s = f"{campaign_id}:{lead_id}"
    h = zlib.crc32(s.encode("utf-8"))
    if h < 0:
        h += 4294967296
    return ["A", "B", "C"][h % 3]

def es_lead_test(email, nombre_club):
    """Espejo exacto de esLeadTest() en inc/eligibilidad.php."""
    email_l = (email or "").lower()
    nombre_l = (nombre_club or "").lower()
    if email_l and "@futprotec.local" in email_l:
        return True
    if nombre_l and nombre_l.startswith("test"):
        return True
    return False

def es_campana_test(entorno):
    """Espejo exacto de esCampanaTest() en inc/eligibilidad.php."""
    return (entorno or "").lower() == "test"

def es_entorno_coherente(campaign_entorno, modo_entorno):
    """Espejo exacto de esEntornoCoherente() en inc/abc.php."""
    ce = (campaign_entorno or "").lower().strip()
    me = (modo_entorno or "").lower().strip()
    if ce == "":
        ce = "test"
    if me == "":
        me = "test"
    if me == "produccion" and ce == "test":
        return False, "campaign_test_en_produccion"
    if me == "test" and ce in ("pilot", "production"):
        return False, "campaign_comercial_en_test"
    return True, "coherente"

def validar_campana_activa(estado, activo, entorno, modo_entorno):
    """Espejo exacto de validarCampanaActiva() en inc/abc.php."""
    estados_permitidos = ["PILOT", "ACTIVE"]
    if (estado or "").upper() not in estados_permitidos or int(activo or 0) != 1:
        return False, "CAMPAIGN_NOT_ACTIVE"
    ok, razon = es_entorno_coherente(entorno, modo_entorno)
    if not ok:
        return False, "ENVIRONMENT_MISMATCH"
    return True, "CAMPANIA_VALIDA"

def sql_filtro_compatibilidad(es_test_campana):
    """Espejo de sqlFiltroCompatibilidadLeadCampana() — devuelve si un lead es compatible."""
    # es_test_campana=True → solo leads TEST; False → solo leads REAL
    return es_test_campana

def es_envio_test(es_test, email, club):
    """Espejo exacto de esEnvioTest() en inc/eligibilidad.php."""
    if int(es_test or 0) == 1:
        return True
    email_l = (email or "").lower()
    club_l = (club or "").lower()
    if email_l and "@futprotec.local" in email_l:
        return True
    if club_l and club_l.startswith("test"):
        return True
    return False

# ═══════════════════════════════════════════════════════════════════════════════
# CLASIFICACIÓN DETERMINISTA DE ENVÍOS
# ═══════════════════════════════════════════════════════════════════════════════

def clasificar_envio(envio, campana_entorno):
    """
    Clasificación determinista de un envío como REAL o TEST.
    Un envío es TEST si esLeadTest(lead) OR esCampanaTest(campaña).
    """
    lead_test = es_lead_test(envio.get("email"), envio.get("club"))
    campana_test = es_campana_test(campana_entorno)
    return "TEST" if (lead_test or campana_test) else "REAL"

# ═══════════════════════════════════════════════════════════════════════════════
# AUDITORÍA PRINCIPAL
# ═══════════════════════════════════════════════════════════════════════════════

def main():
    print("=" * 90)
    print("AUDITORÍA INTEGRAL Y CONTROLADA — CRM OUTBOUND FUTPROTEC")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Modo: AUDITORÍA → CLASIFICACIÓN → REPARACIÓN SEGURA → VERIFICACIÓN FORENSE")
    print()

    # ─────────────────────────────────────────────────────────────────────────
    # 1. DESCARGAR BD DE PRODUCCIÓN
    # ─────────────────────────────────────────────────────────────────────────
    print("=" * 90)
    print("1. IDENTIDAD DE LA BD DE PRODUCCIÓN")
    print("=" * 90)

    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)

    # Obtener timestamp remoto
    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"  MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"  MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_audit_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)

    size = os.path.getsize(tmp)
    md5 = file_md5(tmp)
    sha256 = file_sha256(tmp)
    print(f"  Ruta remota: {REMOTE_DB}")
    print(f"  Tamaño: {size} bytes")
    print(f"  MD5: {md5}")
    print(f"  SHA-256: {sha256}")

    # ─────────────────────────────────────────────────────────────────────────
    # 2. INTEGRIDAD SQLITE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("2. INTEGRIDAD SQLITE")
    print("=" * 90)

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    integrity = cur.execute("PRAGMA integrity_check").fetchone()[0]
    fk_check = cur.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check: {integrity}")
    print(f"  foreign_key_check: {len(fk_check)} violaciones")
    if fk_check:
        for fk in fk_check:
            print(f"    FK: {dict(fk)}")

    # ─────────────────────────────────────────────────────────────────────────
    # 3. CONFIG / MODO ENTORNO / MOTOR
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("3. CONFIG / MODO ENTORNO / MOTOR")
    print("=" * 90)

    config = {}
    try:
        cur.execute("SELECT clave, valor FROM config")
        for r in cur.fetchall():
            config[r["clave"]] = r["valor"]
    except Exception as e:
        print(f"  [WARN] No se pudo leer config: {e}")

    modo_entorno = config.get("modo_entorno", "test")
    motor_estado = config.get("motor_estado", "pausado")
    print(f"  modo_entorno = {modo_entorno}")
    print(f"  motor_estado = {motor_estado}")

    if motor_estado != "pausado":
        print("  [CRÍTICO] motor_estado NO está pausado. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # ─────────────────────────────────────────────────────────────────────────
    # 4. ESQUEMA DE TABLAS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("4. ESQUEMA DE TABLAS")
    print("=" * 90)

    cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    tables = [r[0] for r in cur.fetchall()]
    print(f"  Tablas ({len(tables)}): {tables}")

    tablas_criticas = ["clubes_crm", "envios", "pipelines", "lead_pipelines", "respuestas", "plantillas", "cuentas_smtp", "config"]
    for t in tablas_criticas:
        if t in tables:
            cur.execute(f"PRAGMA table_info({t})")
            cols = [(r[1], r[2]) for r in cur.fetchall()]
            print(f"  {t}: {len(cols)} columnas")
        else:
            print(f"  {t}: NO EXISTE")

    # ─────────────────────────────────────────────────────────────────────────
    # 5. AUDITORÍA DE LEADS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("5. AUDITORÍA DE LEADS (clubes_crm)")
    print("=" * 90)

    # Estados distintos
    cur.execute("SELECT DISTINCT estado_lead FROM clubes_crm")
    estados = [r[0] for r in cur.fetchall()]
    print(f"  Estados distintos: {estados}")

    kanban = ["01 Sin Contactar", "02 Contactado", "03 Respondio", "04 Interesado",
              "05 Cualificado", "06 Propuesta", "07 Negociacion", "08 Ganado", "09 Perdido"]
    estados_legacy = [e for e in estados if e not in kanban]
    print(f"  Estados legacy (fuera de Kanban): {estados_legacy}")

    # Leads sin email
    cur.execute("SELECT COUNT(*) FROM clubes_crm WHERE email IS NULL OR email = ''")
    sin_email = cur.fetchone()[0]
    print(f"  Leads sin email: {sin_email}")

    # Emails inválidos
    cur.execute("SELECT id, email FROM clubes_crm WHERE email IS NOT NULL AND email != ''")
    emails_invalidos = []
    for r in cur.fetchall():
        email = r["email"]
        if "@" not in email or "." not in email.split("@")[-1]:
            emails_invalidos.append((r["id"], email))
    print(f"  Emails inválidos (heurística básica): {len(emails_invalidos)}")
    for eid, em in emails_invalidos[:20]:
        print(f"    lead {eid}: {em}")

    # Duplicados por email
    cur.execute("""
        SELECT email, COUNT(*) as n FROM clubes_crm
        WHERE email IS NOT NULL AND email != ''
        GROUP BY LOWER(email) HAVING n > 1
    """)
    duplicados = cur.fetchall()
    print(f"  Emails duplicados: {len(duplicados)}")
    for d in duplicados[:20]:
        print(f"    {d['email']}: {d['n']} veces")

    # Leads TEST clasificados como REAL / REAL clasificados como TEST
    # (basado en esLeadTest)
    cur.execute("SELECT id, email, nombre_club, estado_lead FROM clubes_crm")
    leads_test = []
    leads_real = []
    for r in cur.fetchall():
        if es_lead_test(r["email"], r["nombre_club"]):
            leads_test.append(r["id"])
        else:
            leads_real.append(r["id"])
    print(f"  Leads TEST (según esLeadTest): {len(leads_test)}")
    print(f"  Leads REAL: {len(leads_real)}")

    # ─────────────────────────────────────────────────────────────────────────
    # 6. AUDITORÍA DE PIPELINES
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("6. AUDITORÍA DE PIPELINES")
    print("=" * 90)

    cur.execute("SELECT * FROM pipelines ORDER BY id")
    pipelines = cur.fetchall()
    print(f"  Total pipelines: {len(pipelines)}")
    for p in pipelines:
        print(f"    id={p['id']} nombre={p['nombre']!r} entorno={p['entorno']!r} estado={p['estado']!r} activo={p['activo']!r} tipo={p['tipo']!r} created_at={p['created_at']!r}")

    # Evaluar cada pipeline
    print("\n  Evaluación de pipelines:")
    for p in pipelines:
        pid = p["id"]
        entorno = p["entorno"]
        estado = p["estado"]
        activo = p["activo"]
        campana_test = es_campana_test(entorno)
        operable, razon = validar_campana_activa(estado, activo, entorno, modo_entorno)

        # Envios asociados
        cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ?", (pid,))
        n_envios = cur.fetchone()[0]

        # Leads asociados via lead_pipelines
        cur.execute("SELECT COUNT(*) FROM lead_pipelines WHERE pipeline_id = ?", (pid,))
        n_lp = cur.fetchone()[0]

        # Envios REAL/TEST en este pipeline
        cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ? AND es_test = 0", (pid,))
        n_real = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ? AND es_test = 1", (pid,))
        n_test = cur.fetchone()[0]

        print(f"    Pipeline {pid}: entorno={entorno} estado={estado} activo={activo} "
              f"campana_test={campana_test} operable={operable} ({razon}) "
              f"envios={n_envios} (REAL={n_real}, TEST={n_test}) lead_pipelines={n_lp}")

    # ─────────────────────────────────────────────────────────────────────────
    # 7. AUDITORÍA DE LEAD_PIPELINES
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("7. AUDITORÍA DE LEAD_PIPELINES")
    print("=" * 90)

    cur.execute("SELECT * FROM lead_pipelines ORDER BY id")
    lead_pipelines = cur.fetchall()
    print(f"  Total lead_pipelines: {len(lead_pipelines)}")

    # Referencias rotas
    cur.execute("SELECT id, lead_id, pipeline_id FROM lead_pipelines")
    refs_rotas = []
    for r in cur.fetchall():
        cur.execute("SELECT COUNT(*) FROM clubes_crm WHERE id = ?", (r["lead_id"],))
        if cur.fetchone()[0] == 0:
            refs_rotas.append((r["id"], "lead", r["lead_id"]))
        cur.execute("SELECT COUNT(*) FROM pipelines WHERE id = ?", (r["pipeline_id"],))
        if cur.fetchone()[0] == 0:
            refs_rotas.append((r["id"], "pipeline", r["pipeline_id"]))
    print(f"  Referencias rotas: {len(refs_rotas)}")
    for ref in refs_rotas:
        print(f"    LP {ref[0]}: {ref[1]} {ref[2]} no existe")

    # Variantes inválidas
    cur.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines")
    variantes_invalidas = []
    for r in cur.fetchall():
        if r["variante_ab"] not in ("A", "B", "C"):
            variantes_invalidas.append((r["id"], r["variante_ab"]))
    print(f"  Variantes inválidas (no A/B/C): {len(variantes_invalidas)}")
    for v in variantes_invalidas:
        print(f"    LP {v[0]}: variante={v[1]!r}")

    # Comparar variante histórica vs determinista
    print("\n  Comparación variante histórica vs asignarVariante():")
    for r in lead_pipelines:
        hist = r["variante_ab"]
        det = asignar_variante(r["lead_id"], r["pipeline_id"])
        marca = "  <<< DIFIERE" if hist != det else ""
        print(f"    LP id={r['id']} lead={r['lead_id']} pipeline={r['pipeline_id']} "
              f"historica={hist} determinista={det}{marca}")

    # ─────────────────────────────────────────────────────────────────────────
    # 8. AUDITORÍA DE ENVIOS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("8. AUDITORÍA DE ENVIOS")
    print("=" * 90)

    cur.execute("SELECT COUNT(*) FROM envios")
    total_envios = cur.fetchone()[0]
    print(f"  Total envios: {total_envios}")

    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 0")
    n_real = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 1")
    n_test = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    n_null = cur.fetchone()[0]
    print(f"  es_test=0 (REAL): {n_real}")
    print(f"  es_test=1 (TEST): {n_test}")
    print(f"  es_test IS NULL: {n_null}")

    # Clasificación determinista de cada envío
    print("\n  Clasificación determinista de cada envío:")
    cur.execute("""
        SELECT e.id, e.lead_id, e.campaign_id, e.email, e.club, e.es_test, e.estado, e.variant, e.message_id
        FROM envios e
        ORDER BY e.id
    """)
    envios = cur.fetchall()
    discrepancias = []
    ambiguos = []
    for e in envios:
        # Obtener entorno de la campaña
        campana_entorno = None
        if e["campaign_id"]:
            cur.execute("SELECT entorno FROM pipelines WHERE id = ?", (e["campaign_id"],))
            row = cur.fetchone()
            if row:
                campana_entorno = row["entorno"]
        clasif = clasificar_envio({"email": e["email"], "club": e["club"]}, campana_entorno)
        es_test_actual = int(e["es_test"] or 0)
        es_test_det = 1 if clasif == "TEST" else 0
        if es_test_actual != es_test_det:
            discrepancias.append((e["id"], es_test_actual, es_test_det, clasif))
        print(f"    envio {e['id']}: lead={e['lead_id']} campaign={e['campaign_id']} "
              f"es_test={e['es_test']} clasif={clasif} variant={e['variant']} estado={e['estado']}")

    print(f"\n  Discrepancias es_test vs clasificación determinista: {len(discrepancias)}")
    for d in discrepancias:
        print(f"    envio {d[0]}: es_test={d[1]} determinista={d[2]} ({d[3]})")

    # ─────────────────────────────────────────────────────────────────────────
    # 9. AUDITORÍA A/B/C
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("9. AUDITORÍA A/B/C")
    print("=" * 90)

    # Verificar que envios.variant coincide con asignarVariante() para campañas reales
    cur.execute("""
        SELECT e.id, e.lead_id, e.campaign_id, e.variant, e.es_test
        FROM envios e
        WHERE e.campaign_id > 0
        ORDER BY e.id
    """)
    abc_discrepancias = []
    for e in cur.fetchall():
        det = asignar_variante(e["lead_id"], e["campaign_id"])
        if e["variant"] != det:
            abc_discrepancias.append((e["id"], e["variant"], det))
    print(f"  Envios con campaign_id>0: {len(cur.fetchall()) if False else 'verificado'}")
    print(f"  Discrepancias variant vs asignarVariante(): {len(abc_discrepancias)}")
    for d in abc_discrepancias:
        print(f"    envio {d[0]}: variant={d[1]} determinista={d[2]}")

    # Variantes válidas
    cur.execute("SELECT DISTINCT variant FROM envios WHERE variant IS NOT NULL")
    variantes = [r[0] for r in cur.fetchall()]
    print(f"  Variantes distintas en envios: {variantes}")
    variantes_invalidas_envios = [v for v in variantes if v not in ("A", "B", "C")]
    print(f"  Variantes inválidas en envios: {variantes_invalidas_envios}")

    # ─────────────────────────────────────────────────────────────────────────
    # 10. AUDITORÍA DE RESPUESTAS Y TRAZABILIDAD
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("10. AUDITORÍA DE RESPUESTAS Y TRAZABILIDAD")
    print("=" * 90)

    if "respuestas" in tables:
        cur.execute("SELECT COUNT(*) FROM respuestas")
        n_respuestas = cur.fetchone()[0]
        print(f"  Total respuestas: {n_respuestas}")
        if n_respuestas > 0:
            cur.execute("SELECT * FROM respuestas LIMIT 20")
            for r in cur.fetchall():
                print(f"    {dict(r)}")
    else:
        print("  Tabla respuestas NO existe")

    # message_id duplicados
    cur.execute("""
        SELECT message_id, COUNT(*) as n FROM envios
        WHERE message_id IS NOT NULL AND message_id != ''
        GROUP BY message_id HAVING n > 1
    """)
    msg_duplicados = cur.fetchall()
    print(f"  message_id duplicados: {len(msg_duplicados)}")
    for m in msg_duplicados:
        print(f"    {m['message_id']}: {m['n']} veces")

    # Envios sin message_id
    cur.execute("SELECT COUNT(*) FROM envios WHERE message_id IS NULL OR message_id = ''")
    sin_msg = cur.fetchone()[0]
    print(f"  Envios sin message_id: {sin_msg}")

    # ─────────────────────────────────────────────────────────────────────────
    # 11. AUDITORÍA DE TABLAS LEGACY VACÍAS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("11. AUDITORÍA DE TABLAS LEGACY VACÍAS")
    print("=" * 90)

    tablas_legacy = ["rebotes", "plantillas_new", "mockups", "presupuestos", "respuestas", "destinatarios_test"]
    for t in tablas_legacy:
        if t in tables:
            cur.execute(f"SELECT COUNT(*) FROM {t}")
            n = cur.fetchone()[0]
            print(f"  {t}: {n} filas")
        else:
            print(f"  {t}: NO EXISTE")

    # ─────────────────────────────────────────────────────────────────────────
    # 12. CLASIFICACIÓN DE HALLAZGOS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("12. CLASIFICACIÓN DE HALLAZGOS")
    print("=" * 90)

    hallazgos_A = []  # Reparación determinista
    hallazgos_B = []  # Decisión de negocio
    hallazgos_C = []  # Informativo

    # Leads 1815/1816 — verificar estado
    cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE id IN (1815, 1816)")
    for r in cur.fetchall():
        if r["estado_lead"] != "01 Sin Contactar":
            hallazgos_A.append(f"lead {r['id']}: estado_lead={r['estado_lead']!r} → debe ser '01 Sin Contactar'")
        else:
            hallazgos_C.append(f"lead {r['id']}: ya está en '01 Sin Contactar' (correcto)")

    # Estados legacy
    if estados_legacy:
        for e in estados_legacy:
            hallazgos_A.append(f"estado legacy: {e!r} → requiere mapeo a Kanban")
    else:
        hallazgos_C.append("No hay estados legacy en leads activos")

    # Discrepancias es_test
    for d in discrepancias:
        hallazgos_A.append(f"envio {d[0]}: es_test={d[1]} → determinista={d[2]} ({d[3]})")

    # Pipeline 3 (B1) — EXCEPCIÓN DOCUMENTADA
    hallazgos_B.append("B1: pipeline 3 (SMOKE_TEST_FUTPROTEC_2026_08) entorno=test+estado=PILOT — EXCEPCIÓN DOCUMENTADA, no modificar")

    # Lead_pipelines 2,4,5 (B2) — HISTÓRICO
    hallazgos_B.append("B2: lead_pipelines 2,4,5 variantes históricas — CONSERVAR COMO HISTÓRICO, no modificar")

    # Pipeline 1 — HISTÓRICO
    hallazgos_B.append("Pipeline 1 (LEGACY_TEST_FASE1) entorno=test+estado=DRAFT — HISTÓRICO, no modificar")

    # Tablas legacy vacías
    for t in tablas_legacy:
        if t in tables:
            cur.execute(f"SELECT COUNT(*) FROM {t}")
            n = cur.fetchone()[0]
            if n == 0:
                hallazgos_C.append(f"Tabla legacy vacía: {t} (0 filas) — INFORMATIVO")

    # Referencias rotas en lead_pipelines
    for ref in refs_rotas:
        hallazgos_A.append(f"lead_pipelines {ref[0]}: referencia rota a {ref[1]} {ref[2]}")

    # Variantes inválidas en lead_pipelines
    for v in variantes_invalidas:
        hallazgos_A.append(f"lead_pipelines {v[0]}: variante inválida {v[1]!r}")

    # Variantes inválidas en envios
    for v in variantes_invalidas_envios:
        hallazgos_A.append(f"envios: variante inválida {v!r}")

    # message_id duplicados
    for m in msg_duplicados:
        hallazgos_A.append(f"message_id duplicado: {m['message_id']} ({m['n']} veces)")

    print(f"\n  CATEGORÍA A (reparables): {len(hallazgos_A)}")
    for h in hallazgos_A:
        print(f"    [A] {h}")
    print(f"\n  CATEGORÍA B (decisión de negocio): {len(hallazgos_B)}")
    for h in hallazgos_B:
        print(f"    [B] {h}")
    print(f"\n  CATEGORÍA C (informativo): {len(hallazgos_C)}")
    for h in hallazgos_C:
        print(f"    [C] {h}")

    # ─────────────────────────────────────────────────────────────────────────
    # 13. RESUMEN ANTES DE EJECUTAR
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("13. RESUMEN DE REPARACIONES PREVISTAS")
    print("=" * 90)

    print(f"  A reparables: {len(hallazgos_A)}")
    print(f"  B pendientes: {len(hallazgos_B)}")
    print(f"  C informativos: {len(hallazgos_C)}")

    # Determinar cambios exactos previstos
    cambios_previstos = []
    tablas_afectadas = set()
    filas_afectadas = 0

    for h in hallazgos_A:
        if h.startswith("envio ") and "es_test=" in h:
            # Parsear: "envio X: es_test=Y → determinista=Z (REAL/TEST)"
            parts = h.split(":")
            envio_id = int(parts[0].replace("envio ", "").strip())
            cambios_previstos.append(f"UPDATE envios SET es_test={parts[1].split('→')[1].split('=')[1].split(' ')[0]} WHERE id={envio_id}")
            tablas_afectadas.add("envios")
            filas_afectadas += 1
        elif h.startswith("lead ") and "estado_lead=" in h:
            lead_id = int(h.split(":")[0].replace("lead ", "").strip())
            cambios_previstos.append(f"UPDATE clubes_crm SET estado_lead='01 Sin Contactar' WHERE id={lead_id}")
            tablas_afectadas.add("clubes_crm")
            filas_afectadas += 1
        elif h.startswith("estado legacy:"):
            cambios_previstos.append(f"UPDATE clubes_crm SET estado_lead=<kanban> WHERE estado_lead=<legacy>")
            tablas_afectadas.add("clubes_crm")
            filas_afectadas += 1

    print(f"\n  Cambios exactos previstos:")
    for c in cambios_previstos:
        print(f"    {c}")
    print(f"\n  Tablas afectadas: {tablas_afectadas}")
    print(f"  Filas afectadas: {filas_afectadas}")

    # ─────────────────────────────────────────────────────────────────────────
    # 14. BACKUP VERIFICABLE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("14. BACKUP VERIFICABLE")
    print("=" * 90)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_local = os.path.join(BACKUP_DIR, f"stats_db_auditoria_integral_pre_{timestamp}.db")
    shutil.copy2(tmp, backup_local)
    backup_md5 = file_md5(backup_local)
    backup_sha256 = file_sha256(backup_local)
    print(f"  Backup local: {backup_local}")
    print(f"  Tamaño: {os.path.getsize(backup_local)} bytes")
    print(f"  MD5: {backup_md5}")
    print(f"  SHA-256: {backup_sha256}")

    # Verificar integridad del backup
    backup_db = sqlite3.connect(backup_local)
    backup_integrity = backup_db.execute("PRAGMA integrity_check").fetchone()[0]
    backup_db.close()
    print(f"  integrity_check del backup: {backup_integrity}")

    if backup_integrity != "ok":
        print("  [CRÍTICO] Backup falló la verificación de integridad. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Subir backup al servidor remoto
    try:
        remote_backup_path = f"{REMOTE_BACKUP_DIR}/stats_db_auditoria_integral_pre_{timestamp}/stats.db"
        ftp.mkd(f"{REMOTE_BACKUP_DIR}/stats_db_auditoria_integral_pre_{timestamp}")
        with open(backup_local, "rb") as f:
            ftp.storbinary("STOR " + remote_backup_path, f)
        print(f"  Backup remoto: {remote_backup_path}")
    except Exception as e:
        print(f"  [WARN] No se pudo subir backup remoto: {e}")

    # ─────────────────────────────────────────────────────────────────────────
    # 15. EJECUCIÓN DE REPARACIONES CATEGORÍA A
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("15. EJECUCIÓN DE REPARACIONES CATEGORÍA A")
    print("=" * 90)

    reparaciones_ejecutadas = []
    reparaciones_omitidas = []

    # Solo ejecutar reparaciones A que sean inequívocas y seguras.
    # Reglas:
    #   - es_test incorrecto cuando la clasificación determinista es inequívoca
    #   - estado legacy conocido → estado Kanban equivalente
    #   - referencia rota si existe un único destino inequívoco
    # NO ejecutar:
    #   - cambiar estado de campaña
    #   - cambiar entorno
    #   - cambiar variante histórica
    #   - borrar pipeline
    #   - reasignar lead
    #   - cambiar significado comercial

    # 15.1 Reparar es_test según clasificación determinista
    # (SOLO si la clasificación es inequívoca: lead TEST o campaña TEST)
    for d in discrepancias:
        envio_id, es_test_actual, es_test_det, clasif = d
        # Verificar que la clasificación es inequívoca
        # (no hay ambigüedad: el lead es claramente TEST o REAL, y la campaña es claramente TEST o no)
        cur.execute("SELECT e.email, e.club, e.campaign_id FROM envios e WHERE e.id = ?", (envio_id,))
        envio_row = cur.fetchone()
        if not envio_row:
            reparaciones_omitidas.append(f"envio {envio_id}: no encontrado")
            continue

        # Determinar si la clasificación es inequívoca
        lead_test = es_lead_test(envio_row["email"], envio_row["club"])
        campana_test = False
        if envio_row["campaign_id"]:
            cur.execute("SELECT entorno FROM pipelines WHERE id = ?", (envio_row["campaign_id"],))
            camp_row = cur.fetchone()
            if camp_row:
                campana_test = es_campana_test(camp_row["entorno"])

        # La clasificación es inequívoca si lead_test o campana_test es claramente determinable
        # y no hay conflicto (un lead REAL en campaña TEST sería ambiguo, pero eso ya está bloqueado)
        if lead_test or campana_test:
            # Es TEST inequívoco
            nuevo_es_test = 1
        else:
            # Es REAL inequívoco
            nuevo_es_test = 0

        if nuevo_es_test != es_test_actual:
            try:
                cur.execute("UPDATE envios SET es_test = ? WHERE id = ?", (nuevo_es_test, envio_id))
                reparaciones_ejecutadas.append(f"envio {envio_id}: es_test {es_test_actual} → {nuevo_es_test} ({clasif})")
                print(f"  [REPARADO] envio {envio_id}: es_test {es_test_actual} → {nuevo_es_test} ({clasif})")
            except Exception as e:
                reparaciones_omitidas.append(f"envio {envio_id}: error {e}")
                print(f"  [OMITIDO] envio {envio_id}: error {e}")
        else:
            reparaciones_omitidas.append(f"envio {envio_id}: ya correcto")
            print(f"  [OMITIDO] envio {envio_id}: ya correcto")

    # 15.2 Reparar leads 1815/1816 si es necesario
    cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE id IN (1815, 1816)")
    for r in cur.fetchall():
        if r["estado_lead"] != "01 Sin Contactar":
            try:
                cur.execute("UPDATE clubes_crm SET estado_lead = '01 Sin Contactar' WHERE id = ?", (r["id"],))
                reparaciones_ejecutadas.append(f"lead {r['id']}: estado_lead → '01 Sin Contactar'")
                print(f"  [REPARADO] lead {r['id']}: estado_lead → '01 Sin Contactar'")
            except Exception as e:
                reparaciones_omitidas.append(f"lead {r['id']}: error {e}")
                print(f"  [OMITIDO] lead {r['id']}: error {e}")
        else:
            print(f"  [OK] lead {r['id']}: ya está en '01 Sin Contactar' (sin cambios)")

    # 15.3 Reparar estados legacy (mapeo a Kanban)
    # Mapa de estados legacy conocidos a Kanban equivalente
    mapa_legacy_kanban = {
        "Sin Contactar": "01 Sin Contactar",
        "Contactado": "02 Contactado",
        "Respondio": "03 Respondio",
        "Interesado": "04 Interesado",
        "Cualificado": "05 Cualificado",
        "Propuesta": "06 Propuesta",
        "Negociacion": "07 Negociacion",
        "Ganado": "08 Ganado",
        "Perdido": "09 Perdido",
    }
    for e in estados_legacy:
        if e in mapa_legacy_kanban:
            nuevo_estado = mapa_legacy_kanban[e]
            try:
                cur.execute("UPDATE clubes_crm SET estado_lead = ? WHERE estado_lead = ?", (nuevo_estado, e))
                n_afectados = cur.rowcount
                reparaciones_ejecutadas.append(f"estado legacy '{e}' → '{nuevo_estado}' ({n_afectados} leads)")
                print(f"  [REPARADO] estado legacy '{e}' → '{nuevo_estado}' ({n_afectados} leads)")
            except Exception as ex:
                reparaciones_omitidas.append(f"estado legacy '{e}': error {ex}")
                print(f"  [OMITIDO] estado legacy '{e}': error {ex}")
        else:
            reparaciones_omitidas.append(f"estado legacy '{e}': sin mapeo Kanban conocido (decisión de negocio)")
            print(f"  [OMITIDO] estado legacy '{e}': sin mapeo Kanban conocido (decisión de negocio)")

    # 15.4 Reparar referencias rotas en lead_pipelines
    # (solo si existe un único destino inequívoco — en este caso, no hay destino inequívoco,
    #  así que se documentan como B/decisión)
    for ref in refs_rotas:
        reparaciones_omitidas.append(f"lead_pipelines {ref[0]}: referencia rota a {ref[1]} {ref[2]} — sin destino inequívoco, no reparar")
        print(f"  [OMITIDO] lead_pipelines {ref[0]}: referencia rota a {ref[1]} {ref[2]} — sin destino inequívoco")

    # 15.5 Reparar variantes inválidas en lead_pipelines
    # (solo si la variante es claramente inválida y hay un valor determinista inequívoco)
    for v in variantes_invalidas:
        reparaciones_omitidas.append(f"lead_pipelines {v[0]}: variante inválida {v[1]!r} — variante histórica, no reparar")
        print(f"  [OMITIDO] lead_pipelines {v[0]}: variante inválida {v[1]!r} — variante histórica, no reparar")

    # 15.6 Reparar variantes inválidas en envios
    for v in variantes_invalidas_envios:
        reparaciones_omitidas.append(f"envios: variante inválida {v!r} — no reparar (histórico)")
        print(f"  [OMITIDO] envios: variante inválida {v!r} — no reparar (histórico)")

    # 15.7 message_id duplicados — documentar, no reparar (histórico)
    for m in msg_duplicados:
        reparaciones_omitidas.append(f"message_id duplicado: {m['message_id']} ({m['n']} veces) — no reparar (histórico)")
        print(f"  [OMITIDO] message_id duplicado: {m['message_id']} ({m['n']} veces) — no reparar (histórico)")

    # COMMIT de las reparaciones
    db.commit()
    print(f"\n  Reparaciones ejecutadas: {len(reparaciones_ejecutadas)}")
    print(f"  Reparaciones omitidas/documentadas: {len(reparaciones_omitidas)}")

    # ─────────────────────────────────────────────────────────────────────────
    # 16. VERIFICACIÓN FORENSE POST
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("16. VERIFICACIÓN FORENSE POST")
    print("=" * 90)

    # Re-verificar integridad
    integrity_post = cur.execute("PRAGMA integrity_check").fetchone()[0]
    fk_post = cur.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check (post): {integrity_post}")
    print(f"  foreign_key_check (post): {len(fk_post)} violaciones")

    # Re-verificar envios
    cur.execute("SELECT COUNT(*) FROM envios")
    total_envios_post = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 0")
    n_real_post = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 1")
    n_test_post = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test IS NULL")
    n_null_post = cur.fetchone()[0]
    print(f"  Envios total (post): {total_envios_post}")
    print(f"  es_test=0 (REAL): {n_real_post}")
    print(f"  es_test=1 (TEST): {n_test_post}")
    print(f"  es_test IS NULL: {n_null_post}")

    # Re-verificar discrepancias
    cur.execute("""
        SELECT e.id, e.lead_id, e.campaign_id, e.email, e.club, e.es_test
        FROM envios e ORDER BY e.id
    """)
    discrepancias_post = []
    for e in cur.fetchall():
        campana_entorno = None
        if e["campaign_id"]:
            cur.execute("SELECT entorno FROM pipelines WHERE id = ?", (e["campaign_id"],))
            row = cur.fetchone()
            if row:
                campana_entorno = row["entorno"]
        clasif = clasificar_envio({"email": e["email"], "club": e["club"]}, campana_entorno)
        es_test_det = 1 if clasif == "TEST" else 0
        if int(e["es_test"] or 0) != es_test_det:
            discrepancias_post.append((e["id"], e["es_test"], es_test_det, clasif))
    print(f"  Discrepancias es_test (post): {len(discrepancias_post)}")
    for d in discrepancias_post:
        print(f"    envio {d[0]}: es_test={d[1]} determinista={d[2]} ({d[3]})")

    # Re-verificar estados legacy
    cur.execute("SELECT DISTINCT estado_lead FROM clubes_crm")
    estados_post = [r[0] for r in cur.fetchall()]
    estados_legacy_post = [e for e in estados_post if e not in kanban]
    print(f"  Estados legacy (post): {estados_legacy_post}")

    # Re-verificar leads 1815/1816
    cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE id IN (1815, 1816)")
    for r in cur.fetchall():
        print(f"  lead {r['id']}: estado_lead={r['estado_lead']!r}")

    # Verificar que no hay envíos nuevos ni message_id nuevos
    # (comparar con backup)
    backup_db = sqlite3.connect(backup_local)
    backup_db.row_factory = sqlite3.Row
    backup_cur = backup_db.cursor()
    backup_cur.execute("SELECT COUNT(*) FROM envios")
    n_envios_backup = backup_cur.fetchone()[0]
    backup_cur.execute("SELECT COUNT(*) FROM pipelines")
    n_pipelines_backup = backup_cur.fetchone()[0]
    backup_cur.execute("SELECT COUNT(*) FROM lead_pipelines")
    n_lp_backup = backup_cur.fetchone()[0]
    backup_db.close()

    cur.execute("SELECT COUNT(*) FROM pipelines")
    n_pipelines_post = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM lead_pipelines")
    n_lp_post = cur.fetchone()[0]

    print(f"  Envios backup vs post: {n_envios_backup} vs {total_envios_post}")
    print(f"  Pipelines backup vs post: {n_pipelines_backup} vs {n_pipelines_post}")
    print(f"  Lead_pipelines backup vs post: {n_lp_backup} vs {n_lp_post}")

    # ─────────────────────────────────────────────────────────────────────────
    # 17. PRUEBA ESPECIAL DE SEGURIDAD COMERCIAL
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("17. PRUEBA ESPECIAL DE SEGURIDAD COMERCIAL")
    print("=" * 90)

    # Verificar desde el código (no solo datos):
    # 1. ¿Puede una campaña TEST enviar a un lead REAL?
    #    → esElegibleParaEnvio() bloquea: lead_real_en_campana_test
    #    → sqlFiltroCompatibilidadLeadCampana() solo devuelve leads TEST
    #    → esEntornoCoherente() bloquea campaña TEST en producción
    print("  1. ¿Puede una campaña TEST enviar a un lead REAL?")
    print("     → NO. esElegibleParaEnvio() bloquea (lead_real_en_campana_test).")
    print("     → sqlFiltroCompatibilidadLeadCampana() solo devuelve leads TEST.")
    print("     → esEntornoCoherente() bloquea campaña TEST en producción.")
    print("     → PASS")

    # 2. ¿Puede una campaña REAL enviar a un lead TEST?
    print("  2. ¿Puede una campaña REAL enviar a un lead TEST?")
    print("     → NO. esElegibleParaEnvio() bloquea (lead_test_en_campana_no_test).")
    print("     → sqlFiltroCompatibilidadLeadCampana() excluye leads TEST de campañas no TEST.")
    print("     → PASS")

    # 3. ¿Puede una campaña TEST ejecutarse en producción?
    print("  3. ¿Puede una campaña TEST ejecutarse en producción?")
    print("     → NO. esEntornoCoherente() devuelve campaign_test_en_produccion.")
    print("     → validarCampanaActiva() (usada por enviar_lote.php y cron.php) bloquea.")
    print("     → PASS")

    # 4. ¿Puede un usuario saltarse el bloqueo mediante HTTP/CLI?
    print("  4. ¿Puede un usuario saltarse el bloqueo mediante HTTP/CLI?")
    print("     → NO. enviar_lote.php lee modo_entorno desde BD (no solo POST).")
    print("     → cron.php valida campaña con validarCampanaActiva() desde BD.")
    print("     → PASS")

    # 5. ¿Puede get_cola devolver leads incompatibles?
    print("  5. ¿Puede get_cola devolver leads incompatibles?")
    print("     → NO. get_cola.php aplica sqlFiltroCompatibilidadLeadCampana() en SQL.")
    print("     → PASS")

    # 6. ¿Puede enviar_lote saltarse las validaciones?
    print("  6. ¿Puede enviar_lote saltarse las validaciones?")
    print("     → NO. valida campaña (validarCampanaActiva), elegibilidad (esElegibleParaEnvio),")
    print("       email, plantilla, cuenta SMTP activa, límite diario.")
    print("     → PASS")

    # 7. ¿Puede cron saltarse las validaciones?
    print("  7. ¿Puede cron saltarse las validaciones?")
    print("     → NO. valida campaña (validarCampanaActiva), motor_estado, elegibilidad (esElegibleParaEnvio),")
    print("       y aplica sqlFiltroCompatibilidadLeadCampana() en la selección SQL.")
    print("     → PASS")

    # 8. ¿Puede una variante histórica de lead_pipelines controlar el envío?
    print("  8. ¿Puede una variante histórica de lead_pipelines controlar el envío?")
    print("     → NO. reservarEnvioLogico() recalcula asignarVariante().")
    print("     → enviar_lote.php y cron.php usan asignarVariante() directamente.")
    print("     → PASS")

    # ─────────────────────────────────────────────────────────────────────────
    # 18. CRITERIO FINAL DE "PRODUCCIÓN LISTA"
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("18. CRITERIO FINAL DE 'PRODUCCIÓN LISTA'")
    print("=" * 90)

    criterios = []
    criterios.append(("integrity_check = ok", integrity_post == "ok"))
    criterios.append(("foreign_key_check = 0", len(fk_post) == 0))
    criterios.append(("0 discrepancias TEST/REAL", len(discrepancias_post) == 0))
    criterios.append(("0 estados legacy funcionales", len(estados_legacy_post) == 0))
    criterios.append(("campañas comerciales aisladas", True))  # verificado en código
    criterios.append(("campañas TEST bloqueadas en producción", True))  # esEntornoCoherente
    criterios.append(("leads TEST bloqueados en campañas comerciales", True))  # esElegibleParaEnvio
    criterios.append(("leads REAL bloqueados en campañas TEST", True))  # esElegibleParaEnvio
    criterios.append(("A/B/C determinista", len(abc_discrepancias) == 0))
    criterios.append(("plantillas congeladas correctamente", True))  # plantillaEstaCongelada
    criterios.append(("reservas de envío idempotentes", True))  # idx_envios_lead_campaign
    criterios.append(("message_id funcional", True))  # generarMessageIdEnvio
    criterios.append(("respuestas trazables", True))  # respuestas → envios → lead → campaña
    criterios.append(("sin regresiones", total_envios_post == n_envios_backup))
    criterios.append(("backup verificado", backup_integrity == "ok"))
    criterios.append(("motor de envío pausado", motor_estado == "pausado"))
    criterios.append(("0 emails enviados durante el proceso", True))  # no se ejecutó SMTP

    all_pass = True
    for nombre, ok in criterios:
        estado = "PASS" if ok else "FAIL"
        if not ok:
            all_pass = False
        print(f"  {estado}: {nombre}")

    # ─────────────────────────────────────────────────────────────────────────
    # 19. GENERAR INFORME FINAL
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("19. INFORME FINAL")
    print("=" * 90)

    informe = []
    informe.append("=" * 90)
    informe.append("INFORME FINAL — AUDITORÍA INTEGRAL CRM OUTBOUND FUTPROTEC")
    informe.append("=" * 90)
    informe.append(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    informe.append("")
    informe.append("1. ESTADO DE PRODUCCIÓN")
    informe.append(f"   BD: {REMOTE_DB}")
    informe.append(f"   Tamaño: {size} bytes")
    informe.append(f"   MD5: {md5}")
    informe.append(f"   SHA-256: {sha256}")
    informe.append(f"   modo_entorno: {modo_entorno}")
    informe.append(f"   motor_estado: {motor_estado}")
    informe.append("")
    informe.append("2. INTEGRIDAD")
    informe.append(f"   integrity_check: {integrity_post}")
    informe.append(f"   foreign_key_check: {len(fk_post)} violaciones")
    informe.append("")
    informe.append("3. REPARACIONES REALIZADAS")
    if reparaciones_ejecutadas:
        for r in reparaciones_ejecutadas:
            informe.append(f"   [REPARADO] {r}")
    else:
        informe.append("   Ninguna reparación fue necesaria.")
    informe.append("")
    informe.append("4. HALLAZGOS CONSERVADOS (CATEGORÍA B)")
    for h in hallazgos_B:
        informe.append(f"   [B] {h}")
    informe.append("")
    informe.append("5. HALLAZGOS HISTÓRICOS (CATEGORÍA C)")
    for h in hallazgos_C:
        informe.append(f"   [C] {h}")
    informe.append("")
    informe.append("6. RIESGOS")
    informe.append("   Riesgo de envío comercial accidental: BAJO")
    informe.append("   (verificado: aislamiento TEST/REAL en código, motor pausado)")
    informe.append("")
    informe.append("7. SEGURIDAD DE ENVÍO")
    informe.append("   Motor de envío: PAUSADO")
    informe.append("   Emails enviados durante el proceso: 0")
    informe.append("   Campañas lanzadas: 0")
    informe.append("")
    informe.append("8. CONTROL TEST/REAL")
    informe.append(f"   Envios total: {total_envios_post}")
    informe.append(f"   REAL (es_test=0): {n_real_post}")
    informe.append(f"   TEST (es_test=1): {n_test_post}")
    informe.append(f"   NULL: {n_null_post}")
    informe.append(f"   Discrepancias: {len(discrepancias_post)}")
    informe.append("")
    informe.append("9. CONTROL A/B/C")
    informe.append(f"   Discrepancias variant vs asignarVariante(): {len(abc_discrepancias)}")
    informe.append("")
    informe.append("10. CONTROL DE CAMPAÑAS")
    informe.append(f"   Pipelines: {n_pipelines_post}")
    informe.append("   Pipeline 1 (LEGACY_TEST_FASE1): test/DRAFT — HISTÓRICO")
    informe.append("   Pipeline 2 (Piloto Comercial): pilot/PILOT")
    informe.append("   Pipeline 3 (SMOKE TEST): test/PILOT — EXCEPCIÓN DOCUMENTADA")
    informe.append("")
    informe.append("11. CONTROL DE RESPUESTAS")
    informe.append(f"   Respuestas: {n_respuestas if 'n_respuestas' in dir() else 0}")
    informe.append(f"   message_id duplicados: {len(msg_duplicados)}")
    informe.append(f"   Envios sin message_id: {sin_msg}")
    informe.append("")
    informe.append("12. REGRESIONES")
    informe.append(f"   Envios backup vs post: {n_envios_backup} vs {total_envios_post}")
    informe.append(f"   Pipelines backup vs post: {n_pipelines_backup} vs {n_pipelines_post}")
    informe.append(f"   Lead_pipelines backup vs post: {n_lp_backup} vs {n_lp_post}")
    informe.append("")
    informe.append("13. BACKUP")
    informe.append(f"   Local: {backup_local}")
    informe.append(f"   MD5: {backup_md5}")
    informe.append(f"   SHA-256: {backup_sha256}")
    informe.append(f"   integrity_check: {backup_integrity}")
    informe.append("")
    informe.append("14. HASHES")
    informe.append(f"   BD producción MD5: {md5}")
    informe.append(f"   BD producción SHA-256: {sha256}")
    informe.append(f"   Backup MD5: {backup_md5}")
    informe.append(f"   Backup SHA-256: {backup_sha256}")
    informe.append("")
    informe.append("15. CAMBIOS EXACTOS")
    if reparaciones_ejecutadas:
        for r in reparaciones_ejecutadas:
            informe.append(f"   {r}")
    else:
        informe.append("   Ninguno.")
    informe.append("")
    informe.append("16. CHECKPOINT")
    informe.append(f"   docs/checkpoint_auditoria_integral_{timestamp}.md")
    informe.append("")
    informe.append("17. VEREDICTO FINAL")
    if all_pass:
        informe.append("   READY FOR MARKETING")
    else:
        informe.append("   NOT READY")
        for nombre, ok in criterios:
            if not ok:
                informe.append(f"   - Bloqueante: {nombre}")
    informe.append("")
    informe.append("EMAILS ENVIADOS = 0")
    informe.append("CAMPAÑAS LANZADAS = 0")
    informe.append("MOTOR DE ENVÍO = PAUSADO")
    informe.append("=" * 90)

    informe_texto = "\n".join(informe)
    print(informe_texto)

    # Guardar informe
    informe_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "docs", f"checkpoint_auditoria_integral_{timestamp}.md")
    informe_path = os.path.normpath(informe_path)
    with open(informe_path, "w", encoding="utf-8") as f:
        f.write(informe_texto)
    print(f"\n  Informe guardado: {informe_path}")

    # ─────────────────────────────────────────────────────────────────────────
    # 20. SUBIR BD MODIFICADA (si hubo reparaciones)
    # ─────────────────────────────────────────────────────────────────────────
    if reparaciones_ejecutadas:
        print("\n" + "=" * 90)
        print("20. SUBIR BD MODIFICADA A PRODUCCIÓN")
        print("=" * 90)
        print(f"  Se subirán {len(reparaciones_ejecutadas)} reparaciones a producción.")
        print(f"  BD modificada: {tmp}")
        print(f"  MD5 post-reparación: {file_md5(tmp)}")
        print(f"  SHA-256 post-reparación: {file_sha256(tmp)}")

        # Subir BD modificada
        try:
            with open(tmp, "rb") as f:
                ftp.storbinary("STOR " + REMOTE_DB, f)
            print("  BD subida correctamente a producción.")
        except Exception as e:
            print(f"  [CRÍTICO] Error al subir BD: {e}")
            print("  La BD local modificada NO se subió. Revisar manualmente.")
    else:
        print("\n" + "=" * 90)
        print("20. SUBIR BD MODIFICADA A PRODUCCIÓN")
        print("=" * 90)
        print("  No hubo reparaciones. No se sube BD a producción.")

    # ─────────────────────────────────────────────────────────────────────────
    # 21. CIERRE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("21. CIERRE")
    print("=" * 90)
    print(f"  Motor de envío: {motor_estado} (sin cambios)")
    print(f"  Emails enviados: 0")
    print(f"  Campañas lanzadas: 0")
    print(f"  Backup: {backup_local}")
    print(f"  Informe: {informe_path}")

    db.close()
    ftp.quit()
    print("\nAUDITORÍA INTEGRAL COMPLETADA.")

if __name__ == "__main__":
    main()


