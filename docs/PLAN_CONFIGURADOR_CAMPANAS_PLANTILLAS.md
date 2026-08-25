# PLAN DE EJECUCIÓN — Configurador de Campañas + Categorías de Plantillas

**Fecha:** 2026-08-25
**Estado:** ⏸️ PENDIENTE DE APROBACIÓN (no ejecutar hasta OK del usuario)
**Ámbito:** `public_html/outbound/` (CRM FutProtec)

---

## 0. Contexto y decisión de diseño

Tras el análisis comparado con plataformas B2B (HubSpot, ActiveCampaign, Brevo,
Lemlist, Smartlead), el modelo validado es:

- **Campaña = contenedor** (público + contenido + entorno).
- **Público = segmento por filtros** (checklist de federaciones: "Todas" o marcadas;
  opcional estado del lead), NO "categorías de contactos".
- **Plantillas = banco central** (categorías editables/opcionales) y la **campaña
  referencia** cuáles usa (multi-select). Sin duplicar plantillas.

Lo que YA existe en el CRM (se reutiliza):
- Tabla `pipelines` (id, nombre, identificador, estado, entorno, activo).
- `get_cola.php` ya filtra por `federacion`, `estado_lead` y `campaign_id`.
- `get_templates?categoria=` filtra plantillas por categoría.
- `test_ab` (A/B/C) y `campaign_id` en envíos/métricas.

Lo que FALTA:
- Gestión de categorías de plantillas (CRUD + opcionalidad).
- Formulario de campaña (segmento de federaciones + selector de plantillas).
- Persistencia del segmento y de las plantillas asignadas.
- Integración de la Lanzadera con el segmento/plantillas de la campaña.

---

## FASE 0 — Preflight y checkpoint (0.5 h)

**Objetivo:** partir de un estado estable y recuperable.

- [ ] Backup de `public_html/outbound/data/stats.db` (copia con fecha en `backups/`).
- [ ] Verificar `pipelines` y `plantillas` actuales (volcado de diagnóstico).
- [ ] Confirmar que el último deploy (seguridad) está operativo en producción.
- [ ] Checkpoint: `docs/checkpoint_fase0_configurador_campanas.md`.

**Validación:** `php -l` de los archivos tocados · dashboard carga OK.

---

## FASE 1 — Categorías de plantillas: editables y opcionales (1-1.5 h)

**Objetivo:** poder crear/renombrar/eliminar categorías y plantillas SIN categoría.

| # | Tarea | Archivos |
|---|---|---|
| 1.1 | Quitar la obligatoriedad de categoría: botón "Nueva plantilla" sin necesidad de `ec`; guardar con categoría vacía permitido | `tabs/editor.php`, `api/plantillas.php` |
| 1.2 | Select de categoría **editable** en el editor (permite escribir una nueva categoría al guardar) | `tabs/editor.php`, `api/plantillas.php::save_template` |
| 1.3 | Endpoints CRUD de categorías: `save_categoria`, `rename_categoria`, `delete_categoria` (renombra/actualiza `plantillas.categoria`) | `api/plantillas.php` (o nuevo `api/categorias.php`) |
| 1.4 | `get_categorias` sigue devolviendo `DISTINCT categoria` (incluye las gestionadas) | `api/plantillas.php` |
| 1.5 | En el listado, plantilla sin categoría se muestra como "Sin pipeline"; en la Lanzadera se ofrecen como **genéricas** | `tabs/editor.php`, `js/app.js` |

**Validación:**
- Crear categoría nueva, renombrar (afecta a sus plantillas), eliminar (con confirmación).
- Crear/editar plantilla sin categoría.
- La Lanzadera muestra las plantillas por estado + las genéricas.

**Riesgo / rollback:**
- Eliminar una categoría reasigna sus plantillas a sin-categoría (no borra plantillas).
- Backup previo de la tabla `plantillas`.

---

## FASE 2 — Configurador de campañas (backend + esquema) (1.5-2 h)

**Objetivo:** persistir el segmento y las plantillas asignadas a cada campaña.

---

## FASE 3 — UI del configurador de campañas (1-1.5 h)

**Objetivo:** formulario visual para crear/editar campañas.

