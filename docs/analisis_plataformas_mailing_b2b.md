# ANÁLISIS DE PLATAFORMAS MODERNAS DE MAILING B2B
## Benchmark de características valoradas y su implementación en FutProtec CRM

**Fecha:** 19/08/2026
**Estado:** READ-ONLY / Análisis estratégico
**Objetivo:** Reconocer qué características de las plataformas B2B modernas son las más valoradas y cómo implementarlas en el CRM FutProtec sin rehacerlo.

---

# 1. PLATAFORMAS ANALIZADAS

| Plataforma | Enfoque | Fortaleza principal |
|---|---|---|
| **HubSpot** | CRM + Marketing + Sales | Timeline unificada, scoring, automatización, tracking web |
| **ActiveCampaign** | Email + Automatización + CRM | Automatización visual, scoring, site tracking, segmentación |
| **Brevo (Sendinblue)** | Email transaccional + Marketing | SMTP API, webhooks, entregabilidad, delivered/opened/clicked |
| **Mailchimp** | Email marketing | Segmentación, A/B testing, informes, sitios conectados |
| **Lemlist** | Cold email B2B | Personalización, secuencias multicanal, verificación de entregabilidad |
| **Instantly.ai** | Cold email B2B | Rotación de dominios, warmup, escalado masivo |
| **Smartlead** | Cold email B2B | Rotación de cuentas, warmup, inbox rotation, respuestas automáticas |
| **Woodpecker** | Cold email B2B | Secuencias, follow-ups, entregabilidad, respuestas |
| **Reply.io** | Cold email B2B | Secuencias multicanal, respuestas, automatización |
| **Salesloft / Outreach** | Sales engagement | Secuencias, cadencia, priorización de leads |

---

# 2. CARACTERÍSTICAS MÁS VALORADAS (CONSENSO B2B)

Tras analizar las plataformas, estas son las características que los usuarios de mailing B2B valoran más:

## 2.1 Entregabilidad (la #1 absoluta)
- **Warmup de dominios/cuentas** (Instantly, Smartlead, Lemlist)
- **Rotación de cuentas SMTP** para evitar bloqueo (Smartlead, Instantly)
- **Verificación de emails** antes de enviar (Lemlist, Instantly)
- **Límites diarios por cuenta** (todas)
- **Monitoreo de spam/rebotes** (todas)

## 2.2 Secuencias y follow-ups automáticos
- **Secuencias de emails** con pasos y delays (Lemlist, Woodpecker, Reply.io)
- **Follow-ups automáticos** si no hay respuesta (todas)
- **Detención de secuencia** cuando el lead responde (todas)
- **Multicanal** (email + LinkedIn + llamada) (Lemlist, Reply.io)

## 2.3 Respuestas y conversación
- **Detección automática de respuestas** (todas)
- **Clasificación de respuestas** (positiva/negativa/neutral) (todas)
- **Notificación inmediata** de respuesta (todas)
- **Inbox unificado** para responder (Smartlead, Lemlist)

## 2.4 Personalización y variantes
- **A/B testing** de asuntos y cuerpos (Mailchimp, ActiveCampaign)
- **Personalización por campos** (nombre, club, federación) (todas)
- **Variantes ganadoras** (Mailchimp, ActiveCampaign)

## 2.5 Tracking y analítica
- **Aperturas** (píxel) (todas)
- **Clicks** (link tracking) (todas)
- **Visitas web** (site tracking) (ActiveCampaign, HubSpot)
- **Timeline del lead** (HubSpot, ActiveCampaign)
- **Scoring** (HubSpot, ActiveCampaign)

## 2.6 Priorización y pipeline
- **Scoring de leads** (HubSpot, ActiveCampaign)
- **Kanban/pipeline** (HubSpot, ActiveCampaign)
- **Priorización de leads calientes** (Salesloft, Outreach)

## 2.7 Automatización
- **Automatización visual** (ActiveCampaign)
- **Triggers por evento** (todas)
- **Webhooks** (Brevo, ActiveCampaign)

## 2.8 Cumplimiento y seguridad
- **Unsubscribe** (todas, obligatorio)
- **Suppression list** (todas)
- **RGPD** (todas)
- **Aislamiento TEST/REAL** (crítico en FutProtec)

