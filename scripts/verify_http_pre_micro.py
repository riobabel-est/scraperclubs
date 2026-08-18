#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verificacion HTTP PRE-MICRO-LOTE (solo lectura, no envia nada)
Comprueba que los endpoints principales responden correctamente:
- dashboard.php -> 200 (tras login)
- app.js?v=10   -> 200 (publico)
- api/track.php -> 200 (publico, pixel)
- api/get_cola.php -> 200 (protegido, tras login)
- api/leads.php, api/smtp.php, api/baja.php -> sin 500 (protegidos)
Interpreta 401/403 como "autenticacion requerida" (no fallo de codigo).
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

def main():
    env = load_env()
    auth_key = env.get("AUTH_KEY", AUTH_KEY)

    # ── 1. LOGIN para obtener cookie de sesion ──
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    login_data = urllib.parse.urlencode({"password": auth_key}).encode()
    print("=== 1. LOGIN (dashboard.php) ===")
    try:
        req = urllib.request.Request(BASE + "/dashboard.php", data=login_data)
        with opener.open(req, timeout=30) as resp:
            print(f"  dashboard.php (POST login) -> HTTP {resp.status}")
    except urllib.error.HTTPError as e:
        print(f"  dashboard.php (POST login) -> HTTP {e.code}")
    except Exception as e:
        print(f"  [ERR] login: {e}")

    # ── 2. Endpoints publicos ──
    print("\n=== 2. ENDPOINTS PUBLICOS ===")
    public_urls = [
        ("app.js?v=10", BASE + "/js/app.js?v=10"),
        ("api/track.php", BASE + "/api/track.php"),
    ]
    for name, url in public_urls:
        try:
            req = urllib.request.Request(url)
            with urllib.request.urlopen(req, timeout=30) as resp:
                print(f"  {name} -> HTTP {resp.status}")
        except urllib.error.HTTPError as e:
            print(f"  {name} -> HTTP {e.code}")
        except Exception as e:
            print(f"  {name} -> [ERR] {e}")

    # ── 3. Endpoints protegidos (con sesion) ──
    print("\n=== 3. ENDPOINTS PROTEGIDOS (con sesion) ===")
    protected_urls = [
        ("dashboard.php", BASE + "/dashboard.php"),
        ("api/get_cola.php", BASE + "/api/get_cola.php"),
        ("api/leads.php", BASE + "/api/leads.php"),
        ("api/smtp.php", BASE + "/api/smtp.php"),
        ("api/baja.php", BASE + "/api/baja.php"),
    ]
    for name, url in protected_urls:
        try:
            req = urllib.request.Request(url)
            with opener.open(req, timeout=30) as resp:
                print(f"  {name} -> HTTP {resp.status}")
        except urllib.error.HTTPError as e:
            code = e.code
            if code in (401, 403):
                print(f"  {name} -> HTTP {code} (autenticacion requerida, no es fallo de codigo)")
            else:
                print(f"  {name} -> HTTP {code} [ATENCION]")
        except Exception as e:
            print(f"  {name} -> [ERR] {e}")

    print("\nVerificacion HTTP completada.")

if __name__ == "__main__":
    main()
