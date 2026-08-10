<?php
/**
 * tabs/analytics.php — Pestaña de Analytics v2
 * Pipeline funnel, timeline, A/B, aperturas por federación, interacciones antes del cierre.
 * Gráficos CSS nativos (sin Chart.js) — SiteGround compatible.
 */
?>
<div x-data="analyticsTab()" x-init="load()" class="space-y-4">

<!-- Funnel Pipeline -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-2"><i data-lucide="git-merge" class="w-4 h-4 text-amber-400"></i>Pipeline Funnel</h5>
<div class="space-y-2">
<template x-for="p in pipeline" :key="p.estado_lead">
<div class="flex items-center gap-2">
<div class="w-36 text-xs text-slate-400 truncate text-right" x-text="p.estado_lead"></div>
<div class="flex-1 bg-slate-800 rounded-full h-6 relative overflow-hidden">
<div class="h-full rounded-full transition-all duration-700 flex items-center pl-2 text-xs font-semibold"
:style="{ width: p.pct + '%' }"
:class="p.color" x-text="p.cnt + ' ('+p.pct+'%)'"></div>
</div>
</div>
</template>
</div>
</div>

<!-- Timeline 30 días -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-2"><i data-lucide="calendar" class="w-4 h-4 text-blue-400"></i>Timeline Envíos 30 Días</h5>
<div x-show="timeline.length > 0" class="h-40 flex items-end gap-1">
<template x-for="d in timeline" :key="d.dia">
<div class="flex-1 flex flex-col items-center group relative">
<div class="bg-blue-500/60 hover:bg-blue-500/80 rounded-t transition-all w-full" :style="{ height: d.hPct + '%' }" :title="d.dia + ': ' + d.envios + ' envíos'"></div>
<div class="bg-cyan-400/40 rounded-t w-2/3 -mt-px" :style="{ height: (d.aPct||0) + '%' }" :title="d.dia + ': ' + (d.aperturas||0) + ' aperturas'"></div>
<span class="text-[8px] text-slate-600 mt-1 rotate-45 origin-left whitespace-nowrap" x-text="d.dia.substring(5)"></span>
</div>
</template>
</div>
<div x-show="timeline.length === 0" class="text-center py-6 text-xs text-slate-600">Sin datos de timeline</div>
</div>

<!-- ═══ Aperturas por Federación — Gráfico comparativo con checkboxes ═══ -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-3 flex items-center gap-2"><i data-lucide="map" class="w-4 h-4 text-emerald-400"></i>Aperturas por Federación</h5>

<!-- Global siempre visible -->
<div x-show="fedAperturas.length > 0" class="mb-3">
<div class="flex items-center justify-between text-xs mb-1">
<span class="text-slate-300 font-semibold">🌍 Global</span>
<span class="text-slate-500" x-text="fedTotalEnvios + ' env / ' + fedTotalAperturas + ' abr / ' + fedTasaGlobal + '%'"></span>
</div>
<div class="flex items-center gap-2">
<div class="flex-1 bg-slate-800 rounded-full h-4">
<div class="bg-emerald-500 h-4 rounded-full transition-all duration-500" :style="{ width: Math.min(fedTasaGlobal,100) + '%' }"></div>
</div>
</div>
</div>

<!-- Checkboxes para seleccionar federaciones -->
<div x-show="fedAperturas.length > 0" class="flex flex-wrap gap-2 mb-3">
<template x-for="(f, idx) in fedAperturas" :key="f.federacion">
<label class="flex items-center gap-1 px-2 py-1 bg-slate-800/40 rounded-lg text-xs cursor-pointer hover:bg-slate-800 transition" :class="fedSelected[idx] ? 'border border-emerald-500/30' : 'border border-transparent'">
<input type="checkbox" x-model="fedSelected[idx]" @change="recalcFedMax()" class="w-3 h-3 accent-emerald-500 rounded">
<span class="text-slate-400 truncate max-w-[140px]" x-text="f.federacion"></span>
</label>
</template>
</div>

