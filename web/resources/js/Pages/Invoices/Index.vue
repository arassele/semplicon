<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Row = { has_xml: boolean; deletable: boolean; id: number; direction: string | null; direction_label: string; doc_type: string; doc_type_label: string; number: string; doc_date: string; receipt_date: string | null; year: number; counterparty: string; currency: string; taxable_total: string; tax_total: string; status: string; status_label: string };
type Paginated = { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
const props = defineProps<{
    direction: string | null;
    filters: { stato: string | null; anno: number | 'tutti'; q: string; ordina: string; verso: string };
    years: number[];
    invoices: Paginated;
    totals: { count: number; taxable: string; tax: string };
    counts: { acquisto: number; vendita: number; archiviata: number; da_verificare: number; aperte: number; registrata: number };
}>();

const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const listUrl = computed(() => (props.direction === 'acquisto' ? `${base.value}/acquisti` : props.direction === 'vendita' ? `${base.value}/vendite` : `${base.value}/fatture`));
const title = computed(() => (props.direction === 'acquisto' ? 'Acquisti' : props.direction === 'vendita' ? 'Vendite' : 'Fatture'));
const f = reactive({ stato: props.filters.stato ?? '', anno: String(props.filters.anno), q: props.filters.q, ordina: props.filters.ordina, verso: props.filters.verso });
const apply = () => router.get(listUrl.value, Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), { preserveState: true, preserveScroll: true });
function sortBy(k: string) { if (f.ordina === k) f.verso = f.verso === 'asc' ? 'desc' : 'asc'; else { f.ordina = k; f.verso = k === 'data' || k === 'imponibile' ? 'desc' : 'asc'; } apply(); }
const sortMark = (k: string) => (f.ordina === k ? (f.verso === 'asc' ? ' ↑' : ' ↓') : '');
// Ritorno all’elenco con stato, ricerca, filtro, ordinamento e pagina (Sezione 3).
const here = computed(() => (typeof window !== 'undefined' ? window.location.pathname + window.location.search : listUrl.value));
const view = (i: Row) => `${base.value}/fatture/${i.id}?vista=1&ritorno=${encodeURIComponent(here.value)}`;
const isPurchaseList = computed(() => props.direction === 'acquisto');
/* Selezione multipla: documenti (ZIP), elenco (CSV), eliminazione delle fatture non registrate */
const picked = ref<number[]>([]);
const allOn = computed(() => props.invoices.data.length > 0 && picked.value.length === props.invoices.data.length);
const toggleAll = () => { picked.value = allOn.value ? [] : props.invoices.data.map((i) => i.id); };
const busy = ref(false);
const error = ref('');
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
async function download(kind: 'documenti' | 'csv') {
    busy.value = true; error.value = '';
    try {
        const res = await fetch(`${base.value}/fatture/seleziona/${kind}`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/zip, text/csv, application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ ids: picked.value }) });
        if (!res.ok) { error.value = res.status === 404 ? 'Nessun documento disponibile per la selezione.' : 'Esportazione non riuscita.'; busy.value = false; return; }
        const blob = await res.blob(), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? `export.${kind === 'csv' ? 'csv' : 'zip'}`; document.body.appendChild(a); a.click(); a.remove();
    } catch { error.value = 'Esportazione non riuscita.'; }
    busy.value = false;
}
function remove(ids: number[], what: string) {
    if (!confirm(`Eliminare ${what}? Il file importato viene rimosso e potrà essere importato di nuovo. Le fatture già registrate non vengono eliminate.`)) return;
    router.post(`${base.value}/fatture/seleziona/elimina`, { ids }, { preserveScroll: true, onSuccess: () => { picked.value = picked.value.filter((x) => !ids.includes(x)); } });
}
const docUrl = (i: Row) => (i.has_xml ? `${base.value}/fatture/${i.id}/anteprima-xml` : view(i));
const label = (l: string) => l.replace('&laquo;', '‹').replace('&raquo;', '›').replace('Previous', 'Precedente').replace('Next', 'Successiva');
</script>

