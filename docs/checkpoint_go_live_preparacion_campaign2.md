# FASE GO-LIVE — PREPARACIÓN CAMPAÑA 2 (PILOTO COMERCIAL)

## 1. AUDITORÍA DE LA CAMPAÑA 2
La campaña 2 en la base de datos `stats.db` (tabla `pipelines`) tiene actualmente estos valores:
- **ID:** 2
- **Nombre:** Piloto Comercial FutProtec 2026-08
- **Identificador:** PILOTO_FUTPROTEC_2026_08
- **Estado:** DRAFT
- **Entorno:** pilot
- **Activo:** 1
- **Descripción:** (vacía)
- **Objetivo:** (vacío)
- **Fechas:** `fecha_inicio` y `fecha_fin` están vacías.
- **Plantilla pretendida:** Históricamente, la plataforma no asocia la campaña a una plantilla mediante una clave foránea en la base de datos. La selección de la plantilla ocurre en tiempo real desde la Interfaz de Usuario (Lanzadera). Las plantillas comerciales aptas son:
  - ID 1: "Prospeccion (abc - texto plano)" (Categoría: `01 Sin Contactar`, con Test A/B)
  - ID 2: "Primer Contacto (ABC - Texto Plano)" (Categoría: `01 Sin Contactar`, con Test A/B)
  - ID 7: "Primera plantilla" (Categoría: `01 Sin Contactar`, con Test A/B)
- **Segmentos/Filtros de la Lanzadera:** Determinados en el momento de envío (Estado del Lead = '01 Sin Contactar', Federación = 'Todas' u opcional).

## 2. REQUISITOS PARA QUE LA CAMPAÑA 2 SEA ENVIABLE
Para poder utilizar esta campaña en el entorno real de forma segura y sin bloqueos de UI ni software, se necesita lo siguiente:

### Requisitos de Configuración (Global)
1. **`config.modo_entorno`**: Actualmente está en `test`. Debe pasarse a `produccion`. De lo contrario, `enviar_lote.php` no permitirá enviar leads reales (saltará el filtro anti-bypass de entorno).
2. **`config.motor_estado`**: Actualmente está en `pausado`. Al estar el motor manual por lotes, esto puede seguir `pausado` si se va a usar la Lanzadera con el botón `▶ Iniciar`, o en caso de querer usar `cron.php`, se debe pasar a `activo`.

### Requisitos de Campaña
1. **`pipelines.entorno` de Campaña 2**: Actualmente es `pilot`. Esto es válido para operar, pero debe coincidir semánticamente con las validaciones de `enviar_lote.php`. Al pasarlo a `production` o dejarlo en `pilot`, y `modo_entorno` a `produccion`, la campaña es elegible.
2. **`pipelines.estado` de Campaña 2**: Actualmente es `DRAFT`. Debe cambiarse obligatoriamente a `ACTIVE` (o `PILOT`) para que el backend (`inc/eligibilidad.php` y validaciones) autorice la generación del envío y aplique la inmutabilidad de A/B (asignación hash-basada).

### Requisitos de UI (Lanzadera)
1. El toggle de "Modo" debe cambiarse a **✅ MODO PRODUCCIÓN** en la Lanzadera (`modeTest = false`).
2. Debe seleccionarse la Campaña 2 ("Piloto Comercial").
3. Debe seleccionarse el Estado del Lead ("01 Sin Contactar").
4. Debe seleccionarse la Plantilla comercial correspondiente (por ejemplo, "Primer Contacto (ABC - Texto Plano)").
5. Cargar la cola.

