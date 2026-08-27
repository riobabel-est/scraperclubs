<!-- ═══════════ SEGUIMIENTO — Operativa de leads (sin métricas ejecutivas) ═══════════ -->
<div x-data="seguimientoApp()" x-init="load()" class="space-y-4">

  <!-- Barra de herramientas y filtros (sticky) -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 sticky top-[70px] z-10 flex flex-wrap items-end gap-3 shadow-lg">
    <div class="min-w-[200px] flex-1">
      <label class="block text-sm font-semibold text-slate-300 mb-1">Buscar club o email</label>
      <input type="text" x-model="f.busqueda" @input.debounce.300ms="pagina=1;load()" placeholder="Buscar..."
        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
    </div>
    <div>
      <label class="block text-sm font-semibold text-slate-300 mb-1">Federación</label>
      <select x-model="f.federacion" @change="pagina=1;load()"
        class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        <option value="">Todas</option>
        <template x-for="fed in federaciones" :key="fed"><option :value="fed" x-text="fed"></option></template>
      </select>
    </div>
    <div>
      <label class="block text-sm font-semibold text-slate-300 mb-1">Estado</label>
      <select x-model="filtroEstado" @change="pagina=1"
        class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        <option value="">Todos</option>
        <template x-for="est in estadosPipeline" :key="est"><option :value="est" x-text="est"></option></template>
      </select>
    </div>
    <div>
      <label class="block text-sm font-semibold text-slate-300 mb-1">Variante</label>
      <select x-model="filtroVariante" @change="pagina=1"
        class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        <option value="">Todas</option>
        <option value="A">A</option>
        <option value="B">B</option>
        <option value="C">C</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-semibold text-slate-300 mb-1">Interés</label>
      <select x-model="filtroInteres" @change="pagina=1"
        class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        <option value="">Todos</option>
        <option value="General">General / Producto</option>
        <option value="Identidad">Identidad / Cantera</option>
        <option value="Financiero">Financiero / Rentabilidad</option>
      </select>
    </div>
    <button @click="pagina=1;load()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1.5">
      <i data-lucide="refresh-cw" class="w-4 h-4"></i> Refrescar
    </button>
  </div>


  <!-- Cola de trabajo (lista ÚNICA con select de vista por tipo) -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
    <div class="flex items-center gap-2 px-4 py-2.5 border-b border-slate-800 flex-wrap">
      <i data-lucide="list-filter" class="w-4 h-4 text-amber-400"></i>
      <span class="text-sm font-semibold uppercase tracking-wider text-slate-300">Vista</span>
      <select x-model="cola" @change="pagina=1" class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
        <option value="todos">Todos</option>
        <option value="calentar">🌱 Calentar (1er toque)</option>
        <option value="secuencia">📋 Secuencia pendiente</option>
        <option value="calientes">🔥 Calientes sin responder (≥3 apert.)</option>
        <option value="perseguir">🎯 Perseguir (2º toque)</option>
        <option value="cerrar">🤝 Cerrar</option>
        <option value="mockup">🎨 Mockup</option>
        <option value="proforma">🧾 Proforma</option>
        <option value="pausar">⏸️ Pausar</option>
        <option value="descartar">🗑️ Descartar</option>
      </select>
      <span class="text-xs text-slate-400 flex items-center gap-3 ml-auto flex-wrap">
        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-rose-400"></span> Urgente</span>
        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Hoy</span>
        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> OK</span>
        <span x-text="colaTotal + ' leads'"></span>
      </span>
    </div>

    <!-- Barra de acciones en lote (selección múltiple) — complementa el flujo lead a lead -->
    <div x-show="seleccion.length > 0" x-cloak class="px-3 py-2 bg-slate-800/50 border-t border-slate-700/50 flex flex-wrap items-center gap-2">
      <span class="text-xs font-semibold text-slate-200" x-text="seleccion.length + ' seleccionado(s)'"></span>
      <button @click="enviarLanzaderaLote()" class="px-3 py-1.5 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition flex items-center gap-1">
        <i data-lucide="send" class="w-3.5 h-3.5"></i> Enviar a Lanzadera
      </button>
      <button @click="programarAccionLote()" class="px-3 py-1.5 bg-cyan-500/15 text-cyan-400 border border-cyan-500/30 rounded-lg text-xs font-semibold hover:bg-cyan-500/25 transition flex items-center gap-1">
        <i data-lucide="calendar-clock" class="w-3.5 h-3.5"></i> Programar próxima acción
      </button>
      <button @click="limpiarSeleccion()" class="px-3 py-1.5 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:text-slate-100 transition ml-auto flex items-center gap-1">
        <i data-lucide="x" class="w-3.5 h-3.5"></i> Quitar selección
      </button>
    </div>

    <!-- Tabla operativa unificada (densificada) -->
    <div class="p-2 overflow-x-auto min-h-[240px]">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800 text-xs">
            <th class="px-2 py-2 text-left w-8"><input type="checkbox" :checked="seleccionTodos" @change="toggleSeleccionTodos()" class="w-4 h-4 accent-amber-500 rounded" title="Seleccionar todos los visibles"></th>
            <th class="px-2 py-2 text-left"><button @click="ordenar('sem')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='sem' ? 'text-amber-400' : ''">Semáforo <span x-show="sortKey==='sem'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-left"><button @click="ordenar('tipo')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='tipo' ? 'text-amber-400' : ''">Acción <span x-show="sortKey==='tipo'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-left"><button @click="ordenar('nombre')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='nombre' ? 'text-amber-400' : ''">Club / Email <span x-show="sortKey==='nombre'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-left hidden md:table-cell"><button @click="ordenar('envio')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='envio' ? 'text-amber-400' : ''">Última acción <span x-show="sortKey==='envio'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-right"><button @click="ordenar('dias')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='dias' ? 'text-amber-400' : ''">Días <span x-show="sortKey==='dias'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-right hidden md:table-cell"><button @click="ordenar('envios')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='envios' ? 'text-amber-400' : ''">Envíos <span x-show="sortKey==='envios'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-right hidden md:table-cell"><button @click="ordenar('aperturas')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='aperturas' ? 'text-amber-400' : ''">Apert. <span x-show="sortKey==='aperturas'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-left hidden lg:table-cell"><button @click="ordenar('temperatura')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='temperatura' ? 'text-amber-400' : ''">Temp. <span x-show="sortKey==='temperatura'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-left hidden lg:table-cell"><button @click="ordenar('estado')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='estado' ? 'text-amber-400' : ''">Estado <span x-show="sortKey==='estado'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 hidden lg:table-cell"><button @click="ordenar('variante')" class="inline-flex items-center gap-1 hover:text-amber-400 transition" :class="sortKey==='variante' ? 'text-amber-400' : ''">Variante / Interés <span x-show="sortKey==='variante'" x-text="sortDir==='asc' ? '↑' : '↓'"></span></button></th>
            <th class="px-2 py-2 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="l in colaPaginada" :key="l.id">
            <tr class="border-b border-slate-800/40 hover:bg-slate-800/30">
              <td class="px-2 py-1.5 w-8"><input type="checkbox" :checked="seleccion.includes(l.id)" @change="toggleSeleccion(l.id)" class="w-4 h-4 accent-amber-500 rounded"></td>
              <td class="px-2 py-1.5">
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                  <span class="inline-block w-2.5 h-2.5 rounded-full shrink-0" :class="semDot(l.sem)" :title="'Urgencia: ' + l.sem_label + '. ' + (l.motivo || '')"></span>
                  <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold" :class="semClase(l.sem)" x-text="l.sem_label" :title="'Urgencia: ' + l.sem_label + '. ' + (l.motivo || '')"></span>
                </span>
              </td>
              <td class="px-2 py-1.5">
                <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="tipoClase(l.tipo)" x-text="tipoLabel(l.tipo)"></span>
                <div class="text-xs text-slate-500 mt-0.5 max-w-[170px]" x-text="l.motivo || ''"></div>
              </td>
              <td class="px-2 py-1.5">
                <div class="text-slate-200 font-semibold text-sm truncate max-w-[240px]" x-text="l.nombre_club"></div>
                <div class="text-xs text-slate-400 truncate max-w-[240px]" x-text="l.email"></div>
              </td>
              <td class="px-2 py-1.5 hidden md:table-cell">
                <div class="text-xs text-slate-300" x-text="(l.ultimo_envio || l.ultimo_contacto || '').slice(0,10)"></div>
              </td>
              <td class="px-2 py-1.5 text-right">
                <span class="px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                  :class="(l.dias_desde_envio ?? l.dias_desde_contacto ?? l.dias_desde_creado ?? 0) > 7 ? 'bg-rose-500/15 text-rose-400' : 'bg-slate-800 text-slate-400'"
                  x-text="(l.dias_desde_envio ?? l.dias_desde_contacto ?? l.dias_desde_creado) ?? '—'"></span>
              </td>
              <td class="px-2 py-1.5 text-right text-xs text-slate-400 hidden md:table-cell" x-text="l.num_envios || '—'"></td>
              <td class="px-2 py-1.5 text-right hidden md:table-cell">
                <span class="inline-flex items-center gap-1 justify-end">
                  <span class="inline-block w-2 h-2 rounded-full" :class="(l.num_aperturas || 0) > 0 ? 'bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.6)]' : 'bg-slate-600'"></span>
                  <span class="text-xs font-semibold" :class="(l.num_aperturas || 0) >= 2 ? 'text-emerald-400' : (l.num_aperturas > 0 ? 'text-cyan-400' : 'text-slate-500')" x-text="l.num_aperturas || 0"></span>
                </span>
              </td>
              <td class="px-2 py-1.5 hidden lg:table-cell">
                <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap" :class="b2bClase(l.estado_b2b)" x-text="b2bLabel(l.estado_b2b)"></span>
                <span class="text-[11px] text-slate-500 ml-1" x-text="l.score_temp ?? ''"></span>
              </td>
              <td class="px-2 py-1.5 hidden lg:table-cell">
                <span class="px-1.5 py-0.5 rounded-full text-xs font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/30" x-text="l.estado_lead"></span>
              </td>
              <td class="px-2 py-1.5 hidden lg:table-cell">
                <div class="flex items-center gap-1.5">
                  <span class="px-1.5 py-0.5 rounded text-xs font-semibold" :class="l.variante ? 'bg-purple-500/15 text-purple-400 border border-purple-500/30' : 'bg-slate-800 text-slate-500'" x-text="l.variante || '—'"></span>
                  <span class="text-xs text-slate-400 truncate max-w-[130px]" x-text="l.interes_etiqueta || ''" :title="l.interes_etiqueta || ''"></span>
                </div>
              </td>
              <td class="px-2 py-1.5 text-right whitespace-nowrap">
                <template x-if="l.tipo === 'secuencia'">
                  <button @click="enviarSugerenciaSecuencia(l)" class="px-2 py-1 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition mr-1" title="Aprobar y abrir borrador de la secuencia">📨 Enviar</button>
                  <button @click="rechazarPropuesta({id: l.propuesta_id})" class="px-2 py-1 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-semibold hover:bg-rose-500/25 transition mr-1" title="Descartar la sugerencia">🗑️ Descartar</button>
                </template>
                <button @click="abrirAtencion(l)" class="px-2 py-1 bg-violet-500/15 text-violet-400 border border-violet-500/30 rounded-lg text-xs font-semibold hover:bg-violet-500/25 transition mr-1">🎯 Atender</button>
                <button @click="openFicha(l.id)" class="px-2 py-1 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs font-semibold hover:text-slate-100 transition">Ficha</button>
              </td>
            </tr>
          </template>
          <tr x-show="colaPaginada.length === 0"><td colspan="12" class="px-2 py-10 text-center text-slate-400">No hay leads con los filtros actuales.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div class="flex items-center justify-between px-3 py-2 border-t border-slate-800 flex-wrap gap-2">
      <span class="text-xs text-slate-400" x-text="'Mostrando ' + inicioPaginado + '–' + finPaginado + ' de ' + colaTotal"></span>
      <div class="flex gap-1.5">
        <button @click="pagina > 1 && (pagina--)" class="px-2.5 py-1 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs hover:text-slate-100 transition" :disabled="pagina <= 1">‹ Anterior</button>
        <span class="px-2 py-1 text-xs text-slate-400" x-text="'Pág ' + pagina + ' / ' + totalPaginas"></span>
        <button @click="pagina < totalPaginas && (pagina++)" class="px-2.5 py-1 bg-slate-800 text-slate-300 border border-slate-700 rounded-lg text-xs hover:text-slate-100 transition" :disabled="pagina >= totalPaginas">Siguiente ›</button>
      </div>
    </div>
  </div>

  <p x-show="cargando" class="text-sm text-slate-400 text-center py-4">Cargando seguimiento…</p>
  <p x-show="!cargando && error" class="text-sm text-rose-400 text-center py-4" x-text="error"></p>

  <!-- ═══════════ MODAL DE ATENCIÓN A MEDIDA (Asistente IA v2) ═══════════ -->
  <div x-show="modalAtencion" x-cloak @click.self="modalAtencion = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">
      <div class="flex items-start gap-3 px-4 py-3 border-b border-slate-800 flex-wrap">
        <div class="flex-1 min-w-[220px]">
          <div class="text-base font-semibold text-slate-100" x-text="(charla && charla.lead ? charla.lead.nombre_club : 'Cargando…')"></div>
          <div class="text-sm text-slate-400" x-text="charla && charla.lead ? (charla.lead.federacion || '') + ' · ' + (charla.lead.email || '') : ''"></div>
          <div class="text-xs text-slate-400 mt-0.5" x-show="charla && charla.contacto_real" x-text="'Contacto: ' + charla.contacto_real"></div>
          <div class="text-xs text-amber-400 mt-0.5" x-show="charla && charla.ok && !charla.contacto_real">⚠ Email genérico — la IA no inventará nombre</div>
        </div>
        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-500/15 text-blue-400 border border-blue-500/30" x-text="charla && charla.lead ? charla.lead.estado_lead : ''"></span>
        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/30" x-show="charla && charla.lead && charla.lead.volumen_estimado" x-text="'Volumen: ' + charla.lead.volumen_estimado"></span>
        <button @click="modalAtencion = false" class="px-2.5 py-1 bg-slate-800 text-slate-400 border border-slate-700 rounded-lg text-sm hover:text-slate-100 transition">✕</button>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 flex-1 min-h-0">
        <!-- IZQUIERDA: LA CHARLA -->
        <div class="border-b lg:border-b-0 lg:border-r border-slate-800 flex flex-col min-h-0">
          <div class="px-4 py-2 border-b border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-300">💬 Charla con el club</div>
          <div class="p-3 space-y-2 overflow-y-auto flex-1 min-h-0 max-h-[280px] lg:max-h-[60vh] text-sm">
            <template x-for="e in (charla ? charla.envios : [])" :key="'e'+e.id">
              <div class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-2.5">
                <div class="text-xs text-slate-400">📤 <span x-text="(e.fecha_envio || '').slice(0,16)"></span> · variante <span x-text="e.variant || 'A'"></span> · <span x-text="e.cuenta_emision"></span></div>
                <div class="text-slate-200 text-xs font-semibold mt-1" x-text="e.asunto"></div>
                <div class="text-slate-300 text-xs mt-1 whitespace-pre-wrap" x-show="e.cuerpo_charla && e.cuerpo_charla.trim()" x-text="e.cuerpo_charla"></div>
                <div class="text-slate-500 italic text-xs mt-1" x-show="!e.cuerpo_charla || !e.cuerpo_charla.trim()">(correo sin cuerpo extraíble)</div>
              </div>
            </template>
            <div x-show="charla && (charla.aperturas_total || 0) > 0" class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-2.5 text-xs">
              👁 <span class="text-emerald-400 font-semibold" x-text="charla.aperturas_total + ' aperturas'"></span>
              <span class="text-slate-400" x-text="'· primera: ' + (charla.primera_apertura || '')"></span>
            </div>
            <template x-for="r in (charla ? charla.respuestas : [])" :key="'r'+r.id">
              <div class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-2.5">
                <div class="text-xs text-slate-400">📥 <span x-text="(r.fecha_respuesta || '').slice(0,16)"></span> · <span x-text="r.remitente"></span></div>
                <div class="text-slate-300 text-xs mt-1 whitespace-pre-wrap" x-text="r.cuerpo"></div>
                <div class="text-xs text-violet-400 mt-0.5" x-show="r.clasificacion" x-text="'Clasificación IA: ' + r.clasificacion"></div>
              </div>
            </template>
            <div x-show="charla && charla.mockup" class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-2.5 text-xs">
              🎨 Mockup: <span class="text-slate-300 font-semibold" x-text="charla.mockup.estado"></span>
              <span class="text-slate-400" x-text="charla.mockup.solicitado_en ? ' · solicitado: ' + (charla.mockup.solicitado_en || '').slice(0,10) : ''"></span>
            </div>
            <div x-show="charla && charla.presupuesto" class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-2.5 text-xs">
              🧾 Presupuesto: <span class="text-slate-300 font-semibold" x-text="'v' + charla.presupuesto.version + ' · ' + Number(charla.presupuesto.importe_total).toLocaleString('es-ES') + ' €'"></span>
              <span class="text-slate-400" x-text="charla.presupuesto.estado"></span>
            </div>
            <div x-show="cargandoCharla" class="text-xs text-slate-400 p-2">Cargando charla…</div>
            <div x-show="!cargandoCharla && charla && !charla.ok" class="text-xs text-rose-400 p-2" x-text="charla.error"></div>
          </div>
        </div>
        <!-- DERECHA: REDACTAR + ENVIAR -->
        <div class="flex flex-col min-h-0 p-3 space-y-3 overflow-y-auto">
          <div class="flex items-center gap-2 flex-wrap">
            <button @click="generarEmailIA()" :disabled="generandoIA" class="px-3 py-1.5 bg-violet-500/20 text-violet-400 border border-violet-500/30 rounded-lg text-sm font-semibold hover:bg-violet-500/30 transition disabled:opacity-50">
              <span x-show="!generandoIA">✨ Generar respuesta con IA</span>
              <span x-show="generandoIA" class="flex items-center gap-1.5"><span class="w-3 h-3 border-2 border-violet-400 border-t-transparent rounded-full animate-spin inline-block"></span> Redactando…</span>
            </button>
            <select x-model="plantillaSel" @change="elegirPlantilla()" class="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50 min-w-[180px]">
              <option value="0">📋 Plantilla base (opcional)</option>
              <template x-for="p in (charla ? charla.plantillas : [])" :key="p.id">
                <option :value="p.id" x-text="p.nombre"></option>
              </template>
            </select>
          </div>
          <!-- 🧠 AI COMMAND CENTER: análisis ejecutivo de la conversación -->
          <button @click="analizarLeadIA()" :disabled="analizandoIA" class="w-full px-3 py-1.5 bg-fuchsia-500/20 text-fuchsia-400 border border-fuchsia-500/30 rounded-lg text-sm font-semibold hover:bg-fuchsia-500/30 transition disabled:opacity-50">
            <span x-show="!analizandoIA">🧠 Analizar conversación con IA</span>
            <span x-show="analizandoIA" class="flex items-center justify-center gap-1.5"><span class="w-3 h-3 border-2 border-fuchsia-400 border-t-transparent rounded-full animate-spin inline-block"></span> Analizando…</span>
          </button>
          <div x-show="analisisIA" x-cloak class="border border-fuchsia-500/30 bg-fuchsia-500/5 rounded-lg p-3 space-y-2">
            <div class="text-xs font-bold uppercase tracking-wider text-fuchsia-400 flex items-center gap-2">🧠 Análisis IA</div>
            <p class="text-sm text-slate-200 leading-relaxed" x-text="analisisIA.resumen"></p>
            <div class="flex items-center gap-2 flex-wrap">
              <span class="px-2 py-0.5 rounded-full text-xs font-semibold" :class="iaIntencionColor(analisisIA.intencion)" x-text="iaIntencionLabel(analisisIA.intencion)"></span>
              <div class="flex-1 min-w-[100px] h-2 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-2 rounded-full transition-all" :class="iaConfianzaColor(analisisIA.confianza)" :style="'width:' + Math.round(analisisIA.confianza * 100) + '%'"></div>
              </div>
              <span class="text-xs text-slate-400" x-text="Math.round(analisisIA.confianza * 100) + '% confianza'"></span>
            </div>
            <p class="text-xs text-slate-400" x-text="'¿Por qué? ' + (analisisIA.motivo || '—')"></p>
            <div class="flex items-start gap-2">
              <span class="text-sm shrink-0">➡️</span>
              <span class="text-sm text-emerald-400 font-medium leading-snug" x-text="analisisIA.proxima_accion"></span>
            </div>
            <button @click="generarEmailIA()" class="w-full px-3 py-1.5 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-semibold hover:bg-emerald-500/30 transition">✉️ Redactar respuesta con esta acción</button>
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-300 mb-1">Asunto</label>
            <input type="text" x-model="emailAsunto" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
          </div>
          <div class="flex-1">
            <label class="block text-sm font-semibold text-slate-300 mb-1">Cuerpo (editable)</label>
            <textarea x-model="emailCuerpo" rows="9" class="w-full h-full min-h-[150px] bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50 resize-y"></textarea>
          </div>
          <div class="border-t border-slate-800 pt-3 space-y-2.5">
            <div>
              <label class="block text-sm font-semibold text-slate-300 mb-1">Cuenta SMTP (heredada del último envío)</label>
              <select x-model="smtpSel" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-violet-500/50">
                <option value="0">Seleccionar cuenta…</option>
                <template x-for="c in (charla ? charla.cuentas_smtp : [])" :key="c.id">
                  <option :value="c.id" x-text="c.email"></option>
                </template>
              </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer" x-show="charla && charla.mockup && ['solicitado','en_produccion'].includes(charla.mockup.estado)">
              <input type="checkbox" x-model="incluirMockup" class="w-4 h-4 accent-violet-500"> Incluir mockup (<span x-text="charla.mockup.estado"></span>)
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer" x-show="charla && charla.presupuesto && charla.presupuesto.estado === 'creado'">
              <input type="checkbox" x-model="incluirProforma" class="w-4 h-4 accent-violet-500"> Incluir proforma (<span x-text="'v' + charla.presupuesto.version + ' · ' + Number(charla.presupuesto.importe_total).toLocaleString('es-ES') + ' €'"></span>)
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" x-model="adjuntarPresupuesto" class="w-4 h-4 accent-violet-500"> 📎 Adjuntar presupuesto PDF
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer">
              <input type="checkbox" x-model="adjuntarBoceto" class="w-4 h-4 accent-violet-500"> 📎 Adjuntar boceto de espinilleras PDF
            </label>
            <div x-show="atencionMsg" class="text-xs rounded-lg px-3 py-2" :class="atencionMsgTipo === 'error' ? 'bg-rose-500/10 text-rose-400 border border-rose-500/30' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'" x-text="atencionMsg"></div>
            <button @click="enviarAtencion()" :disabled="enviandoAtencion || !smtpSel || !emailCuerpo" class="w-full px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-bold hover:bg-emerald-500/30 transition disabled:opacity-50 flex items-center justify-center gap-1.5">
              <span x-show="!enviandoAtencion">🚀 ENVIAR a medida</span>
              <span x-show="enviandoAtencion" class="flex items-center gap-1.5"><span class="w-3 h-3 border-2 border-emerald-400 border-t-transparent rounded-full animate-spin inline-block"></span> Enviando…</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>