<script setup>
// Gráfico de tendencia: rango de 1 h, 1 día, 7 días o 30 días, escala vertical según los datos visibles.
// Las horas sin datos (equipo caído) cortan la línea en lugar de inventar valores.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useFormat } from '../lib/format'

const props = defineProps({
    variable: { type: Object, required: true }, // { key, name, alertName, unit, decimals, chartColor }
    setpoint: { type: Number, required: true },
    ranges: { type: Object, required: true },   // { '1h': { from, to, gap, series: { key: [[ms, valor], ...] } }, ... }
})

const { formatComma, formatDateTime, formatShort, formatClock } = useFormat()

const OPTIONS = [
    { key: '1h', label: '1 h', days: 0 },
    { key: '1d', label: '1 día', days: 1 },
    { key: '7d', label: '7 días', days: 7 },
    { key: '30d', label: '30 días', days: 30 },
]
const DAY = 86400000

// days = 0 → última hora; 1 → 24 h (promedios de 10 min); 2 a 30 → promedios horarios recortados.
const days = ref(1)
const title = computed(() => {
    if (days.value === 0) return 'última hora'
    return days.value === 1 ? 'últimas 24 horas' : `últimos ${days.value} días`
})

function currentRange() {
    if (days.value === 0) return props.ranges?.['1h']
    if (days.value === 1) return props.ranges?.['1d']
    const base = props.ranges?.['30d']
    if (!base) return null
    const from = base.to - days.value * DAY
    const series = {}
    for (const [key, points] of Object.entries(base.series)) series[key] = points.filter((p) => p[0] >= from)
    return { ...base, from, series }
}

const scroll = ref(null)
const svg = ref(null)
const readout = ref('')
const defaultReadout = '<span class="trend-key">● Cruce del setpoint.</span> Haga clic para consultar fecha, hora y valor.'
readout.value = defaultReadout

let geometry = null

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

// Escala vertical: datos visibles + setpoint, con un margen. Redondea a valores "lindos".
function scale(values, setpoint) {
    let min = Math.min(setpoint, ...values)
    let max = Math.max(setpoint, ...values)
    const span = max - min || Math.max(Math.abs(max) * 0.1, 1)
    const positive = min >= 0
    min -= span * 0.1
    max += span * 0.1
    if (positive) min = Math.max(0, min) // sin valores negativos, la escala no baja de 0
    const step = 10 ** Math.floor(Math.log10(max - min)) / 2
    return { min: Math.floor(min / step) * step, max: Math.ceil(max / step) * step }
}

function render() {
    const el = scroll.value
    if (!el || !svg.value) return

    const r = currentRange()
    if (!r) return
    const points = r.series[props.variable.key] || []
    const decimals = props.variable.decimals
    const unit = props.variable.unit
    const setpoint = props.setpoint
    const { min, max } = scale(points.map((p) => p[1]), setpoint)

    const width = Math.max(320, el.clientWidth || 720)
    const left = 52, right = 20, top = 18, plotHeight = 170, plotWidth = width - left - right
    const x = (t) => left + ((t - r.from) / (r.to - r.from)) * plotWidth
    const y = (value) => top + ((max - clamp(value, min, max)) / (max - min)) * plotHeight
    const setY = y(setpoint)
    const timeLabel = days.value <= 1 ? (t) => formatClock(t).slice(0, 5) : formatShort

    let markup = ''
    ;[0, 0.5, 1].forEach((f) => {
        const yy = top + f * plotHeight
        const value = max - f * (max - min)
        markup += `<line class="trend-grid" x1="${left}" y1="${yy}" x2="${width - right}" y2="${yy}"/><text class="trend-axis" x="4" y="${yy + 4}">${formatComma(value, decimals)}</text>`
    })
    const ticks = width < 600 ? 3 : 6
    for (let i = 0; i < ticks; i++) {
        const t = r.from + ((r.to - r.from) * i) / ticks
        const xx = x(t)
        markup += `<line class="trend-grid" x1="${xx}" y1="${top}" x2="${xx}" y2="${top + plotHeight}"/><text class="trend-axis" x="${xx + 4}" y="${top + plotHeight + 25}">${timeLabel(t)}</text>`
    }

    // Tramos continuos: se corta donde hay un hueco mayor al esperado.
    const segments = []
    let segment = []
    points.forEach((p, i) => {
        if (i > 0 && p[0] - points[i - 1][0] > r.gap) {
            segments.push(segment)
            segment = []
        }
        segment.push(`${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`)
    })
    if (segment.length) segments.push(segment)

    const crossings = []
    for (let i = 1; i < points.length; i++) {
        const [ta, a] = points[i - 1]
        const [tb, b] = points[i]
        if (tb - ta > r.gap) continue
        if ((a < setpoint && b >= setpoint) || (a >= setpoint && b < setpoint)) {
            const t = ta + (tb - ta) * ((setpoint - a) / (b - a))
            crossings.push({ cx: x(t), time: t })
        }
    }

    const color = props.variable.chartColor
    const key = props.variable.key
    markup += `<line class="trend-setpoint" x1="${left}" y1="${setY}" x2="${width - right}" y2="${setY}"/>`
        + `<text class="trend-setpoint-label" x="${left + 8}" y="${Math.max(13, setY - 6)}">SETPOINT ${formatComma(setpoint, decimals)} ${unit}</text>`
        + segments.map((s) => s.length === 1
            ? `<circle cx="${s[0].split(',')[0]}" cy="${s[0].split(',')[1]}" r="2" fill="${color}"/>`
            : `<polyline class="trend-path" stroke="${color}" points="${s.join(' ')}"/>`).join('')
        + crossings.map((p) => `<circle class="trend-milestone" cx="${p.cx}" cy="${setY}" r="5"><title>${formatDateTime(p.time)} · cruce del setpoint · ${formatComma(setpoint, decimals)} ${unit}</title></circle>`).join('')
        + (points.length ? '' : `<text class="trend-axis" x="${left + plotWidth / 2}" y="${top + plotHeight * 0.3}" text-anchor="middle">Sin datos en este período</text>`)
        + `<line id="${key}-guide" x1="0" y1="${top}" x2="0" y2="${top + plotHeight}" stroke="#183651" stroke-width="1" opacity="0"/>`
        + `<circle id="${key}-selected" class="trend-selected" cx="0" cy="0" r="5" opacity="0"/>`
        + `<rect class="trend-hit" x="${left}" y="${top}" width="${plotWidth}" height="${plotHeight}"/>`

    svg.value.setAttribute('viewBox', `0 0 ${width} 230`)
    svg.value.style.width = `${width}px`
    svg.value.innerHTML = markup
    geometry = { width, left, plotWidth, points, r, x, y, decimals, unit }
}

