# Checkpoint — Lanzadera Final: Flujo de Pruebas y Envío

> Fase: LANZADERA-FINAL
> Alcance: auditoría profunda + corrección quirúrgica del flujo `enviarCorreoPrueba()` / `iniciarMotor()` / `api/enviar_lote.php`.
> Modo: SIN SMTP. SIN cambios en `stats.db` de producción. SIN cambios de campaña/plantilla/entorno.

---

## A. Diagnóstico original

Al pulsar **“Enviar correos de prueba”** (`app.js:556-592`) el flujo era:

1. valida solo que exista `lzCampaignId` (no su estado);
2. comprueba plantilla + `testEmailsList` + primer lead (`lzCola[0]` o `get_leads_table[0]`);
3. toma la primera cuenta SMTP activa;
4. detecta A/B/C por `tpl.test_ab`;
5. muestra `confirm("Se enviarán 3 correos de prueba…")` — **antes** de cualquier validación de campaña;
6. hace 3 POST a `api/enviar_lote.php` con `modo_test=1`, `variante_ab` = A/B/C, `campaign_id`;
7. backend rechaza campaña DRAFT con `CAMPAIGN_NOT_ACTIVE` (`validarCampanaActiva()`);
8. el usuario veía 3 × “❌ Campaña no válida”.

**Causa raíz:** el `confirm()` aparece antes de la validación de campaña; y con campaña 2 (`DRAFT`) el backend bloquea. Además, en la versión original, la variante en modo test se resolvía con `asignarVariante()` (determinística), por lo que las 3 entregas A/B/C tendían al mismo contenido, y la reserva de envío usaba `campaign_id` real, contaminando la idempotencia comercial con los intentos de prueba.

## B. Flujo antes

```
enviarCorreoPrueba()
  → alert si no lzCampaignId / plantilla / testEmails / club / smtp
  → confirm "Se enviarán N"   ← sin validar campaña
  → for A/B/C: POST enviar_lote.php (modo_test=1, variante=asignarVariante determinística)
  → validarCampanaActiva() rechaza DRAFT → "Campaña no válida"
  → reservarEnvioLogico( campaign_id real ) → idempotencia colisiona entre pruebas
```

## C. Problemas encontrados

1. **Aviso incoherente**: promete “3 correos” aunque el backend vaya a rechazar.
2. **DRAFT no operable**: `validarCampanaActiva()` exige `estado ∈ {PILOT,ACTIVE}` y `activo=1`, más coherencia de entorno. Una campaña DRAFT se rechaza (por diseño).
3. **Variante en prueba**: `asignarVariante()` determinística hacía que A/B/C no mostrara contenido distinto.
4. **Idempotencia en prueba**: reservar el envío lógico con el `campaign_id` comercial hacía que una prueba pudiera bloquear (o contaminar) el envío comercial posterior del mismo lead.
5. **Selección de lead de prueba**: `enviarCorreoPrueba()` usa `lzCola[0]` o el primer lead de `get_leads_table`; este último es un lead REAL (no TEST), lo que en una campaña TEST dispara el bloqueo de aislamiento `lead_real_en_campana_test`.
6. **En MODO PRUEBAS, el aislamiento TEST/REAL sigue aplicando**: una campaña TEST exige lead TEST; probarla con un lead REAL se bloquea en `esElegibleParaEnvio()` (comportamiento correcto de FASE 6F.6, pero es tracción a documentar).

## D. Correcciones realizadas

Solo lectura previa + correcciones mínimas. **No se tocó** A/B/C, `resolverContenidoVariante()`, `reservarEnvioLogico()`, Message-ID, tracking, respuestas, supresión, credenciales SMTP, ni `enviar_smtp_random.php`.

### `public_html/outbound/api/enviar_lote.php`

1. **Variante en prueba explícita** (tras la validación de campaña):
   ```php
   $varianteUsada = $modoTest ? $varianteAb : asignarVariante($idClub, $idCampana);
   ```
   - Producción/real: determinística e inmutable (sin regresión).
   - Prueba: respeta la variante A/B/C elegida en la UI.

