<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Code = { code: string; label: string };
type Existing = { id: number; doc_type: string; number: string; doc_date: string; receipt_date: string | null; counterparty_name: string; counterparty_vat: string | null; counterparty_cf: string | null;
    description: string | null; lines: { description: string; rate: string; natura: string; taxable: string; tax: string }[]; document: { id: number; name: string } | null; registered: string | null;
    currency: string; exchange_rate: string; counterparty_country: string | null; address: Record<string, string> };
const props = defineProps<{ invoice: Existing | null; direction: 'acquisto' | 'vendita'; docTypes: Code[]; nature: Code[]; today: string; accept: string; maxMb: number }>();
const ex = props.invoice;
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);

type Line = { description: string; rate: string; natura: string; taxable: string; tax: string };
const form = useForm({
    direction: props.direction, doc_type: ex?.doc_type ?? 'TD01', number: ex?.number ?? '', doc_date: ex?.doc_date ?? props.today, receipt_date: ex?.receipt_date ?? '',
    counterparty_name: ex?.counterparty_name ?? '', counterparty_vat: ex?.counterparty_vat ?? '', counterparty_cf: ex?.counterparty_cf ?? '', description: ex?.description ?? '',
    lines: (ex ? ex.lines.map((l) => ({ ...l })) : [{ description: '', rate: '22', natura: '', taxable: '', tax: '' }]) as Line[],
    currency: ex?.currency ?? 'EUR', exchange_rate: ex?.exchange_rate ?? '',
    counterparty_country: ex?.counterparty_country ?? 'IT', counterparty_address: ex?.address?.indirizzo ?? '', counterparty_zip: ex?.address?.cap ?? '', counterparty_city: ex?.address?.comune ?? '', counterparty_province: ex?.address?.provincia ?? '',
    file: null as File | null,
    remove_attachment: false,
});
const e = (k: string) => (form.errors as Record<string, string>)[k];

