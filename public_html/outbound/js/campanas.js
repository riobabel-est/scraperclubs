// campanas.js — campanasConfig (Alpine). Extraido de app.js (refactor modular 2026-08-26).
// Depende de app() en runtime (window.app).

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
        seleccionarTodasFed() {
            this.form.federaciones = this.federaciones.slice();
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
