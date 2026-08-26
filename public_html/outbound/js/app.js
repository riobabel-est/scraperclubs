var app = function() {
    console.log('[DEBUG] app() INVOCADO. Iniciando construcción del objeto Alpine...');
    try {
    var _cfg = (typeof window._cfg === 'object' && window._cfg !== null) ? window._cfg : {};
    var i = {

        tab: 'kanban',
        killSwitch: _cfg.motorActivo,
        modeTest: _cfg.modeTest,


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

        // Respuestas (FASE 4C + FASE FG: bandeja de conversaciones)
        respuestas: [],
        respuestasFiltro: '',
        respuestasPrioridad: '',
        rsModal: false,
        rsRespuesta: null,
        rsEnvio: null,
        rsConversacion: null,
        rsConversacionModal: false,
        // Notificaciones de nuevas respuestas (FASE G)
        rsNuevas: 0,
        rsToast: '',
        rsToastVisible: false,
        rsToastTimer: null,
        // ─── UNIBOX SPLIT-VIEW (FASE UNIBOX UI) ─────────────────────────────
        // Conversación seleccionada en el panel derecho (visor).
        rsSeleccion: null,
        // Búsqueda por nombre de club en el panel izquierdo.
        rsBusqueda: '',
        // Filtro de clasificación (Todas / Interesado / Duda Precio / Baja).
        rsFiltroClas: '',
        // Texto de la respuesta que se está redactando en el footer.
        rsRedaccion: '',
        // Plantilla rápida seleccionada.
        rsPlantillaRapida: '',
        // Estado de envío de la respuesta SMTP.
        rsEnviando: false,
        rsEnvioMsg: '',
        rsEnvioMsgOk: false,
        // Plantillas rápidas de respuesta.
        rsPlantillasRapidas: [
            { id: 'muestra', label: 'Enviar Muestra Física', cuerpo: 'Hola {{CONTACTO}}, gracias por tu interés. Te enviamos una muestra física de las espinilleras para que podáis valorarlas en persona. ¿Nos confirmas la dirección de envío del club?' },
            { id: 'tarifas', label: 'Enviar PDF Tarifas', cuerpo: 'Hola {{CONTACTO}}, adjuntamos el PDF con nuestras tarifas por volumen. Para un pedido de {{VOLUMEN}} pares el precio B2B es muy competitivo. ¿Te parece bien que te lo enviemos?' },
            { id: 'llamada', label: 'Agendar Llamada', cuerpo: 'Hola {{CONTACTO}}, encantados de hablar con vosotros. ¿Qué día y hora os viene mejor para una llamada de 10 minutos y resolver todas las dudas?' }
        ],
        // Estados del lead disponibles para el desplegable del header.
        rsEstadosLead: [
            '01 Sin Contactar', '02 Contactado', '03 En Conversación',
            '04 Propuesta', '05 Ganado', '06 Perdido', '07 Baja'
        ],

        // Mapa de clasificación → etiqueta de intención (badge).
        rsIntencionMap: {
            POSITIVE: { label: 'SOLICITA MUESTRA', color: 'bg-emerald-500/20 text-emerald-400' },
            INTERESADO: { label: 'INTERESADO', color: 'bg-emerald-500/20 text-emerald-400' },
            'DUDA PRECIO': { label: 'DUDA PRECIO', color: 'bg-amber-500/20 text-amber-400' },
            NEUTRAL: { label: 'NEUTRAL', color: 'bg-amber-500/20 text-amber-400' },
            'NO INTERESA': { label: 'NO INTERESA', color: 'bg-rose-500/20 text-rose-400' },
            DESUSCRIPCION: { label: 'DESUSCRIPCIÓN', color: 'bg-rose-500/20 text-rose-400' },
            UNSUBSCRIBE: { label: 'DESUSCRIPCIÓN', color: 'bg-rose-500/20 text-rose-400' },
            NEGATIVE: { label: 'NO INTERESA', color: 'bg-rose-500/20 text-rose-400' },
            PENDING: { label: 'NEUTRAL', color: 'bg-amber-500/20 text-amber-400' },
            OOO: { label: 'NEUTRAL', color: 'bg-amber-500/20 text-amber-400' }
        },
        // Plantillas de respuesta rápida disponibles (cargadas desde BD).
        rsTemplatesRapidas: [],

        // Cuenta SMTP activa para el envío de respuesta.
        rsCuentaSmtp: null,
        // Enlace WhatsApp dinámico del lead seleccionado.
        rsWaUrl: '',
        // Estado de la sincronización IMAP/POP3 (botón "Actualizar").
        rsSyncing: false,
        rsSyncMsg: '',





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

        // ─── FILTROS RÁPIDOS KANBAN (FASE 3 — absorción de Follow-ups) ─────
        // Datos expuestos por dashboard.php en window._kanbanLeads / _chipCounters.
        // Valores por defecto seguros: si window._kanbanLeads / _chipCounters no
        // existen (o llegan corruptos), NUNCA deben colapsar la inicialización de
        // Alpine. Una variable ausente aquí rompería el scope global y provocaría
        // errores tipo "rsSyncing is not defined" en directivas de otros tabs.
        kanbanLeads: (typeof window._kanbanLeads === 'object' && window._kanbanLeads !== null) ? window._kanbanLeads : [],
        chipCounters: (typeof window._chipCounters === 'object' && window._chipCounters !== null) ? window._chipCounters : { calientes: 0, leidos: 0, pendiente_wa: 0, federaciones: {} },

        // Filtro activo: '' (todos) | 'calientes' | 'pendiente_wa' | 'federacion:<nombre>'

        filtroActivo: '',
        // Búsqueda en tiempo real por nombre de club (con debounce).
        busqueda: '',
        // Selector de federación (desplegable).
        filtroFederacion: '',
        // Lista de federaciones derivada de los contadores (para el desplegable).
        federacionesFiltro: [],
        // Timer del debounce de búsqueda.
        _busquedaTimer: null,


        // Gestor
        gs: '', ge: '', gf: '', gd: '', gt: '', gp: 1, gpp: 50, gsc: 'nombre_club', gso: 'ASC',


        // Editor
         ec: '', et: '', en: false,
         edPlataforma: 'email',
         estadosLead: [
             '01 Sin Contactar',
             '02 Contactado',
             '03 En Conversación',
             '04 Propuesta',
             '05 Ganado',
             '06 Perdido',
             '07 Baja'
         ],

         categorias: [], templates: [],
         edNombre: '', edAsunto: '', edAsuntoB: '', edAsuntoC: '', edTestAb: 0,
         edCuerpo: '', edCuerpoB: '', edCuerpoC: '', edTipo: 'html', edFocus: 'edCuerpo',
         edCategoria: '',
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
            '03 En Conversación',
            '04 Propuesta',
            '05 Ganado',
            '06 Perdido',
            '07 Baja'
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
            this.edCategoria = t.categoria || '';
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
        // Refactor §5.2: fuente única de cálculo de %. lzEnvioOkPct delega en
        // lzTasaExito (misma fórmula). Ambos getters se conservan porque la UI los
        // referencia por separado (tabs/lanzadera.php).
        get lzTasaExito() {
            return this.lzTotalProcesados > 0 ? Math.round((this.lzEnvioOkCount / this.lzTotalProcesados) * 100) : 0;
        },
        get lzEnvioOkPct() {
            return this.lzTasaExito;
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
            // Inicializa el desplegable de federaciones de los chips del Kanban.
            this.initFederacionesFiltro();

            // ─── Notificador global de background (polling asíncrono) ─────────
            // Consulta el contador de respuestas sin notificar cada 30s,
            // independientemente del tab activo (Kanban, Lanzadera, Respuestas).
            this.checkUnreadNotifications();
            setInterval(() => this.checkUnreadNotifications(), 30000);
            // ─── Carga diferida del Kanban (FASE 3) ─────────────────────────
            // Tras el primer render de Alpine, inicializa los IntersectionObserver
            // de los footers "Cargar más" de cada columna para auto-cargar al
            // llegar al final del scroll.
            setTimeout(() => this.initObservadoresColumnas(), 300);
            try { await this.loadGestor(); } catch (e) { console.error('boot: loadGestor falló', e); }

            try { await this.loadSmtp(); } catch (e) { console.error('boot: loadSmtp falló', e); }
            try { await this.bootLanzadera(); } catch (e) { console.error('boot: bootLanzadera falló', e); }
            try { await this.loadMockupCapacity(); } catch (e) { console.error('boot: loadMockupCapacity falló', e); }
        },

        // ─── Notificador global de background ────────────────────────────────
        // Consulta el contador de respuestas sin notificar (endpoint ligero
        // get_unread_count) y actualiza el badge de la campana. Se ejecuta en
        // boot() y cada 30s, independientemente del tab activo.
        async checkUnreadNotifications() {
            try {
                // IMPORTANTE: analytics.php NO es un endpoint standalone (es incluido
                // por dashboard.php que define $db y $action). Por eso el polling debe
                // apuntar al orquestador dashboard.php?action=get_unread_count, igual
                // que el resto de endpoints delegados de la app. Llamar directamente a
                // api/analytics.php devolvía una respuesta vacía (error fatal PHP por
                // $db/$action indefinidos) → "Unexpected end of JSON input".
                const r = await fetch('dashboard.php?action=get_unread_count');
                const j = await r.json();
                if (j && j.success) {
                    this.rsNuevas = parseInt(j.unread) || 0;
                }
            } catch (e) {
                // Silencioso: el polling no debe romper la app si falla la red.
                console.error('checkUnreadNotifications:', e);
            }
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

        // ─── FILTROS RÁPIDOS KANBAN (FASE 3) ────────────────────────────────
        // Devuelve el conjunto de IDs de leads que cumplen el filtro activo
        // (chip) + la búsqueda en tiempo real. Se usa en x-show de cada tarjeta.
        get leadsFiltrados() {
            const q = (this.busqueda || '').trim().toLowerCase();
            const fa = this.filtroActivo;
            const ff = this.filtroFederacion;
            return (this.kanbanLeads || []).filter(l => {
                // Filtro por chip activo
                if (fa === 'calientes' && !(l.num_opens >= 2)) return false;
                if (fa === 'leidos' && !(l.num_opens >= 1)) return false;
                if (fa === 'pendiente_wa' && !(l.tiene_whatsapp === 1 && (l.estado_lead === '01 Sin Contactar' || l.estado_lead === '02 Contactado'))) return false;
                if (fa === 'federacion' && ff && l.federacion !== ff) return false;
                // Búsqueda en tiempo real por nombre de club
                if (q && !String(l.nombre_club || '').toLowerCase().includes(q)) return false;
                return true;
            }).map(l => l.id);
        },
        // Conjunto de IDs visibles (Set) para consulta O(1) en x-show.
        get leadsFiltradosSet() {
            return new Set(this.leadsFiltrados);
        },
        // Indica si una tarjeta debe mostrarse según el filtro activo.
        leadVisible(id) {
            return this.leadsFiltradosSet.has(id);
        },
        // Activa/desactiva un chip de filtro (toggle).
        toggleFiltro(filtro) {
            if (this.filtroActivo === filtro) {
                this.filtroActivo = '';
            } else {
                this.filtroActivo = filtro;
            }
        },
        // Establece el filtro de federación desde el desplegable.
        setFiltroFederacion(fed) {
            this.filtroFederacion = fed;
            if (fed) {
                this.filtroActivo = 'federacion';
            } else if (this.filtroActivo === 'federacion') {
                this.filtroActivo = '';
            }
        },
        // Limpia todos los filtros y la búsqueda.
        limpiarFiltros() {
            this.filtroActivo = '';
            this.filtroFederacion = '';
            this.busqueda = '';
        },
        // Debounce de la búsqueda en tiempo real (150-200ms).
        onBusquedaInput() {
            if (this._busquedaTimer) clearTimeout(this._busquedaTimer);
            this._busquedaTimer = setTimeout(() => {
                // La búsqueda ya es reactiva vía x-model; este timer solo evita
                // recalcular el Set en cada keystroke si hubiera lógica pesada.
                this._busquedaTimer = null;
            }, 180);
        },
        // Puebla el desplegable de federaciones desde los contadores del servidor.
        initFederacionesFiltro() {
            const feds = (this.chipCounters && this.chipCounters.federaciones) || {};
            this.federacionesFiltro = Object.keys(feds).sort();
        },

        // ─── CARGA DIFERIDA / INFINITE SCROLL POR COLUMNA (FASE 3) ──────────
        // Límite de tarjetas visibles por columna (renderizado diferido).
        // Se inicializa a 20 para no colapsar el DOM con +1.600 tarjetas.
        limitesColumnas: {},
        // Incremento de carga al pulsar "Cargar más" / IntersectionObserver.
        pasoCarga: 30,
        // Devuelve el límite actual de una columna (default 20).
        limiteColumna(estado) {
            return this.limitesColumnas[estado] || 20;
        },
        // Devuelve el total REAL de leads de una columna (sin filtro).
        // Se calcula desde kanbanLeads (array plano expuesto por el servidor).
        totalColumna(estado) {
            return (this.kanbanLeads || []).filter(l => l.estado_lead === estado).length;
        },
        // Incrementa el límite de una columna en +pasoCarga (30).
        cargarMas(estado) {
            this.limitesColumnas[estado] = (this.limitesColumnas[estado] || 20) + this.pasoCarga;
        },
        // Reinicia los límites de todas las columnas a 20 (al cambiar de filtro).
        resetLimitesColumnas() {
            this.limitesColumnas = {};
        },
        // Observa el footer "Cargar más" de una columna para auto-cargar al
        // llegar al final del scroll (IntersectionObserver). Se llama desde
        // boot() tras el primer render.
        observarCargaColumna(estado) {
            const el = document.querySelector('[data-cargar-mas="' + estado + '"]');
            if (!el || typeof IntersectionObserver === 'undefined') return;
            if (this._obsColumna) this._obsColumna.disconnect();
            this._obsColumna = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        this.cargarMas(estado);
                    }
                });
            }, { root: null, rootMargin: '200px' });
            this._obsColumna.observe(el);
        },
        // Inicializa los observadores de todas las columnas tras el render.
        initObservadoresColumnas() {
            const estados = this.estadosLead || [];
            estados.forEach((est) => this.observarCargaColumna(est));
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
                     + this.fr('Estado', a.estado_lead) + '<div class="mt-1"><strong class="text-slate-400">Notas:</strong><br>' + this.esc(a.observaciones || '(sin notas)') + '</div>';
            this.mhb = this.fr('Club', b.nombre_club) + this.fr('Email', b.email) + this.fr('Fed', b.federacion || '')
                     + this.fr('Contacto', b.persona_contacto) + this.fr('Movil', b.telefono_movil) + this.fr('Fijo', b.telefono_fijo)
                     + this.fr('Estado', b.estado_lead) + '<div class="mt-1"><strong class="text-slate-400">Notas:</strong><br>' + this.esc(b.observaciones || '(sin notas)') + '</div>';
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
        fr(label, val) { return '<div><strong class="text-slate-400 text-[9px]">' + label + ':</strong> ' + this.esc(val || '-') + '</div>'; },
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

        // ─── Delete Lead (eliminar duplicado directamente) ────────────────────
        async deleteLead(id) {
            if (!id) return;
            if (!confirm('¿Eliminar definitivamente el registro #' + id + '?\n\nEsta acción no se puede deshacer.')) return;
            const fd = new FormData(); fd.append('action', 'delete_lead'); fd.append('id', id);
            const r = await fetch('api/leads.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) { this.mm = false; location.reload(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },

        // ─── Omitir Duplicado (desmarcar para que vuelva a la lanzadera) ─────
        async omitirDuplicado(id) {
            if (!id) return;
            if (!confirm('¿Omitir este registro como duplicado?\n\nSe desmarcará y volverá a ser elegible en la lanzadera (se enviará igualmente aunque comparta nombre con otro club).')) return;
            const fd = new FormData(); fd.append('action', 'omitir_duplicado'); fd.append('id', id);
            const r = await fetch('api/leads.php', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) { this.mm = false; location.reload(); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
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
                search: this.gs, estado: this.ge, federacion: this.gf, duplicado: this.gd

            });
            const r = await fetch('api/leads.php?' + p.toString());
            const j = await r.json();
            if (!j.ok) return;
            this.gt = j.total + ' resultados';
            // Refactor §5.4: renderizado extraído a funciones de plantilla.
            document.getElementById('gestorBody').innerHTML = this.renderGestorRows(j.data);
            document.getElementById('gestorP').innerHTML = this.renderGestorPaginacion(j.total_pages);
        },
        // Renderizado de filas del Gestor (extraído de loadGestor, refactor §5.4).
        renderGestorRows(rows) {
            let h = '';
            rows.forEach(l => {
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><span class="font-medium text-slate-300">' + this.esc(l.nombre_club) + '</span>'
                   + (l.es_duplicado == 1 ? ' <span class="bg-amber-500/15 text-amber-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-pointer" onclick="window.app.openMerge(' + l.duplicado_id + ',' + l.id + ')">DUPLICADO</span>' : '')
                   + '</td>'
                   + '<td class="px-3 py-2 hidden md:table-cell"><code class="text-[10px] text-slate-400">' + this.esc(l.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden md:table-cell text-[10px] text-slate-400 font-mono">' + this.esc(l.telefono_movil || '-') + '</td>'
                   + '<td class="px-3 py-2 text-[10px] text-slate-400">' + this.esc(l.estado_lead) + '</td>'
                   + '<td class="px-3 py-2 hidden lg:table-cell text-[10px] text-slate-400">' + this.esc(l.federacion || '') + '</td>'
                   + '<td class="px-3 py-2 text-right"><button class="px-2 py-1 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-400 hover:text-slate-200 hover:border-slate-600 transition" onclick="window.app.openLead(' + l.id + ')">Ficha</button></td>'
                   + '</tr>';
            });
            return h || '<tr><td colspan="6" class="px-3 py-8 text-center text-slate-400">Sin resultados</td></tr>';
        },
        // Renderizado de paginación del Gestor (extraído de loadGestor, refactor §5.4).
        renderGestorPaginacion(totalPages) {
            const tp = totalPages; const cp = this.gp;
            let pg = '';
            let s = Math.max(1, cp - 2); let e = Math.min(tp, cp + 2);
            const bpg = (n) => '<button class="px-2 py-0.5 text-[10px] rounded border ' + (n === cp ? 'bg-slate-700 border-slate-600 text-slate-200' : 'border-slate-800 text-slate-400 hover:text-slate-300') + '" onclick="window.app.gp=' + n + ';window.app.loadGestor()" title="Ir a pagina ' + n + '">' + n + '</button>';
            if (s > 1) { pg += bpg(1); if (s > 2) pg += '<span class="px-1 text-slate-400">…</span>'; }
            for (let i = s; i <= e; i++) pg += bpg(i);
            if (e < tp) { if (e < tp - 1) pg += '<span class="px-1 text-slate-400">…</span>'; pg += bpg(tp); }
            return pg;
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
            // 'Todas las categorías' (ec vacío) → cargar todas las plantillas.
            const r = await fetch('?action=get_templates' + (this.ec ? '&categoria=' + encodeURIComponent(this.ec) : ''));
            const j = await r.json();
            if (j.ok) this.templates = j.templates;
            setTimeout(() => lucide.createIcons(), 50);
        },
        nuevaPlantilla() {
            this.et = ''; this.en = true;
            this.edNombre = 'Nueva plantilla'; this.edAsunto = ''; this.edAsuntoB = ''; this.edAsuntoC = ''; this.edTestAb = 0;
            this.edCuerpo = ''; this.edCuerpoB = ''; this.edCuerpoC = ''; this.edTipo = this.edPlataforma === 'whatsapp' ? 'whatsapp' : 'html';
            this.edCategoria = this.ec || '';
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
            f.append('categoria', (this.edCategoria || '').trim()); f.append('activo', '1');
            const r = await fetch('', { method: 'POST', body: f }); const j = await r.json();
            if (j.ok) { this.en = false; this.et = j.id; this.onCategoriaChange(); alert('Plantilla guardada'); }
            else { alert('Error: ' + (j.error || 'Desconocido')); }
        },
        insertTag(tag) {
            // El textarea A del bloque A/B/C usa x-model="edCuerpo" (mismo modelo
            // que el cuerpo único). Por eso, cuando edFocus es 'edCuerpoA', se debe
            // escribir en this.edCuerpo, no en una propiedad inexistente edCuerpoA.
            const campo = this.edFocus === 'edCuerpoB' ? 'edCuerpoB' : (this.edFocus === 'edCuerpoC' ? 'edCuerpoC' : 'edCuerpo');
            const el = document.getElementById(this.edFocus === 'edCuerpoA' ? 'edCuerpoA' : campo);
            const val = this[campo] || '';
            const start = el ? el.selectionStart : val.length;
            const end = el ? el.selectionEnd : start;
            this[campo] = val.slice(0, start) + tag + val.slice(end);
            if (el) {
                const pos = start + tag.length;
                el.focus();
                el.setSelectionRange(pos, pos);
            }
            this.renderLivePreview();
        },
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
            // Refactor §5.3: validaciones extraídas a validarPruebaEmail().
            const error = this.validarPruebaEmail();
            if (error) { alert(error); return; }
            const emails = this.testEmailsList;

            // ─── Selección de leads SOLO compatibles con la campaña ─────────────
            // Refactor §5.3: extraído a obtenerCandidatosPrueba(). Nunca se usa
            // get_leads_table sin filtro de compatibilidad TEST/REAL; se reutiliza
            // lzCola o se pide a get_cola.php con campaign_id (que aplica
            // sqlFiltroCompatibilidadLeadCampana()).
            const candidatos = await this.obtenerCandidatosPrueba();
            if (candidatos.length === 0) { alert('No hay leads compatibles con la campaña seleccionada para la prueba.\nCargue una cola válida o amplíe el universo TEST.\nNo se ha enviado nada.'); return; }

            const smtp = this.lzCuentaActiva;
            if (!smtp || !smtp.id) { alert('No hay cuentas SMTP configuradas.'); return; }
            const tpl = (this.lzTemplatesEmail || []).find(t => t.id == this.lzIdPlantillaEmail);
            const esAbc = tpl && parseInt(tpl.test_ab) === 1;

            // ─── Selección A/B/C: buscar leads que cubran las variantes ─────────
            // Refactor §5.3: extraído a armarSeleccionPrueba(). La variante la
            // calcula el servidor (get_cola.php → asignarVariante()); aquí solo se
            // elige un lead distinto por cada variante.
            const seleccion = this.armarSeleccionPrueba(candidatos, esAbc);
            if (!seleccion) return;

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
        // Validaciones previas de una prueba de envío (refactor §5.3).
        // Devuelve string de error (para alert) o null si todo es correcto.
        validarPruebaEmail() {
            if (!this.lzCampaignId) return 'Selecciona una campaña antes de enviar.';
            if (!this.lzIdPlantillaEmail) return 'Selecciona primero una plantilla de email en la configuración del lote.';
            const emails = this.testEmailsList;
            if (emails.length === 0) return 'Configura al menos un email de prueba en "Destinos de Prueba".';
            return null;
        },
        // Obtiene leads compatibles con la campaña para la prueba (refactor §5.3).
        async obtenerCandidatosPrueba() {
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
            return candidatos;
        },
        // Selección de leads de prueba cubriendo las variantes A/B/C (refactor §5.3).
        // Devuelve [{variante, club}] o null si no hay cobertura suficiente (ya alerta).
        armarSeleccionPrueba(candidatos, esAbc) {
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
                    return null;
                }
                seleccion = [
                    { variante: 'A', club: porVariante.A },
                    { variante: 'B', club: porVariante.B },
                    { variante: 'C', club: porVariante.C },
                ];
            } else {
                seleccion = [{ variante: 'A', club: candidatos[0] }];
            }
            return seleccion;
        },
        async lzOnEstadoChange() {
            this.lzIdPlantillaEmail = ''; this.lzTemplatesEmail = []; if (!this.lzEstadoLead) return;
            // incluir_genericas=1: además de las de la categoría (estado), se ofrecen
            // las plantillas sin categoría (genéricas) para cualquier estado.
            try { const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(this.lzEstadoLead) + '&incluir_genericas=1'); const j = await r.json();
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

            // Refactor §5.1: la lógica de cada flujo vive en su propio método.
            // Si hay un lead seleccionado en "Envío Dirigido", se envía SOLO a ese
            // lead, ignorando la cola. Si no, se procesa la cola normal por lotes.
            const dirigido = this.lzSelectedLeadId > 0 && this.lzSelectedLead;
            if (dirigido) {
                await this.enviarDirigido();
            } else {
                await this.enviarCola();
            }
        },

        // ─── CASO A: Envío dirigido (un único lead seleccionado) ──────────────
        // Extraído de iniciarMotor (refactor §5.1). El tamaño de lote se fuerza a 1.
        async enviarDirigido() {
            if (!this.lzIdPlantillaEmail) { alert('Selecciona una plantilla de email antes de enviar.'); return; }
            this.lzMotorEstado = 'ACTIVO'; this.lzAbortController = new AbortController(); const signal = this.lzAbortController.signal;
            this.lzSendCalls = 0;
            const lead = this.lzSelectedLead;
            const r = Math.random(); const vAb = r < 0.333 ? 'A' : (r < 0.666 ? 'B' : 'C');
            const fd = new FormData();
            fd.append('id_club', lead.id);
            fd.append('id_plantilla', this.lzIdPlantillaEmail);
            fd.append('id_cuenta_smtp', (this.lzCuentaActiva || {}).id || '');
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
        },

        // ─── CASO B: Cola normal con límite de lote ───────────────────────────
        // Extraído de iniciarMotor (refactor §5.1).
        async enviarCola() {
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
            // Refactor §5.4: renderizado extraído a función de plantilla.
            document.getElementById('smtpBody').innerHTML = this.renderSmtpRows(j.accounts);
            setTimeout(() => lucide.createIcons(), 50);
        },
        // Renderizado de filas de cuentas SMTP (extraído de loadSmtp, refactor §5.4).
        renderSmtpRows(accounts) {
            let h = '';
            accounts.forEach(a => {
                const usados = parseInt(a.enviados_hoy || 0, 10);
                const limite = parseInt(a.limite_diario || 0, 10);
                const pct = limite > 0 ? Math.min(100, Math.round((usados / limite) * 100)) : 0;
                const barColor = pct >= 90 ? 'bg-rose-500' : (pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500');
                const smtpDot = a.activa == 1
                    ? '<span class="inline-flex items-center gap-1.5 text-emerald-400"><span class="w-2 h-2 rounded-full bg-emerald-400"></span><span class="text-xs font-semibold">ON</span></span>'
                    : '<span class="inline-flex items-center gap-1.5 text-slate-400"><span class="w-2 h-2 rounded-full bg-slate-500"></span><span class="text-xs font-semibold">OFF</span></span>';
                const imapDot = a.ultimo_error
                    ? '<span class="inline-flex items-center gap-1.5 text-rose-400" title="' + this.esc(a.ultimo_error) + '"><span class="w-2 h-2 rounded-full bg-rose-400"></span><span class="text-xs font-semibold">ERR</span></span>'
                    : '<span class="inline-flex items-center gap-1.5 text-emerald-400"><span class="w-2 h-2 rounded-full bg-emerald-400"></span><span class="text-xs font-semibold">OK</span></span>';
                const usoColor = usados >= limite ? 'text-rose-400' : 'text-slate-300';
                h += '<tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">'
                   + '<td class="px-3 py-2"><code class="text-sm text-slate-300">' + this.esc(a.email) + '</code></td>'
                   + '<td class="px-3 py-2 hidden sm:table-cell text-sm text-slate-400">' + this.esc(a.host) + ':' + a.puerto + '</td>'
                   + '<td class="px-3 py-2"><div class="flex items-center gap-2">'
                   + '<div class="flex-1 bg-slate-700 rounded-full h-2"><div class="h-2 rounded-full transition-all duration-500 ' + barColor + '" style="width:' + pct + '%"></div></div>'
                   + '<span class="text-xs w-14 text-right ' + usoColor + '">' + usados + ' / ' + limite + '</span>'
                   + '</div></td>'
                   + '<td class="px-3 py-2 text-center">' + smtpDot + '</td>'
                   + '<td class="px-3 py-2 text-center">' + imapDot + '</td>'
                   + '<td class="px-3 py-2 text-right"><div class="flex gap-1 justify-end">'
                   + '<button class="px-2 py-1 bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 rounded text-xs hover:bg-cyan-500/20 transition" onclick="window.app.testSmtp(' + a.id + ',this)"><i data-lucide="zap" class="w-3.5 h-3.5"></i></button>'
                   + '<button class="px-2 py-1 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded text-xs hover:bg-amber-500/20 transition" onclick="window.app.toggleSmtp(' + a.id + ')"><i data-lucide="power" class="w-3.5 h-3.5"></i></button>'
                   + '<button class="px-2 py-1 bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded text-xs hover:bg-blue-500/20 transition" onclick="window.app.openSmtp(' + a.id + ')"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></button>'
                   + '<button class="px-2 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded text-xs hover:bg-rose-500/20 transition" onclick="window.app.deleteSmtp(' + a.id + ')"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>'
                   + '</div></td></tr>';
            });
            return h || '<tr><td colspan="6" class="px-3 py-8 text-center text-slate-400">Sin cuentas</td></tr>';
        },

        async openSmtp(id) {
            this.se = id;
            this.sp = false;
            if (id > 0) { const r = await fetch('api/smtp.php?action=get_accounts'); const j = await r.json(); const a = j.accounts.find(x => x.id == id);
                if (a) { this.sf = { email: a.email, host: a.host, puerto: a.puerto, usuario: a.usuario, password: a.password || '', seguridad: a.seguridad, limite_diario: a.limite_diario, nombre_emisor: a.nombre_emisor || '', cargo_emisor: a.cargo_emisor || '' }; }
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

        // ─── Respuestas (FASE 4C + FASE FG: bandeja de conversaciones) ────
        async loadRespuestas() {
            this.respuestas = [];
            try {
                const p = new URLSearchParams({ action: 'get_respuestas' });
                if (this.respuestasFiltro) p.append('clasificacion', this.respuestasFiltro);
                if (this.respuestasPrioridad) p.append('prioridad', this.respuestasPrioridad);
                const r = await fetch('?' + p.toString());
                const j = await r.json();
                if (j && j.ok) this.respuestas = j.conversaciones || [];
                // ─── Limpieza de estado (UNIBOX UI) ─────────────────────────
                // Si la conversación seleccionada ya no existe en la lista
                // recargada (p.ej. tras un envío o un cambio de filtro), se
                // limpia el visor derecho para evitar referencias huérfanas.
                if (this.rsSeleccion) {
                    const selClave = this.rsSeleccion.clave || ('lead:' + this.rsSeleccion.lead_id);
                    const sigue = (this.respuestas || []).some(c => (c.clave || ('lead:' + c.lead_id)) === selClave);
                    if (!sigue) {
                        this.rsSeleccion = null;
                        this.rsRedaccion = '';
                        this.rsPlantillaRapida = '';
                        this.rsEnvioMsg = '';
                        this.rsEnvioMsgOk = false;
                        this.rsWaUrl = '';
                    }
                }
                // ─── Notificación de nuevas respuestas (FASE G) ─────────────
                const totalNuevas = (this.respuestas || []).reduce((acc, c) => acc + (parseInt(c.nuevas) || 0), 0);
                this.rsNuevas = totalNuevas;
                if (totalNuevas > 0 && !sessionStorage.getItem('rs_toast_mostrado')) {
                    sessionStorage.setItem('rs_toast_mostrado', '1');
                    this.mostrarToast('🔔 ' + totalNuevas + ' nueva' + (totalNuevas === 1 ? '' : 's') + ' respuesta' + (totalNuevas === 1 ? '' : 's') + ' sin revisar');
                }
            } catch (e) { console.error('loadRespuestas:', e); }
        },
        // Dispara la sincronización IMAP/POP3 de respuestas (botón "Actualizar")
        // y recarga la bandeja. El sync se ejecuta en el servidor (endpoint
        // sync_respuestas, autenticado por sesión) sin exponer el token del cron.
        async syncRespuestas() {
            this.rsSyncing = true;
            this.rsSyncMsg = '';
            try {
                const r = await fetch('?action=sync_respuestas');
                const j = await r.json();
                if (j && j.ok) {
                    this.rsSyncMsg = (j.resumen && j.resumen.length)
                        ? j.resumen.join(' · ')
                        : 'Sincronización completada';
                } else {
                    this.rsSyncMsg = 'Error al sincronizar: ' + (j && j.error ? j.error : 'desconocido');
                }
            } catch (e) {
                console.error('syncRespuestas:', e);
                this.rsSyncMsg = 'Error de red al sincronizar';
            } finally {
                this.rsSyncing = false;
                // Recargar la bandeja tras el sync para mostrar los nuevos correos.
                await this.loadRespuestas();
            }
        },
        // Muestra un toast de notificación (auto-cierre)
        mostrarToast(msg) {

            this.rsToast = msg;
            this.rsToastVisible = true;
            if (this.rsToastTimer) clearTimeout(this.rsToastTimer);
            this.rsToastTimer = setTimeout(() => { this.rsToastVisible = false; this.rsToast = ''; }, 6000);
        },
        // Navega al tab de respuestas y limpia el aviso de nuevas
        irARespuestas() {
            this.tab = 'respuestas';
            this.rsToastVisible = false;
            this.rsToast = '';
            this.loadRespuestas();
        },

        // Abre el hilo de conversación completo de un lead
        abrirConversacion(conv) {
            this.rsConversacion = conv;
            this.rsConversacionModal = true;
            setTimeout(() => lucide.createIcons(), 50);
        },
        cerrarConversacion() {
            this.rsConversacionModal = false;
            this.rsConversacion = null;
        },
        // Abre la conversación de un lead desde el Kanban (icono Mail).
        // Busca la conversación por lead_id en la bandeja de respuestas y
        // navega al tab de Respuestas seleccionando la conversación en el
        // split-view (rsSeleccionar). Si aún no hay respuestas cargadas,
        // las carga primero.
        async abrirConversacionLead(leadId) {
            if (!leadId) return;
            if (!this.respuestas || this.respuestas.length === 0) {
                await this.loadRespuestas();
            }
            const conv = (this.respuestas || []).find(c => String(c.lead_id) === String(leadId));
            if (conv) {
                // Navega al tab de Respuestas y selecciona la conversación en
                // el panel derecho (split-view), reutilizando la UI existente.
                this.tab = 'respuestas';
                this.rsSeleccionar(conv);
            } else {
                alert('No hay conversación registrada para este lead.');
            }
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
                    if (this.rsConversacion && this.rsConversacion.mensajes) {
                        const m = this.rsConversacion.mensajes.find(x => x.id == id);
                        if (m) m.clasificacion = j.clasificacion;
                    }
                    this.loadRespuestas();
                } else {
                    alert('Error: ' + (j.error || 'Desconocido'));
                }
            } catch (e) { console.error('clasificarRespuesta:', e); }
        },
        // Helpers de presentación para la bandeja de conversaciones
        rsClasLabel(clas) {
            const mapa = { POSITIVE: 'Positiva', NEGATIVE: 'Rebote', UNSUBSCRIBE: 'Baja', OOO: 'Fuera de oficina', NEUTRAL: 'Automática', PENDING: 'Pendiente' };
            return mapa[clas] || clas || '—';
        },
        rsClasColor(clas) {
            const mapa = { POSITIVE: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30', NEGATIVE: 'text-rose-400 bg-rose-500/10 border-rose-500/30', UNSUBSCRIBE: 'text-amber-400 bg-amber-500/10 border-amber-500/30', OOO: 'text-sky-400 bg-sky-500/10 border-sky-500/30', NEUTRAL: 'text-slate-400 bg-slate-500/10 border-slate-500/30', PENDING: 'text-slate-400 bg-slate-500/10 border-slate-500/30' };
            return mapa[clas] || 'text-slate-400 bg-slate-500/10 border-slate-500/30';
        },
        rsPrioLabel(p) {
            const mapa = { alta: 'Alta', media: 'Media', baja: 'Baja' };
            return mapa[p] || p || '—';
        },
        rsPrioColor(p) {
            const mapa = { alta: 'text-rose-400 bg-rose-500/10 border-rose-500/30', media: 'text-amber-400 bg-amber-500/10 border-amber-500/30', baja: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' };
            return mapa[p] || 'text-slate-400 bg-slate-500/10 border-slate-500/30';
        },
        rsPrioDot(p) {
            const mapa = { alta: 'bg-rose-500', media: 'bg-amber-500', baja: 'bg-emerald-500' };
            return mapa[p] || 'bg-slate-500';
        },
        rsFmtFecha(f) {
            if (!f) return '—';
            const d = new Date(f);
            if (isNaN(d.getTime())) return f;
            return d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        },
        rsUltimoMensaje(conv) {
            if (!conv || !conv.mensajes || conv.mensajes.length === 0) return '';
            const m = conv.mensajes[0];
            return (m.subject_respuesta || m.asunto_envio || '') + ' — ' + (m.remitente || m.email || '');
        },
        rsEsEntrante(m) {
            // Entrante = respuesta del lead (tiene remitente distinto del envío)
            return !!m.remitente && m.remitente !== m.email;
        },

        // ─── UNIBOX SPLIT-VIEW (FASE UNIBOX UI) ─────────────────────────────
        // Devuelve la lista de conversaciones filtradas por búsqueda y clasificación.
        get rsFiltradas() {
            const q = (this.rsBusqueda || '').trim().toLowerCase();
            const cl = this.rsFiltroClas;
            return (this.respuestas || []).filter(c => {
                if (q && !String(c.nombre_club || '').toLowerCase().includes(q)) return false;
                // Clasificación efectiva de la conversación: la del último mensaje
                // (mismo criterio que rsIntencion), con fallback a nivel de conversación.
                const ultimo = (c.mensajes && c.mensajes.length > 0) ? c.mensajes[0] : null;
                const clas = String((ultimo && (ultimo.clasificacion || ultimo.clas)) || c.clasificacion || c.clas || 'PENDING').toUpperCase();
                if (cl) {
                    if (cl === 'INTERESADO' && !['POSITIVE', 'INTERESADO'].includes(clas)) return false;
                    if (cl === 'DUDA' && !['DUDA PRECIO', 'NEUTRAL', 'PENDING', 'OOO'].includes(clas)) return false;
                    if (cl === 'BAJA' && !['UNSUBSCRIBE', 'DESUSCRIPCION', 'NO INTERESA'].includes(clas)) return false;
                    // Solo rebotes: muestra únicamente conversaciones cuyo último mensaje es un rebote.
                    if (cl === 'REBOTE' && !['NEGATIVE', 'REBOTE'].includes(clas)) return false;
                    // Ocultar rebotes: excluye las conversaciones cuyo último mensaje es un rebote.
                    if (cl === 'SIN_REBOTE' && ['NEGATIVE', 'REBOTE'].includes(clas)) return false;
                }
                return true;
            });
        },
        // Selecciona una conversación en el panel derecho y construye el enlace WhatsApp.
        rsSeleccionar(conv) {
            this.rsSeleccion = conv;
            this.rsRedaccion = '';
            this.rsPlantillaRapida = '';
            this.rsEnvioMsg = '';
            this.rsEnvioMsgOk = false;
            this.rsWaUrl = this.rsConstruirWaUrl(conv);
            setTimeout(() => lucide.createIcons(), 50);
        },
        // Construye el enlace dinámico de WhatsApp para el lead seleccionado.
        rsConstruirWaUrl(conv) {
            if (!conv) return '';
            const tel = String(conv.telefono || conv.telefono_movil || '').replace(/[^0-9]/g, '');
            const num = tel.match(/([67]\d{8})/);
            if (!num) return '';
            const contacto = encodeURIComponent(conv.contacto_nombre || conv.persona_contacto || 'responsable');
            const texto = 'Hola%20' + contacto + ',%20vi%20tu%20respuesta%20sobre%20las%20espinilleras...';
            return 'https://wa.me/34' + num[1] + '?text=' + texto;
        },
        // Aplica una plantilla rápida al editor de respuesta.
        rsAplicarPlantillaRapida() {
            const tpl = (this.rsPlantillasRapidas || []).find(t => t.id === this.rsPlantillaRapida);
            if (!tpl || !this.rsSeleccion) return;
            const conv = this.rsSeleccion;
            this.rsRedaccion = tpl.cuerpo
                .replace(/{{CONTACTO}}/g, conv.contacto_nombre || conv.persona_contacto || 'responsable')
                .replace(/{{VOLUMEN}}/g, conv.volumen_equipos || conv.volumen_estimado || '');
        },
        // Actualiza el estado del lead en clubes_crm en tiempo real.
        async rsActualizarEstadoLead() {
            if (!this.rsSeleccion || !this.rsSeleccion.lead_id) return;
            const f = new FormData();
            f.append('action', 'actualizar_estado_lead');
            f.append('lead_id', this.rsSeleccion.lead_id);
            f.append('estado', this.rsSeleccion.estado_lead || '');
            try {
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j && j.ok) {
                    this.rsEnvioMsg = 'Estado actualizado a "' + (this.rsSeleccion.estado_lead || '') + '".';
                    this.rsEnvioMsgOk = true;
                } else {
                    this.rsEnvioMsg = 'Error: ' + (j.error || 'No se pudo actualizar el estado.');
                    this.rsEnvioMsgOk = false;
                }
            } catch (e) {
                this.rsEnvioMsg = 'Error de conexión al actualizar el estado.';
                this.rsEnvioMsgOk = false;
            }
        },
        // Envía la respuesta redactada por SMTP usando la cuenta del lead.
        async rsEnviarRespuesta() {
            if (!this.rsSeleccion) return;
            if (!this.rsRedaccion.trim()) { this.rsEnvioMsg = 'Escribe una respuesta antes de enviar.'; this.rsEnvioMsgOk = false; return; }
            this.rsEnviando = true;
            this.rsEnvioMsg = '';
            const f = new FormData();
            f.append('action', 'enviar_respuesta_smtp');
            f.append('lead_id', this.rsSeleccion.lead_id || '');
            f.append('email', this.rsSeleccion.email || this.rsSeleccion.remitente || '');
            f.append('cuerpo', this.rsRedaccion);
            f.append('asunto', 'Re: ' + (this.rsSeleccion.subject || this.rsSeleccion.asunto_envio || ''));
            f.append('envio_id', this.rsSeleccion.envio_id || '');
            try {
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j && j.ok) {
                    this.rsEnvioMsg = 'Respuesta enviada correctamente.';
                    this.rsEnvioMsgOk = true;
                    this.rsRedaccion = '';
                    this.rsPlantillaRapida = '';
                    this.loadRespuestas();
                } else {
                    this.rsEnvioMsg = 'Error: ' + (j.error || 'No se pudo enviar la respuesta.');
                    this.rsEnvioMsgOk = false;
                }
            } catch (e) {
                this.rsEnvioMsg = 'Error de conexión al enviar la respuesta.';
                this.rsEnvioMsgOk = false;
            }
            this.rsEnviando = false;
        },
        // Devuelve la etiqueta de intención para un badge según la clasificación.
        // La clasificación se lee del último mensaje de la conversación (el cuerpo
        // y la clasificación viven en cada mensaje, no a nivel de conversación).
        rsIntencion(conv) {
            if (!conv) return { label: '', color: '' };
            const ultimo = (conv.mensajes && conv.mensajes.length > 0) ? conv.mensajes[0] : null;
            const clas = String((ultimo && (ultimo.clasificacion || ultimo.clas)) || conv.clasificacion || conv.clas || 'PENDING').toUpperCase();
            const m = this.rsIntencionMap[clas] || this.rsIntencionMap.PENDING;
            return m;
        },
        // Devuelve el snippet (primeros 110 caracteres) del cuerpo de la respuesta.
        // El cuerpo se lee del último mensaje de la conversación (cuerpo o
        // contenido_html), con fallback al snippet del payload.
        rsSnippet(conv) {
            if (!conv) return '';
            const ultimo = (conv.mensajes && conv.mensajes.length > 0) ? conv.mensajes[0] : null;
            let cuerpo = '';
            if (ultimo) {
                cuerpo = ultimo.cuerpo_texto || ultimo.cuerpo || ultimo.contenido_html || '';
            }
            if (!cuerpo) cuerpo = conv.cuerpo_texto || conv.cuerpo || conv.snippet || '';
            // Si es HTML, extraer solo el texto visible.
            if (cuerpo && /<[a-z][\s\S]*>/i.test(cuerpo)) {
                const d = document.createElement('div');
                d.innerHTML = cuerpo;
                cuerpo = d.textContent || '';
            }
            const limpio = String(cuerpo).replace(/\s+/g, ' ').trim();
            return limpio.length > 110 ? limpio.slice(0, 110) + '…' : limpio;
        },
        // Devuelve el volumen de equipos formateado (Ej: "12 Equipos").
        rsVolumenLabel(conv) {
            if (!conv) return '';
            const v = parseInt(conv.volumen_equipos || conv.volumen_estimado) || 0;
            if (v <= 0) return '';
            return v + ' Equipos';
        },
        // Devuelve el teléfono limpio del lead (para WhatsApp).
        rsTelefonoLimpio(conv) {
            if (!conv) return '';
            return String(conv.telefono || conv.telefono_movil || '').replace(/[^0-9]/g, '');
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

        esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; },

        // Sanitiza el contenido HTML de una respuesta antes de inyectarlo en el
        // modal (x-html). Elimina scripts, iframes, event handlers y estilos
        // peligrosos para evitar XSS. Devuelve un HTML limpio y seguro.
        rsSanitizarHtml(html) {
            if (!html) return '';
            const d = document.createElement('div');
            d.innerHTML = html;
            // Eliminar elementos peligrosos
            d.querySelectorAll('script, iframe, object, embed, link, meta, style, form, input, button, textarea, select').forEach(el => el.remove());
            // Eliminar atributos de eventos y javascript: URLs
            d.querySelectorAll('*').forEach(el => {
                [...el.attributes].forEach(attr => {
                    const name = attr.name.toLowerCase();
                    if (name.startsWith('on') || (name === 'href' && /^\s*javascript:/i.test(attr.value)) || (name === 'src' && /^\s*javascript:/i.test(attr.value))) {
                        el.removeAttribute(attr.name);
                    }
                });
            });
            return d.innerHTML;
        }
    };
    window.app = i;
    return i;
    } catch (e) {
        // Captura cualquier error silencioso que interrumpa la construcción del
        // objeto de Alpine ANTES de devolverlo. Sin este fallback, app() devolvería
        // undefined y Alpine perdería el scope global → "rsSyncing is not defined".
        // IMPORTANTE: el fallback incluye las propiedades reactivas MÍNIMAS que los
        // tabs referencian directamente desde el scope raíz (Respuestas, Lista Negra,
        // Lanzadera, Kanban). Si solo incluyera rsSyncing/rsSyncMsg, un fallo de init
        // en otro tab generaría nuevos "Expression Error" en cascada en los demás.
        console.error("[Alpine app() Init Error]:", e);
        var fallback = {
            // Respuestas (UNIBOX)
            rsSyncing: false,
            rsSyncMsg: '',
            rsNuevas: 0,
            rsSeleccion: null,
            rsToast: '',
            rsToastVisible: false,
            // Lista Negra
            blSearch: '',
            blResults: [],
            blSearchMsg: '',
            blSearchMsgOk: false,
            blList: [],
            blLoading: false,
            blMsg: '',
            blMsgOk: false,
            // Lanzadera
            lzMotorEstado: 'PAUSADO',
            lzCola: [],
            lzLoading: false,
            lzError: '',
            // Kanban
            collapsed: {},
            kanbanLeads: [],
            chipCounters: { calientes: 0, leidos: 0, pendiente_wa: 0, federaciones: {} },
        // Tab activo
        tab: 'kanban',
        // ── Métodos críticos (stubs no-op) ──────────────────────────────────
        // Si app() lanza una excepción, el fallback devuelto debe incluir TAMBIÉN
        // los métodos que los botones de los tabs invocan (loadRespuestas,
        // blCargar, irARespuestas, etc.). Sin ellos, aunque las propiedades
        // reactivas existan, los @click de los tabs fallarían con "X is not a
        // function". Estos stubs evitan el colapso total del dashboard.
        boot: function () {},
        irARespuestas: function () {},
        loadRespuestas: function () {},
        blCargar: function () {},
        loadCategorias: function () {},
        abrirAnalytics: function () {},
        syncRespuestas: function () {},
        // Métodos de Respuestas (UNIBOX)
        rsSeleccionar: function () {},
        rsEnviarRespuesta: function () {},
        rsCerrarVisor: function () {},
        // Métodos de Lista Negra
        blBuscar: function () {},
        blAgregar: function () {},
        blEliminar: function () {},
        // Métodos de Lanzadera
        lzCargarCola: function () {},
        lzIniciar: function () {},
        lzPausar: function () {},
        // Métodos de Kanban
        toggleColapsar: function () {},
        setFiltro: function () {},
        // Métodos de Leads
        gCargar: function () {},
        gBuscar: function () {},
        // Métodos de Plantillas y Campañas
        edCargar: function () {},
        edGuardar: function () {}
        };
        window.app = fallback;
        return fallback;
    }

};