---

# 3. QUÉ YA TIENE FUTPROTEC CRM (ESTADO ACTUAL)

| Característica | Estado | Dónde |
|---|---|---|
| SMTP con rotación de cuentas | ✅ Implementado | `api/smtp.php`, `enviar_smtp_random.php` |
| Límites diarios por cuenta | ✅ Implementado | `enviados_hoy` |
| A/B/C testing determinista | ✅ Implementado | `asignarVariante()` backend |
| Aislamiento TEST/REAL | ✅ Implementado | `eligibilidad.php` |
| Suppression / Lista Negra | ✅ Implementado | `api/blacklist.php`, `tabs/lista_negra.php` |
| Unsubscribe | ✅ Implementado | `api/baja.php` |
| Aperturas (píxel) | ✅ Implementado | `mime.php`, tracking |
| Respuestas + clasificación | ✅ Implementado (manual) | `tabs/respuestas.php` |
| Kanban con drag&drop | ✅ Implementado | `tabs/kanban.php` |
| Analytics (PRR, Open Rate, Reply Rate) | ✅ Implementado | `tabs/analytics.php` |
| Message-ID | ✅ Implementado | `envios` |
| Backups y checkpoints | ✅ Implementado | `docs/`, scripts |
| **IMAP / detección automática de respuestas** | ❌ **GAP** | — |
| **Click tracking** | ❌ **GAP** | — |
| **Tracking web identificado** | ❌ **GAP** | — |
| **Timeline del lead** | ❌ **GAP** | — |
| **Scoring** | ❌ **GAP** | — |
| **Secuencias / follow-ups automáticos** | ❌ **GAP** | — |
| **Warmup de dominios** | ❌ **GAP** | — |
| **Verificación de emails** | ⚠️ Parcial (validación MX) | `scripts/validate_emails.py` |

---

# 4. CARACTERÍSTICAS MÁS VALORADAS → CÓMO IMPLEMENTARLAS

## 4.1 IMAP / Detección automática de respuestas (GAP crítico)
**Valoración:** La más valorada en cold email B2B. Sin esto, el comercial debe revisar el buzón manualmente.

**Implementación:**
- Script `cli/imap_sync.php` que conecta por IMAP a cada cuenta SMTP.
- Atribución por `Message-ID` / `In-Reply-To` / `References`.
- Idempotencia por `message_id_respuesta` + `UID IMAP`.
- Clasificación inicial sin IA (humana/rebote/baja/OOO/automática).
- Notificación inmediata.
- **UI:** evolucionar tab "Respuestas" (badge de nuevas, botón sincronizar, chips de filtro).

## 4.2 Click tracking (GAP — REAJUSTADO POR DIRECCIÓN COMERCIAL)
**Valoración:** Alta, PERO con restricción crítica de entregabilidad.

> **⚠️ REAJUSTE COMERCIAL:** NO implementar como reescritor general de URLs en campañas frías. Reescribir enlaces mediante un proxy del CRM destruye el *domain reputation* en correos fríos. El Email 1 (Primer Impacto) debe ser **Texto Plano Puro sin reescritura de enlaces**.

**Implementación (solo Fase 2 / tras respuesta):**
- El Click Tracking NO se aplica al Email 1 frío.
- Se aplica exclusivamente a los enlaces de la **Fase 2 (tras respuesta)** y al sistema de landing `/c/` (subdirectorio `/c/SCFC-4821`).
- Endpoint que registra click (lead, campaña, envío, url, timestamp) y redirige.
- **UI:** columna "Clicks" en analytics y en la ficha del lead (solo para enlaces de Fase 2).
## 4.3 Tracking web identificado (GAP)

**Valoración:** Media-alta. Permite saber qué leads visitan FutProtec tras el email.

**Implementación:**
- Token no predecible en enlaces → cookie de seguimiento.
- Niveles: anónimo → identificado → identificado+respuesta.
- **Privacidad:** revisar RGPD, consentimiento, minimización.

## 4.4 Timeline del lead (GAP)
**Valoración:** Alta. Es lo que diferencia un CRM de envío de un CRM de actividad.

