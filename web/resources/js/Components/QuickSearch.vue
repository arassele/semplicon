<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

/* Ricerca rapida (Ctrl/Cmd + K): voci di menu in locale; fatture, controparti, catalogo e documenti dal server.
   Tastiera: ↑ ↓ per scegliere, Invio apre, Esc chiude. Finestra di dialogo con focus trap grazie al comportamento modale (Tab resta nel dialogo). */
type Hit = { kind: string; title: string; subtitle: string | null; url: string };
const props = defineProps<{ base: string; pages: { label: string; href: string }[]; open: boolean }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const q = ref('');
const remote = ref<Hit[]>([]);
const active = ref(0);
const loading = ref(false);
const input = ref<HTMLInputElement | null>(null);
const dialog = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let ctrl: AbortController | undefined;
let restore: HTMLElement | null = null;

const kindLabel: Record<string, string> = { pagina: 'Pagina', fattura: 'Fattura', controparte: 'Controparte', articolo: 'Articolo', testo: 'Testo', ricorrente: 'Ricorrente', documento: 'Documento' };
const local = computed<Hit[]>(() => {
    const t = q.value.trim().toLowerCase();
    if (!t) return props.pages.slice(0, 8).map((p) => ({ kind: 'pagina', title: p.label, subtitle: null, url: p.href }));
    return props.pages.filter((p) => p.label.toLowerCase().includes(t)).slice(0, 5).map((p) => ({ kind: 'pagina', title: p.label, subtitle: null, url: p.href }));
});
const hits = computed(() => [...local.value, ...remote.value]);

watch(() => props.open, async (o) => {
    if (o) { restore = document.activeElement as HTMLElement | null; q.value = ''; remote.value = []; active.value = 0; await nextTick(); input.value?.focus(); } else { restore?.focus(); }
});
watch(q, (v) => {
    active.value = 0;
    clearTimeout(timer); ctrl?.abort();
    if (v.trim().length < 2) { remote.value = []; loading.value = false; return; }
    loading.value = true;
    timer = setTimeout(async () => {
        ctrl = new AbortController();
        try {
            const res = await fetch(`${props.base}/cerca?q=${encodeURIComponent(v.trim())}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal, credentials: 'same-origin' });
            if (res.ok) remote.value = ((await res.json()) as { results: Hit[] }).results;
        } catch { /* annullato oppure offline: restano i risultati locali */ }
        loading.value = false;
    }, 200);
});
onUnmounted(() => { clearTimeout(timer); ctrl?.abort(); });

function go(h: Hit | undefined) {
    if (!h) return;
    emit('close');
    if (h.kind === 'documento') window.location.href = h.url; else router.visit(h.url);
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') { e.preventDefault(); emit('close'); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(active.value + 1, hits.value.length - 1); scroll(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(active.value - 1, 0); scroll(); }
    else if (e.key === 'Enter') { e.preventDefault(); go(hits.value[active.value]); }
    else if (e.key === 'Tab') { e.preventDefault(); input.value?.focus(); }
}
const scroll = () => nextTick(() => dialog.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }));
</script>

<template>
    <div v-if="open" class="qs-backdrop" @mousedown.self="emit('close')">
        <div ref="dialog" class="qs" role="dialog" aria-modal="true" aria-label="Cerca" @keydown="onKey">
            <div class="qs-input">
                <Icon name="search" />
                <input ref="input" v-model="q" type="text" role="combobox" aria-expanded="true" aria-controls="qs-list" :aria-activedescendant="hits.length ? `qs-${active}` : undefined" aria-autocomplete="list"
                    placeholder="Cerca fatture, clienti, fornitori, articoli, pagine…" autocomplete="off" spellcheck="false">
                <kbd>Esc</kbd>
            </div>
            <ul id="qs-list" class="qs-list" role="listbox" aria-label="Risultati">
                <li v-for="(h, i) in hits" :id="`qs-${i}`" :key="`${h.kind}${h.url}${i}`" role="option" :aria-selected="i === active" :class="{ on: i === active }" @mousemove="active = i" @click="go(h)">
                    <span class="qs-kind">{{ kindLabel[h.kind] ?? h.kind }}</span>
                    <span class="qs-title">{{ h.title }}<small v-if="h.subtitle">{{ h.subtitle }}</small></span>
                </li>
            </ul>
            <p v-if="!hits.length && !loading" class="qs-empty" role="status">Nessun risultato per «{{ q }}».</p>
            <p class="qs-foot" aria-live="polite">{{ loading ? 'Ricerca…' : '↑↓ per scegliere · Invio per aprire' }}</p>
        </div>
    </div>
</template>

<style scoped>
.qs-backdrop { position: fixed; inset: 0; background: rgb(0 0 0 / 0.4); display: flex; justify-content: center; align-items: flex-start; padding: 10vh 16px 16px; z-index: 100; }
.qs { background: var(--surface, #fff); color: inherit; width: min(640px, 100%); border-radius: 12px; box-shadow: 0 20px 60px rgb(0 0 0 / 0.3); border: 1px solid var(--line, #ddd); overflow: hidden; display: flex; flex-direction: column; max-height: 72vh; }
.qs-input { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line, #ddd); }
.qs-input svg { width: 18px; flex: none; opacity: 0.6; }
.qs-input input { flex: 1; border: 0; outline: 0; background: transparent; font: inherit; font-size: 1rem; color: inherit; min-width: 0; }
.qs-input kbd, .qs-kind { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; border: 1px solid var(--line, #ddd); opacity: 0.8; white-space: nowrap; }
.qs-list { list-style: none; margin: 0; padding: 6px; overflow-y: auto; }
.qs-list li { display: flex; gap: 10px; align-items: baseline; padding: 8px 10px; border-radius: 8px; cursor: pointer; }
.qs-list li.on { background: var(--accent-soft, rgb(0 0 0 / 0.07)); outline: 2px solid var(--accent, currentColor); outline-offset: -2px; }
.qs-kind { width: 5.6rem; text-align: center; flex: none; }
.qs-title { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.qs-title small { opacity: 0.7; font-size: 0.8125rem; }
.qs-empty, .qs-foot { margin: 0; padding: 10px 16px; font-size: 0.8125rem; opacity: 0.75; }
.qs-foot { border-top: 1px solid var(--line, #ddd); }
</style>