2. **Idempotencia TEST vs REAL separada** (en la reserva del envío lógico):
   ```php
   $campaignIdParaReserva = $modoTest ? 0 : $idCampana;
   $reserva = reservarEnvioLogico(..., $campaignIdParaReserva, ..., ($campaignIdParaReserva > 0) ? $varianteUsada : $varianteAb, ...);
   ```
   - Prueba: reserva con `campaign_id NULL` → las 3 variantes no colisionan con `idx_envios_lead_campaign` y no contaminan el histórico comercial.
   - Producción: `campaign_id > 0` → idempotencia intacta (un lead → una fila por campaña), variante determinística intacta.

3. **Trazabilidad**: `comunicaciones_log.detalles` ahora etiqueta `[TEST campaña N]` en modo pruebas.

### `public_html/outbound/js/app.js`

4. **CORRECCIÓN 1 (prevalidación UX, sin duplicar seguridad)**: se añadió `campanaOperable(c)` (espejo de `validarCampanaActiva()`/`esEntornoCoherente()` SOLO para UX) y `enviarCorreoPrueba()` la invoca **antes** del `confirm()`. Si la campaña no es operable, se muestra un motivo concreto (p. ej. “Campaña DRAFT: no operable para pruebas de envío…”) y **no** se muestra “Se enviarán 3 correos…”. El backend sigue siendo la autoridad.

## E. Flujo después

```
enviarCorreoPrueba()
  → alert si no lzCampaignId
  → campanaOperable(lzCampana)         ← bloqueo temprano con motivo claro
  → alert si no plantilla / testEmails / club / smtp
  → confirm "Se enviarán N"             ← solo si la campaña es operable
  → for A/B/C: POST enviar_lote.php (modo_test=1, variante_ab explícita)
  → validarCampanaActiva()              ← autoridad (estricta)
  → esElegibleParaEnvio()               ← aislamiento TEST/REAL
  → plantilla activa → variante → SMTP activa/no saturada
  → destinatario: test_email (modo test) | lead.email (producción)
  → reserva: campaign_id NULL (test) | campaign_id real (producción, idempotente)
```

## F. TEST/REAL (FASE 6F.6)

Regla simétrica verificada (harness `scripts/fase6f6_test_aislamiento.php`, 16/16 PASS):

- **CAMPAÑA TEST + LEAD TEST** → permitido.
- **CAMPAÑA TEST + LEAD REAL** → bloqueado (`lead_real_en_campana_test`).
- **CAMPAÑA NO TEST + LEAD TEST** → bloqueado (`lead_test_en_campana_no_test`).
- **CAMPAÑA NO TEST + LEAD REAL** → pasa aislamiento.

**Decisión sobre “lead REAL en MODO PRUEBAS”:** se **mantiene MODELO A** (aislamiento actual). No se cambia de forma automática. En modo pruebas la garantía de destinatario es server-side (`modo_test=1` + `test_email` válido → `$emailDestino = $testEmailOverride`); para campañas TEST debe usarse un lead TEST. Para probar una campaña comercial normal conviene hacerlo en su propio entorno (PILOT/ACTIVE coherente); el desvío de destinatario en modo test ya impide llegar al email real.

## G. Modo pruebas

- `modo_test` server-side = `config.modo_entorno === 'test'` **OR** `POST.modo_test === '1'` (anti-bypass desde BD).
- Con `modo_test` y `test_email` válido → `$emailDestino = $testEmailOverride` (nunca `$emailClub`).
- Sin `test_email` válido → fallback `contactofutprotec@gmail.com`.
- No altera `estado_lead` comercial; solo añade nota `[TEST …]` a `observaciones`.
- `campaign_id` queda registrado en el detalle del log, pero el envío lógico de prueba usa `campaign_id NULL` (no contamina la idempotencia comercial).

## H. Idempotencia

