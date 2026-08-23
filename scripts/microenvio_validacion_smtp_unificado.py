#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
microenvio_validacion_smtp_unificado.py

MICROENVÍO DE VALIDACIÓN DEL TRANSPORTE SMTP UNIFICADO (5 LEADS REALES)

OBJETIVO: Validar en producción el transporte SMTP centralizado
(futprotec_enviarSMTP en inc/smtp_transport.php) tras el refactor y deploy
del 2026-08-22. Los envíos previos de campaña 2 (18/08) fueron ANTES del
refactor, por lo que este microenvío valida el transporte centralizado
con un envío real post-refactor.

AUTORIZACIÓN: EXACTAMENTE 5 EMAILS REALES.
  lead_id IN (3, 4, 5, 8, 9)   <- leads SIN envío previo en campaña 2
  campaign_id = 2
  plantilla_id = 1 (Prospeccion abc - texto plano)

REGLAS ABSOLUTAS:
  - NO enviar más de 5 emails.
  - NO usar cron general.
  - NO activar el motor.
  - NO modificar pipelines, lead_pipelines, variantes, plantillas, es_test.
  - NO lanzar campaña masiva.
  - Si cualquier precondición falla: STOP.

FLUJO:
  1. Login (cookie de sesión).
  2. Descargar BD, verificar identidad/hashes/integridad.
  3. Precheck de los 5 leads (variantes, elegibilidad, TEST/REAL, envío previo).
  4. Simulación de los 5 emails (sin enviar).
  5. Envío controlado de exactamente 5 leads vía enviar_lote.php.
  6. Postcheck (5 envíos, variantes, message_id, integridad).
  7. Generar checkpoint.

USO:
  python scripts/microenvio_validacion_smtp_unificado.py
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
import urllib.request
import urllib.parse
import http.cookiejar
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
AUTH_KEY = env.get("AUTH_KEY", "FutProtec2026!")
BASE_URL = env.get("BASE_URL", "https://getfutprotec.com/outbound")
REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

BACKUP_DIR = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "backups_deploy"))
os.makedirs(BACKUP_DIR, exist_ok=True)

CAMPAIGN_ID = 2
PLANTILLA_ID = 1
# Leads SIN envío previo en campaña 2 (validación transporte SMTP unificado)
LEADS_AUTORIZADOS = [3, 4, 5, 8, 9]
VARIANTES_ESPERADAS = {3: 'B', 4: 'B', 5: 'B', 8: 'C', 9: 'B'}

# ═══════════════════════════════════════════════════════════════════════════════
# FUNCIONES AUXILIARES (espejo exacto de las reglas PHP)
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
    """Espejo exacto de asignarVariante() en inc/abc.php (crc32)."""
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
    """Espejo exacto de esCampanaTest()."""
    return (entorno or "").lower() == "test"

def es_entorno_coherente(campaign_entorno, modo_entorno):
    """Espejo exacto de esEntornoCoherente()."""
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
    """Espejo exacto de validarCampanaActiva()."""
    estados_permitidos = ["PILOT", "ACTIVE"]
    if (estado or "").upper() not in estados_permitidos or int(activo or 0) != 1:
        return False, "CAMPAIGN_NOT_ACTIVE"
    ok, razon = es_entorno_coherente(entorno, modo_entorno)
    if not ok:
        return False, "ENVIRONMENT_MISMATCH"
    return True, "CAMPANIA_VALIDA"

ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

def es_elegible(db, lead_id, campaign_id):
    """Espejo exacto de esElegibleParaEnvio()."""
    if lead_id <= 0:
        return False, "lead_no_valido"
    cur = db.cursor()
    cur.execute("SELECT id, email, estado_lead, es_duplicado, nombre_club FROM clubes_crm WHERE id = ?", (lead_id,))
    lead = cur.fetchone()
    if not lead:
        return False, "lead_no_encontrado"
    if lead["estado_lead"] in ESTADOS_SUPRESION:
        return False, "supresion"
    if int(lead["es_duplicado"] or 0) == 1:
        return False, "duplicado"
    if not lead["email"] or "@" not in lead["email"]:
        return False, "email_invalido"
    if campaign_id > 0:
        cur.execute("SELECT entorno FROM pipelines WHERE id = ?", (campaign_id,))
        row = cur.fetchone()
        campana_test = es_campana_test(row["entorno"] if row else "test")
        lead_test = es_lead_test(lead["email"], lead["nombre_club"])
        if campana_test and not lead_test:
            return False, "lead_real_en_campana_test"
        if not campana_test and lead_test:
            return False, "lead_test_en_campana_no_test"
    return True, "elegible"

