<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL FICHA LEAD v4 — Cualificacion + Mockup + Presupuesto -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div x-show="lm" @click.self="lm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[85vh] overflow-y-auto m-4">
<div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl z-10">
<h5 class="text-base font-bold text-slate-200" x-text="'Ficha: '+(ld.nombre_club||'')"></h5>
<div class="flex items-center gap-1.5 flex-wrap justify-end">
  <button @click="irABandejaLead(ld)" x-show="ld && ld.id" title="Abrir la conversación de este club en la Bandeja"
    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-sm font-semibold transition bg-sky-500/15 text-sky-400 border border-sky-500/30 hover:bg-sky-500/25">
    <i data-lucide="inbox" class="w-4 h-4"></i> Bandeja</button>
  <button @click="irAPipelineLead(ld)" x-show="ld && ld.id" title="Ver este club en el Pipeline (Kanban)"
    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-sm font-semibold transition bg-amber-500/15 text-amber-400 border border-amber-500/30 hover:bg-amber-500/25">
    <i data-lucide="columns-3" class="w-4 h-4"></i> Pipeline</button>
  <button @click="irASeguimientoLead(ld)" x-show="ld && ld.id" title="Abrir Seguimiento / modal Atender de este club"
    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-sm font-semibold transition bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25">
    <i data-lucide="trending-up" class="w-4 h-4"></i> Seguimiento</button>
  <button @click="lm=false" class="text-slate-400 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button>
</div>
</div>
<div class="p-5 space-y-4">

<!-- Estado de carga / error -->
<div x-show="ldLoading" class="flex items-center justify-center py-12">
<span class="w-8 h-8 border-2 border-amber-400 border-t-transparent rounded-full animate-spin"></span>
<span class="ml-3 text-slate-400 text-sm">Cargando ficha...</span>
</div>
<div x-show="ldError" class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-4 text-center">
<p class="text-rose-400 text-sm font-semibold">Error al cargar la ficha</p>
<p class="text-rose-300 text-xs mt-1" x-text="ldError"></p>
<button @click="lm=false" class="mt-3 px-4 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-300 hover:bg-slate-700 transition">Cerrar</button>
</div>

<!-- Contenido de la ficha (solo si no hay error ni loading) -->
<div x-show="!ldLoading && !ldError">

<!-- ═══ FILA 1: Club + Federacion ═══ -->
<div class="grid grid-cols-2 gap-4">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Club</label>
<input type="text" x-model="ld.nombre_club" readonly class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-400 cursor-not-allowed">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Federacion</label>
<select x-model="ld.federacion" @change="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
<option value="">Sin federacion</option>
<?php foreach($federacionesSelect as $fed):?>
<option value="<?=escHtml($fed)?>"><?=escHtml($fed)?></option>
<?php endforeach;?>
</select>
</div>
</div>

<!-- ═══ FILA 2: Persona Contacto + Cargo ═══ -->
<div class="grid grid-cols-2 gap-4">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Persona Contacto</label>
<input type="text" x-model="ld.persona_contacto" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Cargo</label>
<input type="text" x-model="ld.cargo_contacto" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
</div>
</div>

<!-- ═══ FILA 3: Telefono Fijo ═══ -->
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Telefono Fijo</label>
<input type="text" x-model="ld.telefono_fijo" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>

<!-- ═══ FILA 4: Email + Envios/Aperturas ═══ -->
<div class="grid grid-cols-5 gap-4">
<div class="col-span-3">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Email</label>
<input type="text" x-model="ld.email" readonly class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-400 font-mono cursor-not-allowed">
</div>
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Estadisticas</label>
<div class="bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2.5 flex items-center justify-around">
<div class="text-center"><span class="text-slate-400 text-xs block">Envios</span><span class="text-sm font-bold text-blue-400" x-text="ld.total_envios||0">0</span></div>
<div class="text-slate-400">|</div>
<div class="text-center"><span class="text-slate-400 text-xs block">Aperturas</span><span class="text-sm font-bold text-cyan-400" x-text="ld.total_aperturas||0">0</span></div>
</div>
</div>
</div>

