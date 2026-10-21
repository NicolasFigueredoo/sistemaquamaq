<script setup>
// Consulta en vivo a la API de Exemys (equivale a `php artisan exemys:fetch`).
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({ plant: Object })
const emit = defineEmits(['close'])

const loading = ref(false)
const data = ref(null)
const error = ref('')

async function load() {
    loading.value = true
    error.value = ''
    try {
        const res = await fetch(`/plantas/${props.plant.code}/exemys`, { headers: { Accept: 'application/json' } })
        const body = await res.json().catch(() => ({}))
        if (!res.ok) throw new Error(body.error || body.message || `HTTP ${res.status}`)
        data.value = body
    } catch (e) {
        error.value = e.message
    } finally {
        loading.value = false
    }
}

const onKey = (event) => {
    if (event.key === 'Escape') emit('close')
}
onMounted(() => {
    document.addEventListener('keydown', onKey)
    load()
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
    <section class="modal-layer" @click.self="emit('close')">
        <div class="modal">
            <div class="modal-top">
                <h2>Exemys · {{ data?.desc ?? '' }}</h2>
                <button class="modal-close" type="button" aria-label="Cerrar" @click="emit('close')">×</button>
            </div>

            <div v-if="error" class="form-errors" role="alert"><p>{{ error }}</p></div>

            <template v-if="data">
                <p class="exemys-meta">
                    <span :style="{ color: data.online ? 'var(--green)' : 'var(--red)' }">{{ data.online ? 'En línea' : 'Desconectado' }}</span>
                    · último reporte hace {{ data.last_report }}
                </p>
                <table class="events-table exemys-table">
                    <thead><tr><th>Canal</th><th>Valor</th></tr></thead>
                    <tbody>
                        <tr v-for="io in data.ios" :key="io.id">
                            <td>{{ io.desc }}</td>
                            <td><b>{{ io.value ?? '—' }}</b> {{ io.unit }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
            <p v-else-if="loading" class="exemys-meta">Consultando…</p>

            <button class="save-button" type="button" :disabled="loading" @click="load">ACTUALIZAR</button>
        </div>
    </section>
</template>
