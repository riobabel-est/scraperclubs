<div class="grid grid-cols-1 lg:grid-cols-5 gap-4" style="min-height:70vh">
<!-- ═══════════ COLUMNA IZQ: SELECTORES ═══════════ -->
<div class="lg:col-span-2 space-y-3">

<!-- 1. Estado del Lead -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">1. Estado del Lead</label>
<select x-model="ec" @change="onCategoriaChange()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1.5 focus:outline-none focus:border-amber-500/50 transition">
<option value="">Seleccionar estado</option>
<template x-for="e in estadosLead" :key="e"><option :value="e" x-text="e"></option></template>
</select>
</div>

<!-- 2. Plataforma (pills toggle) + sub-formato -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4" x-show="ec" x-transition>
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">2. Plataforma</label>
<div class="flex mt-1.5 gap-1 bg-slate-800 rounded-lg p-1">
<button @click="edPlataforma='email'; onCategoriaChange()"
class="flex-1 py-2 px-3 rounded-md text-sm font-semibold transition-all flex items-center justify-center gap-1.5"
:class="edPlataforma === 'email' ? 'bg-blue-500/20 text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-300'">
<i data-lucide="mail" class="w-4 h-4"></i> Email
</button>
<button @click="edPlataforma='whatsapp'; onCategoriaChange()"
class="flex-1 py-2 px-3 rounded-md text-sm font-semibold transition-all flex items-center justify-center gap-1.5"
:class="edPlataforma === 'whatsapp' ? 'bg-emerald-500/20 text-emerald-400 shadow-sm' : 'text-slate-500 hover:text-slate-300'">
<i data-lucide="message-circle" class="w-4 h-4"></i> WhatsApp
</button>
</div>
<!-- Sub-formato: solo visible en Email -->
<div x-show="edPlataforma === 'email'" class="flex mt-2 gap-1 bg-slate-800/50 rounded-lg p-1">
<button @click="edTipo='html'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'html' ? 'bg-slate-700 text-slate-200' : 'text-slate-500 hover:text-slate-300'">
📄 HTML
</button>
<button @click="edTipo='texto_plano'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'texto_plano' ? 'bg-slate-700 text-slate-200' : 'text-slate-500 hover:text-slate-300'">
📝 Texto Plano
</button>
</div>
</div>

<!-- 3. Seleccionar plantilla -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4" x-show="ec" x-transition>
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">3. Plantilla</label>
<select x-model="et" @change="onTemplateChange()" :disabled="!ec" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1.5 focus:outline-none focus:border-amber-500/50 disabled:opacity-40 disabled:cursor-not-allowed">
<option value="">Seleccionar plantilla</option>
<template x-for="t in templatesFiltradas" :key="t.id">
<option :value="t.id">
<span x-text="(t.tipo==='whatsapp'?'💬 ':'📧 ')+t.nombre"></span>
</option>
</template>
</select>
<div class="flex gap-2 mt-2">
<button @click="nuevaPlantilla()" :disabled="!ec"
class="px-3 py-1.5 bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-lg text-sm font-semibold hover:bg-blue-500/25 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1">
<i data-lucide="plus" class="w-3.5 h-3.5"></i> Nueva
</button>
<button @click="eliminarPlantilla()" :disabled="!et"
class="px-3 py-1.5 bg-rose-500/15 text-rose-400 border border-rose-500/30 rounded-lg text-sm font-semibold hover:bg-rose-500/25 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1">
<i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Eliminar
</button>
</div>

<!-- Listado de plantillas -->
<div class="mt-3 max-h-48 overflow-y-auto space-y-1" x-show="templatesFiltradas.length > 0">
<div class="text-xs text-slate-500 mb-1.5 flex items-center gap-3">
<span>📧 <span x-text="templatesFiltradas.filter(t=>t.tipo!=='whatsapp').length" class="text-slate-400"></span></span>
<span>💬 <span x-text="templatesFiltradas.filter(t=>t.tipo==='whatsapp').length" class="text-slate-400"></span></span>
</div>
<template x-for="t in templatesFiltradas" :key="t.id">
<button @click="et = t.id; onTemplateChange()"
class="w-full text-left px-3 py-2 rounded-lg text-sm transition-all border flex items-center gap-2"
:class="et == t.id
? 'bg-amber-500/10 text-amber-400 border-amber-500/30 font-semibold'
: 'bg-slate-800/50 border-transparent text-slate-400 hover:bg-slate-800 hover:text-slate-200 hover:border-slate-700'">
<span x-text="t.tipo==='whatsapp'?'💬':'📧'" class="text-base"></span>
<span x-text="t.nombre" class="flex-1 truncate"></span>
<span x-show="et == t.id" class="text-amber-400"><i data-lucide="check" class="w-3.5 h-3.5"></i></span>
</button>
</template>
</div>
<div class="mt-3 text-center text-xs text-slate-600 py-4" x-show="ec && templatesFiltradas.length === 0">
No hay plantillas para este estado.<br>Crea una nueva con el botón <span class="text-blue-400">+ Nueva</span>
</div>
</div>
</div>

