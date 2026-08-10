<?php
/**
 * tabs/lanzadera.php — Módulo Lanzadera Outbound v2.1
 * Layout masonry 2 columnas adaptativas.
 * Izq: Config → Monitor → Log + Botones de Control
 * Der: SMTP → Cola de Envíos (infinite scroll + botón Cargar)
 * Colores de fila: ámbar = procesando, gris apagado = ya enviado.
 *
 * Campos Config Lote Actual (en orden):
 *   1.1 Seleccionar Federación
 *   1.2 Seleccionar Estado del Lead
 *   1.3 Seleccionar Plantilla
 */
?>
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- GRID PRINCIPAL: 2 columnas masonry -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="grid lg:grid-cols-2 gap-4 items-start">

    <!-- ═══════════ COLUMNA IZQUIERDA ═══════════ -->
    <div class="space-y-4">

        <!-- BLOQUE 1 IZQ: CONFIGURACIÓN DE LOTE ACTUAL -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
            <div class="flex items-center gap-3 mb-4">
                <i data-lucide="sliders-horizontal" class="w-5 h-5 text-amber-400"></i>
                <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Configuración de Lote Actual</h5>
            </div>
            <div class="grid sm:grid-cols-3 gap-4">
                <!-- 1.1 Seleccionar Federación -->
                <div>
                    <label class="block text-xs uppercase tracking-wider text-slate-400 mb-1.5">1. Seleccionar Federación</label>
                    <select x-model="lzFederacion"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 transition">
                        <option value="">Todas las federaciones</option>
                        <template x-for="fed in lzFederaciones" :key="fed">
                            <option :value="fed" x-text="fed"></option>
                        </template>
                    </select>
                </div>
                <!-- 1.2 Seleccionar Estado del Lead -->
                <div>
                    <label class="block text-xs uppercase tracking-wider text-slate-400 mb-1.5">2. Seleccionar Estado del Lead</label>
                    <select x-model="lzEstadoLead" @change="lzOnEstadoChange()"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 transition">
                        <option value="">Seleccionar estado...</option>
                        <template x-for="est in lzEstadosLead" :key="est">
                            <option :value="est" x-text="est"></option>
                        </template>
                    </select>
                </div>
                <!-- 1.3 Seleccionar Plantilla -->
                <div>
                    <label class="block text-xs uppercase tracking-wider text-slate-400 mb-1.5">3. Seleccionar Plantilla</label>
                    <select x-model="lzIdPlantillaEmail" :disabled="!lzEstadoLead"
                        class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-amber-500/50 transition disabled:opacity-40 disabled:cursor-not-allowed">
                        <option value="">Seleccionar plantilla...</option>
                        <template x-for="tpl in lzTemplatesEmail" :key="tpl.id">
                            <option :value="tpl.id" x-text="tpl.nombre"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <!-- BLOQUE 2 IZQ: MONITORIZACIÓN DEL MOTOR + KPIs -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
            <div class="flex items-center gap-3 mb-4">
                <i data-lucide="activity" class="w-5 h-5 text-amber-400"></i>
                <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Monitorización del Motor</h5>
            </div>

            <!-- Analytics: Envíos OK / Errores / Tasa de Éxito (tiempo real de sesión) -->
            <div class="mb-4">
                <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="bar-chart-3" class="w-4 h-4 text-amber-400"></i>
                    <span class="text-xs uppercase tracking-wider text-slate-400">Analytics de Sesión</span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <!-- Envíos OK -->
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-slate-400">✅ Envíos OK</span>
                            <span class="text-sm font-semibold text-emerald-400" x-text="lzEnvioOkCount">0</span>
                        </div>
                        <div class="w-full bg-slate-700 rounded-full h-1.5">
                            <div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-300"
                                :style="'width:' + lzEnvioOkPct + '%'"></div>
                        </div>
                    </div>
                    <!-- Errores -->
                    <div class="bg-slate-800/50 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-slate-400">❌ Errores</span>
                            <span class="text-sm font-semibold text-rose-400" x-text="lzEnvioErrorCount">0</span>
                        </div>
                        <div class="w-full bg-slate-700 rounded-full h-1.5">
                            <div class="bg-rose-500 h-1.5 rounded-full transition-all duration-300"
                                :style="'width:' + lzEnvioErrorPct + '%'"></div>
                        </div>
                    </div>
                    <!-- Tasa de Éxito -->
                    <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                        <div class="text-xs text-slate-400 mb-1">📊 Tasa de Éxito</div>
                        <div class="text-base font-semibold"
                            :class="lzTasaExito >= 90 ? 'text-emerald-400' : (lzTasaExito >= 70 ? 'text-amber-400' : 'text-rose-400')"
                            x-text="lzTasaExito + '%'">—</div>
                        <div class="text-xs text-slate-500 mt-0.5" x-text="lzLogEnviados.length + ' procesados'"></div>
                    </div>
                </div>
            </div>

            <!-- Badge Estado + Retardo + Límite -->
            <div class="grid sm:grid-cols-3 gap-3">
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <span class="text-xs text-slate-400 block mb-1">Estado del Motor</span>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full"
                            :style="{backgroundColor: lzMotorEstado === 'ACTIVO' ? '#34d399' : (lzMotorEstado === 'DETENIDO' ? '#f43f5e' : '#fbbf24')}"
                            :class="lzMotorEstado === 'ACTIVO' ? 'animate-pulse' : ''"></span>
                        <span class="text-sm font-semibold"
                            :style="{color: lzMotorEstado === 'ACTIVO' ? '#34d399' : (lzMotorEstado === 'DETENIDO' ? '#f43f5e' : '#fbbf24')}"
                            x-text="lzMotorEstado">PAUSADO</span>
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <span class="text-xs text-slate-400 block mb-1">Retardo entre Envíos</span>
                    <div class="flex items-center gap-2">
                        <input type="range" x-model.number="lzDelay" min="1" max="60" step="1" @change="lzSaveDelay()"
                            class="flex-1 h-1.5 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-amber-400"
                            :disabled="lzMotorEstado === 'ACTIVO'">
                        <span class="text-sm font-semibold w-10 text-right"
                            :style="{color: lzDelay <= 10 ? '#34d399' : (lzDelay <= 30 ? '#fbbf24' : '#f43f5e')}"
                            x-text="lzDelay + 's'">5s</span>
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3">
                    <span class="text-xs text-slate-400 block mb-1">Límite Diario</span>
                    <div class="flex items-center gap-1">
                        <span class="text-sm font-semibold text-slate-300">50</span>
                        <span class="text-xs text-slate-500">/ cuenta / día</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- BLOQUE 2.5 IZQ: DESTINOS DE PRUEBA (solo visible en modo pruebas) -->
        <div class="bg-slate-900 border border-amber-500/30 rounded-xl p-5" x-show="modeTest" x-cloak>
            <div class="flex items-center gap-3 mb-3">
                <i data-lucide="flask-conical" class="w-5 h-5 text-amber-400"></i>
                <h5 class="text-base font-semibold uppercase tracking-wider text-amber-400">🧪 Destinos de Prueba</h5>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-slate-400 mb-1.5">Emails para pruebas (uno por línea o separados por coma)</label>
                <textarea x-model="testEmails" rows="3"
                    class="w-full bg-slate-800 border border-amber-500/30 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-none focus:border-amber-500/50 resize-y"
                    placeholder="test1@gmail.com, test2@hotmail.com&#10;miau@outlook.es"></textarea>
                <div class="flex items-center justify-between mt-2">
                    <span class="text-xs text-slate-500" x-text="'(' + testEmailsList.length + ' emails detectados)'"></span>
                    <span class="text-xs text-amber-400">Los envíos en modo pruebas irán al primer email de esta lista</span>
                </div>
            </div>
        </div>

        <!-- BLOQUE 3 IZQ: LOG DE ENVÍOS REALIZADOS + BOTONES DE CONTROL AL PIE -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col">
            <div class="flex items-center justify-between mb-3">
                <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200 flex items-center gap-2">
                    📜 Log de Envíos Realizados
                </h5>
                <span class="text-xs text-slate-500" x-text="lzLogEnviados.length + ' registros'"></span>
            </div>

            <div class="overflow-y-auto max-h-96 custom-scrollbar" id="lzLogScroll" @scroll="lzOnLogScroll()">
                <div x-show="lzLogEnviados.length === 0" class="text-center py-10">
                    <i data-lucide="scroll-text" class="w-10 h-10 text-slate-700 mx-auto mb-3"></i>
                    <p class="text-slate-400 text-sm">Sin envíos realizados en esta sesión</p>
                    <p class="text-slate-500 text-xs mt-1">Los resultados aparecerán en tiempo real</p>
                </div>
                <table x-show="lzLogEnviados.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider sticky top-0">
                            <th class="px-2 py-1.5 text-left font-semibold w-14">Hora</th>
                            <th class="px-2 py-1.5 text-left font-semibold">Club</th>
                            <th class="px-2 py-1.5 text-left font-semibold hidden sm:table-cell">SMTP</th>
                            <th class="px-2 py-1.5 text-center font-semibold w-14">Res.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="log in lzLogEnviadosPaginados" :key="log.timestamp">
                            <tr class="border-b border-slate-800/50">
                                <td class="px-2 py-1.5 text-slate-400 text-xs" x-text="log.timestamp?.substring(11, 19)"></td>
                                <td class="px-2 py-1.5">
                                    <span class="text-slate-200 text-xs" x-text="log.club"></span>
                                    <div class="text-xs text-slate-500" x-text="log.email"></div>
                                </td>
                                <td class="px-2 py-1.5 text-slate-300 text-xs hidden sm:table-cell" x-text="log.cuenta_smtp?.split('@')[0] || '—'"></td>
                                <td class="px-2 py-1.5 text-center">
                                    <span x-show="log.envio_exitoso" class="text-emerald-400 text-sm">✅</span>
                                    <span x-show="!log.envio_exitoso" class="text-rose-400 text-sm cursor-help" :title="log.error_smtp || 'Error desconocido'">🔴</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- BOTONES DE CONTROL al pie del log -->
            <div class="mt-4 pt-4 border-t border-slate-800 flex items-center justify-center gap-3 flex-wrap">
                <button @click="iniciarMotor()" :disabled="lzCola.length === 0 || lzMotorEstado === 'ACTIVO'"
                    class="px-4 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2
                           bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30
                           disabled:opacity-30 disabled:cursor-not-allowed">
                    <i data-lucide="play" class="w-4 h-4"></i>
                    🟢 INICIAR LANZADERA
                </button>
                <button @click="pausarMotor()" :disabled="lzMotorEstado !== 'ACTIVO'"
                    class="px-4 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2
                           bg-amber-500/20 text-amber-400 border border-amber-500/30 hover:bg-amber-500/30
                           disabled:opacity-30 disabled:cursor-not-allowed">
                    <i data-lucide="pause" class="w-4 h-4"></i>
                    🟡 PAUSAR MOTOR
                </button>
                <button @click="detenerMotor()" :disabled="lzMotorEstado === 'DETENIDO'"
                    class="px-4 py-2.5 rounded-lg text-sm font-semibold transition flex items-center gap-2
                           bg-rose-500/20 text-rose-400 border border-rose-500/30 hover:bg-rose-500/30
                           disabled:opacity-30 disabled:cursor-not-allowed">
                    <i data-lucide="square" class="w-4 h-4"></i>
                    🔴 DETENER MOTOR
                </button>
            </div>
        </div>
    </div><!-- /columna izquierda -->

    <!-- ═══════════ COLUMNA DERECHA ═══════════ -->
    <div class="space-y-4">

        <!-- BLOQUE 1 DER: ESTADO DE CUENTAS SMTP -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
            <div class="flex items-center gap-3 mb-4">
                <i data-lucide="server" class="w-5 h-5 text-cyan-400"></i>
                <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200">Estado de Cuentas SMTP</h5>
                <span class="text-sm text-slate-500" x-text="'(' + lzCuentasSmtp.length + ' cuentas)'"></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider">
                            <th class="px-3 py-2 text-left font-semibold">Cuenta</th>
                            <th class="px-3 py-2 text-left w-36 font-semibold">Uso Hoy</th>
                            <th class="px-3 py-2 text-center w-14 font-semibold">SMTP</th>
                            <th class="px-3 py-2 text-center w-14 font-semibold">IMAP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="cuenta in lzCuentasSmtp" :key="cuenta.id">
                            <tr class="border-b border-slate-800/50 hover:bg-slate-800/30 transition">
                                <td class="px-3 py-2">
                                    <code class="text-xs text-slate-300" x-text="cuenta.email"></code>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 bg-slate-700 rounded-full h-2">
                                            <div class="h-2 rounded-full transition-all duration-500"
                                                :class="(cuenta.enviados_hoy / cuenta.limite_diario * 100) > 90 ? 'bg-rose-500' : (cuenta.enviados_hoy / cuenta.limite_diario * 100) > 70 ? 'bg-amber-500' : 'bg-emerald-500'"
                                                :style="'width:' + Math.min(100, (cuenta.enviados_hoy / cuenta.limite_diario * 100)) + '%'"></div>
                                        </div>
                                        <span class="text-xs w-14 text-right"
                                            :class="cuenta.enviados_hoy >= cuenta.limite_diario ? 'text-rose-400' : 'text-slate-300'"
                                            x-text="cuenta.enviados_hoy + ' / ' + cuenta.limite_diario"></span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="text-sm" :title="cuenta.ultimo_error || 'OK'"
                                        x-text="cuenta.activa ? (cuenta.ultimo_error ? '🔴' : '🟢') : '⏸️'"></span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="text-sm">🟢</span>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="lzCuentasSmtp.length === 0">
                            <td colspan="4" class="px-3 py-8 text-center text-slate-500">Sin cuentas SMTP configuradas</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BLOQUE 2 DER: COLA DE ENVÍOS EN ESPERA + BOTÓN CARGAR + INFINITE SCROLL -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col">
            <div class="flex items-center justify-between mb-3">
                <h5 class="text-base font-semibold uppercase tracking-wider text-slate-200 flex items-center gap-2">
                    📋 Cola de Envíos en Espera
                </h5>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-500" x-text="'(' + lzCola.length + ' pendientes)'"></span>
                    <button @click="cargarCola()" :disabled="!puedeCargarCola() || lzMotorEstado === 'ACTIVO'"
                        class="px-3 py-1.5 rounded-lg text-sm font-semibold transition flex items-center gap-1.5
                               bg-blue-500/20 text-blue-400 hover:bg-blue-500/30
                               disabled:opacity-30 disabled:cursor-not-allowed">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                        🔵 Cargar Cola
                    </button>
                </div>
            </div>

            <div class="overflow-y-auto max-h-[500px] custom-scrollbar" id="lzColaScroll" @scroll="lzOnColaScroll()">
                <div x-show="lzCola.length === 0" class="text-center py-10">
                    <i data-lucide="inbox" class="w-10 h-10 text-slate-700 mx-auto mb-3"></i>
                    <p class="text-slate-400 text-sm">Carga la cola para ver los próximos envíos</p>
                    <p class="text-slate-500 text-xs mt-1">Configura el lote a la izquierda y pulsa "Cargar Cola"</p>
                </div>
                <table x-show="lzCola.length > 0" class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-800/50 text-slate-300 text-xs uppercase tracking-wider sticky top-0">
                            <th class="px-2 py-1.5 text-left w-6 font-semibold">#</th>
                            <th class="px-2 py-1.5 text-left font-semibold">Club</th>
                            <th class="px-2 py-1.5 text-left font-semibold hidden sm:table-cell">Email</th>
                            <th class="px-2 py-1.5 text-left font-semibold">SMTP</th>
                            <th class="px-2 py-1.5 text-right font-semibold">Est.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in lzColaPaginada" :key="item.id">
                            <tr class="border-b border-slate-800/50 transition"
                                :class="{
                                    'bg-amber-500/10 border-l-2 border-l-amber-400': idx === lzColaIndex,
                                    'opacity-40 saturate-50 pointer-events-none': lzColaCompletados[item.id],
                                    'hover:bg-slate-800/30': !lzColaCompletados[item.id] && idx !== lzColaIndex
                                }">
                                <td class="px-2 py-1.5 text-xs" :class="lzColaCompletados[item.id] ? 'text-slate-700' : 'text-slate-500'" x-text="idx + 1"></td>
                                <td class="px-2 py-1.5">
                                    <span class="font-normal text-xs" :class="lzColaCompletados[item.id] ? 'text-slate-700 line-through' : 'text-slate-200'" x-text="item.nombre_club"></span>
                                    <span class="text-xs block" :class="lzColaCompletados[item.id] ? 'text-slate-800' : 'text-slate-500'" x-text="item.federacion?.substring(0, 25) || ''"></span>
                                </td>
                                <td class="px-2 py-1.5 hidden sm:table-cell">
                                    <code class="text-xs" :class="lzColaCompletados[item.id] ? 'text-slate-700' : 'text-slate-400'" x-text="item.email"></code>
                                </td>
                                <td class="px-2 py-1.5 text-xs" :class="lzColaCompletados[item.id] ? 'text-slate-700' : 'text-slate-300'" x-text="item.smtp_asignada_email?.split('@')[0] || '—'"></td>
                                <td class="px-2 py-1.5 text-right text-xs" :class="lzColaCompletados[item.id] ? 'text-slate-700' : 'text-slate-400'" x-text="item.hora_estimada"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="lzCola.length > 0 && lzColaPaginada.length < lzCola.length" class="text-center py-3">
                    <span class="text-xs text-slate-500">Desplázate para cargar más ({{ lzColaPaginada.length }} / {{ lzCola.length }})</span>
                </div>
            </div>
        </div>
    </div><!-- /columna derecha -->
</div>

<!-- Re-init de iconos tras pintado Alpine -->
<div x-init="$nextTick(() => { setTimeout(() => lucide.createIcons(), 200); })"></div>