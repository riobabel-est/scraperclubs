<!-- ═══════════ FILTROS RÁPIDOS KANBAN (FASE 3 — absorción de Follow-ups) ═══════════ -->
<div class="mb-4 bg-slate-900/60 border border-slate-800 rounded-xl p-3">
    <div class="flex items-center gap-2 flex-wrap">
        <!-- Chip: Todos -->
        <button @click="limpiarFiltros()"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border transition"
            :class="filtroActivo === '' && !busqueda ? 'bg-amber-500/20 text-amber-400 border-amber-500/40' : 'bg-slate-800 text-slate-400 border-slate-700 hover:text-slate-200 hover:border-slate-600'">
            Todos
        </button>

        <!-- Chip: Calientes (+2 aperturas) -->
        <button @click="toggleFiltro('calientes')"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border transition"
            :class="filtroActivo === 'calientes' ? 'bg-orange-500/20 text-orange-400 border-orange-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            🔥 Calientes (+2)
            <span class="ml-1 text-xs font-bold text-slate-200" x-text="chipCounters.calientes || 0"></span>
        </button>

        <!-- Chip: Pendiente WhatsApp -->
        <button @click="toggleFiltro('pendiente_wa')"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border transition"
            :class="filtroActivo === 'pendiente_wa' ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            📱 Pendiente WA
            <span class="ml-1 text-xs font-bold text-slate-200" x-text="chipCounters.pendiente_wa || 0"></span>
        </button>

        <!-- Chip: Leídos (1+ aperturas) -->
        <button @click="toggleFiltro('leidos')"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border transition"
            :class="filtroActivo === 'leidos' ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/40' : 'bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600'">
            👁️ Leídos (1+)
            <span class="ml-1 text-xs font-bold text-slate-200" x-text="chipCounters.leidos || 0"></span>
        </button>

        <!-- Selector: Federación -->
        <select x-model="filtroFederacion" @change="setFiltroFederacion(filtroFederacion)"
            class="px-3 py-1.5 rounded-full text-sm font-semibold border bg-slate-800 text-slate-300 border-slate-700 hover:text-slate-100 hover:border-slate-600 focus:outline-none focus:border-amber-500/50">
            <option value="">Federación</option>
            <template x-for="fed in federacionesFiltro" :key="fed">
                <option :value="fed" x-text="fed + ' (' + (chipCounters.federaciones[fed] || 0) + ')'"></option>
            </template>
        </select>

        <!-- Buscador en tiempo real -->
        <div class="relative flex-1 min-w-[180px]">
            <input type="text" x-model="busqueda" @input="onBusquedaInput()"
                placeholder="Buscar club..."
                class="w-full bg-slate-800 border border-slate-700 rounded-full pl-9 pr-8 py-1.5 text-sm text-slate-200 placeholder-slate-400 focus:outline-none focus:border-amber-500/50">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <button x-show="busqueda" @click="busqueda=''" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-200 transition" title="Limpiar búsqueda">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Contador de resultados visibles -->
        <span class="text-xs text-slate-400 whitespace-nowrap" x-show="filtroActivo || busqueda">
            <span x-text="leadsFiltrados.length"></span> de <span x-text="kanbanLeads.length"></span> leads
        </span>
    </div>
</div>

<div class="kanban-scroll flex gap-3 overflow-x-auto pb-4 items-stretch h-[calc(100vh-220px)] min-h-[400px]">
<?php foreach($estadosKanban as $idx => $est): $cards=$kanbanData[$est]??[]; $borderCls=$colClasses[$est]??'border-slate-500'; $isEmpty=count($cards)===0; ?>
<div class="flex-shrink-0 bg-slate-900/50 border border-slate-800 rounded-xl flex flex-col transition-all duration-200"
     :class="collapsed['<?=escHtml($est)?>'] ? 'w-14' : 'w-72'"
     @dragover.prevent @drop="collapsed['<?=escHtml($est)?>']=false; dropLead($event, '<?=escHtml($est)?>')">
<div class="p-3 cursor-pointer flex items-center gap-2 select-none"
     @click="collapsed['<?=escHtml($est)?>'] = !collapsed['<?=escHtml($est)?>']">
