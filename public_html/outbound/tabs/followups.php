<!-- FOLLOW-UPS TAB v4 — F4.1 No respondedores + F4.2 Sin proxima accion + F4.3 KPIs Operativos -->
<div x-data="followupsApp()" x-init="load()" class="space-y-6">

<!-- F4.3 — KPIs OPERATIVOS (scorecards) -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
<div class="bg-slate-900 border border-rose-500/20 rounded-xl p-4">
<div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">No Respondedores</span><i data-lucide="mail-question" class="w-4 h-4 text-slate-400"></i></div>
<div class="text-2xl font-bold text-rose-400 mt-1" x-text="kpis.no_respondedores"></div>
<div class="text-[10px] text-slate-400 mt-1">Contactados sin respuesta</div>
</div>
<div class="bg-slate-900 border border-amber-500/20 rounded-xl p-4">
<div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Sin Prox. Accion</span><i data-lucide="alert-circle" class="w-4 h-4 text-slate-400"></i></div>
<div class="text-2xl font-bold text-amber-400 mt-1" x-text="kpis.sin_proxima_accion"></div>
<div class="text-[10px] text-slate-400 mt-1">Leads operativos sin proxima accion</div>
</div>
<div class="bg-slate-900 border border-indigo-500/20 rounded-xl p-4">
<div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Mockups Pend.</span><i data-lucide="palette" class="w-4 h-4 text-slate-400"></i></div>
<div class="text-2xl font-bold text-indigo-400 mt-1" x-text="kpis.mockups_pendientes"></div>
<div class="text-[10px] text-slate-400 mt-1">Solicitados / en produccion</div>
</div>
<div class="bg-slate-900 border border-emerald-500/20 rounded-xl p-4">
<div class="flex items-center justify-between"><span class="text-xs text-slate-400 uppercase tracking-wider">Presup. Pend.</span><i data-lucide="file-text" class="w-4 h-4 text-slate-400"></i></div>
<div class="text-2xl font-bold text-emerald-400 mt-1" x-text="kpis.presupuestos_pendientes"></div>
<div class="text-[10px] text-slate-400 mt-1">Presupuestos en estado creado</div>
</div>
</div>

<!-- F4.1 — NO RESPONDEDORES -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-3">📧 No Respondedores <span class="text-xs text-rose-400 ml-2" x-text="'(' + kpis.no_respondedores + ' leads)'"></span></h3>
<template x-if="noRespondedores.length > 0">
<div class="overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
<th class="px-2 py-1.5 text-left">Club</th><th class="px-2 py-1.5 text-left">Contacto</th><th class="px-2 py-1.5 text-right">Envios</th><th class="px-2 py-1.5 text-center">Apertura</th><th class="px-2 py-1.5 text-right">Dias</th><th class="px-2 py-1.5"></th>
</tr></thead><tbody>
<template x-for="l in noRespondedores" :key="l.id">
<tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
<td class="px-2 py-1.5 text-slate-300" x-text="l.nombre_club"></td>
<td class="px-2 py-1.5 text-slate-400" x-text="l.persona_contacto || '-'"></td>
<td class="px-2 py-1.5 text-right text-slate-400" x-text="l.num_envios"></td>
<td class="px-2 py-1.5 text-center"><span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold" :class="l.tiene_apertura ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-700 text-slate-400'" x-text="l.tiene_apertura ? 'Si' : 'No'"></span></td>
<td class="px-2 py-1.5 text-right" :class="l.dias_desde_envio > 7 ? 'text-rose-400 font-bold' : 'text-slate-400'" x-text="l.dias_desde_envio !== null ? l.dias_desde_envio : '-'"></td>
<td class="px-2 py-1.5 text-right"><button class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-400 hover:text-slate-200" @click="openFicha(l.id)">Ficha</button></td>
</tr></template></tbody></table></div></template>
<template x-if="noRespondedores.length === 0">
<div class="text-xs text-slate-400 py-8 text-center">No hay leads no respondedores. ¡Buen trabajo!</div></template>
</div>

<!-- F4.2 — SIN PROXIMA ACCION -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h3 class="text-sm font-bold text-slate-200 mb-3">⚠️ Sin Proxima Accion <span class="text-xs text-amber-400 ml-2" x-text="'(' + kpis.sin_proxima_accion + ' leads)'"></span></h3>
<template x-if="sinProximaAccion.length > 0">
<div class="overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-slate-400 uppercase tracking-wider border-b border-slate-800">
<th class="px-2 py-1.5 text-left">Club</th><th class="px-2 py-1.5 text-left">Estado</th><th class="px-2 py-1.5 text-right">Volumen</th><th class="px-2 py-1.5 text-right">Presup.</th><th class="px-2 py-1.5 text-right">Dias</th><th class="px-2 py-1.5"></th>
</tr></thead><tbody>
<template x-for="l in sinProximaAccion" :key="l.id">
<tr class="border-b border-slate-800/50 hover:bg-slate-800/30">
<td class="px-2 py-1.5 text-slate-300" x-text="l.nombre_club"></td>
<td class="px-2 py-1.5"><span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400" x-text="l.estado_lead"></span></td>
<td class="px-2 py-1.5 text-right text-slate-400" x-text="l.volumen_estimado || '-'"></td>
<td class="px-2 py-1.5 text-right" :class="l.presupuesto_importe ? 'text-emerald-400 font-bold' : 'text-slate-400'" x-text="l.presupuesto_importe ? l.presupuesto_importe + '\u20AC' : '-'"></td>
<td class="px-2 py-1.5 text-right" :class="l.dias_desde_contacto > 7 ? 'text-rose-400 font-bold' : 'text-slate-400'" x-text="l.dias_desde_contacto !== null ? l.dias_desde_contacto : '-'"></td>
<td class="px-2 py-1.5 text-right"><button class="px-2 py-0.5 bg-slate-800 border border-slate-700 rounded text-[10px] text-slate-400 hover:text-slate-200" @click="openFicha(l.id)">Ficha</button></td>
</tr></template></tbody></table></div></template>
<template x-if="sinProximaAccion.length === 0">
<div class="text-xs text-slate-400 py-8 text-center">Todos los leads operativos tienen proxima accion. ¡Excelente!</div></template>
</div>

</div>
<script>
function followupsApp(){
return {
noRespondedores:[], sinProximaAccion:[], kpis:{no_respondedores:0,sin_proxima_accion:0,mockups_pendientes:0,presupuestos_pendientes:0},
async load(){
try{
var r=await fetch('?action=get_followups');
var j=await r.json();
if(j.ok){
this.noRespondedores=j.no_respondedores||[];
this.sinProximaAccion=j.sin_proxima_accion||[];
this.kpis=j.kpis||{no_respondedores:0,sin_proxima_accion:0,mockups_pendientes:0,presupuestos_pendientes:0}
}
}catch(e){console.error('Followups:',e)}
setTimeout(function(){lucide.createIcons()},100)
},
openFicha(id){
if(window.app && window.app.openLead){
window.app.openLead(id)
}
}
}}
</script>
