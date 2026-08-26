<div class="grid lg:grid-cols-2 gap-4 items-start">

    <!-- ═══════════ COLUMNA IZQUIERDA ═══════════ -->
    <div class="space-y-4">

    <!-- ═══════════ INTELIGENCIA ARTIFICIAL (MULTI-PROVEEDOR) ═══════════ -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5" x-data="configIA()" x-init="cargar()">

        <div class="flex items-center gap-3 mb-4">
            <i data-lucide="brain-circuit" class="w-5 h-5 text-violet-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Inteligencia Artificial</h5>
            <span class="text-xs text-slate-400 ml-auto">Clasificación de respuestas</span>
        </div>


        <!-- Selector de proveedor -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold text-slate-300 mb-1.5">Proveedor de IA</label>
                <select x-model="proveedor" @change="cambiarProveedor()" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
                    <option value="deepseek">DeepSeek</option>
                    <option value="openai">OpenAI (ChatGPT)</option>
                    <option value="anthropic">Anthropic (Claude)</option>
                    <option value="google">Google Gemini</option>
                    <option value="mistral">Mistral AI</option>
                    <option value="groq">Groq (Llama)</option>
                </select>
                <p class="text-xs text-slate-400 mt-1.5">Selecciona el proveedor activo para clasificar respuestas.</p>
            </div>

            <!-- API Key (toggle mostrar/ocultar robusto: x-ref + data-ia-eye/-off) -->
            <div>
                <label class="block text-sm font-semibold text-slate-300 mb-1.5" x-text="'API Key de ' + nombreProveedor"></label>
                <div class="flex gap-2">
                    <input type="password" x-model="apiKey" x-ref="iaApiKeyInput" placeholder="sk-..." autocomplete="off"
                        class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:outline-none focus:border-violet-500/50">
                    <button @click="toggleMostrar()" type="button" x-ref="iaToggleBtn"
                        class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-slate-200 hover:border-slate-600 transition"
                        :title="mostrar ? 'Ocultar API Key' : 'Mostrar API Key'">
                        <i data-lucide="eye" data-ia-eye class="w-4 h-4"></i>
                        <i data-lucide="eye-off" data-ia-eye-off class="w-4 h-4 hidden"></i>
                    </button>
                </div>
                <p class="text-xs text-slate-400 mt-1.5">Se guarda en la BD de configuración. Nunca se expone en logs ni commits.</p>
                <p class="text-xs mt-1" :class="apiKey ? 'text-emerald-400' : 'text-amber-400'"
                   x-text="apiKey ? '✓ API key configurada (' + apiKey.length + ' caracteres)' : '⚠ Sin API key configurada'"></p>
            </div>

            <!-- Modelo -->
            <div>
                <label class="block text-sm font-semibold text-slate-300 mb-1.5">Modelo / Versión</label>
                <select x-model="modelo" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
                    <template x-for="m in modelosDisponibles" :key="m.value">
                        <option :value="m.value" x-text="m.label"></option>
                    </template>
                </select>
                <p class="text-xs text-slate-400 mt-1.5" x-text="notaModelo"></p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 mt-4 pt-3 border-t border-slate-800">
            <span x-show="guardando" class="text-xs text-slate-400 flex items-center gap-1.5">
                <span class="w-3 h-3 border-2 border-violet-400 border-t-transparent rounded-full animate-spin inline-block"></span> Guardando...
            </span>
            <span x-show="guardado" class="text-xs text-emerald-400">✓ Configuración guardada</span>
            <button @click="guardar()" class="px-4 py-2 bg-violet-500/15 text-violet-300 border border-violet-500/30 rounded-lg text-sm font-semibold hover:bg-violet-500/25 transition flex items-center gap-1.5">
                <i data-lucide="save" class="w-4 h-4"></i> Guardar configuración IA
            </button>
        </div>
    </div>

    <!-- ═══════════ CUENTAS SMTP ═══════════ -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
        <div class="flex items-center gap-3 mb-4">
            <i data-lucide="server" class="w-5 h-5 text-cyan-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Cuentas SMTP</h5>
            <button @click="openSmtp(0)" class="ml-auto px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2 bg-blue-500/20 text-blue-400 border border-blue-500/30 hover:bg-blue-500/30">
                <i data-lucide="plus" class="w-4 h-4"></i> Añadir Cuenta
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider">
                        <th class="px-3 py-2 text-left font-semibold">Cuenta</th>
                        <th class="px-3 py-2 text-left hidden sm:table-cell font-semibold">Host</th>
                        <th class="px-3 py-2 text-center w-36 font-semibold">Uso Hoy</th>
                        <th class="px-3 py-2 text-center w-14 font-semibold">SMTP</th>
                        <th class="px-3 py-2 text-center w-14 font-semibold">IMAP</th>
                        <th class="px-3 py-2 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody id="smtpBody">
                    <tr><td colspan="6" class="px-3 py-8 text-center text-slate-400">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════ SEGURIDAD DEL PANEL (contraseña + email recuperación) ═══════════ -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5" x-data="seguridadPanel()" x-init="cargar()">
        <div class="flex items-center gap-3 mb-4">
            <i data-lucide="shield" class="w-5 h-5 text-emerald-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Seguridad del Panel</h5>
        </div>

        <!-- Cambiar contraseña -->
        <div class="mb-5">
            <h6 class="text-sm font-semibold text-slate-300 mb-2">Cambiar contraseña de acceso</h6>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Contraseña actual</label>
                    <div class="flex gap-2">
                        <input type="password" x-model="actual" x-ref="secPassActual" autocomplete="off"
                            class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-emerald-500/50">
                        <button type="button" x-ref="secBtnActual" @click="toggleCampo('secPassActual','secBtnActual')"
                            class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-slate-200 hover:border-slate-600 transition"
                            title="Mostrar contraseña" aria-label="Mostrar contraseña">
                            <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                            <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Nueva contraseña</label>
                        <div class="flex gap-2">
                            <input type="password" x-model="nueva" x-ref="secPassNueva" autocomplete="new-password"
                                class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-emerald-500/50" placeholder="Mínimo 8 caracteres">
                            <button type="button" x-ref="secBtnNueva" @click="toggleCampo('secPassNueva','secBtnNueva')"
                                class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-slate-200 hover:border-slate-600 transition"
                                title="Mostrar contraseña" aria-label="Mostrar contraseña">
                                <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                                <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Confirmar nueva</label>
                        <div class="flex gap-2">
                            <input type="password" x-model="confirmar" x-ref="secPassConfirmar" autocomplete="new-password"
                                class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-emerald-500/50">
                            <button type="button" x-ref="secBtnConfirmar" @click="toggleCampo('secPassConfirmar','secBtnConfirmar')"
                                class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-slate-200 hover:border-slate-600 transition"
                                title="Mostrar contraseña" aria-label="Mostrar contraseña">
                                <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                                <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button @click="cambiarPass()"
                    class="px-4 py-2 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-semibold hover:bg-emerald-500/25 transition flex items-center gap-1.5">
                    <i data-lucide="key-round" class="w-4 h-4"></i> Cambiar contraseña
                </button>
                <p x-show="msgPass" class="text-sm" :class="msgPassOk ? 'text-emerald-400' : 'text-rose-400'" x-text="msgPass"></p>
            </div>
        </div>

        <!-- Email de recuperación -->
        <div class="pt-4 border-t border-slate-800">
            <h6 class="text-sm font-semibold text-slate-300 mb-2">Email de recuperación</h6>
            <p class="text-xs text-slate-400 mb-2">A esta dirección se envía el enlace para restablecer la contraseña si la olvidas.</p>
            <div class="flex gap-2">
                <input type="email" x-model="emailRecuperacion" placeholder="contacto@ejemplo.com"
                    class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:outline-none focus:border-emerald-500/50">
                <button @click="guardarEmail()"
                    class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-300 hover:bg-slate-700 transition">
                    Guardar
                </button>
            </div>
            <p x-show="msgEmail" class="text-sm mt-2" :class="msgEmailOk ? 'text-emerald-400' : 'text-rose-400'" x-text="msgEmail"></p>
        </div>
    </div>

    </div><!-- /columna izquierda -->

    <!-- ═══════════ COLUMNA DERECHA ═══════════ -->
    <div class="space-y-4">


    <!-- ═══════════ GESTIÓN DE PRUEBAS (AISLAMIENTO TEST/REAL) ═══════════ -->

    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5" x-data="gestionPruebas()" x-init="cargarTodo()">
        <div class="flex items-center gap-3 mb-4">
            <i data-lucide="flask-conical" class="w-5 h-5 text-amber-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Gestión de Pruebas</h5>
            <span class="text-xs text-slate-400 ml-auto">Aislado del histórico comercial</span>
        </div>

        <!-- Destinatarios de prueba -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h6 class="text-sm font-semibold text-slate-300">Destinatarios de prueba</h6>
                <button @click="nuevoDestinatario()" class="px-3 py-1.5 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition flex items-center gap-1">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Añadir destinatario
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider">
                            <th class="px-3 py-2 text-left font-semibold">Email</th>
                            <th class="px-3 py-2 text-left hidden sm:table-cell font-semibold">Nombre</th>
                            <th class="px-3 py-2 text-center font-semibold">Estado</th>
                            <th class="px-3 py-2 text-right font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="d in destinatarios" :key="d.id">
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">
                                <td class="px-3 py-2 text-slate-300" x-text="d.email"></td>
                                <td class="px-3 py-2 text-slate-400 hidden sm:table-cell" x-text="d.nombre || '—'"></td>
                                <td class="px-3 py-2 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                        :class="d.activo ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700/40 text-slate-400'"
                                        x-text="d.activo ? 'Activo' : 'Inactivo'"></span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button @click="eliminarDestinatario(d.id)" class="text-rose-400 hover:text-rose-300 transition" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="destinatarios.length === 0">
                            <td colspan="4" class="px-3 py-6 text-center text-slate-400">Sin destinatarios de prueba configurados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Leads TEST -->
        <div class="mb-6">
            <h6 class="text-sm font-semibold text-slate-300 mb-3">Leads TEST</h6>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider">
                            <th class="px-3 py-2 text-left font-semibold">ID</th>
                            <th class="px-3 py-2 text-left font-semibold">Club</th>
                            <th class="px-3 py-2 text-left font-semibold">Email</th>
                            <th class="px-3 py-2 text-center font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="l in leadsTest" :key="l.id">
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">
                                <td class="px-3 py-2 text-slate-400" x-text="l.id"></td>
                                <td class="px-3 py-2 text-slate-300" x-text="l.nombre_club"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="l.email"></td>
                                <td class="px-3 py-2 text-center text-slate-400" x-text="l.estado_lead"></td>
                            </tr>
                        </template>
                        <tr x-show="leadsTest.length === 0">
                            <td colspan="4" class="px-3 py-6 text-center text-slate-400">No hay leads TEST.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Histórico de pruebas -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h6 class="text-sm font-semibold text-slate-300">Histórico de pruebas</h6>
                <span class="text-xs text-slate-400" x-text="'Total: ' + historico.length"></span>
            </div>
            <div class="overflow-x-auto max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-slate-800/50">
                        <tr class="text-slate-300 text-xs uppercase tracking-wider">
                            <th class="px-3 py-2 text-left font-semibold">ID</th>
                            <th class="px-3 py-2 text-left font-semibold">Club</th>
                            <th class="px-3 py-2 text-left font-semibold">Email</th>
                            <th class="px-3 py-2 text-left font-semibold">Fecha</th>
                            <th class="px-3 py-2 text-center font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="h in historico" :key="h.id">
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">
                                <td class="px-3 py-2 text-slate-400" x-text="h.id"></td>
                                <td class="px-3 py-2 text-slate-300" x-text="h.club"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="h.email"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="h.fecha_envio"></td>
                                <td class="px-3 py-2 text-center text-slate-400" x-text="h.estado"></td>
                            </tr>
                        </template>
                        <tr x-show="historico.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-slate-400">Sin envíos de prueba registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
            <button @click="limpiarHistorico()" class="px-4 py-2 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-sm font-semibold hover:bg-rose-500/25 transition flex items-center gap-2">
                <i data-lucide="trash" class="w-4 h-4"></i> Limpiar histórico de pruebas
            </button>
        </div>
    </div>



