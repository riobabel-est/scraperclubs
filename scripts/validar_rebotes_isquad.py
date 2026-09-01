"""
validar_rebotes_isquad.py — Comprobación de rebote técnica (pre-entrega) del CSV
de iSquad (700 clubes CV) antes de importar al CRM.

Criterios (los mismos del CRM / validate_emails.py):
  1. Formato de email válido (regex RFC básica).
  2. El dominio tiene registro MX (dnspython).

Heurísticas adicionales:
  - Email vacío -> motivo 'empty email'
  - Dominio de la propia federación (ffcv.es / isquad.es) -> 'dominio federacion' (no es contacto del club)
  - Dominios desechables conocidos -> 'dominio desechable'

Salidas (en output/clean/):
  - clubs_isquad_cv_ok.csv       -> emails con MX válido (importables)
  - clubs_isquad_cv_problema.csv -> descartados + motivo

Uso:
  python scripts/validar_rebotes_isquad.py [--csv output/clubs_isquad_cv.csv]
"""

import argparse
import csv
import re
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import dns.resolver

OUTPUT_DIR = Path(__file__).parent.parent / "output"
CLEAN_DIR = OUTPUT_DIR / "clean"
CLEAN_DIR.mkdir(parents=True, exist_ok=True)

EMAIL_PATTERN = re.compile(
    r"^(?P<local>[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+)@(?P<domain>[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)$"
)

# Dominios desechables/temporales conocidos (no son contacto real de club)
DESECHABLES = {
    "mailinator.com", "yopmail.com", "guerrillamail.com", "tempmail.com",
    "10minutemail.com", "throwawaymail.com", "getnada.com", "trashmail.com",
    "maildrop.cc", "dispostable.com", "spam4.me", "fakeinbox.com",
}

# Dominios de la propia federación (correos institucionales, no del club)
DOMINIOS_FEDERACION = {"ffcv.es", "isquad.es", "fenix.es", "ffcv"}

CAMPO_MOTIVO = "motivo_rebote"


def sintactically_valid(email):
    return bool(email and EMAIL_PATTERN.match(email))


def dominio_email(email):
    try:
        return email.split("@", 1)[1].lower()
    except IndexError:
        return ""


def has_mx(domain):
    try:
        answers = dns.resolver.resolve(domain, "MX", lifetime=8.0)
        return bool(answers)
    except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN,
            dns.resolver.NoNameservers, dns.resolver.LifetimeTimeout,
            dns.resolver.YXDOMAIN):
        return False
    except Exception:
        return False


def clasificar(fila):
    """Devuelve (fila_con_motivo, ok: bool)."""
    email = (fila.get("email") or "").strip()
    if not email:
        fila[CAMPO_MOTIVO] = "empty email"
        return fila, False
    if not sintactically_valid(email):
        fila[CAMPO_MOTIVO] = "syntax invalid"
        return fila, False
    dom = dominio_email(email)
    if dom in DOMINIOS_FEDERACION:
        fila[CAMPO_MOTIVO] = "dominio federacion"
        return fila, False
    if dom in DESECHABLES:
        fila[CAMPO_MOTIVO] = "dominio desechable"
        return fila, False
    if not has_mx(dom):
        fila[CAMPO_MOTIVO] = "no MX record"
        return fila, False
    fila[CAMPO_MOTIVO] = ""
    return fila, True


def main():
    parser = argparse.ArgumentParser(description="Validación de rebote técnica del CSV de iSquad")
    parser.add_argument("--csv", default=str(OUTPUT_DIR / "clubs_isquad_cv.csv"))
    parser.add_argument("--workers", type=int, default=10, help="Hilos para consultas DNS")
    args = parser.parse_args()

    csv_path = Path(args.csv)
    with open(csv_path, encoding="utf-8-sig", newline="") as f:
        filas = list(csv.DictReader(f))
    print(f"[validar] Filas leídas: {len(filas)} de {csv_path.name}")

    # Cache de dominios para no repetir consultas MX
    cache_mx = {}

    def mx_ok(dom):
        if dom not in cache_mx:
            cache_mx[dom] = has_mx(dom)
        return cache_mx[dom]

    ok_rows, problema_rows = [], []
    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futuros = {}
        for fila in filas:
            email = (fila.get("email") or "").strip()
            if email and sintactically_valid(email):
                dom = dominio_email(email)
                if dom not in DOMINIOS_FEDERACION and dom not in DESECHABLES:
                    futuros[pool.submit(mx_ok, dom)] = fila
        # clasificar las que no requieren DNS ya
        sin_dns = []
        for fila in filas:
            email = (fila.get("email") or "").strip()
            if not email or not sintactically_valid(email) or \
               dominio_email(email) in DOMINIOS_FEDERACION or \
               dominio_email(email) in DESECHABLES:
                sin_dns.append(fila)

        for fut in as_completed(futuros):
            fila = futuros[fut]
            try:
                mx_ok_result = fut.result()
                if mx_ok_result:
                    fila[CAMPO_MOTIVO] = ""
                    ok_rows.append(fila)
                else:
                    fila[CAMPO_MOTIVO] = "no MX record"
                    problema_rows.append(fila)
            except Exception:
                fila[CAMPO_MOTIVO] = "no MX record"
                problema_rows.append(fila)

        for fila in sin_dns:
            f2, ok = clasificar(fila)
            (ok_rows if ok else problema_rows).append(f2)

    ok_path = CLEAN_DIR / "clubs_isquad_cv_ok.csv"
    prob_path = CLEAN_DIR / "clubs_isquad_cv_problema.csv"
    cols_ok = list(filas[0].keys()) if filas else ["email"]
    cols_prob = cols_ok + [CAMPO_MOTIVO]
    with open(ok_path, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols_ok)
        w.writeheader(); w.writerows(ok_rows)
    with open(prob_path, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols_prob)
        w.writeheader(); w.writerows(problema_rows)

    from collections import Counter
    motivos = Counter(r[CAMPO_MOTIVO] for r in problema_rows)
    print(f"\n[validar] OK (con MX): {len(ok_rows)}")
    print(f"[validar] Problema: {len(problema_rows)}")
    for m, n in motivos.most_common():
        print(f"   - {m:22} {n}")
    print(f"[validar] Guardados: {ok_path} | {prob_path}")


if __name__ == "__main__":
    main()
