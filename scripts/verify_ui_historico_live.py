#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FASE 7 — PRUEBA CRÍTICA UI LIVE (PASO 8 OPCache + PASO 9 endpoint/JSON)
Verifica el endpoint/JSON real que alimenta la interfaz:

  - ?action=get_analytics&tab=envios  → tabla "Histórico Comercial"
  - ?action=get_last_envios           → "Envíos Realizados" (últimos 10)
  - ?action=get_test_history          → "Histórico de Pruebas"

Comprueba que el JSON devuelto por LIVE contiene SOLO los 12 REAL en el
histórico comercial y los 20 TEST en el histórico de pruebas.

Si el endpoint devuelve los datos correctos, demuestra que LIVE sirve el
código nuevo (no OPCache antiguo).

SOLO LECTURA. NO envía emails. NO ejecuta cron.
"""
import urllib.request
import urllib.parse
import http.cookiejar
import json
import sys

BASE = "https://getfutprotec.com/outbound"
AUTH_KEY = "FutProtec2026!"

def load_env(path=".env"):
    env = {}
    try:
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    except FileNotFoundError:
        pass
    return env

fails = 0
def check(label, got, expected):
    global fails
    ok = (got == expected)
    if not ok:
        fails += 1
    print(f"[{'PASS' if ok else 'FAIL'}] {label}: got={got} expected={expected}")

def main():
    env = load_env()
    auth_key = env.get("AUTH_KEY", AUTH_KEY)

    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

    # ── LOGIN ──
    print("=== LOGIN ===")
    login_data = urllib.parse.urlencode({"password": auth_key}).encode()
    try:
        req = urllib.request.Request(BASE + "/dashboard.php", data=login_data)
        with opener.open(req, timeout=30) as resp:
            print(f"  dashboard.php (POST login) -> HTTP {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"  dashboard.php (POST login) -> HTTP {e.code}")
    except Exception as e:
        print(f"  [ERR] login: {e}")
        sys.exit(1)

    # ── get_analytics tab=envios (Histórico Comercial) ──
    print("\n=== get_analytics tab=envios (Histórico Comercial) ===")
    try:
        req = urllib.request.Request(BASE + "/dashboard.php?action=get_analytics&tab=envios")
        with opener.open(req, timeout=30) as resp:
            data = json.loads(resp.read().decode())
        envios = data.get('envios', [])
        check("Histórico Comercial count (JSON)", len(envios), 12)
        # Ningún email TEST
        test_emails = [e['email'] for e in envios if '@futprotec.local' in (e.get('email') or '') or 'TEST_' in (e.get('email') or '').upper() or 'riobabel' in (e.get('email') or '').lower()]
        check("Histórico Comercial sin emails TEST (JSON)", len(test_emails), 0)
        print("  Emails:", [e.get('email') for e in envios])
    except Exception as e:
        fails += 1
        print(f"  [FAIL] get_analytics: {e}")

    # ── get_last_envios (Envíos Realizados) ──
    print("\n=== get_last_envios (Envíos Realizados) ===")
    try:
        req = urllib.request.Request(BASE + "/dashboard.php?action=get_last_envios")
        with opener.open(req, timeout=30) as resp:
            data = json.loads(resp.read().decode())
        envs = data.get('envios', [])
        check("get_last_envios count (JSON)", len(envs), 10)
        test_emails = [e['email'] for e in envs if '@futprotec.local' in (e.get('email') or '') or 'TEST_' in (e.get('email') or '').upper() or 'riobabel' in (e.get('email') or '').lower()]
        check("get_last_envios sin emails TEST (JSON)", len(test_emails), 0)
        print("  Emails:", [e.get('email') for e in envs])
    except Exception as e:
        fails += 1
        print(f"  [FAIL] get_last_envios: {e}")

    # ── get_test_history (Histórico de Pruebas) ──
    print("\n=== get_test_history (Histórico de Pruebas) ===")
    try:
        req = urllib.request.Request(BASE + "/dashboard.php?action=get_test_history")
        with opener.open(req, timeout=30) as resp:
            data = json.loads(resp.read().decode())
        hist = data.get('envios', data.get('test_history', []))
        check("Histórico de Pruebas count (JSON)", len(hist), 20)
    except Exception as e:
        fails += 1
        print(f"  [FAIL] get_test_history: {e}")

    print("\n=== RESUMEN ===")
    if fails == 0:
        print("TODOS LOS CHECKS PASARON")
        print("DEPLOY_TEST_ISOLATION_UI = PASS")
        print("OPCACHE: LIVE sirve el código nuevo (endpoint devuelve datos correctos)")
    else:
        print(f"FALLOS: {fails}")
        print("DEPLOY_TEST_ISOLATION_UI = FAIL")
    sys.exit(0 if fails == 0 else 1)

if __name__ == "__main__":
    main()
