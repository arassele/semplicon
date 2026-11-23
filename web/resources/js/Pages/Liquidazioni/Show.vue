<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, dateTime, money } from '@/lib/format';
import { uuid } from '@/Components/Scadenziario/types';
import type { SharedProps } from '@/types';

type Doc = { registration_id: number; invoice_id: number; protocol: string; direction: string; status: string; doc_type: string; number: string; doc_date: string; counterparty: string; amount: string; tax: string | null; type?: string };
type Calc = { ok: boolean; quarterly_type?: string | null; issues: { field: string; message: string }[]; period_label?: string; profile_label?: string; pro_rata?: string; rule_version?: string; figures?: Record<string, string>;
    result?: string; due_date?: string; code?: { code: string; rateazione: string | null; label: string }; detail?: Record<string, Doc[]>; deferral_allowed?: boolean; deferred_from?: string | null;
    adjustments?: { id: number; kind: string; amount: string; causale: string; reference: string | null; author: string | null; date: string }[];
    credits?: { movement_id: number; credit_id: number; type: string; amount: string; status: string; origin: string; credit_residual: string }[];
    checks?: { message: string; documents: Doc[] }[]; notes?: string[]; year: number; period: number };
type Info = { id: number; version: number; status: string; result: string; amount_due: string; credit: string; deferred: string; due_date: string | null; review_reason: string | null; confirmed_by: string | null; confirmed_at: string; rule_version: string };
type F24 = { id: number; status: string; status_label: string; due_date: string; lines: { code: string; label: string; rateazione: string | null; year: number; debit: string; credit: string }[];
    total_debit: string; total_credit: string; balance: string; presented_at: string | null; protocol: string | null; outcome: string | null; debit_confirmed_at: string | null };
type Comp = { credit_id: number; description: string; kind: string; available: string; reserved: string; reasons: string[]; first_date: string | null; code: string | null; year: number };
const props = defineProps<{ year: number; period: number; label: string; draft: Calc; confirmed: (Info & { data: Calc }) | null; versions: Info[]; lipe: { quarter: number; year: number } | null;
    compare: { rows: { key: string; label: string; old: string; new: string | null; diff: string | null }[]; effects: { type: string; text: string; link?: { year: number; period: number }; f24_id?: number; claim_id?: number }[]; credit_diff: string; due_diff: string | null; new_checks: { message: string }[]; new_ok: boolean; reason: string | null } | null;
    f24: F24[]; compensable: Comp[]; creditSummary: unknown; prev: [number, number]; next: [number, number]; today: string; canAcconto: boolean }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const url = computed(() => `${base.value}/liquidazioni/${props.year}/${props.period}`);
const opts = { preserveScroll: true };

// Confermata: mostra la versione salvata; Bozza: calcolata in tempo reale.
const showDraft = ref(!props.confirmed);
const d = computed<Calc>(() => (showDraft.value || !props.confirmed ? props.draft : props.confirmed.data));
const f = computed(() => d.value.figures ?? {});
const RESULT: Record<string, string> = { debito: 'A debito', credito: 'A credito', zero: 'A zero' };
const neg = (v: string | undefined) => (v && v !== '0.00' ? (v.startsWith('-') ? v.slice(1) : `-${v}`) : '0.00');
const open = ref<string | null>(null);
const toggle = (k: string) => (open.value = open.value === k ? null : k);
const lines = computed(() => [
    { k: 'debit', label: 'IVA a debito', value: f.value.debit, detail: 'debit', bold: true },
    { k: 'sales', label: 'Vendite', value: f.value.sales, detail: 'vendite', sub: true },
    { k: 'integrations', label: 'Integrazioni reverse charge (TD16–TD19)', value: f.value.integrations, detail: 'integrazioni', sub: true },
    { k: 'adj_debit', label: 'Rettifiche manuali a debito', value: f.value.adj_debit, detail: 'adj', sub: true },
    { k: 'deductible', label: 'IVA detraibile', value: neg(f.value.deductible), detail: 'deductible', bold: true },
    { k: 'deductible_gross', label: 'Acquisti, dopo indetraibilità specifica', value: neg(f.value.deductible_gross), detail: 'acquisti', sub: true },
    { k: 'pro_rata_reduction', label: `Riduzione pro rata (${(d.value.pro_rata ?? '100.00').replace('.', ',')}%)`, value: f.value.pro_rata_reduction, sub: true },
    { k: 'adj_deductible', label: 'Rettifiche manuali alla detrazione', value: neg(f.value.adj_deductible), detail: 'adj', sub: true },
    { k: 'own', label: 'Saldo del periodo', value: f.value.own, bold: true },
    { k: 'deferred_in', label: `Debiti di modesto importo rinviati${d.value.deferred_from ? ` (da ${d.value.deferred_from})` : ''}`, value: f.value.deferred_in },
    { k: 'credit_previous', label: 'Credito dei periodi precedenti utilizzato', value: neg(f.value.credit_previous), detail: 'credits' },
    { k: 'credit_annual', label: 'Quota di credito annuale utilizzata', value: neg(f.value.credit_annual), detail: 'credits' },
    { k: 'acconto', label: 'Acconto IVA riconosciuto', value: neg(f.value.acconto), detail: 'adj' },
    { k: 'tax', label: 'Imposta', value: f.value.tax, bold: true },
    { k: 'interest', label: 'Interessi trimestrali (1%)', value: f.value.interest },
].filter((l) => l.k !== 'interest' || d.value.quarterly_type === 'opzione' || l.value !== '0.00'));
const detailDocs = (k: string): Doc[] => (d.value.detail?.[k] ?? []) as Doc[];

