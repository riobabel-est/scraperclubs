<div class="grid grid-cols-1 lg:grid-cols-5 gap-4" style="min-height:70vh">

<!-- ═══════════ COLUMNA IZQ: FILTRO + LISTADO ═══════════ -->
<div class="lg:col-span-2 space-y-3">

<!-- 1. Categoría de plantilla (filtro) -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Categoría de Plantilla</label>
<select x-model="ec" @change="onCategoriaChange()" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 mt-1.5 focus:outline-none focus:border-amber-500/50 transition">
<option value="">Todas las categorías</option>
<template x-for="c in categorias" :key="c"><option :value="c" x-text="c"></option></template>
</select>
</div>

<!-- LISTADO DE PLANTILLAS -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col">
<label class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Plantillas</label>
<div class="text-xs text-slate-400 mt-1 mb-2" x-show="templatesFiltradas.length > 0">
<span x-text="templatesFiltradas.length"></span> plantillas
<span class="text-slate-400">·</span>
📧 <span x-text="templatesFiltradas.filter(t=>t.tipo!=='whatsapp').length" class="text-slate-400"></span>
<span class="text-slate-400">·</span>
💬 <span x-text="templatesFiltradas.filter(t=>t.tipo==='whatsapp').length" class="text-slate-400"></span>
</div>

<!-- Buscador -->
<div class="relative mt-2" x-show="templatesFiltradas.length > 0 || edSearch">
<input type="text" x-model="edSearch" placeholder="Buscar plantilla..." 
class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 pl-8">
<i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-2.5 top-2.5"></i>
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
<div class="text-[10px] truncate mt-0.5" :class="et == t.id ? 'text-amber-500/70' : 'text-slate-400 group-hover:text-slate-400'" x-text="t.categoria || 'Sin pipeline'"></div>
</div>
<!-- Check -->
<span x-show="et == t.id" class="flex-shrink-0"><i data-lucide="check" class="w-3.5 h-3.5"></i></span>
</button>
</template>
</div>

<!-- Empty state -->
<div class="text-center py-10 text-xs text-slate-400" x-show="templatesFiltradas.length === 0">
<i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
<p>No hay plantillas aquí.</p>
<p class="mt-1">Crea una con el botón de abajo.</p>
</div>

<!-- Botón NUEVA al pie -->
<div class="mt-3 pt-3 border-t border-slate-800">
<button @click="nuevaPlantilla()"
class="w-full py-2 bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-lg text-sm font-semibold hover:bg-blue-500/25 transition flex items-center justify-center gap-1.5">
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
        <div class="flex items-center gap-2">
            <span class="text-xs text-slate-400" x-text="edNombre||'(nueva)'"></span>
            <button @click="pvLive = !pvLive; if(pvLive){ renderLivePreview(); }" type="button"
                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border flex items-center gap-1.5"
                :class="pvLive ? 'bg-purple-500/20 text-purple-400 border-purple-500/30' : 'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700'">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                <span x-text="pvLive ? 'Ocultar Vista Previa' : 'Vista Previa'"></span>
            </button>
        </div>
        </div>

<!-- Fila 1: Nombre + Pipeline + Plataforma -->
<div class="grid grid-cols-5 gap-2 mb-3">
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider">Nombre</label>
<input type="text" x-model="edNombre" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Categoría (Pipeline)</label>
<input type="text" x-model="edCategoria" list="categoriasList"
class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50"
placeholder="Sin pipeline (genérica)">
<datalist id="categoriasList">
<template x-for="c in categorias" :key="c"><option :value="c"></option></template>
</datalist>
</div>
<div class="col-span-2">
<label class="text-xs text-slate-400 uppercase tracking-wider">Plataforma</label>
<div class="flex gap-1 bg-slate-800 rounded-lg p-1">
<button @click="edPlataforma='email'; edTipo = edTipo==='whatsapp'?'html':edTipo"
class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all flex items-center justify-center gap-1"
:class="edPlataforma === 'email' ? 'bg-blue-500/20 text-blue-400' : 'text-slate-400 hover:text-slate-300'">
📧 Email
</button>
<button @click="edPlataforma='whatsapp'"
class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all flex items-center justify-center gap-1"
:class="edPlataforma === 'whatsapp' ? 'bg-emerald-500/20 text-emerald-400' : 'text-slate-400 hover:text-slate-300'">
💬 WA
</button>
</div>
</div>
</div>

