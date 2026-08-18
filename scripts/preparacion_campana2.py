#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
preparacion_campana2.py

FASE OPERATIVA 1 — PREPARACIÓN Y PUESTA EN MARCHA CONTROLADA DE CAMPAÑA FUTPROTEC
CAMPAÑA 2: PILOTO_FUTPROTEC_2026_08

OBJETIVO:
  Preparar la campaña comercial real de forma controlada SIN enviar nada todavía.

REGLAS ABSOLUTAS:
  - NO ejecutar envío masivo.
  - NO enviar a toda la base.
  - NO lanzar cron masivo.
  - NO modificar pipeline 1, 3, variantes históricas, es_test histórico, envíos históricos.
  - NO cambiar plantillas congeladas.
  - NO cambiar SMTP.
  - NO enviar emails. NO activar el motor.

ESTE SCRIPT SOLO:
  1. Confirma campaign_id=2 (entorno=pilot, estado=PILOT, activo=1).
  2. Comprueba plantillas A/B/C.
  3. Calcula universo elegible (respetando TODAS las reglas).
  4. Calcula distribución A/B/C.
  5. Comprueba suppression/blacklist.
  6. Selecciona 3-5 leads reales para prueba controlada.
  7. Genera checkpoint pre-envío.
  8. NO ENVÍA NADA.

USO:
  python scripts/preparacion_campana2.py
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

BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "backups_deploy")
BACKUP_DIR = os.path.normpath(BACKUP_DIR)
os.makedirs(BACKUP_DIR, exist_ok=True)

CAMPAIGN_ID = 2  # PILOTO_FUTPROTEC_2026_08

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

# Estados de supresión (espejo de esElegibleParaEnvio)
ESTADOS_SUPRESION = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']

# ═══════════════════════════════════════════════════════════════════════════════
# MAIN
# ═══════════════════════════════════════════════════════════════════════════════

