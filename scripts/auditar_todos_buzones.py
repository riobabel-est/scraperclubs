#!/usr/bin/env python3
"""
Audita TODOS los buzones IMAP de producción (read-only) y cuenta los mensajes
en INBOX, INBOX.Junk e INBOX.spam por cuenta. Muestra remitente/asunto de cada
mensaje para diagnosticar por qué el runner IMAP solo registra 2 de ~10.

MODO READ-ONLY: SELECT readonly + BODY.PEEK. No modifica nada.
"""
import imaplib
import email
import sys
import os
import sqlite3
from email.header import decode_header, make_header

DB_PATH = os.path.join(os.path.dirname(__file__), '..', 'public_html', 'outbound', 'data', 'stats.db')
DB_PATH = os.path.abspath(DB_PATH)
HOST = 'mail.getfutprotec.com'
PORT = 993
CARPETAS = ['INBOX', 'INBOX.Junk', 'INBOX.spam']


def decodificar(valor):
    if not valor:
        return ''
    try:
        return str(make_header(decode_header(valor)))
    except Exception:
        return valor


def obtener_cuentas():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute("SELECT id, email, usuario, password, activa FROM cuentas_smtp ORDER BY id")
    cuentas = [dict(r) for r in cur.fetchall()]
    conn.close()
    return cuentas


def auditar_cuenta(cuenta):
    email_addr = cuenta['email']
    print("=" * 70)
    print(f"CUENTA: {email_addr}")
    print("=" * 70)
    try:
        M = imaplib.IMAP4_SSL(HOST, PORT)
        M.login(cuenta['usuario'], cuenta['password'])
    except Exception as e:
        print(f"  [ERROR] Login: {e}")
        return 0

    total_general = 0
    for carpeta in CARPETAS:
        try:
            typ, data = M.select(carpeta, readonly=True)
            if typ != 'OK':
                print(f"  [{carpeta}] no accesible")
                continue
            total = int(data[0])
            print(f"  [{carpeta}] {total} mensajes")
            if total == 0:
                continue
            total_general += total
            typ, data = M.search(None, 'ALL')
            if typ != 'OK':
                continue
            seqs = data[0].split()
            for seq in seqs:
                try:
                    typ2, msg_data = M.fetch(seq, '(UID BODY.PEEK[HEADER])')
                    if typ2 != 'OK':
                        continue
                    raw = b''
                    uid = None
                    for part in msg_data:
                        if isinstance(part, tuple):
                            raw = part[1]
                            first = part[0].decode('utf-8', 'replace')
                            if 'UID ' in first:
                                try:
                                    uid = first.split('UID ')[1].split(' ')[0]
                                except Exception:
                                    uid = None
                    if not raw:
                        continue
                    msg = email.message_from_bytes(raw)
                    print(f"    UID={uid} | From: {decodificar(msg.get('From'))} | To: {decodificar(msg.get('To'))} | Subj: {decodificar(msg.get('Subject'))}")
                except Exception as e:
                    print(f"    [ERROR] fetch {seq}: {e}")
        except Exception as e:
            print(f"  [{carpeta}] [ERROR] {e}")

    try:
        M.logout()
    except Exception:
        pass
    print(f"  >>> TOTAL en {email_addr}: {total_general}")
    return total_general


def main():
    cuentas = obtener_cuentas()
    activas = [c for c in cuentas if c['activa']]
    print(f"Cuentas activas: {len(activas)} de {len(cuentas)}")
    gran_total = 0
    for c in activas:
        gran_total += auditar_cuenta(c)
    print("=" * 70)
    print(f"GRAN TOTAL de mensajes en todos los buzones: {gran_total}")
    print("=" * 70)


if __name__ == '__main__':
    main()
