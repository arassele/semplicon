<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Emissione fattura elettronica: inserimento → FatturaPA (libreria deved/fattura-elettronica) → Registro vendite → Scadenziario.
   Gli importi qui sono un’anteprima; fa fede il calcolo del server. */
type Customer = { payment_mode?: string; payment_days?: number; master?: boolean; kind: 'azienda' | 'persona'; name: string; first_name: string; last_name: string; vat: string | null; cf: string | null; country: string; address: string; zip: string; city: string; province: string; sdi_code: string | null; pec: string | null; is_pa: boolean };
const props = defineProps<{
    companyIssues: { field: string; label: string }[]; nextNumber: string; today: string; docTypes: Record<string, string>; paymentModes: Record<string, string>;
    regimes: Record<string, string>; regime: string; nature: { code: string; label: string }[]; accounts: { id: number; code: string; name: string }[]; account: number | null;
    bank: { iban: string; bank_name: string | null } | null; customers: Customer[]; bolloThreshold: number; hasRegime: boolean;
    settings: { ritenuta: { enabled: boolean; type: string; rate: string; base_pct: string; causale: string }; casse: { type: string; rate: string; base_pct: string; vat_rate: string; natura: string; ritenuta: boolean; cap: string }[] };
    settingTypes: { cassa_types: { code: string; label: string }[]; ritenuta_types: { code: string; label: string }[] };
    prefill: { form: Record<string, unknown>; warnings: string[]; original: { number: string; date: string; id: string } } | null;
    catalog: { articles: { id: number; code: string | null; description: string; unit: string | null; price: string; rate: string; natura: string | null }[]; snippets: { id: number; title: string; body: string; kind: 'causale' | 'riga' }[]; units: string[] };
    acconti: { id: number; number: string; doc_date: string; doc_type: string; customer: string; customer_vat: string | null; customer_cf: string | null; taxable: string }[];
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);

const blank = (): Customer => ({ kind: 'azienda', name: '', first_name: '', last_name: '', vat: '', cf: '', country: 'IT', address: '', zip: '', city: '', province: '', sdi_code: '', pec: '', is_pa: false });
const addDays = (iso: string, n: number) => { const d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
type Line = { description: string; quantity: string; unit: string; price: string; discount: string; rate: string; natura: string };
const form = useForm({
    doc_type: 'TD01', doc_date: props.today, regime: props.regime, account_id: props.account as number | null, payment_mode: 'MP05', causale: '', bollo: false, split: false,
    ref_number: '', ref_date: '', ref_normativo: '',
    customer: blank(),
    lines: [{ description: '', quantity: '1', unit: 'pz', price: '', discount: '', rate: '22', natura: '' }] as Line[],
    apply_ritenuta: props.settings.ritenuta.enabled, apply_casse: props.settings.casse.map(() => true) as boolean[], acconti: [] as number[],
    payments: [{ date: addDays(props.today, 30), amount: '' }] as { date: string; amount: string }[],
});
// Nota di credito con un clic: righe, cliente e opzioni della fattura originale (storno per l’intero importo)
if (props.prefill) {
    const f = props.prefill.form as Record<string, any>;
    form.doc_type = f.doc_type; form.regime = f.regime; form.payment_mode = f.payment_mode; form.bollo = !!f.bollo; form.split = !!f.split;
    form.ref_number = f.ref_number; form.ref_date = f.ref_date; form.ref_normativo = f.ref_normativo ?? ''; form.causale = f.causale ?? '';
    if (f.account_id && props.accounts.some((a) => a.id === f.account_id)) form.account_id = f.account_id;
    form.customer = { ...blank(), ...f.customer, vat: f.customer?.vat ?? '', cf: f.customer?.cf ?? '', sdi_code: f.customer?.sdi_code ?? '', pec: f.customer?.pec ?? '' };
    if (f.lines?.length) form.lines = f.lines;
    form.apply_ritenuta = !!f.apply_ritenuta && props.settings.ritenuta.enabled;
    if (Array.isArray(f.apply_casse) && f.apply_casse.length === props.settings.casse.length) form.apply_casse = f.apply_casse;
}
const err = (k: string) => (form.errors as Record<string, string>)[k];

type Quote = { lines: (string | null)[]; groups: { rate: string; natura: string | null; taxable: string; tax: string }[]; casse: { type: string; rate: string; contribution: string; base: string; natura: string | null }[];
    ritenuta: { amount: string; base: string; rate: string } | null; taxable: string; tax: string; total: string; payable: string; exempt: string; errors: Record<string, string> };
const quote = ref<Quote | null>(null);
const cents = (v: string | undefined) => Math.round(Number(v ?? '0') * 100);
const totals = computed(() => ({ taxable: cents(quote.value?.taxable), tax: cents(quote.value?.tax), total: cents(quote.value?.total), payable: cents(quote.value?.payable), exempt: cents(quote.value?.exempt) }));
const fmt = (c: number) => money(`${c < 0 ? '-' : ''}${Math.floor(Math.abs(c) / 100)}.${String(Math.abs(c) % 100).padStart(2, '0')}`);
const m = (v: string | null | undefined) => (v === null || v === undefined ? '—' : money(v));
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
async function refreshQuote() {
    try {
        const res = await fetch(`${base.value}/fatture/emetti/anteprima`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(payload()) });
        if (res.ok) quote.value = (await res.json()) as Quote;
    } catch { /* l’anteprima è facoltativa, l’emissione calcola da sé */ }
}
const bolloSuggested = computed(() => totals.value.exempt > props.bolloThreshold);

