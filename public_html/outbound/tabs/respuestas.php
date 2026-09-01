<!-- ═══════════════ RESPONSES TAB — UNIBOX SPLIT-VIEW (FASE UNIBOX UI) ═══════════════
     Interfaz dividida 35%/65% (estándar Instantly/Smartlead/HubSpot) para triaje e
     interacción ultrarrápida a dos paneles. Sin ventanas modales: la selección de un
     lead en la lista izquierda renderiza su hilo completo en el visor derecho. -->
<!-- ═══════════════ CSS DE SOPORTE (CLASES ARBITRARIAS TAILWIND) ═══════════════
     El tailwind.min.css precompilado (ago 2026) NO incluye las clases arbitrarias
     que este tab necesita para el layout de scroll (h-[calc(100vh-120px)],
     w-[35%], min-h-0, etc.). Se definen aquí como CSS puro para garantizar el
     scroll independiente de la lista izquierda y del hilo derecho, sin necesidad
     de recompilar Tailwind (compatible SiteGround, sin Node.js). -->
<style>
  /* Altura del tab: ocupa el viewport menos la cabecera/navbar */
  .h-\[calc\(100vh-120px\)\] { height: calc(100vh - 120px); }
  /* Ancho del panel izquierdo (35%) y mínimo */
  .w-\[35\%\] { width: 35%; }
  .min-w-\[280px\] { min-width: 280px; }
  /* min-h-0: imprescindible en flexbox para que el overflow-y-auto interno
     haga scroll en lugar de expandir el panel */
  .min-h-0 { min-height: 0; }
  /* Ancho máximo de las burbujas de mensaje (85%) */
  .max-w-\[85\%\] { max-width: 85%; }
  /* Ancho mínimo del selector de plantilla */
  .min-w-\[200px\] { min-width: 200px; }
  .max-w-\[220px\] { max-width: 220px; }
  .min-h-\[110px\] { min-height: 110px; }
  .max-h-\[260px\] { max-height: 260px; }
