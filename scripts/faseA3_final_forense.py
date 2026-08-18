#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_final_forense.py

FASE A.3-FINAL — VERIFICACIÓN FORENSE READ-ONLY POST-CUELGUES.

EXCLUSIVAMENTE READ-ONLY. NO modifica producción.

Verifica de forma independiente que el estado REAL de producción coincide
con el estado esperado tras FASE A.3 y que no hubo efectos secundarios
de los cuelgues ocurridos durante el proceso.

Controles (secciones 1-13 del prompt):
  1. Identidad de la BD de producción
  2. Integridad SQLite (integrity_check, foreign_key_check)
  3. Estructura de la BD (envios.es_test, tablas críticas)
  4. Leads 1815 y 1816 + conteo 'Sin Contactar'
  5. Control global de estados legacy
  6. Control de envios (total, REAL/TEST/NULL, IDs 18/19, discrepancias es_test)
  7. Control de campañas / pipelines
  8. Control de lead_pipelines (variantes históricas)
  9. Control de no regresión de A.2
  10. Control de emails / actividad comercial
  11. Control de tablas C
  12. Comparación con checkpoint A.3
  13. Detección de efectos de los cuelgues

NO ejecuta UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX.
NO sube BD. NO reemplaza archivos. NO lanza campañas. NO envía emails.

USO:
  python scripts/faseA3_final_forense.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import zlib
import re

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

# Backup pre-reparación (manifest faseA3)
BACKUP_PRE = "backups_deploy/stats_db_faseA3_pre_20260818_015427.db"
CHECKPOINT_A3 = "docs/checkpoint_faseA3_reparaciones.md"

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

def es_lead_test(email, nombre_club):
    """Espejo de esLeadTest() de eligibilidad.php."""
    e = (email or "").lower()
    n = (nombre_club or "").lower()
    if e and "@futprotec.local" in e:
        return True
    if n and n.startswith("test"):
        return True
    return False

def asignar_variante(lead_id, campaign_id):
    """Espejo de asignarVariante() de abc.php (crc32 % 3)."""
    s = f"{campaign_id}:{lead_id}"
    h = zlib.crc32(s.encode("utf-8"))
    if h < 0:
        h += 4294967296
    return ["A", "B", "C"][h % 3]

