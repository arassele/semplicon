<script setup lang="ts">
defineProps<{ id: string; label: string; hint?: string; error?: string }>();
</script>

<template>
    <div class="field">
        <label :for="id">{{ label }}</label>
        <slot :describedby="[hint ? `${id}-hint` : '', error ? `${id}-error` : ''].join(' ').trim() || undefined" :invalid="error ? true : undefined" />
        <p v-if="hint" :id="`${id}-hint`" class="hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="error">{{ error }}</p>
    </div>
</template>
