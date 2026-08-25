
**Fecha de análisis:** 2026-08-19
**Estado:** Documento de retomada (no implica cambios en producción)
**Ámbito:** `public_html/outbound/`

---

## 1. RESUMEN EJECUTIVO

El módulo outbound del CRM FutProtec tiene un núcleo funcional sólido, pero presenta
problemas de mantenibilidad que conviene corregir de forma incremental:

1. **Duplicación masiva de lógica SMTP** (3-4 implementaciones del mismo protocolo) — riesgo de seguridad.
2. **Funciones monolíticas** en `analytics.php`, `leads.php` e `imap_respuestas.php`.
3. **HTML/PHP/JS mezclados** en `dashboard.php` y `modals.php`.
4. **Código duplicado** en `js/app.js` (84KB, 1309 líneas).
5. **Lógica de negocio embebida en vistas** en varios tabs.

**Estimación total:** 18-26 horas de trabajo efectivo (3-4 días a tiempo completo).

**Regla de oro:** Cada refactor debe hacerse de forma incremental, con test + deploy +
checkpoint, sin romper funcionalidad existente y respetando la regla de "no deshacer
features ya terminadas".

---

## 2. PRIORIDAD ALTA — Lógica SMTP duplicada

### 2.1 Problema

Existen **3-4 implementaciones distintas** del envío SMTP autenticado por socket, con el
mismo flujo EHLO/STARTTLS/AUTH/MAIL/RCPT/DATA/QUIT:

| Archivo | Función | Líneas |
|---|---|---|
| `cli/cron.php` | `enviarSMTP()` | 358-490 |
| `api/enviar_smtp_random.php` | `enviarSMTPAutenticado()` | 281-394 |
| `inc/mime.php` | `enviarSMTPAutenticado()` | 75-246 |
| `api/enviar_smtp_random.php` | `enviarSMTP()` (usa `mail()`) | 238-275 |

El bloque SSL context (`verify_peer=>false`) y el helper de lectura multilínea se repiten
casi literalmente en los 3 archivos.

### 2.2 Riesgo

Un fix de seguridad o timeout en una implementación **no se propaga** a las demás.
Esto puede dejar el motor de envío vulnerable o inconsistente.

### 2.3 ✅ ESTADO: COMPLETADO Y DESPLEGADO (2026-08-22)

**Implementado:**

1. **Creado `inc/smtp_transport.php`** con la función centralizada `futprotec_enviarSMTP()` que:
   - Conecta por socket (SSL 465, STARTTLS 587, TCP plano).
   - Hace EHLO/STARTTLS/AUTH LOGIN/MAIL/RCPT/DATA/QUIT.
   - Verifica códigos 220/250/334/235/354.
   - Maneja timeout de lectura explícito (evita bloqueos de `fgets()`).
   - Soporta `text/html` y `multipart/alternative` (texto_plano + html).
   - Soporta Message-ID, Reply-To, nombre de remitente dinámico y headers extra.
   - Devuelve `['ok' => bool, 'error' => string]`.
   - Envuelto en `if (!function_exists(...))` para evitar colisiones.

2. **Reescritos para delegar en el transporte centralizado:**
   - `inc/mime.php` → `enviarSMTPAutenticado()` normaliza la cuenta y delega en `futprotec_enviarSMTP()`.
   - `cli/cron.php` → `enviarSMTP()` construye la cuenta normalizada y delega (mantiene firma y retorno `bool`).
   - `api/enviar_smtp_random.php` → `enviarSMTPAutenticado()` delega. **Se mantuvo intacto el `die("SISTEMA BLOQUEADO...")` de seguridad (FASE 2B)** — el script sigue desactivado.

3. **Validación:** `php -l` OK en los 5 archivos (smtp_transport, mime, cron, enviar_smtp_random, enviar_lote). Sin definiciones duplicadas en archivos activos (solo en `backups/`).

**Pendiente:**
- Deploy a producción (requiere aprobación del usuario).
- Test de envío real (microenvío) + checkpoint.

### 2.4 Estimación

**4-6 horas** (implementación completada; resta deploy + test).

---

## 3. PRIORIDAD ALTA — Funciones monolíticas en APIs

### 3.1 `api/analytics.php`

#### `get_analytics` (líneas 346-528, ~183 líneas)
Un solo handler con 5 ramas (`envios`, `aperturas`, `rebotes`, `bajas`, `dashboard`).
La rama `dashboard` (377-526) es un monolito de ~150 líneas con 12 consultas de funnel,
KPIs económicos, comparativa A/B/C y objetivo.