<!-- Barras comparativas de federaciones seleccionadas -->
<div x-show="fedComparativa.length > 0" class="space-y-2">
<template x-for="(item, idx) in fedComparativa" :key="item.federacion">
<div>
<div class="flex items-center justify-between text-xs mb-1">
<span class="text-slate-400 truncate max-w-[55%]" x-text="item.federacion"></span>
<span class="text-slate-500" x-text="item.envios + ' env / ' + item.aperturas + ' abr / ' + item.tasa + '%'"></span>
</div>
<div class="flex items-center gap-2">
<div class="flex-1 bg-slate-800 rounded-full h-3">
<div class="h-3 rounded-full transition-all duration-500" :style="{ width: Math.min(item.tasa,100) + '%' }" :class="fedColors[idx % fedColors.length]"></div>
</div>
</div>
</div>
</template>
</div>

<div x-show="fedAperturas.length === 0" class="text-center py-6 text-xs text-slate-600">Sin datos de aperturas por federación</div>
</div>

<!-- ═══ NUEVO: Interacciones antes del cierre ═══ -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-2"><i data-lucide="activity" class="w-4 h-4 text-purple-400"></i>Interacciones antes del Cierre</h5>

<!-- Resumen Ganado vs Perdido -->
<div x-show="interaccionesCierre.length > 0" class="grid grid-cols-2 gap-3 mb-4">
<template x-for="ic in interaccionesCierre" :key="ic.estado_lead">
<div class="bg-slate-800/40 rounded-lg p-3 text-center">
<div class="text-xs font-semibold mb-1" :class="ic.estado_lead.includes('Ganado') ? 'text-emerald-400' : 'text-rose-400'" x-text="ic.estado_lead"></div>
<div class="text-2xl font-bold" :class="ic.estado_lead.includes('Ganado') ? 'text-emerald-300' : 'text-rose-300'" x-text="ic.media_interacciones"></div>
<div class="text-xs text-slate-500">interacciones de media</div>
<div class="text-xs text-slate-600 mt-1" x-text="ic.total_leads + ' leads'"></div>
</div>
</template>
</div>

<!-- Histograma barras -->
<div x-show="histogramaInteracciones.length > 0">
<div class="text-xs text-slate-500 mb-2">Distribución de interacciones por lead</div>
<div class="h-32 flex items-end gap-1">
<template x-for="h in histogramaInteracciones" :key="h.interacciones">
<div class="flex-1 flex flex-col items-center group relative">
<div class="bg-purple-500/60 hover:bg-purple-500/80 rounded-t transition-all w-full"
:style="{ height: h.hPct + '%' }"
:title="h.interacciones + ' interacciones: ' + h.cantidad_leads + ' leads'"></div>
<span class="text-[8px] text-slate-600 mt-1 whitespace-nowrap" x-text="h.interacciones"></span>
</div>
</template>
</div>
<div class="text-[8px] text-slate-600 mt-1 text-center">Nº de interacciones antes del cierre</div>
</div>

<div x-show="interaccionesCierre.length === 0 && histogramaInteracciones.length === 0" class="text-center py-6 text-xs text-slate-600">Sin datos de interacciones — cierra algunos leads primero</div>
</div>

<!-- ═══ NUEVO: Timeline de Interacciones ═══ -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-2"><i data-lucide="trending-up" class="w-4 h-4 text-amber-400"></i>Actividad de Comunicaciones 30 Días</h5>
<div x-show="timelineInteracciones.length > 0" class="h-32 flex items-end gap-1">
<template x-for="d in timelineInteracciones" :key="d.dia">
<div class="flex-1 flex flex-col items-center group relative">
<div class="bg-amber-500/60 hover:bg-amber-500/80 rounded-t transition-all w-full"
:style="{ height: d.hPct + '%' }"
:title="d.dia + ': ' + d.total_comunicaciones + ' comunicaciones'"></div>
<span class="text-[8px] text-slate-600 mt-1 rotate-45 origin-left whitespace-nowrap" x-text="d.dia.substring(5)"></span>
</div>
</template>
</div>
<div x-show="timelineInteracciones.length === 0" class="text-center py-6 text-xs text-slate-600">Sin actividad de comunicaciones</div>
</div>