<span class="text-sm font-bold uppercase tracking-wider text-slate-200 flex-1 whitespace-nowrap overflow-hidden"
      x-show="!collapsed['<?=escHtml($est)?>']"><?=escHtml($est)?></span>
<span class="text-slate-400 text-sm font-mono"><?=count($cards)?></span>
<span class="text-sm text-slate-200 uppercase tracking-widest whitespace-nowrap"
      x-show="collapsed['<?=escHtml($est)?>']"
      style="writing-mode:vertical-rl;text-orientation:mixed"><?=escHtml($est)?></span>
<svg class="w-4 h-4 text-slate-400 transition-transform duration-200 flex-shrink-0"
     :class="collapsed['<?=escHtml($est)?>'] ? '-rotate-90' : ''"
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
<path d="M6 9l6 6 6-6"/></svg>
</div>
<div class="space-y-2 flex-1 overflow-y-auto px-3 pb-3"
     x-show="!collapsed['<?=escHtml($est)?>']">
<?php $cardIdx=0; foreach($cards as $card): $waLink=getWaLink($card['telefono_movil']??''); $hasWa=(int)($card['tiene_whatsapp']??0); $opens=(int)($card['num_opens']??0); $isDup=(int)($card['es_duplicado']??0); $dupId=$card['duplicado_id']??null; $clasIA=(string)($card['clasificacion_ia']??''); $tieneConv=$clasIA!==''; $clasIAEtiqueta=['interesado'=>'Interesado','duda_precio'=>'Duda Precio','neutral'=>'Neutral','no_interesa'=>'No Interesa','baja'=>'Baja','humana'=>'Respondió','otro'=>'Otro']; $clasIAColor=['interesado'=>'bg-emerald-500/15 text-emerald-400','duda_precio'=>'bg-amber-500/15 text-amber-400','neutral'=>'bg-slate-500/15 text-slate-300','no_interesa'=>'bg-rose-500/15 text-rose-400','baja'=>'bg-rose-500/15 text-rose-400','humana'=>'bg-cyan-500/15 text-cyan-400','otro'=>'bg-slate-500/15 text-slate-300']; $tempCard=(string)($card['temperatura']??''); $tempIcon=['MuyCaliente'=>'🌋','Caliente'=>'🔥','Tibio'=>'⏳','Frio'=>'🥶'][$tempCard]??''; $ramalCard=(string)($card['ramal']??''); $novedadCard=(bool)($card['novedad']??false); $numEnv=(int)($card['num_envios']??0); $paFecha=(string)($card['pa_fecha']??''); $paTexto=(string)($card['pa_texto']??''); $paEstado=(string)($card['pa_estado']??''); $paCls=['vencida'=>'bg-rose-500/15 text-rose-400','urgente'=>'bg-amber-500/15 text-amber-400','ok'=>'bg-emerald-500/15 text-emerald-400'][$paEstado]??'bg-slate-500/15 text-slate-300'; $paLabel=['vencida'=>'⏰ Vencida','urgente'=>'⚠️ Próxima','ok'=>'📅 Agendada'][$paEstado]??'📅'; ?>
<div class="bg-slate-800 border-l-4 <?=$borderCls?> rounded-lg p-3 cursor-pointer hover:bg-slate-700/70 transition text-sm"
     x-show="leadVisible(<?=$card['id']?>) && <?=$cardIdx?> < limiteColumna('<?=escHtml($est)?>')"
     draggable="true"
     @dragstart="dragStart($event, <?=$card['id']?>)"
     @click="openLead(<?=$card['id']?>)">
