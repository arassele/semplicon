<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Field from '@/Components/Field.vue';
import Icon from '@/Components/Icon.vue';
import type { Option, SharedProps } from '@/types';

type Contact = { type: string; value: string; description: string | null; is_default: boolean };
type Activity = { code: string; description: string; is_primary: boolean };
type Bank = { iban: string; bank_name: string | null; description: string | null; is_default: boolean; abi_cab?: { abi: string; cab: string } | null };
type Cassa = { type: string; rate: string; base_pct: string; vat_rate: string; natura: string; ritenuta: boolean; cap: string };
type Issuing = { ritenuta: { enabled: boolean; type: string; rate: string; base_pct: string; causale: string }; casse: Cassa[] };
type CompanyData = Record<string, string | null> & { tipologia: string; contacts: Contact[]; activities: Activity[]; bank_accounts: Bank[]; issuing: Issuing };
type Code = { code: string; label: string | null };
type IssuingOptions = { cassa_types: Code[]; ritenuta_types: Code[]; causali: Code[]; professions: { label: string; type: string }[]; nature: Code[] };

const props = defineProps<{ logo: { v: number } | null; company: CompanyData; atecoSource: string; options: { types: Option[]; contact_types: Option[] }; issuingOptions: IssuingOptions }>();

/* Logo: caricamento autonomo, indipendente dal salvataggio dell’anagrafica */
const logoBase = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/dati-azienda/logo`);
const logoUrl = logoBase;
const logoError = ref('');
function uploadLogo(e: Event) {
    const input = e.target as HTMLInputElement, f = input.files?.[0]; if (!f) return;
    logoError.value = '';
    router.post(logoBase.value, { logo: f }, { forceFormData: true, preserveScroll: true, onError: (er: Record<string, string>) => { logoError.value = (Object.values(er)[0] as string) ?? 'Caricamento non riuscito.'; }, onFinish: () => { input.value = ''; } });
}
const removeLogo = () => router.delete(logoBase.value, { preserveScroll: true });

/* Ricerca ATECO: suggerimenti dall’elenco ufficiale, descrizione associata al codice. */
type AtecoItem = { code: string; title_it: string };
const atecoHits = ref<AtecoItem[]>([]);
const atecoTitles = ref<Record<string, string>>(Object.fromEntries(props.company.activities.map((a) => [a.code, a.description])));
let atecoTimer: ReturnType<typeof setTimeout> | undefined;
function searchAteco(q: string) {
    clearTimeout(atecoTimer);
    if (q.trim().length < 2) return;
    atecoTimer = setTimeout(async () => {
        const res = await fetch(`/ateco?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = (await res.json()) as { items: AtecoItem[] };
        atecoHits.value = data.items;
        data.items.forEach((i) => { atecoTitles.value[i.code] = i.title_it; });
    }, 200);
}
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);

const toForm = (c: CompanyData) => ({
    tipologia: c.tipologia, name: c.name ?? '', partita_iva: c.partita_iva ?? '', codice_fiscale: c.codice_fiscale ?? '',
    natura_giuridica: c.natura_giuridica ?? '', sigla: c.sigla ?? '', codice_sdi: c.codice_sdi ?? '',
    titolo: c.titolo ?? '', cognome: c.cognome ?? '', nome: c.nome ?? '', sesso: c.sesso ?? '', data_nascita: c.data_nascita ?? '',
    comune_nascita: c.comune_nascita ?? '', provincia_nascita: c.provincia_nascita ?? '',
    indirizzo: c.indirizzo ?? '', numero_civico: c.numero_civico ?? '', cap: c.cap ?? '', comune: c.comune ?? '', provincia: c.provincia ?? '',
    contacts: c.contacts.map((x) => ({ ...x, description: x.description ?? '' })),
    activities: c.activities.map((x) => ({ ...x })),
    bank_accounts: c.bank_accounts.map(({ abi_cab, ...x }) => ({ ...x, bank_name: x.bank_name ?? '', description: x.description ?? '' })),
    issuing: { ritenuta: { ...c.issuing.ritenuta }, casse: c.issuing.casse.map((x) => ({ ...x })) } as Issuing,
});
const form = useForm(toForm(props.company));
// Dopo il salvataggio, recepire i valori salvati (normalizzati).
watch(() => props.company, (c) => { form.defaults(toForm(c)); form.reset(); });
const isPerson = computed(() => form.tipologia !== 'societa');
const e = (k: string) => (form.errors as Record<string, string>)[k];

