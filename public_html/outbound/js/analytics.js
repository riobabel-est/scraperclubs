// analytics.js — analyticsCampana (Alpine). Extraido de app.js (refactor modular 2026-08-26).
// Depende de app() en runtime (window.app).

function analyticsCampana() {
    return {
        campaignId: 0, campanas: [], funnel: [], cuello: {},
        metricas: { campaña: {}, variantes: { A: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 }, B: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 }, C: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 } }, positive: 0, negative: 0, neutral: 0, unsubscribe: 0, ooo: 0, pending: 0 },
        cargando: false, error: '',
        // Asistente de informes IA (chat con contexto real de la BD).
        chatMsgs: [], chatInput: '', chatEnviando: false,
        async init() {
            try {
                const r = await fetch('?action=get_piloto_campanas');
                const j = await r.json();
                this.campanas = j.campanas || [];
                // Contexto global del header: se lee de window._campanaActual
                // (valor plano del servidor, independiente del timing de Alpine/boot).
                const ctx = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                if (ctx && this.campanas.some(c => c.id == ctx)) this.campaignId = ctx;
            } catch (e) {}
            await this.cargar();
        },
        async cargar() {
            this.cargando = true; this.error = '';
            // metricas NUNCA pasa a null: conserva sus valores por defecto (ceros)
            // para que los templates de analytics.php no lancen errores si el fetch
            // falla puntualmente. Si llega ok, se sustituye con datos reales.
            this.funnel = []; this.cuello = null;
            if (!this.campaignId) { this.cargando = false; return; }
            try {
                // Solo métricas de campaña: el embudo se construye en cliente desde
                // metricas.leads_* (funnelLeads/cuelloLeads). Ya no se consulta el
                // funnel del pipeline (lead_pipelines no se alimenta con datos reales).
                const r1 = await fetch('?action=get_piloto_metricas&campaign_id=' + this.campaignId);
                const j1 = await r1.json();
                if (j1 && j1.ok) this.metricas = j1;
            } catch (e) { this.error = 'Error al cargar la analítica.'; }
            this.cargando = false;
            if (window.lucide) lucide.createIcons();
        },
        // Envía una pregunta al asistente IA con el contexto real de la BD.
        // El historial se mantiene en cliente; el backend (informe_ia) recibe
        // las últimas 8 respuestas para dar continuidad al diálogo.
        async chatEnviar(pregunta) {
            const p = (pregunta || this.chatInput || '').trim();
            if (!p || this.chatEnviando) return;
            this.chatMsgs.push({ role: 'user', content: p });
            this.chatInput = '';
            this.chatEnviando = true;
            const f = new FormData();
            f.append('action', 'informe_ia');
            f.append('pregunta', p);
            f.append('campaign_id', this.campaignId);
            f.append('historial', JSON.stringify(this.chatMsgs.slice(0, -1).slice(-8)));
            try {
                const r = await fetch('?action=informe_ia', { method: 'POST', body: f });
                const j = await r.json();
                this.chatMsgs.push({ role: 'assistant', content: (j && j.ok) ? j.respuesta : ((j && j.error) || 'Error de conexión con la IA.') });
            } catch (e) {
                this.chatMsgs.push({ role: 'assistant', content: 'Error de conexión con la IA.' });
            }
            this.chatEnviando = false;
            this.$nextTick(() => { const el = this.$refs.chatBox; if (el) el.scrollTop = el.scrollHeight; });
        },
        get totalEnvios() {
            if (!this.metricas || !this.metricas.variantes) return 0;
            return (this.metricas.variantes.A.envios || 0) + (this.metricas.variantes.B.envios || 0) + (this.metricas.variantes.C.envios || 0);
        },
        get prr()       { return this.metricas && this.metricas.aceptados > 0 ? Math.round(this.metricas.positive / this.metricas.aceptados * 1000) / 10 : 0; },
        get openRate()  { return this.metricas && this.metricas.aceptados > 0 ? Math.round(this.metricas.abiertos_unicos / this.metricas.aceptados * 1000) / 10 : 0; },
        get replyRate() { return this.metricas && this.metricas.aceptados > 0 ? Math.round(this.metricas.respuestas / this.metricas.aceptados * 1000) / 10 : 0; },
        get varianteGanadora() {
            if (!this.metricas || !this.metricas.variantes) return '';
            let best = '', max = -1;
            ['A', 'B', 'C'].forEach(v => { const prr = this.metricas.variantes[v].prr; if (prr > max) { max = prr; best = v; } });
            return max > 0 ? best : '';
        },
        get funnelMax() { return Math.max.apply(null, this.funnel.map(f => f.cnt).concat([1])); },
        // Embudo de conversión REAL de la campaña basado en LEADS (no en estados del
        // pipeline): Tocados → Entregados → Aperturas → Respuestas → Positivas.
        get funnelLeads() {
            const m = this.metricas || {};
            const etapas = [
                { clave: 'leads_tocados',     label: 'Tocados' },
                { clave: 'leads_entregados',  label: 'Entregados' },
                { clave: 'leads_abrieron',    label: 'Aperturas' },
                { clave: 'leads_respondieron', label: 'Respuestas' },
                { clave: 'leads_positivas',   label: 'Positivas' },
            ];
            const tocados = m.leads_tocados || 0;
            const out = [];
            let prev = null;
            for (const e of etapas) {
                const cnt = m[e.clave] || 0;
                out.push({
                    label: e.label,
                    cnt,
                    // % de conversión desde la ETAPA ANTERIOR.
                    pct: prev !== null && prev > 0 ? Math.round(cnt / prev * 100) : null,
                    // % sobre el total de leads tocados (para la barra).
                    maxPct: tocados > 0 ? Math.round(cnt / tocados * 1000) / 10 : 0,
                });
                prev = cnt;
            }
            return out;
        },
        get funnelLeadsMax() {
            return Math.max.apply(null, this.funnelLeads.map(f => f.cnt).concat([1]));
        },
        // Etapa con mayor caída de conversión (cuello de botella del embudo real).
        get cuelloLeads() {
            const f = this.funnelLeads;
            let mejor = null;
            for (let i = 0; i < f.length - 1; i++) {
                const a = f[i], b = f[i + 1];
                if (a.cnt > 0 && b.pct !== null && (b.cnt < a.cnt)) {
                    if (!mejor || b.pct < mejor.pct) mejor = { origen: a.label, destino: b.label, pct: b.pct };
                }
            }
            return mejor;
        }
    };
}

