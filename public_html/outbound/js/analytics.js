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
