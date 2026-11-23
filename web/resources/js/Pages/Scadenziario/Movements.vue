<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import Tabs from '@/Components/Scadenziario/Tabs.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Alloc = { id: number; amount: string; status: string; reversal_reason: string | null; installment_id: number; seq: number; invoice_id: number; number: string };
type Mov = { id: number; kind: 'incasso' | 'pagamento'; date: string; payer_date: string | null; payer_year_rule: boolean; party: string; amount: string; method: string | null; bank_ref: string | null;
    notes: string | null; status: string; reversal_reason: string | null; created_by: string | null; allocated: string; unallocated: string; allocations: Alloc[] };
const props = defineProps<{ filters: { party: string; from: string | null; to: string | null; kind: string | null; as_of: string | null }; rows: Mov[]; modes: Record<string, string> }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const f = ref({ partner: props.filters.party, dal: props.filters.from ?? '', al: props.filters.to ?? '', tipo: props.filters.kind ?? '' });
const query = computed(() => Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')));
const apply = () => router.get(`${base.value}/scadenziario/incassi-e-pagamenti`, query.value, { preserveScroll: true, preserveState: true });
const csvUrl = computed(() => `${base.value}/scadenziario/incassi-e-pagamenti/csv?${new URLSearchParams(query.value as Record<string, string>).toString()}`);
const hash = typeof window !== 'undefined' ? window.location.hash : '';

const target = ref<{ type: 'movement' | 'allocation'; id: number; label: string } | null>(null);
const sform = useForm({ reason: '' });
const doStorno = () => sform.post(target.value!.type === 'movement' ? `${base.value}/scadenziario/movimenti/${target.value!.id}/storna` : `${base.value}/scadenziario/abbinamenti/${target.value!.id}/storna`,
    { preserveScroll: true, onSuccess: () => { target.value = null; sform.reset(); } });
</script>

<template>
    <Head title="Movimenti finanziari" />
    <AppLayout crumb="Incassi e pagamenti · Movimenti finanziari">
        <div class="page-head">
            <div>
                <p class="eyebrow">Incassi e pagamenti</p>
                <h1>Movimenti finanziari</h1>
                <p>Ogni movimento compare una sola volta, con le fatture a cui è abbinato. Uno storno con motivo riapre il residuo e ricalcola l’imputazione fiscale; il registro IVA non cambia.</p>
            </div>
            <div class="actions"><a class="btn" :href="csvUrl"><Icon name="download" />CSV</a></div>
        </div>
        <Tabs :base="base" current="incassi-e-pagamenti" />
        <section class="panel">
            <form class="panel-body acc-filters" @submit.prevent="apply">
                <div class="field"><label for="mv-party">Cliente o fornitore</label><input id="mv-party" v-model="f.partner" class="input"></div>
                <div class="field"><label for="mv-kind">Tipo</label><select id="mv-kind" v-model="f.tipo" class="input"><option value="">Incassi e pagamenti</option><option value="incasso">Solo incassi</option><option value="pagamento">Solo pagamenti</option></select></div>
                <div class="field"><label for="mv-from">Dal</label><input id="mv-from" v-model="f.dal" type="date" class="input"></div>
                <div class="field"><label for="mv-to">Al</label><input id="mv-to" v-model="f.al" type="date" class="input"></div>
                <div class="actions" style="align-self: end"><button class="btn" type="submit">Filtra</button></div>
            </form>
            <div v-if="rows.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th>Data</th><th>Tipo</th><th>Cliente/Fornitore</th><th class="num">Importo</th><th>Abbinamenti</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="m in rows" :id="`movimento-${m.id}`" :key="m.id" :class="{ 'row-stornato': m.status === 'stornata', 'row-highlight': hash === `#movimento-${m.id}` }">
                            <td class="tabular nowrap">{{ date(m.date) }}<template v-if="m.payer_date"><br><span class="small muted">sostituto: {{ date(m.payer_date) }}</span></template></td>
                            <td>{{ m.kind === 'incasso' ? 'Incasso' : 'Pagamento' }} <span class="small muted">n. {{ m.id }}</span><template v-if="m.status === 'stornata'"><br><span class="badge bad">Stornato</span> <span class="small">{{ m.reversal_reason }}</span></template></td>
                            <td>{{ m.party }}<br><span class="small muted"><template v-if="m.method">{{ m.method }}</template><template v-if="m.bank_ref"> · rif. {{ m.bank_ref }}</template><template v-if="m.notes"> · {{ m.notes }}</template></span></td>
                            <td class="num tabular nowrap"><strong>{{ money(m.amount) }}</strong><template v-if="m.unallocated !== '0.00'"><br><span class="small text-warn">da abbinare {{ money(m.unallocated) }}</span></template></td>
                            <td>
                                <ul class="plain small">
                                    <li v-for="a in m.allocations" :key="a.id" :class="{ 'muted': a.status !== 'valida' }"><Link :href="`${base}/fatture/${a.invoice_id}#scadenze`">{{ a.number }}</Link> rata {{ a.seq }} · {{ money(a.amount) }}
                                        <template v-if="a.status !== 'valida'"> (stornato: {{ a.reversal_reason }})</template>
                                        <button v-else-if="m.status === 'valida'" class="btn link small" type="button" @click="target = { type: 'allocation', id: a.id, label: `l’abbinamento a ${a.number}` }; sform.clearErrors()">Storna abbinamento</button></li>
                                </ul>
                            </td>
                            <td class="nowrap"><button v-if="m.status === 'valida'" class="btn link" type="button" @click="target = { type: 'movement', id: m.id, label: `il ${m.kind} n. ${m.id} di ${money(m.amount)}` }; sform.clearErrors()">Storna</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessun incasso o pagamento registrato. Si registrano da “Da incassare”, “Da pagare” o dalla fattura.</p>
        </section>

        <div v-if="target" class="dialog-wrap" @click.self="target = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="ms-title" @submit.prevent="doStorno">
                <h2 id="ms-title">Stornare {{ target.label }}?</h2>
                <p class="muted">Il movimento resta visibile come stornato. Residui, stato di pagamento e imputazione fiscale vengono ricalcolati.</p>
                <div class="field"><label for="ms-reason">Motivo</label><input id="ms-reason" v-model="sform.reason" class="input" :aria-invalid="sform.errors.reason ? true : undefined"><p v-if="sform.errors.reason" class="error">{{ sform.errors.reason }}</p></div>
                <div class="actions"><button class="btn danger" type="submit" :disabled="sform.processing">Conferma storno</button><button class="btn" type="button" @click="target = null">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>
