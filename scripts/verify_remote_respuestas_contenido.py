#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VERIFY REMOTE respuestas CONTENIDO - MODO READ-ONLY ESTRICTO
=============================================================
Descarga la BD stats.db remota a local (backups_deploy/) y muestra el
contenido de la tabla `respuestas` (remitente, subject, cuerpo, message_id,
in_reply_to, references, clasificacion, estado) para diagnosticar si el
cuerpo del email se registró o quedó vacío.

NO escribe NADA en el servidor remoto. Solo RETR (descarga).
"""
import ftplib
import os
import time
import sqlite3

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
LOCAL_BACKUP_DIR = "backups_deploy"

def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    print("Login OK")

    ts = time.strftime("%Y%m%d_%H%M%S")
    os.makedirs(LOCAL_BACKUP_DIR, exist_ok=True)
    local_bk = os.path.join(LOCAL_BACKUP_DIR, f"stats_db_remoto_respuestas_{ts}.db")
    print(f"\n=== DESCARGANDO BD remota a {local_bk} (solo lectura) ===")
    with open(local_bk, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    print(f"  Descargada: {os.path.getsize(local_bk)} bytes")
    ftp.quit()
    print("=== CONEXION FTP CERRADA (no se escribio nada en remoto) ===\n")

    db = sqlite3.connect(local_bk)
    cur = db.cursor()

    cur.execute("SELECT COUNT(*) FROM respuestas")
    total = cur.fetchone()[0]
    print(f"=== TABLA respuestas: {total} filas ===\n")

    cur.execute(
        "SELECT id, envio_id, remitente, destinatario, subject, "
        "message_id, in_reply_to, \"references\", clasificacion, estado_procesamiento, "
        "fecha_respuesta, length(cuerpo) AS cuerpo_len "
        "FROM respuestas ORDER BY id"
    )

    rows = cur.fetchall()
    for r in rows:
        print(f"--- Respuesta id={r[0]} ---")
        print(f"  envio_id          : {r[1]}")
        print(f"  remitente         : {r[2]}")
        print(f"  destinatario      : {r[3]}")
        print(f"  subject           : {r[4]}")
        print(f"  message_id        : {r[5]}")
        print(f"  in_reply_to       : {r[6]}")
        print(f"  references        : {r[7]}")
        print(f"  clasificacion     : {r[8]}")
        print(f"  estado_procesamiento: {r[9]}")
        print(f"  fecha_respuesta   : {r[10]}")
        print(f"  cuerpo_len        : {r[11]} (0 = vacío)")
        print()

    # Mostrar cuerpo completo de la primera fila si existe
    if rows:
        cur.execute("SELECT id, cuerpo FROM respuestas ORDER BY id LIMIT 1")
        rid, cuerpo = cur.fetchone()
        print(f"=== CUERPO COMPLETO de respuesta id={rid} (len={len(cuerpo) if cuerpo else 0}) ===")
        print(cuerpo if cuerpo else "(VACÍO)")

    db.close()
    print("\n=== FIN ===")

if __name__ == "__main__":
    main()
