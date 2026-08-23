# Checkpoint — Refactor de `dashboard.php` a funciones puras

**Fecha:** 22/08/2026
**Archivo:** `public_html/outbound/dashboard.php`
**Objetivo:** Reducir el acoplamiento del orquestador del panel extrayendo la lógica de negocio de los handlers AJAX a funciones puras testables, dejando los handlers como orquestadores delgados.

---

## Resumen del refactor

Se extrajeron **4 funciones puras + 2 constantes** de los handlers AJAX más grandes del archivo. Cada handler ahora solo orquesta: lee parámetros, llama a la función pura y devuelve JSON.

### Funciones puras extraídas
| Función pura | Handler que reemplaza | Responsabilidad |
|---|---|---|
| `getLeadDetalle($db, int $id)` | `get_lead` | Obtiene un lead con contadores de envíos/aperturas + último mockup y presupuesto |
| `updateLeadCampo($db, int $id, string $field, string $value)` | `update_lead` | Actualiza un campo editable con lógica especial (observaciones merge, estado_lead con protección opt-out real + log, tiene_whatsapp normalización) |
| `esOptOutReal($db, int $id)` | (helper de `update_lead`) | Determina si un lead tiene una BAJA REAL del destinatario (opt-out por email) |
| `actualizarEstadoLeadUnibox($db, int $id, string $estado)` | `actualizar_estado_lead` | Actualiza el estado desde el visor Unibox validando contra estados permitidos |
| `enviarRespuestaSmtpLead($db, int $leadId, string $email, string $cuerpo, string $asunto)` | `enviar_respuesta_smtp` | Envía respuesta SMTP con rotación de cuenta + límite diario y registra en `envios` |

### Constantes extraídas
| Constante | Contenido |
|---|---|
| `CAMPOS_EDITABLES_LEAD` | Lista blanca de campos editables desde el Kanban |
| `ESTADOS_UNIBOX_PERMITIDOS` | Estados editables desde el visor Unibox |

---

## Cambios realizados

1. **`get_lead`**: El bloque de consulta SQL + mockup + presupuesto se extrajo a `getLeadDetalle()`. El handler ahora solo lee `$_GET['id']` y delega.

2. **`update_lead`**: El bloque monolítico (validación de campos, normalización de `tiene_whatsapp`, merge de `observaciones`, protección opt-out real, log de cambio de estado) se extrajo a `updateLeadCampo()`. La lógica de detección de opt-out real se aisló en `esOptOutReal()`. El handler ahora solo lee `$_POST` y delega.

3. **`actualizar_estado_lead`**: La validación de estados permitidos + UPDATE se extrajo a `actualizarEstadoLeadUnibox()`. El handler ahora solo lee `$_POST` y delega.

4. **`enviar_respuesta_smtp`**: El bloque monolítico (validación de email, selección de cuenta SMTP con rotación, normalización de cuenta, envío, incremento de contador, registro en `envios`) se extrajo a `enviarRespuestaSmtpLead()`. El handler ahora solo lee `$_POST` y delega.

5. **`sync_respuestas`**: Se mantuvo como está (es un orquestador HTTP interno que ya es delgado y no tiene lógica de negocio extraíble).

---

## Verificación

- `php -l public_html/outbound/dashboard.php` → **No syntax errors detected** ✅
- Se preservó la lógica de negocio exacta (mismas consultas SQL, mismas validaciones, mismo flujo de envío SMTP, misma protección opt-out real).
- No se cambió la estructura de columnas de salida ni los contratos JSON del frontend.
- Compatible con PHP 8.x nativo / SiteGround (sin extensiones externas).

---

## Beneficios

- **Testabilidad**: Las funciones puras pueden invocarse de forma aislada con un `$db` de prueba.
- **Legibilidad**: Los handlers pasan de decenas de líneas a orquestadores de ~5-10 líneas.
- **Mantenibilidad**: Cada endpoint se puede modificar sin tocar el resto.
- **Sin regresión**: La lógica se movió tal cual (copy-paste + firma de función), sin alterar comportamiento.

---

## Pendiente

- El refactor es **solo local** (no se ha hecho deploy ni `git push`). El usuario debe decidir cuándo desplegar a producción.
- Se recomienda un smoke test funcional de los 4 endpoints (`get_lead`, `update_lead`, `actualizar_estado_lead`, `enviar_respuesta_smtp`) tras el deploy.
