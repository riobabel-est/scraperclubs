<!-- ANALYTICS TAB v3 — F3 Funnel 12 niveles + KPIs + A/B/C + Objetivo + Cuellos + Temporales + Snapshots -->
<div x-data="analyticsApp()" x-init="load()" class="space-y-6">

<!-- FILTROS -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap gap-3 items-center">
<div class="flex items-center gap-2"><label class="text-xs text-slate-400">Pipeline:</label><select x-model="fPipeline" @change="load()" class="bg-slate-800 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-200"><option value="">Todos</option><template x-for="p in pipelines" :key="p.id"><option :value="p.id" x-text="p.nombre"></option></template></select></div>
<div class="flex items-center gap-2"><label class="text-xs text-slate-400">Variante:</label><select x-model="fVariante" @change="load()" class="bg-slate-800 border border-slate-700 rounded px-3 py-1.5 text-xs text-slate-200"><option value="">Todas</option><option value="A">A</option><option value="B">B</option><option value="C">C</option></select></div>
<div class="flex items-center gap-2"><label class="text-xs text-slate-400">Excluir TEST:</label><input type="checkbox" x-model="fExcluirTest" @change="load()" class="accent-amber-500"></div>
<button @click="load()" class="px-3 py-1.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded text-xs font-bold hover:bg-amber-500/30">Actualizar</button>
</div>

<!-- F3.3 — KPIs ECONÓMICOS -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
<template x-for="card in kpiCards" :key="card.label">
<div class="bg-slate-900 border rounded-xl p-4" :class="card.border">
<div class="text-xs text-slate-400 uppercase tracking-wider" x-text="card.label"></div>
<div class="text-xl font-bold mt-1" :class="card.color" x-text="card.value"></div>
<div class="text-[10px] text-slate-500 mt-1" x-text="card.sub"></div>
</div></template>
</div>

<!-- F3.7 — KPIs EFICIENCIA TEMPORAL -->
<div x-show="tiempos.length > 0" class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-3">⏱ Eficiencia Temporal</h3>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
<template x-for="t in tiempos" :key="t.label">
<div class="bg-slate-800 rounded-lg p-3">
<div class="text-xs text-slate-400" x-text="t.label"></div>
<div class="text-lg font-bold mt-1" :class="t.dias === 'N/D' ? 'text-slate-500' : 'text-slate-200'" x-text="t.dias"></div>
<div class="text-[10px] text-slate-500 mt-0.5" x-text="t.extra || ''"></div>
</div></template>
</div>
</div>

<!-- F3.1 — FUNNEL 12 NIVELES -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-3">📊 Funnel de Conversión (12 niveles)</h3>
<div class="space-y-2">
<template x-for="(f, i) in funnel" :key="i">
<div class="flex items-center gap-3">
<span class="w-40 text-xs text-slate-400 text-right flex-shrink-0" x-text="f.nivel"></span>
<div class="flex-1 bg-slate-800 rounded-full h-5 overflow-hidden">
<div class="h-full bg-gradient-to-r from-amber-500 to-amber-400 rounded-full flex items-center justify-end px-2 transition-all duration-500" :style="'width:' + (funnelMax > 0 ? (f.cnt/funnelMax*100) : 0) + '%'">
<span class="text-xs text-slate-900 font-bold pr-1" x-text="f.cnt" x-show="f.cnt > 0"></span>
</div>
</div>
<span class="w-12 text-xs text-slate-500 text-right flex-shrink-0" x-show="f.pct !== undefined" x-text="f.pct+'%'"></span>
</div></template>
</div>
</div>

<!-- F3.4 — CUELLOS DE BOTELLA -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-3">🔍 ¿Dónde perdemos oportunidades?</h3>
<template x-if="conversiones && conversiones.length > 0">
<div class="space-y-2">
<template x-for="c in conversiones" :key="c.origen">
<div class="flex items-center gap-2 text-xs">
<span class="w-40 text-slate-400 text-right flex-shrink-0" x-text="c.origen + ' → ' + c.destino"></span>
<div class="flex-1 bg-slate-800 rounded-full h-4 overflow-hidden">
<div class="h-full rounded-full transition-all" :class="c.pct < 30 ? 'bg-rose-500' : c.pct < 60 ? 'bg-amber-500' : 'bg-emerald-500'" :style="'width:'+Math.max(c.pct,1)+'%'"></div>
</div>
<span class="w-14 text-right flex-shrink-0" :class="c.pct < 30 ? 'text-rose-400 font-bold' : 'text-slate-500'" x-text="c.pct+'%'"></span>
<span class="w-8 text-right text-slate-500 flex-shrink-0" x-text="c.perdida"></span>
</div></template>
</div></template>
<template x-if="!conversiones || conversiones.length === 0">
<div class="text-xs text-slate-500 py-4 text-center">Muestra insuficiente para calcular conversiones.</div>
</template>
<template x-if="cuelloPrincipal">
<div class="mt-3 p-3 bg-rose-500/10 border border-rose-500/20 rounded-lg">
<span class="text-xs text-rose-400 font-bold">⚠️ Mayor pérdida: </span>
<span class="text-xs text-rose-300" x-text="cuelloPrincipal.origen + ' → ' + cuelloPrincipal.destino"></span>
<span class="text-xs text-rose-400 ml-2" x-text="'(solo ' + cuelloPrincipal.pct + '% convierte, se pierde ' + (100-cuelloPrincipal.pct) + '%)'"></span>
</div></template>
</div>

