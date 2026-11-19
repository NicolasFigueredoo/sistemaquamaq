<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import SupportContact from '../components/SupportContact.vue'

const props = defineProps({
    step: { type: String, required: true }, // request | code
    identifier: { type: String, default: null },
    support: { type: Object, required: true },
})

const page = usePage()
const status = computed(() => page.props.flash?.status)

const requestForm = useForm({ identifier: props.identifier ?? '' })
const resetForm = useForm({ code: '', password: '', password_confirmation: '' })

const sendCode = () => requestForm.post('/olvide-contrasena/codigo', { preserveScroll: true })
const reset = () => resetForm.post('/olvide-contrasena', {
    onFinish: () => resetForm.reset('password', 'password_confirmation'),
})
const resend = () => {
    requestForm.identifier = props.identifier
    sendCode()
}
const restart = () => router.post('/olvide-contrasena/reiniciar')

const resetError = computed(() => resetForm.errors.code || resetForm.errors.password || requestForm.errors.identifier || '')
</script>

<template>
    <Head title="Olvidé mi contraseña" />
    <section class="login-screen">
        <div class="login-card">
            <div class="login-brand">
                <h1>RECUPERAR CONTRASEÑA</h1>
                <p>Te enviamos un código al mail registrado en tu usuario</p>
            </div>

            <p v-if="status" class="form-status" role="status">{{ status }}</p>

            <form v-if="step === 'request'" @submit.prevent="sendCode">
                <label class="field"><span>Usuario o mail</span><input v-model="requestForm.identifier" type="text" autocomplete="username" required autofocus></label>
                <button class="login-button" type="submit" :disabled="requestForm.processing">ENVIAR CÓDIGO</button>
                <div class="login-error" role="alert">{{ requestForm.errors.identifier }}</div>
            </form>

            <form v-else @submit.prevent="reset">
                <p class="login-help left">Usuario: <b>{{ identifier }}</b></p>
                <label class="field"><span>Código recibido</span><input v-model="resetForm.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
                <label class="field"><span>Contraseña nueva (mínimo 8 caracteres)</span><input v-model="resetForm.password" type="password" autocomplete="new-password" required></label>
                <label class="field"><span>Repetir contraseña nueva</span><input v-model="resetForm.password_confirmation" type="password" autocomplete="new-password" required></label>
                <button class="login-button" type="submit" :disabled="resetForm.processing">CAMBIAR CONTRASEÑA</button>
                <div class="login-error" role="alert">{{ resetError }}</div>
                <p class="login-link">
                    <button type="button" class="link-button" :disabled="requestForm.processing" @click="resend">Reenviar código</button>
                    ·
                    <button type="button" class="link-button" @click="restart">Usar otro usuario</button>
                </p>
            </form>

            <SupportContact :support="support" />
            <p class="login-link"><Link href="/login">Volver al ingreso</Link></p>
        </div>
    </section>
</template>