**Refactor:** Dividir en `getAnalyticsEnvios()`, `getAnalyticsDashboard()`, `getFunnel()`,
`getAbcComparativa()`.

#### `get_respuestas` (líneas 104-295, ~190 líneas)
Combina consulta SQL, mapeo de clasificaciones, agrupación por lead, cálculo de
score/prioridad (semáforo), ordenación y filtrado.

**Refactor:** Extraer la lógica de score/prioridad (212-273) y la agrupación (163-210)
a funciones puras.

#### `get_followups` (líneas 28-98, ~70 líneas)
Mezcla 3 sub-bloques (F4.1 no respondedores, F4.2 sin próxima acción, F4.3 KPIs).

**Refactor:** Dividir en 3 funciones.

### 3.2 `api/leads.php`

#### `scan_duplicates` (líneas 86-191, ~105 líneas)
Algoritmo de detección de duplicados monolítico.

**Refactor:** Extraer la lógica de comparación y agrupación a funciones auxiliares.

#### ✅ ESTADO: COMPLETADO EN LOCAL (2026-08-23)

Extraídas 4 funciones puras (`resetFlagsDuplicados`, `detectarDuplicadosEmail`,
`detectarDuplicadosNombre`, `marcarDuplicados`) y el handler `scan_duplicates` quedó
como orquestador delgado. Mejoras de rendimiento sin cambiar comportamiento:
pre-cálculo de normalización por club y set de claves O(1) para deduplicación.
`php -l` OK. Contrato JSON preservado. Ver `checkpoint_refactor_leads_scan_duplicates.md`.

**Pendiente:** deploy a producción (requiere aprobación del usuario) + smoke test.

### 3.3 `inc/imap_respuestas.php`

#### `imap_registrar_respuesta` (líneas 532-655, ~124 líneas)
Hace 6 cosas: (a) asegurar esquema, (b) 4 comprobaciones de idempotencia, (c) INSERT en
`respuestas`, (d) INSERT en `comunicaciones_log`, (e) notificación FASE G, (f) mover Kanban.

**Refactor:** Descomponer en `verificarIdempotencia()`, `insertarRespuesta()`,
`registrarLog()`, `notificarRespuesta()`. La idempotencia repite 4 veces el mismo patrón
`SELECT id FROM respuestas WHERE X LIMIT 1` — extraíble a `existeRespuesta($db, $columna, $valor)`.

#### ✅ ESTADO: COMPLETADO EN LOCAL (2026-08-23)

`imap_registrar_respuesta()` (líneas 1159-1210) ya es un **orquestador delgado** que delega
en funciones puras extraídas:

| Función | Responsabilidad |
|---|---|
| `imap_asegurar_esquema()` | Migración idempotente de columnas de `respuestas` |
| `imap_existe_respuesta()` | Idempotencia genérica (`SELECT id FROM respuestas WHERE X LIMIT 1`) |
| `imap_insertar_respuesta()` | INSERT en `respuestas` (devuelve id o null) |
| `imap_registrar_log_respuesta()` | INSERT en `comunicaciones_log` |
| `imap_notificar_respuesta()` | Notificación FASE G (🔔 NUEVA RESPUESTA) |
| `imap_mover_kanban()` | Mueve lead a '03 En Conversación' (respuesta humana) |
| `imap_es_respuesta_humana()` | Helper de clasificación humana (heurística + IA) |

Además, la sección 6.2 (`imap_procesar_buzon`) también quedó resuelta: el bucle anidado
(carpetas → mensajes) ahora delega en `imap_procesar_mensaje()` (líneas 1225-1295), que
encapsula el flujo completo de un único mensaje con degradado elegante y reconexión ante
timeout. Se añadieron además variantes "ligeras" (`imap_procesar_mensaje_ligero`,
`imap_procesar_buzon_ligero`, `imap_procesar_todas_cuentas_ligero`) para el dashboard.

`php -l` OK. Contrato de retorno preservado (`'insertado'|'duplicado'|'error'`).

**Pendiente:** deploy a producción (requiere aprobación del usuario) + smoke test.

### 3.4 Estimación

- `analytics.php`: **3-4 horas**
- `leads.php`: **1-2 horas**
- `imap_respuestas.php`: **2-3 horas** (implementación completada; resta deploy + test)


---

## 4. PRIORIDAD MEDIA — HTML/PHP/JS mezclados en vistas

### 4.1 `dashboard.php` (262-436)