<!-- Sub-formato: solo Email -->
<div x-show="edPlataforma === 'email'" class="flex gap-1 bg-slate-800/40 rounded-lg p-1 mb-3">
<button @click="edTipo='html'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'html' ? 'bg-slate-700 text-slate-200' : 'text-slate-400 hover:text-slate-300'">
📄 HTML
</button>
<button @click="edTipo='texto_plano'" class="flex-1 py-1.5 px-2 rounded text-xs font-semibold transition-all"
:class="edTipo === 'texto_plano' ? 'bg-slate-700 text-slate-200' : 'text-slate-400 hover:text-slate-300'">
📝 Texto Plano
</button>
</div>

<!-- ═══ A/B/C TESTING (solo Email) ═══ -->
<div x-show="edPlataforma==='email'" class="mb-3 bg-slate-800/30 rounded-lg p-3 border border-slate-700/50">
<div class="flex items-center justify-between mb-2">
<label class="text-xs text-slate-400 font-semibold">🧪 Test A/B/C de Asunto</label>
<button @click="edTestAb = edTestAb ? 0 : 1" class="relative inline-flex h-6 w-11 items-center rounded-full transition" :class="edTestAb ? 'bg-purple-500' : 'bg-slate-600'">
<span class="inline-block h-4 w-4 transform rounded-full bg-white transition" :class="edTestAb ? 'translate-x-6' : 'translate-x-1'"></span>
</button>
</div>
<div x-show="edTestAb" class="space-y-2">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto A <span class="text-slate-400">(33%)</span></label>
<input type="text" x-model="edAsunto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto B <span class="text-purple-400">(33%)</span></label>
<input type="text" x-model="edAsuntoB" class="w-full bg-slate-800 border border-purple-500/30 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-purple-500/50">
</div>
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto C <span class="text-cyan-400">(33%)</span></label>
<input type="text" x-model="edAsuntoC" class="w-full bg-slate-800 border border-cyan-500/30 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-cyan-500/50">
</div>
</div>
<div x-show="!edTestAb">
<label class="text-xs text-slate-400 uppercase tracking-wider">Asunto</label>
<input type="text" x-model="edAsunto" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50">
</div>
</div>

<!-- ═══ A/B/C CUERPOS (solo si hay test A/B) ═══ -->
<div x-show="edTestAb && edPlataforma === 'email'" class="mb-3 bg-slate-800/30 rounded-lg p-3 border border-purple-500/20">
<label class="text-xs text-purple-400 font-semibold flex items-center gap-1 mb-2">🧪 Cuerpos A/B/C <span class="text-slate-400 font-normal">(cada variante puede tener texto distinto)</span></label>
<div class="flex gap-1 mb-2 flex-wrap">
<template x-for="tag in ['{{CLUB}}','{{CONTACTO}}','{{FEDERACION}}','{{ANIO}}']">
<button type="button" @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-xs text-amber-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
<template x-for="tag in ['{{SENDER_NAME}}','{{SENDER_TITLE}}','{{SENDER_EMAIL}}']">
<button type="button" @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-purple-500/30 rounded text-xs text-purple-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
</div>
<div class="space-y-3">
<div>
<label class="text-xs text-slate-400 uppercase tracking-wider">Cuerpo A <span class="text-slate-400">(33%)</span></label>
<textarea id="edCuerpoA" x-model="edCuerpo" @input="onCuerpoInput()" @focus="edFocus='edCuerpoA'" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50 h-32 resize-y"></textarea>
</div>
<div>
<label class="text-xs text-purple-400 uppercase tracking-wider">Cuerpo B <span class="text-purple-400">(33%)</span></label>
<textarea id="edCuerpoB" x-model="edCuerpoB" @input="onCuerpoInput()" @focus="edFocus='edCuerpoB'" class="w-full bg-slate-800 border border-purple-500/30 rounded-lg px-3 py-2.5 text-sm text-slate-200 font-mono focus:outline-none focus:border-purple-500/50 h-32 resize-y"></textarea>
</div>
<div>
<label class="text-xs text-cyan-400 uppercase tracking-wider">Cuerpo C <span class="text-cyan-400">(33%)</span></label>
<textarea id="edCuerpoC" x-model="edCuerpoC" @input="onCuerpoInput()" @focus="edFocus='edCuerpoC'" class="w-full bg-slate-800 border border-cyan-500/30 rounded-lg px-3 py-2.5 text-sm text-slate-200 font-mono focus:outline-none focus:border-cyan-500/50 h-32 resize-y"></textarea>
</div>
</div>
</div>

