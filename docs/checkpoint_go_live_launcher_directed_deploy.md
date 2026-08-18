# Checkpoint — Deploy GO-LIVE.2: Envío Dirigido + Tamaño de Lote

**Fecha:** 17/08/2026
**Estado:** DEPLOY_ALREADY_COMPLETE (los 4 archivos ya estaban en producción y coinciden con local)
**Regla:** NO se ha enviado ningún correo. NO se ha iniciado el motor. NO se ha ejecutado cron. NO se ha modificado la BD.

---

## 1. Pre-flight local

```
git status --short:
  M public_html/outbound/js/app.js
  M public_html/outbound/tabs/lanzadera.php
  ?? public_html/outbound/api/lead_search.php
  ?? public_html/outbound/api/lead_validate.php

git rev-parse HEAD: e576ea8ff65da9d5f2088ec10fcaef42795363fa

php -l api/lead_search.php      → No syntax errors
php -l api/lead_validate.php    → No syntax errors
php -l tabs/lanzadera.php       → No syntax errors
node --check js/app.js          → OK
```

Pre-flight PASS. No `DEPLOY_BLOCKED`.

---

## 2. FASE 1 — Verificación MD5 local vs remoto (sin upload)

```
api/lead_search.php       MATCH  a3b286c486e38d28f9c3d88cfe4a7a65
api/lead_validate.php     MATCH  845d940f96fc52c2e780e9c53c98daf7
tabs/lanzadera.php        MATCH  2ad1b8bfa957031646a784edc7d5f04a
js/app.js                 MATCH  d752bb4901d71917dfc38cb7f4df8e0f
```

Los 4 archivos ya estaban desplegados y coinciden con el local.
**Resultado: `DEPLOY_ALREADY_COMPLETE`** → no se subió nada.

---

## 3. FASE 2 — Backup

No aplica: no hubo sobrescritura (todos MATCH). No se creó backup nuevo.
Los backups previos existentes se conservan intactos.

---

## 4. FASE 3 — Verificación final

- MD5 local == MD5 remoto para los 4 archivos (ver FASE 1).
- `https://getfutprotec.com/outbound/js/app.js?v=10` → **HTTP 200**
- MD5 app.js servido por HTTP == local (`d752bb...`).

---

## 5. Endpoints nuevos (sin envío)

```
https://getfutprotec.com/outbound/api/lead_search.php     → HTTP 401 (requiere auth, NO 500)
https://getfutprotec.com/outbound/api/lead_validate.php   → HTTP 401 (requiere auth, NO 500)
```

401 coherente con autenticación. No hay HTTP 500. No se ejecutó ningún envío.

---

## 6. Lanzadera remota

`https://getfutprotec.com/outbound/tabs/lanzadera.php` → **HTTP 200**
Contenido verificado:
- "Envío Dirigido" presente ✓
- "Tamaño de Lote" / `lzBatchSize` presente ✓
- `lzSearchLeads`, `lzValidateLead` presentes en app.js remoto ✓

Nota: el MD5 del HTML servido por HTTP difiere del local porque `lanzadera.php`
es un template parcial renderizado dentro de `dashboard.php`; el archivo remoto
por FTP coincide exactamente con el local (FASE 1).

---

## 7. Seguridad

```
envios totales  = 18   (sin envíos nuevos; baseline previo = 18)
envios hoy      = 10   (corresponden a envíos previos del día, NO a este deploy)
motor_estado    = pausado
modo_entorno    = produccion
integridad      = ok
Campaña 2       = PILOT / pilot / activo=1 (sin cambios)
```

```
SMTP = NO
POST de envío = NO
cron = NO
BD modificada = NO
campaign modificada = NO
config modificada = NO
```

---

## 8. Prueba funcional sin SMTP (pendiente manual)

La prueba de "Buscar → seleccionar → Validar elegibilidad" requiere sesión
autenticada en el navegador (los endpoints devuelven 401 sin sesión). No se
puede ejecutar desde CLI. Queda pendiente de validación manual en la UI:

```
Campaña = [ID 2] Piloto Comercial
Modo = PRODUCCIÓN
Plantilla = [ID 1] Prospeccion (abc - texto plano)
Estado = 01 Sin Contactar
→ Buscar lead real
→ seleccionar
→ Validar elegibilidad → debe devolver elegible=true SIN generar envío
```

---

## 9. Veredicto

```
DEPLOY_ALREADY_COMPLETE
```

Los 4 archivos de la nueva funcionalidad de Lanzadera (Envío Dirigido + Tamaño
de Lote) están desplegados y verificados en producción. No se ha enviado ningún
correo ni modificado la BD.

---

## 10. Pendiente

- Validación manual en navegador (sesión autenticada) del flujo Buscar → Seleccionar → Validar.
- El siguiente paso será el primer envío REAL usando Envío Dirigido → 1 club → Validar → Iniciar → 1 solo correo.
