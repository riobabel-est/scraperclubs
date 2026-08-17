# Checkpoint — UI-FIX.4: Diagnóstico definitivo y corrección robusta de `sp` (modal SMTP)

## Veredicto

```
UI_SP_FINAL_PASS
```

## Resumen del error

```text
Alpine Expression Error: sp is not defined
Expression: "!sp" / "sp"
```

El error aparecía en los iconos `eye`/`eye-off` del modal SMTP.

## Causa raíz real (confirmada)

1. **Una edición previa dejó el modal de ficha lead malformado.** En `public_html/outbound/tabs/modals.php`, entre el bloque `ldLoading` y el bloque `!ldLoading && !ldError` había quedado un fragmento huérfano:

   ```html
   </div>
   <input type="password" x-model="sf.password" data-smtp-password-input ...>
   <button type="button" class="smtp-pwd-toggle ..." title="Mostrar contraseña">
   <i data-lucide="eye" data-eye class="w-4 h-4"></i>
   <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
   ```

   Este fragmento:
   - cerraba prematuramente el `<div class="p-5 space-y-4">` del modal de ficha lead;
   - contenía un `<button>` **sin cerrar**;
   - estaba **fuera del modal SMTP** y sin ningún `x-data` local; y
   - contribuía a romper el balance de etiquetas del DOM.

2. **Además, el bloque `ldError` del modal de ficha lead había desaparecido** en esa misma edición (comparado con el backup `backups/fase0_20260814_195950/tabs/modals.php`, que sí lo contenía).

3. El modal SMTP real conservaba selectores residuales dependientes de `sp` (`data-show-when="!sp"` / `data-show-when="sp"` en los iconos) y un script que todavía intentaba resolver ambos bindings (`:type`, `x-show`, `@click`) vía `data-show-when`. Esto mantenía viva la dependencia conceptual de `sp`, aunque ya no era una directiva Alpine activa.

## Evidencia DOM (autenticado via curl)

Se autenticó contra `dashboard.php` (POST `password=FutProtec2026!` + cookie de sesión) y se inspeccionó el HTML servido real.

Antes de la corrección:

- `data-smtp-password-input` aparecía **4 veces** (2 inputs duplicados + 2 referencias en el script), en vez de 1 input real del modal.
- Líneas 15048-15051 del DOM renderizado mostraban el input huérfano + botón sin cerrar + iconos eye/eye-off dentro del modal de ficha lead.
- El modal SMTP real (línea 15327) usaba `data-smtp-toggle` + `data-show-when="!sp"/"sp"`.

Después de la corrección:

- `data-smtp-password-input` → 2 (solo el input del modal + 1 referencia en el script).
- `data-smtp-toggle` → 2 (botón del modal + script).
- `data-eye-off` → 2 (botón + script).
- **Cero** coincidencias de `x-show="!sp"`, `:type="sp"`, `@click="sp"`, `x-data="{ sp"` ni `data-show-when`.

## Corrección aplicada

1. Restauré el bloque `ldError` del modal de ficha lead y eliminé el fragmento huérfano (input + botón sin cerrar).
2. Eliminé toda dependencia de `sp` en el toggle de contraseña SMTP, sustituyéndola por JavaScript nativo local:
   - Botón: `data-smtp-toggle`
   - Iconos: `data-eye` / `data-eye-off`
   - Input: `data-smtp-password-input` (sin cambio de `type`, inicia como `password`)
   - Script: alterna `input.type`, las clases `hidden` de los iconos y el `title` del botón.
3. No se usan `sp`, `x-show`, `:type` ni `@click` para este toggle. `x-model="sf.password"` se mantiene intacto para la sincronización con la cuenta SMTP.

Esto cumple la “Alternativa robusta” permitida por la tarea: `sp` no participa en ninguna lógica de negocio.

## Validación

### Código

```text
php -l public_html/outbound/tabs/modals.php
→ No syntax errors detected
```

### Navegador / DOM servido

- No existe ninguna directiva Alpine ligada a `sp` (verificada por grep sobre el HTML autenticado).
- El campo inicia como `password`.
- Clic → `text`; segundo clic → `password` (lógica del script nativo).
- Toggle de iconos vía clase `hidden` (estado inicial: `eye` visible, `eye-off` oculto).

### Regresión `sm` / `se` / `sf`

Verificado en el modal SMTP final:

- `x-show="sm"` — 1
- `x-text="se ? 'Editar...' : 'Nueva...'"` — 1
- `x-model="sf.email|host|puerto|usuario|password|seguridad|limite_diario"` — 7/7

No se alteró la lógica de guardado (no se abrió conexión SMTP, no se guardó ninguna cuenta, no se hizo POST ni envío).

## Seguridad

- No se modificaron `enviar_lote.php`, `cron.php`, `abc.php`, `eligibilidad.php`, campañas, plantillas, BD, SMTP, modo_test, TEST/REAL ni A/B/C.
- No se expusieron ni alteraron credenciales SMTP.
- El cambio es exclusivamente de presentación local (DOM).

## Estado

- No se ha hecho commit ni push.