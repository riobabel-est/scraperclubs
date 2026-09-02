#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
preparar_bd_deploy.py — Prepara la BD que se subirá a SiteGround.

Parte de una COPIA REMOTA fresca (descargada de producción), le aplica TODAS las
migraciones de las fases (1-5 + columna ruta de adjuntos) y migra los adjuntos
BLOB a disco (data/adjuntos/<club>/enviados|recibidos).

Uso: python scripts/preparar_bd_deploy.py <bd_remota_fresca>
Genera: data/stats.db.deploy_<ts>  (la que se subirá como stats.db en el servidor)
"""
import sqlite3, os, re, sys, time, shutil, email.utils

ORIGEN = sys.argv[1] if len(sys.argv) > 1 else None
if not ORIGEN:
    print("Uso: python scripts/preparar_bd_deploy.py <bd_remota_fresca>")
    sys.exit(2)

ts = time.strftime('%Y%m%d_%H%M%S')
DESTINO = 'public_html/outbound/data/stats.db.deploy_' + ts
shutil.copy2(ORIGEN, DESTINO)

con = sqlite3.connect(DESTINO)
cur = con.cursor()

def addcol(tabla, col, ddl):
    if col not in [c[1] for c in cur.execute(f'PRAGMA table_info({tabla})').fetchall()]:
        cur.execute(ddl)

# ─── FASE 1: rebotes (ALTERs aditivos) ───────────────────────────────────────
addcol('rebotes','envio_id','ALTER TABLE rebotes ADD COLUMN envio_id INTEGER')
addcol('rebotes','lead_id','ALTER TABLE rebotes ADD COLUMN lead_id INTEGER')
addcol('rebotes','campaign_id','ALTER TABLE rebotes ADD COLUMN campaign_id INTEGER')
addcol('rebotes','smtp_code','ALTER TABLE rebotes ADD COLUMN smtp_code TEXT')
addcol('rebotes','atribucion_parcial','ALTER TABLE rebotes ADD COLUMN atribucion_parcial INTEGER DEFAULT 0')

# ─── FASE 1b: poblar rebotes desde respuestas.es_rebote=1 ────────────────────
if cur.execute('SELECT COUNT(*) FROM rebotes').fetchone()[0] == 0:
    cur.execute('''INSERT INTO rebotes (email, motivo, fecha_rebote, envio_id, lead_id, campaign_id, smtp_code, atribucion_parcial)
    WITH parses AS (
      SELECT r.id,
        CASE WHEN instr(r.cuerpo,'Message-ID: <') > 0
          THEN substr(r.cuerpo, instr(r.cuerpo,'Message-ID: <')+13, instr(substr(r.cuerpo, instr(r.cuerpo,'Message-ID: <')+13), '>') - 1)
          ELSE NULL END AS mid_cuerpo,
        CASE WHEN instr(r.cuerpo,'Final-Recipient: rfc822;') > 0
          THEN trim(replace(replace(substr(r.cuerpo, instr(r.cuerpo,'Final-Recipient: rfc822;')+24, instr(substr(r.cuerpo, instr(r.cuerpo,'Final-Recipient: rfc822;')+24), char(10)) - 1), char(13), ''), char(10), ''))
          ELSE NULL END AS final_recip,
        CASE WHEN instr(r.cuerpo,'Status: ') > 0
          THEN substr(r.cuerpo, instr(r.cuerpo,'Status: ')+8, instr(substr(r.cuerpo, instr(r.cuerpo,'Status: ')+8), char(10)) - 1)
          ELSE NULL END AS status_code,
        CASE WHEN instr(r.cuerpo,'Diagnostic-Code: smtp;') > 0
          THEN ltrim(replace(replace(substr(r.cuerpo, instr(r.cuerpo,'Diagnostic-Code: smtp;')+23, 180), char(13), ''), char(10), ' '))
          ELSE NULL END AS diag,
        r.message_id_original, r.cuerpo, r.creado_el
      FROM respuestas r WHERE r.es_rebote = 1
    )
    SELECT COALESCE(NULLIF(e1.email,''), NULLIF(e2.email,''), p.final_recip, '') AS email, p.diag AS motivo,
      COALESCE(p.creado_el, datetime('now')) AS fecha_rebote,
      COALESCE(e1.id, e2.id) AS envio_id, COALESCE(e1.lead_id, e2.lead_id) AS lead_id,
      COALESCE(e1.campaign_id, e2.campaign_id) AS campaign_id, p.status_code AS smtp_code,
      CASE WHEN (length(p.cuerpo) = 0 OR length(p.cuerpo) IS NULL) OR (e1.id IS NULL AND e2.id IS NULL) THEN 1 ELSE 0 END AS atribucion_parcial
    FROM parses p
    LEFT JOIN envios e1 ON e1.message_id = '<' || p.mid_cuerpo || '>'
    LEFT JOIN envios e2 ON e2.message_id = '<' || REPLACE(p.message_id_original,'<','') || '>' ''')

# ─── FASE 2: envios / comunicaciones_log / respuestas / oportunidades ────────
for col in ['variant_original','campaign_batch_id','parent_envio_id','respuesta_origen_id']:
    addcol('envios', col, f'ALTER TABLE envios ADD COLUMN {col} ' + ('VARCHAR(1)' if col=='variant_original' else 'TEXT' if col=='campaign_batch_id' else 'INTEGER'))
cur.execute('CREATE INDEX IF NOT EXISTS idx_envios_parent ON envios(parent_envio_id)')
addcol('comunicaciones_log','metadata','ALTER TABLE comunicaciones_log ADD COLUMN metadata TEXT')
for col in ['fecha_respuesta_iso','intencion','proxima_accion']:
    addcol('respuestas', col, 'ALTER TABLE respuestas ADD COLUMN '+col+' TEXT')
cur.execute('CREATE INDEX IF NOT EXISTS idx_respuestas_intencion ON respuestas(intencion)')
# ─── T-4 (2026-09-02): índices de respuestas (Bandeja, idempotencia, rendimiento) ───
# (las columnas ya existen en respuestas; solo se aseguran índices)
for _col, _name in [('lead_id','idx_respuestas_lead'), ('cuenta_uid','idx_respuestas_cuenta_uid'),
                    ('hash_auxiliar','idx_respuestas_hash'), ('estado_conversacion','idx_respuestas_estado_conv'),
                    ('fecha_respuesta','idx_respuestas_fecha'), ('carpeta','idx_respuestas_carpeta')]:
    cur.execute(f'CREATE INDEX IF NOT EXISTS {_name} ON respuestas({_col})')
cur.execute('''CREATE TABLE IF NOT EXISTS oportunidades (
  id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER NOT NULL, campaign_id INTEGER,
  estado TEXT NOT NULL DEFAULT 'NUEVA', origen TEXT, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion DATETIME, cantidad_estimada INTEGER, nivel_interes TEXT, proxima_accion TEXT,
  fecha_proxima_accion DATETIME, motivo_perdida TEXT, importe_potencial REAL, es_test INTEGER DEFAULT 0, notas TEXT)''')
cur.execute('CREATE INDEX IF NOT EXISTS idx_oportunidades_lead ON oportunidades(lead_id)')
cur.execute('CREATE INDEX IF NOT EXISTS idx_oportunidades_campaign ON oportunidades(campaign_id)')

# ─── FASE 3: clics + vista ───────────────────────────────────────────────────
cur.execute('''CREATE TABLE IF NOT EXISTS clics (
  id INTEGER PRIMARY KEY AUTOINCREMENT, envio_id INTEGER, lead_id INTEGER, campaign_id INTEGER,
  tracking_id TEXT, url_original TEXT, tipo_cta TEXT, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_agent TEXT, ip TEXT, es_test INTEGER DEFAULT 0)''')
for ddl in ['CREATE INDEX IF NOT EXISTS idx_clics_envio ON clics(envio_id)','CREATE INDEX IF NOT EXISTS idx_clics_lead ON clics(lead_id)','CREATE INDEX IF NOT EXISTS idx_clics_campaign ON clics(campaign_id)','CREATE INDEX IF NOT EXISTS idx_clics_tracking ON clics(tracking_id)']:
    cur.execute(ddl)
cur.execute('DROP VIEW IF EXISTS vw_aperturas_analiticas')
cur.execute('''CREATE VIEW vw_aperturas_analiticas AS
SELECT e.id AS envio_id, e.lead_id, e.campaign_id, e.tracking_id, e.es_test,
  MIN(a.fecha_apertura) AS primera_apertura, MAX(a.fecha_apertura) AS ultima_apertura,
  COUNT(a.id) AS num_aperturas, CASE WHEN COUNT(a.id) > 0 THEN 1 ELSE 0 END AS opened,
  CASE WHEN EXISTS (SELECT 1 FROM aperturas a2 WHERE a2.tracking_id=e.tracking_id
    AND LOWER(COALESCE(a2.user_agent,'')) NOT LIKE '%bot%' AND LOWER(COALESCE(a2.user_agent,'')) NOT LIKE '%spider%'
    AND LOWER(COALESCE(a2.user_agent,'')) NOT LIKE '%preview%' AND LOWER(COALESCE(a2.user_agent,'')) NOT LIKE '%whatsapp%')
  THEN 1 ELSE 0 END AS apertura_humana_probable
FROM envios e LEFT JOIN aperturas a ON a.tracking_id=e.tracking_id
GROUP BY e.id, e.lead_id, e.campaign_id, e.tracking_id, e.es_test''')

# ─── FASE 4: presupuestos / mockups ─────────────────────────────────────────
for col in ['campaign_id','opportunity_id','respuesta_origen_id','envio_origen_id','fecha_envio','fecha_aprobacion','fecha_rechazo','motivo_rechazo']:
    addcol('presupuestos', col, f'ALTER TABLE presupuestos ADD COLUMN {col} ' + ('INTEGER' if col in ('campaign_id','opportunity_id','respuesta_origen_id','envio_origen_id') else 'DATETIME' if col.startswith('fecha') else 'TEXT'))
cur.execute('CREATE INDEX IF NOT EXISTS idx_presupuestos_opportunity ON presupuestos(opportunity_id)')
for col in ['campaign_id','opportunity_id','presupuesto_id','fecha_creacion','version']:
    addcol('mockups', col, f'ALTER TABLE mockups ADD COLUMN {col} ' + ('INTEGER' if col in ('campaign_id','opportunity_id','presupuesto_id') else 'DATETIME' if col=='fecha_creacion' else 'INTEGER DEFAULT 1'))
cur.execute('CREATE INDEX IF NOT EXISTS idx_mockups_opportunity ON mockups(opportunity_id)')

# ─── FASE 5: batches ────────────────────────────────────────────────────────
cur.execute('''CREATE TABLE IF NOT EXISTS batches (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, batch TEXT, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, estado TEXT DEFAULT 'PENDIENTE', tamano INTEGER DEFAULT 0)''')
cur.execute('CREATE INDEX IF NOT EXISTS idx_batches_campaign ON batches(campaign_id)')

# ─── T-5: histórico temporal de estados Kanban ──────────────────────────────
cur.execute('''CREATE TABLE IF NOT EXISTS lead_estado_hist (
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER NOT NULL, campaign_id INTEGER DEFAULT NULL,
    estado_anterior TEXT DEFAULT '', estado_nuevo TEXT DEFAULT '', origen TEXT DEFAULT 'manual',
    creado_el DATETIME DEFAULT CURRENT_TIMESTAMP)''')
cur.execute('CREATE INDEX IF NOT EXISTS idx_leh_lead ON lead_estado_hist(lead_id)')
cur.execute('CREATE INDEX IF NOT EXISTS idx_leh_campaign ON lead_estado_hist(campaign_id)')
cur.execute('CREATE INDEX IF NOT EXISTS idx_leh_fecha ON lead_estado_hist(creado_el)')

# ─── FASE ADJUNTOS: columna ruta ────────────────────────────────────────────
for t in ['envios_adjuntos','respuestas_adjuntos']:
    addcol(t,'ruta','ALTER TABLE '+t+' ADD COLUMN ruta TEXT')

# ─── Backfill fecha_respuesta_iso ───────────────────────────────────────────
for rid, fr in cur.execute("SELECT id, fecha_respuesta FROM respuestas WHERE fecha_respuesta IS NOT NULL AND fecha_respuesta <> '' AND (fecha_respuesta_iso IS NULL OR fecha_respuesta_iso='')").fetchall():
    try:
        iso = email.utils.parsedate_to_datetime(fr).replace(tzinfo=None).strftime('%Y-%m-%d %H:%M:%S')
        cur.execute('UPDATE respuestas SET fecha_respuesta_iso=? WHERE id=?', (iso, rid))
    except Exception:
        pass

# ─── Migrar adjuntos BLOB a disco ───────────────────────────────────────────
BASE = 'public_html/outbound/data/adjuntos'
def sanit(n):
    n = re.sub(r'[\\\\/:*?"<>|\r\n]+', '_', str(n)).strip()
    return n or 'adjunto'
def migrar(tabla, tipo, id_col, ref_tabla):
    n=0
    for a_id, nombre, datos, ref in cur.execute(f'SELECT id, nombre, datos, {id_col} FROM {tabla}').fetchall():
        club = 0
        r = cur.execute(f'SELECT COALESCE(lead_id,0) FROM {ref_tabla} WHERE id=?', (ref,)).fetchone()
        if r: club = r[0]
        carp = os.path.join(BASE, str(club), tipo); os.makedirs(carp, exist_ok=True)
        fname = sanit(nombre)
        fp = os.path.join(carp, fname)
        if not os.path.exists(fp):
            with open(fp,'wb') as f: f.write(datos or b'')
        ruta_rel = os.path.join('adjuntos', str(club), tipo, fname).replace('\\\\','/')
        cur.execute(f'UPDATE {tabla} SET ruta=? WHERE id=?', (ruta_rel, a_id))
        n+=1
    return n
nr = migrar('respuestas_adjuntos','recibidos','respuesta_id','respuestas')
ne = migrar('envios_adjuntos','enviados','envio_id','envios')

cur.execute("INSERT INTO _migraciones (script, fase, ejecutado_en, exitoso, detalles) VALUES ('preparar_bd_deploy.py','DEPLOY',datetime('now'),1,'Migraciones F1-F5 + adjuntos ruta aplicadas sobre copia remota fresca.')")
con.commit()
print('BD DE DEPLOY:', DESTINO)
print('conteos:', 'envios', cur.execute('SELECT COUNT(*) FROM envios').fetchone()[0], '| aperturas', cur.execute('SELECT COUNT(*) FROM aperturas').fetchone()[0], '| respuestas', cur.execute('SELECT COUNT(*) FROM respuestas').fetchone()[0], '| rebotes', cur.execute('SELECT COUNT(*) FROM rebotes').fetchone()[0])
print('adjuntos a disco: recibidos=%d enviados=%d' % (nr, ne))
print('integrity:', cur.execute('PRAGMA integrity_check').fetchone()[0])
con.close()


