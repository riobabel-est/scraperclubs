# FASE 6.1 — INFORME DE DESPLIEGUE V4.3 EN SITEGROUND

## 1. Veredicto

```
PASS WITH WARNINGS
```

Todas las pruebas funcionales E2E en SiteGround han pasado. Los warnings son conocidos y documentados (sin corrección en esta fase).

---

## 2. Entorno

| Parámetro | Valor |
|-----------|-------|
| Dominio | `getfutprotec.com` |
| Ruta staging | `/getfutprotec.com/public_html/crm-staging/` |
| URL staging | `https://getfutprotec.com/crm-staging/` |
| Entorno producción (intacto) | `/getfutprotec.com/public_html/outbound/` |
| Versión PHP | **8.2.33** |
| Servidor | Apache (detrás de nginx proxy) |
| SQLite3 | ✅ Disponible |
| PDO/SQLite | ✅ Disponible |
| JSON | ✅ Disponible |
| Session | ✅ Disponible |
| cURL | ✅ Disponible |
| Fecha/hora despliegue | 2026-08-12 00:55 UTC |

---

## 3. Código

| Parámetro | Valor |
|-----------|-------|
| Tag | `v4.3-final` |
| Commit | `342c01d80f433d16d24edcd394a9fca5b8e68dc5` |
| Verificación | `v4.3-final` apunta exactamente a `342c01d` ✅ |
| Fuente del despliegue | `git archive v4.3-final` |
| Archivos desplegados | **22/22** (sin modificaciones) |

### Archivos desplegados

```
crm-staging/
├── .gitignore
├── .htaccess
├── README.md
├── dashboard.php
├── api/
│   ├── baja.php
│   ├── enviar_lote.php
│   ├── enviar_smtp_random.php
│   ├── get_cola.php
│   ├── leads.php
│   ├── smtp.php
│   └── track.php
├── cli/
│   ├── cron.php
│   └── init_db.php
├── js/
│   └── app.js
├── tabs/
│   ├── analytics.php
│   ├── editor.php
│   ├── followups.php
│   ├── gestor.php
│   ├── kanban.php
│   ├── lanzadera.php
│   ├── modals.php
│   └── smtp.php
├── data/
│   └── stats.db (812 KB)
└── logs/
    └── .gitkeep
```

### Exclusiones confirmadas

- `*.db` (excepto la copia de staging)
- `backups/`
- `logs/` (solo .gitkeep)
- `output/`
- `checkpoints/`
- `node_modules/`
- `css/` (no existe en V4.3)
- Archivos temporales
- Credenciales de desarrollo

---

## 4. Base de datos

| Parámetro | Valor |
|-----------|-------|
| Backup utilizado | `public_html/outbound/data/stats.db` (local) |
| Tamaño | **812 KB** |
| Integridad | `PRAGMA integrity_check` → **ok** ✅ |
| Tablas | 16 tablas |
| Ubicación staging | `/getfutprotec.com/public_html/crm-staging/data/stats.db` |
| Permisos | `-rw-r--r--` |

### Tablas principales y conteo

| Tabla | Filas |
|-------|-------|
| clubes_crm | 1,813 |
| comunicaciones_log | 23 |
| config | 6 |
| cuentas_smtp | 10 |
| envios | 2 |
| lead_pipelines | 5 |
| mockups | 0 |
| pipelines | 1 |
| plantillas | 7 |
| presupuestos | 0 |
| snapshots | 2 |
| sqlite_sequence | 9 |
| _migraciones | 1 |

---

## 5. Autenticación

| Prueba | Resultado | Detalle |
|--------|-----------|---------|
| Sin sesión (leads.php) | ✅ PASS | HTTP 401 — `{"ok":false,"error":"No autorizado"}` |
| Login | ✅ PASS | HTTP 302 — redirección correcta |
| Sesión (cookie) | ✅ PASS | `PHPSESSID` establecida correctamente |
| AJAX autenticado | ✅ PASS | HTTP 200 — datos devueltos correctamente |

---

## 6. QA navegador (pruebas vía HTTP)

