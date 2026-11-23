<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Line = { line_no: number | null; description: string; kind: string; legal_ref: string | null; natura_label: string | null; integrated: boolean; account: string; rate: string; natura: string | null; taxable: string; tax: string; deductible: string; deductible_pct: string };
type Entry = {
    id: number; invoice_id: number; document: { id: number; name: string } | null; protocol: number; protocol_label: string; integration: boolean; status: string; reverses_protocol: number | null;
    payment: { source: string; dates: string[]; label: string }; registration_date: string; receipt_date: string | null; period_label: string; year: number; detail_missing: boolean; doc_type: string; doc_type_label: string; number: string; doc_date: string;
    counterparty: { name: string; id: string | null }; taxable: string; tax: string; deductible: string; lines: Line[];
};
type Total = { label: string; taxable: string; tax: string; deductible: string };
const props = defineProps<{
    register: 'acquisti' | 'vendite'; year: number | null; period: number | null; years: Record<string, string>; periodicity: string | null;
    periods: { value: number; label: string }[];
    data: { payment_title: string; entries: Entry[]; totals: Total[]; sum: { taxable: string; tax: string; deductible: string } };
    highlight: number | null; returnUrl: string | null;
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const acq = computed(() => props.register === 'acquisti');
const query = computed(() => `anno=${props.year ?? 'tutti'}${props.period ? `&periodo=${props.period}` : ''}`);
const go = (register: string, year: number | string | null, period: number | null) => router.get(`${base.value}/registri/${register}`, { anno: year ?? 'tutti', ...(period ? { periodo: period } : {}) }, { preserveScroll: true });
onMounted(() => { if (props.highlight) document.getElementById(`reg-${props.highlight}`)?.scrollIntoView({ block: 'center' }); });
const statusLabel = (e: Entry) => (e.integration ? 'Integrazione' : e.status === 'storno' ? `Storno di prot. ${e.reverses_protocol}` : e.status === 'stornata' ? 'Stornata' : '');
</script>

<template>
    <Head :title="`Registro IVA ${register}`" />
    <AppLayout :crumb="`Registro IVA ${register}`">
        <div class="page-head">
            <div>
                <p class="eyebrow">Registri IVA</p>
                <h1>Registro {{ acq ? 'acquisti' : 'vendite' }}</h1>
                <p>Registrazioni in ordine di protocollo. Storni e registrazioni stornate restano visibili con il loro segno: i totali sono il saldo del periodo.<template v-if="!acq"> Le integrazioni reverse charge (TD16–TD19) registrate negli acquisti compaiono anche qui per l’IVA a debito.</template></p>
            </div>
            <div class="actions">
                <Link v-if="returnUrl" :href="returnUrl" class="btn primary">Torna alla verifica</Link>
                <a :href="`${base}/registri/${register}/csv?${query}`" class="btn"><Icon name="download" />CSV</a>
                <a :href="`${base}/registri/${register}/pdf?${query}`" class="btn"><Icon name="download" />PDF</a>
            </div>
        </div>

        <nav class="tabs" aria-label="Registro">
            <Link :href="`${base}/registri/acquisti?${query}`" :aria-current="acq ? 'page' : undefined">Acquisti</Link>
            <Link :href="`${base}/registri/vendite?${query}`" :aria-current="!acq ? 'page' : undefined">Vendite</Link>
        </nav>

        <section class="panel">
            <div class="filters">
                <label for="r-year" class="sr-only">Anno</label>
                <select id="r-year" class="input" style="max-width: 140px" :value="year ?? 'tutti'" @change="go(register, ($event.target as HTMLSelectElement).value, null)">
                    <option value="tutti">Tutti</option>
                    <option v-for="(per, y) in years" :key="y" :value="Number(y)">{{ y }}</option>
                    <option v-if="year && !years[String(year)]" :value="year">{{ year }}</option>
                </select>
                <label for="r-period" class="sr-only">Periodo</label>
                <select id="r-period" class="input" style="max-width: 240px" :value="period ?? 0" :disabled="!periods.length" @change="go(register, year, Number(($event.target as HTMLSelectElement).value) || null)">
                    <option :value="0">Tutto l’anno</option>
                    <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <span class="small muted" style="align-self: center">{{ data.entries.length }} registrazioni · {{ data.entries.reduce((n, e) => n + e.lines.length, 0) }} righe</span>
            </div>

            <div v-if="data.entries.length" class="table-wrap">
                <table class="register">
                    <thead><tr>
                        <th>Prot.</th><th>Data reg.</th><th>{{ data.payment_title }}</th><th>Documento</th><th>Controparte</th><th>Riga</th><th>Aliquota / Natura</th>
                        <th class="num">Imponibile</th><th class="num">Imposta</th><th v-if="acq" class="num">Detraibile</th>
                    </tr></thead>
                    <tbody v-for="e in data.entries" :id="e.integration ? undefined : `reg-${e.id}`" :key="e.id + (e.integration ? 'i' : '')" :class="{ storno: e.status === 'storno', stornata: e.status === 'stornata', highlight: !e.integration && e.id === highlight }">
                        <tr v-for="(l, k) in e.lines" :key="k">
                            <template v-if="k === 0">
                                <td :rowspan="e.lines.length" class="nowrap"><strong class="tabular">{{ e.protocol_label }}</strong><template v-if="!year"><br><span class="small muted">{{ e.year }}</span></template><br><span v-if="statusLabel(e)" class="small muted">{{ statusLabel(e) }}</span></td>
                                <td :rowspan="e.lines.length" class="tabular nowrap">{{ date(e.registration_date) }}<br><span class="small muted">{{ e.period_label }}</span><template v-if="acq && e.receipt_date"><br><span class="small muted">Ricevuta {{ date(e.receipt_date) }}</span></template></td>
                                <td :rowspan="e.lines.length" class="tabular small" :title="e.payment.source === 'registrazione' ? 'Uguale alla data di registrazione' : 'Dallo Scadenziario'">{{ e.payment.label }}<br><span class="muted">{{ e.payment.source === 'registrazione' ? 'da registrazione' : 'da Scadenziario' }}</span></td>
                                <td :rowspan="e.lines.length"><Link :href="`${base}/fatture/${e.invoice_id}`">{{ e.number }}</Link><template v-if="e.detail_missing"><br><span class="small text-warn" title="Solo i totali del riepilogo sono disponibili; se c’è l’XML, “Rielabora XML” aggiunge le righe">Dettaglio righe non disponibile</span></template><br><span class="small muted">{{ e.doc_type }} · {{ date(e.doc_date) }}</span><template v-if="e.document"><br><a class="small" :href="`${base}/documenti/${e.document.id}/scarica`" :title="`Scarica ${e.document.name}`">Allegato</a></template></td>
                                <td :rowspan="e.lines.length">{{ e.counterparty.name }}<br><span class="small muted tabular">{{ e.counterparty.id }}</span></td>
                            </template>
                            <td :class="{ muted: l.kind !== 'riga' }"><span class="tabular">{{ l.line_no ?? '' }}</span> {{ l.description }}<template v-if="l.legal_ref"><br><span class="small muted">{{ l.legal_ref }}</span></template></td>
                            <td class="nowrap" :title="l.natura_label ?? undefined">{{ l.natura ?? `${money(l.rate)}%` }}<template v-if="l.integrated"> <span class="small muted">integrata</span></template><br><span class="small muted">{{ l.account }}</span></td>
                            <td class="num tabular nowrap">{{ money(l.taxable) }}</td>
                            <td class="num tabular nowrap">{{ money(l.tax) }}</td>
                            <td v-if="acq" class="num tabular nowrap">{{ money(l.deductible) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty"><h2>Nessuna registrazione</h2><p>Nel periodo scelto non ci sono registrazioni. Registra i documenti da “Da registrare”.</p></div>
        </section>

        <section v-if="data.entries.length" class="panel" aria-labelledby="tot-title" style="margin-top: 24px">
            <div class="panel-head"><h2 id="tot-title">Riepilogo per aliquota / natura</h2></div>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead><tr><th>Aliquota / Natura</th><th class="num">Imponibile</th><th class="num">Imposta</th><th v-if="acq" class="num">IVA detraibile</th></tr></thead>
                    <tbody>
                        <tr v-for="t in data.totals" :key="t.label"><td>{{ t.label }}</td><td class="num tabular">{{ money(t.taxable) }}</td><td class="num tabular">{{ money(t.tax) }}</td><td v-if="acq" class="num tabular">{{ money(t.deductible) }}</td></tr>
                        <tr class="sum-row"><td>Totale</td><td class="num tabular">{{ money(data.sum.taxable) }}</td><td class="num tabular">{{ money(data.sum.tax) }}</td><td v-if="acq" class="num tabular">{{ money(data.sum.deductible) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
