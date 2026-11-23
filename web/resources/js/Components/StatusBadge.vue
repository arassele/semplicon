<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string; label: string }>();
const tone = computed(() => ({
    confermato: 'good',
    bozza: 'warn',
    da_verificare: 'warn',
    bloccato: 'bad',
    sostituito: '',
}[props.status] ?? ''));
</script>

<template>
    <span class="badge" :class="tone">{{ label }}</span>
</template>
