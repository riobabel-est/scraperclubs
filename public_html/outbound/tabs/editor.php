<div class="grid grid-cols-1 lg:grid-cols-5 gap-4" style="min-height:70vh">

<!-- ═══════════ COLUMNA IZQ: FILTRO + LISTADO ═══════════ -->
<div class="lg:col-span-2 space-y-3">

<!-- 1. Estado del Lead (filtro) -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Estado del Lead</label>
<select x-model="ec" @change="onCategoriaChange()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1.5 focus:outline-none focus:border-amber-500/50 transition">
<option value="">Todas las plantillas</option>
<template x-for="e in estadosLead" :key="e"><option :value="e" x-text="e"></option></template>
</select>
</div>

<!-- LISTADO DE PLANTILLAS -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Plantillas</label>
<div class="text-xs text-slate-500 mt-1 mb-2" x-show="templatesFiltradas.length > 0">
<span x-text="templatesFiltradas.length"></span> plantillas
<span class="text-slate-600">·</span>
📧 <span x-text="templatesFiltradas.filter(t=>t.tipo!=='whatsapp').length" class="text-slate-400"></span>
<span class="text-slate-600">·</span>
💬 <span x-text="templatesFiltradas.filter(t=>t.tipo==='whatsapp').length" class="text-slate-400"></span>
</div>

<!-- Buscador -->
<div class="relative mt-2" x-show="templatesFiltradas.length > 0 || edSearch">
<input type="text" x-model="edSearch" placeholder="Buscar plantilla..." 
class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 pl-8">
<i data-lucide="search" class="w-4 h-4 text-slate-500 absolute left-2.5 top-2.5"></i>
</div>

<!-- Lista scrollable -->
<div class="max-h-[360px] overflow-y-auto space-y-1 -mx-1 px-1" x-show="templatesFiltradas.length > 0">
<template x-for="t in templatesFiltradas" :key="t.id">
<button @click="seleccionarPlantilla(t)"
class="w-full text-left px-3 py-2.5 rounded-lg transition-all border flex items-center gap-2 group"
:class="et == t.id
? 'bg-amber-500/10 text-amber-400 border-amber-500/30'
: 'bg-slate-800/40 border-transparent text-slate-400 hover:bg-slate-800 hover:text-slate-200 hover:border-slate-700'">
<!-- Icono plataforma -->
<span class="text-base flex-shrink-0" x-text="t.tipo==='whatsapp'?'💬':'📧'"></span>
<!-- Nombre + Pipeline -->
<div class="flex-1 min-w-0">
<div class="text-sm truncate" x-text="t.nombre" :class="et == t.id ? 'font-semibold' : ''"></div>
<div class="text-[10px] truncate mt-0.5" :class="et == t.id ? 'text-amber-500/70' : 'text-slate-600 group-hover:text-slate-500'" x-text="t.categoria || 'Sin pipeline'"></div>
</div>
<!-- Check -->
<span x-show="et == t.id" class="flex-shrink-0"><i data-lucide="check" class="w-3.5 h-3.5"></i></span>
</button>
</template>
</div>

<!-- Empty state -->
<div class="text-center py-10 text-xs text-slate-600" x-show="templatesFiltradas.length === 0">
<i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
<p>No hay plantillas aquí.</p>
<p class="mt-1">Crea una con el botón de abajo.</p>
</div>

<!-- Botón NUEVA al pie -->
<div class="mt-3 pt-3 border-t border-slate-800">
<button @click="nuevaPlantilla()" :disabled="!ec"
class="w-full py-2 bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-lg text-sm font-semibold hover:bg-blue-500/25 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
<i data-lucide="plus" class="w-4 h-4"></i> Nueva Plantilla
</button>
</div>
</div>

<!-- Botón ELIMINAR (solo visible si hay plantilla seleccionada) -->
<div x-show="et" x-transition>
<button @click="eliminarPlantilla()"
class="w-full py-2 bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded-lg text-sm font-semibold hover:bg-rose-500/20 transition flex items-center justify-center gap-1.5">
<i data-lucide="trash-2" class="w-4 h-4"></i> Eliminar Plantilla
</button>
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

