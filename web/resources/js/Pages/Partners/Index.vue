<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Anagrafiche: dati anagrafici di clienti e fornitori. I clienti vengono recepiti direttamente dal modulo di emissione. */
type P = { id: number; is_customer: boolean; is_supplier: boolean; kind: 'azienda' | 'persona'; name: string | null; first_name: string | null; last_name: string | null; vat: string | null; cf: string | null; country: string; address: string | null;
    zip: string | null; city: string | null; province: string | null; sdi_code: string | null; pec: string | null; is_pa: boolean; email: string | null; phone: string | null; iban: string | null; payment_mode: string; payment_days: number;
    notes: string | null; active: boolean; display: string };
const props = defineProps<{ kind: 'clienti' | 'fornitori'; q: string; partners: P[]; paymentModes: Record<string, string>; counts: { clienti: number; fornitori: number } }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const isCust = computed(() => props.kind === 'clienti');
const blank = () => ({ is_customer: isCust.value, is_supplier: !isCust.value, kind: 'azienda' as 'azienda' | 'persona', name: '', first_name: '', last_name: '', vat: '', cf: '', country: 'IT', address: '', zip: '', city: '', province: '', sdi_code: '', pec: '', is_pa: false, email: '', phone: '', iban: '', payment_mode: 'MP05', payment_days: 30, notes: '', active: true });
const form = useForm(blank());
const editing = ref<number | null>(null);
const open = ref(false);
const search = ref(props.q);
const foreign = computed(() => form.country.trim().toUpperCase() !== 'IT');
function startNew() { form.defaults(blank()).reset(); form.clearErrors(); editing.value = null; open.value = true; }
function edit(p: P) { editing.value = p.id; open.value = true; form.clearErrors(); Object.assign(form, { is_customer: p.is_customer, is_supplier: p.is_supplier, kind: p.kind, name: p.name ?? '', first_name: p.first_name ?? '', last_name: p.last_name ?? '', vat: p.vat ?? '', cf: p.cf ?? '', country: p.country, address: p.address ?? '', zip: p.zip ?? '', city: p.city ?? '', province: p.province ?? '', sdi_code: p.sdi_code ?? '', pec: p.pec ?? '', is_pa: p.is_pa, email: p.email ?? '', phone: p.phone ?? '', iban: p.iban ?? '', payment_mode: p.payment_mode, payment_days: p.payment_days, notes: p.notes ?? '', active: p.active }); window.scrollTo({ top: 0, behavior: 'smooth' }); }
const done = () => { open.value = false; editing.value = null; };
const save = () => form.transform((d) => ({ ...d, country: d.country.toUpperCase(), province: d.province.toUpperCase(), sdi_code: d.sdi_code.toUpperCase() }))[editing.value ? 'put' : 'post'](editing.value ? `${base.value}/anagrafiche/${editing.value}` : `${base.value}/anagrafiche`, { preserveScroll: true, onSuccess: done });
const del = (p: P) => { if (confirm(`Eliminare l’anagrafica «${p.display}»? Le fatture già emesse o registrate non cambiano.`)) router.delete(`${base.value}/anagrafiche/${p.id}`, { preserveScroll: true }); };
const find = () => router.get(`${base.value}/anagrafiche/${props.kind}`, { q: search.value || undefined }, { preserveState: true, replace: true });
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <Head :title="isCust ? 'Clienti' : 'Fornitori'" />
    <AppLayout :crumb="isCust ? 'Clienti' : 'Fornitori'">
        <div class="page-head">
            <div>
                <p class="eyebrow">Tabelle</p>
                <h1>{{ isCust ? 'Clienti' : 'Fornitori' }}</h1>
                <p>Anagrafica con dati fiscali, indirizzo, recapiti e condizioni di pagamento. {{ isCust ? 'In «Emetti fattura» scegli il cliente e i dati vengono ripresi.' : 'I fornitori servono come archivio dei dati; le fatture di acquisto restano quelle importate o inserite.' }}</p>
            </div>
            <div class="actions">
                <button class="btn" type="button" @click="router.post(`${base}/anagrafiche/importa`, {}, { preserveScroll: true })">Importa dalle fatture</button>
                <button class="btn primary" type="button" @click="startNew"><Icon name="plus" />{{ isCust ? 'Nuovo cliente' : 'Nuovo fornitore' }}</button>
            </div>
        </div>
        <nav class="segmented" aria-label="Tipo di anagrafica" style="margin-bottom: 16px">
            <Link :href="`${base}/anagrafiche/clienti`" :aria-current="isCust ? 'page' : undefined">Clienti ({{ counts.clienti }})</Link>
            <Link :href="`${base}/anagrafiche/fornitori`" :aria-current="!isCust ? 'page' : undefined">Fornitori ({{ counts.fornitori }})</Link>
        </nav>

        <section v-if="open" class="panel" aria-labelledby="pf-title">
            <div class="panel-head"><h2 id="pf-title">{{ editing ? 'Modifica anagrafica' : isCust ? 'Nuovo cliente' : 'Nuovo fornitore' }}</h2></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="save">
                <fieldset class="field span3"><legend>Ruolo</legend>
                    <label class="check"><input v-model="form.is_customer" type="checkbox">Cliente</label>
                    <label class="check"><input v-model="form.is_supplier" type="checkbox">Fornitore</label>
                    <p v-if="err('is_customer')" class="error">{{ err('is_customer') }}</p></fieldset>
                <fieldset class="field span3"><legend>Tipo</legend>
                    <div class="segmented"><label><input v-model="form.kind" type="radio" value="azienda">Azienda o professionista</label><label><input v-model="form.kind" type="radio" value="persona">Persona fisica</label></div></fieldset>
                <div v-if="form.kind === 'azienda'" class="field span3"><label for="pf-name">Denominazione</label><input id="pf-name" v-model="form.name" class="input" maxlength="120" :aria-invalid="err('name') ? true : undefined"><p v-if="err('name')" class="error">{{ err('name') }}</p></div>
                <template v-else>
                    <div class="field"><label for="pf-fn">Nome</label><input id="pf-fn" v-model="form.first_name" class="input" maxlength="60" :aria-invalid="err('first_name') ? true : undefined"><p v-if="err('first_name')" class="error">{{ err('first_name') }}</p></div>
                    <div class="field span2"><label for="pf-ln">Cognome</label><input id="pf-ln" v-model="form.last_name" class="input" maxlength="60"></div>
                </template>
                <div class="field"><label for="pf-vat">Partita IVA</label><input id="pf-vat" v-model="form.vat" class="input tabular" :aria-invalid="err('vat') ? true : undefined"><p v-if="err('vat')" class="error">{{ err('vat') }}</p></div>
                <div class="field"><label for="pf-cf">Codice fiscale</label><input id="pf-cf" v-model="form.cf" class="input tabular" :aria-invalid="err('cf') ? true : undefined"><p v-if="err('cf')" class="error">{{ err('cf') }}</p></div>
                <div class="field"><label for="pf-co">Paese</label><input id="pf-co" v-model="form.country" class="input" maxlength="2" style="text-transform: uppercase" :aria-invalid="err('country') ? true : undefined"><p v-if="err('country')" class="error">{{ err('country') }}</p></div>
                <div class="field span2"><label for="pf-ad">Indirizzo</label><input id="pf-ad" v-model="form.address" class="input" maxlength="80"></div>
                <div class="field"><label for="pf-zip">CAP</label><input id="pf-zip" v-model="form.zip" class="input" maxlength="10"></div>
                <div class="field span2"><label for="pf-city">Comune / città</label><input id="pf-city" v-model="form.city" class="input" maxlength="60"></div>
                <div class="field"><label for="pf-pr">Provincia</label><input id="pf-pr" v-model="form.province" class="input" maxlength="2" :disabled="foreign" style="text-transform: uppercase"><p v-if="err('province')" class="error">{{ err('province') }}</p></div>
                <template v-if="form.is_customer && !foreign">
                    <div class="field"><label for="pf-sdi">{{ form.is_pa ? 'Codice univoco ufficio' : 'Codice destinatario' }}</label><input id="pf-sdi" v-model="form.sdi_code" class="input tabular" maxlength="7" style="text-transform: uppercase" :aria-invalid="err('sdi_code') ? true : undefined"><p v-if="err('sdi_code')" class="error">{{ err('sdi_code') }}</p></div>
                    <div class="field span2"><label for="pf-pec">PEC</label><input id="pf-pec" v-model="form.pec" class="input" type="email"><p v-if="err('pec')" class="error">{{ err('pec') }}</p></div>
                    <label class="check span3"><input v-model="form.is_pa" type="checkbox">Pubblica Amministrazione (formato FPA12, scissione dei pagamenti)</label>
                </template>
                <div class="field span2"><label for="pf-em">E-mail</label><input id="pf-em" v-model="form.email" class="input" type="email"><p v-if="err('email')" class="error">{{ err('email') }}</p></div>
                <div class="field"><label for="pf-ph">Telefono</label><input id="pf-ph" v-model="form.phone" class="input" maxlength="40"></div>
                <div class="field span2"><label for="pf-ib">IBAN</label><input id="pf-ib" v-model="form.iban" class="input tabular" maxlength="40" :aria-invalid="err('iban') ? true : undefined"><p v-if="err('iban')" class="error">{{ err('iban') }}</p></div>
                <div class="field"><label for="pf-pm">Pagamento</label><select id="pf-pm" v-model="form.payment_mode" class="input"><option v-for="(l, k) in paymentModes" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field"><label for="pf-pd">Giorni</label><input id="pf-pd" v-model.number="form.payment_days" type="number" min="0" max="365" class="input"></div>
                <div class="field span3"><label for="pf-nt">Note</label><textarea id="pf-nt" v-model="form.notes" class="input" rows="2" maxlength="2000" /></div>
                <label class="check span3"><input v-model="form.active" type="checkbox">Attiva (proposta nei moduli)</label>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Salva</button><button class="btn" type="button" @click="done">Annulla</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><form class="search-row" role="search" @submit.prevent="find"><label class="sr-only" for="pq">Cerca</label><input id="pq" v-model="search" class="input" type="search" placeholder="Cerca per nome, partita IVA, codice fiscale o città"><button class="btn" type="submit">Cerca</button></form></div>
            <div v-if="partners.length" class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Partita IVA / C.F.</th><th>Sede</th><th>Contatti</th><th>Pagamento</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="p in partners" :key="p.id">
                            <td>{{ p.display }}<span v-if="p.is_customer && p.is_supplier" class="badge"> cliente e fornitore</span><span v-if="p.is_pa" class="badge info"> PA</span><span v-if="!p.active" class="badge"> non attiva</span></td>
                            <td class="tabular small">{{ p.vat }}<template v-if="p.cf && p.cf !== (p.vat ?? '').slice(2)"><br>{{ p.cf }}</template></td>
                            <td class="small">{{ p.address }}<br>{{ p.zip }} {{ p.city }}<template v-if="p.province"> ({{ p.province }})</template> {{ p.country !== 'IT' ? p.country : '' }}</td>
                            <td class="small">{{ p.email }}<br>{{ p.phone }}</td>
                            <td class="small">{{ paymentModes[p.payment_mode] }}<br>{{ p.payment_days }} gg</td>
                            <td class="num" style="white-space: nowrap"><button class="btn link" type="button" @click="edit(p)">Modifica</button> <button class="btn link" type="button" @click="del(p)">Elimina</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="panel-body"><p class="muted">{{ q ? 'Nessun risultato.' : isCust ? 'Nessun cliente. Aggiungine uno o importa quelli già presenti nelle fatture.' : 'Nessun fornitore. Aggiungine uno o importa quelli già presenti nelle fatture.' }}</p></div>
        </section>
    </AppLayout>
</template>

<style scoped>
.search-row { display: flex; gap: 8px; width: 100%; max-width: 520px; }
.search-row .input { flex: 1; }
</style>
