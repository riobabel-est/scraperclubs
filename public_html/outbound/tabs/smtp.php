<div class="space-y-8">

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
