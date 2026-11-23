<script setup lang="ts">
import { computed, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Anagrafica cespite: Identificazione, Acquisizione, Valori e metodo, Contabilità (conti con default della categoria), Agevolazioni. */
type Acc = { id: number; code: string; name: string; type: string; sezione: string | null };
type Cat = { id: number; code: string; name: string; kind: string; civil_rate: string | null; fiscal_rate: string | null; first_year_half: boolean; dm: { coefficient: string } | null } & Record<string, unknown>;
const props = defineProps<{ asset: (Record<string, unknown> & { id: number; locked: boolean }) | null; categories: Cat[]; accounts: Acc[]; methods: Record<string, string>; roles: Record<string, string>; invoices: { id: number; label: string }[]; settings: Record<string, unknown>;
    opening: { mode: string; last_year: number; fund_civil: string; fund_fiscal: string } | null; default_last_year: number }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const a: Record<string, any> = props.asset ?? {};
const s = (k: string) => (a[k] === null || a[k] === undefined ? '' : String(a[k]));
const roleKeys = Object.keys(props.roles);
const form = useForm({
    code: s('code'), description: s('description'), description_long: s('description_long'), asset_category_id: s('asset_category_id'), subcategory: s('subcategory'),
    location: s('location'), cost_center: s('cost_center'), serial_number: s('serial_number'), matricola: s('matricola'), supplier_name: s('supplier_name'), notes: s('notes'),
    purchase_date: s('purchase_date'), invoice_date: s('invoice_date'), invoice_number: s('invoice_number'), invoice_id: s('invoice_id'), in_service_date: s('in_service_date'), depreciation_start: s('depreciation_start'),
    original_cost: s('original_cost'), accessory_costs: s('accessory_costs'), nondeductible_vat: s('nondeductible_vat'), grants: s('grants'), grant_treatment: s('grant_treatment') || 'riduzione', residual_value: s('residual_value'), fiscal_cost: s('fiscal_cost'),
    method: s('method') || 'ordinario', first_year_half: a.first_year_half === null || a.first_year_half === undefined ? '' : a.first_year_half ? '1' : '0',
    extra_deduction_pct: s('extra_deduction_pct'), extra_deduction_note: s('extra_deduction_note'), fiscal_cost_cap: s('fiscal_cost_cap'), fiscal_deductible_pct: s('fiscal_deductible_pct'), low_value: !!a.low_value,
    opening_enabled: !!props.opening, opening_last_year: String(props.opening?.last_year ?? props.default_last_year),
    opening_fund_civil: props.opening ? String(props.opening.fund_civil).replace('.', ',') : '', opening_fund_fiscal: props.opening && props.opening.fund_fiscal !== props.opening.fund_civil ? String(props.opening.fund_fiscal).replace('.', ',') : '',
    version: a.version ?? 1, ...Object.fromEntries(roleKeys.map((r) => [`account_${r}_id`, s(`account_${r}_id`)])),
});
const err = (k: string) => (form.errors as Record<string, string>)[k];
const cat = computed(() => props.categories.find((c) => String(c.id) === form.asset_category_id));
const accFor = (r: string) => props.accounts.filter((x) => (['asset', 'fund'].includes(r) ? x.type === 'patrimoniale' : r === 'gain' ? x.type === 'ricavo' : x.type === 'costo'));
const catAccount = (r: string) => { const id = cat.value?.[`account_${r}_id`]; const x = props.accounts.find((y) => y.id === id); return x ? `${x.code} ${x.name}` : 'nessuno'; };
watch(() => form.in_service_date, (v) => { if (v && !form.depreciation_start) form.depreciation_start = v; });
const submit = () => (props.asset ? form.put(`${base.value}/${props.asset.id}`) : form.post(base.value));
const locked = computed(() => !!props.asset?.locked);
</script>

<template>
    <Head :title="asset ? 'Modifica cespite' : 'Nuovo cespite'" />
    <AppLayout crumb="Cespiti">
        <div class="page-head">
            <div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>{{ asset ? `Modifica ${asset.code}` : 'Nuovo cespite' }}</h1>
                <p>Distingui data di acquisto, entrata in funzione e inizio dell’ammortamento. I conti proposti sono quelli della categoria; puoi cambiarli per il singolo cespite.</p></div>
        </div>
        <div v-if="locked" class="notice info" role="status" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Cespite con ammortamenti o movimenti</strong>Valori, date e categoria si cambiano con un movimento (incremento, decremento, riclassificazione, rettifica): lo storico resta tracciato.</div></div>
        <div v-if="err('version')" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ err('version') }}</div></div>
        <form novalidate @submit.prevent="submit">
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><h2>Identificazione</h2></div>
                <div class="panel-body mi-grid">
                    <div class="field"><label for="f-code">Codice</label><input id="f-code" v-model="form.code" class="input tabular" maxlength="30" placeholder="automatico"><p v-if="err('code')" class="error">{{ err('code') }}</p></div>
                    <div class="field span2"><label for="f-desc">Descrizione breve</label><input id="f-desc" v-model="form.description" class="input" maxlength="200" :aria-invalid="err('description') ? true : undefined"><p v-if="err('description')" class="error">{{ err('description') }}</p></div>
                    <div class="field span3"><label for="f-long">Descrizione dettagliata</label><textarea id="f-long" v-model="form.description_long" class="input" rows="2"></textarea></div>
                    <div class="field span2"><label for="f-cat">Categoria</label><select id="f-cat" v-model="form.asset_category_id" class="input" :disabled="locked" :aria-invalid="err('asset_category_id') ? true : undefined"><option value="">Scegli…</option><option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.code }} · {{ c.name }} ({{ c.kind }}, {{ (c.civil_rate ?? c.fiscal_rate ?? '—').toString().replace('.', ',') }}%)</option></select><p v-if="err('asset_category_id')" class="error">{{ err('asset_category_id') }}</p></div>
                    <div class="field"><label for="f-sub">Sottocategoria</label><input id="f-sub" v-model="form.subcategory" class="input"></div>
                    <div class="field"><label for="f-loc">Ubicazione</label><input id="f-loc" v-model="form.location" class="input"></div>
                    <div class="field"><label for="f-cc">Centro di costo</label><input id="f-cc" v-model="form.cost_center" class="input"></div>
                    <div class="field"><label for="f-sup">Fornitore</label><input id="f-sup" v-model="form.supplier_name" class="input"></div>
                    <div class="field"><label for="f-mat">Matricola</label><input id="f-mat" v-model="form.matricola" class="input"></div>
                    <div class="field"><label for="f-ser">Numero di serie</label><input id="f-ser" v-model="form.serial_number" class="input"></div>
                    <div class="field span3"><label for="f-notes">Note</label><input id="f-notes" v-model="form.notes" class="input"></div>
                </div></section>
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><h2>Acquisizione</h2></div>
                <div class="panel-body mi-grid">
                    <div class="field"><label for="f-pd">Data acquisto</label><input id="f-pd" v-model="form.purchase_date" type="date" class="input" :disabled="locked"><p v-if="err('purchase_date')" class="error">{{ err('purchase_date') }}</p></div>
                    <div class="field"><label for="f-isd">Entrata in funzione</label><input id="f-isd" v-model="form.in_service_date" type="date" class="input" :disabled="locked"><p v-if="err('in_service_date')" class="error">{{ err('in_service_date') }}</p></div>
                    <div class="field"><label for="f-ds">Inizio ammortamento</label><input id="f-ds" v-model="form.depreciation_start" type="date" class="input" :disabled="locked"><p v-if="err('depreciation_start')" class="error">{{ err('depreciation_start') }}</p></div>
                    <div class="field"><label for="f-in">Numero fattura</label><input id="f-in" v-model="form.invoice_number" class="input"></div>
                    <div class="field"><label for="f-id">Data fattura</label><input id="f-id" v-model="form.invoice_date" type="date" class="input"></div>
                    <div class="field"><label for="f-inv">Fattura di acquisto in Semplicon</label><select id="f-inv" v-model="form.invoice_id" class="input"><option value="">—</option><option v-for="i in invoices" :key="i.id" :value="String(i.id)">{{ i.label }}</option></select></div>
                </div></section>
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Valori e metodo</h2><p>Importi in euro (es. 1250,00). Il costo fiscale vuoto è uguale al costo civilistico.</p></div></div>
                <div class="panel-body mi-grid">
                    <div class="field"><label for="f-oc">Valore originario</label><input id="f-oc" v-model="form.original_cost" class="input tabular" inputmode="decimal" :disabled="locked"><p v-if="err('original_cost')" class="error">{{ err('original_cost') }}</p></div>
                    <div class="field"><label for="f-ac">Costi accessori capitalizzati</label><input id="f-ac" v-model="form.accessory_costs" class="input tabular" inputmode="decimal" :disabled="locked"></div>
                    <div class="field"><label for="f-nv">IVA indetraibile capitalizzata</label><input id="f-nv" v-model="form.nondeductible_vat" class="input tabular" inputmode="decimal" :disabled="locked"></div>
                    <div class="field"><label for="f-gr">Contributi in conto impianti</label><input id="f-gr" v-model="form.grants" class="input tabular" inputmode="decimal" :disabled="locked"></div>
                    <div class="field"><label for="f-gt">Trattamento del contributo</label><select id="f-gt" v-model="form.grant_treatment" class="input" :disabled="locked"><option value="riduzione">Riduzione del costo</option><option value="risconto">Ricavo pluriennale (risconto)</option></select></div>
                    <div class="field"><label for="f-rv">Valore residuo stimato</label><input id="f-rv" v-model="form.residual_value" class="input tabular" inputmode="decimal" :disabled="locked"><p v-if="err('residual_value')" class="error">{{ err('residual_value') }}</p></div>
                    <div class="field"><label for="f-fc">Costo fiscalmente riconosciuto</label><input id="f-fc" v-model="form.fiscal_cost" class="input tabular" inputmode="decimal" :disabled="locked" placeholder="come il costo"></div>
                    <div class="field"><label for="f-m">Metodo</label><select id="f-m" v-model="form.method" class="input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
                    <div v-if="cat?.kind === 'materiale'" class="field"><label for="f-h">Primo esercizio fiscale a metà (art. 102 TUIR)</label><select id="f-h" v-model="form.first_year_half" class="input"><option value="">Come la categoria ({{ cat.first_year_half ? 'sì' : 'no' }})</option><option value="1">Sì</option><option value="0">No</option></select></div>
                    <p v-if="form.method === 'manuale'" class="small muted span3">Metodo manuale: le quote per esercizio si indicano nella scheda del cespite (Valori e aliquote › Modifica dal …).</p>
                    <p class="small muted span3">La quota ridotta si sceglie nel Calcolo ammortamenti dell’esercizio (per tutti i beni o per categoria).</p>
                </div></section>
            <section v-if="cat?.kind !== 'non_ammortizzabile'" class="panel" style="margin-bottom: 16px" aria-labelledby="f-op-h"><div class="panel-head"><div><h2 id="f-op-h">Progressivi (cespite già ammortizzato)</h2>
                <p>Per un bene acquistato prima dell’uso di Semplicon (es. nel 2000, inserito nel 2026): indica il fondo ammortamento al 31/12 dell’ultimo esercizio già ammortizzato. Semplicon calcola dall’esercizio successivo; nessuna scrittura in prima nota.</p></div></div>
                <div v-if="opening?.mode === 'storico'" class="panel-body small muted">Progressivi importati con le quote per esercizio (import “storico”): si consultano nella scheda del cespite.</div>
                <div v-else class="panel-body mi-grid">
                    <label class="check span3"><input v-model="form.opening_enabled" type="checkbox" :disabled="locked"> Il cespite ha già ammortamenti di esercizi precedenti</label>
                    <template v-if="form.opening_enabled">
                        <div class="field"><label for="f-oly">Situazione al 31/12 dell’esercizio</label><input id="f-oly" v-model="form.opening_last_year" class="input tabular" inputmode="numeric" maxlength="4" :disabled="locked" :aria-invalid="err('opening_last_year') ? true : undefined"><p v-if="err('opening_last_year')" class="error">{{ err('opening_last_year') }}</p></div>
                        <div class="field"><label for="f-ofc">Fondo ammortamento civilistico</label><input id="f-ofc" v-model="form.opening_fund_civil" class="input tabular" inputmode="decimal" :disabled="locked" :aria-invalid="err('opening_fund_civil') ? true : undefined"><p v-if="err('opening_fund_civil')" class="error">{{ err('opening_fund_civil') }}</p></div>
                        <div class="field"><label for="f-off">Fondo fiscale (ammortamenti dedotti)</label><input id="f-off" v-model="form.opening_fund_fiscal" class="input tabular" inputmode="decimal" :disabled="locked" placeholder="come il civilistico"><p v-if="err('opening_fund_fiscal')" class="error">{{ err('opening_fund_fiscal') }}</p></div>
                    </template>
                    <p class="small muted span3">Per molti cespiti insieme: Cespiti › Importa progressivi (CSV).</p>
                </div></section>
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Contabilità</h2><p>Vuoto = conto della categoria.</p></div></div>
                <div class="panel-body mi-grid">
                    <div v-for="(l, r) in roles" :key="r" class="field"><label :for="`f-acc-${r}`">{{ l }}</label>
                        <select :id="`f-acc-${r}`" v-model="(form as unknown as Record<string, string>)[`account_${r}_id`]" class="input"><option value="">Categoria: {{ catAccount(r) }}</option><option v-for="x in accFor(r)" :key="x.id" :value="String(x.id)">{{ x.code }} · {{ x.name }}</option></select>
                        <p v-if="err(`account_${r}_id`)" class="error">{{ err(`account_${r}_id`) }}</p></div>
                </div></section>
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Agevolazioni e limiti fiscali</h2><p>Nessun automatismo: indica solo ciò che spetta secondo la disciplina dell’investimento (da verificare con il professionista).</p></div></div>
                <div class="panel-body mi-grid">
                    <label class="check span3"><input v-model="form.low_value" type="checkbox"> Bene di modesto valore: deduzione integrale nell’esercizio (art. 102 c. 5 TUIR, fino a {{ String(settings.low_value_threshold).replace('.', ',') }} €)</label>
                    <p v-if="err('low_value')" class="error span3">{{ err('low_value') }}</p>
                    <div class="field"><label for="f-cap">Limite del costo fiscale</label><input id="f-cap" v-model="form.fiscal_cost_cap" class="input tabular" inputmode="decimal" placeholder="es. autovetture"></div>
                    <div class="field"><label for="f-dp">% di deducibilità</label><input id="f-dp" v-model="form.fiscal_deductible_pct" class="input tabular" inputmode="decimal" placeholder="es. 20"></div>
                    <div class="field"><label for="f-ex">Maggiorazione (super/iperammortamento) %</label><input id="f-ex" v-model="form.extra_deduction_pct" class="input tabular" inputmode="decimal"></div>
                    <div class="field span3"><label for="f-exn">Riferimento normativo della maggiorazione</label><input id="f-exn" v-model="form.extra_deduction_note" class="input"></div>
                </div></section>
            <div class="actions"><button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Salva</button><Link class="btn" :href="asset ? `${base}/${asset.id}` : base">Annulla</Link></div>
        </form>
    </AppLayout>
</template>
