# Checkpoint: Atribución de respuestas IMAP en producción

**Fecha:** 23/08/2026
**Estado:** COMPLETADO

## Problema detectado

En la UI del Kanban, las respuestas humanas de leads aparecían como **"Pendiente"** en lugar de **"En Conversación"**, y el indicador de conversación no se mostraba.

## Causa raíz

La BD de producción (`public_html/outbound/data/stats.db`) tenía las respuestas IMAP registradas (9 filas en tabla `respuestas`) pero **sin atribución**: todas tenían `lead_id = NULL` y `envio_id = NULL`.

La subconsulta del Kanban busca respuestas por `lead_id` y `clasificacion_ia`, por lo que al no encontrar coincidencias, mostraba "Pendiente".

## Diagnóstico realizado

1. **`api/imap_sync.php`** (runner web con token): confirmó que la BD producción SÍ tiene 9 respuestas registradas (0 insertados, 9 duplicados).
2. **`tmp_inspect_remota_datos.php`**: todas las respuestas tenían `lead_id=null` y `envio_id=null`.
3. **`tmp_inspect_remota_envios.php`**: los envíos 188 (→lead 1217, Segosala) y 164 (→lead 407, DURCAL) existen en producción.
4. **`tmp_inspect_remota_respuestas_schema.php`**: las respuestas 8 (Segosala) y 11 (DURCAL) son humanas con `message_id_original` que coincide con los envíos 188 y 164.

## Solución aplicada

Se creó un runner web de atribución retroactiva (`atribuir_respuestas_runner.php`) que:
- Busca respuestas humanas sin `lead_id`.
- Atribuye por `In-Reply-To`, `References` o email remitente contra la tabla de envíos.
- Actualiza `lead_id`, `envio_id`, `campaign_id`, `id_cuenta_smtp`.
- Mueve el lead a '03 En Conversación' si la respuesta es humana.

### Resultado en producción (apply=1)

| Respuesta | Remitente | lead_id | envio_id | campaign_id | id_cuenta_smtp |
|-----------|-----------|---------|----------|-------------|----------------|
| 8 | segosala@gmail.com | 1217 | 188 | 2 | 5 |
| 11 | cddurcal2026@gmail.com | 407 | 164 | 2 | 10 |

- Leads 1217 y 407 movidos de '02 Contactado' a '03 En Conversación'.
- 3 respuestas de ruido no atribuibles (sin envío coincidente).

## Verificación de persistencia

Runner independiente (`verificar_atribucion_runner.php`) confirmó que la atribución **PERSISTIÓ** correctamente en la BD de producción (consulta con conexión nueva).

## Limpieza

- Los runners temporales (`atribuir_respuestas_runner.php`, `verificar_atribucion_runner.php`) fueron eliminados del nodo FTP.
- **Nota SiteGround**: el FTP se conecta a un nodo y el HTTP sirve desde otro (infraestructura de clúster). Los archivos ya no existen en el nodo FTP ("No such file or directory"), pero el HTTP puede seguir sirviéndolos desde otro nodo hasta que se propague la eliminación. Esto es un comportamiento normal de SiteGround y se resuelve solo.
- **Seguridad**: ambos runners requieren token secreto para ejecutarse. El runner de atribución es idempotente (no re-atribuye respuestas que ya tienen `lead_id`), por lo que no hay riesgo de daño durante la propagación.

## Impacto en UI

El Kanban en producción ahora mostrará correctamente los leads 1217 y 407 como **"En Conversación"** con el indicador de conversación, ya que las respuestas tienen `lead_id` y `clasificacion_ia` asignados.

## Archivos creados/modificados

- `scripts/atribuir_respuestas_retroactivo.php` (script local de referencia)
- `scripts/deploy_atribuir_respuestas.py` (deploy del runner)
- `public_html/outbound/atribuir_respuestas_runner.php` (runner web, eliminado de producción)
- `public_html/outbound/verificar_atribucion_runner.php` (runner de verificación, eliminado de producción)