<!-- ═══ CUERPO ÚNICO (sin A/B) ═══ -->
<div x-show="!edTestAb || edPlataforma !== 'email'">
<div class="flex items-center justify-between mb-1">
<label class="text-xs text-slate-400 uppercase tracking-wider">Mensaje</label>
<span x-show="edPlataforma==='whatsapp'" class="text-xs" :class="edCuerpo.length > 4000 ? 'text-rose-400' : edCuerpo.length > 3500 ? 'text-amber-400' : 'text-emerald-400'" x-text="edCuerpo.length+' / 4096'"></span>
</div>
<div class="flex gap-1 mb-1.5 flex-wrap">
<template x-for="tag in ['{{CLUB}}','{{CONTACTO}}','{{FEDERACION}}','{{ANIO}}']">
<button type="button" @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-xs text-amber-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
<template x-for="tag in ['{{SENDER_NAME}}','{{SENDER_TITLE}}','{{SENDER_EMAIL}}']" x-show="edPlataforma==='email'">
<button type="button" @click="insertTag(tag)" class="px-2 py-0.5 bg-slate-800 border border-purple-500/30 rounded text-xs text-purple-400 hover:bg-slate-700 transition font-mono" x-text="tag"></button>
</template>
</div>
<textarea id="edCuerpo" x-model="edCuerpo" @input="onCuerpoInput()" @focus="edFocus='edCuerpo'" :maxlength="edPlataforma==='whatsapp'?4096:null"
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
<div class="text-center text-slate-400">
<i data-lucide="file-text" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
<p class="text-sm">Selecciona una plantilla del listado<br>o crea una nueva para empezar.</p>
</div>
</div>
</div>

<!-- ═══ PANEL PREVISUALIZACIÓN EN VIVO ═══ -->
<div class="mt-4 bg-slate-900 border border-slate-800 rounded-xl p-4" x-show="pvLive && (et || en)" x-cloak x-transition>
<div class="flex items-center justify-between mb-3 flex-wrap gap-2">
<div class="flex items-center gap-2">
<i data-lucide="eye" class="w-4 h-4 text-purple-400"></i>
<span class="text-xs uppercase tracking-wider text-slate-300 font-semibold">Vista Previa</span>
<template x-if="edTestAb && edPlataforma === 'email'">
<span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-500/20 text-purple-400">🧪 A/B/C</span>
</template>
</div>
<div class="flex items-center gap-2 flex-wrap">
<select x-model="previewClubId" @change="renderLivePreview()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 max-w-[220px]">
<option value="">Sin club (placeholders vacíos)</option>
<?php foreach($clubesList as $c):?>
<option value="<?=$c['id']?>"><?=escHtml($c['nombre_club'])?></option>
<?php endforeach;?>
</select>
</div>
</div>

<!-- Vista única (sin A/B/C) -->
<div x-show="edTestAb !== 1 || edPlataforma !== 'email'" class="border border-slate-700 rounded-xl p-4 bg-white min-h-[300px] text-slate-900 text-sm overflow-auto max-h-[70vh]" style="white-space:pre-wrap;word-break:break-word" x-html="pvLiveA"></div>

