<script setup lang="ts">
// Fattura → Scadenze → Pagamenti → Anno fiscale: rate, storico dei pagamenti e attribuzione fiscale per anno.
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import PaymentDialog from './PaymentDialog.vue';
import { date, money } from '@/lib/format';
import { dueClass, paidClass, toCents, type Payment, type Rate } from './types';

type FiscalYear = { year: number; amount: string; fiscal: string; check: boolean; entries: { date: string; account: string | null; basis: string; amount: string; fiscal_amount: string; reason: string }[] };
export type Schedule = { sale: boolean; direction: string | null; credit_note: boolean; party: string | null; ritenuta: string; expected: string; condition: string | null;
    state: { amount: string; settled: string; residual: string; paid: string; paid_label: string; due: string | null; due_label: string | null; check: boolean; amount_check: boolean };
    rates: Rate[]; history: Payment[]; fiscal: FiscalYear[]; credits: { installment_id: number; number: string; doc_date: string; residual: string }[];
    mode: [string, string] | null; modes: Record<string, string>; today: string; professionista: boolean };
const props = defineProps<{ base: string; invoiceId: number; s: Schedule }>();
const word = computed(() => (props.s.sale ? 'incasso' : 'pagamento'));
const BASIS: Record<string, string> = { cassa: 'Per cassa', registrazione: 'Alla registrazione', da_verificare: 'Da verificare' };
const opts = { preserveScroll: true };

const pay = ref(false);
const open = computed(() => props.s.rates.filter((r) => toCents(r.residual) > 0).map((r) => r.id));

// Modifica delle scadenze (impostare la scadenza mancante, suddividere le rate); gli importi già pagati restano protetti.
const editing = ref(false);
const eform = useForm({ rows: [] as { id: number | null; due_date: string; amount: string; payment_mode: string }[] });
function startEdit() { eform.clearErrors(); eform.rows = props.s.rates.map((r) => ({ id: r.id, due_date: r.due_date ?? '', amount: r.amount.replace('.', ','), payment_mode: r.payment_mode ?? '' })); editing.value = true; }
const addRow = () => eform.rows.push({ id: null, due_date: '', amount: '', payment_mode: eform.rows[0]?.payment_mode ?? '' });
const sumRows = computed(() => eform.rows.reduce((t, r) => t + (Number.isNaN(toCents(r.amount)) ? 0 : toCents(r.amount)), 0));
const saveEdit = () => eform.put(`${props.base}/fatture/${props.invoiceId}/scadenze`, { ...opts, onSuccess: () => (editing.value = false) });
const err = (k: string) => (eform.errors as Record<string, string>)[k];
const confirmStatus = () => router.post(`${props.base}/fatture/${props.invoiceId}/stato-pagamento`, {}, opts);

const comp = ref(false);
const cform = useForm({ installment_id: 0, credit_installment_id: 0, amount: '', date: props.s.today });
function startComp() {
    const r = props.s.rates.find((x) => toCents(x.residual) > 0); const c = props.s.credits[0];
    cform.clearErrors(); cform.installment_id = r?.id ?? 0; cform.credit_installment_id = c?.installment_id ?? 0;
    cform.amount = r && c ? (Math.min(toCents(r.residual), toCents(c.residual)) / 100).toFixed(2).replace('.', ',') : ''; comp.value = true;
}
const doComp = () => cform.post(`${props.base}/scadenziario/compensa`, { ...opts, onSuccess: () => (comp.value = false) });
const openYear = ref<number | null>(null);
</script>

