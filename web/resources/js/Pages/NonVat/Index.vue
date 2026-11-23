<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Entry = { id: number; protocol_label: string; kind: 'costo' | 'ricavo'; entry_date: string; account: string; description: string; counterparty: string | null; reference: string | null;
    amount: string; status: string; reason: string | null; reverses: string | null; document: { id: number; name: string } | null; created_by: string | null };
type Acc = { id: number; code: string; name: string; type: string; iva_rilevante: boolean; categoria: string | null; deducibilita_nota: string | null };
const props = defineProps<{ year: number; years: number[]; kind: string | null; data: { entries: Entry[]; totals: { code: string; name: string; kind: string; amount: string }[]; sum: { costo: string; ricavo: string } };
    accounts: Acc[]; today: string; accept: string }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const go = (year: number, kind: string | null) => router.get(`${base.value}/fuori-campo`, { anno: year, ...(kind ? { tipo: kind } : {}) }, { preserveScroll: true });

/* Selezione multipla: Allegati (ZIP) ed elenco (CSV) */
const picked = ref<number[]>([]);
const allOn = computed(() => props.data.entries.length > 0 && picked.value.length === props.data.entries.length);
const toggleAll = () => { picked.value = allOn.value ? [] : props.data.entries.map((x) => x.id); };
const busy = ref(false);
const bulkError = ref('');
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
async function download(kind: 'documenti' | 'csv') {
    busy.value = true; bulkError.value = '';
    try {
        const res = await fetch(`${base.value}/fuori-campo/seleziona/${kind}`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/zip, text/csv, application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ ids: picked.value }) });
        if (!res.ok) { bulkError.value = res.status === 404 ? 'Nessun allegato per la selezione.' : 'Esportazione non riuscita.'; busy.value = false; return; }
        const blob = await res.blob(), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? `export.${kind === 'csv' ? 'csv' : 'zip'}`; document.body.appendChild(a); a.click(); a.remove();
    } catch { bulkError.value = 'Esportazione non riuscita.'; }
    busy.value = false;
}
const open = ref(false);
const form = useForm({ kind: 'costo' as 'costo' | 'ricavo', entry_date: props.today, account_id: 0, description: '', counterparty: '', reference: '', amount: '', file: null as File | null });
const e = (k: string) => (form.errors as Record<string, string>)[k];
// Prima i conti fuori campo IVA (ad es. Bollo, Personale, Interessi), poi gli altri conti del tipo.
const groups = computed(() => {
    const list = props.accounts.filter((a) => a.type === form.kind);
    return [['Conti fuori campo IVA', list.filter((a) => !a.iva_rilevante)], ['Altri conti', list.filter((a) => a.iva_rilevante)]].filter(([, l]) => (l as Acc[]).length) as [string, Acc[]][];
});
const chosen = computed(() => props.accounts.find((a) => a.id === form.account_id) ?? null);
function start(kind: 'costo' | 'ricavo') { form.reset(); form.clearErrors(); form.kind = kind; open.value = true; nextTick(() => document.getElementById('nv-date')?.focus()); }
const save = () => form.post(`${base.value}/fuori-campo`, { forceFormData: true, preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });

const storno = ref<Entry | null>(null);
const sform = useForm({ reason: '', date: props.today });
const doStorno = () => sform.post(`${base.value}/fuori-campo/${storno.value!.id}/storna`, { preserveScroll: true, onSuccess: () => { storno.value = null; sform.reset(); } });
</script>

