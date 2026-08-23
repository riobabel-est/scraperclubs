# INFORME TÉCNICO PARA ASESOR EXTERNO
## Errores Alpine.js en el CRM Outbound tras implementar el Sidebar Lateral Retráctil

**Fecha:** 21/08/2026
**Proyecto:** FutProtec Outbound CRM (`/public_html/outbound`)
**Autor del informe:** Ingeniería de desarrollo (modo read-only)
**Objetivo:** Documentar de forma detallada y accionable el diagnóstico de los errores de consola que impiden que el sidebar lateral funcione correctamente.

---

## 1. RESUMEN EJECUTIVO

Tras implementar el **sidebar lateral retráctil (estilo Gemini)** en `dashboard.php` y añadir las propiedades `sidebarOpen` y `rsSyncing` en `js/app.js`, la consola del navegador muestra los siguientes errores de Alpine.js:

```
Alpine Expression Error: sidebarOpen is not defined
Alpine Expression Error: rsSyncing is not defined
Uncaught ReferenceError: sidebarOpen is not defined
Uncaught ReferenceError: rsSyncing is not defined
```

**Conclusión preliminar:** El código fuente local es **correcto** (las propiedades existen en `app.js` y el `dashboard.php` referencia la versión `?v=14`). El error se produce porque **el navegador está ejecutando una versión cacheada/antigua de `app.js`** que no contiene estas propiedades, o porque el objeto devuelto por `app()` no se está vinculando correctamente al scope de Alpine.

---

## 2. EVIDENCIA DEL ESTADO ACTUAL DEL CÓDIGO (VERIFICADO)

### 2.1 `js/app.js` — Las propiedades SÍ existen

```js
// Línea 7 — sidebarOpen definido con persistencia en LocalStorage
sidebarOpen: localStorage.getItem('fp_sidebar') === 'true',

// Línea 10-12 — toggleSidebar() definido
toggleSidebar() {
    this.sidebarOpen = !this.sidebarOpen;
    try {
        localStorage.setItem('fp_sidebar', this.sidebarOpen ? 'true' : 'false');
    } catch (e) { /* almacenamiento no disponible */ }
},

// Línea 154 — rsSyncing definido
rsSyncing: false,
```

**Verificación con grep:**
- `sidebarOpen` → 3 coincidencias (definición + toggle + persistencia).
- `rsSyncing` → 3 coincidencias (definición + uso en syncRespuestas).
- `window.app = i;` → **0 coincidencias** (antipatrón eliminado).

### 2.2 `dashboard.php` — Referencia correcta al asset

```html
<!-- Línea 487: el componente Alpine se inicializa con app() -->
<body class="bg-slate-950 text-slate-200 min-h-screen" x-data="app()" x-init="boot()">

<!-- Línea 692: configuración global definida ANTES de cargar app.js -->
<script>
window._cfg = {motorActivo:..., modeTest:...};
</script>

<!-- Línea 694: app.js cargado con versión ?v=14 -->
<script src="js/app.js?v=14"></script>
```

**Verificación con grep:**
- `js/app.js?v=14` → presente.
- `x-data="app()"` → presente en línea 487.
- `window._cfg` → definido en línea 692 (antes del script de app.js).

---

## 3. ANÁLISIS DE CAUSA RAÍZ

### 3.1 Hipótesis principal: Caché del navegador (más probable)

Los errores `sidebarOpen is not defined` y `rsSyncing is not defined` son **exactamente** los que se producen cuando Alpine evalúa las expresiones `x-show="sidebarOpen"` / `:class="rsSyncing ? 'animate-spin' : ''"` pero el objeto devuelto por `app()` **no contiene esas propiedades**.

Esto ocurre cuando el navegador ejecuta una **versión antigua de `app.js`** (cacheada) que fue generada antes de añadir `sidebarOpen` y `rsSyncing`. Aunque `dashboard.php` ahora referencia `?v=14`, si el navegador tiene cacheada una versión anterior (p.ej. `?v=13` o sin query string) y no la ha invalidado, seguirá ejecutando el JS antiguo.

**Evidencia que apoya esta hipótesis:**
- El código local es correcto (verificado con grep).
- Los errores mencionan exactamente las dos propiedades nuevas (`sidebarOpen`, `rsSyncing`), que son las que se añadieron en la última iteración.
- El resto de la app (que usa propiedades antiguas) funciona, lo que indica que Alpine SÍ está inicializando, pero con un objeto `app()` que no tiene las propiedades nuevas.

### 3.2 Hipótesis secundaria: Orden de carga / scope de Alpine