/* Rata: finché non viene modificata a mano, l’unica rata segue l’importo da pagare. */
const touched = ref(false);
watch(() => totals.value.payable, (p) => {
    if (!touched.value && form.payments.length === 1) form.payments[0].amount = p > 0 ? `${Math.floor(p / 100)},${String(p % 100).padStart(2, '0')}` : '';
});
const paySum = computed(() => form.payments.reduce((s, r) => s + Math.round(Number((r.amount || '0').replace(',', '.')) * 100), 0));
const addPayment = () => { touched.value = true; form.payments.push({ date: addDays(form.doc_date, 60), amount: '' }); };
const removePayment = (i: number) => { touched.value = true; form.payments.splice(i, 1); };

const pick = ref('');
// Ricerca: cliente esistente e articolo con suggerimenti (richieste del 24.11.2026)
const customerLabel = (c: Customer) => `${c.kind === 'persona' ? `${c.first_name} ${c.last_name}` : c.name}${c.vat ? ` · ${c.vat}` : ''}${c.master ? '' : ' (da fatture)'}`;
const customerLabels = computed(() => props.customers.map(customerLabel));
const customerQuery = ref('');
function pickCustomer() { const i = customerLabels.value.indexOf(customerQuery.value); if (i >= 0) { pick.value = String(i); applyCustomer(); } }
const articleLabel = (a: { code?: string | null; description: string }) => `${a.code ? `${a.code} · ` : ''}${a.description.slice(0, 60)}`;
const articleByLabel = (v: string) => props.catalog.articles.find((a) => articleLabel(a) === v);
watch(() => form.customer.kind, (k) => { if (k === 'persona' && !foreign.value) form.customer.vat = ''; });
function applyCustomer() {
    const c = props.customers[Number(pick.value)];
    if (!c) return;
    const { payment_mode, payment_days, master, ...rest } = c;
    form.customer = { ...blank(), ...rest, vat: c.vat ?? '', cf: c.cf ?? '', sdi_code: c.sdi_code ?? '', pec: c.pec ?? '' };
    if (master && payment_mode) { form.payment_mode = payment_mode; if (!touched.value && form.payments.length === 1) form.payments[0].date = addDays(form.doc_date, payment_days ?? 30); }
}
/* VIES: verifica della partita IVA del cliente (UE); con numero di consultazione come prova. */
const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'GR', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];
type Vies = { status: 'valida' | 'non_valida' | 'non_disponibile'; name: string | null; address: string | null; request_identifier: string | null; message: string | null; checked_at: string };
const vies = ref<Vies | null>(null);
const viesBusy = ref(false);
const viesError = ref('');
const canVies = computed(() => EU.includes(form.customer.country.trim().toUpperCase()) && !!(form.customer.vat ?? '').trim());
watch(() => [form.customer.country, form.customer.vat], () => { vies.value = null; viesError.value = ''; });
async function checkVies() {
    viesBusy.value = true; viesError.value = ''; vies.value = null;
    try {
        const res = await fetch(`${base.value}/vies/verifica`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ country: form.customer.country, vat: form.customer.vat }) });
        const j = await res.json();
        if (res.ok) vies.value = j as Vies; else viesError.value = (j.errors && Object.values(j.errors as Record<string, string[]>).flat()[0]) || j.message || 'Verifica non riuscita.';
    } catch { viesError.value = 'Verifica non riuscita. Riprova più tardi.'; }
    viesBusy.value = false;
}
const useViesName = () => { if (vies.value?.name) { form.customer.kind = 'azienda'; form.customer.name = vies.value.name.slice(0, 80); } };
const intraNatura = computed(() => foreign.value && form.lines.some((l) => ['N3.2', 'N3.3', 'N3.4'].includes(l.natura)));
const foreign = computed(() => form.customer.country.trim().toUpperCase() !== 'IT');
const hasNatura = computed(() => form.lines.some((l) => l.natura));
const payload = () => ({ ...form.data(), customer: { ...form.customer, country: form.customer.country.toUpperCase(), province: form.customer.province.toUpperCase(), sdi_code: (form.customer.sdi_code ?? '').toUpperCase() },
    payments: form.doc_type === 'TD04' ? [] : form.payments });
