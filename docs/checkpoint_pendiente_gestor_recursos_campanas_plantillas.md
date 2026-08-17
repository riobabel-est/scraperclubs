# Checkpoint — Gestor de recursos de campañas y edición/versionado de plantillas

**Estado:** `BACKLOG_DESIGN_BLOCKED` → aclarado como `IMMEDIATE_BACKLOG_READY` (ver veredicto final)
**Tipo:** Auditoría de arquitectura + diseño mínimo viable (sin implementar)
**Fecha:** 2026-08-16
**Alcance:** CRM Outbound FutProtec V4.3 — módulo `public_html/outbound/`

---

## A. Problema actual

El sistema protege las plantillas mediante `plantillaEstaCongelada()` y muestra el error:

> "Error: Plantilla congelada (usada por campaña PILOT/ACTIVE). Crea una nueva plantilla."

La protección existe, pero es **opaca e insuficiente** desde el punto de vista operativo:

1. El editor no dice **qué campañas** usan la plantilla, ni su estado/entorno.
2. No existe forma de gestionar la relación plantilla ↔ campaña.
3. Las campañas (`pipelines`) no tienen panel de recursos asociados.
4. No hay visibilidad de si la relación es **actual** (en curso) o **histórica** (ya enviada).
5. No hay "duplicar para editar", "ver campaña", ni noción de versión.
6. La necesidad real no es "desbloquear", sino un modelo coherente **campaña → recursos → versiones → histórico**.

Conclusión: la congelación es correcta como **regla de seguridad**, pero la UX y el modelo de datos no la respaldan.

---

## B. Arquitectura actual (verificada contra código y BD real)

### B.1 Esquema real de la BD (`public_html/outbound/data/stats.db`)

> NOTA: el esquema real **no** está en `init_db.php` (que solo crea tablas legacy). Las tablas de campaña se crearon por migración `scripts/fase0_02_migracion_ddl.py` (registrada en `_migraciones` con `id=1`).

**Tabla `pipelines`**
| columna | tipo | uso real |
|---|---|---|
| id | INTEGER PK | sí |
| nombre | TEXT | sí (human-readable) |
| descripcion | TEXT | **no usado** (solo insert) |
| fecha_inicio | DATETIME | **no usado** |
| fecha_fin | DATETIME | **no usado** |
| variante_ganadora | VARCHAR(1) | **no usado** (existe, sin lógica) |
| activo | INTEGER | sí (`validarCampanaActiva`) |
| created_at | DATETIME | solo metadata |
| identificador | TEXT | sí (listados, smoke) |
| estado | TEXT | sí (`DRAFT`/`PILOT`/`ACTIVE`) |
| entorno | TEXT | sí (`test`/`pilot`/`production`) |
| tipo | TEXT | sí (valor fijo `'outbound'`) |
| objetivo | INTEGER | **no usado** |

**Registros reales hoy**
| id | nombre | identificador | estado | entorno | activo |
|---|---|---|---|---|---|
| 1 | Experimento Fase 1 TEST | LEGACY_TEST_FASE1 | DRAFT | test | 1 |
| 2 | Piloto Comercial FutProtec 2026-08 | PILOTO_FUTPROTEC_2026_08 | DRAFT | pilot | 1 |
| 3 | SMOKE TEST FutProtec 2026-08 | SMOKE_TEST_FUTPROTEC_2026_08 | **PILOT** | test | 1 |

**Tabla `lead_pipelines`** (asignación lead→campaña + variante)
```
id, lead_id, pipeline_id, variante_ab, fecha_asignacion
UNIQUE(lead_id, pipeline_id)
```
- En producción solo tiene **5 filas**, todas de `pipeline_id=1` (legacy/test). **No** se usa para segmentar envíos (ver C.4).

**Tabla `plantillas`**
```
id, nombre, asunto, asunto_b, asunto_c, test_ab,
cuerpo, cuerpo_b, cuerpo_c, tipo, categoria, activo, fecha_creacion
```
- 7 plantillas activas. Las relevantes: `id=1` (Prospección ABC, `test_ab=1`), `id=2` (Primer Contacto ABC, `test_ab=1`), `id=3..7` (seguimiento/objeción/whatsapp/etc.).

**Tabla `envios` (histórico snapshot)**
```
id, club, email, federacion, cuenta_emision, fecha_envio, estado,
tracking_id, asunto, cuerpo_mensaje, lead_id, campaign_id, variant,
plantilla_id, smtp_id, message_id, resultado_envio, fecha_resultado_envio
```
- Es la **única** relación física campaña↔plantilla (mediante `campaign_id` + `plantilla_id`).
- Conserva el cuerpo real enviado (`cuerpo_mensaje`), variante (`variant`) y `message_id`.