<template>
    <Head title="Fuori campo IVA" />
    <AppLayout crumb="Fuori campo IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow">Movimenti</p>
                <h1>Costi e ricavi fuori campo IVA</h1>
                <p>Per spese e incassi senza IVA (es. imposta di bollo, assicurazioni, stipendi, interessi, ricevute). Non entrano nei registri IVA né nella liquidazione; ogni movimento ha un protocollo e si corregge con lo storno.</p>
            </div>
            <div class="actions">
                <button class="btn" type="button" @click="start('ricavo')"><Icon name="plus" />Ricavo</button>
                <button class="btn primary" type="button" @click="start('costo')"><Icon name="plus" />Costo</button>
            </div>
        </div>

        <section class="panel">
            <div class="panel-body acc-filters">
                <label class="sr-only" for="nv-year">Anno</label>
                <select id="nv-year" class="input" style="max-width: 120px" :value="year" @change="go(Number(($event.target as HTMLSelectElement).value), kind)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <label class="sr-only" for="nv-kind">Tipo</label>
                <select id="nv-kind" class="input" style="max-width: 200px" :value="kind ?? ''" @change="go(year, ($event.target as HTMLSelectElement).value || null)"><option value="">Costi e ricavi</option><option value="costo">Solo costi</option><option value="ricavo">Solo ricavi</option></select>
                <span class="small muted">{{ data.entries.length }} movimenti · costi {{ money(data.sum.costo) }} · ricavi {{ money(data.sum.ricavo) }}</span>
            </div>
            <div v-if="picked.length" class="bulk" role="region" aria-label="Azioni sulle registrazioni selezionate">
                <strong>{{ picked.length }} {{ picked.length === 1 ? 'selezionata' : 'selezionate' }}</strong>
                <button class="btn" type="button" :disabled="busy" @click="download('documenti')"><Icon name="download" />Allegati (ZIP)</button>
                <button class="btn" type="button" :disabled="busy" @click="download('csv')"><Icon name="download" />Elenco (CSV)</button>
                <button class="btn link" type="button" @click="picked = []">Deseleziona</button>
                <span v-if="bulkError" class="error" role="alert">{{ bulkError }}</span>
            </div>
            <div v-if="data.entries.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th class="check-col"><input type="checkbox" :checked="allOn" aria-label="Seleziona tutte le registrazioni" @change="toggleAll"></th><th>Prot.</th><th>Data</th><th>Descrizione</th><th>Conto</th><th class="num">Importo</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="r in data.entries" :key="r.id" :class="{ 'row-storno': r.status === 'storno', 'row-stornato': r.status === 'stornato' }">
                        <td class="check-col"><input v-model="picked" type="checkbox" :value="r.id" :aria-label="`Seleziona la registrazione ${r.id}`"></td>
                            <td class="nowrap"><strong class="tabular">{{ r.protocol_label }}</strong><br><span class="small muted">{{ r.kind === 'costo' ? 'Costo' : 'Ricavo' }}<template v-if="r.status === 'storno'"> · storno di {{ r.reverses }}</template><template v-if="r.status === 'stornato'"> · stornato</template></span></td>
                            <td class="tabular nowrap">{{ date(r.entry_date) }}</td>
                            <td>{{ r.description }}<br><span class="small muted"><template v-if="r.counterparty">{{ r.counterparty }}</template><template v-if="r.reference"> · {{ r.reference }}</template><template v-if="r.reason"> · {{ r.reason }}</template></span>
                                <template v-if="r.document"><br><a class="small" :href="`${base}/documenti/${r.document.id}/scarica`">Allegato: {{ r.document.name }}</a></template></td>
                            <td class="small">{{ r.account }}</td>
                            <td class="num tabular nowrap">{{ money(r.amount) }}</td>
                            <td class="nowrap"><button v-if="r.status === 'registrato'" class="btn link" type="button" @click="storno = r; sform.clearErrors()">Storna</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessun movimento nel {{ year }}. Usa “Costo” o “Ricavo” per registrarne uno.</p>
        </section>

        <section v-if="data.totals.length" class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Riepilogo per conto</h2></div>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead><tr><th>Conto</th><th>Tipo</th><th class="num">Importo</th></tr></thead>
                    <tbody>
                        <tr v-for="t in data.totals" :key="t.code"><td><strong class="tabular">{{ t.code }}</strong> · {{ t.name }}</td><td>{{ t.kind === 'costo' ? 'Costo' : 'Ricavo' }}</td><td class="num tabular">{{ money(t.amount) }}</td></tr>
                        <tr class="sum-row"><td colspan="2">Totale costi</td><td class="num tabular">{{ money(data.sum.costo) }}</td></tr>
                        <tr class="sum-row"><td colspan="2">Totale ricavi</td><td class="num tabular">{{ money(data.sum.ricavo) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="open" class="dialog-wrap" @click.self="open = false">
            <form class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="nv-title" novalidate @submit.prevent="save" @keydown.esc="open = false">
                <h2 id="nv-title">{{ form.kind === 'costo' ? 'Nuovo costo fuori campo IVA' : 'Nuovo ricavo fuori campo IVA' }}</h2>
                <div class="acc-grid">
                    <div class="field"><label for="nv-date">Data</label><input id="nv-date" v-model="form.entry_date" type="date" class="input" :max="today" :aria-invalid="e('entry_date') ? true : undefined"><p v-if="e('entry_date')" class="error">{{ e('entry_date') }}</p></div>
                    <div class="field"><label for="nv-amount">Importo</label><input id="nv-amount" v-model="form.amount" class="input tabular" inputmode="decimal" placeholder="0,00" :aria-invalid="e('amount') ? true : undefined"><p v-if="e('amount')" class="error">{{ e('amount') }}</p></div>
                    <div class="field"><label for="nv-ref">Riferimento</label><input id="nv-ref" v-model="form.reference" class="input" placeholder="es. n. ricevuta"></div>
                    <div class="field span3"><label for="nv-acc">Conto</label>
                        <select id="nv-acc" v-model.number="form.account_id" class="input" :aria-invalid="e('account_id') ? true : undefined"><option :value="0" disabled>Scegli il conto</option><optgroup v-for="[g, list] in groups" :key="g" :label="g"><option v-for="a in list" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></optgroup></select>
                        <p v-if="chosen?.deducibilita_nota" class="hint">Deducibilità: {{ chosen.deducibilita_nota }}</p><p v-if="e('account_id')" class="error">{{ e('account_id') }}</p></div>
                    <div class="field span3"><label for="nv-desc">Descrizione</label><input id="nv-desc" v-model="form.description" class="input" placeholder="es. Imposta di bollo su fattura 12" :aria-invalid="e('description') ? true : undefined"><p v-if="e('description')" class="error">{{ e('description') }}</p></div>
                    <div class="field span3"><label for="nv-cp">Controparte (facoltativa)</label><input id="nv-cp" v-model="form.counterparty" class="input"></div>
                    <div class="field span3"><label for="nv-file">Allegato (facoltativo)</label><input id="nv-file" type="file" class="input" :accept="accept" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"><p v-if="e('file')" class="error">{{ e('file') }}</p></div>
                </div>
                <div class="actions"><button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Registra</button><button class="btn" type="button" @click="open = false">Annulla</button></div>
            </form>
        </div>

        <div v-if="storno" class="dialog-wrap" @click.self="storno = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="ns-title" @submit.prevent="doStorno">
                <h2 id="ns-title">Stornare il movimento {{ storno.protocol_label }}?</h2>
                <p class="muted">Viene creato un movimento di storno con nuovo protocollo. Il movimento originale resta visibile.</p>
                <div class="field"><label for="ns-reason">Motivo</label><input id="ns-reason" v-model="sform.reason" class="input" :aria-invalid="sform.errors.reason ? true : undefined"><p v-if="sform.errors.reason" class="error">{{ sform.errors.reason }}</p></div>
                <div class="field"><label for="ns-date">Data dello storno</label><input id="ns-date" v-model="sform.date" type="date" class="input" :min="storno.entry_date" :max="today"><p v-if="sform.errors.date" class="error">{{ sform.errors.date }}</p></div>
                <div class="actions"><button class="btn danger" type="submit" :disabled="sform.processing">Conferma storno</button><button class="btn" type="button" @click="storno = null">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.bulk { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 20px; background: var(--ct-tint, #edf9f1); border-block: 1px solid var(--ct-border, #dce3dd); }
.check-col { width: 36px; }
</style>
