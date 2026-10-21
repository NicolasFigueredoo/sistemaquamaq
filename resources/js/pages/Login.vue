<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'

defineProps({ demoAccess: Boolean })

const form = useForm({ username: '', password: '' })
const userInput = ref(null)
const error = computed(() => form.errors.username || form.errors.password || '')

const submit = () => {
    form.transform((data) => ({ ...data, username: data.username.trim() }))
        .post('/login', { onFinish: () => form.reset('password') })
}

onMounted(() => userInput.value?.focus())
</script>

<template>
    <Head title="Ingreso" />
    <section class="login-screen">
        <form class="login-card" @submit.prevent="submit">
            <div class="login-brand">
                <div class="login-logos">
                    <img class="brand-logo login-ivess-logo" src="/img/ivess.jpg" alt="IVESS">
                    <img class="brand-logo login-quamaq-logo" src="/img/quamaq.jpg" alt="Quamaq Soluciones Tecnológicas">
                </div>
                <h1>IVESS CONECTA 4.0</h1>
                <p>Acceso seguro a la plataforma de monitoreo</p>
            </div>
            <label class="field"><span>Usuario</span><input id="login-user" ref="userInput" v-model="form.username" type="text" autocomplete="username" required></label>
            <label class="field"><span>Contraseña</span><input id="login-password" v-model="form.password" type="password" autocomplete="current-password" required></label>
            <button class="login-button" type="submit" :disabled="form.processing">INGRESAR</button>
            <div class="login-error" role="alert">{{ error }}</div>
            <p v-if="demoAccess" class="login-help">Acceso demostrativo: usuario <b>admin</b> · contraseña <b>IVESS40</b></p>
        </form>
    </section>
</template>
