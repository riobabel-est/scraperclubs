#!/usr/bin/env python3
"""
FASE E — Auditoría IMAP READ-ONLY
=================================
Estudia la estructura de un buzón real de producción (SiteGround) para
preparar la implementación de respuestas IMAP en el CRM FutProtec.

MODO ESTRICTAMENTE READ-ONLY:
  - SELECT en modo readonly (no marca mensajes como leídos)
  - FETCH con BODY.PEEK (no altera el flag \\Seen)
  - NO ejecuta STORE / COPY / MOVE / DELETE / EXPUNGE / APPEND
  - NO modifica la BD local ni producción

Uso:
  python scripts/faseE_auditoria_imap.py [--cuenta email] [--max N] [--host HOST] [--puerto PUERTO]

Las credenciales se leen de la BD local public_html/outbound/data/stats.db
(tabla cuentas_smtp). Por defecto usa la primera cuenta activa.
"""

import imaplib
import email
import sys
import os
import sqlite3
import argparse
from email.header import decode_header, make_header
from datetime import datetime

DB_PATH = os.path.join(os.path.dirname(__file__), '..', 'public_html', 'outbound', 'data', 'stats.db')
DB_PATH = os.path.abspath(DB_PATH)

DEFAULT_HOST = 'mail.getfutprotec.com'
DEFAULT_PORT = 993  # IMAP SSL


def obtener_cuentas():
    """Lee las cuentas SMTP de la BD local (solo lectura)."""
    if not os.path.exists(DB_PATH):
        print(f"ERROR: No se encuentra la BD local: {DB_PATH}")
        sys.exit(1)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute(
        "SELECT id, email, host, puerto, usuario, password, activa "
        "FROM cuentas_smtp ORDER BY id"
    )
    cuentas = [dict(r) for r in cur.fetchall()]
    conn.close()
    return cuentas


def decodificar(valor):
    """Decodifica cabeceras MIME (RFC 2047) a texto plano."""
    if not valor:
        return ''
    try:
        return str(make_header(decode_header(valor)))
    except Exception:
        return valor