<div class="font-semibold text-slate-100 text-sm mb-1"><?=escHtml($card['nombre_club'])?></div>
<div class="flex items-center gap-2 flex-wrap text-xs text-slate-300">
<?php if($card['persona_contacto']):?><span class="text-slate-300"><?=escHtml($card['persona_contacto'])?></span><?php endif;?>
<?php if($waLink&&$hasWa):?><a href="<?=escHtml($waLink)?>" target="_blank" @click.stop="registrarWhatsApp(<?=$card['id']?>)" class="text-emerald-400 hover:text-emerald-300 font-semibold" title="WhatsApp"><i data-lucide="message-circle" class="w-4 h-4 inline"></i> WA</a><?php endif;?>
<?php if($card['email']):?><?php if($tieneConv):?><button @click.stop="abrirConversacionLead(<?=$card['id']?>)" class="text-amber-400 hover:text-amber-500 font-semibold" title="Ver conversación"><i data-lucide="mail" class="w-4 h-4 inline"></i> Mail</button><?php else:?><span class="text-slate-300 select-none" title="Email pendiente de gestionar"><i data-lucide="mail" class="w-4 h-4 inline"></i> Pendiente</span><?php endif;?><?php endif;?>
<?php if($opens>0):?><span class="bg-cyan-500/15 text-cyan-400 px-2 py-0.5 rounded-full text-xs font-semibold"><?=$opens?></span><?php endif;?>
<?php if($clasIA!=='' && isset($clasIAEtiqueta[$clasIA])):?><span class="<?=$clasIAColor[$clasIA]?> px-2 py-0.5 rounded-full text-xs font-semibold" title="Clasificación IA de la última respuesta"><?=escHtml($clasIAEtiqueta[$clasIA])?></span><?php endif;?>
<?php if($novedadCard):?><span class="bg-amber-500/15 text-amber-400 px-2 py-0.5 rounded-full text-xs font-semibold" title="Actividad hoy (apertura o respuesta)">● NUEVO</span><?php endif;?>
<?php if($tempIcon!==''):?><span class="bg-slate-800 text-slate-300 px-2 py-0.5 rounded-full text-xs font-semibold" title="Temperatura del lead"><?=$tempIcon?> <?=escHtml($tempCard)?></span><?php endif;?>
<?php if($ramalCard!==''):?><span class="bg-blue-500/15 text-blue-400 px-2 py-0.5 rounded-full text-xs font-semibold" title="Interés (variante ABC que más abrió)"><?=escHtml($ramalCard)?></span><?php endif;?>
<?php if($numEnv>0):?><span class="bg-slate-800 text-slate-400 px-2 py-0.5 rounded-full text-xs font-semibold" title="Comunicaciones enviadas">📧 <?=$numEnv?></span><?php endif;?>
<?php if($paFecha!==''):?><span class="<?=$paCls?> px-2 py-0.5 rounded-full text-xs font-semibold" title="Próxima acción: <?=escHtml($paTexto !== '' ? $paTexto : '—')?> (<?=escHtml($paFecha)?>)"><?=$paLabel?> <?=escHtml(date('d/m', strtotime($paFecha)))?></span><?php endif;?>
<?php if($isDup):?><span class="bg-amber-500/15 text-amber-400 px-2 py-0.5 rounded-full text-xs font-semibold cursor-pointer" @click.stop="openMerge(<?=$dupId?>,<?=$card['id']?>)">DUPLICADO</span><?php endif;?>
</div></div>
<?php $cardIdx++; endforeach; ?>

<?php if($isEmpty):?>
<div class="text-center text-slate-400 text-sm py-8 italic">Sin leads</div>
<?php endif;?>
<!-- Footer de carga diferida por columna -->
<div class="pt-1 pb-1" x-show="!collapsed['<?=escHtml($est)?>'] && totalColumna('<?=escHtml($est)?>') > limiteColumna('<?=escHtml($est)?>')">
    <div class="text-center text-xs text-slate-400 mb-1">
        Mostrando <span x-text="Math.min(limiteColumna('<?=escHtml($est)?>'), totalColumna('<?=escHtml($est)?>'))"></span> de <span x-text="totalColumna('<?=escHtml($est)?>')"></span> leads
    </div>
    <button @click="cargarMas('<?=escHtml($est)?>')"
        data-cargar-mas="<?=escHtml($est)?>"
        class="w-full py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-xs font-semibold text-slate-300 hover:text-slate-100 hover:border-slate-600 transition">
        Cargar más
    </button>
</div>
</div></div>
<?php endforeach; ?>
</div>
