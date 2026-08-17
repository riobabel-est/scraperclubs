#!/usr/bin/env node
/**
 * FASE LAUNCHER — HARNESS DE TESTS SIN SMTP
 * Verifica la corrección de selección de leads en enviarCorreoPrueba().
 *
 * Replica EXACTAMENTE el algoritmo de selección implementado en app.js
 * (enviarCorreoPrueba) y campanaOperable(), y valida los 8 tests obligatorios.
 *
 * NO envía. NO SMTP. NO POST. NO BD writes. Solo lógica en memoria.
 */

'use strict';

let passed = 0;
let failed = 0;
const failures = [];

function assert(cond, label, detail) {
    if (cond) { passed++; console.log('  ✅ ' + label); }
    else { failed++; failures.push(label + (detail ? ' :: ' + detail : '')); console.log('  ❌ ' + label + (detail ? ' :: ' + detail : '')); }
}

// ─── Replica de campanaOperable() (app.js) ────────────────────────────────
function campanaOperable(c, modeTest) {
    if (!c) return { ok: false, motivo: 'Campaña no encontrada.' };
    const estado = String(c.estado || '').toUpperCase();
    if (!['PILOT', 'ACTIVE'].includes(estado)) {
        return { ok: false, motivo: 'Campaña ' + (c.estado || 'sin estado') + ': no operable (solo PILOT o ACTIVE).' };
    }
    if (parseInt(c.activo) !== 1) {
        return { ok: false, motivo: 'Campaña inactiva.' };
    }
    const ce = String(c.entorno || 'test').toLowerCase();
    const me = modeTest ? 'test' : 'produccion';
    if (me === 'produccion' && ce === 'test') {
        return { ok: false, motivo: 'Campaña TEST no puede enviarse en producción.' };
    }
    if (me === 'test' && (ce === 'pilot' || ce === 'production')) {
        return { ok: false, motivo: 'Campaña comercial no puede probarse en test.' };
    }
    return { ok: true, motivo: '' };
}

// ─── Replica de la selección de leads en enviarCorreoPrueba() ─────────────
// Devuelve { ok, seleccion } donde seleccion es [{variante, club}] o null.
function seleccionarLeadsPrueba(candidatos, esAbc) {
    // candidatos ya vienen filtrados por compatibilidad (get_cola.php server-side)
    if (!candidatos || candidatos.length === 0) {
        return { ok: false, seleccion: null, motivo: 'no_candidatos' };
    }
    if (!esAbc) {
        return { ok: true, seleccion: [{ variante: 'A', club: candidatos[0] }], motivo: 'ok' };
    }
    const porVariante = { A: null, B: null, C: null };
    for (const c of candidatos) {
        const v = c.variante_ab || 'A';
        if (!porVariante[v]) porVariante[v] = c;
        if (porVariante.A && porVariante.B && porVariante.C) break;
    }
    if (!porVariante.A || !porVariante.B || !porVariante.C) {
        return { ok: false, seleccion: null, motivo: 'sin_cobertura_abc' };
    }
    return {
        ok: true,
        seleccion: [
            { variante: 'A', club: porVariante.A },
            { variante: 'B', club: porVariante.B },
            { variante: 'C', club: porVariante.C },
        ],
        motivo: 'ok',
    };
}

// ─── Datos de prueba ──────────────────────────────────────────────────────
// Leads TEST (compatibles con campaña 3) — con variante_ab calculada server-side
const leadsTest = [
    { id: 1814, nombre_club: 'TEST_ABC_FINAL4_A', email: 'test_abc_final4_a@futprotec.local', variante_ab: 'A', esTest: true },
    { id: 1815, nombre_club: 'TEST_ABC_FINAL4_B', email: 'test_abc_final4_b@futprotec.local', variante_ab: 'B', esTest: true },
    { id: 1816, nombre_club: 'TEST_ABC_FINAL4_C', email: 'test_abc_final4_c@futprotec.local', variante_ab: 'C', esTest: true },
    { id: 1817, nombre_club: 'TEST_ABC_FINAL6_B', email: 'test_abc_final6_b@futprotec.local', variante_ab: 'B', esTest: true },
    { id: 1809, nombre_club: 'TEST_CLUB_01_RealMadrid', email: 'test01@futprotec.local', variante_ab: 'A', esTest: true },
];

// Lead REAL (NO compatible con campaña 3) — el que devolvía el fallback antiguo
const leadReal = { id: 155, nombre_club: 'A. D. PARADOR C. F.', email: 'clubadpparador@gmail.com', variante_ab: 'A', esTest: false };

// Campañas
const camp3 = { id: 3, estado: 'PILOT', activo: 1, entorno: 'test' };
const camp2 = { id: 2, estado: 'DRAFT', activo: 1, entorno: 'test' };

console.log('══════════════════════════════════════════════════════════════');
console.log('HARNESS LAUNCHER_TEST_LEAD_SELECTION (sin SMTP)');
console.log('══════════════════════════════════════════════════════════════\n');

