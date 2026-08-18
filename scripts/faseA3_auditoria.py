#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
faseA3_auditoria.py

FASE A.3 — AUDITORÍA INTEGRAL Y RECONCILIACIÓN TOTAL DE PRODUCCIÓN (READ-ONLY).

Descarga data/stats.db remota y ejecuta TODAS las auditorías de la fase A.3:
  1. integridad estructural (integrity_check, foreign_key_check)
  2. leads / clubes (duplicados, emails inválidos, estados Kanban)
  3. TEST/REAL (matriz de clasificación determinista)
  4. pipelines / campañas
  5. lead_pipelines
  6. envios (coherencia lead/campaign/email/club/plantilla/variante/estado/message_id)
  7. respuestas
  8. mockups
  9. presupuestos
  10. Kanban / estados
  11. A/B/C (determinismo vs asignarVariante)
  12. elegibilidad / suppression
  13. SMTP / logs
  14. esquema vs código
  15. datos legacy

Genera una tabla de hallazgos clasificados A (reparable determinista),
B (requiere decisión) y C (informativo).

NO modifica nada. Solo lectura.

USO:
  python scripts/faseA3_auditoria.py
"""
import ftplib
import os
import sys
import time
import hashlib
import sqlite3
import tempfile
import re
import zlib

# Forzar salida UTF-8 (evita UnicodeEncodeError en Windows cp1252)
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# ─────────────────────────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────────────────────────
def load_env(path=".env"):
    env = {}
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env

env = load_env()
HOST = env.get("FTP_HOST", "ftp.getfutprotec.com")
USER = env.get("FTP_USER", "")
PASS = env.get("FTP_PASS", "")
REMOTE_DB = "/getfutprotec.com/public_html/outbound/data/stats.db"

# Kanban definitivo (9 columnas)
KANBAN = [
    "01 Sin Contactar", "02 Contactado", "03 Respondió", "04 Interesado",
    "05 Cualificado", "06 Propuesta", "07 Negociación", "08 Ganado", "09 Perdido",
]
KANBAN_SET = set(KANBAN)

# Estados de supresión / baja (bloquean envío comercial)
ESTADOS_SUPRESION = ["Lista Negra", "Opt-Out", "Unsubscribed", "Baja / Opt-Out", "Email Inválido"]

# Estados finales de envío (no reenviar) y retryables
ESTADOS_FINALES_ENVIO = {"enviado", "abierto"}
ESTADOS_RETRYABLE = {"pendiente", "error"}

# Estados de pipeline permitidos para envío
ESTADOS_PIPELINE_ENVIO = {"PILOT", "ACTIVE"}

# ─────────────────────────────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────────────────────────────
def file_md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

def es_lead_test(email, nombre_club):
    """Espejo de esLeadTest() de eligibilidad.php."""
    e = (email or "").lower()
    n = (nombre_club or "").lower()
    if e and "@futprotec.local" in e:
        return True
    if n and n.startswith("test"):
        return True
    return False

def es_envio_test(es_test, email, club):
    """Espejo de esEnvioTest() de eligibilidad.php."""
    if int(es_test or 0) == 1:
        return True
    e = (email or "").lower()
    c = (club or "").lower()
    if e and "@futprotec.local" in e:
        return True
    if c and c.startswith("test"):
        return True
    return False

def asignar_variante(lead_id, campaign_id):
    """Espejo de asignarVariante() de abc.php (crc32 % 3)."""
    s = f"{campaign_id}:{lead_id}"
    h = zlib.crc32(s.encode("utf-8"))
    if h < 0:
        h += 4294967296
    return ["A", "B", "C"][h % 3]

def email_valido(email):
    if not email:
        return False
    email = email.strip()
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email):
        return False
    return True

# ─────────────────────────────────────────────────────────────────────────────
# AUDITORÍA
# ─────────────────────────────────────────────────────────────────────────────
class Auditor:
    def __init__(self, db):
        self.db = db
        self.cur = db.cursor()
        self.hallazgos = []  # (entidad, id, problema, actual, esperado, regla, categoria)

    def add(self, entidad, id_, problema, actual, esperado, regla, categoria):
        self.hallazgos.append({
            "entidad": entidad, "id": id_, "problema": problema,
            "actual": actual, "esperado": esperado, "regla": regla, "categoria": categoria,
        })

    def run(self):
        self.audit_integridad()
        self.audit_leads()
        self.audit_test_real()
        self.audit_pipelines()
        self.audit_lead_pipelines()
        self.audit_envios()
        self.audit_respuestas()
        self.audit_mockups()
        self.audit_presupuestos()
        self.audit_abc()
        self.audit_elegibilidad()
        self.audit_smtp_logs()
        self.audit_esquema_codigo()
        self.audit_legacy()

    # ── 1. Integridad estructural ──────────────────────────────────────────
    def audit_integridad(self):
        self.cur.execute("PRAGMA integrity_check")
        r = self.cur.fetchone()
        if r and r[0] != "ok":
            self.add("BD", "-", "integrity_check != ok", r[0], "ok",
                     "PRAGMA integrity_check debe ser ok", "A")
        self.cur.execute("PRAGMA foreign_key_check")
        fk = self.cur.fetchall()
        for row in fk:
            self.add("FK", row[0], "violación foreign key", dict(row), "sin violación",
                     "PRAGMA foreign_key_check sin violaciones", "A")

    # ── 2. Leads / clubes ──────────────────────────────────────────────────
    def audit_leads(self):
        # emails duplicados (por lower)
        self.cur.execute("""
            SELECT LOWER(email) AS e, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM clubes_crm GROUP BY LOWER(email) HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("clubes_crm", r["ids"], "email duplicado", r["n"], "1",
                     "email UNIQUE (case-insensitive)", "A")
        # emails vacíos
        self.cur.execute("SELECT id, email FROM clubes_crm WHERE email IS NULL OR TRIM(email)=''")
        for r in self.cur.fetchall():
            self.add("clubes_crm", r["id"], "email vacío", repr(r["email"]), "email válido",
                     "email NOT NULL y no vacío", "A")
        # emails inválidos
        self.cur.execute("SELECT id, email FROM clubes_crm")
        for r in self.cur.fetchall():
            if not email_valido(r["email"]):
                self.add("clubes_crm", r["id"], "email inválido", r["email"], "email válido",
                         "formato email válido", "A")
        # estados fuera del Kanban definitivo
        self.cur.execute("SELECT id, estado_lead FROM clubes_crm")
        for r in self.cur.fetchall():
            if r["estado_lead"] not in KANBAN_SET:
                self.add("clubes_crm", r["id"], "estado_lead fuera del Kanban definitivo",
                         r["estado_lead"], "estado Kanban 01-09",
                         "estado_lead ∈ Kanban definitivo (9 columnas)", "A")
        # es_duplicado=1 sin duplicado_id
        self.cur.execute("SELECT id, es_duplicado, duplicado_id FROM clubes_crm WHERE es_duplicado=1 AND duplicado_id IS NULL")
        for r in self.cur.fetchall():
            self.add("clubes_crm", r["id"], "es_duplicado=1 sin duplicado_id", "NULL",
                     "duplicado_id apuntando al original", "es_duplicado=1 ⇒ duplicado_id NOT NULL", "B")
        # duplicado_id apuntando a lead inexistente
        self.cur.execute("""
            SELECT c.id, c.duplicado_id FROM clubes_crm c
            LEFT JOIN clubes_crm o ON o.id = c.duplicado_id
            WHERE c.duplicado_id IS NOT NULL AND o.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("clubes_crm", r["id"], "duplicado_id apunta a lead inexistente",
                     r["duplicado_id"], "lead existente", "duplicado_id debe referenciar lead existente", "A")

    # ── 3. TEST/REAL ───────────────────────────────────────────────────────
    def audit_test_real(self):
        # leads: clasificación determinista vs estado
        self.cur.execute("SELECT id, email, nombre_club, estado_lead FROM clubes_crm")
        leads = self.cur.fetchall()
        for r in leads:
            is_test = es_lead_test(r["email"], r["nombre_club"])
            # Un lead TEST no debería estar en estado comercial avanzado (Ganado/Perdido)
            if is_test and r["estado_lead"] in ("08 Ganado", "09 Perdido"):
                self.add("clubes_crm", r["id"], "lead TEST en estado comercial avanzado",
                         r["estado_lead"], "estado no comercial", "lead TEST no debe estar Ganado/Perdido", "B")

        # envios: es_test vs clasificación determinista
        self.cur.execute("SELECT id, es_test, email, club, campaign_id FROM envios")
        envios = self.cur.fetchall()
        for r in envios:
            det = es_envio_test(r["es_test"], r["email"], r["club"])
            if int(r["es_test"] or 0) == 1 and not det:
                self.add("envios", r["id"], "es_test=1 pero clasificación determinista REAL",
                         "es_test=1", "es_test=0", "esEnvioTest() debe coincidir con es_test", "B")
            if int(r["es_test"] or 0) == 0 and det:
                self.add("envios", r["id"], "es_test=0 pero clasificación determinista TEST",
                         "es_test=0", "es_test=1", "esEnvioTest() debe coincidir con es_test", "A")

    # ── 4. Pipelines / campañas ────────────────────────────────────────────
    def audit_pipelines(self):
        self.cur.execute("SELECT * FROM pipelines")
        for r in self.cur.fetchall():
            # entorno válido
            if (r["entorno"] or "").lower() not in ("test", "pilot", "production"):
                self.add("pipelines", r["id"], "entorno inválido", r["entorno"],
                         "test|pilot|production", "entorno ∈ {test,pilot,production}", "A")
            # estado válido
            if (r["estado"] or "").upper() not in ("DRAFT", "PILOT", "ACTIVE", "CLOSED", "ARCHIVED"):
                self.add("pipelines", r["id"], "estado inválido", r["estado"],
                         "DRAFT|PILOT|ACTIVE|CLOSED|ARCHIVED", "estado pipeline válido", "A")
            # incoherencia entorno=test con estado PILOT/ACTIVE (campaña test tratada como comercial)
            if (r["entorno"] or "").lower() == "test" and (r["estado"] or "").upper() in ("PILOT", "ACTIVE"):
                self.add("pipelines", r["id"], "entorno=test pero estado PILOT/ACTIVE",
                         f"entorno={r['entorno']}, estado={r['estado']}",
                         "campaña test no debe estar PILOT/ACTIVE",
                         "campaña TEST no debe ser operable para envío comercial", "B")
            # incoherencia entorno=pilot/production con estado DRAFT
            if (r["entorno"] or "").lower() in ("pilot", "production") and (r["estado"] or "").upper() == "DRAFT":
                self.add("pipelines", r["id"], "entorno comercial pero estado DRAFT",
                         f"entorno={r['entorno']}, estado={r['estado']}",
                         "estado coherente con entorno",
                         "campaña comercial en DRAFT no es operable", "C")

        # envíos asociados a campañas inexistentes
        self.cur.execute("""
            SELECT e.id, e.campaign_id FROM envios e
            LEFT JOIN pipelines p ON p.id = e.campaign_id
            WHERE e.campaign_id IS NOT NULL AND p.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "campaign_id apunta a campaña inexistente",
                     r["campaign_id"], "campaña existente", "campaign_id debe existir en pipelines", "A")

        # envíos TEST asociados a campañas comerciales (no test)
        self.cur.execute("""
            SELECT e.id, e.campaign_id, e.es_test, p.entorno
            FROM envios e JOIN pipelines p ON p.id = e.campaign_id
            WHERE e.es_test=1 AND LOWER(p.entorno) != 'test'
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "envío TEST en campaña no-test",
                     f"es_test=1, campaign={r['campaign_id']}, entorno={r['entorno']}",
                     "envío TEST solo en campaña test",
                     "aislamiento TEST/REAL: envío TEST no en campaña comercial", "A")

        # envíos comerciales (es_test=0) asociados a campañas TEST
        self.cur.execute("""
            SELECT e.id, e.campaign_id, e.es_test, p.entorno
            FROM envios e JOIN pipelines p ON p.id = e.campaign_id
            WHERE COALESCE(e.es_test,0)=0 AND LOWER(p.entorno)='test'
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "envío comercial en campaña TEST",
                     f"es_test=0, campaign={r['campaign_id']}, entorno={r['entorno']}",
                     "envío comercial solo en campaña no-test",
                     "aislamiento TEST/REAL: envío comercial no en campaña test", "A")

    # ── 5. lead_pipelines ──────────────────────────────────────────────────
    def audit_lead_pipelines(self):
        # duplicados (lead_id, pipeline_id)
        self.cur.execute("""
            SELECT lead_id, pipeline_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM lead_pipelines GROUP BY lead_id, pipeline_id HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("lead_pipelines", r["ids"], "relación duplicada (lead,pipeline)",
                     r["n"], "1", "relación N:M única por (lead,pipeline)", "A")
        # lead inexistente
        self.cur.execute("""
            SELECT lp.id, lp.lead_id FROM lead_pipelines lp
            LEFT JOIN clubes_crm c ON c.id = lp.lead_id WHERE c.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("lead_pipelines", r["id"], "lead_id inexistente", r["lead_id"],
                     "lead existente", "lead_id debe existir en clubes_crm", "A")
        # pipeline inexistente
        self.cur.execute("""
            SELECT lp.id, lp.pipeline_id FROM lead_pipelines lp
            LEFT JOIN pipelines p ON p.id = lp.pipeline_id WHERE p.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("lead_pipelines", r["id"], "pipeline_id inexistente", r["pipeline_id"],
                     "pipeline existente", "pipeline_id debe existir en pipelines", "A")
        # variante_ab inválida
        self.cur.execute("SELECT id, variante_ab FROM lead_pipelines")
        for r in self.cur.fetchall():
            if (r["variante_ab"] or "") not in ("A", "B", "C", ""):
                self.add("lead_pipelines", r["id"], "variante_ab inválida", r["variante_ab"],
                         "A|B|C", "variante_ab ∈ {A,B,C}", "A")
        # leads TEST en pipeline no-test / leads REAL en pipeline test
        self.cur.execute("""
            SELECT lp.id, lp.lead_id, lp.pipeline_id, c.email, c.nombre_club, p.entorno
            FROM lead_pipelines lp
            JOIN clubes_crm c ON c.id = lp.lead_id
            JOIN pipelines p ON p.id = lp.pipeline_id
        """)
        for r in self.cur.fetchall():
            is_test = es_lead_test(r["email"], r["nombre_club"])
            camp_test = (r["entorno"] or "").lower() == "test"
            if camp_test and not is_test:
                self.add("lead_pipelines", r["id"], "lead REAL en pipeline test",
                         f"lead={r['lead_id']}, pipeline={r['pipeline_id']}",
                         "lead TEST en pipeline test",
                         "aislamiento TEST/REAL en lead_pipelines", "B")
            if not camp_test and is_test:
                self.add("lead_pipelines", r["id"], "lead TEST en pipeline no-test",
                         f"lead={r['lead_id']}, pipeline={r['pipeline_id']}",
                         "lead REAL en pipeline no-test",
                         "aislamiento TEST/REAL en lead_pipelines", "B")

    # ── 6. envios ──────────────────────────────────────────────────────────
    def audit_envios(self):
        self.cur.execute("SELECT * FROM envios")
        envios = self.cur.fetchall()
        # lead_id inexistente
        self.cur.execute("""
            SELECT e.id, e.lead_id FROM envios e
            LEFT JOIN clubes_crm c ON c.id = e.lead_id
            WHERE e.lead_id IS NOT NULL AND c.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "lead_id inexistente", r["lead_id"],
                     "lead existente", "lead_id debe existir en clubes_crm", "A")
        # email incoherente con lead
        self.cur.execute("""
            SELECT e.id, e.email, e.lead_id, c.email AS lead_email
            FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
            WHERE LOWER(e.email) != LOWER(c.email)
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "email no coincide con lead",
                     f"envio={r['email']}, lead={r['lead_email']}",
                     "email coherente con lead", "envios.email debe coincidir con clubes_crm.email", "B")
        # club incoherente con lead
        self.cur.execute("""
            SELECT e.id, e.club, e.lead_id, c.nombre_club
            FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
            WHERE LOWER(e.club) != LOWER(c.nombre_club)
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "club no coincide con lead",
                     f"envio={r['club']}, lead={r['nombre_club']}",
                     "club coherente con lead", "envios.club debe coincidir con clubes_crm.nombre_club", "B")
        # plantilla_id inexistente
        self.cur.execute("""
            SELECT e.id, e.plantilla_id FROM envios e
            LEFT JOIN plantillas p ON p.id = e.plantilla_id
            WHERE e.plantilla_id IS NOT NULL AND p.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "plantilla_id inexistente", r["plantilla_id"],
                     "plantilla existente", "plantilla_id debe existir en plantillas", "A")
        # smtp_id inexistente
        self.cur.execute("""
            SELECT e.id, e.smtp_id FROM envios e
            LEFT JOIN cuentas_smtp s ON s.id = e.smtp_id
            WHERE e.smtp_id IS NOT NULL AND s.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "smtp_id inexistente", r["smtp_id"],
                     "cuenta smtp existente", "smtp_id debe existir en cuentas_smtp", "A")
        # variant inválida (None permitido solo para envíos sin campaña)
        for r in envios:
            v = r["variant"]
            if v is not None and v not in ("A", "B", "C"):
                self.add("envios", r["id"], "variant inválida", v,
                         "A|B|C", "variant ∈ {A,B,C}", "A")
            if v is None and r["campaign_id"] is not None:
                self.add("envios", r["id"], "campaign_id presente pero variant NULL",
                         "variant=NULL", "variant A/B/C",
                         "envío con campaña debe tener variante determinista", "A")
        # estado inválido
        for r in envios:
            if (r["estado"] or "") not in ("pendiente", "enviado", "abierto", "error"):
                self.add("envios", r["id"], "estado inválido", r["estado"],
                         "pendiente|enviado|abierto|error", "estado de envío válido", "A")
        # message_id duplicado
        self.cur.execute("""
            SELECT message_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM envios WHERE message_id IS NOT NULL GROUP BY message_id HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["ids"], "message_id duplicado", r["n"], "1",
                     "message_id único", "message_id debe ser único", "A")
        # tracking_id duplicado
        self.cur.execute("""
            SELECT tracking_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM envios GROUP BY tracking_id HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["ids"], "tracking_id duplicado", r["n"], "1",
                     "tracking_id único", "tracking_id debe ser único", "A")
        # incoherencia estado/timestamps: 'enviado'/'abierto' sin fecha_resultado_envio
        for r in envios:
            if r["estado"] in ("enviado", "abierto") and r["fecha_resultado_envio"] is None:
                self.add("envios", r["id"], "estado final sin fecha_resultado_envio",
                         f"estado={r['estado']}, fecha=NULL", "fecha_resultado_envio presente",
                         "estado final ⇒ fecha_resultado_envio NOT NULL", "C")
        # resultado_envio ACCEPTED pero estado 'pendiente'
        for r in envios:
            if r["resultado_envio"] == "ACCEPTED" and r["estado"] == "pendiente":
                self.add("envios", r["id"], "ACCEPTED pero estado pendiente",
                         f"resultado={r['resultado_envio']}, estado={r['estado']}",
                         "estado enviado/abierto", "ACCEPTED ⇒ estado final", "B")
        # envíos con campaign_id pero variant NULL (debería tener variante determinista)
        for r in envios:
            if r["campaign_id"] is not None and r["variant"] is None:
                self.add("envios", r["id"], "campaign_id presente pero variant NULL",
                         "variant=NULL", "variant A/B/C",
                         "envío con campaña debe tener variante determinista", "A")

    # ── 7. respuestas ──────────────────────────────────────────────────────
    def audit_respuestas(self):
        self.cur.execute("SELECT * FROM respuestas")
        resp = self.cur.fetchall()
        # envio_id inexistente
        self.cur.execute("""
            SELECT r.id, r.envio_id FROM respuestas r
            LEFT JOIN envios e ON e.id = r.envio_id
            WHERE r.envio_id IS NOT NULL AND e.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("respuestas", r["id"], "envio_id inexistente", r["envio_id"],
                     "envío existente", "envio_id debe existir en envios", "A")
        # message_id duplicado
        self.cur.execute("""
            SELECT message_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM respuestas WHERE message_id IS NOT NULL GROUP BY message_id HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("respuestas", r["ids"], "message_id duplicado", r["n"], "1",
                     "message_id único", "message_id debe ser único", "A")
        # clasificacion inválida
        for r in resp:
            if (r["clasificacion"] or "") not in ("PENDING", "INTERESADO", "NO_INTERESADO", "OBJECION", "OTRO"):
                self.add("respuestas", r["id"], "clasificacion inválida", r["clasificacion"],
                         "PENDING|INTERESADO|NO_INTERESADO|OBJECION|OTRO",
                         "clasificacion válida", "C")

    # ── 8. mockups ─────────────────────────────────────────────────────────
    def audit_mockups(self):
        self.cur.execute("""
            SELECT m.id, m.lead_id FROM mockups m
            LEFT JOIN clubes_crm c ON c.id = m.lead_id WHERE c.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("mockups", r["id"], "lead_id inexistente", r["lead_id"],
                     "lead existente", "lead_id debe existir en clubes_crm", "A")
        self.cur.execute("""
            SELECT m.id, m.pipeline_id FROM mockups m
            LEFT JOIN pipelines p ON p.id = m.pipeline_id
            WHERE m.pipeline_id IS NOT NULL AND p.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("mockups", r["id"], "pipeline_id inexistente", r["pipeline_id"],
                     "pipeline existente", "pipeline_id debe existir en pipelines", "A")
        self.cur.execute("SELECT id, estado FROM mockups")
        for r in self.cur.fetchall():
            if (r["estado"] or "") not in ("solicitado", "enviado", "aprobado", "rechazado"):
                self.add("mockups", r["id"], "estado inválido", r["estado"],
                         "solicitado|enviado|aprobado|rechazado", "estado mockup válido", "A")

    # ── 9. presupuestos ────────────────────────────────────────────────────
    def audit_presupuestos(self):
        self.cur.execute("""
            SELECT p.id, p.lead_id FROM presupuestos p
            LEFT JOIN clubes_crm c ON c.id = p.lead_id WHERE c.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("presupuestos", r["id"], "lead_id inexistente", r["lead_id"],
                     "lead existente", "lead_id debe existir en clubes_crm", "A")
        self.cur.execute("""
            SELECT p.id, p.pipeline_id FROM presupuestos p
            LEFT JOIN pipelines pl ON pl.id = p.pipeline_id
            WHERE p.pipeline_id IS NOT NULL AND pl.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("presupuestos", r["id"], "pipeline_id inexistente", r["pipeline_id"],
                     "pipeline existente", "pipeline_id debe existir en pipelines", "A")
        # cantidades imposibles
        self.cur.execute("SELECT id, unidades, precio_unitario, subtotal, importe_total FROM presupuestos")
        for r in self.cur.fetchall():
            if (r["unidades"] or 0) < 0 or (r["precio_unitario"] or 0) < 0 or (r["importe_total"] or 0) < 0:
                self.add("presupuestos", r["id"], "cantidad/precio negativo",
                         f"unidades={r['unidades']}, precio={r['precio_unitario']}, total={r['importe_total']}",
                         "valores >= 0", "cantidades y precios no negativos", "A")

    # ── 10. Kanban / estados ───────────────────────────────────────────────
    def audit_kanban(self):
        # (ya cubierto en audit_leads: estados fuera del Kanban)
        pass

    # ── 11. A/B/C determinismo ─────────────────────────────────────────────
    def audit_abc(self):
        self.cur.execute("""
            SELECT e.id, e.lead_id, e.campaign_id, e.variant
            FROM envios e WHERE e.campaign_id IS NOT NULL AND e.variant IS NOT NULL
        """)
        for r in self.cur.fetchall():
            esperado = asignar_variante(r["lead_id"], r["campaign_id"])
            if r["variant"] != esperado:
                self.add("envios", r["id"], "variant no coincide con asignación determinista",
                         f"variant={r['variant']}", f"variant={esperado}",
                         "asignarVariante(lead_id, campaign_id) es determinista", "A")
        # lead_pipelines variante vs determinista
        self.cur.execute("SELECT id, lead_id, pipeline_id, variante_ab FROM lead_pipelines WHERE variante_ab != ''")
        for r in self.cur.fetchall():
            esperado = asignar_variante(r["lead_id"], r["pipeline_id"])
            if r["variante_ab"] != esperado:
                self.add("lead_pipelines", r["id"], "variante_ab no coincide con asignación determinista",
                         f"variante={r['variante_ab']}", f"variante={esperado}",
                         "asignarVariante(lead_id, pipeline_id) es determinista", "A")

    # ── 12. elegibilidad / suppression ─────────────────────────────────────
    def audit_elegibilidad(self):
        # leads en estado de supresión que tienen envíos comerciales posteriores
        self.cur.execute("""
            SELECT e.id, e.lead_id, e.fecha_envio, c.estado_lead
            FROM envios e JOIN clubes_crm c ON c.id = e.lead_id
            WHERE c.estado_lead IN ('Lista Negra','Opt-Out','Unsubscribed','Baja / Opt-Out','Email Inválido')
              AND COALESCE(e.es_test,0)=0
        """)
        for r in self.cur.fetchall():
            self.add("envios", r["id"], "envío comercial a lead suprimido",
                     f"lead={r['lead_id']}, estado={r['estado_lead']}",
                     "sin envío comercial a suprimido",
                     "lead suprimido no elegible para envío comercial", "B")
        # leads TEST en campañas no-test (envios) — ya cubierto en pipelines
        # leads REAL en campañas test (envios) — ya cubierto en pipelines

    # ── 13. SMTP / logs ────────────────────────────────────────────────────
    def audit_smtp_logs(self):
        # cuentas smtp: email inválido
        self.cur.execute("SELECT id, email FROM cuentas_smtp")
        for r in self.cur.fetchall():
            if not email_valido(r["email"]):
                self.add("cuentas_smtp", r["id"], "email inválido", r["email"],
                         "email válido", "formato email válido", "A")
        # cuentas smtp: email duplicado
        self.cur.execute("""
            SELECT LOWER(email) AS e, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
            FROM cuentas_smtp GROUP BY LOWER(email) HAVING n > 1
        """)
        for r in self.cur.fetchall():
            self.add("cuentas_smtp", r["ids"], "email duplicado", r["n"], "1",
                     "email UNIQUE", "email smtp único", "A")
        # comunicaciones_log: lead_id inexistente
        self.cur.execute("""
            SELECT cl.id, cl.lead_id FROM comunicaciones_log cl
            LEFT JOIN clubes_crm c ON c.id = cl.lead_id
            WHERE cl.lead_id IS NOT NULL AND c.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("comunicaciones_log", r["id"], "lead_id inexistente", r["lead_id"],
                     "lead existente", "lead_id debe existir en clubes_crm", "A")
        # comunicaciones_log: plantilla_id inexistente
        self.cur.execute("""
            SELECT cl.id, cl.plantilla_id FROM comunicaciones_log cl
            LEFT JOIN plantillas p ON p.id = cl.plantilla_id
            WHERE cl.plantilla_id IS NOT NULL AND p.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("comunicaciones_log", r["id"], "plantilla_id inexistente", r["plantilla_id"],
                     "plantilla existente", "plantilla_id debe existir en plantillas", "A")
        # comunicaciones_log: id_cuenta_smtp inexistente
        self.cur.execute("""
            SELECT cl.id, cl.id_cuenta_smtp FROM comunicaciones_log cl
            LEFT JOIN cuentas_smtp s ON s.id = cl.id_cuenta_smtp
            WHERE cl.id_cuenta_smtp IS NOT NULL AND s.id IS NULL
        """)
        for r in self.cur.fetchall():
            self.add("comunicaciones_log", r["id"], "id_cuenta_smtp inexistente", r["id_cuenta_smtp"],
                     "cuenta smtp existente", "id_cuenta_smtp debe existir en cuentas_smtp", "A")
        # comunicaciones_log: variante_ab inválida
        self.cur.execute("SELECT id, variante_ab FROM comunicaciones_log WHERE variante_ab != ''")
        for r in self.cur.fetchall():
            if (r["variante_ab"] or "") not in ("A", "B", "C"):
                self.add("comunicaciones_log", r["id"], "variante_ab inválida", r["variante_ab"],
                         "A|B|C", "variante_ab ∈ {A,B,C}", "A")
        # comunicaciones_log: tipo_evento inválido
        self.cur.execute("SELECT id, tipo_evento FROM comunicaciones_log")
        for r in self.cur.fetchall():
            if (r["tipo_evento"] or "") not in ("envio_email", "cambio_estado", "blacklist_add", "blacklist_remove", "respuesta", "apertura"):
                self.add("comunicaciones_log", r["id"], "tipo_evento inválido", r["tipo_evento"],
                         "envio_email|cambio_estado|blacklist_add|blacklist_remove|respuesta|apertura",
                         "tipo_evento válido", "C")

    # ── 14. esquema vs código ──────────────────────────────────────────────
    def audit_esquema_codigo(self):
        # Columnas que el código espera en clubes_crm
        cur = self.cur
        cur.execute("PRAGMA table_info('clubes_crm')")
        cols = {r["name"] for r in cur.fetchall()}
        esperadas = {"id", "nombre_club", "email", "telefono_fijo", "telefono_movil",
                     "federacion", "estado_lead", "es_duplicado", "duplicado_id", "creado_el"}
        faltantes = esperadas - cols
        for c in sorted(faltantes):
            self.add("clubes_crm", "-", f"columna esperada por código no existe: {c}",
                     "ausente", "presente", "esquema debe coincidir con código", "A")

        # Columnas que el código espera en envios
        cur.execute("PRAGMA table_info('envios')")
        cols_env = {r["name"] for r in cur.fetchall()}
        esperadas_env = {"id", "club", "email", "federacion", "cuenta_emision", "estado",
                         "tracking_id", "asunto", "cuerpo_mensaje", "lead_id", "campaign_id",
                         "variant", "plantilla_id", "smtp_id", "message_id", "es_test",
                         "fecha_envio", "resultado_envio", "fecha_resultado_envio"}
        faltantes_env = esperadas_env - cols_env
        for c in sorted(faltantes_env):
            self.add("envios", "-", f"columna esperada por código no existe: {c}",
                     "ausente", "presente", "esquema debe coincidir con código", "A")

        # Columnas que el código espera en pipelines
        cur.execute("PRAGMA table_info('pipelines')")
        cols_pip = {r["name"] for r in cur.fetchall()}
        esperadas_pip = {"id", "nombre", "identificador", "estado", "entorno", "tipo",
                         "activo", "created_at"}
        faltantes_pip = esperadas_pip - cols_pip
        for c in sorted(faltantes_pip):
            self.add("pipelines", "-", f"columna esperada por código no existe: {c}",
                     "ausente", "presente", "esquema debe coincidir con código", "A")

        # Columnas que el código espera en plantillas
        cur.execute("PRAGMA table_info('plantillas')")
        cols_pl = {r["name"] for r in cur.fetchall()}
        esperadas_pl = {"id", "nombre", "tipo", "categoria", "activo", "test_ab",
                        "asunto", "cuerpo", "asunto_b", "cuerpo_b", "asunto_c", "cuerpo_c"}
        faltantes_pl = esperadas_pl - cols_pl
        for c in sorted(faltantes_pl):
            self.add("plantillas", "-", f"columna esperada por código no existe: {c}",
                     "ausente", "presente", "esquema debe coincidir con código", "A")

        # Tablas esperadas por el código
        cur.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        tablas = {r["name"] for r in cur.fetchall()}
        esperadas_tablas = {"clubes_crm", "envios", "pipelines", "lead_pipelines", "plantillas",
                            "cuentas_smtp", "comunicaciones_log", "aperturas", "respuestas",
                            "mockups", "presupuestos", "config", "snapshots", "destinatarios_test",
                            "rebotes", "_migraciones"}
        faltantes_tablas = esperadas_tablas - tablas
        for t in sorted(faltantes_tablas):
            self.add("BD", "-", f"tabla esperada por código no existe: {t}",
                     "ausente", "presente", "esquema debe coincidir con código", "A")

    # ── 15. datos legacy ───────────────────────────────────────────────────
    def audit_legacy(self):
        # Estados Kanban antiguos (sin prefijo numérico) en clubes_crm
        self.cur.execute("SELECT DISTINCT estado_lead FROM clubes_crm")
        for r in self.cur.fetchall():
            e = r["estado_lead"]
            if e and e not in KANBAN_SET and e not in ESTADOS_SUPRESION:
                # Estado legacy de la arquitectura de 7 columnas
                self.add("clubes_crm", "-", f"estado legacy detectado: {e}",
                         e, "estado Kanban 01-09", "estado_lead ∈ Kanban definitivo", "A")
        # Tablas legacy vacías (obsoletas pero seguras)
        self.cur.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        for r in self.cur.fetchall():
            t = r["name"]
            try:
                self.cur.execute(f"SELECT COUNT(*) FROM '{t}'")
                n = self.cur.fetchone()[0]
                if n == 0 and t in ("plantillas_new", "destinatarios_test", "mockups", "presupuestos", "respuestas", "rebotes"):
                    self.add("BD", "-", f"tabla {t} vacía (legacy/obsoleta pero segura)",
                             "0 filas", "n/a", "tabla vacía no es inconsistencia", "C")
            except Exception:
                pass

    # ── Salida ─────────────────────────────────────────────────────────────
    def report(self):
        print("\n" + "=" * 80)
        print("TABLA DE HALLAZGOS (clasificación A/B/C)")
        print("=" * 80)
        if not self.hallazgos:
            print("  (sin hallazgos)")
            return
        # Ordenar por categoría (A, B, C) y entidad
        orden = {"A": 0, "B": 1, "C": 2}
        for h in sorted(self.hallazgos, key=lambda x: (orden.get(x["categoria"], 9), x["entidad"], str(x["id"]))):
            print(f"[{h['categoria']}] {h['entidad']} id={h['id']} | {h['problema']}")
            print(f"      actual:   {h['actual']}")
            print(f"      esperado: {h['esperado']}")
            print(f"      regla:    {h['regla']}")
        print("-" * 80)
        from collections import Counter
        c = Counter(h["categoria"] for h in self.hallazgos)
        print(f"TOTAL: {len(self.hallazgos)} hallazgos | A={c.get('A',0)} | B={c.get('B',0)} | C={c.get('C',0)}")
        print("  A = reparación determinista segura")
        print("  B = requiere decisión (STOP)")
        print("  C = informativo, no reparar")


def main():
    print(f"Conectando a {HOST} ...")
    ftp = ftplib.FTP(HOST)
    ftp.login(USER, PASS)
    tmp = os.path.join(tempfile.gettempdir(), f"futprotec_faseA3_aud_{int(time.time())}.db")
    with open(tmp, "wb") as f:
        ftp.retrbinary("RETR " + REMOTE_DB, f.write)
    ftp.quit()
    print(f"Descargada BD remota: {os.path.getsize(tmp)} bytes, md5={file_md5(tmp)}")
    print(f"Ruta temporal: {tmp}\n")

    db = sqlite3.connect(tmp)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA foreign_keys=ON")

    aud = Auditor(db)
    aud.run()
    aud.report()

    db.close()
    print(f"\n=== FIN AUDITORÍA (READ-ONLY) ===")
    print(f"BD temporal conservada en: {tmp}")


if __name__ == "__main__":
    main()