</style>
<div class="flex flex-col gap-4 h-[calc(100vh-120px)] min-h-[480px]">


  <!-- Barra superior global de la pestaña -->
  <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-3 flex items-center gap-3 flex-wrap shrink-0">
    <i data-lucide="inbox" class="w-5 h-5 text-amber-400"></i>
    <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Bandeja de Respuestas</h5>
    <span class="text-xs text-slate-400" x-text="rsFiltradas.length + ' conversaciones'"></span>
    <div class="flex items-center gap-2 ml-auto flex-wrap">
      <button @click="syncRespuestas()" :disabled="rsSyncing" class="px-3 py-1.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded text-sm font-semibold hover:bg-amber-500/30 transition disabled:opacity-50 disabled:cursor-not-allowed">
        <i data-lucide="refresh-cw" class="w-4 h-4 inline-block mr-1" :class="rsSyncing ? 'animate-spin' : ''"></i>
        <span x-text="rsSyncing ? 'Sincronizando...' : 'Actualizar'"></span>
      </button>
      <div x-show="rsSyncMsg" x-text="rsSyncMsg" class="text-xs text-slate-400 mt-1"></div>

    </div>
  </div>

  <!-- ═══════════ SPLIT-VIEW: Panel Izquierdo (35%) + Panel Derecho (65%) ═══════════ -->
  <div class="flex flex-1 gap-3 overflow-hidden">

    <!-- ─────────── PANEL IZQUIERDO: Lista de Triaje (35%) ─────────── -->
    <!-- min-h-0: imprescindible en flexbox para que el overflow-y-auto interno
         (lista de correos) haga scroll en lugar de expandir el panel. -->
    <div class="w-[35%] min-w-[280px] min-h-0 flex flex-col bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">

      <!-- Barra de búsqueda + filtro -->
      <div class="p-3 border-b border-slate-800 space-y-2 shrink-0">
        <div class="relative">
          <!-- Lupa inline (SVG): siempre visible, sin depender de lucide/JS -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input x-model="rsBusqueda" type="text" placeholder="Buscar por nombre de club..."
                 class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-9 pr-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:border-orange-500/50 focus:outline-none">
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-slate-400 shrink-0">Clasificación:</label>
          <select x-model="rsFiltroClas" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-orange-500/50 focus:outline-none">
            <option value="">Todas</option>
            <option value="INTERESADO">Interesado</option>
            <option value="DUDA">Duda Precio</option>
            <option value="BAJA">Baja</option>
            <option value="REBOTE">Solo Rebotes</option>
            <option value="SIN_REBOTE">Ocultar Rebotes</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-slate-400 shrink-0">Orden:</label>
          <select x-model="rsOrden" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-orange-500/50 focus:outline-none">
            <option value="recepcion">Recepción</option>
            <option value="nombre">Nombre cliente</option>
            <option value="estado">Estado</option>
          </select>
        </div>
        <!-- Triaje: pestañas por estado de conversación (descriptivo, con contadores) -->
        <div class="flex items-center gap-1 flex-wrap">
          <button @click="rsSetTabTriaje('requiere_respuesta')" title="Conversaciones del club que requieren tu respuesta (las que te han escrito y aún no has contestado)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'requiere_respuesta' ? 'bg-orange-500/20 text-orange-400 border-orange-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            <i data-lucide="inbox" class="w-3.5 h-3.5"></i> Por responder
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-orange-500/15 text-orange-400" x-text="rsCountsTriaje.requiere_respuesta || 0"></span>
          </button>
          <button @click="rsSetTabTriaje('todos')" title="Todas las conversaciones (respondidas, en espera, pendientes...)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'todos' ? 'bg-amber-500/20 text-amber-400 border-amber-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            <i data-lucide="layout-grid" class="w-3.5 h-3.5"></i> Todos
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400" x-text="rsCountsTriaje.total || 0"></span>
          </button>
          <span class="text-xs text-slate-400" x-show="rsCountsTriaje.en_espera > 0" title="Conversaciones que respondiste y esperas al club">
            · <span class="text-sky-400 font-semibold" x-text="rsCountsTriaje.en_espera"></span> en espera
          </span>
          <button @click="rsSetTabTriaje('rebotes')" title="Correos rebotados (no entregados)"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'rebotes' ? 'bg-rose-500/20 text-rose-400 border-rose-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Rebotados
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400" x-text="rsCountsTriaje.rebotes || 0"></span>
          </button>
          <button @click="rsSetTabTriaje('archivados')" title="Conversaciones archivadas"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'archivados' ? 'bg-sky-500/20 text-sky-400 border-sky-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archivados
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/15 text-sky-400" x-text="rsCountsTriaje.archivados || 0"></span>
          </button>
          <button @click="rsSetTabTriaje('borrados')" title="Conversaciones borradas"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'borrados' ? 'bg-slate-500/20 text-slate-300 border-slate-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Borrados
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-500/15 text-slate-400" x-text="rsCountsTriaje.borrados || 0"></span>
          </button>
          <button x-show="rsTabTriaje === 'borrados' && (rsCountsTriaje.borrados || 0) > 0"
                  @click="rsVaciarPapelera()"
                  title="Eliminar definitivamente los correos de la papelera"
                  class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition bg-slate-800 text-rose-400 border-slate-700 hover:text-rose-300 hover:border-rose-500/40">
            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Vaciar papelera
          </button>
        </div>
      </div>

      <!-- Lista de leads (scroll independiente) -->
      <div class="flex-1 overflow-y-auto divide-y divide-slate-800/60">
        <template x-for="conv in rsFiltradas" :key="conv.clave || conv.id">
          <!-- Tarjeta de lead -->
          <div @click="rsSeleccionar(conv)"
               class="px-3 py-3.5 cursor-pointer transition border-l-4"
               :class="rsSeleccion && (rsSeleccion.clave === conv.clave || rsSeleccion.id === conv.id)
                 ? 'border-orange-500 bg-slate-800/80'
                 : 'border-transparent hover:bg-slate-800/40'">
            <!-- Fila 1: nombre (club → contacto → email) + tiempo transcurrido + fecha de ingreso -->
            <div class="flex items-center justify-between gap-2">
              <span class="font-bold text-sm truncate"
                    :class="(conv.nuevas || 0) > 0 ? 'text-amber-300' : 'text-slate-50'"
                    x-text="(conv.nombre_club && conv.nombre_club !== '—') ? (conv.nombre_club || conv.club) : (conv.contacto_nombre || conv.persona_contacto || conv.remitente_email || conv.email || '—')"></span>
              <span x-show="(conv.nuevas || 0) > 0"
                    class="ml-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold bg-amber-500 text-slate-900"
                    x-text="conv.nuevas"></span>
              <div class="flex items-center gap-2 shrink-0">
                <span class="text-[11px] text-slate-500" x-text="rsTiempoRelativo(rsEstadoHilo(conv).fecha)"></span>
                <span class="text-xs text-slate-400" x-text="rsFmtFecha(conv.ultima_fecha || conv.fecha || conv.fecha_respuesta)"></span>
              </div>
            </div>
            <!-- Fila 1.5: estado del hilo (Esperando respuesta / Sin responder) -->
            <div class="flex items-center gap-2 mt-1">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border"
                    :class="rsEstadoHilo(conv).esperando ? 'text-sky-400 bg-sky-500/10 border-sky-500/30' : 'text-amber-400 bg-amber-500/10 border-amber-500/30'"
                    x-text="rsEstadoHilo(conv).label"></span>
            </div>
            <!-- Fila 2: De / Para (una sola línea compacta) -->
            <div class="flex items-center gap-2 mt-1.5 text-xs font-mono">
              <span class="text-amber-400 truncate" x-text="'De: ' + (conv.remitente_email || conv.email || '—')"></span>
              <span class="text-slate-400 shrink-0">·</span>
              <span class="text-blue-400 truncate" x-text="'Para: ' + (conv.buzon_destino || conv.buzón_cuenta || '—')"></span>
            </div>
            <!-- Fila 3: snippet -->
            <div class="text-sm text-slate-400 truncate mt-1.5" x-text="rsSnippet(conv)"></div>
            <!-- Fila 4: semáforo de prioridad + badge de intención + volumen -->
            <div class="flex items-center gap-2 mt-2">
              <!-- Semáforo de prioridad (calculado en api/analytics.php → calcularScorePrioridad) -->
              <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold border"
                    :class="rsPrioColor(conv.prioridad)"
                    x-show="conv.prioridad">
                <span class="w-1.5 h-1.5 rounded-full" :class="rsPrioDot(conv.prioridad)"></span>
                <span x-text="rsPrioLabel(conv.prioridad)"></span>
              </span>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold"
                    :class="rsIntencion(conv).color"
                    x-text="'[' + rsIntencion(conv).label + ']'"></span>
              <span x-show="rsVolumenLabel(conv)" class="bg-slate-800 text-slate-300 border border-slate-700 px-1.5 py-0.5 rounded-full text-xs shrink-0" x-text="rsVolumenLabel(conv)"></span>
            </div>
          </div>
        </template>

        <div x-show="rsFiltradas.length === 0" class="p-8 text-center text-slate-400 text-sm">
          Sin conversaciones con los filtros seleccionados
        </div>
      </div>
    </div>

    <!-- ─────────── PANEL DERECHO: Visor de Conversación y Acción (65%) ─────────── -->
    <!-- min-h-0: permite que el hilo de mensajes (overflow-y-auto) haga scroll
         independiente sin expandir el panel ni empujar el footer. -->
    <div class="flex-1 min-h-0 flex flex-col bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">


      <!-- Estado vacío (sin selección) -->
      <div x-show="!rsSeleccion" class="flex-1 flex flex-col items-center justify-center text-slate-400 gap-3">
        <i data-lucide="inbox" class="w-12 h-12 text-slate-400"></i>
        <p class="text-sm">Selecciona una conversación de la lista para ver el hilo completo</p>
      </div>

      <template x-if="rsSeleccion">
      <!-- min-h-0: permite que el hilo de mensajes (flex-1 overflow-y-auto) haga
           scroll independiente dentro del visor, sin empujar el footer. -->
      <div class="flex flex-col h-full min-h-0">


        <!-- Header de Ficha Rápida (fijo arriba) -->
        <div class="px-4 py-3 border-b border-slate-800 bg-slate-800/40 shrink-0">
          <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="min-w-0">
              <h5 class="text-base font-bold text-slate-50 truncate"
                  x-text="(rsSeleccion.nombre_club && rsSeleccion.nombre_club !== '—') ? (rsSeleccion.nombre_club || rsSeleccion.club) : (rsSeleccion.remitente_email || rsSeleccion.email || '—')"></h5>
              <!-- Cabecera compacta: (email del club) contacto de <cuenta FutProtec que lo atiende> -->
              <div class="text-sm text-slate-300 truncate mt-0.5">
                <span class="font-mono" x-text="'(' + (rsSeleccion.remitente_email || rsSeleccion.email || '—') + ')'"></span>
                <span class="text-slate-400"> contacto de </span>
                <span class="font-mono text-sky-300" x-text="rsSeleccion.buzon_destino || rsSeleccion.buzón_cuenta || rsSeleccion.cuenta_emision || '—'"></span>
              </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap shrink-0">
              <span x-show="rsSeleccion.variant" class="px-2 py-1 rounded-full text-xs bg-slate-800 text-slate-300 border border-slate-700" x-text="'🧭 Variante ' + rsSeleccion.variant"></span>
              <!-- Interés del lead (clasificación de su última respuesta) -->
              <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border" :class="rsIntencion(rsSeleccion).color" x-text="rsIntencion(rsSeleccion).label"></span>
              <!-- Indicador de notas particulares del lead -->
              <span x-show="rsSeleccion.tiene_notas" title="Este lead tiene notas particulares (mira su ficha)" class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-amber-500/15 text-amber-400 border border-amber-500/30"><i data-lucide="sticky-note" class="w-3.5 h-3.5"></i> Notas</span>
              <!-- Botón: ficha completa del cliente (datos accesorios + notas) -->
              <button @click="openLead(rsSeleccion.lead_id)" x-show="rsSeleccion.lead_id" title="Ficha del cliente (datos y notas)"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition bg-slate-800 text-slate-300 border-slate-700 hover:text-white hover:border-slate-600"><i data-lucide="user" class="w-4 h-4"></i> Ficha</button>
              <!-- Desplegable de estado del lead (actualización en tiempo real) -->
              <select x-model="rsSeleccion.estado_lead" @change="rsActualizarEstadoLead()"
                      class="bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 text-sm text-slate-200 focus:border-orange-500/50 focus:outline-none">
                <template x-for="e in rsEstadosLead" :key="e">
                  <option :value="e" x-text="e"></option>
                </template>
              </select>
              <!-- Acciones del hilo (estado de conversación) — iconos lineales Lucide -->
              <button @click="rsAccion('atender')" title="Marcar como pendiente de respuesta" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-orange-500/15 text-orange-400 border-orange-500/30 hover:bg-orange-500/25"><i data-lucide="inbox" class="w-4 h-4"></i></button>
              <button @click="rsAccion('archivar')" title="Archivar conversación" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-sky-500/15 text-sky-400 border-sky-500/30 hover:bg-sky-500/25"><i data-lucide="archive" class="w-4 h-4"></i></button>
              <button @click="rsAccion('snooze', 1)" title="Posponer 1 día" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-amber-500/15 text-amber-400 border-amber-500/30 hover:bg-amber-500/25"><i data-lucide="clock" class="w-4 h-4"></i><span class="text-[10px]">1d</span></button>
              <button @click="rsAccion('snooze', 3)" title="Posponer 3 días" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-amber-500/15 text-amber-400 border-amber-500/30 hover:bg-amber-500/25"><i data-lucide="clock" class="w-4 h-4"></i><span class="text-[10px]">3d</span></button>
              <button @click="rsAccion('restaurar')" title="Restaurar (sacar de archivado/borrado)" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-emerald-500/15 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/25"><i data-lucide="rotate-ccw" class="w-4 h-4"></i></button>
              <button @click="rsAccion('borrar')" title="Borrar conversación" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-semibold border transition bg-rose-500/15 text-rose-400 border-rose-500/30 hover:bg-rose-500/25"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
            </div>
          </div>
          <!-- Mensaje de estado (feedback de actualización) -->
          <div x-show="rsEnvioMsg" class="mt-2 text-sm" :class="rsEnvioMsgOk ? 'text-emerald-400' : 'text-rose-400'" x-text="rsEnvioMsg"></div>
        </div>

        <!-- Cuerpo Central: Hilo de Mensajes (scroll independiente) -->
        <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-950/40">
          <template x-for="m in rsHiloInvertido()" :key="m.id">
            <div class="flex" :class="rsEsEntrante(m) ? 'justify-start' : 'justify-end'">
              <div class="max-w-[85%] rounded-xl p-3 border"
                   :class="rsEsEntrante(m) ? 'bg-slate-900 text-slate-100 border-slate-700' : 'bg-slate-800 text-slate-200 border-slate-700'">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <span class="text-xs font-bold uppercase tracking-wider"
                        :class="rsEsEntrante(m) ? 'text-slate-400' : 'text-orange-400'"
                        x-text="rsEsEntrante(m) ? (m.remitente || 'Club') : 'FutProtec'"></span>
                  <span class="text-xs text-slate-400" x-text="rsFmtFecha(m.fecha || m.fecha_respuesta || m.fecha_envio)"></span>
                  <span x-show="m.sentido !== 'saliente' && m.clasificacion" class="px-1.5 py-0.5 rounded-full text-xs font-semibold border" :class="rsClasColor(m.clasificacion)" x-text="rsClasLabel(m.clasificacion)"></span>
                </div>
                <div class="text-sm text-slate-300 font-medium" x-text="m.subject_respuesta || m.asunto_envio || ''"></div>
                <!-- Cuerpo del mensaje (render ROBUSTO): rsCuerpoMensaje devuelve el
                     mejor texto disponible (limpio → texto → extraído del HTML).
                     Solo si no hay nada legible se muestra el HTML sanitizado o el asunto. -->
                <div class="mensaje-cuerpo-texto mt-1 whitespace-pre-wrap text-sm text-slate-200" x-show="rsCuerpoMensaje(m)" x-text="rsCuerpoMensaje(m)"></div>
                <div class="mensaje-cuerpo-html mt-1" x-show="!rsCuerpoMensaje(m) && m.contenido_html" x-html="rsSanitizarHtml(m.contenido_html)"></div>
                <!-- Sin cuerpo ni HTML extraíble: al menos mostrar el asunto (trazabilidad). -->
                <div class="mensaje-cuerpo-texto mt-1 text-slate-500 italic text-sm" x-show="!rsCuerpoMensaje(m) && !m.contenido_html"><i data-lucide="file-text" class="w-3.5 h-3.5 inline-block mr-1"></i><span x-text="m.subject_respuesta || m.asunto_envio || '(correo sin contenido de texto extraíble)'"></span></div>
                <!-- Archivos adjuntos del mensaje (descarga autenticada) -->
                <div x-show="m.adjuntos && m.adjuntos.length > 0" class="mt-2 flex items-center gap-2 flex-wrap">
                  <template x-for="a in (m.adjuntos || [])" :key="'adj' + a.id">
                    <a :href="'api/adjunto.php?id=' + a.id + (m.sentido === 'saliente' ? '&tipo=envio' : '')" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-sky-500/15 text-sky-400 border border-sky-500/30 hover:bg-sky-500/25 transition"
                       :title="a.mime">
                      <i data-lucide="paperclip" class="w-3.5 h-3.5"></i> <span x-text="a.nombre"></span>
                      <span class="text-slate-400" x-text="(a.tamano ? (a.tamano/1024).toFixed(1) + ' KB' : '')"></span>
                    </a>
                  </template>
                </div>

                <!-- Clasificación rápida del mensaje (FASE 4: set comercial de 9 estados + legacy) -->
                <div x-show="m.sentido !== 'saliente'" class="mt-2 flex items-center gap-1 flex-wrap">
                  <template x-for="c in ['PENDING','POSITIVE','INTERESADO','SOLICITA_INFO','SOLICITA_PRECIO','SOLICITA_MOCKUP','NO_INTERESADO','NEGATIVE','NEUTRAL','UNSUBSCRIBE','OOO','HARD_BOUNCE','OTRO']" :key="c">
                    <button @click="clasificarRespuesta(m.id, c)"
                      :title="'Clasificar como ' + c"
                      class="px-2 py-0.5 rounded text-xs font-semibold transition border"
                      :class="m.clasificacion === c
                        ? 'bg-amber-500/20 text-amber-400 border-amber-500/40'
                        : 'bg-slate-900 text-slate-400 border-slate-700 hover:bg-slate-700 hover:text-slate-300'"
                      x-text="rsClasBotonLabel(c)"></button>
                  </template>
                </div>
              </div>
            </div>
          </template>
          <p x-show="!rsSeleccion.mensajes || rsSeleccion.mensajes.length === 0" class="text-center text-slate-400 text-sm py-4">Sin mensajes en este hilo</p>
        </div>

        <!-- Footer: Caja de Respuesta Inmediata -->
        <div class="px-4 py-3 border-t border-slate-800 bg-slate-800/40 shrink-0">
          <!-- Selector de plantillas (las REALES que se editan en Plantillas) -->
          <div class="flex items-center gap-2 mb-2 flex-wrap">
            <label class="text-sm text-slate-400">Plantilla:</label>
            <select x-model="rsPlantillaRapida" @change="rsAplicarPlantillaRapida()"
                    class="flex-1 min-w-[200px] bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-orange-500/50 focus:outline-none">
              <option value="">— Seleccionar plantilla —</option>
              <template x-for="t in rsTemplatesRapidas" :key="'rt' + t.id">
                <option :value="t.id" x-text="(t.categoria ? t.categoria + ' · ' : '') + t.nombre"></option>
              </template>
            </select>
            <button @click="rsCargarTemplates()" title="Recargar plantillas"
                    class="w-8 h-8 shrink-0 rounded-lg bg-slate-800 border border-slate-700 text-slate-300 hover:text-white hover:border-slate-600 transition text-sm">↻</button>
          </div>
          <p x-show="rsTemplatesMsg" class="text-xs text-amber-400 mb-2" x-text="rsTemplatesMsg"></p>
          <!-- Editor de respuesta (HTML sencillo, tipo Tiny) -->
          <div class="rounded-lg border border-slate-700 bg-slate-900 overflow-hidden">
            <div class="flex items-center gap-1 px-2 py-1.5 border-b border-slate-700 bg-slate-950 flex-wrap">
              <button type="button" @click="rsEditorCmd('bold')" class="w-7 h-7 rounded hover:bg-slate-700 transition text-sm font-bold text-slate-200" title="Negrita"><b>B</b></button>
              <button type="button" @click="rsEditorCmd('italic')" class="w-7 h-7 rounded hover:bg-slate-700 transition text-sm italic text-slate-200" title="Cursiva"><i>I</i></button>
              <button type="button" @click="rsEditorCmd('underline')" class="w-7 h-7 rounded hover:bg-slate-700 transition text-sm underline text-slate-200" title="Subrayado"><u>U</u></button>
              <button type="button" @click="rsEditorCmd('insertUnorderedList')" class="w-7 h-7 rounded hover:bg-slate-700 transition text-sm text-slate-200" title="Lista con viñetas">•≡</button>
              <button type="button" @click="rsEditorCmd('insertOrderedList')" class="w-7 h-7 rounded hover:bg-slate-700 transition text-sm text-slate-200" title="Lista numerada">1≡</button>
              <button type="button" @click="rsEditorLink()" class="px-2 h-7 rounded hover:bg-slate-700 transition text-sm text-sky-400" title="Insertar enlace">🔗</button>
              <span class="mx-1 w-px h-5 bg-slate-700"></span>
              <span class="text-[11px] text-slate-400 ml-auto" x-text="(rsRedaccion || '').length + ' caracteres'"></span>
            </div>
            <div x-ref="rsEditorBody" contenteditable="true" @input="rsEditorInput()"
                 class="p-2 min-h-[110px] max-h-[260px] overflow-y-auto text-sm text-slate-200 focus:outline-none bg-slate-900"
                 placeholder="Escribe tu respuesta aquí... (o usa la IA / una plantilla)"></div>
          </div>
          <!-- Adjuntos de la respuesta (archivos a enviar) -->
          <div class="flex items-center gap-2 mt-2 flex-wrap">
            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-sm font-semibold text-slate-300 hover:text-slate-100 hover:border-slate-600 transition cursor-pointer">
              <i data-lucide="paperclip" class="w-4 h-4"></i> Adjuntar
              <input type="file" multiple class="hidden" @change="rsAdjuntarArchivos($event)"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.zip,.txt">
            </label>
            <select @change="rsAgregarRepoAdjunto($event)"
                    class="bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 text-sm text-slate-300 focus:border-sky-500/50 focus:outline-none max-w-[220px]">
              <option value="">📚 Del repositorio…</option>
              <template x-for="ra in rsRepoAdjuntos" :key="'repo' + ra.id">
                <option :value="ra.id" x-text="ra.nombre"></option>
              </template>
            </select>
            <template x-for="(a, i) in (rsAdjuntos || [])" :key="'rsadj' + i">
              <span class="inline-flex items-center gap-1 px-2 py-1 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-300">
                📎 <span class="truncate max-w-[150px]" x-text="a.name"></span>
                <span class="text-slate-400 shrink-0" x-text="(a.size / 1024).toFixed(1) + ' KB'"></span>
                <button @click="rsQuitarAdjunto(i)" title="Quitar adjunto"
                        class="text-rose-400 hover:text-rose-300 font-bold leading-none">✕</button>
              </span>
            </template>
          </div>
          <!-- Botones de acción -->
          <div class="flex items-center gap-2 mt-2 flex-wrap">
            <button @click="rsGenerarIA()" :disabled="rsGenerandoIA || !rsSeleccion"
                    class="px-4 py-2 bg-violet-500/20 hover:bg-violet-500/30 text-violet-300 border border-violet-500/30 rounded-lg text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed">
              <i data-lucide="sparkles" class="w-4 h-4 inline-block mr-1"></i>
              <span x-text="rsGenerandoIA ? 'Generando...' : '✨ Respuesta IA'"></span>
            </button>
            <button @click="rsEnviarRespuesta()" :disabled="rsEnviando"
                    class="px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed">
              <i data-lucide="send" class="w-4 h-4 inline-block mr-1"></i>
              <span x-text="rsEnviando ? 'Enviando...' : 'Enviar Respuesta SMTP'"></span>
            </button>
            <a :href="rsWaUrl" target="_blank" rel="noopener"
               class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold transition"
               :class="rsWaUrl ? '' : 'pointer-events-none opacity-40'">
              <i data-lucide="message-circle" class="w-4 h-4 inline-block mr-1"></i>
              Abrir WhatsApp Directo
            </a>
          </div>
        </div>

      </div>
      </template>
    </div>

  </div>
</div>
