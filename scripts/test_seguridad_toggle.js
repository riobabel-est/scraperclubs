// Test funcional del toggleCampo (bloque Seguridad del Panel, tabs/smtp.php).
const vm = require('vm');
const fs = require('fs');
const path = require('path');

global.window = global;
global.lucide = { createIcons: () => {} };
global.alert = () => {};
global.confirm = () => true;
global.fetch = async () => ({ ok: true, json: async () => ({ ok: true }) });

const smtpPath = path.join(__dirname, '..', 'public_html', 'outbound', 'tabs', 'smtp.php');
const c = fs.readFileSync(smtpPath, 'utf8');
const m = c.match(/<script>([\s\S]*?)<\/script>/);
if (!m) { console.error('FATAL: no <script> en smtp.php'); process.exit(1); }
vm.runInThisContext(m[1], { filename: 'smtp.js' });

const sp = seguridadPanel();

function mockBtn() {
    const eye = { classList: { toggle() {} } };
    const eyeOff = { classList: { toggle() {} } };
    return {
        title: '',
        querySelector: (s) => s === '[data-eye]' ? eye : eyeOff,
        setAttribute() {},
    };
}

let ok = true;
function check(nombre, cond, detalle) {
    if (cond) { console.log('  ✅ ' + nombre); }
    else { ok = false; console.log('  ❌ ' + nombre + (detalle ? ' — ' + detalle : '')); }
}

// Simular $refs de los 3 campos de contraseña.
const refs = {
    secPassActual: { type: 'password' }, secBtnActual: mockBtn(),
    secPassNueva: { type: 'password' }, secBtnNueva: mockBtn(),
    secPassConfirmar: { type: 'password' }, secBtnConfirmar: mockBtn(),
};
sp.$refs = refs;

// Campo 1: mostrar -> ocultar
sp.toggleCampo('secPassActual', 'secBtnActual');
check('actual: 1ra pulsación -> text (visible)', refs.secPassActual.type === 'text', 'got ' + refs.secPassActual.type);
sp.toggleCampo('secPassActual', 'secBtnActual');
check('actual: 2da pulsación -> password (oculto)', refs.secPassActual.type === 'password', 'got ' + refs.secPassActual.type);

// Campo 2
sp.toggleCampo('secPassNueva', 'secBtnNueva');
check('nueva: 1ra pulsación -> text', refs.secPassNueva.type === 'text', 'got ' + refs.secPassNueva.type);
sp.toggleCampo('secPassNueva', 'secBtnNueva');
check('nueva: 2da pulsación -> password', refs.secPassNueva.type === 'password', 'got ' + refs.secPassNueva.type);

// Campo 3
sp.toggleCampo('secPassConfirmar', 'secBtnConfirmar');
check('confirmar: 1ra pulsación -> text', refs.secPassConfirmar.type === 'text', 'got ' + refs.secPassConfirmar.type);
sp.toggleCampo('secPassConfirmar', 'secBtnConfirmar');
check('confirmar: 2da pulsación -> password', refs.secPassConfirmar.type === 'password', 'got ' + refs.secPassConfirmar.type);

// El toggle NO debe romper el x-model (la instancia sigue funcionando).
check('instancia sigue operativa (emailRecuperacion editable)', (sp.emailRecuperacion = 'x@y.com') === 'x@y.com');

console.log('\n' + (ok ? 'VEREDICTO: TEST_SEGURIDAD_TOGGLE_PASS' : 'VEREDICTO: TEST_SEGURIDAD_TOGGLE_FAIL'));
process.exit(ok ? 0 : 1);
