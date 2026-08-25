#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Reemplaza las llamadas a ->conectar($cuenta['usuario'], $cuenta['password'])
por ->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''))
en los archivos IMAP/POP3 que aún leen la contraseña en claro.

Los archivos ya incluyen inc/imap_respuestas.php o inc/pop3_respuestas.php,
que a su vez cargan inc/crypto.php, por lo que la función está disponible.
"""
import re
import sys

ARCHIVOS = [
    "public_html/outbound/cli/imap_respuestas.php",
    "public_html/outbound/imap_respuestas_runner.php",
    "public_html/outbound/cli/imap_respuestas_runner.php",
    "public_html/outbound/cli/imap_respuestas_cron.php",
    "public_html/outbound/api/imap_sync.php",
]

# Patrón: ->conectar($cuenta['usuario'], $cuenta['password']);
# Con variantes de espacios (incluye el caso sin espacio: $cuenta['usuario'],$cuenta['password'])
PATRON = re.compile(
    r"->conectar\(\s*\$cuenta\['usuario'\]\s*,\s*\$cuenta\['password'\]\s*\)"
)

REEMPLAZO = "->conectar($cuenta['usuario'], futprotec_descifrarPassword($cuenta['password'] ?? ''))"

total_reemplazos = 0
for ruta in ARCHIVOS:
    try:
        with open(ruta, "r", encoding="utf-8") as f:
            contenido = f.read()
    except FileNotFoundError:
        print(f"[SKIP] No existe: {ruta}")
        continue

    nuevo, n = PATRON.subn(REEMPLAZO, contenido)
    if n > 0:
        with open(ruta, "w", encoding="utf-8") as f:
            f.write(nuevo)
        print(f"[OK] {ruta}: {n} reemplazo(s)")
        total_reemplazos += n
    else:
        print(f"[SIN CAMBIOS] {ruta}")

print(f"\nTotal reemplazos: {total_reemplazos}")