function onSvgClick(event) {
    if (!geometry || !event.target.classList?.contains('trend-hit')) return
    const { width, left, plotWidth, points, r, x, y, decimals, unit } = geometry
    const rect = svg.value.getBoundingClientRect()
    const clickX = (event.clientX - rect.left) * (width / rect.width)
    const t = r.from + clamp((clickX - left) / plotWidth, 0, 1) * (r.to - r.from)
    const key = props.variable.key
    const guide = svg.value.querySelector(`#${key}-guide`)
    const marker = svg.value.querySelector(`#${key}-selected`)

    // Punto más cercano al clic; si está lejos (hueco), "sin datos".
    let nearest = null
    for (const p of points) {
        if (!nearest || Math.abs(p[0] - t) < Math.abs(nearest[0] - t)) nearest = p
    }
    const xx = nearest && Math.abs(nearest[0] - t) <= r.gap ? x(nearest[0]) : clickX
    guide.setAttribute('x1', xx)
    guide.setAttribute('x2', xx)
    guide.setAttribute('opacity', '1')
    if (!nearest || Math.abs(nearest[0] - t) > r.gap) {
        marker.setAttribute('opacity', '0')
        readout.value = `<strong>${formatDateTime(t)}</strong> · Sin datos`
        return
    }
    marker.setAttribute('cx', xx)
    marker.setAttribute('cy', y(nearest[1]))
    marker.setAttribute('opacity', '1')
    readout.value = `<strong>${formatDateTime(nearest[0])}</strong> · ${formatComma(nearest[1], decimals)} ${unit}`
}

function setDays(value) {
    days.value = Number(value)
    readout.value = defaultReadout
    render()
}

let resizeTimer
const onResize = () => {
    clearTimeout(resizeTimer)
    resizeTimer = setTimeout(render, 120)
}

onMounted(() => {
    nextTick(render)
    window.addEventListener('resize', onResize, { passive: true })
})
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize)
    clearTimeout(resizeTimer)
})

// Datos nuevos del polling o setpoint cambiado: se redibuja manteniendo el rango elegido.
watch(() => [props.ranges, props.setpoint], render)
</script>

<template>
    <article class="panel trend-panel" :data-chart="variable.key">
        <div class="panel-head">
            <div><h3>{{ variable.name }}</h3><span>Histórico de {{ title }} · {{ variable.unit }}</span></div>
            <div class="trend-range">
                <label class="trend-days">
                    <span>{{ days === 0 ? '1 h' : `${days} ${days === 1 ? 'día' : 'días'}` }}</span>
                    <input type="range" min="1" max="30" step="1" :value="Math.max(1, days)" aria-label="Días a mostrar" @input="setDays($event.target.value)">
                </label>
                <div class="trend-controls">
                    <button v-for="o in OPTIONS" :key="o.key" type="button" :class="{ active: days === o.days }" @click="setDays(o.days)">{{ o.label }}</button>
                </div>
            </div>
        </div>
        <div ref="scroll" class="trend-scroll">
            <svg ref="svg" class="trend-svg" role="img" :aria-label="`Histórico de ${variable.alertName.toLowerCase()}`" @click="onSvgClick"></svg>
        </div>
        <div class="trend-readout" aria-live="polite" v-html="readout"></div>
    </article>
</template>
