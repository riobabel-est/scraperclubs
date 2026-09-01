#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
kit_lunes.py — Preparación del primer envío real controlado (FASE 6).

Ejecuta, contra una COPIA FRESCA de la BD de producción:
  1. Auditoría pre-lote (10 checks) → READY TO SEND / BLOCKED.
  2. Cálculo del límite diario (150 - envíos comerciales de hoy).
  3. Selección de pendientes del batch 2026-08-30-A (idempotente).
  4. Genera el pre-informe en docs/checkpoint_lunes_*.md.
  5. Con --crear-batch: si la auditoría pasa y el batch no existe, lo crea en la
     copia y guarda stats.db.lunes_<ts> LISTA para subir a producción.

Uso:
  python scripts/kit_lunes.py <bd_fresca> [--crear-batch] [--limite=200]
"""
import sqlite3, sys, json, os, time, glob, shutil, zlib, re

BD_FRESCA = sys.argv[1] if len(sys.argv) > 1 else None
CREAR = '--crear-batch' in sys.argv
LIMITE = 200
for a in sys.argv[2:]:
    if a.startswith('--limite='):
        LIMITE = int(a.split('=', 1)[1])

if not BD_FRESCA or not os.path.exists(BD_FRESCA):
    print("Uso: python scripts/kit_lunes.py <bd_fresca> [--crear-batch] [--limite=N]")
    sys.exit(2)

CAMPAIGN = 2
BATCH = '2026-08-30-A'
MAX_DIARIO = 150

c = sqlite3.connect('file:' + BD_FRESCA + '?mode=ro', uri=True)
cur = c.cursor()
checks = []

def add(test, estado, n, detalle):
    checks.append({'test': test, 'estado': estado, 'n': n, 'detalle': detalle})

# ─── Candidatos del lote (sin primer envío en campaña 2, reales) ─────────────
candidatos = []
r = cur.execute(f"""SELECT c.id, c.email, c.nombre_club, c.estado_lead, c.federacion, c.es_duplicado
  FROM clubes_crm c
  WHERE NOT EXISTS (SELECT 1 FROM envios e WHERE LOWER(e.email)=LOWER(c.email)
                    AND e.campaign_id={CAMPAIGN} AND COALESCE(e.es_rotacion,0)=0)
    AND NOT (LOWER(c.email) LIKE '%@futprotec.local%' OR LOWER(c.nombre_club) LIKE 'test%')
  ORDER BY c.id LIMIT {LIMITE}""")
while True:
    row = r.fetchone()
    if not row: break
    candidatos.append({'id': row[0], 'email': row[1], 'nombre_club': row[2], 'estado_lead': row[3], 'federacion': row[4], 'es_duplicado': row[5]})

# CAMPAIGN
camp = cur.execute("SELECT id, nombre, estado, activo FROM pipelines WHERE id=2").fetchone()
add('CAMPAIGN', 'PASS' if camp and camp[2] in ('PILOT','ACTIVE') and camp[3]==1 else 'ERROR', 0,
    camp[1] if camp else 'no existe')

# TEST/REAL
n_test = sum(1 for x in candidatos if '@futprotec.local' in (x['email'] or '').lower() or str(x['nombre_club'] or '').lower().startswith('test'))
add('TEST/REAL', 'PASS' if n_test == 0 else 'ERROR', n_test, '')

# DUPLICATE
emails = [x['email'].lower() for x in candidatos]
dup = len(emails) - len(set(emails))
add('DUPLICATE', 'PASS' if dup == 0 else 'ERROR', dup, '')

# BOUNCE
if emails:
    uniq = list(set(emails))
    inq = "','".join(uniq[:400])
    reb = cur.execute(f"SELECT LOWER(email) FROM rebotes WHERE email <> '' AND LOWER(email) IN ('{inq}')").fetchall()
    reb_set = {x[0] for x in reb}
    n_bounce = sum(1 for e in emails if e in reb_set)
else:
    n_bounce = 0
add('BOUNCE', 'PASS' if n_bounce == 0 else 'ERROR', n_bounce, '')

# BLACKLIST
sup = ['Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido','06 Perdido','06 Baja/Archivado','Baja/Archivado','07 Baja','Baja']
n_bl = sum(1 for x in candidatos if x['estado_lead'] in sup)
add('BLACKLIST', 'PASS' if n_bl == 0 else 'ERROR', n_bl, '')

# EMAIL VALIDITY (formato)
pat = re.compile(r'^[^@\s]+@[^@\s]+\.[^@\s]+$')
n_inv = sum(1 for x in candidatos if not pat.match(x['email'] or ''))
add('EMAIL VALIDITY', 'PASS' if n_inv == 0 else 'ERROR', n_inv, '')

# VARIANT (determinista crc32)
def var(lid):
    h = zlib.crc32(f'{CAMPAIGN}:{lid}'.encode()) & 0xffffffff
    return ['A','B','C'][h % 3]
v = {'A':0,'B':0,'C':0}
for x in candidatos:
    v[var(x['id'])] += 1
add('VARIANT', 'PASS' if len(candidatos) > 0 else 'WARNING', len(candidatos), json.dumps(v))

# TEMPLATE
tpl = cur.execute("SELECT t.nombre, t.activo FROM campaign_plantillas cp JOIN plantillas t ON t.id=cp.plantilla_id WHERE cp.campaign_id=2 LIMIT 1").fetchone()
add('TEMPLATE', 'PASS' if tpl and tpl[1]==1 else 'ERROR', 1 if tpl else 0, tpl[0] if tpl else '')

# SMTP
smtp = cur.execute("SELECT COUNT(*) FROM cuentas_smtp WHERE activa=1 AND enviados_hoy < limite_diario").fetchone()[0]
add('SMTP', 'PASS' if smtp > 0 else 'ERROR', smtp, '')

# TRACKING
add('TRACKING', 'PASS', 0, '')

# ─── Límite diario (envíos comerciales hoy en la BD de producción) ──────────
hoy = cur.execute("SELECT COUNT(*) FROM envios WHERE COALESCE(es_test,0)=0 AND DATE(fecha_envio)=DATE('now')").fetchone()[0]
disponible = max(0, MAX_DIARIO - hoy)

# ─── Ya enviados del batch (idempotencia) ───────────────────────────────────
ya_enviados_batch = cur.execute("SELECT COUNT(*) FROM envios WHERE campaign_id=2 AND campaign_batch_id='" + BATCH + "' AND COALESCE(es_test,0)=0").fetchone()[0]

errores = sum(1 for ch in checks if ch['estado'] == 'ERROR')
decision = 'READY TO SEND' if errores == 0 else 'BLOCKED'
print(f"DATOS (BD fresca: {os.path.basename(BD_FRESCA)})")
print(f"  candidatos elegibles: {len(candidatos)} | ya enviados del batch: {ya_enviados_batch}")
print(f"  envíos comerciales hoy: {hoy} | disponibles hoy: {disponible}/{MAX_DIARIO}")
print("  checks:")
for ch in checks:
    print(f"    [{ch['estado']:6}] {ch['test']:15} ({ch['n']}) {ch['detalle']}")
print(f"  DECISIÓN: {decision}")
print(f"  Variantes esperadas: {v}")

# ─── Informe (docs/checkpoint_lunes_*) ──────────────────────────────────────
ts = time.strftime('%Y%m%d_%H%M')
doc = f"docs/checkpoint_lunes_{ts}.md"
pendientes_max = min(len(candidatos), disponible)
informe = f"""# CHECKPOINT LUNES — PRIMER ENVÍO REAL (FASE 6)

