<script setup>
import { Head, router, useForm, usePoll, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppLayout from '../layouts/AppLayout.vue'
import ConfigModal from '../components/ConfigModal.vue'
import ExemysTestModal from '../components/ExemysTestModal.vue'
import TrendChart from '../components/TrendChart.vue'
import { cipColors, statusColors, statusLabels, useFormat, variableColors } from '../lib/format'

const props = defineProps({
    plant: Object,
    variables: Array,
    settings: Object,
    banner: Object,
    cip: Array,
    chart: Object,
    events: Array,
    note: Object,
    can: Object,
    serverNow: Number,
    exemysTest: Boolean,
    showNetworkLink: Boolean,
})

const page = usePage()
const { formatComma, formatDateTime, formatShort, formatDuration, formatAgo } = useFormat()

usePoll((page.props.panel?.pollSeconds || 30) * 1000, {
    only: ['plant', 'variables', 'settings', 'banner', 'cip', 'chart', 'events', 'serverNow'],
})

// Reloj para "Último dato: hace N segundos", corregido por la diferencia con el servidor.
const offset = ref(props.serverNow - Date.now())
watch(() => props.serverNow, (value) => { offset.value = value - Date.now() })
const now = ref(Date.now() + offset.value)
let timer
onMounted(() => { timer = setInterval(() => { now.value = Date.now() + offset.value }, 1000) })
onBeforeUnmount(() => clearInterval(timer))

const offline = computed(() => props.plant.status === 'sin_conexion')
const byKey = computed(() => Object.fromEntries(props.variables.map((v) => [v.key, v])))
const cards = computed(() => [...props.variables].sort((a, b) => a.sort - b.sort))
const charts = computed(() => props.variables.filter((v) => v.chartSort != null).sort((a, b) => a.chartSort - b.chartSort))

const dotColor = (v) => (offline.value ? 'var(--gray)' : variableColors[v.state] || 'var(--green)')
const targetColor = (v) => (offline.value || v.state === 'ok' ? null : v.state === 'alarma' ? 'var(--red)' : '#c47e00')

function cardValue(v) {
    if (v.value == null) return { value: '—', unit: v.unit }
    if (v.kind === 'boolean') return { value: v.value >= 0.5 ? 'PRESENTE' : 'AUSENTE', unit: '' }
    return { value: formatComma(v.value, v.decimals), unit: v.unit }
}

function cardTarget(v) {
    const sp = formatComma(v.setpoint, v.decimals)
    if (v.kind === 'boolean') return v.value == null || v.value >= 0.5 ? 'Presencia confirmada' : 'Presencia no detectada'
    if (v.key === 'effective_time') return `Objetivo ${sp}`
    return `${v.rule === 'max' ? 'Máximo permitido' : 'Mínimo requerido'} ${sp}`
}

const bannerText = computed(() => {
    const b = props.banner
    if (!b) return ''
    if (b.kind === 'offline') {
        return `SIN CONEXIÓN · No existe un valor actual confiable. Último registro recibido: ${formatDateTime(b.lastReadingAt)}.`
    }
    const label = statusLabels[props.plant.status].toUpperCase()
    if (b.variable) {
        const v = byKey.value[b.variable]
        if (v.kind === 'boolean') return `${label} · Fuera de parámetro: ${v.alertName} no detectado · Valor configurado: presencia obligatoria.`
        const limit = v.rule === 'max' ? 'máximo' : 'mínimo'
        return `${label} · Fuera de parámetro: ${v.alertName} ${formatComma(v.value, v.decimals)} ${v.unit} · Valor configurado: ${limit} ${formatComma(v.setpoint, v.decimals)} ${v.unit}.`
    }
    if (b.cip) {
        const when = b.cip.status === 'vencido' ? 'vencido desde el' : 'próximo a vencer:'
        return `${label} · CIP de ${b.cip.name.toLowerCase()} ${when} ${formatDateTime(b.cip.dueAt)}.`
    }
    return label
})
const bannerClass = computed(() => ({ alarm: props.banner?.kind === 'alarm', offline: props.banner?.kind === 'offline' }))

// Control semanal CIP (columna derecha)
const cipLine = (c) => {
    if (c.running) return `En curso · ${c.running.label} desde ${formatShort(c.running.since)}`
    if (c.status === 'sin_registro') return 'Sin ciclos registrados'
    if (c.status === 'proximo') return `Próximo: ${formatShort(c.dueAt)}`
    if (c.status === 'vencido') return `Venció: ${formatShort(c.dueAt)}`
    return `Último completo: ${formatShort(c.lastCompleteAt)}`
}
const nextDue = computed(() => props.cip.filter((c) => c.dueAt).sort((a, b) => a.dueAt - b.dueAt)[0])

// Bloc de notas del auditor
const noteForm = useForm({ body: props.note?.body ?? '' })
watch(() => props.note, (n) => { noteForm.defaults({ body: n?.body ?? '' }); noteForm.reset() })
const noteStatus = computed(() => {
    if (!props.note?.savedAt) return 'Sin observaciones guardadas.'
    return `Guardado el ${formatDateTime(props.note.savedAt)}${props.note.author ? ` · ${props.note.author}` : ''}`
})
const saveNote = () => noteForm.post(`/plantas/${props.plant.code}/notas`, { preserveScroll: true })

const showConfig = ref(false)
const showExemys = ref(false)
const go = (url) => router.visit(url)
</script>

<template>
    <Head :title="plant.name" />
    <AppLayout subtitle="Proyecto individual del asociado · Monitoreo y eventos">
        <section id="plant" class="view active">
            <div class="plant-header">
                <div class="plant-heading">
                    <button v-if="showNetworkLink" class="screen-button" type="button" @click="go('/')">← Volver a la red nacional</button>
                    <h2 id="plant-title">{{ plant.name.toUpperCase() }}</h2>
                    <p id="plant-location">Proyecto individual del asociado · {{ plant.location }} · Solo lectura</p>
                </div>
                <div class="plant-header-actions">
                    <span id="plant-pill" class="state-pill" :style="{ background: statusColors[plant.status] }">{{ statusLabels[plant.status] }}</span>
                    <button v-if="exemysTest" class="screen-button config-local" type="button" @click="showExemys = true">EXEMYS</button>
                    <button v-if="can.configure" id="config-button" class="screen-button config-local" type="button" @click="showConfig = true">CONFIGURAR ALARMAS</button>
                </div>
                <span class="readonly">Último dato: {{ formatAgo(plant.lastReadingAt, now) }}</span>
            </div>

            <div v-if="banner" id="deviation-banner" class="deviation-banner" :class="bannerClass" role="status">{{ bannerText }}</div>

            <div class="measurements">
                <article v-for="v in cards" :key="v.key" class="measurement">
                    <div class="label"><span class="dot" :style="{ background: dotColor(v) }"></span>{{ v.shortName }}</div>
                    <div class="value">{{ cardValue(v).unit ? `${cardValue(v).value} ` : cardValue(v).value }}<span v-if="cardValue(v).unit" class="unit">{{ cardValue(v).unit }}</span></div>
                    <div class="target" :style="targetColor(v) ? { color: targetColor(v) } : null">{{ cardTarget(v) }}<span v-if="v.kind !== 'boolean'" class="target-unit">{{ ` ${v.unit}` }}</span></div>
                </article>
            </div>

            <div class="plant-layout">
                <TrendChart
                    v-for="v in charts"
                    :key="v.key"
                    :variable="v"
                    :setpoint="v.setpoint"
                    :ranges="chart.ranges"
                />
                <aside class="panel">
                    <div class="panel-head"><h3>CONTROL SEMANAL CIP</h3></div>
                    <div class="cip-box">
                        <div v-for="c in cip" :key="c.type" class="cip-item" :class="{ soon: c.status === 'proximo' }">
                            <div class="cip-title">
                                <span class="dot" :style="{ background: cipColors[c.status] }"></span>{{ c.name }}
                                <em :style="{ color: cipColors[c.status] }">{{ c.statusLabel.toUpperCase() }}</em>
                            </div>
                            <p>{{ cipLine(c) }}</p>
                        </div>
                    </div>
                    <div v-if="nextDue" class="next">PRÓXIMO VENCIMIENTO<b>{{ nextDue.name }} · {{ formatShort(nextDue.dueAt) }}</b></div>
                </aside>
            </div>

            <article class="panel events">
                <div class="panel-head">
                    <div><h3>HISTORIAL DE EVENTOS DE ALARMA</h3><span>Solo se registran eventos que efectivamente alcanzaron estado de alarma.</span></div>
                    <span>Últimos 30 días</span>
                </div>
                <div class="events-scroll">
                    <table class="events-table">
                        <thead><tr><th>Variable</th><th>Inicio de la alarma</th><th>Finalización / normalización</th><th>Duración</th><th>Estado</th></tr></thead>
                        <tbody>
                            <tr v-for="e in events" :key="e.id">
                                <td class="event-var">{{ e.variable }}</td>
                                <td>{{ formatDateTime(e.startedAt) }}</td>
                                <td>{{ e.endedAt ? formatDateTime(e.endedAt) : 'En curso' }}</td>
                                <td class="duration">{{ formatDuration(e.durationS) }}</td>
                                <td><span class="alarm-tag">{{ e.endedAt ? 'Normalizada' : 'Activa' }}</span></td>
                            </tr>
                            <tr v-if="!events.length" class="empty-row"><td colspan="5">Sin alarmas en los últimos 30 días.</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article v-if="can.writeNotes" class="panel auditor-notes">
                <div class="panel-head">
                    <div><h3>BLOC DE NOTAS DEL AUDITOR</h3><span>Observaciones independientes para esta planta.</span></div>
                    <span id="notes-plant-name">{{ plant.name }}</span>
                </div>
                <textarea id="auditor-notes-input" v-model="noteForm.body" placeholder="Escriba observaciones, tareas pendientes o conclusiones de la auditoría..."></textarea>
                <div class="notes-actions">
                    <span id="notes-status" class="notes-status">{{ noteForm.errors.body || noteStatus }}</span>
                    <button id="save-note-button" class="save-note" type="button" :disabled="noteForm.processing" @click="saveNote">GUARDAR OBSERVACIÓN</button>
                </div>
            </article>

            <div class="note">
                <span>Las fechas corresponden al inicio y a la normalización efectiva de cada condición de alarma.</span>
                <button class="go-cip" type="button" @click="go(`/plantas/${plant.code}/cip`)">Abrir trazabilidad CIP</button>
            </div>
        </section>

        <ExemysTestModal v-if="showExemys" :plant="plant" @close="showExemys = false" />
        <ConfigModal v-if="showConfig" :plant="plant" :variables="variables" :settings="settings" @close="showConfig = false" />
    </AppLayout>
</template>