// ─── analyticsApp — Alpine component for Analytics tab ──────────────────────

function analyticsApp(){return{funnel:[],kpi:null,abc:[],abcGanadora:null,obj:{ganados:0,pct:0,restantes:20,tasa_cierre:0,contactos_necesarios:'-',contactados:0,facturacion:0,pares:0,margen:0,proyeccion:null},pipelines:[],tiempos:[],fPipeline:'',fVariante:'',fExcluirTest:true,
get funnelMax(){return Math.max.apply(null,this.funnel.map(function(f){return f.cnt}).concat([1]))},
get kpiCards(){if(!this.kpi)return[];return[{label:'Ganados/100 contactos',value:this.kpi.ganados_100,sub:'clubes',color:'text-emerald-400',border:'border-emerald-500/30'},{label:'Facturacion/100 contactos',value:this.kpi.fact_100+'\u20AC',sub:'estimado',color:'text-blue-400',border:'border-blue-500/30'},{label:'Pares/100 contactos',value:this.kpi.pares_100,sub:'unidades',color:'text-amber-400',border:'border-amber-500/30'},{label:'Margen Club/100 contactos',value:this.kpi.margen_100+'\u20AC',sub:'potencial',color:'text-purple-400',border:'border-purple-500/30'}]},
get conversiones(){if(!this.funnel||this.funnel.length<2)return[];var r=[];for(var i=0;i<this.funnel.length-1;i++){var a=this.funnel[i];var b=this.funnel[i+1];if(a.cnt>0&&b.pct!==undefined){var pct=b.pct;r.push({origen:a.nivel.replace(/^\d+\.\s*/,''),destino:b.nivel.replace(/^\d+\.\s*/,''),pct:pct,perdida:(100-pct)+'%'})}}return r},
get cuelloPrincipal(){var c=this.conversiones;if(!c||c.length===0)return null;var min=c[0];for(var i=1;i<c.length;i++){if(c[i].pct<min.pct)min=c[i]}return min},
get abcFilas(){if(!this.abc||this.abc.length===0)return[];var rows=[];var labels=['Leads','Entregados','Aperturas','Tasa Apertura','Respuestas','Tasa Respuesta','Resp. Positiva','Cualificados','Mockups','Presupuestos','Negociaciones','Ganados','Perdidos','Conversion','Facturacion','Pares','Fact/100','Pares/100'];var keys=['leads','entregados','aperturas','tasa_apertura','respondio','tasa_resp','interesado','cualificado','mockups','presupuestos','negociacion','ganado','perdido','conversion','facturacion','pares','fact_100','pares_100'];var sufs=['','','','%','','%','','','','','','','','%','','','',''];for(var ri=0;ri<labels.length;ri++){var row={label:labels[ri],a:'0',b:'0',c:'0',bestIndex:-1};var vals=[];for(var vi=0;vi<this.abc.length;vi++){var v=this.abc[vi][keys[ri]];var sv=v;if(typeof v==='number'){sv=v.toLocaleString()+(sufs[ri]||'')}else{sv=v||'0'}if(vi===0)row.a=sv;if(vi===1)row.b=sv;if(vi===2)row.c=sv;if(typeof v==='number')vals.push({v:v,i:vi})}if(vals.length>0){var best=vals[0];for(var bi=1;bi<vals.length;bi++){if(vals[bi].v>best.v)best=vals[bi]}row.bestIndex=best.i}rows.push(row)}return rows},
async load(){var p=new URLSearchParams({action:'get_analytics',tab:'dashboard'});if(this.fPipeline)p.append('pipeline',this.fPipeline);if(this.fVariante)p.append('variante',this.fVariante);if(!this.fExcluirTest)p.append('excluir_test','0');try{var r=await fetch('?'+p.toString());var j=await r.json();if(j.ok){this.funnel=j.funnel||[];this.kpi=j.kpi||null;this.abc=j.abc||[];this.abcGanadora=j.abc_ganadora||null;if(j.objetivo){this.obj=j.objetivo;this.obj.proyeccion=j.objetivo.tasa_cierre>0&&j.objetivo.ganados>0?Math.round(j.objetivo.ganados/j.objetivo.tasa_cierre*100/100):null}if(j.pipelines)this.pipelines=j.pipelines;if(j.tiempos)this.tiempos=j.tiempos}}catch(e){console.error('Analytics:',e)}}}}