**Implementación:**
- Tabla `lead_events` (event store).
- Eventos: email_sent, email_opened, email_clicked, web_visit, email_received, reply_classified, unsubscribe, bounce, kanban_changed, manual_note.
- **UI:** panel de timeline en la ficha del lead (modal).

## 4.5 Scoring determinista (GAP)
**Valoración:** Alta. Permite priorizar leads calientes.

**Implementación:**
- Tabla `lead_score` o columna calculada.
- Puntos por evento (apertura +2, click +5, visita +4, respuesta +15, presupuesto +25).
- **UI:** badge de score en kanban y en ficha del lead.

## 4.6 Secuencias / follow-ups automáticos (GAP — SUBE A P2)
**Valoración:** Muy alta en cold email B2B. Es la base de Lemlist/Woodpecker/Reply.io.

> **⚠️ REAJUSTE COMERCIAL:** En prospección B2B a clubes de fútbol base, **el 70% de las respuestas no llegan en el primer email, sino en el 1º o 2º follow-up**. Por eso esta característica sube a **Prioridad 2 (P2)**, justo por debajo de IMAP. El orden natural de crecimiento del CRM es: **Enviar → Escuchar respuesta (IMAP) → Re-impactar si no responde (Secuencia)**.

**Implementación:**
- Tabla `secuencias` (pasos, delays, condiciones).
- Tabla `secuencia_lead` (progreso por lead, Estado: En espera, Enviado, Detenido por respuesta).
- Cron que envía el siguiente paso si no hay respuesta (cadencia a 3 y 7 días).
- **Detención automática** cuando IMAP detecta correo entrante del lead.
- **UI:** tab "Follow-ups" (ya existe `followups.php` como placeholder) → evolucionarla.


## 4.7 Warmup de dominios (GAP)
**Valoración:** Alta para entregabilidad, pero compleja y de riesgo.

**Implementación:**
- Requiere infraestructura externa (Instantly/Smartlead) o script de warmup.
- **Recomendación:** NO implementar internamente al inicio. Evaluar capa externa (Brevo/Instantly) en FASE N.

## 4.8 Verificación de emails (parcial)
**Valoración:** Alta para entregabilidad.

**Implementación:**
- Ya existe validación MX (`checkdnsrr`).
- Ampliar con verificación de sintaxis + dominio + MX + (opcional) SMTP handshake.
- **UI:** badge de "email verificado" en la ficha del lead.

---

# 5. MATRIZ DE PRIORIDADES (ESTÁNDAR COMERCIAL FUTPROTEC — REVISADA)

> **Nota de reajuste ejecutivo:** Esta matriz ha sido revisada por la Dirección Comercial de FutProtec. Los tres reajustes críticos de negocio son:
> 1. **IMAP = GAP P0 inmediato** (retraso >2h en responder reduce conversión un 60%).
> 2. **Click tracking NO debe reescribir URLs en campañas frías** (contamina entregabilidad). Solo aplica en Fase 2 (tras respuesta) y landing `/c/`.
> 3. **Secuencias/follow-ups suben a P2** (el 70% de respuestas llegan en el 1º o 2º follow-up, no en el primer email).

| Prioridad | Característica / Módulo | Esfuerzo | Impacto Ventas | Regla Operativa de Negocio |
|---|---|---|---|---|
| **P1** | IMAP / Sync Automático Respuestas | Medio | **CRÍTICO** | Cero pérdida de leads. Respuestas procesadas en < 2 horas. |
| **P2** | Secuencias / Follow-ups Auto (con stop automático al responder) | Alto | **MUY ALTO** | Cadencia a 3 y 7 días si no hay respuesta al Email 1. |
| **P3** | Tracking Web / Enlaces `/c/` | Bajo | **ALTO** | Trazabilidad exclusiva en Fase 2 (después de respuesta o follow-up). |
| **P4** | Timeline del Lead (Event Store) | Medio | **ALTO** | Historial unificado en la ficha del club en el Kanban. |
| **P5** | Scoring Determinista | Bajo | **MEDIO** | Priorización en Kanban por puntos (Respondió = +25, Visitó Web = +10). |
| **P6** | Verificación / Higiene de Emails | Bajo | **MEDIO** | Check MX / Syntax previa al envío. |
| **EXTERNAL** | Warmup de Dominios / Reputación | — | — | Externalizar a herramientas dedicadas (Instantly/Brevo) si escala >1k/día. |