<!-- F3.5 — TABLA COMPARATIVA A/B/C -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-x-auto">
<div class="flex items-center justify-between mb-3">
<h3 class="text-sm font-bold text-slate-200">⚖ Comparativa A/B/C</h3>
<template x-if="abcGanadora">
<span class="text-xs px-2 py-0.5 rounded font-bold" :class="abcGanadora==='A'?'bg-amber-500/20 text-amber-400':abcGanadora==='B'?'bg-blue-500/20 text-blue-400':'bg-purple-500/20 text-purple-400'">
🏆 Variante <span x-text="abcGanadora"></span> lidera en conversión
</span></template>
</div>
<table class="w-full text-xs">
<thead><tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
<th class="px-2 py-1.5 text-left">Métrica</th>
<th class="px-2 py-1.5 text-right">A</th>
<th class="px-2 py-1.5 text-right">B</th>
<th class="px-2 py-1.5 text-right">C</th>
</tr></thead>
<tbody>
<template x-for="metrica in abcFilas" :key="metrica.label">
<tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
<td class="px-2 py-1.5 text-slate-400" x-text="metrica.label"></td>
<td class="px-2 py-1.5 text-right" :class="metrica.bestIndex===0 ? 'text-emerald-400 font-bold' : 'text-slate-300'" x-text="metrica.a"></td>
<td class="px-2 py-1.5 text-right" :class="metrica.bestIndex===1 ? 'text-emerald-400 font-bold' : 'text-slate-300'" x-text="metrica.b"></td>
<td class="px-2 py-1.5 text-right" :class="metrica.bestIndex===2 ? 'text-emerald-400 font-bold' : 'text-slate-300'" x-text="metrica.c"></td>
</tr></template>
</tbody></table>
<div x-show="abc.length === 0" class="text-center text-slate-500 py-8 text-xs">Sin datos A/B/C. Asigna leads a pipelines con variantes.</div>
</div>

<!-- F3.6 — OBJETIVO 20 CLUBES -->
<div class="bg-slate-900 border border-amber-500/20 rounded-xl p-4">
<h3 class="text-sm font-bold text-amber-400 mb-3">🎯 Objetivo: 20 Clubes</h3>
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-3">
<div><span class="text-xs text-slate-400 block">Ganados</span><span class="text-xl font-bold text-emerald-400" x-text="obj.ganados+' / 20'"></span></div>
<div><span class="text-xs text-slate-400 block">% Objetivo</span><span class="text-xl font-bold text-amber-400" x-text="obj.pct+'%'"></span></div>
<div><span class="text-xs text-slate-400 block">Tasa Cierre</span><span class="text-xl font-bold text-blue-400" x-text="obj.tasa_cierre+'%'"></span></div>
<div><span class="text-xs text-slate-400 block">Restantes</span><span class="text-xl font-bold text-rose-400" x-text="obj.restantes"></span></div>
<div><span class="text-xs text-slate-400 block">Contactados</span><span class="text-xl font-bold text-slate-200" x-text="obj.contactados || 0"></span></div>
</div>
<div class="bg-slate-800 rounded-lg p-3 text-xs text-slate-400">
<span>Contactos adicionales estimados: </span>
<span class="text-slate-200 font-bold" x-text="typeof obj.contactos_necesarios === 'number' ? obj.contactos_necesarios.toLocaleString() : obj.contactos_necesarios"></span>
<span class="text-slate-500 ml-2" x-show="typeof obj.contactos_necesarios !== 'number'">(datos insuficientes)</span>
</div>
<div x-show="obj.proyeccion" class="bg-slate-800 rounded-lg p-3 mt-2 text-xs text-slate-400">
<span>Proyección: </span><span class="text-slate-200 font-bold" x-text="obj.proyeccion + ' clubes'"></span>
</div>
<div x-show="!obj.proyeccion && obj.ganados > 0" class="bg-slate-800 rounded-lg p-3 mt-2 text-xs text-slate-500">Datos insuficientes para proyectar</div>
<div class="grid grid-cols-3 gap-2 mt-2 text-xs"><div><span class="text-slate-500">Pares:</span><span class="text-slate-300 ml-1" x-text="obj.pares"></span></div><div><span class="text-slate-500">Fact:</span><span class="text-slate-300 ml-1" x-text="obj.facturacion+'€'"></span></div><div><span class="text-slate-500">Margen:</span><span class="text-slate-300 ml-1" x-text="obj.margen+'€'"></span></div></div>
</div>