const confirming = ref(false);
const reqId = ref(uuid());
const confirm = () => { if (confirming.value) return; confirming.value = true; router.post(`${url.value}/conferma`, { request_id: reqId.value }, { ...opts, onFinish: () => { confirming.value = false; reqId.value = uuid(); showDraft.value = false; } }); };

const aform = useForm({ kind: 'debito', amount: '', causale: '', reference: '' });
const addingAdj = ref(false);
const saveAdj = () => aform.post(`${url.value}/rettifiche`, { ...opts, onSuccess: () => { aform.reset(); addingAdj.value = false; } });
const delAdj = (id: number) => router.delete(`${base.value}/liquidazioni/rettifiche/${id}`, opts);

const active = computed(() => props.f24.find((x) => x.status !== 'scartato') ?? null);
const gform = useForm({ compensations: props.compensable.map((c) => ({ credit_id: c.credit_id, amount: '' })), request_id: uuid() });
const ge = (k: string) => (gform.errors as Record<string, string>)[k];
const generate = () => gform.post(`${url.value}/f24`, { ...opts, onFinish: () => (gform.request_id = uuid()) });
const sform = useForm({ date: props.today, protocol: '', reason: '' });
const statusDialog = ref<{ id: number; action: 'presentato' | 'scartato' | 'addebito' } | null>(null);
const doStatus = () => sform.post(`${base.value}/f24/${statusDialog.value!.id}/${statusDialog.value!.action}`, { ...opts, onSuccess: () => { statusDialog.value = null; sform.reset(); } });
</script>

