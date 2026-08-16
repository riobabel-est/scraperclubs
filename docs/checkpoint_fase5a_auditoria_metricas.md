# CHECKPOINT — FASE 5A: AUDITORÍA Y DISEÑO DE MÉTRICAS DEL PILOTO A/B/C

**FECHA:** 2026-08-14
**ALCANCE:** Solo auditoría y diseño. NO se modifica código/BD/tracking/A/B/C/respuestas. Sin envíos.

---

## 1. OBJETIVO
Definir cómo se calcularán las métricas del piloto A/B/C usando las fuentes reales (`envios`, `respuestas`, `aperturas`) y detectar bloqueantes antes de implementar.

## 2. FUENTES DE DATOS (estado real verificado)
| Tabla | Columnas relevantes | Estado actual |
|---|---|---|
| `envios` | id, club, email, fecha_envio, **estado**, tracking_id, asunto, cuerpo_mensaje, **lead_id**, **campaign_id**, **variant**, **plantilla_id**, **smtp_id**, **message_id** | 2 filas legacy (`estado='enviado'`, `campaign_id/variant/plantilla/smtp/message_id = NULL`) |
| `respuestas` | id, **envio_id**, fecha_respuesta, remitente, subject, cuerpo, **message_id** (UNIQUE parcial), in_reply_to, references, **clasificacion**, fecha_clasificacion, estado_procesamiento | 0 filas |
| `aperturas` | id, **tracking_id** (FK→envios.tracking_id), fecha_apertura, ip, user_agent | 0 filas |
| `pipelines` | id, nombre, **estado**, **entorno**, activo, identificador | 1 fila TEST (`DRAFT`/`test`) |
| `clubes_crm` | id, email, estado_lead, es_duplicado, ... | 1813 filas |
| `cuentas_smtp` | id, email, ... | 10 cuentas |
| `plantillas` | id, asunto, asunto_b/c, cuerpo_b/c, test_ab | 7 plantillas |
| `config` | clave/valor | `modo_entorno=test`, `motor_estado=pausado` |

## 3. RELACIONES
- `envios` → `lead_id` (identidad de lead), `campaign_id` (pipelines), `variant` (A/B/C), `plantilla_id`, `smtp_id`, `message_id`.
- `respuestas.envio_id` → `envios.id` (relación principal e inequívoca).
- `aperturas.tracking_id` → `envios.tracking_id` (FK real).
- **Relaciones que siguen dependiendo de email:** NINGUNA para las métricas nuevas si se usa `envio_id`/`tracking_id`. El email queda solo como dato de destinatario. (El dashboard legacy sí usa email.)

## 4. MÉTRICAS DISPONIBLES HOY
| Métrica | Fuente | Estado |
|---|---|---|
| Aceptados SMTP | `envios.estado` (ver §9) | PARTIAL (estado mutable) |
| Aperturas (totales y únicas) | `aperturas` join `envios` por tracking_id | PARTIAL |
| Respuestas | `respuestas` (envio_id) | PASS (estructura lista, aún sin datos) |
| POSITIVE / NEGATIVE / NEUTRAL / UNSUBSCRIBE / OOO / PENDING | `respuestas.clasificacion` | PASS (estructura) |
| Segmentación A/B/C | `envios.variant` | PASS (estructura) |
| Filtrado por campaña | `envios.campaign_id` + `pipelines` | PASS (estructura) |

## 5. MÉTRICAS NO DISPONIBLES (NOT AVAILABLE)
- Delivery rate real (solo aceptación SMTP; no hay confirmación de bandeja).
- Bounce rate real (no hay procesamiento de rebotes SMTP/IMAP).
- Click rate (no implementado).
- Sentimiento automático de respuesta (no hay IA; clasificación es humana).
- Revenue atribuido fiable (presupuestos existen pero no se ligan a campaign_id/variant de forma fiable).
- Conversiones finales por variante (no garantizadas por `envios.variant` en la capa de negocio actual).

## 6. DEFINICIONES CANÓNICAS (propuestas)
- **Aceptado SMTP** = envío cuyo servidor SMTP respondió aceptación (250). NO es entregado/leído/abierto/respondido.
- **Open** = fila en `aperturas`. Aperturas totales = COUNT; envíos abiertos únicos = DISTINCT tracking_id; Open Rate = únicos / aceptados.
- **Reply** = fila en `respuestas` (una vez por message_id único; la idempotencia ya impide duplicados).
- **POSITIVE / NEGATIVE / NEUTRAL / UNSUBSCRIBE / OOO** = `respuestas.clasificacion` exacta. NO usar estado_lead/Kanban/mockup/presupuesto/WhatsApp/apertura como sustituto.
- **PENDING** = no POSITIVE, no NEGATIVE, no NEUTRAL (queda sin clasificar).

## 7. KPI PRINCIPAL
**Positive Reply Rate = POSITIVE / ACEPTADOS SMTP**
Mostrar siempre numerador, denominador y porcentaje (ej. `B: 2 POSITIVE / 8 ACEPTADOS = 25 %`).

## 8. MÉTRICAS A/B/C
Todas segmentadas por `envios.variant` (NO `lead_pipelines`, NO estado, NO random):
- Asignados (envios con variant A/B/C), Enviados, Aceptados SMTP, Aperturas únicas, Respuestas, Positivas, Negativas, Neutrales, UNSUBSCRIBE, OOO.