// ─── Componentes Alpine (FIX SCOPE rsSyncing) ────────────────────────────────
// Todos los componentes se usan con paréntesis en el HTML (x-data="app()",
// x-data="analyticsApp()", x-data="pilotoAnalyticsApp()", etc.), por lo que
// Alpine evalúa las funciones globales directamente y NO es necesario (ni
// recomendable) registrarlas con Alpine.data(). Registrar 'app' como componente
// Alpine interfería con la evaluación de x-data="app" (sin paréntesis) y
// provocaba "Alpine Expression Error: rsSyncing is not defined" en el tab
// Respuestas por un problema de timing en la instanciación del componente.
// Con x-data="app()" la función global se evalúa de forma síncrona y el objeto
// devuelto (que incluye rsSyncing) queda disponible como scope raíz.
console.log('[APP VERSION] 2026-08-21-UNIBOX-SCOPE-FIX');
console.log('[DEBUG] app.js ejecutado. window.Alpine definido?', typeof window.Alpine !== 'undefined');
console.log('[DEBUG] window._cfg definido?', typeof window._cfg !== 'undefined');
console.log('[DEBUG] typeof app:', typeof app);
console.log('[DEBUG] Componentes usados con x-data="fn()" (sin registro Alpine.data).');

// ─── Toggle de contraseña SMTP (JS nativo, sin Alpine) ──────────────────────
// Movido desde tabs/modals.php (refactor 2026-08-25). Usa delegación de eventos
// para funcionar con cualquier input[data-smtp-password-input] + botón
// [data-smtp-toggle] que exista en el DOM (modal SMTP).
document.addEventListener('click', function (e) {
	var btn = e.target.closest('[data-smtp-toggle]');
	if (!btn) return;
	var input = btn.parentElement ? btn.parentElement.querySelector('input[data-smtp-password-input]') : null;
	if (!input) return;
	var eye = btn.querySelector('[data-eye]');
	var eyeOff = btn.querySelector('[data-eye-off]');
	var show = (input.type === 'password');
	input.type = show ? 'text' : 'password';
	if (eye) eye.classList.toggle('hidden', show);
	if (eyeOff) eyeOff.classList.toggle('hidden', !show);
	btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
});




