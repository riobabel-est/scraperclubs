<!-- ANALYTICS DEL PILOTO (FASE 5D) — fuente única get_piloto_metricas -->
<div x-data="pilotoAnalyticsApp()" x-init="loadCampanas().then(() => { if (campaignId) loadMetricas(); })" class="space-y-6">

  <!-- Selector de vista -->
  <div class="flex gap-1 bg-slate-900 border border-slate-800 rounded-xl p-1.5 w-fit">
    <button @click="vista = 'piloto'" type="button"
      class="px-4 py-2 text-sm font-semibold rounded-lg transition"
      :class="vista === 'piloto' ? 'bg-amber-500/20 text-amber-400' : 'text-slate-400 hover:text-slate-200'">Piloto A/B/C</button>
    <button @click="vista = 'global'" type="button"
      class="px-4 py-2 text-sm font-semibold rounded-lg transition"
      :class="vista === 'global' ? 'bg-amber-500/20 text-amber-400' : 'text-slate-400 hover:text-slate-200'">Efectividad Global</button>
  </div>

  <!-- ═══════════ PANEL PILOTO (por campaña, variantes A/B/C) ═══════════ -->
  <div x-show="vista === 'piloto'">

  <!-- Selector de campaña -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap gap-3 items-center">
    <label class="text-xs text-slate-400 font-semibold">Campaña</label>
    <select x-model="campaignId" @change="loadMetricas()" class="bg-slate-800 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-200">
      <option value="">— Seleccionar campaña —</option>
      <template x-for="c in campanas" :key="c.id">
        <option :value="c.id" x-text="(c.identificador || c.nombre || ('Campaña ' + c.id))"></option>
      </template>
    </select>
    <button @click="loadMetricas()" class="px-3 py-1.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded text-xs font-semibold hover:bg-amber-500/30">Actualizar</button>
  </div>

  <!-- Sin campaña seleccionada -->
  <div x-show="!campaignId" class="text-slate-400 text-sm py-10 text-center">
    NO HAY CAMPAÑA SELECCIONADA
  </div>

  <!-- Contenido -->
  <div x-show="campaignId" x-cloak>
    <!-- Contexto de campaña -->
    <template x-if="campaña">
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-4">
        <h5 class="text-sm font-semibold text-slate-200 mb-2">Campaña</h5>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
          <div><span class="text-slate-400">Identificador:</span> <span class="text-slate-200" x-text="campaña.identificador || '—'"></span></div>
          <div><span class="text-slate-400">Estado:</span> <span class="text-slate-200" x-text="campaña.estado"></span></div>
          <div><span class="text-slate-400">Entorno:</span> <span class="text-slate-200" x-text="campaña.entorno"></span></div>
          <div>
            <span x-show="!coherente" class="text-amber-400 text-[11px]">⚠ entorno incoherente para piloto</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Resumen global -->
    <template x-if="metricas">
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Aceptados SMTP</div>
        <div class="text-xl font-semibold text-slate-200" x-text="metricas.aceptados"></div>
      </div>
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Aperturas únicas</div>
        <div class="text-xl font-semibold text-cyan-400" x-text="metricas.abiertos_unicos"></div>
      </div>
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Respuestas</div>
        <div class="text-xl font-semibold text-slate-200" x-text="metricas.respuestas"></div>
      </div>
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Positive Reply Rate</div>
        <div class="text-xl font-semibold text-emerald-400" x-text="prrGlobal + '%'"></div>
      </div>
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Open Rate</div>
        <div class="text-xl font-semibold text-cyan-400" x-text="openRateGlobal + '%'"></div>
      </div>
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-3">
        <div class="text-xs text-slate-400">Reply Rate</div>
        <div class="text-xl font-semibold text-slate-200" x-text="replyRateGlobal + '%'"></div>
      </div>
    </div>
    </template>

    <!-- Comparativa A/B/C -->
    <template x-if="metricas && metricas.variantes">
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4 overflow-x-auto">
      <h5 class="text-sm font-semibold text-slate-200 mb-3">Desglose A/B/C</h5>
      <table class="w-full text-xs">
        <thead>
          <tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
            <th class="px-2 py-1.5 text-left">Variante</th>
            <th class="px-2 py-1.5 text-right">Aceptados</th>
            <th class="px-2 py-1.5 text-right">Aperturas</th>
            <th class="px-2 py-1.5 text-right">Respuestas</th>
            <th class="px-2 py-1.5 text-right">Positivas</th>
            <th class="px-2 py-1.5 text-right">Negativas</th>
            <th class="px-2 py-1.5 text-right">Neutrales</th>
            <th class="px-2 py-1.5 text-right">UNSUB</th>
            <th class="px-2 py-1.5 text-right">OOO</th>
            <th class="px-2 py-1.5 text-right">PRR</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="v in ['A','B','C']" :key="v">
            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
              <td class="px-2 py-1.5 font-semibold" x-text="v"></td>
              <td class="px-2 py-1.5 text-right" x-text="metricas.variantes[v].aceptados"></td>
              <td class="px-2 py-1.5 text-right text-cyan-400" x-text="metricas.variantes[v].aperturas"></td>
              <td class="px-2 py-1.5 text-right" x-text="metricas.variantes[v].respuestas"></td>
              <td class="px-2 py-1.5 text-right text-emerald-400" x-text="metricas.variantes[v].positivas"></td>
              <td class="px-2 py-1.5 text-right text-rose-400" x-text="metricas.variantes[v].negativas"></td>
              <td class="px-2 py-1.5 text-right" x-text="metricas.variantes[v].neutrales"></td>
              <td class="px-2 py-1.5 text-right text-amber-400" x-text="metricas.variantes[v].unsubscribe"></td>
              <td class="px-2 py-1.5 text-right" x-text="metricas.variantes[v].ooo"></td>
              <td class="px-2 py-1.5 text-right font-semibold"
                  x-text="(metricas.variantes[v].positivas) + ' / ' + (metricas.variantes[v].aceptados) + ' = ' + metricas.variantes[v].prr + '%'"></td>
            </tr>
          </template>
        </tbody>
      </table>
      <p class="text-[11px] text-slate-400 mt-2">PRR = POSITIVE / ACEPTADOS SMTP. No se declara variante ganadora.</p>
    </div>
    </template>

    <!-- Clasificación -->
    <template x-if="metricas">
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4">
      <h5 class="text-sm font-semibold text-slate-200 mb-2">Clasificación de respuestas</h5>
      <div class="flex flex-wrap gap-2 text-xs">
        <span class="bg-emerald-500/15 text-emerald-400 px-2 py-1 rounded">POSITIVE <span x-text="metricas.positive"></span></span>
        <span class="bg-rose-500/15 text-rose-400 px-2 py-1 rounded">NEGATIVE <span x-text="metricas.negative"></span></span>
        <span class="bg-slate-700 text-slate-300 px-2 py-1 rounded">NEUTRAL <span x-text="metricas.neutral"></span></span>
        <span class="bg-amber-500/15 text-amber-400 px-2 py-1 rounded">UNSUBSCRIBE <span x-text="metricas.unsubscribe"></span></span>
        <span class="bg-cyan-500/15 text-cyan-400 px-2 py-1 rounded">OOO <span x-text="metricas.ooo"></span></span>
        <span class="bg-slate-800 text-slate-400 px-2 py-1 rounded">PENDING <span x-text="metricas.pending"></span></span>
      </div>
    </div>
    </template>

    <!-- Observación -->
    <template x-if="metricas">
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4 text-xs text-slate-400">
      <div x-show="mayorPrr">Mayor PRR observado actualmente: <span class="text-slate-200 font-semibold" x-text="mayorPrr"></span></div>
      <div x-show="!mayorPrr && metricas.aceptados < 1">OBSERVACIÓN INSUFICIENTE</div>
      <p class="text-[11px] text-slate-400 mt-2">Open se registra por píxel y puede verse afectado por privacidad, caché o bloqueo de imágenes. Open ≠ interés comercial.</p>
    </div>
    </template>
  </div>
  </div>

  <!-- ═══════════ PANEL EFECTIVIDAD GLOBAL (dashboard completo) ═══════════ -->
  <div x-show="vista === 'global'">
    <div x-data="analyticsApp()" x-init="load()">

      <!-- Filtros globales -->
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label class="block text-sm font-semibold text-slate-300 mb-1.5">Pipeline</label>
          <select x-model="fPipeline" @change="load()"
            class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
            <option value="">Todos</option>
            <template x-for="p in pipelines" :key="p.id"><option :value="p.id" x-text="p.nombre"></option></template>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-300 mb-1.5">Variante</label>
          <select x-model="fVariante" @change="load()"
            class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
            <option value="">A + B + C</option>
            <option value="A">A</option><option value="B">B</option><option value="C">C</option>
          </select>
        </div>
        <label class="flex items-center gap-2 pb-2 cursor-pointer">
          <input type="checkbox" x-model="fExcluirTest" @change="load()" class="accent-amber-500">
          <span class="text-sm text-slate-300">Excluir TEST</span>
        </label>
        <button @click="load()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1.5">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i> Actualizar
        </button>
      </div>

      <!-- KPIs económicos por 100 contactos -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
        <template x-for="(c, i) in kpiCards" :key="i">
          <div class="bg-slate-900 border border-slate-800 rounded-xl p-4" :class="c.border">
            <div class="text-xs text-slate-400 uppercase tracking-wider" x-text="c.label"></div>
            <div class="text-2xl font-bold mt-1" :class="c.color" x-text="c.value"></div>
            <div class="text-xs text-slate-400 mt-1" x-text="c.sub"></div>
          </div>
        </template>
      </div>

      <!-- Embudo 12 niveles -->
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4">
        <div class="flex items-center gap-2 mb-3">
          <i data-lucide="trending-down" class="w-4 h-4 text-amber-400"></i>
          <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Embudo de Conversión</h5>
          <span class="text-xs text-slate-400 ml-auto">12 niveles · % conversión</span>
        </div>
        <div class="space-y-1.5">
          <template x-for="f in funnel" :key="f.nivel">
            <div class="flex items-center gap-2 cursor-pointer hover:bg-slate-800/40 rounded px-1" @click="irAEstado(f.nivel)" :title="'Ver leads: ' + f.nivel">
              <div class="w-40 text-xs text-slate-300 truncate" x-text="f.nivel"></div>
              <div class="flex-1 bg-slate-800 rounded h-5 overflow-hidden">
                <div class="h-full bg-amber-500/80" :style="'width:' + Math.max(2, Math.round(f.cnt / funnelMax * 100)) + '%'"></div>
              </div>
              <div class="w-12 text-xs text-right text-slate-300 font-semibold" x-text="f.cnt"></div>
              <div class="w-16 text-xs text-right" :class="f.pct !== undefined && f.pct !== null ? (f.pct >= 50 ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-500'"
                x-text="f.pct !== undefined && f.pct !== null ? f.pct + '%' : '—'"></div>
            </div>
          </template>
        </div>
        <template x-if="cuelloPrincipal">
          <div class="mt-3 p-3 bg-rose-500/10 border border-rose-500/30 rounded-lg text-sm text-rose-300">
            ⚠️ Cuello de botella: <span class="font-semibold" x-text="cuelloPrincipal.origen + ' → ' + cuelloPrincipal.destino"></span>
            (solo el <span class="font-semibold" x-text="cuelloPrincipal.pct"></span>% pasa) — aquí invertir esfuerzo
          </div>
        </template>
      </div>


      <!-- Objetivo de cierre -->
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4">
        <div class="flex items-center gap-2 mb-3">
          <i data-lucide="target" class="w-4 h-4 text-amber-400"></i>
          <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Objetivo de cierre</h5>
          <span class="text-xs text-slate-400 ml-auto" x-text="obj.ganados + ' / ' + obj.objetivo + ' clubes'"></span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          <div><span class="text-slate-400">Ganados:</span> <span class="font-semibold text-emerald-400" x-text="obj.ganados"></span></div>
          <div><span class="text-slate-400">Progreso:</span> <span class="font-semibold" x-text="obj.pct + '%'"></span></div>
          <div><span class="text-slate-400">Restantes:</span> <span class="font-semibold" x-text="obj.restantes"></span></div>
          <div><span class="text-slate-400">Tasa cierre:</span> <span class="font-semibold" x-text="obj.tasa_cierre + '%'"></span></div>
          <div><span class="text-slate-400">Contactos necesarios:</span> <span class="font-semibold" x-text="obj.contactos_necesarios"></span></div>
          <div><span class="text-slate-400">Facturación:</span> <span class="font-semibold text-blue-400" x-text="obj.facturacion ? obj.facturacion.toLocaleString('es-ES') + '€' : '0€'"></span></div>
          <div><span class="text-slate-400">Pares:</span> <span class="font-semibold" x-text="obj.pares"></span></div>
          <div><span class="text-slate-400">Margen:</span> <span class="font-semibold text-purple-400" x-text="obj.margen ? obj.margen.toLocaleString('es-ES') + '€' : '0€'"></span></div>
        </div>
      </div>

      <!-- Comparativa A/B/C ampliada -->
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4 overflow-x-auto">
        <div class="flex items-center gap-2 mb-3">
          <i data-lucide="git-compare" class="w-4 h-4 text-amber-400"></i>
          <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Comparativa A/B/C ampliada</h5>
          <span class="text-xs text-slate-400 ml-auto" x-show="abcGanadora" x-text="'Ganadora actual: ' + abcGanadora"></span>
        </div>
        <template x-if="abcFilas.length > 0">
          <table class="w-full text-sm">
            <thead><tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
              <th class="px-2 py-1.5 text-left">Métrica</th><th class="px-2 py-1.5 text-right">A</th><th class="px-2 py-1.5 text-right">B</th><th class="px-2 py-1.5 text-right">C</th>
            </tr></thead>
            <tbody>
              <template x-for="(row, ri) in abcFilas" :key="ri">
                <tr class="border-b border-slate-800/50">
                  <td class="px-2 py-1.5 text-slate-300" x-text="row.label"></td>
                  <td class="px-2 py-1.5 text-right" :class="row.bestIndex === 0 ? 'text-emerald-400 font-semibold bg-emerald-500/5' : 'text-slate-300'" x-text="row.a"></td>
                  <td class="px-2 py-1.5 text-right" :class="row.bestIndex === 1 ? 'text-emerald-400 font-semibold bg-emerald-500/5' : 'text-slate-300'" x-text="row.b"></td>
                  <td class="px-2 py-1.5 text-right" :class="row.bestIndex === 2 ? 'text-emerald-400 font-semibold bg-emerald-500/5' : 'text-slate-300'" x-text="row.c"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </template>
        <template x-if="abcFilas.length === 0"><div class="text-sm text-slate-400 py-6 text-center">Sin datos de variantes A/B/C.</div></template>
      </div>
    </div>
  </div>