<!-- ═══ FILA 5: Telefono Movil + WhatsApp ═══ -->
<div class="grid grid-cols-5 gap-4">
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Telefono Movil</label>
<input type="text" x-model="ld.telefono_movil" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
<div class="col-span-3">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">WhatsApp</label>
<div class="bg-slate-800 border rounded-lg px-3 py-2.5 flex items-center gap-3" :class="waLink ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-slate-700'">
<div class="flex items-center gap-2"><input type="checkbox" x-model="ld.tiene_whatsapp" @change="markChanged()" class="w-4 h-4 accent-amber-500 rounded"><label class="text-sm text-slate-300 cursor-pointer select-none">WhatsApp</label></div>
<a :href="waLink" x-show="waLink" target="_blank" @click="registrarWhatsApp()" class="px-3 py-1.5 rounded-lg text-sm font-semibold transition flex items-center gap-1.5 ml-auto" :class="waLink ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30' : 'bg-slate-700 text-slate-400 cursor-not-allowed'"><i data-lucide="message-circle" class="w-4 h-4"></i> Enviar WA</a>
<span x-show="!waLink" class="text-xs text-slate-400 ml-auto">Sin numero valido</span>
</div>
</div>
</div>

<!-- ═══ FILA 6: Estado Kanban ═══ -->
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Estado Kanban</label>
<select x-model="ld.estado_lead" @change="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
<?php foreach($estadosKanban as $es):?>
<option value="<?=escHtml($es)?>"><?=escHtml($es)?></option>
<?php endforeach;?>
</select>
</div>

<!-- ═══ LISTA NEGRA (BLOQUE 4) ═══ -->
<div class="bg-slate-800/30 border border-slate-700/50 rounded-xl p-4 space-y-3">
    <template x-if="!ldEsListaNegra()">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 inline-block"></span>
                <span class="text-sm font-semibold text-emerald-400">Contacto operativo</span>
            </div>
            <button type="button" @click="blAdd(ld)" class="w-full px-4 py-2 bg-rose-500/10 text-rose-400 border border-rose-500/30 rounded-lg text-sm font-semibold hover:bg-rose-500/20 transition">
                <i data-lucide="ban" class="w-4 h-4 inline-block mr-1"></i> Añadir a Lista Negra
            </button>
        </div>
    </template>
    <template x-if="ldEsListaNegra()">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="w-2.5 h-2.5 rounded-full bg-rose-500 inline-block"></span>
                <span class="text-sm font-semibold text-rose-400">En Lista Negra</span>
            </div>
            <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3 text-xs space-y-1">
                <div class="flex justify-between"><span class="text-slate-400">Tipo:</span><span class="text-slate-300" x-text="ldTipoSupresion()"></span></div>
                <div class="flex justify-between"><span class="text-slate-400">Fecha:</span><span class="text-slate-300" x-text="ldFechaSupresion()"></span></div>
                <div class="flex justify-between"><span class="text-slate-400">Motivo:</span><span class="text-slate-300" x-text="ldMotivoSupresion()"></span></div>
            </div>
            <button type="button" @click="blRemove(ld)" class="mt-2 w-full px-4 py-2 bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-semibold hover:bg-emerald-500/20 transition">
                <i data-lucide="check-circle" class="w-4 h-4 inline-block mr-1"></i> Quitar de Lista Negra
            </button>
        </div>
    </template>
</div>

<!-- ═══ CUALIFICACION ═══ -->