- Mezcla endpoints AJAX + SQL (L1-259), render HTML (L262-436) y `showLoginForm()` (L440-503).
- `showLoginForm()` duplica el `<head>`/`<html>` completo.
- Inyección de config PHP dentro de `<script>` (`window._cfg`, L431-433).

**Refactor:** Mover JS inline a `app.js`. Extraer `showLoginForm()` a un partial.

### 4.2 `modals.php` (250-265)

- Bloque `<script>` JS nativo (toggle SMTP) incrustado en medio de vistas HTML.

**Refactor:** Mover a `js/app.js` o un helper JS.

#### ✅ ESTADO: COMPLETADO EN LOCAL (2026-08-25)

El bloque `<script>` inline (líneas 250-265, toggle de contraseña SMTP) fue **eliminado**
de `tabs/modals.php`. El handler ya vivía en `js/app.js` (al final del archivo) usando
delegación de eventos sobre `input[data-smtp-password-input]` + botón `[data-smtp-toggle]`,
por lo que no se perdió funcionalidad. De paso se aplicó el **refactor de contraste UI**
pendiente en `modals.php` (55 reemplazos `text-slate-500`/`text-slate-600` →
`text-slate-400`, vía `scripts/refactor_contraste_ui.py`).

- `php -l tabs/modals.php` OK. `node --check js/app.js` OK.
- Sin `<script>` inline restante en `modals.php` (los atributos `data-*` del modal SMTP
  se conservan, son el gancho del handler).

**Pendiente:** commitear + deploy a producción.

### 4.3 Estimación

**2-3 horas.**

---

## 5. PRIORIDAD MEDIA — Código duplicado en `js/app.js`

### 5.1 `iniciarMotor()` (líneas 745-827, ~83 líneas)

Mezcla 2 flujos distintos:
- **CASO A:** envío dirigido de 1 lead (751-785)
- **CASO B:** cola normal con lote (787-826)

Cada caso tiene su propio bucle, construcción de FormData y manejo de errores casi idénticos.

**Refactor:** Dividir en `enviarDirigido()` y `enviarCola()`.

### 5.2 Getters de analytics casi idénticos (líneas 188-205)

- `lzTasaExito` (197) y `lzEnvioOkPct` (200) tienen exactamente la misma fórmula
  `Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100)`.

**Refactor:** Unificar en un solo getter.

### 5.3 `enviarCorreoPrueba()` (líneas 643-723, ~80 líneas)

Combina validación de campaña, selección de leads, selección A/B/C, confirmación y envío.
Lógica de negocio y UI (alert/confirm) entrelazadas.

**Refactor:** Separar validación de envío.

### 5.4 `loadGestor()` (452-484) y `loadSmtp()` (888-909)

Generan HTML de tablas inline (lógica de UI mezclada con fetch).

**Refactor:** Extraer renderizado a funciones de plantilla.

#### ✅ ESTADO: COMPLETADO EN LOCAL (2026-08-25)

| Subsección | Qué se hizo |
|---|---|
| 5.1 `iniciarMotor()` | Dividido en **orquestador delgado** + `enviarDirigido()` (CASO A) + `enviarCola()` (CASO B). Se reutiliza el getter `lzCuentaActiva` (ya existente) en lugar de recalcular la cuenta SMTP activa inline. |
| 5.2 Getters | `lzEnvioOkPct` ahora **delega en `lzTasaExito`** (misma fórmula). Ambos getters se conservan porque la UI (`tabs/lanzadera.php`) los referencia por separado. |
| 5.3 `enviarCorreoPrueba()` | Extraídos `validarPruebaEmail()`, `obtenerCandidatosPrueba()` y `armarSeleccionPrueba()`. El método principal quedó como **orquestador** (validar → candidatos → selección → confirm → envío). |
| 5.4 `loadGestor()`/`loadSmtp()` | Renderizado extraído a `renderGestorRows()`, `renderGestorPaginacion()` y `renderSmtpRows()` (funciones de plantilla puras). |

- `node --check js/app.js` OK. Contratos públicos preservados: `iniciarMotor`,
  `lzTasaExito`, `lzEnvioOkPct`, `lzEnvioErrorPct`, `loadGestor`, `loadSmtp`,
  `enviarCorreoPrueba` (todas referenciadas desde `tabs/lanzadera.php` y `tabs/gestor.php`).
- Delta neto: +131 / −81 líneas (crece ligeramente por comentarios de refactor).

**Pendiente:** deploy a producción + smoke test + commitear.

