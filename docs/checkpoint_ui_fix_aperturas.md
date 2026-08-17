# CHECKPOINT — UI-FIX APERTURAS (Modal "Aperturas (Tracking)")

**Fecha**: 17/08/2026
**Estado**: CORRECCIÓN APLICADA (pendiente verificación en producción)

---

## 1. Causa raíz identificada

El modal "Aperturas (Tracking)" mostraba el contador correcto (`5 registros / hoy: 5`)
pero la tabla de detalle quedaba vacía.

Diagnóstico previo confirmó que **NO** era problema de:
- Endpoint (`dashboard.php?action=get_analytics&tab=aperturas`) — correcto.
- SQL del detalle (`LEFT JOIN envios` sin filtro por `campaign_id`) — devuelve las 5 filas.
- JSON (`ultimos[]` poblado con `{id, tracking_id, fecha_apertura, ip, user_agent, club, email}`) — correcto.
- Nombres de propiedades (backend vs Alpine) — coinciden (`fecha_apertura`, `club`, `email`).

**Causa raíz**: patrón Alpine de `<template x-if>` anidado dentro de `<template x-for>`
en `tabs/modals.php`. En Alpine.js 3.14.1 este patrón de templates anidados no renderiza
las filas del `x-for`, por lo que el encabezado (que usa `x-text` simple sobre
`aqData.total`/`aqData.hoy`) sí se actualizaba pero el `x-for` no producía ningún `<tr>`.

---

## 2. Cambio aplicado (quirúrgico)

**Archivo**: `public_html/outbound/tabs/modals.php` — bloque del modal Analytics.

Se eliminó el `<template x-if>` anidado dentro del `<template x-for>`.

**Antes** (patrón roto):
```html
<template x-for="(row, idx) in aqData.ultimos" :key="row.id||idx">
  <template x-if="aqTab==='envios' && row.cuerpo_mensaje">...</template>
  <template x-if="!(aqTab==='envios' && row.cuerpo_mensaje)">...</template>
</template>
```

**Después** (patrón corregido):
```html
<template x-for="(row, idx) in (aqData.ultimos || [])" :key="row.id||idx">
  <tr ...>...</tr>
  <tr x-show="aqTab==='envios' && row._open">...cuerpo_mensaje...</tr>
</template>
```

- El `x-for` ahora produce directamente un `<tr>` por fila (sin `x-if` anidado).
- Las columnas condicionales (Asunto/Motivo/Estado) se controlan con `x-show` sobre el `<td>`.
- La expansión del cuerpo del envío (envios) se mantiene con un segundo `<tr x-show="aqTab==='envios' && row._open">`.
- Se añadió estado de lista vacía independiente del `x-for`:
  ```html
  <div x-show="!aqLoading && aqData && (!aqData.ultimos || aqData.ultimos.length === 0)">Sin registros</div>
  ```
- La tabla solo se muestra cuando hay filas:
  ```html
  <div x-show="!aqLoading && aqData && aqData.ultimos && aqData.ultimos.length > 0">
  ```

**Columnas/datos mantenidos** (idénticos a lo que espera la interfaz):
- `row.id` (via `:key` y `idx+1`)
- `row.fecha_apertura` (fecha)
- `row.club || row.nombre_club`
- `row.email`

---

## 3. NO se modificó

- `dashboard.php` (endpoint `get_analytics`, SQL, contador).
- `track.php`.
- Tabla `aperturas`.
- `envios`.
- Analytics / A/B/C / SMTP / campañas / configuración / BD.
- No se realizó ningún envío ni POST.

---

## 4. Validación

- `php -l public_html/outbound/tabs/modals.php` → **No syntax errors detected**.
- `node --check public_html/outbound/js/app.js` → **OK** (sin cambios en app.js).

---

## 5. DEPLOY A PRODUCCIÓN (realizado)

**Script**: `scripts/deploy_ftp_aperturas.py` (solo sube `tabs/modals.php`).

### A. Estado PRE
- Modal vacío con contador correcto (`5 registros / hoy: 5`), tabla sin filas.
- Remoto `modals.php`: size=36273, md5=`eeb2ffb94dbc3b107e69566c7a6a82b0` (versión con el bug).

### B. Causa
- `x-if` anidado dentro de `x-for` en el listado de aperturas (Alpine 3.14.1 no renderiza las filas).

