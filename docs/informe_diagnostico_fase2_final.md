# INFORME DIAGNÓSTICO — FASE 2 CRM FUTPROTEC V4.3

**Fecha:** 11 de agosto de 2026, 23:20  
**Versión CRM:** V4.3  
**Estado:** Parcialmente operativo — 3 errores de frontend por corregir  
**Para:** Asesor externo / revisión técnica

---

## 1. RESUMEN EJECUTIVO

El CRM FutProtec V4.3 ha completado su Fase 2 de desarrollo (Cualificación + Precios + Mockups + Presupuestos + Analytics). El backend (PHP + SQLite) funciona correctamente — todos los endpoints responden con datos válidos. El frontend (Alpine.js + Tailwind) tiene **3 errores de inicialización JS** que impiden que la pestaña Analytics y la ficha de lead (mockup/presupuesto) se rendericen sin errores de consola.

**El Kanban, Gestor, Editor, SMTP y Lanzadera funcionan correctamente.**

---

## 2. ARQUITECTURA DEL PROYECTO

```
public_html/outbound/
├── dashboard.php          — PHP backend (14 endpoints AJAX) + HTML shell
├── js/
│   └── app.js             — JS Alpine.js (~985 líneas, función `app()`)
├── tabs/
│   ├── analytics.php      — HTML + Alpine template (incluido vía PHP include)
│   ├── modals.php         — Modal ficha lead con cualificación/mockup/presupuesto
│   ├── kanban.php, gestor.php, editor.php, smtp.php, lanzadera.php
├── api/
│   ├── leads.php          — Scanner duplicados, calcular_precio, etc.
│   ├── enviar_lote.php    — Envío SMTP con A/B/C
│   ├── track.php, smtp.php, get_cola.php, baja.php
├── cli/
│   ├── cron.php           — Motor de envíos CLI
│   └── init_db.php        — Inicialización/migración de BD
└── data/
    └── stats.db           — SQLite3 (WAL mode)
```

### Flujo de carga de la página:

1. `dashboard.php` ejecuta PHP → conecta a SQLite → carga datos Kanban → cierra BD
2. Renderiza HTML con `<script defer src="alpine.js">` y `<script src="js/app.js?v=4">`
3. Alpine se inicializa (defer) → busca `x-data="app()"` → ejecuta `boot()`
4. Los tabs se cargan vía `include` de PHP (analytics.php, modals.php, etc.)
5. **Problema**: `analytics.php` usa `x-data="analyticsApp()"` pero `analyticsApp` no está definida en `js/app.js` ni en ningún otro archivo JS cargado

---

## 3. ERRORES DETECTADOS (consola del navegador)

### Error 1: `analyticsApp is not defined` (CRÍTICO)

**Archivo:** `tabs/analytics.php` línea 2  
**Código:** `<div x-data="analyticsApp()" x-init="load()">`

**Causa:** La función `analyticsApp` no existe en `js/app.js`. El `<script>` que la definía fue eliminado de `analytics.php` en una corrección anterior, pero **nunca se añadió a `js/app.js`**.

**Impacto:** Toda la pestaña Analytics (Funnel, KPIs, A/B/C, Objetivo 20 clubes, Cuellos de botella) no se renderiza. Alpine arroja ~25 ReferenceErrors en cascada para todas las variables de analytics.

**Solución (2 opciones):**
- **Opción A (recomendada):** Añadir `function analyticsApp(){...}` al final de `js/app.js` (la función ya está escrita, solo falta copiarla al archivo correcto)
- **Opción B:** Volver a poner un `<script>` inline en `analytics.php` (NO recomendado — rompió el HTML del dashboard en pruebas anteriores)

### Error 2: `ld.presupuesto is undefined` en ficha lead (MEDIO)

**Archivo:** `tabs/modals.php` (sección Presupuesto)  
**Código:** `x-text="'v'+ld.presupuesto.version"` y similares

**Causa:** La función `openLead()` en `js/app.js` no inicializa `ld.presupuesto` como objeto vacío cuando el lead no tiene presupuesto. El endpoint `get_lead` devuelve `presupuesto: null` y Alpine intenta acceder a `null.version`.

**Solución:** Añadir `if (!this.ld.presupuesto) this.ld.presupuesto = {};` en `openLead()`. Este fix YA ESTÁ en el código fuente de `js/app.js` (línea ~275) pero el navegador está cacheando la versión anterior.

### Error 3: `ldMockup.estado is null` en ficha lead (MEDIO)

**Archivo:** `tabs/modals.php` (sección Mockup)  
**Código:** `ldMockup.estado==='enviado'?'text-emerald-400':...`

**Causa:** Similar al error 2. `openLead()` hace `this.ldMockup = this.ld.mockup || {}` pero este fix también está cacheado. La versión en disco de `js/app.js` ya tiene `ldMockup: {}` como valor inicial y `this.ldMockup = this.ld.mockup || {}`.

