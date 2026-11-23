<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { date, money } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps, YearItem } from '@/types';

type Task = { key: string; count: number; label: string; url: string; tone: string };
type Missing = { id: number; number: string; doc_date: string; direction: string | null; direction_label: string; counterparty: string; total: string; status: string; url: string };
type Area = { id: string; label: string; url: string; items: { label: string; value: string; tone: string; url: string }[] };
type Series = { in: number[]; out: number[]; in_total: number; out_total: number; result: number };
type Due = { date: string; kind: string; label: string; amount: string; overdue: boolean; url: string };
const props = defineProps<{ currentYear: number; years: YearItem[]; invoices: { acquisto: number; vendita: number; da_verificare: number };
    overview: { year: number; areas: Area[]; analysis: { year: number; accrual: Series & { vat_out: number; vat_in: number }; cash: Series }; tasks: Task[]; missing: Missing[]; missing_total: number; deadlines: Due[]; today: string } }>();
const company = computed(() => usePage<SharedProps>().props.currentCompany!);
const base = computed(() => `/aziende/${company.value.id}`);
/* Analisi entrate/uscite: Competenza (fatture registrate) o Cassa (pagamenti rilevati), per mese */
const mode = ref<'accrual' | 'cash'>('accrual');
const ser = computed(() => props.overview.analysis[mode.value]);
const months = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
const eur = (c: number) => money(`${c < 0 ? '-' : ''}${Math.floor(Math.abs(c) / 100)}.${String(Math.abs(c) % 100).padStart(2, '0')}`);
const top = computed(() => Math.max(1, ...ser.value.in, ...ser.value.out));
const niceTop = computed(() => { const mag = 10 ** Math.floor(Math.log10(top.value)); const n = [1, 2, 2.5, 5, 10].map((k) => k * mag).find((v) => v >= top.value) ?? top.value; return n; });
const bar = (v: number) => Math.max(v > 0 ? 1 : 0, (v / niceTop.value) * 170);
const compact = (c: number) => (c >= 100000000 ? `${(c / 100000000).toFixed(1).replace('.', ',')} Mln` : c >= 100000 ? `${Math.round(c / 100000)} k` : String(Math.round(c / 100)));
const current = computed(() => props.years.find((y) => y.year === props.currentYear) ?? null);
</script>

