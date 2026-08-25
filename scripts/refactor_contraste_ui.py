#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Refactor de contraste UI (WCAG AA 4.5:1) para el módulo outbound.

Reemplaza colores de texto que NO cumplen 4.5:1 sobre fondos oscuros
(bg-slate-900/950/800) por equivalentes accesibles, unificando estilos.

Reglas de mapeo (sobre fondos oscuros slate):
  text-slate-500 (#64748b, ~4.6:1 marginal) -> text-slate-400 (#94a3b8, ~7.4:1)
  text-slate-600 (#475569, ~3.1:1 FALLA)    -> text-slate-400
  text-slate-700 (#334155, ~2.2:1 FALLA)    -> text-slate-400
  text-gray-500/600/700                     -> text-slate-400
  text-zinc-500/600/700                     -> text-slate-400

Se procesan SOLO los archivos de UI del módulo outbound.
"""
import os
import re
import sys

BASE = os.path.join(os.path.dirname(__file__), '..', 'public_html', 'outbound')

# Archivos de UI a procesar
UI_FILES = [
    'dashboard.php',
    'tabs/analytics.php',
    'tabs/editor.php',
    'tabs/followups.php',
    'tabs/gestor.php',
    'tabs/kanban.php',
    'tabs/lanzadera.php',
    'tabs/lista_negra.php',
    'tabs/modals.php',
    'tabs/respuestas.php',
    'tabs/smtp.php',
    'inc/login_form.php',
    'js/app.js',
]


# Mapeo de colores de texto problemáticos -> accesibles
# Orden importa: reemplazar primero los más específicos (600, 700) antes que 500
REPLACEMENTS = [
    # text-slate-600 -> text-slate-400
    (r'text-slate-600', 'text-slate-400'),
    # text-slate-700 -> text-slate-400
    (r'text-slate-700', 'text-slate-400'),
    # text-slate-500 -> text-slate-400 (garantiza 4.5:1 con margen)
    (r'text-slate-500', 'text-slate-400'),
    # text-gray-*
    (r'text-gray-600', 'text-slate-400'),
    (r'text-gray-700', 'text-slate-400'),
    (r'text-gray-500', 'text-slate-400'),
    # text-zinc-*
    (r'text-zinc-600', 'text-slate-400'),
    (r'text-zinc-700', 'text-slate-400'),
    (r'text-zinc-500', 'text-slate-400'),
]

def process_file(path):
    """Procesa un archivo y aplica los reemplazos de contraste."""
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    original = content
    changes = 0

    for pattern, replacement in REPLACEMENTS:
        # Contar ocurrencias antes de reemplazar
        matches = re.findall(pattern, content)
        if matches:
            content = re.sub(pattern, replacement, content)
            changes += len(matches)

    if content != original:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        return changes
    return 0

def main():
    total = 0
    processed = []
    for rel in UI_FILES:
        path = os.path.join(BASE, rel)
        if not os.path.exists(path):
            print(f"[SKIP] No existe: {rel}")
            continue
        n = process_file(path)
        if n > 0:
            processed.append((rel, n))
            total += n
            print(f"[OK] {rel}: {n} reemplazos")
        else:
            print(f"[--] {rel}: sin cambios")

    print(f"\n=== TOTAL: {total} reemplazos en {len(processed)} archivos ===")

if __name__ == '__main__':
    main()