- **Mismo lead + misma campaña (real)** → 1 fila (`INSERT OR IGNORE` + índice único parcial `idx_envios_lead_campaign`). Probado: 1 fila, variante determinística respetada.
- **Mismo lead + distinta campaña (real)** → fila independiente (la clave es `(lead_id, campaign_id)`).
- **Mismo lead, prueba repetida (test)** → cada variante A/B/C es una fila con `campaign_id NULL`; repetir la prueba genera filas nuevas (sin bloqueo).
- **Prueba NO bloquea el posterior envío real**: la reserva de prueba es `campaign_id NULL`, distinta de la reserva comercial `(lead_id, campaign_id)`.

Harness nuevo: `scripts/fase_launcher_check_idempotencia.php` → 4/4 PASS (en memoria, sin SMTP).

## I. Plantillas

- `test_ab=1` → `resolverContenidoVariante()` resuelve asunto/cuerpo por A/B/C. En modo prueba la variante es explícita (A/B/C entregados).
- Placeholders soportados: `{{CLUB}}`, `{{CONTACTO}}`, `{{FEDERACION}}`, `{{ANIO}}`, `{{EMAIL}}`, `{{SENDER_NAME}}`, `{{SENDER_TITLE}}`, `{{SENDER_EMAIL}}`.
- Plantilla vacía activa: el backend la acepta (no hay validación de cuerpo vacío); el contenido se resuelve tal cual. (Sin cambios; riesgo menor documentado, no corregido por alcance.)
- Congelación (`plantillaEstaCongelada()`): una plantilla usada por campaña PILOT/ACTIVE no se puede sobrescribir. No se tocó. No existe “Duplicar para editar” → pendiente, no rediseñada.

## J. Validaciones

- PHP: `php -l` sin errores en `enviar_lote.php`, `abc.php`, `eligibilidad.php`, `get_cola.php`, `dashboard.php`.
- JS: `node --check public_html/outbound/js/app.js` → exit 0.
- Harness aislamiento TEST/REAL existente → 16/16 PASS.
- Harness idempotencia nuevo → 4/4 PASS.

## K. Riesgos restantes

1. **Campaña DRAFT no operativa para pruebas** (por `validarCampanaActiva()`, mantenido estricto por CORRECCIÓN 2). Para probar, la campaña debe pasar a `PILOT`/`ACTIVE` coherente con el entorno. Esto es el punto de fricción operativa principal.
2. **Lead de prueba**: `enviarCorreoPrueba()` toma `lzCola[0]` o el primer lead REAL de `get_leads_table`. Para una campaña TEST hay que cargar la cola primero (la cola de campaña TEST devuelve solo leads TEST por `sqlFiltroCompatibilidadLeadCampana()`). Si no hay cola, el fallback REAL será bloqueado por aislamiento (seguro, pero confuso). Mejora recomendada (no implementada): selector explícito de lead de prueba.
3. **`enviar_smtp_random.php`** queda fuera del flujo (no usado; CORRECCIÓN: no reintroducir).
4. **Selección SMTP**: la prueba usa la primera cuenta activa (sin round-robin de `get_cola`); producción usa `smtp_asignada_id` de la cola. Difieren (documentado).
5. **`modo_entorno` global** actualmente en `test`: mientras esté en `test`, el motor nunca enviará a leads reales (el desvío y la reserva de prueba se fuerzan). El paso a producción requiere cambiar `modo_entorno` a `produccion`, lo cual queda fuera de esta fase (no ejecutado).

## L. Qué falta para el primer envío comercial

1. Poner la campaña comercial en `PILOT` (o `ACTIVE`) con entorno coherente (p. ej. `pilot`), y `config.modo_entorno = produccion`.
2. Confirmar plantilla(s) y cuentas SMTP activas bajo límite diario.
3. Autorización explícita de SMTP real (fuera de esta fase).
4. Primer envío controlado con volumen reducido; monitorizar `envios` / `comunicaciones_log` / respuesta.

---

## Veredicto

```text
LAUNCHER_FLOW_READY
```

El flujo de pruebas (destinatario desviado, variantes A/B/C explícitas, idempotencia separada) y de producción (destinatario real, variante determinística, idempotencia comercial) queda coherente y validado **sin SMTP** y **sin tocar `stats.db` de producción**. La campaña DRAFT queda bloqueada con mensaje claro (no operable), en coherencia con `validarCampanaActiva()`.