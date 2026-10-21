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
const ozone = byKey.ozone
const temperature = byKey.temperature
const alkaline = byKey.alkaline

const form = useForm({
    setpoints: {
        ozone: Number(ozone?.setpoint ?? 0.5).toFixed(2),
        temperature: Number(temperature?.setpoint ?? 60).toFixed(1),
        alkaline: Number(alkaline?.setpoint ?? 20).toFixed(1),
    },
    cip_period_days: props.settings.cipPeriodDays,
    tank_volume_l: String(props.settings.tankVolume),
    product_liters: Number(props.settings.productLiters).toFixed(1),
})

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
                <label class="field"><span>{{ ozone?.name ?? 'Ozono en llenadora' }}</span><input id="ozone-setpoint-input" ref="firstInput" v-model="form.setpoints.ozone" type="number" :min="ozone?.setpointMin ?? 0" :max="ozone?.setpointMax ?? 100" step="0.01" required><small>{{ ozone?.unit ?? 'ppm' }}</small></label>
                <label class="field"><span>{{ temperature?.name ?? 'Temperatura de lavado' }}</span><input id="temperature-setpoint-input" v-model="form.setpoints.temperature" type="number" :min="temperature?.setpointMin ?? 0" :max="temperature?.setpointMax ?? 100" step="0.5" required><small>{{ temperature?.unit ?? '°C' }}</small></label>
                <label class="field"><span>{{ alkaline?.name ?? 'Producto alcalino' }}</span><input id="alkaline-setpoint-input" v-model="form.setpoints.alkaline" type="number" :min="alkaline?.setpointMin ?? 0" :max="alkaline?.setpointMax ?? 100" step="0.1" required><small>{{ alkaline?.unit ?? 'mS/cm' }}</small></label>
                <label class="field"><span>Periodicidad CIP</span><input id="period-days-input" v-model="form.cip_period_days" type="number" min="1" max="30" step="1" required><small>días desde el último ciclo completo</small></label>
                <label class="field"><span>Volumen del tanque de lavado</span><input id="tank-volume-input" v-model="form.tank_volume_l" type="number" min="1" step="1" required><small>litros</small></label>
                <label class="field"><span>Producto para alcanzar el setpoint</span><input id="product-liters-input" v-model="form.product_liters" type="number" min="0" step="0.1" required><small>litros volcados</small></label>
            </div>
            <p class="config-note">Los setpoints admiten valores de 0 a 100 y se reflejan en las líneas rojas de los gráficos. El porcentaje estimado de producto alcalino se calcula como: litros de producto ÷ volumen del tanque × 100. La configuración se guarda por planta y cada cambio queda registrado con su autor.</p>
            <div v-if="errors.length" class="form-errors" role="alert"><p v-for="(e, i) in errors" :key="i">{{ e }}</p></div>
            <button class="save-button" type="submit" :disabled="form.processing">GUARDAR SETPOINTS</button>
        </form>
    </section>
</template>