En `dashboard.php`, el orden de carga es:
1. `<script src="js/app.js?v=14">` (define la función global `app()`).
2. Alpine.js se carga con `defer` en el `<head>`.

Si por algún motivo Alpine.js se inicializa **antes** de que `app.js` defina la función `app()`, el `x-data="app()"` fallaría. Sin embargo, esto es poco probable porque Alpine usa `defer` y espera al DOMContentLoaded.

### 3.3 Hipótesis descartada: `window.app = i` (antipatrón)

En una iteración anterior existía `window.app = i;` al final de `app()`, que sobrescribía la función global `app` con el objeto `i`. Esto rompía el scope de Alpine. **Este antipatrón ya fue eliminado** (grep = 0), por lo que ya no es la causa.

---

## 4. IMPACTO

- El sidebar lateral no se renderiza correctamente (los iconos de toggle `panel-left-close`/`panel-left-open` quedan ocultos con `display:none`).
- El spinner de sincronización de respuestas (`rsSyncing`) no funciona.
- Los errores de consola son **no bloqueantes** para el resto de la app (Alpine los captura y continúa), pero degradan la experiencia y ensucian la consola.

---

## 5. RECOMENDACIONES DE SOLUCIÓN (para el asesor)

### 5.1 Acción inmediata (verificación de caché)

1. **Forzar recarga sin caché** en el navegador:
   - **Windows/Linux:** `Ctrl + Shift + R` (o `Ctrl + F5`).
   - **Mac:** `Cmd + Shift + R`.
2. **Abrir DevTools → Network** y confirmar que `app.js` se carga con `?v=14` y que el servidor devuelve `200` (no `304 Not Modified` desde caché).
3. **Verificar en DevTools → Sources** que el `app.js` cargado contiene `sidebarOpen` (buscar `Ctrl+F` → `sidebarOpen`).

### 5.2 Acción de robustez (recomendada en el código)

Para evitar futuros problemas de caché y hacer el sistema más robusto, se recomienda:

1. **Añadir cabeceras de no-caché** para `app.js` en el servidor (`.htaccess` o configuración PHP):
   ```apache
   <FilesMatch "app\.js$">
       Header set Cache-Control "no-cache, no-store, must-revalidate"
       Header set Pragma "no-cache"
       Header set Expires "0"
   </FilesMatch>
   ```

2. **Incrementar la versión del asset** a un valor nuevo (p.ej. `?v=15`) para forzar la invalidación de caché en todos los navegadores que ya tienen cacheada la `?v=14`.

3. **Verificar que el servidor de producción** (SiteGround) tiene desplegada la versión más reciente de `app.js` y `dashboard.php`. Si el despliegue se hizo con `deploy_outbound_full.py`, confirmar que el `MISMATCH: 0` se cumplió.

### 5.3 Acción de diagnóstico adicional (si el problema persiste)

Si tras forzar la recarga sin caché el error persiste, ejecutar en la consola del navegador:

```js
// Verificar que la función app() existe y devuelve las propiedades
typeof app;                       // debe ser "function"
app().sidebarOpen;                // debe ser true/false (no undefined)
app().rsSyncing;                  // debe ser false (no undefined)
```

Si `app().sidebarOpen` devuelve `undefined`, el `app.js` cargado es antiguo (problema de caché/deploy). Si devuelve un valor, el problema está en el scope de Alpine (menos probable).

---

## 6. ARCHIVOS AFECTADOS

| Archivo | Cambio | Estado |
|---------|--------|--------|
| `public_html/outbound/js/app.js` | Añadidas `sidebarOpen`, `toggleSidebar()`, `rsSyncing`; eliminado `window.app = i` | ✅ Correcto |
| `public_html/outbound/dashboard.php` | Layout flex con sidebar; referencia `?v=14`; `x-data="app()"` | ✅ Correcto |
| `public_html/outbound/inc/login_form.php` | Única definición de `showLoginForm()` (refactorización) | ✅ Correcto |

---

## 7. CONCLUSIÓN

El código fuente es correcto y las propiedades `sidebarOpen` y `rsSyncing` están definidas. El error de consola es **casi con total seguridad un problema de caché del navegador** que sigue ejecutando una versión antigua de `app.js`. Se recomienda:

1. Forzar recarga sin caché (`Ctrl+Shift+R`).
2. Confirmar en DevTools que `app.js?v=14` se sirve con `200`.
3. Si persiste, incrementar la versión del asset a `?v=15` y/o añadir cabeceras de no-caché.
4. Verificar el despliegue en producción (SiteGround) con `MISMATCH: 0`.

---

*Fin del informe.*
