<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Tabs from '@/Components/Scadenziario/Tabs.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Entry = { date: string; account: string | null; basis: string; amount: string; fiscal_amount: string; reason: string; allocation_id: number | null };
type Doc = { invoice_id: number; number: string; doc_date: string; doc_type: string; party: string; nature: 'ricavo' | 'costo'; amount: string; fiscal: string; check: boolean; entries: Entry[] };
defineProps<{ year: number; years: number[]; data: { year: number; mode: [string, string]; totals: Record<string, string>; documents: Doc[]; checks: { invoice_id: number; number: string; party: string; reason: string }[] };
    openAtYearEnd: { sale: string; purchase: string } }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const open = ref<number | null>(null);
const BASIS: Record<string, string> = { cassa: 'Per cassa', registrazione: 'Alla registrazione', da_verificare: 'Da verificare' };
</script>

<template>
    <Head title="Riepilogo fiscale per cassa" />
    <AppLayout crumb="Incassi e pagamenti">
        <div class="page-head">
            <div>
                <p class="eyebrow">Incassi e pagamenti</p>
                <h1>Riepilogo fiscale per cassa {{ year }}</h1>
                <p>Quali importi incassati e pagati appartengono al {{ year }}: per ogni documento le quote imputate, il motivo e le verifiche aperte. Calcolo per conto, in centesimi; il registro IVA non viene modificato.</p>
            </div>
            <div class="actions"><label class="sr-only" for="fy">Anno</label><select id="fy" class="input" :value="year" @change="router.get(`${base}/scadenziario/riepilogo-fiscale`, { anno: ($event.target as HTMLSelectElement).value })"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
        </div>
        <Tabs :base="base" current="riepilogo-fiscale" />

        <section class="panel">
            <div class="panel-body">
                <p><span class="badge" :class="data.mode[0] === 'da_verificare' ? 'warn' : 'good'">{{ BASIS[data.mode[0]] ?? data.mode[0] }}</span> {{ data.mode[1] }}</p>
                <div class="sc-kpis">
                    <div><span class="small muted">Ricavi/compensi imputati</span><strong class="tabular">{{ money(data.totals.ricavo) }}</strong><span class="small muted">rilevanti {{ money(data.totals.ricavo_fiscale) }}</span></div>
                    <div><span class="small muted">Costi/spese imputati</span><strong class="tabular">{{ money(data.totals.costo) }}</strong><span class="small muted">deducibili {{ money(data.totals.costo_fiscale) }}</span></div>
                    <div><span class="small muted">Da verificare</span><strong class="tabular" :class="{ 'text-warn': data.totals.da_verificare !== '0.00' }">{{ money(data.totals.da_verificare) }}</strong></div>
                    <div><span class="small muted">Residuo da incassare al 31/12</span><strong class="tabular">{{ money(openAtYearEnd.sale) }}</strong><Link class="small" :href="`${base}/scadenziario/da-incassare?al_giorno=${year}-12-31`">Dettaglio</Link></div>
                    <div><span class="small muted">Residuo da pagare al 31/12</span><strong class="tabular">{{ money(openAtYearEnd.purchase) }}</strong><Link class="small" :href="`${base}/scadenziario/da-pagare?al_giorno=${year}-12-31`">Dettaglio</Link></div>
                </div>
            </div>
        </section>

        <section class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Documenti</h2></div>
            <div v-if="data.documents.length" class="table-wrap" style="margin-top: 14px">
                <table class="register">
                    <thead><tr><th>Documento</th><th>Cliente/Fornitore</th><th>Natura</th><th class="num">Imputato {{ year }}</th><th class="num">Rilevante</th><th><span class="sr-only">Dettaglio</span></th></tr></thead>
                    <tbody>
                        <template v-for="d in data.documents" :key="d.invoice_id">
                            <tr>
                                <td><Link :href="`${base}/fatture/${d.invoice_id}#scadenze`"><strong>{{ d.number }}</strong></Link> <span class="small muted">del {{ date(d.doc_date) }}<template v-if="d.doc_type === 'TD04'"> · nota di credito</template></span></td>
                                <td>{{ d.party }}</td>
                                <td>{{ d.nature === 'ricavo' ? 'Ricavo' : 'Costo' }}<template v-if="d.check"> <span class="badge warn">Da verificare</span></template></td>
                                <td class="num tabular">{{ money(d.amount) }}</td>
                                <td class="num tabular">{{ money(d.fiscal) }}</td>
                                <td><button class="btn link" type="button" :aria-expanded="open === d.invoice_id" @click="open = open === d.invoice_id ? null : d.invoice_id">Motivi</button></td>
                            </tr>
                            <tr v-if="open === d.invoice_id" class="sub-row"><td colspan="6">
                                <ul class="plain small"><li v-for="(e, i) in d.entries" :key="i">{{ date(e.date) }} · {{ BASIS[e.basis] }} · {{ money(e.amount) }}<template v-if="e.account"> · {{ e.account }}</template><br><span class="muted">{{ e.reason }}</span></li></ul>
                            </td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessun importo imputato al {{ year }}.</p>
        </section>

        <section v-if="data.checks.length" class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Verifiche aperte</h2></div>
            <div class="panel-body"><ul class="plain"><li v-for="c in data.checks" :key="c.invoice_id"><Link :href="`${base}/fatture/${c.invoice_id}#scadenze`">{{ c.number }}</Link> · {{ c.party }} · <span class="text-warn">{{ c.reason }}</span></li></ul></div>
        </section>
    </AppLayout>
</template>
