# PLAN DE AJUSTES PENDIENTES — ScrapperClub + FutProtec Outbound

> **Fecha:** 2026-09-02 · **Base:** análisis del código + `docs/roadmap_outbound.md` + `docs/ESTADO_LISTADOS_CLUBES_2026-09-01.md` + estado real de BD/CSVs.
> **Alcance:** todo lo pendiente verificado contra el estado real (no suposiciones).

---

## 0. ✅ COMPLETADO (2026-09-02) — para contexto

| Ítem | Evidencia |
|---|---|
| Fix adjuntos respuestas entrantes (clientes) | `api/imap_sync.php` (BODY.PEEK[]+extracción MIME) · `inc/imap_respuestas.php` (backfill en duplicados) · `inc/adjuntos.php` (rutas `\`→`/`). Commit `f6cd0a2`, desplegado a SiteGround, **54 adjuntos recuperados** |
| BD local estabilizada con datos reales | `data/stats.db` = producción 21:09 + reparación (FK 0, integrity ok, VACUUM) |
| CRM local operativo | `http://localhost:8090` con BD real, modo `test/pausado` |
| Backport `init_db.php` (BD fresca) | ✅ Commit `eb17d76` + push + verificado en BD vacía |
| Bandeja: rebotes ocultos + pestaña Rebotados | ✅ Commit `9d13521` + push + **desplegado a SiteGround** |
| `SCRAPERAPI_KEY` movida a `.env` | ✅ Commit `b4801b3` + push (`config.py` con fallback) |
| Git | `main` sincronizado con `origin/main` en `b4801b3` |

---

## 1. 🔴 PRIORIDAD ALTA — Bugs / bloqueantes

### 1.1 `init_db.php` no inicializa BD fresca (bug real) — ✅ RESUELTO
- **Qué:** en BD nueva fallaba en 3 puntos: (a) migra desde tabla legacy `plantillas` que no existe; (b) crea índice sobre `envios.es_test` inexistente; (c) crea índice sobre `envios.lead_id/campaign_id` inexistentes.
- **Solución aplicada:** 3 guards `CREATE/ALTER IF NOT EXISTS` en `public_html/outbound/cli/init_db.php`. Commit `eb17d76` + push + verificado en BD vacía.

### 1.2 Adjuntos de rebotes ensucian la Bandeja — ✅ RESUELTO (ampliado)
- **Qué:** la recuperación de adjuntos insertó también los adjuntos técnicos de los NDR.
- **Solución aplicada (mayor alcance, petición del usuario):** los mensajes de **rebote quedan ocultos** en Por responder/Todos y **solo se muestran en la pestaña Rebotados** (con sus adjuntos, para verificar). Commit `9d13521` + push + desplegado a SiteGround (`api/analytics.php`, `js/app.js`, `tabs/respuestas.php`).

### 1.3 ScraperAPI — ⚠️ config resuelta; scraping pendiente por anti-bot
- **Qué:** la key estaba hardcodeada y el scraping no avanzaba.
- **Resuelto:** `SCRAPERAPI_KEY` movida a `.env` (commit `b4801b3`); la key **tiene crédito** (verificado HTTP 200).
- **Bloqueo actual:** `ffcm.es` aplica **anti-bot temporal por IP** (probado: directo → HTTP 200 vacío; proxy sin JS → 117 B; Playwright → sin IDs). Requiere **enfriamiento (~30-60 min)** y reintentar en tandas con `--directo --delay 5`, o usar otra IP/VPN.

---

## 2. 🟠 PRIORIDAD MEDIA — Scraping (recuperar lo que falta)

> Fuente: `docs/ESTADO_LISTADOS_CLUBES_2026-09-01.md`. Total pendiente ≈ **8.000 clubes NOVA** + Madrid.

### 2.1 Consolidados actualizados parcialmente
- ⚠️ El test del browser regeneró `clubs_nova.csv` / `clubs_todos.csv` con el merge del momento (**2.172 clubes**; antes 148/274). Al terminar el scraping completo conviene regenerar con `python main.py --merge-only` (incluye NOVA + rfcylf + fcf). Reescribe 2 archivos derivados → requiere OK explícito.

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
- **E-5** ~~Rotar contraseñas SMTP~~ → ✅ **Descartado por decisión del usuario** (2026-09-02): ya está todo OK. El CLI `cli/rotar_password_smtp.php` (cifra FP1, `--dry-run`) queda disponible por si se necesita en el futuro.

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
| Mover `SCRAPERAPI_KEY` a `.env` | ✅ Hecho (commit `b4801b3`): se lee de entorno/`.env` con fallback. |
| Limpiar artefactos | `tmp_audit_mega.php` (raíz), `archivo_inactivo/` (basura), `public_html/` pesa 492 MB (incluye `tailwindcss-windows-x64.exe` 40 MB + backups de BD en `data/`). |
| `clubes.json` | ✅ OK (UTF-8 válido, 1.870 clubes Murcia). Sin acción. |

---

## 5. ORDEN SUGERIDO DE EJECUCIÓN

- ✅ 1-3 resueltos (init_db, Bandeja rebotes, key `.env`).
- 4. **Regenerar consolidados finales** (`python main.py --merge-only`) al cerrar el scraping → requiere tu OK.
- 5. **Scraping por tandas** tras enfriamiento anti-bot: CLM → Andalucía → Extremadura → Galicia → parciales → Tenerife/Ceuta → Madrid (con `--resume`, delay ≥ 5 s, `PYTHONUTF8=1`).
- 6. **Roadmap CRM** según prioridad: O-2 (vínculos entre tabs) primero por impacto comercial; después E-3/E-4/E-5 y deuda T-1..T-5.

---

## 6. CRITERIOS DE ÉXITO
- `init_db.php` inicializa BD fresca sin errores (verificable con `php cli/init_db.php` en BD vacía).
- Bandeja muestra adjuntos de clientes, no los de rebotes.
- Consolidados CSV reflejan el total real de clubes scrapeados.
- Scraping reanudable sin perder progreso (`--resume` + checkpoints).
- Ninguna credencial en código versionado.