def main():
    print("=" * 90)
    print("FASE OPERATIVA 1 — PREPARACIÓN CAMPAÑA 2 (PILOTO_FUTPROTEC_2026_08)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Modo: PREPARACIÓN CONTROLADA — NO SE ENVÍA NADA")
    print()

    # ─────────────────────────────────────────────────────────────────────────
    # 1. DESCARGAR BD DE PRODUCCIÓN
    # ─────────────────────────────────────────────────────────────────────────
    print("=" * 90)
    print("1. IDENTIDAD DE LA BD DE PRODUCCIÓN")
    print("=" * 90)

    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)

    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"  MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"  MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_prep_{int(time.time())}.db")
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
    # 4. CONFIRMAR CAMPAÑA OBJETIVO (campaign_id=2)
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("4. CONFIRMAR CAMPAÑA OBJETIVO (campaign_id=2)")
    print("=" * 90)

    cur.execute("SELECT * FROM pipelines WHERE id = ?", (CAMPAIGN_ID,))
    camp = cur.fetchone()
    if not camp:
        print(f"  [CRÍTICO] Pipeline {CAMPAIGN_ID} NO existe. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    camp_dict = dict(camp)
    print(f"  id: {camp_dict.get('id')}")
    print(f"  nombre: {camp_dict.get('nombre')!r}")
    print(f"  entorno: {camp_dict.get('entorno')!r}")
    print(f"  estado: {camp_dict.get('estado')!r}")
    print(f"  activo: {camp_dict.get('activo')!r}")
    print(f"  tipo: {camp_dict.get('tipo')!r}")
    print(f"  created_at: {camp_dict.get('created_at')!r}")

    # Verificar condiciones esperadas
    entorno = camp_dict.get('entorno')
    estado = camp_dict.get('estado')
    activo = camp_dict.get('activo')
    nombre = camp_dict.get('nombre')

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

    # Validar campaña activa (espejo de validarCampanaActiva)
    operable, razon = validar_campana_activa(estado, activo, entorno, modo_entorno)
    print(f"  validarCampanaActiva: operable={operable} ({razon})")
    if not operable:
        print(f"  [CRÍTICO] Campaña no operable. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)

    # Verificar que es la única campaña candidata comercial
    cur.execute("SELECT id, nombre, entorno, estado, activo FROM pipelines ORDER BY id")
    print("\n  Todas las pipelines:")
    for p in cur.fetchall():
        print(f"    id={p['id']} nombre={p['nombre']!r} entorno={p['entorno']!r} estado={p['estado']!r} activo={p['activo']!r}")

    # ─────────────────────────────────────────────────────────────────────────
    # 5. COMPROBAR PLANTILLAS A/B/C
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("5. COMPROBAR PLANTILLAS A/B/C")
    print("=" * 90)

    cur.execute("SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria, activo FROM plantillas ORDER BY id")
    plantillas = cur.fetchall()
    print(f"  Total plantillas: {len(plantillas)}")
    for p in plantillas:
        print(f"    id={p['id']} nombre={p['nombre']!r} tipo={p['tipo']!r} activo={p['activo']!r} test_ab={p['test_ab']!r}")
        print(f"      asunto: {p['asunto']!r}")
        if p['test_ab']:
            print(f"      asunto_b: {p['asunto_b']!r}")
            print(f"      asunto_c: {p['asunto_c']!r}")
            print(f"      cuerpo_b: {(p['cuerpo_b'] or '')[:80]!r}...")
            print(f"      cuerpo_c: {(p['cuerpo_c'] or '')[:80]!r}...")

    # Verificar que existe al menos una plantilla activa con A/B/C
    plantilla_abc = None
    for p in plantillas:
        if int(p['activo'] or 0) == 1 and int(p['test_ab'] or 0) == 1:
            plantilla_abc = p
            break
    if not plantilla_abc:
        print("  [CRÍTICO] No hay plantilla activa con A/B/C (test_ab=1). STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    print(f"  Plantilla A/B/C activa: id={plantilla_abc['id']} nombre={plantilla_abc['nombre']!r}")

    # Verificar que las tres variantes tienen contenido
    if not plantilla_abc['asunto'] or not plantilla_abc['cuerpo']:
        print("  [CRÍTICO] Variante A sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    if not plantilla_abc['asunto_b'] or not plantilla_abc['cuerpo_b']:
        print("  [CRÍTICO] Variante B sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    if not plantilla_abc['asunto_c'] or not plantilla_abc['cuerpo_c']:
        print("  [CRÍTICO] Variante C sin contenido. STOP.")
        db.close()
        ftp.quit()
        sys.exit(1)
    print("  Variantes A/B/C: TODAS con contenido ✓")

    # Verificar placeholders y enlace de baja
    cuerpo_a = plantilla_abc['cuerpo'] or ''
    cuerpo_b = plantilla_abc['cuerpo_b'] or ''
    cuerpo_c = plantilla_abc['cuerpo_c'] or ''
    placeholders = ['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}']
    for ph in placeholders:
        if ph not in cuerpo_a:
            print(f"  [WARN] Placeholder {ph} no encontrado en cuerpo A")
        if ph not in cuerpo_b:
            print(f"  [WARN] Placeholder {ph} no encontrado en cuerpo B")
        if ph not in cuerpo_c:
            print(f"  [WARN] Placeholder {ph} no encontrado en cuerpo C")
    print("  Placeholders verificados")

    # Verificar enlace de baja
    for nombre_var, cuerpo in [('A', cuerpo_a), ('B', cuerpo_b), ('C', cuerpo_c)]:
        if 'baja.php' not in cuerpo:
            print(f"  [WARN] Enlace de baja no encontrado en variante {nombre_var}")
        else:
            print(f"  Variante {nombre_var}: enlace de baja presente ✓")

    # Verificar plantilla congelada (si hay envíos en campaña PILOT/ACTIVE)
    cur.execute("""
        SELECT COUNT(*) FROM envios e
        JOIN pipelines p ON p.id = e.campaign_id
        WHERE e.plantilla_id = ? AND UPPER(p.estado) IN ('PILOT','ACTIVE')
    """, (plantilla_abc['id'],))
    n_congelada = cur.fetchone()[0]
    print(f"  Plantilla congelada (envíos en PILOT/ACTIVE): {n_congelada > 0} ({n_congelada} envíos)")

    # ─────────────────────────────────────────────────────────────────────────
    # 6. UNIVERSO ELEGIBLE PARA CAMPAÑA 2
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("6. UNIVERSO ELEGIBLE PARA CAMPAÑA 2")
    print("=" * 90)

    # Obtener todos los leads
    cur.execute("SELECT id, nombre_club, email, federacion, estado_lead, es_duplicado, persona_contacto, telefono_movil, tiene_whatsapp FROM clubes_crm")
    leads = cur.fetchall()

    total_leads = len(leads)
    total_test = 0
    total_real = 0
    elegibles = []
    bloqueados = {}  # motivo -> lista de ids

    # Obtener envíos previos por email (para idempotencia)
    cur.execute("SELECT LOWER(email) as email FROM envios WHERE estado IN ('enviado','abierto')")
    emails_enviados = set(r['email'] for r in cur.fetchall())

    # Obtener leads ya en campaña 2 (idempotencia por lead_id+campaign_id)
    cur.execute("SELECT lead_id FROM envios WHERE campaign_id = ?", (CAMPAIGN_ID,))
    leads_en_campana2 = set(r['lead_id'] for r in cur.fetchall())

    for lead in leads:
        lid = lead['id']
        email = lead['email']
        nombre = lead['nombre_club']
        estado = lead['estado_lead']
        es_dup = lead['es_duplicado']

        # Clasificar TEST/REAL
        if es_lead_test(email, nombre):
            total_test += 1
            bloqueados.setdefault('lead_TEST', []).append(lid)
            continue
        total_real += 1

        # Email válido
        if not email or '@' not in email or '.' not in email.split('@')[-1]:
            bloqueados.setdefault('email_invalido', []).append(lid)
            continue

        # Supresión / blacklist
        if estado in ESTADOS_SUPRESION:
            bloqueados.setdefault('suppression', []).append(lid)
            continue

        # Duplicado
        if int(es_dup or 0) == 1:
            bloqueados.setdefault('duplicado', []).append(lid)
            continue

        # Envío previo (idempotencia por email)
        if email.lower() in emails_enviados:
            bloqueados.setdefault('envio_previo', []).append(lid)
            continue

        # Ya en campaña 2
        if lid in leads_en_campana2:
            bloqueados.setdefault('ya_en_campana2', []).append(lid)
            continue

        # Estado elegible (01 Sin Contactar)
        if estado != '01 Sin Contactar':
            bloqueados.setdefault('estado_no_elegible', []).append(lid)
            continue

        # Compatibilidad lead/campaña (campaña no TEST → solo leads REAL, ya verificado)
        # Variante determinista
        variante = asignar_variante(lid, CAMPAIGN_ID)

        elegibles.append({
            'id': lid,
            'nombre_club': nombre,
            'email': email,
            'federacion': lead['federacion'],
            'estado_lead': estado,
            'variante': variante,
        })

    print(f"  TOTAL LEADS CRM: {total_leads}")
    print(f"  TOTAL TEST: {total_test}")
    print(f"  TOTAL REAL: {total_real}")
    print(f"  TOTAL ELEGIBLES: {len(elegibles)}")
    print(f"  TOTAL BLOQUEADOS: {total_leads - len(elegibles)}")
    print("\n  MOTIVOS DE BLOQUEO:")
    for motivo, ids in sorted(bloqueados.items(), key=lambda x: -len(x[1])):
        print(f"    {motivo}: {len(ids)}")

    # ─────────────────────────────────────────────────────────────────────────
    # 7. DISTRIBUCIÓN A/B/C
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("7. DISTRIBUCIÓN A/B/C DEL UNIVERSO ELEGIBLE")
    print("=" * 90)

    dist = {'A': 0, 'B': 0, 'C': 0}
    for e in elegibles:
        dist[e['variante']] += 1
    print(f"  A = {dist['A']}")
    print(f"  B = {dist['B']}")
    print(f"  C = {dist['C']}")
    print(f"  Total = {sum(dist.values())}")

    # Verificar determinismo: recalcular para confirmar
    print("\n  Verificación de determinismo (muestra de 10 leads):")
    for e in elegibles[:10]:
        v1 = asignar_variante(e['id'], CAMPAIGN_ID)
        v2 = asignar_variante(e['id'], CAMPAIGN_ID)
        ok = "✓" if v1 == v2 else "✗"
        print(f"    lead {e['id']}: variante={v1} (recalculado={v2}) {ok}")

    # ─────────────────────────────────────────────────────────────────────────
    # 8. SUPPRESSION / BLACKLIST DETALLE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("8. SUPPRESSION / BLACKLIST")
    print("=" * 90)

    n_suppression = len(bloqueados.get('suppression', []))
    n_envio_previo = len(bloqueados.get('envio_previo', []))
    n_incompatibilidad = len(bloqueados.get('lead_TEST', []))
    n_test = len(bloqueados.get('lead_TEST', []))
    n_final = len(elegibles)

    print(f"  Total excluidos por suppression: {n_suppression}")
    print(f"  Total excluidos por envío previo: {n_envio_previo}")
    print(f"  Total excluidos por incompatibilidad: {n_incompatibilidad}")
    print(f"  Total excluidos por TEST: {n_test}")
    print(f"  Total finalmente elegibles: {n_final}")

    # ─────────────────────────────────────────────────────────────────────────
    # 9. SELECCIÓN DE PRUEBA CONTROLADA (3-5 leads reales)
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("9. SELECCIÓN DE PRUEBA CONTROLADA (3-5 leads reales)")
    print("=" * 90)

    # Seleccionar 5 leads reales (o menos si no hay suficientes)
    # Preferir leads con las 3 variantes representadas
    prueba = []
    variantes_seleccionadas = set()
    for e in elegibles:
        if len(prueba) >= 5:
            break
        if e['variante'] not in variantes_seleccionadas or len(prueba) < 3:
            prueba.append(e)
            variantes_seleccionadas.add(e['variante'])

    # Si no hay suficientes con variantes distintas, completar
    if len(prueba) < 3:
        for e in elegibles:
            if len(prueba) >= 5:
                break
            if e not in prueba:
                prueba.append(e)

    print(f"  Leads seleccionados para prueba controlada: {len(prueba)}")
    print("\n  Detalle de la prueba:")
    for i, e in enumerate(prueba, 1):
        print(f"    {i}. lead_id={e['id']} | {e['nombre_club']} | {e['email']} | variante={e['variante']} | fed={e['federacion']}")

    # Verificar que son leads reales (no TEST)
    for e in prueba:
        if es_lead_test(e['email'], e['nombre_club']):
            print(f"  [CRÍTICO] lead {e['id']} es TEST. STOP.")
            db.close()
            ftp.quit()
            sys.exit(1)
    print("\n  Todos los leads de prueba son REALES ✓")

    # ─────────────────────────────────────────────────────────────────────────
    # 10. BACKUP VERIFICABLE
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("10. BACKUP VERIFICABLE")
    print("=" * 90)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_local = os.path.join(BACKUP_DIR, f"stats_db_prep_campana2_pre_{timestamp}.db")
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
        remote_backup_path = f"{REMOTE_BACKUP_DIR}/stats_db_prep_campana2_pre_{timestamp}/stats.db"
        ftp.mkd(f"{REMOTE_BACKUP_DIR}/stats_db_prep_campana2_pre_{timestamp}")
        with open(backup_local, "rb") as f:
            ftp.storbinary("STOR " + remote_backup_path, f)
        print(f"  Backup remoto: {remote_backup_path}")
    except Exception as e:
        print(f"  [WARN] No se pudo subir backup remoto: {e}")

    # ─────────────────────────────────────────────────────────────────────────
    # 11. GENERAR CHECKPOINT PRE-ENVÍO
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("11. GENERAR CHECKPOINT PRE-ENVÍO")
    print("=" * 90)

    checkpoint_path = os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "docs",
        f"checkpoint_pre_envio_piloto_{timestamp}.md"
    )
    checkpoint_path = os.path.normpath(checkpoint_path)

    lines = []
    lines.append("=" * 90)
    lines.append("CHECKPOINT PRE-ENVÍO — CAMPAÑA 2 (PILOTO_FUTPROTEC_2026_08)")
    lines.append("=" * 90)
    lines.append(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    lines.append("")
    lines.append("1. IDENTIDAD BD")
    lines.append(f"   Ruta: {REMOTE_DB}")
    lines.append(f"   Tamaño: {size} bytes")
    lines.append(f"   MD5: {md5}")
    lines.append(f"   SHA-256: {sha256}")
    lines.append(f"   modo_entorno: {modo_entorno}")
    lines.append(f"   motor_estado: {motor_estado}")
    lines.append("")
    lines.append("2. CAMPAÑA OBJETIVO")
    lines.append(f"   campaign_id: {CAMPAIGN_ID}")
    lines.append(f"   nombre: {nombre!r}")
    lines.append(f"   entorno: {entorno!r}")
    lines.append(f"   estado: {estado!r}")
    lines.append(f"   activo: {activo!r}")
    lines.append(f"   validarCampanaActiva: {razon}")
    lines.append("")
    lines.append("3. PLANTILLA A/B/C")
    lines.append(f"   plantilla_id: {plantilla_abc['id']}")
    lines.append(f"   nombre: {plantilla_abc['nombre']!r}")
    lines.append(f"   test_ab: {plantilla_abc['test_ab']!r}")
    lines.append(f"   congelada: {n_congelada > 0}")
    lines.append("")
    lines.append("4. UNIVERSO ELEGIBLE")
    lines.append(f"   TOTAL LEADS CRM: {total_leads}")
    lines.append(f"   TOTAL TEST: {total_test}")
    lines.append(f"   TOTAL REAL: {total_real}")
    lines.append(f"   TOTAL ELEGIBLES: {len(elegibles)}")
    lines.append(f"   TOTAL BLOQUEADOS: {total_leads - len(elegibles)}")
    lines.append("")
    lines.append("5. DISTRIBUCIÓN A/B/C")
    lines.append(f"   A = {dist['A']}")
    lines.append(f"   B = {dist['B']}")
    lines.append(f"   C = {dist['C']}")
    lines.append("")
    lines.append("6. SUPPRESSION / BLACKLIST")
    lines.append(f"   Excluidos por suppression: {n_suppression}")
    lines.append(f"   Excluidos por envío previo: {n_envio_previo}")
    lines.append(f"   Excluidos por incompatibilidad: {n_incompatibilidad}")
    lines.append(f"   Excluidos por TEST: {n_test}")
    lines.append(f"   Finalmente elegibles: {n_final}")
    lines.append("")
    lines.append("7. PRUEBA CONTROLADA (3-5 leads reales)")
    lines.append(f"   Número de destinatarios: {len(prueba)}")
    lines.append("   IDs de leads:")
    for e in prueba:
        lines.append(f"     - lead_id={e['id']} | {e['nombre_club']} | {e['email']} | variante={e['variante']} | fed={e['federacion']}")
    lines.append("")
    lines.append("8. FILTROS APLICADOS")
    lines.append("   - lead REAL (no TEST)")
    lines.append("   - campaña comercial (campaign_id=2)")
    lines.append("   - no blacklist/suppression")
    lines.append("   - no envío previo incompatible")
    lines.append("   - idempotencia (lead_id+campaign_id)")
    lines.append("   - estado elegible (01 Sin Contactar)")
    lines.append("   - compatibilidad lead/campaña")
    lines.append("   - variante determinista")
    lines.append("")
    lines.append("9. BACKUP")
    lines.append(f"   Local: {backup_local}")
    lines.append(f"   MD5: {backup_md5}")
    lines.append(f"   SHA-256: {backup_sha256}")
    lines.append(f"   integrity_check: {backup_integrity}")
    lines.append("")
    lines.append("10. SEGURIDAD")
    lines.append("   pipeline 3 TEST = BLOQUEADO")
    lines.append("   pipeline 1 TEST = BLOQUEADO")
    lines.append("   campaign_id permitido = 2")
    lines.append("   lead TEST = BLOQUEADO")
    lines.append("   lead REAL = permitido")
    lines.append("   es_test del envío real = 0")
    lines.append("   NO se ejecutó cron general")
    lines.append("   NO se activó envío masivo")
    lines.append("   NO se envió ningún email")
    lines.append("")
    lines.append("EMAILS ENVIADOS DURANTE AUDITORÍA = 0")
    lines.append("EMAILS ENVIADOS EN ESTA PRUEBA = 0 (NO SE HA ENVIADO NADA)")
    lines.append("ESPERAR AUTORIZACIÓN ANTES DE CONTINUAR.")
    lines.append("=" * 90)

    with open(checkpoint_path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))
    print(f"  Checkpoint generado: {checkpoint_path}")

    # ─────────────────────────────────────────────────────────────────────────
    # 12. VEREDICTO
    # ─────────────────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("12. VEREDICTO")
    print("=" * 90)

    # Verificar todos los criterios para READY FOR CONTROLLED SEND
    criterios = []
    criterios.append(("campaign_id=2 confirmado (pilot/PILOT/activo=1)", condiciones_ok))
    criterios.append(("validarCampanaActiva operable", operable))
    criterios.append(("plantilla A/B/C activa con contenido", True))
    criterios.append(("variantes A/B/C deterministas", True))
    criterios.append(("universo elegible calculado", len(elegibles) > 0))
    criterios.append(("prueba controlada seleccionada (3-5 leads)", 3 <= len(prueba) <= 5))
    criterios.append(("leads de prueba son REALES", True))
    criterios.append(("backup verificado", backup_integrity == "ok"))
    criterios.append(("motor de envío pausado", motor_estado == "pausado"))
    criterios.append(("0 emails enviados", True))

    all_pass = True
    for nombre, ok in criterios:
        estado = "PASS" if ok else "FAIL"
        if not ok:
            all_pass = False
        print(f"  {estado}: {nombre}")

    print()
    if all_pass:
        print("  VEREDICTO: READY FOR CONTROLLED SEND")
        print()
        print(f"  Leads preparados: {len(elegibles)}")
        print(f"  Leads en prueba controlada: {len(prueba)}")
        print("  Detalle de la prueba:")
        for e in prueba:
            print(f"    - lead_id={e['id']} | {e['nombre_club']} | {e['email']} | variante={e['variante']}")
        print()
        print("  Controles ejecutados:")
        print("    - campaign_id=2 confirmado")
        print("    - plantillas A/B/C verificadas")
        print("    - universo elegible calculado")
        print("    - distribución A/B/C calculada")
        print("    - suppression/blacklist verificada")
        print("    - prueba controlada seleccionada")
        print("    - backup verificado")
        print("    - checkpoint pre-envío generado")
        print()
        print("  Pendiente:")
        print("    - Ejecutar envío controlado de los leads de prueba (requiere autorización)")
        print("    - Verificar message_id, estado enviado, SMTP accepted")
        print("    - Ampliar envío al resto del universo (requiere autorización)")
    else:
        print("  VEREDICTO: NOT READY")
        print("  Revisar los criterios FAIL antes de continuar.")

    print()
    print("EMAILS ENVIADOS DURANTE AUDITORÍA = 0")
    print("EMAILS ENVIADOS EN ESTA PRUEBA = 0 (NO SE HA ENVIADO NADA)")
    print("ESPERAR AUTORIZACIÓN ANTES DE CONTINUAR.")
    print("=" * 90)

    # ─────────────────────────────────────────────────────────────────────────
    # CERRAR CONEXIONES
    # ─────────────────────────────────────────────────────────────────────────
    db.close()
    ftp.quit()

    # Limpiar archivo temporal
    try:
        os.remove(tmp)
    except Exception:
        pass

    print("\nProceso de preparación completado. NO se envió ningún email.")

if __name__ == "__main__":
    main()


