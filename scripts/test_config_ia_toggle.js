// Test funcional local del toggle API Key IA (tabs/smtp.php, configIA()).
const vm = require('vm');
const fs = require('fs');
const path = require('path');

global.window = global;
global.lucide = { createIcons: () => {} };
global.alert = () => {};
global.confirm = () => true;
global.fetch = async () => ({
    ok: true,
    json: async () => ({ ok: true, config: { ia_proveedor: 'deepseek', deepseek_api_key: 'sk-test-123456', deepseek_model: 'deepseek-chat' } }),
});

const smtpPath = path.join(__dirname, '..', 'public_html', 'outbound', 'tabs', 'smtp.php');
const c = fs.readFileSync(smtpPath, 'utf8');
const m = c.match(/<script>([\s\S]*?)<\/script>/);
if (!m) { console.error('FATAL: no <script> en smtp.php'); process.exit(1); }
vm.runInThisContext(m[1], { filename: 'smtp.js' });

const ia = configIA();
const eye = { classList: { toggle() {} } };
const eyeOff = { classList: { toggle() {} } };
// Simular $refs de Alpine (se inyectan en runtime).
ia.$refs = {
    iaApiKeyInput: { type: 'password' },
    iaToggleBtn: { querySelector: (s) => s === '[data-ia-eye]' ? eye : eyeOff },
};

let ok = true;
function check(nombre, cond, detalle) {
    if (cond) { console.log('  ✅ ' + nombre); }
    else { ok = false; console.log('  ❌ ' + nombre + (detalle ? ' — ' + detalle : '')); }
}

// Toggle mostrar -> ocultar (doble sentido).
ia.toggleMostrar();
check('toggle 1: muestra la API key (type=text)', ia.$refs.iaApiKeyInput.type === 'text', 'got ' + ia.$refs.iaApiKeyInput.type);
check('toggle 1: mostrar=true', ia.mostrar === true, 'got ' + ia.mostrar);
ia.toggleMostrar();
check('toggle 2: vuelve a ocultar (type=password)', ia.$refs.iaApiKeyInput.type === 'password', 'got ' + ia.$refs.iaApiKeyInput.type);
check('toggle 2: mostrar=false', ia.mostrar === false, 'got ' + ia.mostrar);

// cambiarProveedor con _keysCache relleno.
ia._keysCache = { ia_proveedor: 'deepseek', deepseek_api_key: 'sk-AAA', deepseek_model: 'deepseek-chat', openai_api_key: 'sk-BBB', openai_model: 'gpt-4o-mini' };
ia.proveedor = 'deepseek'; ia.cambiarProveedor();
check('cambiarProveedor deepseek -> sk-AAA', ia.apiKey === 'sk-AAA', 'got ' + ia.apiKey);
ia.proveedor = 'openai'; ia.cambiarProveedor();
check('cambiarProveedor openai -> sk-BBB', ia.apiKey === 'sk-BBB', 'got ' + ia.apiKey);

// cargar() rellena _keysCache y apiKey del proveedor activo.
(async () => {
    ia._keysCache = {};
    await ia.cargar();
    check('cargar() apiKey = sk-test-123456', ia.apiKey === 'sk-test-123456', 'got ' + ia.apiKey);
    check('cargar() rellena _keysCache', ia._keysCache.deepseek_api_key === 'sk-test-123456', 'got ' + JSON.stringify(ia._keysCache));
    console.log('\n' + (ok ? 'VEREDICTO: TEST_CONFIG_IA_TOGGLE_PASS' : 'VEREDICTO: TEST_CONFIG_IA_TOGGLE_FAIL'));
    process.exit(ok ? 0 : 1);
})();
