# CHECKPOINT — FASE F/G: Registro de respuestas IMAP + Notificación + Kanban

**Fecha:** 2026-08-19
**Modo:** Desarrollo local (sin deploy a producción)
**Estado:** COMPLETADA — Test unitario 43/43 OK

---

## 1. OBJETIVO

Implementar el módulo de registro de respuestas por IMAP (FASE F) y las notificaciones (FASE G), sobre la base confirmada en FASE E (IMAP viable en SiteGround, 10 cuentas conectables).

---

## 2. ARCHIVOS

| Archivo | Rol |
|---|---|
| `public_html/outbound/inc/imap_respuestas.php` | Núcleo del módulo (parsing, clasificación, atribución, registro, idempotencia, notificación, Kanban) |
| `public_html/outbound/cli/imap_respuestas.php` | CLI de procesamiento (conexión IMAP + registro) |
| `scripts/test_imap_respuestas.php` | Test unitario (43 pasos) |

---

## 3. FUNCIONALIDAD IMPLEMENTADA

### 3.1 Parsing de mensaje (`imap_parsear_mensaje`)
Extrae de un raw MIME:
- `message_id`
- `in_reply_to`
- `references`
- `from_email`
- `to_email`
- `subject`
- `cuerpo` (texto plano)

### 3.2 Clasificación (`imap_clasificar`)
Sin IA, determinista:
- `humana` (con In-Reply-To)
- `rebote`
- `baja`
- `fuera_de_oficina`
- `automatica`
- `desconocida` (sin In-Reply-To)

### 3.3 Atribución (`imap_atribuir`)
Prioridad de identificación:
1. `In-Reply-To` → match con `envios.message_id`
2. `References` → match con `envios.message_id`
3. email remitente → match con `envios.email`
4. asunto como apoyo (nunca única prueba)

Devuelve el envío con `lead_id`, `campaign_id`, `variant`, `smtp_id`.

### 3.4 Registro (`imap_registrar_respuesta`)
Inserta en `respuestas` con **idempotencia completa**:
- por `message_id`
- por `uid_imap` (UID IMAP)
- por `cuenta_uid` (cuenta + UID)
- por `hash_auxiliar` (message_id + from + subject, para mensajes sin UID)

Campos nuevos guardados:
- `lead_id`
- `campaign_id`
- `id_cuenta_smtp`
- `message_id_original`
- `contenido_html`
- `uid_imap`
- `cuenta_uid`
- `hash_auxiliar`
- `carpeta` (INBOX / Junk / spam)
- `notificado` (0/1)
- `kanban_movido` (0/1)

### 3.5 Notificación (FASE G)
- Registra evento `notificacion_respuesta` en `comunicaciones_log` cuando la respuesta es humana.
- Marca `notificado = 1` en la fila de respuesta.
- (El envío real de la notificación por email/telegram queda para una fase posterior; aquí se registra el evento trazable.)

### 3.6 Kanban (respuesta humana → 03 Respondió)
- Respuesta **humana** → mueve lead a `03 Respondió` (si está en `02 Contactado` o anterior).
- Respuesta **automática** → NO mueve Kanban.
- **Protección opt-out real**: si el lead está en estado de baja real (`Opt-Out` con `[BAJA]` en observaciones), NO se reactiva.
- **No retrocede**: si el lead ya está en etapa posterior (`04 Interesado` o más), NO retrocede.

---

## 4. TEST UNITARIO — 43/43 OK

```
=== TEST 1: Parsing de mensaje ===        (6/6)
=== TEST 2: Clasificación ===             (6/6)
=== TEST 3: Atribución ===                (3/3)
=== TEST 4: Idempotencia y campos ===     (21/21)
=== TEST 5: Kanban automática NO mueve === (3/3)
=== TEST 6: Kanban opt-out real ===       (2/2)
=== TEST 7: Kanban no retrocede ===       (2/2)
```

Ejecución:
```bash
php scripts/test_imap_respuestas.php
```

---

## 5. VALIDACIÓN DE SINTAXIS

```bash
php -l public_html/outbound/inc/imap_respuestas.php   # OK
php -l public_html/outbound/cli/imap_respuestas.php   # OK
php -l scripts/test_imap_respuestas.php               # OK
```

---

## 6. GARANTÍA DE SEGURIDAD

- **No se modificó producción.**
- **No se enviaron emails.**
- **No se lanzaron campañas.**
- **No se hizo UPDATE/INSERT/DELETE/ALTER/DROP/CREATE/VACUUM/REINDEX sobre la BD real.**
- El test usa una BD temporal en memoria (`SQLite3(':memory:')`).
- El módulo respeta el aislamiento TEST/REAL: la atribución se basa en `envios` reales.
- La protección opt-out real impide reactivar leads dados de baja.

---

## 7. PENDIENTE PARA FASE SIGUIENTE

1. **Deploy a producción** (requiere petición explícita del usuario).
2. **Conexión IMAP real** en el CLI (procesar buzones INBOX + Junk + spam de las 10 cuentas).
3. **Envío real de notificaciones** (email/telegram) — aquí solo se registra el evento.
4. **UI de respuestas** (tab `respuestas.php`) para visualizar las respuestas registradas.
5. **Timeline del lead** (FASE I) — unificar actividad.
6. **Click tracking** (FASE J) — hueco funcional detectado.
7. **Scoring determinista** (FASE K).

---

## 8. VISIBILIDAD DE TODOS LOS EMAILS EN EL TAB DE RESPUESTAS

**Objetivo:** que el tab `Respuestas` del dashboard muestre **todos** los emails entrantes registrados, incluidos los que no tienen envío asociado (mensajes directos o de remitentes no identificados).

### Cambios en `public_html/outbound/api/analytics.php` (endpoint `get_respuestas`)

1. **`JOIN envios` → `LEFT JOIN envios`**: ahora se muestran TODAS las respuestas, incluidas las sin envío asociado.
2. **Filtro comercial ajustado**: `AND (r.envio_id IS NULL OR COALESCE(e.es_test, 0) = 0)` — las respuestas sin envío siempre se muestran (actividad entrante legítima); las que tienen envío solo si es REAL (no TEST).
3. **Mapeo de clasificaciones IMAP → UI**: las clasificaciones del módulo IMAP (`humana`, `rebote`, `baja`, `fuera_de_oficina`, `automatica`, `desconocida`) se convierten a las de la UI (`POSITIVE`, `NEGATIVE`, `UNSUBSCRIBE`, `OOO`, `NEUTRAL`, `PENDING`) para que se muestren con el badge de color correcto.
4. **Filtro por clasificación ampliado**: cuando el usuario filtra por una clasificación de la UI, el WHERE busca tanto el valor de la UI como el equivalente IMAP (ej. filtrar por `POSITIVE` también encuentra `humana`).

### Cambios en `public_html/outbound/tabs/respuestas.php`

1. **Tabla**: `r.club` y `r.email` muestran el remitente como fallback cuando no hay envío asociado (`r.club || r.remitente || '—'`).
2. **Modal**: el "Contexto del envío original" muestra el remitente como fallback y añade un aviso cuando la respuesta no tiene envío asociado (`!rsEnvio.id`).

### Validación

```bash
php -l public_html/outbound/api/analytics.php   # OK
php -l public_html/outbound/tabs/respuestas.php # OK
```

---

## 9. ARCHIVOS RELACIONADOS

- `docs/checkpoint_faseD_diseno_actividad.md` — auditoría arquitectónica (FASE D)
- `docs/checkpoint_faseE_auditoria_imap.md` — auditoría IMAP (FASE E)
- Este checkpoint — FASE F/G


