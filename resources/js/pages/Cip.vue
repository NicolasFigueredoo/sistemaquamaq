<script setup>
import { Head, router, usePoll, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '../layouts/AppLayout.vue'
import { cipColors, useFormat } from '../lib/format'

const props = defineProps({
    plant: Object,
    settings: Object,
    cip: Array,
    history: Array,
    showNetworkLink: Boolean,
})

const page = usePage()
const { formatDateTime, formatDuration } = useFormat()

usePoll((page.props.panel?.pollSeconds || 30) * 1000, { only: ['cip', 'history', 'settings'] })

const stageOrder = ['saneado', 'enjuague', 'desinfeccion', 'enjuague_final']
const stageNames = { saneado: 'Saneado', enjuague: 'Enjuague', desinfeccion: 'Desinfección', enjuague_final: 'Enjuague final' }

// Qué pasó con cada etapa que no se cumplió.
const stageStatus = {
    en_curso: { cell: 'En curso', why: 'en curso' },
    pendiente: { cell: 'Pendiente', why: 'pendiente' },
    no_inicio: { cell: 'No inició', why: 'no inició' },
    cortada: { cell: 'Cortada', why: 'arrancó y no terminó' },
}
const closeReasons = {
    nuevo_saneado: 'Se cerró porque arrancó un nuevo saneado.',
    marca_cortada: 'Se cerró porque el PLC cortó el proceso.',
    sin_actividad: 'Se cerró por inactividad.',
}

const rows = computed(() => props.history.map((c) => {
    const stages = stageOrder.map((key) => c.stages.find((s) => s.stage === key) || { stage: key, completed: false, seconds: null, status: 'no_inicio' })
    const missing = stages.filter((s) => !s.completed).map((s) => `${stageNames[s.stage]} (${stageStatus[s.status]?.why ?? 'no se cumplió'})`)
    const running = c.result === 'en_curso'
    return { ...c, stages, missing: missing.join(', '), running, incomplete: !running && c.result !== 'finalizado', reason: closeReasons[c.closeReason] }
}))

const dueLine = (c) => {
    if (!c.dueAt) return 'Sin ciclos registrados'
    return `${c.status === 'vencido' ? 'Venció' : 'Próximo'}: ${formatDateTime(c.dueAt)}`
}

const go = (url) => router.visit(url)
</script>

<template>
    <Head :title="`CIP · ${plant.name}`" />
    <AppLayout subtitle="Registros de lavadora y llenadora">
        <section id="cip" class="view active">
            <div class="section-title">
                <div>
                    <button class="screen-button" type="button" @click="go(`/plantas/${plant.code}`)">← Volver a la planta</button>
                    <h2 style="margin-top:10px">TRAZABILIDAD CIP</h2>
                    <p><span id="cip-plant">{{ plant.name }}</span> · Registros PLC para lavadora y llenadora</p>
                </div>
                <div>
                    <button v-if="showNetworkLink" class="screen-button" type="button" @click="go('/')">MENÚ PRINCIPAL</button>
                    <div class="update" style="margin-top:8px">Ciclos pendientes, finalizados e incompletos</div>
                </div>
            </div>

            <div class="cip-status">
                <div v-for="c in cip" :key="c.type" class="cip-status-card">
                    <span class="dot" :style="{ background: cipColors[c.status] }"></span>
                    <div class="info">
                        <h3>{{ c.name.toUpperCase() }}</h3>
                        <p>Último CIP completo: {{ formatDateTime(c.lastCompleteAt) }}</p>
                    </div>
                    <div class="right">
                        <strong :style="{ color: cipColors[c.status] }">{{ c.statusLabel }}</strong>
                        <p>{{ dueLine(c) }}</p>
                    </div>
                </div>
            </div>

            <article class="panel history">
                <div class="panel-head">
                    <div><h3>HISTORIAL DE SANEADOS</h3><span>Un ciclo sin terminar queda pendiente hasta que se cumpla la etapa que falta, aunque sea otro día. Los incompletos detallan qué etapa falló y no actualizan la periodicidad. Duraciones con precisión de segundos.</span></div>
                    <span>Período disponible: últimos 13 meses</span>
                </div>
                <div class="history-table-scroll">
                    <table class="cip-table">
                        <thead><tr><th>Fecha</th><th>Equipo</th><th>Saneado</th><th>Enjuague</th><th>Desinfección</th><th>Enjuague final</th><th>Total</th><th>Estado</th></tr></thead>
                        <tbody id="cip-history-body">
                            <tr v-for="row in rows" :key="row.id">
                                <td>{{ row.running ? `Desde ${formatDateTime(row.startedAt)}` : formatDateTime(row.finishedAt) }}</td>
                                <td>{{ row.equipment }}</td>
                                <template v-for="s in row.stages" :key="s.stage">
                                    <td v-if="s.completed">{{ formatDuration(s.seconds) }}</td>
                                    <td v-else :class="row.running ? 'stage-pending' : 'stage-missing'">{{ stageStatus[s.status]?.cell ?? 'No cumplido' }}</td>
                                </template>
                                <td>{{ formatDuration(row.totalSeconds) }}{{ row.result !== 'finalizado' ? ' (parcial)' : '' }}</td>
                                <td>
                                    <template v-if="row.running"><span class="cip-result pending">Pendiente</span><span class="missing-stages pending">Falta: {{ row.missing }}</span></template>
                                    <template v-else-if="row.incomplete"><span class="cip-result incomplete">Incompleto</span><span class="missing-stages">No se cumplió: {{ row.missing }}</span><span v-if="row.reason" class="missing-stages">{{ row.reason }}</span></template>
                                    <span v-else class="cip-result">Finalizado</span>
                                </td>
                            </tr>
                            <tr v-if="!rows.length" class="empty-row"><td colspan="8">Todavía no hay ciclos CIP informados por el PLC.</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="panel rule">
                <div class="rule-block"><div><label>Periodicidad exigida</label><b id="cip-period-value">{{ settings.cipPeriodDays }} DÍAS</b></div></div>
                <div class="rule-warning"><span class="dot" style="background:var(--red)"></span><span id="cip-period-explanation">El vencimiento se calcula {{ settings.cipPeriodDays }} días después, a la misma hora del último CIP completo. El aviso “Próximo a vencer” se activa durante las {{ settings.cipWarningHours }} horas previas.</span></div>
            </article>
            <div class="note"><span>Un proceso interrumpido no modifica la fecha ni la hora del último saneamiento válido.</span><span>Datos informados por el PLC de cada equipo.</span></div>
        </section>
    </AppLayout>
</template>
