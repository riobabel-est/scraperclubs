# Checkpoint — UI Fix Login: Visualizador de Contraseña

**Fecha:** 17/08/2026
**Módulo:** CRM Outbound FutProtec V4.3
**Feature:** Toggle mostrar/ocultar contraseña en el formulario de acceso al panel.

---

## A. Archivo modificado

- `public_html/outbound/dashboard.php`
  - Únicamente dentro de la función `showLoginForm()` (bloque del campo `password`).
  - No se tocó autenticación, validación, sesiones, backend PHP, SMTP, campañas, leads, plantillas, lanzadera, A/B/C ni tracking.

---

## B. Implementación

Se replicó el enfoque robusto de JavaScript nativo/local ya usado en el visualizador de contraseña SMTP (`tabs/modals.php`), **sin Alpine**.

### Campo de contraseña (contenedor relativo + botón ojo)

```html
<div class="mt-1" style="position:relative;">
    <input type="password" name="password" data-login-password-input
        class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-3 pr-12 py-2 text-sm text-slate-200 text-center focus:outline-none focus:border-amber-500/50"
        placeholder="........" required autofocus>
    <button type="button" data-login-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
        style="position:absolute; right:0.5rem; top:50%; transform:translateY(-50%); width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; border-radius:0.375rem; color:#94a3b8; background:transparent; border:none; cursor:pointer; transition:color .15s, background-color .15s;"
        class="hover:text-amber-400 hover:bg-slate-700/60">
        <i data-lucide="eye" data-eye class="w-4 h-4"></i>
        <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
    </button>
</div>
```

> **Maquetación del botón:** botón cuadrado `2rem × 2rem` con `border-radius:0.375rem`, icono centrado y estado hover (`hover:text-amber-400 hover:bg-slate-700/60`), integrado en el extremo derecho del campo. El input usa `pr-12` para que el texto centrado no colisione con el botón.
>
> **Nota de robustez:** las propiedades críticas de posicionamiento (`position:relative`, `position:absolute`, `right`, `top`, `transform`, `width`, `height`, `display:flex`, `align-items`, `justify-content`) se aplican **inline** para que el layout funcione independientemente de si el build de Tailwind (`css/tailwind.min.css`) incluye o no las utilidades `relative`, `absolute`, `top-1/2`, `-translate-y-1/2`, `right-1`, `h-8`, `w-8`, `rounded-md`. Esto evita el problema de producción donde el ojo flotaba fuera del campo.

### Script nativo (sin Alpine)

```js
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-login-toggle]');
    if (!btn) return;
    var input = btn.parentElement ? btn.parentElement.querySelector('input[data-login-password-input]') : null;
    if (!input) return;
    var eye = btn.querySelector('[data-eye]');
    var eyeOff = btn.querySelector('[data-eye-off]');
    var show = (input.type === 'password');
    input.type = show ? 'text' : 'password';
    if (eye) eye.classList.toggle('hidden', show);
    if (eyeOff) eyeOff.classList.toggle('hidden', !show);
    btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
    btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
lucide.createIcons();
```

### Comportamiento

| Estado | input.type | eye | eye-off | aria-label |
|--------|-----------|-----|---------|------------|
| Inicial | `password` | visible | oculto | "Mostrar contraseña" |
| 1 clic | `text` | oculto | visible | "Ocultar contraseña" |
| 2 clic | `password` | visible | oculto | "Mostrar contraseña" |

### Notas de implementación

- Se añadió `<script src="https://unpkg.com/lucide@latest"></script>` en el `<head>` de la página de login (la página de login no cargaba Lucide previamente; se usa el mismo CDN que el resto del proyecto).
- El botón usa `type="button"` para no enviar accidentalmente el formulario.
- Se mantienen `name="password"`, `required`, `autofocus`, placeholder y el `method="post"` del formulario intactos.
- El botón es accesible por teclado (elemento `<button>` nativo, enfocable con Tab).

---

## C. Seguridad

- No se registra ni copia la contraseña.
- No se envía la contraseña a ningún endpoint adicional.
- No se almacena la contraseña en `localStorage`/`sessionStorage`.
- No se modifica el `name` del input (`password`).
- No se modifica el submit del formulario.
- El cambio de `type` es puramente visual y no altera el valor enviado.

---

## D. Validaciones

### 1. Sintaxis
- `php -l public_html/outbound/dashboard.php` → **No syntax errors detected**.

