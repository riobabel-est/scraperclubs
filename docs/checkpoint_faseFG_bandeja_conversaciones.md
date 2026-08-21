# CHECKPOINT — FASE FG: Bandeja de Conversaciones con Score y Semáforo

**Fecha:** 19/08/2026
**Estado:** Implementado y validado (sintaxis OK)
**Alcance:** UI del tab Respuestas + API `get_respuestas` + JS `app.js`

---

## 1. Objetivo

Evolucionar el tab de Respuestas de una **lista plana de respuestas** a una
**bandeja de conversaciones comerciales** agrupadas por lead, con:
- hilo de mensajes (envío original + respuestas) en orden cronológico;
- **score** determinista por conversación;
- **semáforo de prioridad** (alta / media / baja);
- filtros por clasificación y por prioridad;
- badge de respuestas nuevas sin notificar.

Esto implementa parcialmente las prioridades 3 (timeline), 5 (scoring) y 6
(notificaciones) del Plan Maestro de Evolución Post-Core, sin rehacer el CRM.

---

## 2. Cambios realizados

### 2.1 API — `public_html/outbound/api/analytics.php`

El endpoint `get_respuestas` ahora devuelve `conversaciones` (agrupadas por
lead) en lugar de `respuestas` planas.

**Agrupación:**
- Clave de agrupación: `lead_id` si existe, si no por `email` normalizado.
- Cada conversación incluye: datos del lead (`clubes_crm`), campaña, variante,
  y el array `mensajes` (envío original + respuestas) en orden cronológico.

**Score determinista (sin IA):**
- `POSITIVE` → +15
- `NEUTRAL` → +2
- `OOO` → +1
- `NEGATIVE` → −5
- Aperturas del lead (envíos REALES) → +2 por apertura (máx 5)
- Score mínimo 0.

**Semáforo de prioridad:**
- `POSITIVE` presente → alta.
- `UNSUBSCRIBE` o `NEGATIVE` → baja.
- Respuesta humana sin gestionar > 48h → alta; > 24h → sube un nivel.
- Score ≥ 20 refuerza a alta (si no es baja).
- Orden: alta > media > baja, y dentro de la misma prioridad la más reciente primero.

**Filtros:**
- `clasificacion` (mapea clasificaciones IMAP en minúscula a las de la UI).
- `prioridad` (alta/media/baja).

**Compatibilidad:** se mantiene el mapeo de clasificaciones IMAP
(`humana`→`POSITIVE`, `rebote`→`NEGATIVE`, `baja`→`UNSUBSCRIBE`,
`fuera_de_oficina`→`OOO`, `automatica`→`NEUTRAL`, `desconocida`→`PENDING`).

### 2.2 JS — `public_html/outbound/js/app.js`

- `loadRespuestas()` ahora lee `j.conversaciones` y añade el filtro de prioridad.
- Nuevo estado: `respuestasPrioridad`, `rsConversacion`, `rsConversacionModal`.
- Nuevas funciones:
  - `abrirConversacion(conv)` / `cerrarConversacion()` — modal de hilo completo.
  - Helpers de presentación: `rsClasLabel`, `rsClasColor`, `rsPrioLabel`,
    `rsPrioColor`, `rsPrioDot`, `rsFmtFecha`, `rsUltimoMensaje`, `rsEsEntrante`.
- `clasificarRespuesta()` actualiza también el mensaje dentro de la conversación
  abierta y recarga la bandeja.

### 2.3 UI — `public_html/outbound/tabs/respuestas.php`

Reescrito como **bandeja de conversaciones**:
- Cabecera con contador de conversaciones y filtros (clasificación + prioridad).
- Lista de conversaciones con: semáforo de prioridad, club, email, badge de
  nuevas, score, prioridad, estado del lead, campaña/variante y último mensaje.
- **Modal de conversación** (hilo completo): cabecera con prioridad/score,
  datos del lead, y mensajes en burbujas (entrante a la izquierda, enviado a la
  derecha) con clasificación editable inline.
- Se mantiene el **modal de ficha de respuesta individual** (`rsModal`) con
  contexto del envío original y clasificación.

---

## 3. Validación

- `php -l public_html/outbound/api/analytics.php` → **No syntax errors**.
- `php -l public_html/outbound/tabs/respuestas.php` → **No syntax errors**.
- `node --check public_html/outbound/js/app.js` → sin errores.

---

## 4. Reglas respetadas

- **No se modifica producción** (cambios locales, pendientes de deploy explícito).
- **No se borran/sobrescriben datos** de `output/` ni `checkpoints/`.
- **No se tocan otros archivos** del módulo outbound salvo los necesarios
  (`analytics.php`, `app.js`, `tabs/respuestas.php`).
- **No se deshacen features previas**: se conserva el modal de ficha individual,
  la clasificación manual y el mapeo de clasificaciones IMAP.
- **Backend como autoridad**: el score y la prioridad se calculan en el API
  (PHP), no en el cliente. El cliente solo presenta.
- **Separación EVENTO vs ESTADO**: el score/prioridad NO mueven el Kanban
  automáticamente. La transición a `03 Respondió` sigue siendo responsabilidad
  del módulo IMAP (respuesta humana) y del componente humano.

---

## 5. Pendiente / siguiente paso

- **Deploy** a SiteGround cuando el usuario lo solicite explícitamente
  (regla de oro: no `git push` ni deploy sin petición).
- **Notificaciones push/visuales** de nuevas respuestas (FASE G completa):
  el badge "X nuevas" ya está implementado; se puede añadir un aviso global
  (toast) en el dashboard si se desea.
- **Timeline unificada** (FASE I) y **click tracking** (FASE J) como siguientes
  prioridades del plan maestro.