| # | Tarea | Archivos |
|---|---|---|
| 3.1 | Bloque/Modal "Configurador de Campañas" (en Configuración o tab nuevo): nombre, identificador, entorno (test/producción), activo | `tabs/smtp.php` o nuevo `tabs/campanas.php` |
| 3.2 | **Checklist de federaciones** (checkbox "Todas" + lista de federaciones reales de `clubes_crm`) | mismo archivo + helper `obtenerFederacionesUnicas` |
| 3.3 | **Selector de plantillas** (multi-checkbox del banco central, con búsqueda) | mismo archivo |
| 3.4 | Función Alpine `campanasConfig()` (cargar/guardar/editar/eliminar) | mismo archivo (script inline) |

**Validación:** crear campaña marcando 3 federaciones y 2 plantillas; recargar y verlas.

**Riesgo / rollback:** solo UI + endpoints de Fase 2; reversible.

---

## FASE 4 — Integración con la Lanzadera (1.5-2 h)

**Objetivo:** la campaña gobierna el segmento y las plantillas en el envío.

| # | Tarea | Archivos |
|---|---|---|
| 4.1 | `get_cola.php`: si la campaña tiene segmento de federaciones (no "Todas"), aplicar filtro automático (con el manual como override) | `api/get_cola.php` |
| 4.2 | Lanzadera: al elegir campaña, cargar su segmento y **filtrar plantillas a las asignadas + genéricas** | `js/app.js` (`lzOnCampaignChange`), `tabs/lanzadera.php` |
| 4.3 | Mostrar en la UI de Lanzadera: "Campaña: X · Federaciones: N · Plantillas: N" | `tabs/lanzadera.php` |

**Validación (en TEST primero):** campaña TEST con 2 federaciones → cola solo de esas
federaciones; plantillas solo las asignadas. Entorno de producción intacto.

**Riesgo / rollback:** es el cambio de mayor impacto → probar exhaustivamente en TEST
antes de producción.

---

## FASE 5 — A/B/C y métricas por campaña (revisión, 0.5 h)

- [ ] Confirmar que `test_ab` + variantes siguen funcionando con el flujo nuevo.
- [ ] Confirmar métricas por `campaign_id` (analytics, funnel) sin cambios.

---

## FASE 6 — Tests, deploy, docs, commit (1 h)

- [ ] Tests funcionales locales: CRUD categorías, CRUD campañas, segmento → cola, plantillas por campaña.
- [ ] `php -l` + `node --check` + tests existentes (seguridad, refactor).
- [ ] Deploy a SiteGround (`deploy_outbound_full.py`) + verificación.
- [ ] Actualizar `docs/CONFIGURACION_SEGURIDAD.md` y `docs/REFACTORIZACIONES_PENDIENTES.md`.
- [ ] Checkpoint final + commit + push (con OK explícito del usuario).

---

## Reglas obligatorias durante la ejecución

1. **Nunca** borrar/sobrescribir `output/` ni `checkpoints/`.
2. **Nunca** tocar columnas de salida del scraping ni credenciales SMTP del array de
   `enviar_smtp_random.php`.
3. No cambiar el esquema de `pipelines` existente (solo añadir tablas nuevas).
4. Pruebas siempre en entorno TEST antes de producción.
5. Un refactor = un objetivo = un checkpoint documentado.
6. Compatibilidad SiteGround (PHP 8.x nativo, sin PECL).

---

## Orden de prioridad recomendado

1. **Fase 1** (categorías editables/opcionales) → desbloquea el editor, bajo riesgo.
2. **Fases 2-3** (configurador de campañas) → la feature principal.
3. **Fase 4** (integración Lanzadera) → el mayor impacto, al final y con pruebas TEST.
4. **Fases 5-6** → cierre.

**Estimación total:** ~6-8 h de trabajo efectivo.


| # | Tarea | Archivos |
|---|---|---|
| 2.1 | Migración idempotente: tablas `campaign_segmentos` (campaign_id, tipo='federacion'\|'estado'\|'todas', valor) y `campaign_plantillas` (campaign_id, plantilla_id) | `cli/init_db.php` (o script de migración) |
| 2.2 | Endpoints CRUD campañas extendidos: `save_campaign` (nombre, identificador, entorno, estado, activo + segmento + plantillas), `get_campaign`, `delete_campaign` | nuevo `api/campanas.php` o `api/analytics.php` |
| 2.3 | `get_piloto_campanas` devuelve además `segmento` y `plantillas_id` (pre-cargar Lanzadera) | `api/analytics.php` |

**Validación:** crear/editar/borrar campaña con segmento y plantillas; persistencia en BD.

**Riesgo / rollback:** esquema nuevo → migración idempotente (no rompe `pipelines`). Backup de BD.
