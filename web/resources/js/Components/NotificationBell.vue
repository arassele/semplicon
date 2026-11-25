<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/* Notifiche (campanella): numero del lavoro aperto dal menu; il pannello carica attività e scadenze dei prossimi 14 giorni solo all’apertura. */
const props = defineProps<{ base: string; counts: Record<string, number> | null }>();
type Task = { key: string; count: number; label: string; url: string; tone: string };
type Due = { date: string; label: string; url: string; overdue: boolean };
const open = ref(false);
const loading = ref(false);
const data = ref<{ tasks: Task[]; deadlines: Due[] } | null>(null);
const total = computed(() => Object.values(props.counts ?? {}).reduce((t, n) => t + (n || 0), 0));
const late = computed(() => (props.counts?.da_incassare ?? 0) + (props.counts?.da_pagare ?? 0) > 0);
const root = ref<HTMLElement | null>(null);
const short = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;

async function toggle() {
    open.value = !open.value;
    if (!open.value) return;
    loading.value = true;
    try {
        const res = await fetch(`${props.base}/notifiche`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        data.value = res.ok ? await res.json() : { tasks: [], deadlines: [] };
    } finally { loading.value = false; }
}
const onDoc = (e: MouseEvent) => { if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false; };
const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && open.value) open.value = false; };
onMounted(() => { document.addEventListener('click', onDoc); document.addEventListener('keydown', onKey); });
onUnmounted(() => { document.removeEventListener('click', onDoc); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div ref="root" class="bell-wrap">
        <button class="bell-btn" type="button" :aria-expanded="open" aria-controls="bell-pop" :aria-label="total ? `Notifiche: ${total} da fare` : 'Notifiche'" @click="toggle">
            <Icon name="bell" />
            <span v-if="total" class="bell-n" :class="{ late }">{{ total }}</span>
        </button>
        <div v-if="open" id="bell-pop" class="bell-pop" role="region" aria-label="Notifiche">
            <div class="bell-h"><strong>Notifiche</strong><button class="btn link" type="button" @click="open = false">Chiudi</button></div>
            <p v-if="loading" class="small muted bell-empty">Caricamento…</p>
            <template v-else-if="data">
                <template v-if="data.tasks.length">
                    <p class="bell-s">Da fare</p>
                    <ul><li v-for="t in data.tasks" :key="t.key"><Link :href="t.url" @click="open = false"><span class="dash-count tabular" :class="t.tone">{{ t.count }}</span><span>{{ t.label }}</span></Link></li></ul>
                </template>
                <template v-if="data.deadlines.length">
                    <p class="bell-s">Scadenze (14 giorni)</p>
                    <ul><li v-for="(d, i) in data.deadlines" :key="i"><Link :href="d.url" @click="open = false"><span class="tabular bell-d" :class="{ late: d.overdue }">{{ short(d.date) }}</span><span>{{ d.label }}</span></Link></li></ul>
                </template>
                <p v-if="!data.tasks.length && !data.deadlines.length" class="small muted bell-empty">Nessuna notifica.</p>
            </template>
        </div>
    </div>
</template>