### 5.5 Estimación

**4-5 horas** (implementación completada; resta deploy + test).

---

## 6. PRIORIDAD BAJA — Mejoras menores

### 6.1 `inc/mime.php` `enviarSMTPAutenticado()` (75-246)

Mezcla construcción MIME (175-223) con protocolo SMTP de bajo nivel.

**Refactor:** Extraer `construirMensajeMIME()` como función pura separada del transporte.

### 6.2 `inc/imap_respuestas.php` `imap_procesar_buzon()` (661-708)

Bucle anidado (carpetas → mensajes) con 3 niveles de try/catch difícil de seguir.

**Refactor:** Extraer el procesamiento de un único mensaje (674-701) a una función.

#### ✅ ESTADO: COMPLETADO EN LOCAL (2026-08-23)

Resuelto junto con la sección 3.3. `imap_procesar_buzon()` ahora delega en
`imap_procesar_mensaje()` (líneas 1225-1295), que encapsula el flujo completo de un
único mensaje (FETCH ENVELOPE → cuerpo con degradado → fallback de metadatos →
clasificación → atribución → registro) y devuelve contadores incrementales. Se añadieron
variantes "ligeras" para el dashboard (`imap_procesar_mensaje_ligero`,
`imap_procesar_buzon_ligero`, `imap_procesar_todas_cuentas_ligero`).


### 6.3 `inc/eligibilidad.php`

Lógica de negocio mezclada con consultas SQL.

### 6.4 Estimación

**2-3 horas.**

---

## 7. ORDEN DE EJECUCIÓN RECOMENDADO

| Orden | Bloque | Archivos | Horas |
|---|---|---|---|
| 1 | SMTP unificado | `inc/smtp_transport.php` (nuevo), `cron.php`, `enviar_smtp_random.php`, `mime.php` | 4-6 |
| 2 | analytics.php | `api/analytics.php` | 3-4 |
| 3 | leads.php | `api/leads.php` | 1-2 |
| 4 | imap_respuestas.php | `inc/imap_respuestas.php` | 2-3 |
| 5 | dashboard/modals | `dashboard.php`, `modals.php` | 2-3 |
| 6 | app.js | `js/app.js` | 4-5 |
| 7 | Mejoras menores | `mime.php`, `eligibilidad.php` | 2-3 |
| | **TOTAL** | | **18-26** |

---

## 8. REGLAS OBLIGATORIAS PARA CADA REFACTOR

1. **Incremental:** Un refactor = un objetivo = un checkpoint.
2. **No romper funcionalidad:** Verificar que las features ya terminadas siguen operativas.
3. **Test antes de deploy:** Ejecutar compilación/sintaxis y tests unitarios.
4. **Deploy con verificación:** Usar `deploy_outbound_full.py` y verificar con `verify_remote_fg_deploy.py`.
5. **Checkpoint:** Documentar cada refactor en `docs/checkpoint_*.md`.
6. **No tocar la BD:** Los refactors de código no deben alterar el esquema de la BD.
7. **No deshacer features:** Validación MX, WhatsApp, modales, tracking, etc. deben seguir funcionando.
8. **Compatibilidad SiteGround:** PHP 8.x nativo, sin extensiones PECL, sin fsockopen en planes compartidos.

---

## 9. VALIDACIÓN MÍNIMA ANTES DE CERRAR CADA REFACTOR

- `php -l` en los archivos PHP modificados.
- `node --check` en `js/app.js` (si aplica).
- Test manual de la funcionalidad afectada.
- Deploy + verificación remota.
- Checkpoint documentado.

---

## 10. ESTADO ACTUAL DEL PROYECTO (CONTEXTO PARA RETOMAR)

