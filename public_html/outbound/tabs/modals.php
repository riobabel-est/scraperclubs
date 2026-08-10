<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL FICHA LEAD v3 — Layout intuitivo por filas, guardado condicional -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div x-show="lm" @click.self="lm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[85vh] overflow-y-auto m-4">
<div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl z-10">
<h5 class="text-base font-bold text-slate-200" x-text="'Ficha: '+(ld.nombre_club||'')"></h5>
<button @click="lm=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button>
</div>
<div class="p-5 space-y-4">

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
<div class="text-center">
<span class="text-slate-500 text-xs block">Envios</span>
<span class="text-sm font-bold text-blue-400" x-text="ld.total_envios||0">0</span>
</div>
<div class="text-slate-600">|</div>
<div class="text-center">
<span class="text-slate-500 text-xs block">Aperturas</span>
<span class="text-sm font-bold text-cyan-400" x-text="ld.total_aperturas||0">0</span>
</div>
</div>
</div>
</div>

<!-- ═══ FILA 5: Telefono Movil + WhatsApp + Estado Kanban ═══ -->
<div class="grid grid-cols-5 gap-4">
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Telefono Movil</label>
<input type="text" x-model="ld.telefono_movil" @input="markChanged()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
<div class="col-span-3">
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">WhatsApp</label>
<div class="bg-slate-800 border rounded-lg px-3 py-2.5 flex items-center gap-3"
:class="waLink ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-slate-700'">
<!-- Toggle tiene_whatsapp -->
<div class="flex items-center gap-2">
<input type="checkbox" x-model="ld.tiene_whatsapp" @change="markChanged()" class="w-4 h-4 accent-amber-500 rounded">
<label class="text-sm text-slate-300 cursor-pointer select-none">WhatsApp</label>
</div>
<!-- Botón WhatsApp (solo si waLink existe) -->
<a :href="waLink" x-show="waLink" target="_blank"
class="px-3 py-1.5 rounded-lg text-sm font-semibold transition flex items-center gap-1.5 ml-auto"
:class="waLink ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30' : 'bg-slate-700 text-slate-500 cursor-not-allowed'">
<i data-lucide="message-circle" class="w-4 h-4"></i> Enviar WA
</a>
<span x-show="!waLink" class="text-xs text-slate-500 ml-auto">Sin numero valido</span>
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

<!-- ═══ FILA 7: Observaciones ═══ -->
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Observaciones</label>
<div class="flex gap-2 mb-2">
<textarea x-model="ln" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 h-16 focus:outline-none focus:border-amber-500/50 resize-y" placeholder="Anadir nota..."></textarea>
<button @click="addNota()" class="px-3 py-2 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/25 transition self-end whitespace-nowrap flex items-center gap-1">
<i data-lucide="plus" class="w-4 h-4"></i> Nota
</button>
</div>
<pre x-text="ld.observaciones||'(sin notas)'" class="bg-slate-800/50 border border-slate-700 rounded-lg p-3 text-xs text-slate-400 max-h-32 overflow-y-auto whitespace-pre-wrap font-mono"></pre>
</div>

<!-- ═══ FILA 8: BOTON GUARDAR (condicional) ═══ -->
<div class="pt-2 border-t border-slate-800 flex justify-end">
<button @click="guardarFicha()" :disabled="!ldChanged"
class="px-5 py-2.5 rounded-lg text-sm font-bold transition flex items-center gap-2"
:class="ldChanged ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30 hover:bg-amber-500/30 cursor-pointer' : 'bg-slate-800 text-slate-600 border border-slate-700 cursor-not-allowed'">
<i data-lucide="save" class="w-4 h-4"></i>
<span x-show="ldChanged">GUARDAR CAMBIOS</span>
<span x-show="!ldChanged">Sin cambios</span>
</button>
</div>

