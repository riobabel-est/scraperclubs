<!-- ═══════════════ RESPONSES TAB (FASE 4C) ═══════════════ -->
<div class="space-y-4">
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center gap-3 flex-wrap">
    <h5 class="text-base font-semibold text-slate-200">Respuestas</h5>
    <div class="flex items-center gap-2 ml-auto">
      <label class="text-xs text-slate-400">Filtro:</label>
      <select x-model="respuestasFiltro" @change="loadRespuestas()" class="bg-slate-800 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-200">
        <option value="">Todas</option>
        <option value="PENDING">PENDING</option>
        <option value="POSITIVE">POSITIVE</option>
        <option value="NEGATIVE">NEGATIVE</option>
        <option value="NEUTRAL">NEUTRAL</option>
        <option value="UNSUBSCRIBE">UNSUBSCRIBE</option>
        <option value="OOO">OOO</option>
      </select>
      <button @click="loadRespuestas()" class="px-3 py-1.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded text-xs font-semibold hover:bg-amber-500/30">Actualizar</button>
    </div>
  </div>

  <div class="overflow-x-auto rounded-xl border border-slate-800">
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-slate-900 text-slate-400 text-[10px] uppercase tracking-wider">
          <th class="px-3 py-2 text-left">Club</th>
          <th class="px-3 py-2 text-left">Email</th>
          <th class="px-3 py-2 text-center">Campaña</th>
          <th class="px-3 py-2 text-center">Variante</th>
          <th class="px-3 py-2 text-center">Clasificación</th>
          <th class="px-3 py-2 text-right">Acción</th>
        </tr>
      </thead>
      <tbody>
        <template x-for="r in respuestas" :key="r.id">
          <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">
            <td class="px-3 py-2 text-slate-300" x-text="r.club"></td>
            <td class="px-3 py-2 text-slate-400" x-text="r.email"></td>
            <td class="px-3 py-2 text-center text-slate-400" x-text="r.campaña_nombre || '—'"></td>
            <td class="px-3 py-2 text-center">
              <span class="px-1.5 py-0.5 rounded-full text-[9px] font-semibold"
                    :class="r.variant==='A' ? 'bg-amber-500/15 text-amber-400' : (r.variant==='B' ? 'bg-blue-500/15 text-blue-400' : 'bg-purple-500/15 text-purple-400')"
                    x-text="r.variant || '—'"></span>
            </td>
            <td class="px-3 py-2 text-center">
              <span class="px-1.5 py-0.5 rounded-full text-[9px] font-semibold"
                    :class="{PENDING:'bg-slate-700 text-slate-300',POSITIVE:'bg-emerald-500/15 text-emerald-400',NEGATIVE:'bg-rose-500/15 text-rose-400',NEUTRAL:'bg-slate-700 text-slate-300',UNSUBSCRIBE:'bg-amber-500/15 text-amber-400',OOO:'bg-cyan-500/15 text-cyan-400'}[r.clasificacion]"
                    x-text="r.clasificacion"></span>
            </td>
            <td class="px-3 py-2 text-right">
              <button @click="abrirRespuesta(r.id)" class="px-2 py-1 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-300 hover:text-slate-100">Ficha</button>
            </td>
          </tr>
        </template>
        <tr x-show="respuestas.length === 0">
          <td colspan="6" class="px-3 py-8 text-center text-slate-600">Sin respuestas registradas</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════ MODAL FICHA RESPUESTA ═══════════ -->
<div x-show="rsModal" @click.self="rsModal=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" x-cloak x-transition>
  <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[85vh] overflow-y-auto m-4">
    <div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between">
      <h5 class="text-sm font-bold text-slate-200">Respuesta</h5>
      <button @click="rsModal=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button>
    </div>
    <div class="p-5 space-y-4" x-show="rsRespuesta">
      <!-- Contexto del envío original -->
      <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
        <h6 class="text-xs font-bold text-slate-300 mb-2">Contexto del envío original</h6>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <div><span class="text-slate-500">Club:</span> <span class="text-slate-200" x-text="rsEnvio.club"></span></div>
          <div><span class="text-slate-500">Email:</span> <span class="text-slate-200" x-text="rsEnvio.email"></span></div>
          <div><span class="text-slate-500">Campaña:</span> <span class="text-slate-200" x-text="rsEnvio.campaña_nombre || '—'"></span></div>
          <div><span class="text-slate-500">Variante:</span> <span class="text-slate-200" x-text="rsEnvio.variant"></span></div>
          <div><span class="text-slate-500">Fecha envío:</span> <span class="text-slate-200" x-text="rsEnvio.fecha_envio"></span></div>
          <div><span class="text-slate-500">Asunto enviado:</span> <span class="text-slate-200" x-text="rsEnvio.asunto"></span></div>
        </div>
      </div>

      <!-- Respuesta recibida -->
      <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
        <h6 class="text-xs font-bold text-slate-300 mb-2">Respuesta recibida</h6>
        <div class="space-y-1 text-xs">
          <div><span class="text-slate-500">Remitente:</span> <span class="text-slate-200" x-text="rsRespuesta.remitente"></span></div>
          <div><span class="text-slate-500">Fecha:</span> <span class="text-slate-200" x-text="rsRespuesta.fecha_respuesta"></span></div>
          <div><span class="text-slate-500">Asunto respuesta:</span> <span class="text-slate-200" x-text="rsRespuesta.subject"></span></div>
          <div class="pt-1"><span class="text-slate-500">Cuerpo:</span>
            <div class="bg-white rounded p-2 text-slate-900 mt-1 whitespace-pre-wrap" x-text="rsRespuesta.cuerpo"></div>
          </div>
        </div>
      </div>

      <!-- Clasificación -->
      <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3">
        <h6 class="text-xs font-bold text-slate-300 mb-2">Clasificación</h6>
        <div class="flex flex-wrap gap-2">
          <template x-for="c in ['PENDING','POSITIVE','NEGATIVE','NEUTRAL','UNSUBSCRIBE','OOO']" :key="c">
            <button @click="clasificarRespuesta(rsRespuesta.id, c)"
              class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border"
              :class="rsRespuesta.clasificacion === c
                ? 'bg-amber-500/20 text-amber-400 border-amber-500/40'
                : 'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700'"
              x-text="c"></button>
          </template>
        </div>
        <p class="text-[10px] text-slate-500 mt-2">UNSUBSCRIBE activa la supresión del lead (Lista Negra). El envío histórico no se modifica.</p>
      </div>
    </div>
  </div>
</div>