<div x-show="['04 Propuesta','05 Ganado','06 Perdido'].includes(ld.estado_lead)" class="bg-slate-800/30 border border-slate-700/50 rounded-xl p-4 space-y-3">
<h6 class="text-xs text-amber-400 uppercase tracking-wider font-bold">Cualificacion Comercial</h6>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Volumen Estimado (pares)</label><input type="number" x-model="ld.volumen_estimado" @input="markChanged(); calcPrecio()" min="0" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Num. Jugadores (aprox)</label><input type="number" x-model="ld.num_jugadores" @input="markChanged()" min="0" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
</div>
<div x-show="(ld.volumen_estimado||0) >= 50" class="bg-slate-800/50 border border-amber-500/30 rounded-lg p-3 grid grid-cols-3 gap-2 text-xs">
<div class="text-center"><span class="text-slate-400 block">Precio B2B</span><span class="text-amber-400 font-bold text-sm" x-text="ldCalcPrecio.precio_b2b ? ldCalcPrecio.precio_b2b+'€/par' : '-'"></span></div>
<div class="text-center"><span class="text-slate-400 block">Facturacion</span><span class="text-emerald-400 font-bold text-sm" x-text="ldCalcPrecio.facturacion ? ldCalcPrecio.facturacion+'€' : '-'"></span></div>
<div class="text-center"><span class="text-slate-400 block">Margen Club</span><span class="text-blue-400 font-bold text-sm" x-text="ldCalcPrecio.margen_total ? ldCalcPrecio.margen_total+'€' : '-'"></span></div>
<div class="col-span-3 text-center text-slate-400 text-[10px]" x-text="ldCalcPrecio.tramo||''"></div>
</div>
<div x-show="(ld.volumen_estimado||0) > 0 && (ld.volumen_estimado||0) < 50" class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-2 text-xs text-rose-400 text-center">Volumen insuficiente para propuesta/mockup (minimo 50 pares)</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Categorias/Equipos</label><input type="text" x-model="ld.categorias" @input="markChanged()" placeholder="Ej: Benjamines, Alevines, Infantiles" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Fecha Decision Prevista</label><input type="date" x-model="ld.fecha_decision_prevista" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Canal Interaccion</label><select x-model="ld.canal_interaccion" @change="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"><option value="">--</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="telefono">Telefono</option><option value="presencial">Presencial</option></select></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Proxima Accion</label><input type="text" x-model="ld.proxima_accion" @input="markChanged()" placeholder="Ej: Llamar el lunes" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
</div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Objeciones</label><textarea x-model="ld.objeciones" @input="markChanged()" rows="2" placeholder="Objeciones del club..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 resize-y"></textarea></div>
</div>

<!-- ═══ MOTIVO PERDIDA ═══ -->
<div x-show="ld.estado_lead === '06 Perdido'" class="bg-rose-500/10 border border-rose-500/30 rounded-lg p-3">
<label class="text-xs text-rose-400 uppercase tracking-wider font-bold">Motivo de Perdida</label>
<select x-model="ld.motivo_perdida" @change="markChanged()" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-rose-500/50">
<option value="">-- Seleccionar motivo --</option>
<option value="precio">Precio</option><option value="no_interesa">No interesa</option><option value="ya_tiene_proveedor">Ya tiene proveedor</option>
<option value="no_gestionar_venta">No quieren gestionar venta</option><option value="volumen_insuficiente">Volumen insuficiente</option>
<option value="timing">Timing (fuera de temporada)</option><option value="falta_respuesta">Falta de respuesta</option>
<option value="directiva">Decision de directiva</option><option value="quiere_muestra">Quiere muestra fisica</option>
<option value="margen_insuficiente">Margen insuficiente</option><option value="otro">Otro</option>
</select>
</div>