</div>

<script>
function pilotoAnalyticsApp(){
  return {
    campaignId: '',
    vista: 'piloto',
    campanas: [],
    metricas: null,
    campaña: null,
    coherente: true,

    async loadCampanas() {
      try {
        const r = await fetch('?action=get_piloto_campanas');
        const j = await r.json();
        if (j.ok) this.campanas = j.campanas || [];
        // Contexto global: preselecciona la campaña activa del panel.
        if (window.app && window.app.campanaActual > 0) {
          const c = (this.campanas || []).find(x => x.id == window.app.campanaActual);
          if (c) this.campaignId = c.id;
        }
      } catch(e) { console.error('loadCampanas:', e); }
    },

    async loadMetricas() {
      if (!this.campaignId) { this.metricas = null; this.campaña = null; return; }
      try {
        const r = await fetch('?action=get_piloto_metricas&campaign_id=' + encodeURIComponent(this.campaignId));
        const j = await r.json();
        if (j && j.ok !== undefined) {
          this.metricas = j.ok ? j : null;
          this.campaña = j.campaña || null;
          this.coherente = !(!this.campaña || (this.campaña.entorno === 'test' && this.campaña.estado === 'ACTIVE'));
        } else {
          this.metricas = j;
          this.campaña = j.campaña || null;
        }
      } catch(e) { console.error('loadMetricas:', e); }
    },

    get prrGlobal() {
      if (!this.metricas) return 0;
      return this.metricas.aceptados > 0 ? Math.round(this.metricas.positive / this.metricas.aceptados * 1000) / 10 : 0;
    },
    get openRateGlobal() {
      if (!this.metricas) return 0;
      return this.metricas.aceptados > 0 ? Math.round(this.metricas.abiertos_unicos / this.metricas.aceptados * 1000) / 10 : 0;
    },
    get replyRateGlobal() {
      if (!this.metricas) return 0;
      return this.metricas.aceptados > 0 ? Math.round(this.metricas.respuestas / this.metricas.aceptados * 1000) / 10 : 0;
    },
    get mayorPrr() {
      if (!this.metricas || !this.metricas.variantes) return '';
      let best = ''; let max = -1;
      ['A','B','C'].forEach(v => {
        const prr = this.metricas.variantes[v].prr;
        if (prr > max) { max = prr; best = v; }
      });
      return max > 0 ? best : '';
    }
  };
}
</script>