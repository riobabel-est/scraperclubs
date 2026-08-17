# Checkpoint — Abordaje: desconexión campaña ↔ plantilla en el motor de envío

**Tipo:** Informe accionable (hallazgo crítico detectado en auditoría previa)
**Referencia:** `docs/checkpoint_pendiente_gestor_recursos_campanas_plantillas.md`
**Fecha:** 2026-08-16
**Objetivo:** documentar en detalle el problema del motor automático y la selección de plantilla para abordarlo en breve.
**Estado:** NO implementado. Solo diagnóstico + plan.

---

## 1. Resumen ejecutivo

Existe una **desconexión real entre la campaña y la plantilla en el motor de envío**. El resultado es que:

- La campaña **no sabe** qué plantilla utiliza (no hay atributo ni tabla intermedia).
- El motor automático (`cron.php`) **ignora cualquier selección de plantilla asociada a la campaña** y usa siempre la **primera plantilla HTML activa** de la tabla.
- La lanzadera manual (`enviar_lote.php`) sí recibe `id_plantilla`, pero es un parámetro externo elegido a mano, sin relación declarativa con la campaña.
- La protección `plantillaEstaCongelada()` **infiere** la relación a partir del histórico de `envios`, lo que provoca:
  - **falso negativo**: una plantilla seleccionada para una campaña PILOT pero aún sin envíos **no** queda congelada.
  - **falso sentido de protección**: solo `envios` materializa la relación, no la propia campaña.
  - **no distingue entorno**: una campaña de `entorno=test` con `estado=PILOT` congela igualmente la plantilla.

Esta desconexión es la causa raíz de que el sistema muestre "Plantilla congelada" sin que exista una relación campaña→plantilla claramente gestionable.

---

## 2. Evidencia exacta (con código)

### 2.1 Esquema: la campaña NO guarda plantilla

Tabla `pipelines` (verificada con `PRAGMA table_info`):

```
id, nombre, descripcion, fecha_inicio, fecha_fin, variante_ganadora,
activo, created_at, identificador, estado, entorno, tipo, objetivo
```

**No existe** `plantilla_id`. Tampoco hay tabla intermedia campaña→plantilla.

### 2.2 `cron.php` ignora la plantilla de la campaña

`public_html/outbound/cli/cron.php` (aprox. líneas 154–159):

```php
// 5. Obtener plantilla activa
$plantilla = $db->querySingle(
    "SELECT * FROM plantillas WHERE activo = 1 AND tipo = 'html' ORDER BY id ASC LIMIT 1",
    true
);
```

Consecuencias:
- El cierre del ciclo de envío **no consulta** `campaign_id` para elegir plantilla.
- `tipo = 'html'` y `ORDER BY id ASC LIMIT 1` → siempre la primera plantilla HTML activa.
- Si la campaña necesita otra plantilla (seguimiento, objeción, variante), el cron la **ignora**.

### 2.3 Lanzadera recibe la plantilla por parámetro externo

`public_html/outbound/api/enviar_lote.php`:

```php
$idPlantilla = (int)($_POST['id_plantilla'] ?? 0);
...
$plantilla = $db->querySingle(
    "SELECT id, nombre, asunto, asunto_b, asunto_c, test_ab, cuerpo, cuerpo_b, cuerpo_c, tipo, categoria
     FROM plantillas WHERE id = {$idPlantilla} AND activo = 1",
    true
);
```

El origen de `id_plantilla` es la UI (`js/app.js`, select `lzIdPlantillaEmail`), **independiente** del select de campaña (`lzCampaignId`).

### 2.4 `plantillaEstaCongelada()` infiere la relación del histórico

`public_html/outbound/inc/eligibilidad.php`:

```php
function plantillaEstaCongelada(SQLite3 $db, int $plantillaId): bool
{
    if ($plantillaId <= 0) return false;
    $n = (int)$db->querySingle(
        "SELECT COUNT(*)
         FROM envios e
         JOIN pipelines p ON p.id = e.campaign_id
         WHERE e.plantilla_id = {$plantillaId}
           AND UPPER(p.estado) IN ('PILOT','ACTIVE')"
    );
    return $n > 0;
}
```

Observaciones:
- Usa `UPPER(p.estado) IN ('PILOT','ACTIVE')`, **sin** filtrar `p.entorno`.
- Solo cuenta `envios`; no existe consulta a una relación declarativa.

### 2.5 Guardado bloqueado solo en escritura

`public_html/outbound/dashboard.php` (`save_template`):

```php
if ($id > 0 && plantillaEstaCongelada($db, $id)) {
    echo json_encode(['ok' => false, 'error' => 'Plantilla congelada (usada por campaña PILOT/ACTIVE). Crea una nueva plantilla.']);
    exit;
}
```