<!-- ═══════════ COLUMNA DER: EDITOR ═══════════ -->
<div class="lg:col-span-3 space-y-3" x-show="et || en" x-cloak x-transition>
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<div class="flex items-center justify-between mb-3">
<div class="flex items-center gap-2">
<span class="text-sm font-bold text-amber-400 uppercase tracking-wider">Editor</span>
<span class="px-2 py-0.5 rounded-full text-xs font-semibold"
:class="edPlataforma==='whatsapp'?'bg-emerald-500/20 text-emerald-400':'bg-blue-500/20 text-blue-400'"
x-text="edPlataforma==='whatsapp'?'💬 WhatsApp':'📧 Email'"></span>
</div>
<span class="text-xs text-slate-500" x-text="edNombre||'(nueva)'"></span>
</div>

<!-- Nombre + Estado + Contador WA -->
<div class="grid grid-cols-4 gap-2 mb-3">
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider">Nombre</label>
<input type="text" x-model="edNombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Estado</label>
<input type="text" x-model="ec" disabled class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-500 cursor-not-allowed">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Formato</label>
<div class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-400 cursor-not-allowed flex items-center gap-1"
x-text="edPlataforma==='whatsapp'?'💬 WhatsApp':'📧 '+(edTipo==='texto_plano'?'Texto Plano':'HTML')"></div>
</div>
</div>

<!-- ═══ A/B TESTING (solo Email) ═══ -->
<div x-show="edPlataforma==='email'" class="mb-3 bg-slate-800/30 rounded-lg p-3 border border-slate-700/50">
<div class="flex items-center justify-between mb-2">
<label class="text-xs text-slate-400 font-semibold">🧪 Test A/B de Asunto</label>
<button @click="edTestAb = edTestAb ? 0 : 1" class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="edTestAb ? 'bg-purple-500' : 'bg-slate-600'">
<span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="edTestAb ? 'translate-x-6' : 'translate-x-1'"></span>
</button>
</div>
<div x-show="edTestAb" class="space-y-2">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto A <span class="text-slate-600">(50%)</span></label>
<input type="text" x-model="edAsunto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto B <span class="text-purple-400">(50%)</span></label>
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
<div class="flex items-center justify-between mb-1">
<label class="text-xs text-slate-400 uppercase tracking-wider">Mensaje</label>
<span x-show="edPlataforma==='whatsapp'" class="text-xs" :class="edCuerpo.length > 4000 ? 'text-rose-400' : edCuerpo.length > 3500 ? 'text-amber-400' : 'text-emerald-400'" x-text="edCuerpo.length+' / 4096'"></span>
</div>
<!-- Placeholder tags contextuales -->
<div class="flex gap-1 mb-1.5 flex-wrap">
<template x-for="tag in ['{{CLUB}}','{{CONTACTO}}','{{FEDERACION}}','{{ANIO}}']">
<button @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-xs text-amber-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
<template x-for="tag in ['{{SENDER_NAME}}','{{SENDER_TITLE}}','{{SENDER_EMAIL}}']" x-show="edPlataforma==='email'">
<button @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-purple-500/30 rounded text-xs text-purple-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
</div>
<textarea x-model="edCuerpo" @input="onCuerpoInput()" :maxlength="edPlataforma==='whatsapp'?4096:null"
class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50 h-48 resize-y"></textarea>
</div>

<div class="flex gap-2 mt-3">
<button @click="guardarPlantilla()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1.5">
<i data-lucide="check" class="w-4 h-4"></i> Guardar
</button>
</div>
</div>
</div>

<!-- PLACEHOLDER VACÍO -->
<div class="lg:col-span-3 flex items-center justify-center" x-show="!et && !en" x-cloak>
<div class="text-center text-slate-600">
<i data-lucide="file-text" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
<p class="text-sm">Selecciona estado y plataforma<br>para crear o editar plantillas.</p>
</div>
</div>
</div>

<!-- ═══ PREVISUALIZACIÓN ═══ -->
<div class="mt-4 bg-slate-900 border border-slate-800 rounded-xl p-4" x-show="et || en" x-cloak x-transition>
<div class="flex items-center gap-3 mb-3">
<span class="text-sm font-bold text-amber-400 uppercase tracking-wider">Previsualizacion</span>
<span x-show="edPlataforma==='whatsapp'" class="text-xs text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full">WhatsApp</span>
</div>
<div class="flex gap-2 mb-3 flex-wrap">
<select x-model="previewClubId" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 w-64 focus:outline-none focus:border-amber-500/50">
<option value="">Buscar club...</option>
<?php foreach($clubesList as $c):?>
<option value="<?=$c['id']?>"><?=escHtml($c['nombre_club'].($c['persona_contacto']?' ('.$c['persona_contacto'].')':''))?></option>
<?php endforeach;?>
</select>
<button @click="previewTpl()" :disabled="!previewClubId" class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-300 hover:bg-slate-700 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1.5">
<i data-lucide="eye" class="w-4 h-4"></i> Previsualizar
</button>
</div>
<div id="previewContainer" class="border border-slate-700 rounded-xl p-4 bg-white min-h-[200px] text-slate-900 text-sm overflow-auto max-h-96" style="white-space:pre-wrap;word-break:break-word"></div>
</div>