---

# 6. QUÉ EXTERNALIZAR vs QUÉ IMPLEMENTAR INTERNAMENTE

## Implementar internamente (núcleo comercial)
- IMAP / respuestas
- Click tracking
- Timeline
- Scoring
- Secuencias / follow-ups
- Verificación de emails
- Tracking web identificado

## Externalizar (capa de entregabilidad)
- **Warmup de dominios** → Instantly / Smartlead / Brevo
- **SMTP transaccional de alto volumen** → Brevo (API/webhooks)
- **Verificación masiva de emails** → NeverBounce / ZeroBounce (opcional)

**Principio:** el CRM FutProtec sigue siendo el núcleo comercial. La capa externa solo aporta entregabilidad.

---

# 7. ARQUITECTURA FUTURA RECOMENDADA

```text
                    FUTPROTEC CRM (núcleo)
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
   CAMPAÑAS            LEADS             KANBAN
        │                 │                 │
        └─────────────────┼─────────────────┘
                          │
                   MOTOR DE EVENTOS
                          │
   ┌──────────┬───────────┼───────────┬──────────┐
   │          │           │           │          │
  SMTP      IMAP        WEB       TRACKING    SECUENCIAS
   │          │           │           │          │
   └──────────┴───────────┼───────────┴──────────┘
                          │
                   TIMELINE DEL LEAD
                          │
                        SCORING
                          │
                   NOTIFICACIONES
                          │
              ┌───────────┴───────────┐
              │                       │
        CAPA EXTERNA (opcional)   ACCIÓN COMERCIAL
        Brevo / Instantly
```

---

# 8. CONCLUSIÓN

Las plataformas B2B modernas (Lemlist, Instantly, Smartlead, Woodpecker, Reply.io) valoran por encima de todo:

1. **Entregabilidad** (warmup, rotación, verificación)
2. **Secuencias y follow-ups automáticos**
3. **Detección y clasificación de respuestas**
4. **Personalización y A/B testing**
5. **Tracking (aperturas, clicks, web)**
6. **Priorización (scoring, pipeline)**

FutProtec CRM ya cubre parcialmente: SMTP con rotación, A/B/C, aperturas, respuestas manuales, kanban, analytics, suppression, aislamiento TEST/REAL.

**Los gaps más valiosos a cubrir (alineados con la Dirección Comercial):**
- **IMAP/respuestas automáticas** (P1) — GAP P0 inmediato. Cero pérdida de leads; respuestas procesadas en < 2 horas.
- **Secuencias/follow-ups automáticos** (P2) — el 70% de respuestas llegan en el 1º o 2º follow-up. Cadencia a 3 y 7 días con stop automático al responder.
- **Tracking web / enlaces `/c/`** (P3) — trazabilidad exclusiva en Fase 2 (tras respuesta o follow-up), NO en campañas frías.
- **Timeline del lead** (P4) — historial unificado en la ficha del club en el Kanban.
- **Scoring determinista** (P5) — priorización en Kanban por puntos (Respondió = +25, Visitó Web = +10).
- **Verificación/higiene de emails** (P6) — check MX/syntax previa al envío.

**La estrategia correcta:** implementar internamente el núcleo comercial (IMAP, secuencias, timeline, scoring, tracking de Fase 2) y externalizar solo la entregabilidad (warmup de dominios, SMTP de alto volumen) cuando escale >1k/día.


---

# 9. PRÓXIMO PASO RECOMENDADO

Ejecutar **FASE E — Auditoría IMAP READ-ONLY** para confirmar la viabilidad técnica real de la prioridad #1 (IMAP/respuestas) antes de implementar nada.

Esto incluye:
- Conectar a una cuenta SMTP por IMAP.
- Estudiar carpetas, UID, Message-ID, In-Reply-To, References.
- Confirmar que SiteGround permite IMAP saliente.
- Verificar que los `message_id` de los envíos permiten el match.
