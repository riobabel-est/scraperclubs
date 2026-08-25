# Checkpoint — Refactor `js/app.js` (bloque 5)

**Fecha:** 2026-08-25
**Ámbito:** `public_html/outbound/js/app.js`
**Tipo:** Refactor de mantenibilidad (sin cambio de comportamiento ni de esquema BD)
**Bloque:** §5 de `docs/REFACTORIZACIONES_PENDIENTES.md`

---

## Objetivo

Eliminar el código duplicado y las funciones monolíticas de `js/app.js` (1923 líneas),
aplicando el patrón de "orquestador delgado + funciones de plantilla" ya usado en los
refactors de PHP (`analytics.php`, `leads.php`, `imap_respuestas.php`).

## Cambios aplicados

### 5.1 — `iniciarMotor()` dividido

`iniciarMotor()` (antes ~83 líneas con dos flujos) quedó como **orquestador delgado**
que decide entre dos métodos nuevos:

| Método | Flujo |
|---|---|
| `enviarDirigido()` | CASO A: envío dirigido de 1 lead (antes 751-785). Usa `lzCuentaActiva` en lugar de recalcular la cuenta SMTP activa inline. |
| `enviarCola()` | CASO B: cola normal con lote + delay anti-bloqueo (antes 787-826). |

### 5.2 — Getters unificados

`lzEnvioOkPct` (línea 200) tenía la misma fórmula que `lzTasaExito` (197). Ahora
`lzEnvioOkPct` **delega en `lzTasaExito`**. Ambos getters se conservan porque la UI
(`tabs/lanzadera.php`) los referencia por separado (barra de OK y % de éxito).

### 5.3 — `enviarCorreoPrueba()` descompuesto

Se extrajeron 3 funciones auxiliares y el método principal quedó como orquestador:

| Función | Responsabilidad |
|---|---|
| `validarPruebaEmail()` | Validaciones previas (campaña, plantilla, emails de prueba). Devuelve string de error o `null`. |
| `obtenerCandidatosPrueba()` | Reutiliza `lzCola` o consulta `get_cola.php` con `campaign_id` (compatibilidad TEST/REAL). |
| `armarSeleccionPrueba(candidatos, esAbc)` | Elige 1 lead (o 3 para A/B/C). Devuelve `[{variante, club}]` o `null`. |

### 5.4 — Renderizado extraído

| Función | De dónde salió |
|---|---|
| `renderGestorRows(rows)` | `loadGestor()` (filas de la tabla del Gestor) |
| `renderGestorPaginacion(totalPages)` | `loadGestor()` (paginación) |
| `renderSmtpRows(accounts)` | `loadSmtp()` (filas de cuentas SMTP) |

`loadGestor()` y `loadSmtp()` quedaron como **fetch + delegación de render**.

## Contratos públicos preservados

Todos los nombres referenciados desde las vistas siguen existiendo con la misma firma:

- `iniciarMotor()`, `enviarCorreoPrueba()`, `loadGestor()`, `loadSmtp()`
- Getters `lzTasaExito`, `lzEnvioOkPct`, `lzEnvioErrorPct`

Referenciados desde `tabs/lanzadera.php` y `tabs/gestor.php` (Alpine `@click`/`x-text`).

## Validación

- `node --check public_html/outbound/js/app.js` → **OK** (sintaxis válida).
- **Test funcional local `scripts/test_app_js_refactor.js`** → **38/38 OK**:
  - §5.2: `lzTasaExito`, `lzEnvioOkPct` (delega), `lzEnvioErrorPct` y casos borde con cola vacía.
  - §5.3: `validarPruebaEmail()` (4 casos), `obtenerCandidatosPrueba()` (lzCola + fetch), `armarSeleccionPrueba()` (no-ABC, ABC completo, ABC incompleto → null).
  - §5.4: `renderGestorRows`/`renderGestorPaginacion`/`renderSmtpRows` (contenido HTML, estados vacíos, botones).
  - §5.1: `enviarCola()` (lote 2 de 3, PAUSADO, logs, KPI, `lzColaIndex`), `enviarDirigido()`, `iniciarMotor()` (delegación dirigido/cola).
  - Nota: el test usa `APP_FN` capturada antes de instanciar porque `app()` reasigna `window.app = i` (patrón real de los onclick inline).
- Delta neto del archivo: **+131 / −81 líneas**.
- No se tocó la BD ni los endpoints PHP.

## Pendiente

- **Deploy a producción** (requiere aprobación del usuario).
- Smoke test en el dashboard: carga Gestor (tabla + paginación), tab SMTP (tabla de
  cuentas), Lanzadera (iniciarMotor dirigido + cola, enviarCorreoPrueba).
- Commitear.

## Archivos de referencia

- `docs/REFACTORIZACIONES_PENDIENTES.md` — bloque 5
- `docs/checkpoint_refactor_leads_scan_duplicates.md` — patrón previo aplicado
- `scripts/test_app_js_refactor.js` — test funcional local del refactor (38/38 OK)
