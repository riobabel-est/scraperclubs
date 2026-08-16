# FASE 6F.6 — Corrección de aislamiento TEST/REAL

## A. Archivos modificados

| Archivo | Tipo |
|---|---|
| `public_html/outbound/inc/eligibilidad.php` | helpers + regla simétrica |
| `public_html/outbound/api/get_cola.php` | filtro server-side por campaña |
| `public_html/outbound/cli/cron.php` | filtro SQL en selección de lead |
| `public_html/outbound/js/app.js` | envía `campaign_id` a `get_cola.php` |
| `scripts/fase6f6_test_aislamiento.php` | harness aislado (nuevo, sin SMTP) |
| `docs/checkpoint_fase6f6_correccion_aislamiento.md` | este informe |

NO se modificó: Evolution API, credenciales, SMTP, `modo_entorno` global,
estructura de campañas, productos, bajas multicampaña, gestor de campañas,
`lead_pipelines`, algoritmo A/B/C, idempotencia, tracking, ni `enviar_lote.php`
(ya protegido vía `esElegibleParaEnvio()`).

## B. Cambios exactos

### 1. `inc/eligibilidad.php`
- Se añadieron 3 helpers reutilizables:
  - `esLeadTest(array $lead): bool`
  - `esCampanaTest(SQLite3 $db, int $idCampana): bool`
  - `sqlFiltroCompatibilidadLeadCampana(SQLite3 $db, int $idCampana): string`
- `esElegibleParaEnvio()` pasó de proteger solo el cruce
  "lead test en campaña no-test" a una regla **SIMÉTRICA**:
  - campaña TEST + lead REAL → `lead_real_en_campana_test`
  - campaña NO TEST + lead TEST → `lead_test_en_campana_no_test`
- Se conservó intacta la lógica previa de supresión, duplicados, idempotencia,
  email inválido, etc. (las comprobaciones anteriores no se alteraron).

### 2. `api/get_cola.php`
- Se importó `inc/eligibilidad.php`.
- Se añadió el parámetro `campaign_id` (GET).
- En la construcción del `WHERE` de la cola se añade el fragmento SQL de
  compatibilidad cuando hay campaña; el filtrado es **server-side** (SQL).
  La UI no recibe leads incompatibles.

### 3. `cli/cron.php`
- En la selección del lead (paso 3) se añade el mismo fragmento SQL de
  compatibilidad, antes de la reserva/en vía. No se tocó ninguna otra lógica.
- Se mantiene la defensa en profundidad existente (`esElegibleParaEnvio()`).

### 4. `js/app.js`
- `cargarCola()` ahora envía `campaign_id` al endpoint `get_cola.php` para que
  la interfaz muestre solo leads compatibles.

## C. Definición final de lead TEST
Un lead es TEST si cumple **al menos una** de estas condiciones existentes
(no se añadió criterio nuevo):
- `email` contiene `@futprotec.local` (case-insensitive), o
- `nombre_club` empieza por `test` (case-insensitive).

Cualquier otro lead es considerado REAL.

## D. Definición final de campaña TEST
Una campaña es TEST cuando `pipelines.entorno` = `test` (case-insensitive),
consultado en BD (no se confía en valores del cliente).

## E. Matriz de permisos TEST/REAL

| Campaña \\ Lead | LEAD TEST | LEAD REAL |
|---|---|---|
| CAMPAÑA TEST (`entorno=test`) | PERMITIDO | BLOQUEADO (`lead_real_en_campana_test`) |
| CAMPAÑA NO TEST (`entorno=pilot/production`) | BLOQUEADO (`lead_test_en_campana_no_test`) | Reglas normales de elegibilidad |

## F. Resultados de todas las pruebas

Harness `php scripts/fase6f6_test_aislamiento.php` (BD en memoria, SIN SMTP):

```
[PASS] esLeadTest() detecta lead TEST por email/criterio existente
[PASS] esCampanaTest() detecta campaña TEST por pipelines.entorno
[PASS] 1. campaign_id=3 (TEST) + lead TEST → permitido — razon=elegible
[PASS] 2. campaign_id=3 (TEST) + lead REAL → bloqueado — razon=lead_real_en_campana_test
[PASS] 3. campaign_id=2 (pilot) + lead TEST → bloqueado — razon=lead_test_en_campana_no_test
[PASS] 4. campaign_id=2 (pilot) + lead REAL → pasa aislamiento — razon=elegible
[PASS] 5. get_cola campaign 3 → no contiene leads reales — ids=[1809]
[PASS] 6. get_cola campaign 2 → no contiene leads TEST — ids=[1]
[PASS] 7. cron campaign TEST → no selecciona leads reales — lead_id=1809
[PASS] 8. enviar_lote directo campaign_id=3 + id_club real → bloqueado antes de SMTP
[PASS] REGRESIÓN: asignarVariante determinística/inmutable
[PASS] REGRESIÓN: esEntornoCoherente mínima
[PASS] REGRESIÓN: reservarEnvioLogico idempotente
[PASS] REGRESIÓN: supresión Lista Negra intacta
[PASS] REGRESIÓN: email inválido intacto
[PASS] REGRESIÓN: duplicado intacto
✅ TODAS LAS PRUEBAS PASARON
```

Comprobación adicional **contra la BD real (solo lectura)**:
- Campaña TEST (id=3): 5 compatibles, 5 TEST, 0 REAL.
- Campaña NO TEST (id=2): 1742 compatibles, 0 TEST, 1742 REAL.

Validación de sintaxis/compilación:
- `php -l` OK en `eligibilidad.php`, `get_cola.php`, `enviar_lote.php`, `cron.php`.
- `python -m py_compile` OK en `main.py`, `scraper_nova.py`, `scraper_rfcylf.py`, `scraper_fcf_cat.py`, `config.py`.

## G. Confirmación explícita
**NO SE HA REALIZADO NINGÚN ENVÍO SMTP.**
No se ejecutó SMTP ni el smoke test.

## H. Riesgos residuales
- La definición de lead TEST por `nombre_club` que empieza por "test" podría
  capturar un club real cuyo nombre comience por "test" (poco probable; coincide
  exactamente con el criterio preexistente solicitado). Se mantiene por fidelidad
  al criterio existente.
- La definición SQL usa `LIKE` collation por defecto de SQLite (case-insensitive
  para ASCII); los criterios `@futprotec.local` y `test` son ASCII, así que la
  equivalencia con `esLeadTest()` (que usa `strtolower`/`mb_strtolower`) es exacta.
- `get_cola.php` sin `campaign_id` sigue mostrando todos los leads (retrocompat).
  La protección dura de envío recae en `enviar_lote.php`/`cron.php` vía
  `esElegibleParaEnvio()`, por lo que incluso la cola sin filtrar no puede
  derivar en un envío cruzado.

## Estado
CORRECCIÓN IMPLEMENTADA Y VERIFICADA. **Se espera autorización explícita antes
de ejecutar el smoke test.**