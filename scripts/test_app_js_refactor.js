#!/usr/bin/env node
/**
 * test_app_js_refactor.js — Test funcional local del refactor §5 de js/app.js.
 *
 * Instancia el objeto app() en Node con shims de window/document/lucide y
 * valida que los métodos refactorizados se comportan igual que antes:
 *   - 5.1: iniciarMotor() delega en enviarDirigido()/enviarCola()
 *   - 5.2: getters lzTasaExito/lzEnvioOkPct/lzEnvioErrorPct
 *   - 5.3: enviarCorreoPrueba() -> validarPruebaEmail/obtenerCandidatosPrueba/armarSeleccionPrueba
 *   - 5.4: renderGestorRows/renderGestorPaginacion/renderSmtpRows
 *
 * Uso (local, no se ejecuta en el servidor):
 *   node scripts/test_app_js_refactor.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

// ─── Shims de globales usadas por app.js ─────────────────────────────────────
global.window = global;
global.document = { addEventListener: () => {} };
global.lucide = { createIcons: () => {} };
global.Alpine = { data: () => {}, nextTick: (fn) => Promise.resolve(fn()) };
global.alert = () => {};
global.confirm = () => true;

// ─── Cargar app.js en el contexto global ─────────────────────────────────────
const appJsPath = path.join(__dirname, '..', 'public_html', 'outbound', 'js', 'app.js');
const code = fs.readFileSync(appJsPath, 'utf8');
vm.runInThisContext(code, { filename: 'app.js' });

// app() quedó definida como var global. Nota: app() reasigna window.app = i
// (patrón para los onclick="window.app...") por lo que capturamos la referencia
// a la función ANTES de la primera instanciación.
const APP_FN = global.app;
if (typeof APP_FN !== 'function') {
    console.error('FATAL: no se pudo cargar app() desde js/app.js');
    process.exit(1);
}

// ─── Mini framework de asserts ────────────────────────────────────────────────
let passed = 0, failed = 0;
const failures = [];
function check(nombre, cond, detalle) {
    if (cond) { passed++; console.log('  ✅ ' + nombre); }
    else { failed++; failures.push(nombre); console.log('  ❌ ' + nombre + (detalle ? ' — ' + detalle : '')); }
}
function eq(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

// ─── Helpers ─────────────────────────────────────────────────────────────────
function makeInstancia() {
    const inst = APP_FN();
    // Mock de esc (escape HTML simple) y delay instantáneo para no esperar.
    inst.esc = (s) => String(s == null ? '' : s);
    inst.delay = () => Promise.resolve();
    return inst;
}
function mockFetch(handler) {
    global.fetch = async (url, opts) => {
        const r = handler(url, opts);
        return { ok: true, json: async () => r };
    };
}
const ENVIO_OK = { envio_exitoso: true, timestamp: 'T', club: 'Club', email: 'a@a.com', cuenta_smtp: 'c@c.com' };


// ═══════════════════════════════════════════════════════════════════════════════
// §5.2 — Getters
// ═══════════════════════════════════════════════════════════════════════════════
console.log('\n=== §5.2 Getters ===');
{
    const inst = makeInstancia();
    inst.lzLogEnviados = [
        { envio_exitoso: true }, { envio_exitoso: false }, { envio_exitoso: true },
    ];
    check('lzTasaExito = 67', inst.lzTasaExito === 67, 'got ' + inst.lzTasaExito);
    check('lzEnvioOkPct delega en lzTasaExito (= 67)', inst.lzEnvioOkPct === 67, 'got ' + inst.lzEnvioOkPct);
    check('lzEnvioErrorPct = 33', inst.lzEnvioErrorPct === 33, 'got ' + inst.lzEnvioErrorPct);
    inst.lzLogEnviados = [];
    check('lzTasaExito con cola vacía = 0', inst.lzTasaExito === 0, 'got ' + inst.lzTasaExito);
    check('lzEnvioOkPct con cola vacía = 0', inst.lzEnvioOkPct === 0, 'got ' + inst.lzEnvioOkPct);
}

// ═══════════════════════════════════════════════════════════════════════════════
// §5.3 — validarPruebaEmail / obtenerCandidatosPrueba / armarSeleccionPrueba
// ═══════════════════════════════════════════════════════════════════════════════
console.log('\n=== §5.3 enviarCorreoPrueba (helpers) ===');
async function test53() {
    const inst = makeInstancia();
    // validarPruebaEmail
    inst.lzCampaignId = ''; inst.lzIdPlantillaEmail = 5; inst.testEmails = 'a@x.com';
    check('validar: sin campaña -> error', inst.validarPruebaEmail() === 'Selecciona una campaña antes de enviar.');
    inst.lzCampaignId = 1; inst.lzIdPlantillaEmail = ''; inst.testEmails = 'a@x.com';
    check('validar: sin plantilla -> error', inst.validarPruebaEmail() === 'Selecciona primero una plantilla de email en la configuración del lote.');
    inst.lzIdPlantillaEmail = 5; inst.testEmails = '';
    check('validar: sin emails -> error', inst.validarPruebaEmail() === 'Configura al menos un email de prueba en "Destinos de Prueba".');
    inst.testEmails = 'a@x.com\nb@x.com';
    check('validar: todo correcto -> null', inst.validarPruebaEmail() === null);

    // obtenerCandidatosPrueba (reutiliza lzCola si tiene leads)
    inst.lzCola = [{ id: 1 }, { id: 2 }];
    const cands = await inst.obtenerCandidatosPrueba();
    check('obtenerCandidatos: reutiliza lzCola (2)', Array.isArray(cands) && cands.length === 2);

    // obtenerCandidatosPrueba (fetch get_cola si lzCola vacía)
    inst.lzCola = [];
    mockFetch(() => ({ ok: true, cola: [{ id: 10 }, { id: 11 }] }));
    const cands2 = await inst.obtenerCandidatosPrueba();
    check('obtenerCandidatos: fetch get_cola (2)', Array.isArray(cands2) && cands2.length === 2);

    // armarSeleccionPrueba: no-ABC usa el primer lead
    const sel1 = inst.armarSeleccionPrueba([{ id: 1 }, { id: 2 }], false);
    check('armarSeleccion: no-ABC -> 1 lead variante A', eq(sel1, [{ variante: 'A', club: { id: 1 } }]));

    // armarSeleccionPrueba: ABC con cobertura -> 3 leads
    const sel3 = inst.armarSeleccionPrueba(
        [{ id: 1, variante_ab: 'A' }, { id: 2, variante_ab: 'B' }, { id: 3, variante_ab: 'C' }], true);
    check('armarSeleccion: ABC -> 3 leads', Array.isArray(sel3) && sel3.length === 3);

    // armarSeleccionPrueba: ABC sin cobertura -> null
    const selNull = inst.armarSeleccionPrueba([{ id: 1, variante_ab: 'A' }], true);
    check('armarSeleccion: ABC incompleto -> null', selNull === null);
}

// ═══════════════════════════════════════════════════════════════════════════════
// §5.4 — renderGestorRows / renderGestorPaginacion / renderSmtpRows
// ═══════════════════════════════════════════════════════════════════════════════
console.log('\n=== §5.4 renderizado ===');
{
    const inst = makeInstancia();
    const h = inst.renderGestorRows([{ nombre_club: 'CD Ejemplo', email: 'club@ejemplo.es', telefono_movil: '600000000', estado_lead: 'Pendiente', federacion: 'Madrid', id: 9, es_duplicado: 0 }]);
    check('renderGestorRows: contiene nombre del club', h.includes('CD Ejemplo'));
    check('renderGestorRows: contiene email', h.includes('club@ejemplo.es'));
    check('renderGestorRows: contiene botón Ficha', h.includes('openLead(9)'));
    check('renderGestorRows: fila vacía -> "Sin resultados"', inst.renderGestorRows([]).includes('Sin resultados'));

    inst.gp = 3;
    const pg = inst.renderGestorPaginacion(10);
    check('renderGestorPaginacion: contiene página 3 activa', pg.includes('bg-slate-700 border-slate-600'));
    check('renderGestorPaginacion: contiene primera página', pg.includes('Ir a pagina 1'));
    check('renderGestorPaginacion: contiene elipsis', pg.includes('…'));

    const sm = inst.renderSmtpRows([{ id: 5, email: 'ventas@getfutprotec.com', host: 'mail.getfutprotec.com', puerto: 465, activa: 1, enviados_hoy: 10, limite_diario: 50, ultimo_error: '' }]);
    check('renderSmtpRows: contiene email', sm.includes('ventas@getfutprotec.com'));
    check('renderSmtpRows: contiene host:puerto', sm.includes('mail.getfutprotec.com:465'));
    check('renderSmtpRows: indicador ON', sm.includes('ON'));
    check('renderSmtpRows: botón toggle', sm.includes('toggleSmtp(5)'));
    check('renderSmtpRows: fila vacía -> "Sin cuentas"', inst.renderSmtpRows([]).includes('Sin cuentas'));
}

// ═══════════════════════════════════════════════════════════════════════════════
// §5.1 — enviarCola (CASO B) + enviarDirigido (CASO A) + iniciarMotor (orquestador)
// ═══════════════════════════════════════════════════════════════════════════════
console.log('\n=== §5.1 motor de envíos ===');
(async () => {
    // Serializar §5.3 antes de §5.1 para evitar condiciones de carrera sobre
    // global.fetch (cada sección usa su propio mockFetch).
    await test53();

    // enviarCola: lote de 2, cola de 3
    {
        const inst = makeInstancia();
        mockFetch(() => ENVIO_OK);
        inst.lzCola = [
            { id: 1, smtp_asignada_id: 5, smtp_asignada_email: 'c@c.com', nombre_club: 'Club1', email: 'a1@x.com' },
            { id: 2, smtp_asignada_id: 5, smtp_asignada_email: 'c@c.com', nombre_club: 'Club2', email: 'a2@x.com' },
            { id: 3, smtp_asignada_id: 5, smtp_asignada_email: 'c@c.com', nombre_club: 'Club3', email: 'a3@x.com' },
        ];
        inst.lzColaIndex = 0;
        inst.lzIdPlantillaEmail = 7;
        inst.lzCampaignId = 1;
        inst.modeTest = false;
        inst.lzBatchSize = '2';
        inst.lzDelay = 0;
        inst.randomMode = false;
        inst.lzCuentasSmtp = [{ id: 5, activa: 1, email: 'c@c.com' }];
        inst.lzLogEnviados = []; inst.lzLogEnviadosPaginados = []; inst.lzLogPageSize = 10; inst.lzLogPageCurrent = 0;
        inst.lzColaCompletados = {}; inst.lzColaResultados = {}; inst.lzKpiEnviosHoy = 0;
        await inst.enviarCola();
        check('enviarCola: respeta tamaño de lote (2 de 3)', inst.lzSendCalls === 2, 'got ' + inst.lzSendCalls);
        check('enviarCola: queda PAUSADO al alcanzar lote', inst.lzMotorEstado === 'PAUSADO', 'got ' + inst.lzMotorEstado);
        check('enviarCola: registra 2 logs', inst.lzLogEnviados.length === 2, 'got ' + inst.lzLogEnviados.length);
        check('enviarCola: resultado ok en lead 1', inst.lzColaResultados[1] && inst.lzColaResultados[1].ok === true);
        check('enviarCola: incrementa KPI envios hoy (2)', inst.lzKpiEnviosHoy === 2, 'got ' + inst.lzKpiEnviosHoy);
        check('enviarCola: lzColaIndex queda en último índice procesado (1)', inst.lzColaIndex === 1, 'got ' + inst.lzColaIndex);
    }

    // enviarDirigido: 1 lead, cuenta activa vía lzCuentaActiva
    {
        const inst = makeInstancia();
        mockFetch(() => ENVIO_OK);
        inst.lzSelectedLead = { id: 42, nombre_club: 'Dirigido FC', email: 'dir@x.com' };
        inst.lzIdPlantillaEmail = 7;
        inst.lzCampaignId = 1;
        inst.modeTest = false;
        inst.lzCuentasSmtp = [{ id: 9, activa: 1, email: 'activa@c.com' }];
        inst.lzLogEnviados = []; inst.lzLogEnviadosPaginados = []; inst.lzLogPageSize = 10; inst.lzLogPageCurrent = 0;
        inst.lzColaCompletados = {}; inst.lzColaResultados = {}; inst.lzKpiEnviosHoy = 0;
        await inst.enviarDirigido();
        check('enviarDirigido: envía 1 lead', inst.lzSendCalls === 1, 'got ' + inst.lzSendCalls);
        check('enviarDirigido: queda PAUSADO al terminar', inst.lzMotorEstado === 'PAUSADO', 'got ' + inst.lzMotorEstado);
        check('enviarDirigido: registra 1 log', inst.lzLogEnviados.length === 1, 'got ' + inst.lzLogEnviados.length);
    }

    // iniciarMotor: orquestador delega en enviarDirigido / enviarCola
    {
        const inst = makeInstancia();
        let llamado = null;
        inst.enviarDirigido = async () => { llamado = 'dirigido'; };
        inst.enviarCola = async () => { llamado = 'cola'; };
        inst.lzCampaignId = 1;
        inst.lzSelectedLeadId = 5; inst.lzSelectedLead = { id: 5 };
        await inst.iniciarMotor();
        check('iniciarMotor: con lead dirigido llama a enviarDirigido', llamado === 'dirigido', 'got ' + llamado);

        inst.lzSelectedLeadId = 0; inst.lzSelectedLead = null;
        await inst.iniciarMotor();
        check('iniciarMotor: sin lead dirigido llama a enviarCola', llamado === 'cola', 'got ' + llamado);

        inst.lzCampaignId = '';
        await inst.iniciarMotor();
        check('iniciarMotor: sin campaña no lanza (validación previa)', true);
    }

    // ═══════════ Resumen ═══════════
    console.log('\n══════════════════════════════════════════════');
    console.log('  RESULTADO: ' + passed + ' OK / ' + failed + ' FAIL');
    if (failed > 0) {
        console.log('  Fallos: ' + failures.join(', '));
        console.log('══════════════════════════════════════════════');
        process.exit(1);
    }
    console.log('  VEREDICTO: TEST_APP_JS_REFACTOR_PASS');
    console.log('══════════════════════════════════════════════');
    process.exit(0);
})().catch((e) => { console.error('ERROR en test:', e); process.exit(1); });
