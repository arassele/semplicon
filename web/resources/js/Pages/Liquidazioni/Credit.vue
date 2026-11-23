<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import { fromCents, toCents } from '@/Components/Scadenziario/types';
import type { Credit } from '@/Components/Liquidazioni/types';
import type { SharedProps } from '@/types';

const props = defineProps<{ year: number; period: number; settlement: { label: string; credit: string; figures: Record<string, string> }; credits: { own: Credit[]; origins: Credit[] };
    tr: { quarter: number; eligible: boolean; matured: string; admissible: string; threshold: string; reasons: string[]; deadline: string | null; existing: boolean } | null;
    presupposti: Record<string, string>; annualPresupposti: Record<string, string> }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const done = computed(() => [...props.credits.own, ...props.credits.origins].flatMap((c) => c.movements.filter((m) => m.status !== 'sbloccata').map((m) => ({ ...m, credit: c.description }))));
const all = computed(() => [...props.credits.own, ...props.credits.origins].filter((c) => toCents(c.residual) > 0));

// Proposta iniziale: tutto a riporto.
const form = useForm({ rows: all.value.map((c) => ({ credit_id: c.id, riporto: c.destinations.riporto.ok ? c.residual.replace('.', ',') : '', compensazione: '', rimborso: '' })), presupposto: '', documentation: '' });
const left = (i: number) => toCents(all.value[i].residual) - ['riporto', 'compensazione', 'rimborso'].reduce((s, k) => s + (toCents((form.rows[i] as Record<string, string | number>)[k] as string) || 0), 0);
const anyRefund = computed(() => form.rows.some((r) => toCents(r.rimborso) > 0));
const e = (k: string) => (form.errors as Record<string, string>)[k];
const save = () => form.post(`${base.value}/crediti-iva/destinazione`, { preserveScroll: true });

const trForm = useForm({ refund: '', compensation: '', presupposto: '', documentation: '', visto: false });
const trOpen = ref(false);
const saveTr = () => trForm.post(`${base.value}/liquidazioni/tr/${props.year}/${props.tr!.quarter}`);
const LABEL = { riporto: 'Riporto IVA su IVA', compensazione: 'Compensazione in F24', rimborso: 'Rimborso' } as const;
</script>

