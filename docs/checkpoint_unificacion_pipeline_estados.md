# Checkpoint — Unificación del Pipeline de Estados (Vocabulario A)

**Fecha:** 23/08/2026
**Objetivo:** Eliminar la coexistencia de dos vocabularios de estados (A y B) que provocaba
inconsistencias en el CRM Outbound. Unificar todo el sistema bajo el **Vocabulario A** (7 columnas).

---

## Contexto del problema

Se detectó que el sistema tenía **dos vocabularios de estados** en paralelo:

### Vocabulario A (canónico, 7 columnas)
| Código | Estado |
|--------|--------|
| 01 | Nuevo |
| 02 | Contactado |
| 03 | En Conversación |
| 04 | Propuesta |
| 05 | Ganado |
| 06 | Perdido |
| 07 | Baja |

### Vocabulario B (legado, disperso en varios archivos)
| Código | Estado |
|--------|--------|
| 03 | Respondió |
| 05 | Cualificado |
| 06 | Propuesta |
| 07 | Negociacion |
| 09 | Perdido |

Esta duplicidad provocaba que:
- Un lead marcado como `06 Propuesta` (B) no coincidiera con `04 Propuesta` (A).
- Los filtros de cualificación/mockup/presupuesto en la ficha del lead no se mostraran
  correctamente según el estado real.
- Las métricas de analytics y presupuestos usaran un `stage_order` distinto.

---

## Archivos unificados

| Archivo | Cambio aplicado |
|---------|-----------------|
| `inc/imap_respuestas.php` | `03 Respondió` → `03 En Conversación` |
| `api/analytics.php` | `stage_order` y estados → Vocabulario A |
| `api/presupuestos.php` | `stage_order` → Vocabulario A |
| `tabs/modals.php` | Filtros de cualificación/mockup/presupuesto → `04 Propuesta`, `05 Ganado`, `06 Perdido` |
| `api/mockups.php` | `mockup_solicitar` actualiza a `04 Propuesta` (antes `06 Propuesta`) |

---

## Detalle de cambios en `tabs/modals.php`

1. **Bloque Cualificación Comercial**: se muestra ahora para
   `['04 Propuesta','05 Ganado','06 Perdido']` (antes incluía `05 Cualificado`, `06 Propuesta`, `07 Negociacion`).
2. **Bloque Motivo de Pérdida**: se muestra para `06 Perdido` (antes `09 Perdido`).
3. **Bloque Mockup**: se muestra para `04 Propuesta` (antes `05 Cualificado`, `06 Propuesta`, `07 Negociacion`).
4. **Bloque Presupuesto**: se muestra para `04 Propuesta` (antes `06 Propuesta`, `07 Negociacion`).

## Detalle de cambios en `api/mockups.php`

- `mockup_solicitar`: al solicitar un mockup, el lead pasa a `04 Propuesta` (antes `06 Propuesta`).

---

## Validación

Todos los archivos modificados pasan `php -l` sin errores de sintaxis:

```
No syntax errors detected in public_html/outbound/inc/imap_respuestas.php
No syntax errors detected in public_html/outbound/api/analytics.php
No syntax errors detected in public_html/outbound/api/presupuestos.php
No syntax errors detected in public_html/outbound/tabs/modals.php
No syntax errors detected in public_html/outbound/api/mockups.php
```

---

## Estado final

El pipeline de estados queda **100% unificado bajo el Vocabulario A** en todos los sectores
del CRM: Kanban, ficha de lead, mockups, presupuestos, analytics, Unibox y lógica de envío.

**Pendiente de deploy a SiteGround** (requiere confirmación explícita del usuario).
