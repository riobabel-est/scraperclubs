// Respaldo de scope para directivas Alpine: `rsSyncing` se usa en tabs/respuestas.php
// dentro del scope x-data="app()". Si por cualquier motivo el componente no provee
// la variable (fallo de scope/timing), definirla en window evita el error
// "rsSyncing is not defined" (el scope del componente tiene prioridad si existe).
window.rsSyncing = false;

var app = function() {
    console.log('[DEBUG] app() INVOCADO. Iniciando construcción del objeto Alpine...');
    try {
    var _cfg = (typeof window._cfg === 'object' && window._cfg !== null) ? window._cfg : {};
    var i = {

        tab: 'inicio',
        killSwitch: _cfg.motorActivo,
        modeTest: _cfg.modeTest,


        // Modales
        lm: false, mm: false, sm: false, al: false,
        ln: '', mn: true, mk: 0, md: 0,
        ld: { presupuesto: null },

        // Atención a medida (modal global)
        modalAtencion: false,
        atencionLead: null,
        charla: { ok: true, lead: {}, envios: [], respuestas: [], mockup: { estado: '', solicitado_en: null }, presupuesto: { version: 0, importe_total: 0, estado: '' }, aperturas_total: 0, primera_apertura: null, contacto_real: '', plantillas: [], cuentas_smtp: [], smtp_heredada: 0 },
        cargandoCharla: false,
        emailAsunto: '',
        emailCuerpo: '',
        plantillaSel: 0,
        smtpSel: 0,
        incluirMockup: false,
        incluirProforma: false,
        generandoIA: false,
        enviandoAtencion: false,
        analisisIA: null,
        analizandoIA: false,
        atencionMsg: '',
        atencionMsgTipo: 'ok',
        // Adjuntos manuales del modal de atención (File objects).
        atencionAdjuntos: [],
        // Lista de conversaciones de la Bandeja integrada en el modal Atender
        // (misma fuente que el tab Respuestas: get_respuestas, agrupada por lead).
        atencionLista: [],
        atencionCargandoLista: false,
        // Leads contactados SIN respuesta humana (estado 01/02 con ≥1 envío) →
        // candidatos a "volver a escribir" (seguimiento/2º toque).
        atencionFollowups: [],
        // ─── Mi cuenta y seguridad (perfil del header, estándar CRM) ───
        perfilAbierto: false,
        modalCuenta: false,
        perfilNombre: '',
        perfilEmail: '',
        perfilMsg: '',
        perfilMsgOk: false,
        passActual: '',
        passNueva: '',
        passConfirmar: '',
        resetEmail: '',
        // Sub-tabs internos de las pestañas de configuración (UX moderna).
        ajTab: 'ia',      // Ajustes: ia | cuentas | pruebas | adjuntos
        edTab: 'plantillas', // Plantillas y Campañas: plantillas | campanas | secuencias
        // Repositorio de adjuntos reutilizables (Ajustes → Adjuntos).
        rsRepoAdjuntos: [],
        atencionRepoAdjuntos: [],

        // Tab Inicio
        inicio: { kpis: { pendientes_hoy: 0, respuestas_sin_atender: 0, mockups_pendientes: 0, proformas_por_presentar: 0, acciones_vencidas: 0 }, acciones: [], conseguir: [], bandeja: [], vencidas: [], horas: [] },
        inicioCargando: false,
        inicioError: '',
        resumenDia: '',
        resumenFresco: null,
        resumenError: '',
        generandoResumen: false,
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
        // 🔄 SMTP aleatorio: get_cola.php baraja las cuentas por lead (independiente del retardo).
        smtpRandom: false,
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
        // Cuenta SMTP heredada del lead (responder desde la misma cuenta) y datos del remitente.
        rsSmtpHeredada: 0,
        rsSenderEmail: '',
        rsSenderName: '',
        rsSenderTitle: 'Atención a Clubes - FutProtec',
        // Respuesta asistida por IA (asunto + borrador) y flags de la caja rápida.
        rsAsuntoResp: '',
        rsGenerandoIA: false,
        // Triaje de la Bandeja: pestaña activa (requiere_respuesta | rebotes | archivados | borrados | todos).
        rsTabTriaje: 'requiere_respuesta',
        // Contadores descriptivos por estado (requiere_respuesta, en_espera, archivados, total).
        rsCountsTriaje: { requiere_respuesta: 0, en_espera: 0, archivados: 0, total: 0 },
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
        // Adjuntos pendientes de adjuntar a la respuesta (File objects).
        rsAdjuntos: [],
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
        // Plantillas de respuesta REALES (cargadas desde la BD vía get_templates,
        // las mismas que se editan en la pestaña Plantillas; sin las de WhatsApp).
        rsTemplatesRapidas: [],
        rsTemplatesMsg: '',

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
        campanaActual: (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0,
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
         // Asistente IA de Plantillas
         iaTplOpen: false, iaTplGenerando: false, iaTplMsg: '', iaTplMsgOk: false,
         iaTpl: { categoria: '01 Prospección', ramal: '', tono: 'profesional', longitud: 'media', instruccion: '' },
         edCategoria: '',
         previewClubId: '', debounceTimer: null,
         pvLive: false, pvLiveA: '', pvLiveB: '', pvLiveC: '', previewClubCache: {}, senderCache: null,

        // Lanzadera v2
        lzMotorEstado: 'PAUSADO',
        lzDelay: 5,
        // 🕒 Rango de retardo aleatorio (segundos). 5s - 300s (5 min).
        lzDelayMin: 5,
        lzDelayMax: 300,
        lzInterval: null,
        lzAbortController: null,
        testEmails: '',
        lzCola: [],
        lzColaIndex: 0,
        lzModoRotacion: false,
        lzRotacionInfo: null,
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
        // Selección en lote desde Seguimiento (IDs concretos a enviar en la Lanzadera).
        lzBulkIds: null,
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
            // El tab Inicio es la vista de entrada por defecto (no se restaura
            // la pestaña previa de sessionStorage para que cada visita empiece en Inicio).
            this.tab = 'inicio';
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

            // Plantillas reales para la caja de respuesta de la Bandeja (problema:
            // antes eran 3 plantillas hardcodeadas que no coincidían con el editor).
            try { await this.rsCargarTemplates(); } catch (e) { console.error('boot: rsCargarTemplates falló', e); }

            try { await this.loadSmtp(); } catch (e) { console.error('boot: loadSmtp falló', e); }
            try { await this.bootLanzadera(); } catch (e) { console.error('boot: bootLanzadera falló', e); }
            try { await this.loadMockupCapacity(); } catch (e) { console.error('boot: loadMockupCapacity falló', e); }
            // Perfil del usuario (identidad del header, se carga siempre).
            try { await this.perfilCargar(); } catch (e) { console.error('boot: perfilCargar falló', e); }
            // Repositorio de adjuntos reutilizables (Ajustes → Adjuntos).
            try { await this.cargarRepoAdjuntos(); } catch (e) { console.error('boot: cargarRepoAdjuntos falló', e); }
        },

        // ─── Mi cuenta y seguridad (perfil del header) ─────────────────────────
        perfilIniciales() {
            const s = String(this.perfilNombre || this.perfilEmail || 'FP').trim();
            const p = s.split(/[\s@._-]+/).filter(Boolean);
            const ini = (p[0] ? p[0][0] : 'F') + (p.length > 1 ? p[p.length - 1][0] : '');
            return ini.toUpperCase().slice(0, 2);
        },
        async perfilCargar() {
            try {
                const r = await fetch('?action=get_config&keys=panel_nombre_usuario,panel_email,reset_email');
                const j = await r.json();
                if (j.ok && j.config) {
                    this.perfilNombre = j.config.panel_nombre_usuario || '';
                    this.perfilEmail = j.config.panel_email || '';
                    this.resetEmail = j.config.reset_email || '';
                }
            } catch (e) { console.error('perfilCargar:', e); }
        },
        async perfilGuardar() {
            this.perfilMsg = '';
            try {
                const f1 = new FormData();
                f1.append('action', 'update_config'); f1.append('key', 'panel_nombre_usuario'); f1.append('value', this.perfilNombre);
                await fetch('', { method: 'POST', body: f1 });
                const f2 = new FormData();
                f2.append('action', 'update_config'); f2.append('key', 'panel_email'); f2.append('value', this.perfilEmail);
                const r = await fetch('', { method: 'POST', body: f2 });
                const j = await r.json();
                this.perfilMsg = j.ok ? '✓ Perfil guardado.' : (j.error || 'Error al guardar el perfil.');
                this.perfilMsgOk = !!j.ok;
            } catch (e) { this.perfilMsg = 'Error de conexión al guardar el perfil.'; this.perfilMsgOk = false; }
        },
        async perfilCambiarPass() {
            this.perfilMsg = '';
            if (this.passNueva.length < 8) { this.perfilMsg = 'La nueva contraseña debe tener al menos 8 caracteres.'; this.perfilMsgOk = false; return; }
            if (this.passNueva !== this.passConfirmar) { this.perfilMsg = 'Las contraseñas no coinciden.'; this.perfilMsgOk = false; return; }
            try {
                const f = new FormData();
                f.append('action', 'change_password');
                f.append('password_actual', this.passActual);
                f.append('password_nueva', this.passNueva);
                f.append('password_confirmar', this.passConfirmar);
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                this.perfilMsg = j.message || (j.ok ? 'Contraseña actualizada.' : 'Error al cambiar la contraseña.');
                this.perfilMsgOk = !!j.ok;
                if (j.ok) { this.passActual = ''; this.passNueva = ''; this.passConfirmar = ''; }
            } catch (e) { this.perfilMsg = 'Error de conexión.'; this.perfilMsgOk = false; }
        },
        async perfilGuardarEmail() {
            this.perfilMsg = '';
            try {
                const f = new FormData();
                f.append('action', 'update_reset_email');
                f.append('reset_email', this.resetEmail);
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                this.perfilMsg = j.message || (j.ok ? 'Email actualizado.' : 'Error al guardar el email.');
                this.perfilMsgOk = !!j.ok;
            } catch (e) { this.perfilMsg = 'Error de conexión.'; this.perfilMsgOk = false; }
        },

        // ─── Repositorio de adjuntos (Ajustes → Adjuntos) ───────────────────────
        async cargarRepoAdjuntos() {
            try {
                const r = await fetch('?action=get_adjuntos_repo');
                const j = await r.json();
                if (j && j.ok && Array.isArray(j.items)) {
                    this.rsRepoAdjuntos = j.items;
                    this.atencionRepoAdjuntos = j.items;
                }
            } catch (e) { /* silencioso */ }
        },
        // Bandeja: añade un archivo del repositorio a los adjuntos de la respuesta.
        async rsAgregarRepoAdjunto(ev) {
            const id = ev && ev.target ? ev.target.value : '';
            if (ev.target) ev.target.value = '';
            if (!id) return;
            const item = (this.rsRepoAdjuntos || []).find(x => String(x.id) === String(id));
            if (!item) return;
            try {
                const r = await fetch('api/adjunto.php?tipo=repo&id=' + id);
                if (!r.ok) { this.rsEnvioMsg = 'No se pudo cargar el archivo del repositorio.'; this.rsEnvioMsgOk = false; return; }
                const blob = await r.blob();
                const file = new File([blob], item.nombre, { type: item.mime || 'application/octet-stream' });
                this.rsAdjuntos.push(file);
            } catch (e) { this.rsEnvioMsg = 'Error al cargar el archivo del repositorio.'; this.rsEnvioMsgOk = false; }
        },
        // Modal Atender: añade un archivo del repositorio a los adjuntos del envío.
        async atencionAgregarRepoAdjunto(ev) {
            const id = ev && ev.target ? ev.target.value : '';
            if (ev.target) ev.target.value = '';
            if (!id) return;
            const item = (this.atencionRepoAdjuntos || []).find(x => String(x.id) === String(id));
            if (!item) return;
            try {
                const r = await fetch('api/adjunto.php?tipo=repo&id=' + id);
                if (!r.ok) { this.atencionMsg = 'No se pudo cargar el archivo del repositorio.'; this.atencionMsgTipo = 'error'; return; }
                const blob = await r.blob();
                const file = new File([blob], item.nombre, { type: item.mime || 'application/octet-stream' });
                this.atencionAdjuntos.push(file);
            } catch (e) { this.atencionMsg = 'Error al cargar el archivo del repositorio.'; this.atencionMsgTipo = 'error'; }
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
            this.smtpRandom = !this.smtpRandom;
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
        // ─── Atención a medida (modal global, usado por Inicio y Seguimiento) ───
        async abrirAtencion(lead) {
            if (!lead || !lead.id) return;
            this.atencionLead = lead;
            this.modalAtencion = true;
            this.emailAsunto = ''; this.emailCuerpo = '';
            this.plantillaSel = 0; this.smtpSel = 0;
            this.incluirMockup = false; this.incluirProforma = false;
            this.analisisIA = null; this.analizandoIA = false;
            this.atencionMsg = '';
            this.atencionAdjuntos = [];
            await Promise.all([this.cargarCharla(), this.cargarConversacionesAtencion()]);
        },
        // Lista de conversaciones de la Bandeja integrada en el modal Atender.
        // Filtra solo las que tienen lead vinculado (el modal redacta/envía por
        // lead_id). Misma fuente que el tab Respuestas (get_respuestas).
        async cargarConversacionesAtencion() {
            this.atencionCargandoLista = true;
            try {
                const r = await fetch('?action=get_respuestas');
                const j = await r.json();
                this.atencionLista = (j && Array.isArray(j.conversaciones)) ? j.conversaciones : [];
                this.atencionFollowups = (j && Array.isArray(j.leads_followup)) ? j.leads_followup : [];
            } catch (e) {
                this.atencionLista = [];
                this.atencionFollowups = [];
            } finally {
                this.atencionCargandoLista = false;
            }
        },
        get atencionConversaciones() {
            // Días sin respuesta del club tras mi último envío → toca "volver a escribir"
            // (follow-up). Ajustable según el ciclo de seguimiento de FutProtec.
            const DIAS_FOLLOWUP = 3;
            const ahora = Date.now();
            const pendientes = (this.atencionLista || []).filter(c => {
                const lid = parseInt(c.lead_id, 10) || 0;
                if (lid <= 0) return false;
                const msgs = (c.mensajes) || [];
                const ultimo = msgs[0] || null;
                if (!ultimo) return false;
                // Fuera: rebotes.
                if (ultimo.es_rebote === 1 || String(ultimo.es_rebote) === '1') return false;
                // Fuera: archivado / borrado (estado del hilo en las respuestas).
                const est = this.atencionEstadoHilo(c);
                if (est === 'borrado' || est === 'archivado') return false;
                // A) DEBO RESPONDER: el último mensaje es del club (entrante) y aún
                //    no le he contestado. rsEstadoHilo esperando=false → "📥 Sin responder".
                if (!this.rsEstadoHilo(c).esperando) return true;
                // B) VOLVER A ESCRIBIR: respondí y el club no vuelve a escribir.
                //    → snooze vencido, o sin snooze y pasados DIAS_FOLLOWUP desde mi envío.
                const snooze = this.atencionSnooze(c);
                if (snooze) {
                    const t = Date.parse(String(snooze).replace(' ', 'T'));
                    return !isNaN(t) && t <= ahora;
                }
                const fecha = ultimo.fecha || ultimo.fecha_envio || c.ultima_fecha || '';
                if (!fecha) return false;
                const tf = Date.parse(fecha);
                if (!tf) return false;
                return (ahora - tf) / 86400000 >= DIAS_FOLLOWUP;
            });
            // Leads contactados SIN respuesta humana (estado 01/02, ≥1 envío) →
            // candidatos a "volver a escribir" (seguimiento / 2º toque).
            const followups = (this.atencionFollowups || []).map(f => ({
                lead_id: parseInt(f.id, 10) || 0,
                clave: 'fu:' + f.id,
                tipo: 'followup',
                nombre_club: f.nombre_club || '',
                federacion: f.federacion || '',
                email: f.email || '',
                estado_lead: f.estado_lead || '',
                persona_contacto: f.persona_contacto || '',
                n_envios: parseInt(f.n_envios, 10) || 0,
                ult_envio: f.ult_envio || '',
                volumen_estimado: f.volumen_estimado,
                prioridad: 'media',
            }));
            // Primero lo que requiere acción de respuesta, después los follow-ups.
            return [...pendientes, ...followups];
        },
        // Estado de conversación del hilo: lo llevan las respuestas entrantes
        // (los envíos salientes no tienen la columna). Devuelve el del mensaje
        // más reciente que lo tenga ('requiere_respuesta' | 'en_espera' | ...).
        atencionEstadoHilo(c) {
            const msgs = (c && c.mensajes) || [];
            for (const m of msgs) {
                if (m && m.estado_conversacion) return String(m.estado_conversacion);
            }
            return '';
        },
        atencionSnooze(c) {
            const msgs = (c && c.mensajes) || [];
            for (const m of msgs) {
                if (m && m.snooze_until) return m.snooze_until;
            }
            return '';
        },
        // Cambia el lead atendido en el modal desde la lista de la Bandeja.
        async atencionSeleccionar(conv) {
            if (!conv || !conv.lead_id) return;
            this.atencionLead = { id: parseInt(conv.lead_id, 10) };
            this.emailAsunto = ''; this.emailCuerpo = '';
            this.plantillaSel = 0; this.smtpSel = 0;
            this.incluirMockup = false; this.incluirProforma = false;
            this.analisisIA = null; this.analizandoIA = false;
            this.atencionMsg = ''; this.atencionAdjuntos = [];
            await this.cargarCharla();
        },
        // Resalta la conversación actual en la lista del modal.
        atencionEsActual(conv) {
            return !!(this.atencionLead && conv &&
                parseInt(conv.lead_id, 10) === parseInt(this.atencionLead.id, 10));
        },
        // Hilo cronológico de la charla (estilo Bandeja): combina envíos
        // salientes y respuestas entrantes, ordenado del más reciente al más viejo.
        charlaHilo() {
            const env = (this.charla && this.charla.envios || []).map(e => ({
                id: 'e' + e.id,
                sentido: 'saliente',
                fecha: e.fecha_envio,
                asunto: e.asunto,
                cuerpo: e.cuerpo_charla,
                adjuntos: e.adjuntos || [],
            }));
            const res = (this.charla && this.charla.respuestas || []).map(r => ({
                id: 'r' + r.id,
                sentido: 'entrante',
                fecha: r.fecha_respuesta,
                remitente: r.remitente,
                subject: r.subject,
                cuerpo: r.cuerpo,
                clasificacion: r.clasificacion,
                es_rebote: r.es_rebote,
                adjuntos: r.adjuntos || [],
            }));
            return [...env, ...res].sort((a, b) => {
                const ta = Date.parse(a.fecha) || 0;
                const tb = Date.parse(b.fecha) || 0;
                return tb - ta;
            });
        },
        async cargarCharla() {
            this.cargandoCharla = true;
            try {
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                const r = await fetch('?action=get_charla_lead&lead_id=' + this.atencionLead.id + '&campaign_id=' + cid);
                const j = await r.json();
                this.charla = j || { ok: false, error: 'Error de conexión' };
                if (this.charla.smtp_heredada) this.smtpSel = this.charla.smtp_heredada;
                if (this.charla.mockup && ['solicitado', 'en_produccion'].includes(this.charla.mockup.estado)) this.incluirMockup = true;
                if (this.charla.presupuesto && this.charla.presupuesto.estado === 'creado') this.incluirProforma = true;
            } catch (e) {
                this.charla = { ok: false, error: 'Error de conexión al cargar la charla.' };
            } finally {
                this.cargandoCharla = false;
            }
        },
        async generarEmailIA() {
            if (!this.atencionLead) return;
            this.generandoIA = true;
            this.atencionMsg = '';
            try {
                const f = new FormData();
                f.append('action', 'generar_email_ia');
                f.append('lead_id', this.atencionLead.id);
                f.append('plantilla_id', this.plantillaSel || 0);
                const r = await fetch('?action=generar_email_ia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.emailAsunto = j.asunto || '';
                    this.emailCuerpo = j.cuerpo || '';
                    this.atencionMsg = '✨ Email generado — revísalo y edítalo antes de enviar.';
                    this.atencionMsgTipo = 'ok';
                } else {
                    this.atencionMsg = j.error || 'No se pudo generar.';
                    this.atencionMsgTipo = 'error';
                }
            } catch (e) {
                this.atencionMsg = 'Error de conexión al generar.';
                this.atencionMsgTipo = 'error';
            } finally {
                this.generandoIA = false;
            }
        },
        // 🧠 AI Command Center: la IA lee TODA la conversación y devuelve
        // resumen + intención con confianza + motivo + próxima acción.
        async analizarLeadIA() {
            if (!this.atencionLead) return;
            this.analizandoIA = true;
            this.atencionMsg = '';
            try {
                const f = new FormData();
                f.append('action', 'ia_analizar_lead');
                f.append('lead_id', this.atencionLead.id);
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                f.append('campaign_id', cid);
                const r = await fetch('?action=ia_analizar_lead', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.analisisIA = j.analisis;
                    this.atencionMsg = '🧠 Análisis generado con la IA.';
                    this.atencionMsgTipo = 'ok';
                } else {
                    this.atencionMsg = j.error || 'No se pudo analizar.';
                    this.atencionMsgTipo = 'error';
                }
            } catch (e) {
                this.atencionMsg = 'Error de conexión al analizar.';
                this.atencionMsgTipo = 'error';
            } finally {
                this.analizandoIA = false;
            }
        },
        // Etiquetas y colores del análisis IA (Command Center).
        iaIntencionLabel(i) {
            const m = { interesado: '😊 Interesado', duda_precio: '💶 Duda Precio', baja: '🚫 Baja', neutral: '😐 Neutral', no_interesa: '🙅 No interesa', otro: '📄 Otro', pendiente: '❓ Revisar' };
            return m[i] || i || '—';
        },
        iaIntencionColor(i) {
            const m = { interesado: 'bg-emerald-500/15 text-emerald-400', duda_precio: 'bg-amber-500/15 text-amber-400', baja: 'bg-rose-500/15 text-rose-400', neutral: 'bg-slate-500/15 text-slate-300', no_interesa: 'bg-rose-500/15 text-rose-400', otro: 'bg-slate-500/15 text-slate-300', pendiente: 'bg-fuchsia-500/15 text-fuchsia-400' };
            return m[i] || 'bg-slate-500/15 text-slate-300';
        },
        iaConfianzaColor(c) {
            if (c >= 0.7) return 'bg-emerald-500';
            if (c >= 0.5) return 'bg-amber-500';
            return 'bg-rose-500';
        },
        elegirPlantilla() {
            if (!this.charla || !this.plantillaSel) return;
            const p = (this.charla.plantillas || []).find(x => Number(x.id) === Number(this.plantillaSel));
            if (!p) return;
            const lead = this.charla.lead || {};
            const rep = (t) => String(t || '')
                .replace(/\{\{CLUB\}\}/g, lead.nombre_club || '')
                .replace(/\{\{CONTACTO\}\}/g, this.charla.contacto_real || 'responsable')
                .replace(/\{\{FEDERACION\}\}/g, lead.federacion || '')
                .replace(/\{\{ANIO\}\}/g, String(new Date().getFullYear()));
            this.emailAsunto = rep(p.asunto);
            this.emailCuerpo = rep(p.cuerpo);
        },
        // Añade los archivos seleccionados como adjuntos del envío a medida.
        atencionAdjuntarArchivos(ev) {
            const files = ev && ev.target ? Array.from(ev.target.files || []) : [];
            if (!files.length) return;
            const actual = (this.atencionAdjuntos || []).reduce((a, x) => a + (x.size || 0), 0);
            let total = actual;
            let aviso = '';
            for (const f of files) {
                if (!f.name || f.size <= 0) continue;
                total += f.size;
                if (total > 8 * 1024 * 1024) { aviso = 'El total de adjuntos no puede superar 8 MB.'; continue; }
                this.atencionAdjuntos.push(f);
            }
            if (aviso) { this.atencionMsg = aviso; this.atencionMsgTipo = 'error'; }
            if (ev.target) ev.target.value = '';
        },
        // Quita un adjunto pendiente del envío a medida.
        atencionQuitarAdjunto(i) { this.atencionAdjuntos.splice(i, 1); },
        async enviarAtencion() {
            // Auto-seleccionar la primera cuenta SMTP activa si no hay ninguna
            // elegida (evita el fallo SILENCIOSO que dejaba el envío sin registrar).
            if (!this.smtpSel && this.charla && this.charla.cuentas && this.charla.cuentas.length) {
                const act = (this.charla.cuentas || []).find(c => String(c.activa) === '1' || c.activa === 1) || this.charla.cuentas[0];
                if (act && act.id) this.smtpSel = act.id;
            }
            if (!this.smtpSel) {
                this.atencionMsg = '❌ No hay cuenta SMTP seleccionada. Elige una cuenta en el desplegable antes de enviar.';
                this.atencionMsgTipo = 'error';
                return;
            }
            if (!this.emailCuerpo || !this.emailCuerpo.trim()) {
                this.atencionMsg = '❌ Escribe un mensaje antes de enviar.';
                this.atencionMsgTipo = 'error';
                return;
            }
            const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
            if (!cid) {
                // No usar nunca campaign_id=1 como fallback (puede ser DRAFT).
                this.atencionMsg = '❌ No hay campaña activa seleccionada. Elige la campaña «Piloto Comercial» en el selector superior antes de enviar.';
                this.atencionMsgTipo = 'error';
                return;
            }
            this.enviandoAtencion = true;
            this.atencionMsg = '';
            try {
                const tplId = this.plantillaSel || (this.charla && this.charla.plantillas && this.charla.plantillas.length ? this.charla.plantillas[0].id : 1);
                const f = new FormData();
                f.append('id_club', this.atencionLead.id);
                f.append('id_plantilla', tplId);
                f.append('id_cuenta_smtp', this.smtpSel);
                f.append('campaign_id', cid);
                f.append('asunto', this.emailAsunto);
                f.append('cuerpo', this.emailCuerpo);
                f.append('marcar_mockup_enviado', this.incluirMockup ? '1' : '0');
                // Adjuntos manuales seleccionados en el modal.
                for (const a of (this.atencionAdjuntos || [])) {
                    f.append('adjunto[]', a, a.name);
                }
                const r = await fetch('api/enviar_lote.php', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.atencionMsg = '✅ Enviado a medida' + (this.incluirMockup ? ' · mockup marcado como enviado' : '');
                    this.atencionMsgTipo = 'ok';
                    this.modalAtencion = false;
                    this.atencionAdjuntos = [];
                    await this.resolverPropuestasLead();
                    if (window._segApp) window._segApp.cargarPropuestas();
                    if (window._segApp) window._segApp.load();
                } else {
                    this.atencionMsg = '❌ ' + (j.error || 'Error al enviar');
                    this.atencionMsgTipo = 'error';
                }
            } catch (e) {
                this.atencionMsg = 'Error de conexión al enviar.';
                this.atencionMsgTipo = 'error';
            } finally {
                this.enviandoAtencion = false;
            }
        },
        async resolverPropuestasLead() {
            if (!this.atencionLead) return;
            try {
                const f = new FormData(); f.append('action', 'resolver_propuestas_lead'); f.append('lead_id', this.atencionLead.id);
                const r = await fetch('?action=resolver_propuestas_lead', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok && window._segApp) window._segApp.cargarPropuestas();
            } catch (e) {}
        },
        async posponerPropuesta(pr, dias = 1) {
            const fecha = new Date();
            fecha.setDate(fecha.getDate() + dias);
            const iso = fecha.toISOString().slice(0, 10);
            try {
                const f = new FormData(); f.append('action', 'posponer_propuesta'); f.append('id', pr.id); f.append('fecha', iso);
                const r = await fetch('?action=posponer_propuesta', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok && window._segApp) window._segApp.cargarPropuestas();
            } catch (e) {}
        },

        // ─── Tab Inicio ─────────────────────────────────────────────────────
        async loadInicio() {
            this.inicioCargando = true;
            this.inicioError = '';
            try {
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                const r = await fetch('?action=get_inicio&campaign_id=' + cid);
                const j = await r.json();
                this.inicio = j && j.ok ? j : null;
            } catch (e) {
                this.inicio = null;
                this.inicioError = 'Error de conexión al cargar el inicio.';
            } finally {
                this.inicioCargando = false;
            }
        },
        async generarResumenDia() {
            this.generandoResumen = true;
            this.resumenError = '';
            try {
                const cid = (typeof window._campanaActual !== 'undefined' && window._campanaActual > 0) ? window._campanaActual : 0;
                const f = new FormData(); f.append('action', 'generar_resumen_dia'); f.append('campaign_id', cid);
                const r = await fetch('?action=generar_resumen_dia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.resumenDia = j.resumen || '';
                    this.resumenFresco = new Date();
                } else {
                    this.resumenError = j.error || 'No se pudo generar el resumen.';
                }
            } catch (e) {
                this.resumenError = 'Error de conexión al generar el resumen.';
            } finally {
                this.generandoResumen = false;
            }
        },
        irAAtender(lead) {
            // Desde el tab Inicio: navega a Seguimiento y abre el modal de atención
            // global (el modal y sus métodos viven ahora en app()).
            this.tab = 'seguimiento';
            setTimeout(() => { this.abrirAtencion(lead); }, 120);
        },
        tipoAccionLabel(t) {
            return ({ responder_2toque: '🎯 2º toque', enviar_1toque: '🌱 1er toque', enviar_mockup: '🎨 Mockup', presentar_proforma: '🧾 Proforma', responder: '📥 Responder' }[t] || t);
        },
        tipoAccionClase(t) {
            return ({ responder_2toque: 'bg-amber-500/15 text-amber-400 border border-amber-500/30', enviar_1toque: 'bg-sky-500/15 text-sky-400 border border-sky-500/30', enviar_mockup: 'bg-purple-500/15 text-purple-400 border border-purple-500/30', presentar_proforma: 'bg-violet-500/15 text-violet-400 border border-violet-500/30' }[t] || 'bg-slate-700 text-slate-400');
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
        async registrarWhatsApp(id) {
            // Registra el envío de WhatsApp (trazabilidad en comunicaciones_log +
            // avance del lead a '03 En Conversación'). No bloquea la apertura de wa.me.
            const lid = id || (this.ld ? this.ld.id : 0);
            if (!lid) return;
            try {
                const f = new FormData();
                f.append('action', 'registrar_whatsapp');
                f.append('lead_id', lid);
                await fetch('api/leads.php', { method: 'POST', body: f });
            } catch (e) {}
        },
        markChanged() {
            this.ldChanged = JSON.stringify(this.ld) !== JSON.stringify(this.ldOriginal);
        },
        abrirAsistente() {
            // Asistente IA de Plantillas: pre-carga la categoría actual del editor.
            this.iaTpl.categoria = this.edCategoria || this.ec || '01 Prospección';
            this.iaTplOpen = true; this.iaTplMsg = '';
            setTimeout(() => { if (window.lucide) lucide.createIcons(); }, 80);
        },
        async generarPlantillaIA(variantes) {
            if (this.iaTplGenerando) return;
            this.iaTplGenerando = true; this.iaTplMsg = '';
            try {
                const f = new FormData();
                f.append('action', 'generar_plantilla_ia');
                f.append('categoria', this.iaTpl.categoria);
                f.append('ramal', this.iaTpl.ramal);
                f.append('tono', this.iaTpl.tono);
                f.append('longitud', this.iaTpl.longitud);
                f.append('instruccion', this.iaTpl.instruccion);
                f.append('variantes', variantes ? '1' : '0');
                const r = await fetch('?action=generar_plantilla_ia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    if (variantes && j.variantes && j.variantes.length === 3) {
                        this.edTestAb = 1;
                        this.edAsunto = j.variantes[0].asunto; this.edCuerpo = j.variantes[0].cuerpo;
                        this.edAsuntoB = j.variantes[1].asunto; this.edCuerpoB = j.variantes[1].cuerpo;
                        this.edAsuntoC = j.variantes[2].asunto; this.edCuerpoC = j.variantes[2].cuerpo;
                    } else {
                        this.edAsunto = j.asunto || ''; this.edCuerpo = j.cuerpo || '';
                    }
                    this.edCategoria = this.iaTpl.categoria || '';
                    this.iaTplMsg = 'Plantilla generada ✓ Revísala y guárdala.';
                    this.iaTplMsgOk = true;
                } else {
                    this.iaTplMsg = j.error || 'Error al generar.'; this.iaTplMsgOk = false;
                }
            } catch (e) {
                this.iaTplMsg = 'Error de conexión.'; this.iaTplMsgOk = false;
            }
            this.iaTplGenerando = false;
            if (window.lucide) lucide.createIcons();
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
            if (window.app && window.app.campanaActual > 0) p.append('campaign_id', window.app.campanaActual);
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
            try { const r = await fetch('api/leads.php?action=get_config&key=lanzadera_delay_min'); const j = await r.json(); if (j.ok && j.valor) this.lzDelayMin = parseInt(j.valor) || 5; } catch (e) { this.lzDelayMin = 5; }
            try { const r = await fetch('api/leads.php?action=get_config&key=lanzadera_delay_max'); const j = await r.json(); if (j.ok && j.valor) this.lzDelayMax = parseInt(j.valor) || 300; } catch (e) { this.lzDelayMax = 300; }
            try { const r = await fetch('api/leads.php?action=get_config&key=test_emails'); const j = await r.json(); if (j.ok && j.valor) this.testEmails = j.valor; } catch (e) {}
            try { const r = await fetch('?action=get_piloto_campanas'); const j = await r.json(); if (j.ok) this.lzCampanas = j.campanas || []; } catch (e) {}
            // Hereda la campaña del contexto global del panel (P0 navegación).
            if (window.app && window.app.campanaActual > 0 && !this.lzCampaignId) {
                this.lzCampaignId = String(window.app.campanaActual);
            }
            try { const r = await fetch('api/get_cola.php?_ts=' + Date.now()); const j = await r.json();
                if (j.ok) { this.lzFederaciones = j.federaciones || []; this.lzCuentasSmtp = j.cuentas_smtp || []; this.lzKpiClubes = j.kpi_clubes || 0; this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0; this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0; }
            } catch (e) {}
        },
        async lzSaveTestEmails() {
            const f = new FormData(); f.append('action', 'update_config'); f.append('key', 'test_emails'); f.append('value', this.testEmails);
            await fetch('', { method: 'POST', body: f });
        },
        lzOnCampaignChange() { /* la campaña se lee de lzCampaignId en el envío; no se pierde la selección en lote */ },
        // Al elegir plantilla con una selección en lote (desde Seguimiento), carga la cola.
        lzOnPlantillaChange() {
            if (this.lzBulkIds && this.lzBulkIds.length > 0 && this.lzIdPlantillaEmail) {
                this.cargarCola();
            }
        },
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
            // Cambiar el estado manualmente cancela cualquier selección en lote
            // (se vuelve al modo filtrado normal).
            this.lzBulkIds = null;
            this.lzIdPlantillaEmail = ''; this.lzTemplatesEmail = []; if (!this.lzEstadoLead) return;
            // Mapeo estado del pipeline → categoría por objetivo/campaña (reorganización 2026-08-26).
            // Los estados sin categoría asignada devuelven solo plantillas genéricas
            // (incluir_genericas=1 → WHERE categoria = :cat OR categoria = '').
            const mapaCat = {
                '01 Sin Contactar': '01 Prospección',
                '02 Contactado': '02 Seguimiento',
                '03 En Conversación': '03 Respuestas',
                '03 Respondió': '03 Respuestas',
            };
            const cat = mapaCat[this.lzEstadoLead] || '__sin_categoria__';
            try { const r = await fetch('?action=get_templates&categoria=' + encodeURIComponent(cat) + '&incluir_genericas=1'); const j = await r.json();
                if (j.ok && j.templates) { this.lzTemplatesEmail = j.templates.filter(t => t.tipo !== 'whatsapp'); this.lzTemplatesWa = j.templates.filter(t => t.tipo === 'whatsapp'); }
            } catch (e) {}
        },
        puedeCargarCola() {
            if (!this.lzIdPlantillaEmail) return false;
            if (this.lzBulkIds && this.lzBulkIds.length > 0) return true;
            return this.lzEstadoLead !== '';
        },
        async cargarCola() {
            // MODO ROTACIÓN ABC: no requiere estado ni plantilla (el sistema
            // resuelve plantilla y variante rotada desde la secuencia).
            if (this.lzModoRotacion) {
                if (!this.lzCampaignId) { alert('Selecciona una campaña para cargar la rotación ABC.'); return; }
            } else {
                // Con selección en lote (Seguimiento) la plantilla es obligatoria y el
                // estado se ignora (la lista de IDs es la fuente de la cola).
                if (!this.lzIdPlantillaEmail) { alert('Selecciona al menos la Plantilla de Email'); return; }
                if (!this.lzBulkIds && !this.lzEstadoLead) { alert('Selecciona al menos Estado del Lead y Plantilla de Email'); return; }
            }
            this.lzCola = []; this.lzColaPaginada = []; this.lzColaPageCurrent = 0; this.lzColaIndex = 0;
            this.lzColaCompletados = {}; this.lzColaResultados = {}; this.lzLogEnviados = []; this.lzLogEnviadosPaginados = []; this.lzLogPageCurrent = 0; this.lzMotorEstado = 'PAUSADO';
            const params = new URLSearchParams({ estado_lead: this.lzEstadoLead, federacion: this.lzFederacion, id_plantilla_email: this.lzIdPlantillaEmail, id_plantilla_wa: this.lzIdPlantillaWa, habilitar_whatsapp: this.lzWhatsappOn ? '1' : '0', random_mode: this.smtpRandom ? '1' : '0', campaign_id: this.lzCampaignId || '' });
            // ANTI-CACHÉ: URL única por petición (el caché de SiteGround devolvía colas obsoletas).
            params.set('_ts', Date.now());
            if (this.lzModoRotacion) { params.set('rotacion', '1'); params.delete('estado_lead'); params.delete('id_plantilla_email'); }
            if (this.lzBulkIds && this.lzBulkIds.length > 0) params.set('ids', this.lzBulkIds.join(','));
            try { const r = await fetch('api/get_cola.php?' + params.toString()); const j = await r.json();
                if (!j.ok) { alert('Error: ' + (j.error || 'Desconocido')); return; }
                this.lzCola = j.cola || [];
                if (this.lzCola.length > 0) { this.lzColaPaginada = this.lzCola.slice(0, Math.min(this.lzColaPageSize, this.lzCola.length)); this.lzColaPageCurrent = 1; }
                this.lzCuentasSmtp = j.cuentas_smtp || []; this.lzKpiClubes = j.kpi_clubes || 0; this.lzKpiSmtpActivas = j.kpi_smtp_activas || 0; this.lzKpiEnviosHoy = j.kpi_envios_hoy || 0; this.lzDelay = j.delay_segundos || 5;
                if (j.delay_min_segundos) this.lzDelayMin = j.delay_min_segundos;
                if (j.delay_max_segundos) this.lzDelayMax = j.delay_max_segundos;
                if (this.lzCola.length === 0) { alert(this.lzModoRotacion ? 'No hay leads no abridores pendientes de rotación (revisa espera/máx. envíos en la secuencia).' : 'No hay leads pendientes con los filtros seleccionados.'); }
                this.lzRotacionInfo = j.rotacion || null;
                // La selección en lote se consume en la carga (vuelve al modo normal).
                this.lzBulkIds = null;
                this.lzSelectedLeadId = 0; this.lzSelectedLead = null;
                this.lzModoRotacion = false;
            } catch (e) { alert('Error de conexión al cargar la cola.'); this.lzModoRotacion = false; }
            setTimeout(() => lucide.createIcons(), 100);
        },
        async cargarRotacion() {
            // 🔄 Rotación ABC: prepara en la Lanzadera el reenvío con la siguiente
            // variante para los no abridores (configurado en Plantillas y Campañas → Secuencia).
            this.lzModoRotacion = true;
            await this.cargarCola();
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
                const r = Math.random(); const vAb = lead.es_rotacion ? (lead.variante_ab || 'A') : (r < 0.333 ? 'A' : (r < 0.666 ? 'B' : 'C'));
                const fd = new FormData(); fd.append('id_club', lead.id); fd.append('id_plantilla', lead.es_rotacion ? (lead.rotacion_plantilla_id || this.lzIdPlantillaEmail) : this.lzIdPlantillaEmail); fd.append('id_cuenta_smtp', lead.smtp_asignada_id); fd.append('modo_test', this.modeTest ? '1' : '0'); fd.append('variante_ab', vAb); fd.append('campaign_id', this.lzCampaignId);
                if (lead.es_rotacion) { fd.append('es_rotacion', '1'); }
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
                    // 🕒 Retardo aleatorio dentro del rango configurado (5s - 5min).
                    await this.delay(this.lzRandomDelay());
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
        async lzSaveDelay() {
            // Guarda el rango de retardo (min/max) + la media en lanzadera_delay (compatibilidad).
            const min = Math.max(1, parseInt(this.lzDelayMin) || 5);
            const max = Math.max(min, parseInt(this.lzDelayMax) || 300);
            this.lzDelayMin = min; this.lzDelayMax = max;
            this.lzDelay = Math.round((min + max) / 2);
            const guardar = async (key, val) => { const f = new FormData(); f.append('action', 'update_config'); f.append('key', key); f.append('value', val); await fetch('', { method: 'POST', body: f }); };
            await guardar('lanzadera_delay_min', min);
            await guardar('lanzadera_delay_max', max);
            await guardar('lanzadera_delay', this.lzDelay);
        },
        // 🕒 Valor aleatorio del retardo (ms) dentro del rango configurado.
        lzRandomDelay() {
            const min = Math.max(1, parseInt(this.lzDelayMin) || 5);
            const max = Math.max(min, parseInt(this.lzDelayMax) || 300);
            return Math.max(500, Math.floor(min * 1000 + Math.random() * (max - min) * 1000));
        },
        // Formatea segundos como "45s", "2m", "2m 30s".
        lzFmtDelay(s) {
            s = parseInt(s) || 0;
            if (s < 60) return s + 's';
            const m = Math.floor(s / 60), r = s % 60;
            return r ? (m + 'm ' + r + 's') : (m + 'm');
        },
        lzOnDelayMinChange() {
            if (this.lzDelayMin > this.lzDelayMax) this.lzDelayMax = this.lzDelayMin;
        },
        lzOnDelayMaxChange() {
            if (this.lzDelayMax < this.lzDelayMin) this.lzDelayMin = this.lzDelayMax;
        },

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
            // Al seleccionar un lead dirigido se cancela cualquier selección en lote.
            this.lzBulkIds = null;
            // Al seleccionar un lead dirigido, el tamaño de lote se fuerza a 1
            this.lzBatchSize = 1;
        },
        lzClearLead() {
            this.lzSelectedLeadId = 0;
            this.lzSelectedLead = null;
            this.lzLeadValidation = null;
        },
        async lzEnviarSeleccion(ids) {
            // Acción en lote desde Seguimiento: lleva los leads seleccionados a la
            // Lanzadera y carga la cola con esa lista exacta (get_cola.php?ids=...).
            this.lzBulkIds = (ids || []).map(Number).filter(Boolean);
            // En modo selección por IDs no hay estado del lead: cargar todas las
            // plantillas de email para poder elegir sin depender del estado.
            try {
                const r = await fetch('?action=get_templates');
                const j = await r.json();
                if (j.ok && j.templates) {
                    this.lzTemplatesEmail = j.templates.filter(t => t.tipo !== 'whatsapp');
                    this.lzTemplatesWa = j.templates.filter(t => t.tipo === 'whatsapp');
                }
            } catch (e) {}
            this.tab = 'lanza';
            setTimeout(() => { if (this.lzIdPlantillaEmail) this.cargarCola(); }, 150);
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
        async setCampana(id) {
            // Guarda la campaña de trabajo en sesión y recarga el panel
            // (el Kanban se renderiza server-side con el filtro aplicado).
            try {
                const f = new FormData();
                f.append('action', 'set_campana_actual');
                f.append('campaign_id', id);
                const r = await fetch('?action=set_campana_actual', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    sessionStorage.setItem('futprotec_tab', this.tab);
                    location.reload();
                } else {
                    alert('No se pudo cambiar la campaña.');
                }
            } catch (e) { alert('Error de conexión al cambiar de campaña.'); }
        },
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
                if (this.rsTabTriaje && this.rsTabTriaje !== 'todos') p.append('estado', this.rsTabTriaje);
                if (this.respuestasFiltro) p.append('clasificacion', this.respuestasFiltro);
                if (this.respuestasPrioridad) p.append('prioridad', this.respuestasPrioridad);
                // NOTA: la Bandeja es un INBOX GLOBAL. No se filtra por la campaña
                // activa del header (las respuestas a medida tienen campaign_id vacío
                // y quedarían ocultas; la campaña del header la usa la Lanzadera).
                const r = await fetch('?' + p.toString());
                const j = await r.json();
                if (j && j.ok) this.respuestas = j.conversaciones || [];
                if (j && j.counts_triaje) this.rsCountsTriaje = j.counts_triaje;
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
        // Etiqueta genérica en español para los botones de clasificación rápida
        // (clasificación, NO la intención específica: "Positivo", no "Solicita muestra").
        rsClasBotonLabel(c) {
            const mapa = {
                PENDING: 'Pendiente',
                POSITIVE: 'Positivo',
                NEGATIVE: 'Negativo',
                NEUTRAL: 'Neutral',
                UNSUBSCRIBE: 'Baja',
                OOO: 'Fuera de oficina',
            };
            return mapa[c] || c || '—';
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
            // Mismos códigos que la pestaña Seguimiento (Urgente/Atender hoy/Sin urgencia).
            const mapa = { alta: 'Urgente', media: 'Atender hoy', baja: 'Sin urgencia' };
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
        // Tiempo transcurrido legible desde una fecha ("hace 5 min", "hace 2 h",
        // "hace 3 d"...). Permite reconocer prioridades de un vistazo.
        rsTiempoRelativo(f) {
            if (!f) return '';
            const d = new Date(f);
            if (isNaN(d.getTime())) return '';
            const seg = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
            if (seg < 60) return 'hace ' + seg + ' s';
            const min = Math.floor(seg / 60);
            if (min < 60) return 'hace ' + min + ' min';
            const h = Math.floor(min / 60);
            if (h < 24) return 'hace ' + h + ' h';
            const dias = Math.floor(h / 24);
            if (dias < 30) return 'hace ' + dias + ' d';
            const meses = Math.floor(dias / 30);
            return 'hace ' + meses + ' mes' + (meses === 1 ? '' : 'es');
        },
        // Estado del hilo según su ÚLTIMO mensaje (backend entrega mensajes en
        // orden DESC, el [0] es el más reciente):
        //  - Último SALIENTE (respondí)  → "Esperando respuesta del cliente" (tiempo desde mi envío).
        //  - Último ENTRANTE (me respondió) → "Sin responder" (tiempo desde su respuesta).
        rsEstadoHilo(conv) {
            if (!conv || !conv.mensajes || conv.mensajes.length === 0) {
                return { esperando: false, label: 'Sin mensajes', fecha: '' };
            }
            const ultimo = conv.mensajes[0];
            const fecha = ultimo.fecha || ultimo.fecha_respuesta || ultimo.fecha_envio || '';
            if (ultimo.sentido === 'saliente') {
                return { esperando: true, label: '⏳ Esperando respuesta', fecha };
            }
            return { esperando: false, label: '📥 Sin responder', fecha };
        },
        rsUltimoMensaje(conv) {
            if (!conv || !conv.mensajes || conv.mensajes.length === 0) return '';
            const m = conv.mensajes[0];
            return (m.subject_respuesta || m.asunto_envio || '') + ' — ' + (m.remitente || m.email || '');
        },
        rsEsEntrante(m) {
            // Entrante = respuesta del lead; saliente = envío de FutProtec.
            if (m && m.sentido) return m.sentido === 'entrante';
            return !!m.remitente && m.remitente !== m.email;
        },
        // Devuelve el cuerpo legible de un mensaje de forma ROBUSTA:
        // 1) texto limpio (cuerpo_limpio/cuerpo_texto/cuerpo), 2) texto extraído
        // del HTML, 3) '' (el visor mostrará entonces el HTML o el asunto).
        // Nunca devuelve los literales 'Sin contenido de texto' ni strings solo
        // de cita: si el cuerpo limpio es irrelevante, se intenta el HTML.
        rsCuerpoMensaje(m) {
            if (!m) return '';
            const util = (s) => {
                const x = String(s || '').trim();
                if (!x) return '';
                const l = x.toLowerCase();
                if (l === 'sin contenido de texto' || l === 'sin contenido html') return '';
                return x;
            };
            const t = util(m.cuerpo_limpio) || util(m.cuerpo_texto) || util(m.cuerpo);
            if (t) return t;
            const h = util(m.contenido_html);
            if (h) {
                const d = document.createElement('div');
                d.innerHTML = h;
                return (d.textContent || '').replace(/\s+/g, ' ').trim();
            }
            return '';
        },
        // Hilo en orden WhatsApp: el primer mensaje ARRIBA y el último ABAJO.
        // (el backend mantiene mensajes en DESC para snippets/intención; aquí
        //  se invierte SOLO para el visor, facilitando leer el último mensaje).
        rsHiloInvertido() {
            if (!this.rsSeleccion || !Array.isArray(this.rsSeleccion.mensajes)) return [];
            return [...this.rsSeleccion.mensajes].reverse();
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
            // Cuenta SMTP heredada del lead: responder desde la misma cuenta con la
            // que ya se comunicó el club (p.ej. gonzalo.vega@getfutprotec.com).
            this.rsSmtpHeredada = parseInt(conv.smtp_heredada, 10) || 0;
            this.rsSenderEmail = conv.cuenta_emision || '';
            this.rsSenderName = conv.smtp_nombre_emisor || (this.rsSenderEmail ? this.rsSenderEmail.split('@')[0].replace(/\b\w/g, c => c.toUpperCase()) : '');
            this.rsSenderTitle = 'Atención a Clubes - FutProtec';
            setTimeout(() => lucide.createIcons(), 50);
            this.rsSyncEditorHtml();
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
        // Carga las plantillas de respuesta REALES desde la BD (get_templates),
        // filtrando las de WhatsApp (el selector de la Bandeja es solo email).
        // Robusto: reintenta si el fetch falla o viene vacío (una única petición
        // fallida no debe dejar el selector sin plantillas).
        async rsCargarTemplates() {
            let intento = 0;
            while (intento < 3) {
                intento++;
                try {
                    const r = await fetch('?action=get_templates');
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    // Parseo manual: si el endpoint devolviera HTML (p.ej. pantalla de
                    // login o un 500), JSON.parse lanza y reintentamos sin ruido.
                    const texto = await r.text();
                    let j = null;
                    try { j = JSON.parse(texto); } catch (e) { throw new Error('Respuesta no JSON'); }
                    if (j && j.ok && Array.isArray(j.templates)) {
                        this.rsTemplatesRapidas = j.templates.filter(t => (t.tipo || '') !== 'whatsapp');
                        if (this.rsTemplatesRapidas.length > 0) {
                            this.rsTemplatesMsg = '';
                            return;
                        }
                    }
                } catch (e) {
                    // Solo se muestra en consola en el último intento (evita ruido
                    // cuando el primer fetch coincide con la carga/login).
                    if (intento >= 3) console.warn('rsCargarTemplates: no se pudieron cargar las plantillas', e);
                }
                await new Promise(res => setTimeout(res, 700 * intento));
            }
            // Última oportunidad: mensaje visible para el usuario.
            this.rsTemplatesRapidas = [];
            this.rsTemplatesMsg = '⚠️ No se pudieron cargar las plantillas. Usa ↻ para reintentar.';
        },
        // Añade los archivos seleccionados como adjuntos pendientes de la respuesta.
        rsAdjuntarArchivos(ev) {
            const files = ev && ev.target ? Array.from(ev.target.files || []) : [];
            if (!files.length) return;
            const actual = (this.rsAdjuntos || []).reduce((a, x) => a + (x.size || 0), 0);
            let total = actual;
            let aviso = '';
            for (const f of files) {
                if (!f.name || f.size <= 0) continue;
                total += f.size;
                if (total > 8 * 1024 * 1024) { aviso = 'El total de adjuntos no puede superar 8 MB.'; continue; }
                this.rsAdjuntos.push(f);
            }
            if (aviso) { this.rsEnvioMsg = aviso; this.rsEnvioMsgOk = false; }
            if (ev.target) ev.target.value = '';
        },
        // Quita un adjunto pendiente de la respuesta.
        rsQuitarAdjunto(i) { this.rsAdjuntos.splice(i, 1); },
        // Aplica una plantilla real de la BD al editor de respuesta.
        rsAplicarPlantillaRapida() {
            this.rsAplicarPlantillaId(this.rsPlantillaRapida);
        },
        // Aplica una plantilla (por su id) al editor rellenando los placeholders
        // con el contexto real de la conversación y la cuenta SMTP heredada.
        rsAplicarPlantillaId(id) {
            const tpl = (this.rsTemplatesRapidas || []).find(t => String(t.id) === String(id));
            if (!tpl || !this.rsSeleccion) return;
            const conv = this.rsSeleccion;
            // Sustitución contextual: rellena TODOS los placeholders con datos reales
            // del lead y de la cuenta SMTP heredada (la misma con la que se comunicó).
            const senderName = this.rsSenderName || 'FutProtec';
            const senderEmail = this.rsSenderEmail || '';
            const rep = (s) => String(s || '')
                .replace(/{{CONTACTO}}/g, conv.contacto_nombre || conv.persona_contacto || 'responsable')
                .replace(/{{VOLUMEN}}/g, conv.volumen_equipos || conv.volumen_estimado || '')
                .replace(/{{CLUB}}/g, conv.nombre_club || conv.club || '')
                .replace(/{{EMAIL}}/g, conv.email || senderEmail)
                .replace(/{{FEDERACION}}/g, conv.federacion || '')
                .replace(/{{ANIO}}/g, String(new Date().getFullYear()))
                .replace(/{{SENDER_NAME}}/g, senderName)
                .replace(/{{SENDER_TITLE}}/g, this.rsSenderTitle || 'Atención a Clubes - FutProtec')
                .replace(/{{SENDER_EMAIL}}/g, senderEmail)
                // Residuales: nunca dejar un placeholder sin resolver (evita filtros anti-spam).
                .replace(/\{\{[^}]+\}\}/g, '')
                .replace(/\{\[[^\]]+\]\}/g, '')
                .replace(/\[\[[^\]]+\]\]/g, '');
            this.rsRedaccion = this.rsATextoHtml(rep(tpl.cuerpo));
            if (tpl.asunto) this.rsAsuntoResp = rep(tpl.asunto);
            this.rsSyncEditorHtml();
        },
        // Convierte texto plano (con saltos de línea) a HTML para el editor.
        // Si ya contiene etiquetas HTML, lo deja tal cual.
        rsATextoHtml(t) {
            if (!t) return '';
            if (/<[a-zA-Z\/!][^>]*>/.test(String(t))) return String(t);
            return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        },
        // ─── Editor HTML de respuesta (sencillo, tipo TinyMCE) ────────────────
        // Sincroniza el HTML del editor (contenteditable) con rsRedaccion sin
        // re-renderizar (para no perder el cursor).
        rsSyncEditorHtml() {
            this.$nextTick(() => {
                const el = this.$refs && this.$refs.rsEditorBody;
                if (el) el.innerHTML = this.rsRedaccion || '';
            });
        },
        rsEditorInput() {
            const el = this.$refs && this.$refs.rsEditorBody;
            if (el) this.rsRedaccion = el.innerHTML;
        },
        rsEditorCmd(cmd) {
            const el = this.$refs && this.$refs.rsEditorBody;
            if (!el) return;
            el.focus();
            try { document.execCommand(cmd, false, null); } catch (e) { /* execCommand deprecated pero funcional */ }
            this.rsEditorInput();
        },
        rsEditorLink() {
            const url = prompt('URL del enlace:');
            if (!url) return;
            const el = this.$refs && this.$refs.rsEditorBody;
            if (!el) return;
            el.focus();
            try { document.execCommand('createLink', false, url); } catch (e) {}
            this.rsEditorInput();
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
                    // Aplicación inmediata: refleja el nuevo estado en la lista y el hilo.
                    await this.loadRespuestas();
                } else {
                    this.rsEnvioMsg = 'Error: ' + (j.error || 'No se pudo actualizar el estado.');
                    this.rsEnvioMsgOk = false;
                    // El cambio fue rechazado (p.ej. anti-regresión a 01/02 con
                    // conversación activa): restaurar el estado real del lead.
                    await this.loadRespuestas();
                }
            } catch (e) {
                this.rsEnvioMsg = 'Error de conexión al actualizar el estado.';
                this.rsEnvioMsgOk = false;
            }
        },
        // Genera un borrador de respuesta con la IA leyendo el diálogo completo.
        // Si la conversación NO tiene lead vinculado (p.ej. cuentas de prueba),
        // carga una plantilla de respuesta como borrador (la IA requiere historial
        // de lead) para poder contestar igualmente.
        async rsGenerarIA() {
            if (!this.rsSeleccion) {
                this.rsEnvioMsg = 'Selecciona una conversación.'; this.rsEnvioMsgOk = false; return;
            }
            if (!this.rsSeleccion.lead_id) {
                const tpl = (this.rsTemplatesRapidas || []).find(t => (t.categoria || '').includes('03'))
                    || (this.rsTemplatesRapidas || [])[0];
                if (tpl) {
                    this.rsAplicarPlantillaId(tpl.id);
                    this.rsEnvioMsg = 'No hay lead vinculado: se cargó «' + tpl.nombre + '» como borrador. Edítalo antes de enviar.';
                    this.rsEnvioMsgOk = true;
                } else {
                    this.rsEnvioMsg = 'No hay lead vinculado ni plantillas disponibles. Escribe la respuesta manualmente.';
                    this.rsEnvioMsgOk = false;
                }
                return;
            }
            this.rsGenerandoIA = true;
            this.rsEnvioMsg = '';
            try {
                const f = new FormData();
                f.append('action', 'generar_email_ia');
                f.append('lead_id', this.rsSeleccion.lead_id);
                f.append('plantilla_id', 0);
                const r = await fetch('?action=generar_email_ia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.rsRedaccion = this.rsATextoHtml(j.cuerpo || '');
                    this.rsSyncEditorHtml();
                    this.rsAsuntoResp = j.asunto || '';
                    this.rsEnvioMsg = '✨ Borrador generado con la IA — revísalo y edítalo antes de enviar.';
                    this.rsEnvioMsgOk = true;
                } else {
                    this.rsEnvioMsg = j.error || 'No se pudo generar la respuesta.'; this.rsEnvioMsgOk = false;
                }
            } catch (e) {
                this.rsEnvioMsg = 'Error de conexión al generar.'; this.rsEnvioMsgOk = false;
            } finally {
                this.rsGenerandoIA = false;
            }
        },
        // Cambia la pestaña de triaje de la Bandeja y recarga.
        rsSetTabTriaje(tab) {
            if (this.rsTabTriaje === tab) return;
            this.rsTabTriaje = tab;
            this.rsSeleccion = null;
            this.loadRespuestas();
        },
        // Aplica una acción de estado a la conversación seleccionada (hilo del lead).
        // Soporta conversaciones SIN lead vinculado (por email del remitente), de modo
        // que las cuentas de prueba o correos sin emparejar también se gestionan.
        async rsAccion(accion, dias = 0) {
            const conv = this.rsSeleccion;
            const leadId = (conv && conv.lead_id) || 0;
            const email = (conv && (conv.remitente_email || conv.email)) || '';
            // Conversaciones sin lead y sin remitente (p.ej. NDR con cabecera From
            // vacía): se identifican por el id de la respuesta (conv.id).
            const respuestaId = (conv && conv.id) || 0;
            if (!leadId && !email && !respuestaId) {
                this.rsEnvioMsg = 'Selecciona una conversación válida.';
                this.rsEnvioMsgOk = false;
                return;
            }
            const f = new FormData();
            f.append('action', 'conversacion_accion');
            f.append('lead_id', leadId);
            f.append('email', email);
            if (respuestaId) f.append('respuesta_id', respuestaId);
            f.append('accion', accion);
            if (dias > 0) f.append('dias', dias);
            try {
                const r = await fetch('?action=conversacion_accion', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) {
                    this.loadRespuestas();
                    this.rsSeleccion = null;
                } else {
                    this.rsEnvioMsg = j.error || 'Error al aplicar la acción.';
                    this.rsEnvioMsgOk = false;
                }
            } catch (e) {
                this.rsEnvioMsg = 'Error de conexión al aplicar la acción.';
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
            // Asunto de la respuesta: si no hay IA/plantilla, "Re: <asunto original>"
            // pero limpiando placeholders sin resolver ({{…}}, {[…]} o [[…]]) y
            // evitando "Re:" vacío (los placeholders sin resolver activan filtros
            // anti-spam como el de riobabel y el correo no llega).
            const asuntoBase = String(this.rsSeleccion.subject || this.rsSeleccion.asunto_envio || '')
                .replace(/\{\{[^}]+\}\}/g, '')
                .replace(/\{\[[^\]]+\]\}/g, '')
                .replace(/\[\[[^\]]+\]\]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            f.append('asunto', this.rsAsuntoResp || ('Re: ' + (asuntoBase || 'Espinilleras personalizadas')));
            // Cuerpo: limpiar cualquier placeholder residual antes de enviar.
            f.append('cuerpo', String(this.rsRedaccion)
                .replace(/\{\{[^}]+\}\}/g, '')
                .replace(/\{\[[^\]]+\]\}/g, '')
                .replace(/\[\[[^\]]+\]\]/g, ''));
            f.append('formato', 'html'); // el editor produce HTML (negritas, listas...)
            f.append('envio_id', this.rsSeleccion.envio_id || '');
            // Responder desde la misma cuenta SMTP con la que ya se comunicó el club.
            f.append('smtp_id', this.rsSmtpHeredada || 0);
            // Adjuntos seleccionados (archivos locales) → multipart/mixed.
            for (const a of (this.rsAdjuntos || [])) {
                f.append('adjunto[]', a, a.name);
            }
            // El servidor verifica que si aquí hay adjuntos, los recibe (nunca enviar
            // en silencio sin ellos: trazabilidad de subida).
            f.append('con_adjuntos', (this.rsAdjuntos || []).length);
            try {
                const r = await fetch('', { method: 'POST', body: f });
                const j = await r.json();
                if (j && j.ok) {
                    const nAdjEnv = (this.rsAdjuntos || []).length;
                    this.rsEnvioMsg = 'Respuesta enviada correctamente' + (nAdjEnv > 0 ? ' con ' + nAdjEnv + ' adjunto(s).' : '.');
                    this.rsEnvioMsgOk = true;
                    this.rsRedaccion = '';
                    this.rsPlantillaRapida = '';
                    this.rsAdjuntos = [];
                    // Fix "vuelve a 01 Sin Contactar": al responder, el lead pasa a
                    // conversación activa. Si el valor visible era 01/02 (desplegable
                    // sin refrescar), forzarlo a 03 para que nunca muestre regresión.
                    if (this.rsSeleccion && ['01 Sin Contactar', '02 Contactado'].includes(this.rsSeleccion.estado_lead)) {
                        this.rsSeleccion.estado_lead = '03 En Conversación';
                    }
                    // La conversación pasa a "en espera" (ya respondida).
                    this.rsAccion('espera');
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
        // contenido_html), con fallback al asunto si no hay texto extraíble.
        rsSnippet(conv) {
            if (!conv) return '';
            const ultimo = (conv.mensajes && conv.mensajes.length > 0) ? conv.mensajes[0] : null;
            let cuerpo = '';
            if (ultimo) {
                cuerpo = ultimo.cuerpo_limpio || ultimo.cuerpo_texto || ultimo.cuerpo || ultimo.contenido_html || '';
            }
            if (!cuerpo) cuerpo = conv.cuerpo_texto || conv.cuerpo || conv.snippet || '';
            // Si es HTML, extraer solo el texto visible.
            if (cuerpo && /<[a-z][\s\S]*>/i.test(cuerpo)) {
                const d = document.createElement('div');
                d.innerHTML = cuerpo;
                cuerpo = d.textContent || '';
            }
            const limpio = String(cuerpo).replace(/\s+/g, ' ').trim();
            if (limpio) return limpio.length > 110 ? limpio.slice(0, 110) + '…' : limpio;
            // Sin cuerpo extraíble: mostrar el asunto del último mensaje (trazabilidad).
            const asunto = (ultimo && (ultimo.subject_respuesta || ultimo.asunto_envio || '')) || conv.subject || '';
            return asunto ? '📄 ' + asunto : '(sin contenido de texto)';
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
                        random_mode: this.smtpRandom ? '1' : '0',
                        campaign_id: this.lzCampaignId || ''
                    });
                    // ANTI-CACHÉ: URL única por petición (el caché de SiteGround devolvía colas obsoletas).
                    params.set('_ts', Date.now());
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
        tab: 'inicio',
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
        rsClasBotonLabel: function () { return ''; },
        rsCargarTemplates: function () {},
        rsAdjuntarArchivos: function () {},
        rsQuitarAdjunto: function () {},
        // Métodos del modal de atención
        atencionAdjuntarArchivos: function () {},
        atencionQuitarAdjunto: function () {},
        atencionSeleccionar: function () {},
        cargarConversacionesAtencion: function () {},
        atencionEsActual: function () { return false; },
        atencionEstadoHilo: function () { return ''; },
        atencionSnooze: function () { return ''; },
        charlaHilo: function () { return []; },
        rsCuerpoMensaje: function () { return ''; },
        rsTiempoRelativo: function () { return ''; },
        rsEstadoHilo: function () { return { esperando: false, label: '', fecha: '' }; },
        rsAplicarPlantillaId: function () {},
        // Métodos de perfil / Mi cuenta
        perfilIniciales: function () { return 'FP'; },
        perfilCargar: function () {},
        perfilGuardar: function () {},
        perfilCambiarPass: function () {},
        perfilGuardarEmail: function () {},
        // Repositorio de adjuntos
        cargarRepoAdjuntos: function () {},
        rsAgregarRepoAdjunto: function () {},
        atencionAgregarRepoAdjunto: function () {},
        // Editor HTML de respuesta
        rsSyncEditorHtml: function () {},
        rsEditorInput: function () {},
        rsEditorCmd: function () {},
        rsEditorLink: function () {},
        rsATextoHtml: function () {},
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







// ═════════════════════════════════════════════════════════════════════════════
// secuenciaConfig — Configurador de secuencias condicionales (O-1, ramal ABC).
// Plan: docs/PLAN_RAMIFICACION_SECUENCIAS_ABC.md
// Usado por tabs/editor.php (x-data="secuenciaConfig()").
// ═════════════════════════════════════════════════════════════════════════════
function secuenciaConfig() {
    return {
        campanas: [], plantillas: [],
        campaignId: 0, secuencias: [],
        edit: { id: 0, nombre: '', modo_auto: 0, pasos: [], rotar_no_abridores: 0, rotar_espera_dias: 3, rotar_max_envios: 2, rotar_plantilla_id: 0 },
        formVisible: false,
        msg: '', msgOk: false,
        async cargar() {
            this.msg = '';
            try { const rC = await fetch('?action=get_piloto_campanas'); const jC = await rC.json(); if (jC.ok) this.campanas = jC.campanas || []; } catch (e) {}
            try { const rT = await fetch('?action=get_templates'); const jT = await rT.json(); if (jT.ok) this.plantillas = jT.templates || []; } catch (e) {}
            if (this.campaignId > 0) await this.cargarSecuencias();
        },
        async cargarSecuencias() {
            try { const r = await fetch('?action=get_secuencias&campaign_id=' + this.campaignId); const j = await r.json(); if (j.ok) this.secuencias = j.secuencias || []; } catch (e) { this.secuencias = []; }
        },
        nuevaSecuencia() {
            if (!this.campaignId) { this.msg = 'Selecciona una campaña primero.'; this.msgOk = false; return; }
            this.edit = { id: 0, nombre: 'Secuencia de la campaña', modo_auto: 0, pasos: [{ paso: 1, plantilla_id: 0, espera_dias: 2, ramal: '', activo: 1 }], rotar_no_abridores: 0, rotar_espera_dias: 3, rotar_max_envios: 2, rotar_plantilla_id: 0 };
            this.formVisible = true;
            this.msg = ''; this.msgOk = false;
        },
        editar(s) {
            this.edit = { id: s.id, nombre: s.nombre, modo_auto: s.modo_auto, pasos: (s.pasos || []).map(p => ({ paso: p.paso, plantilla_id: p.plantilla_id, espera_dias: p.espera_dias, ramal: p.ramal || '', activo: p.activo })), rotar_no_abridores: +(s.rotar_no_abridores || 0), rotar_espera_dias: +(s.rotar_espera_dias || 3), rotar_max_envios: +(s.rotar_max_envios || 2), rotar_plantilla_id: +(s.rotar_plantilla_id || 0) };
            this.formVisible = true;
            this.msg = ''; this.msgOk = false;
        },
        addPaso() { const max = this.edit.pasos.reduce((m, p) => Math.max(m, p.paso || 0), 0); this.edit.pasos.push({ paso: max + 1, plantilla_id: 0, espera_dias: 2, ramal: '', activo: 1 }); },
        removePaso(idx) { this.edit.pasos.splice(idx, 1); },
        async guardar() {
            if (!this.campaignId || !this.edit.nombre) { this.msg = 'Nombre y campaña son obligatorios.'; this.msgOk = false; return; }
            try {
                const f = new FormData();
                f.append('action', 'save_secuencia');
                f.append('id', this.edit.id);
                f.append('campaign_id', this.campaignId);
                f.append('nombre', this.edit.nombre);
                f.append('modo_auto', this.edit.modo_auto);
                f.append('activo', '1');
                f.append('rotar_no_abridores', this.edit.rotar_no_abridores ? '1' : '0');
                f.append('rotar_espera_dias', this.edit.rotar_espera_dias || 3);
                f.append('rotar_max_envios', this.edit.rotar_max_envios || 2);
                f.append('rotar_plantilla_id', this.edit.rotar_plantilla_id || 0);
                f.append('pasos', JSON.stringify(this.edit.pasos));
                const r = await fetch('?action=save_secuencia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) { this.msg = 'Secuencia guardada ✓'; this.msgOk = true; this.edit.id = j.id; await this.cargarSecuencias(); }
                else { this.msg = j.error || 'Error al guardar'; this.msgOk = false; }
            } catch (e) { this.msg = 'Error de conexión'; this.msgOk = false; }
        },
        async eliminar(s) {
            if (!confirm('¿Eliminar la secuencia "' + s.nombre + '"? No toca envíos ya registrados.')) return;
            try {
                const f = new FormData();
                f.append('action', 'delete_secuencia');
                f.append('id', s.id);
                const r = await fetch('?action=delete_secuencia', { method: 'POST', body: f });
                const j = await r.json();
                if (j.ok) { if (this.edit.id === s.id) { this.edit = { id: 0, nombre: '', modo_auto: 0, pasos: [], rotar_no_abridores: 0, rotar_espera_dias: 3, rotar_max_envios: 2, rotar_plantilla_id: 0 }; this.formVisible = false; } await this.cargarSecuencias(); }
                else { alert(j.error || 'Error al eliminar'); }
            } catch (e) { alert('Error de conexión'); }
        },
    };
}

// ─── Histórico de pruebas (Lanzadera) — registro efímero del modo test ─────
function pruebasHistorico() {
    return {
        historico: [],
        async cargar() {
            try {
                const r = await fetch('?action=get_test_history');
                const j = await r.json();
                this.historico = (j && j.ok) ? j.items : [];
                if (window.lucide) lucide.createIcons();
            } catch (e) { this.historico = []; }
        },
        async limpiar() {
            const c = prompt('Escribe CONFIRMAR para limpiar el histórico de pruebas. Esta acción es irreversible (se hace backup previo).');
            if (c !== 'CONFIRMAR') { alert('Operación cancelada.'); return; }
            const body = new URLSearchParams({ action: 'clear_test_history', confirm: 'CONFIRMAR' });
            const r = await fetch('?action=clear_test_history', { method: 'POST', body });
            const j = await r.json();
            if (j.ok) { alert('Histórico limpiado. Backup: ' + (j.backup || '')); await this.cargar(); }
            else { alert(j.error || 'Error al limpiar histórico'); }
        }
    };
}


// ═════════════════════════════════════════════════════════════════════════════
// WIDGET DE TUTORÍA FLOTANTE — Guía de uso por tab + pasos de configuración.
// Contenido por tab; la lógica (arrastre/minimizar) vive en tutorApp().
// ═════════════════════════════════════════════════════════════════════════════
const TUTORIA = {
    inicio: { titulo: '🏠 Inicio — Resumen del día', pasos: [
        'Pulsa ✨ Generar con IA para ver las prioridades de hoy, alertas de retraso y la franja horaria recomendada según aperturas reales.',
        'KPIs: pendientes hoy, respuestas sin atender, mockups, proformas y acciones vencidas.',
        '"Qué enviar hoy": 1er y 2º toque que requieren tu acción.',
        '"Qué conseguir por cliente": pendientes para cerrar cada club.',
        'Bandeja resumida: últimas respuestas; pulsa "Ver Bandeja completa" para atenderlas.',
    ] },
    kanban: { titulo: '🧱 Pipeline — Estado de la negociación', pasos: [
        'Arrastra las tarjetas entre columnas para mover el lead de etapa (01 Sin Contactar → 07 Baja).',
        'Chips: 🔥 Calientes (+2 aperturas), 👁️ Leídos (1+), 📱 Pendiente WA, Federación y buscador.',
        'Cada tarjeta muestra: temperatura (🌋🔥⏳🥶), ramal de interés (A/B/C), nº de aperturas, clasificación IA y ● NUEVO si hay actividad hoy.',
        'Clic en la tarjeta → ficha completa (timeline, mockup, presupuesto, próxima acción).',
        'Clic en WA → abre WhatsApp y registra el contacto automáticamente (avanza el lead).',
    ] },
    gestor: { titulo: '📇 Leads — Base de datos', pasos: [
        'Añadir Lead: alta manual validando el email (MX).',
        'Busca por club o email; filtra por estado y federación; ordena por columnas.',
        'Escanear Duplicados: detecta clubes repetidos y fusiona.',
        'Clic en la fila → ficha del lead con timeline y acciones.',
    ] },
    editor: { titulo: '📝 Plantillas y Campañas — Configuración del envío', pasos: [
        'PASO 1 · PLANTILLA: crea el email y asígnale la categoría por objetivo (01 Prospección / 02 Seguimiento / 03 Respuestas). El paso se indica en el nombre (Paso 1, 2, 3…).',
        'Activa 🧪 Test A/B/C para probar 3 asuntos y cuerpos. Cada lead recibe SIEMPRE la misma variante (determinística), así sabes qué ángulo le interesa.',
        'Usa los tags {{CLUB}}, {{CONTACTO}}, {{FEDERACION}}, {{ANIO}} y {{SENDER_*}} en asunto y cuerpo.',
        'PASO 2 · CAMPAÑA: nombre, identificador, estado, entorno, federaciones y plantillas. Guarda.',
        'ESTADO vs ENTORNO: el ESTADO (DRAFT/PILOT/ACTIVE) es la fase — solo PILOT/ACTIVE pueden enviar (DRAFT no). El ENTORNO (TEST/PILOT/PRODUCTION) define con qué datos: TEST solo leads de prueba; PILOT/PRODUCTION van a clubes reales (operan con el motor en modo producción).',
        'Cuando la campaña está ACTIVE, los campos de entorno/federaciones/plantillas se ocultan para no alterarla en marcha. Para cambiarlos, pásala a PILOT/DRAFT, guárdala y vuelve a ACTIVE.',
        'PASO 3 · SECUENCIA (opcional): elige la campaña, crea la secuencia y define pasos (plantilla + espera en días + ramal A/B/C).',
        'Modo 🟡 Asistido → el Paso 2/3 sale como sugerencia en Seguimiento; 🟢 Automático → el cron envía solo. El paso solo se dispara si el ramal coincide con el que el lead más abrió.',
    ] },
    smtp: { titulo: '⚙️ Ajustes — IA, correo y seguridad', pasos: [
        'IA: elige proveedor (DeepSeek/OpenAI/Anthropic/Gemini/Mistral/Groq), API key y modelo. El "conocimiento de producto" mejora los borradores.',
        'Cuentas SMTP: añade/edita cuentas, prueba conexión, activa/desactiva y define límite diario.',
        'Seguridad: cambia la contraseña del panel y el email de recuperación.',
        'Gestión de Pruebas: emails de prueba para verificar envíos sin tocar leads reales.',
    ] },
    lanza: { titulo: '🚀 Lanzadera — Envío masivo', pasos: [
        '1) Campaña (obligatoria) · 2) Federación · 3) Estado del lead · 4) Plantilla de email.',
        'Pulsa 🔵 Cargar Cola: se listan los candidatos con su cuenta SMTP asignada (round-robin) y hora estimada.',
        'Configura el tamaño de lote y pulsa Iniciar: respeta el delay y los límites diarios por cuenta.',
        'Desde Seguimiento puedes "Enviar a Lanzadera" una selección exacta (modo lote).',
        'Monitorea el log: ✅ enviados / ❌ errores, con la cuenta utilizada.',
    ] },
    respuestas: { titulo: '📥 Bandeja — Respuestas clasificadas por IA', pasos: [
        'Las respuestas entrantes se clasifican con IA: POSITIVE / NEGATIVE / NEUTRAL / UNSUBSCRIBE / OOO.',
        'Clic en una conversación → visor con el hilo; reclasifica si la IA se equivoca (mueve el lead según el sentimiento).',
        'Responder: redacta y envía con la cuenta SMTP original (incluye tracking).',
        'La campana 🔔 del header muestra cuántas respuestas tienes sin atender.',
    ] },
    seguimiento: { titulo: '🎯 Seguimiento — Tu consola de decisiones', pasos: [
        'Cola unificada: Semáforo (qué hacer) + Temperatura (interés) + Ramal (ángulo validado).',
        'Vistas: Todos / 🌱 Calentar / 🎯 Perseguir / 🔥 Calientes (≥3 apert.) / 🤝 Cerrar / 🎨 Mockup / 🧾 Proforma / 📋 Secuencia.',
        'Filtros: Federación, Estado, Variante e Interés (General / Identidad / Financiero).',
        'Por fila: 🎯 Atender (modal IA con borrador editable), Ficha; en Secuencia: 📨 Enviar / 🗑️ Descartar.',
        'Selección múltiple: marca checkboxes y usa "Enviar a Lanzadera" o "Programar próxima acción" en lote.',
        'El semáforo tiene tooltip con el motivo (aperturas, vencida, mockup pendiente…).',
    ] },
    analytics: { titulo: '📊 Analytics — Rendimiento de campaña', pasos: [
        'Elige la campaña: KPIs de envíos, entregados, aperturas, respuestas y PRR.',
        'Embudo de conversión y comparativa A/B/C con la variante ganadora.',
        'Clasificación IA de respuestas: positivas / negativas / neutrales / bajas.',
    ] },
    lista_negra: { titulo: '🚫 Lista Negra — Supresión', pasos: [
        'Busca un lead y pulsa "Añadir a Lista Negra" (se guarda su estado anterior para poder restaurarlo).',
        'Los leads suprimidos nunca vuelven a recibir envíos.',
        '"Quitar de Lista Negra" restaura el estado anterior.',
    ] },
};

// tutorApp — Widget de tutoría del topbar (junto a la campana): panel desplegable
// con la guía del tab activo. Estado abierto/cerrado persistido.
function tutorApp() {
    return {
        abierto: false,
        init() {
            try { this.abierto = localStorage.getItem('crm_tutor_open') === '1'; } catch (e) {}
        },
        get guia() {
            const tab = (window.app && window.app.tab) || 'inicio';
            return TUTORIA[tab] || TUTORIA.inicio;
        },
        toggle() {
            this.abierto = !this.abierto;
            try { localStorage.setItem('crm_tutor_open', this.abierto ? '1' : '0'); } catch (e) {}
        },
        cerrar() {
            this.abierto = false;
            try { localStorage.setItem('crm_tutor_open', '0'); } catch (e) {}
        },
    };
}

function importadorCSV() {
    // Importación de leads desde CSV (botón "📥 Importar CSV" en la pestaña Leads).
    // Parsing en cliente para vista previa y mapeo; el backend (api/leads.php
    // action=importar_csv) re-lee el archivo y aplica el mismo mapeo.
    return {
        abierto: false,
        archivo: null,
        delimitador: 'auto',
        conCabecera: true,
        headers: [],
        filasPreview: [],
        mapa: {},
        campos: ['email', 'nombre_club', 'telefono_movil', 'telefono_fijo', 'persona_contacto', 'cargo_contacto', 'federacion'],
        camposLabel: {
            email: 'Email', nombre_club: 'Nombre del club', telefono_movil: 'Teléfono móvil',
            telefono_fijo: 'Teléfono fijo', persona_contacto: 'Persona de contacto', cargo_contacto: 'Cargo', federacion: 'Federación'
        },
        validarMx: true,
        ignorarDuplicados: true,
        importando: false,
        msg: '',
        msgOk: false,
        resultado: null,

        abrir() {
            // Reset del estado previo al abrir el modal.
            this.archivo = null; this.headers = []; this.filasPreview = []; this.mapa = {};
            this.msg = ''; this.msgOk = false; this.resultado = null;
            this.abierto = true;
        },
        cerrar() { this.abierto = false; },
        cargarArchivo(ev) {
            const file = ev && ev.target ? ev.target.files[0] : null;
            if (!file) return;
            this.archivo = file;
            this.msg = ''; this.msgOk = false; this.resultado = null;
            this.headers = []; this.filasPreview = []; this.mapa = {};
            const reader = new FileReader();
            reader.onload = (e) => this.parsear(String(e.target.result || ''));
            reader.onerror = () => { this.msg = 'No se pudo leer el archivo.'; this.msgOk = false; };
            reader.readAsText(file, 'UTF-8');
        },
        parsear(texto) {
            const filas = this.csvParse(texto, this.delimitador);
            if (!filas.length) { this.msg = 'El archivo está vacío o no se pudo parsear.'; this.msgOk = false; return; }
            if (this.conCabecera) {
                this.headers = filas[0].map(h => String(h).trim());
                this.filasPreview = filas.slice(1, 6);
            } else {
                this.headers = filas[0].map((_, i) => 'Columna ' + (i + 1));
                this.filasPreview = filas.slice(0, 5);
            }
            this.mapa = this.autoMapa(this.headers);
            this.msg = ''; this.msgOk = false;
        },
        // Parser CSV robusto: comillas dobles, delimitadores comunes y saltos de
        // línea dentro de comillas.
        csvParse(texto, delim) {
            const d = delim === 'auto' ? null : delim;
            const filas = [];
            let fila = [], campo = '', enComillas = false;
            const pushCampo = () => { fila.push(campo); campo = ''; };
            const pushFila = () => { pushCampo(); filas.push(fila); fila = []; };
            for (let i = 0; i < texto.length; i++) {
                const ch = texto[i];
                if (enComillas) {
                    if (ch === '"') {
                        if (texto[i + 1] === '"') { campo += '"'; i++; }
                        else enComillas = false;
                    } else campo += ch;
                    continue;
                }
                if (ch === '"') { enComillas = true; continue; }
                if (d === null) {
                    if (ch === ',' || ch === ';' || ch === '|' || ch === '\t') { pushCampo(); continue; }
                } else if (ch === d) { pushCampo(); continue; }
                if (ch === '\n') { pushFila(); continue; }
                if (ch === '\r') continue;
                campo += ch;
            }
            if (campo !== '' || fila.length) pushFila();
            return filas;
        },


        // Mapeo automático por sinónimos del encabezado.
        autoMapa(headers) {
            const mapa = {};
            const syn = {
                email: ['email', 'e-mail', 'correo', 'correo electronico', 'correo-e'],
                nombre_club: ['nombre', 'club', 'nombre_club', 'nombre del club', 'equipo', 'club deportivo', 'entidad'],
                telefono_movil: ['movil', 'telefono_movil', 'telefono movil', 'celular', 'tfno', 'telefono', 'móvil'],
                telefono_fijo: ['telefono_fijo', 'fijo', 'telefono fijo'],
                persona_contacto: ['persona_contacto', 'contacto', 'persona', 'nombre contacto', 'responsable', 'presidente'],
                cargo_contacto: ['cargo_contacto', 'cargo', 'puesto'],
                federacion: ['federacion', 'fed', 'federación', 'ff', 'rffm', 'rfef']
            };
            headers.forEach((h, i) => {
                const hl = String(h).toLowerCase().replace(/[\s_\-]+/g, ' ');
                for (const campo of this.campos) {
                    if ((syn[campo] || []).some(s => hl.includes(s))) { mapa[i] = campo; break; }
                }
            });
            return mapa;
        },
        async importar() {
            if (!this.archivo) { this.msg = 'Selecciona un archivo CSV primero.'; this.msgOk = false; return; }
            const valores = Object.values(this.mapa);
            if (!valores.includes('email') || !valores.includes('nombre_club')) {
                this.msg = 'El mapeo necesita una columna Email y otra de Nombre del club.';
                this.msgOk = false;
                return;
            }
            this.importando = true; this.msg = ''; this.msgOk = false; this.resultado = null;
            const f = new FormData();
            f.append('csv', this.archivo);
            f.append('delimitador', this.delimitador);
            f.append('mapeo', JSON.stringify(this.mapa));
            f.append('validar_mx', this.validarMx ? '1' : '0');
            f.append('ignorar_duplicados', this.ignorarDuplicados ? '1' : '0');
            f.append('con_cabecera', this.conCabecera ? '1' : '0');
            try {
                const r = await fetch('api/leads.php?action=importar_csv', { method: 'POST', body: f });
                const j = await r.json();
                if (j && j.ok) {
                    this.resultado = j;
                    this.msg = 'Importación completada: ' + j.importados + ' leads creados.';
                    this.msgOk = true;
                    // Refresca la lista de Leads tras importar.
                    if (window.app && window.app.loadGestor) window.app.loadGestor();
                } else {
                    this.msg = (j && j.error) || 'Error al importar.';
                    this.msgOk = false;
                }
            } catch (e) {
                this.msg = 'Error de conexión al importar.';
                this.msgOk = false;
            } finally {
                this.importando = false;
            }
        }
    };
}