// ═════════════════════════════════════════════════════════════════════════════
// resumenDia — Resumen global del día (bloque de la Lanzadera)
// Consume get_analytics_federaciones con desde=hasta=hoy.
// ═════════════════════════════════════════════════════════════════════════════
function resumenDia() {
    return {
        datos: null, cargando: false, error: '',
        async cargar() {
            this.cargando = true; this.error = '';
            const hoy = new Date();
            const iso = hoy.toISOString().slice(0, 10);
            try {
                const r = await fetch('?action=get_analytics_federaciones&desde=' + iso + '&hasta=' + iso);
                const j = await r.json();
                this.datos = (j && j.ok) ? j : null;
            } catch (e) { this.error = 'No se pudo cargar el resumen del día.'; }
            this.cargando = false;
            if (window.lucide) lucide.createIcons();
        },
        get r() { return this.datos ? this.datos.resumen : null; },
        get porFed() { return this.datos ? this.datos.por_federacion : []; }
    };
}

// ═════════════════════════════════════════════════════════════════════════════
// marketingFederaciones — Cuadro de mando de marketing por federación
// (Analytics). Filtros: fechas, campaña, federación. Gráficos CSS puros.
// ═════════════════════════════════════════════════════════════════════════════
function marketingFederaciones() {
    return {
        campanas: [], campaignId: 0, federaciones: [], fedSel: '',
        desde: '', hasta: '', datos: null, cargando: false, error: '',
        async init() {
            const hoy = new Date();
            this.hasta = hoy.toISOString().slice(0, 10);
            const inicio = new Date(); inicio.setDate(inicio.getDate() - 30);
            this.desde = inicio.toISOString().slice(0, 10);
            try {
                const r = await fetch('?action=get_piloto_campanas');
                const j = await r.json();
                this.campanas = j.campanas || [];
            } catch (e) {}
            const ctx = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
            if (ctx) this.campaignId = ctx;
            await this.cargar();
        },
        async cargar() {
            this.cargando = true; this.error = '';
            const q = new URLSearchParams({ action: 'get_analytics_federaciones' });
            if (this.desde) q.set('desde', this.desde);
            if (this.hasta) q.set('hasta', this.hasta);
            if (this.campaignId) q.set('campaign_id', this.campaignId);
            if (this.fedSel) q.set('federacion', this.fedSel);
            try {
                const r = await fetch('?' + q.toString());
                const j = await r.json();
                if (j && j.ok) {
                    this.datos = j;
                    this.federaciones = j.por_federacion.map(f => f.fed).filter(f => f && f !== '(sin federación)');
                }
            } catch (e) { this.error = 'No se pudo cargar la analítica.'; }
            this.cargando = false;
            if (window.lucide) lucide.createIcons();
        },
        get r() { return this.datos ? this.datos.resumen : null; },
        get porFed() { return this.datos ? this.datos.por_federacion : []; },
        get serie() { return this.datos ? this.datos.serie : []; },
        get maxSerie() { return Math.max.apply(null, this.serie.map(s => s.envios).concat([1])); },
        get donutStyle() {
            if (!this.porFed.length) return 'background: conic-gradient(#1e293b 0 100%);';
            const colores = ['#f59e0b','#10b981','#38bdf8','#a78bfa','#f472b6','#f97316','#22d3ee','#84cc16','#e11d48','#8b5cf6','#14b8a6','#64748b'];
            let acc = 0; const segs = [];
            this.porFed.forEach((f, i) => {
                const pct = Number(f.pct_envios) || 0;
                if (pct <= 0) return;
                segs.push(colores[i % colores.length] + ' ' + acc + '% ' + (acc + pct) + '%');
                acc += pct;
            });
            if (acc < 100) segs.push('#1e293b ' + acc + '% 100%');
            return 'background: conic-gradient(' + segs.join(', ') + ');';
        },
        color(i) {
            const c = ['#f59e0b','#10b981','#38bdf8','#a78bfa','#f472b6','#f97316','#22d3ee','#84cc16','#e11d48','#8b5cf6','#14b8a6','#64748b'];
            return c[i % c.length];
        },
        claseTasa(v, umbral) {
            if (v === null || v === undefined) return 'text-slate-500';
            return v >= umbral ? 'text-emerald-400' : 'text-amber-400';
        }
    };
}