const rec = ref({ enabled: false, name: '', frequency: 'mensile', start_date: addDays(props.today, 30), end_date: '', auto_issue: false, payment_days: 30 });
const saveRecurring = () => {
    const { enabled, ...r } = rec.value;
    form.transform(() => ({ ...payload(), payments: [], recurring: { ...r, end_date: r.end_date || null } })).post(`${base.value}/fatture/ricorrenti`);
};
/* Anteprima del documento generato dal file XML (nessuna emissione) */
const draftHtml = ref<string | null>(null);
const draftBusy = ref(false);
async function showDraft() {
    draftBusy.value = true; form.clearErrors();
    try {
        const res = await fetch(`${base.value}/fatture/emetti/documento`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(payload()) });
        const j = await res.json();
        if (res.ok) draftHtml.value = j.html as string;
        else { form.setError(Object.fromEntries(Object.entries((j.errors ?? { invoice: [j.message] }) as Record<string, string[]>).map(([k, v]) => [k, v[0]])) as never); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    } catch { form.setError('invoice' as never, 'Anteprima non riuscita. Riprova.' as never); }
    draftBusy.value = false;
}
const submit = () => { form.transform(() => payload()).post(`${base.value}/fatture/emetti`); };
// Ricalcola l’anteprima a ogni modifica dopo una breve pausa
watch(() => JSON.stringify(form.data()), () => { clearTimeout(quoteTimer); quoteTimer = setTimeout(refreshQuote, 250); }, { immediate: true });
const matchingAcconti = computed(() => props.acconti.filter((a) => (form.customer.vat && a.customer_vat === (form.customer.vat.toUpperCase().startsWith(form.customer.country.toUpperCase()) ? form.customer.vat.toUpperCase() : `${form.customer.country.toUpperCase()}${form.customer.vat.toUpperCase()}`)) || (form.customer.cf && a.customer_cf === form.customer.cf.toUpperCase())));
function toggleAcconto(id: number) { form.acconti = form.acconti.includes(id) ? form.acconti.filter((x) => x !== id) : [...form.acconti, id]; }
const ritLabel = computed(() => props.settingTypes.ritenuta_types.find((t) => t.code === props.settings.ritenuta.type)?.label ?? '');
const cassaLabel = (t: string) => props.settingTypes.cassa_types.find((x) => x.code === t)?.label ?? t;
const num = (v: string) => v.replace('.', ',');
function onNatura(l: Line) { if (l.natura) l.rate = '0'; else if (l.rate === '0') l.rate = '22'; }
/* Catalogo: l’articolo compila la riga, il testo predefinito aggiunge testo a Causale o Descrizione. */
const applyArticle = (l: Line, id: string) => {
    const a = props.catalog.articles.find((x) => String(x.id) === id);
    if (!a) return;
    Object.assign(l, { description: a.description, unit: a.unit || l.unit, price: a.price, rate: a.natura ? '0' : a.rate, natura: a.natura ?? '' });
};
const addText = (cur: string, text: string) => (cur ? `${cur} ${text}` : text);
const causaleSnippets = computed(() => props.catalog.snippets.filter((s) => s.kind === 'causale'));
const rowSnippets = computed(() => props.catalog.snippets.filter((s) => s.kind === 'riga'));
const newLine = (): Line => ({ description: '', quantity: '1', unit: 'pz', price: '', discount: '', rate: '22', natura: '' });
</script>