### B.2 Qué representa hoy una campaña

`pipelines` es una **entidad de trazabilidad/orquestación**, no de recursos:
- Identifica un experimento (`identificador`, `nombre`).
- Tiene `estado` (DRAFT/PILOT/ACTIVE) y `entorno` (test/pilot/production), validados por `validarCampanaActiva()` + `esEntornoCoherente()`.
- NO guarda referencia a plantilla, producto ni segmento.
- NO hay CRUD funcional de campañas: **no existe** ningún `INSERT/UPDATE/DELETE INTO pipelines` en `public_html/outbound/` (solo en scripts de test `_test_*`). Las campañas se crean por scripts de migración/carga manual.

---

## C. Por qué existe la congelación

### C.1 Implementación exacta

`public_html/outbound/inc/eligibilidad.php` → `plantillaEstaCongelada()`:

```php
function plantillaEstaCongelada(SQLite3 $db, int $plantillaId): bool
{
    if ($plantillaId <= 0) return false;
    $n = (int)$db->querySingle(
        "SELECT COUNT(*)
         FROM envios e
         JOIN pipelines p ON p.id = e.campaign_id
         WHERE e.plantilla_id = {$plantillaId}
           AND UPPER(p.estado) IN ('PILOT','ACTIVE')"
    );
    return $n > 0;
}
```

Se invoca en `dashboard.php` (`save_template`) antes del UPDATE:

```php
if ($id > 0 && plantillaEstaCongelada($db, $id)) {
    echo json_encode(['ok' => false, 'error' => 'Plantilla congelada ...']);
    exit;
}
```

### C.2 Criterio real

Una plantilla está congelada **si y solo si** existe al menos un envío en `envios`:
- cuyo `plantilla_id` apunta a esa plantilla, **y**
- cuya `campaign_id` apunta a una `pipeline` con `estado ∈ {PILOT, ACTIVE}`.

Es decir, la congelación es **inferida del histórico de envíos**, no de una asignación declarativa campaña→plantilla.

### C.3 Por qué puede decir "congelada" sin relación gestionable

Porque **no hay `pipelines.plantilla_id` ni tabla intermedia campaña→plantilla**. La única traza de la relación es `envios.plantilla_id + envios.campaign_id`. Implicaciones:

- La "relación" solo se materializa **después del primer envío**.
- Si una plantilla está seleccionada en la lanzadera para una campaña PILOT pero aún no ha producido envíos, **no** está congelada (falso negativo de protección).
- El editor no puede listar campañas vinculadas porque esas campañas se deducen con la misma consulta, que hoy nunca se ejecuta en lectura (solo en el guardado).

Matiz crítico detectado: la congelación **no distingue entorno**. La campaña `id=3` (SMOKE TEST) tiene `estado=PILOT` pero `entorno=test`, y aun así congela la plantilla `id=2`. Es coherente con el literal de la regla (solo mira `estado`), pero conviene documentarlo para no sorprender al operador.

---

## D. Limitaciones UX (verificadas en `tabs/editor.php`, `js/app.js`, `tabs/lanzadera.php`)

### D.1 Editor de plantillas (`tabs/editor.php`)

- Lo que **sí** permite editar: nombre, pipeline/categoría (select), plataforma (email/WA), formato (HTML/texto), asunto A/B/C, cuerpo A/B/C, `test_ab`.
- Lo que **no** permite: ver campañas vinculadas, ver estado/entorno de esas campañas, duplicar, versionar.
- Guardado: `js/app.js → guardarPlantilla()` → `action=save_template`. El front **no** consulta `plantillaEstaCongelada()` antes de guardar; simplemente recibe el `{ok:false, error}` del servidor y lo muestra con `alert()`.
- El listado (`templatesFiltradas`) solo muestra nombre + categoría + ícono. No hay badge "Usada por X campañas".
- `get_templates` devuelve todas las columnas de `plantillas` **pero no** añade `usada_en_campanas` ni `congelada`.

### D.2 UX de campañas