<!-- Fila 1: Nombre + Pipeline + Plataforma -->
<div class="grid grid-cols-5 gap-2 mb-3">
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider">Nombre</label>
<input type="text" x-model="edNombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Pipeline</label>
<select x-model="ec" class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-500 cursor-not-allowed" disabled>
<template x-for="e in estadosLead" :key="e"><option :value="e" x-text="e"></option></template>
</select>
</div>
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider">Plataforma</label>
<div class="flex gap-1 bg-slate-800 rounded-lg p-1">
<button @click="edPlataforma='email'; edTipo = edTipo==='whatsapp'?'html':edTipo"
class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all flex items-center justify-center gap-1"
:class="edPlataforma === 'email' ? 'bg-blue-500/20 text-blue-400' : 'text-slate-500 hover:text-slate-300'">
📧 Email
</button>
<button @click="edPlataforma='whatsapp'"
class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all flex items-center justify-center gap-1"
:class="edPlataforma === 'whatsapp' ? 'bg-emerald-500/20 text-emerald-400' : 'text-slate-500 hover:text-slate-300'">
💬 WA
</button>
</div>
</div>
</div>

<!-- Sub-formato: solo Email -->
<div x-show="edPlataforma === 'email'" class="flex gap-1 bg-slate-800/40 rounded-lg p-1 mb-3">
<button @click="edTipo='html'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'html' ? 'bg-slate-700 text-slate-200' : 'text-slate-500 hover:text-slate-300'">
📄 HTML
</button>
<button @click="edTipo='texto_plano'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'texto_plano' ? 'bg-slate-700 text-slate-200' : 'text-slate-500 hover:text-slate-300'">
📝 Texto Plano
</button>
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
<p class="text-sm">Selecciona una plantilla del listado<br>o crea una nueva para empezar.</p>
</div>
</div>
</div>

<!-- ═══ BARRA PREVISUALIZACIÓN ═══ -->
<div class="mt-4 bg-slate-900 border border-slate-800 rounded-xl p-3 flex items-center gap-3" x-show="et || en" x-cloak>
<span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Previsualizacion</span>
<select x-model="previewClubId" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 flex-1 focus:outline-none focus:border-amber-500/50">
<option value="">Seleccionar club para previsualizar...</option>
<?php foreach($clubesList as $c):?>
<option value="<?=$c['id']?>"><?=escHtml($c['nombre_club'].($c['persona_contacto']?' ('.$c['persona_contacto'].')':''))?></option>
<?php endforeach;?>
</select>
<button @click="abrirPreview()" :disabled="!previewClubId" class="px-4 py-2 bg-purple-500/20 text-purple-400 border border-purple-500/30 rounded-lg text-sm font-semibold hover:bg-purple-500/30 transition disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1.5 whitespace-nowrap">
<i data-lucide="eye" class="w-4 h-4"></i> Vista Previa
</button>
</div>

<!-- ═══ MODAL PREVISUALIZACIÓN ═══ -->
<template x-if="pv">
<div @click.self="pv=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70" x-transition>
<div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto m-4">
<div class="sticky top-0 bg-slate-900 border-b border-slate-800 px-5 py-3 flex items-center justify-between rounded-t-2xl z-10">
<div class="flex items-center gap-2">
<i data-lucide="eye" class="w-4 h-4 text-purple-400"></i>
<h5 class="text-sm font-bold text-slate-200">Previsualizacion</h5>
<span class="px-2 py-0.5 rounded-full text-xs font-semibold"
:class="edPlataforma==='whatsapp'?'bg-emerald-500/20 text-emerald-400':'bg-blue-500/20 text-blue-400'"
x-text="edPlataforma==='whatsapp'?'💬 WhatsApp':'📧 Email'"></span>
</div>
<div class="flex items-center gap-2">
<select x-model="pvClubId" @change="cargarPreview()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-amber-500/50 max-w-[200px]">
<option value="">Cambiar club...</option>
<?php foreach($clubesList as $c):?>
<option value="<?=$c['id']?>"><?=escHtml($c['nombre_club'])?></option>
<?php endforeach;?>
</select>
<button @click="cargarPreview()" class="px-2 py-1.5 bg-purple-500/20 text-purple-400 border border-purple-500/30 rounded text-xs font-semibold hover:bg-purple-500/30 transition flex items-center gap-1">
<i data-lucide="refresh-cw" class="w-3 h-3"></i> Actualizar
</button>
<button @click="pv=false" class="text-slate-500 hover:text-slate-300"><i data-lucide="x" class="w-5 h-5"></i></button>
</div>
</div>
<div class="p-5">
<!-- Spinner -->
<div x-show="pvLoading" class="flex items-center justify-center py-20">
<span class="w-8 h-8 border-2 border-purple-400 border-t-transparent rounded-full animate-spin"></span>
</div>
<!-- Contenido -->
<div x-show="!pvLoading" id="pvContainer" class="border border-slate-700 rounded-xl p-4 bg-white min-h-[300px] text-slate-900 text-sm overflow-auto max-h-[60vh]" style="white-space:pre-wrap;word-break:break-word" x-html="pvContent"></div>
</div>
</div>
</div>
</template>
