<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Sezione 5: Integrazione IVA / Reverse charge con anteprima per gruppo IVA. Gli importi vengono ricalcolati dal server alla conferma. */
type Group = { summary_index: number; rate: string; natura: string | null; taxable: string; tax: string; candidate: boolean; account_id: number | null; deductible_pct: string };
const props = defineProps<{
    invoice: { id: number; number: string; doc_date: string; supplier: string; supplier_vat: string | null; currency: string; total: string; doc_type: string; direction: string | null; status: string; registered: boolean; protocol: string | null };
    proposal: { possible: boolean; doc_type: string | null; reason: string; country: string; kind: string | null; warnings: string[] };
    groups: Group[];
    types: Record<string, string>;
    kinds: Record<string, string>;
    years: Record<string, string>;
    receipt_date: string;
    suggest: { year: number; period: number } | null;
    today: string;
    accounts: { id: number; code: string; name: string }[];
    existing: { id: number; doc_type: string; number: string; doc_date: string; active: boolean; linked_document: boolean; groups: { base: string; rate: string; tax: string; deductible: string }[] }[];
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const MONTHS = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

const form = ref({
    doc_type: props.proposal.doc_type ?? 'TD17',
    kind: props.proposal.kind ?? '',
    doc_date: props.receipt_date > props.invoice.doc_date ? props.receipt_date : props.invoice.doc_date,
    receipt_date: props.receipt_date,
    registration_date: props.today,
    period: props.suggest ? `${props.suggest.year}-${props.suggest.period}` : '',
    reason: '',
    groups: props.groups.map((g) => ({ ...g, integrate: g.candidate, it_rate: '22' })),
});
const periodOptions = computed(() => Object.entries(props.years).map(([y, per]) => ({
    year: Number(y),
    items: (per === 'trimestrale' ? [1, 2, 3, 4] : MONTHS.map((_, i) => i + 1)).map((n) => ({ key: `${y}-${n}`, label: per === 'trimestrale' ? `${n}° trimestre ${y}` : `${MONTHS[n - 1]} ${y}` })),
})));

/* Anteprima in centesimi (stesso arrotondamento del server: commerciale al centesimo). */
const cents = (v: string) => Math.round(Math.abs(Number(String(v).replace(',', '.'))) * 100);
const pctOf = (c: number, pct: string) => Math.round((c * Number(String(pct).replace(',', '.') || 0)) / 100);
const preview = computed(() => form.value.groups.filter((g) => g.integrate).map((g) => {
    const b = cents(g.taxable), t = pctOf(b, g.it_rate), d = pctOf(t, g.deductible_pct);
    return { idx: g.summary_index, base: b, tax: t, ded: d, nonded: t - d };
}));
const tot = computed(() => preview.value.reduce((a, p) => ({ base: a.base + p.base, tax: a.tax + p.tax, ded: a.ded + p.ded, nonded: a.nonded + p.nonded }), { base: 0, tax: 0, ded: 0, nonded: 0 }));
const eur = (c: number) => money((c / 100).toFixed(2));
const periodLabel = computed(() => periodOptions.value.flatMap((g) => g.items).find((o) => o.key === form.value.period)?.label ?? '—');

const errors = computed(() => page.props.errors as Record<string, string>);
const busy = ref(false);
const activeExisting = computed(() => props.existing.find((e) => e.active));
function submit() {
    busy.value = true;
    const [year, period] = form.value.period.split('-').map(Number);
    router.post(`${base.value}/fatture/${props.invoice.id}/integra-iva`, {
        doc_type: form.value.doc_type, kind: form.value.kind || null, doc_date: form.value.doc_date, receipt_date: form.value.receipt_date,
        registration_date: form.value.registration_date, year, period, reason: form.value.reason || null,
        groups: form.value.groups.map((g) => ({ summary_index: g.summary_index, integrate: g.integrate, rate: g.it_rate, account_id: g.account_id, deductible_pct: g.deductible_pct })),
    }, { onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Head title="Integra IVA / Reverse charge" />
    <AppLayout crumb="Integra IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/fatture/${invoice.id}`">Fattura {{ invoice.number }}</Link></p>
                <h1>Integra IVA / Reverse charge</h1>
                <p>{{ invoice.supplier }}<template v-if="invoice.supplier_vat"> · {{ invoice.supplier_vat }}</template> · del {{ date(invoice.doc_date) }} · totale {{ money(invoice.total) }} €. Il costo, il conto e il debito verso il fornitore restano sulla fattura originale; l’integrazione aggiunge solo l’IVA nei due registri.</p>
            </div>
        </div>

        <div v-if="Object.keys(errors).length" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div><div v-for="(m, k) in errors" :key="k">{{ m }}</div></div></div>
        <div class="notice" :class="proposal.possible ? 'info' : 'warn'" role="status">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>{{ proposal.possible ? `Proposta: ${proposal.doc_type} · ${types[proposal.doc_type!]}` : 'Nessuna integrazione proposta' }}</strong>{{ proposal.reason }}
                <div v-for="w in proposal.warnings" :key="w" class="small">{{ w }}</div></div>
        </div>
        <div v-if="activeExisting" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Integrazione {{ activeExisting.doc_type }} n. {{ activeExisting.number }} già registrata</strong>Per correggerla storna la registrazione della fattura (prot. {{ invoice.protocol }}): lo storno vale per entrambi i registri.</div></div>

        <section class="panel" style="margin-top: 16px">
            <div class="panel-head"><h2>Tipo e date</h2></div>
            <div class="panel-body form-grid">
                <label>Tipo documento
                    <select class="input" v-model="form.doc_type"><option v-for="(l, k) in types" :key="k" :value="k">{{ k }} · {{ l }}</option></select></label>
                <label v-if="proposal.country !== 'IT'">Natura dell’acquisto
                    <select class="input" v-model="form.kind"><option value="">—</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></label>
                <label>Data integrazione<input class="input" v-model="form.doc_date" type="date" :min="invoice.doc_date"></label>
                <label>Data di ricezione della fattura<input class="input" v-model="form.receipt_date" type="date" :min="invoice.doc_date" :max="today"></label>
                <label>Data di registrazione<input class="input" v-model="form.registration_date" type="date" :max="today"></label>
                <label>Periodo IVA
                    <select class="input" v-model="form.period"><option value="">—</option><optgroup v-for="g in periodOptions" :key="g.year" :label="String(g.year)"><option v-for="o in g.items" :key="o.key" :value="o.key">{{ o.label }}</option></optgroup></select></label>
            </div>
        </section>

        <section class="panel" style="margin-top: 16px">
            <div class="panel-head"><div><h2>Gruppi IVA</h2><p>Seleziona i gruppi da integrare con l’aliquota italiana. Più aliquote, integrazioni parziali e note di credito (TD04: importi in riduzione) sono gestite per gruppo.</p></div></div>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Integra</th><th>Riepilogo fattura</th><th class="num">Base</th><th>Aliquota IT</th><th>Conto di costo</th><th class="num">% detraibile</th><th class="num">IVA a debito</th><th class="num">Detraibile</th><th class="num">Indetraibile</th></tr></thead>
                    <tbody>
                        <tr v-for="g in form.groups" :key="g.summary_index">
                            <td><input v-model="g.integrate" type="checkbox" :aria-label="`Integra gruppo ${g.summary_index + 1}`"></td>
                            <td>{{ g.natura ?? `${g.rate}%` }}<span v-if="g.candidate" class="badge good" style="margin-left: 6px">proposto</span></td>
                            <td class="num tabular">{{ money(g.taxable) }}</td>
                            <td><select class="input" v-model="g.it_rate" :disabled="!g.integrate"><option v-for="r in ['4', '5', '10', '22']" :key="r" :value="r">{{ r }}%</option></select></td>
                            <td><select class="input" v-model="g.account_id"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></select></td>
                            <td class="num"><input class="input" v-model="g.deductible_pct" inputmode="decimal" style="width: 70px; text-align: right"></td>
                            <template v-if="preview.find((p) => p.idx === g.summary_index)">
                                <td class="num tabular">{{ eur(preview.find((p) => p.idx === g.summary_index)!.tax) }}</td>
                                <td class="num tabular">{{ eur(preview.find((p) => p.idx === g.summary_index)!.ded) }}</td>
                                <td class="num tabular">{{ eur(preview.find((p) => p.idx === g.summary_index)!.nonded) }}</td>
                            </template>
                            <td v-else colspan="3" class="muted small">non integrato</td>
                        </tr>
                    </tbody>
                    <tfoot><tr><th colspan="2">Totale integrato</th><th class="num tabular">{{ eur(tot.base) }}</th><th colspan="3"></th><th class="num tabular">{{ eur(tot.tax) }}</th><th class="num tabular">{{ eur(tot.ded) }}</th><th class="num tabular">{{ eur(tot.nonded) }}</th></tr></tfoot>
                </table>
            </div>
            <div class="panel-body small">
                <p><strong>Registro IVA vendite</strong>: IVA a debito {{ eur(tot.tax) }} € · <strong>Registro IVA acquisti</strong>: IVA detraibile {{ eur(tot.ded) }} €, indetraibile {{ eur(tot.nonded) }} € (a costo) · periodo {{ periodLabel }} · effetto sulla liquidazione {{ eur(tot.nonded) }} €.</p>
                <p class="muted">Nessun ricavo, nessun credito verso clienti e nessuna scadenza dalla parte vendite. Il debito verso il fornitore resta quello della fattura originale.</p>
            </div>
        </section>

        <div class="actions" style="margin-top: 16px">
            <button class="btn primary" type="button" :disabled="busy || !tot.tax || !form.period || !!activeExisting" @click="submit">Conferma integrazione</button>
            <Link :href="`${base}/fatture/${invoice.id}`" class="btn">Annulla</Link>
        </div>

        <section v-if="existing.length" class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Integrazioni della fattura</h2></div>
            <ul class="panel-body plain small"><li v-for="e in existing" :key="e.id">{{ e.doc_type }} n. {{ e.number }} del {{ date(e.doc_date) }} · IVA {{ money(String(e.groups.reduce((a, g) => a + Number(g.tax), 0).toFixed(2))) }} € · {{ e.active ? 'registrata' : 'stornata' }}<template v-if="e.linked_document"> · XML SdI abbinato</template></li></ul>
        </section>
    </AppLayout>
</template>
