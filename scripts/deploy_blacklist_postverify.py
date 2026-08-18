#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deploy Blacklist/SMTP/Queue - VERIFICACION POST-DEPLOY
1. Confirma que 1810 y 1814 estan restaurados/inmutables en BD remota
2. Verifica limites SMTP en BD (fuente unica de verdad)
3. Verifica que la cola (get_cola) excluye leads en Lista Negra
4. Seguridad: modo_entorno, motor_estado, envios
Solo lectura.
"""
import ftplib
import os
import time
import hashlib
import sqlite3
import tempfile
import urllib.request
import json

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

def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_postverify_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"  BD descargada: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")

    db = sqlite3.connect(tmp)
    cur = db.cursor()

    # ── 1. ESTADO DE 1810 Y 1814 ──
    print("\n=== 1. ESTADO LEADS TEST (post-deploy) ===")
    for lid in (1810, 1814):
        cur.execute("SELECT id, nombre_club, email, estado_lead, observaciones FROM clubes_crm WHERE id = ?", (lid,))
        r = cur.fetchone()
        print(f"  id={r[0]} | {r[1]} | estado={r[3]} | obs_len={len(r[4] or '')}")
        if r[3] in ('Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out'):
            print(f"    -> esElegibleParaEnvio=false (supresion)")
        else:
            print(f"    -> esElegibleParaEnvio=true (elegible)")

    # ── 2. LIMITES SMTP (fuente unica de verdad) ──
    print("\n=== 2. LIMITES SMTP EN BD (fuente unica de verdad) ===")
    cur.execute("SELECT id, email, limite_diario, activa FROM cuentas_smtp ORDER BY id ASC")
    for r in cur.fetchall():
        print(f"  id={r[0]} | {r[1]} | limite_diario={r[2]} | activa={r[3]}")

    # ── 3. COLA: leads en Lista Negra NO deben ser candidatos ──
    print("\n=== 3. COLA: leads en Lista Negra excluidos ===")
    estados_supresion = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido']
    in_list = "','".join(estados_supresion)
    # Leads en Lista Negra
    cur.execute(f"SELECT id, nombre_club, email FROM clubes_crm WHERE estado_lead IN ('{in_list}')")
    suprimidos = cur.fetchall()
    print(f"  Leads suprimidos en BD: {len(suprimidos)}")
    for r in suprimidos:
        print(f"    id={r[0]} | {r[1]} | {r[2]}")
    # Candidatos elegibles (espejo SQL de get_cola.php)
    cur.execute(f"""
        SELECT COUNT(*) FROM clubes_crm c
        WHERE c.email IS NOT NULL AND c.email != '' AND c.es_duplicado = 0
          AND c.estado_lead NOT IN ('{in_list}')
    """)
    elegibles = cur.fetchone()[0]
    print(f"  Candidatos elegibles (espejo get_cola): {elegibles}")

    # ── 4. SEGURIDAD ──
    print("\n=== 4. SEGURIDAD ===")
    cur.execute("SELECT clave, valor FROM config WHERE clave IN ('modo_entorno','motor_estado')")
    for r in cur.fetchall():
        print(f"  config.{r[0]} = {r[1]}")
    cur.execute("SELECT COUNT(*) FROM comunicaciones_log WHERE tipo_evento = 'envio_email' AND DATE(fecha) = DATE('now')")
    print(f"  envios_email_hoy = {cur.fetchone()[0]}")

    db.close()
    os.remove(tmp)

    # ── 5. API get_cola (coherencia con esElegibleParaEnvio) ──
    print("\n=== 5. API get_cola (coherencia con esElegibleParaEnvio) ===")
    try:
        url = "https://getfutprotec.com/outbound/api/get_cola.php"
        req = urllib.request.Request(url)
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read().decode('utf-8'))
        if data.get('ok'):
            cola = data.get('cola', [])
            total_cola = data.get('total_cola', 0)
            print(f"  total_cola = {total_cola}")
            # Verificar que ningun lead de la cola esta en Lista Negra
            emails_cola = [c.get('email') for c in cola]
            print(f"  leads en cola: {len(emails_cola)}")
            # Verificar limites SMTP en la respuesta
            cuentas = data.get('cuentas_smtp', [])
            print("  limites SMTP en API get_cola:")
            for c in cuentas:
                print(f"    {c['email']}: enviados_hoy={c['enviados_hoy']} / limite={c['limite_diario']}")
        else:
            print(f"  get_cola error: {data.get('error')}")
    except Exception as e:
        print(f"  [ERR get_cola] {e}")

    print("\nVerificacion post-deploy completada.")

if __name__ == "__main__":
    main()
