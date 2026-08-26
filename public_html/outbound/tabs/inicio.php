<!-- ═══════════ INICIO — Qué enviar hoy + Qué conseguir por cliente ═══════════ -->
<div class="space-y-4" x-data x-init="loadInicio()">

  <!-- Resumen del día (IA) -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
    <div class="flex items-center gap-3 flex-wrap mb-3">
      <i data-lucide="sunrise" class="w-5 h-5 text-violet-400"></i>
      <span class="text-base font-semibold uppercase tracking-wider text-slate-200">Resumen del día</span>
      <span class="text-xs text-slate-400 ml-auto" x-show="resumenFresco" x-text="resumenFresco ? 'generado ' + resumenFresco.toLocaleTimeString('es-ES', {hour:'2-digit', minute:'2-digit'}) : ''"></span>
      <button @click="generarResumenDia()" :disabled="generandoResumen" class="px-3 py-1.5 bg-violet-500/20 text-violet-400 border border-violet-500/30 rounded-lg text-sm font-semibold hover:bg-violet-500/30 transition disabled:opacity-50">
        <span x-show="!generandoResumen">✨ Generar resumen con IA</span>
        <span x-show="generandoResumen" class="flex items-center gap-1.5"><span class="w-3 h-3 border-2 border-violet-400 border-t-transparent rounded-full animate-spin inline-block"></span> Analizando…</span>
      </button>
    </div>
    <div x-show="resumenDia" class="bg-slate-950/60 border border-slate-700/50 rounded-lg p-3.5 text-sm text-slate-300 whitespace-pre-wrap leading-relaxed" x-text="resumenDia"></div>
    <div x-show="!resumenDia && !generandoResumen" class="text-sm text-slate-400">
      Pulsa <span class="text-violet-400 font-semibold">✨ Generar resumen con IA</span> para ver las prioridades de hoy, alertas de retraso y la franja horaria recomendada.
    </div>
    <div x-show="resumenError" class="text-sm text-rose-400 mt-2" x-text="resumenError"></div>
  </div>

  <!-- KPIs rápidos -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3" x-show="inicio">
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
      <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Pendientes hoy</div>
      <div class="text-2xl font-semibold text-violet-400 mt-1" x-text="inicio.kpis.pendientes_hoy"></div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
      <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Respuestas sin atender</div>
      <div class="text-2xl font-semibold text-cyan-400 mt-1" x-text="inicio.kpis.respuestas_sin_atender"></div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
      <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Mockups por presentar</div>
      <div class="text-2xl font-semibold text-purple-400 mt-1" x-text="inicio.kpis.mockups_pendientes"></div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
      <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Proformas por enviar</div>
      <div class="text-2xl font-semibold text-violet-400 mt-1" x-text="inicio.kpis.proformas_por_presentar"></div>
    </div>
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
      <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Acciones vencidas</div>
      <div class="text-2xl font-semibold mt-1" :class="(inicio.kpis.acciones_vencidas || 0) > 0 ? 'text-rose-400' : 'text-slate-500'" x-text="inicio.kpis.acciones_vencidas"></div>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- 📤 Qué enviar hoy -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
      <div class="flex items-center gap-2 px-4 py-2.5 border-b border-slate-800">
        <i data-lucide="send" class="w-4 h-4 text-amber-400"></i>
        <span class="text-sm font-semibold uppercase tracking-wider text-slate-200">Qué enviar hoy</span>
        <span class="text-xs text-slate-400 ml-auto" x-text="(inicio ? inicio.acciones.length : 0) + ' acciones'"></span>
      </div>
      <div class="p-2 space-y-2 max-h-[420px] overflow-y-auto">
        <template x-for="a in (inicio ? inicio.acciones : [])" :key="'a'+a.lead_id+'-'+a.tipo">
          <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="tipoAccionClase(a.tipo)" x-text="tipoAccionLabel(a.tipo)"></span>
              <span class="text-sm font-semibold text-slate-200" x-text="a.nombre_club"></span>
              <span class="text-xs text-slate-500" x-text="a.email"></span>
            </div>
            <div class="text-xs text-slate-400 mt-1" x-text="a.razon"></div>
            <div class="mt-2 flex justify-end">
              <button @click="irAAtender({id: a.lead_id, nombre_club: a.nombre_club, email: a.email})" class="px-2.5 py-1 bg-violet-500/15 text-violet-400 border border-violet-500/30 rounded-lg text-xs font-semibold hover:bg-violet-500/25 transition">🎯 Atender</button>
            </div>
          </div>
        </template>
        <div x-show="inicio && inicio.acciones.length === 0" class="text-sm text-slate-400 text-center py-6">Sin envíos pendientes. 🎉</div>
      </div>
    </div>

    <!-- 🎯 Qué conseguir por cliente -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
      <div class="flex items-center gap-2 px-4 py-2.5 border-b border-slate-800">
        <i data-lucide="target" class="w-4 h-4 text-emerald-400"></i>
        <span class="text-sm font-semibold uppercase tracking-wider text-slate-200">Qué conseguir por cliente</span>
      </div>
      <div class="p-2 space-y-2 max-h-[420px] overflow-y-auto">
        <template x-for="c in (inicio ? inicio.conseguir : [])" :key="'c'+c.lead_id+'-'+c.pendiente">
          <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-semibold text-slate-200" x-text="c.nombre_club"></span>
              <span class="px-1.5 py-0.5 rounded-full text-xs bg-blue-500/15 text-blue-400 border border-blue-500/30" x-text="c.estado_lead"></span>
            </div>
            <div class="text-xs text-slate-400 mt-1" x-text="c.pendiente"></div>
            <div class="mt-2 flex justify-end">
              <button @click="irAAtender({id: c.lead_id, nombre_club: c.nombre_club, email: ''})" class="px-2.5 py-1 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/25 transition">🎯 Gestionar</button>
            </div>
          </div>
        </template>
        <div x-show="inicio && inicio.conseguir.length === 0" class="text-sm text-slate-400 text-center py-6">Todo al día. 💪</div>
      </div>
    </div>
  </div>

  <!-- Bandeja resumida -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
    <div class="flex items-center gap-2 px-4 py-2.5 border-b border-slate-800">
      <i data-lucide="inbox" class="w-4 h-4 text-cyan-400"></i>
      <span class="text-sm font-semibold uppercase tracking-wider text-slate-200">Bandeja (últimas respuestas)</span>
      <button @click="tab='respuestas'; loadRespuestas()" class="px-2.5 py-1 bg-cyan-500/15 text-cyan-400 border border-cyan-500/30 rounded-lg text-xs font-semibold hover:bg-cyan-500/25 transition ml-auto">Ver Bandeja completa →</button>
    </div>
    <div class="p-2 space-y-1.5 max-h-[260px] overflow-y-auto">
      <template x-for="b in (inicio ? inicio.bandeja : [])" :key="'b'+b.id">
        <div class="bg-slate-800/40 border border-slate-700/50 rounded-lg px-3 py-2 flex items-center gap-3 flex-wrap">
          <span class="inline-block w-2 h-2 rounded-full shrink-0" :class="b.notificado == 0 ? 'bg-cyan-400 shadow-[0_0_6px_rgba(34,211,238,0.6)]' : 'bg-slate-600'"></span>
          <div class="flex-1 min-w-[200px]">
            <div class="text-sm text-slate-200 truncate max-w-[420px]" x-text="b.subject || b.remitente"></div>
            <div class="text-xs text-slate-400" x-text="(b.remitente || '') + ' · ' + (b.fecha_respuesta || '').slice(0,16)"></div>
          </div>
          <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold" :class="b.clasificacion ? 'bg-violet-500/15 text-violet-400 border border-violet-500/30' : 'bg-slate-800 text-slate-500'" x-text="b.clasificacion || 'sin clasificar'"></span>
        </div>
      </template>
      <div x-show="inicio && inicio.bandeja.length === 0" class="text-sm text-slate-400 text-center py-6">Sin respuestas recientes.</div>
    </div>
  </div>

  <p x-show="inicioCargando" class="text-sm text-slate-400 text-center py-4">Cargando inicio…</p>
  <p x-show="!inicioCargando && inicioError" class="text-sm text-rose-400 text-center py-4" x-text="inicioError"></p>
</div>
