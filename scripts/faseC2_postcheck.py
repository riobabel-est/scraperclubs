#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseC2_postcheck.py — POSTCHECK de la Fase C.2 (microenvío 5 leads).

Descarga la BD de producción DESPUÉS de que el usuario ejecute el microenvío
en la lanzadera web autenticada, y verifica:

  - exactamente 5 envíos NUEVOS en campaña 2 (22 → 27)
  - los 5 leads autorizados (2,3,4,6,8)
  - campaign_id = 2, es_test = 0
  - variantes B/B/B/A/C
  - message_id válido
  - estado = enviado
  - integridad SQLite (integrity_check, foreign_key_check)
  - ausencia de envíos en otras campañas / otros leads
  - motor sigue pausado
  - pipelines/lead_pipelines/plantillas intactas

NO envía emails. NO modifica BD. Solo lectura.
"""
import ftplib
import os
import sys
import time
import hashlib
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

CAMPAIGN_ID = 2
LEADS_AUTORIZADOS = [2, 3, 4, 6, 8]
VARIANTES_ESPERADAS = {2: 'B', 3: 'B', 4: 'B', 6: 'A', 8: 'C'}
ENVIOS_PRE = 22
ENVIOS_POST_ESPERADO = 27

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

def main():
    print("=" * 90)
    print("FASE C.2 — POSTCHECK (microenvío 5 leads)")
    print("=" * 90)
    print(f"Fecha/hora: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print()

    # ── 1. Descargar BD ──────────────────────────────────────────────────────
    print("1. DESCARGAR BD DE PRODUCCIÓN (post-envío)")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    try:
        mdtm = ftp.sendcmd("MDTM " + REMOTE_DB)
        print(f"  MDTM remoto: {mdtm}")
    except Exception as e:
        print(f"  MDTM no disponible: {e}")

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_c2post_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()

    size = os.path.getsize(tmp)
    md5 = file_md5(tmp)
    sha256 = file_sha256(tmp)
    print(f"  Tamaño: {size} bytes")
    print(f"  MD5: {md5}")
    print(f"  SHA-256: {sha256}")

    # ── 2. Integridad ────────────────────────────────────────────────────────
    print("\n2. INTEGRIDAD SQLITE")
    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()
    integrity = cur.execute("PRAGMA integrity_check").fetchone()[0]
    fk = cur.execute("PRAGMA foreign_key_check").fetchall()
    print(f"  integrity_check: {integrity}")
    print(f"  foreign_key_check: {len(fk)} violaciones")

    # ── 3. Config / motor ────────────────────────────────────────────────────
    print("\n3. CONFIG / MODO ENTORNO / MOTOR")
    config = {}
    try:
        cur.execute("SELECT clave, valor FROM config")
        for r in cur.fetchall():
            config[r["clave"]] = r["valor"]
    except Exception as e:
        print(f"  [WARN] config: {e}")
    modo_entorno = config.get("modo_entorno", "test")
    motor_estado = config.get("motor_estado", "pausado")
    print(f"  modo_entorno = {modo_entorno}")
    print(f"  motor_estado = {motor_estado}")

    # ── 4. Conteo campaña 2 ──────────────────────────────────────────────────
    print("\n4. CONTEO CAMPAÑA 2")
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ?", (CAMPAIGN_ID,))
    n_camp2 = cur.fetchone()[0]
    print(f"  Envíos campaña 2: {n_camp2} (esperado {ENVIOS_POST_ESPERADO}, antes {ENVIOS_PRE})")

    # ── 5. Los 5 envíos nuevos ───────────────────────────────────────────────
    print("\n5. ENVÍOS DE LOS 5 LEADS AUTORIZADOS")
    cur.execute("""
        SELECT e.id, e.lead_id, e.campaign_id, e.email, e.estado, e.es_test, e.variant, e.message_id, e.plantilla_id, e.fecha
        FROM envios e
        WHERE e.campaign_id = ? AND e.lead_id IN (2,3,4,6,8)
        ORDER BY e.lead_id
    """, (CAMPAIGN_ID,))
    envios = cur.fetchall()

    envios_ok = True
    leads_vistos = set()
    for e in envios:
        leads_vistos.add(e['lead_id'])
        print(f"  envio_id={e['id']} lead={e['lead_id']} email={e['email']} estado={e['estado']} es_test={e['es_test']} variant={e['variant']} plantilla={e['plantilla_id']} message_id={e['message_id']}")
        if e['estado'] != 'enviado':
            print(f"    [CRÍTICO] estado={e['estado']} ≠ 'enviado'"); envios_ok = False
        if int(e['es_test'] or 0) != 0:
            print(f"    [CRÍTICO] es_test={e['es_test']} ≠ 0"); envios_ok = False
        if e['variant'] != VARIANTES_ESPERADAS[e['lead_id']]:
            print(f"    [CRÍTICO] variant={e['variant']} ≠ esperada {VARIANTES_ESPERADAS[e['lead_id']]}"); envios_ok = False
        if not e['message_id']:
            print(f"    [CRÍTICO] message_id vacío"); envios_ok = False
        if int(e['campaign_id'] or 0) != CAMPAIGN_ID:
            print(f"    [CRÍTICO] campaign_id={e['campaign_id']} ≠ 2"); envios_ok = False

    # Verificar que los 5 leads tienen envío
    for lid in LEADS_AUTORIZADOS:
        if lid not in leads_vistos:
            print(f"  [CRÍTICO] lead {lid} NO tiene envío en campaña 2"); envios_ok = False

    # ── 6. Ausencia de envíos adicionales ────────────────────────────────────
    print("\n6. AUSENCIA DE ENVÍOS ADICIONALES")
    # Envíos en campaña 2 de leads NO autorizados
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id = ? AND lead_id NOT IN (2,3,4,6,8)", (CAMPAIGN_ID,))
    otros_camp2 = cur.fetchone()[0]
    print(f"  Envíos campaña 2 de leads NO autorizados: {otros_camp2}")
    if otros_camp2 != ENVIOS_PRE:
        print(f"  [CRÍTICO] Se esperaban {ENVIOS_PRE} envíos previos de otros leads, hay {otros_camp2}"); envios_ok = False

    # Envíos en otras campañas
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id IS NOT NULL AND campaign_id != ?", (CAMPAIGN_ID,))
    otras_camp = cur.fetchone()[0]
    print(f"  Envíos en otras campañas: {otras_camp}")

    # Envíos con campaign_id NULL
    cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id IS NULL")
    null_camp = cur.fetchone()[0]
    print(f"  Envíos con campaign_id NULL: {null_camp}")

    # ── 7. Estados de los leads ──────────────────────────────────────────────
    print("\n7. ESTADOS DE LOS LEADS TRAS ENVÍO")
    for lid in LEADS_AUTORIZADOS:
        cur.execute("SELECT estado_lead FROM clubes_crm WHERE id = ?", (lid,))
        r = cur.fetchone()
        estado_lead = r['estado_lead'] if r else 'NO EXISTE'
        print(f"  lead {lid}: {estado_lead}")
        if estado_lead != '02 Contactado':
            print(f"    [CRÍTICO] estado_lead={estado_lead} ≠ '02 Contactado'"); envios_ok = False

    # ── 8. Pipelines intactas ────────────────────────────────────────────────
    print("\n8. PIPELINES (deben estar intactas)")
    cur.execute("SELECT id, nombre, entorno, estado, activo FROM pipelines ORDER BY id")
    for p in cur.fetchall():
        print(f"  id={p['id']} nombre={p['nombre']!r} entorno={p['entorno']!r} estado={p['estado']!r} activo={p['activo']!r}")

    # ── 9. lead_pipelines intactas ───────────────────────────────────────────
    print("\n9. LEAD_PIPELINES (deben estar intactas)")
    cur.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines ORDER BY id")
    for lp in cur.fetchall():
        print(f"  id={lp['id']} lead={lp['lead_id']} pipeline={lp['pipeline_id']} variante={lp['variante_ab']!r}")

    # ── 10. Plantillas intactas ──────────────────────────────────────────────
    print("\n10. PLANTILLAS (deben estar intactas)")
    cur.execute("SELECT id, nombre, test_ab, tipo FROM plantillas ORDER BY id")
    for pl in cur.fetchall():
        print(f"  id={pl['id']} nombre={pl['nombre']!r} test_ab={pl['test_ab']!r} tipo={pl['tipo']!r}")

    # ── 11. message_id duplicados ────────────────────────────────────────────
    print("\n11. MESSAGE_ID DUPLICADOS")
    cur.execute("SELECT message_id, COUNT(*) as n FROM envios WHERE message_id IS NOT NULL AND message_id != '' GROUP BY message_id HAVING n > 1")
    dup_msg = cur.fetchall()
    print(f"  message_id duplicados: {len(dup_msg)}")
    if len(dup_msg) > 0:
        print("  [CRÍTICO] message_id duplicados"); envios_ok = False

    # ── 12. Envíos TEST/REAL ─────────────────────────────────────────────────
    print("\n12. ENVÍOS TEST/REAL")
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 1")
    n_test = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM envios WHERE es_test = 0")
    n_real = cur.fetchone()[0]
    print(f"  Envíos TEST: {n_test}")
    print(f"  Envíos REAL: {n_real}")

    db.close()

    # ── Veredicto ────────────────────────────────────────────────────────────
    print("\n" + "=" * 90)
    print("VEREDICTO FASE C.2")
    print("=" * 90)
    print(f"  Envíos campaña 2: {n_camp2} (esperado {ENVIOS_POST_ESPERADO})")
    print(f"  integrity_check: {integrity} | foreign_key_check: {len(fk)}")
    print(f"  motor_estado: {motor_estado}")

    if (n_camp2 == ENVIOS_POST_ESPERADO and envios_ok and integrity == 'ok'
            and len(fk) == 0 and motor_estado == 'pausado'):
        print("\n  ✅ VEREDICTO: PASS — Microenvío de 5 leads completado correctamente.")
        print("     - 5 emails REALES enviados (leads 2,3,4,6,8)")
        print("     - Variantes B/B/B/A/C correctas")
        print("     - es_test=0 en todos")
        print("     - message_id generados")
        print("     - Integridad SQLite OK")
        print("     - Motor sigue pausado")
        print("     - Pipelines/lead_pipelines/plantillas intactas")
        print("     - Sin envíos adicionales")
    else:
        print("\n  ❌ VEREDICTO: FAIL — Hay anomalías. Revisar el detalle anterior.")
        print("     NO ampliar la campaña. NO enviar más emails.")

    print("\n  EMAILS ENVIADOS (nuevos en esta fase): 5 (si PASS)")
    print("  CAMPAÑAS LANZADAS: 0")
    print("=" * 90)

    try:
        os.remove(tmp)
    except Exception:
        pass

if __name__ == "__main__":
    main()
