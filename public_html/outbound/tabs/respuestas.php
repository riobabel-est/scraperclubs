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
        <!-- Triaje: pestañas por estado de conversación -->
        <div class="flex items-center gap-1 flex-wrap">
          <button @click="rsSetTabTriaje('requiere_respuesta')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'requiere_respuesta' ? 'bg-orange-500/20 text-orange-400 border-orange-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            📥 Requiere respuesta
          </button>
          <button @click="rsSetTabTriaje('rebotes')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'rebotes' ? 'bg-rose-500/20 text-rose-400 border-rose-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            ⚠️ Rebotados
          </button>
          <button @click="rsSetTabTriaje('archivados')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'archivados' ? 'bg-sky-500/20 text-sky-400 border-sky-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            📦 Archivados
          </button>
          <button @click="rsSetTabTriaje('borrados')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'borrados' ? 'bg-slate-500/20 text-slate-300 border-slate-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            🗑️ Borrados
          </button>
          <button @click="rsSetTabTriaje('todos')" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition"
                  :class="rsTabTriaje === 'todos' ? 'bg-amber-500/20 text-amber-400 border-amber-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            🗂️ Todo
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
            <!-- Fila 1: nombre (club → contacto → email) + fecha + badge intención -->
            <div class="flex items-center justify-between gap-2">
              <span class="font-bold text-slate-50 text-sm truncate"
                    x-text="(conv.nombre_club && conv.nombre_club !== '—') ? (conv.nombre_club || conv.club) : (conv.contacto_nombre || conv.persona_contacto || conv.remitente_email || conv.email || '—')"></span>
              <span class="text-xs text-slate-400 shrink-0" x-text="rsFmtFecha(conv.ultima_fecha || conv.fecha || conv.fecha_respuesta)"></span>
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
              <div class="text-sm text-slate-400 truncate">
                <span x-text="rsSeleccion.contacto_nombre || rsSeleccion.persona_contacto || '—'"></span>
                <span x-show="rsSeleccion.telefono || rsSeleccion.telefono_movil" class="text-slate-400"> · </span>
                <span x-text="rsSeleccion.telefono || rsSeleccion.telefono_movil || ''"></span>
              </div>
              <!-- Email de Origen y Email de Destino (FutProtec) -->
              <div class="text-xs text-amber-400 font-mono truncate mt-1" x-text="'De: ' + (rsSeleccion.remitente_email || rsSeleccion.email || '—')"></div>
              <div class="text-xs text-blue-400 font-mono truncate" x-text="'Para: ' + (rsSeleccion.buzon_destino || rsSeleccion.buzón_cuenta || '—')"></div>
            </div>

            <div class="flex items-center gap-2 flex-wrap shrink-0">
              <span x-show="rsSeleccion.variant" class="px-2 py-1 rounded-full text-xs bg-slate-800 text-slate-300 border border-slate-700" x-text="'Variante ' + rsSeleccion.variant"></span>
              <!-- Desplegable de estado del lead (actualización en tiempo real) -->
              <select x-model="rsSeleccion.estado_lead" @change="rsActualizarEstadoLead()"
                      class="bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 text-sm text-slate-200 focus:border-orange-500/50 focus:outline-none">
                <template x-for="e in rsEstadosLead" :key="e">
                  <option :value="e" x-text="e"></option>
                </template>
              </select>
              <!-- Acciones del hilo (estado de conversación) -->
              <button @click="rsAccion('atender')" title="Marcar como pendiente de respuesta" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-orange-500/15 text-orange-400 border-orange-500/30 hover:bg-orange-500/25">✉️</button>
              <button @click="rsAccion('archivar')" title="Archivar conversación" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-sky-500/15 text-sky-400 border-sky-500/30 hover:bg-sky-500/25">📦</button>
              <button @click="rsAccion('snooze', 1)" title="Posponer 1 día" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-amber-500/15 text-amber-400 border-amber-500/30 hover:bg-amber-500/25">⏰1d</button>
              <button @click="rsAccion('snooze', 3)" title="Posponer 3 días" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-amber-500/15 text-amber-400 border-amber-500/30 hover:bg-amber-500/25">⏰3d</button>
              <button @click="rsAccion('restaurar')" title="Restaurar (sacar de archivado/borrado)" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-emerald-500/15 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/25">↩️</button>
              <button @click="rsAccion('borrar')" title="Borrar conversación" class="px-2 py-1.5 rounded-lg text-xs font-semibold border transition bg-rose-500/15 text-rose-400 border-rose-500/30 hover:bg-rose-500/25">🗑️</button>
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
                <!-- Cuerpo del mensaje: prioriza el texto limpio (cuerpo_limpio/cuerpo_texto)
                     para garantizar que se vea TODO el contenido legible del email.
                     Solo si no hay texto limpio se muestra el HTML sanitizado. -->
                <template x-if="(m.cuerpo_limpio && m.cuerpo_limpio.trim() && m.cuerpo_limpio !== 'Sin contenido de texto') || (m.cuerpo_texto && m.cuerpo_texto.trim() && m.cuerpo_texto !== 'Sin contenido de texto')">
                  <div class="mensaje-cuerpo-texto mt-1 whitespace-pre-wrap text-sm text-slate-200" x-text="m.cuerpo_limpio || m.cuerpo_texto || ''"></div>
                </template>
                <template x-if="!((m.cuerpo_limpio && m.cuerpo_limpio.trim() && m.cuerpo_limpio !== 'Sin contenido de texto') || (m.cuerpo_texto && m.cuerpo_texto.trim() && m.cuerpo_texto !== 'Sin contenido de texto'))">
                  <div class="mensaje-cuerpo-html mt-1" x-html="rsSanitizarHtml(m.contenido_html)"></div>
                </template>
                <!-- Sin cuerpo ni HTML extraíble: al menos mostrar el asunto (trazabilidad). -->
                <template x-if="!((m.cuerpo_limpio && m.cuerpo_limpio.trim() && m.cuerpo_limpio !== 'Sin contenido de texto') || (m.cuerpo_texto && m.cuerpo_texto.trim() && m.cuerpo_texto !== 'Sin contenido de texto') || m.contenido_html)">
                  <div class="mensaje-cuerpo-texto mt-1 text-slate-500 italic text-sm">📄 <span x-text="m.subject_respuesta || m.asunto_envio || '(correo sin contenido de texto extraíble)'"></span></div>
                </template>
                <!-- Archivos adjuntos del mensaje (descarga autenticada) -->
                <div x-show="m.adjuntos && m.adjuntos.length > 0" class="mt-2 flex items-center gap-2 flex-wrap">
                  <template x-for="a in (m.adjuntos || [])" :key="'adj' + a.id">
                    <a :href="'api/adjunto.php?id=' + a.id + (m.sentido === 'saliente' ? '&tipo=envio' : '')" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-sky-500/15 text-sky-400 border border-sky-500/30 hover:bg-sky-500/25 transition"
                       :title="a.mime">
                      📎 <span x-text="a.nombre"></span>
                      <span class="text-slate-400" x-text="(a.tamano ? (a.tamano/1024).toFixed(1) + ' KB' : '')"></span>
                    </a>
                  </template>
                </div>

                <!-- Clasificación rápida del mensaje (solo respuestas entrantes) -->
                <div x-if="m.sentido !== 'saliente'" class="mt-2 flex items-center gap-1 flex-wrap">
                  <template x-for="c in ['PENDING','POSITIVE','NEGATIVE','NEUTRAL','UNSUBSCRIBE','OOO']" :key="c">
                    <button @click="clasificarRespuesta(m.id, c)"
                      class="px-2 py-0.5 rounded text-xs font-semibold transition border"
                      :class="m.clasificacion === c
                        ? 'bg-amber-500/20 text-amber-400 border-amber-500/40'
                        : 'bg-slate-900 text-slate-400 border-slate-700 hover:bg-slate-700 hover:text-slate-300'"
                      x-text="c"></button>
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
          </div>
          <!-- Editor de respuesta -->
          <textarea x-model="rsRedaccion" rows="3" placeholder="Escribe tu respuesta aquí... (o usa la IA / una plantilla)"
                    class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:border-orange-500/50 focus:outline-none resize-none"></textarea>
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
            <button @click="rsGenerarIA()" :disabled="rsGenerandoIA || !rsSeleccion || !rsSeleccion.lead_id"
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
