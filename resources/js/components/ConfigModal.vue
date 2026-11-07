<script setup>
import { useForm } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    plant: { type: Object, required: true },
    variables: { type: Array, required: true },
    settings: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const byKey = Object.fromEntries(props.variables.map((v) => [v.key, v]))
const temperature = byKey.temperature
const alkaline = byKey.alkaline
const chlorine = byKey.chlorine

const disinfectants = props.settings.disinfectants

const form = useForm({
    setpoints: {
        temperature: Number(temperature?.setpoint ?? 60).toFixed(1),
        alkaline: Number(alkaline?.setpoint ?? 0.3).toFixed(1),
        chlorine: Number(chlorine?.setpoint ?? 0.1).toFixed(2),
        ...Object.fromEntries(disinfectants.map((d) => [d.key, Number(d.setpoint).toFixed(2)])),
    },
    setpoints_high: Object.fromEntries(disinfectants.map((d) => [d.key, Number(d.setpointHigh ?? d.setpoint).toFixed(2)])),
    disinfectant: props.settings.disinfectant,
    cip_period_days: props.settings.cipPeriodDays,
})

const disinfectant = computed(() => disinfectants.find((d) => d.key === form.disinfectant))
const errors = computed(() => Object.values(form.errors))
const firstInput = ref(null)

const submit = () => {
    form.put(`/plantas/${props.plant.code}/configuracion`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    })
}

const onKey = (event) => {
    if (event.key === 'Escape') emit('close')
}
onMounted(() => {
    document.addEventListener('keydown', onKey)
    nextTick(() => firstInput.value?.focus())
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
    <section id="config-modal" class="modal-layer" @click.self="emit('close')">
        <form id="config-form" class="modal" @submit.prevent="submit">
            <div class="modal-top">
                <div>
                    <h2>Configuración de <span id="config-plant-name">{{ plant.name }}</span></h2>
                    <p>Parámetros independientes para esta planta, disponibles únicamente para el usuario administrador.</p>
                </div>
                <button id="config-close" class="modal-close" type="button" aria-label="Cerrar" @click="emit('close')">×</button>
            </div>
            <div class="config-grid">
                <label class="field"><span>Desinfectante</span><select id="disinfectant-input" ref="firstInput" v-model="form.disinfectant"><option v-for="d in disinfectants" :key="d.key" :value="d.key">{{ d.name }}</option></select><small>el que usa esta fábrica</small></label>
                <label class="field"><span>{{ disinfectant.name }} · mínimo</span><input id="disinfectant-min-input" v-model="form.setpoints[disinfectant.key]" type="number" :min="disinfectant.setpointMin ?? 0" :max="disinfectant.setpointMax ?? 100" step="0.01" required><small>{{ disinfectant.unit }} · alarma por baja</small></label>
                <label class="field"><span>{{ disinfectant.name }} · máximo</span><input id="disinfectant-max-input" v-model="form.setpoints_high[disinfectant.key]" type="number" :min="disinfectant.setpointMin ?? 0" :max="disinfectant.setpointMax ?? 100" step="0.01" required><small>{{ disinfectant.unit }} · alarma por alta</small></label>
                <label class="field"><span>{{ temperature?.name ?? 'Temperatura de lavado' }}</span><input id="temperature-setpoint-input" v-model="form.setpoints.temperature" type="number" :min="temperature?.setpointMin ?? 0" :max="temperature?.setpointMax ?? 100" step="0.1" required><small>{{ temperature?.unit ?? '°C' }}</small></label>
                <label class="field"><span>{{ alkaline?.name ?? 'Conductividad del agua' }}</span><input id="alkaline-setpoint-input" v-model="form.setpoints.alkaline" type="number" :min="alkaline?.setpointMin ?? 0" :max="alkaline?.setpointMax ?? 20" step="0.1" required><small>{{ alkaline?.unit ?? 'mS/cm' }}</small></label>
                <label class="field"><span>{{ chlorine?.name ?? 'Cloro en enjuague' }} · mínimo</span><input id="chlorine-setpoint-input" v-model="form.setpoints.chlorine" type="number" :min="chlorine?.setpointMin ?? 0" :max="chlorine?.setpointMax ?? 2" step="0.01" required><small>{{ chlorine?.unit ?? 'ppm' }} · desde este valor hay presencia</small></label>
                <label class="field"><span>Periodicidad CIP</span><input id="period-days-input" v-model="form.cip_period_days" type="number" min="1" max="30" step="1" required><small>días desde el último ciclo completo</small></label>
            </div>
            <p class="config-note">Los setpoints se reflejan en las líneas rojas de los gráficos. El desinfectante necesita un mínimo para desinfectar y un máximo para no transferir gusto. La configuración se guarda por planta y cada cambio queda registrado con su autor.</p>
            <div v-if="errors.length" class="form-errors" role="alert"><p v-for="(e, i) in errors" :key="i">{{ e }}</p></div>
            <button class="save-button" type="submit" :disabled="form.processing">GUARDAR SETPOINTS</button>
        </form>
    </section>
</template>
