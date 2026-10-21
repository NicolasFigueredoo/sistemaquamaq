<script setup>
import { router } from '@inertiajs/vue3'

defineProps({
    subtitle: { type: String, required: true },
})

const logout = () => router.post('/logout')
</script>

<template>
    <div class="app">
        <header class="topbar">
            <img class="brand-logo ivess-logo" src="/img/ivess.jpg" alt="IVESS">
            <div class="brand">
                <h1>IVESS CONECTA 4.0</h1>
                <p id="subtitle">{{ subtitle }}</p>
            </div>
            <div class="quamaq">
                <img class="brand-logo quamaq-logo" src="/img/quamaq.jpg" alt="Quamaq Soluciones Tecnológicas">
                <div class="top-actions"><button type="button" @click="logout">SALIR</button></div>
            </div>
        </header>
        <main>
            <slot />
        </main>
    </div>
</template>
