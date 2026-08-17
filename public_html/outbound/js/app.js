var app = function() {
    var i = {
        tab: 'kanban',
        killSwitch: window._cfg.motorActivo,
        modeTest: window._cfg.modeTest,

        // Modales
        lm: false, mm: false, sm: false, al: false,
        ln: '', mn: true, mk: 0, md: 0,
        ld: { presupuesto: null },
        ldOriginal: {},
        ldChanged: false,
        ldCalcPrecio: {},
        ldMockup: {},
        ldLoading: false,
        ldError: null,
        mf: [], mha: '', mhb: '',

        // SMTP
        se: 0,
        sp: false,
        randomMode: false,
        sf: { email: '', host: 'mail.getfutprotec.com', puerto: 465, usuario: '', password: '', seguridad: 'ssl', limite_diario: 50, nombre_emisor: '', cargo_emisor: '' },

        // Add Lead
        af: { nombre: '', email: '', federacion: '', movil: '', fijo: '', persona: '', cargo: '' },

        // WhatsApp desde ficha
        ldPlantillasWa: [],
        ldPlantillaWaId: '',
        ldWaUrl: '',

        // Buscador en listado
        edSearch: '',

        // Analytics modal
        aq: false,
        aqTab: 'envios',
        aqData: { total: 0, ultimos: [] },
        aqLoading: false,

        // Respuestas (FASE 4C)
        respuestas: [],
        respuestasFiltro: '',
        rsModal: false,
        rsRespuesta: null,
        rsEnvio: null,

        // Lista Negra (MEGA AUDITORÍA)
        blSearch: '',
        blResults: [],
        blSearchMsg: '',
        blSearchMsgOk: false,
        blList: [],
        blLoading: false,
        blMsg: '',
        blMsgOk: false,


        // Lanzadera v2
        lzMotorEstado: 'PAUSADO',

        // Kanban colapsable
        collapsed: {},

        // Gestor
        gs: '', ge: '', gf: '', gt: '', gp: 1, gpp: 50, gsc: 'nombre_club', gso: 'ASC',

        // Editor
         ec: '', et: '', en: false,
         edPlataforma: 'email',
         estadosLead: [
             '01 Sin Contactar',
             '02 Contactado',
             '03 Respondió',
             '04 Interesado',
             '05 Cualificado',
             '06 Propuesta',
             '07 Negociación',
             '08 Ganado',
             '09 Perdido'
         ],
         categorias: [], templates: [],
         edNombre: '', edAsunto: '', edAsuntoB: '', edAsuntoC: '', edTestAb: 0,
         edCuerpo: '', edCuerpoB: '', edCuerpoC: '', edTipo: 'html',
         previewClubId: '', debounceTimer: null,
         pvLive: false, pvLiveA: '', pvLiveB: '', pvLiveC: '', previewClubCache: {}, senderCache: null,

        // Lanzadera v2
        lzMotorEstado: 'PAUSADO',
        lzDelay: 5,
        lzInterval: null,
        lzAbortController: null,
        testEmails: '',
        lzCola: [],
        lzColaIndex: 0,
        lzColaPaginada: [],
        lzColaPageSize: 50,
        lzColaPageCurrent: 0,
        lzColaCompletados: {},
        lzColaResultados: {},
        lzLogEnviados: [],
        lzLogEnviadosPaginados: [],
        lzLogPageSize: 30,
        lzLogPageCurrent: 0,
        lzCuentasSmtp: [],
        lzFederacion: '',
        lzEstadoLead: '',
        lzIdPlantillaEmail: '',
        lzIdPlantillaWa: '',
        lzWhatsappOn: false,
        lzFederaciones: [],
        lzEstadosLead: [
            '01 Sin Contactar',
            '02 Contactado',
            '03 Respondió',
            '04 Interesado',
            '05 Cualificado',
            '06 Propuesta',
            '07 Negociación',
            '08 Ganado',
            '09 Perdido'
        ],
        lzTemplatesEmail: [],
        lzTemplatesWa: [],
        lzCampanas: [],
        lzCampaignId: '',
        lzTabMonitor: 'cola',
        lzKpiClubes: 0,
        lzKpiSmtpActivas: 0,
        lzKpiEnviosHoy: 0,

        // Envío dirigido (1 lead) + tamaño de lote
        lzBatchSize: 1,
        lzLeadSearch: '',
        lzLeadResults: [],
        lzLeadSearching: false,
        lzSelectedLeadId: 0,
        lzSelectedLead: null,
        lzLeadValidating: false,
        lzLeadValidation: null,
        lzSendCalls: 0,


        // ─── Computed ─────────────────────────────────────────────────────────
        get waLink() {
            const m = this.ld.telefono_movil || '';
            const n = m.split(',').map(s => s.trim()).filter(s => /^[67]\d{8}$/.test(s));
            return n.length > 0 ? 'https://wa.me/34' + n[0] : '';
        },
        get templatesFiltradas() {
            const q = (this.edSearch || '').toLowerCase();
            return this.templates.filter(t =>
                (!this.ec || t.categoria === this.ec) &&
                (this.edPlataforma === 'whatsapp' ? t.tipo === 'whatsapp' : t.tipo !== 'whatsapp') &&
                (!q || (t.nombre || '').toLowerCase().includes(q) || (t.categoria || '').toLowerCase().includes(q))
            );
        },
        seleccionarPlantilla(t) {
            this.et = t.id;
            this.edNombre = t.nombre;
            this.edAsunto = t.asunto || '';
             this.edAsuntoB = t.asunto_b || '';
             this.edAsuntoC = t.asunto_c || '';
             this.edTestAb = parseInt(t.test_ab) || 0;
             this.edCuerpo = t.cuerpo || '';
             this.edCuerpoB = t.cuerpo_b || '';
             this.edCuerpoC = t.cuerpo_c || '';
             this.edTipo = t.tipo || 'html';
             this.edPlataforma = (t.tipo === 'whatsapp') ? 'whatsapp' : 'email';
            this.ec = t.categoria || this.ec;
            this.en = false;
            this.autoPreview();
            setTimeout(() => lucide.createIcons(), 50);
        },

        // ─── Analytics de Sesión (Lanzadera) ────────────────────────────────
        get lzEnvioOkCount() {
            return this.lzLogEnviados.filter(l => l.envio_exitoso).length;
        },
        get lzEnvioErrorCount() {
            return this.lzLogEnviados.filter(l => !l.envio_exitoso).length;
        },
        get lzTotalProcesados() {
            return this.lzLogEnviados.length;
        },
        get lzTasaExito() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100) : 0;
        },
        get lzEnvioOkPct() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100) : 0;
        },
        get lzEnvioErrorPct() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioErrorCount / this.lzTotalProcesados) * 100) : 0;
        },
        // Fuente única de verdad del límite diario: la cuenta SMTP activa seleccionada.
        // Proviene de lzCuentasSmtp (cargado desde get_cola.php → BD cuentas_smtp.limite_diario).
        get lzCuentaActiva() {
            return (this.lzCuentasSmtp || []).find(c => c.activa == 1) || (this.lzCuentasSmtp || [])[0] || null;
        },
        get lzCuentaActivaLimite() {
            const c = this.lzCuentaActiva;
            return c ? (parseInt(c.limite_diario) || 0) : null;
        },
        get lzCuentaActivaLabel() {
            const c = this.lzCuentaActiva;
            if (!c) return 'Sin cuentas SMTP activas';
            return c.email + (c.activa == 1 ? '' : ' (inactiva)');
        },

        // ─── 🧪 Parser de emails de prueba ────────────────────────────────
        get testEmailsList() {
            if (!this.testEmails || !this.testEmails.trim()) return [];
            return this.testEmails
                .split(/[\n,]+/)
                .map(e => e.trim())
                .filter(e => e.length > 0 && e.includes('@'));
        },

        // ─── Boot ─────────────────────────────────────────────────────────────
        async boot() {
            window.app = this;
            lucide.createIcons();
            try { await this.loadGestor(); } catch (e) { console.error('boot: loadGestor falló', e); }
            try { await this.loadSmtp(); } catch (e) { console.error('boot: loadSmtp falló', e); }
            try { await this.bootLanzadera(); } catch (e) { console.error('boot: bootLanzadera falló', e); }
            try { await this.loadMockupCapacity(); } catch (e) { console.error('boot: loadMockupCapacity falló', e); }
        },

        // ─── Config ───────────────────────────────────────────────────────────
        async toggleKS() {
            this.killSwitch = !this.killSwitch;
            const f = new FormData();
            f.append('action', 'update_config');
            f.append('key', 'motor_estado');
            f.append('value', this.killSwitch ? 'activo' : 'pausado');
            await fetch('', { method: 'POST', body: f });
        },
        async toggleModo() {
            this.modeTest = !this.modeTest;
            const f = new FormData();
            f.append('action', 'update_config');
            f.append('key', 'modo_entorno');
            f.append('value', this.modeTest ? 'test' : 'produccion');
            await fetch('', { method: 'POST', body: f });
        },
        toggleRandom() {
            this.randomMode = !this.randomMode;
        },

        // ─── Kanban Drag & Drop ─────────────────────────────────────────────
        dragStart(ev, id) {
            ev.dataTransfer.setData('text/plain', id);
            ev.dataTransfer.effectAllowed = 'move';
        },
        async dropLead(ev, nuevoEstado) {
            ev.preventDefault();
            const id = parseInt(ev.dataTransfer.getData('text/plain'));
            if (!id) return;
            try {
                const f = new FormData();
                f.append('action', 'update_lead');
                f.append('id', id);
                f.append('field', 'estado_lead');
                f.append('value', nuevoEstado);
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) location.reload();
            } catch(e) { console.error('dropLead:', e); }
        },

        // ─── Lead Modal ───────────────────────────────────────────────────────
        async openLead(id) {
            this.lm = true;
            this.ldLoading = true;
            this.ldError = null;
            this.ln = '';
            this.ldPlantillaWaId = '';
            this.calcPrecio(); // reset
            try {
                const [r, rwa] = await Promise.all([
                    fetch('?action=get_lead&id=' + id),
                    fetch('?action=get_templates')
                ]);
                if (!r.ok) throw new Error('Error del servidor al cargar lead (HTTP ' + r.status + ')');
                const data = await r.json();
                if (!data || !data.id) throw new Error('Lead no encontrado o respuesta inválida');
                if (data.presupuesto === null || data.presupuesto === undefined) data.presupuesto = null;
                this.ld = data;
                this.ldMockup = data.mockup || {};
                this.ldOriginal = JSON.parse(JSON.stringify(this.ld));
                this.ldChanged = false;
                this.ldWaUrl = this.waLink ? ('https://wa.me/34' + (this.ld.telefono_movil || '').replace(/[^0-9]/g, '').match(/([67]\d{8})/)?.[1] || '') : '';
                const jwa = await rwa.json();
                if (jwa.ok) { this.ldPlantillasWa = jwa.templates.filter(t => t.tipo === 'whatsapp'); }
                this.ldError = null;
                this.calcPrecio(); // trigger if volumen_estimado set
            } catch (e) {
                console.error('openLead: error al cargar ficha', e);
                this.ldError = e.message || 'Error desconocido al cargar la ficha';
                this.ld = { presupuesto: null };
                this.ldMockup = {};
            }
            this.ldLoading = false;
            setTimeout(() => lucide.createIcons(), 100);
        },
        onWaPlantillaChange() {
            if (!this.ldPlantillaWaId || !this.waLink) { this.ldWaUrl = this.waLink || ''; return; }
            const tpl = this.ldPlantillasWa.find(x => x.id == this.ldPlantillaWaId);
            if (!tpl) return;
            const texto = (tpl.cuerpo || '')
                .replace(/{{CLUB}}/g, this.ld.nombre_club || '')
                .replace(/{{CONTACTO}}/g, this.ld.persona_contacto || 'responsable')
                .replace(/{{FEDERACION}}/g, this.ld.federacion || '')
                .replace(/{{ANIO}}/g, new Date().getFullYear());
            const num = (this.ld.telefono_movil || '').replace(/[^0-9]/g, '').match(/([67]\d{8})/)?.[1] || '';
            this.ldWaUrl = num ? ('https://wa.me/34' + num + '?text=' + encodeURIComponent(texto)) : '';
        },
        markChanged() {
            this.ldChanged = JSON.stringify(this.ld) !== JSON.stringify(this.ldOriginal);
        },
        async guardarFicha() {
            if (!this.ld.id || !this.ldChanged) return;
            const campos = ['federacion','persona_contacto','cargo_contacto','telefono_movil','telefono_fijo','tiene_whatsapp','estado_lead',
                'volumen_estimado','num_jugadores','categorias','fecha_decision_prevista',
                'objeciones','proxima_accion','canal_interaccion','motivo_perdida'];
            const promises = [];
            for (const campo of campos) {
                const val = campo === 'tiene_whatsapp' ? (this.ld[campo] ? 1 : 0) : (this.ld[campo] || '');
                if (String(val) !== String(this.ldOriginal[campo] ?? '')) {
                    const f = new FormData();
                    f.append('action', 'update_lead');
                    f.append('id', this.ld.id);
                    f.append('field', campo);
                    f.append('value', val);
                    promises.push(fetch('', { method: 'POST', body: f }));
                }
            }
            if (promises.length > 0) {
                await Promise.all(promises);
                this.ldOriginal = JSON.parse(JSON.stringify(this.ld));
                this.ldChanged = false;
                location.reload();
            }
        },
        async saveF(field, value) {
            if (!this.ld.id) return;
            const f = new FormData();
            f.append('action', 'update_lead');
            f.append('id', this.ld.id);
            f.append('field', field);
            f.append('value', value);
            await fetch('', { method: 'POST', body: f });
        },
        async addNota() {
            if (!this.ln.trim()) return;
            await this.saveF('observaciones', this.ln);
            const r = await fetch('?action=get_lead&id=' + this.ld.id);
            this.ld = await r.json();
            this.ln = '';
        },

        // ─── Add Lead (con validación MX y WhatsApp) ─────────────────────────
        openAddLead() {
            this.af = { nombre: '', email: '', federacion: '', movil: '', fijo: '', persona: '', cargo: '' };
            this.al = true;
            setTimeout(() => lucide.createIcons(), 100);
        },
        get afWaDetected() {
            if (!this.af.movil) return false;
            const limpio = this.af.movil.replace(/[^0-9]/g, '');
            return limpio.length === 9 && ['6', '7'].includes(limpio[0]);
        },
        async saveAddLead() {
            const f = new FormData();
            f.append('action', 'add_lead');
            f.append('nombre', this.af.nombre);
            f.append('email', this.af.email);
            f.append('federacion', this.af.federacion);
            f.append('telefono_movil', this.af.movil);
            f.append('telefono_fijo', this.af.fijo);
            f.append('persona_contacto', this.af.persona);
            f.append('cargo_contacto', this.af.cargo);
            const r = await fetch('', { method: 'POST', body: f });
            const j = await r.json();
            if (j.ok) { this.al = false; this.loadGestor(); alert('Lead anadido'); }
            else { alert(j.error || 'Desconocido'); }
        },

        // ─── Merge ────────────────────────────────────────────────────────────
        async openMerge(k, d) {
            this.mk = k; this.md = d;
            const [r1, r2] = await Promise.all([
                fetch('?action=get_lead&id=' + k).then(r => r.json()),
                fetch('?action=get_lead&id=' + d).then(r => r.json())
            ]);
            const a = r1, b = r2;
            if (!a || !b) return;
            this.mha = this.fr('Club', a.nombre_club) + this.fr('Email', a.email) + this.fr('Fed', a.federacion || '')
                     + this.fr('Contacto', a.persona_contacto) + this.fr('Movil', a.telefono_movil) + this.fr('Fijo', a.telefono_fijo)
                     + this.fr('Estado', a.estado_lead) + '<div class="mt-1"><strong class="text-slate-500">Notas:</strong><br>' + this.esc(a.observaciones || '(sin notas)') + '</div>';
            this.mhb = this.fr('Club', b.nombre_club) + this.fr('Email', b.email) + this.fr('Fed', b.federacion || '')
                     + this.fr('Contacto', b.persona_contacto) + this.fr('Movil', b.telefono_movil) + this.fr('Fijo', b.telefono_fijo)
                     + this.fr('Estado', b.estado_lead) + '<div class="mt-1"><strong class="text-slate-500">Notas:</strong><br>' + this.esc(b.observaciones || '(sin notas)') + '</div>';
            this.mf = [
                { name: 'nombre', label: 'Nombre', vA: a.nombre_club, vB: b.nombre_club, cA: true },
                { name: 'contacto', label: 'Contacto', vA: a.persona_contacto, vB: b.persona_contacto, cA: !!a.persona_contacto },
                { name: 'movil', label: 'Movil', vA: a.telefono_movil, vB: b.telefono_movil, cA: !!a.telefono_movil },
                { name: 'fijo', label: 'Fijo', vA: a.telefono_fijo, vB: b.telefono_fijo, cA: !!a.telefono_fijo },
                { name: 'estado', label: 'Estado', vA: a.estado_lead, vB: b.estado_lead, cA: true }
            ];
            this.mm = true; this.mn = true;
            setTimeout(() => lucide.createIcons(), 100);
        },
        fr(label, val) { return '<div><strong class="text-slate-500 text-[9px]">' + label + ':</strong> ' + this.esc(val || '-') + '</div>'; },
        async doMerge() {
            const fm = { nombre: 'nombre_club', contacto: 'persona_contacto', movil: 'telefono_movil', fijo: 'telefono_fijo', estado: 'estado_lead' };
            for (const f of this.mf) {
                const s = document.querySelector('input[name="mg_' + f.name + '"]:checked');
                if (s && s.value === 'B') {
                    const fd = new FormData(); fd.append('action', 'update_lead'); fd.append('id', this.mk); fd.append('field', fm[f.name]);
                    const bL = await fetch('?action=get_lead&id=' + this.md).then(r => r.json());
                    fd.append('value', bL[fm[f.name]] || '');
                    await fetch('', { method: 'POST', body: fd });
                }
            }
            const fd = new FormData(); fd.append('action', 'merge_leads'); fd.append('keep_id', this.mk); fd.append('dup_id', this.md); fd.append('merge_notes', this.mn ? '1' : '0');
            const r = await fetch('api/leads.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) { this.mm = false; location.reload(); } else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Scan Dups ────────────────────────────────────────────────────────
        async scanDups() {
            const r = await fetch('api/leads.php?action=scan_duplicates');
            const j = await r.json();
            if (j.ok) { alert('Escaneo: ' + j.dups + ' duplicados en ' + j.total + ' clubes.'); location.reload(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Gestor ───────────────────────────────────────────────────────────
        async loadGestor() {
            const p = new URLSearchParams({
                action: 'get_leads_table', page: this.gp, per_page: this.gpp,
                sort: this.gsc, order: this.gso,
                search: this.gs, estado: this.ge, federacion: this.gf
            });
            const r = await fetch('api/leads.php?' + p.toString());
            const j = await r.json();
            if (!j.ok) return;
            this.gt = j.total + ' resultados';
            let h = '';
            j.data.forEach(l => {
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><span class="font-medium text-slate-300">' + this.esc(l.nombre_club) + '</span>'
                   + (l.es_duplicado == 1 ? ' <span class="bg-amber-500/15 text-amber-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-pointer" onclick="window.app.openMerge(' + l.duplicado_id + ',' + l.id + ')">DUPLICADO</span>' : '')
                   + '</td>'
                   + '<td class="px-3 py-2 hidden md:table-cell"><code class="text-[10px] text-slate-500">' + this.esc(l.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden md:table-cell text-[10px] text-slate-400 font-mono">' + this.esc(l.telefono_movil || '-') + '</td>'
                   + '<td class="px-3 py-2 text-[10px] text-slate-400">' + this.esc(l.estado_lead) + '</td>'
                   + '<td class="px-3 py-2 hidden lg:table-cell text-[10px] text-slate-600">' + this.esc(l.federacion || '') + '</td>'
                   + '<td class="px-3 py-2 text-right"><button class="px-2 py-1 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-400 hover:text-slate-200 hover:border-slate-600 transition" onclick="window.app.openLead(' + l.id + ')">Ficha</button></td>'
                   + '</tr>';
            });
            document.getElementById('gestorBody').innerHTML = h || '<tr><td colspan="6" class="px-3 py-8 text-center text-slate-600">Sin resultados</td></tr>';
            let pg = '';
            const tp = j.total_pages; const cp = this.gp;
            let s = Math.max(1, cp - 2); let e = Math.min(tp, cp + 2);
            const bpg = (n) => '<button class="px-2 py-0.5 text-[10px] rounded border ' + (n === cp ? 'bg-slate-700 border-slate-600 text-slate-200' : 'border-slate-800 text-slate-500 hover:text-slate-300') + '" onclick="window.app.gp=' + n + ';window.app.loadGestor()" title="Ir a pagina ' + n + '">' + n + '</button>';
            if (s > 1) { pg += bpg(1); if (s > 2) pg += '<span class="px-1 text-slate-600">…</span>'; }
            for (let i = s; i <= e; i++) pg += bpg(i);
            if (e < tp) { if (e < tp - 1) pg += '<span class="px-1 text-slate-600">…</span>'; pg += bpg(tp); }
            document.getElementById('gestorP').innerHTML = pg;
        },
        gSort(col) {
            if (this.gsc === col) this.gso = this.gso === 'ASC' ? 'DESC' : 'ASC';
            else { this.gsc = col; this.gso = 'ASC'; }
            this.gp = 1; this.loadGestor();
        },

        // ─── Editor ────────────────────────────────────────────────────────────
        async loadCategorias() {
            const r = await fetch('?action=get_categorias'); const j = await r.json();
            if (j.ok) this.categorias = j.categorias;
        },
        async onCategoriaChange() {
            this.et = ''; this.en = false;
            if (!this.ec) return;
            const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.ec));
            const j = await r.json();
            if (j.ok) this.templates = j.templates;
            setTimeout(() => lucide.createIcons(), 50);
        },
        nuevaPlantilla() {
            this.et = ''; this.en = true;
            this.edNombre = 'Nueva plantilla'; this.edAsunto = ''; this.edAsuntoB = ''; this.edAsuntoC = ''; this.edTestAb = 0;
            this.edCuerpo = ''; this.edCuerpoB = ''; this.edCuerpoC = ''; this.edTipo = this.edPlataforma === 'whatsapp' ? 'whatsapp' : 'html';
            setTimeout(() => lucide.createIcons(), 50);
        },
        async eliminarPlantilla() {
            if (!this.et) return; if (!confirm('Eliminar esta plantilla?')) return;
            const f = new FormData(); f.append('action', 'delete_template'); f.append('id', this.et);
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { this.et = ''; this.en = false; this.onCategoriaChange(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        async guardarPlantilla() {
            const f = new FormData(); f.append('action', 'save_template');
            if (this.et && !this.en) f.append('id', this.et);
            f.append('nombre', this.edNombre); f.append('asunto', this.edAsunto);
            f.append('asunto_b', this.edAsuntoB); f.append('asunto_c', this.edAsuntoC);
            f.append('test_ab', this.edTestAb); f.append('cuerpo', this.edCuerpo);
            f.append('cuerpo_b', this.edCuerpoB); f.append('cuerpo_c', this.edCuerpoC);
            f.append('tipo', this.edPlataforma === 'whatsapp' ? 'whatsapp' : (this.edTipo || 'html'));
            f.append('categoria', this.ec); f.append('activo', '1');
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { this.en = false; this.et = j.id; this.onCategoriaChange(); alert('Plantilla guardada'); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        insertTag(tag) { this.edCuerpo += tag; this.renderLivePreview(); },
        onCuerpoInput() { clearTimeout(this.debounceTimer); this.debounceTimer = setTimeout(() => this.renderLivePreview(), 400); },
        async getPreviewClub() {
            const id = this.previewClubId;
            if (!id) return { nombre_club: '', persona_contacto: '', federacion: '' };
            if (this.previewClubCache[id]) return this.previewClubCache[id];
            try {
                const r = await fetch('?action=get_lead&id=' + id);
                const d = await r.json();
                if (d && d.id) this.previewClubCache[id] = d;
            } catch (e) {}
            return this.previewClubCache[id] || { nombre_club: '', persona_contacto: '', federacion: '' };
        },
        async getSenderPreview() {
            if (this.senderCache) return this.senderCache;
            let s = { sName: 'Nombre', sTitle: 'Equipo Comercial', sEmail: 'email@ejemplo.com' };
            try {
                const rs = await fetch('api/smtp.php?action=get_accounts');
                const jss = await rs.json();
                const cuenta = (jss.ok && jss.accounts && jss.accounts.length > 0) ? (jss.accounts.find(a => a.activa == 1 || a.activa == '1') || jss.accounts[0]) : null;
                if (cuenta) {
                    s.sName = cuenta.nombre_emisor || (cuenta.email ? cuenta.email.split('@')[0] : 'Nombre');
                    s.sTitle = cuenta.cargo_emisor || 'Equipo Comercial';
                    s.sEmail = cuenta.email || 'email@ejemplo.com';
                }
            } catch (e) {}
            this.senderCache = s;
            return s;
        },
        substPreview(body, club, sender) {
            return body
                .replace(/{{CLUB}}/g, club.nombre_club || '')
                .replace(/{{CONTACTO}}/g, club.persona_contacto || 'responsable')
                .replace(/{{FEDERACION}}/g, club.federacion || '')
                .replace(/{{EMAIL}}/g, club.email || '')
                .replace(/{{ANIO}}/g, new Date().getFullYear())
                .replace(/{{SENDER_NAME}}/g, sender.sName)
                .replace(/{{SENDER_TITLE}}/g, sender.sTitle)
                .replace(/{{SENDER_EMAIL}}/g, sender.sEmail);
        },
        renderVariantHtml(body, club, sender) {
            const html = this.substPreview(body || '', club, sender);
            if (this.edPlataforma === 'whatsapp') {
                return '<div style="background:#e5ddd5;padding:16px;border-radius:8px;max-width:400px;font-family:sans-serif;font-size:14px;white-space:pre-wrap">' + this.esc(html) + '</div>';
            }
            return html;
        },
        async renderLivePreview() {
            if (!this.pvLive) return;
            const [club, sender] = await Promise.all([this.getPreviewClub(), this.getSenderPreview()]);
            const abc = this.edTestAb === 1 && this.edPlataforma === 'email';
            this.pvLiveA = this.renderVariantHtml(this.edCuerpo, club, sender);
            this.pvLiveB = abc ? this.renderVariantHtml(this.edCuerpoB || this.edCuerpo, club, sender) : '';
            this.pvLiveC = abc ? this.renderVariantHtml(this.edCuerpoC || this.edCuerpo, club, sender) : '';
        },
        autoPreview() { this.renderLivePreview(); },

        // ═══════════════════════════════════════════════════════════════════════
        // LANZADERA OUTBOUND v2
        // ═══════════════════════════════════════════════════════════════════════

        async bootLanzadera() {
            try { const r = await fetch('api/leads.php?action=get_config&key=lanzadera_delay'); const j = await r.json(); if (j.ok && j.valor) this.lzDelay = parseInt(j.valor) || 5; } catch (e) { this.lzDelay = 5; }
            try { const r = await fetch('api/leads.php?action=get_config&key=test_emails'); const j = await r.json(); if (j.ok && j.valor) this.testEmails = j.valor; } catch (e) {}
            try { const r = await fetch('?action=get_piloto_campanas'); const j = await r.json(); if (j.ok) this.lzCampanas = j.campanas || []; } catch (e) {}
            try { const r = await fetch('api/get_cola.php'); const j = await r.json();
                if (j.ok) { this.lzFederaciones = j.federaciones || []; this.lzCuentasSmtp = j.cuentas_smtp || []; this.lzKpiClubes = j.kpi_clubes || 0; this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0; this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0; }
            } catch (e) {}
        },
        async lzSaveTestEmails() {
            const f = new FormData(); f.append('action', 'update_config'); f.append('key', 'test_emails'); f.append('value', this.testEmails);
            await fetch('', { method: 'POST', body: f });
        },
        lzOnCampaignChange() { /* la campaña se lee de lzCampaignId en el envío */ },
        // Prevalidación de campaña en UI (SOLO UX). NO sustituye a
        // validarCampanaActiva()/esEntornoCoherente() del backend, que siguen
        // siendo la autoridad. Espejo de inc/abc.php para no prometer envíos
        // condenados a fallar en el servidor.
        campanaOperable(c) {
            if (!c) return { ok: false, motivo: 'Campaña no encontrada. Recarga la página.' };
            const estado = String(c.estado || '').toUpperCase();
            if (!['PILOT', 'ACTIVE'].includes(estado)) {
                return { ok: false, motivo: 'Campaña ' + (c.estado || 'sin estado') + ': no operable para pruebas de envío (solo PILOT o ACTIVE).' };
            }
            if (parseInt(c.activo) !== 1) {
                return { ok: false, motivo: 'Campaña inactiva: no operable para pruebas de envío.' };
            }
            const ce = String(c.entorno || 'test').toLowerCase();
            const me = this.modeTest ? 'test' : 'produccion';
            if (me === 'produccion' && ce === 'test') {
                return { ok: false, motivo: 'La campaña es de entorno TEST y no puede enviarse en producción.' };
            }
            if (me === 'test' && (ce === 'pilot' || ce === 'production')) {
                return { ok: false, motivo: 'La campaña es de entorno ' + (c.entorno || 'pilot') + ' y no puede probarse en este entorno (campaña comercial).' };
            }
            return { ok: true, motivo: '' };
        },
        async enviarCorreoPrueba() {
            if (!this.lzCampaignId) { alert('Selecciona una campaña antes de enviar.'); return; }
            const campana = (this.lzCampanas || []).find(c => String(c.id) === String(this.lzCampaignId));
            const op = this.campanaOperable(campana);
            if (!op.ok) { alert(op.motivo); return; }
            if (!this.lzIdPlantillaEmail) { alert('Selecciona primero una plantilla de email en la configuración del lote.'); return; }
            const emails = this.testEmailsList;
            if (emails.length === 0) { alert('Configura al menos un email de prueba en "Destinos de Prueba".'); return; }

            // ─── Selección de leads SOLO compatibles con la campaña ─────────────
            // Nunca se usa get_leads_table sin filtro de compatibilidad TEST/REAL.
            // Si lzCola ya contiene leads compatibles (cargados por get_cola.php),
            // se reutilizan. Si está vacía, se obtienen internamente vía get_cola.php
            // con campaign_id, que aplica sqlFiltroCompatibilidadLeadCampana().
            let candidatos = (this.lzCola || []).filter(c => c && c.id);
            if (candidatos.length === 0) {
                try {
                    const params = new URLSearchParams();
                    params.append('campaign_id', this.lzCampaignId);
                    if (this.lzEstadoLead) params.append('estado_lead', this.lzEstadoLead);
                    if (this.lzFederacion) params.append('federacion', this.lzFederacion);
                    const r = await fetch('api/get_cola.php?' + params.toString());
                    const j = await r.json();
                    candidatos = (j.ok && Array.isArray(j.cola)) ? j.cola.filter(c => c && c.id) : [];
                } catch (e) { candidatos = []; }
            }
            if (candidatos.length === 0) { alert('No hay leads compatibles con la campaña seleccionada para la prueba.\nCargue una cola válida o amplíe el universo TEST.\nNo se ha enviado nada.'); return; }

            const smtp = (this.lzCuentasSmtp || []).find(c => c.activa == 1) || (this.lzCuentasSmtp || [])[0];
            if (!smtp || !smtp.id) { alert('No hay cuentas SMTP configuradas.'); return; }
            const tpl = (this.lzTemplatesEmail || []).find(t => t.id == this.lzIdPlantillaEmail);
            const esAbc = tpl && parseInt(tpl.test_ab) === 1;

            // ─── Selección A/B/C: buscar leads que cubran las variantes ─────────
            // La variante la calcula el servidor (get_cola.php → asignarVariante()).
            // No se fuerza variante: se elige un lead distinto por cada variante.
            let seleccion = [];
            if (esAbc) {
                const porVariante = { A: null, B: null, C: null };
                for (const c of candidatos) {
                    const v = c.variante_ab || 'A';
                    if (!porVariante[v]) porVariante[v] = c;
                    if (porVariante.A && porVariante.B && porVariante.C) break;
                }
                if (!porVariante.A || !porVariante.B || !porVariante.C) {
                    alert('No hay tres leads compatibles que cubran A/B/C.\nCargue una cola válida o amplíe el universo TEST.\nNo se ha enviado nada.');
                    return;
                }
                seleccion = [
                    { variante: 'A', club: porVariante.A },
                    { variante: 'B', club: porVariante.B },
                    { variante: 'C', club: porVariante.C },
                ];
            } else {
                seleccion = [{ variante: 'A', club: candidatos[0] }];
            }

            const cantidad = seleccion.length;
            if (!confirm('Se enviarán ' + cantidad + (esAbc ? ' correos de prueba (variantes A, B y C)' : ' correo de prueba (mensaje único)') + ' a: ' + emails.join(', ') + ' usando la cuenta ' + smtp.email + '.\n\n¿Continuar?')) return;
            const resultados = [];
            for (let i = 0; i < seleccion.length; i++) {
                const sel = seleccion[i];
                const fd = new FormData();
                fd.append('id_club', sel.club.id);
                fd.append('id_plantilla', this.lzIdPlantillaEmail);
                fd.append('id_cuenta_smtp', smtp.id);
                fd.append('modo_test', '1');
                fd.append('test_email', emails[i % emails.length]);
                fd.append('variante_ab', sel.variante);
                fd.append('campaign_id', this.lzCampaignId);
                try {
                    const r = await fetch('api/enviar_lote.php', { method: 'POST', body: fd });
                    const j = await r.json();
                    resultados.push('Variante ' + sel.variante + ': ' + (j.envio_exitoso ? '✅ OK' : '❌ ' + (j.error_smtp || j.error || 'error')));
                } catch (e) {
                    resultados.push('Variante ' + sel.variante + ': ❌ ' + (e.message || 'error de red'));
                }
            }
            alert('Resultado de la prueba:\n\n' + resultados.join('\n'));

        },
        async lzOnEstadoChange() {
            this.lzIdPlantillaEmail = ''; this.lzTemplatesEmail = []; if (!this.lzEstadoLead) return;
            try { const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.lzEstadoLead)); const j = await r.json();
                if (j.ok && j.templates) { this.lzTemplatesEmail = j.templates.filter(t => t.tipo !== 'whatsapp'); this.lzTemplatesWa = j.templates.filter(t => t.tipo === 'whatsapp'); }
            } catch (e) {}
        },
        puedeCargarCola() { return this.lzEstadoLead !== '' && this.lzIdPlantillaEmail !== ''; },
        async cargarCola() {
            if (!this.puedeCargarCola()) { alert('Selecciona al menos Estado del Lead y Plantilla de Email'); return; }
            this.lzCola = []; this.lzColaPaginada = []; this.lzColaPageCurrent = 0; this.lzColaIndex = 0;
            this.lzColaCompletados = {}; this.lzColaResultados = {}; this.lzLogEnviados = []; this.lzLogEnviadosPaginados = []; this.lzLogPageCurrent = 0; this.lzMotorEstado = 'PAUSADO';
            const params = new URLSearchParams({ estado_lead: this.lzEstadoLead, federacion: this.lzFederacion, id_plantilla_email: this.lzIdPlantillaEmail, id_plantilla_wa: this.lzIdPlantillaWa, habilitar_whatsapp: this.lzWhatsappOn ? '1' : '0', random_mode: this.randomMode ? '1' : '0', campaign_id: this.lzCampaignId || '' });
            try { const r = await fetch('api/get_cola.php?' + params.toString()); const j = await r.json();
                if (!j.ok) { alert('Error: ' + (j.error || 'Desconocido')); return; }
                this.lzCola = j.cola || [];
                if (this.lzCola.length > 0) { this.lzColaPaginada = this.lzCola.slice(0, Math.min(this.lzColaPageSize, this.lzCola.length)); this.lzColaPageCurrent = 1; }
                this.lzCuentasSmtp = j.cuentas_smtp || []; this.lzKpiClubes = j.kpi_clubes || 0; this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0; this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0; this.lzDelay = j.delay_segundos || 5;
                if (this.lzCola.length === 0) { alert('No hay leads pendientes con los filtros seleccionados.'); }
            } catch (e) { alert('Error de conexión al cargar la cola.'); }
            setTimeout(() => lucide.createIcons(), 100);
        },
        async iniciarMotor() {
            if (!this.lzCampaignId) { alert('Selecciona una campaña antes de enviar.'); return; }

            // ─── CASO A: Envío dirigido (un único lead seleccionado) ──────────
            // Si hay un lead seleccionado en "Envío Dirigido", se envía SOLO a ese
            // lead, ignorando la cola. El tamaño de lote se fuerza a 1.
            const dirigido = this.lzSelectedLeadId > 0 && this.lzSelectedLead;
            if (dirigido) {
                if (!this.lzIdPlantillaEmail) { alert('Selecciona una plantilla de email antes de enviar.'); return; }
                this.lzMotorEstado = 'ACTIVO'; this.lzAbortController = new AbortController(); const signal = this.lzAbortController.signal;
                this.lzSendCalls = 0;
                const lead = this.lzSelectedLead;
                const r = Math.random(); const vAb = r < 0.333 ? 'A' : (r < 0.666 ? 'B' : 'C');
                const fd = new FormData();
                fd.append('id_club', lead.id);
                fd.append('id_plantilla', this.lzIdPlantillaEmail);
                fd.append('id_cuenta_smtp', (this.lzCuentasSmtp.find(c => c.activa == 1) || this.lzCuentasSmtp[0] || {}).id || '');
                fd.append('modo_test', this.modeTest ? '1' : '0');
                fd.append('variante_ab', vAb);
                fd.append('campaign_id', this.lzCampaignId);
                if (this.modeTest && this.testEmailsList.length > 0) { fd.append('test_email', this.testEmailsList[0]); }
                try {
                    const r = await fetch('api/enviar_lote.php', { method: 'POST', body: fd, signal: signal }); const j = await r.json();
                    this.lzSendCalls++;
                    this.lzColaCompletados[lead.id] = true;
                    this.lzColaResultados[lead.id] = { ok: !!j.envio_exitoso, error: j.error_smtp || j.error || '' };
                    this.lzLogEnviados.unshift({ timestamp: j.timestamp || new Date().toISOString(), club: j.club || lead.nombre_club, email: j.email || lead.email, cuenta_smtp: j.cuenta_smtp || '', envio_exitoso: j.envio_exitoso || false, error_smtp: j.error_smtp || '' });
                    if (this.lzLogEnviadosPaginados.length === 0) { this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length)); this.lzLogPageCurrent = 1; }
                    if (j.envio_exitoso) { this.lzKpiEnviosHoy = (this.lzKpiEnviosHoy || 0) + 1; }
                } catch (e) {
                    if (e.name !== 'AbortError') {
                        this.lzColaCompletados[lead.id] = true;
                        this.lzColaResultados[lead.id] = { ok: false, error: e.message || 'Error de red' };
                        this.lzLogEnviados.unshift({ timestamp: new Date().toISOString(), club: lead.nombre_club, email: lead.email, cuenta_smtp: '—', envio_exitoso: false, error_smtp: e.message || 'Error de red' });
                        if (this.lzLogEnviadosPaginados.length === 0) { this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length)); this.lzLogPageCurrent = 1; }
                    }
                }
                this.lzMotorEstado = 'PAUSADO';
                this.lzAbortController = null; setTimeout(() => lucide.createIcons(), 100);
                return;
            }

            // ─── CASO B: Cola normal con límite de lote ───────────────────────
            if (this.lzCola.length === 0) return;
            const batchSize = Math.max(1, parseInt(this.lzBatchSize) || 1);
            this.lzMotorEstado = 'ACTIVO'; this.lzAbortController = new AbortController(); const signal = this.lzAbortController.signal;
            this.lzSendCalls = 0;
            for (let i = this.lzColaIndex; i < this.lzCola.length; i++) {
                if (signal.aborted) break; if (this.lzMotorEstado === 'PAUSADO' || this.lzMotorEstado === 'DETENIDO') break;
                // ─── DOBLE SALVAGUARDA: nunca superar el tamaño de lote ────────
                if (this.lzSendCalls >= batchSize) { this.lzMotorEstado = 'PAUSADO'; break; }
                this.lzColaIndex = i; const lead = this.lzCola[i]; if (!lead) continue;
                const r = Math.random(); const vAb = r < 0.333 ? 'A' : (r < 0.666 ? 'B' : 'C');
                const fd = new FormData(); fd.append('id_club', lead.id); fd.append('id_plantilla', this.lzIdPlantillaEmail); fd.append('id_cuenta_smtp', lead.smtp_asignada_id); fd.append('modo_test', this.modeTest ? '1' : '0'); fd.append('variante_ab', vAb); fd.append('campaign_id', this.lzCampaignId);
                if (this.modeTest && this.testEmailsList.length > 0) { fd.append('test_email', this.testEmailsList[i % this.testEmailsList.length]); }
                try {
                    const r = await fetch('api/enviar_lote.php', { method: 'POST', body: fd, signal: signal }); const j = await r.json();
                    this.lzSendCalls++;
                    this.lzColaCompletados[lead.id] = true;
                    this.lzColaResultados[lead.id] = { ok: !!j.envio_exitoso, error: j.error_smtp || j.error || '' };
                    this.lzLogEnviados.unshift({ timestamp: j.timestamp || new Date().toISOString(), club: j.club || lead.nombre_club, email: j.email || lead.email, cuenta_smtp: j.cuenta_smtp || lead.smtp_asignada_email, envio_exitoso: j.envio_exitoso || false, error_smtp: j.error_smtp || '' });
                    if (this.lzLogEnviadosPaginados.length === 0) { this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length)); this.lzLogPageCurrent = 1; }
                    const smtpIdx = this.lzCuentasSmtp.findIndex(c => c.id == lead.smtp_asignada_id);
                    if (smtpIdx >= 0 && j.envio_exitoso) { this.lzCuentasSmtp[smtpIdx].enviados_hoy = (this.lzCuentasSmtp[smtpIdx].enviados_hoy || 0) + 1; }
                    else if (smtpIdx >= 0 && j.error_smtp) { this.lzCuentasSmtp[smtpIdx].ultimo_error = j.error_smtp; }
                    if (j.envio_exitoso) { this.lzKpiEnviosHoy = (this.lzKpiEnviosHoy || 0) + 1; }
                } catch (e) {
                    if (e.name === 'AbortError') break;
                    this.lzColaCompletados[lead.id] = true;
                    this.lzColaResultados[lead.id] = { ok: false, error: e.message || 'Error de red' };
                    this.lzLogEnviados.unshift({ timestamp: new Date().toISOString(), club: lead.nombre_club, email: lead.email, cuenta_smtp: lead.smtp_asignada_email || '—', envio_exitoso: false, error_smtp: e.message || 'Error de red' });
                    if (this.lzLogEnviadosPaginados.length === 0) { this.lzLogEnviadosPaginados = this.lzLogEnviados.slice(0, Math.min(this.lzLogPageSize, this.lzLogEnviados.length)); this.lzLogPageCurrent = 1; }
                }
                // ─── Salvaguarda tras cada envío: parar al alcanzar el lote ───
                if (this.lzSendCalls >= batchSize) { this.lzMotorEstado = 'PAUSADO'; break; }
                if (i < this.lzCola.length - 1 && this.lzMotorEstado === 'ACTIVO') {
                    const baseMs = this.lzDelay * 1000; const ms = this.randomMode ? baseMs + Math.floor(Math.random() * this.lzDelay * 1000) - (this.lzDelay * 500) : baseMs;
                    await this.delay(Math.max(500, ms));
                }
            }
            if (this.lzMotorEstado === 'ACTIVO') { this.lzMotorEstado = 'PAUSADO'; }
            this.lzAbortController = null; setTimeout(() => lucide.createIcons(), 100);
        },

        pausarMotor() { this.lzMotorEstado = 'PAUSADO'; if (this.lzAbortController) { this.lzAbortController.abort(); this.lzAbortController = null; } },
        detenerMotor() { this.lzMotorEstado = 'DETENIDO'; if (this.lzAbortController) { this.lzAbortController.abort(); this.lzAbortController = null; } this.lzCola = []; this.lzColaIndex = 0; this.lzLogEnviados = []; },
        lzOnColaScroll() { const el = document.getElementById('lzColaScroll'); if (!el) return; if (el.scrollHeight - el.scrollTop - el.clientHeight < 100 && this.lzColaPaginada.length < this.lzCola.length) { this.lzLoadMoreCola(); } },
        lzLoadMoreCola() { const next = (this.lzColaPageCurrent + 1) * this.lzColaPageSize; if (next >= this.lzColaPaginada.length) { const end = Math.min(this.lzCola.length, (this.lzColaPageCurrent + 1) * this.lzColaPageSize + this.lzColaPageSize); const start = this.lzColaPageCurrent * this.lzColaPageSize; if (start < this.lzCola.length) { this.lzColaPaginada.push(...this.lzCola.slice(start, end)); this.lzColaPageCurrent++; } } },
        lzOnLogScroll() { const el = document.getElementById('lzLogScroll'); if (!el) return; if (el.scrollHeight - el.scrollTop - el.clientHeight < 100 && this.lzLogEnviadosPaginados.length < this.lzLogEnviados.length) { this.lzLoadMoreLog(); } },
        lzLoadMoreLog() { const start = this.lzLogPageCurrent * this.lzLogPageSize; if (start < this.lzLogEnviados.length) { this.lzLogEnviadosPaginados.push(...this.lzLogEnviados.slice(start, Math.min(this.lzLogEnviados.length, start + this.lzLogPageSize))); this.lzLogPageCurrent++; } },
        async lzSaveDelay() { const f = new FormData(); f.append('action', 'update_config'); f.append('key', 'lanzadera_delay'); f.append('value', this.lzDelay); await fetch('', { method: 'POST', body: f }); },

        // ─── Envío dirigido (1 lead) + tamaño de lote ─────────────────────────
        async lzSaveBatchSize() {
            let v = parseInt(this.lzBatchSize) || 1;
            if (v < 1) v = 1;
            if (v > 500) v = 500;
            this.lzBatchSize = v;
        },
        async lzSearchLeads() {
            const q = (this.lzLeadSearch || '').trim();
            if (!q) { this.lzLeadResults = []; return; }
            this.lzLeadSearching = true;
            try {
                const params = new URLSearchParams({ q: q });
                if (this.lzCampaignId) params.append('campaign_id', this.lzCampaignId);
                const r = await fetch('api/lead_search.php?' + params.toString());
                const j = await r.json();
                this.lzLeadResults = (j.ok && Array.isArray(j.results)) ? j.results : [];
            } catch (e) { this.lzLeadResults = []; }
            this.lzLeadSearching = false;
        },
        lzSelectLead(lead) {
            this.lzSelectedLeadId = lead.id;
            this.lzSelectedLead = lead;
            this.lzLeadValidation = null;
            // Al seleccionar un lead dirigido, el tamaño de lote se fuerza a 1
            this.lzBatchSize = 1;
        },
        lzClearLead() {
            this.lzSelectedLeadId = 0;
            this.lzSelectedLead = null;
            this.lzLeadValidation = null;
        },
        async lzValidateLead() {
            if (!this.lzSelectedLeadId) return;
            if (!this.lzCampaignId) { this.lzLeadValidation = { ok: false, error: 'Selecciona una campaña antes de validar.' }; return; }
            this.lzLeadValidating = true;
            this.lzLeadValidation = null;
            try {
                const params = new URLSearchParams({ lead_id: this.lzSelectedLeadId, campaign_id: this.lzCampaignId });
                const r = await fetch('api/lead_validate.php?' + params.toString());
                const j = await r.json();
                this.lzLeadValidation = j;
            } catch (e) {
                this.lzLeadValidation = { ok: false, error: 'Error de conexión al validar.' };
            }
            this.lzLeadValidating = false;
        },
        delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); },


        // ─── SMTP ─────────────────────────────────────────────────────────────
        async loadSmtp() {
            const r = await fetch('api/smtp.php?action=get_accounts'); const j = await r.json(); if (!j.ok) return;
            let h = '';
            j.accounts.forEach(a => {
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><code class="text-[10px] text-slate-300">' + this.esc(a.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden sm:table-cell text-[10px] text-slate-500">' + this.esc(a.host) + ':' + a.puerto + '</td>'
                   + '<td class="px-3 py-2 text-center text-[10px]"><span class="text-slate-300 font-semibold">' + a.enviados_hoy + '</span><span class="text-slate-600"> / ' + a.limite_diario + '</span></td>'
                   + '<td class="px-3 py-2 text-center">'
                   + (a.activa == 1 ? '<span class="bg-emerald-500/15 text-emerald-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">ON</span>' : '<span class="bg-slate-700 text-slate-500 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">OFF</span>')
                   + ' ' + (a.ultimo_error ? '<span class="bg-rose-500/15 text-rose-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-help" title="' + this.esc(a.ultimo_error) + '">!</span>' : '<span class="bg-emerald-500/15 text-emerald-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold">OK</span>')
                   + '</td>'
                   + '<td class="px-3 py-2 text-right"><div class="flex gap-1 justify-end">'
                   + '<button class="px-2 py-1 bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 rounded text-[10px] hover:bg-cyan-500/20 transition" onclick="window.app.testSmtp(' + a.id + ',this)"><i data-lucide="zap" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded text-[10px] hover:bg-amber-500/20 transition" onclick="window.app.toggleSmtp(' + a.id + ')"><i data-lucide="power" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded text-[10px] hover:bg-blue-500/20 transition" onclick="window.app.openSmtp(' + a.id + ')"><i data-lucide="pencil" class="w-3 h-3"></i></button>'
                   + '<button class="px-2 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded text-[10px] hover:bg-rose-500/20 transition" onclick="window.app.deleteSmtp(' + a.id + ')"><i data-lucide="trash-2" class="w-3 h-3"></i></button>'
                   + '</div></td></tr>';
            });
            document.getElementById('smtpBody').innerHTML = h || '<tr><td colspan="5" class="px-3 py-8 text-center text-slate-600">Sin cuentas</td></tr>';
            setTimeout(() => lucide.createIcons(), 50);
        },
        async openSmtp(id) {
            this.se = id;
            this.sp = false;
            if (id > 0) { const r = await fetch('api/smtp.php?action=get_accounts'); const j = await r.json(); const a = j.accounts.find(x => x.id == id);
                if (a) { this.sf = { email: a.email, host: a.host, puerto: a.puerto, usuario: a.usuario, password: a.password, seguridad: a.seguridad, limite_diario: a.limite_diario, nombre_emisor: a.nombre_emisor || '', cargo_emisor: a.cargo_emisor || '' }; }
            } else { this.sf = { email: '', host: 'mail.getfutprotec.com', puerto: 465, usuario: '', password: '', seguridad: 'ssl', limite_diario: 50 }; }
            this.sm = true; setTimeout(() => lucide.createIcons(), 100);
        },
        async saveSmtp() { const f = new FormData(); f.append('action', 'save_account'); if (this.se > 0) f.append('id', this.se); f.append('email', this.sf.email); f.append('host', this.sf.host); f.append('puerto', this.sf.puerto); f.append('usuario', this.sf.usuario); f.append('password', this.sf.password); f.append('seguridad', this.sf.seguridad); f.append('limite_diario', this.sf.limite_diario); f.append('nombre_emisor', this.sf.nombre_emisor || ''); f.append('cargo_emisor', this.sf.cargo_emisor || ''); const r = await fetch('api/smtp.php', { method: 'POST', body: f }); const j = await r.json(); if (j.ok) { this.sm = false; this.loadSmtp(); } else { alert('Error: ' + (j.error || 'Desconocido')); } },
        async toggleSmtp(id) { const f = new FormData(); f.append('action', 'toggle_account'); f.append('id', id); await fetch('api/smtp.php', { method: 'POST', body: f }); this.loadSmtp(); },
        async deleteSmtp(id) { if (!confirm('Eliminar esta cuenta SMTP?')) return; const f = new FormData(); f.append('action', 'delete_account'); f.append('id', id); const r = await fetch('api/smtp.php', { method: 'POST', body: f }); const j = await r.json(); if (j.ok) this.loadSmtp(); else alert('Error: ' + (j.error || 'Desconocido')); },
        async testSmtp(id, btn) {
            if (btn) { btn.disabled = true; const orig = btn.innerHTML; btn.innerHTML = '<span class="w-3 h-3 border-2 border-cyan-400 border-t-transparent rounded-full animate-spin inline-block"></span>'; }
            try { const f = new FormData(); f.append('action', 'test_smtp'); f.append('id', id); const r = await fetch('api/smtp.php', { method: 'POST', body: f }); const j = await r.json(); alert((j.status === 'success' ? 'CONEXION EXITOSA: ' : 'ERROR: ') + (j.message || 'Sin respuesta del servidor')); }
            catch (e) { alert('ERROR: No se pudo conectar con el servidor SMTP.\n' + (e.message || 'Error de red')); }
            if (btn) { btn.disabled = false; btn.innerHTML = orig; } this.loadSmtp();
        },

        // ─── Analytics ──────────────────────────────────────────────────────────
        async abrirAnalytics(tab) {
            this.aqTab = tab; this.aq = true; this.aqLoading = true; this.aqData = { total: 0, ultimos: [] };
            try { const r = await fetch('?action=get_analytics&tab=' + tab); const j = await r.json(); if (j && j.ok) this.aqData = j; } catch(e) {}
            this.aqLoading = false; setTimeout(() => lucide.createIcons(), 100);
        },
        aqTitulo(tab) { const mapa = { envios: 'Envíos Realizados', aperturas: 'Aperturas (Tracking)', rebotes: 'Rebotes', bajas: 'Leads de Baja' }; return mapa[tab] || tab; },

        // ─── Presupuesto ──────────────────────────────────────────────────────
        async crearPresupuesto() {
            if (!this.ld.id) return;
            if ((this.ld.volumen_estimado || 0) < 50) { alert('Volumen minimo 50 pares'); return; }
            const cp = prompt('Condiciones de pago:\n1) 50%+50%\n2) 100% adelantado (5% dto)', '1');
            const cond = cp === '2' ? '100% adelantado' : '50%+50%';
            const f = new FormData(); f.append('action', 'presupuesto_crear'); f.append('lead_id', this.ld.id); f.append('condiciones_pago', cond);
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { location.reload(); } else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Mockup ───────────────────────────────────────────────────────────
        async solicitarMockup() {
            if (!this.ld.id) return;
            if ((this.ld.volumen_estimado || 0) < 50 && !confirm('Volumen inferior a 50 pares. Solicitar mockup igualmente?')) return;
            const f = new FormData(); f.append('action', 'mockup_solicitar'); f.append('lead_id', this.ld.id);
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { location.reload(); } else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        async mockupEnviado() {
            if (!this.ldMockup || !this.ldMockup.id) return;
            const f = new FormData(); f.append('action', 'mockup_enviado'); f.append('mockup_id', this.ldMockup.id);
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { location.reload(); } else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Calculo Precio ────────────────────────────────────────────────
        calcPrecio() {
            const v = parseInt(this.ld.volumen_estimado) || 0;
            if (v <= 0) { this.ldCalcPrecio = {}; return; }
            fetch('api/leads.php?action=calcular_precio&volumen=' + v + '&pvp=15')
                .then(r => r.json()).then(j => { this.ldCalcPrecio = j; })
                .catch(() => { this.ldCalcPrecio = {}; });
        },

        // ─── Interacciones Manuales (F2.6) ────────────────────────────────
        irForm: { canal: 'email', tipo_evento: 'llamada', resumen: '', resultado: '', proxima_accion: '' },
        irSending: false,
        async registrarInteraccion() {
            if (!this.ld.id || !this.irForm.resumen.trim()) { alert('Resumen obligatorio'); return; }
            this.irSending = true;
            try {
                const f = new FormData();
                f.append('action', 'registrar_interaccion');
                f.append('lead_id', this.ld.id);
                f.append('canal', this.irForm.canal);
                f.append('tipo_evento', this.irForm.tipo_evento);
                f.append('resumen', this.irForm.resumen);
                f.append('resultado', this.irForm.resultado);
                f.append('proxima_accion', this.irForm.proxima_accion);
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.irForm = { canal: 'email', tipo_evento: 'llamada', resumen: '', resultado: '', proxima_accion: '' };
                    location.reload();
                } else { alert('Error: ' + (j.error || 'Desconocido')); }
            } catch (e) { console.error('registrarInteraccion:', e); }
            this.irSending = false;
        },

        // ─── Mockup Capacity (F2.4) ────────────────────────────────────────
        mockupCap: { solicitados_semana: 0, en_produccion: 0, enviados: 0, capacidad_semanal: 100, restante: 100, pct_utilizado: 0, alerta_80: false, alerta_95: false },
        async loadMockupCapacity() {
            try {
                const r = await fetch('?action=mockup_capacity');
                const j = await r.json();
                if (j.ok) this.mockupCap = j;
            } catch (e) { console.error('loadMockupCapacity:', e); }
        },

        // ─── Respuestas (FASE 4C) ────────────────────────────────────────
        async loadRespuestas() {
            this.respuestas = [];
            try {
                const p = new URLSearchParams({ action: 'get_respuestas' });
                if (this.respuestasFiltro) p.append('clasificacion', this.respuestasFiltro);
                const r = await fetch('?' + p.toString());
                const j = await r.json();
                if (j && j.ok) this.respuestas = j.respuestas || [];
            } catch (e) { console.error('loadRespuestas:', e); }
        },
        async abrirRespuesta(id) {
            this.rsRespuesta = null; this.rsEnvio = null; this.rsModal = true;
            try {
                const r = await fetch('?action=get_respuesta&id=' + id);
                const j = await r.json();
                if (j && j.ok) { this.rsRespuesta = j.respuesta; this.rsEnvio = j.envio || {}; }
            } catch (e) { console.error('abrirRespuesta:', e); }
        },
        async clasificarRespuesta(id, clasif) {
            try {
                const f = new FormData();
                f.append('action', 'clasificar_respuesta');
                f.append('id', id);
                f.append('clasificacion', clasif);
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j && j.ok) {
                    if (this.rsRespuesta && this.rsRespuesta.id == id) this.rsRespuesta.clasificacion = j.clasificacion;
                    this.loadRespuestas();
                } else {
                    alert('Error: ' + (j.error || 'Desconocido'));
                }
            } catch (e) { console.error('clasificarRespuesta:', e); }
        },

        // ─── Lista Negra (MEGA AUDITORÍA) ─────────────────────────────────
        // Gestión manual de supresión: opt-out real (protegido) y bloqueo manual.
        // NUNCA borra historial: registra quién/cuándo/motivo en observaciones
        // y comunicaciones_log.
        async blBuscar() {
            const q = (this.blSearch || '').trim();
            this.blResults = [];
            this.blSearchMsg = '';
            if (!q) { this.blSearchMsg = 'Introduce nombre, email o ID para buscar.'; this.blSearchMsgOk = false; return; }
            try {
                const params = new URLSearchParams({ q: q });
                const r = await fetch('api/lead_search.php?' + params.toString());
                const j = await r.json();
                if (j.ok) {
                    this.blResults = j.results || [];
                    this.blSearchMsg = this.blResults.length === 0 ? 'Sin resultados.' : '';
                    this.blSearchMsgOk = this.blResults.length > 0;
                } else {
                    this.blSearchMsg = j.error || 'Error al buscar.';
                    this.blSearchMsgOk = false;
                }
            } catch (e) {
                this.blSearchMsg = 'Error de conexión al buscar.';
                this.blSearchMsgOk = false;
            }
        },
        blEsSuprimido(r) {
            const estados = ['Lista Negra', 'Opt-Out', 'Unsubscribed', 'Baja / Opt-Out', 'Email Inválido'];
            return estados.includes(String(r.estado_lead || ''));
        },
        // ─── Helpers de la ficha del lead (BLOQUE 4) ─────────────────────────
        // Determinan si el lead está en Lista Negra y extraen tipo/fecha/motivo
        // de la última marca de supresión en observaciones. NUNCA borran historial.
        ldEsListaNegra() {
            return this.blEsSuprimido(this.ld);
        },
        ldTipoSupresion() {
            const obs = String(this.ld.observaciones || '');
            if (/\[BAJA\][^\n]*fuente\s*=\s*email/i.test(obs)) return 'Baja por email';
            if (/\[LISTA NEGRA\]/i.test(obs)) return 'Bloqueo manual';
            if (/\[BLOQUEO MANUAL\]/i.test(obs)) return 'Bloqueo manual';
            return String(this.ld.estado_lead || 'Lista Negra');
        },
        ldFechaSupresion() {
            const obs = String(this.ld.observaciones || '');
            const m = obs.match(/\[(?:BAJA|BLOQUEO MANUAL|LISTA NEGRA)\][^\n]*?(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/i);
            return m ? m[1] : '';
        },
        ldMotivoSupresion() {
            const obs = String(this.ld.observaciones || '');
            const m = obs.match(/\[(?:BAJA|BLOQUEO MANUAL|LISTA NEGRA)\][^\n]*?(?:motivo=([^\n|]*))/i);
            return m ? m[1] : '';
        },
        async blAdd(r) {

            const motivo = prompt('Añadir a Lista Negra\n\nMotivo:\nEj: Cliente pidió no recibir comunicaciones, Lead de prueba, Importación incorrecta, Cliente no objetivo, Error humano, Bloqueo preventivo', '');
            if (motivo === null) return; // cancelado
            try {
                const f = new FormData();
                f.append('action', 'blacklist_add');
                f.append('id', r.id);
                f.append('motivo', motivo);
                const resp = await fetch('', { method: 'POST', body: f });
                const j = await resp.json();
                if (j.ok) {
                    this.blMsg = 'Lead añadido a Lista Negra.';
                    this.blMsgOk = true;
                    this.blResults = [];
                    this.blSearch = '';
                    this.blCargar();
                    this.blRefreshUI();
                } else {
                    this.blMsg = j.error || 'Error al añadir a Lista Negra.';
                    this.blMsgOk = false;
                }
            } catch (e) {
                this.blMsg = 'Error de conexión al añadir a Lista Negra.';
                this.blMsgOk = false;
            }
        },
        async blRemove(l) {
            // BLOQUE 6: confirmación explícita con motivo OBLIGATORIO.
            if (!confirm('¿Quitar de Lista Negra?\n\n"' + (l.nombre_club || '') + '" dejará de estar suprimido y volverá a ser elegible para futuras campañas si cumple el resto de criterios.\n\nEl histórico de bajas y bloqueos NO se eliminará.')) return;
            const motivo = prompt('Motivo de la reactivación (OBLIGATORIO):\n\nEj: Cliente volvió a solicitar contacto, Solicitó recibir información de nuevo, Bloqueo introducido por error, Cliente activo / relación comercial, Prueba / QA, Otro', '');
            if (motivo === null) return; // cancelado
            if (motivo.trim() === '') {
                this.blMsg = 'El motivo de reactivación es obligatorio.';
                this.blMsgOk = false;
                return;
            }
            try {
                const f = new FormData();
                f.append('action', 'blacklist_remove');
                f.append('id', l.id);
                f.append('motivo', motivo.trim());
                const resp = await fetch('', { method: 'POST', body: f });
                const j = await resp.json();
                if (j.ok) {
                    this.blMsg = 'Quitado de Lista Negra. Lead reactivado.';
                    this.blMsgOk = true;
                    this.blCargar();
                    this.blRefreshUI();
                } else {
                    this.blMsg = j.error || 'Error al quitar de Lista Negra.';
                    this.blMsgOk = false;
                }
            } catch (e) {
                this.blMsg = 'Error de conexión al quitar de Lista Negra.';
                this.blMsgOk = false;
            }
        },
        // BLOQUE 9: refresca ficha, estado, elegibilidad visual y cola tras
        // añadir/quitar de Lista Negra, sin obligar a cerrar sesión.
        async blRefreshUI() {
            // Refrescar ficha del lead si está abierta (this.ld)
            if (this.ld && this.ld.id) {
                try {
                    const resp = await fetch('?action=get_lead&id=' + this.ld.id);
                    const j = await resp.json();
                    if (j && j.id) {
                        this.ld = j;
                        this.ldOriginal = JSON.parse(JSON.stringify(this.ld));
                        this.ldChanged = false;
                    }
                } catch (e) {}
            }
            // Refrescar cola si está cargada (this.lzCola)
            if (this.lzCola && this.lzCola.length !== undefined) {
                try {
                    const params = new URLSearchParams({
                        estado_lead: this.lzEstadoLead || '',
                        federacion: this.lzFederacion || '',
                        id_plantilla_email: this.lzIdPlantillaEmail || '',
                        id_plantilla_wa: this.lzIdPlantillaWa || '',
                        habilitar_whatsapp: this.lzWhatsappOn ? '1' : '0',
                        random_mode: this.randomMode ? '1' : '0',
                        campaign_id: this.lzCampaignId || ''
                    });
                    const resp = await fetch('api/get_cola.php?' + params.toString());
                    const j = await resp.json();
                    if (j && j.ok) this.lzCola = j.cola || [];
                } catch (e) {}
            }
        },


        async blCargar() {
            this.blLoading = true;
            this.blMsg = '';
            try {
                const r = await fetch('?action=get_blacklist');
                const j = await r.json();
                if (j.ok) {
                    this.blList = j.items || [];
                } else {
                    this.blList = [];
                    this.blMsg = j.error || 'Error al cargar la Lista Negra.';
                    this.blMsgOk = false;
                }
            } catch (e) {
                this.blList = [];
                this.blMsg = 'Error de conexión al cargar la Lista Negra.';
                this.blMsgOk = false;
            }
            this.blLoading = false;
        },

        // ─── Snapshot (F2.12) ──────────────────────────────────────────────
        snapshotMsg: '',
        async guardarSnapshot() {

            this.snapshotMsg = 'Guardando...';
            try {
                const r = await fetch('?action=snapshot_crear', { method: 'POST' });
                const j = await r.json();
                if (j.ok) { this.snapshotMsg = 'Snapshot guardado. Total: ' + j.total + ' leads.'; }
                else { this.snapshotMsg = 'Error: ' + (j.error || 'Desconocido'); }
            } catch (e) { this.snapshotMsg = 'Error de conexión'; }
        },

        esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    };
    window.app = i;
    return i;
};