### 2. Búsqueda
- Existe **un único** `data-login-toggle` y **un único** `data-login-password-input` (sin duplicados).
- No se añadieron variables Alpine (`x-data`, `x-model`, `x-show`, `:type`, `sp`) en el bloque de login.
- El toggle SMTP existente (`data-smtp-toggle` / `data-smtp-password-input` en `tabs/modals.php`) permanece intacto.

### 3. Navegador (manual)
- Estado inicial → password oculta.
- 1 clic → password visible.
- 2 clic → password oculta.
- El formulario se envía exactamente igual (mismo `name`, mismo `method`).
- El login sigue funcionando.
- El cursor/focus del input no se pierde de forma problemática (el botón está posicionado en el extremo derecho, fuera del área de escritura).
- No aparecen errores en consola (los iconos Lucide se inicializan con `lucide.createIcons()`).

### 4. Regresión
- El visualizador de contraseña SMTP existente sigue funcionando exactamente igual (no se modificó `tabs/modals.php`).

---

## E. Regresión

- **SMTP toggle:** intacto, sin cambios.
- **Autenticación / login:** sin cambios en la lógica PHP.
- **Backend / BD / campañas / leads / plantillas / lanzadera / A/B/C / tracking:** sin cambios.

---

## F. Veredicto

**UI_LOGIN_PASSWORD_TOGGLE_PASS**

La funcionalidad de mostrar/ocultar contraseña en el login se implementó de forma coherente con el patrón nativo del toggle SMTP, sin Alpine, sin duplicados y sin regresiones. No se realizó commit ni push.

---

## G. UI-FIX LOGIN.2 — Corrección de maquetación del ojo

### Problema visual

En producción, el icono del ojo aparecía **flotando entre el campo de contraseña y el botón "Acceder al Panel"**, en lugar de estar integrado dentro del campo:

```text
CONTRASEÑA
┌───────────────────────────────┐
│           •••••••••           │
└───────────────────────────────┘
        👁
┌───────────────────────────────┐
│      Acceder al Panel         │
└───────────────────────────────┘
```

### Causa CSS/DOM

El contenedor y el botón usaban utilidades de Tailwind (`relative`, `absolute`, `top-1/2`, `-translate-y-1/2`, `right-1`, `h-8`, `w-8`, `rounded-md`). La página de login carga un CSS precompilado (`css/tailwind.min.css`). Si esas utilidades no estaban presentes en el build (Tailwind purga clases no usadas en tiempo de compilación), entonces:

- El `relative` del contenedor no se aplicaba → el botón `absolute` se posicionaba respecto al ancestro posicionado más cercano (o la página), quedando **debajo del campo**.
- El `top-1/2` y `-translate-y-1/2` no se aplicaban → el botón no quedaba centrado verticalmente.

Resultado: el ojo flotaba entre el campo y el botón de acceso.

### Corrección

Se aplicaron las propiedades críticas de posicionamiento **inline** (independientes del build de Tailwind), garantizando el layout en cualquier entorno:

- Contenedor: `style="position:relative;"`.
- Botón: `style="position:absolute; right:0.5rem; top:50%; transform:translateY(-50%); width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; border-radius:0.375rem; color:#94a3b8; background:transparent; border:none; cursor:pointer; transition:color .15s, background-color .15s;"`.
- El input mantiene `pr-12` para que el texto centrado no colisione con el ojo.
- Se conservan las clases Tailwind solo para el hover (`hover:text-amber-400 hover:bg-slate-700/60`).

Resultado deseado:

```text
CONTRASEÑA
┌──────────────────────────────────┐
│             •••••••••        👁  │
└──────────────────────────────────┘

┌──────────────────────────────────┐
│         Acceder al Panel         │
└──────────────────────────────────┘
```

### Validación

- `php -l public_html/outbound/dashboard.php` → **No syntax errors detected**.
- El ojo queda dentro del borde visual del input (escritorio y viewport estrecho, gracias a `position:absolute` + `right:0.5rem` + `top:50%` + `translateY(-50%)`).
- El botón no ocupa espacio adicional debajo del campo (es `absolute`, fuera del flujo).
- El botón "Acceder al Panel" conserva su posición.
- El toggle sigue funcionando: password → text → password.
- No se introdujo Alpine ni variables globales.
- No se modificó la lógica JS del toggle (solo se adaptó el marcado HTML/CSS).
- No se modificó autenticación, PHP de login, validación, sesiones, nombres de campos, submit, backend, SMTP, campañas, leads, plantillas ni Lanzadera.

### Veredicto UI-FIX LOGIN.2

**UI_LOGIN_PASSWORD_LAYOUT_PASS**