- `get_templates` (lectura) **no** expone `congelada` ni las campañas vinculadas.

---

## 3. Impacto operativo

| Problema | Impacto |
|---|---|
| cron ignora plantilla de campaña | el envío automático envía contenido **incorrecto** (plantilla global, no la de la campaña) |
| sin `pipelines.plantilla_id` | el operador no sabe qué plantilla usa realmente una campaña PILOT/ACTIVE |
| congelación inferida | plantilla "seleccionada pero aún sin envío" no se congela → riesgo de edición indebida |
| congelación sin entorno | campañas TEST en estado PILOT congelan plantillas reales (ruido/bloqueo) |
| protección opaca | el editor no explica qué campaña bloquea la plantilla |

---

## 4. Causa raíz

1. El modelo de datos define `pipelines` como **trazabilidad** (estado/entorno/identificador), no como **recurso** (plantilla/producto/segmento).
2. El vínculo campaña→plantilla solo existe **implícitamente** en `envios`.
3. El motor automático no fue actualizado para respetar una plantilla por campaña, porque ese vínculo nunca se persistió.

---

## 5. Plan de abordaje propuesto (por prioridad)

### ABORDAJE INMEDIATO (sin tocar BD, bajo riesgo)

1. **Hacer visible la relación real** (solo lectura):
   - En `get_templates`, añadir por cada plantilla:
     - `congelada` = `plantillaEstaCongelada($db, $id)`.
     - `usada_en_campanas` = lista `(campaign_id, nombre, estado, entorno, n_envios)` derivada de `envios JOIN pipelines WHERE e.plantilla_id = id`.
   - Nueva acción `get_template_campanas?plantilla_id=` con la misma consulta.

2. **Corregir el criterio de congelación al alcance real** (opcional, requiere aprobación):
   - Decidir si una campaña de `entorno=test` en `estado=PILOT` debe congelar. Si no, ajustar `plantillaEstaCongelada()` para filtrar también `p.entorno <> 'test'`.
   - En esta fase conviene **no** alterar aún la función para no regresionar; documentar la decisión.

### ABORDAJE ESTRUCTURAL (siguiente fase, con aprobación explícita)

3. **Persistir la relación campaña→plantilla**:
   - Opción A: `ALTER TABLE pipelines ADD COLUMN plantilla_id INTEGER` (una columna).
   - Opción B: tabla ligera `campana_plantilla(campaign_id, plantilla_id, ...)`.
   - Justificación: hoy el vínculo es inferido y no gestionable; este cambio lo hace declarativo.

4. **Actualizar el motor (`cron.php`) para leer la plantilla desde la campaña**:
   - Reemplazar la selección global `ORDER BY id ASC LIMIT 1` por la plantilla asociada a `campaign_id`.
   - Mantener `envios.cuerpo_mensaje` como snapshot inmutable.

5. **Versionado formal** (solo si es necesario, a largo plazo):
   - `plantillas_versiones` + fijar versión por campaña, sin alterar `envios`.

---

## 6. Riesgos

| Acción | Riesgo | Mitigación |
|---|---|---|
| tocar `cron.php` sin plan | alterar motor de envío en producción | solo FASE estructural, con aprobación y test |
| quitar/alterar `plantillaEstaCongelada()` | edición de plantilla activa, histórico incoherente | NO alterar en la fase inmediata |
| vincular plantilla a campaña sin actualizar cron | doble fuente de verdad (campaña vs global) | actualizar cron junto con la columna |
| reutilizar `lead_pipelines` para segmentar | colisión con métricas | no reutilizar; definir segmento explícito |
| migrar histórico a `campana_plantilla` | pérdida de trazabilidad | `envios` intocable; relación nueva solo hacia adelante |

---

## 7. Qué NO tocar

- `reservarEnvioLogico()` y el índice único `(lead_id, campaign_id)`.
- `envios.cuerpo_mensaje`, `envios.variant`, `envios.plantilla_id`, `envios.message_id`.
- A/B/C (`inc/abc.php`: `asignarVariante`, `resolverContenidoVariante`).
- `enviar_lote.php` (a menos que se apruebe explícitamente la fase estructural).
- BD (sin columnas/tablas nuevas hasta aprobación).
- Credenciales SMTP.

---

## Conclusión

La desconexión campaña↔plantilla es el defecto estructural que explica la opacidad actual y el comportamiento del motor automático. El abordaje más seguro y de mayor retorno **a corto plazo** es hacer visible la relación real (lectura sobre `envios`), sin tocar BD ni motor; el cambio estructural (persistir `plantilla_id` en la campaña y actualizar `cron.php`) debe planificarse como fase separada con aprobación explícita.