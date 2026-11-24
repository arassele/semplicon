<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import Tabs from '@/Components/Scadenziario/Tabs.vue';
import PaymentDialog from '@/Components/Scadenziario/PaymentDialog.vue';
import { date, money } from '@/lib/format';
import { dueClass, paidClass, toCents, type Rate } from '@/Components/Scadenziario/types';
import type { SharedProps } from '@/types';

type Filters = { party: string; period: string; from: string | null; to: string | null; as_of: string | null; status: string; overdue: boolean };
type Unalloc = { id: number; date: string; party: string; party_key: string; amount: string; unallocated: string };
const props = defineProps<{ view: 'da-incassare' | 'da-pagare'; filters: Filters; rows: Rate[]; statuses: Record<string, string>; totals: Record<string, string>;
    modes: Record<string, string>; today: string; professionista: boolean; unallocated: Unalloc[] }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const sale = computed(() => props.view === 'da-incassare');
const word = computed(() => (sale.value ? 'incasso' : 'pagamento'));

const f = ref({ partner: props.filters.party, periodo: props.filters.period, dal: props.filters.from ?? '', al: props.filters.to ?? '', stato: props.filters.status, scadute: props.filters.overdue, al_giorno: props.filters.as_of ?? '' });
const query = computed(() => Object.fromEntries(Object.entries({ ...f.value, scadute: f.value.scadute ? '1' : '' }).filter(([, v]) => v !== '' && v !== 'scadenza' && v !== 'aperte')));
const apply = () => router.get(`${base.value}/scadenziario/${props.view}`, query.value, { preserveScroll: true, preserveState: true });
const reset = () => router.get(`${base.value}/scadenziario/${props.view}`);
const csvUrl = computed(() => `${base.value}/scadenziario/${props.view}/csv?${new URLSearchParams(query.value as Record<string, string>).toString()}`);

// Selezione per il pagamento cumulativo: solo rate dello stesso partner.
const picked = ref<number[]>([]);
const pickedParty = computed(() => props.rows.find((r) => r.id === picked.value[0])?.party_key ?? null);
const canPick = (r: Rate) => !r.credit_note && toCents(r.residual) > 0 && !props.filters.as_of && (!pickedParty.value || pickedParty.value === r.party_key);
const dialog = ref<{ party: string; selected: number[] } | null>(null);
function pay(r?: Rate) {
    const key = r ? r.party_key : pickedParty.value;
    if (!key) return;
    dialog.value = { party: key, selected: r ? [r.id] : [...picked.value] };
}
const candidates = computed(() => props.rows.filter((r) => dialog.value && r.party_key === dialog.value.party));
const openHistory = ref<number | null>(null);

const allocDialog = ref<Unalloc | null>(null);
const aform = useForm({ allocations: [] as { installment_id: number; amount: string }[] });
const allocRows = ref<{ rate: Rate; amount: string }[]>([]);
function startAlloc(m: Unalloc) { allocDialog.value = m; aform.clearErrors(); allocRows.value = props.rows.filter((r) => r.party_key === m.party_key && !r.credit_note && toCents(r.residual) > 0).map((r) => ({ rate: r, amount: '' })); }
const doAlloc = () => { aform.allocations = allocRows.value.filter((r) => r.amount.trim()).map((r) => ({ installment_id: r.rate.id, amount: r.amount })); aform.post(`${base.value}/scadenziario/movimenti/${allocDialog.value!.id}/abbina`, { preserveScroll: true, onSuccess: () => (allocDialog.value = null) }); };
</script>