## 3. FLUJO EXACTO DE LA LANZADERA (UI)
Pasos que debe hacer el operador para enviar un primer lead REAL:
1. Navegar a **Lanzadera** (`tab='lanzadera'`).
2. Desactivar el **Modo Pruebas** haciendo clic en el botón `🧪 MODO PRUEBAS` para que cambie a `✅ MODO PRODUCCIÓN`.
3. Seleccionar **Campaña:** `Piloto Comercial FutProtec 2026-08 · DRAFT` (o `ACTIVE` una vez actualizado) en el control `0. Seleccionar Campaña *`.
4. Seleccionar **Federación:** (Opcional, e.g., 'Real Federación de Fútbol de Madrid').
5. Seleccionar **Estado del Lead:** `01 Sin Contactar`.
6. Seleccionar **Plantilla:** La deseada (ID 1 o 2).
7. En la Columna Derecha, pulsar **Cargar Cola de Envíos**.
8. Localizar el *primer lead REAL* de la cola generada.
9. El operador pulsar el botón Play azul individual (`<i data-lucide="play"></i>`) **en la fila específica de ese lead** para enviarlo de forma aislada (o bien aislando la cola a un solo lead manipulando el tamaño de lote).

## 4. VALORES EXACTOS MODO PRODUCCIÓN ANTES DEL ENVÍO REAL
- `config.modo_entorno` = `'produccion'`
- `pipelines.estado` (ID 2) = `'ACTIVE'`
- `pipelines.entorno` (ID 2) = `'production'` (opcional: `pilot` también permite envíos reales según el script).
- Modo Pruebas UI = `false` (`modeTest` en `app.js` desactivado).
- `config.motor_estado` = `'pausado'` (para envío manual controlado, o `'activo'` si se automatiza).

## 5. DISEÑO DE PRUEBA OPERATIVA (1 SOLO LEAD REAL)
**Variables:**
- **Lead ID:** Uno válido de producción, en estado '01 Sin Contactar' (por ej. `id = X`).
- **Campaña:** ID 2 (`Piloto Comercial`).
- **Plantilla:** ID 2 (`Primer Contacto (ABC - Texto Plano)`).
- **Variante:** Será asignada determinísticamente por `asignarVariante($leadId, 2)` (A, B o C).
- **SMTP:** ID de cuenta SMTP de rotación asignada automáticamente por `get_cola.php`.
- **Destinatario:** El email del Lead `X`.

**Pre-checks (Qué comprobar antes):**
- Confirmar que la BD `stats.db` y `pipelines` (id=2) tienen el estado `ACTIVE`.
- Confirmar que `config.modo_entorno` es `produccion`.
- Confirmar en el Dashboard que la Lanzadera está en `MODO PRODUCCIÓN`.

**Post-checks (Qué comprobar después):**
- `envios`: Hay un nuevo registro para el Lead `X`, Campaign 2, estado `enviado`, variante `[A/B/C]`.
- `comunicaciones_log`: Registro de tipo `envio_email` con resultado `exito`.
- `clubes_crm`: El estado de `X` cambió de `01 Sin Contactar` a `02 Contactado`.

## 6. CRITERIO DE SALIDA: INSTRUCCIÓN OPERACIONAL
```text
PASO 1: Actualizar la base de datos para poner la campaña 2 en activo.
> php -r "$db = new SQLite3('public_html/outbound/data/stats.db'); $db->exec(\"UPDATE pipelines SET estado='ACTIVE', entorno='production' WHERE id=2\");"

PASO 2: Actualizar el entorno global de la plataforma a producción.
> php -r "$db = new SQLite3('public_html/outbound/data/stats.db'); $db->exec(\"UPDATE config SET valor='produccion' WHERE clave='modo_entorno'\");"

PASO 3: Acceder a la Lanzadera en la Interfaz Web.
- Hacer clic en "🧪 MODO PRUEBAS" para pasarlo a "✅ MODO PRODUCCIÓN".
- Seleccionar Campaña: "Piloto Comercial FutProtec 2026-08 · ACTIVE".
- Seleccionar Estado: "01 Sin Contactar".
- Seleccionar Plantilla: "Primer Contacto (ABC - Texto Plano)".
- Pulsar "Cargar Cola de Envíos".

PASO 4: Enviar a 1 lead real.
- En la tabla de la cola cargada, localizar el primer lead.
- Hacer clic en el botón azul de "Play" de ESA ÚNICA fila para enviarlo manualmente de forma individual.
```

**VEREDICTO:**
```text
READY_FOR_REAL_FIRST_SEND
```
Todo el código y el esquema soportan perfectamente el flujo sin necesidad de modificaciones estructurales, solo hace falta el flip de base de datos a los estados correctos de producción.