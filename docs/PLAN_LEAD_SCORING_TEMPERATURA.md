# PLAN — Lead Scoring B2B con Temperatura (Frío / Tibio / Caliente / Muy Caliente)

**Fecha:** 2026-08-26
**Estado:** PROPUESTA (pendiente de OK para implementar)
**Ámbito:** `public_html/outbound/` — Seguimiento, funciones puras, UI.
**Basado en:** modelo B2B del asesor, **adaptado a los datos reales del negocio** (clubes de fútbol, outbound email).

---

## 1. VERIFICACIÓN DE DATOS REALES (lo que condiciona el diseño)

| Dato | Realidad en BD | Decisión de diseño |
|---|---|---|
| `cargo_contacto` | 1818 vacíos (0%) | Decisor +30 **opcional**: solo aplica si el dato existe (se deja preparado) |
| Email genérico (gmail…) | 73% | **NO penalizar** (norma en fútbol base) |
| `volumen_estimado` | 0 con ≥20 | Usar `num_jugadores` como fallback; el fit por tamaño queda a medias hasta enriquecer |
| Clics / visitas web | No existen tablas | **El modelo no puede usar señales web**; se usan señales de email reales |

**Señales reales disponibles (el fuerte):** aperturas con recurrencia, respuestas clasificadas por IA (POSITIVE/NEGATIVE/NEUTRAL/OOO), nº de envíos, estado del pipeline (01→07), presupuestos, mockups, días de inactividad.

---

## 2. MATRIZ DE PUNTOS ADAPTADA (B2B clubes / email)

### 2.1 FIT (perfil — qué encaja con el negocio)
| Señal | Pts | Fuente |
|---|---|---|
| Federación objetivo | +15 | `clubes_crm.federacion` |
| `num_jugadores`/`volumen_estimado` ≥ 50 | +20 | `COALESCE(volumen_estimado, num_jugadores, 0)` |
| 20-49 | +10 | ídem |
| Cargo de decisión (presidente/director/gerente/secretario técnico/delegado) | +30 | `cargo_contacto` (solo si está relleno) |
| Email genérico | 0 | Sin penalización (norma del sector) |

### 2.2 BEHAVIOR (comportamiento — qué demuestra interés)
| Señal | Pts | Temperatura | Fuente |
|---|---|---|---|
| Respuesta **POSITIVE** (IA) | +50 | 🌋 Muy Caliente | `respuestas.clasificacion` |
| Presupuesto o Mockup creado | +40 | 🌋 Muy Caliente | `presupuestos` / `mockups` |
| Estado `04 Propuesta` | +35 | 🔥 Caliente | `estado_lead` |
| **≥ 4 aperturas** | +30 | 🔥 Caliente | `num_aperturas` |
| Estado `03 En Conversación` | +20 | 🔥 Caliente | `estado_lead` |
| **2-3 aperturas** | +20 | ⏳ Tibio | `num_aperturas` |
| Respuesta humana (NEUTRAL/otra) | +10 | ⏳ Tibio | clasificación |
| ≥ 2 envíos recibidos | +10 | ⏳ Tibio | `num_envios` |
| 1 apertura | +5 | ⏳ Tibio | `num_aperturas` |
| Sin actividad 30+ días | **−25** | 🥶 Enfría | `ultimo_contacto` |
| Sin aperturas 15+ días | −10 | 🥶 Enfría | `num_aperturas` + fecha |

### 2.3 Umbrales de temperatura (B2B)
`0-25 Frío 🥶 · 26-60 Tibio ⏳ · 61-85 Caliente 🔥 · >85 Muy Caliente 🌋`

**Prioridad derivada:** Muy Caliente/Caliente → 🔴 Alta · Tibio → 🟠 Media · Frío → ⚪ Baja.
**Matriz fit×behavior:** se expone `fit` y `behavior` por separado para ver el cuadrante (fit alto + behavior alto = venta; fit bajo + behavior alto = ruido).

---

## 3. OPCIONES TÉCNICAS

| Opción | Descripción | Cuándo |
|---|---|---|
| **A (recomendada)** | Función pura `calcularTemperaturaLead()` en `inc/lead_scoring.php`, calculada en tiempo real en las colas | Ahora — volumen bajo, testeable, sin BD nueva |
| **B** | Tabla `lead_score` persistida + runner/cron | Cuando el score se consulte masivamente o se quiera histórico |
| **C** | Columna `clubes_crm.temperatura` (migración) + recalculo al cambiar estado/envío | Fase 2: mostrar en Kanban/ficha sin recalcular |

---

## 4. PLAN DE EJECUCIÓN (Opción A)

1. `inc/lead_scoring.php`:
   - `calcularFitLead(array $lead): int`
   - `calcularBehaviorLead(array $lead): int`
   - `calcularTemperaturaLead(array $lead): array` → `{score, fit, behavior, temperatura, prioridad}`
2. `api/analytics.php` (`get_seguimiento`): añadir score/temperatura/fit/behavior a cada lead de las 3 colas.
3. `tabs/seguimiento.php`: badge de temperatura (🥶/⏳/🔥/🌋) + score numérico en la tabla; mantener el semáforo Alta/Media/Baja como derivado.
4. `scripts/test_lead_scoring.php`: tests de las funciones puras (fit, behavior, umbrales, enfriamiento).
5. Fase 2 (opcional): temperatura en `get_lead` (ficha) y en el Kanban (opción C).

## 5. VALIDACIÓN MÍNIMA
- `php -l` de archivos tocados · tests de scoring
- Smoke real: la cola Perseguir muestra temperatura/score coherentes (ej. 5 aperturas → Caliente/Muy Caliente)
- Render autenticado OK · sin romper ordenación/paginación/filtros

## 6. NOTA DE ENFOQUE
- No se castiga el email genérico (norma del sector).
- El modelo usa **señales de email reales**, no web analytics inexistentes.
- El fit por cargo/tamaño queda "preparado" y activo cuando se enriquezcan datos.