const addContact = () => form.contacts.push({ type: 'email', value: '', description: '', is_default: form.contacts.length === 0 });
const addActivity = () => form.activities.push({ code: '', description: '', is_primary: form.activities.length === 0 });
// Selezione dai suggerimenti: ridurre al codice un input come “69.20.01 – Attività di commercialisti”.
function pickAteco(a: Activity) {
    const m = a.code.match(/^\d{2}\.\d{2}\.\d{2}/);
    if (m) a.code = m[0];
    searchAteco(a.code);
}
const addBank = () => form.bank_accounts.push({ iban: '', bank_name: '', description: '', is_default: form.bank_accounts.length === 0 });
// Solo una voce può essere “predefinito” o “prevalente”.
function only<T extends Record<string, unknown>>(list: T[], i: number, flag: keyof T) {
    list.forEach((row, j) => { (row as Record<string, unknown>)[flag as string] = j === i ? !row[flag] : false; });
}
const addCassa = () => form.issuing.casse.push({ type: 'TC22', rate: '', base_pct: '100,00', vat_rate: '22', natura: '', ritenuta: false, cap: '' });
const profession = ref<Record<number, string>>({});
function pickProfession(i: number) { const p = props.issuingOptions.professions.find((x) => x.label === profession.value[i]); if (p) form.issuing.casse[i].type = p.type; }
const cassaTitle = (i: number) => (i === 0 ? 'Prima cassa' : 'Seconda cassa');
const save = () => form.transform((d) => ({ ...d, sesso: d.sesso || null, data_nascita: d.data_nascita || null })).put(`${base.value}/dati-azienda`, { preserveScroll: true });
</script>

