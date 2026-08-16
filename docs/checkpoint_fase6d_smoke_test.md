# CHECKPOINT — FASE 6D: SMOKE TEST PRE-PILOTO

**FECHA:** 2026-08-15
**VEREDICTO:** BLOCKED — DECISION REQUIRED

---

## 1. Objetivo
Validar end-to-end (campaña 2 → P1 → campaign_id → variante → reserva → lead_id/Message-ID/snapshot/smtp_id/resultado_envio/tracking → métricas/Analytics) con 3 envíos A/B/C a buzones controlados.

## 2. Estado inicial (sin cambios en FASE 6D)
- `pipelines=2` (id=1 legacy; id=2 `PILOTO_FUTPROTEC_2026_08` `DRAFT/pilot`).
- `config.modo_entorno=test`, `config.motor_estado=pausado`.
- `envios=2`, `respuestas=0`, `aperturas=0`.

## 3. Auditoría pre-implementación
Verificado en código:
- `validarCampanaActiva(2, modoEntorno)`: exige `estado ∈ {PILOT,ACTIVE}`, `activo=1`, y `esEntornoCoherente('pilot', modoEntorno)`.
- `esEntornoCoherente('pilot', 'test')` = **NO coherente** (bloqueado). Para campaña 2 (`entorno=pilot`) debe ser `modo_entorno=produccion`.
- En P1, con `modo_entorno=produccion`, el destino es el email del lead (`$emailClub`) a menos que se fuerce `modo_test=1` + `test_email` override (esto SÍ está soportado en código).
- `esElegibleParaEnvio(leadId, campaignId=2)` bloquea los leads TEST (`@futprotec.local` / nombre `test`) porque campaña 2 tiene `entorno=pilot` (no test).

## 4. Hallazgo CRÍTICO (bloqueante)
Para probar la campaña **2** (`entorno=pilot`) y conseguir exactamente 1 variante A, 1 B y 1 C, las únicas combinaciones posibles son:

1. **Leads TEST** (test01..05@futprotec.local): variantes disponibles A=1810/1812, B=1809/1811, C=1813. **PERO** `esElegibleParaEnvio` los bloquea porque la campaña 2 no es `entorno=test`. → No se puede enviar con TEST leads.

2. **Leads reales** (p.ej. id 1→A, id 2→B, id 8→C): `esElegibleParaEnvio` los permite, y con `modo_test=1` + `test_email` override se enviaría al buzón controlado. **PERO**:
   - Se registraría `envios.lead_id/email/club` con un lead comercial real (aunque el `To:` sea el buzón controlado), incumpliendo la regla "NO utilizar ningún lead comercial de clubes" y contaminando los datos del smoke test.

No existe un tercer tipo de lead en `clubes_crm`. Por tanto no hay forma de ejecutar los 3 envíos A/B/C sobre la campaña 2 cumpliendo simultáneamente:
- `campaign_id=2` (entorno=pilot, no test),
- NO usar leads comerciales,
- usar leads TEST (bloqueados por la propia campaña piloto).

## 5. Decisión requerida
No procedo sin autorización. Opciones a elegir (solo una):

**Opción A** — Usar 3 leads reales (id 1=A, id 2=B, id 8=C) como `id_club` SOLO para sustitución de plantilla + `modo_test=1` + `test_email` a buzón controlado, aceptando que `envios.lead_id/email/club` apuntarán a leads reales en los registros de smoke (posteriormente identificables/eliminables como TEST).

**Opción B** — Ejecutar el smoke test contra una campaña TEST (`entorno=test`) usando leads TEST (test01..05), sin tocar la campaña 2. Esto NO valida `campaign_id=2` (sino una campaña de prueba distinta).

**Opción C** — Otra indicación explícita que el usuario determine.

> No modifiqué configuración, no realicé envíos, no cambié estado de campaña. Sigo en `modo_entorno=test`, `motor_estado=pausado`, campaña 2 en `DRAFT`.