<!-- MOCKUP CAPACITY (F2.4) -->
<div class="bg-slate-900 border border-indigo-500/20 rounded-xl p-4">
<h3 class="text-sm font-bold text-indigo-400 mb-3">🎨 Capacidad Mockups</h3>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2">
<div><span class="text-xs text-slate-400 block">Solicitados semana</span><span class="text-xl font-bold text-indigo-400" id="mcSolicitados">0</span></div>
<div><span class="text-xs text-slate-400 block">En producción</span><span class="text-xl font-bold text-amber-400" id="mcProd">0</span></div>
<div><span class="text-xs text-slate-400 block">Enviados</span><span class="text-xl font-bold text-emerald-400" id="mcEnv">0</span></div>
<div><span class="text-xs text-slate-400 block">Restante</span><span class="text-xl font-bold text-slate-200" id="mcRest">100</span></div>
</div>
<div class="w-full bg-slate-800 rounded-full h-4 overflow-hidden">
<div id="mcBar" class="h-full rounded-full transition-all duration-500 bg-indigo-500" style="width:0%"></div>
</div>
<div class="flex justify-between text-xs mt-1">
<span class="text-slate-500" id="mcLabel">0% utilizado</span>
<span id="mcAlert" class="font-bold text-slate-400"></span>
</div>
</div>

<!-- SNAPSHOTS (F2.12) -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-2">📸 Snapshots de Funnel</h3>
<div class="flex items-center gap-3">
<button onclick="window.app.guardarSnapshot().then(()=>{setTimeout(()=>{document.getElementById('snapMsg').textContent=window.app.snapshotMsg},500)})" class="px-4 py-2 bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 rounded-lg text-sm font-bold hover:bg-indigo-500/30 transition flex items-center gap-1"><i data-lucide="camera" class="w-4 h-4"></i> Guardar Snapshot</button>
<span class="text-xs text-slate-400" id="snapMsg"></span>
</div>
<p class="text-xs text-slate-500 mt-2">Los snapshots guardan el estado actual del funnel para análisis histórico (tabla snapshots).</p>
</div>

</div>
<script>
(function(){
setTimeout(function(){
if(window.app && window.app.loadMockupCapacity){
window.app.loadMockupCapacity().then(function(){
try{
var c = window.app.mockupCap;
if(!c || !c.ok) return;
document.getElementById('mcSolicitados').textContent = c.solicitados_semana || 0;
document.getElementById('mcProd').textContent = c.en_produccion || 0;
document.getElementById('mcEnv').textContent = c.enviados || 0;
document.getElementById('mcRest').textContent = c.restante || 100;
document.getElementById('mcBar').style.width = (c.pct_utilizado || 0) + '%';
document.getElementById('mcLabel').textContent = (c.pct_utilizado || 0) + '% utilizado de ' + (c.capacidad_semanal || 100) + ' /semana';
if(c.alerta_95){document.getElementById('mcBar').className='h-full rounded-full transition-all duration-500 bg-rose-500';document.getElementById('mcAlert').className='font-bold text-rose-400';document.getElementById('mcAlert').textContent='⚠️ ALERTA 95%';}
else if(c.alerta_80){document.getElementById('mcBar').className='h-full rounded-full transition-all duration-500 bg-amber-500';document.getElementById('mcAlert').className='font-bold text-amber-400';document.getElementById('mcAlert').textContent='⚠️ Alerta 80%';}
else{document.getElementById('mcBar').className='h-full rounded-full transition-all duration-500 bg-indigo-500';document.getElementById('mcAlert').textContent='';}
}catch(e){console.error('mc:',e);}
});
}
},800);
})();
</script>