- Existe listado de campañas **solo como selector** en la lanzadera (`tabs/lanzadera.php`, select `lzCampanas`) y en `analytics.php`.
- `get_piloto_campanas` devuelve `id, nombre, identificador, estado, entorno, activo`.
- **NO existe** panel de edición/creación/archivado/duplicado de campañas.
- **NO existe** asignación de plantilla, producto o segmento a una campaña desde la UI.
- La selección de plantilla en la lanzadera es **manual e independiente** de la campaña (`lzIdPlantillaEmail` es un selector separado); no se persiste como atributo de la campaña.

### D.3 Flujo de envío (para sustentar el diseño)

- **Lanzadera (manual):** `enviar_lote.php` recibe `id_plantilla` por parámetro (elegido a mano), además de `campaign_id`. La plantilla **no** se lee desde la campaña.
- **Cron (automático):** `cron.php` selecciona SIEMPRE la **primera plantilla HTML activa** de la tabla:
  ```sql
  SELECT * FROM plantillas WHERE activo = 1 AND tipo = 'html' ORDER BY id ASC LIMIT 1
  ```
  → El cron **ignora** la elección de plantilla de la campaña; no existe vínculo campaña→plantilla.
- **Segmento de leads:** `get_cola.php`/`cron.php` filtran por `estado_lead`/`federacion`/aislamiento TEST-REAL. **No** consultan `lead_pipelines` para segmentar. `lead_pipelines` solo se usa en métricas (`dashboard.php get_analytics`).

---

## E. Alternativas (comparación de las 4 opciones de edición)

### Opción 1 — Bloqueo actual (status quo)
- **Pros:** preserva integridad; sin trabajo.
- **Contras:** UX opaca; no escala; bloquea de facto la evolución. No resuelve el problema.

### Opción 2 — Editar libremente (quitar congelación)
- **Pros:** máxima agilidad.
- **Contras:** **rompe trazabilidad**: modificar `plantillas.cuerpo` tras enviar hace que `envios.cuerpo_mensaje` histórico ya no corresponda con la "plantilla actual". Un retry contra la misma campaña mezclaría dos cuerpos bajo el mismo `plantilla_id`. Riesgo alto de regresión en el motor. **Descartada.**

### Opción 3 — Versionado
- **Pros:** trazabilidad completa y correcta a largo plazo.
- **Contras:** requiere tabla nueva + migración + alterar el guardado y la selección de plantilla (hoy inexistente). Es la opción **correcta** pero **estructural**, no inmediata.

### Opción 4 — Duplicación obligatoria (congelar + "Duplicar para editar")
- **Pros:** quirúrgica; no toca el motor ni `envios`; preserva histórico; conceptos ya existentes ("Crea una nueva plantilla").
- **Contras:** no da versión formal; el operador genera copias manuales (riesgo de proliferación), pero es manejable.

### Recomendación
**Adoptar la Opción 4 como inmediata**, manteniendo `plantillaEstaCongelada()` intacto, y **diseñar la Opción 3 (versionado) como fase estructural futura**. La Opción 4 es la única que respeta la regla "no romper trazabilidad" sin tocar BD, motor ni `envios`, y resuelve la UX opaca en esta fase.

---

## F. Recomendación

```text
QUIRÚRGICO / INMEDIATO
  - Visibilidad de vínculo (lectura, sin tocar BD):
      * get_templates enriquece con "usada_en_campanas" y "congelada".
      * get_piloto_campanas (o nuevo get_template_campanas) devuelve campañas
        vinculadas por historial/envíos.
      * Editor muestra panel "USO EN CAMPAÑAS".
  - Duplicación de plantilla ("Duplicar para editar"):
      * nueva acción duplicate_template (clona fila plantillas, id nuevo).
  - Botón "Ver campaña" (navegación a la campaña en lanzadera/analytics).

FUTURO / ESTRUCTURAL
  - Relación formal campaña→plantilla (+ versión), versionado.
  - Asignación de producto y segmento por campaña.
  - CRUD de campañas.
```

---

## G. Diseño mínimo propuesto

### G.1 Editor de plantillas — panel "USO EN CAMPAÑAS"

Al abrir una plantilla, mostrar:

```text
USO EN CAMPAÑAS
  Usada por 1 campaña(s)

  🟢 SMOKE TEST FutProtec 2026-08
     PILOT / test
     (histórica: 6 envíos registrados)

  [ Duplicar para editar ]   [ Ver campaña ]
```

Campos del panel (todos derivados de `envios JOIN pipelines`, sin BD nueva):
- lista de campañas únicas (`p.id`, `p.nombre`, `p.estado`, `p.entorno`).
- nº de envíos por campaña (distinción actual/histórica).
- badge de estado/entorno.

### G.2 En Campaña (mínimo, sin "Campaign Manager" completo)