def auditoria_imap(cuenta, host, puerto, max_msgs):
    """Conecta por IMAP SSL y audita carpetas + cabeceras de INBOX."""
    email_addr = cuenta['email']
    usuario = cuenta['usuario']
    password = cuenta['password']

    print("=" * 70)
    print("FASE E — AUDITORÍA IMAP READ-ONLY")
    print(f"Cuenta: {email_addr}")
    print(f"Host:   {host}:{puerto} (IMAP SSL)")
    print(f"Inicio: {datetime.now().isoformat()}")
    print("=" * 70)

    try:
        M = imaplib.IMAP4_SSL(host, puerto)
    except Exception as e:
        print(f"\n[ERROR] No se pudo conectar por IMAP SSL a {host}:{puerto}")
        print(f"        {e}")
        print("\nPosibles causas:")
        print("  - El host no expone IMAP (solo SMTP). Probar imap.getfutprotec.com")
        print("  - El puerto IMAP no es 993 (probar 143 con STARTTLS)")
        print("  - SiteGround bloquea conexiones IMAP externas")
        return False

    try:
        M.login(usuario, password)
        print(f"\n[OK] Login IMAP correcto para {email_addr}")
    except imaplib.IMAP4.error as e:
        print(f"\n[ERROR] Login IMAP fallido: {e}")
        M.logout()
        return False

    # 1. Listar carpetas
    print("\n--- CARPETAS (LIST) ---")
    try:
        typ, data = M.list()
        if typ == 'OK':
            for item in data:
                if item:
                    print(f"  {item.decode('utf-8', 'replace')}")
        else:
            print("  (sin respuesta)")
    except Exception as e:
        print(f"  [ERROR] list: {e}")

    # 2. Seleccionar INBOX en modo READ-ONLY
    print("\n--- INBOX (SELECT readonly) ---")
    try:
        typ, data = M.select('INBOX', readonly=True)
        if typ != 'OK':
            print(f"  [ERROR] No se pudo seleccionar INBOX: {data}")
            M.logout()
            return False
        total = int(data[0])
        print(f"  Total mensajes en INBOX: {total}")
    except Exception as e:
        print(f"  [ERROR] select: {e}")
        M.logout()
        return False

    if total == 0:
        print("  INBOX vacío. No hay mensajes que auditar.")
        M.logout()
        return True

    # 3. Obtener UIDs de los últimos N mensajes
    n = min(max_msgs, total)
    print(f"\n--- CABECERAS de los últimos {n} mensajes (BODY.PEEK, sin marcar leído) ---")

    try:
        typ, data = M.search(None, 'ALL')
        if typ != 'OK':
            print("  [ERROR] search ALL falló")
            M.logout()
            return False
        seq_nums = data[0].split()
        seq_nums = seq_nums[-n:]  # últimos N
    except Exception as e:
        print(f"  [ERROR] search: {e}")
        M.logout()
        return False

    for seq in seq_nums:
        try:
            # BODY.PEEK[HEADER] NO marca el mensaje como leído
            typ, msg_data = M.fetch(seq, '(UID BODY.PEEK[HEADER])')
            if typ != 'OK':
                continue
            # msg_data es [(b'1 (UID 123 BODY[HEADER] {len}', b'...cabeceras...', b')'), ...]
            raw_header = b''
            uid = None
            for part in msg_data:
                if isinstance(part, tuple):
                    raw_header = part[1]
                    # Extraer UID del primer elemento
                    first = part[0].decode('utf-8', 'replace')
                    if 'UID ' in first:
                        try:
                            uid = first.split('UID ')[1].split(' ')[0]
                        except Exception:
                            uid = None
            if not raw_header:
                continue

            msg = email.message_from_bytes(raw_header)
            print("-" * 60)
            print(f"  UID:        {uid}")
            print(f"  Message-ID: {decodificar(msg.get('Message-ID'))}")
            print(f"  In-Reply-To:{decodificar(msg.get('In-Reply-To'))}")
            print(f"  References: {decodificar(msg.get('References'))}")
            print(f"  From:       {decodificar(msg.get('From'))}")
            print(f"  To:         {decodificar(msg.get('To'))}")
            print(f"  Subject:    {decodificar(msg.get('Subject'))}")
            print(f"  Date:       {decodificar(msg.get('Date'))}")
        except Exception as e:
            print(f"  [ERROR] fetch seq {seq}: {e}")

    # 4. Cerrar sin expunge (no borra nada)
    try:
        M.close()  # cierra INBOX sin EXPUNGE
    except Exception:
        pass
    M.logout()
    print("\n" + "=" * 70)
    print("AUDITORÍA IMAP COMPLETADA (READ-ONLY, sin modificaciones)")
    print("=" * 70)
    return True


def main():
    parser = argparse.ArgumentParser(description='FASE E — Auditoría IMAP READ-ONLY')
    parser.add_argument('--cuenta', help='Email de la cuenta a auditar (por defecto la primera activa)')
    parser.add_argument('--max', type=int, default=10, help='Número máximo de mensajes a auditar (default 10)')
    parser.add_argument('--host', default=DEFAULT_HOST, help=f'Host IMAP (default {DEFAULT_HOST})')
    parser.add_argument('--puerto', type=int, default=DEFAULT_PORT, help=f'Puerto IMAP (default {DEFAULT_PORT})')
    args = parser.parse_args()

    cuentas = obtener_cuentas()
    if not cuentas:
        print("ERROR: No hay cuentas SMTP en la BD local.")
        sys.exit(1)

    cuenta = None
    if args.cuenta:
        cuenta = next((c for c in cuentas if c['email'] == args.cuenta), None)
        if not cuenta:
            print(f"ERROR: No se encontró la cuenta {args.cuenta}")
            sys.exit(1)
    else:
        cuenta = next((c for c in cuentas if c['activa']), cuentas[0])

    ok = auditoria_imap(cuenta, args.host, args.puerto, args.max)
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
