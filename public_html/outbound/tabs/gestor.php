<div class="flex items-center justify-between mb-3 flex-wrap gap-2"><h5 class="text-sm font-semibold text-slate-300">Leads</h5><div class="flex gap-2"><button @click="openAddLead()" class="px-3 py-1.5 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-semibold hover:bg-emerald-500/25 transition flex items-center gap-1"><i data-lucide="user-plus" class="w-3.5 h-3.5"></i> Añadir Lead</button><button @click="window.dispatchEvent(new CustomEvent('abrir-importar'))" class="px-3 py-1.5 bg-sky-500/15 text-sky-400 border border-sky-500/30 rounded-lg text-xs font-semibold hover:bg-sky-500/25 transition flex items-center gap-1"><i data-lucide="file-up" class="w-3.5 h-3.5"></i> Importar CSV</button><button @click="scanDups()" class="px-3 py-1.5 bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-semibold hover:bg-amber-500/25 transition flex items-center gap-1"><i data-lucide="search" class="w-3.5 h-3.5"></i> Escanear Duplicados</button></div></div><div class="flex gap-2 mb-3 flex-wrap"><input type="text" x-model="gs" @input="loadGestor()" placeholder="Buscar club o email..." class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 w-48 focus:outline-none focus:border-amber-500/50"><select x-model="ge" @change="loadGestor()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="">Todos estados</option><?php foreach($estadosKanban as $es):?><option value="<?=escHtml($es)?>"><?=escHtml($es)?></option><?php endforeach;?></select><select x-model="gf" @change="loadGestor()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="">Todas federaciones</option><?php foreach($federaciones as $fed):?><option value="<?=escHtml($fed)?>"><?=escHtml($fed)?></option><?php endforeach;?></select><select x-model="gd" @change="gp=1;loadGestor()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="">Todos (incl. duplicados)</option><option value="1">Solo duplicados</option></select><select x-model="gpp" @change="gp=1;loadGestor()" class="bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50"><option value="10">10</option><option value="50" selected>50</option><option value="100">100</option><option value="250">250</option></select><span class="text-[10px] text-slate-400 self-center" x-text="gt"></span></div><div class="overflow-x-auto rounded-xl border border-slate-800"><table class="w-full text-xs"><thead><tr class="bg-slate-900 text-slate-400 text-[10px] uppercase tracking-wider"><th class="px-3 py-2 text-left cursor-pointer hover:text-slate-200" @click="gSort('nombre_club')">Club</th><th class="px-3 py-2 text-left cursor-pointer hover:text-slate-200 hidden md:table-cell" @click="gSort('email')">Email</th><th class="px-3 py-2 text-left hidden md:table-cell">Telefono</th><th class="px-3 py-2 text-left cursor-pointer hover:text-slate-200" @click="gSort('estado_lead')">Estado</th><th class="px-3 py-2 text-left hidden lg:table-cell">Federacion</th><th class="px-3 py-2 text-right">Accion</th></tr></thead><tbody id="gestorBody"><tr><td colspan="6" class="px-3 py-8 text-center text-slate-400">Cargando...</td></tr></tbody></table></div><div class="flex justify-center gap-1 mt-3" id="gestorP"></div>

