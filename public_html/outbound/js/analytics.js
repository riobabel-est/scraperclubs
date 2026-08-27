// analytics.js — analyticsCampana (Alpine). Extraido de app.js (refactor modular 2026-08-26).
// Depende de app() en runtime (window.app).

function analyticsCampana() {
    return {
        campaignId: 0, campanas: [], funnel: [], cuello: {},
        metricas: { campaña: {}, variantes: { A: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 }, B: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 }, C: { aceptados: 0, aperturas: 0, aperturas_totales: 0, respuestas: 0, positivas: 0, negativas: 0, neutrales: 0, unsubscribe: 0, ooo: 0, prr: 0 } }, positive: 0, negative: 0, neutral: 0, unsubscribe: 0, ooo: 0, pending: 0 },
        cargando: false, error: '',
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
                const [r1, r2] = await Promise.all([
                    fetch('?action=get_piloto_metricas&campaign_id=' + this.campaignId),
                    fetch('?action=get_analytics&tab=dashboard&pipeline=' + this.campaignId)
                ]);
                const j1 = await r1.json();
                const j2 = await r2.json();
                if (j1 && j1.ok) this.metricas = j1;
                if (j2 && j2.ok) {
                    this.funnel = j2.funnel || [];
                    const conv = [];
                    for (let i = 0; i < this.funnel.length - 1; i++) {
                        const a = this.funnel[i], b = this.funnel[i + 1];
                        if (a.cnt > 0 && b.pct !== undefined && b.pct !== null) {
                            conv.push({ origen: a.nivel.replace(/^\d+\.\s*/, ''), destino: b.nivel.replace(/^\d+\.\s*/, ''), pct: b.pct });
                        }
                    }
                    if (conv.length > 0) this.cuello = conv.reduce((m, x) => x.pct < m.pct ? x : m, conv[0]);
                }
            } catch (e) { this.error = 'Error al cargar la analítica.'; }
            this.cargando = false;
            if (window.lucide) lucide.createIcons();
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
        get funnelMax() { return Math.max.apply(null, this.funnel.map(f => f.cnt).concat([1])); }
    };
}
