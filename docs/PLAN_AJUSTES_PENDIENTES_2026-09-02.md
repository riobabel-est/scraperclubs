# PLAN DE AJUSTES PENDIENTES — ScrapperClub + FutProtec Outbound

> **Fecha:** 2026-09-02 · **Base:** análisis del código + `docs/roadmap_outbound.md` + `docs/ESTADO_LISTADOS_CLUBES_2026-09-01.md` + estado real de BD/CSVs.
> **Alcance:** todo lo pendiente verificado contra el estado real (no suposiciones).

---

## 0. ✅ COMPLETADO HOY (2026-09-02) — para contexto

| Ítem | Evidencia |
|---|---|
| Fix adjuntos respuestas entrantes (clientes) | `api/imap_sync.php` (BODY.PEEK[]+extracción MIME) · `inc/imap_respuestas.php` (backfill en duplicados) · `inc/adjuntos.php` (rutas `\`→`/`). Commit `f6cd0a2`, desplegado a SiteGround, **54 adjuntos recuperados** |
| BD local estabilizada con datos reales | `data/stats.db` = producción 21:09 + reparación (FK 0, integrity ok, VACUUM) |
| CRM local operativo | `http://localhost:8090` con BD real, modo `test/pausado` |
| Git | Commit `f6cd0a2` + push a `origin/main` |

---

## 1. 🔴 PRIORIDAD ALTA — Bugs / bloqueantes

### 1.1 `init_db.php` no inicializa BD fresca (bug real)
- **Qué:** en BD nueva falla en 3 puntos: (a) migra desde tabla legacy `plantillas` que no existe; (b) crea índice sobre `envios.es_test` inexistente; (c) crea índice sobre `envios.lead_id/campaign_id` inexistentes.
- **Impacto:** imposible desplegar el CRM en un servidor/entorno nuevo sin parche manual.
- **Estado:** parcheado **solo en la copia local de deploy** (`futprotec-outbound-local`). **Falta backport al repo.**
- **Acción:** aplicar los 3 guards `CREATE/ALTER IF NOT EXISTS` en `public_html/outbound/cli/init_db.php` del repo.
- **Esfuerzo:** bajo · **Riesgo:** ninguno (idempotente).

### 1.2 Adjuntos de rebotes ensucian la Bandeja
- **Qué:** la recuperación de adjuntos insertó también los adjuntos técnicos de los NDR (`adjunto_1.bin`, `adjunto_2.bin` de Mailer-Daemon). La Bandeja ahora muestra adjuntos en mensajes de rebote.
- **Acción:** en el render de la Bandeja, **no mostrar adjuntos** cuando `es_rebote=1` (o filtrar `adjunto_*.bin` genéricos de NDR). Opcional: limpiar esas filas de `respuestas_adjuntos` para rebotes.
- **Esfuerzo:** bajo.

### 1.3 ScraperAPI sin créditos (bloqueante de scraping)
- **Qué:** `SCRAPERAPI_KEY` agotada → los scrapers NOVA se rinden tras retries (`[vacío]` en logs).
- **Acción:** decidir — (a) nueva key ScraperAPI, o (b) usar `--directo` (curl_cffi TLS) / Playwright para federaciones que lo permitan.
- **Nota:** `SCRAPERAPI_KEY` está **hardcodeada en `config.py`** (versionada en git). Mover a `.env`.

---

## 2. 🟠 PRIORIDAD MEDIA — Scraping (recuperar lo que falta)

> Fuente: `docs/ESTADO_LISTADOS_CLUBES_2026-09-01.md`. Total pendiente ≈ **8.000 clubes NOVA** + Madrid.

### 2.1 Consolidados desactualizados (inconsistencia de datos)
- `clubs_nova.csv` = 148 filas pero los individuales suman **2.209**; `clubs_todos.csv` = 274.
- **Acción:** `python main.py --merge-only` (regenera consolidados). **Reescribe 2 archivos derivados** → requiere OK explícito.

### 2.2 Federaciones NOVA pendientes (por volumen)
| Federación | Pendiente | Comando |
|---|---:|---|
| Castilla-La Mancha | ~1.881 | `scraper_nova.py --fed "Castilla-La Mancha" --resume --delay 3` |
| Andalucía | ~1.700 | `--fed "Andalucía" --resume --start-page 1 --delay 3` |
| Extremadura | ~1.439 | `--fed "Extremadura" --resume --delay 3` |
| Galicia | ~1.366 | `--fed "Galicia" --resume --delay 3` |
| Aragón / Asturias / Murcia / Cantabria | ~1.400 | `--resume` (parciales) |
| **Tenerife / Ceuta** | todo | primera ejecución (ya verificadas) |
| **Madrid** | ~290 + 599 pendientes | `scraper_madrid.py --resume` |

### 2.3 Federaciones sin acceso / sin activar
- **Navarra / Las Palmas** (`skip=True`): validar `NFG_VerClub` y activar.
- **País Vasco, Melilla, Baleares, Cataluña**: localizar dominio/portal (sin scraper funcional hoy).

---

## 3. 🟡 PRIORIDAD MEDIA — Outbound CRM (del roadmap, verificado)

### Prioridad 0 — Operación comercial
- **O-2** Vínculos cruzados entre tabs (ficha → Pipeline/Seguimiento; Analytics → acción).

### Prioridad 1 — Entregabilidad / seguridad
- **E-3** Verificación SPF/DKIM/DMARC por dominio + warmup de cuentas.
- **E-4** Gestión de rebotes **soft** + supresión automática (hard ✅ hecho).
- **E-5** **Rotar contraseñas SMTP en producción** (estuvieron en historial git).

### Prioridad 2 — Deuda técnica
- **T-1** Dividir monolitos (`inc/imap_respuestas.php` 2.100+ líneas, `api/leads.php`, `cli/init_db.php`).
- **T-2** Prepared statements en endpoints de escritura.
- **T-3** Plantillas versionadas inmutables.
- **T-4** Índices y saneamiento de esquema.
- **T-5** Histórico de estados Kanban por campaña.

### Prioridad 3 — Confort
- **C-1** Búsqueda global Cmd+K · **C-2** persistencia de filtros · **C-3** snooze en colas · **AI-4** pipeline configurable.

---

## 4. 🔵 PRIORIDAD BAJA — Infraestructura / limpieza

| Ítem | Detalle |
|---|---|
| Mover `SCRAPERAPI_KEY` a `.env` | Está hardcodeada en `config.py` (versionada). |
| Limpiar artefactos | `tmp_audit_mega.php` (raíz), `archivo_inactivo/` (basura), `public_html/` pesa 492 MB (incluye `tailwindcss-windows-x64.exe` 40 MB + backups de BD en `data/`). |
| `clubes.json` | ✅ OK (UTF-8 válido, 1.870 clubes Murcia). Sin acción. |

---

## 5. ORDEN SUGERIDO DE EJECUCIÓN

1. **Backport `init_db.php`** (bug real, bajo esfuerzo) → commit.
2. **Filtrar adjuntos de rebotes** en Bandeja → commit + deploy.
3. **Mover `SCRAPERAPI_KEY` a `.env`** → commit.
4. **Regenerar consolidados** (`--merge-only`) con tu OK.
5. **Scraping por tandas** con `--resume --delay 3` (CLM → Andalucía → Extremadura → Galicia → …), en background con monitor.
6. **Roadmap CRM** según prioridad (O-2 primero por impacto comercial).

---

## 6. CRITERIOS DE ÉXITO
- `init_db.php` inicializa BD fresca sin errores (verificable con `php cli/init_db.php` en BD vacía).
- Bandeja muestra adjuntos de clientes, no los de rebotes.
- Consolidados CSV reflejan el total real de clubes scrapeados.
- Scraping reanudable sin perder progreso (`--resume` + checkpoints).
- Ninguna credencial en código versionado.