<!-- Vista triple A/B/C (solo email con test activo) -->
<div x-show="edTestAb === 1 && edPlataforma === 'email'" class="grid md:grid-cols-3 gap-3">
<div class="flex flex-col">
<div class="text-center text-xs font-semibold text-amber-500 mb-2 py-1 bg-slate-800 rounded-t-lg">Variante A</div>
<div class="border border-slate-700 rounded-b-lg p-4 bg-white min-h-[300px] text-slate-900 text-sm overflow-auto max-h-[70vh] flex-1" style="white-space:pre-wrap;word-break:break-word" x-html="pvLiveA"></div>
</div>
<div class="flex flex-col">
<div class="text-center text-xs font-semibold text-purple-400 mb-2 py-1 bg-slate-800 rounded-t-lg">Variante B</div>
<div class="border border-slate-700 rounded-b-lg p-4 bg-white min-h-[300px] text-slate-900 text-sm overflow-auto max-h-[70vh] flex-1" style="white-space:pre-wrap;word-break:break-word" x-html="pvLiveB"></div>
</div>
<div class="flex flex-col">
<div class="text-center text-xs font-semibold text-cyan-400 mb-2 py-1 bg-slate-800 rounded-t-lg">Variante C</div>
<div class="border border-slate-700 rounded-b-lg p-4 bg-white min-h-[300px] text-slate-900 text-sm overflow-auto max-h-[70vh] flex-1" style="white-space:pre-wrap;word-break:break-word" x-html="pvLiveC"></div>
</div>
</div>
</div>

    <!-- ═══════════ CONFIGURADOR DE CAMPAÑAS (movido desde Configuración — reorganización 2026-08-26) ═══════════ -->
    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5" x-data="campanasConfig()" x-init="cargarTodo()">
        <div class="flex items-center gap-3 mb-4">
            <i data-lucide="target" class="w-5 h-5 text-amber-400"></i>
            <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Configurador de Campañas</h5>
            <span class="text-xs text-slate-400 ml-auto">Público + plantillas por campaña</span>
        </div>

        <div class="mb-4 space-y-2" x-show="campanas.length > 0">
            <template x-for="c in campanas" :key="c.id">
                <div class="bg-slate-800/40 border border-slate-700 rounded-lg p-3 flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-200" x-text="c.nombre"></div>
                        <div class="text-xs text-slate-400 truncate"
                            x-text="c.identificador + ' · ' + c.entorno + ' · ' + (c.segmento && c.segmento.todas ? 'Todas las federaciones' : ((c.segmento && c.segmento.federaciones.length) || 0) + ' federaciones') + ' · ' + ((c.plantillas_id && c.plantillas_id.length) || 0) + ' plantillas'"></div>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <button @click="editar(c)" class="px-2.5 py-1.5 bg-blue-500/10 text-blue-400 border border-blue-500/30 rounded-lg text-xs font-semibold hover:bg-blue-500/20 transition">Editar</button>
                        <button @click="eliminar(c)" class="px-2.5 py-1.5 bg-rose-500/10 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-semibold hover:bg-rose-500/20 transition">Eliminar</button>
                    </div>
                </div>
            </template>
        </div>

        <div class="space-y-3 pt-3 border-t border-slate-800">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Nombre</label>
                    <input type="text" x-model="form.nombre" placeholder="Ej: Campaña 3 — Clasificación"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Identificador</label>
                    <input type="text" x-model="form.identificador" placeholder="Ej: CAMPA3"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Entorno</label>
                    <select x-model="form.entorno" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                        <option value="test">TEST</option>
                        <option value="pilot">PILOT</option>
                        <option value="produccion">Producción</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Estado</label>
                    <select x-model="form.estado" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                        <option value="PILOT">PILOT</option>
                        <option value="ACTIVE">ACTIVE</option>
                        <option value="DRAFT">DRAFT</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Activa</label>
                    <select x-model="form.activo" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50">
                        <option :value="1">Sí</option>
                        <option :value="0">No</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-300 mb-1.5">Federaciones del público</label>
                <div class="flex items-center gap-2 mb-2">
                    <input type="checkbox" x-model="form.todas" id="todasFed" class="accent-amber-500">
                    <label for="todasFed" class="text-sm text-slate-300">Todas las federaciones</label>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5 max-h-36 overflow-y-auto" x-show="!form.todas">
                    <template x-for="fed in federaciones" :key="fed">
                        <label class="flex items-center gap-1.5 text-sm text-slate-300 bg-slate-800/40 border border-slate-700/60 rounded px-2 py-1.5 cursor-pointer">
                            <input type="checkbox" :value="fed" x-model="form.federaciones" class="accent-amber-500">
                            <span class="truncate" x-text="fed"></span>
                        </label>
                    </template>
                    <p x-show="federaciones.length === 0" class="text-xs text-slate-400 col-span-full">Sin federaciones en la BD</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-300 mb-1.5">Plantillas asignadas (banco central)</label>
                <div class="max-h-36 overflow-y-auto space-y-1 border border-slate-700/60 rounded-lg p-2 bg-slate-950/40">
                    <template x-for="t in plantillas" :key="t.id">
                        <label class="flex items-center gap-1.5 text-sm text-slate-300 cursor-pointer">
                            <input type="checkbox" :value="t.id" x-model="form.plantillas" class="accent-amber-500">
                            <span class="truncate" x-text="(t.categoria ? t.categoria + ' · ' : 'Sin pipeline · ') + t.nombre"></span>
                        </label>
                    </template>
                    <p x-show="plantillas.length === 0" class="text-xs text-slate-400">No hay plantillas en el banco</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-1">
                <button @click="nueva()" class="px-3 py-2 text-xs text-slate-400 hover:text-slate-200 transition">Limpiar</button>
                <button @click="guardar()" class="px-4 py-2 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition flex items-center gap-1.5">
                    <i data-lucide="save" class="w-4 h-4"></i> Guardar campaña
                </button>
            </div>
            <p x-show="msg" class="text-sm" :class="msgOk ? 'text-emerald-400' : 'text-rose-400'" x-text="msg"></p>
        </div>
    </div>