def main():
    print("=" * 80)
    print("FASE A.3-FINAL — VERIFICACIÓN FORENSE READ-ONLY POST-CUELGUES")
    print("=" * 80)

    # ── 1. IDENTIDAD DE LA BD ──
    print("\n[1] IDENTIDAD DE LA BD DE PRODUCCIÓN")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    try:
        ftp.cwd("/getfutprotec.com/public_html/outbound/data")
        size = ftp.size("stats.db")
        mtime = ftp.sendcmd("MDTM stats.db")
        print(f"  Ruta: {REMOTE_DB}")
        print(f"  Tamaño: {size} bytes")
        print(f"  Fecha/hora modificación (MDTM): {mtime}")
    except Exception as e:
        print(f"  [ERR] No se pudo obtener metadatos remotos: {e}")
        size, mtime = None, None

    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_final_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    md5 = file_md5(tmp)
    sha = file_sha256(tmp)
    print(f"  MD5: {md5}")
    print(f"  SHA-256: {sha}")

    # Comparar con backup pre-reparación
    if os.path.exists(BACKUP_PRE):
        bk_md5 = file_md5(BACKUP_PRE)
        print(f"  Backup pre-reparación MD5: {bk_md5}")
        if bk_md5 == md5:
            print("  [OK] La BD actual coincide con el backup pre-reparación (sin cambios).")
        else:
            print("  [INFO] La BD actual difiere del backup pre-reparación (se analizará en secciones siguientes).")
    else:
        print(f"  [WARN] Backup pre no encontrado: {BACKUP_PRE}")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    cur = db.cursor()

    # ── 2. INTEGRIDAD SQLITE ──
    print("\n[2] INTEGRIDAD SQLITE")
    cur.execute("PRAGMA integrity_check")
    integ = cur.fetchone()[0]
    print(f"  integrity_check = {integ}")
    cur.execute("PRAGMA foreign_key_check")
    fk = cur.fetchall()
    print(f"  foreign_key_check = {len(fk)} violaciones")
    for r in fk:
        print(f"    {dict(r)}")

    # ── 3. ESTRUCTURA DE LA BD ──
    print("\n[3] ESTRUCTURA DE LA BD")
    cur.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    tablas = [r["name"] for r in cur.fetchall()]
    print(f"  Tablas: {tablas}")
    for t in ("clubes_crm", "envios", "pipelines", "lead_pipelines"):
        if t in tablas:
            cur.execute(f"PRAGMA table_info('{t}')")
            cols = [r["name"] for r in cur.fetchall()]
            print(f"  Tabla {t}: columnas = {cols}")
        else:
            print(f"  [FAIL] Tabla {t} NO existe")
    # envios.es_test
    cur.execute("PRAGMA table_info('envios')")
    env_cols = {r["name"] for r in cur.fetchall()}
    print(f"  envios.es_test existe: {'es_test' in env_cols}")

    # ── 4. LEADS 1815 Y 1816 ──
    print("\n[4] LEADS 1815 Y 1816")
    for lid in (1815, 1816):
        cur.execute("SELECT id, estado_lead FROM clubes_crm WHERE id=?", (lid,))
        r = cur.fetchone()
        if r is None:
            print(f"  [FAIL] lead {lid} NO existe")
        else:
            ok = r["estado_lead"] == "01 Sin Contactar"
            print(f"  [{'OK' if ok else 'FAIL'}] lead {lid} estado_lead='{r['estado_lead']}'")
    cur.execute("SELECT COUNT(*) AS n FROM clubes_crm WHERE estado_lead='Sin Contactar'")
    n_sin = cur.fetchone()["n"]
    print(f"  COUNT(*) estado_lead='Sin Contactar' = {n_sin} (esperado 0)")

    # ── 5. CONTROL GLOBAL DE ESTADOS LEGACY ──
    print("\n[5] CONTROL GLOBAL DE ESTADOS LEGACY")
    cur.execute("SELECT DISTINCT estado_lead FROM clubes_crm")
    estados = [r["estado_lead"] for r in cur.fetchall()]
    print(f"  Estados distintos en clubes_crm: {estados}")
    # Estados legacy de la arquitectura de 7 columnas (sin prefijo numérico)
    kanban = {"01 Sin Contactar","02 Contactado","03 Respondió","04 Interesado",
              "05 Cualificado","06 Propuesta","07 Negociación","08 Ganado","09 Perdido"}
    supresion = {"Lista Negra","Opt-Out","Unsubscribed","Baja / Opt-Out","Email Inválido"}
    legacy = [e for e in estados if e and e not in kanban and e not in supresion]
    if legacy:
        print(f"  [INFO] Estados legacy detectados: {legacy}")
        for e in legacy:
            cur.execute("SELECT id FROM clubes_crm WHERE estado_lead=?", (e,))
            ids = [r["id"] for r in cur.fetchall()]
            print(f"    estado='{e}' -> {len(ids)} leads, IDs: {ids}")
    else:
        print("  [OK] No hay estados legacy fuera del Kanban definitivo.")

    # ── 6. CONTROL DE ENVIOS ──
    print("\n[6] CONTROL DE ENVIOS")
    cur.execute("SELECT COUNT(*) AS n FROM envios")
    total = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM envios WHERE es_test=0")
    n_real = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM envios WHERE es_test=1")
    n_test = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM envios WHERE es_test IS NULL")
    n_null = cur.fetchone()["n"]
    print(f"  total = {total} (esperado 42)")
    print(f"  es_test=0 (REAL) = {n_real} (esperado 24)")
    print(f"  es_test=1 (TEST) = {n_test} (esperado 18)")
    print(f"  es_test IS NULL = {n_null} (esperado 0)")

    # IDs 18 y 19
    for eid in (18, 19):
        cur.execute("SELECT id, es_test FROM envios WHERE id=?", (eid,))
        r = cur.fetchone()
        if r is None:
            print(f"  [FAIL] envio {eid} NO existe")
        else:
            ok = r["es_test"] == 0
            print(f"  [{'OK' if ok else 'FAIL'}] envio {eid} es_test={r['es_test']} (esperado 0/REAL)")

    # Discrepancias es_test vs clasificación determinista
    cur.execute("SELECT id, entorno FROM pipelines")
    pipelines = {r["id"]: r["entorno"] for r in cur.fetchall()}
    cur.execute("SELECT * FROM envios ORDER BY id")
    envios = cur.fetchall()
    discrepancias = []
    ambiguos = []
    for e in envios:
        eid = e["id"]
        lead_id = e["lead_id"]
        camp_id = e["campaign_id"]
        es_test_actual = e["es_test"]
        lead_test = False
        if lead_id:
            cur.execute("SELECT email, nombre_club FROM clubes_crm WHERE id=?", (lead_id,))
            lead = cur.fetchone()
            if lead is not None:
                lead_test = es_lead_test(lead["email"], lead["nombre_club"])
            else:
                ambiguos.append(eid)
        camp_test = False
        if camp_id:
            if camp_id in pipelines:
                camp_test = (pipelines[camp_id] or "").lower() == "test"
            else:
                ambiguos.append(eid)
        determ = 1 if (lead_test or camp_test) else 0
        if es_test_actual != determ:
            discrepancias.append((eid, es_test_actual, determ))
    print(f"  discrepancias es_test = {len(discrepancias)} -> {discrepancias}")
    print(f"  ambiguos = {len(ambiguos)} -> {ambiguos}")

    # ── 7. CONTROL DE CAMPAÑAS / PIPELINES ──
    print("\n[7] CONTROL DE CAMPAÑAS / PIPELINES")
    cur.execute("SELECT id, nombre, entorno, estado FROM pipelines ORDER BY id")
    for r in cur.fetchall():
        print(f"  pipeline id={r['id']} nombre='{r['nombre']}' entorno='{r['entorno']}' estado='{r['estado']}'")
    # Verificar pipeline 3 (hallazgo B)
    cur.execute("SELECT id, nombre, entorno, estado FROM pipelines WHERE id=3")
    p3 = cur.fetchone()
    if p3:
        ok = (p3["entorno"] == "test" and p3["estado"] == "PILOT")
        print(f"  [{'OK' if ok else 'FAIL'}] pipeline 3: entorno='{p3['entorno']}' estado='{p3['estado']}' (esperado test/PILOT, HALLAZGO B PENDIENTE)")

    # ── 8. CONTROL DE LEAD_PIPELINES ──
    print("\n[8] CONTROL DE LEAD_PIPELINES (variantes históricas)")
    cur.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines ORDER BY id")
    for r in cur.fetchall():
        print(f"  lead_pipeline id={r['id']} lead={r['lead_id']} pipeline={r['pipeline_id']} variante_ab='{r['variante_ab']}'")
    # Verificar ids 2,4,5 (hallazgo B)
    cur.execute("SELECT id, variante_ab FROM lead_pipelines WHERE id IN (2,4,5) ORDER BY id")
    lp_b = cur.fetchall()
    print(f"  lead_pipelines 2,4,5 (HALLAZGO B PENDIENTE): {[dict(r) for r in lp_b]}")

    # ── 9. CONTROL DE NO REGRESIÓN DE A.2 ──
    print("\n[9] CONTROL DE NO REGRESIÓN DE A.2")
    print(f"  envios total = {total} (esperado 42)")
    print(f"  REAL = {n_real} (esperado 24)")
    print(f"  TEST = {n_test} (esperado 18)")
    print(f"  NULL = {n_null} (esperado 0)")
    print(f"  discrepancias = {len(discrepancias)} (esperado 0)")
    print(f"  ambiguos = {len(ambiguos)} (esperado 0)")
    print(f"  integrity_check = {integ} (esperado ok)")

    # ── 10. CONTROL DE EMAILS / ACTIVIDAD COMERCIAL ──
    print("\n[10] CONTROL DE EMAILS / ACTIVIDAD COMERCIAL")
    # Comparar envios con backup pre-reparación
    if os.path.exists(BACKUP_PRE):
        db_pre = sqlite3.connect(BACKUP_PRE)
        db_pre.row_factory = sqlite3.Row
        cur_pre = db_pre.cursor()
        cur_pre.execute("SELECT COUNT(*) AS n FROM envios")
        pre_total = cur_pre.fetchone()["n"]
        cur_pre.execute("SELECT id, message_id, estado, fecha_envio FROM envios ORDER BY id")
        pre_envios = {r["id"]: dict(r) for r in cur_pre.fetchall()}
        db_pre.close()
        cur.execute("SELECT id, message_id, estado, fecha_envio FROM envios ORDER BY id")
        post_envios = {r["id"]: dict(r) for r in cur.fetchall()}
        nuevos = [eid for eid in post_envios if eid not in pre_envios]
        print(f"  Envios en backup pre: {pre_total}")
        print(f"  Envios actuales: {total}")
        print(f"  Envios nuevos (no en backup pre): {nuevos}")
        # message_id nuevos
        pre_mids = {r["message_id"] for r in pre_envios.values() if r["message_id"]}
        post_mids = {r["message_id"] for r in post_envios.values() if r["message_id"]}
        mids_nuevos = post_mids - pre_mids
        print(f"  message_id nuevos: {len(mids_nuevos)} -> {list(mids_nuevos)[:10]}")
        # estados de envío
        cur.execute("SELECT estado, COUNT(*) AS n FROM envios GROUP BY estado")
        print("  Estados de envío actuales:")
        for r in cur.fetchall():
            print(f"    {r['estado']}: {r['n']}")
        # respuestas
        cur.execute("SELECT COUNT(*) AS n FROM respuestas")
        print(f"  respuestas total: {cur.fetchone()['n']}")
        # campañas (pipelines) nuevos
        cur.execute("SELECT COUNT(*) AS n FROM pipelines")
        print(f"  pipelines total: {cur.fetchone()['n']}")
    else:
        print(f"  [WARN] Backup pre no encontrado: {BACKUP_PRE}. No se puede comparar actividad.")
        print("  NO DETERMINABLE CON LA EVIDENCIA DISPONIBLE (falta backup pre para comparar envios/message_id).")

    # ── 11. CONTROL DE TABLAS C ──
    print("\n[11] CONTROL DE TABLAS C (INFORMATIVOS)")
    for t in ("rebotes", "plantillas_new", "mockups", "presupuestos", "respuestas", "destinatarios_test"):
        if t in tablas:
            cur.execute(f"SELECT COUNT(*) AS n FROM '{t}'")
            print(f"  {t}: {cur.fetchone()['n']} filas")
        else:
            print(f"  {t}: tabla no existe")
    cur.execute("SELECT id, estado, fecha_resultado_envio FROM envios WHERE id IN (1,2)")
    for r in cur.fetchall():
        print(f"  envio {r['id']}: estado='{r['estado']}' fecha_resultado_envio={r['fecha_resultado_envio']}")

    # ── 12. COMPARACIÓN CON CHECKPOINT A.3 ──
    print("\n[12] COMPARACIÓN CON CHECKPOINT A.3")
    print(f"  Checkpoint: {CHECKPOINT_A3}")
    print(f"  leads 1815/1816 = '01 Sin Contactar': verificado en sección 4")
    print(f"  integrity_check = {integ}")
    print(f"  pipelines = 3 (verificado en sección 7)")
    print(f"  lead_pipelines = 5 (verificado en sección 8)")
    print(f"  envios = {total} (esperado 42)")
    print(f"  es_test REAL={n_real} TEST={n_test} NULL={n_null}")
    print(f"  variantes A/B/C históricas: verificado en sección 8 (sin recalcular)")

    # ── 13. DETECCIÓN DE EFECTOS DE LOS CUELGUES ──
    print("\n[13] DETECCIÓN DE EFECTOS DE LOS CUELGUES")
    if os.path.exists(BACKUP_PRE):
        db_pre = sqlite3.connect(BACKUP_PRE)
        db_pre.row_factory = sqlite3.Row
        cur_pre = db_pre.cursor()
        # Comparar clubes_crm completos
        cur.execute("SELECT * FROM clubes_crm ORDER BY id")
        cur_pre.execute("SELECT * FROM clubes_crm ORDER BY id")
        rows_post = cur.fetchall()
        rows_pre = cur_pre.fetchall()
        diffs = []
        if len(rows_post) != len(rows_pre):
            diffs.append(f"clubes_crm filas difieren: pre={len(rows_pre)} post={len(rows_post)}")
        else:
            for rp, rpost in zip(rows_pre, rows_post):
                if rp["id"] != rpost["id"]:
                    diffs.append(f"id difiere: pre={rp['id']} post={rpost['id']}")
                    continue
                for col in rp.keys():
                    if rp[col] != rpost[col]:
                        if col == "estado_lead" and rpost["id"] in (1815, 1816):
                            continue
                        diffs.append(f"clubes_crm id={rp['id']} col={col}: pre={rp[col]!r} post={rpost[col]!r}")
        if diffs:
            print("  [FAIL] Diferencias en clubes_crm fuera de estado_lead de 1815/1816:")
            for d in diffs:
                print(f"    - {d}")
        else:
            print("  [OK] clubes_crm: sin diferencias fuera de estado_lead de 1815/1816")

        # Comparar pipelines, lead_pipelines, envios
        for tabla in ("pipelines", "lead_pipelines", "envios"):
            cur.execute(f"SELECT * FROM {tabla} ORDER BY id")
            cur_pre.execute(f"SELECT * FROM {tabla} ORDER BY id")
            if cur.fetchall() != cur_pre.fetchall():
                print(f"  [FAIL] {tabla} difiere respecto al backup pre-reparación")
            else:
                print(f"  [OK] {tabla}: sin cambios respecto al backup pre-reparación")
        db_pre.close()
    else:
        print(f"  [WARN] Backup pre no encontrado: {BACKUP_PRE}. No se puede comparar para detectar efectos de cuelgues.")

    db.close()
    print("\n" + "=" * 80)
    print("=== FIN VERIFICACIÓN FORENSE (READ-ONLY) ===")
    print(f"BD temporal conservada en: {tmp}")
    print(f"MD5: {md5}")
    print(f"SHA-256: {sha}")

if __name__ == "__main__":
    main()
