# Checkpoint — Refactor `inc/eligibilidad.php` (bloque 6.3)

**Fecha:** 2026-08-25
**Ámbito:** `public_html/outbound/inc/eligibilidad.php`
**Tipo:** Refactor de mantenibilidad (sin cambio de comportamiento ni de esquema BD)
**Bloque:** §6.3 de `docs/REFACTORIZACIONES_PENDIENTES.md`

---

## Objetivo

Separar la **lógica de negocio** de las **consultas SQL** en el archivo de reglas de
elegibilidad (única fuente de verdad de supresión/aislamiento TEST/REAL para el motor
de envío).

## Cambios aplicados

Se extrajeron las consultas SQL a funciones de **acceso a datos** y las funciones de
negocio quedaron como **orquestadores delgados**:

| Función de acceso a datos (nueva) | Responsabilidad | Usada por |
|---|---|---|
| `getEntornoCampana(SQLite3 $db, int $idCampana): string` | Lee `pipelines.entorno` | `esCampanaTest()` |
| `getLeadParaElegibilidad(SQLite3 $db, int $leadId): ?array` | Lee el lead (id, email, estado, duplicado, nombre) | `esElegibleParaEnvio()` |
| `esEstadoSupresion(string $estadoLead): bool` | Lógica pura: lista de estados de baja/supresión | `esElegibleParaEnvio()` |
| `getEnviosPlantillaEnCampanasActivas(SQLite3 $db, int $plantillaId): int` | COUNT de envíos en campañas PILOT/ACTIVE | `plantillaEstaCongelada()` |
| `insertarEnvioLogico(SQLite3 $db, ..., bool $ignore): void` | INSERT (o INSERT OR IGNORE) del envío lógico | `reservarEnvioLogico()` |
| `getEnvioLogicoExistente(SQLite3 $db, int $leadId, int $campaignId): ?array` | SELECT de la fila idempotente existente | `reservarEnvioLogico()` |

**Comportamiento preservado** (verificados por test):
- `esElegibleParaEnvio()` devuelve las mismas razones: `lead_no_valido`, `lead_no_encontrado`,
  `supresion`, `duplicado`, `email_invalido`, `lead_real_en_campana_test`,
  `lead_test_en_campana_no_test`, `elegible`.
- `reservarEnvioLogico()` mantiene la **idempotencia** (INSERT OR IGNORE + fila existente)
  y el Message-ID/variante estables.
- Contrato público intacto (nombres/firmas/retornos).

## Validación

- `php -l public_html/outbound/inc/eligibilidad.php` → **No syntax errors detected.**
- **Test funcional `scripts/test_eligibilidad.php` → 20/20 PASS** (sobre copia de BD,
  sin tocar `stats.db` real): funciones puras, acceso a datos, elegibilidad (lead real,
  id=0, supresión), congelación de plantillas, y reserva lógica idempotente (1ª nuevo /
  2ª no / mismo id).
- Backup preventivo de `stats.db` creado antes y **eliminado tras validar**.
- No se modificó la BD real ni el esquema.

## Pendiente

- Deploy a producción + commit (requiere OK del usuario).

## Archivos de referencia

- `docs/REFACTORIZACIONES_PENDIENTES.md` — bloque 6.3
- `docs/PENDIENTES_OUTBOUND.md` — compendio (R-1)
- `scripts/test_eligibilidad.php` — test funcional local