<template>
    <Head :title="sale ? 'Da incassare' : 'Da pagare'" />
    <AppLayout crumb="Incassi e pagamenti">
        <div class="page-head">
            <div>
                <p class="eyebrow">Incassi e pagamenti</p>
                <h1>{{ sale ? 'Da incassare' : 'Da pagare' }}</h1>
                <p>{{ sale ? 'Chi deve cosa e quando: rate delle fatture di vendita con importo incassato e residuo.' : 'Quali fornitori pagare entro quando: rate delle fatture di acquisto con importo pagato e residuo.' }} Lo stato del pagamento e la scadenza del residuo sono indicati separatamente.</p>
            </div>
            <div class="actions">
                <a class="btn" :href="csvUrl"><Icon name="download" />CSV</a>
                <button class="btn primary" type="button" :disabled="!picked.length" @click="pay()"><Icon name="plus" />Registra {{ word }}<template v-if="picked.length > 1"> ({{ picked.length }} rate)</template></button>
            </div>
        </div>
        <Tabs :base="base" :current="view" />

        <section class="panel">
            <form class="panel-body acc-filters" @submit.prevent="apply">
                <div class="field"><label for="sc-party">{{ sale ? 'Cliente' : 'Fornitore' }}</label><input id="sc-party" v-model="f.partner" class="input" placeholder="Nome o P. IVA"></div>
                <div class="field"><label for="sc-period">Periodo</label><select id="sc-period" v-model="f.periodo" class="input"><option value="scadenza">per scadenza</option><option value="pagamento">per data {{ sale ? 'incasso' : 'pagamento' }}</option></select></div>
                <div class="field"><label for="sc-from">Dal</label><input id="sc-from" v-model="f.dal" type="date" class="input"></div>
                <div class="field"><label for="sc-to">Al</label><input id="sc-to" v-model="f.al" type="date" class="input"></div>
                <div class="field"><label for="sc-status">Stato</label><select id="sc-status" v-model="f.stato" class="input"><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field"><label for="sc-asof">Situazione al giorno</label><input id="sc-asof" v-model="f.al_giorno" type="date" class="input"></div>
                <label class="check" style="align-self: end"><input v-model="f.scadute" type="checkbox"> Solo scadute</label>
                <div class="actions" style="align-self: end"><button class="btn" type="submit">Filtra</button><button class="btn link" type="button" @click="reset">Azzera</button></div>
            </form>
            <p v-if="filters.as_of" class="panel-body small" style="padding-top: 0"><span class="badge info">Situazione al {{ date(filters.as_of) }}</span> Sono considerati solo fatture e {{ sale ? 'incassi' : 'pagamenti' }} fino a questa data (es. una fattura di dicembre incassata a gennaio risulta aperta al 31/12).</p>
            <div class="panel-body sc-totals small">
                <span>Rate: <strong>{{ rows.length }}</strong></span><span>Importo: <strong class="tabular">{{ money(totals.amount) }}</strong></span><span>{{ sale ? 'Incassato' : 'Pagato' }}: <strong class="tabular">{{ money(totals.settled) }}</strong></span>
                <span>Residuo: <strong class="tabular">{{ money(totals.residual) }}</strong></span><span>di cui scaduto: <strong class="tabular" :class="{ 'text-bad': toCents(totals.overdue) > 0 }">{{ money(totals.overdue) }}</strong></span>
            </div>
            <div v-if="rows.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th><span class="sr-only">Seleziona</span></th><th>{{ sale ? 'Cliente' : 'Fornitore' }}</th><th>Fattura</th><th>Data fatt.</th><th>Scadenza</th><th class="num">Rata</th><th class="num">{{ sale ? 'Incassato' : 'Pagato' }}</th><th class="num">Residuo</th><th>Stato</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <template v-for="r in rows" :key="r.id">
                            <tr>
                                <td><input v-model="picked" type="checkbox" :value="r.id" :disabled="!canPick(r) && !picked.includes(r.id)" :aria-label="`Seleziona fattura ${r.number} rata ${r.seq}`"></td>
                                <td>{{ r.party }}</td>
                                <td class="nowrap"><Link :href="`${base}/fatture/${r.invoice_id}#scadenze`"><strong>{{ r.number }}</strong></Link><template v-if="r.credit_note"><br><span class="small muted">Nota di credito</span></template></td>
                                <td class="tabular nowrap">{{ date(r.doc_date) }}</td>
                                <td class="tabular nowrap">{{ r.due_date ? date(r.due_date) : 'Da definire' }}<br><span class="small muted">rata {{ r.seq }}/{{ r.rates }}<template v-if="r.payment_mode"> · {{ modes[r.payment_mode] ?? r.payment_mode }}</template></span></td>
                                <td class="num tabular">{{ money(r.amount) }}</td>
                                <td class="num tabular">{{ money(r.settled) }}</td>
                                <td class="num tabular"><strong>{{ money(r.residual) }}</strong></td>
                                <td><span class="badge" :class="paidClass(r.paid)">{{ r.paid_label }}</span><template v-if="r.due !== 'saldata'"> <span class="badge" :class="dueClass(r.due)">{{ r.due_label }}</span></template>
                                    <template v-if="r.amount_check"><br><span class="small text-warn">Importo da verificare</span></template></td>
                                <td class="row-actions">
                                    <button v-if="!r.credit_note && toCents(r.residual) > 0 && !filters.as_of" class="btn small-btn" type="button" :aria-label="`Registra ${word} per ${r.number} rata ${r.seq}`" @click="pay(r)"><Icon name="check" />{{ sale ? 'Incassa' : 'Paga' }}</button>
                                    <button v-if="r.payments.length" class="btn link" type="button" :aria-expanded="openHistory === r.id" @click="openHistory = openHistory === r.id ? null : r.id">Storico ({{ r.payments.length }})</button>
                                </td>
                            </tr>
                            <tr v-if="openHistory === r.id" class="sub-row">
                                <td></td>
                                <td colspan="9">
                                    <ul class="plain small">
                                        <li v-for="p in r.payments" :key="p.id">{{ date(p.date) }} · {{ money(p.amount) }} ·
                                            <template v-if="p.type === 'nota_credito'">compensazione con <Link :href="`${base}/fatture/${p.other_invoice_id}#scadenze`">{{ r.credit_note ? 'fattura' : 'nota di credito' }} {{ p.other_number }}</Link></template>
                                            <template v-else><Link :href="`${base}/scadenziario/incassi-e-pagamenti#movimento-${p.movement_id}`">{{ sale ? 'incasso' : 'pagamento' }} n. {{ p.movement_id }}</Link> di {{ money(p.movement_amount) }}<template v-if="p.method"> · {{ p.method }}</template><template v-if="p.bank_ref"> · rif. {{ p.bank_ref }}</template><template v-if="p.payer_date"> · pagato dal sostituto il {{ date(p.payer_date) }}</template></template>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessuna rata con questi filtri.</p>
        </section>

        <section v-if="unallocated.length" class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Importi da abbinare</h2></div>
            <p class="panel-body small muted">{{ sale ? 'Incassi' : 'Pagamenti' }} con una parte non ancora abbinata a fatture (es. pagamento in eccesso o acconto).</p>
            <div class="table-wrap"><table><thead><tr><th>Data</th><th>{{ sale ? 'Cliente' : 'Fornitore' }}</th><th class="num">Movimento</th><th class="num">Da abbinare</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                <tbody><tr v-for="m in unallocated" :key="m.id"><td class="tabular">{{ date(m.date) }}</td><td>{{ m.party }}</td><td class="num tabular">{{ money(m.amount) }}</td><td class="num tabular"><strong>{{ money(m.unallocated) }}</strong></td>
                    <td><button class="btn link" type="button" @click="startAlloc(m)">Abbina</button></td></tr></tbody></table></div>
        </section>

        <PaymentDialog v-if="dialog" :base="base" :sale="sale" :candidates="candidates" :selected="dialog.selected" :modes="modes" :today="today" :professionista="professionista" @close="dialog = null; picked = []" />

        <div v-if="allocDialog" class="dialog-wrap" @click.self="allocDialog = null">
            <form class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="al-title" @submit.prevent="doAlloc">
                <h2 id="al-title">Abbina {{ money(allocDialog.unallocated) }} · {{ allocDialog.party }}</h2>
                <div v-if="allocRows.length" class="table-wrap"><table><thead><tr><th>Fattura</th><th>Rata</th><th class="num">Residuo</th><th class="num">Importo</th></tr></thead>
                    <tbody><tr v-for="(a, i) in allocRows" :key="a.rate.id"><td>{{ a.rate.number }}</td><td>{{ a.rate.seq }}/{{ a.rate.rates }}</td><td class="num tabular">{{ money(a.rate.residual) }}</td>
                        <td class="num"><input v-model="a.amount" class="input tabular" style="max-width: 120px; text-align: right" inputmode="decimal" :aria-label="`Importo per ${a.rate.number}`"><p v-if="(aform.errors as Record<string, string>)[`allocations.${i}.amount`]" class="error">{{ (aform.errors as Record<string, string>)[`allocations.${i}.amount`] }}</p></td></tr></tbody></table></div>
                <p v-else class="muted">Nessuna rata aperta di questo {{ sale ? 'cliente' : 'fornitore' }}.</p>
                <p v-if="aform.errors.allocations" class="error">{{ aform.errors.allocations }}</p>
                <div class="actions"><button class="btn primary" type="submit" :disabled="aform.processing || !allocRows.length">Abbina</button><button class="btn" type="button" @click="allocDialog = null">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>