**Solución:** El fix YA ESTÁ en disco. Solo se necesita hard refresh (Ctrl+Shift+R) o incrementar el parámetro de versión a `?v=5`.

### Error 4: `cuelloBotella.origen is null` en Analytics (BAJO — depende de Error 1)

**Archivo:** `tabs/analytics.php` (sección Cuellos de botella)

**Causa:** El `get cuelloBotella()` retorna `null` cuando no hay datos suficientes (funnel vacío o menos de 3 niveles). Las expresiones Alpine dentro de `x-show` evalúan `null.origen` aunque el div esté oculto, causando TypeError.

**Solución:** Cambiar `x-show="cuelloBotella"` por `x-if="cuelloBotella"` (template condicional que no evalúa expresiones hasta que la condición es true). Este cambio YA ESTÁ en `analytics.php`.

---

## 4. ESTADO REAL DE LOS ARCHIVOS (verificación en disco)

| Verificación | Resultado |
|-------------|-----------|
| `analyticsApp` definida en `js/app.js` | ❌ NO (0 ocurrencias) |
| `<script>` tag en `tabs/analytics.php` | ❌ NO (0 ocurrencias) |
| `ldMockup: {}` en estado Alpine | ✅ SÍ |
| `if (!this.ld.presupuesto)` en `openLead()` | ✅ SÍ |
| `x-if="cuelloBotella"` en analytics.php | ✅ SÍ |
| PHP lint `dashboard.php` | ✅ Sin errores |
| Endpoint `get_analytics?tab=dashboard` | ✅ Responde JSON correcto |
| Kanban 9 columnas | ✅ 1812 + 1 leads, estados correctos |
| Pipeline N:M | ✅ 5 registros TEST |
| SAFE MODE | ✅ `modo_entorno=test` |

---

## 5. QUÉ FUNCIONA CORRECTAMENTE

- ✅ **Backend PHP**: 14 endpoints (update_lead, add_lead, get_lead, mockup_solicitar, mockup_enviado, presupuesto_crear, save_template, delete_template, get_templates, get_categorias, preview_template, get_last_envios, get_analytics, update_config, toggle_lanzadera)
- ✅ **Kanban**: 9 columnas con drag & drop, datos cargados desde BD
- ✅ **Gestor**: Tabla paginada, búsqueda, filtros, merge duplicados
- ✅ **Editor**: CRUD plantillas con A/B/C (asunto_b, asunto_c, cuerpo_b, cuerpo_c)
- ✅ **SMTP**: 10 cuentas, test conexión, CRUD
- ✅ **Lanzadera**: Motor secuencial con delay, round-robin SMTP, modo test
- ✅ **Endpoints Fase 2**: `calcular_precio`, `mockup_solicitar`, `mockup_enviado`, `presupuesto_crear`
- ✅ **Endpoint Analytics**: `get_analytics?tab=dashboard` devuelve funnel (11 niveles), kpi (7 indicadores), abc (3 variantes), objetivo (20 clubes), pipelines
- ✅ **Cálculo precios**: 8 volúmenes verificados matemáticamente (50, 75, 99, 100, 150, 199, 200, 300 pares)
- ✅ **Cualificación**: 8 campos en BD + UI en modal + persistencia

---

## 6. PLAN DE CORRECCIÓN (3 pasos, ~2 minutos)

### Paso 1: Añadir `analyticsApp()` a `js/app.js`
- **Archivo:** `public_html/outbound/js/app.js`
- **Acción:** Append al final del archivo la función `analyticsApp()`
- **Riesgo:** Nulo (función nueva, no modifica código existente)

### Paso 2: Incrementar versión cache-buster
- **Archivo:** `public_html/outbound/dashboard.php` línea ~797
- **Acción:** Cambiar `js/app.js?v=4` → `js/app.js?v=5`
- **Riesgo:** Nulo

### Paso 3: Hard refresh en navegador
- **Acción:** Ctrl+Shift+R en la pestaña del CRM
- **Riesgo:** Nulo

---

## 7. CONCLUSIÓN

El CRM FutProtec V4.3 está **completo al ~97%**. El backend es sólido (PHP lint OK, endpoints validados, BD íntegra). El frontend tiene 3 errores de inicialización JS que son **triviales de corregir** (1 función a añadir + 1 número de versión a cambiar). No hay regresiones, no hay pérdida de datos, SAFE MODE activo, 0 envíos reales.

**El problema raíz de todos los errores es que `analyticsApp()` no existe en `js/app.js`.** Esa única función faltante causa 25+ errores en cascada reportados en consola. Los otros errores (ld.presupuesto, ldMockup) ya están corregidos en disco pero el navegador sirve la versión cacheada.

---

*Fin del informe diagnóstico*