<template>
    <Head title="Emetti fattura elettronica" />
    <AppLayout crumb="Emetti fattura">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/vendite`">Vendite</Link></p>
                <h1>Emetti fattura elettronica</h1>
                <p>Semplicon crea il file FatturaPA, lo controlla rispetto allo schema ufficiale, assegna il numero, registra la fattura nel registro vendite e prepara le scadenze. La trasmissione al SdI resta a te: scarica il file e invialo dal tuo canale.</p>
            </div>
        </div>

        <div v-if="prefill" class="notice" role="status" style="margin-bottom: 16px">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Nota di credito per la fattura n. {{ prefill.original.number }} del {{ prefill.original.date.split('-').reverse().join('/') }}</strong>Il modulo riprende cliente e righe della fattura (storno totale). Per uno storno parziale cambia o togli le righe, poi emetti.
                <ul v-if="prefill.warnings.length"><li v-for="(w, i) in prefill.warnings" :key="i">{{ w }}</li></ul></div>
        </div>
        <div v-if="companyIssues.length" class="notice bad" role="alert" style="margin-bottom: 16px">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Mancano dati dell’azienda per l’XML</strong>{{ companyIssues.map((i) => i.label).join(', ') }}. <Link :href="`${base}/dati-azienda`">Completa Dati azienda</Link></div>
        </div>
        <div v-if="!hasRegime" class="notice warn" role="status" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Regime IVA non confermato</strong>Senza regime confermato per l’anno non si può registrare. <Link :href="`${base}/profilo-iva`">Imposta il regime</Link></div></div>
        <div v-if="err('company') || err('xml') || err('invoice')" class="notice bad" role="alert" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ err('company') || err('xml') || err('invoice') }}</div></div>

        <form class="stack" novalidate @submit.prevent="submit">
            <section class="panel">
                <div class="panel-head"><h2>Documento</h2></div>
                <div class="panel-body mi-grid">
                    <div class="field span2"><label for="is-type">Tipo documento</label>
                        <select id="is-type" v-model="form.doc_type" class="input"><option v-for="(l, k) in docTypes" :key="k" :value="k">{{ k }} · {{ l }}</option></select><p v-if="err('doc_type')" class="error">{{ err('doc_type') }}</p></div>
                    <div class="field"><label for="is-num">Numero</label><input id="is-num" class="input tabular" :value="nextNumber" readonly aria-describedby="is-num-h"><p id="is-num-h" class="hint">Progressivo assegnato all’emissione.</p></div>
                    <div class="field"><label for="is-date">Data</label><input id="is-date" v-model="form.doc_date" type="date" class="input" :max="today" :aria-invalid="err('doc_date') ? true : undefined"><p v-if="err('doc_date')" class="error">{{ err('doc_date') }}</p></div>
                    <div class="field span2"><label for="is-reg">Regime fiscale dell’emittente</label>
                        <select id="is-reg" v-model="form.regime" class="input"><option v-for="(l, k) in regimes" :key="k" :value="k">{{ k }} · {{ l }}</option></select><p v-if="err('regime')" class="error">{{ err('regime') }}</p></div>
                    <div v-if="form.doc_type === 'TD04'" class="field"><label for="is-refn">Fattura rettificata: numero</label><input id="is-refn" v-model="form.ref_number" class="input" maxlength="20"></div>
                    <div v-if="form.doc_type === 'TD04'" class="field"><label for="is-refd">Fattura rettificata: data</label><input id="is-refd" v-model="form.ref_date" type="date" class="input"></div>
                    <div class="field span3"><label for="is-causale">Causale (facoltativa)</label><input id="is-causale" v-model="form.causale" class="input" maxlength="200" placeholder="es. Consulenza novembre 2026"><p v-if="err('causale')" class="error">{{ err('causale') }}</p>
                        <select v-if="causaleSnippets.length" class="input small-select" aria-label="Inserisci testo predefinito nella causale" @change="(e) => { const t = (e.target as HTMLSelectElement); const s = causaleSnippets.find((x) => String(x.id) === t.value); if (s) form.causale = addText(form.causale, s.body).slice(0, 200); t.value = ''; }"><option value="">+ Inserisci testo predefinito…</option><option v-for="s in causaleSnippets" :key="s.id" :value="String(s.id)">{{ s.title }}</option></select></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><div><h2>Cliente</h2><p>Per i clienti già fatturati puoi riprendere i dati.</p></div></div>
                <div class="panel-body mi-grid">
                    <div v-if="customers.length" class="field span3"><label for="is-pick">Cliente esistente</label>
                        <input id="is-pick" v-model="customerQuery" class="input" type="search" list="is-pick-list" autocomplete="off" placeholder="Cerca per nome o partita IVA…" @change="pickCustomer">
                        <datalist id="is-pick-list"><option v-for="(lab, i) in customerLabels" :key="i" :value="lab" /></datalist>
                        <p class="hint">Scrivi per cercare e scegli dall’elenco; lascia vuoto per un nuovo cliente.</p></div>
                    <fieldset class="field span3"><legend>Tipo di cliente</legend>
                        <div class="segmented"><label><input v-model="form.customer.kind" type="radio" value="azienda">Azienda o professionista</label><label><input v-model="form.customer.kind" type="radio" value="persona">Persona fisica</label></div></fieldset>
                    <div v-if="form.customer.kind === 'azienda'" class="field span3"><label for="is-cn">Denominazione</label><input id="is-cn" v-model="form.customer.name" class="input" maxlength="80" :aria-invalid="err('customer.name') ? true : undefined"><p v-if="err('customer.name')" class="error">{{ err('customer.name') }}</p></div>
                    <template v-else>
                        <div class="field"><label for="is-cfn">Nome</label><input id="is-cfn" v-model="form.customer.first_name" class="input" :aria-invalid="err('customer.first_name') ? true : undefined"><p v-if="err('customer.first_name')" class="error">{{ err('customer.first_name') }}</p></div>
                        <div class="field span2"><label for="is-cln">Cognome</label><input id="is-cln" v-model="form.customer.last_name" class="input"></div>
                    </template>
                    <div v-if="form.customer.kind !== 'persona' || foreign" class="field"><label for="is-vat">{{ form.customer.kind === 'persona' ? 'Identificativo fiscale estero' : 'Partita IVA' }}</label><input id="is-vat" v-model="form.customer.vat" class="input tabular" :placeholder="foreign ? 'es. DE123456789' : 'es. 01234567897'" :aria-invalid="err('customer.vat') ? true : undefined"><p v-if="err('customer.vat')" class="error">{{ err('customer.vat') }}</p></div>
                    <div class="field"><label for="is-cf">Codice fiscale</label><input id="is-cf" v-model="form.customer.cf" class="input tabular" :aria-invalid="err('customer.cf') ? true : undefined"><p v-if="err('customer.cf')" class="error">{{ err('customer.cf') }}</p></div>
                    <div class="field"><label for="is-co">Paese</label><input id="is-co" v-model="form.customer.country" class="input" maxlength="2" style="text-transform: uppercase" :aria-invalid="err('customer.country') ? true : undefined"><p v-if="err('customer.country')" class="error">{{ err('customer.country') }}</p></div>
                    <p v-if="form.customer.kind === 'persona' && !foreign" class="hint span3">Cliente privato: nessuna partita IVA e nessuna verifica VIES, basta il codice fiscale.</p>
                    <div v-if="canVies && form.customer.kind !== 'persona'" class="span3 vies-box">
                        <button class="btn" type="button" :disabled="viesBusy" @click="checkVies"><Icon name="search" />{{ viesBusy ? 'Verifica in corso…' : 'Verifica la partita IVA con VIES' }}</button>
                        <p v-if="viesError" class="error" role="alert">{{ viesError }}</p>
                        <p v-if="vies?.status === 'valida'" class="vies-ok" role="status"><strong>Partita IVA valida in VIES</strong><template v-if="vies.name"> · {{ vies.name }}</template><template v-if="vies.address"> · {{ vies.address }}</template>
                            <template v-if="vies.request_identifier"><br>Numero di consultazione {{ vies.request_identifier }} ({{ dateTime(vies.checked_at) }}): conservalo come prova.</template>
                            <button v-if="vies.name && !form.customer.name" class="btn link" type="button" @click="useViesName">Usa il nome</button></p>
                        <p v-else-if="vies?.status === 'non_valida'" class="error" role="alert"><strong>Non valida</strong> · {{ vies.message }}</p>
                        <p v-else-if="vies?.status === 'non_disponibile'" class="hint" role="status">{{ vies.message }} Il risultato non dice nulla sulla validità del numero.</p>
                        <p v-if="intraNatura && vies?.status !== 'valida'" class="hint">Per le cessioni intra-UE non imponibili conserva una verifica VIES valida del cliente.</p>
                    </div>
                    <div class="field span2"><label for="is-ad">Indirizzo</label><input id="is-ad" v-model="form.customer.address" class="input" maxlength="60" placeholder="Via e numero civico" :aria-invalid="err('customer.address') ? true : undefined"><p v-if="err('customer.address')" class="error">{{ err('customer.address') }}</p></div>
                    <div class="field"><label for="is-zip">CAP</label><input id="is-zip" v-model="form.customer.zip" class="input" :disabled="foreign" :aria-invalid="err('customer.zip') ? true : undefined"><p v-if="foreign" class="hint">Estero: 00000</p><p v-if="err('customer.zip')" class="error">{{ err('customer.zip') }}</p></div>
                    <div class="field span2"><label for="is-city">Comune / città</label><input id="is-city" v-model="form.customer.city" class="input" maxlength="60" :aria-invalid="err('customer.city') ? true : undefined"><p v-if="err('customer.city')" class="error">{{ err('customer.city') }}</p></div>
                    <div class="field"><label for="is-prov">Provincia</label><input id="is-prov" v-model="form.customer.province" class="input" maxlength="2" :disabled="foreign" style="text-transform: uppercase" :aria-invalid="err('customer.province') ? true : undefined"><p v-if="err('customer.province')" class="error">{{ err('customer.province') }}</p></div>
                    <template v-if="!foreign">
                        <div class="field"><label for="is-sdi">{{ form.customer.is_pa ? 'Codice univoco ufficio (6 caratteri)' : 'Codice destinatario (7 caratteri)' }}</label><input id="is-sdi" v-model="form.customer.sdi_code" class="input tabular" maxlength="7" style="text-transform: uppercase" :aria-invalid="err('customer.sdi_code') ? true : undefined"><p class="hint">Vuoto = 0000000 (consegna via PEC o area riservata).</p><p v-if="err('customer.sdi_code')" class="error">{{ err('customer.sdi_code') }}</p></div>
                        <div class="field span2"><label for="is-pec">PEC del cliente</label><input id="is-pec" v-model="form.customer.pec" class="input" type="email" :aria-invalid="err('customer.pec') ? true : undefined"><p v-if="err('customer.pec')" class="error">{{ err('customer.pec') }}</p></div>
                        <label class="check span3"><input v-model="form.customer.is_pa" type="checkbox">Il cliente è una Pubblica Amministrazione (formato FPA12)</label>
                        <p v-if="err('customer.is_pa')" class="error span3">{{ err('customer.is_pa') }}</p>
                    </template>
                    <p v-else class="hint span3">Cliente estero: l’XML usa il codice destinatario XXXXXXX; si trasmette al SdI come le altre fatture.</p>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><div><h2>Righe</h2><p>Prezzo senza IVA. Per righe senza IVA scegli la natura (es. N4 esente, N2.2 non soggetta).</p></div></div>
                <div class="table-wrap">
                    <datalist id="is-art-list"><option v-for="a in catalog.articles" :key="a.id" :value="articleLabel(a)" /></datalist>
                    <table class="mi-lines">
                        <thead><tr><th>Descrizione</th><th class="num">Quantità</th><th>Unità</th><th class="num">Prezzo</th><th class="num">Sconto %</th><th>Aliquota %</th><th>Natura</th><th class="num">Totale riga</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(l, i) in form.lines" :key="i">
                                <td style="min-width: 170px"><input v-model="l.description" class="input" :aria-label="`Descrizione riga ${i + 1}`" :aria-invalid="err(`lines.${i}.description`) ? true : undefined"><p v-if="err(`lines.${i}.description`)" class="error">{{ err(`lines.${i}.description`) }}</p>
                                    <div v-if="catalog.articles.length || rowSnippets.length" class="line-tools">
                                        <input v-if="catalog.articles.length" class="input small-select" type="search" list="is-art-list" autocomplete="off" placeholder="Cerca articolo…" :aria-label="`Cerca articolo per la riga ${i + 1}`" @change="(e) => { const t = (e.target as HTMLInputElement); const a = articleByLabel(t.value); if (a) applyArticle(l, String(a.id)); t.value = ''; }">
                                        <select v-if="rowSnippets.length" class="input small-select" :aria-label="`Testo predefinito per la riga ${i + 1}`" @change="(e) => { const t = (e.target as HTMLSelectElement); const s = rowSnippets.find((x) => String(x.id) === t.value); if (s) l.description = addText(l.description, s.body).slice(0, 1000); t.value = ''; }"><option value="">Testo…</option><option v-for="s in rowSnippets" :key="s.id" :value="String(s.id)">{{ s.title }}</option></select>
                                    </div></td>
                                <td style="width: 78px"><input v-model="l.quantity" class="input tabular" inputmode="decimal" style="text-align: right" :aria-label="`Quantità riga ${i + 1}`"><p v-if="err(`lines.${i}.quantity`)" class="error">{{ err(`lines.${i}.quantity`) }}</p></td>
                                <td style="width: 76px"><input v-model="l.unit" class="input" maxlength="10" list="is-units" :aria-label="`Unità riga ${i + 1}`"></td>
                                <td style="width: 104px"><input v-model="l.price" class="input tabular" inputmode="decimal" placeholder="0,00" style="text-align: right" :aria-label="`Prezzo riga ${i + 1}`" :aria-invalid="err(`lines.${i}.price`) ? true : undefined"><p v-if="err(`lines.${i}.price`)" class="error">{{ err(`lines.${i}.price`) }}</p></td>
                                <td style="width: 74px"><input v-model="l.discount" class="input tabular" inputmode="decimal" placeholder="0" style="text-align: right" :aria-label="`Sconto riga ${i + 1}`"><p v-if="err(`lines.${i}.discount`)" class="error">{{ err(`lines.${i}.discount`) }}</p></td>
                                <td style="width: 82px"><input v-model="l.rate" class="input tabular" inputmode="decimal" list="is-rates" :disabled="!!l.natura" :aria-label="`Aliquota riga ${i + 1}`"><p v-if="err(`lines.${i}.rate`)" class="error">{{ err(`lines.${i}.rate`) }}</p></td>
                                <td style="min-width: 150px"><select v-model="l.natura" class="input" :aria-label="`Natura riga ${i + 1}`" @change="onNatura(l)"><option value="">— (imponibile)</option><option v-for="n in nature" :key="n.code" :value="n.code">{{ n.code }} · {{ n.label }}</option></select><p v-if="err(`lines.${i}.natura`)" class="error">{{ err(`lines.${i}.natura`) }}</p></td>
                                <td class="num tabular" style="width: 104px; white-space: nowrap">{{ m(quote?.lines[i]) }}</td>
                                <td><button v-if="form.lines.length > 1" class="btn link" type="button" @click="form.lines.splice(i, 1)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr v-for="(c, k) in quote?.casse ?? []" :key="`c${k}`"><td colspan="7">Cassa previdenziale {{ c.type }} ({{ num(c.rate) }}% su {{ m(c.base) }})</td><td class="num tabular">{{ m(c.contribution) }}</td><td /></tr>
                            <tr class="sum-row"><td colspan="7">Imponibile</td><td class="num tabular">{{ m(quote?.taxable) }}</td><td /></tr>
                            <tr><td colspan="7">IVA</td><td class="num tabular">{{ m(quote?.tax) }}</td><td /></tr>
                            <tr class="sum-row"><td colspan="7">Totale documento</td><td class="num tabular">{{ m(quote?.total) }}</td><td /></tr>
                            <tr v-if="quote?.ritenuta"><td colspan="7">Ritenuta d’acconto {{ num(quote.ritenuta.rate) }}% (su {{ m(quote.ritenuta.base) }})</td><td class="num tabular" style="white-space: nowrap">− {{ m(quote.ritenuta.amount) }}</td><td /></tr>
                            <tr v-if="quote?.ritenuta" class="sum-row"><td colspan="7">Netto a pagare</td><td class="num tabular">{{ m(quote?.payable) }}</td><td /></tr>
                        </tfoot>
                    </table>
                    <datalist id="is-units"><option v-for="u in catalog.units" :key="u" :value="u" /></datalist>
                    <datalist id="is-rates"><option value="22" /><option value="10" /><option value="5" /><option value="4" /></datalist>
                </div>
                <div class="panel-body mi-grid">
                    <div class="span3"><button class="btn" type="button" @click="form.lines.push(newLine())"><Icon name="plus" />Aggiungi riga</button><p v-if="err('lines')" class="error">{{ err('lines') }}</p></div>
                    <template v-if="settings.ritenuta.enabled || settings.casse.length">
                        <p class="span3 small muted" style="margin-top: 8px">Valori da <Link :href="`${base}/dati-azienda`">Dati azienda</Link>. Togli la spunta per non applicarli a questa fattura.</p>
                        <label v-if="settings.ritenuta.enabled" class="check span3"><input v-model="form.apply_ritenuta" type="checkbox">Ritenuta d’acconto {{ num(settings.ritenuta.rate) }}% su {{ num(settings.ritenuta.base_pct) }}% dell’imponibile · {{ settings.ritenuta.type }} {{ ritLabel }} · causale {{ settings.ritenuta.causale }}</label>
                        <label v-for="(c, k) in settings.casse" :key="k" class="check span3"><input v-model="form.apply_casse[k]" type="checkbox">{{ k === 0 ? 'Prima' : 'Seconda' }} cassa {{ c.type }} {{ num(c.rate) }}% su {{ num(c.base_pct) }}%{{ c.cap ? `, massimale ${num(c.cap)} €` : '' }} · IVA {{ c.vat_rate === '0' ? c.natura : `${c.vat_rate}%` }}{{ c.ritenuta ? ' · soggetta a ritenuta' : '' }} · {{ cassaLabel(c.type) }}</label>
                    </template>
                    <div v-if="matchingAcconti.length" class="span3">
                        <p class="small"><strong>Acconti già fatturati a questo cliente</strong> (si deducono dalle righe, IVA compresa)</p>
                        <label v-for="a in matchingAcconti" :key="a.id" class="check"><input type="checkbox" :checked="form.acconti.includes(a.id)" @change="toggleAcconto(a.id)">Acconto {{ a.doc_type }} n. {{ a.number }} del {{ a.doc_date.split('-').reverse().join('/') }} · imponibile {{ money(a.taxable) }} €</label>
                        <p v-if="err('acconti.0')" class="error">{{ err('acconti.0') }}</p>
                    </div>
                    <div v-if="hasNatura" class="field span3"><label for="is-rn">Riferimento normativo (righe senza IVA)</label><input id="is-rn" v-model="form.ref_normativo" class="input" maxlength="100" placeholder="es. Esente art. 10 DPR 633/72"></div>
                    <label class="check span3"><input v-model="form.bollo" type="checkbox">Bollo virtuale (2,00 €, a carico dell’emittente)</label>
                    <p v-if="bolloSuggested && !form.bollo" class="hint span3">Gli importi senza IVA superano 77,47 €: in genere serve il bollo.</p>
                    <p v-if="err('bollo')" class="error span3">{{ err('bollo') }}</p>
                    <label v-if="form.customer.is_pa" class="check span3"><input v-model="form.split" type="checkbox">Scissione dei pagamenti (art. 17-ter): l’IVA la versa la PA</label>
                    <p v-if="err('split')" class="error span3">{{ err('split') }}</p>
                </div>
            </section>

            <section v-if="form.doc_type !== 'TD04'" class="panel">
                <div class="panel-head"><div><h2>Pagamento</h2><p>Importo da pagare {{ fmt(totals.payable) }} €<template v-if="form.split && form.customer.is_pa"> (IVA in scissione esclusa)</template>. Le scadenze finiscono nello Scadenziario.</p></div></div>
                <div class="panel-body mi-grid">
                    <div class="field span2"><label for="is-mode">Modalità di pagamento</label><select id="is-mode" v-model="form.payment_mode" class="input"><option v-for="(l, k) in paymentModes" :key="k" :value="k">{{ l }}</option></select>
                        <p v-if="bank && ['MP05', 'MP19', 'MP20', 'MP21'].includes(form.payment_mode)" class="hint">IBAN nel file: {{ bank.iban }}{{ bank.bank_name ? ` · ${bank.bank_name}` : '' }}</p>
                        <p v-else-if="['MP05', 'MP19', 'MP20', 'MP21'].includes(form.payment_mode)" class="hint">Nessun conto bancario in Dati azienda: il file non conterrà l’IBAN.</p></div>
                    <div v-for="(r, i) in form.payments" :key="i" class="span3 pay-row">
                        <div class="field"><label :for="`is-pd-${i}`">Scadenza {{ form.payments.length > 1 ? i + 1 : '' }}</label><input :id="`is-pd-${i}`" v-model="r.date" type="date" class="input" :min="form.doc_date" :aria-invalid="err(`payments.${i}.date`) ? true : undefined"><p v-if="err(`payments.${i}.date`)" class="error">{{ err(`payments.${i}.date`) }}</p></div>
                        <div class="field"><label :for="`is-pa-${i}`">Importo</label><input :id="`is-pa-${i}`" v-model="r.amount" class="input tabular" inputmode="decimal" style="text-align: right" @input="touched = true" :aria-invalid="err(`payments.${i}.amount`) ? true : undefined"><p v-if="err(`payments.${i}.amount`)" class="error">{{ err(`payments.${i}.amount`) }}</p></div>
                        <button v-if="form.payments.length > 1" class="btn link" type="button" @click="removePayment(i)">Rimuovi</button>
                    </div>
                    <div class="span3"><button class="btn" type="button" @click="addPayment"><Icon name="plus" />Aggiungi rata</button>
                        <p v-if="paySum !== totals.payable" class="hint" style="margin-top: 6px">Somma delle rate {{ fmt(paySum) }} €: deve essere {{ fmt(totals.payable) }} €.</p>
                        <p v-if="err('payments')" class="error">{{ err('payments') }}</p></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Registrazione</h2></div>
                <div class="panel-body mi-grid">
                    <div class="field span2"><label for="is-acc">Conto di ricavo</label><select id="is-acc" v-model="form.account_id" class="input"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></select><p class="hint">La fattura è registrata subito nel registro vendite, nel periodo della data fattura.</p><p v-if="err('account_id')" class="error">{{ err('account_id') }}</p></div>
                </div>
            </section>

            <section v-if="!prefill" class="panel">
                <div class="panel-head"><div><h2>Ripetizione (facoltativa)</h2><p>Salva questa fattura come modello e riemettila a intervalli regolari. Nella causale e nelle righe valgono <code>{mese}</code>, <code>{anno}</code>, <code>{periodo}</code>.</p></div></div>
                <div class="panel-body mi-grid">
                    <label class="check span3"><input v-model="rec.enabled" type="checkbox">Salva come fattura ricorrente</label>
                    <template v-if="rec.enabled">
                        <div class="field"><label for="rc-name">Nome del modello</label><input id="rc-name" v-model="rec.name" class="input" maxlength="80" placeholder="es. Canone assistenza"></div>
                        <div class="field"><label for="rc-freq">Frequenza</label><select id="rc-freq" v-model="rec.frequency" class="input"><option value="mensile">Ogni mese</option><option value="trimestrale">Ogni 3 mesi</option><option value="semestrale">Ogni 6 mesi</option><option value="annuale">Ogni anno</option></select><p v-if="err('recurring.frequency')" class="error">{{ err('recurring.frequency') }}</p></div>
                        <div class="field"><label for="rc-start">Prima emissione</label><input id="rc-start" v-model="rec.start_date" type="date" class="input"><p v-if="err('recurring.start_date')" class="error">{{ err('recurring.start_date') }}</p></div>
                        <div class="field"><label for="rc-end">Ultima emissione (facoltativa)</label><input id="rc-end" v-model="rec.end_date" type="date" class="input"><p v-if="err('recurring.end_date')" class="error">{{ err('recurring.end_date') }}</p></div>
                        <div class="field"><label for="rc-days">Pagamento dopo giorni</label><input id="rc-days" v-model.number="rec.payment_days" type="number" min="0" max="365" class="input"></div>
                        <label class="check span3"><input v-model="rec.auto_issue" type="checkbox">Emetti automaticamente ogni mattina alla scadenza (senza conferma)</label>
                        <div class="span3"><button class="btn" type="button" :disabled="form.processing" @click="saveRecurring"><Icon name="calendar" />Salva modello ricorrente</button><p class="hint">Il modello non emette nulla adesso: la fattura viene emessa alla data della prima emissione.</p></div>
                    </template>
                </div>
            </section>

            <div class="actions">
                <button class="btn primary" type="submit" :disabled="form.processing || companyIssues.length > 0"><Icon name="check" />Emetti e registra</button>
                <button class="btn" type="button" :disabled="draftBusy || companyIssues.length > 0" @click="showDraft"><Icon name="search" />{{ draftBusy ? 'Creo l’anteprima…' : 'Anteprima della fattura' }}</button>
                <Link :href="`${base}/vendite`" class="btn">Annulla</Link>
            </div>
        </form>
        <div v-if="draftHtml" class="draft-back" role="dialog" aria-modal="true" aria-label="Anteprima della fattura" @keydown.esc="draftHtml = null" @mousedown.self="draftHtml = null">
            <div class="draft">
                <div class="draft-bar"><strong>Anteprima dal file XML</strong><button class="btn" type="button" autofocus @click="draftHtml = null">Chiudi</button></div>
                <iframe :srcdoc="draftHtml" title="Anteprima della fattura" sandbox="" />
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.line-tools { display: flex; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
.small-select { width: auto; max-width: 100%; font-size: 0.8125rem; padding-block: 3px; margin-top: 4px; }
.line-tools .small-select { margin-top: 0; }
.vies-box { display: grid; gap: 6px; justify-items: start; }
.vies-ok { color: var(--ok, inherit); margin: 0; }
.draft-back { position: fixed; inset: 0; background: rgb(0 30 26 / 0.45); display: flex; justify-content: center; align-items: flex-start; padding: 4vh 16px; z-index: 100; }
.draft { background: #fff; width: min(860px, 100%); height: 92vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgb(0 0 0 / 0.3); }
.draft-bar { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #dce3dd; }
.draft iframe { flex: 1; border: 0; width: 100%; }
.pay-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 16px; align-items: end; }
@media (max-width: 640px) { .pay-row { grid-template-columns: minmax(0, 1fr); } }
</style>