// Imposta proposta da Imponibile × Aliquota (centesimi, mezzo centesimo arrotondato per eccesso in valore assoluto), come sul server.
function cents(v: string): number | null {
    const m = /^(\d{1,12})(?:[.,](\d{1,2}))?$/.exec(v.trim());
    return m ? Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0')) : null;
}
const fromCents = (c: number) => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`;
function expectedTax(l: Line): number | null {
    const t = cents(l.taxable), r = cents(l.rate);
    return t === null || r === null ? null : Math.floor((t * r + 5000) / 10000);
}
const totals = computed(() => {
    let taxable = 0, tax = 0;
    for (const l of form.lines) { taxable += cents(l.taxable) ?? 0; tax += (l.tax.trim() ? cents(l.tax) : expectedTax(l)) ?? 0; }
    return { taxable: fromCents(taxable), tax: fromCents(tax), total: fromCents(taxable + tax) };
});
function onNatura(l: Line) { if (l.natura) { l.rate = '0'; l.tax = ''; } }
const addLine = () => form.lines.push({ description: '', rate: form.currency === 'EUR' ? '22' : '0', natura: form.currency === 'EUR' ? '' : 'N2.2', taxable: '', tax: '' });
const foreign = computed(() => form.currency.toUpperCase() !== 'EUR');
// Controvalore in euro per valuta estera (visualizzazione; fa fede il calcolo sul server).
const fxRate = computed(() => Number(String(form.exchange_rate).replace(',', '.')) || 0);
const eurOf = (v: string) => { const c = cents(v); return c === null || !fxRate.value ? null : Math.floor((c / fxRate.value) + 0.5); };
const submit = () => form.post(ex ? `${base.value}/fatture/${ex.id}/modifica` : `${base.value}/fatture/nuova`, { forceFormData: true });
const backUrl = computed(() => (ex ? `${base.value}/fatture/${ex.id}` : `${base.value}/${form.direction === 'vendita' ? 'vendite' : 'acquisti'}`));
const commonTypes = ['TD01', 'TD04', 'TD05', 'TD06', 'TD16', 'TD17', 'TD18', 'TD19', 'TD24', 'TD25'];
const typeOptions = computed(() => [...props.docTypes.filter((t) => commonTypes.includes(t.code)), ...props.docTypes.filter((t) => !commonTypes.includes(t.code))]);
</script>

<template>
    <Head :title="ex ? `Modifica fattura ${ex.number}` : 'Fattura manuale'" />
    <AppLayout :crumb="ex ? 'Modifica fattura' : 'Fattura manuale'">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/${form.direction === 'vendita' ? 'vendite' : 'acquisti'}`">{{ form.direction === 'vendita' ? 'Vendite' : 'Acquisti' }}</Link></p>
                <h1>{{ ex ? `Modifica fattura ${ex.number}` : 'Fattura manuale' }}</h1>
                <p v-if="!ex">Per fatture senza file FatturaPA (es. cartacee, PDF, fornitori esteri). Dopo il salvataggio la fattura è da registrare come quelle importate.</p>
            </div>
        </div>
        <p v-if="ex?.registered" class="notice info" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><span>La fattura è registrata con protocollo {{ ex.registered }}. Salvando, la registrazione viene ricalcolata con le stesse date, gli stessi conti e le stesse % detraibili. Se cambi il numero di righe del riepilogo, elimina prima la registrazione.</span></p>
        <p v-if="e('registration') || e('invoice')" class="notice bad" role="alert" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><span>{{ e('registration') || e('invoice') }}</span></p>

        <form class="stack" novalidate @submit.prevent="submit">
            <section class="panel">
                <div class="panel-head"><h2>Documento</h2></div>
                <div class="panel-body mi-grid">
                    <fieldset class="field span3"><legend>Tipo di operazione</legend>
                        <div class="segmented"><label><input v-model="form.direction" type="radio" value="acquisto">Acquisto (fattura ricevuta)</label><label><input v-model="form.direction" type="radio" value="vendita">Vendita (fattura emessa)</label></div>
                    </fieldset>
                    <div class="field span2"><label for="mi-type">Tipo documento</label><select id="mi-type" v-model="form.doc_type" class="input"><option v-for="t in typeOptions" :key="t.code" :value="t.code">{{ t.code }} · {{ t.label }}</option></select><p v-if="e('doc_type')" class="error">{{ e('doc_type') }}</p></div>
                    <div class="field"><label for="mi-num">Numero</label><input id="mi-num" v-model="form.number" class="input" maxlength="20" :aria-invalid="e('number') ? true : undefined"><p v-if="e('number')" class="error">{{ e('number') }}</p></div>
                    <div class="field"><label for="mi-date">Data</label><input id="mi-date" v-model="form.doc_date" type="date" class="input" :max="today" :aria-invalid="e('doc_date') ? true : undefined"><p v-if="e('doc_date')" class="error">{{ e('doc_date') }}</p></div>
                    <div v-if="form.direction === 'acquisto'" class="field"><label for="mi-receipt">Data di ricezione</label><input id="mi-receipt" v-model="form.receipt_date" type="date" class="input" :min="form.doc_date" :aria-invalid="e('receipt_date') ? true : undefined"><p class="hint">Determina l’anno in Acquisti e il periodo proposto.</p><p v-if="e('receipt_date')" class="error">{{ e('receipt_date') }}</p></div>
                    <div class="field span2"><label for="mi-desc">Descrizione (facoltativa)</label><input id="mi-desc" v-model="form.description" class="input" placeholder="es. Canone software annuale"></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>{{ form.direction === 'vendita' ? 'Cliente' : 'Fornitore' }}</h2></div>
                <div class="panel-body mi-grid">
                    <div class="field span3"><label for="mi-cp">Denominazione o nome</label><input id="mi-cp" v-model="form.counterparty_name" class="input" :aria-invalid="e('counterparty_name') ? true : undefined"><p v-if="e('counterparty_name')" class="error">{{ e('counterparty_name') }}</p></div>
                    <div class="field"><label for="mi-vat">Partita IVA</label><input id="mi-vat" v-model="form.counterparty_vat" class="input tabular" placeholder="es. 01234567897 o DE123456789" :aria-invalid="e('counterparty_vat') ? true : undefined"><p v-if="e('counterparty_vat')" class="error">{{ e('counterparty_vat') }}</p></div>
                    <div class="field"><label for="mi-country">Paese</label><input id="mi-country" v-model="form.counterparty_country" class="input" maxlength="2" placeholder="IT, DE, US…" style="text-transform: uppercase"><p v-if="e('counterparty_country')" class="error">{{ e('counterparty_country') }}</p></div>
                    <div class="field span2"><label for="mi-addr">Indirizzo</label><input id="mi-addr" v-model="form.counterparty_address" class="input" placeholder="Via e numero (serve per l’XML)"></div>
                    <div class="field"><label for="mi-zip">CAP</label><input id="mi-zip" v-model="form.counterparty_zip" class="input"></div>
                    <div class="field"><label for="mi-city">Comune / città</label><input id="mi-city" v-model="form.counterparty_city" class="input"></div>
                    <div class="field"><label for="mi-prov">Provincia (IT)</label><input id="mi-prov" v-model="form.counterparty_province" class="input" maxlength="2"></div>
                    <div class="field"><label for="mi-cf">Codice fiscale</label><input id="mi-cf" v-model="form.counterparty_cf" class="input tabular" :aria-invalid="e('counterparty_cf') ? true : undefined"><p v-if="e('counterparty_cf')" class="error">{{ e('counterparty_cf') }}</p></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><div><h2>Righe del documento</h2><p>Ogni posizione della fattura su una riga: compare così nei registri IVA. L’imposta è calcolata; correggila solo se in fattura c’è un arrotondamento diverso. Il riepilogo per aliquota si forma da solo.</p></div></div>
                <div class="panel-body mi-grid" style="padding-bottom: 0">
                    <div class="field"><label for="mi-cur">Valuta</label><input id="mi-cur" v-model="form.currency" class="input" maxlength="3" style="text-transform: uppercase" :aria-invalid="e('currency') ? true : undefined"><p v-if="e('currency')" class="error">{{ e('currency') }}</p></div>
                    <div v-if="foreign" class="field"><label for="mi-fx">Cambio applicato (1 EUR = … {{ form.currency.toUpperCase() }})</label><input id="mi-fx" v-model="form.exchange_rate" class="input tabular" inputmode="decimal" placeholder="es. 1,0850" :aria-invalid="e('exchange_rate') ? true : undefined"><p class="hint">Gli importi si inseriscono in {{ form.currency.toUpperCase() }}; Semplicon salva originale, cambio e controvalore in euro.</p><p v-if="e('exchange_rate')" class="error">{{ e('exchange_rate') }}</p></div>
                </div>
                <div class="table-wrap">
                    <table class="mi-lines">
                        <thead><tr><th>Descrizione</th><th>Aliquota %</th><th>Natura</th><th class="num">{{ foreign ? `Importo ${form.currency.toUpperCase()}` : 'Imponibile' }}</th><th class="num">Imposta</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(l, i) in form.lines" :key="i">
                                <td style="min-width: 200px"><input v-model="l.description" class="input" :aria-label="`Descrizione riga ${i + 1}`" placeholder="es. Licenza software"></td>
                                <td style="width: 110px"><input v-model="l.rate" class="input tabular" inputmode="decimal" list="mi-rates" :disabled="!!l.natura" :aria-label="`Aliquota riga ${i + 1}`" :aria-invalid="e(`lines.${i}.rate`) ? true : undefined"><p v-if="e(`lines.${i}.rate`)" class="error">{{ e(`lines.${i}.rate`) }}</p></td>
                                <td style="min-width: 200px"><select v-model="l.natura" class="input" :aria-label="`Natura riga ${i + 1}`" @change="onNatura(l)"><option value="">— (imponibile)</option><option v-for="n in nature" :key="n.code" :value="n.code">{{ n.code }} · {{ n.label }}</option></select><p v-if="e(`lines.${i}.natura`)" class="error">{{ e(`lines.${i}.natura`) }}</p></td>
                                <td style="width: 150px"><input v-model="l.taxable" class="input tabular" inputmode="decimal" placeholder="0,00" style="text-align: right" :aria-label="`Imponibile riga ${i + 1}`" :aria-invalid="e(`lines.${i}.taxable`) ? true : undefined"><p v-if="foreign && eurOf(l.taxable) !== null" class="hint" style="text-align: right">= {{ money(fromCents(eurOf(l.taxable)!)) }} €</p><p v-if="e(`lines.${i}.taxable`)" class="error">{{ e(`lines.${i}.taxable`) }}</p></td>
                                <td style="width: 150px"><input v-model="l.tax" class="input tabular" inputmode="decimal" :placeholder="expectedTax(l) === null ? '0,00' : money(fromCents(expectedTax(l)!))" style="text-align: right" :disabled="!!l.natura" :aria-label="`Imposta riga ${i + 1}`" :aria-invalid="e(`lines.${i}.tax`) ? true : undefined"><p v-if="e(`lines.${i}.tax`)" class="error">{{ e(`lines.${i}.tax`) }}</p></td>
                                <td><button v-if="form.lines.length > 1" class="btn link" type="button" @click="form.lines.splice(i, 1)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                        <tfoot><tr class="sum-row"><td colspan="3">Totale documento {{ money(totals.total) }}</td><td class="num tabular">{{ money(totals.taxable) }}</td><td class="num tabular">{{ money(totals.tax) }}</td><td /></tr></tfoot>
                    </table>
                    <datalist id="mi-rates"><option value="22" /><option value="10" /><option value="5" /><option value="4" /><option value="0" /></datalist>
                </div>
                <div class="panel-body"><button class="btn" type="button" @click="addLine"><Icon name="plus" />Aggiungi riga</button><p v-if="form.doc_type === 'TD04'" class="hint" style="margin-top: 8px">Nota di credito: indica gli importi in positivo, la registrazione li riduce.</p></div>
            </section>

            <section class="panel">
                <div class="panel-head"><div><h2>Allega PDF</h2><p>PDF, foto (JPG, PNG) o XML della fattura, al massimo {{ maxMb }} MB. Resta collegato alla registrazione.</p></div></div>
                <div class="panel-body">
                    <template v-if="ex?.document">
                        <p class="small">Allegato attuale: <a :href="`${base}/documenti/${ex.document.id}/scarica`">{{ ex.document.name }}</a>. Scegli un nuovo file per sostituirlo.</p>
                        <label class="check"><input v-model="form.remove_attachment" type="checkbox" :disabled="!!form.file">Rimuovi l’allegato</label>
                    </template>
                    <label class="sr-only" for="mi-file">Allegato</label>
                    <input id="mi-file" type="file" class="input" :accept="accept" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null">
                    <p v-if="e('file')" class="error">{{ e('file') }}</p>
                </div>
            </section>

            <div class="actions">
                <button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />{{ ex ? 'Salva modifiche' : 'Salva fattura' }}</button>
                <Link :href="backUrl" class="btn">Annulla</Link>
            </div>
        </form>
    </AppLayout>
</template>
