<script setup>
import { Head, router, usePoll, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '../layouts/AppLayout.vue'
import { statusColors, statusLabels, statusRank, useFormat } from '../lib/format'

const props = defineProps({
    plants: Array,
    summary: Object,
    updatedAt: Number,
    canOpen: { type: Boolean, default: true },
})

const { formatClock } = useFormat()
const page = usePage()

usePoll((page.props.panel?.pollSeconds || 30) * 1000, { only: ['plants', 'summary', 'updatedAt'] })

// Orden automático: conformes → atención → sin conexión → alarmas (y alfabético dentro de cada grupo).
const sorted = computed(() => [...props.plants].sort(
    (a, b) => statusRank[a.status] - statusRank[b.status] || a.name.localeCompare(b.name),
))

const open = (plant) => props.canOpen && router.visit(`/plantas/${plant.code}`)
</script>

<template>
    <Head title="Estado nacional" />
    <AppLayout subtitle="Proyecto central IVESS · Vista nacional multi-HMI">
        <section id="network" class="view active">
            <div class="section-title">
                <div><h2>ESTADO NACIONAL</h2><p v-if="canOpen">Seleccione una planta para abrir su pantalla individual.</p></div>
                <div class="update">Actualizado {{ formatClock(updatedAt) }}</div>
            </div>
            <div class="summary">
                <div class="stat"><span class="dot" style="background:var(--blue)"></span><div><label>Total</label><b>{{ summary.total }}</b></div></div>
                <div class="stat"><span class="dot" style="background:var(--green)"></span><div><label>Conformes</label><b>{{ summary.conforme }}</b></div></div>
                <div class="stat"><span class="dot" style="background:var(--yellow)"></span><div><label>Atención</label><b>{{ summary.atencion }}</b></div></div>
                <div class="stat"><span class="dot" style="background:var(--gray)"></span><div><label>Sin conexión</label><b>{{ summary.sin_conexion }}</b></div></div>
                <div class="stat"><span class="dot" style="background:var(--red)"></span><div><label>Alarmas</label><b>{{ summary.alarma }}</b></div></div>
            </div>
            <div class="order-note"><strong>Orden automático:</strong> conformes → atención → sin conexión → alarmas. Las plantas en alarma cierran la lista.</div>
            <div id="plant-grid" class="plant-grid" aria-live="polite">
                <button
                    v-for="plant in sorted"
                    :key="plant.code"
                    type="button"
                    class="plant-card"
                    :class="{ static: !canOpen }"
                    :style="{ '--state': statusColors[plant.status] }"
                    @click="open(plant)"
                >
                    <div class="plant-name"><span class="dot" :style="{ background: statusColors[plant.status] }"></span>{{ plant.name }}</div>
                    <div class="plant-meta"><span>{{ plant.location }}</span><span class="plant-state">{{ statusLabels[plant.status] }}</span></div>
                </button>
            </div>
            <div class="note"><span>Estados calculados en el servidor a partir de la telemetría de cada gateway.</span><span>Se actualiza automáticamente.</span></div>
        </section>
    </AppLayout>
</template>
