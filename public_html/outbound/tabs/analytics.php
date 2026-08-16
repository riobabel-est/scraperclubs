<!-- ANALYTICS DEL PILOTO (FASE 5D) — fuente única get_piloto_metricas -->
<div x-data="pilotoAnalyticsApp()" x-init="loadCampanas().then(() => { if (campaignId) loadMetricas(); })" class="space-y-6">

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
          <div><span class="text-slate-500">Identificador:</span> <span class="text-slate-200" x-text="campaña.identificador || '—'"></span></div>
          <div><span class="text-slate-500">Estado:</span> <span class="text-slate-200" x-text="campaña.estado"></span></div>
          <div><span class="text-slate-500">Entorno:</span> <span class="text-slate-200" x-text="campaña.entorno"></span></div>
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
      <p class="text-[11px] text-slate-500 mt-2">PRR = POSITIVE / ACEPTADOS SMTP. No se declara variante ganadora.</p>
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
      <p class="text-[11px] text-slate-500 mt-2">Open se registra por píxel y puede verse afectado por privacidad, caché o bloqueo de imágenes. Open ≠ interés comercial.</p>
    </div>
    </template>
  </div>
</div>

<script>
function pilotoAnalyticsApp(){
  return {
    campaignId: '',
    campanas: [],
    metricas: null,
    campaña: null,
    coherente: true,

    async loadCampanas() {
      try {
        const r = await fetch('?action=get_piloto_campanas');
        const j = await r.json();
        if (j.ok) this.campanas = j.campanas || [];
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