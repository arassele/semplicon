<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, dateTime, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Side = { id: number; number: string; doc_type: string; doc_type_label: string; doc_date: string; status_label: string; source: string; supplier: string; customer: string;
    taxable_total: string; tax_total: string; total_amount: string | null; file: string | null; imported_at: string;
    summaries: { key: string; label: string; taxable: string; tax: string }[]; lines: { no: number; description: string; total: string; rate: string }[] };
const props = defineProps<{ a: Side; b: Side }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
/* Ticket 08: le righe che differiscono vengono evidenziate. */
const facts = computed(() => [
    ['Tipo documento', `${props.a.doc_type} · ${props.a.doc_type_label}`, `${props.b.doc_type} · ${props.b.doc_type_label}`],
    ['Numero', props.a.number, props.b.number],
    ['Data', date(props.a.doc_date), date(props.b.doc_date)],
    ['Cedente', props.a.supplier, props.b.supplier],
    ['Cessionario', props.a.customer, props.b.customer],
    ['Imponibile', money(props.a.taxable_total), money(props.b.taxable_total)],
    ['IVA', money(props.a.tax_total), money(props.b.tax_total)],
    ['Totale documento', props.a.total_amount ? money(props.a.total_amount) : '—', props.b.total_amount ? money(props.b.total_amount) : '—'],
    ['Stato', props.a.status_label, props.b.status_label],
]);
const sumKeys = computed(() => [...new Set([...props.a.summaries, ...props.b.summaries].map((s) => s.key))]);
const sumOf = (s: Side, k: string) => s.summaries.find((x) => x.key === k);
const lineNos = computed(() => [...new Set([...props.a.lines, ...props.b.lines].map((l) => l.no))].sort((x, y) => x - y));
const lineOf = (s: Side, n: number) => s.lines.find((l) => l.no === n);
const lineText = (l?: { description: string; total: string; rate: string }) => (l ? `${l.description} · ${money(l.total)} · ${l.rate.includes('.') ? money(l.rate) + '%' : l.rate}` : '—');
</script>

<template>
    <Head title="Confronta documenti" />
    <AppLayout crumb="Confronta documenti">
        <div class="page-head">
            <div>
                <p class="eyebrow">Possibile conflitto</p>
                <h1>Confronta documenti</h1>
                <p>Stesso emittente, tipo, anno e numero, ma contenuto diverso. Le righe evidenziate sono diverse. Le registrazioni esistenti restano invariate: decidi quale documento tenere (puoi scartare o eliminare l’altro).</p>
            </div>
        </div>
        <section class="panel">
            <div class="table-wrap">
                <table class="compare">
                    <thead><tr><th></th><th>Nuovo · <Link :href="`${base}/fatture/${a.id}`">apri</Link><br><span class="small muted">{{ a.file ?? (a.source === 'manuale' ? 'Inserita manualmente' : '') }} · {{ dateTime(a.imported_at) }}</span></th><th>Esistente · <Link :href="`${base}/fatture/${b.id}`">apri</Link><br><span class="small muted">{{ b.file ?? (b.source === 'manuale' ? 'Inserita manualmente' : '') }} · {{ dateTime(b.imported_at) }}</span></th></tr></thead>
                    <tbody>
                        <tr v-for="[label, x, y] in facts" :key="label" :class="{ diff: x !== y }"><th scope="row">{{ label }}</th><td class="tabular">{{ x }}</td><td class="tabular">{{ y }}</td></tr>
                        <tr class="group-row"><th colspan="3">Riepilogo IVA</th></tr>
                        <tr v-for="k in sumKeys" :key="k" :class="{ diff: JSON.stringify(sumOf(a, k)) !== JSON.stringify(sumOf(b, k)) }">
                            <th scope="row">{{ (sumOf(a, k) ?? sumOf(b, k))!.label }}</th>
                            <td class="tabular">{{ sumOf(a, k) ? `${money(sumOf(a, k)!.taxable)} / ${money(sumOf(a, k)!.tax)}` : '—' }}</td>
                            <td class="tabular">{{ sumOf(b, k) ? `${money(sumOf(b, k)!.taxable)} / ${money(sumOf(b, k)!.tax)}` : '—' }}</td>
                        </tr>
                        <tr class="group-row"><th colspan="3">Righe</th></tr>
                        <tr v-for="n in lineNos" :key="n" :class="{ diff: lineText(lineOf(a, n)) !== lineText(lineOf(b, n)) }"><th scope="row">{{ n }}</th><td>{{ lineText(lineOf(a, n)) }}</td><td>{{ lineText(lineOf(b, n)) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
