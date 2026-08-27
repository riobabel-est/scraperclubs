<!-- ═══════════ ANALYTICS DE CAMPAÑA (consolidado) ═══════════ -->
<!-- Vista única: KPIs + Embudo + A/B/C + Clasificación IA. Reemplaza Piloto/Global. -->
<div x-data="analyticsCampana()" x-init="init()" class="space-y-6">

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
    <!-- BLOQUE 1: KPIs + Embudo -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="activity" class="w-4 h-4 text-amber-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">Rendimiento de Campaña</h5>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Total Envíos</div>
          <div class="text-xl font-bold text-slate-100 mt-0.5" x-text="totalEnvios"></div>
        </div>
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Entregados</div>
          <div class="text-xl font-bold text-slate-100 mt-0.5" x-text="(metricas?.aceptados ?? 0)"></div>
        </div>
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Aperturas</div>
          <div class="text-xl font-bold text-cyan-400 mt-0.5" x-text="openRate + '%'"></div>
        </div>
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Respuestas</div>
          <div class="text-xl font-bold text-slate-100 mt-0.5" x-text="replyRate + '%'"></div>
        </div>
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Resp. Positivas</div>
          <div class="text-xl font-bold text-emerald-400 mt-0.5" x-text="prr + '%'"></div>
        </div>
        <div class="bg-slate-800/40 border border-slate-700/60 rounded-lg p-3">
          <div class="text-xs text-slate-400 uppercase tracking-wider">Cuello de Botella</div>
          <div class="text-sm font-bold text-rose-400 mt-0.5 leading-tight" x-show="cuello"
            x-text="(cuello?.origen || '') + ' → ' + (cuello?.destino || '') + ' (' + (cuello?.pct ?? 0) + '%)'"></div>
          <div class="text-xs text-slate-500 mt-0.5" x-show="!cuello">Sin datos</div>
        </div>
      </div>

      <!-- Embudo compacto -->
      <div class="mt-4">
        <div class="flex items-center gap-2 mb-2">
          <i data-lucide="trending-down" class="w-4 h-4 text-amber-400"></i>
          <span class="text-xs uppercase tracking-wider text-slate-300 font-semibold">Embudo de Conversión</span>
          <span class="text-xs text-slate-500 ml-auto">% conversión entre etapas</span>
        </div>
        <div class="space-y-1">
          <template x-for="f in funnel" :key="f.nivel">
            <div class="flex items-center gap-2">
              <div class="w-36 text-xs text-slate-400 truncate" x-text="f.nivel.replace(/^\d+\.\s*/, '')"></div>
              <div class="flex-1 bg-slate-800 rounded h-4 overflow-hidden">
                <div class="h-full bg-amber-500/70" :style="'width:' + Math.max(2, Math.round(f.cnt / funnelMax * 100)) + '%'"></div>
              </div>
              <div class="w-10 text-xs text-right text-slate-300 font-semibold" x-text="f.cnt"></div>
              <div class="w-14 text-xs text-right" :class="f.pct !== null && f.pct !== undefined ? (f.pct >= 50 ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-500'"
                x-text="f.pct !== null && f.pct !== undefined ? f.pct + '%' : '—'"></div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- BLOQUE 2: A/B/C -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4 overflow-x-auto">
      <div class="flex items-center gap-2 mb-3 flex-wrap">
        <i data-lucide="git-compare" class="w-4 h-4 text-amber-400"></i>
        <h5 class="text-sm font-semibold uppercase tracking-wider text-slate-200">Desglose A/B/C</h5>
        <span x-show="varianteGanadora" class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">🏆 Ganadora: <span x-text="varianteGanadora"></span></span>
      </div>
      <table class="w-full text-sm">
        <thead><tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
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
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4">
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
  </div>
</div>