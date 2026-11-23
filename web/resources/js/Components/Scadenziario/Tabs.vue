<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{ base: string; current: string }>();
const tabs = [['da-incassare', 'Da incassare'], ['da-pagare', 'Da pagare'], ['incassi-e-pagamenti', 'Movimenti'], ['riepilogo-fiscale', 'Riepilogo per cassa']];
</script>

<template>
    <nav class="tabs" aria-label="Incassi e pagamenti">
        <Link v-for="[k, l] in tabs" :key="k" :href="`${base}/scadenziario/${k}`" class="tab" :aria-current="current === k ? 'page' : undefined">{{ l }}</Link>
    </nav>
</template>