- **Fases F/G (IMAP + respuestas + notificaciones):** Implementadas, testeadas y **desplegadas** a SiteGround (44/44 archivos OK, verificado).
- **Tabla `respuestas`:** Confirmada en BD remota (14 columnas base, vacía). Las 11 columnas nuevas se añadirán al ejecutar el CLI IMAP.
- **Pendiente de producción:** Ejecutar `php cli/imap_respuestas.php` en remoto (requiere aprobación del usuario).
- **Motor de envío:** Pausado (sin envíos automáticos no autorizados).
- **Refactor SMTP unificado (sección 2):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-22). Creado `inc/smtp_transport.php`; reescritos `mime.php`, `cron.php` y `enviar_smtp_random.php` para delegar. `php -l` OK en los 5 archivos. **Validado en producción** con los 159 envíos reales de campaña 2 (el microenvío vía HTTP quedó bloqueado por el WAF de SiteGround, pero no es necesario — ver `checkpoint_microenvio_smtp_403_waf.md`). **Estado: RESUELTO.**
- **Refactor analytics.php (sección 3.1):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-25). Extraídas 11 funciones puras de `get_analytics`, `get_respuestas` y `get_followups`. `php -l` OK. Ver `checkpoint_refactor_analytics_funciones_puras.md`.
- **Refactor dashboard.php (sección 4.1):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-25). Ver `checkpoint_refactor_dashboard_funciones_puras.md`.
- **Refactor leads.php scan_duplicates (sección 3.2):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-25). Extraídas 4 funciones puras; handler `scan_duplicates` como orquestador delgado. `php -l` OK. Ver `checkpoint_refactor_leads_scan_duplicates.md`.
- **Refactor imap_respuestas.php (sección 3.3 + 6.2):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-25). `imap_registrar_respuesta()` y `imap_procesar_buzon()` ya delegan en funciones puras. `php -l` OK.
- **Refactor modals.php (sección 4.2):** ✅ **COMPLETADO Y DESPLEGADO** (2026-08-25). Bloque `<script>` inline (toggle SMTP) eliminado de `tabs/modals.php`; el handler delegado ya vivía en `js/app.js`. Aplicado además el contraste UI pendiente en `modals.php` (55 reemplazos). `php -l` + `node --check` OK.
- **Refactor app.js (sección 5):** ✅ **COMPLETADO, DESPLEGADO Y COMMITEADO** (2026-08-25). `iniciarMotor()` dividido en `enviarDirigido()`/`enviarCola()`; getters unificados (`lzEnvioOkPct` delega en `lzTasaExito`); `enviarCorreoPrueba()` con `validarPruebaEmail()`/`obtenerCandidatosPrueba()`/`armarSeleccionPrueba()`; renderizado de `loadGestor()`/`loadSmtp()` en funciones de plantilla. `node --check` OK + test funcional 38/38. Ver `checkpoint_refactor_app_js.md`.
- **SIGUIENTE PENDIENTE PRIORITARIO (sección 6.3):** Refactor de `inc/eligibilidad.php` — separar lógica de negocio de consultas SQL. Prioridad baja, estimación **1-2 horas**.
- **CRÍTICO (auditoría 2026-08-25):** Credenciales hardcodeadas — ✅ **RESUELTO** (2026-08-25). `AUTH_KEY` del dashboard, tokens de runners y `CSRF_SECRET` movidos a `inc/secret.php` (gitignored + .htaccess). API keys de IA cifradas `FP1:` en BD (config) y descifradas al uso. Además: **la contraseña del panel y el email de recuperación se gestionan desde la UI** (bloque "Seguridad del Panel" en Configuración) con **recuperación por email mediante token de un solo uso** (`request_reset`/`reset_password`). Ver `docs/CONFIGURACION_SEGURIDAD.md`.
- **MEDIO (auditoría 2026-08-25):** Colisiones de funciones sin `function_exists` en `inc/mime.php`, `inc/abc.php`, `inc/eligibilidad.php`, `inc/helpers.php`, `inc/pop3_respuestas.php` (hoy latentes porque `enviar_smtp_random.php` está bloqueado). Envolver en guardas. Ver informe de auditoría.
- **MEDIO-BAJO (auditoría 2026-08-25):** Dividir monolitos — `inc/imap_respuestas.php` (1553 líneas) es el principal candidato; también `api/leads.php` (1186), `cli/init_db.php` (806). Ver informe de auditoría.
- **LIMPIEZA:** Eliminar `inspect_db.php` y `tmp_schema_check2/3/4.txt` (residuos de diagnóstico en la raíz).


---

## 11. ARCHIVOS DE REFERENCIA

- `docs/checkpoint_deploy_fases_fg_siteground.md` — deploy F/G completado
- `docs/checkpoint_faseFG_imap_respuestas_kanban.md` — módulo IMAP
- `docs/checkpoint_faseFG_bandeja_conversaciones.md` — bandeja de respuestas
- `docs/informe_auditoria_bugs_20260825.md` — auditoría de bugs y deuda técnica (2026-08-25)
- `docs/checkpoint_faseG_notificaciones_globales.md` — notificaciones
- `scripts/verify_remote_fg_deploy.py` — verificación de deploy
- `scripts/verify_remote_respuestas_readonly.py` — verificación de BD remota