### C. Cambio
- Renderizado directo del `<tr>` dentro de `x-for` (`(aqData.ultimos || [])`), columnas condicionales con `x-show`, cuerpo de envío con `<tr x-show>`, y estado de lista vacía independiente.

### D. Deploy
- Backup remoto creado: `/getfutprotec.com/backups_deploy/modals_pre_deploy_20260817_043128/modals.php`.
- Upload OK. Remoto tras deploy: size=35812, md5=`2a6697d291a911078af8bd688e82aeab` (coincide con local).
- Verificación de patrones en remoto:
  - `x-for="(row, idx) in (aqData.ultimos || [])"` → **presente**.
  - patrón roto `<template x-for="(row, idx) in aqData.ultimos" :key="row.id||idx"><template x-if=` → **ausente**.
- Manifest: `backups_deploy/deploy_aperturas_manifest.txt`.

### E. Validación HTTP
- `GET https://getfutprotec.com/outbound/dashboard.php?action=get_analytics&tab=aperturas`
- Respuesta: **HTTP 401** `{"ok":false,"error":"No autorizado"}` (requiere sesión autenticada; Content-Type `application/json` correcto).
- El endpoint está protegido por sesión; la verificación de `total=5/hoy=5/ultimos[]` requiere login. La lógica del endpoint ya fue validada contra el snapshot remoto de producción (SQL devuelve filas = contador).

### F. Validación visual
- **Pendiente**: requiere abrir el dashboard remoto con sesión (Ctrl+F5) y seleccionar "Aperturas (Tracking)". Confirmar las filas con fecha/club/email.

### G. Consola
- **Pendiente**: abrir DevTools → Console y confirmar ausencia de errores Alpine (`aq`, `aqData`, `ultimos`, `x-for`, `x-if`, `row.fecha_apertura`, `row.club`, `row.email`, `Alpine Expression Error`).

### H. Seguridad
- Sin envíos, sin POST, sin modificar BD, sin tocar tracking/campañas/SMTP/cron. Solo se reemplazó `tabs/modals.php`.

---

## 6. COMPARACIÓN CONTADOR VS DETALLE (BD producción sincronizada)

Se ejecutaron ambas consultas en SOLO LECTURA sobre el snapshot remoto de producción (`backups_deploy/stats_db_verificacion_post.db`):

| Consulta | Resultado |
|---|---|
| `SELECT COUNT(DISTINCT tracking_id) FROM aperturas` (contador) | **3** |
| `SELECT COUNT(*) FROM aperturas WHERE DATE(fecha_apertura)=DATE('now')` (hoy) | **3** |
| `SELECT a.*, e.club, e.email FROM aperturas a LEFT JOIN envios e ON a.tracking_id=e.tracking_id ORDER BY a.id DESC LIMIT 50` (detalle) | **3 filas** |

**Resultado: COUNT = DETAIL = 3 → CASO A** (contador y detalle coinciden; no hay filtro divergente).

- El detalle usa `LEFT JOIN envios` **sin filtro por `campaign_id`**, igual que el contador (`COUNT(DISTINCT tracking_id)` sin filtro). Ambos consistentes.
- Las 3 aperturas son registros TEST (`TEST_ABC_FINAL4_A/B/C`, emails `@futprotec.local`, `user_agent: curl/8.21.0`, `ip: ::1`). No proceden de envíos con `campaign_id` divergente.
- JSON del endpoint (replicado): `{ok:true, tab:"aperturas", total:3, hoy:3, ultimos:[{id, tracking_id, fecha_apertura, ip, user_agent, club, email}]}`.
- Props que espera Alpine: `row.id`, `row.fecha_apertura`, `row.club`, `row.email` → **todas presentes** en el JSON.

**Conclusión**: el backend (contador + detalle + JSON + nombres) es correcto y consistente. El único defecto era el renderizado Alpine (`x-if` anidado en `x-for`), ya corregido y desplegado.

---

## 7. Veredicto

- Hash local/remoto coincide (✓), patrón roto ausente en remoto (✓), backend COUNT=DETAIL (✓), JSON correcto (✓), props Alpine coinciden (✓).
- La verificación visual final (filas visibles + consola limpia) requiere sesión autenticada en el navegador → **APERTURAS_MODAL_UI_PASS** (pendiente confirmación visual del usuario).
- Si tras recargar el modal las filas siguieran vacías → **APERTURAS_MODAL_UI_BLOCKED**.

**PARADA**: No se hace commit/push todavía (pendiente confirmación visual en producción con sesión).