<!-- ═══ MOCKUP ═══ -->
<div x-show="['04 Propuesta'].includes(ld.estado_lead)" class="bg-indigo-500/10 border border-indigo-500/30 rounded-xl p-4 space-y-3">
<h6 class="text-xs text-indigo-400 uppercase tracking-wider font-bold">Mockup</h6>
<div class="flex gap-2">
<button @click="solicitarMockup()" x-show="!ldMockup || !ldMockup.id" class="px-4 py-2 bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 rounded-lg text-sm font-bold hover:bg-indigo-500/30 transition flex items-center gap-1" :disabled="(ld.volumen_estimado||0) < 50"><i data-lucide="image" class="w-4 h-4"></i> Solicitar Mockup</button>
<button @click="mockupEnviado()" x-show="ldMockup && ldMockup.estado === 'solicitado'" class="px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-bold hover:bg-emerald-500/30 transition flex items-center gap-1"><i data-lucide="send" class="w-4 h-4"></i> Mockup Enviado</button>
</div>
<div x-show="ldMockup && ldMockup.id" class="bg-slate-800/50 border border-slate-700 rounded-lg p-3 text-xs space-y-1">
<div class="flex justify-between"><span class="text-slate-400">Estado:</span><span class="font-bold" :class="ldMockup.estado==='enviado'?'text-emerald-400':ldMockup.estado==='solicitado'?'text-amber-400':'text-slate-400'" x-text="ldMockup.estado||'pendiente'"></span></div>
<div class="flex justify-between" x-show="ldMockup.solicitado_en"><span class="text-slate-400">Solicitado:</span><span class="text-slate-300" x-text="ldMockup.solicitado_en?.substring(0,16)"></span></div>
<div class="flex justify-between" x-show="ldMockup.enviado_en"><span class="text-slate-400">Enviado:</span><span class="text-slate-300" x-text="ldMockup.enviado_en?.substring(0,16)"></span></div>
</div>
<div x-show="(ld.volumen_estimado||0) < 50 && (ld.volumen_estimado||0) > 0" class="text-xs text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg p-2 text-center">Volumen insuficiente para mockup (min 50 pares)</div>
</div>

<!-- ═══ PRESUPUESTO ═══ -->
<template x-if="['04 Propuesta'].includes(ld.estado_lead)">
<div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 space-y-3">
<h6 class="text-xs text-emerald-400 uppercase tracking-wider font-bold">Presupuesto</h6>
<button @click="crearPresupuesto()" class="px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-bold hover:bg-emerald-500/30 transition flex items-center gap-1"><i data-lucide="calculator" class="w-4 h-4"></i> Crear / Versionar Presupuesto</button>
<template x-if="ld.presupuesto && ld.presupuesto.id">
<div class="bg-slate-800/50 border border-slate-700 rounded-lg p-3 text-xs space-y-1">
<div class="flex justify-between"><span class="text-slate-400">Version:</span><span class="text-slate-300 font-bold" x-text="'v'+ld.presupuesto.version"></span></div>
<div class="flex justify-between"><span class="text-slate-400">Unidades:</span><span class="text-slate-300" x-text="ld.presupuesto.unidades"></span></div>
<div class="flex justify-between"><span class="text-slate-400">Precio Unitario:</span><span class="text-amber-400" x-text="ld.presupuesto.precio_unitario+'€'"></span></div>
<div class="flex justify-between"><span class="text-slate-400">Condiciones:</span><span class="text-slate-300" x-text="ld.presupuesto.condiciones_pago"></span></div>
<div class="flex justify-between border-t border-slate-700 pt-1 mt-1"><span class="text-slate-400">Total:</span><span class="text-emerald-400 font-bold text-sm" x-text="ld.presupuesto.importe_total+'€'"></span></div>
<div class="flex justify-between"><span class="text-slate-400">Margen Club:</span><span class="text-blue-400" x-text="ld.presupuesto.margen_potencial_club+'€'"></span></div>
<div class="flex justify-between"><span class="text-slate-400">Fecha:</span><span class="text-slate-300" x-text="ld.presupuesto?.fecha?.substring(0,16) || ''"></span></div>
</div>
</template>
</div>
</template>