<!-- Modal: Importar Leads CSV (acción de la entidad Leads, patrón CRM estándar) -->
<style>
  .imp-grid-map { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
  @media (min-width: 768px) { .imp-grid-map { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  .imp-grid-res { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
  @media (min-width: 768px) { .imp-grid-res { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
<div x-data="importadorCSV()" @abrir-importar.window="abrir()" x-show="abierto" x-cloak @click.self="cerrar()"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col overflow-hidden">
        <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-800">
            <i data-lucide="file-up" class="w-5 h-5 text-emerald-400"></i>
            <div class="flex-1">
                <div class="text-base font-semibold text-slate-100">Importar Leads (CSV)</div>
                <div class="text-xs text-slate-400">Sube el CSV, mapea las columnas y revisa la vista previa. Los leads se crean en estado <b>01 Sin Contactar</b>.</div>
            </div>
            <button @click="cerrar()" class="px-2.5 py-1 bg-slate-800 text-slate-400 border border-slate-700 rounded-lg text-sm hover:text-slate-100 transition">✕</button>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <!-- 1. Archivo + delimitador -->
            <div class="flex items-center gap-3 flex-wrap">
                <label class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm font-semibold text-slate-300 hover:text-slate-100 hover:border-slate-600 transition cursor-pointer">
                    <i data-lucide="upload" class="w-4 h-4"></i> Seleccionar CSV
                    <input type="file" class="hidden" accept=".csv,.txt" @change="cargarArchivo($event)">
                </label>
                <span class="text-sm text-slate-400 truncate max-w-[220px]" x-text="archivo ? archivo.name : 'Sin archivo seleccionado'"></span>
                <select x-model="delimitador" class="bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-sm text-slate-200 focus:outline-none focus:border-emerald-500/50">
                    <option value="auto">Delimitador: auto</option>
                    <option value=",">Coma (,)</option>
                    <option value=";">Punto y coma (;)</option>
                    <option value="|">Pipe (|)</option>
                    <option value="&#9;">Tabulador</option>
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" x-model="conCabecera" class="w-4 h-4 accent-emerald-500"> 1ª fila es cabecera
                </label>
            </div>

            <!-- 2. Mapeo de columnas -->
            <template x-if="headers.length">
                <div class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-4">
                    <div class="text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Mapeo de columnas</div>
                    <div class="imp-grid-map">
                        <template x-for="(h, i) in headers" :key="'col' + i">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1 truncate" x-text="'Col ' + (i + 1) + ': ' + (h || '(vacía)')"></label>
                                <select x-model="mapa[i]" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-2 py-1.5 text-sm text-slate-200 focus:outline-none focus:border-emerald-500/50">
                                    <option value="">— Ignorar —</option>
                                    <template x-for="c in campos" :key="c">
                                        <option :value="c" x-text="'→ ' + camposLabel[c]"></option>
                                    </template>
                                </select>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- 3. Vista previa -->
            <template x-if="filasPreview.length">
                <div class="overflow-x-auto bg-slate-800/30 border border-slate-700/50 rounded-lg">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-800/50 text-slate-300 uppercase tracking-wider">
                                <template x-for="(h, i) in headers" :key="'th' + i">
                                    <th class="px-2 py-1.5 text-left font-semibold border-b border-slate-700" x-text="(mapa[i] ? '✓ ' : '') + (h || ('Col ' + (i + 1)))"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(f, fi) in filasPreview" :key="'row' + fi">
                                <tr>
                                    <template x-for="(c, ci) in f" :key="'cell' + ci">
                                        <td class="px-2 py-1.5 text-slate-400 border-b border-slate-800" x-text="c"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="px-2 py-1.5 text-[11px] text-slate-500" x-text="'Vista previa: primeras ' + filasPreview.length + ' filas del archivo'"></div>
                </div>
            </template>

            <!-- 4. Opciones -->
            <div class="flex items-center gap-4 flex-wrap">
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" x-model="validarMx" class="w-4 h-4 accent-emerald-500"> Validar MX (rechaza dominios sin correo)
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" x-model="ignorarDuplicados" class="w-4 h-4 accent-emerald-500"> Ignorar emails ya existentes
                </label>
            </div>

            <!-- 5. Importar -->
            <button @click="importar()" :disabled="importando || !archivo"
                    class="px-4 py-2 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-sm font-bold hover:bg-emerald-500/30 transition disabled:opacity-50 inline-flex items-center gap-1.5">
                <span x-show="!importando">🚀 Importar leads</span>
                <span x-show="importando" class="inline-flex items-center gap-1.5"><span class="w-3 h-3 border-2 border-emerald-400 border-t-transparent rounded-full animate-spin inline-block"></span> Importando…</span>
            </button>
            <div x-show="msg" class="text-sm rounded-lg px-3 py-2" :class="msgOk ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/10 text-rose-400 border border-rose-500/30'" x-text="msg"></div>

            <!-- 6. Resultado -->
            <template x-if="resultado">
                <div class="bg-slate-800/40 border border-slate-700/50 rounded-lg p-4 text-sm space-y-3">
                    <div class="font-bold text-slate-100">Resultado de la importación</div>
                    <div class="imp-grid-res">
                        <div class="bg-slate-900 rounded-lg p-2.5"><div class="text-[11px] uppercase text-slate-400">Total filas</div><b class="text-slate-100" x-text="resultado.total"></b></div>
                        <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-2.5"><div class="text-[11px] uppercase text-emerald-300">Importados</div><b class="text-emerald-400" x-text="resultado.importados"></b></div>
                        <div class="bg-amber-500/10 border border-amber-500/20 rounded-lg p-2.5"><div class="text-[11px] uppercase text-amber-300">Duplicados</div><b class="text-amber-400" x-text="resultado.duplicados"></b></div>
                        <div class="bg-rose-500/10 border border-rose-500/20 rounded-lg p-2.5"><div class="text-[11px] uppercase text-rose-300">Email inválido</div><b class="text-rose-400" x-text="resultado.email_invalidos"></b></div>
                        <div class="bg-rose-500/10 border border-rose-500/20 rounded-lg p-2.5"><div class="text-[11px] uppercase text-rose-300">Sin MX</div><b class="text-rose-400" x-text="resultado.mx_fallidos"></b></div>
                        <div class="bg-slate-900 rounded-lg p-2.5"><div class="text-[11px] uppercase text-slate-400">Errores</div><b class="text-slate-100" x-text="resultado.errores"></b></div>
                    </div>
                    <template x-if="resultado.errores_detalle && resultado.errores_detalle.length">
                        <div class="max-h-40 overflow-y-auto bg-slate-950/50 rounded-lg p-2 space-y-1">
                            <template x-for="(e, i) in resultado.errores_detalle" :key="'err' + i">
                                <div class="text-xs text-rose-300" x-text="'Fila ' + e.fila + ': ' + (e.email || '') + ' — ' + e.motivo"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>


