<!-- ═══════════ SEGUIMIENTO — Cola de trabajo + KPIs + Embudo ═══════════════ -->
<!-- Módulo rediseñado (ex followups.php). Plan: docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md -->
<div x-data="seguimientoApp()" x-init="load()" class="space-y-6">

    <!-- 1. SCORECARDS (KPIs inteligibles) -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3">
        <div class="bg-slate-900 border border-rose-500/20 rounded-xl p-4 cursor-pointer hover:bg-slate-800/60 transition" @click="cola = 'perseguir'" title="Ver cola Perseguir">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">No Respondedores</span><i data-lucide="mail-question" class="w-4 h-4 text-rose-400"></i></div>
            <div class="text-2xl font-bold text-rose-400 mt-1" x-text="kpis.no_respondedores"></div>
            <div class="text-xs text-slate-400 mt-1">Perseguir 2º toque</div>
        </div>
        <div class="bg-slate-900 border border-amber-500/20 rounded-xl p-4 cursor-pointer hover:bg-slate-800/60 transition" @click="cola = 'avanzar'" title="Ver cola Avanzar">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Sin Prox. Acción</span><i data-lucide="alarm-clock" class="w-4 h-4 text-amber-400"></i></div>
            <div class="text-2xl font-bold text-amber-400 mt-1" x-text="kpis.sin_proxima_accion"></div>
            <div class="text-xs text-slate-400 mt-1">Calientes parados</div>
        </div>
        <div class="bg-slate-900 border border-indigo-500/20 rounded-xl p-4 cursor-pointer hover:bg-slate-800/60 transition" @click="cola = 'calentar'" title="Ver cola Calentar">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Nuevos sin Actividad</span><i data-lucide="flame" class="w-4 h-4 text-indigo-400"></i></div>
            <div class="text-2xl font-bold text-indigo-400 mt-1" x-text="kpis.nuevos_sin_actividad"></div>
            <div class="text-xs text-slate-400 mt-1">1er toque pendiente</div>
        </div>
        <div class="bg-slate-900 border border-cyan-500/20 rounded-xl p-4">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Tasa Apertura</span><i data-lucide="eye" class="w-4 h-4 text-cyan-400"></i></div>
            <div class="text-2xl font-bold text-cyan-400 mt-1" x-text="kpis.tasa_apertura + '%'"></div>
            <div class="text-xs text-slate-400 mt-1">Abrieron / entregados</div>
        </div>
        <div class="bg-slate-900 border border-emerald-500/20 rounded-xl p-4">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Tasa Respuesta</span><i data-lucide="message-square" class="w-4 h-4 text-emerald-400"></i></div>
            <div class="text-2xl font-bold text-emerald-400 mt-1" x-text="kpis.tasa_respuesta + '%'"></div>
            <div class="text-xs text-slate-400 mt-1">Respondieron / contactados</div>
        </div>
        <div class="bg-slate-900 border border-indigo-500/20 rounded-xl p-4">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Mockups Pend.</span><i data-lucide="palette" class="w-4 h-4 text-indigo-400"></i></div>
            <div class="text-2xl font-bold text-indigo-400 mt-1" x-text="kpis.mockups_pendientes"></div>
            <div class="text-xs text-slate-400 mt-1">Solicitados / en prod.</div>
        </div>
        <div class="bg-slate-900 border border-sky-500/20 rounded-xl p-4">
            <div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Pipeline en Juego</span><i data-lucide="banknote" class="w-4 h-4 text-sky-400"></i></div>
            <div class="text-2xl font-bold text-sky-400 mt-1" x-text="kpis.pipeline_value ? kpis.pipeline_value.toLocaleString('es-ES') + '€' : '0€'"></div>
            <div class="text-xs text-slate-400 mt-1">Presupuestos activos</div>
        </div>
    </div>

    <!-- 2. EMBUDO DE CONVERSIÓN (5 etapas) -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
        <div class="flex items-center gap-2 mb-3">
            <i data-lucide="trending-down" class="w-4 h-4 text-amber-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Embudo de Conversión</h5>
            <span class="text-xs text-slate-400 ml-auto">% = conversión a la siguiente etapa</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
            <template x-for="(et, i) in funnel" :key="et.etapa">
                <div class="bg-slate-800/40 border border-slate-700 rounded-lg p-2.5 text-center cursor-pointer hover:border-amber-500/40 hover:bg-slate-800/60 transition"
                    @click="irAEstado(et.etapa)" :title="'Ver leads: ' + et.etapa">
                    <div class="text-lg font-bold text-slate-100" x-text="et.cnt"></div>
                    <div class="text-xs text-slate-400 truncate" x-text="et.etapa.replace(/^\d+\s/, '')"></div>
                    <div class="text-sm font-semibold mt-0.5" x-show="et.pct !== null"
                        :class="i < funnel.length - 1 && et.pct !== null && et.pct >= 50 ? 'text-emerald-400' : (i < funnel.length - 1 ? 'text-amber-400' : 'text-slate-500')"
                        x-text="i < funnel.length - 1 ? '↓ ' + et.pct + '%' : '—'"></div>
                </div>
            </template>
        </div>
    </div>

    <!-- 3. FILTROS -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[220px] flex-1">
            <label class="block text-sm font-semibold text-slate-300 mb-1.5">Buscar club o email</label>
            <input type="text" x-model="f.busqueda" @input.debounce.300ms="load()" placeholder="Buscar..."
                class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        </div>
        <div>
            <label class="block text-sm font-semibold text-slate-300 mb-1.5">Federación</label>
            <select x-model="f.federacion" @change="load()"
                class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                <option value="">Todas las federaciones</option>
                <template x-for="fed in federaciones" :key="fed"><option :value="fed" x-text="fed"></option></template>
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-slate-300 mb-1.5">Días sin contacto</label>
            <select x-model="f.dias_min" @change="load()"
                class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                <option :value="0">Cualquier antigüedad</option>
                <option :value="3">≥ 3 días</option>
                <option :value="7">≥ 7 días</option>
                <option :value="14">≥ 14 días</option>
            </select>
        </div>
        <label class="flex items-center gap-2 pb-2 cursor-pointer">
            <input type="checkbox" x-model="f.solo_alta" @change="load()" class="accent-amber-500">
            <span class="text-sm text-slate-300">Solo prioridad alta</span>
        </label>

    <!-- 4. COLA DE TRABAJO -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="flex border-b border-slate-800">
            <button @click="cola = 'perseguir'" type="button"
                class="flex items-center gap-2 px-4 py-3 text-sm font-semibold transition border-b-2"
                :class="cola === 'perseguir' ? 'border-amber-400 text-amber-400 bg-slate-950/40' : 'border-transparent text-slate-400 hover:text-slate-200'">
                <i data-lucide="mail-question" class="w-4 h-4"></i> Perseguir
                <span class="px-1.5 py-0.5 rounded-full text-xs font-bold bg-rose-500/15 text-rose-400" x-text="kpis.no_respondedores"></span>
            </button>
            <button @click="cola = 'avanzar'" type="button"
                class="flex items-center gap-2 px-4 py-3 text-sm font-semibold transition border-b-2"
                :class="cola === 'avanzar' ? 'border-amber-400 text-amber-400 bg-slate-950/40' : 'border-transparent text-slate-400 hover:text-slate-200'">
                <i data-lucide="alarm-clock" class="w-4 h-4"></i> Avanzar
                <span class="px-1.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/15 text-amber-400" x-text="kpis.sin_proxima_accion"></span>
            </button>
            <button @click="cola = 'calentar'" type="button"
                class="flex items-center gap-2 px-4 py-3 text-sm font-semibold transition border-b-2"
                :class="cola === 'calentar' ? 'border-amber-400 text-amber-400 bg-slate-950/40' : 'border-transparent text-slate-400 hover:text-slate-200'">
                <i data-lucide="flame" class="w-4 h-4"></i> Calentar
                <span class="px-1.5 py-0.5 rounded-full text-xs font-bold bg-indigo-500/15 text-indigo-400" x-text="kpis.nuevos_sin_actividad"></span>
            </button>
        </div>

        <!-- Cola A: PERSIGUIR (no respondedores) -->
        <div x-show="cola === 'perseguir'" class="p-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <th class="px-2 py-2 text-left">Prioridad</th>
                        <th class="px-2 py-2 text-left">Club</th>
                        <th class="px-2 py-2 text-left hidden md:table-cell">Contacto</th>
                        <th class="px-2 py-2 text-left hidden lg:table-cell">Último envío</th>
                        <th class="px-2 py-2 text-center">Apert.</th>
                        <th class="px-2 py-2 text-right">Envíos</th>
                        <th class="px-2 py-2 text-right">Días</th>
                        <th class="px-2 py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in noRespondedores" :key="l.id">
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                            <td class="px-2 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap"
                                    :class="l.prioridad === 'Alta' ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30' : (l.prioridad === 'Media' ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600')"
                                    x-text="l.prioridad"></span>
                            </td>
                            <td class="px-2 py-2">
                                <div class="text-slate-200 font-semibold" x-text="l.nombre_club"></div>
                                <div class="text-xs text-slate-400" x-text="l.email"></div>
                            </td>
                            <td class="px-2 py-2 text-slate-400 hidden md:table-cell" x-text="l.persona_contacto || '—'"></td>
                            <td class="px-2 py-2 hidden lg:table-cell">
                                <div class="text-xs text-slate-300 max-w-[220px] truncate" x-text="l.ultimo_asunto || '—'"></div>
                                <div class="text-xs text-slate-500" x-text="l.ultimo_envio ? l.ultimo_envio.slice(0,10) : '—'"></div>
                            </td>
                            <td class="px-2 py-2 text-center">
                                <span class="inline-block w-2.5 h-2.5 rounded-full" :class="l.tiene_apertura ? 'bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.6)]' : 'bg-slate-600'"
                                    :title="l.tiene_apertura ? 'Ha abierto el envío' : 'No ha abierto'"></span>
                            </td>
                            <td class="px-2 py-2 text-right text-slate-400" x-text="l.num_envios"></td>
                            <td class="px-2 py-2 text-right">
                                <span class="px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                                    :class="l.dias_desde_envio !== null && l.dias_desde_envio > 7 ? 'bg-rose-500/15 text-rose-400' : 'bg-slate-800 text-slate-400'"
                                    x-text="l.dias_desde_envio !== null ? l.dias_desde_envio : '—'"></span>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button @click="perseguir(l)" class="px-2.5 py-1.5 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition mr-1">Enviar</button>
                                <button @click="openFicha(l.id)" class="px-2.5 py-1.5 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:text-slate-100 transition">Ficha</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="noRespondedores.length === 0"><td colspan="8" class="px-2 py-8 text-center text-slate-400">No hay leads no respondedores con los filtros actuales.</td></tr>
                </tbody>
            </table>
        </div>

        <button @click="load()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1.5">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i> Refrescar
        </button>
    </div>


        <!-- Cola B: AVANZAR (sin próxima acción) -->
        <div x-show="cola === 'avanzar'" class="p-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <th class="px-2 py-2 text-left">Prioridad</th>
                        <th class="px-2 py-2 text-left">Club</th>
                        <th class="px-2 py-2 text-left hidden md:table-cell">Estado</th>
                        <th class="px-2 py-2 text-right">Volumen</th>
                        <th class="px-2 py-2 text-right">Presupuesto</th>
                        <th class="px-2 py-2 text-right">Días sin cont.</th>
                        <th class="px-2 py-2 text-right">Próx. acción</th>
                        <th class="px-2 py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in sinProximaAccion" :key="l.id">
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                            <td class="px-2 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap"
                                    :class="l.prioridad === 'Alta' ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30' : (l.prioridad === 'Media' ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600')"
                                    x-text="l.prioridad"></span>
                            </td>
                            <td class="px-2 py-2">
                                <div class="text-slate-200 font-semibold" x-text="l.nombre_club"></div>
                                <div class="text-xs text-slate-400" x-text="l.email"></div>
                            </td>
                            <td class="px-2 py-2 hidden md:table-cell">
                                <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/30" x-text="l.estado_lead"></span>
                            </td>
                            <td class="px-2 py-2 text-right text-slate-400" x-text="l.volumen_estimado ? l.volumen_estimado + ' uds' : '—'"></td>
                            <td class="px-2 py-2 text-right" :class="l.presupuesto_importe ? 'text-emerald-400 font-semibold' : 'text-slate-500'"
                                x-text="l.presupuesto_importe ? l.presupuesto_importe.toLocaleString('es-ES') + '€' : '—'"></td>
                            <td class="px-2 py-2 text-right">
                                <span class="px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                                    :class="l.dias_desde_contacto !== null && l.dias_desde_contacto > 7 ? 'bg-rose-500/15 text-rose-400' : 'bg-slate-800 text-slate-400'"
                                    x-text="l.dias_desde_contacto !== null ? l.dias_desde_contacto : '—'"></span>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <template x-if="!l.programando">
                                    <span class="px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                                        :class="l.vencida ? 'bg-rose-500/15 text-rose-400' : (l.fecha_proxima_accion ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-800 text-slate-400')"
                                        x-text="l.vencida ? ('Vencida ' + l.dias_vencida + 'd') : (l.fecha_proxima_accion ? l.fecha_proxima_accion.slice(0,10) : 'Sin fecha')"></span>
                                    <button @click="l.programando = true" class="px-2 py-1 ml-1 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs hover:text-amber-400 transition" title="Programar próxima acción">⏱</button>
                                </template>
                                <template x-if="l.programando">
                                    <span class="inline-flex items-center gap-1.5">
                                        <input type="date" x-model="l.nuevaFecha" class="bg-slate-950 border border-slate-700 rounded px-2 py-1 text-xs text-slate-200">
                                        <button @click="guardarFecha(l)" class="px-2 py-1 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold">OK</button>
                                        <button @click="l.programando = false" class="px-2 py-1 text-xs text-slate-400 hover:text-slate-200">✕</button>
                                    </span>
                                </template>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button @click="openFicha(l.id)" class="px-2.5 py-1.5 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:text-slate-100 transition">Ficha</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="sinProximaAccion.length === 0"><td colspan="8" class="px-2 py-8 text-center text-slate-400">Todos los leads en conversación tienen próxima acción definida.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Cola C: CALENTAR (nuevos sin actividad) -->
        <div x-show="cola === 'calentar'" class="p-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <th class="px-2 py-2 text-left">Prioridad</th>
                        <th class="px-2 py-2 text-left">Club</th>
                        <th class="px-2 py-2 text-left hidden md:table-cell">Estado</th>
                        <th class="px-2 py-2 text-right hidden lg:table-cell">Volumen</th>
                        <th class="px-2 py-2 text-right">Días creado</th>
                        <th class="px-2 py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in nuevosSinActividad" :key="l.id">
                        <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
                            <td class="px-2 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap"
                                    :class="l.prioridad === 'Alta' ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30' : (l.prioridad === 'Media' ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30' : 'bg-slate-700 text-slate-400 border border-slate-600')"
                                    x-text="l.prioridad"></span>
                            </td>
                            <td class="px-2 py-2">
                                <div class="text-slate-200 font-semibold" x-text="l.nombre_club"></div>
                                <div class="text-xs text-slate-400" x-text="l.email"></div>
                            </td>
                            <td class="px-2 py-2 hidden md:table-cell">
                                <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/30" x-text="l.estado_lead"></span>
                            </td>
                            <td class="px-2 py-2 text-right text-slate-400 hidden lg:table-cell" x-text="l.volumen_estimado ? l.volumen_estimado + ' uds' : '—'"></td>
                            <td class="px-2 py-2 text-right">
                                <span class="px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                                    :class="l.dias_desde_creado !== null && l.dias_desde_creado >= 3 ? 'bg-rose-500/15 text-rose-400' : 'bg-slate-800 text-slate-400'"
                                    x-text="l.dias_desde_creado !== null ? l.dias_desde_creado : '—'"></span>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button @click="perseguir(l)" class="px-2.5 py-1.5 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition mr-1">Enviar</button>
                                <button @click="openFicha(l.id)" class="px-2.5 py-1.5 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:text-slate-100 transition">Ficha</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="nuevosSinActividad.length === 0"><td colspan="6" class="px-2 py-8 text-center text-slate-400">No hay leads nuevos sin actividad. ¡Buen trabajo!</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <p x-show="cargando" class="text-sm text-slate-400 text-center py-4">Cargando seguimiento…</p>
    <p x-show="!cargando && error" class="text-sm text-rose-400 text-center py-4" x-text="error"></p>
</div>

