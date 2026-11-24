<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Conto economico: per competenza, per cassa, ai fini fiscali (Professionisti per cassa, Semplificata per competenza alla registrazione). */
type Row = { account_id: number | null; code: string; name: string; amount: string; fiscal: string };
type Group = { key: string; label: string; amount: string; fiscal: string; rows: Row[] };
type Data = { year: number; view: 'competenza' | 'cassa' | 'fiscale'; views: Record<string, string>; criterion: string; mode: string; ricavi: Group[]; costi: Group[];
    totals: Record<string, string>; da_verificare: string; pending: { invoice_id: number; number: string; nature: string; amount: string; reason: string }[]; notes: string[] };
const props = defineProps<{ data: Data; compare: Data | null; compareYears: { year: number; has_data: boolean }[] }>();
// Confronto con un anno precedente (richieste del 24.11.2026)
const cmp = computed(() => props.compare);
const setCompare = (e: Event) => { const v = (e.target as HTMLSelectElement).value; router.get(`${base.value}/conto-economico`, { vista: props.data.view, ...(v ? { confronto: v } : {}) }, { preserveScroll: true }); };
const cents = (v: string | undefined) => Math.round(Number(v ?? '0') * 100);
const prevGroup = (side: 'ricavi' | 'costi', key: string) => cmp.value?.[side].find((g) => g.key === key)?.amount ?? '0.00';
const prevRow = (side: 'ricavi' | 'costi', key: string, code: string) => cmp.value?.[side].find((g) => g.key === key)?.rows.find((r) => r.code === code)?.amount ?? '0.00';
const diff = (a: string, b: string) => { const d = cents(a) - cents(b); return `${d > 0 ? '+' : d < 0 ? '−' : ''}${money((Math.abs(d) / 100).toFixed(2))}`; };
const pctv = (a: string, b: string) => { const x = cents(a), y = cents(b); return y ? `${x >= y ? '+' : '−'}${(Math.abs((x - y) / y) * 100).toFixed(1).replace('.', ',')}%` : '—'; };
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const fiscal = computed(() => props.data.view === 'fiscale');
const neg = (v: string) => v.startsWith('-');
const sides = computed(() => [['ricavi', 'Ricavi', props.data.ricavi, 'ricavi'], ['costi', 'Costi', props.data.costi, 'costi']] as const);
</script>

