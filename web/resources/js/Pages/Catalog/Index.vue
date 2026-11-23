<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Catalogo: articoli e servizi, testi predefiniti (causale, descrizione riga), unità di misura. Le fatture copiano i valori. */
type Article = { id: number; code: string | null; description: string; unit: string | null; price: string; rate: string; natura: string | null; active: boolean };
type Snippet = { id: number; title: string; body: string; kind: 'causale' | 'riga' };
const props = defineProps<{ articles: Article[]; snippets: Snippet[]; ownUnits: { id: number; code: string; label: string | null }[]; defaultUnits: string[]; nature: { code: string; label: string }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/tabelle/catalogo`);

const af = useForm({ code: '', description: '', unit: 'pz', price: '', rate: '22', natura: '', active: true });
const editA = ref<number | null>(null);
const sf = useForm({ title: '', body: '', kind: 'riga' as 'causale' | 'riga' });
const editS = ref<number | null>(null);
const uf = useForm({ code: '', label: '' });
const reset = () => { af.reset(); af.clearErrors(); editA.value = null; };
const resetS = () => { sf.reset(); sf.clearErrors(); editS.value = null; };
const edit = (a: Article) => { editA.value = a.id; Object.assign(af, { code: a.code ?? '', description: a.description, unit: a.unit ?? '', price: a.price, rate: a.rate, natura: a.natura ?? '', active: a.active }); window.scrollTo({ top: 0, behavior: 'smooth' }); };
const editSn = (s: Snippet) => { editS.value = s.id; Object.assign(sf, { title: s.title, body: s.body, kind: s.kind }); };
const saveArticle = () => (editA.value ? af.put(`${base.value}/articoli/${editA.value}`, { preserveScroll: true, onSuccess: reset }) : af.post(`${base.value}/articoli`, { preserveScroll: true, onSuccess: reset }));
const saveSnippet = () => (editS.value ? sf.put(`${base.value}/testi/${editS.value}`, { preserveScroll: true, onSuccess: resetS }) : sf.post(`${base.value}/testi`, { preserveScroll: true, onSuccess: resetS }));
const del = (url: string, what: string) => { if (confirm(`Eliminare ${what}? Le fatture già emesse non cambiano.`)) router.delete(url, { preserveScroll: true }); };
const onNatura = () => { if (af.natura) af.rate = '0'; else if (af.rate === '0') af.rate = '22'; };
</script>

<template>
    <Head title="Articoli e testi" />
    <AppLayout crumb="Articoli e testi">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni</p>
                <h1>Articoli, testi e unità</h1>
                <p>Compili le fatture più in fretta: scegli un articolo per riempire descrizione, unità, prezzo e IVA; inserisci un testo predefinito nella causale o nella riga.</p>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head"><div><h2>Articoli e servizi</h2><p>Prezzo senza IVA. Per righe senza IVA scegli la natura.</p></div></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="saveArticle">
                <div class="field"><label for="ca-code">Codice (facoltativo)</label><input id="ca-code" v-model="af.code" class="input" maxlength="30" :aria-invalid="af.errors.code ? true : undefined"><p v-if="af.errors.code" class="error">{{ af.errors.code }}</p></div>
                <div class="field span2"><label for="ca-desc">Descrizione</label><input id="ca-desc" v-model="af.description" class="input" maxlength="1000" :aria-invalid="af.errors.description ? true : undefined"><p v-if="af.errors.description" class="error">{{ af.errors.description }}</p></div>
                <div class="field"><label for="ca-unit">Unità</label><input id="ca-unit" v-model="af.unit" class="input" maxlength="10" list="ca-units"><datalist id="ca-units"><option v-for="u in [...defaultUnits, ...ownUnits.map((x) => x.code)]" :key="u" :value="u" /></datalist></div>
                <div class="field"><label for="ca-price">Prezzo</label><input id="ca-price" v-model="af.price" class="input tabular" inputmode="decimal" placeholder="0,00" style="text-align: right" :aria-invalid="af.errors.price ? true : undefined"><p v-if="af.errors.price" class="error">{{ af.errors.price }}</p></div>
                <div class="field"><label for="ca-rate">Aliquota %</label><input id="ca-rate" v-model="af.rate" class="input tabular" inputmode="decimal" :disabled="!!af.natura" :aria-invalid="af.errors.rate ? true : undefined"><p v-if="af.errors.rate" class="error">{{ af.errors.rate }}</p></div>
                <div class="field span2"><label for="ca-nat">Natura (se senza IVA)</label><select id="ca-nat" v-model="af.natura" class="input" @change="onNatura"><option value="">— (imponibile)</option><option v-for="n in nature" :key="n.code" :value="n.code">{{ n.code }} · {{ n.label }}</option></select><p v-if="af.errors.natura" class="error">{{ af.errors.natura }}</p></div>
                <label class="check"><input v-model="af.active" type="checkbox">Attivo (proposto nelle fatture)</label>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="af.processing"><Icon name="check" />{{ editA ? 'Salva modifiche' : 'Aggiungi articolo' }}</button><button v-if="editA" class="btn" type="button" @click="reset">Annulla</button></div>
            </form>
            <div v-if="articles.length" class="table-wrap">
                <table>
                    <thead><tr><th>Codice</th><th>Descrizione</th><th>Unità</th><th class="num">Prezzo</th><th>IVA</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="a in articles" :key="a.id"><td class="tabular">{{ a.code }}</td><td>{{ a.description }}<span v-if="!a.active" class="badge"> non attivo</span></td><td>{{ a.unit }}</td><td class="num tabular">{{ a.price }}</td><td>{{ a.natura ?? `${a.rate}%` }}</td>
                            <td class="num"><button class="btn link" type="button" @click="edit(a)">Modifica</button> <button class="btn link" type="button" @click="del(`${base}/articoli/${a.id}`, 'questo articolo')">Elimina</button></td></tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessun articolo. Aggiungine uno sopra.</p>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h2>Testi predefiniti</h2><p>Frasi ricorrenti: per la causale del documento o per la descrizione di una riga (es. riferimenti normativi).</p></div></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="saveSnippet">
                <div class="field"><label for="cs-title">Titolo</label><input id="cs-title" v-model="sf.title" class="input" maxlength="80" :aria-invalid="sf.errors.title ? true : undefined"><p v-if="sf.errors.title" class="error">{{ sf.errors.title }}</p></div>
                <div class="field"><label for="cs-kind">Uso</label><select id="cs-kind" v-model="sf.kind" class="input"><option value="riga">Descrizione riga</option><option value="causale">Causale</option></select></div>
                <div class="field span3"><label for="cs-body">Testo</label><textarea id="cs-body" v-model="sf.body" class="input" rows="2" maxlength="1000" :aria-invalid="sf.errors.body ? true : undefined" /><p v-if="sf.errors.body" class="error">{{ sf.errors.body }}</p></div>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="sf.processing"><Icon name="check" />{{ editS ? 'Salva modifiche' : 'Aggiungi testo' }}</button><button v-if="editS" class="btn" type="button" @click="resetS">Annulla</button></div>
            </form>
            <div v-if="snippets.length" class="table-wrap">
                <table>
                    <thead><tr><th>Titolo</th><th>Uso</th><th>Testo</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody><tr v-for="s in snippets" :key="s.id"><td>{{ s.title }}</td><td>{{ s.kind === 'causale' ? 'Causale' : 'Riga' }}</td><td>{{ s.body }}</td>
                        <td class="num"><button class="btn link" type="button" @click="editSn(s)">Modifica</button> <button class="btn link" type="button" @click="del(`${base}/testi/${s.id}`, 'questo testo')">Elimina</button></td></tr></tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h2>Unità di misura</h2><p>Standard: {{ defaultUnits.join(', ') }}. Aggiungi le tue.</p></div></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="uf.post(`${base}/unita`, { preserveScroll: true, onSuccess: () => uf.reset() })">
                <div class="field"><label for="cu-code">Sigla</label><input id="cu-code" v-model="uf.code" class="input" maxlength="10" :aria-invalid="uf.errors.code ? true : undefined"><p v-if="uf.errors.code" class="error">{{ uf.errors.code }}</p></div>
                <div class="field span2"><label for="cu-label">Nome (facoltativo)</label><input id="cu-label" v-model="uf.label" class="input" maxlength="60"></div>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="uf.processing"><Icon name="plus" />Aggiungi unità</button></div>
            </form>
            <div v-if="ownUnits.length" class="table-wrap">
                <table><thead><tr><th>Sigla</th><th>Nome</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody><tr v-for="u in ownUnits" :key="u.id"><td class="tabular">{{ u.code }}</td><td>{{ u.label }}</td><td class="num"><button class="btn link" type="button" @click="del(`${base}/unita/${u.id}`, `l’unità ${u.code}`)">Elimina</button></td></tr></tbody></table>
            </div>
        </section>
    </AppLayout>
</template>