// ─── analyticsApp — Alpine component for Analytics tab ──────────────────────
function analyticsApp(){return{funnel:[],kpi:null,abc:[],abcGanadora:null,obj:{ganados:0,pct:0,restantes:20,tasa_cierre:0,contactos_necesarios:'-',contactados:0,facturacion:0,pares:0,margen:0,proyeccion:null},pipelines:[],tiempos:[],fPipeline:'',fVariante:'',fExcluirTest:true,
get funnelMax(){return Math.max.apply(null,this.funnel.map(function(f){return f.cnt}).concat([1]))},
get kpiCards(){if(!this.kpi)return[];return[{label:'Ganados/100 contactos',value:this.kpi.ganados_100,sub:'clubes',color:'text-emerald-400',border:'border-emerald-500/30'},{label:'Facturacion/100 contactos',value:this.kpi.fact_100+'\u20AC',sub:'estimado',color:'text-blue-400',border:'border-blue-500/30'},{label:'Pares/100 contactos',value:this.kpi.pares_100,sub:'unidades',color:'text-amber-400',border:'border-amber-500/30'},{label:'Margen Club/100 contactos',value:this.kpi.margen_100+'\u20AC',sub:'potencial',color:'text-purple-400',border:'border-purple-500/30'}]},
get conversiones(){if(!this.funnel||this.funnel.length<2)return[];var r=[];for(var i=0;i<this.funnel.length-1;i++){var a=this.funnel[i];var b=this.funnel[i+1];if(a.cnt>0&&b.pct!==undefined){var pct=b.pct;r.push({origen:a.nivel.replace(/^\d+\.\s*/,''),destino:b.nivel.replace(/^\d+\.\s*/,''),pct:pct,perdida:(100-pct)+'%'})}}return r},
get cuelloPrincipal(){var c=this.conversiones;if(!c||c.length===0)return null;var min=c[0];for(var i=1;i<c.length;i++){if(c[i].pct<min.pct)min=c[i]}return min},
get abcFilas(){if(!this.abc||this.abc.length===0)return[];var rows=[];var labels=['Leads','Entregados','Aperturas','Tasa Apertura','Respuestas','Tasa Respuesta','Resp. Positiva','Cualificados','Mockups','Presupuestos','Negociaciones','Ganados','Perdidos','Conversion','Facturacion','Pares','Fact/100','Pares/100'];var keys=['leads','entregados','aperturas','tasa_apertura','respondio','tasa_resp','interesado','cualificado','mockups','presupuestos','negociacion','ganado','perdido','conversion','facturacion','pares','fact_100','pares_100'];var sufs=['','','','%','','%','','','','','','','','%','','','',''];for(var ri=0;ri<labels.length;ri++){var row={label:labels[ri],a:'0',b:'0',c:'0',bestIndex:-1};var vals=[];for(var vi=0;vi<this.abc.length;vi++){var v=this.abc[vi][keys[ri]];var sv=v;if(typeof v==='number'){sv=v.toLocaleString()+(sufs[ri]||'')}else{sv=v||'0'}if(vi===0)row.a=sv;if(vi===1)row.b=sv;if(vi===2)row.c=sv;if(typeof v==='number')vals.push({v:v,i:vi})}if(vals.length>0){var best=vals[0];for(var bi=1;bi<vals.length;bi++){if(vals[bi].v>best.v)best=vals[bi]}row.bestIndex=best.i}rows.push(row)}return rows},
async load(){var p=new URLSearchParams({action:'get_analytics',tab:'dashboard'});if(this.fPipeline)p.append('pipeline',this.fPipeline);if(this.fVariante)p.append('variante',this.fVariante);if(!this.fExcluirTest)p.append('excluir_test','0');try{var r=await fetch('?'+p.toString());var j=await r.json();if(j.ok){this.funnel=j.funnel||[];this.kpi=j.kpi||null;this.abc=j.abc||[];this.abcGanadora=j.abc_ganadora||null;if(j.objetivo){this.obj=j.objetivo;this.obj.proyeccion=j.objetivo.tasa_cierre>0&&j.objetivo.ganados>0?Math.round(j.objetivo.ganados/j.objetivo.tasa_cierre*100/100):null}if(j.pipelines)this.pipelines=j.pipelines;if(j.tiempos)this.tiempos=j.tiempos}}catch(e){console.error('Analytics:',e)}}}}
