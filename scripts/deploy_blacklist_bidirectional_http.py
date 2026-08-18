#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verificacion HTTP del deploy de Lista Negra BIDIRECCIONAL (solo lectura).

Interpreta 401/403 como "autenticacion requerida" (no es fallo de codigo),
igual que verify_http_pre_micro.py. Solo se consideran fallos los 500+ o
errores de conexion.

Comprueba:
  - js/app.js?v=10 -> 200 (publico) y contiene "Quitar de Lista Negra", sin "Protegido"
  - dashboard.php -> responde (200 o 401/403), nunca 500
  - tabs/lista_negra.php -> responde (200 o 401/403), nunca 500
  - tabs/modals.php -> responde (200 o 401/403), nunca 500

El contenido exacto de los archivos protegidos ya se verifico por MD5
(LOCAL == REMOTE) en la fase de deploy. Este script confirma que se sirven
sin errores de servidor.

NO modifica nada. Solo lectura.
"""
import urllib.request
import urllib.parse
import http.cookiejar
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

PASS_N = 0
FAIL_N = 0
FAILS = []

def check(nombre, cond, detalle=''):
    global PASS_N, FAIL_N, FAILS
    if cond:
        PASS_N += 1
        print(f"  ✅ {nombre}")
    else:
        FAIL_N += 1
        FAILS.append(nombre)
        print(f"  ❌ {nombre}" + (f" — {detalle}" if detalle else ""))

def fetch(opener, url, data=None):
    try:
        req = urllib.request.Request(url, data=data)
        with opener.open(req, timeout=30) as resp:
            body = resp.read().decode('utf-8', errors='replace')
            return resp.status, body
    except urllib.error.HTTPError as e:
        return e.code, ''
    except Exception as e:
        return 0, str(e)

def is_ok_status(code):
    # 200 OK, o 401/403 = autenticacion requerida (esperado). 500+ = fallo.
    return code in (200, 401, 403)

def main():
    env = load_env()
    auth_key = env.get("AUTH_KEY", AUTH_KEY)

    print("=== 1. LOGIN (dashboard.php) ===")
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    login_data = urllib.parse.urlencode({"password": auth_key}).encode()
    status, body = fetch(opener, BASE + "/dashboard.php", login_data)
    print(f"  dashboard.php (POST login) -> HTTP {status}")
    check('login dashboard.php responde (no 500)', is_ok_status(status), f"status={status}")

    print("\n=== 2. ENDPOINTS PUBLICOS ===")
    status, body = fetch(opener, BASE + "/js/app.js?v=10")
    print(f"  js/app.js?v=10 -> HTTP {status}")
    check('app.js 200', status == 200, f"status={status}")
    check('app.js contiene "Quitar de Lista Negra"', 'Quitar de Lista Negra' in body)
    check('app.js NO contiene "Protegido"', 'Protegido' not in body)

    print("\n=== 3. ENDPOINTS PROTEGIDOS (responde, no 500) ===")
    protected = [
        ("dashboard.php", BASE + "/dashboard.php"),
        ("tabs/lista_negra.php", BASE + "/tabs/lista_negra.php"),
        ("tabs/modals.php", BASE + "/tabs/modals.php"),
    ]
    for name, url in protected:
        status, body = fetch(opener, url)
        print(f"  {name} -> HTTP {status}")
        if status in (401, 403):
            check(f'{name} responde (auth requerida)', True, f"status={status}")
        else:
            check(f'{name} responde (no 500)', is_ok_status(status), f"status={status}")

    print("\n═══════════════════════════════════════════════════════════════")
    print(f" RESULTADO: {PASS_N} pasados, {FAIL_N} fallidos")
    if FAIL_N > 0:
        print(" FALLIDOS:")
        for f in FAILS:
            print(f"   - {f}")
        print(" VEREDICTO: BLOCKED")
        sys.exit(1)
    print(" VEREDICTO: BLACKLIST_BIDIRECTIONAL_HTTP_PASS")
    print("═══════════════════════════════════════════════════════════════")
    sys.exit(0)

if __name__ == "__main__":
    main()
