# -*- coding: utf-8 -*-
"""Inserta campos 'Nombre Emisor' y 'Cargo Emisor' en el MODAL SMTP de modals.php,
justo ANTES del campo 'Email Emisor' (al inicio del formulario)."""
import io

path = r"c:\laragon\www\scrapperclub\public_html\outbound\tabs\modals.php"

with io.open(path, "r", encoding="utf-8", newline="") as f:
    content = f.read()

# 1) Si ya existen los campos (por una ejecución previa), eliminarlos primero
#    para evitar duplicados. Bloque actual insertado al final (antes de botones).
old_block = (
    '<div class="grid grid-cols-2 gap-2">'
    '<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Nombre Emisor</label>'
    '<input type="text" x-model="sf.nombre_emisor" placeholder="Ej: Equipo Comercial" '
    'class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>'
    '<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Cargo Emisor</label>'
    '<input type="text" x-model="sf.cargo_emisor" placeholder="Ej: Responsable de Ventas" '
    'class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>'
    '</div>'
)
if old_block in content:
    content = content.replace(old_block, "", 1)

# 2) Anchor: el bloque de "Email Emisor" que abre el formulario del modal SMTP.
anchor = '<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Email Emisor</label><input type="email" x-model="sf.email"'

if anchor not in content:
    raise SystemExit("ERROR: anchor 'Email Emisor' no encontrado")

insertion = (
    '<div class="grid grid-cols-2 gap-2">'
    '<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Nombre Emisor</label>'
    '<input type="text" x-model="sf.nombre_emisor" placeholder="Ej: Equipo Comercial" '
    'class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>'
    '<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Cargo Emisor</label>'
    '<input type="text" x-model="sf.cargo_emisor" placeholder="Ej: Responsable de Ventas" '
    'class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>'
    '</div>'
)

new_content = content.replace(anchor, insertion + anchor, 1)

with io.open(path, "w", encoding="utf-8", newline="") as f:
    f.write(new_content)

print("OK: campos insertados antes de Email Emisor")
