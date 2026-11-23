<script setup lang="ts">
// Registra incasso / pagamento: una movimentazione, abbinata a una o più rate dello stesso cliente o fornitore.
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import { fromCents, toCents, uuid, type Rate } from './types';

const props = defineProps<{ base: string; sale: boolean; candidates: Rate[]; selected: number[]; modes: Record<string, string>; today: string; professionista: boolean }>();
const emit = defineEmits<{ close: [] }>();
const label = computed(() => (props.sale ? 'incasso' : 'pagamento'));

// Rata proposta con il residuo aperto; importi parziali ammessi.
const rows = ref(props.candidates.filter((r) => !r.credit_note && toCents(r.residual) > 0).map((r) => ({ rate: r, on: props.selected.includes(r.id), amount: props.selected.includes(r.id) ? fromCents(toCents(r.residual)) : '' })));
const firstMode = props.candidates.find((r) => props.selected.includes(r.id))?.payment_mode;
const form = useForm({ kind: props.sale ? 'incasso' : 'pagamento', movement_date: props.today, amount: '', method: firstMode && props.modes[firstMode] ? props.modes[firstMode] : 'Bonifico', bank_ref: '', notes: '',
    payer_date: '', payer_year_rule: true, request_id: uuid(), allocations: [] as { installment_id: number; amount: string }[] });
const allocated = computed(() => rows.value.filter((r) => r.on).reduce((s, r) => s + (Number.isNaN(toCents(r.amount)) ? 0 : toCents(r.amount)), 0));
const amountC = computed(() => toCents(form.amount));
const rest = computed(() => (Number.isNaN(amountC.value) ? 0 : amountC.value - allocated.value));
onMounted(() => { form.amount = allocated.value ? fromCents(allocated.value) : ''; document.getElementById('pd-date')?.focus(); });
function toggle(r: (typeof rows.value)[number]) { r.amount = r.on ? fromCents(toCents(r.rate.residual)) : ''; if (!form.amount || toCents(form.amount) < allocated.value) form.amount = fromCents(allocated.value); }
const e = (k: string) => (form.errors as Record<string, string>)[k];
const submit = () => {
    if (form.processing) return; // doppio clic: un solo invio, request_id protegge inoltre lato server
    form.allocations = rows.value.filter((r) => r.on).map((r) => ({ installment_id: r.rate.id, amount: r.amount }));
    form.post(`${props.base}/scadenziario/movimenti`, { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <div class="dialog-wrap" @click.self="emit('close')">
        <form class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="pd-title" novalidate @submit.prevent="submit" @keydown.esc="emit('close')">
            <h2 id="pd-title">Registra {{ label }}<template v-if="candidates[0]"> · {{ candidates[0].party }}</template></h2>
            <p class="small muted">La scadenza e la modalità indicate in fattura sono solo accordi: la fattura risulta {{ sale ? 'incassata' : 'pagata' }} solo con un {{ label }} registrato qui. Un movimento può saldare più fatture dello stesso {{ sale ? 'cliente' : 'fornitore' }}.</p>
            <div class="acc-grid">
                <div class="field"><label for="pd-date">Data {{ label }}</label><input id="pd-date" v-model="form.movement_date" type="date" class="input" :max="today" :aria-invalid="e('movement_date') ? true : undefined"><p v-if="e('movement_date')" class="error">{{ e('movement_date') }}</p></div>
                <div class="field"><label for="pd-amount">Importo del movimento</label><input id="pd-amount" v-model="form.amount" class="input tabular" inputmode="decimal" placeholder="0,00" :aria-invalid="e('amount') ? true : undefined"><p v-if="e('amount')" class="error">{{ e('amount') }}</p></div>
                <div class="field"><label for="pd-mode">Modalità</label><input id="pd-mode" v-model="form.method" class="input" list="pd-modes"><datalist id="pd-modes"><option v-for="(m, k) in modes" :key="k" :value="m" /></datalist></div>
                <div class="field"><label for="pd-ref">Riferimento banca (facoltativo)</label><input id="pd-ref" v-model="form.bank_ref" class="input" placeholder="es. CRO / TRN"></div>
                <div class="field span2"><label for="pd-notes">Nota (facoltativa)</label><input id="pd-notes" v-model="form.notes" class="input"></div>
                <template v-if="professionista && sale">
                    <div class="field"><label for="pd-payer">Data pagamento del sostituto d’imposta</label><input id="pd-payer" v-model="form.payer_date" type="date" class="input" :max="today"><p class="hint">Solo se diversa dalla data di accredito (es. pagato il 30/12, accreditato a gennaio).</p><p v-if="e('payer_date')" class="error">{{ e('payer_date') }}</p></div>
                    <div v-if="form.payer_date && form.payer_date.slice(0, 4) !== form.movement_date.slice(0, 4)" class="field span2"><label class="check"><input v-model="form.payer_year_rule" type="checkbox"> Imputare il compenso al {{ form.payer_date.slice(0, 4) }} (anno del pagamento del sostituto, come da certificazione)</label></div>
                </template>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><span class="sr-only">Abbina</span></th><th>Fattura</th><th>Rata</th><th>Scadenza</th><th class="num">Residuo</th><th class="num">Importo abbinato</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.rate.id">
                            <td><input v-model="r.on" type="checkbox" :aria-label="`Abbina fattura ${r.rate.number} rata ${r.rate.seq}`" @change="toggle(r)"></td>
                            <td><strong>{{ r.rate.number }}</strong> <span class="small muted">del {{ date(r.rate.doc_date) }}</span></td>
                            <td class="tabular">{{ r.rate.seq }}/{{ r.rate.rates }}</td>
                            <td class="tabular">{{ r.rate.due_date ? date(r.rate.due_date) : 'Da definire' }}</td>
                            <td class="num tabular">{{ money(r.rate.residual) }}</td>
                            <td class="num"><input v-model="r.amount" class="input tabular" style="max-width: 120px; text-align: right" inputmode="decimal" :disabled="!r.on" :aria-label="`Importo abbinato alla rata ${r.rate.seq} della fattura ${r.rate.number}`">
                                <p v-if="e(`allocations.${rows.filter((x) => x.on).indexOf(r)}.amount`) && r.on" class="error">{{ e(`allocations.${rows.filter((x) => x.on).indexOf(r)}.amount`) }}</p></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small" aria-live="polite">Abbinato {{ money(fromCents(allocated).replace(',', '.')) }}<template v-if="rest > 0"> · <strong>Importo da abbinare {{ money(fromCents(rest).replace(',', '.')) }}</strong> (resta disponibile per altre fatture)</template><template v-if="rest < 0"> · <span class="error">Gli importi abbinati superano l’importo del movimento.</span></template></p>
            <p v-if="e('allocations') || e('request_id')" class="error">{{ e('allocations') || e('request_id') }}</p>
            <div class="actions"><button class="btn primary" type="submit" :disabled="form.processing || rest < 0 || !allocated"><Icon name="check" />{{ form.processing ? 'Registrazione…' : `Registra ${label}` }}</button><button class="btn" type="button" @click="emit('close')">Annulla</button></div>
        </form>
    </div>
</template>