# ═══════════════════════════════════════════════════════════════════════════════
# MAIN
# ═══════════════════════════════════════════════════════════════════════════════

def main():
    print("=" * 90)
    print("MICROENVÍO VALIDACIÓN TRANSPORTE SMTP UNIFICADO (5 LEADS REALES)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Leads autorizados: {LEADS_AUTORIZADOS}")
    print(f"campaign_id: {CAMPAIGN_ID}")
    print(f"plantilla_id: {PLANTILLA_ID}")
    print()

    # ─────────────────────────────────────────────────────────────────────────
    # 1. LOGIN (cookie de sesión) — NO BLOQUEANTE
    # ─────────────────────────────────────────────────────────────────────────
    print("=" * 90)
    print("1. LOGIN (informativo — enviar_lote.php no requiere sesión)")
    print("=" * 90)
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    login_data = urllib.parse.urlencode({"password": AUTH_KEY}).encode()
    try:
        req = urllib.request.Request(BASE_URL + "/dashboard.php", data=login_data)
        with opener.open(req, timeout=30) as resp:
            print(f"  Login -> HTTP {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"  Login -> HTTP {e.code} (informativo, no bloquea)")
    except Exception as e:
        print(f"  Login -> [ERR] {e} (informativo, no bloquea)")


    # ─────────────────────────────────────────────────────────────────────────
    # 2. DESCARGAR BD DE PRODUCCIÓN
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("2. IDENTIDAD DE LA BD DE PRODUCCIÓN")
    print("=" * 90)

    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)

    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"  MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"  MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_micro_{int(time.time())}.db")
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
    # 3. INTEGRIDAD SQLITE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("3. INTEGRIDAD SQLITE")
    print("=" * 90)

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    integrity = cur.execute("PRAGMA integrity_check").fetchone()[0]
    fk_check = cur.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check: {integrity}")
    print(f"  foreign_key_check: {len(fk_check)} violaciones")

    if integrity != "ok" or len(fk_check) > 0:
        print("  [CRÍTICO] Integridad de BD comprometida. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # ─────────────────────────────────────────────────────────────────────────
    # 4. CONFIG / MODO ENTORNO / MOTOR
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("4. CONFIG / MODO ENTORNO / MOTOR")
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
    # 5. CONFIRMAR CAMPAÑA OBJETIVO
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("5. CONFIRMAR CAMPAÑA OBJETIVO (campaign_id=2)")
    print("=" * 90)

    cur.execute("SELECT * FROM pipelines WHERE id = ?", (CAMPAIGN_ID,))
    camp = cur.fetchone()
    if not camp:
        print(f"  [CRÍTICO] Pipeline {CAMPAIGN_ID} NO existe. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    camp_dict = dict(camp)
    entorno = camp_dict.get('entorno')
    estado = camp_dict.get('estado')
    activo = camp_dict.get('activo')
    nombre = camp_dict.get('nombre')
    print(f"  id: {camp_dict.get('id')}")
    print(f"  nombre: {nombre!r}")
    print(f"  entorno: {entorno!r}")
    print(f"  estado: {estado!r}")
    print(f"  activo: {activo!r}")
    print(f"  tipo: {camp_dict.get('tipo')!r}")

    condiciones_ok = True
    if (entorno or '').lower() != 'pilot':
        print(f"  [CRÍTICO] entorno={entorno!r} ≠ 'pilot'. STOP.")
        condiciones_ok = False
    if (estado or '').upper() != 'PILOT':
        print(f"  [CRÍTICO] estado={estado!r} ≠ 'PILOT'. STOP.")
        condiciones_ok = False
    if int(activo or 0) != 1:
        print(f"  [CRÍTICO] activo={activo!r} ≠ 1. STOP.")
        condiciones_ok = False
    if not condiciones_ok:
        db.close()
        ftp.quit()
        sys.exit(1)

    operable, razon = validar_campana_activa(estado, activo, entorno, modo_entorno)
    print(f"  validarCampanaActiva: operable={operable} ({razon})")
    if not operable:
        print(f"  [CRÍTICO] Campaña no operable. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Confirmar que pipeline 1 y 3 NO son candidatos
    cur.execute("SELECT id, nombre, entorno, estado, activo FROM pipelines ORDER BY id")
    print("\n  Todas las pipelines:")
    for p in cur.fetchall():
        print(f"    id={p['id']} nombre={p['nombre']!r} entorno={p['entorno']!r} estado={p['estado']!r} activo={p['activo']!r}")

    # ─────────────────────────────────────────────────────────────────────────
    # 6. PRECHECK DE LOS 5 LEADS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("6. PRECHECK DE LOS 5 LEADS AUTORIZADOS")
    print("=" * 90)

    leads_data = {}
    precheck_ok = True
    for lid in LEADS_AUTORIZADOS:
        cur.execute("SELECT id, nombre_club, email, federacion, persona_contacto, estado_lead, es_duplicado FROM clubes_crm WHERE id = ?", (lid,))
        lead = cur.fetchone()
        if not lead:
            print(f"  [CRÍTICO] lead {lid} NO existe. STOP.")
            precheck_ok = False
            continue

        ld = dict(lead)
        # Clasificar TEST/REAL
        is_test = es_lead_test(ld['email'], ld['nombre_club'])
        # Variante esperada
        variante_calc = asignar_variante(lid, CAMPAIGN_ID)
        variante_esp = VARIANTES_ESPERADAS[lid]
        # Elegibilidad
        elegible, razon_elig = es_elegible(db, lid, CAMPAIGN_ID)
        # Envío previo en campaña 2
        cur.execute("SELECT COUNT(*) FROM envios WHERE lead_id = ? AND campaign_id = ?", (lid, CAMPAIGN_ID))
        n_envios_camp2 = cur.fetchone()[0]

        print(f"  lead_id={lid} | {ld['nombre_club']} | {ld['email']}")
        print(f"    TEST/REAL: {'TEST' if is_test else 'REAL'}")
        print(f"    variante calculada: {variante_calc} (esperada: {variante_esp})")
        print(f"    elegible: {elegible} ({razon_elig})")
        print(f"    envíos previos en campaña 2: {n_envios_camp2}")

        if is_test:
            print(f"    [CRÍTICO] lead {lid} es TEST. STOP.")
            precheck_ok = False
        if variante_calc != variante_esp:
            print(f"    [CRÍTICO] variante {variante_calc} ≠ esperada {variante_esp}. STOP.")
            precheck_ok = False
        if not elegible:
            print(f"    [CRÍTICO] lead {lid} no elegible ({razon_elig}). STOP.")
            precheck_ok = False
        if n_envios_camp2 > 0:
            print(f"    [CRÍTICO] lead {lid} ya tiene envío en campaña 2. STOP.")
            precheck_ok = False

        leads_data[lid] = {
            'id': lid,
            'nombre_club': ld['nombre_club'],
            'email': ld['email'],
            'federacion': ld['federacion'],
            'persona_contacto': ld['persona_contacto'],
            'variante': variante_calc,
            'is_test': is_test,
        }

    if not precheck_ok:
        print("\n  [CRÍTICO] Precheck falló. STOP. NO se envió nada.")
        db.close()
        ftp.quit()
        sys.exit(1)
    print("\n  Precheck de los 5 leads: TODOS PASS ✓")

    # ─────────────────────────────────────────────────────────────────────────
    # 7. PLANTILLA A/B/C
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("7. PLANTILLA A/B/C (id=1)")
    print("=" * 90)

    cur.execute("SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo FROM plantillas WHERE id = ?", (PLANTILLA_ID,))
    plantilla = cur.fetchone()
    if not plantilla:
        print(f"  [CRÍTICO] Plantilla {PLANTILLA_ID} NO existe. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    p = dict(plantilla)
    print(f"  id: {p['id']}")
    print(f"  nombre: {p['nombre']!r}")
    print(f"  tipo: {p['tipo']!r}")
    print(f"  test_ab: {p['test_ab']!r}")

    # Verificar contenido de las 3 variantes
    if not p['asunto'] or not p['cuerpo']:
        print("  [CRÍTICO] Variante A sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    if not p['asunto_b'] or not p['cuerpo_b']:
        print("  [CRÍTICO] Variante B sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    if not p['asunto_c'] or not p['cuerpo_c']:
        print("  [CRÍTICO] Variante C sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    print("  Variantes A/B/C: TODAS con contenido ✓")

    # Verificar enlace de baja en las 3 variantes
    for var, cuerpo in [('A', p['cuerpo']), ('B', p['cuerpo_b']), ('C', p['cuerpo_c'])]:
        if 'baja.php' not in (cuerpo or ''):
            print(f"  [CRÍTICO] Enlace de baja no encontrado en variante {var}. STOP.")
            db.close()
            ftp.quit()
            sys.exit(1)
        print(f"  Variante {var}: enlace de baja presente ✓")

    # Verificar plantilla congelada
    cur.execute("""
        SELECT COUNT(*) FROM envios e
        JOIN pipelines p ON p.id = e.campaign_id
        WHERE e.plantilla_id = ? AND UPPER(p.estado) IN ('PILOT','ACTIVE')
    """, (PLANTILLA_ID,))
    n_congelada = cur.fetchone()[0]
    print(f"  Plantilla congelada (envíos en PILOT/ACTIVE): {n_congelada > 0} ({n_congelada} envíos)")

    # ─────────────────────────────────────────────────────────────────────────
    # 8. CUENTA SMTP ACTIVA
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("8. CUENTA SMTP ACTIVA")
    print("=" * 90)

    cur.execute("SELECT id, email, host, puerto, seguridad, activa, limite_diario, enviados_hoy FROM cuentas_smtp WHERE activa = 1 ORDER BY id ASC")
    cuentas = cur.fetchall()
    if not cuentas:
        print("  [CRÍTICO] No hay cuentas SMTP activas. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Seleccionar la primera cuenta activa con límite disponible
    smtp_id = None
    smtp_data = None
    for c in cuentas:
        if int(c['enviados_hoy'] or 0) < int(c['limite_diario'] or 0):
            smtp_id = c['id']
            smtp_data = dict(c)
            print(f"  Cuenta SMTP seleccionada: id={c['id']} email={c['email']} host={c['host']}:{c['puerto']} ({c['enviados_hoy']}/{c['limite_diario']})")
            break
    if smtp_id is None:
        print("  [CRÍTICO] Todas las cuentas SMTP saturadas. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Datos del remitente dinámico (espejo de enviar_lote.php líneas 177-183)
    sender_name = smtp_data.get('nombre_emisor') or ''
    sender_title = smtp_data.get('cargo_emisor') or ''
    sender_email = smtp_data.get('email') or ''
    if not sender_name:
        sender_name = (sender_email.split('@')[0] or '').capitalize()
    print(f"  Remitente: {sender_name} ({sender_title}) <{sender_email}>")


    # ─────────────────────────────────────────────────────────────────────────
    # 9. SIMULACIÓN DE LOS 5 EMAILS (sin enviar)
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("9. SIMULACIÓN DE LOS 5 EMAILS (sin enviar)")
    print("=" * 90)

    simulaciones = []
    for lid in LEADS_AUTORIZADOS:
        ld = leads_data[lid]
        var = ld['variante']
        # Resolver contenido por variante (espejo de resolverContenidoVariante)
        asunto_tpl = p['asunto']
        cuerpo_tpl = p['cuerpo']
        if int(p['test_ab'] or 0) == 1:
            if var == 'B':
                asunto_tpl = p['asunto_b'] if p['asunto_b'] else asunto_tpl
                cuerpo_tpl = p['cuerpo_b'] if p['cuerpo_b'] else cuerpo_tpl
            elif var == 'C':
                asunto_tpl = p['asunto_c'] if p['asunto_c'] else asunto_tpl
                cuerpo_tpl = p['cuerpo_c'] if p['cuerpo_c'] else cuerpo_tpl

        # Sustituir placeholders (espejo de enviar_lote.php líneas 185-197)
        contacto = ld['persona_contacto'] or 'responsable'
        replacements = {
            '{{CLUB}}': ld['nombre_club'],
            '{{CONTACTO}}': contacto,
            '{{FEDERACION}}': ld['federacion'] or '',
            '{{ANIO}}': str(datetime.now().year),
            '{{EMAIL}}': ld['email'],
            '{{SENDER_NAME}}': sender_name,
            '{{SENDER_TITLE}}': sender_title,
            '{{SENDER_EMAIL}}': sender_email,
        }

        asunto_final = asunto_tpl
        cuerpo_final = cuerpo_tpl
        for k, v in replacements.items():
            asunto_final = asunto_final.replace(k, v)
            cuerpo_final = cuerpo_final.replace(k, v)

        # Verificar que no quedan placeholders sin resolver
        placeholders_restantes = []
        for ph in ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}', '{{EMAIL}}', '{{SENDER_NAME}}', '{{SENDER_TITLE}}', '{{SENDER_EMAIL}}']:
            if ph in asunto_final or ph in cuerpo_final:
                placeholders_restantes.append(ph)

        simulaciones.append({
            'lead_id': lid,
            'club': ld['nombre_club'],
            'email': ld['email'],
            'campaign_id': CAMPAIGN_ID,
            'entorno': entorno,
            'es_test': 0,
            'variante': var,
            'asunto': asunto_final,
            'cuerpo': cuerpo_final,
            'placeholders_restantes': placeholders_restantes,
        })

        print(f"\n  --- Simulación lead {lid} ({ld['nombre_club']}) ---")
        print(f"    email: {ld['email']}")
        print(f"    campaign_id: {CAMPAIGN_ID}")
        print(f"    entorno: {entorno}")
        print(f"    es_test: 0")
        print(f"    variante: {var}")
        print(f"    asunto: {asunto_final}")
        print(f"    cuerpo (primeros 200 chars): {cuerpo_final[:200]!r}...")
        if placeholders_restantes:
            print(f"    [CRÍTICO] Placeholders sin resolver: {placeholders_restantes}. STOP.")
        else:
            print(f"    placeholders: NINGUNO sin resolver ✓")

    # Verificar que ninguna simulación tiene placeholders sin resolver
    sim_ok = True
    for s in simulaciones:
        if s['placeholders_restantes']:
            sim_ok = False
    if not sim_ok:
        print("\n  [CRÍTICO] Simulación detectó placeholders sin resolver. STOP. NO se envió nada.")
        db.close()
        ftp.quit()
        sys.exit(1)
    print("\n  Simulación de los 5 emails: TODOS PASS ✓ (sin placeholders sin resolver)")

    # ─────────────────────────────────────────────────────────────────────────
    # 10. BACKUP VERIFICABLE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("10. BACKUP VERIFICABLE")
    print("=" * 90)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_local = os.path.join(BACKUP_DIR, f"stats_db_microenvio_smtp_pre_{timestamp}.db")
    shutil.copy2(tmp, backup_local)
    backup_md5 = file_md5(backup_local)
    backup_sha256 = file_sha256(backup_local)
    print(f"  Backup local: {backup_local}")
    print(f"  Tamaño: {os.path.getsize(backup_local)} bytes")
    print(f"  MD5: {backup_md5}")
    print(f"  SHA-256: {backup_sha256}")

    backup_db = sqlite3.connect(backup_local)
    backup_integrity = backup_db.execute("PRAGMA integrity_check").fetchone()[0]
    backup_db.close()
    print(f"  integrity_check del backup: {backup_integrity}")

    if backup_integrity != "ok":
        print("  [CRÍTICO] Backup falló la verificación de integridad. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Subir backup remoto
    try:
        remote_backup_path = f"/getfutprotec.com/backups_deploy/stats_db_microenvio_smtp_pre_{timestamp}/stats.db"
        ftp.mkd(f"/getfutprotec.com/backups_deploy/stats_db_microenvio_smtp_pre_{timestamp}")
        with open(backup_local, "rb") as f:
            ftp.storbinary("STOR " + remote_backup_path, f)
        print(f"  Backup remoto: {remote_backup_path}")
    except Exception as e:
        print(f"  [WARN] No se pudo subir backup remoto: {e}")

    # ─────────────────────────────────────────────────────────────────────────
    # 11. ENVÍO CONTROLADO DE EXACTAMENTE 5 LEADS
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("11. ENVÍO CONTROLADO DE EXACTAMENTE 5 LEADS")
    print("=" * 90)
    print("  LÍMITE ABSOLUTO: máximo 5 emails. Si el sistema intenta un sexto: STOP.")

    resultados = []
    for lid in LEADS_AUTORIZADOS:
        ld = leads_data[lid]
        print(f"\n  --- Enviando lead {lid} ({ld['nombre_club']}) ---")
        print(f"    email destino: {ld['email']}")
        print(f"    campaign_id: {CAMPAIGN_ID}")
        print(f"    plantilla_id: {PLANTILLA_ID}")
        print(f"    smtp_id: {smtp_id}")

        # POST a enviar_lote.php
        post_data = urllib.parse.urlencode({
            'id_club': lid,
            'id_plantilla': PLANTILLA_ID,
            'id_cuenta_smtp': smtp_id,
            'campaign_id': CAMPAIGN_ID,
        }).encode()

        try:
            req = urllib.request.Request(BASE_URL + "/api/enviar_lote.php", data=post_data)
            with opener.open(req, timeout=60) as resp:
                body = resp.read().decode('utf-8', errors='replace')
                print(f"    HTTP {resp.status}")
                print(f"    Respuesta: {body}")
                try:
                    j = json.loads(body)
                except Exception:
                    j = {'ok': False, 'error': 'Respuesta no JSON: ' + body[:200]}
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace')
            print(f"    HTTP {e.code}")
            print(f"    Respuesta: {body}")
            try:
                j = json.loads(body)
            except Exception:
                j = {'ok': False, 'error': f'HTTP {e.code}: ' + body[:200]}
        except Exception as e:
            print(f"    [ERROR] {e}")
            j = {'ok': False, 'error': str(e)}

        resultados.append({
            'lead_id': lid,
            'club': ld['nombre_club'],
            'email': ld['email'],
            'variante': ld['variante'],
            'respuesta': j,
        })

        # Si el envío falla, STOP (no continuar con los siguientes)
        if not j.get('ok', False):
            print(f"    [CRÍTICO] Envío del lead {lid} falló. STOP.")
            db.close()
            ftp.quit()
            sys.exit(1)

        # Pausa de seguridad entre envíos (throttling)
        time.sleep(3)

    # ─────────────────────────────────────────────────────────────────────────
    # 12. POSTCHECK (verificar los 5 envíos en la BD)
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("12. POSTCHECK (verificar los 5 envíos en la BD)")
    print("=" * 90)

    # Re-descargar la BD para verificar los envíos
    time.sleep(2)
    tmp_post = os.path.join(tempfile.gettempdir(), f"futprotec_micro_post_{int(time.time())}.db")
    with open(tmp_post, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)

    db_post = sqlite3.connect(tmp_post)
    db_post.row_factory = sqlite3.Row
    cur_post = db_post.cursor()

    post_ok = True
    for lid in LEADS_AUTORIZADOS:
        cur_post.execute("SELECT id, lead_id, email, campaign_id, plantilla_id, variante, es_test, message_id, estado, fecha_envio FROM envios WHERE lead_id = ? AND campaign_id = ? ORDER BY id DESC LIMIT 1", (lid, CAMPAIGN_ID))
        envio = cur_post.fetchone()
        if not envio:
            print(f"  [CRÍTICO] lead {lid}: NO se encontró envío en campaña 2. STOP.")
            post_ok = False
            continue

        e = dict(envio)
        print(f"  lead {lid}: envio_id={e['id']} email={e['email']} variante={e['variante']} es_test={e['es_test']} estado={e['estado']} msg={e['message_id']} fecha={e['fecha_envio']}")

        # Verificar variante esperada
        if e['variante'] != VARIANTES_ESPERADAS[lid]:
            print(f"    [CRÍTICO] variante {e['variante']} ≠ esperada {VARIANTES_ESPERADAS[lid]}. STOP.")
            post_ok = False
        # Verificar es_test = 0
        if int(e['es_test'] or 0) != 0:
            print(f"    [CRÍTICO] es_test={e['es_test']} ≠ 0. STOP.")
            post_ok = False
        # Verificar message_id presente
        if not e['message_id']:
            print(f"    [CRÍTICO] message_id vacío. STOP.")
            post_ok = False
        # Verificar plantilla
        if int(e['plantilla_id'] or 0) != PLANTILLA_ID:
            print(f"    [CRÍTICO] plantilla_id={e['plantilla_id']} ≠ {PLANTILLA_ID}. STOP.")
            post_ok = False

    # Verificar que NO se envió ningún lead fuera de los autorizados
    cur_post.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ? AND lead_id NOT IN (?,?,?,?,?)", (CAMPAIGN_ID, *LEADS_AUTORIZADOS))
    n_no_autorizados = cur_post.fetchone()[0]
    print(f"\n  Envíos en campaña 2 fuera de los 5 autorizados: {n_no_autorizados}")
    if n_no_autorizados > 0:
        print("  [CRÍTICO] Se detectaron envíos fuera de los autorizados. STOP.")
        post_ok = False

    # Verificar integridad post-envío
    integrity_post = cur_post.execute("PRAGMA integrity_check").fetchone()[0]
    fk_post = cur_post.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check post: {integrity_post}")
    print(f"  foreign_key_check post: {len(fk_post)} violaciones")
    if integrity_post != "ok" or len(fk_post) > 0:
        print("  [CRÍTICO] Integridad post-envío comprometida. STOP.")
        post_ok = False

    if not post_ok:
        print("\n  [CRÍTICO] Postcheck falló. Revisar estado de la BD.")
        db_post.close()
        db.close()
        ftp.quit()
        sys.exit(1)
    print("\n  Postcheck: TODOS PASS ✓")

    # ─────────────────────────────────────────────────────────────────────────
    # 13. GENERAR CHECKPOINT
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("13. GENERAR CHECKPOINT")
    print("=" * 90)

    docs_dir = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "docs"))
    os.makedirs(docs_dir, exist_ok=True)
    checkpoint_path = os.path.join(docs_dir, f"checkpoint_microenvio_validacion_smtp_unificado_{timestamp}.md")

    # Resumen de resultados
    resumen = []
    for r in resultados:
        ok = r['respuesta'].get('ok', False)
        msg = r['respuesta'].get('message') or r['respuesta'].get('error') or r['respuesta'].get('mensaje') or ''
        resumen.append(f"- lead {r['lead_id']} ({r['club']}) [{r['email']}] variante={r['variante']} -> {'OK' if ok else 'FALLO'}: {msg}")

    checkpoint = f"""# Checkpoint: Microenvío Validación Transporte SMTP Unificado

**Fecha:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
**Script:** scripts/microenvio_validacion_smtp_unificado.py

## Objetivo
Validar en producción el transporte SMTP centralizado (`futprotec_enviarSMTP` en `inc/smtp_transport.php`)
tras el refactor y deploy del 2026-08-22. Los envíos previos de campaña 2 (18/08) fueron ANTES del refactor.

## Leads autorizados (5 REALES, sin envío previo en campaña 2)
- lead 3: AGRUPACION DEPORTIVA AZARBE (asociaciondeportivaazarbe@gmail.com) variante B
- lead 4: AGUILAS F.C. (info@aguilasfc.es) variante B
- lead 5: ALGEZARES UNION DEPORTIVA (asnape59@gmail.com) variante B
- lead 8: ASOCIACION DEPORTIVA GUADALUPE VETERANOS (alegriasoler100@hotmail.com) variante C
- lead 9: ASOCIACION DEPORTIVA LORQUI (adlorqui2024@gmail.com) variante B

## Configuración
- campaign_id: {CAMPAIGN_ID}
- plantilla_id: {PLANTILLA_ID}
- smtp_id: {smtp_id}
- modo_entorno: {modo_entorno}
- motor_estado: {motor_estado}

## Resultados de envío
{chr(10).join(resumen)}

## Postcheck
- Envíos verificados en BD: {len(LEADS_AUTORIZADOS)}/5
- Envíos fuera de autorizados: {n_no_autorizados}
- integrity_check post: {integrity_post}
- foreign_key_check post: {len(fk_post)} violaciones

## Backups
- Local: {backup_local} (MD5 {backup_md5}, SHA-256 {backup_sha256})
- Remoto: {remote_backup_path if 'remote_backup_path' in dir() else 'N/A'}

## Estado
- [x] Precheck de los 5 leads PASS
- [x] Simulación de los 5 emails PASS
- [x] Envío controlado de exactamente 5 leads
- [x] Postcheck PASS
- [ ] Confirmación de entrega en buzones (manual)
"""
    with open(checkpoint_path, "w", encoding="utf-8") as f:
        f.write(checkpoint)
    print(f"  Checkpoint generado: {checkpoint_path}")

    # ─────────────────────────────────────────────────────────────────────────
    # 14. CIERRE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("14. CIERRE")
    print("=" * 90)
    print("  Microenvío de validación del transporte SMTP unificado COMPLETADO.")
    print(f"  Leads enviados: {len(LEADS_AUTORIZADOS)}")
    print(f"  Checkpoint: {checkpoint_path}")
    print("  IMPORTANTE: Confirmar manualmente la entrega en los 5 buzones destino.")
    print("  El motor permanece PAUSADO. No se ha lanzado campaña masiva.")

    db_post.close()
    db.close()
    ftp.quit()
    print("\n  FIN ✓")


if __name__ == "__main__":
    main()