## 9. PROBLEMA CRÍTICO DETECTADO — ACEPTACIÓN SMTP (estado mutable)
- P1 y P3 escriben `envios.estado = 'enviado'` (aceptado) o `'error'` (fallo). No usan `deferred`/`unknown`.
- **`api/track.php` sobrescribe `estado='enviado'` → `'abierto'` cuando el píxel registra apertura.**
  → Consecuencia: si "Aceptados SMTP" se calcula como `estado='enviado'`, los emails abiertos **dejan de contarse** como aceptados, subestimando el denominador y distorsionando la tasa.
- **Recomendación (diseño, no implementar):** la métrica "Aceptados SMTP" debe usar `estado IN ('enviado','abierto')`, o mejor, introducir un campo inmutable de resultado de envío (`resultado_envio`) separado del estado de ciclo de vida. Documentar como decisión de FASE 5B.

## 10. APERTURAS — análisis
- FK `tracking_id` → `envios.tracking_id` (inequívoca, no email).
- Sin índice unique: un mismo tracking_id **puede producir varias filas** (cada petición del píxel inserta). Open único = DISTINCT tracking_id.
- IP/UA son solo información técnica (no métrica comercial).
- `aperturas` no tiene `lead_id`/`campaign_id` directos; se obtienen por JOIN a `envios`. OK.

## 11. ANÁLISIS DEL DASHBOARD LEGACY
| Métrica actual | Fuente actual | ¿Correcta? | Fuente propuesta |
|---|---|---|---|
| Funnel "Respondieron" | `estado_lead >= 4` (stageOrder) | NO | `respuestas` |
| Funnel "Resp. Positivas" | `estado_lead >= 5` | NO | `respuestas.clasificacion='POSITIVE'` |
| A/B/C comparativa | `lead_pipelines` (leads/entregados/...) | NO | `envios` (variant, campaign_id) + `respuestas` + `aperturas` |
| Ganadora | `leads >= 5` (umbral simplista) | NO | Mostrar n/den/num/pct, sin "ganadora" automática |
| Envíos Totales | `envios WHERE estado='enviado'` | PARCIAL | `estado IN ('enviado','abierto')` (por problema §9) |
| Tasa Apertura | DISTINCT tracking_id / enviados | PARCIAL | misma, con denominador corregido |

## 12. PROBLEMAS ENCONTRADOS
1. `estado` mutable (`enviado`→`abierto`) afecta al denominador. CRÍTICO.
2. Dashboard legacy lee `lead_pipelines` y `estado_lead` (prohibido por requisito).
3. Criterio de ganadora `>=5` simplista.
4. Envíos legacy tienen `variant/campaign_id/plantilla/smtp/message_id = NULL`; deben excluirse de la segmentación A/B/C.
5. `respuestas` puede tener `envio_id` apuntando a envíos sin campaña (test) → filtro por campaña obligatorio para el piloto.

## 13. PROPUESTA DE DASHBOARD (mínima)
```
CAMPAÑA: identificador — estado — entorno
-------------------------------------------------------
ACEPTADOS SMTP: N
OPEN RATE: aperturas únicas / aceptados
REPLY RATE: replies / aceptados
POSITIVE REPLY RATE: POSITIVE / aceptados
-------------------------------------------------------
VARIANTE A | Aceptados | Respuestas | Positivas | PRR
VARIANTE B | ...
VARIANTE C | ...
-------------------------------------------------------
CLASIFICACIÓN: POSITIVE | NEGATIVE | NEUTRAL | UNSUBSCRIBE | OOO | PENDING
-------------------------------------------------------
OBSERVACIÓN A/B/C: sin declarar ganadora; mostrar n y contexto.
```

## 14. CRITERIOS DE MUESTRA
- No declarar ganadora automática por porcentaje superior.
- Mostrar n_A/n_B/n_C + numerador/denominador/porcentaje + diferencia observada.
- Si la muestra es insuficiente → etiqueta `Observación insuficiente`.
- No definir umbral estadístico arbitrario en esta fase; si se necesita, decisión separada (post-piloto).

## 15. BLOQUEANTES PARA PILOTO
1. Corregir/definir el denominador "Aceptados SMTP" (evitar que `abierto` lo reduzca). **BLOQUEANTE.**
2. Implementar consultas de métricas sobre `envios`+`respuestas`+`aperturas` (no sobre lead_pipelines/estado_lead). **BLOQUEANTE.**
3. Segmentar por `envios.variant` y filtrar por `campaign_id`. **BLOQUEANTE.**
4. Excluir envíos legacy sin `variant`/`campaign_id` de la comparativa A/B/C. **BLOQUEANTE.**

## 16. NO BLOQUEANTES / POST-PILOTO
- Click tracking.
- IMAP/polling.
- IA de clasificación.
- Análisis estadístico formal (test de significancia).
- Auditoría histórica de reclasificación.
- UI de creación manual de respuesta.

## 17. RECOMENDACIONES
- Introducir `resultado_envio` inmutable (accepted/failed) separado de `estado` (o, mínimamente, calcular aceptados con `estado IN ('enviado','abierto')`).
- Crear una única fuente de consulta de métricas (función PHP) reutilizable, no duplicar SQL en dashboard.

## 18. ESTADOS
- Fuentes de datos: PASS
- Métricas disponibles: PASS (estructura) con PARTIAL en aceptación/aperturas
- Métricas no disponibles: NOT AVAILABLE (documentadas)
- Definiciones canónicas: PASS
- KPI principal: PASS (definición)
- Dashboard legacy: FAIL (sustituir)
- Muestra: NOT VERIFIED (0 datos reales aún)

---

> Solo auditoría. No modifiqué archivos del sistema. No ejecuto FASE 5B. Espero aprobación explícita.