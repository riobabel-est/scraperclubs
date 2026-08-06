<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-4">
<div class="flex items-center justify-between flex-wrap gap-3">
<div class="flex items-center gap-3">
<i data-lucide="rocket" class="w-5 h-5 text-amber-400"></i>
<h5 class="text-sm font-semibold uppercase tracking-wider text-slate-300">Lanzadera Outbound</h5>
</div>
<div class="flex items-center gap-3 flex-wrap">
<button @click="toggleMotor()" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 border"
:class="lzMotorActivo ? 'bg-rose-500/20 text-rose-400 border-rose-500/30 hover:bg-rose-500/30' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/30'">
<i data-lucide="power" class="w-3.5 h-3.5"></i>
<span x-text="lzMotorActivo ? '⏸️ PAUSAR LANZADERA' : '🟢 INICIAR LANZADERA'"></span>
</button>
<select x-model.number="lzDelay" @change="lzSaveDelay()" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-amber-500/50">
<option value="30">30 Segundos</option>
<option value="60">60 Segundos</option>
<option value="120">120 Segundos</option>
</select>
<span class="px-3 py-1 rounded-full text-xs font-semibold border"
:class="lzMotorActivo ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : 'bg-slate-800 text-slate-500 border-slate-700'"
x-text="lzMotorActivo ? 'Motor Activo' : 'Motor Detenido'"></span>
</div>
</div>
</div>

<div class="grid lg:grid-cols-2 gap-4">
<!-- Cola de Envíos -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
<i data-lucide="list-ordered" class="w-4 h-4 text-blue-400"></i> Próximos 10 Envíos en Cola
</h5>
<div class="overflow-x-auto">
<table class="w-full text-xs">
<thead>
<tr class="bg-slate-800/50 text-slate-400 text-[10px] uppercase tracking-wider">
<th class="px-2 py-1.5 text-left">#</th>
<th class="px-2 py-1.5 text-left">Club</th>
<th class="px-2 py-1.5 text-left">Email</th>
<th class="px-2 py-1.5 text-left">SMTP Asignada</th>
</tr>
</thead>
<tbody id="lzColaBody">
<tr><td colspan="4" class="px-2 py-6 text-center text-slate-600">Cargando cola...</td></tr>
</tbody>
</table>
</div>
</div>

<!-- Estado SMTP -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
<h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
<i data-lucide="mail" class="w-4 h-4 text-cyan-400"></i> Estado de las Cuentas SMTP
</h5>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-80 overflow-y-auto" id="gridCuentasSmtp">
<div class="text-xs text-slate-600 text-center py-4 col-span-full">Cargando cuentas...</div>
</div>
</div>
</div>

<!-- Consola de Logs -->
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mt-4">
<div class="flex items-center justify-between mb-2">
<h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2">
<i data-lucide="terminal" class="w-4 h-4 text-emerald-400"></i> Consola de Logs
</h5>
<span class="text-[10px] text-slate-600" x-text="lzLogCount + ' entradas'"></span>
</div>
<div id="consoleLogOutbound" style="background:#0f172a; color:#10b981; font-family:monospace; font-size:11px; height:200px; overflow-y:auto; padding:12px; border-radius:6px;">
<div class="text-slate-500">[--] Consola iniciada. Los logs aparecerán aquí en tiempo real.</div>
</div>
</div>