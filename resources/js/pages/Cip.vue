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
const { formatDateTime } = useFormat()

usePoll((page.props.panel?.pollSeconds || 30) * 1000, { only: ['cip', 'history', 'settings'] })

const stageOrder = ['saneado', 'enjuague', 'desinfeccion', 'enjuague_final']
const stageNames = { saneado: 'Saneado', enjuague: 'Enjuague', desinfeccion: 'Desinfección', enjuague_final: 'Enjuague final' }

const rows = computed(() => props.history.map((c) => {
    const stages = stageOrder.map((key) => c.stages.find((s) => s.stage === key) || { stage: key, completed: false, minutes: null })
    const missing = stages.filter((s) => !s.completed).map((s) => stageNames[s.stage])
    return { ...c, stages, missing: missing.join(' y '), incomplete: c.result !== 'finalizado' }
}))

const dueLine = (c) => {
    if (!c.dueAt) return 'Sin ciclos registrados'
    return `${c.status === 'vencido' ? 'Venció' : 'Próximo'}: ${formatDateTime(c.dueAt)}`
}

const go = (url) => router.visit(url)
</script>

<template>
    <Head :title="`CIP · ${plant.name}`" />
    <AppLayout subtitle="Registros finalizados de lavadora y llenadora">
        <section id="cip" class="view active">
            <div class="section-title">
                <div>
                    <button class="screen-button" type="button" @click="go(`/plantas/${plant.code}`)">← Volver a la planta</button>
                    <h2 style="margin-top:10px">TRAZABILIDAD CIP</h2>
                    <p><span id="cip-plant">{{ plant.name }}</span> · Registros PLC para lavadora y llenadora</p>
                </div>
                <div>
                    <button v-if="showNetworkLink" class="screen-button" type="button" @click="go('/')">MENÚ PRINCIPAL</button>
                    <div class="update" style="margin-top:8px">Ciclos finalizados e incompletos</div>
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
                    <div><h3>HISTORIAL DE SANEADOS</h3><span>Los ciclos incompletos se registran y detallan las etapas pendientes, pero no actualizan la periodicidad.</span></div>
                    <span>Período disponible: últimos 13 meses</span>
                </div>
                <div class="history-table-scroll">
                    <table class="cip-table">
                        <thead><tr><th>Fecha</th><th>Equipo</th><th>Saneado</th><th>Enjuague</th><th>Desinfección</th><th>Enjuague final</th><th>Total</th><th>Estado</th></tr></thead>
                        <tbody id="cip-history-body">
                            <tr v-for="row in rows" :key="row.id">
                                <td>{{ formatDateTime(row.finishedAt) }}</td>
                                <td>{{ row.equipment }}</td>
                                <template v-for="s in row.stages" :key="s.stage">
                                    <td v-if="s.completed">{{ s.minutes }} min</td>
                                    <td v-else class="stage-missing">No cumplido</td>
                                </template>
                                <td>{{ row.totalMin }} min{{ row.incomplete ? ' (parcial)' : '' }}</td>
                                <td>
                                    <template v-if="row.incomplete"><span class="cip-result incomplete">Incompleto</span><span class="missing-stages">No se cumplió: {{ row.missing }}</span></template>
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
