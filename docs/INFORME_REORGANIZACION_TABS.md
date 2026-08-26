# INFORME DE VIABILIDAD — Reorganización de Tabs del Panel (CRM Outbound)

**Fecha:** 2026-08-26
**Estado:** ✅ **IMPLEMENTADO** (2026-08-26) — ver §8. El documento original de análisis/plan se conserva como registro.
**Ámbito:** `public_html/outbound/` (dashboard.php + tabs/*)

---

## 1. Inventario actual por tab (análisis en profundidad)

| Tab | Archivo | Componente Alpine | Contenido / Endpoints | Dependencia JS |
|---|---|---|---|---|
| **Kanban CRM** | `tabs/kanban.php` | `app()` (global) | Pipeline de ventas (7 columnas), chips federación | `js/app.js` |
| **Gestor de Datos** | `tabs/gestor.php` | `app()` | Tabla de leads + paginación (`gestorBody`, `gestorP`) | `js/app.js` (`loadGestor`) |
| **Editor Plantilla** | `tabs/editor.php` | `app()` | Filtro categorías, listado, formulario de plantilla, preview A/B/C; campo "Categoría (Pipeline)" editable (F1); IDs: `categoriasList`, `edCuerpo`, `edCuerpoA/B/C` | `js/app.js` (`loadCategorias`, `seleccionarPlantilla`, `guardarPlantilla`…) |
| **Configuración** | `tabs/smtp.php` | `configIA()`, `gestionPruebas()`, `seguridadPanel()`, **`campanasConfig()`** | Bloque IA (multi-proveedor) · Cuentas SMTP (`smtpBody`) · Seguridad del Panel · Gestión de Pruebas · **Configurador de Campañas** (`todasFed`) | `js/app.js` + `<script>` propio con 4 funciones Alpine |
| **Lanzadera** | `tabs/lanzadera.php` | `app()` | Motor de envío (cola, lote, A/B/C): `lzColaScroll`, `lzLogScroll` | `js/app.js` (métodos `lz*`) |
| **Analytics** | `tabs/analytics.php` | `pilotoAnalyticsApp()` | Métricas de campañas; endpoints `get_piloto_campanas`, `get_piloto_metricas` | `<script>` propio |
| **Respuestas** | `tabs/respuestas.php` | `app()` | Bandeja de respuestas (unibox) | `js/app.js` |
| **Lista Negra** | `tabs/lista_negra.php` | `app()` | Supresión / opt-out | `js/app.js` (métodos `bl*`) |
| **Modales** | `tabs/modals.php` | `app()` | Modales globales (ficha lead, merge, SMTP, add lead) | `js/app.js` |
| **followups.php** | `tabs/followups.php` | `followupsApp()` | **NO incluido** en `dashboard.php` → **huérfano** | `<script>` propio |

**Arquitectura:** el `<body>` tiene `x-data="app()" x-init="boot()"` (componente global). Todos los tabs se **incluyen siempre** (includes incondicionales) y se muestran/ocultan con `x-show="tab==='...'"`. Los tabs con `x-data` local (smtp, analytics, followups) anidan componentes dentro del global.

## 2. Hallazgos del análisis

1. **`tabs/smtp.php` está sobrecargado**: 4 componentes Alpine (IA + SMTP + Seguridad + Pruebas + Campañas) en el tab "Configuración". Mezcla **ajustes técnicos** con **gestión operativa** y **planificación de campañas**.
2. **El Configurador de Campañas está mal ubicado**: es *marketing operativo* (usa plantillas), no *configuración*.
3. **`tabs/followups.php` es huérfano**: existe con componente propio pero no se incluye en el dashboard (candidato a eliminar o integrar).
4. **Nombres de tabs funcionales** ("Editor Plantilla", "Configuración") no reflejan el área; las plataformas modernas agrupan por dominio (Marketing/Leads/Ajustes).
5. No hay duplicidad de IDs entre tabs (salvo `todasFed`, único) → mover bloques es seguro.

## 3. Propuesta de reorganización (modelo de plataformas modernas)

| Tab nuevo | Sustituye a | Contenido |
|---|---|---|
| **Pipeline** | Kanban CRM | `kanban.php` |
| **Leads** | Gestor de Datos | `gestor.php` |
| **Plantillas y Campañas** ⭐ | Editor Plantilla | `editor.php` **+ Configurador de Campañas** (movido) |
| **Lanzadera** | Lanzadera | `lanzadera.php` |
| **Bandeja** | Respuestas | `respuestas.php` |
| **Analytics** | Analytics | `analytics.php` |
| **Lista Negra** | Lista Negra | `lista_negra.php` |
| **Ajustes** ⭐ | Configuración | IA + Cuentas SMTP + Seguridad del Panel + Gestión de Pruebas (**sin campañas**) |

## 4. Viabilidad técnica

### 4.1 Mover el Configurador de Campañas (`smtp.php` → `editor.php`)
- El bloque HTML del configurador es **autocontenido** (`x-data="campanasConfig()" x-init="cargarTodo()"`): no depende de otros elementos de `smtp.php`.
- La función `campanasConfig()` vive en el `<script>` de `smtp.php`. Para que `editor.php` la use sin dependencia oculta, se **mueve a `js/app.js`** (que ya aloja todas las funciones del editor: `loadCategorias`, `seleccionarPlantilla`, etc.).
- Como todos los tabs se incluyen siempre, el movimiento solo cambia **dónde** se define el bloque; la visibilidad la controla `x-show="tab==='editor'"`.

### 4.2 Renombrar tabs
- Solo cambia el **texto de los botones** en `dashboard.php` (navegación). Internamente los valores `tab='editor'` / `tab='smtp'` **no cambian** → `app.js` y los `x-show` siguen funcionando sin tocar lógica.

### 4.3 Quitar campañas de "Ajustes"
- `smtp.php` queda con `configIA()`, `gestionPruebas()`, `seguridadPanel()` (3 componentes). Se elimina el bloque del configurador y su función.

## 5. Conflictos potenciales y mitigaciones

| # | Conflicto | Análisis | Mitigación |
|---|---|---|---|
| C-1 | `campanasConfig()` definida en el `<script>` de `smtp.php` | Si el bloque se mueve a `editor.php` sin la función, rompería. Si la función se queda en smtp.php, funcionaría (se carga siempre) pero es dependencia oculta | **Mover la función a `js/app.js`** (patrón del resto del editor) |
| C-2 | Componentes Alpine anidados | `editor.php` pasaría a tener `x-data` local (`campanasConfig`) dentro del global `app()`. Ya ocurre en `smtp.php` (configIA, etc.) → soportado por Alpine | Ninguna (patrón ya probado) |
| C-3 | IDs duplicados | `todasFed` (checkbox) es único en todo el panel; `smtpBody` queda en smtp.php | Verificar con grep tras el movimiento |
| C-4 | `x-init="cargarTodo()"` hace fetch al cargar | Ya sucede en smtp.php (los tabs se cargan siempre, aunque ocultos). Sin cambio de comportamiento | Ninguna |
| C-5 | Nombres de tab internos | `app.js` usa `tab==='editor'`/`'smtp'`; renombrar el texto del botón no afecta | No cambiar los valores internos de `tab` |
| C-6 | `followups.php` huérfano | No se usa; conviene dejarlo documentado (no bloquear) | Anotar en pendientes; fuera de esta tarea |
| C-7 | Layout del tab editor | El configurador (lista + formulario) se mostraría bajo el editor, que ya es ancho | Insertarlo **debajo** del editor (o sub-bloque colapsable) |

## 6. Plan de ejecución (cuando se apruebe)

1. **Mover `campanasConfig()`** del `<script>` de `smtp.php` → `js/app.js`.
2. **Mover el bloque HTML del Configurador** de `tabs/smtp.php` → `tabs/editor.php` (al final, bajo el editor).
3. **Limpiar `smtp.php`**: quitar el bloque y la función movida; queda con IA + SMTP + Seguridad + Pruebas.
4. **Renombrar tabs** en `dashboard.php`: "Kanban CRM"→**Pipeline**, "Gestor de Datos"→**Leads**, "Editor Plantilla"→**Plantillas y Campañas**, "Respuestas"→**Bandeja**, "Configuración"→**Ajustes**.
5. **Validar**: `php -l` · `node --check` · render autenticado (bloque en el tab editor) · tests f1/f2/eligibilidad.
6. **Actualizar docs** + checkpoint + (opcional) commit/deploy.

## 7. Conclusión

**Viable, riesgo BAJO.** Los conflictos son menores y todos mitigables (C-1 es el único relevante y se resuelve moviendo la función a `app.js`). No se cambia lógica de negocio ni esquema de BD; solo ubicación de UI y textos de navegación. El tab "Plantillas y Campañas" agrupa la feature de campañas con su contenido (plantillas), y "Ajustes" queda como panel técnico coherente.

## Referencias

- `docs/PENDIENTES_OUTBOUND.md` — compendio (P-1)
- `docs/PLAN_CONFIGURADOR_CAMPANAS_PLANTILLAS.md` — plan P-1



## 8. Registro de implementación (2026-08-26)

Ejecutado el plan §6 en su totalidad, validado localmente:

| Paso | Acción | Archivos | Verificación |
|---|---|---|---|
| 1 | `campanasConfig()` movida del `<script>` de smtp.php → **`js/app.js`** (función global, línea 1980) | `smtp.php`, `js/app.js` | `node --check app.js` ✅; única definición ✅ |
| 2 | Bloque HTML del Configurador movido → **`tabs/editor.php`** (final del tab, bajo el editor) | `tabs/editor.php` | `id="todasFed"` único ✅ |
| 3 | `smtp.php` limpiado: queda IA + SMTP + Seguridad + Gestión de Pruebas | `tabs/smtp.php` | `php -l` ✅; 0 refs a campañas ✅ |
| 4 | Tabs renombrados en `dashboard.php` (valores internos intactos) | `dashboard.php` | `php -l` ✅ |
| 5 | Headers internos coherentes: `gestor.php`→"Leads", `respuestas.php`→"Bandeja de Respuestas"; comentarios `app.js` | `gestor.php`, `respuestas.php`, `js/app.js` | `php -l` ✅ |

**Nuevo mapa de tabs:** Pipeline · Leads · Plantillas y Campañas (incl. Configurador) · Lanzadera · Bandeja · Analytics · Lista Negra · Ajustes.

**Pendiente (fuera del alcance local):** deploy SiteGround y commit/push si el usuario lo aprueba.
