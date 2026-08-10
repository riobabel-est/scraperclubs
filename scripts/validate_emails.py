import csv
import glob
import os
import re
from pathlib import Path

import dns.resolver

OUTPUT_DIR = Path('output')
CLEAN_DIR = OUTPUT_DIR / 'clean'
CLEAN_DIR.mkdir(parents=True, exist_ok=True)

EMAIL_PATTERN = re.compile(
    r"^(?P<local>[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+)@(?P<domain>[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)$"
)

SOURCE_FILES = sorted(glob.glob(str(OUTPUT_DIR / 'clubs_nova_*.csv')))

FIELDNAMES = ['federacion', 'nombre', 'telefono', 'email']


def clean_email(value: str) -> str:
    return value.strip() if value else ''


def syntactically_valid(email: str) -> bool:
    if not email:
        return False
    return bool(EMAIL_PATTERN.match(email))


def has_mx_record(domain: str) -> bool:
    try:
        answers = dns.resolver.resolve(domain, 'MX', lifetime=10.0)
        return bool(answers)
    except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN, dns.resolver.NoNameservers, dns.resolver.LifetimeTimeout):
        return False


if __name__ == '__main__':
    descartados = []
    sintaxis_ok = []
    total_rows = 0
    with open(CLEAN_DIR / 'contactos_descartados.csv', 'w', encoding='utf-8', newline='') as f_desc, \
            open(CLEAN_DIR / 'contactos_sintaxis_ok.csv', 'w', encoding='utf-8', newline='') as f_ok:
        desc_writer = csv.DictWriter(f_desc, fieldnames=FIELDNAMES + ['motivo'])
        ok_writer = csv.DictWriter(f_ok, fieldnames=FIELDNAMES)
        desc_writer.writeheader()
        ok_writer.writeheader()

        for filepath in SOURCE_FILES:
            with open(filepath, 'r', encoding='utf-8-sig', newline='') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    total_rows += 1
                    row = {k: v.strip() for k, v in row.items()}
                    email = clean_email(row.get('email', ''))
                    if not syntactically_valid(email):
                        row['motivo'] = 'syntax invalid' if email else 'empty email'
                        desc_writer.writerow(row)
                        continue
                    domain = email.split('@', 1)[1]
                    if not has_mx_record(domain):
                        row['motivo'] = 'no MX record'
                        desc_writer.writerow(row)
                        continue
                    ok_writer.writerow({k: row.get(k, '') for k in FIELDNAMES})

    print(f'Total rows processed: {total_rows}')
    print(f'contactos_descartados.csv written with header + motivo')
    print(f'contactos_sintaxis_ok.csv written with header')