<template>
    <Head title="Dati azienda" />
    <AppLayout crumb="Dati azienda">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni</p>
                <h1>Dati azienda</h1>
                <p>Anagrafica del soggetto: identificativi fiscali, sede, recapiti, attività e conti bancari.</p>
            </div>
            <button class="btn primary" type="button" :disabled="form.processing || !form.isDirty" @click="save"><Icon name="check" />Salva dati</button>
        </div>

        <section class="panel" style="margin-bottom: 20px" aria-labelledby="s-logo">
            <div class="panel-head"><div><h2 id="s-logo">Logo per le fatture</h2><p>Compare in alto sull’anteprima e sul PDF delle fatture (in scala di grigi con il resto del documento). PNG o JPG, fino a 2 MB; viene ridotto a 600 × 240 px.</p></div></div>
            <div class="panel-body logo-row">
                <div class="logo-box"><img v-if="logo" :src="`${logoUrl}?v=${logo.v}`" alt="Logo attuale" /><span v-else class="muted small">Nessun logo</span></div>
                <div>
                    <label class="btn" for="logo-file"><Icon name="upload" />{{ logo ? 'Sostituisci logo' : 'Carica logo' }}</label>
                    <input id="logo-file" class="sr-only" type="file" accept="image/png,image/jpeg" @change="uploadLogo">
                    <button v-if="logo" class="btn link" type="button" @click="removeLogo">Rimuovi</button>
                    <p v-if="logoError" class="error" role="alert">{{ logoError }}</p>
                </div>
            </div>
        </section>

        <form class="stack" novalidate @submit.prevent="save">
            <section class="panel" aria-labelledby="s-soggetto">
                <div class="panel-head"><h2 id="s-soggetto">Soggetto</h2></div>
                <div class="panel-body form">
                    <div class="form-row">
                        <fieldset class="field">
                            <legend>Tipologia</legend>
                            <div class="segmented">
                                <label v-for="t in options.types" :key="t.value"><input v-model="form.tipologia" type="radio" name="tipologia" :value="t.value">{{ t.label }}</label>
                            </div>
                        </fieldset>
                        <Field id="codice_sdi" label="Codice destinatario SDI" hint="7 caratteri, per la fatturazione elettronica" :error="e('codice_sdi')" v-slot="{ describedby, invalid }">
                            <input id="codice_sdi" v-model="form.codice_sdi" class="input tabular" maxlength="7" :aria-describedby="describedby" :aria-invalid="invalid">
                        </Field>
                    </div>
                    <div class="form-row">
                        <Field id="partita_iva" label="Partita IVA" hint="IT + 11 cifre" :error="e('partita_iva')" v-slot="{ describedby, invalid }">
                            <input id="partita_iva" v-model="form.partita_iva" class="input tabular" inputmode="numeric" maxlength="11" :aria-describedby="describedby" :aria-invalid="invalid">
                        </Field>
                        <Field id="codice_fiscale" label="Codice fiscale" :error="e('codice_fiscale')" v-slot="{ describedby, invalid }">
                            <input id="codice_fiscale" v-model="form.codice_fiscale" class="input tabular" maxlength="16" :aria-describedby="describedby" :aria-invalid="invalid">
                        </Field>
                    </div>
                    <div class="form-row">
                        <Field id="name" :label="isPerson ? 'Denominazione (facoltativa)' : 'Denominazione / Ragione sociale'" :hint="isPerson ? 'Se vuota: cognome e nome' : undefined" :error="e('name')" v-slot="{ describedby, invalid }">
                            <input id="name" v-model="form.name" class="input" :aria-describedby="describedby" :aria-invalid="invalid">
                        </Field>
                        <Field id="sigla" label="Sigla (facoltativa)" v-slot="{ describedby }">
                            <input id="sigla" v-model="form.sigla" class="input" :aria-describedby="describedby">
                        </Field>
                    </div>
                    <Field v-if="!isPerson" id="natura_giuridica" label="Natura giuridica" v-slot="{ describedby }">
                        <input id="natura_giuridica" v-model="form.natura_giuridica" class="input" placeholder="es. Società in nome collettivo" :aria-describedby="describedby">
                    </Field>
                </div>
            </section>

            <section v-if="isPerson" class="panel" aria-labelledby="s-persona">
                <div class="panel-head"><h2 id="s-persona">Persona fisica</h2></div>
                <div class="panel-body form">
                    <div class="form-row three">
                        <Field id="titolo" label="Titolo" v-slot="{ describedby }"><input id="titolo" v-model="form.titolo" class="input" placeholder="es. Dott." :aria-describedby="describedby"></Field>
                        <Field id="cognome" label="Cognome" :error="e('cognome')" v-slot="{ describedby, invalid }"><input id="cognome" v-model="form.cognome" class="input" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                        <Field id="nome" label="Nome" :error="e('nome')" v-slot="{ describedby, invalid }"><input id="nome" v-model="form.nome" class="input" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                    </div>
                    <div class="form-row three">
                        <fieldset class="field">
                            <legend>Sesso</legend>
                            <div class="segmented">
                                <label><input v-model="form.sesso" type="radio" name="sesso" value="M">Maschio</label>
                                <label><input v-model="form.sesso" type="radio" name="sesso" value="F">Femmina</label>
                            </div>
                        </fieldset>
                        <Field id="data_nascita" label="Data di nascita" :error="e('data_nascita')" v-slot="{ describedby, invalid }"><input id="data_nascita" v-model="form.data_nascita" type="date" class="input" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                        <div class="form-row tight">
                            <Field id="comune_nascita" label="Comune di nascita" v-slot="{ describedby }"><input id="comune_nascita" v-model="form.comune_nascita" class="input" :aria-describedby="describedby"></Field>
                            <Field id="provincia_nascita" label="Prov." :error="e('provincia_nascita')" v-slot="{ describedby, invalid }"><input id="provincia_nascita" v-model="form.provincia_nascita" class="input" maxlength="2" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="s-sede">
                <div class="panel-head"><h2 id="s-sede">{{ isPerson ? 'Residenza / Sede' : 'Sede legale' }}</h2></div>
                <div class="panel-body form">
                    <div class="form-row addr">
                        <Field id="indirizzo" label="Indirizzo" v-slot="{ describedby }"><input id="indirizzo" v-model="form.indirizzo" class="input" :aria-describedby="describedby"></Field>
                        <Field id="numero_civico" label="N. civico" v-slot="{ describedby }"><input id="numero_civico" v-model="form.numero_civico" class="input" :aria-describedby="describedby"></Field>
                    </div>
                    <div class="form-row three">
                        <Field id="cap" label="CAP" :error="e('cap')" v-slot="{ describedby, invalid }"><input id="cap" v-model="form.cap" class="input tabular" inputmode="numeric" maxlength="5" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                        <Field id="comune" label="Comune" v-slot="{ describedby }"><input id="comune" v-model="form.comune" class="input" :aria-describedby="describedby"></Field>
                        <Field id="provincia" label="Provincia" :error="e('provincia')" v-slot="{ describedby, invalid }"><input id="provincia" v-model="form.provincia" class="input" maxlength="2" placeholder="es. BZ" :aria-describedby="describedby" :aria-invalid="invalid"></Field>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="s-recapiti">
                <div class="panel-head"><div><h2 id="s-recapiti">Recapiti</h2><p>Il recapito predefinito si usa per la corrispondenza.</p></div></div>
                <div class="table-wrap" style="margin-top: 14px">
                    <table class="edit-table">
                        <thead><tr><th>Predef.</th><th>Tipo</th><th>Recapito</th><th>Descrizione</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(r, i) in form.contacts" :key="i">
                                <td><input type="checkbox" :checked="r.is_default" :aria-label="`Recapito ${i + 1} predefinito`" @change="only(form.contacts, i, 'is_default')"></td>
                                <td><select v-model="r.type" class="input" :aria-label="`Tipo recapito ${i + 1}`"><option v-for="t in options.contact_types" :key="t.value" :value="t.value">{{ t.label }}</option></select></td>
                                <td><input v-model="r.value" class="input" :aria-label="`Recapito ${i + 1}`" :aria-invalid="e(`contacts.${i}.value`) ? true : undefined"><p v-if="e(`contacts.${i}.value`)" class="error">{{ e(`contacts.${i}.value`) }}</p></td>
                                <td><input v-model="r.description" class="input" :aria-label="`Descrizione recapito ${i + 1}`"></td>
                                <td><button class="btn link" type="button" @click="form.contacts.splice(i, 1)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel-body"><p v-if="e('contacts')" class="error">{{ e('contacts') }}</p><button class="btn" type="button" @click="addContact"><Icon name="plus" />Aggiungi recapito</button></div>
            </section>

            <section class="panel" aria-labelledby="s-attivita">
                <div class="panel-head"><div><h2 id="s-attivita">Attività svolte (ATECO 2025)</h2><p>Cerca per codice o descrizione e indica l’attività prevalente.</p></div></div>
                <div class="table-wrap" style="margin-top: 14px">
                    <table class="edit-table">
                        <thead><tr><th>Prev.</th><th>Codice ATECO 2025</th><th>Descrizione</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(a, i) in form.activities" :key="i">
                                <td><input type="checkbox" :checked="a.is_primary" :aria-label="`Attività ${i + 1} prevalente`" @change="only(form.activities, i, 'is_primary')"></td>
                                <td style="width: 220px"><input v-model="a.code" class="input tabular" list="ateco-list" placeholder="Codice o parola, es. commercialisti" autocomplete="off" :aria-label="`Codice ATECO ${i + 1}`" :aria-invalid="e(`activities.${i}.code`) ? true : undefined" @input="searchAteco(a.code)" @change="pickAteco(a)"><p v-if="e(`activities.${i}.code`)" class="error">{{ e(`activities.${i}.code`) }}</p></td>
                                <td><p class="ateco-title">{{ atecoTitles[a.code] ?? (a.code ? 'Cerca e scegli un codice dalla lista' : '—') }}</p></td>
                                <td><button class="btn link" type="button" @click="form.activities.splice(i, 1)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <datalist id="ateco-list"><option v-for="h in atecoHits" :key="h.code" :value="h.code">{{ h.code }} – {{ h.title_it }}</option></datalist>
                <div class="panel-body"><p v-if="e('activities')" class="error">{{ e('activities') }}</p><button class="btn" type="button" @click="addActivity"><Icon name="plus" />Aggiungi attività</button><p class="hint" style="margin-top: 10px">{{ atecoSource }}</p></div>
            </section>

            <section class="panel" aria-labelledby="s-banche">
                <div class="panel-head"><div><h2 id="s-banche">Conti bancari</h2><p>ABI e CAB vengono ricavati dall’IBAN.</p></div></div>
                <div class="table-wrap" style="margin-top: 14px">
                    <table class="edit-table">
                        <thead><tr><th>Predef.</th><th>IBAN</th><th>Istituto di credito</th><th>Utilizzo</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                        <tbody>
                            <tr v-for="(b, i) in form.bank_accounts" :key="i">
                                <td><input type="checkbox" :checked="b.is_default" :aria-label="`Conto ${i + 1} predefinito`" @change="only(form.bank_accounts, i, 'is_default')"></td>
                                <td class="iban-cell"><input v-model="b.iban" class="input tabular" placeholder="IT60 X054 2811 1010 0000 0123 456" :aria-label="`IBAN ${i + 1}`" :aria-invalid="e(`bank_accounts.${i}.iban`) ? true : undefined">
                                    <p v-if="e(`bank_accounts.${i}.iban`)" class="error">{{ e(`bank_accounts.${i}.iban`) }}</p>
                                    <p v-else-if="company.bank_accounts[i]?.abi_cab && company.bank_accounts[i].iban === b.iban" class="hint">ABI {{ company.bank_accounts[i].abi_cab!.abi }} · CAB {{ company.bank_accounts[i].abi_cab!.cab }}</p></td>
                                <td><input v-model="b.bank_name" class="input" :aria-label="`Istituto ${i + 1}`"></td>
                                <td><input v-model="b.description" class="input" :aria-label="`Utilizzo conto ${i + 1}`"></td>
                                <td><button class="btn link" type="button" @click="form.bank_accounts.splice(i, 1)">Rimuovi</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel-body"><p v-if="e('bank_accounts')" class="error">{{ e('bank_accounts') }}</p><button class="btn" type="button" @click="addBank"><Icon name="plus" />Aggiungi conto</button></div>
            </section>

            <section class="panel" aria-labelledby="s-ritenuta">
                <div class="panel-head"><div><h2 id="s-ritenuta">Fatturazione: ritenuta d’acconto</h2><p>Quando emetti una fattura, Semplicon propone la ritenuta con questi valori. Le percentuali sono quelle che indichi tu.</p></div></div>
                <div class="panel-body mi-grid">
                    <label class="check span3"><input v-model="form.issuing.ritenuta.enabled" type="checkbox">Applica la ritenuta d’acconto alle mie fatture</label>
                    <template v-if="form.issuing.ritenuta.enabled">
                        <div class="field span2"><label for="rt-type">Tipo ritenuta</label><select id="rt-type" v-model="form.issuing.ritenuta.type" class="input"><option v-for="t in issuingOptions.ritenuta_types" :key="t.code" :value="t.code">{{ t.code }} · {{ t.label }}</option></select><p v-if="e('issuing.ritenuta.type')" class="error">{{ e('issuing.ritenuta.type') }}</p></div>
                        <div class="field"><label for="rt-rate">Ritenuta del (%)</label><input id="rt-rate" v-model="form.issuing.ritenuta.rate" class="input tabular" inputmode="decimal" :aria-invalid="e('issuing.ritenuta.rate') ? true : undefined"><p v-if="e('issuing.ritenuta.rate')" class="error">{{ e('issuing.ritenuta.rate') }}</p></div>
                        <div class="field"><label for="rt-base">su (% dell’imponibile)</label><input id="rt-base" v-model="form.issuing.ritenuta.base_pct" class="input tabular" inputmode="decimal" :aria-invalid="e('issuing.ritenuta.base_pct') ? true : undefined"><p v-if="e('issuing.ritenuta.base_pct')" class="error">{{ e('issuing.ritenuta.base_pct') }}</p></div>
                        <div class="field span2"><label for="rt-cau">Causale di pagamento della ritenuta</label><select id="rt-cau" v-model="form.issuing.ritenuta.causale" class="input"><option v-for="c in issuingOptions.causali" :key="c.code" :value="c.code">{{ c.code }}{{ c.label ? ` · ${c.label}` : '' }}</option></select><p class="hint">Codici del modello CU.</p></div>
                    </template>
                </div>
            </section>

            <section class="panel" aria-labelledby="s-cassa">
                <div class="panel-head"><div><h2 id="s-cassa">Fatturazione: cassa previdenziale</h2><p>Contributo di cassa (rivalsa) che si aggiunge all’imponibile delle fatture. Fino a due casse.</p></div></div>
                <div v-for="(c, i) in form.issuing.casse" :key="i" class="panel-body mi-grid cassa-block">
                    <h3 class="span3">{{ cassaTitle(i) }}</h3>
                    <div class="field"><label :for="`cs-prof-${i}`">Professione svolta</label><select :id="`cs-prof-${i}`" v-model="profession[i]" class="input" @change="pickProfession(i)"><option value="">— scegli per proporre la cassa —</option><option v-for="p in issuingOptions.professions" :key="p.label" :value="p.label">{{ p.label }}</option></select></div>
                    <div class="field span2"><label :for="`cs-type-${i}`">Tipo cassa</label><select :id="`cs-type-${i}`" v-model="c.type" class="input"><option v-for="t in issuingOptions.cassa_types" :key="t.code" :value="t.code">{{ t.code }} · {{ t.label }}</option></select><p v-if="e(`issuing.casse.${i}.type`)" class="error">{{ e(`issuing.casse.${i}.type`) }}</p></div>
                    <div class="field"><label :for="`cs-rate-${i}`">% cassa previdenza</label><input :id="`cs-rate-${i}`" v-model="c.rate" class="input tabular" inputmode="decimal" :aria-invalid="e(`issuing.casse.${i}.rate`) ? true : undefined"><p v-if="e(`issuing.casse.${i}.rate`)" class="error">{{ e(`issuing.casse.${i}.rate`) }}</p></div>
                    <div class="field"><label :for="`cs-base-${i}`">% cassa su imponibile</label><input :id="`cs-base-${i}`" v-model="c.base_pct" class="input tabular" inputmode="decimal" :aria-invalid="e(`issuing.casse.${i}.base_pct`) ? true : undefined"><p v-if="e(`issuing.casse.${i}.base_pct`)" class="error">{{ e(`issuing.casse.${i}.base_pct`) }}</p></div>
                    <div class="field"><label :for="`cs-cap-${i}`">Massimale (contributo massimo per fattura)</label><input :id="`cs-cap-${i}`" v-model="c.cap" class="input tabular" inputmode="decimal" placeholder="senza massimale"><p v-if="e(`issuing.casse.${i}.cap`)" class="error">{{ e(`issuing.casse.${i}.cap`) }}</p></div>
                    <div class="field"><label :for="`cs-vat-${i}`">Aliquota IVA sul contributo</label><select :id="`cs-vat-${i}`" v-model="c.vat_rate" class="input"><option value="22">22%</option><option value="10">10%</option><option value="5">5%</option><option value="4">4%</option><option value="0">0% (con natura)</option></select></div>
                    <div v-if="c.vat_rate === '0'" class="field span2"><label :for="`cs-nat-${i}`">Natura</label><select :id="`cs-nat-${i}`" v-model="c.natura" class="input"><option value="">—</option><option v-for="n in issuingOptions.nature" :key="n.code" :value="n.code">{{ n.code }} · {{ n.label }}</option></select><p v-if="e(`issuing.casse.${i}.natura`)" class="error">{{ e(`issuing.casse.${i}.natura`) }}</p></div>
                    <label class="check"><input v-model="c.ritenuta" type="checkbox">Soggetta a ritenuta</label>
                    <div class="span3"><button class="btn link" type="button" @click="form.issuing.casse.splice(i, 1)">Rimuovi {{ cassaTitle(i).toLowerCase() }}</button></div>
                </div>
                <div class="panel-body"><button v-if="form.issuing.casse.length < 2" class="btn" type="button" @click="addCassa"><Icon name="plus" />Aggiungi {{ form.issuing.casse.length ? 'seconda' : 'prima' }} cassa</button><p v-else class="hint">Massimo due casse.</p><p v-if="e('issuing.casse')" class="error">{{ e('issuing.casse') }}</p></div>
            </section>

            <div class="actions">
                <button class="btn primary" type="submit" :disabled="form.processing || !form.isDirty"><Icon name="check" />Salva dati</button>
                <span v-if="form.isDirty" class="small muted">Modifiche non salvate</span>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.logo-row { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.logo-box { width: 220px; height: 90px; border: 1px dashed var(--ct-border, #dce3dd); border-radius: 10px; display: grid; place-items: center; background: #fff; padding: 8px; }
.logo-box img { max-width: 100%; max-height: 100%; filter: grayscale(1); }
</style>
