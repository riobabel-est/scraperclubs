# Checkpoint — Lanzadera: Envío Dirigido (1 lead) + Tamaño de Lote

**Fecha:** 17/08/2026
**Estado:** Implementado y validado (sintaxis OK). NO desplegado aún.
**Regla:** NO se ha enviado ningún correo. NO se ha iniciado el motor. NO se ha modificado la BD.

---

## 1. Objetivo

Permitir ejecutar **exactamente un único lead real** desde la Lanzadera, sin pulsar
"Iniciar Lanzadera" sobre una cola completa, y sin crear un segundo motor.

Se añaden **dos mecanismos complementarios**:

1. **Envío Dirigido (1 lead):** buscar y seleccionar un lead concreto → el motor
   envía SOLO a ese lead (CASO A), ignorando la cola.
2. **Tamaño de Lote (máx. envíos):** límite de envíos por ejecución en la cola
   normal (CASO B), con doble salvaguarda.

---

## 2. Archivos modificados/creados

| Archivo | Tipo | Descripción |
|---------|------|-------------|
| `api/lead_search.php` | **NUEVO** | Busca leads por nombre/email/ID (máx. 20), con aislamiento TEST/REAL por campaña. NO envía. |
| `api/lead_validate.php` | **NUEVO** | Valida (SIN ENVIAR) que un lead es elegible en la campaña. Replica la cadena de `enviar_lote.php`. NO envía. |
| `tabs/lanzadera.php` | Modificado | Bloque "Envío Dirigido (1 lead)" + input "Tamaño de Lote". |
| `js/app.js` | Modificado | Estado nuevo + funciones de búsqueda/selección/validación + lógica CASO A/B en `iniciarMotor()`. |

---

## 3. Mecanismo de ejecución

### CASO A — Envío Dirigido (lead seleccionado)

```
UI "Envío Dirigido"
→ lzSearchLeads()  →  api/lead_search.php?q=...&campaign_id=...
→ lzSelectLead(lead)  →  lzSelectedLeadId = lead.id; lzBatchSize = 1
→ lzValidateLead()  →  api/lead_validate.php?lead_id=...&campaign_id=...
→ INICIAR LANZADERA
→ iniciarMotor() detecta lzSelectedLeadId > 0
→ envía SOLO a ese lead (1 llamada a api/enviar_lote.php)
→ lzMotorEstado = 'PAUSADO'
```

- **Número de leads procesados:** exactamente 1.
- **Criterio de parada:** tras el único envío, el motor se pausa.
- **Salvaguarda:** `lzSendCalls` se fuerza a 1 (no hay bucle).

### CASO B — Cola normal con límite de lote

```
INICIAR LANZADERA
→ iniciarMotor() (sin lead seleccionado)
→ batchSize = max(1, lzBatchSize)
→ bucle sobre lzCola
→ DOBLE SALVAGUARDA:
    1. antes de cada envío: if (lzSendCalls >= batchSize) → PAUSADO + break
    2. tras cada envío:     if (lzSendCalls >= batchSize) → PAUSADO + break
→ lzMotorEstado = 'PAUSADO'
```

- **Número de leads procesados:** como máximo `lzBatchSize`.
- **Criterio de parada:** al alcanzar `lzBatchSize` o al agotar la cola.
- **Con `lzBatchSize = 1`:** exactamente 1 envío por ejecución.

---

## 4. Doble salvaguarda (anti-envío masivo)

En CASO B, `lzSendCalls` se incrementa tras cada llamada a `api/enviar_lote.php`.
Se comprueba **antes** y **después** de cada envío que `lzSendCalls < batchSize`.
Si se supera, el motor se pausa inmediatamente. Esto garantiza que **nunca** se
supere el tamaño de lote configurado, incluso ante errores de red o reintentos.

En CASO A, no hay bucle: se hace una única llamada y se pausa.

---

## 5. Aislamiento TEST/REAL (FASE 6F.6)

- `lead_search.php` aplica `sqlFiltroCompatibilidadLeadCampana()` cuando hay campaña,
  de modo que solo se muestran leads compatibles con la campaña seleccionada.
- `lead_validate.php` aplica `validarCampanaActiva()` + `esElegibleParaEnvio()`,
  la misma política única que `enviar_lote.php`.
- La variante A/B/C se calcula con `asignarVariante()` (determinística), igual que el motor.

---

## 6. Validación técnica

```
php -l api/lead_search.php        → No syntax errors
php -l api/lead_validate.php      → No syntax errors
php -l tabs/lanzadera.php         → No syntax errors
node --check js/app.js            → OK
```

Funciones backend verificadas (existen con las firmas usadas):
- `esLeadTest(array $lead): bool` ✓
- `sqlFiltroCompatibilidadLeadCampana(SQLite3 $db, int $idCampana): string` ✓
- `esElegibleParaEnvio(SQLite3 $db, int $leadId, int $campaignId = 0): array` ✓
- `asignarVariante(int $leadId, int $campaignId): string` ✓
- `validarCampanaActiva(SQLite3 $db, int $campaignId, string $modoEntorno): array` ✓

---

## 7. Veredicto

```
MECANISMO_EXISTENTE = Envío Dirigido (CASO A) + Tamaño de Lote (CASO B)
SE_PUEDE_LIMITAR_A_1 = YES
COMO = Seleccionar un lead en "Envío Dirigido" (fuerza lote=1) O poner "Tamaño de Lote" = 1
RIESGO_DE_ENVIAR_MAS_DE_1 = Muy bajo (doble salvaguarda lzSendCalls; CASO A sin bucle)
```

---

## 8. Pasos operativos para enviar 1 único lead real

```
PASO 1: En la Lanzadera, selecciona CAMPAÑA 2 (Piloto Comercial), federación y estado.
PASO 2: En "Envío Dirigido (1 lead)", busca el club por nombre/email/ID y selecciónalo.
        (Opcional) pulsa "Validar elegibilidad" para confirmar que pasará el backend.
PASO 3: Pulsa "INICIAR LANZADERA". El motor enviará SOLO a ese lead y se pausará.
```

Alternativa (cola normal):
```
PASO 1: Configura el lote (campaña, federación, estado, plantilla).
PASO 2: Pon "Tamaño de Lote" = 1.
PASO 3: Carga la cola y pulsa "INICIAR LANZADERA". Enviará 1 y se pausará.
```

---

## 9. Pendiente

- Desplegar los 4 archivos a producción (FTP) cuando el usuario lo autorice.
- Prueba funcional en entorno real (sin SMTP) del flujo de búsqueda/validación.