<template>
    <Head :title="`Liquidazione ${label}`" />
    <AppLayout crumb="Liquidazioni IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/liquidazioni?anno=${year}`">Liquidazioni {{ year }}</Link></p>
                <h1>Liquidazione IVA · {{ label }}</h1>
                <p v-if="d.ok">{{ d.profile_label }} · regole {{ d.rule_version }}</p>
            </div>
            <div class="actions">
                <Link class="btn" :href="`${base}/liquidazioni/${prev[0]}/${prev[1]}`" aria-label="Periodo precedente">‹</Link>
                <Link class="btn" :href="`${base}/liquidazioni/${next[0]}/${next[1]}`" aria-label="Periodo successivo">›</Link>
                <a v-if="d.ok" class="btn" :href="`${url}/pdf`"><Icon name="download" />PDF</a>
                <a v-if="lipe" class="btn" :href="`${base}/liquidazioni/lipe/${lipe.year}/${lipe.quarter}/pdf`"><Icon name="download" />LIPE {{ lipe.quarter }}° trim.</a>
            </div>
        </div>

        <div v-for="i in draft.issues" :key="i.field" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Da completare: {{ i.field === 'quarterly_type' ? 'tipo di trimestrale' : i.field === 'periodicity' ? 'periodicità' : i.field === 'rules' ? 'regole dell’anno' : 'regime IVA' }}</strong><p>{{ i.message }}</p>
            <Link v-if="i.field !== 'rules'" :href="`${base}/profilo-iva?anno=${year}`">Apri Regime IVA {{ year }}</Link></div></div>

        <div v-if="confirmed" class="notice" :class="confirmed.status === 'riesaminare' ? 'warn' : 'good'" role="status">
            <Icon :name="confirmed.status === 'riesaminare' ? 'alert' : 'check'" style="width: 20px; flex: none" />
            <div><strong>{{ confirmed.status === 'riesaminare' ? 'Liquidazione da riesaminare' : 'Confermata' }} · versione {{ confirmed.version }}</strong>
                <p class="small">Confermata da {{ confirmed.confirmed_by }} il {{ dateTime(confirmed.confirmed_at) }}<template v-if="confirmed.review_reason"> · {{ confirmed.review_reason }}</template></p>
                <div class="actions"><button class="btn link" type="button" @click="showDraft = !showDraft">{{ showDraft ? 'Mostra versione confermata' : 'Mostra ricalcolo attuale' }}</button></div></div>
        </div>

        <section v-if="compare" class="panel" style="margin-bottom: 24px">
            <div class="panel-head"><div><h2>Confronto con la nuova versione</h2><p>Gli originali restano agli atti. Differenza credito {{ money(compare.credit_diff) }}<template v-if="compare.due_diff"> · differenza da versare {{ money(compare.due_diff) }}</template>.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Voce</th><th class="num">Confermata</th><th class="num">Ricalcolo</th><th class="num">Differenza</th></tr></thead>
                <tbody><tr v-for="r in compare.rows" :key="r.key"><td>{{ r.label }}</td><td class="num tabular">{{ money(r.old) }}</td><td class="num tabular">{{ r.new ? money(r.new) : '—' }}</td><td class="num tabular" :class="{ 'text-warn': r.diff && r.diff !== '0.00' }">{{ r.diff ? money(r.diff) : '—' }}</td></tr></tbody></table></div>
            <div v-if="compare.effects.length" class="panel-body"><h3 class="small-head">Conseguenze da verificare</h3>
                <ul class="plain small"><li v-for="(e, i) in compare.effects" :key="i">{{ e.text }}<template v-if="e.link"> · <Link :href="`${base}/liquidazioni/${e.link.year}/${e.link.period}`">apri</Link></template><template v-if="e.claim_id || e.f24_id"> · <Link :href="`${base}/crediti-iva`">registro crediti</Link></template></li></ul></div>
            <div class="panel-body actions"><button class="btn primary" type="button" :disabled="confirming || !compare.new_ok || compare.new_checks.length > 0" @click="confirm"><Icon name="check" />Conferma nuova versione</button>
                <span v-if="page.props.errors.settlement" class="error">{{ page.props.errors.settlement }}</span></div>
        </section>

        <div v-if="d.ok" class="split">
            <div class="stack">
                <div v-for="(c, i) in d.checks" :key="i" class="notice warn" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Da verificare</strong><p>{{ c.message }}</p>
                    <ul v-if="c.documents.length" class="plain small"><li v-for="x in c.documents" :key="x.registration_id"><Link :href="`${base}/fatture/${x.invoice_id}`">{{ x.doc_type }} {{ x.number }}</Link> · prot. {{ x.protocol }} · {{ x.counterparty }}</li></ul></div></div>

                <section class="panel" aria-labelledby="pr-title">
                    <div class="panel-head"><div><h2 id="pr-title">Prospetto {{ showDraft || !confirmed ? '(bozza, calcolato ora)' : `(versione ${confirmed.version})` }}</h2></div>
                        <span class="badge" :class="d.result === 'debito' ? 'warn' : d.result === 'credito' ? 'info' : 'good'">{{ RESULT[d.result ?? 'zero'] }}</span></div>
                    <div class="table-wrap">
                        <table class="register">
                            <tbody>
                                <template v-for="l in lines" :key="l.k">
                                    <tr :class="{ 'sum-row': l.bold }">
                                        <td :style="l.sub ? 'padding-left: 28px' : ''">
                                            <button v-if="l.detail && ((l.detail === 'credits' && d.credits?.length) || (l.detail === 'adj' && d.adjustments?.length) || detailDocs(l.detail).length || ['debit', 'deductible'].includes(l.detail))" class="btn link" type="button" :aria-expanded="open === l.k" @click="toggle(l.k)">{{ l.label }}</button>
                                            <template v-else>{{ l.label }}</template></td>
                                        <td class="num tabular">{{ money(l.value) }}</td>
                                    </tr>
                                    <tr v-if="open === l.k" class="sub-row"><td colspan="2">
                                        <ul v-if="l.detail === 'credits'" class="plain small"><li v-for="c in d.credits" :key="c.movement_id">{{ c.origin }} · {{ money(c.amount) }} · {{ c.type === 'annuale' ? 'credito annuale' : 'periodo precedente' }} · {{ c.status }} · residuo del credito {{ money(c.credit_residual) }}</li></ul>
                                        <ul v-else-if="l.detail === 'adj'" class="plain small"><li v-for="a in d.adjustments" :key="a.id">{{ a.kind }} · {{ money(a.amount) }} · {{ a.causale }}<template v-if="a.reference"> · rif. {{ a.reference }}</template> · {{ a.author }} ({{ date(a.date) }})</li></ul>
                                        <ul v-else class="plain small">
                                            <li v-for="x in (l.detail === 'debit' ? [...detailDocs('vendite'), ...detailDocs('integrazioni')] : l.detail === 'deductible' ? detailDocs('acquisti') : detailDocs(l.detail!))" :key="`${l.k}-${x.registration_id}`">
                                                <Link :href="`${base}/fatture/${x.invoice_id}`">{{ x.doc_type }} {{ x.number }}</Link> · prot. {{ x.protocol }}<template v-if="x.status === 'storno'"> (storno)</template> · {{ x.counterparty }} · {{ money(x.amount) }}<template v-if="x.tax"> (IVA {{ money(x.tax) }})</template></li>
                                        </ul>
                                    </td></tr>
                                </template>
                                <tr class="sum-row"><td>Risultato · {{ RESULT[d.result ?? 'zero'] }}</td><td class="num tabular">{{ money(d.result === 'credito' ? f.credit : f.due) }}</td></tr>
                                <tr v-if="f.deferred_out && f.deferred_out !== '0.00'"><td>Debito rinviato al periodo successivo</td><td class="num tabular">{{ money(f.deferred_out) }}</td></tr>
                                <tr v-if="f.split && f.split !== '0.00'"><td class="small muted">IVA in split payment (versata dal cessionario, esclusa)</td><td class="num tabular small muted">{{ money(f.split) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel-body small">
                        <p v-if="f.due && f.due !== '0.00'">Versamento: codice tributo <strong>{{ d.code?.code }}</strong><template v-if="d.code?.rateazione"> (rateazione {{ d.code.rateazione }})</template>, anno {{ year }}, scadenza <strong>{{ date(d.due_date) }}</strong>.</p>
                        <p v-for="(n, i) in d.notes" :key="i" class="muted">{{ n }}</p>
                        <details v-if="detailDocs('rettifiche').length"><summary>Note di credito e storni del periodo ({{ detailDocs('rettifiche').length }}) – già compresi nelle voci</summary>
                            <ul class="plain"><li v-for="x in detailDocs('rettifiche')" :key="x.registration_id">{{ x.type }} · <Link :href="`${base}/fatture/${x.invoice_id}`">{{ x.number }}</Link> · {{ x.counterparty }} · {{ money(x.amount) }}</li></ul></details>
                    </div>
                    <div v-if="!confirmed" class="panel-body actions">
                        <button class="btn primary" type="button" :disabled="confirming || (d.checks?.length ?? 0) > 0" @click="confirm"><Icon name="check" />{{ confirming ? 'Conferma…' : 'Conferma liquidazione' }}</button>
                        <span v-if="page.props.errors.settlement" class="error">{{ page.props.errors.settlement }}</span>
                    </div>
                </section>
            </div>

            <div class="stack">
                <section v-if="confirmed && confirmed.status !== 'riesaminare'" class="panel" aria-labelledby="f24-title">
                    <div class="panel-head"><h2 id="f24-title">{{ confirmed.result === 'credito' ? 'Credito IVA' : 'Versamento' }}</h2></div>
                    <div class="panel-body">
                        <template v-if="confirmed.result === 'credito'">
                            <p>Credito di <strong>{{ money(confirmed.credit) }}</strong>. Proposta: riporto alla liquidazione successiva.</p>
                            <Link class="btn primary" :href="`${url}/credito`">Destinazione del credito IVA</Link>
                        </template>
                        <template v-else-if="confirmed.amount_due === '0.00'">
                            <p>{{ confirmed.result === 'debito' ? `Debito di ${money(confirmed.deferred)} rinviato al periodo successivo: nessuna delega ora.` : 'Liquidazione a zero senza movimenti da esporre: periodo chiuso senza F24.' }}</p>
                        </template>
                        <template v-else-if="active">
                            <p><span class="badge" :class="active.status === 'bozza' ? 'info' : 'good'">{{ active.status_label }}</span> Delega n. {{ active.id }} · scadenza {{ date(active.due_date) }} · saldo <strong>{{ money(active.balance) }}</strong></p>
                            <table class="small"><thead><tr><th>Codice</th><th>Anno</th><th class="num">Debito</th><th class="num">Credito</th></tr></thead>
                                <tbody><tr v-for="(l, i) in active.lines" :key="i"><td>{{ l.code }}<template v-if="l.rateazione"> · {{ l.rateazione }}</template></td><td>{{ l.year }}</td><td class="num tabular">{{ l.debit !== '0.00' ? money(l.debit) : '' }}</td><td class="num tabular">{{ l.credit !== '0.00' ? money(l.credit) : '' }}</td></tr></tbody></table>
                            <p v-if="active.status === 'bozza'" class="small muted">La bozza non è trasmessa: la scadenza resta aperta finché non registri la presentazione.</p>
                            <p v-if="active.presented_at" class="small">Presentata il {{ date(active.presented_at) }}<template v-if="active.protocol"> · protocollo {{ active.protocol }}</template><template v-if="active.debit_confirmed_at"> · addebito confermato il {{ date(active.debit_confirmed_at) }}</template></p>
                            <div class="actions">
                                <a class="btn" :href="`${base}/f24/${active.id}/pdf`"><Icon name="download" />PDF bozza F24</a>
                                <button v-if="active.status === 'bozza'" class="btn" type="button" @click="statusDialog = { id: active.id, action: 'presentato' }">Presentato</button>
                                <button v-if="active.status === 'presentato' && active.balance !== '0.00'" class="btn" type="button" @click="statusDialog = { id: active.id, action: 'addebito' }">Addebito confermato</button>
                                <button v-if="['bozza', 'presentato'].includes(active.status)" class="btn link" type="button" @click="statusDialog = { id: active.id, action: 'scartato' }">Scartato</button>
                            </div>
                        </template>
                        <form v-else @submit.prevent="generate">
                            <p>Da versare <strong>{{ money(confirmed.amount_due) }}</strong> entro il {{ date(confirmed.due_date) }}.</p>
                            <template v-if="compensable.length">
                                <p class="small muted">Crediti utilizzabili in compensazione (facoltativo):</p>
                                <div v-for="(c, i) in compensable" :key="c.credit_id" class="field">
                                    <label :for="`cp-${c.credit_id}`">{{ c.description }} · {{ c.code }} anno {{ c.year }} · disponibile {{ money(c.available) }}<template v-if="c.reserved !== '0.00'"> (prenotati {{ money(c.reserved) }})</template></label>
                                    <input :id="`cp-${c.credit_id}`" v-model="gform.compensations[i].amount" class="input tabular" inputmode="decimal" placeholder="0,00">
                                    <p v-for="r in c.reasons" :key="r" class="hint text-warn">{{ r }}</p>
                                    <p v-if="ge(`compensations.${i}`)" class="error">{{ ge(`compensations.${i}`) }}</p>
                                </div>
                            </template>
                            <p v-if="ge('compensations') || ge('f24')" class="error">{{ ge('compensations') || ge('f24') }}</p>
                            <button class="btn primary" type="submit" :disabled="gform.processing"><Icon name="check" />Genera F24</button>
                            <p class="small muted">Prepara la bozza senza trasmetterla né disporre addebiti.</p>
                        </form>
                        <details v-if="f24.filter((x) => x.status === 'scartato').length" class="small"><summary>Deleghe scartate</summary><ul class="plain"><li v-for="x in f24.filter((y) => y.status === 'scartato')" :key="x.id">n. {{ x.id }} · {{ x.outcome }} · <a :href="`${base}/f24/${x.id}/pdf`">PDF</a></li></ul></details>
                    </div>
                </section>

                <section class="panel" aria-labelledby="adj-title">
                    <div class="panel-head"><h2 id="adj-title">Rettifiche manuali</h2><button class="btn" type="button" @click="addingAdj = !addingAdj"><Icon name="plus" />Rettifica</button></div>
                    <ul v-if="draft.adjustments?.length" class="panel-body plain small"><li v-for="a in draft.adjustments" :key="a.id">{{ a.kind === 'acconto' ? 'Acconto IVA' : a.kind === 'debito' ? 'A debito' : 'Alla detrazione' }} · {{ money(a.amount) }} · {{ a.causale }}<template v-if="a.reference"> · rif. {{ a.reference }}</template> · {{ a.author }} <button class="btn link small" type="button" @click="delAdj(a.id)">Elimina</button></li></ul>
                    <p v-else class="panel-body small muted">Nessuna rettifica. Ogni intervento conserva autore, causale e riferimento.</p>
                    <form v-if="addingAdj" class="panel-body" novalidate @submit.prevent="saveAdj">
                        <div class="field"><label for="adj-kind">Tipo</label><select id="adj-kind" v-model="aform.kind" class="input"><option value="debito">Rettifica IVA a debito</option><option value="detrazione">Rettifica IVA detraibile</option><option v-if="canAcconto" value="acconto">Acconto IVA versato</option></select><p v-if="aform.errors.kind" class="error">{{ aform.errors.kind }}</p></div>
                        <div class="field"><label for="adj-amount">Importo (negativo per ridurre)</label><input id="adj-amount" v-model="aform.amount" class="input tabular" inputmode="decimal"><p v-if="aform.errors.amount" class="error">{{ aform.errors.amount }}</p></div>
                        <div class="field"><label for="adj-causale">Causale</label><input id="adj-causale" v-model="aform.causale" class="input"><p v-if="aform.errors.causale" class="error">{{ aform.errors.causale }}</p></div>
                        <div class="field"><label for="adj-ref">Riferimento (facoltativo)</label><input id="adj-ref" v-model="aform.reference" class="input" placeholder="es. F24 del 27/12, documento n."></div>
                        <div class="actions"><button class="btn primary" type="submit" :disabled="aform.processing">Salva rettifica</button></div>
                    </form>
                </section>

                <section v-if="versions.length" class="panel"><div class="panel-head"><h2>Versioni</h2></div>
                    <ul class="panel-body plain small"><li v-for="v in versions" :key="v.id">v{{ v.version }} · {{ v.status === 'sostituita' ? 'Sostituita' : v.status === 'riesaminare' ? 'Da riesaminare' : 'Confermata' }} · {{ dateTime(v.confirmed_at) }} · {{ v.confirmed_by }} · {{ RESULT[v.result] }} {{ money(v.result === 'credito' ? v.credit : v.amount_due) }}</li></ul></section>
            </div>
        </div>

        <div v-if="statusDialog" class="dialog-wrap" @click.self="statusDialog = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="st-title" @submit.prevent="doStatus">
                <h2 id="st-title">{{ statusDialog.action === 'presentato' ? 'Delega presentata' : statusDialog.action === 'addebito' ? 'Addebito confermato' : 'Delega scartata' }}</h2>
                <div v-if="statusDialog.action !== 'scartato'" class="field"><label for="st-date">Data</label><input id="st-date" v-model="sform.date" type="date" class="input" :max="today"><p v-if="(sform.errors as Record<string, string>).presented_at || (sform.errors as Record<string, string>).date" class="error">{{ (sform.errors as Record<string, string>).presented_at || (sform.errors as Record<string, string>).date }}</p></div>
                <div v-if="statusDialog.action === 'presentato'" class="field"><label for="st-prot">Protocollo (facoltativo)</label><input id="st-prot" v-model="sform.protocol" class="input"></div>
                <div v-if="statusDialog.action === 'scartato'" class="field"><label for="st-reason">Motivo dello scarto</label><input id="st-reason" v-model="sform.reason" class="input"><p class="hint">Le quote di credito della delega vengono sbloccate con questa motivazione.</p><p v-if="sform.errors.reason" class="error">{{ sform.errors.reason }}</p></div>
                <p v-if="(sform.errors as Record<string, string>).status" class="error">{{ (sform.errors as Record<string, string>).status }}</p>
                <div class="actions"><button class="btn primary" type="submit" :disabled="sform.processing">Conferma</button><button class="btn" type="button" @click="statusDialog = null">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>
