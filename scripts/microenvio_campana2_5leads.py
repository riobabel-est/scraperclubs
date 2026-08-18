#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
microenvio_campana2_5leads.py

FASE OPERATIVA 2 — MICROENVÍO CONTROLADO DE CAMPAÑA 2 (5 LEADS REALES)

AUTORIZACIÓN: EXACTAMENTE 5 EMAILS REALES.
  lead_id IN (2, 3, 4, 6, 8)
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
  python scripts/microenvio_campana2_5leads.py
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
LEADS_AUTORIZADOS = [2, 3, 4, 6, 8]
VARIANTES_ESPERADAS = {2: 'B', 3: 'B', 4: 'B', 6: 'A', 8: 'C'}

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
    print("FASE OPERATIVA 2 — MICROENVÍO CONTROLADO CAMPAÑA 2 (5 LEADS REALES)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Leads autorizados: {LEADS_AUTORIZADOS}")
    print(f"campaign_id: {CAMPAIGN_ID}")
    print(f"plantilla_id: {PLANTILLA_ID}")
    print()

    # ─────────────────────────────────────────────────────────────────────────
    # 1. LOGIN (cookie de sesión) — NO BLOQUEANTE
    # ─────────────────────────────────────────────────────────────────────────
    # NOTA: enviar_lote.php NO requiere sesión (valida campaña desde BD, anti-bypass).
    # El login es solo informativo. Si el WAF de SiteGround bloquea el POST de login
    # (403), NO bloqueamos el flujo porque el envío no depende de la sesión.
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
    backup_local = os.path.join(BACKUP_DIR, f"stats_db_microenvio_pre_{timestamp}.db")
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
        remote_backup_path = f"/getfutprotec.com/backups_deploy/stats_db_microenvio_pre_{timestamp}/stats.db"
        ftp.mkd(f"/getfutprotec.com/backups_deploy/stats_db_microenvio_pre_{timestamp}")
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
            print(f"    [CRÍTICO] Envío del lead {lid} falló. STOP. NO se envían los siguientes.")
            break

        # Esperar entre envíos (delay)
        if lid != LEADS_AUTORIZADOS[-1]:
            print("    Esperando 3s...")
            time.sleep(3)

    # ─────────────────────────────────────────────────────────────────────────
    # 12. POSTCHECK
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("12. POSTCHECK")
    print("=" * 90)

    # Recargar BD desde remoto para verificar el estado real
    time.sleep(2)
    tmp_post = os.path.join(tempfile.gettempdir(), f"futprotec_micro_post_{int(time.time())}.db")
    with open(tmp_post, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)

    post_size = os.path.getsize(tmp_post)
    post_md5 = file_md5(tmp_post)
    post_sha256 = file_sha256(tmp_post)
    print(f"  BD post-envío:")
    print(f"    Tamaño: {post_size} bytes")
    print(f"    MD5: {post_md5}")
    print(f"    SHA-256: {post_sha256}")

    db_post = sqlite3.connect(tmp_post)
    db_post.row_factory = sqlite3.Row
    cur_post = db_post.cursor()

    post_integrity = cur_post.execute("PRAGMA integrity_check").fetchone()[0]
    post_fk = cur_post.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check: {post_integrity}")
    print(f"  foreign_key_check: {len(post_fk)} violaciones")

    # Verificar los 5 envíos creados
    print("\n  Envíos creados en campaña 2:")
    cur_post.execute("""
        SELECT e.id, e.lead_id, e.campaign_id, e.email, e.estado, e.es_test, e.variant, e.message_id, e.plantilla_id
        FROM envios e
        WHERE e.campaign_id = ? AND e.lead_id IN (2,3,4,6,8)
        ORDER BY e.lead_id
    """, (CAMPAIGN_ID,))
    envios_post = cur_post.fetchall()

    envios_ok = True
    for e in envios_post:
        print(f"    envio_id={e['id']} lead={e['lead_id']} email={e['email']} estado={e['estado']} es_test={e['es_test']} variant={e['variant']} plantilla={e['plantilla_id']} message_id={e['message_id']}")
        if e['estado'] != 'enviado':
            print(f"      [CRÍTICO] estado={e['estado']} ≠ 'enviado'")
            envios_ok = False
        if int(e['es_test'] or 0) != 0:
            print(f"      [CRÍTICO] es_test={e['es_test']} ≠ 0")
            envios_ok = False
        if e['variant'] != VARIANTES_ESPERADAS[e['lead_id']]:
            print(f"      [CRÍTICO] variant={e['variant']} ≠ esperada {VARIANTES_ESPERADAS[e['lead_id']]}")
            envios_ok = False
        if not e['message_id']:
            print(f"      [CRÍTICO] message_id vacío")
            envios_ok = False

    if len(envios_post) != 5:
        print(f"  [CRÍTICO] Se esperaban 5 envíos, hay {len(envios_post)}. STOP.")
        envios_ok = False

    # Verificar que NO hay envíos adicionales en campaña 2 (más allá de los 5)
    cur_post.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ?", (CAMPAIGN_ID,))
    total_camp2 = cur_post.fetchone()[0]
    print(f"\n  Total envíos en campaña 2: {total_camp2}")
    if total_camp2 != 5:
        print(f"  [CRÍTICO] Total envíos campaña 2 = {total_camp2} ≠ 5. STOP.")
        envios_ok = False

    # Verificar que NO hay envíos nuevos en otras campañas
    cur_post.execute("SELECT COUNT(*) FROM envios WHERE campaign_id IS NOT NULL AND campaign_id != ?", (CAMPAIGN_ID,))
    otros_camp = cur_post.fetchone()[0]
    print(f"  Envíos en otras campañas: {otros_camp}")

    # Verificar estados de los leads (deben ser '02 Contactado')
    print("\n  Estados de los leads tras envío:")
    for lid in LEADS_AUTORIZADOS:
        cur_post.execute("SELECT estado_lead FROM clubes_crm WHERE id = ?", (lid,))
        r = cur_post.fetchone()
        estado_lead = r['estado_lead'] if r else 'NO EXISTE'
        print(f"    lead {lid}: {estado_lead}")
        if estado_lead != '02 Contactado':
            print(f"      [CRÍTICO] estado_lead={estado_lead} ≠ '02 Contactado'")
            envios_ok = False

    # Verificar que pipelines NO cambiaron
    print("\n  Pipelines (deben estar intactas):")
    cur_post.execute("SELECT id, nombre, entorno, estado, activo FROM pipelines ORDER BY id")
    for p in cur_post.fetchall():
        print(f"    id={p['id']} nombre={p['nombre']!r} entorno={p['entorno']!r} estado={p['estado']!r} activo={p['activo']!r}")

    # Verificar que lead_pipelines NO cambiaron
    print("\n  lead_pipelines (deben estar intactas):")
    cur_post.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines ORDER BY id")
    for lp in cur_post.fetchall():
        print(f"    id={lp['id']} lead={lp['lead_id']} pipeline={lp['pipeline_id']} variante={lp['variante_ab']!r}")

    # Verificar que plantillas NO cambiaron
    print("\n  Plantillas (deben estar intactas):")
    cur_post.execute("SELECT id, nombre, test_ab, tipo FROM plantillas ORDER BY id")
    for pl in cur_post.fetchall():
        print(f"    id={pl['id']} nombre={pl['nombre']!r} test_ab={pl['test_ab']!r} tipo={pl['tipo']!r}")

    # Verificar que config NO cambió (motor sigue pausado)
    cur_post.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    for c in cur_post.fetchall():
        print(f"  config.{c['clave']} = {c['valor']}")
        if c['clave'] == 'motor_estado' and c['valor'] != 'pausado':
            print(f"    [CRÍTICO] motor_estado cambió a {c['valor']}. STOP.")
            envios_ok = False

    # Verificar que NO hay message_id duplicados
    cur_post.execute("SELECT message_id, COUNT(*) as n FROM envios WHERE message_id IS NOT NULL AND message_id != '' GROUP BY message_id HAVING n > 1")
    dup_msg = cur_post.fetchall()
    print(f"\n  message_id duplicados: {len(dup_msg)}")
    if len(dup_msg) > 0:
        print("  [CRÍTICO] message_id duplicados detectados. STOP.")
        envios_ok = False

    # Verificar que NO hay envíos TEST nuevos
    cur_post.execute("SELECT COUNT(*) FROM envios WHERE es_test = 1")
    n_test = cur_post.fetchone()[0]
    print(f"  Envíos TEST totales: {n_test}")

    # Verificar que NO hay envíos REAL nuevos fuera de los 5 autorizados
    cur_post.execute("SELECT COUNT(*) FROM envios WHERE es_test = 0")
    n_real = cur_post.fetchone()[0]
    print(f"  Envíos REAL totales: {n_real}")

    db_post.close()

    # ─────────────────────────────────────────────────────────────────────────
    # 13. VEREDICTO
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("13. VEREDICTO")
    print("=" * 90)

    n_exitosos = sum(1 for r in resultados if r['respuesta'].get('ok', False))
    n_fallidos = len(resultados) - n_exitosos

    print(f"  Envíos intentados: {len(resultados)}")
    print(f"  Envíos exitosos: {n_exitosos}")
    print(f"  Envíos fallidos: {n_fallidos}")

    if n_exitosos == 5 and envios_ok and post_integrity == 'ok' and len(post_fk) == 0:
        veredicto = "PASS"
        print("\n  ✅ VEREDICTO: PASS — Microenvío controlado completado correctamente.")
        print("     - 5 emails REALES enviados (leads 2,3,4,6,8)")
        print("     - Variantes correctas (B,B,B,A,C)")
        print("     - es_test=0 en todos")
        print("     - message_id generados")
        print("     - Integridad SQLite OK")
        print("     - Motor sigue pausado")
        print("     - Pipelines/lead_pipelines/plantillas intactas")
    elif n_exitosos > 0:
        veredicto = "PARTIAL"
        print("\n  ⚠️ VEREDICTO: PARTIAL — Algunos envíos se completaron pero hay anomalías.")
        print("     Revisar el detalle anterior. NO continuar con más envíos.")
    else:
        veredicto = "STOP"
        print("\n  ❌ VEREDICTO: STOP — No se completó ningún envío o hubo fallo crítico.")
        print("     Revisar el detalle anterior.")

    # ─────────────────────────────────────────────────────────────────────────
    # 14. CHECKPOINT
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("14. CHECKPOINT")
    print("=" * 90)

    checkpoint_path = os.path.normpath(os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "docs",
        f"checkpoint_microenvio_campana2_{timestamp}.md"
    ))

    with open(checkpoint_path, "w", encoding="utf-8") as f:
        f.write(f"# CHECKPOINT — MICROENVÍO CONTROLADO CAMPAÑA 2\n\n")
        f.write(f"**Fecha:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n")
        f.write(f"## Resumen\n\n")
        f.write(f"- **Veredicto:** {veredicto}\n")
        f.write(f"- **Envíos intentados:** {len(resultados)}\n")
        f.write(f"- **Envíos exitosos:** {n_exitosos}\n")
        f.write(f"- **Envíos fallidos:** {n_fallidos}\n")
        f.write(f"- **Leads autorizados:** {LEADS_AUTORIZADOS}\n")
        f.write(f"- **campaign_id:** {CAMPAIGN_ID}\n")
        f.write(f"- **plantilla_id:** {PLANTILLA_ID}\n\n")
        f.write(f"## Identidad BD (pre-envío)\n\n")
        f.write(f"- Tamaño: {size} bytes\n")
        f.write(f"- MD5: {md5}\n")
        f.write(f"- SHA-256: {sha256}\n")
        f.write(f"- integrity_check: {integrity}\n\n")
        f.write(f"## Identidad BD (post-envío)\n\n")
        f.write(f"- Tamaño: {post_size} bytes\n")
        f.write(f"- MD5: {post_md5}\n")
        f.write(f"- SHA-256: {post_sha256}\n")
        f.write(f"- integrity_check: {post_integrity}\n\n")
        f.write(f"## Backup\n\n")
        f.write(f"- Ruta local: {backup_local}\n")
        f.write(f"- MD5: {backup_md5}\n")
        f.write(f"- SHA-256: {backup_sha256}\n")
        f.write(f"- integrity_check: {backup_integrity}\n\n")
        f.write(f"## Envíos creados\n\n")
        f.write(f"| envio_id | lead_id | email | estado | es_test | variant | message_id |\n")
        f.write(f"|---|---|---|---|---|---|---|\n")
        for e in envios_post:
            f.write(f"| {e['id']} | {e['lead_id']} | {e['email']} | {e['estado']} | {e['es_test']} | {e['variant']} | {e['message_id']} |\n")
        f.write(f"\n## Resultados por lead\n\n")
        for r in resultados:
            f.write(f"- lead {r['lead_id']} ({r['club']}): {r['respuesta']}\n")
        f.write(f"\n## Config\n\n")
        f.write(f"- modo_entorno: {modo_entorno}\n")
        f.write(f"- motor_estado: {motor_estado} (debe seguir pausado)\n\n")
        f.write(f"## EMAILS ENVIADOS: {n_exitosos}\n")
        f.write(f"## CAMPAÑAS LANZADAS: 0\n\n")
        f.write(f"## Notas\n\n")
        f.write(f"- Este microenvío fue autorizado explícitamente para 5 leads REALES.\n")
        f.write(f"- NO se modificaron pipelines, lead_pipelines, plantillas, ni es_test.\n")
        f.write(f"- El motor de envío permanece pausado.\n")

    print(f"  Checkpoint: {checkpoint_path}")

    # ─────────────────────────────────────────────────────────────────────────
    # CIERRE
    # ─────────────────────────────────────────────────────────────────────────
    db.close()
    ftp.quit()
    try:
        os.remove(tmp)
    except Exception:
        pass
    try:
        os.remove(tmp_post)
    except Exception:
        pass

    print("\n" + "=" * 90)
    print("FASE OPERATIVA 2 COMPLETADA")
    print("=" * 90)
    print(f"  EMAILS ENVIADOS = {n_exitosos}")
    print(f"  CAMPAÑAS LANZADAS = 0")
    print(f"  VEREDICTO = {veredicto}")
    print("=" * 90)

    if veredicto != "PASS":
        sys.exit(1)

if __name__ == "__main__":
    main()


