<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '../layouts/AppLayout.vue'
import SupportContact from '../components/SupportContact.vue'

const props = defineProps({
    profile: { type: Object, required: true },
    pendingEmail: { type: String, default: null },
    passwordCodeSent: Boolean,
    support: { type: Object, required: true },
})

const page = usePage()
const status = computed(() => page.props.flash?.status)

const emailForm = useForm({ email: props.pendingEmail ?? props.profile.email ?? '' })
const emailCodeForm = useForm({ email_code: '' })
const passwordCodeForm = useForm({})
const passwordForm = useForm({ password_code: '', password: '', password_confirmation: '' })

const keep = { preserveScroll: true }
const sendEmailCode = () => emailForm.post('/perfil/mail/codigo', keep)
const confirmEmail = () => emailCodeForm.put('/perfil/mail', { ...keep, onSuccess: () => emailCodeForm.reset() })
const sendPasswordCode = () => passwordCodeForm.post('/perfil/contrasena/codigo', keep)
const changePassword = () => passwordForm.put('/perfil/contrasena', {
    ...keep,
    onSuccess: () => passwordForm.reset(),
    onFinish: () => passwordForm.reset('password', 'password_confirmation'),
})

const passwordError = computed(() => passwordCodeForm.errors.password_code || passwordForm.errors.password_code || passwordForm.errors.password || '')
</script>

<template>
    <Head title="Mi perfil" />
    <AppLayout subtitle="Mi perfil">
        <section class="view active">
            <div class="section-title">
                <div><h2>MI PERFIL</h2><p>{{ profile.name }} · usuario <b>{{ profile.username }}</b></p></div>
                <Link href="/" class="back-link">← Volver al panel</Link>
            </div>

            <p v-if="status" class="form-status" role="status">{{ status }}</p>

            <div class="profile-grid">
                <article class="panel">
                    <div class="panel-head"><h3>MAIL</h3></div>
                    <p class="profile-note">
                        <template v-if="profile.email">Registrado: <b>{{ profile.email }}</b> <span v-if="!profile.emailVerified">(sin confirmar)</span></template>
                        <template v-else>Todavía no tenés un mail registrado. Lo necesitás para cambiar o recuperar tu contraseña, y también podés usarlo para ingresar.</template>
                    </p>
                    <form @submit.prevent="sendEmailCode">
                        <label class="field"><span>{{ profile.email ? 'Mail nuevo' : 'Mail' }}</span><input v-model="emailForm.email" type="email" autocomplete="email" required></label>
                        <button class="save-button" type="submit" :disabled="emailForm.processing">ENVIAR CÓDIGO DE CONFIRMACIÓN</button>
                        <div class="login-error" role="alert">{{ emailForm.errors.email }}</div>
                    </form>
                    <form v-if="pendingEmail" @submit.prevent="confirmEmail">
                        <p class="profile-note">Ingresá el código que enviamos a <b>{{ pendingEmail }}</b>.</p>
                        <label class="field"><span>Código</span><input v-model="emailCodeForm.email_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
                        <button class="save-button" type="submit" :disabled="emailCodeForm.processing">CONFIRMAR MAIL</button>
                        <div class="login-error" role="alert">{{ emailCodeForm.errors.email_code }}</div>
                    </form>
                </article>

                <article class="panel">
                    <div class="panel-head"><h3>CONTRASEÑA</h3></div>
                    <template v-if="profile.email">
                        <p class="profile-note">Para cambiarla te mandamos un código a <b>{{ profile.email }}</b>, así confirmamos que sos vos.</p>
                        <button class="save-button" :class="{ 'profile-bottom': !pendingEmail && !passwordCodeSent }" type="button" :disabled="passwordCodeForm.processing" @click="sendPasswordCode">
                            {{ passwordCodeSent ? 'REENVIAR CÓDIGO' : 'ENVIAR CÓDIGO' }}
                        </button>
                        <form v-if="passwordCodeSent" @submit.prevent="changePassword">
                            <label class="field"><span>Código recibido</span><input v-model="passwordForm.password_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label>
                            <label class="field"><span>Contraseña nueva (mínimo 8 caracteres)</span><input v-model="passwordForm.password" type="password" autocomplete="new-password" required></label>
                            <label class="field"><span>Repetir contraseña nueva</span><input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" required></label>
                            <button class="save-button" type="submit" :disabled="passwordForm.processing">CAMBIAR CONTRASEÑA</button>
                        </form>
                        <div class="login-error" role="alert">{{ passwordError }}</div>
                    </template>
                    <template v-else>
                        <p class="profile-note">Primero registrá y confirmá un mail en el recuadro de la izquierda.</p>
                        <SupportContact :support="support" />
                    </template>
                </article>
            </div>
        </section>
    </AppLayout>
</template>