<template>
    <Head title="Conto economico" />
    <AppLayout crumb="Conto economico">
        <div class="page-head">
            <div>
                <p class="eyebrow">Analisi</p>
                <h1>Conto economico · {{ data.year }}</h1>
                <p>Ricavi e costi per sezione e conto, dai dati registrati in Semplicon (registri IVA, fuori campo IVA, incassi e pagamenti). Non sostituisce il bilancio: niente ammortamenti, ratei, risconti o rimanenze.</p>
            </div>
            <div class="actions">
                <a class="btn" :href="`${base}/conto-economico/csv?vista=${data.view}`"><Icon name="download" />CSV</a>
                <a class="btn" :href="`${base}/conto-economico/pdf?vista=${data.view}`"><Icon name="download" />PDF</a>
            </div>
        </div>

        <nav class="segmented" aria-label="Vista" style="margin-bottom: 16px">
            <Link v-for="(l, k) in data.views" :key="k" :href="`${base}/conto-economico?vista=${k}`" class="seg-link" :aria-current="data.view === k ? 'page' : undefined" preserve-scroll>{{ l }}</Link>
        </nav>

        <div class="field" style="max-width: 280px; margin: -4px 0 16px"><label for="ce-cmp">Confronto con</label>
            <select id="ce-cmp" class="input" :value="cmp ? String(cmp.year) : ''" @change="setCompare"><option value="">Nessun confronto</option><option v-for="y in compareYears" :key="y.year" :value="String(y.year)" :disabled="!y.has_data">{{ y.year }}{{ y.has_data ? '' : ' (nessun dato)' }}</option></select></div>
        <div class="notice info" role="status" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>{{ data.views[data.view] }}</strong>{{ data.criterion }}<template v-if="fiscal"> La colonna “Fiscale” applica la deducibilità del conto (Piano dei conti).</template></div></div>
        <div v-for="(n, k) in data.notes" :key="k" class="small muted" style="margin: -6px 0 12px">{{ n }}</div>

        <dl class="facts panel panel-body" style="margin-bottom: 20px">
            <div><dt>Ricavi</dt><dd class="tabular">{{ money(data.totals.ricavi) }} €</dd></div>
            <div><dt>Costi</dt><dd class="tabular">{{ money(data.totals.costi) }} €</dd></div>
            <div><dt>Risultato</dt><dd class="tabular" :class="{ 'text-bad': neg(data.totals.risultato) }">{{ money(data.totals.risultato) }} €</dd></div>
            <div v-if="fiscal"><dt>Risultato fiscale</dt><dd class="tabular" :class="{ 'text-bad': neg(data.totals.risultato_fiscale) }">{{ money(data.totals.risultato_fiscale) }} €</dd></div>
            <div v-if="fiscal && data.totals.maggiorazioni && data.totals.maggiorazioni !== '0.00'"><dt>Maggiorazioni (var. in diminuzione)</dt><dd class="tabular">−{{ money(data.totals.maggiorazioni) }} €</dd></div>
            <div v-if="fiscal && data.totals.maggiorazioni && data.totals.maggiorazioni !== '0.00'"><dt>Risultato fiscale dopo le variazioni</dt><dd class="tabular" :class="{ 'text-bad': neg(data.totals.risultato_dopo_variazioni) }">{{ money(data.totals.risultato_dopo_variazioni) }} €</dd></div>
            <div v-if="fiscal && data.da_verificare !== '0.00'"><dt>Da verificare</dt><dd class="tabular text-warn">{{ money(data.da_verificare) }} €</dd></div>
        </dl>

        <section v-for="[key, title, groups, tot] in sides" :key="key" class="panel" :aria-labelledby="`ce-${key}`" style="margin-bottom: 20px">
            <div class="panel-head"><h2 :id="`ce-${key}`">{{ title }}</h2></div>
            <div v-if="groups.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th>Conto</th><th class="num">{{ cmp ? data.year : 'Importo' }}</th><template v-if="cmp"><th class="num">{{ cmp.year }}</th><th class="num">Differenza</th><th class="num">Var. %</th></template><th v-if="fiscal" class="num">Fiscale</th></tr></thead>
                    <template v-for="g in groups" :key="g.key">
                        <tbody>
                            <tr class="group-row"><td><strong>{{ g.label }}</strong></td><td class="num tabular"><strong>{{ money(g.amount) }}</strong></td><template v-if="cmp"><td class="num tabular"><strong>{{ money(prevGroup(key, g.key)) }}</strong></td><td class="num tabular">{{ diff(g.amount, prevGroup(key, g.key)) }}</td><td class="num tabular">{{ pctv(g.amount, prevGroup(key, g.key)) }}</td></template><td v-if="fiscal" class="num tabular"><strong>{{ money(g.fiscal) }}</strong></td></tr>
                            <tr v-for="r in g.rows" :key="`${g.key}-${r.code}`"><td style="padding-left: 28px"><span class="tabular">{{ r.code }}</span> · {{ r.name }}</td><td class="num tabular">{{ money(r.amount) }}</td><template v-if="cmp"><td class="num tabular">{{ money(prevRow(key, g.key, r.code)) }}</td><td class="num tabular">{{ diff(r.amount, prevRow(key, g.key, r.code)) }}</td><td class="num tabular">{{ pctv(r.amount, prevRow(key, g.key, r.code)) }}</td></template><td v-if="fiscal" class="num tabular">{{ money(r.fiscal) }}</td></tr>
                        </tbody>
                    </template>
                    <tbody><tr class="sum-row"><td>Totale {{ title.toLowerCase() }}</td><td class="num tabular">{{ money(data.totals[tot]) }}</td><template v-if="cmp"><td class="num tabular">{{ money(cmp.totals[tot]) }}</td><td class="num tabular">{{ diff(data.totals[tot], cmp.totals[tot]) }}</td><td class="num tabular">{{ pctv(data.totals[tot], cmp.totals[tot]) }}</td></template><td v-if="fiscal" class="num tabular">{{ money(data.totals[`${tot}_fiscali`]) }}</td></tr></tbody>
                </table>
            </div>
            <p v-else class="panel-body muted small">Nessun importo nel {{ data.year }}.</p>
        </section>

        <section v-if="fiscal && data.pending.length" class="panel" aria-labelledby="ce-pending">
            <div class="panel-head"><div><h2 id="ce-pending">Da verificare</h2><p>Importi che non entrano nel risultato fiscale finché il criterio non è chiarito.</p></div></div>
            <div class="table-wrap"><table>
                <thead><tr><th>Fattura</th><th>Tipo</th><th class="num">Importo</th><th>Motivo</th></tr></thead>
                <tbody><tr v-for="p in data.pending" :key="p.invoice_id"><td><Link :href="`${base}/fatture/${p.invoice_id}`">{{ p.number }}</Link></td><td>{{ p.nature === 'ricavo' ? 'Ricavo' : 'Costo' }}</td><td class="num tabular">{{ money(p.amount) }}</td><td class="small">{{ p.reason }}</td></tr></tbody>
            </table></div>
        </section>
    </AppLayout>
</template>

<style scoped>
.seg-link { padding: 8px 16px; border-radius: 7px; font-weight: 550; font-size: 14px; text-decoration: none; color: inherit; }
.seg-link[aria-current='page'] { background: var(--ct-selected); color: var(--ct-forest); }
.group-row td { background: var(--ct-subtle, #f6f8f6); }
.text-warn { color: #7a4b00; }
</style>
