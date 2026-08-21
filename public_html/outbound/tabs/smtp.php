<div class="space-y-8">

    <!-- ═══════════ INTELIGENCIA ARTIFICIAL (MULTI-PROVEEDOR) ═══════════ -->
    <div class="rounded-xl border border-violet-500/30 bg-slate-900/60 p-4" x-data="configIA()" x-init="cargar()">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i data-lucide="brain-circuit" class="w-4 h-4 text-violet-400"></i>
                <h5 class="text-sm font-semibold text-violet-300">INTELIGENCIA ARTIFICIAL</h5>
            </div>
            <span class="text-[10px] uppercase tracking-wider text-slate-500">Clasificación de respuestas</span>
        </div>

        <!-- Selector de proveedor -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Proveedor de IA</label>
                <select x-model="proveedor" @change="cambiarProveedor()" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
                    <option value="deepseek">DeepSeek</option>
                    <option value="openai">OpenAI (ChatGPT)</option>
                    <option value="anthropic">Anthropic (Claude)</option>
                    <option value="google">Google Gemini</option>
                    <option value="mistral">Mistral AI</option>
                    <option value="groq">Groq (Llama)</option>
                </select>
                <p class="text-[11px] text-slate-500 mt-1.5">Selecciona el proveedor activo para clasificar respuestas.</p>
            </div>

            <!-- API Key -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5" x-text="'API Key de ' + nombreProveedor"></label>
                <div class="flex gap-2">
                    <input type="password" x-model="apiKey" placeholder="sk-..." autocomplete="off"
                        class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:outline-none focus:border-violet-500/50">
                    <button @click="toggleMostrar()" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:bg-slate-700 transition" title="Mostrar/ocultar">
                        <i data-lucide="eye" class="w-4 h-4"></i>
                    </button>
                </div>
                <p class="text-[11px] text-slate-500 mt-1.5">Se guarda en la BD de configuración. Nunca se expone en logs ni commits.</p>
            </div>

            <!-- Modelo -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Modelo / Versión</label>
                <select x-model="modelo" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
                    <template x-for="m in modelosDisponibles" :key="m.value">
                        <option :value="m.value" x-text="m.label"></option>
                    </template>
                </select>
                <p class="text-[11px] text-slate-500 mt-1.5" x-text="notaModelo"></p>
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
    <div>
        <div class="flex items-center justify-between mb-3">
            <h5 class="text-sm font-semibold text-slate-300">CUENTAS SMTP</h5>
            <button @click="openSmtp(0)" class="px-3 py-1.5 bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/25 transition flex items-center gap-1">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Anadir Cuenta
            </button>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-slate-900 text-slate-400 text-[10px] uppercase tracking-wider">
                        <th class="px-3 py-2 text-left">Emisor</th>
                        <th class="px-3 py-2 text-left hidden sm:table-cell">Host</th>
                        <th class="px-3 py-2 text-center">Envios / Limite</th>
                        <th class="px-3 py-2 text-center">Estado</th>
                        <th class="px-3 py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody id="smtpBody">
                    <tr><td colspan="5" class="px-3 py-8 text-center text-slate-600">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════ GESTIÓN DE PRUEBAS (AISLAMIENTO TEST/REAL) ═══════════ -->
    <div class="rounded-xl border border-amber-500/30 bg-slate-900/60 p-4" x-data="gestionPruebas()" x-init="cargarTodo()">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <i data-lucide="flask-conical" class="w-4 h-4 text-amber-400"></i>
                <h5 class="text-sm font-semibold text-amber-300">GESTIÓN DE PRUEBAS</h5>
            </div>
            <span class="text-[10px] uppercase tracking-wider text-slate-500">Aislado del histórico comercial</span>
        </div>

        <!-- Destinatarios de prueba -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <h6 class="text-xs font-semibold text-slate-300">Destinatarios de prueba</h6>
                <button @click="nuevoDestinatario()" class="px-2.5 py-1 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition flex items-center gap-1">
                    <i data-lucide="plus" class="w-3 h-3"></i> Añadir destinatario
                </button>
            </div>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-900 text-slate-400 text-[10px] uppercase tracking-wider">
                            <th class="px-3 py-2 text-left">Email</th>
                            <th class="px-3 py-2 text-left hidden sm:table-cell">Nombre</th>
                            <th class="px-3 py-2 text-center">Estado</th>
                            <th class="px-3 py-2 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="d in destinatarios" :key="d.id">
                            <tr class="border-t border-slate-800/60">
                                <td class="px-3 py-2 text-slate-300" x-text="d.email"></td>
                                <td class="px-3 py-2 text-slate-400 hidden sm:table-cell" x-text="d.nombre || '—'"></td>
                                <td class="px-3 py-2 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                        :class="d.activo ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700/40 text-slate-500'"
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
                            <td colspan="4" class="px-3 py-6 text-center text-slate-600">Sin destinatarios de prueba configurados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Leads TEST -->
        <div class="mb-6">
            <h6 class="text-xs font-semibold text-slate-300 mb-2">Leads TEST</h6>
            <div class="overflow-x-auto rounded-lg border border-slate-800">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="bg-slate-900 text-slate-400 text-[10px] uppercase tracking-wider">
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">Club</th>
                            <th class="px-3 py-2 text-left">Email</th>
                            <th class="px-3 py-2 text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="l in leadsTest" :key="l.id">
                            <tr class="border-t border-slate-800/60">
                                <td class="px-3 py-2 text-slate-500" x-text="l.id"></td>
                                <td class="px-3 py-2 text-slate-300" x-text="l.nombre_club"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="l.email"></td>
                                <td class="px-3 py-2 text-center text-slate-400" x-text="l.estado_lead"></td>
                            </tr>
                        </template>
                        <tr x-show="leadsTest.length === 0">
                            <td colspan="4" class="px-3 py-6 text-center text-slate-600">No hay leads TEST.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Histórico de pruebas -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <h6 class="text-xs font-semibold text-slate-300">Histórico de pruebas</h6>
                <span class="text-[10px] text-slate-500" x-text="'Total: ' + historico.length"></span>
            </div>
            <div class="overflow-x-auto rounded-lg border border-slate-800 max-h-64 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-900">
                        <tr class="text-slate-400 text-[10px] uppercase tracking-wider">
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">Club</th>
                            <th class="px-3 py-2 text-left">Email</th>
                            <th class="px-3 py-2 text-left">Fecha</th>
                            <th class="px-3 py-2 text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="h in historico" :key="h.id">
                            <tr class="border-t border-slate-800/60">
                                <td class="px-3 py-2 text-slate-500" x-text="h.id"></td>
                                <td class="px-3 py-2 text-slate-300" x-text="h.club"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="h.email"></td>
                                <td class="px-3 py-2 text-slate-400" x-text="h.fecha_envio"></td>
                                <td class="px-3 py-2 text-center text-slate-400" x-text="h.estado"></td>
                            </tr>
                        </template>
                        <tr x-show="historico.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-slate-600">Sin envíos de prueba registrados.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
            <button @click="limpiarHistorico()" class="px-3 py-2 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-semibold hover:bg-rose-500/25 transition flex items-center gap-1">
                <i data-lucide="trash" class="w-3.5 h-3.5"></i> Limpiar histórico de pruebas
            </button>
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
            const input = this.$el.querySelector('input[type="password"]');
            if (input) input.type = this.mostrar ? 'text' : 'password';
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
</script>
