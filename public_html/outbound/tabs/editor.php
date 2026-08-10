<div class="grid grid-cols-1 lg:grid-cols-5 gap-4" style="min-height:70vh">
<div class="lg:col-span-2 space-y-3">
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">1. Estado del Lead</label>
<select x-model="ec" @change="onCategoriaChange()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1 focus:outline-none focus:border-amber-500/50">
<option value="">-- Seleccionar estado del lead --</option>
<template x-for="e in estadosLead" :key="e"><option :value="e" x-text="e"></option></template>
</select>
</div>
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">2. Seleccionar plantilla</label>
<select x-model="et" @change="onTemplateChange()" :disabled="!ec" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1 focus:outline-none focus:border-amber-500/50 disabled:opacity-40 disabled:cursor-not-allowed">
<option value="">-- Primero selecciona estado arriba --</option>
<template x-for="t in templatesFiltradas" :key="t.id"><option :value="t.id" x-text="t.nombre"></option></template>
</select>
<div class="flex gap-2 mt-2">
<button @click="nuevaPlantilla()" :disabled="!ec" class="px-3 py-1.5 bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-lg text-sm font-semibold hover:bg-blue-500/25 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1"><i data-lucide="plus" class="w-3.5 h-3.5"></i> Nueva</button>
<button @click="eliminarPlantilla()" :disabled="!et" class="px-3 py-1.5 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-sm font-semibold hover:bg-rose-500/25 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Eliminar</button>
</div>
</div>
</div>
<div class="lg:col-span-3 space-y-3" x-show="et || en" x-cloak>
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<div class="flex items-center justify-between mb-3">
<span class="text-sm font-bold text-amber-400 uppercase tracking-wider">3. Edicion</span>
<span class="text-xs text-slate-500" x-text="'Plantilla activa: '+(edNombre||'(nueva)')"></span>
</div>
<div class="grid grid-cols-3 gap-2 mb-3">
<div><label class="text-xs text-slate-400 uppercase tracking-wider">Nombre</label><input type="text" x-model="edNombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider">Categoria</label><input type="text" x-model="ec" disabled class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-500"></div>
<div><label class="text-xs text-slate-400 uppercase tracking-wider">Formato</label><select x-model="edTipo" @change="onTipoChange()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"><option value="html">HTML</option><option value="texto_plano">Texto Plano</option><option value="whatsapp">WhatsApp</option></select></div>
</div>

<!-- ═══ A/B TESTING TOGGLE + ASUNTO B ═══ -->
<div x-show="edTipo!=='whatsapp'" class="mb-3 bg-slate-800/30 rounded-lg p-3 border border-slate-700/50">
<div class="flex items-center justify-between mb-2">
<label class="text-xs text-slate-400 font-semibold">🧪 Test A/B de Asunto</label>
<button @click="edTestAb = edTestAb ? 0 : 1" class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="edTestAb ? 'bg-purple-500' : 'bg-slate-600'">
<span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="edTestAb ? 'translate-x-6' : 'translate-x-1'"></span>
</button>
</div>
<div x-show="edTestAb" class="space-y-2">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto A <span class="text-slate-600">(50% de los envios)</span></label>
<input type="text" x-model="edAsunto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto B <span class="text-purple-400">(50% de los envios)</span></label>
<input type="text" x-model="edAsuntoB" class="w-full bg-slate-800 border border-purple-500/30 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-purple-500/50">
</div>
</div>
<div x-show="!edTestAb">
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto</label>
<input type="text" x-model="edAsunto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
</div>

<!-- ═══ CUERPO ═══ -->
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Cuerpo <span x-show="edTipo==='whatsapp'" class="text-amber-400" x-text="'('+edCuerpo.length+'/'+4096+')'"></span></label>
<div class="flex gap-1 mb-1 flex-wrap">
<template x-for="tag in ['{{CLUB}}','{{CONTACTO}}','{{FEDERACION}}','{{ANIO}}']"><button @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-xs text-amber-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button></template>
<template x-for="tag in ['{{SENDER_NAME}}','{{SENDER_TITLE}}','{{SENDER_EMAIL}}']"><button @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-purple-500/30 rounded text-xs text-purple-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button></template>
</div>
<textarea x-model="edCuerpo" @input="onCuerpoInput()" :maxlength="edTipo==='whatsapp'?4096:null" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50 h-48 resize-y"></textarea>
</div>
<div class="flex gap-2 mt-3">
<button @click="guardarPlantilla()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1"><i data-lucide="check" class="w-4 h-4"></i> Guardar</button>
</div>
</div>
</div>
<div class="lg:col-span-3 flex items-center justify-center" x-show="!et && !en" x-cloak>
<div class="text-center text-slate-600"><i data-lucide="file-text" class="w-12 h-12 mx-auto mb-3 opacity-30"></i><p class="text-sm">Selecciona una categoria y plantilla<br>para ver o crear contenido.</p></div>
</div>
</div>
<div class="mt-4 bg-slate-900 border border-slate-800 rounded-xl p-4" x-show="et || en" x-cloak>
<div class="flex items-center gap-3 mb-3"><span class="text-sm font-bold text-amber-400 uppercase tracking-wider">4. Previsualizacion</span></div>
<div class="flex gap-2 mb-3 flex-wrap">
<select x-model="previewClubId" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 w-64 focus:outline-none focus:border-amber-500/50"><option value="">Buscar club o contacto...</option><?php foreach($clubesList as $c):?><option value="<?=$c['id']?>"><?=escHtml($c['nombre_club'].($c['persona_contacto']?' ('.$c['persona_contacto'].')':''))?></option><?php endforeach;?></select>
<button @click="previewTpl()" :disabled="!previewClubId" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-300 hover:bg-slate-700 transition disabled:opacity-30 disabled:cursor-not-allowed">Previsualizar</button>
</div>
<div id="previewContainer" class="border border-slate-700 rounded-xl p-4 bg-white min-h-[200px] text-slate-900 text-sm overflow-auto max-h-96" style="white-space:pre-wrap;word-break:break-word"></div>
</div>