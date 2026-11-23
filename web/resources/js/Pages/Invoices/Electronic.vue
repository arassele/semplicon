<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Fatture elettroniche: panoramica delle fatture XML emesse. La trasmissione al SdI avviene con un programma esterno;
   qui i file vengono esportati in blocco e viene annotato lo stato “trasmessa”. */
type Row = { id: number; number: string; doc_type: string; doc_date: string; customer: string; total: string; filename: string | null; sha256: string | null; transmitted_at: string | null; note: string | null };
const props = defineProps<{ filters: { anno: number | 'tutti'; stato: string | null; q: string }; today: string; max: number; counts: { tutte: number; da_trasmettere: number; trasmesse: number }; invoices: Row[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const picked = ref<number[]>([]);
const allOn = computed(() => props.invoices.length > 0 && picked.value.length === props.invoices.length);
const toggleAll = () => { picked.value = allOn.value ? [] : props.invoices.slice(0, props.max).map((i) => i.id); };
const tx = ref({ date: props.today, note: '' });
const search = ref(props.filters.q);
const busy = ref(false);
const error = ref('');
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
function nav(extra: Record<string, string | undefined>) { picked.value = []; router.get(`${base.value}/fatture-elettroniche`, { stato: props.filters.stato ?? undefined, q: props.filters.q || undefined, ...extra }, { preserveState: true, replace: true }); }
async function exportZip(pdf: boolean) {
    busy.value = true; error.value = '';
    try {
        const res = await fetch(`${base.value}/fatture-elettroniche/esporta`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/zip, application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ ids: picked.value, pdf }) });
        if (!res.ok) { const j = await res.json().catch(() => ({})); error.value = (j.errors && (Object.values(j.errors) as string[][]).flat()[0]) || j.message || 'Esportazione non riuscita.'; busy.value = false; return; }
        const blob = await res.blob(), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = (res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1]) ?? 'fatture-elettroniche.zip'; document.body.appendChild(a); a.click(); a.remove();
    } catch { error.value = 'Esportazione non riuscita. Riprova.'; }
    busy.value = false;
}
const mark = () => router.post(`${base.value}/fatture-elettroniche/trasmessa`, { ids: picked.value, date: tx.value.date, note: tx.value.note || null }, { preserveScroll: true, onSuccess: () => { picked.value = []; tx.value.note = ''; }, onError: (e) => { error.value = Object.values(e)[0] as string; } });
const unmark = () => router.post(`${base.value}/fatture-elettroniche/da-trasmettere`, { ids: picked.value }, { preserveScroll: true, onSuccess: () => { picked.value = []; } });
</script>

<template>
    <Head title="Fatture emesse" />
    <AppLayout crumb="Fatture emesse">
        <div class="page-head">
            <div>
                <p class="eyebrow">Fatturazione</p>
                <h1>Fatture emesse</h1>
                <p>Le fatture emesse con Semplicon ({{ filters.anno === 'tutti' ? 'tutti gli anni' : `anno ${filters.anno}` }}). Semplicon non trasmette al SdI: scarica i file, inviali con il tuo programma o dal portale e poi segna qui le fatture come <strong>trasmesse</strong>.</p>
            </div>
            <div class="actions"><Link :href="`${base}/fatture/emetti`" class="btn primary"><Icon name="plus" />Emetti fattura</Link></div>
        </div>

        <nav class="segmented" aria-label="Stato di trasmissione" style="margin-bottom: 16px">
            <a href="#" :aria-current="!filters.stato ? 'page' : undefined" @click.prevent="nav({ stato: undefined })">Tutte ({{ counts.tutte }})</a>
            <a href="#" :aria-current="filters.stato === 'da_trasmettere' ? 'page' : undefined" @click.prevent="nav({ stato: 'da_trasmettere' })">Da trasmettere ({{ counts.da_trasmettere }})</a>
            <a href="#" :aria-current="filters.stato === 'trasmesse' ? 'page' : undefined" @click.prevent="nav({ stato: 'trasmesse' })">Trasmesse ({{ counts.trasmesse }})</a>
        </nav>

        <section class="panel">
            <div class="panel-head">
                <form class="search-row" role="search" @submit.prevent="nav({ q: search || undefined })"><label class="sr-only" for="eq">Cerca</label><input id="eq" v-model="search" class="input" type="search" placeholder="Numero, cliente o partita IVA"><button class="btn" type="submit">Cerca</button></form>
            </div>
            <div v-if="picked.length" class="bulk" role="region" aria-label="Azioni sulle fatture selezionate">
                <strong>{{ picked.length }} {{ picked.length === 1 ? 'selezionata' : 'selezionate' }}</strong>
                <button class="btn" type="button" :disabled="busy" @click="exportZip(false)"><Icon name="download" />Scarica XML (ZIP)</button>
                <button class="btn" type="button" :disabled="busy" @click="exportZip(true)"><Icon name="download" />XML + PDF (ZIP)</button>
                <span class="bulk-sep" />
                <label class="inline">Trasmessa il <input v-model="tx.date" type="date" class="input" :max="today"></label>
                <label class="inline">Nota <input v-model="tx.note" class="input" maxlength="255" placeholder="es. inviata con …, ricevuta n."></label>
                <button class="btn primary" type="button" @click="mark"><Icon name="check" />Segna come trasmessa</button>
                <button class="btn link" type="button" @click="unmark">Riporta a «da trasmettere»</button>
            </div>
            <p v-if="error" class="error" role="alert" style="padding: 0 20px">{{ error }}</p>
            <div v-if="invoices.length" class="table-wrap">
                <table>
                    <thead><tr><th><input type="checkbox" :checked="allOn" aria-label="Seleziona tutte" @change="toggleAll"></th><th>Numero</th><th>Data</th><th>Cliente</th><th class="num">Totale</th><th>File XML</th><th>Stato</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="i in invoices" :key="i.id">
                            <td><input v-model="picked" type="checkbox" :value="i.id" :aria-label="`Seleziona la fattura ${i.number}`"></td>
                            <td><Link :href="`${base}/fatture/${i.id}`">{{ i.number }}</Link><span class="small muted"> {{ i.doc_type }}</span></td>
                            <td class="tabular">{{ date(i.doc_date) }}</td>
                            <td>{{ i.customer }}</td>
                            <td class="num tabular">{{ money(i.total) }}</td>
                            <td class="small"><span class="tabular">{{ i.filename }}</span><br><span class="muted tabular">SHA-256 {{ i.sha256 }}…</span></td>
                            <td><span v-if="i.transmitted_at" class="badge good">Trasmessa {{ date(i.transmitted_at) }}</span><span v-else class="badge warn">Da trasmettere</span><p v-if="i.note" class="small muted" style="margin: 2px 0 0">{{ i.note }}</p></td>
                            <td class="num" style="white-space: nowrap"><Link :href="`${base}/fatture/${i.id}/anteprima-xml`" class="btn link">Anteprima</Link> <a :href="`${base}/fatture/${i.id}/xml`" class="btn link">XML</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="panel-body"><p class="muted">Nessuna fattura emessa per questa selezione.</p></div>
        </section>
    </AppLayout>
</template>

<style scoped>
.search-row { display: flex; gap: 8px; width: 100%; max-width: 480px; }
.search-row .input { flex: 1; }
.bulk { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 20px; background: var(--ct-tint, #edf9f1); border-block: 1px solid var(--ct-border, #dce3dd); }
.bulk .inline { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8125rem; }
.bulk .inline .input { width: auto; min-width: 0; }
.bulk-sep { flex: 1 1 8px; }
</style>