<template>
    <section id="scadenze" class="panel" aria-labelledby="sc-title">
        <div class="panel-head">
            <div>
                <h2 id="sc-title">Scadenze e {{ s.sale ? 'incassi' : 'pagamenti' }}</h2>
                <p><span class="badge" :class="paidClass(s.state.paid)">{{ s.state.paid === 'da_verificare' ? 'Stato pagamento da verificare' : s.state.paid_label }}</span>
                    <template v-if="s.state.due"> · <span class="badge" :class="dueClass(s.state.due)">{{ s.state.due_label }}</span></template>
                    · Residuo <strong class="tabular">{{ money(s.state.residual) }}</strong></p>
                <p class="small muted">Da {{ s.sale ? 'incassare' : 'pagare' }} {{ money(s.expected) }}<template v-if="s.ritenuta !== '0.00'"> (totale documento meno ritenuta d’acconto {{ money(s.ritenuta) }})</template>. Scadenza e modalità della fattura sono accordi, non prove di {{ word }}.</p>
            </div>
            <div class="actions">
                <button v-if="!s.credit_note && open.length && s.direction" class="btn primary" type="button" @click="pay = true"><Icon name="plus" />Registra {{ word }}</button>
                <button v-if="s.credits.length && open.length" class="btn" type="button" @click="startComp">Compensa nota di credito</button>
            </div>
        </div>
        <div v-if="s.state.check || s.state.amount_check" class="notice warn panel-body" role="status">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>{{ s.state.check ? 'Stato pagamento da verificare' : 'Importo da verificare' }}</strong>
                <p class="small">{{ s.state.check ? 'Fattura presente prima dello scadenziario: registra eventuali incassi o pagamenti già avvenuti, poi conferma.' : 'La somma delle rate indicate in fattura non corrisponde all’importo da ' + (s.sale ? 'incassare' : 'pagare') + ': controlla le scadenze.' }}</p>
                <button class="btn" type="button" @click="confirmStatus">Conferma stato pagamento</button></div>
        </div>
        <div v-if="!editing" class="table-wrap">
            <table>
                <thead><tr><th>Rata</th><th>Scadenza</th><th class="num">Importo</th><th class="num">{{ s.sale ? 'Incassato' : 'Pagato' }}</th><th class="num">Residuo e stato</th></tr></thead>
                <tbody>
                    <tr v-for="r in s.rates" :key="r.id">
                        <td class="tabular">{{ r.seq }}/{{ r.rates }}<template v-if="r.edited"> <span class="small muted">modificata</span></template></td>
                        <td class="tabular">{{ r.due_date ? date(r.due_date) : 'Da definire' }}<template v-if="r.payment_mode"><br><span class="small muted">{{ s.modes[r.payment_mode] ?? r.payment_mode }}</span></template></td>
                        <td class="num tabular">{{ money(r.amount) }}</td><td class="num tabular">{{ money(r.settled) }}</td><td class="num tabular"><strong>{{ money(r.residual) }}</strong><br><span class="badge" :class="paidClass(r.paid)">{{ r.paid_label }}</span><template v-if="r.due !== 'saldata'"> <span class="badge" :class="dueClass(r.due)">{{ r.due_label }}</span></template></td>
                    </tr>
                </tbody>
            </table>
            <div class="panel-body"><button class="btn link" type="button" @click="startEdit">Modifica scadenze</button></div>
        </div>
        <form v-else class="panel-body" novalidate @submit.prevent="saveEdit">
            <div v-for="(r, i) in eform.rows" :key="i" class="acc-filters" style="border: 0; padding: 4px 0">
                <div class="field"><label :for="`ed-due-${i}`">Scadenza rata {{ i + 1 }}</label><input :id="`ed-due-${i}`" v-model="r.due_date" type="date" class="input"></div>
                <div class="field"><label :for="`ed-amt-${i}`">Importo</label><input :id="`ed-amt-${i}`" v-model="r.amount" class="input tabular" inputmode="decimal"><p v-if="err(`installments.${i}.amount`) || err(`rows.${i}.amount`)" class="error">{{ err(`installments.${i}.amount`) || err(`rows.${i}.amount`) }}</p></div>
                <div class="field"><label :for="`ed-mode-${i}`">Modalità</label><select :id="`ed-mode-${i}`" v-model="r.payment_mode" class="input"><option value="">—</option><option v-for="(m, k) in s.modes" :key="k" :value="k">{{ m }}</option></select></div>
                <button v-if="eform.rows.length > 1" class="btn link" type="button" style="align-self: end" @click="eform.rows.splice(i, 1)">Rimuovi</button>
            </div>
            <p class="small">Totale rate {{ money((sumRows / 100).toFixed(2)) }} · da {{ s.sale ? 'incassare' : 'pagare' }} {{ money(s.expected) }}<template v-if="sumRows !== toCents(s.expected)"> · <span class="text-warn">differenza: verrà segnalato “Importo da verificare”</span></template></p>
            <p v-if="err('installments')" class="error">{{ err('installments') }}</p>
            <div class="actions"><button class="btn" type="button" @click="addRow"><Icon name="plus" />Rata</button><button class="btn primary" type="submit" :disabled="eform.processing">Salva scadenze</button><button class="btn" type="button" @click="editing = false">Annulla</button></div>
        </form>

        <div v-if="s.history.length" class="panel-body">
            <h3 class="small-head">Storico {{ s.sale ? 'incassi' : 'pagamenti' }}</h3>
            <ul class="plain small">
                <li v-for="p in s.history" :key="p.id" :class="{ muted: p.status !== 'valida' || p.movement_status === 'stornata' }">
                    {{ date(p.date) }} · rata {{ p.seq }} · {{ money(p.amount) }} ·
                    <template v-if="p.type === 'nota_credito'">compensazione con <Link :href="`${base}/fatture/${p.other_invoice_id}#scadenze`">{{ s.credit_note ? 'fattura' : 'nota di credito' }} {{ p.other_number }}</Link></template>
                    <template v-else><Link :href="`${base}/scadenziario/incassi-e-pagamenti#movimento-${p.movement_id}`">{{ word }} n. {{ p.movement_id }}</Link> (movimento {{ money(p.movement_amount) }})<template v-if="p.method"> · {{ p.method }}</template><template v-if="p.payer_date"> · pagato dal sostituto il {{ date(p.payer_date) }}</template></template>
                    <template v-if="p.status !== 'valida' || p.movement_status === 'stornata'"> · <strong>stornato</strong>: {{ p.reversal_reason }}</template>
                </li>
            </ul>
        </div>

        <div class="panel-body">
            <h3 class="small-head">Imputazione fiscale per anno</h3>
            <p v-if="s.mode" class="small muted">{{ s.mode[1] }}</p>
            <table v-if="s.fiscal.length">
                <thead><tr><th>Anno</th><th class="num">{{ s.sale ? 'Ricavo' : 'Costo' }} imputato</th><th class="num">Rilevante</th><th><span class="sr-only">Dettaglio</span></th></tr></thead>
                <tbody>
                    <template v-for="y in s.fiscal" :key="y.year">
                        <tr><td><Link :href="`${base}/scadenziario/riepilogo-fiscale?anno=${y.year}`">{{ y.year }}</Link><template v-if="y.check"> <span class="badge warn">Da verificare</span></template></td>
                            <td class="num tabular">{{ money(y.amount) }}</td><td class="num tabular">{{ money(y.fiscal) }}</td>
                            <td><button class="btn link" type="button" :aria-expanded="openYear === y.year" @click="openYear = openYear === y.year ? null : y.year">Motivi</button></td></tr>
                        <tr v-if="openYear === y.year" class="sub-row"><td colspan="4"><ul class="plain small"><li v-for="(e, i) in y.entries" :key="i">{{ date(e.date) }} · {{ BASIS[e.basis] }} · {{ money(e.amount) }}<template v-if="e.account"> · {{ e.account }}</template><br><span class="muted">{{ e.reason }}</span></li></ul></td></tr>
                    </template>
                </tbody>
            </table>
            <p v-else class="small muted">Nessun importo imputato: {{ s.mode?.[0] === 'registrazione' ? 'la quota si imputa alla registrazione' : 'la quota si imputa con ogni ' + word + ' registrato' }}.</p>
        </div>

        <PaymentDialog v-if="pay" :base="base" :sale="s.sale" :candidates="s.rates" :selected="open" :modes="s.modes" :today="s.today" :professionista="s.professionista" @close="pay = false" />

        <div v-if="comp" class="dialog-wrap" @click.self="comp = false">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="cp-title" @submit.prevent="doComp">
                <h2 id="cp-title">Compensa nota di credito</h2>
                <p class="small muted">Riduce il residuo senza registrare un movimento bancario.</p>
                <div class="field"><label for="cp-rate">Rata</label><select id="cp-rate" v-model.number="cform.installment_id" class="input"><option v-for="r in s.rates.filter((x) => toCents(x.residual) > 0)" :key="r.id" :value="r.id">Rata {{ r.seq }} · residuo {{ money(r.residual) }}</option></select></div>
                <div class="field"><label for="cp-nc">Nota di credito</label><select id="cp-nc" v-model.number="cform.credit_installment_id" class="input"><option v-for="c in s.credits" :key="c.installment_id" :value="c.installment_id">{{ c.number }} del {{ date(c.doc_date) }} · residuo {{ money(c.residual) }}</option></select><p v-if="(cform.errors as Record<string, string>).credit" class="error">{{ (cform.errors as Record<string, string>).credit }}</p></div>
                <div class="field"><label for="cp-amt">Importo</label><input id="cp-amt" v-model="cform.amount" class="input tabular" inputmode="decimal"><p v-if="cform.errors.amount" class="error">{{ cform.errors.amount }}</p></div>
                <div class="field"><label for="cp-date">Data</label><input id="cp-date" v-model="cform.date" type="date" class="input" :max="s.today"><p v-if="cform.errors.date" class="error">{{ cform.errors.date }}</p></div>
                <div class="actions"><button class="btn primary" type="submit" :disabled="cform.processing">Compensa</button><button class="btn" type="button" @click="comp = false">Annulla</button></div>
            </form>
        </div>
    </section>
</template>