// ─── Configurador de Campañas (P-1 F2-F3). Movido de tabs/smtp.php (reorganización 2026-08-26).
function campanasConfig() {
    return {
        campanas: [], federaciones: [], plantillas: [],
        form: { id: 0, nombre: '', identificador: '', entorno: 'test', estado: 'PILOT', activo: 1, todas: false, federaciones: [], estado_lead: '', plantillas: [] },
        msg: '', msgOk: false,
        async cargarTodo() {
            await Promise.all([this.cargarCampanas(), this.cargarFederaciones(), this.cargarPlantillas()]);
            if (window.lucide) lucide.createIcons();
        },
        async cargarCampanas() {
            try { const r = await fetch('?action=get_campanas'); const j = await r.json(); if (j.ok) this.campanas = j.campanas || []; } catch (e) {}
        },
        async cargarFederaciones() {
            try { const r = await fetch('?action=get_federaciones'); const j = await r.json(); if (j.ok) this.federaciones = j.federaciones || []; } catch (e) {}
        },
        async cargarPlantillas() {
            try { const r = await fetch('?action=get_templates'); const j = await r.json(); if (j.ok) this.plantillas = j.templates || []; } catch (e) {}
        },
        nueva() {
            this.form = { id: 0, nombre: '', identificador: '', entorno: 'test', estado: 'PILOT', activo: 1, todas: false, federaciones: [], estado_lead: '', plantillas: [] };
            this.msg = '';
        },
        editar(c) {
            this.form = {
                id: c.id, nombre: c.nombre, identificador: c.identificador, entorno: c.entorno, estado: c.estado, activo: c.activo,
                todas: !!(c.segmento && c.segmento.todas),
                federaciones: (c.segmento && c.segmento.federaciones) || [],
                estado_lead: (c.segmento && c.segmento.estado) || '',
                plantillas: (c.plantillas_id || []).map(x => String(x)),
            };
            this.msg = '';
        },
        async guardar() {
            this.msg = '';
            if (!this.form.nombre.trim() || !this.form.identificador.trim()) { this.msg = 'Nombre e identificador son obligatorios.'; this.msgOk = false; return; }
            const f = new FormData();
            f.append('action', 'save_campaign');
            f.append('id', this.form.id);
            f.append('nombre', this.form.nombre.trim());
            f.append('identificador', this.form.identificador.trim());
            f.append('entorno', this.form.entorno);
            f.append('estado', this.form.estado);
            f.append('activo', this.form.activo);
            f.append('todas_federaciones', this.form.todas ? '1' : '0');
            f.append('federaciones', JSON.stringify(this.form.federaciones));
            f.append('estado_lead', this.form.estado_lead);
            f.append('plantillas', JSON.stringify(this.form.plantillas));
            try {
                const r = await fetch('?action=save_campaign', { method: 'POST', body: f });
                const j = await r.json();
                this.msg = j.message || (j.ok ? 'Campaña guardada.' : (j.error || 'Error al guardar.'));
                this.msgOk = !!j.ok;
                if (j.ok) { await this.cargarCampanas(); this.nueva(); }
            } catch (e) { this.msg = 'Error de conexión.'; this.msgOk = false; }
        },
        async eliminar(c) {
            if (!confirm('¿Eliminar la campaña "' + c.nombre + '"? Se quitarán su segmento y plantillas asignadas.')) return;
            const f = new FormData(); f.append('action', 'delete_campaign'); f.append('id', c.id);
            try { const r = await fetch('?action=delete_campaign', { method: 'POST', body: f }); const j = await r.json();
                if (j.ok) await this.cargarCampanas(); else alert(j.error || 'Error al eliminar.');
            } catch (e) { alert('Error de conexión.'); }
        }
    };
}


// ─── Seguimiento (módulo rediseñado ex-followups). Plan: docs/PLAN_FOLLOWUPS_SEGUIMIENTO_UIUX.md
function seguimientoApp() {
    return {
        noRespondedores: [], sinProximaAccion: [], nuevosSinActividad: [], funnel: [],
        kpis: { no_respondedores: 0, sin_proxima_accion: 0, tasa_apertura: 0, tasa_respuesta: 0, mockups_pendientes: 0, presupuestos_pendientes: 0, pipeline_value: 0 },
        federaciones: [],
        f: { busqueda: '', federacion: '', dias_min: 0, solo_alta: false },
        cola: 'perseguir', cargando: false, error: '',
        async load() {
            if (window.app && Array.isArray(window.app.federaciones)) this.federaciones = window.app.federaciones;
            this.cargando = true; this.error = '';
            try {
                const params = new URLSearchParams();
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
        }
    };
}