<template>
    <Head title="Panoramica" />
    <AppLayout crumb="Panoramica">
        <div class="page-head">
            <div>
                <p class="eyebrow">Panoramica</p>
                <h1>{{ company.name }}</h1>
                <p class="tabular">P. IVA {{ company.partita_iva }}</p>
            </div>
        </div>

        <!-- Panoramica: attività aperte, registrazioni mancanti, prossime scadenze -->
        <section class="panel" aria-labelledby="tasks-title" style="margin-bottom: 24px">
            <div class="panel-head"><div><h2 id="tasks-title">Da fare</h2><p>Attività aperte: ogni riga porta alla pagina in cui completarla.</p></div>
                <span v-if="!overview.tasks.length" class="badge good">Tutto in ordine</span></div>
            <ul v-if="overview.tasks.length" class="list dash-tasks" style="margin-top: 12px">
                <li v-for="t in overview.tasks" :key="t.key">
                    <Link :href="t.url" class="dash-task"><span class="dash-count tabular" :class="t.tone">{{ t.count }}</span><span>{{ t.label }}</span><Icon name="arrow" /></Link>
                </li>
            </ul>
            <p v-else class="panel-body muted small">Nessuna attività aperta.</p>
        </section>

        <section class="panel" aria-labelledby="areas-title" style="margin-bottom: 24px">
            <div class="panel-head"><div><h2 id="areas-title">Stato per area · {{ overview.year }}</h2><p>Le stesse aree del menu: numeri dell’anno contabile scelto e lavoro ancora aperto.</p></div></div>
            <div class="areas">
                <div v-for="a in overview.areas" :key="a.id" class="area">
                    <h3><Link :href="a.url">{{ a.label }}</Link></h3>
                    <ul>
                        <li v-for="(it, k) in a.items" :key="k"><Link :href="it.url" class="area-row"><span>{{ it.label }}</span><strong class="tabular" :class="`tone-${it.tone}`">{{ it.value }}</strong></Link></li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="panel" aria-labelledby="flow-title" style="margin-bottom: 24px">
            <div class="panel-head">
                <div><h2 id="flow-title">Entrate e uscite · {{ overview.analysis.year }}</h2><p>{{ mode === 'accrual' ? 'Imponibile delle fatture registrate nei registri IVA (note di credito in negativo).' : 'Incassi e pagamenti registrati nello Scadenziario, per data del movimento.' }}</p></div>
                <div class="segmented" role="group" aria-label="Base di calcolo">
                    <button type="button" :aria-pressed="mode === 'accrual'" @click="mode = 'accrual'">Competenza</button>
                    <button v-if="usePage<SharedProps>().props.currentCompany!.modules.scadenziario" type="button" :aria-pressed="mode === 'cash'" @click="mode = 'cash'">Cassa</button>
                </div>
            </div>
            <div class="panel-body">
                <dl class="facts flow-kpi">
                    <div><dt>{{ mode === 'accrual' ? 'Ricavi (imponibile)' : 'Incassato' }}</dt><dd class="tabular">{{ eur(ser.in_total) }} €</dd></div>
                    <div><dt>{{ mode === 'accrual' ? 'Costi (imponibile)' : 'Pagato' }}</dt><dd class="tabular">{{ eur(ser.out_total) }} €</dd></div>
                    <div><dt>{{ mode === 'accrual' ? 'Risultato' : 'Saldo di cassa' }}</dt><dd class="tabular" :class="ser.result < 0 ? 'text-bad' : ''">{{ eur(ser.result) }} €</dd></div>
                    <template v-if="mode === 'accrual'">
                        <div><dt>IVA a debito</dt><dd class="tabular">{{ eur(overview.analysis.accrual.vat_out) }} €</dd></div>
                        <div><dt>IVA detraibile</dt><dd class="tabular">{{ eur(overview.analysis.accrual.vat_in) }} €</dd></div>
                    </template>
                </dl>
                <svg class="flow" viewBox="0 0 720 230" role="img" :aria-label="`${mode === 'accrual' ? 'Ricavi e costi' : 'Incassi e pagamenti'} per mese, ${overview.analysis.year}: totale entrate ${eur(ser.in_total)} euro, uscite ${eur(ser.out_total)} euro`">
                    <g v-for="t in 4" :key="t" class="grid"><line x1="46" x2="712" :y1="190 - (t * 170) / 4" :y2="190 - (t * 170) / 4" /><text x="40" :y="194 - (t * 170) / 4" text-anchor="end">{{ compact((niceTop * t) / 4) }}</text></g>
                    <g v-for="(m, i) in months" :key="m">
                        <rect class="bar-in" :x="52 + i * 55.5" :y="190 - bar(ser.in[i])" width="19" :height="bar(ser.in[i])" rx="2" />
                        <rect class="bar-out" :x="73 + i * 55.5" :y="190 - bar(ser.out[i])" width="19" :height="bar(ser.out[i])" rx="2" />
                        <text class="mlabel" :x="72 + i * 55.5" y="208" text-anchor="middle">{{ m }}</text>
                    </g>
                </svg>
                <p class="legend"><span class="sw in" />{{ mode === 'accrual' ? 'Ricavi' : 'Incassi' }} <span class="sw out" />{{ mode === 'accrual' ? 'Costi' : 'Pagamenti' }}</p>
                <details class="small"><summary>Mostra i valori in tabella</summary>
                    <div class="table-wrap"><table class="data"><thead><tr><th>Mese</th><th class="num">{{ mode === 'accrual' ? 'Ricavi' : 'Incassi' }}</th><th class="num">{{ mode === 'accrual' ? 'Costi' : 'Pagamenti' }}</th></tr></thead>
                        <tbody><tr v-for="(m, i) in months" :key="m"><td>{{ m }}</td><td class="num tabular">{{ eur(ser.in[i]) }}</td><td class="num tabular">{{ eur(ser.out[i]) }}</td></tr></tbody></table></div></details>
            </div>
        </section>

        <div class="split" style="margin-bottom: 24px">
            <section class="panel" aria-labelledby="missing-title">
                <div class="panel-head"><div><h2 id="missing-title">Registrazioni mancanti</h2><p>Le fatture più vecchie ancora da registrare<template v-if="overview.missing_total"> ({{ overview.missing_total }} in tutto)</template>.</p></div></div>
                <div v-if="overview.missing.length" class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Fattura</th><th>Controparte</th><th class="num">Totale</th><th><span class="sr-only">Apri</span></th></tr></thead>
                        <tbody>
                            <tr v-for="m in overview.missing" :key="m.id">
                                <td><Link :href="m.url">{{ m.number }}</Link><div class="small muted">{{ m.direction_label }} · {{ date(m.doc_date) }}<template v-if="m.status === 'da_verificare'"> · da verificare</template></div></td>
                                <td>{{ m.counterparty }}</td>
                                <td class="num tabular">{{ money(m.total) }}</td>
                                <td><Link :href="m.url" class="icon-btn" :aria-label="`Visualizza fattura ${m.number}`" title="Visualizza fattura"><Icon name="search" /></Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="panel-body muted small">Tutte le fatture sono registrate.</p>
                <div v-if="overview.missing_total" class="panel-body" style="padding-top: 12px"><Link :href="`${base}/fatture?stato=aperte`" class="btn">Vai a Da registrare<Icon name="arrow" /></Link></div>
            </section>
            <section class="panel" aria-labelledby="due-title">
                <div class="panel-head"><div><h2 id="due-title">Prossime scadenze</h2><p>Incassi, pagamenti, versamenti IVA e operazioni transfrontaliere nei prossimi 45 giorni e scaduti.</p></div></div>
                <ul v-if="overview.deadlines.length" class="list" style="margin-top: 12px">
                    <li v-for="(d, k) in overview.deadlines" :key="k" class="dash-due">
                        <div class="dash-date tabular" :class="{ 'text-bad': d.overdue }">{{ date(d.date) }}<span v-if="d.overdue" class="small"> · scaduta</span></div>
                        <div class="dash-due-main"><Link :href="d.url">{{ d.label }}</Link><p class="meta">{{ d.kind }}</p></div>
                        <div class="tabular">{{ money(d.amount) }}</div>
                    </li>
                </ul>
                <p v-else class="panel-body muted small">Nessuna scadenza nei prossimi 45 giorni.</p>
            </section>
        </div>

        <div class="split">
            <section class="panel" aria-labelledby="year-title">
                <div class="panel-head">
                    <div>
                        <h2 id="year-title">Regime IVA {{ currentYear }}</h2>
                        <p>Vale dal 1° gennaio al 31 dicembre {{ currentYear }}.</p>
                    </div>
                    <span v-if="current?.active" class="badge good">Confermato · v{{ current.active.version }}</span>
                    <span v-else class="badge warn">Da confermare</span>
                </div>
                <div class="panel-body">
                    <template v-if="current?.active">
                        <p class="regime-line">{{ current.active.regime_label }}</p>
                        <dl class="facts">
                            <div><dt>Forfettario con IVA</dt><dd>{{ current.active.forfettario_iva ? 'Sì' : 'No' }}</dd></div>
                            <div><dt>Periodicità</dt><dd>{{ current.active.periodicity_label }}</dd></div>
                            <div><dt>Pro rata</dt><dd class="tabular">{{ current.active.pro_rata.replace('.', ',') }}%</dd></div>
                        </dl>
                    </template>
                    <div v-else class="notice warn">
                        <Icon name="alert" style="width: 20px; flex: none" />
                        <div>
                            <strong>Nessun regime confermato per il {{ currentYear }}</strong>
                            Scegli regime e periodicità per l’anno. Semplicon non deduce il regime dalle fatture.
                        </div>
                    </div>
                    <div class="actions" style="margin-top: 20px">
                        <Link :href="`${base}/profilo-iva?anno=${currentYear}`" class="btn" :class="{ primary: !current?.active }">
                            {{ current?.active ? 'Vedi regime IVA' : current?.has_draft ? 'Continua la bozza' : 'Imposta regime IVA' }}<Icon name="arrow" />
                        </Link>
                    </div>
                </div>
            </section>

            <div class="stack">
            <section class="panel" aria-labelledby="inv-title">
                <div class="panel-head"><div><h2 id="inv-title">Fatture</h2><p>Importate da file FatturaPA.</p></div></div>
                <dl class="facts panel-body">
                    <div><dt>Acquisti</dt><dd class="tabular"><Link :href="`${base}/acquisti`">{{ invoices.acquisto }}</Link></dd></div>
                    <div><dt>Vendite</dt><dd class="tabular"><Link :href="`${base}/vendite`">{{ invoices.vendita }}</Link></dd></div>
                    <div><dt>Da verificare</dt><dd class="tabular"><Link :href="`${base}/fatture?stato=da_verificare`">{{ invoices.da_verificare }}</Link></dd></div>
                </dl>
                <div class="panel-body" style="padding-top: 0"><Link :href="`${base}/importa`" class="btn"><Icon name="upload" />Importa XML</Link></div>
            </section>
            <section class="panel" aria-labelledby="years-title">
                <div class="panel-head"><div><h2 id="years-title">Anni</h2><p>Un regime per anno solare.</p></div></div>
                <ul v-if="years.length" class="list" style="margin-top: 12px">
                    <li v-for="y in years" :key="y.year">
                        <div>
                            <Link :href="`${base}/profilo-iva?anno=${y.year}`" class="title tabular">{{ y.year }}</Link>
                            <p class="meta">{{ y.active ? `${y.active.regime_label}${y.active.forfettario_iva ? ' · Forfettario con IVA' : ''}` : 'Nessun regime confermato' }}</p>
                        </div>
                        <span v-if="y.active" class="badge good">Confermato</span>
                        <span v-if="y.has_draft" class="badge warn">Bozza</span>
                    </li>
                </ul>
                <p v-else class="panel-body muted small">Ancora nessun anno impostato.</p>
            </section>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.areas { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); border-top: 1px solid var(--ct-border, #dce3dd); }
.area { padding: 16px 20px; border-right: 1px solid var(--ct-border, #dce3dd); border-bottom: 1px solid var(--ct-border, #dce3dd); }
.area h3 { margin: 0 0 8px; font-size: 0.9375rem; }
.area ul { list-style: none; margin: 0; padding: 0; }
.area-row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; text-decoration: none; color: inherit; font-size: 0.875rem; border-bottom: 1px solid var(--ct-border, #eef1ed); }
.area-row:hover strong { text-decoration: underline; }
.tone-warn { color: #7a4b00; } .tone-bad { color: #a32626; } .tone-good { color: #17603d; }
.flow-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 12px; }
.flow { width: 100%; height: auto; max-height: 280px; }
.flow .grid line { stroke: #dce3dd; stroke-width: 1; } .flow text { font-size: 10px; fill: #52665f; } .flow .mlabel { font-size: 10.5px; }
.bar-in { fill: #1f7a5c; } .bar-out { fill: #b4761a; }
.legend { display: flex; gap: 14px; align-items: center; font-size: 0.8125rem; color: #52665f; margin: 4px 0 8px; }
.sw { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: -8px; } .sw.in { background: #1f7a5c; } .sw.out { background: #b4761a; }
</style>
