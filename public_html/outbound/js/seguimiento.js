// seguimiento.js — seguimientoApp (Alpine). Extraido de app.js (refactor modular 2026-08-26).
// Depende de app() en runtime (window.app, window._campanaActual).

// ─── Seguimiento (módulo rediseñado ex-followups). Plan: docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md
function seguimientoApp() {
    return {
        noRespondedores: [], sinProximaAccion: [], nuevosSinActividad: [], funnel: [],
        kpis: { no_respondedores: 0, sin_proxima_accion: 0, tasa_apertura: 0, tasa_respuesta: 0, mockups_pendientes: 0, presupuestos_pendientes: 0, pipeline_value: 0 },
        federaciones: [],
        f: { busqueda: '', federacion: '', dias_min: 0, solo_alta: false },
        cola: 'todos', colaUnificada: [], cargando: false, error: '',
        sortKey: 'sem', sortDir: 'asc',
        filtroPrioridad: '', filtroEstado: '', filtroVariante: '',
        pagina: 1, paginaSize: 50,
        propuestas: [],
        estadosPipeline: ['01 Sin Contactar', '02 Contactado', '03 En Conversación', '04 Propuesta', '05 Ganado', '06 Perdido', '07 Baja'],
        async load() {
            if (window) window._segApp = this; // expone la instancia para irAAtender (tab Inicio)
            if (window.app && Array.isArray(window.app.federaciones)) this.federaciones = window.app.federaciones;
            this.cargando = true; this.error = '';
            try {
                const params = new URLSearchParams();
                if (window.app && window.app.campanaActual > 0) params.append('campaign_id', window.app.campanaActual);
                if (this.f.busqueda) params.append('busqueda', this.f.busqueda);
                if (this.f.federacion) params.append('federacion', this.f.federacion);
                if (this.f.dias_min > 0) params.append('dias_min', this.f.dias_min);
                if (this.f.solo_alta) params.append('solo_alta', '1');
                const r = await fetch('?action=get_seguimiento&' + params.toString());
                const j = await r.json();
                if (j.ok) {
                    this.noRespondedores = j.no_respondedores || [];
                    this.sinProximaAccion = j.sin_proxima_accion || [];
                    this.nuevosSinActividad = j.nuevos_sin_actividad || [];
                    this.colaUnificada = j.unificada || [];
                    this.funnel = j.funnel || [];
                    this.kpis = Object.assign(this.kpis, j.kpis || {});
                } else {
                    this.error = (j.error || 'Error al cargar el seguimiento.');
                }


            } catch (e) {
                this.error = 'Error de conexión al cargar el seguimiento.';
            }
            this.cargando = false;
            if (window.lucide) lucide.createIcons();
        },
        openFicha(id) {
            if (window.app && window.app.openLead) window.app.openLead(id);
        },
        irAEstado(estado) {
            // Drill-down: embudo → Gestor (Leads) con ese estado (respeta campaña).
            if (!window.app) return;
            window.app.tab = 'gestor';
            window.app.ge = estado;
            window.app.gp = 1;
            window.app.loadGestor();
        },
        perseguir(lead) {
            // Prepara el lead en la Lanzadera (F4 del plan) y cambia de tab.
            if (!window.app) return;
            window.app.lzSelectLead(lead);
            window.app.tab = 'lanza';
        },
        async guardarFecha(l) {
            // Agenda la próxima acción del lead (columna "Próx. acción" de Avanzar).
            if (!l.nuevaFecha) return;
            try {
                const f = new FormData();
                f.append('action', 'update_lead');
                f.append('id', l.id);
                f.append('field', 'fecha_proxima_accion');
                f.append('value', l.nuevaFecha + ' 00:00:00');
                const r = await fetch('?action=update_lead', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    l.programando = false;
                    await this.load();
                } else {
                    alert(j.error || 'No se pudo guardar la fecha.');
                }
            } catch (e) { alert('Error de conexión al guardar la fecha.'); }
        },
        ordenar(key) {
            // Ordenación interactiva de las colas (Perseguir / Avanzar / Calentar).
            if (this.sortKey === key) {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortKey = key;
                const ascKeys = ['prioridad', 'nombre', 'contacto', 'estado', 'envio', 'proxima'];
                this.sortDir = ascKeys.includes(key) ? 'asc' : 'desc';
            }
        },
        get colaFiltrada() {
            // Lista ÚNICA de trabajo: todas las colas juntas, con select de vista
            // (todos / calentar / perseguir / cerrar / mockup / proforma / pausar / descartar).
            const datos = (this.colaUnificada || []).filter(l => this.cola === 'todos' || l.tipo === this.cola);
            const fp = this.filtroPrioridad, fe = this.filtroEstado, fv = this.filtroVariante;
            if (!fp && !fe && !fv) return datos;
            return datos.filter(l => {
                if (fp && l.prioridad !== fp) return false;
                if (fe && l.estado_lead !== fe) return false;
                if (fv && (l.variante || '') !== fv) return false;
                return true;
            });
        },
        get colaOrdenada() {
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const key = this.sortKey;
            const arr = this.colaFiltrada.slice();
            const val = (l) => {
                switch (key) {
                    case 'sem':         return { rojo: 0, ambar: 1, verde: 2 }[l.sem] ?? 1;
                    case 'prioridad':   return ['Alta', 'Media', 'Baja'].indexOf(l.prioridad);
                    case 'tipo':        return (l.tipo || '');
                    case 'nombre':      return (l.nombre_club || '').toLowerCase();
                    case 'contacto':    return (l.persona_contacto || '').toLowerCase();
                    case 'estado':      return (l.estado_lead || '').toLowerCase();
                    case 'envio':       return (l.ultimo_envio || '');
                    case 'proxima':     return (l.fecha_proxima_accion || '');
                    case 'variante':    return (l.variante || '');
                    case 'temperatura': return (l.score_temp || 0);
                    case 'aperturas':   return (l.num_aperturas || 0);
                    case 'envios':      return (l.num_envios || 0);
                    case 'dias':        return (l.dias_desde_contacto ?? l.dias_desde_envio ?? -1);
                    case 'volumen':     return (l.volumen_estimado || 0);
                    case 'presupuesto': return (l.presupuesto_importe ?? -1);
                    case 'creado':      return (l.dias_desde_creado ?? -1);
                    default:            return 0;
                }
            };
            return arr.sort((a, b) => {
                const x = val(a), y = val(b);
                if (typeof x === 'string') return x.localeCompare(y) * dir;
                return (x - y) * dir;
            });
        },
        get colaTotal()      { return this.colaOrdenada.length; },
        get totalPaginas()   { return Math.max(1, Math.ceil(this.colaTotal / this.paginaSize)); },
        get inicioPaginado() { return this.colaTotal === 0 ? 0 : (this.pagina - 1) * this.paginaSize + 1; },
        get finPaginado()    { return Math.min(this.pagina * this.paginaSize, this.colaTotal); },
        get colaPaginada()   { return this.colaOrdenada.slice(this.inicioPaginado - 1, this.finPaginado); },
        prioridadClase(pri) {
            return pri === 'Alta' ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30'
                : (pri === 'Media' ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                : 'bg-slate-700 text-slate-400 border border-slate-600');
        },
        semClase(sem) {
            return sem === 'rojo' ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30'
                : sem === 'ambar' ? 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                : 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30';
        },
        semDot(sem) {
            return sem === 'rojo' ? 'bg-rose-400 shadow-[0_0_6px_rgba(251,113,133,0.7)]'
                : sem === 'ambar' ? 'bg-amber-400 shadow-[0_0_6px_rgba(251,191,36,0.7)]'
                : 'bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.7)]';
        },
        b2bLabel(s) {
            return ({ SQL: '🟢 SQL', MQL: '🟠 MQL', Warm: '🟡 Warm', Prospect: '🔵 Prospect', Disqualified: '🔴 Perdido' }[s] || (s || '—'));
        },
        b2bClase(s) {
            return ({ SQL: 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30', MQL: 'bg-orange-500/15 text-orange-400 border border-orange-500/30', Warm: 'bg-yellow-500/15 text-yellow-400 border border-yellow-500/30', Prospect: 'bg-blue-500/15 text-blue-400 border border-blue-500/30', Disqualified: 'bg-rose-500/15 text-rose-400 border border-rose-500/30' }[s] || 'bg-slate-700 text-slate-400');
        },
        tempClase(t) {
            return t === 'MuyCaliente' ? 'bg-red-500/15 text-red-400 border border-red-500/30'
                : t === 'Caliente' ? 'bg-orange-500/15 text-orange-400 border border-orange-500/30'
                : t === 'Tibio' ? 'bg-sky-500/15 text-sky-400 border border-sky-500/30'
                : 'bg-slate-700 text-slate-400 border border-slate-600';
        },
        tempLabel(t) {
            return t === 'MuyCaliente' ? '🌋 Muy Caliente'
                : t === 'Caliente' ? '🔥 Caliente'
                : t === 'Tibio' ? '⏳ Tibio'
                : '🥶 Frío';
        },
        async cargarPropuestas() {
            try {
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                const r = await fetch('?action=get_propuestas_ia&campaign_id=' + cid);
                const j = await r.json();
                if (j.ok) this.propuestas = j.propuestas || [];
            } catch (e) { this.propuestas = []; }
        },
        async generarPropuestas() {
            try {
                const f = new FormData(); f.append('action', 'generar_propuestas_ia');
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                f.append('campaign_id', cid);
                const r = await fetch('?action=generar_propuestas_ia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) { await this.cargarPropuestas(); }
                else alert(j.error || 'Error al generar las propuestas.');
            } catch (e) { alert('Error de conexión al generar.'); }
        },
        async aprobarPropuesta(pr) {
            try {
                const f = new FormData(); f.append('action', 'aprobar_propuesta'); f.append('id', pr.id);
                const r = await fetch('?action=aprobar_propuesta', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) this.propuestas = this.propuestas.filter(x => x.id !== pr.id);
            } catch (e) {}
        },
        async rechazarPropuesta(pr) {
            const nota = prompt('Motivo del rechazo (opcional):', '');
            if (nota === null) return;
            try {
                const f = new FormData(); f.append('action', 'rechazar_propuesta'); f.append('id', pr.id); f.append('nota', nota || '');
                const r = await fetch('?action=rechazar_propuesta', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) this.propuestas = this.propuestas.filter(x => x.id !== pr.id);
            } catch (e) {}
        },
        tipoClase(t) {
            return ({ perseguir: 'bg-amber-500/15 text-amber-400 border border-amber-500/30', calentar: 'bg-sky-500/15 text-sky-400 border border-sky-500/30', cerrar: 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30', mockup: 'bg-purple-500/15 text-purple-400 border border-purple-500/30', proforma: 'bg-violet-500/15 text-violet-400 border border-violet-500/30', pausar: 'bg-slate-700 text-slate-400 border border-slate-600', descartar: 'bg-rose-500/15 text-rose-400 border border-rose-500/30' }[t] || 'bg-slate-700 text-slate-400');
        },
        tipoLabel(t) {
            return ({ perseguir: '🎯 Perseguir', calentar: '🌱 Calentar', cerrar: '🤝 Cerrar', mockup: '🎨 Mockup', proforma: '🧾 Proforma', pausar: '⏸️ Pausar', descartar: '🗑️ Descartar' }[t] || t);
        },
    };
}