<!-- A/B Testing -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
<h5 class="text-sm font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-2"><i data-lucide="bar-chart-3" class="w-4 h-4 text-purple-400"></i>A/B Testing — Rendimiento por Asunto</h5>
<div x-show="ab.length > 0" class="space-y-3">
<template x-for="item in ab" :key="item.asunto">
<div class="bg-slate-800/40 rounded-lg p-3">
<div class="flex items-center justify-between mb-1">
<span class="text-sm text-slate-300 truncate max-w-[70%]" x-text="item.asunto.substring(0,60)"></span>
<span class="text-xs font-semibold" :class="item.tasa >= 20 ? 'text-emerald-400' : item.tasa >= 10 ? 'text-amber-400' : 'text-slate-500'" x-text="item.tasa+'%'"></span>
</div>
<div class="flex items-center gap-2">
<div class="flex-1 bg-slate-700 rounded-full h-3">
<div class="bg-emerald-500 h-3 rounded-full transition-all duration-500" :style="{ width: item.tasaWin + '%' }"></div>
</div>
<span class="text-xs text-slate-500 whitespace-nowrap" x-text="item.envios+' env'"></span>
</div>
</div>
</template>
</div>
<div x-show="ab.length === 0" class="text-center py-6 text-xs text-slate-600">Sin datos A/B — empieza a enviar emails</div>
</div>
</div>

<script>
function analyticsTab() {
return {
pipeline: [], ab: [], timeline: [],
fedAperturas: [], fedSelected: [], fedColors: ['bg-emerald-500','bg-blue-500','bg-cyan-500','bg-purple-500','bg-amber-500','bg-rose-500','bg-teal-500','bg-pink-500','bg-indigo-500','bg-orange-500'],
interaccionesCierre: [], histogramaInteracciones: [], timelineInteracciones: [],
colors: ['bg-slate-500','bg-blue-500','bg-cyan-500','bg-amber-500','bg-purple-500','bg-emerald-500','bg-rose-500'],

get fedTotalEnvios() { return this.fedAperturas.reduce((s,f) => s + parseInt(f.envios||0), 0); },
get fedTotalAperturas() { return this.fedAperturas.reduce((s,f) => s + parseInt(f.aperturas||0), 0); },
get fedTasaGlobal() { return this.fedTotalEnvios > 0 ? Math.round((this.fedTotalAperturas/this.fedTotalEnvios)*100) : 0; },
get fedComparativa() { return this.fedAperturas.filter((f,i) => this.fedSelected[i]); },
recalcFedMax() { /* forzamos reactividad */ },

async load() {
try {
const r = await fetch('?action=get_analytics&tab=dashboard');
const j = await r.json();
if (!j.ok) return;
// Pipeline
const max = Math.max(1, ...(j.pipeline||[]).map(p => parseInt(p.cnt)||0));
this.pipeline = (j.pipeline||[]).map((p,i) => ({
...p, cnt: parseInt(p.cnt)||0,
pct: Math.round(((parseInt(p.cnt)||0)/max)*100),
color: this.colors[i % this.colors.length]
}));
// A/B
this.ab = (j.ab||[]).map(a => ({...a, tasa: parseFloat(a.tasa)||0,
tasaWin: Math.round(((parseFloat(a.tasa)||0)/Math.max(1,Math.max(...(j.ab||[]).map(x=>parseFloat(x.tasa)||0))))*100)
}));
// Timeline
const maxTL = Math.max(1, ...(j.timeline||[]).map(d => parseInt(d.envios)||0));
this.timeline = (j.timeline||[]).map(d => ({...d,
hPct: Math.round(((parseInt(d.envios)||0)/maxTL)*100),
aPct: Math.round(((parseInt(d.aperturas)||0)/maxTL)*100)
}));
// Aperturas por federación
this.fedAperturas = j.fedAperturas || [];
// Interacciones cierre
this.interaccionesCierre = j.interaccionesCierre || [];
// Histograma
const maxHist = Math.max(1, ...(j.histogramaInteracciones||[]).map(h => parseInt(h.cantidad_leads)||0));
this.histogramaInteracciones = (j.histogramaInteracciones||[]).map(h => ({
...h,
hPct: Math.round(((parseInt(h.cantidad_leads)||0)/maxHist)*100)
}));
// Timeline interacciones
const maxInt = Math.max(1, ...(j.timelineInteracciones||[]).map(d => parseInt(d.total_comunicaciones)||0));
this.timelineInteracciones = (j.timelineInteracciones||[]).map(d => ({
...d,
hPct: Math.round(((parseInt(d.total_comunicaciones)||0)/maxInt)*100)
}));
setTimeout(() => lucide.createIcons(), 100);
} catch(e) { console.error('Analytics load error', e); }
}
};
}
</script>