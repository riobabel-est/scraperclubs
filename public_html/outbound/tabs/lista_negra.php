<div class="space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <h5 class="text-sm font-semibold text-slate-300">Lista Negra</h5>
        <span class="text-xs text-slate-500">Gestión manual de supresión (opt-out real y bloqueo manual)</span>
    </div>

    <!-- ─── BÚSQUEDA DE LEAD ─────────────────────────────────────────────── -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Buscar lead para añadir a Lista Negra</div>
        <div class="flex gap-2 flex-wrap">
            <input type="text" x-model="blSearch" @keyup.enter="blBuscar()" placeholder="Nombre, email o ID..."
                class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-300 w-64 focus:outline-none focus:border-amber-500/50">
            <button @click="blBuscar()" class="px-4 py-2 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/25 transition flex items-center gap-1">
                <i data-lucide="search" class="w-4 h-4"></i> Buscar
            </button>
        </div>

        <!-- Resultados de búsqueda -->
        <div x-show="blResults.length > 0" x-cloak class="mt-3">
            <div class="text-xs text-slate-500 mb-2" x-text="blResults.length + ' resultado(s)'"></div>
            <div class="space-y-2">
                <template x-for="r in blResults" :key="r.id">
                    <div class="flex items-center justify-between bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2">
                        <div class="min-w-0">
                            <div class="text-sm text-slate-200 font-medium truncate" x-text="r.nombre_club"></div>
                            <div class="text-xs text-slate-500 truncate" x-text="r.email + ' · ' + (r.federacion || '')"></div>
                            <div class="text-xs mt-0.5">
                                <span class="text-slate-500" x-text="'Estado: ' + r.estado_lead"></span>
                                <span x-show="r.es_test" class="ml-2 text-amber-400">TEST</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <template x-if="!blEsSuprimido(r)">
                                <button @click="blAdd(r)" class="px-3 py-1.5 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-semibold hover:bg-rose-500/25 transition flex items-center gap-1">
                                    <i data-lucide="ban" class="w-3.5 h-3.5"></i> Añadir a Lista Negra
                                </button>
                            </template>
                            <template x-if="blEsSuprimido(r)">
                                <span class="text-xs text-slate-500">Ya suprimido</span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <div x-show="blSearchMsg" x-cloak class="mt-3 text-xs" :class="blSearchMsgOk ? 'text-emerald-400' : 'text-rose-400'" x-text="blSearchMsg"></div>
    </div>

    <!-- ─── LISTADO DE LEADS EN LISTA NEGRA ──────────────────────────────── -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Leads en Lista Negra</div>
            <button @click="blCargar()" class="px-3 py-1.5 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:bg-slate-700 transition flex items-center gap-1">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Recargar
            </button>
        </div>

        <div x-show="blList.length === 0 && !blLoading" x-cloak class="text-center text-slate-600 text-sm py-8">No hay leads en Lista Negra.</div>
        <div x-show="blLoading" x-cloak class="text-center text-slate-600 text-sm py-8">Cargando...</div>

        <div x-show="blList.length > 0" x-cloak class="overflow-x-auto rounded-lg border border-slate-800">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-800/50 text-slate-400 text-xs uppercase tracking-wider">
                        <th class="px-3 py-2 text-left">Club</th>
                        <th class="px-3 py-2 text-left hidden md:table-cell">Email</th>
                        <th class="px-3 py-2 text-left">Tipo de supresión</th>
                        <th class="px-3 py-2 text-left hidden lg:table-cell">Motivo / Fuente</th>
                        <th class="px-3 py-2 text-left hidden lg:table-cell">Fecha</th>
                        <th class="px-3 py-2 text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in blList" :key="l.id">
                        <tr class="border-t border-slate-800">
                            <td class="px-3 py-2 text-slate-200 font-medium" x-text="l.nombre_club"></td>
                            <td class="px-3 py-2 text-slate-400 hidden md:table-cell" x-text="l.email"></td>
                            <td class="px-3 py-2">
                                <span x-show="l.tipo === 'optout_real'" class="inline-flex items-center gap-1 text-rose-400 text-xs font-semibold">
                                    <i data-lucide="shield-alert" class="w-3.5 h-3.5"></i> 🔴 Baja solicitada por destinatario
                                </span>
                                <span x-show="l.tipo === 'bloqueo_manual'" class="inline-flex items-center gap-1 text-amber-400 text-xs font-semibold">
                                    <i data-lucide="user-x" class="w-3.5 h-3.5"></i> 🟠 Bloqueo manual
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500 hidden lg:table-cell" x-text="l.motivo"></td>
                            <td class="px-3 py-2 text-xs text-slate-500 hidden lg:table-cell" x-text="l.fecha"></td>
                            <td class="px-3 py-2 text-right">
                                <template x-if="l.tipo === 'bloqueo_manual'">
                                    <button @click="blRemove(l)" class="px-3 py-1.5 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/25 transition flex items-center gap-1 ml-auto">
                                        <i data-lucide="user-check" class="w-3.5 h-3.5"></i> Quitar bloqueo manual
                                    </button>
                                </template>
                                <template x-if="l.tipo === 'optout_real'">
                                    <span class="text-xs text-slate-600" title="Opt-out real: no reactivable por vía rutinaria">Protegido</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="blMsg" x-cloak class="mt-3 text-xs" :class="blMsgOk ? 'text-emerald-400' : 'text-rose-400'" x-text="blMsg"></div>
    </div>
</div>