</div></div></div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL ADD LEAD (con validacion MX y WhatsApp) -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div x-show="al" @click.self="al=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md m-4">
<div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between"><h5 class="text-sm font-bold text-slate-200">Añadir Nuevo Lead (con validacion MX)</h5><button @click="al=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div>
<div class="p-5 space-y-3">
<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Nombre Club *</label><input type="text" x-model="af.nombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Email *</label><input type="email" x-model="af.email" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Federacion</label><select x-model="af.federacion" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="">-- Sin federacion --</option><?php foreach($federacionesSelect as $fed):?><option value="<?=escHtml($fed)?>"><?=escHtml($fed)?></option><?php endforeach;?></select></div>
<div class="grid grid-cols-2 gap-2">
<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Telefono Movil</label><input type="text" x-model="af.movil" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><span x-show="afWaDetected" class="text-[9px] text-emerald-400 mt-1 inline-block">WhatsApp detectado</span></div>
<div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Telefono Fijo</label><input type="text" x-model="af.fijo" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div>
</div>
<div class="grid grid-cols-2 gap-2"><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Persona Contacto</label><input type="text" x-model="af.persona" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Cargo</label><input type="text" x-model="af.cargo" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div>
<div class="flex gap-2 pt-2"><button @click="al=false" class="flex-1 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="saveAddLead()" class="flex-1 px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/30 transition">Guardar Lead (valida MX)</button></div>
</div></div></div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL MERGE -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div x-show="mm" @click.self="mm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition><div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[85vh] overflow-y-auto m-4"><div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl"><h5 class="text-sm font-bold text-amber-400"><i data-lucide="git-compare" class="w-4 h-4 inline mr-1"></i> Fusionar Duplicados</h5><button @click="mm=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div><div class="p-5"><div class="grid md:grid-cols-2 gap-4 mb-4"><div class="bg-slate-800 border border-blue-500/30 rounded-xl p-3"><h6 class="text-[10px] font-bold text-blue-400 uppercase tracking-wider mb-2">Registro A (conservar) <span x-text="'#'+mk"></span></h6><div class="text-xs text-slate-400 space-y-1" x-html="mha"></div></div><div class="bg-slate-800 border border-rose-500/30 rounded-xl p-3"><h6 class="text-[10px] font-bold text-rose-400 uppercase tracking-wider mb-2">Registro B (eliminar) <span x-text="'#'+md"></span></h6><div class="text-xs text-slate-400 space-y-1" x-html="mhb"></div></div></div><div class="bg-slate-800 border border-slate-700 rounded-xl p-3 mb-3 space-y-2"><label class="text-[10px] text-slate-500 uppercase tracking-wider">Campos a conservar:</label><template x-for="f in mf" :key="f.name"><div class="flex items-center gap-3 text-xs"><span class="w-16 text-slate-500 text-[10px] uppercase" x-text="f.label"></span><label class="flex items-center gap-1 text-slate-300"><input type="radio" :name="'mg_'+f.name" value="A" :checked="f.cA" class="w-3 h-3 accent-amber-500"><span class="text-[10px]" x-text="'A: '+(f.vA||'vacio')"></span></label><label class="flex items-center gap-1 text-slate-300"><input type="radio" :name="'mg_'+f.name" value="B" :checked="!f.cA" class="w-3 h-3 accent-amber-500"><span class="text-[10px]" x-text="'B: '+(f.vB||'vacio')"></span></label></div></template></div><label class="flex items-center gap-2 text-xs text-slate-400 mb-3"><input type="checkbox" x-model="mn" class="w-3 h-3 accent-amber-500">Fusionar notas de seguimiento</label><div class="flex gap-2"><button @click="mm=false" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="doMerge()" class="px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/30 transition">Fusionar y Eliminar Duplicado</button></div></div></div></div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL SMTP -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div x-show="sm" @click.self="sm=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" x-cloak x-transition><div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md m-4"><div class="px-5 py-3 border-b border-slate-800 flex items-center justify-between"><h5 class="text-sm font-bold text-slate-200" x-text="se?'Editar Cuenta SMTP':'Nueva Cuenta SMTP'"></h5><button @click="sm=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button></div><div class="p-5 space-y-3"><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Email Emisor</label><input type="email" x-model="sf.email" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div class="grid grid-cols-4 gap-2"><div class="col-span-3"><label class="text-[10px] text-slate-500 uppercase tracking-wider">Host</label><input type="text" x-model="sf.host" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Puerto</label><input type="number" x-model="sf.puerto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Usuario</label><input type="text" x-model="sf.usuario" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Password</label><input type="password" x-model="sf.password" placeholder="Dejar vacio para no cambiar" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div><div class="grid grid-cols-2 gap-2"><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Seguridad</label><select x-model="sf.seguridad" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="ssl">SSL</option><option value="tls">TLS</option></select></div><div><label class="text-[10px] text-slate-500 uppercase tracking-wider">Limite Diario</label><input type="number" x-model="sf.limite_diario" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"></div></div><div class="flex gap-2 pt-2"><button @click="sm=false" class="flex-1 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-400 hover:bg-slate-700 transition">Cancelar</button><button @click="saveSmtp()" class="flex-1 px-4 py-2 bg-blue-500/20 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/30 transition">Guardar</button></div></div></div></div>