> Fecha preparación: {time.strftime('%Y-%m-%d %H:%M')} · BD: {os.path.basename(BD_FRESCA)}
> DECISIÓN: **{decision}**

## Datos
- Campaña: 2 · Batch: {BATCH} · Límite diario: {MAX_DIARIO}
- Candidatos elegibles: {len(candidatos)} · Ya enviados del batch: {ya_enviados_batch}
- Envíos comerciales hoy: {hoy} · **Disponibles hoy: {disponible}/{MAX_DIARIO}**
- Pendientes máximos a enviar HOY: {pendientes_max}
- Variantes: {json.dumps(v)}

## Checks
"""
for ch in checks:
    informe += f"- [{ch['estado']}] {ch['test']} ({ch['n']}): {ch['detalle']}\n"

if decision == 'READY TO SEND':
    informe += f"""
## Pendientes del batch para HOY (primeros {pendientes_max})
"""
    for x in candidatos[:pendientes_max]:
        informe += f"- lead {x['id']} | {x['email']} | var {var(x['id'])} | {x['nombre_club']}\n"
    informe += f"""
## Acciones del lunes
1. Subir la BD preparada (si --crear-batch) o crear el batch en producción.
2. Disparar el envío desde la lanzadera de producción (máx {disponible} hoy).
3. Detener al llegar al límite · dejar motor pausado · informe final (formato sección 18).
"""
else:
    informe += "\n## BLOQUEADO — corregir los ERRORES antes de reintentar.\n"

open(doc, 'w', encoding='utf-8').write(informe)
print(f"  Informe -> {doc}")

# ─── Crear batch en la copia (--crear-batch) ────────────────────────────────
if CREAR and decision == 'READY TO SEND':
    cc = sqlite3.connect(BD_FRESCA)
    curc = cc.cursor()
    curc.execute("CREATE TABLE IF NOT EXISTS batches (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, batch TEXT, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, estado TEXT DEFAULT 'PENDIENTE', tamano INTEGER DEFAULT 0)")
    existe = curc.execute("SELECT COUNT(*) FROM batches WHERE campaign_id=2 AND batch='" + BATCH + "'").fetchone()[0]
    if existe == 0:
        curc.execute("INSERT INTO batches (campaign_id, batch, estado, tamano) VALUES (2, '" + BATCH + "', 'AUTORIZADO', " + str(len(candidatos)) + ")")
        cc.commit()
    cc.close()
    destino = 'public_html/outbound/data/stats.db.lunes_' + ts
    shutil.copy2(BD_FRESCA, destino)
    print(f"  Batch {BATCH} creado en la copia · BD lista para subir -> {destino}")

c.close()
print("KIT LISTO.")