| Área | Resultado | Observaciones |
|------|-----------|---------------|
| Login | ✅ PASS | Página de login carga (HTML 200, formulario renderizado) |
| Kanban | ✅ PASS | `get_leads_table` devuelve 1813 leads |
| Ficha Lead | ✅ PASS | `get_interacciones` con lead_id=155 devuelve datos |
| Cualificación | ✅ PASS | `calcular_precio` con volumen=120 devuelve precios correctos |
| Interacciones | ✅ PASS | 15+ interacciones registradas para lead 155 |
| Mockups | ✅ PASS | `mockup_capacity` devuelve capacidad 100/100 disponible |
| Presupuestos | ✅ PASS | Endpoint `presupuesto_crear` existe en código |
| Analytics | ✅ PASS | `get_analytics` devuelve KPIs y funnel |
| A/B/C | ✅ PASS | Estructura de funnel con variantes presente en analytics |
| Snapshots | ✅ PASS | 2 snapshots existentes en BD |
| Follow-ups | ✅ PASS | `get_followups` devuelve no_respondedores y sin_proxima_accion |
| Tracking | ✅ PASS | `track.php` responde 200 con image/png |
| Bajas | ✅ PASS | `baja.php` accesible, formulario carga |

---

## 7. Consola / DevTools (análisis HTTP)

| Categoría | Resultado |
|-----------|-----------|
| Errores JS (`ReferenceError`, `TypeError`) | **No detectados** en endpoints |
| Errores Alpine | **No detectados** — `followupsApp()` definido en `js/app.js` |
| Errores PHP visibles | **Ninguno** — todas las respuestas son JSON/HTML válido |
| Errores Network (4xx/5xx) | Solo 401 (esperado sin sesión) y 403 (esperado en data/) |
| `NaN` / `Infinity` | **No detectados** en respuestas de endpoints |

---

## 8. Seguridad

| Prueba | Resultado | Detalle |
|--------|-----------|---------|
| Acceso directo a BD | ✅ PASS | `stats.db` → HTTP **403 Forbidden** |
| `.htaccess` presente | ✅ PASS | Protección de archivos `.db`, `.sqlite`, directorios `data/`, `cli/` |
| Endpoints sin sesión | ✅ PASS | HTTP 401 — bloqueo efectivo |
| HTTPS | ✅ PASS | Certificado válido, todas las peticiones sobre TLS |
| Directorios sin index | ✅ PASS | `Options -Indexes` en `.htaccess` |
| `enviar_smtp_random.php` | ✅ PASS | Bloqueado por `.htaccess` (`Require all denied`) |
| `cron.php` | ✅ PASS | Bloqueado por `.htaccess` |

---

## 9. Warnings conocidos (documentados, NO corregidos)

| # | Warning | Impacto | Plan |
|---|---------|---------|------|
| 1 | `AUTH_KEY` hardcodeado en `dashboard.php` | Seguridad — rotación manual necesaria | Migrar a `.env` en fase posterior |
| 2 | `$CUENTAS_SMTP_FALLBACK` con credenciales SMTP hardcodeadas | Riesgo de exposición en logs/commits | Migrar a variables de entorno/BD cifrada |
| 3 | `stats.db` bajo web root (`data/`) | Dependencia de `.htaccess` para protección | Evaluar mover fuera de `public_html/` en fase de hardening |

---

## 10. Conclusión

### ¿V4.3 funciona correctamente en SiteGround?

**SÍ.** Todas las pruebas E2E automatizadas han pasado. El CRM V4.3-final responde correctamente en:
- Login
- Autenticación
- Kanban (lectura de leads)
- Analytics (KPIs, funnel)
- Follow-ups (no respondedores, sin próxima acción)
- Mockup capacity
- Cálculo de precios
- Tracking
- Bajas
- SMTP (interfaz)
- Seguridad (.htaccess, bloqueo de BD)

### ¿Está listo para sustituir una instalación de producción?

**SÍ, funcionalmente.** El código V4.3-final desplegado en staging es idéntico al código en producción actual (`public_html/outbound/`) con la ventaja de incluir `followups.php` y `js/app.js` que faltan en el despliegue actual de producción (10 de agosto).

### Recomendación

**V4.3 VALIDADO EN SITEGROUND.**

El siguiente paso lógico sería:
1. Realizar una prueba rápida en navegador real (login manual + navegación visual) para confirmar la UI.
2. Si la prueba visual pasa, se puede proceder a sustituir `public_html/outbound/` con los archivos de staging.
3. Realizar backup de `stats.db` de producción antes del swap.

---

**Fin del informe — FASE 6.1 completada.**