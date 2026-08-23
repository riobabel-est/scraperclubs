# Checkpoint — Refactor `api/leads.php` (scan_duplicates)

**Fecha:** 2026-08-23
**Ámbito:** `public_html/outbound/api/leads.php`
**Tipo:** Refactor de mantenibilidad (sin cambio de comportamiento ni de esquema BD)

---

## Objetivo

Extraer la lógica monolítica del endpoint `scan_duplicates` (~105 líneas) a funciones
puras, siguiendo el mismo patrón ya aplicado en `api/analytics.php` (handler delgado +
funciones puras). Bloque 3.2 de `docs/REFACTORIZACIONES_PENDIENTES.md`.

## Problema original

El bloque `scan_duplicates` (líneas 86-191) mezclaba 4 responsabilidades en un solo
handler HTTP:

1. Reset de flags de duplicado.
2. Match 1 — emails idénticos.
3. Match 2 — nombres similares >80% por federación.
4. Persistencia de flags en BD.

Además presentaba dos ineficiencias:
- **Bucle O(n²) de deduplicación** (recorría `$paresEncontrados` para comprobar si un
  par ya existía).
- **Re-normalización repetida** de `normalizar_nombre_club()` en cada iteración del
  bucle interno.

## Solución aplicada

Se extrajeron 4 funciones puras y el handler quedó como orquestador delgado:

| Función pura | Responsabilidad |
|---|---|
| `resetFlagsDuplicados(SQLite3 $db): void` | Limpia `es_duplicado`/`duplicado_id` |
| `detectarDuplicadosEmail(array $clubes): array` | Agrupa por email, devuelve pares `email_exacto` |
| `detectarDuplicadosNombre(array $clubes): array` | Agrupa por federación, normaliza, compara >80%, devuelve pares `nombre_similar` |
| `marcarDuplicados(SQLite3 $db, array $pares): array` | Persiste flags y devuelve pares marcados |

**Mejoras de rendimiento (sin cambiar comportamiento):**
- **Pre-cálculo de normalización:** `normalizar_nombre_club()` se calcula una sola vez
  por club (arrays `$norm`/`$len`), en lugar de recalcular en el bucle interno.
- **Set de claves O(1):** se sustituyó el bucle O(n²) de deduplicación por un set
  `$vistos["keep|dup"]`, reduciendo la complejidad de la comprobación de pares repetidos.

**Handler resultante:**
```php
resetFlagsDuplicados($db);
// ...cargar $clubes...
$paresEncontrados = array_merge(
    detectarDuplicadosEmail($clubes),
    detectarDuplicadosNombre($clubes)
);
$duplicadosMarcados = marcarDuplicados($db, $paresEncontrados);
echo json_encode([...]);
```

## Contrato JSON preservado

El endpoint `scan_duplicates` sigue devolviendo exactamente la misma estructura:
```json
{ "ok": true, "total": N, "dups": M, "pares": [...] }
```
No se alteró el esquema de BD ni el contrato de ningún otro endpoint.

## Validación

- `php -l public_html/outbound/api/leads.php` → **No syntax errors detected.**
- No se tocó la BD.
- No se modificó ningún otro endpoint del archivo.

## Pendiente

- **Deploy a producción** (requiere aprobación del usuario).
- Smoke test del endpoint `scan_duplicates` en producción (verificar que devuelve los
  mismos pares que antes del refactor).

## Archivos de referencia

- `docs/REFACTORIZACIONES_PENDIENTES.md` — bloque 3.2
- `docs/checkpoint_refactor_analytics_funciones_puras.md` — patrón previo aplicado