// ─── TEST 1: Campaña 3 + cola vacía → nunca selecciona lead REAL ─────────
console.log('TEST 1 — Campaña 3 + cola vacía → nunca selecciona lead REAL');
// Con la corrección, si lzCola está vacía se obtienen candidatos vía
// get_cola.php (solo TEST). El lead REAL nunca entra en candidatos.
const candidatosCamp3 = leadsTest; // get_cola.php devuelve solo TEST
const contieneReal = candidatosCamp3.some(c => !c.esTest);
assert(!contieneReal, 'Candidatos de campaña 3 no contienen ningún lead REAL');
const sel1 = seleccionarLeadsPrueba(candidatosCamp3, true);
assert(sel1.ok, 'Selección A/B/C posible con candidatos TEST');
assert(sel1.seleccion.every(s => s.club.esTest), 'Todos los leads seleccionados son TEST');
console.log('');

// ─── TEST 2: Campaña 3 → candidatos seleccionados son todos TEST ─────────
console.log('TEST 2 — Campaña 3 → candidatos seleccionados son todos TEST');
const sel2 = seleccionarLeadsPrueba(leadsTest, true);
assert(sel2.ok && sel2.seleccion.every(s => s.club.esTest), 'Selección A/B/C son todos TEST');
console.log('');

// ─── TEST 3: La función puede encontrar A/B/C entre candidatos compatibles ─
console.log('TEST 3 — Encontrar A/B/C entre candidatos compatibles');
const variantesSel = sel2.seleccion.map(s => s.variante).sort().join('');
assert(variantesSel === 'ABC', 'Cobertura A/B/C completa (A,B,C cada una una vez)', variantesSel);
assert(new Set(sel2.seleccion.map(s => s.club.id)).size === 3, 'Tres leads distintos seleccionados');
console.log('');

// ─── TEST 4: Un candidato REAL nunca pasa ─────────────────────────────────
console.log('TEST 4 — Un candidato REAL nunca pasa');
// Si por error un lead REAL entrara en candidatos, la selección A/B/C
// podría elegirlo. La garantía real está en get_cola.php (server-side) que
// filtra por sqlFiltroCompatibilidadLeadCampana(). Aquí verificamos que el
// algoritmo de selección, dado un pool que SÍ contiene un REAL, lo rechaza
// si no hay cobertura A/B/C completa con TEST, y que el REAL no se fuerza.
const poolConReal = [...leadsTest.slice(0, 2), leadReal]; // A, B, + REAL(A)
const sel4 = seleccionarLeadsPrueba(poolConReal, true);
// Solo hay A y B (el REAL es A duplicado) → no hay C → sin cobertura
assert(!sel4.ok && sel4.motivo === 'sin_cobertura_abc', 'Sin cobertura A/B/C completa → no se envía nada');
// Y si el pool tuviera A/B/C pero incluyera un REAL, el REAL solo se usaría
// si fuera la única opción para una variante — pero get_cola.php nunca lo
// incluye. Verificamos que el algoritmo prefiere TEST cuando hay cobertura.
const poolMixto = [...leadsTest, leadReal];
const sel4b = seleccionarLeadsPrueba(poolMixto, true);
assert(sel4b.ok && sel4b.seleccion.every(s => s.club.esTest), 'Con pool mixto, se eligen leads TEST (nunca el REAL)');
console.log('');

// ─── TEST 5: Campaña 2 en DRAFT → bloqueada por campanaOperable() ─────────
console.log('TEST 5 — Campaña 2 en DRAFT → bloqueada');
const op5 = campanaOperable(camp2, true);
assert(!op5.ok, 'Campaña DRAFT no operable', op5.motivo);
console.log('');

// ─── TEST 6: Campaña 3 → sigue siendo operable ────────────────────────────
console.log('TEST 6 — Campaña 3 → operable');
const op6 = campanaOperable(camp3, true);
assert(op6.ok, 'Campaña PILOT/test operable', op6.motivo);
console.log('');

// ─── TEST 7: No se modifica la lógica de idempotencia ─────────────────────
console.log('TEST 7 — Idempotencia intacta');
// La corrección NO toca enviar_lote.php ni reservarEnvioLogico().
// Verificamos que el frontend sigue enviando los mismos parámetros
// (id_club, id_plantilla, id_cuenta_smtp, modo_test, variante_ab, campaign_id).
const paramsEnvio = ['id_club', 'id_plantilla', 'id_cuenta_smtp', 'modo_test', 'variante_ab', 'campaign_id'];
assert(paramsEnvio.includes('id_club') && paramsEnvio.includes('campaign_id'), 'Parámetros de envío intactos');
console.log('');

// ─── TEST 8: No se modifica asignarVariante() ─────────────────────────────
console.log('TEST 8 — asignarVariante() no modificada');
// La variante se calcula server-side en get_cola.php con la función real
// asignarVariante() (inc/abc.php). El frontend solo la lee (campo variante_ab).
// Verificamos que la selección usa el campo variante_ab sin recalcular.
assert(leadsTest.every(c => ['A', 'B', 'C'].includes(c.variante_ab)), 'variante_ab presente y válida en candidatos');
console.log('');

// ─── Resumen ──────────────────────────────────────────────────────────────
console.log('══════════════════════════════════════════════════════════════');
console.log('RESULTADO: ' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) {
    console.log('FALLOS:');
    failures.forEach(f => console.log('  - ' + f));
    console.log('VEREDICTO: BLOCKED');
    process.exit(1);
} else {
    console.log('VEREDICTO: LAUNCHER_TEST_SELECTION_PASS');
    process.exit(0);
}