<!-- ═══ REGISTRAR INTERACCION (F2.6) ═══ -->
<div class="bg-cyan-500/10 border border-cyan-500/30 rounded-xl p-4 space-y-3">
<h6 class="text-xs text-cyan-400 uppercase tracking-wider font-bold">Registrar Interaccion</h6>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Canal</label>
<select x-model="irForm.canal" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500/50">
<option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="telefono">Teléfono</option><option value="presencial">Presencial</option></select></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Tipo</label>
<select x-model="irForm.tipo_evento" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500/50">
<option value="llamada">Llamada</option><option value="email_enviado">Email Enviado</option><option value="whatsapp_enviado">WhatsApp Enviado</option><option value="reunion">Reunión</option><option value="visita">Visita</option><option value="nota_manual">Nota</option></select></div>
</div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Resumen *</label>
<textarea x-model="irForm.resumen" rows="2" placeholder="Resumen de la interacción..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500/50 resize-y"></textarea></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Resultado</label>
<input type="text" x-model="irForm.resultado" placeholder="Ej: Interesado, No contesta..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500/50"></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Próxima Acción</label>
<input type="text" x-model="irForm.proxima_accion" placeholder="Ej: Enviar presupuesto..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-cyan-500/50"></div>
</div>
<button @click="registrarInteraccion()" :disabled="irSending" class="px-4 py-2 bg-cyan-500/20 text-cyan-400 border border-cyan-500/30 rounded-lg text-sm font-bold hover:bg-cyan-500/30 transition flex items-center gap-1">
<i data-lucide="message-square-plus" class="w-4 h-4"></i><span x-text="irSending ? 'Guardando...' : 'Registrar Interaccion'"></span></button>
</div>

<!-- ═══ OBSERVACIONES ═══ -->
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Observaciones</label>
<div class="flex gap-2 mb-2">
<textarea x-model="ln" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 h-16 focus:outline-none focus:border-amber-500/50 resize-y" placeholder="Anadir nota..."></textarea>
<button @click="addNota()" class="px-3 py-2 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/25 transition self-end whitespace-nowrap flex items-center gap-1"><i data-lucide="plus" class="w-4 h-4"></i> Nota</button>
</div>
<pre x-text="ld.observaciones||'(sin notas)'" class="bg-slate-800/50 border border-slate-700 rounded-lg p-3 text-xs text-slate-400 max-h-32 overflow-y-auto whitespace-pre-wrap font-mono"></pre>
</div>

<!-- ═══ GUARDAR ═══ -->
<div class="pt-2 border-t border-slate-800 flex justify-end">
<button @click="guardarFicha()" :disabled="!ldChanged" class="px-5 py-2.5 rounded-lg text-sm font-bold transition flex items-center gap-2" :class="ldChanged ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30 hover:bg-amber-500/30 cursor-pointer' : 'bg-slate-800 text-slate-400 border border-slate-700 cursor-not-allowed'">
<i data-lucide="save" class="w-4 h-4"></i><span x-show="ldChanged">GUARDAR CAMBIOS</span><span x-show="!ldChanged">Sin cambios</span>
</button>
</div>


</div>
</div></div></div>
<!-- Toggle de contraseña SMTP movido a js/app.js (refactor 2026-08-25). -->
<!-- El handler usa delegación de eventos: input[data-smtp-password-input] + botón [data-smtp-toggle]. -->

<!-- ═══════════════ MODAL ADD LEAD ═══════════ -->
<div x-show="al" @click.self="al=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md m-4">
<div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between"><h5 class="text-sm font-bold text-slate-200">Anadir Nuevo Lead (con validacion MX)</h5><button @click="al=false" class="text-slate-400 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div>
<div class="p-5 space-y-3">
<div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Nombre Club *</label><input type="text" x-model="af.nombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Email *</label><input type="email" x-model="af.email" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Federacion</label><select x-model="af.federacion" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="">-- Sin federacion --</option><?php foreach($federacionesSelect as $fed):?><option value="<?=escHtml($fed)?>"><?=escHtml($fed)?></option><?php endforeach;?></select></div>
<div class="grid grid-cols-2 gap-2">
<div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Telefono Movil</label><input type="text" x-model="af.movil" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><span x-show="afWaDetected" class="text-[9px] text-emerald-400 mt-1 inline-block">WhatsApp detectado</span></div>
<div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Telefono Fijo</label><input type="text" x-model="af.fijo" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
</div>
<div class="grid grid-cols-2 gap-2"><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Persona Contacto</label><input type="text" x-model="af.persona" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Cargo</label><input type="text" x-model="af.cargo" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div>
<div class="flex gap-2 pt-2"><button @click="al=false" class="flex-1 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="saveAddLead()" class="flex-1 px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/30 transition">Guardar Lead (valida MX)</button></div>
</div></div></div>