</div>
</div>


<script>
function configIA() {
    // Catálogo de proveedores y sus modelos rentables para clasificación de intención.
    const CATALOGO = {
        deepseek: {
            nombre: 'DeepSeek',
            claveApi: 'deepseek_api_key',
            claveModelo: 'deepseek_model',
            claveProveedor: 'ia_proveedor',
            nota: 'deepseek-chat es el más adecuado para clasificar intención en español.',
            modelos: [
                { value: 'deepseek-chat', label: 'deepseek-chat (DeepSeek-V3) — recomendado' },
                { value: 'deepseek-reasoner', label: 'deepseek-reasoner (DeepSeek-R1) — razonamiento' }
            ]
        },
        openai: {
            nombre: 'OpenAI (ChatGPT)',
            claveApi: 'openai_api_key',
            claveModelo: 'openai_model',
            claveProveedor: 'ia_proveedor',
            nota: 'gpt-4o-mini ofrece el mejor equilibrio coste/calidad para clasificación.',
            modelos: [
                { value: 'gpt-4o-mini', label: 'gpt-4o-mini — recomendado (bajo coste)' },
                { value: 'gpt-4o', label: 'gpt-4o — alta calidad' },
                { value: 'gpt-4.1-mini', label: 'gpt-4.1-mini — rápido y económico' }
            ]
        },
        anthropic: {
            nombre: 'Anthropic (Claude)',
            claveApi: 'anthropic_api_key',
            claveModelo: 'anthropic_model',
            claveProveedor: 'ia_proveedor',
            nota: 'claude-3-5-haiku es rápido y económico para clasificación.',
            modelos: [
                { value: 'claude-3-5-haiku-latest', label: 'claude-3-5-haiku — recomendado (rápido)' },
                { value: 'claude-3-5-sonnet-latest', label: 'claude-3-5-sonnet — alta calidad' }
            ]
        },
        google: {
            nombre: 'Google Gemini',
            claveApi: 'google_api_key',
            claveModelo: 'google_model',
            claveProveedor: 'ia_proveedor',
            nota: 'gemini-1.5-flash es muy económico y rápido.',
            modelos: [
                { value: 'gemini-1.5-flash', label: 'gemini-1.5-flash — recomendado (económico)' },
                { value: 'gemini-2.0-flash', label: 'gemini-2.0-flash — última generación' }
            ]
        },
        mistral: {
            nombre: 'Mistral AI',
            claveApi: 'mistral_api_key',
            claveModelo: 'mistral_model',
            claveProveedor: 'ia_proveedor',
            nota: 'mistral-small es excelente para clasificación en español.',
            modelos: [
                { value: 'mistral-small-latest', label: 'mistral-small — recomendado' },
                { value: 'mistral-medium-latest', label: 'mistral-medium — mayor capacidad' }
            ]
        },
        groq: {
            nombre: 'Groq (Llama)',
            claveApi: 'groq_api_key',
            claveModelo: 'groq_model',
            claveProveedor: 'ia_proveedor',
            nota: 'Groq ofrece inferencia ultrarrápida con modelos Llama.',
            modelos: [
                { value: 'llama-3.3-70b-versatile', label: 'llama-3.3-70b-versatile — recomendado' },
                { value: 'llama-3.1-8b-instant', label: 'llama-3.1-8b-instant — ultra rápido' }
            ]
        }
    };

    return {
        proveedor: 'deepseek',
        apiKey: '',
        _keysCache: {},
        modelo: 'deepseek-chat',
        mostrar: false,
        guardando: false,
        guardado: false,
        get nombreProveedor() { return (CATALOGO[this.proveedor] || {}).nombre || ''; },
        get modelosDisponibles() { return (CATALOGO[this.proveedor] || {}).modelos || []; },
        get notaModelo() { return (CATALOGO[this.proveedor] || {}).nota || ''; },
        async cargar() {
            try {
                const r = await fetch('?action=get_config&keys=ia_proveedor,deepseek_api_key,deepseek_model,openai_api_key,openai_model,anthropic_api_key,anthropic_model,google_api_key,google_model,mistral_api_key,mistral_model,groq_api_key,groq_model');
                const j = await r.json();
                if (j.ok && j.config) {
                    // Cache de todas las claves devueltas (get_config) para que
                    // cambiarProveedor() pueda recuperar la API key de cada proveedor.
                    this._keysCache = j.config;
                    this.proveedor = j.config.ia_proveedor || 'deepseek';
                    this.apiKey = j.config[CATALOGO[this.proveedor].claveApi] || '';
                    this.modelo = j.config[CATALOGO[this.proveedor].claveModelo] || (CATALOGO[this.proveedor].modelos[0] || {}).value || '';
                }
            } catch (e) { console.error('configIA: cargar falló', e); }
            if (window.lucide) lucide.createIcons();
        },
        cambiarProveedor() {
            // Al cambiar de proveedor, cargar su API key y modelo guardados (si existen).
            const cfg = CATALOGO[this.proveedor];
            this.apiKey = this._keysCache && this._keysCache[cfg.claveApi] ? this._keysCache[cfg.claveApi] : '';
            this.modelo = this._keysCache && this._keysCache[cfg.claveModelo] ? this._keysCache[cfg.claveModelo] : (cfg.modelos[0] || {}).value || '';
        },
        toggleMostrar() {
            this.mostrar = !this.mostrar;
            // x-ref permite encontrar el input en ambos sentidos (antes fallaba al
            // buscar 'input[type="password"]' cuando el campo ya era type=text).
            const input = this.$refs.iaApiKeyInput;
            if (input) input.type = this.mostrar ? 'text' : 'password';
            // Feedback visual: alternar iconos eye / eye-off.
            const btn = this.$refs.iaToggleBtn;
            if (btn) {
                const eye = btn.querySelector('[data-ia-eye]');
                const eyeOff = btn.querySelector('[data-ia-eye-off]');
                if (eye) eye.classList.toggle('hidden', this.mostrar);
                if (eyeOff) eyeOff.classList.toggle('hidden', !this.mostrar);
            }
        },
        async guardar() {
            this.guardando = true; this.guardado = false;
            try {
                const cfg = CATALOGO[this.proveedor];
                // Guardar proveedor activo
                const fp = new FormData(); fp.append('action', 'update_config'); fp.append('key', 'ia_proveedor'); fp.append('value', this.proveedor);
                await fetch('?action=update_config', { method: 'POST', body: fp });
                // Guardar API key y modelo del proveedor activo
                const f1 = new FormData(); f1.append('action', 'update_config'); f1.append('key', cfg.claveApi); f1.append('value', this.apiKey.trim());
                await fetch('?action=update_config', { method: 'POST', body: f1 });
                const f2 = new FormData(); f2.append('action', 'update_config'); f2.append('key', cfg.claveModelo); f2.append('value', this.modelo);
                await fetch('?action=update_config', { method: 'POST', body: f2 });
                this.guardado = true;
                setTimeout(() => { this.guardado = false; }, 2500);
            } catch (e) {
                alert('Error al guardar la configuración IA: ' + (e.message || 'Error de red'));
            } finally {
                this.guardando = false;
            }
        }
    };
}

