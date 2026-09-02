# Checkpoint — FASE G: Notificaciones globales de nuevas respuestas

**Fecha:** 19/08/2026
**Estado:** Implementado y verificado (sintaxis OK)
**Alcance:** UI del dashboard — aviso visual de respuestas nuevas sin revisar

---

## Objetivo

Añadir una capa de notificación global en el dashboard que avise al comercial cuando
existan respuestas nuevas sin revisar, sin necesidad de entrar manualmente al tab de
Respuestas. Esto implementa la **Prioridad 6 (Notificaciones)** del Plan Maestro de
Evolución Post-Core.

---

## Cambios realizados

### 1. `public_html/outbound/js/app.js`

**Nuevo estado (bloque Respuestas):**
```js
rsNuevas: 0,          // contador total de respuestas nuevas sin revisar
rsToast: '',          // mensaje del toast
rsToastVisible: false,
rsToastTimer: null,
```

**En `loadRespuestas()`** — tras cargar las conversaciones:
```js
const totalNuevas = (this.respuestas || []).reduce((acc, c) => acc + (parseInt(c.nuevas) || 0), 0);
this.rsNuevas = totalNuevas;
if (totalNuevas > 0 && !sessionStorage.getItem('rs_toast_mostrado')) {
    sessionStorage.setItem('rs_toast_mostrado', '1');
    this.mostrarToast('🔔 ' + totalNuevas + ' nueva(s) respuesta(s) sin revisar');
}
```

**Nuevos métodos:**
- `mostrarToast(msg)` — muestra el toast con auto-cierre a los 6 segundos.
- `irARespuestas()` — navega al tab de respuestas, limpia el toast y recarga la bandeja.

### 2. `public_html/outbound/dashboard.php`

**Topbar** — botón de campana con contador:
```html
<button @click="irARespuestas()" class="relative ..." title="Respuestas nuevas">
    <i data-lucide="bell" class="w-4 h-4"></i>
    <span x-show="rsNuevas > 0" x-cloak class="absolute -top-1 -right-1 bg-amber-500 ...">{{ rsNuevas }}</span>
</button>
```

**Toast fijo abajo-derecha** (antes del cierre del body):
```html
<div x-show="rsToastVisible" x-cloak x-transition.opacity.duration.300ms class="fixed bottom-5 right-5 z-[100] max-w-sm w-full">
    <!-- Título "Nuevas respuestas" + mensaje + botón "Ver" + botón cerrar -->
</div>
```

**Bump de versión** de `app.js` a `v=11` para forzar recarga de caché.

---

## Compatibilidad con el API

El endpoint `get_respuestas` (`api/analytics.php`) ya devuelve el campo `nuevas` en
cada conversación (líneas 202 y 208). La lógica JS suma `c.nuevas` de todas las
conversaciones para obtener el contador global `rsNuevas`. **No se requirió ningún
cambio en el backend.**

---

## Comportamiento

1. Al cargar el dashboard, `loadRespuestas()` se ejecuta y calcula `rsNuevas`.
2. Si hay respuestas nuevas y no se ha mostrado el toast en esta sesión
   (`sessionStorage`), aparece el toast con el total.
3. El badge de la campana en el topbar muestra el contador `rsNuevas` en todo momento.
4. Al hacer clic en la campana o en "Ver" del toast, se navega al tab de Respuestas
   y se limpia el aviso.

---

## Verificación

- `php -l public_html/outbound/dashboard.php` → **No syntax errors detected**
- `node --check public_html/outbound/js/app.js` → **OK**
- `php -l public_html/outbound/api/analytics.php` → **No syntax errors detected**
- `php -l public_html/outbound/tabs/respuestas.php` → **No syntax errors detected**

---

## Notas

- El toast usa `sessionStorage` para mostrarse solo una vez por sesión de navegador,
  evitando ruido en cada recarga.