<template>
    <Head :title="title" />
    <AppLayout :crumb="title">
        <div class="page-head">
            <div>
                <p class="eyebrow">Documenti</p>
                <h1>{{ title }}</h1>
                <p>Fatture importate e registrate. Con «Registra» registri i documenti uno dopo l’altro.</p>
            </div>
            <div class="actions">
                <Link :href="`${base}/importa`" class="btn"><Icon name="upload" />Importa XML</Link>
                <Link v-if="direction === 'vendita'" :href="`${base}/fatture/emetti`" class="btn primary"><Icon name="plus" />Emetti fattura elettronica</Link>
                <Link :href="`${base}/fatture/nuova${direction === 'vendita' ? '?direzione=vendita' : ''}`" class="btn"><Icon name="plus" />{{ direction === 'acquisto' ? 'Nuova fattura di acquisto' : direction === 'vendita' ? 'Registra fattura di vendita già emessa' : 'Fattura manuale' }}</Link>
                <Link v-if="counts.aperte" :href="`${base}/verifica`" class="btn primary"><Icon name="check" />Verifica in sequenza ({{ counts.aperte }})</Link>
            </div>
        </div>

        <nav class="tabs" aria-label="Tipo">
            <Link :href="`${base}/fatture`" :aria-current="!direction ? 'page' : undefined">Tutte</Link>
            <Link :href="`${base}/acquisti`" :aria-current="direction === 'acquisto' ? 'page' : undefined">Acquisti <span class="count">{{ counts.acquisto }}</span></Link>
            <Link :href="`${base}/vendite`" :aria-current="direction === 'vendita' ? 'page' : undefined">Vendite <span class="count">{{ counts.vendita }}</span></Link>
        </nav>

        <section class="panel">
            <form class="filters" @submit.prevent="apply">
                <label class="sr-only" for="q">Cerca</label>
                <input id="q" v-model="f.q" class="input" type="search" placeholder="Numero o controparte">
                <label class="sr-only" for="stato">Stato</label>
                <select id="stato" v-model="f.stato" class="input" @change="apply">
                    <option value="">Tutti gli stati</option>
                    <option value="aperte">Da registrare ({{ counts.aperte }})</option>
                    <option value="da_verificare">Con segnalazioni ({{ counts.da_verificare }})</option>
                    <option value="registrata">Registrate ({{ counts.registrata }})</option>
                    <option value="scartata">Scartate</option>
                    <option v-if="direction !== 'vendita'" value="archiviata">Archiviate ({{ counts.archiviata }})</option>
                </select>
                <label for="anno" class="small muted">Anno</label>
                <select id="anno" v-model="f.anno" class="input tabular" style="max-width: 120px" @change="apply">
                    <option value="tutti">Tutti</option>
                    <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                </select>
                <button class="btn" type="submit">Filtra</button>
            </form>
            <div v-if="picked.length" class="bulk" role="region" aria-label="Azioni sui documenti selezionati">
                <strong>{{ picked.length }} {{ picked.length === 1 ? 'selezionato' : 'selezionati' }}</strong>
                <button class="btn" type="button" :disabled="busy" @click="download('documenti')"><Icon name="download" />Documenti (ZIP)</button>
                <button class="btn" type="button" :disabled="busy" @click="download('csv')"><Icon name="download" />Elenco (CSV)</button>
                <button class="btn danger" type="button" @click="remove(picked, picked.length === 1 ? 'il documento selezionato' : `i ${picked.length} documenti selezionati`)"><Icon name="trash" />Elimina selezionate</button>
                <button class="btn link" type="button" @click="picked = []">Deseleziona</button>
                <span v-if="error" class="error" role="alert">{{ error }}</span>
            </div>
            <div v-if="invoices.data.length" class="table-wrap">
                <table class="cardify">
                    <thead><tr>
                        <th class="check-col"><input type="checkbox" :checked="allOn" aria-label="Seleziona tutti i documenti della pagina" @change="toggleAll"></th>
                        <th><button class="btn link" type="button" @click="sortBy('numero')">Documento{{ sortMark('numero') }}</button></th>
                        <th><button class="btn link" type="button" @click="sortBy('controparte')">Controparte{{ sortMark('controparte') }}</button></th>
                        <th><button class="btn link" type="button" @click="sortBy('data')">{{ isPurchaseList ? 'Data / Ricezione' : 'Data' }}{{ sortMark('data') }}</button></th>
                        <th class="num"><button class="btn link" type="button" @click="sortBy('imponibile')">Imponibile{{ sortMark('imponibile') }}</button></th><th class="num">IVA</th><th>Stato</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="i in invoices.data" :key="i.id" class="row-link" @click="router.visit(`${base}/fatture/${i.id}?ritorno=${encodeURIComponent(here)}`)">
                            <td class="check-col" @click.stop><input v-model="picked" type="checkbox" :value="i.id" :aria-label="`Seleziona la fattura ${i.number}`"></td>
                            <td><Link :href="`${base}/fatture/${i.id}?ritorno=${encodeURIComponent(here)}`" class="title" @click.stop>{{ i.number }}</Link><br><span class="small muted">{{ i.doc_type_label }} · {{ i.direction_label }}<template v-if="filters.anno === 'tutti'"> · {{ i.year }}</template></span></td>
                            <td>{{ i.counterparty }}</td>
                            <td class="tabular nowrap">{{ date(i.doc_date) }}<template v-if="i.direction === 'acquisto'"><br><span class="small muted">{{ i.receipt_date ? `ricevuta ${date(i.receipt_date)}` : 'ricezione n.d.: anno da data documento' }}</span></template></td>
                            <td class="num tabular nowrap">{{ money(i.taxable_total) }}<span v-if="i.currency !== 'EUR'" class="small muted"> {{ i.currency }}</span></td>
                            <td class="num tabular nowrap">{{ money(i.tax_total) }}</td>
                            <td><span class="badge" :class="{ warn: i.status === 'da_verificare' || i.status === 'importata', good: i.status === 'registrata' }">{{ i.status_label }}</span></td>
                            <td class="nowrap" @click.stop><div class="row-actions">
                                <Link :href="docUrl(i)" class="btn icon-btn" title="Apri il documento" :aria-label="`Apri il documento della fattura ${i.number}`"><Icon name="search" /></Link>
                                <Link :href="view(i)" class="btn icon-btn" title="Visualizza fattura" :aria-label="`Visualizza fattura ${i.number}`"><Icon name="doc" /></Link>
                                <button v-if="i.deletable" class="btn icon-btn" type="button" title="Elimina" :aria-label="`Elimina la fattura ${i.number}`" @click="remove([i.id], `la fattura ${i.number}`)"><Icon name="trash" /></button>
                            </div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty">
                <h2>Nessuna fattura</h2>
                <p>{{ filters.q || filters.stato ? 'Nessun risultato con questi filtri.' : `Nessuna fattura${filters.anno !== 'tutti' ? ` nel ${filters.anno}` : ''}. Importa i file XML o inserisci una fattura manuale.` }}</p>
            </div>
            <p v-if="invoices.data.length" class="small muted" style="padding: 10px 22px">{{ totals.count }} documenti<template v-if="filters.anno !== 'tutti'"> nel {{ filters.anno }}{{ direction === 'acquisto' ? ' (per data di ricezione, altrimenti data documento)' : '' }}</template> · imponibile {{ money(totals.taxable) }} · IVA {{ money(totals.tax) }}</p>
            <nav v-if="invoices.links.length > 3" class="pager" aria-label="Pagine">
                <span class="small muted">{{ invoices.from }}–{{ invoices.to }} di {{ invoices.total }}</span>
                <template v-for="(l, k) in invoices.links" :key="k">
                    <Link v-if="l.url" :href="l.url" class="btn" :class="{ primary: l.active }" preserve-scroll>{{ label(l.label) }}</Link>
                </template>
            </nav>
        </section>
    </AppLayout>
</template>

<style scoped>
.bulk { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 20px; background: var(--ct-tint, #edf9f1); border-block: 1px solid var(--ct-border, #dce3dd); }
.check-col { width: 36px; }
.row-actions { display: flex; gap: 4px; flex-wrap: nowrap; min-width: 108px; }
.row-actions .icon-btn { min-height: 32px; min-width: 32px; padding: 4px 6px; justify-content: center; }
</style>