<!-- ═══════════════ MODAL MERGE ═══════════ -->
<div x-show="mm" @click.self="mm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition><div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[85vh] overflow-y-auto m-4"><div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl"><h5 class="text-sm font-bold text-amber-400"><i data-lucide="git-compare" class="w-4 h-4 inline mr-1"></i> Fusionar Duplicados</h5><button @click="mm=false" class="text-slate-400 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div><div class="p-5"><div class="grid md:grid-cols-2 gap-4 mb-4"><div class="bg-slate-800 border border-blue-500/30 rounded-xl p-3"><h6 class="text-[10px] font-bold text-blue-400 uppercase tracking-wider mb-2">Registro A (conservar) <span x-text="'#'+mk"></span></h6><div class="text-xs text-slate-400 space-y-1" x-html="mha"></div></div><div class="bg-slate-800 border border-rose-500/30 rounded-xl p-3"><h6 class="text-[10px] font-bold text-rose-400 uppercase tracking-wider mb-2">Registro B (eliminar) <span x-text="'#'+md"></span></h6><div class="text-xs text-slate-400 space-y-1" x-html="mhb"></div></div></div><div class="bg-slate-800 border border-slate-700 rounded-xl p-3 mb-3 space-y-2"><label class="text-[10px] text-slate-400 uppercase tracking-wider">Campos a conservar:</label><template x-for="f in mf" :key="f.name"><div class="flex items-center gap-3 text-xs"><span class="w-16 text-slate-400 text-[10px] uppercase" x-text="f.label"></span><label class="flex items-center gap-1 text-slate-300"><input type="radio" :name="'mg_'+f.name" value="A" :checked="f.cA" class="w-3 h-3 accent-amber-500"><span class="text-[10px]" x-text="'A: '+(f.vA||'vacio')"></span></label><label class="flex items-center gap-1 text-slate-300"><input type="radio" :name="'mg_'+f.name" value="B" :checked="!f.cA" class="w-3 h-3 accent-amber-500"><span class="text-[10px]" x-text="'B: '+(f.vB||'vacio')"></span></label></div></template></div><label class="flex items-center gap-2 text-xs text-slate-400 mb-3"><input type="checkbox" x-model="mn" class="w-3 h-3 accent-amber-500">Fusionar notas de seguimiento</label><div class="flex gap-2"><button @click="mm=false" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="mm=false;openLead(mk)" class="px-4 py-2 bg-blue-500/10 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/20 transition"><i data-lucide="file-edit" class="w-3.5 h-3.5 inline mr-1"></i>Editar A (#<span x-text="mk"></span>)</button><button @click="mm=false;openLead(md)" class="px-4 py-2 bg-blue-500/10 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/20 transition"><i data-lucide="file-edit" class="w-3.5 h-3.5 inline mr-1"></i>Editar B (#<span x-text="md"></span>)</button><button @click="omitirDuplicado(md)" class="px-4 py-2 bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/20 transition"><i data-lucide="eye-off" class="w-3.5 h-3.5 inline mr-1"></i>Omitir (no es duplicado)</button><button @click="deleteLead(md)" class="px-4 py-2 bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-semibold hover:bg-rose-500/30 transition"><i data-lucide="trash-2" class="w-3.5 h-3.5 inline mr-1"></i>Eliminar Duplicado</button><button @click="doMerge()" class="px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/30 transition">Fusionar y Eliminar Duplicado</button></div></div></div></div>

<!-- ═══════════════ MODAL ANALYTICS ═══════════ -->
<template x-if="aq">
<div @click.self="aq=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[85vh] overflow-y-auto m-4">
<div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl z-10">
<div class="flex items-center gap-2"><i data-lucide="bar-chart-3" class="w-4 h-4 text-amber-400"></i><h5 class="text-sm font-bold text-slate-200" x-text="aqTitulo(aqTab)"></h5><span x-show="aqData" class="text-xs text-slate-400 ml-2" x-text="aqData.total + ' registros'"></span><span x-show="aqData && aqData.hoy !== undefined" class="text-xs text-blue-400 ml-2" x-text="'(hoy: '+aqData.hoy+')'"></span><span x-show="aqLoading" class="text-xs text-slate-400 ml-2">Cargando...</span></div>
<div class="flex items-center gap-1">
<button @click="abrirAnalytics('envios')" class="px-2.5 py-1 rounded text-xs font-semibold transition" :class="aqTab==='envios'?'bg-blue-500/20 text-blue-400':'text-slate-400 hover:text-slate-300'">Envios</button>
<button @click="abrirAnalytics('aperturas')" class="px-2.5 py-1 rounded text-xs font-semibold transition" :class="aqTab==='aperturas'?'bg-cyan-500/20 text-cyan-400':'text-slate-400 hover:text-slate-300'">Aperturas</button>
<button @click="abrirAnalytics('rebotes')" class="px-2.5 py-1 rounded text-xs font-semibold transition" :class="aqTab==='rebotes'?'bg-rose-500/20 text-rose-400':'text-slate-400 hover:text-slate-300'">Rebotes</button>
<button @click="abrirAnalytics('bajas')" class="px-2.5 py-1 rounded text-xs font-semibold transition" :class="aqTab==='bajas'?'bg-amber-500/20 text-amber-400':'text-slate-400 hover:text-slate-300'">Bajas</button>
<button @click="aq=false" class="text-slate-400 hover:text-slate-300 ml-2"><i data-lucide="x" class="w-4 h-4"></i></button>
</div>
</div>
<div class="p-5"><div x-show="aqLoading" class="flex items-center justify-center py-16"><span class="w-6 h-6 border-2 border-amber-400 border-t-transparent rounded-full animate-spin"></span></div>
<div x-show="!aqLoading && aqData && aqData.ultimos && aqData.ultimos.length > 0"><table class="w-full text-sm"><thead><tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider"><th class="px-2 py-1.5 text-left font-semibold">#</th><th class="px-2 py-1.5 text-left font-semibold" x-show="aqTab!=='bajas'">Fecha</th><th class="px-2 py-1.5 text-left font-semibold">Club / Email</th><th class="px-2 py-1.5 text-left font-semibold hidden sm:table-cell" x-show="aqTab==='envios'">Asunto</th><th class="px-2 py-1.5 text-left font-semibold hidden sm:table-cell" x-show="aqTab==='rebotes'">Motivo</th><th class="px-2 py-1.5 text-left font-semibold" x-show="aqTab==='bajas'">Estado</th><th class="px-2 py-1.5 text-left font-semibold">Acción</th></tr></thead><tbody><template x-for="(row, idx) in (aqData.ultimos || [])" :key="row.id||idx"><tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition text-xs" :class="aqTab==='envios'?'cursor-pointer':''" @click="aqTab==='envios' ? row._open = !row._open : null"><td class="px-2 py-1 text-slate-400" x-text="idx+1"></td><td class="px-2 py-1 text-slate-400" x-show="aqTab!=='bajas'" x-text="(row.fecha_envio||row.fecha_apertura||row.fecha_rebote||'').substring(0,16)"></td><td class="px-2 py-1"><span class="text-slate-300" x-text="row.club||row.nombre_club"></span><div class="text-slate-400" x-text="row.email"></div></td><td class="px-2 py-1 text-slate-400 hidden sm:table-cell truncate max-w-[200px]" x-show="aqTab==='envios'" x-text="row.asunto?.substring(0,50)"></td><td class="px-2 py-1 text-slate-400 hidden sm:table-cell" x-show="aqTab==='rebotes'" x-text="row.motivo"></td><td class="px-2 py-1" x-show="aqTab==='bajas'"><span class="px-1.5 py-0.5 rounded text-[10px] font-semibold" :class="row.estado_lead==='Opt-Out'?'bg-rose-500/20 text-rose-400':row.estado_lead==='Unsubscribed'?'bg-amber-500/20 text-amber-400':'bg-slate-700 text-slate-400'" x-text="row.estado_lead"></span></td><td class="px-2 py-1 text-right"><button x-show="row.email||row.club||row.nombre_club||row.lead_id" @click.stop="aqAccionLead(row)" title="Abrir ficha del lead (o buscar el club en el Pipeline)" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold transition bg-slate-700/40 text-slate-300 border border-slate-600 hover:bg-amber-500/20 hover:text-amber-400 hover:border-amber-500/40"><i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i> Abrir</button></td></tr><tr x-show="aqTab==='envios' && row._open" class="border-b border-slate-800/50 bg-slate-800/30"><td colspan="6" class="px-3 py-3"><div class="bg-white rounded-lg p-3 text-slate-900 text-xs max-h-64 overflow-auto" style="white-space:pre-wrap;word-break:break-word" x-html="row.cuerpo_mensaje"></div></td></tr></template></tbody></table></div>
<div x-show="!aqLoading && aqData && (!aqData.ultimos || aqData.ultimos.length === 0)" class="text-slate-400 text-sm py-10 text-center">Sin registros</div></div>
</div></div>
</template>

<!-- ═══════════════ MODAL SMTP ═══════════ -->
<div x-show="sm" @click.self="sm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition><div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md m-4"><div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between"><h5 class="text-sm font-bold text-slate-200" x-text="se?'Editar Cuenta SMTP':'Nueva Cuenta SMTP'"></h5><button @click="sm=false" class="text-slate-400 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div><div class="p-5 space-y-3"><div class="grid grid-cols-2 gap-2"><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Nombre Emisor</label><input type="text" x-model="sf.nombre_emisor" placeholder="Ej: Equipo Comercial" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Cargo Emisor</label><input type="text" x-model="sf.cargo_emisor" placeholder="Ej: Responsable de Ventas" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Email Emisor</label><input type="email" x-model="sf.email" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div class="grid grid-cols-4 gap-2"><div class="col-span-3"><label class="text-[10px] text-slate-400 uppercase tracking-wider">Host</label><input type="text" x-model="sf.host" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Puerto</label><input type="number" x-model="sf.puerto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Usuario</label><input type="text" x-model="sf.usuario" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Password</label><div class="flex items-center gap-1.5"><input type="password" x-model="sf.password" data-smtp-password-input placeholder="Dejar vacio para no cambiar" class="flex-1 min-w-0 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><button type="button" data-smtp-toggle title="Mostrar contraseña" class="shrink-0 px-2.5 py-1.5 rounded-lg bg-slate-800 border border-slate-700 text-slate-400 hover:text-slate-200 hover:border-slate-600 transition flex items-center"><i data-lucide="eye" data-eye class="w-4 h-4"></i><i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i></button></div></div><div class="grid grid-cols-2 gap-2"><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Seguridad</label><select x-model="sf.seguridad" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="ssl">SSL</option><option value="tls">TLS</option></select></div><div><label class="text-[10px] text-slate-400 uppercase tracking-wider">Limite Diario</label><input type="number" x-model="sf.limite_diario" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div><div class="flex gap-2 pt-2"><button @click="sm=false" class="flex-1 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="saveSmtp()" class="flex-1 px-4 py-2 bg-blue-500/20 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/30 transition">Guardar</button></div></div></div></div>