<!-- ═══════════ ANALYTICS DE CAMPAÑA (consolidado) ═══════════ -->
<!-- Vista única: KPIs + Embudo + A/B/C + Clasificación IA. Reemplaza Piloto/Global. -->
<div x-data="analyticsCampana()" x-init="init()" class="space-y-6">
  <style>
    /* Bloques de Analytics a 50% (2 columnas) en pantallas grandes: mejora la
       legibilidad de embudo, desglose, clasificación y asistente. El CSS
       compilado no incluye lg:grid-cols-2, por eso se define con media query. */
    .an-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 1024px) { .an-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  </style>

  <!-- Header de campaña -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center gap-3">
    <i data-lucide="bar-chart-3" class="w-5 h-5 text-amber-400"></i>
    <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Analytics de Campaña</h5>
    <span class="text-xs text-slate-400 ml-auto" x-show="metricas?.campaña"
      x-text="'Estado: ' + (metricas?.campaña?.estado || '—') + ' · Entorno: ' + (metricas?.campaña?.entorno || '—')"></span>
  </div>

  <!-- Selector de campaña (el contexto global la preselecciona) -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center gap-3">
    <label class="text-sm font-semibold text-slate-300">Campaña</label>
    <select x-model="campaignId" @change="cargar()"
      class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
      <option value="0">— Seleccionar campaña —</option>
      <template x-for="c in campanas" :key="c.id"><option :value="c.id" x-text="c.identificador || c.nombre"></option></template>
    </select>
    <span class="text-xs text-slate-500" x-show="typeof window._campanaActual !== 'undefined' && window._campanaActual > 0">(Contexto global aplicado)</span>
  </div>

  <div x-show="!campaignId" class="text-slate-400 text-sm py-12 text-center">Selecciona una campaña para ver su analítica.</div>

  <div x-show="campaignId" x-cloak>
    <div class="an-grid">
    <!-- BLOQUE 1: Embudo de conversión (los KPIs de leads viven en la cabecera del panel) -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3 flex-wrap">
        <i data-lucide="trending-down" class="w-4 h-4 text-amber-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">Embudo de Conversión</h5>
        <span class="text-xs text-slate-500 ml-auto">% conversión entre etapas</span>
      </div>

      <div class="space-y-2">
        <template x-for="f in funnelLeads" :key="f.label">
          <div class="flex items-center gap-2">
            <div class="w-24 text-xs text-slate-400 truncate" x-text="f.label"></div>
            <div class="flex-1 bg-slate-800 rounded h-5 overflow-hidden relative min-w-0">
              <div class="h-full bg-amber-500/70 transition-all" :style="'width:' + Math.max(2, Math.round(f.cnt / funnelLeadsMax * 100)) + '%'"></div>
              <div class="absolute inset-0 flex items-center px-2 text-[10px] font-semibold"
                   :class="f.cnt / funnelLeadsMax > 0.4 ? 'text-slate-900' : 'text-slate-300'"
                   x-text="f.maxPct + '% de tocados'"></div>
            </div>
            <div class="w-10 text-xs text-right text-slate-300 font-semibold" x-text="f.cnt"></div>
            <div class="w-14 text-xs text-right" :class="f.pct !== null ? (f.pct >= 50 ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-500'"
                 x-text="f.pct !== null ? f.pct + '%' : '—'"></div>
          </div>
        </template>
        <div x-show="funnelLeads.length && !funnelLeads.some(f => f.cnt > 0)" class="text-sm text-slate-400 py-4 text-center">Sin envíos reales en esta campaña.</div>
      </div>

      <!-- Cuello de botella del embudo real (mayor caída de conversión) -->
      <div x-show="cuelloLeads" class="mt-3 flex items-center gap-2 text-xs bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2">
        <i data-lucide="alert-triangle" class="w-4 h-4 text-rose-400 shrink-0"></i>
        <span class="text-slate-300">Cuello de botella:</span>
        <span class="text-rose-400 font-semibold" x-text="cuelloLeads.origen + ' → ' + cuelloLeads.destino + ' (' + cuelloLeads.pct + '%)'"></span>
      </div>
    </div>

    <!-- BLOQUE 2: A/B/C -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-x-auto">
      <div class="flex items-center gap-2 mb-3 flex-wrap">
        <i data-lucide="git-compare" class="w-4 h-4 text-amber-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">Desglose A/B/C</h5>
        <span x-show="varianteGanadora" class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">🏆 Ganadora: <span x-text="varianteGanadora"></span></span>
      </div>
      <table class="w-full text-xs">
        <thead><tr class="text-slate-400 text-xs font-semibold uppercase tracking-wider border-b border-slate-800">
          <th class="px-2 py-2 text-left">Variante</th>
          <th class="px-2 py-2 text-right">Aceptados</th>
          <th class="px-2 py-2 text-right">Aperturas</th>
          <th class="px-2 py-2 text-right">Apert. Tot.</th>
          <th class="px-2 py-2 text-right">Respuestas</th>
          <th class="px-2 py-2 text-right">Positivas</th>
          <th class="px-2 py-2 text-right">Negativas</th>
          <th class="px-2 py-2 text-right">Neutrales</th>
          <th class="px-2 py-2 text-right">UNSUB</th>
          <th class="px-2 py-2 text-right">OOO</th>
          <th class="px-2 py-2 text-right">PRR</th>
        </tr></thead>
        <tbody>
          <template x-for="v in ['A','B','C']" :key="v">
            <tr class="border-b border-slate-800/50" :class="varianteGanadora === v ? 'bg-emerald-500/5' : ''">
              <td class="px-2 py-2 font-semibold" x-text="v"></td>
              <td class="px-2 py-2 text-right" x-text="(metricas?.variantes?.[v]?.aceptados ?? 0)"></td>
              <td class="px-2 py-2 text-right text-cyan-400" x-text="(metricas?.variantes?.[v]?.aperturas ?? 0)"></td>
              <td class="px-2 py-2 text-right">
                <span class="font-semibold text-cyan-300" x-text="(metricas?.variantes?.[v]?.aperturas_totales ?? 0)"></span>
                <span class="text-[11px] text-slate-500" x-show="((metricas?.variantes?.[v]?.aperturas ?? 0) > 1)"
                  x-text="' ×' + Math.round((metricas?.variantes?.[v]?.aperturas_totales ?? 0) / (metricas?.variantes?.[v]?.aperturas ?? 1) * 10) / 10"></span>
              </td>
              <td class="px-2 py-2 text-right" x-text="(metricas?.variantes?.[v]?.respuestas ?? 0)"></td>
              <td class="px-2 py-2 text-right text-emerald-400" x-text="(metricas?.variantes?.[v]?.positivas ?? 0)"></td>
              <td class="px-2 py-2 text-right text-rose-400" x-text="(metricas?.variantes?.[v]?.negativas ?? 0)"></td>
              <td class="px-2 py-2 text-right" x-text="(metricas?.variantes?.[v]?.neutrales ?? 0)"></td>
              <td class="px-2 py-2 text-right text-amber-400" x-text="(metricas?.variantes?.[v]?.unsubscribe ?? 0)"></td>
              <td class="px-2 py-2 text-right" x-text="(metricas?.variantes?.[v]?.ooo ?? 0)"></td>
              <td class="px-2 py-2 text-right font-semibold" x-text="(metricas?.variantes?.[v]?.prr ?? 0) + '%'"></td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- BLOQUE 3: Clasificación IA -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="message-square" class="w-4 h-4 text-amber-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">Clasificación de Respuestas (IA)</h5>
      </div>
      <div class="flex flex-wrap gap-2 text-sm">
        <span class="bg-emerald-500/15 text-emerald-400 px-2.5 py-1 rounded-lg">POSITIVE <span class="font-bold" x-text="(metricas?.positive ?? 0)"></span></span>
        <span class="bg-rose-500/15 text-rose-400 px-2.5 py-1 rounded-lg">NEGATIVE <span class="font-bold" x-text="(metricas?.negative ?? 0)"></span></span>
        <span class="bg-slate-700 text-slate-300 px-2.5 py-1 rounded-lg">NEUTRAL <span class="font-bold" x-text="(metricas?.neutral ?? 0)"></span></span>
        <span class="bg-amber-500/15 text-amber-400 px-2.5 py-1 rounded-lg">UNSUBSCRIBE <span class="font-bold" x-text="(metricas?.unsubscribe ?? 0)"></span></span>
        <span class="bg-cyan-500/15 text-cyan-400 px-2.5 py-1 rounded-lg">OOO <span class="font-bold" x-text="(metricas?.ooo ?? 0)"></span></span>
        <span class="bg-slate-800 text-slate-400 px-2.5 py-1 rounded-lg">PENDING <span class="font-bold" x-text="(metricas?.pending ?? 0)"></span></span>
      </div>
    </div>

    <!-- BLOQUE 4: Asistente de Informes IA (diálogo con datos reales) -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3 flex-wrap">
        <i data-lucide="bot" class="w-4 h-4 text-violet-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">🤖 Asistente de Informes IA</h5>
        <span class="text-xs text-slate-500 ml-auto">Responde con los datos reales de la campaña · dialoga para corregirla</span>
      </div>

      <!-- Sugerencias de preguntas -->
      <div class="flex items-center gap-2 flex-wrap mb-3">
        <template x-for="s in ['Genera un informe completo de esta campaña','¿Cuál es el cuello de botella y cómo lo corrijo?','¿Qué plantillas funcionan mejor?','¿Qué leads requieren acción ahora?','¿Cómo mejoro la tasa de respuesta?']" :key="s">
          <button @click="chatEnviar(s)" class="px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-300 hover:text-slate-100 hover:border-violet-500/40 transition" x-text="s"></button>
        </template>
      </div>

      <!-- Chat -->
      <div x-ref="chatBox" class="space-y-3 max-h-80 overflow-y-auto bg-slate-950/40 border border-slate-800 rounded-lg p-3">
        <template x-for="(m, i) in chatMsgs" :key="i">
          <div class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
            <div class="max-w-[85%] rounded-lg px-3 py-2 border text-sm whitespace-pre-wrap"
                 :class="m.role === 'user' ? 'bg-violet-500/15 border-violet-500/30 text-slate-100' : 'bg-slate-800/70 border-slate-700 text-slate-200'"
                 x-text="m.content"></div>
          </div>
        </template>
        <div x-show="chatEnviando" class="flex justify-start">
          <div class="bg-slate-800/70 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-400">🤖 Analizando los datos…</div>
        </div>
        <div x-show="chatMsgs.length === 0 && !chatEnviando" class="text-center text-slate-500 text-sm py-6">
          Pregunta lo que necesites sobre la campaña (leads, plantillas, respuestas, embudo…). La IA lee los datos reales en cada pregunta.
        </div>
      </div>

      <!-- Input -->
      <div class="flex items-center gap-2 mt-3">
        <input type="text" x-model="chatInput" @keydown.enter="chatEnviar()" placeholder="Pregunta algo sobre la campaña…"
               class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
        <button @click="chatEnviar()" :disabled="chatEnviando || !chatInput.trim()"
                class="px-4 py-2 bg-violet-500/20 text-violet-400 border border-violet-500/30 rounded-lg text-sm font-bold hover:bg-violet-500/30 transition disabled:opacity-50 inline-flex items-center gap-1.5">
          <span x-show="!chatEnviando">Enviar</span>
          <span x-show="chatEnviando" class="w-3 h-3 border-2 border-violet-400 border-t-transparent rounded-full animate-spin inline-block"></span>
        </button>
      </div>
    </div>
    </div>
  </div>
</div>