<template>
    <Head title="Destinazione del credito IVA" />
    <AppLayout crumb="Liquidazioni IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/liquidazioni/${year}/${period}`">Liquidazione {{ settlement.label }}</Link></p>
                <h1>Destinazione del credito IVA</h1>
                <p>Risultato a credito {{ money(settlement.credit) }}: credito maturato nel periodo {{ money(settlement.figures.own_credit) }}<template v-if="credits.origins.length">, riporti precedenti ancora disponibili</template>. Proposta: riporto alla liquidazione successiva. Ogni quota ha una sola destinazione.</p>
            </div>
            <div class="actions"><Link class="btn" :href="`${base}/crediti-iva`"><Icon name="shield" />Registro crediti</Link></div>
        </div>

        <form class="panel" novalidate @submit.prevent="save">
            <div v-if="all.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th>Credito</th><th class="num">Disponibile</th><th v-for="(l, k) in LABEL" :key="k" class="num">{{ l }}</th><th class="num">Residuo</th></tr></thead>
                    <tbody>
                        <tr v-for="(c, i) in all" :key="c.id">
                            <td><strong>{{ c.description }}</strong><br><span class="small muted">{{ c.kind_label }}<template v-if="credits.origins.some((o) => o.id === c.id)"> · riporto precedente</template> · riporto a {{ c.next.label }}</span></td>
                            <td class="num tabular">{{ money(c.residual) }}</td>
                            <td v-for="(l, k) in LABEL" :key="k" class="num" style="min-width: 150px">
                                <input v-model="(form.rows[i] as unknown as Record<string, string>)[k]" class="input tabular" style="text-align: right" inputmode="decimal" :disabled="!c.destinations[k].ok" :aria-label="`${l} per ${c.description}`">
                                <p v-for="r in c.destinations[k].reasons" :key="r" class="hint" :class="c.destinations[k].ok ? 'text-warn' : ''" style="text-align: left">{{ r }}</p>
                                <p v-if="c.destinations[k].first_date && k === 'compensazione'" class="hint" style="text-align: left">Prima data utile {{ date(c.destinations[k].first_date) }}</p>
                                <p v-if="e(`rows.${i}.${k}`)" class="error">{{ e(`rows.${i}.${k}`) }}</p>
                            </td>
                            <td class="num tabular" :class="{ 'text-bad': left(i) < 0 }"><strong>{{ money(fromCents(left(i)).replace(',', '.')) }}</strong><p v-if="e(`rows.${i}`)" class="error">{{ e(`rows.${i}`) }}</p></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">{{ done.length ? 'Tutto il credito del periodo è destinato.' : 'Nessun credito ancora da destinare per questo periodo.' }}</p>
            <div v-if="done.length" class="panel-body"><h3 class="small-head">Destinazioni già confermate</h3>
                <ul class="plain small"><li v-for="m in done" :key="m.id">{{ m.credit }} · {{ m.destination_label }} {{ money(m.amount) }} · {{ m.status_label }}<template v-if="m.target"> · <Link :href="`${base}/liquidazioni/${m.target.year}/${m.target.period}`">liquidazione {{ m.target.period }}/{{ m.target.year }}</Link></template></li></ul></div>
            <div v-if="anyRefund" class="panel-body acc-grid">
                <div class="field"><label for="cr-pres">Presupposto del rimborso</label><select id="cr-pres" v-model="form.presupposto" class="input"><option value="">Scegli</option><option v-for="(l, k) in annualPresupposti" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field span2"><label for="cr-doc">Documentazione</label><input id="cr-doc" v-model="form.documentation" class="input" placeholder="es. prospetto aliquote, elenco beni ammortizzabili"></div>
            </div>
            <div v-if="all.length" class="panel-body actions"><button class="btn primary" type="submit" :disabled="form.processing || all.some((_, i) => left(i) < 0)"><Icon name="check" />Conferma destinazione</button><span class="small muted">Gli importi non destinati restano disponibili nel registro crediti.</span></div>
        </form>

        <section v-if="tr" class="panel" style="margin-top: 24px">
            <div class="panel-head"><div><h2>Verifica infrannuale · IVA TR {{ tr.quarter }}° trimestre {{ year }}</h2>
                <p>Credito maturato nel trimestre solare (senza riporti precedenti): <strong>{{ money(tr.matured) }}</strong> · soglia: oltre {{ money(tr.threshold) }}<template v-if="tr.deadline"> · presentazione entro il {{ date(tr.deadline) }}</template></p></div>
                <span class="badge" :class="tr.eligible ? 'good' : ''">{{ tr.eligible ? 'Richiedibile' : 'Non richiedibile' }}</span></div>
            <ul v-if="tr.reasons.length" class="panel-body plain small"><li v-for="r in tr.reasons" :key="r">{{ r }}</li></ul>
            <div v-if="tr.eligible && !tr.existing" class="panel-body">
                <button v-if="!trOpen" class="btn" type="button" @click="trOpen = true">Prepara pratica IVA TR (quota ammissibile {{ money(tr.admissible) }})</button>
                <form v-else class="acc-grid" novalidate @submit.prevent="saveTr">
                    <div class="field"><label for="tr-ref">Rimborso</label><input id="tr-ref" v-model="trForm.refund" class="input tabular" inputmode="decimal"></div>
                    <div class="field"><label for="tr-comp">Compensazione in F24</label><input id="tr-comp" v-model="trForm.compensation" class="input tabular" inputmode="decimal"></div>
                    <div class="field"><label for="tr-pres">Presupposto</label><select id="tr-pres" v-model="trForm.presupposto" class="input"><option value="">Scegli</option><option v-for="(l, k) in presupposti" :key="k" :value="k">{{ l }}</option></select><p v-if="trForm.errors.presupposto" class="error">{{ trForm.errors.presupposto }}</p></div>
                    <div class="field span2"><label for="tr-doc">Documentazione</label><input id="tr-doc" v-model="trForm.documentation" class="input"></div>
                    <label class="check"><input v-model="trForm.visto" type="checkbox"> Visto di conformità sull’istanza</label>
                    <p v-if="(trForm.errors as Record<string, string>).tr" class="error span3">{{ (trForm.errors as Record<string, string>).tr }}</p>
                    <div class="actions span3"><button class="btn primary" type="submit" :disabled="trForm.processing">Crea pratica in bozza</button></div>
                </form>
            </div>
            <p v-if="tr.existing" class="panel-body small">Pratica IVA TR già presente: <Link :href="`${base}/crediti-iva`">registro crediti</Link>.</p>
        </section>
    </AppLayout>
</template>