function gestionPruebas() {
    return {
        destinatarios: [],
        leadsTest: [],
        historico: [],
        async cargarTodo() {
            await Promise.all([this.cargarDestinatarios(), this.cargarLeads(), this.cargarHistorico()]);
            if (window.lucide) lucide.createIcons();
        },
        async cargarDestinatarios() {
            const r = await fetch('?action=get_test_recipients');
            const j = await r.json();
            this.destinatarios = j.ok ? j.items : [];
        },
        async cargarLeads() {
            const r = await fetch('?action=get_test_leads');
            const j = await r.json();
            this.leadsTest = j.ok ? j.items : [];
        },
        async cargarHistorico() {
            const r = await fetch('?action=get_test_history');
            const j = await r.json();
            this.historico = j.ok ? j.items : [];
        },
        nuevoDestinatario() {
            const email = prompt('Email del destinatario de prueba:');
            if (!email) return;
            const nombre = prompt('Nombre (opcional):', '') || '';
            this.agregarDestinatario(email, nombre);
        },
        async agregarDestinatario(email, nombre) {
            const body = new URLSearchParams({ action: 'add_test_recipient', email, nombre });
            const r = await fetch('?action=add_test_recipient', { method: 'POST', body });
            const j = await r.json();
            if (j.ok) { await this.cargarDestinatarios(); if (window.lucide) lucide.createIcons(); }
            else alert(j.error || 'Error al añadir destinatario');
        },
        async eliminarDestinatario(id) {
            if (!confirm('¿Eliminar este destinatario de prueba?')) return;
            const body = new URLSearchParams({ action: 'delete_test_recipient', id });
            const r = await fetch('?action=delete_test_recipient', { method: 'POST', body });
            const j = await r.json();
            if (j.ok) await this.cargarDestinatarios();
            else alert(j.error || 'Error al eliminar');
        },
        async limpiarHistorico() {
            const confirmacion = prompt('Escribe CONFIRMAR para limpiar el histórico de pruebas. Esta acción es irreversible (se hace backup previo).');
            if (confirmacion !== 'CONFIRMAR') { alert('Operación cancelada.'); return; }
            const body = new URLSearchParams({ action: 'clear_test_history', confirm: 'CONFIRMAR' });
            const r = await fetch('?action=clear_test_history', { method: 'POST', body });
            const j = await r.json();
            if (j.ok) {
                alert('Histórico de pruebas limpiado. Backup: ' + (j.backup || ''));
                await this.cargarHistorico();
            } else {
                alert(j.error || 'Error al limpiar histórico');
            }
        }
    };
}

