<script setup>
defineProps({ support: { type: Object, required: true } })
</script>

<template>
    <div class="support-box">
        <b>¿No tenés mail registrado o no recordás cuál es?</b>
        <p>
            Contactá al proveedor del servicio, <b>{{ support.name }}</b><template v-if="support.email || support.phone">:</template>
            <a v-if="support.email" :href="`mailto:${support.email}`">{{ support.email }}</a>
            <template v-if="support.email && support.phone"> · </template>
            <a v-if="support.phone" :href="`tel:${support.phone.replace(/\s/g, '')}`">{{ support.phone }}</a>
        </p>
    </div>
</template>