- Mostrar "plantilla actual" derivada de los envíos de la campaña (más frecuente `plantilla_id`) y su variante A/B/C.
- Advertencia antes de activar si la plantilla es compartida o está congelada.

### G.3 Acciones nuevas (endpoints quirúrgicos en `dashboard.php`)

- `duplicate_template`: clona `plantillas` a un `id` nuevo (mismo contenido, `nombre` sufijado `(copia)`), nunca congela la original.
- (lectura) `get_template_campanas?plantilla_id=`: devuelve lo que hoy se deduce del historial de `envios`.

> Ninguno modifica `envios`, `plantillaEstaCongelada()`, A/B/C, `reservarEnvioLogico()`, ni BD.

---

## H. Fases de implementación (máx. 3)

### FASE A — UX inmediata (quirúrgica, sin BD)
- `get_templates` devuelve `congelada` (llamar a `plantillaEstaCongelada()`) y `num_campanas` asociadas.
- Nueva acción `get_template_campanas` (solo SELECT).
- Panel "USO EN CAMPAÑAS" en `tabs/editor.php` + `js/app.js`.
- `duplicate_template` + botón "Duplicar para editar".
- Botón "Ver campaña" (salta a lanzadera/analytics con `campaign_id` precargado).

### FASE B — Relación campaña/plantilla (persistencia mínima, sin versionado)
- Nuevo atributo `pipelines.plantilla_id` (ALTER TABLE, una columna) o tabla ligera `campana_plantilla` (eval. en fase B).
- Asignar/editar plantilla desde la campaña. Actualizar `cron.php` para leer plantilla desde la campaña (solo si el usuario lo aprueba; hoy `cron.php` elige la primera HTML activa).
- Mantener `envios` como única fuente histórica.

### FASE C — Versionado formal
- Solo si realmente hace falta: `plantillas_versiones` + `campana_plantilla(version_id)`, sin alterar `envios`.
- La campaña fija una versión; editar crea nueva versión.

> FASE A es la que debe priorizarse. B y C requieren aprobación explícita (implican BD y motor).

---

## I. Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| **Quitar congelación** sin alternativa | mezcla de cuerpos bajo el mismo `plantilla_id`; histórico incoherente | NO quitar; mantener `plantillaEstaCongelada()` y añadir duplicación |
| **Editar directamente plantilla activa** | retry/envío posterior reutiliza `cuerpo_mensaje` distinto al del snapshot; auditoría rota | bloquear edición directa; solo duplicar/versionar |
| **Introducir versionado** | migración de datos + cambiar selección de plantilla en cron | difiera a FASE C; versiones como columnas/tabla nuevas, sin tocar `envios` |
| **Vincular plantilla a campaña** | inconsistencia si el motor sigue leyendo plantilla global (`cron.php`) | desacoplar: primero UI, luego motor (FASE B) |
| **Migrar histórico** | romper `envios.cuerpo_mensaje`/`variant`/`message_id` | prohibido; `envios` es intocable |
| **Reutilizar `lead_pipelines`** para segmentar | colisión con métricas; hoy está casi vacío | no reutilizar; definir criterio de segmento explícito |
| **Alterar `envios`** | pérdida de trazabilidad, idempotencia (`reservarEnvioLogico`) | intocable |

---

## J. Qué NO tocar

- `plantillaEstaCongelada()` (mantener; solo leerlo).
- A/B/C (`inc/abc.php`: `asignarVariante`, `resolverContenidoVariante`, `validarCampanaActiva`, `esEntornoCoherente`).
- `envios` (tabla y cols `cuerpo_mensaje`, `variant`, `plantilla_id`, `campaign_id`, `message_id`).
- `reservarEnvioLogico()`.
- `eligibilidad.php` (excepto lectura de la función de congelación ya existente).
- `enviar_lote.php`, `cron.php` (en FASE A; en B solo tras aprobación explícita).
- BD (sin tablas/columnas nuevas en FASE A).
- `enviar_smtp_random.php` (credenciales SMTP intactas).
- `lead_pipelines` (no reutilizar para segmentación).
- `output/` y `checkpoints/` del proyecto principal.

---

## Veredicto

```text
IMMEDIATE_BACKLOG_READY
```

La FASE A (visibilidad de vínculo + duplicación) está completamente definida y es segura. Las FASE B y C quedan como ampliación estructural y NO deben implementarse sin aprobación explícita, porque implican BD y motor. No se ha modificado nada funcional ni la BD en esta auditoría.