function seguridadPanel() {
    return {
        actual: '',
        nueva: '',
        confirmar: '',
        emailRecuperacion: '',
        msgPass: '',
        msgPassOk: false,
        msgEmail: '',
        msgEmailOk: false,
        // Toggle mostrar/ocultar de un campo de contraseña (x-ref + data-eye/-off).
        toggleCampo(ref, btnRef) {
            const input = this.$refs[ref];
            if (!input) return;
            const pasarAVisible = (input.type === 'password');
            input.type = pasarAVisible ? 'text' : 'password';
            const btn = this.$refs[btnRef];
            if (btn) {
                const eye = btn.querySelector('[data-eye]');
                const eyeOff = btn.querySelector('[data-eye-off]');
                if (eye) eye.classList.toggle('hidden', pasarAVisible);
                if (eyeOff) eyeOff.classList.toggle('hidden', !pasarAVisible);
                btn.title = pasarAVisible ? 'Ocultar contraseña' : 'Mostrar contraseña';
                btn.setAttribute('aria-label', btn.title);
            }
        },
        async cargar() {
            try {
                const r = await fetch('?action=get_config&keys=reset_email');
                const j = await r.json();
                if (j.ok && j.config) {
                    this.emailRecuperacion = j.config.reset_email || '';
                }
            } catch (e) { /* silencioso */ }
        },
        async cambiarPass() {
            this.msgPass = '';
            if (this.nueva.length < 8) { this.msgPass = 'La nueva contraseña debe tener al menos 8 caracteres.'; this.msgPassOk = false; return; }
            if (this.nueva !== this.confirmar) { this.msgPass = 'Las contraseñas no coinciden.'; this.msgPassOk = false; return; }
            const f = new FormData();
            f.append('action', 'change_password');
            f.append('password_actual', this.actual);
            f.append('password_nueva', this.nueva);
            f.append('password_confirmar', this.confirmar);
            try {
                const r = await fetch('?action=change_password', { method: 'POST', body: f });
                const j = await r.json();
                this.msgPass = j.message || (j.ok ? 'Contraseña actualizada.' : 'Error al cambiar la contraseña.');
                this.msgPassOk = !!j.ok;
                if (j.ok) { this.actual = ''; this.nueva = ''; this.confirmar = ''; }
            } catch (e) {
                this.msgPass = 'Error de conexión al cambiar la contraseña.';
                this.msgPassOk = false;
            }
        },
        async guardarEmail() {
            this.msgEmail = '';
            const f = new FormData();
            f.append('action', 'update_reset_email');
            f.append('reset_email', this.emailRecuperacion);
            try {
                const r = await fetch('?action=update_reset_email', { method: 'POST', body: f });
                const j = await r.json();
                this.msgEmail = j.message || (j.ok ? 'Email actualizado.' : 'Error al guardar el email.');
                this.msgEmailOk = !!j.ok;
            } catch (e) {
                this.msgEmail = 'Error de conexión.';
                this.msgEmailOk = false;
            }
        }
    };
}
</script>
