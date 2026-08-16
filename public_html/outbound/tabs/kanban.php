<div class="kanban-scroll flex gap-3 overflow-x-auto pb-4 items-start" style="min-height:60vh">
<?php foreach($estadosKanban as $idx => $est): $cards=$kanbanData[$est]??[]; $borderCls=$colClasses[$est]??'border-slate-500'; $isEmpty=count($cards)===0; ?>
<div class="flex-shrink-0 bg-slate-900/50 border border-slate-800 rounded-xl flex flex-col transition-all duration-200"
     :class="collapsed['<?=escHtml($est)?>'] ? 'w-14' : 'w-72'"
     @dragover.prevent @drop="collapsed['<?=escHtml($est)?>']=false; dropLead($event, '<?=escHtml($est)?>')">
<div class="p-3 cursor-pointer flex items-center gap-2 select-none"
     @click="collapsed['<?=escHtml($est)?>'] = !collapsed['<?=escHtml($est)?>']">
<span class="text-xs font-bold uppercase tracking-wider text-slate-400 flex-1 whitespace-nowrap overflow-hidden"
      x-show="!collapsed['<?=escHtml($est)?>']"><?=escHtml($est)?></span>
<span class="text-slate-600 text-xs font-mono"><?=count($cards)?></span>
<span class="text-sm text-slate-300 uppercase tracking-widest whitespace-nowrap"
      x-show="collapsed['<?=escHtml($est)?>']"
      style="writing-mode:vertical-rl;text-orientation:mixed"><?=escHtml($est)?></span>
<svg class="w-3 h-3 text-slate-600 transition-transform duration-200 flex-shrink-0"
     :class="collapsed['<?=escHtml($est)?>'] ? '-rotate-90' : ''"
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
<path d="M6 9l6 6 6-6"/></svg>
</div>
<div class="space-y-2 flex-1 overflow-y-auto max-h-[55vh] px-3 pb-3"
     x-show="!collapsed['<?=escHtml($est)?>']">
<?php foreach($cards as $card): $waLink=getWaLink($card['telefono_movil']??''); $hasWa=(int)($card['tiene_whatsapp']??0); $opens=(int)($card['num_opens']??0); $isDup=(int)($card['es_duplicado']??0); $dupId=$card['duplicado_id']??null; ?>
<div class="bg-slate-800 border-l-4 <?=$borderCls?> rounded-lg p-3 cursor-pointer hover:bg-slate-700/70 transition text-sm"
     draggable="true"
     @dragstart="dragStart($event, <?=$card['id']?>)"
     @click="openLead(<?=$card['id']?>)">
<div class="font-semibold text-slate-200 text-xs mb-1"><?=escHtml($card['nombre_club'])?></div>
<div class="flex items-center gap-2 flex-wrap text-[10px] text-slate-500">
<?php if($card['persona_contacto']):?><span class="text-slate-400"><?=escHtml($card['persona_contacto'])?></span><?php endif;?>
<?php if($waLink&&$hasWa):?><a href="<?=escHtml($waLink)?>" target="_blank" @click.stop class="text-emerald-400 hover:text-emerald-300 font-semibold" title="WhatsApp"><i data-lucide="message-circle" class="w-3 h-3 inline"></i> WA</a><?php endif;?>
<?php if($opens>0):?><span class="bg-cyan-500/15 text-cyan-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold"><?=$opens?></span><?php endif;?>
<?php if($isDup):?><span class="bg-amber-500/15 text-amber-400 px-1.5 py-0.5 rounded-full text-[9px] font-semibold cursor-pointer" @click.stop="openMerge(<?=$dupId?>,<?=$card['id']?>)">DUPLICADO</span><?php endif;?>
</div></div>
<?php endforeach; ?>
<?php if($isEmpty):?>
<div class="text-center text-slate-700 text-xs py-8 italic">Sin leads</div>
<?php endif;?>
</div></div>
<?php endforeach; ?>
</div>