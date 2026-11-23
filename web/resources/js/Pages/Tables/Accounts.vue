<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

type Row = {
    id: number; code: string; name: string; type: 'costo' | 'ricavo' | 'patrimoniale'; active: boolean; is_default: boolean; used: boolean; source: string;
    sezione: string | null; voce_cee: string | null; categoria: string | null; iva_rilevante: boolean; iva_detraibile_pct: string | null; iva_nota: string | null;
    deducibilita_pct: string | null; deducibilita_nota: string | null; irap_nota: string | null; limite: string | null; normativa: string | null; note: string | null;
};
type Model = { key: string; label: string; available: boolean; size: number };
const props = defineProps<{ accounts: Row[]; cee: Record<string, string>; sezioni: Record<string, string>; sezioniGroups: Record<string, string[]>; models: Model[]; currentModel: string }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);

const q = ref('');
const type = ref<'tutti' | 'costo' | 'ricavo'>('tutti');
const onlyIva = ref(false);
const showInactive = ref(true);
// Chiavi numeriche (20, 21) stanno in fondo come nello schema art. 2425 c.c. (JavaScript le mette davanti).
const ceeOrder = computed(() => Object.keys(props.cee).sort((x, y) => Number(/^\d+$/.test(x)) - Number(/^\d+$/.test(y))));
const pct = (v: string | null) => (v == null ? '' : `${String(Number(v)).replace('.', ',')}%`);
const visible = computed(() => {
    const s = q.value.trim().toLowerCase();
    return props.accounts.filter((a) => (type.value === 'tutti' || a.type === type.value) && (!onlyIva.value || a.iva_rilevante) && (showInactive.value || a.active)
        && (!s || a.code.toLowerCase().includes(s) || a.name.toLowerCase().includes(s) || (a.categoria ?? '').toLowerCase().includes(s)));
});
// Gruppi per Sezione (contabilità semplificata); conti senza Sezione per Voce CEE.
const sezOrder = computed(() => Object.keys(props.sezioni));
const groups = computed(() => {
    const m = new Map<string, Row[]>();
    for (const a of visible.value) { const k = a.sezione ? `s:${a.sezione}` : a.voce_cee ? `c:${a.voce_cee}` : ''; m.set(k, [...(m.get(k) ?? []), a]); }
    const idx = (k: string) => (k.startsWith('s:') ? sezOrder.value.indexOf(k.slice(2)) : k.startsWith('c:') ? 100 + ceeOrder.value.indexOf(k.slice(2)) : 999);
    const label = (k: string) => (k.startsWith('s:') ? props.sezioni[k.slice(2)] ?? k.slice(2) : k.startsWith('c:') ? `${k.slice(2)} · ${props.cee[k.slice(2)] ?? ''}` : 'Senza sezione');
    return [...m.entries()].sort((x, y) => idx(x[0]) - idx(y[0])).map(([k, rows]) => ({ key: k, label: label(k), rows: rows.sort((a, b) => a.code.localeCompare(b.code)) }));
});
// Conti in vendita che non concorrono al reddito (es. contributo integrativo casse private): solo ai fini IVA.
const typeLabel = (a: Row) => (a.sezione === 'non_reddito' ? 'Vendite · solo IVA, non è un ricavo' : a.type === 'costo' ? 'Costo' : a.type === 'patrimoniale' ? 'Stato patrimoniale (cespiti)' : 'Ricavo');
const counts = computed(() => ({ all: props.accounts.length, iva: props.accounts.filter((a) => a.iva_rilevante && a.active).length }));

// Modifica in una finestra di dialogo: nuovo conto (id null) o esistente.
const editing = ref<Row | null>(null);
const blank = { code: '', name: '', type: 'costo' as 'costo' | 'ricavo', active: true, sezione: '' as string | null, voce_cee: '' as string | null, categoria: '', iva_rilevante: true, iva_detraibile_pct: '', iva_nota: '',
    deducibilita_pct: '', deducibilita_nota: '', irap_nota: '', limite: '', normativa: '', note: '' };
const form = useForm({ ...blank });
const isNew = ref(false);
function open(a: Row | null) {
    isNew.value = !a;
    editing.value = a;
    form.clearErrors();
    const src = a ? { ...a, iva_detraibile_pct: a.iva_detraibile_pct == null ? '' : String(Number(a.iva_detraibile_pct)), deducibilita_pct: a.deducibilita_pct == null ? '' : String(Number(a.deducibilita_pct)) } : null;
    Object.assign(form, { ...blank, ...(src ? Object.fromEntries(Object.keys(blank).map((k) => [k, (src as Record<string, unknown>)[k] ?? ''])) : {}) });
    if (!a) form.type = type.value === 'ricavo' ? 'ricavo' : 'costo';
    nextTick(() => document.getElementById(a ? 'acc-name' : 'acc-code')?.focus());
}
const close = () => { editing.value = null; isNew.value = false; };
const dialogOpen = computed(() => isNew.value || editing.value !== null);
const e = (k: string) => (form.errors as Record<string, string>)[k];
function save() {
    const opts = { preserveScroll: true, onSuccess: close };
    const data = (d: typeof blank) => ({ ...d, sezione: d.sezione || null, voce_cee: d.voce_cee || null });
    if (isNew.value) form.transform(data).post(`${base.value}/tabelle/piano-dei-conti`, opts);
    else form.transform(data).put(`${base.value}/tabelle/piano-dei-conti/${editing.value!.id}`, opts);
}
const act = (url: string, method: 'post' | 'delete') => router.visit(url, { method, preserveScroll: true, onSuccess: close });
const confirmDelete = ref(false);

const modelOpen = ref(false);
const removeOthers = ref(false);
const modelKey = ref(props.currentModel);
const ownModel = computed(() => props.models.find((m) => m.key === props.currentModel));
const chosenModel = computed(() => props.models.find((m) => m.key === modelKey.value));
const applyModel = () => router.post(`${base.value}/tabelle/piano-dei-conti/modello`, { remove_others: removeOthers.value, model: modelKey.value }, { preserveScroll: true, onSuccess: () => { modelOpen.value = false; } });
const flashErr = computed(() => { const er = page.props.errors as Record<string, string> | undefined; return er?.account ?? er?.model; });
</script>

<template>
    <Head title="Piano dei conti" />
    <AppLayout crumb="Tabelle · Piano dei conti">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni · Tabelle</p>
                <h1>Piano dei conti</h1>
                <p>Modello di riferimento: {{ ownModel?.label }}. Conti con sezioni e impostazioni fiscali. Nella registrazione si scelgono solo i conti attivi e rilevanti ai fini IVA; la % IVA detraibile del conto viene proposta e va verificata caso per caso.</p>
            </div>
            <div class="actions">
                <button class="btn" type="button" @click="modelOpen = true">Applica modello</button>
                <button class="btn primary" type="button" @click="open(null)"><Icon name="plus" />Nuovo conto</button>
            </div>
        </div>

        <p v-if="flashErr" class="notice bad" role="alert" style="margin-bottom: 16px"><Icon name="alert" style="width: 20px; flex: none" /><span>{{ flashErr }}</span></p>

        <section class="panel">
            <div class="panel-body acc-filters">
                <label class="sr-only" for="acc-q">Cerca</label>
                <input id="acc-q" v-model="q" class="input" type="search" placeholder="Codice, descrizione o categoria">
                <label class="sr-only" for="acc-type">Tipo</label>
                <select id="acc-type" v-model="type" class="input"><option value="tutti">Costi e ricavi</option><option value="costo">Solo costi</option><option value="ricavo">Solo ricavi</option><option value="patrimoniale">Stato patrimoniale</option></select>
                <label class="check"><input v-model="onlyIva" type="checkbox">Solo rilevanti IVA</label>
                <label class="check"><input v-model="showInactive" type="checkbox">Mostra disattivati</label>
                <span class="small muted">{{ visible.length }} di {{ counts.all }} conti · {{ counts.iva }} usabili in registrazione</span>
            </div>
            <div class="table-wrap">
                <table class="accounts-table">
                    <thead><tr><th>Codice</th><th>Descrizione</th><th>IVA</th><th>Deducibilità</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody v-for="g in groups" :key="g.key">
                        <tr class="group-row"><th colspan="5" scope="colgroup">{{ g.label }}</th></tr>
                        <tr v-for="a in g.rows" :key="a.id" :class="{ inactive: !a.active }">
                            <td class="tabular nowrap"><strong>{{ a.code }}</strong></td>
                            <td>
                                {{ a.name }}
                                <span v-if="a.is_default" class="badge good">Predefinito</span>
                                <span v-if="!a.active" class="badge">Disattivato</span>
                                <span v-if="a.used" class="badge info">In uso</span>
                                <br><span class="small muted">{{ typeLabel(a) }}<template v-if="a.categoria"> · {{ a.categoria }}</template></span>
                            </td>
                            <td>
                                <span v-if="!a.iva_rilevante" class="small muted">Fuori campo</span>
                                <template v-else><strong v-if="a.iva_detraibile_pct != null" class="tabular">{{ pct(a.iva_detraibile_pct) }}</strong><span v-else-if="a.type === 'costo'" class="tabular">100%</span><br v-if="a.type === 'costo'"><span class="small muted">{{ a.iva_nota }}</span></template>
                            </td>
                            <td><strong v-if="a.deducibilita_pct != null" class="tabular">{{ pct(a.deducibilita_pct) }}</strong><br v-if="a.deducibilita_pct != null"><span class="small muted">{{ a.deducibilita_nota }}</span></td>
                            <td class="nowrap"><button class="btn link" type="button" @click="open(a)">Modifica</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="!visible.length" class="panel-body muted">Nessun conto trovato.</p>
            </div>
        </section>
        <p class="hint" style="margin-top: 12px">Indicazioni fiscali del modello: percentuali indicative (novembre 2026), non sono formule di calcolo. Valuta inerenza, documentazione, limiti e regime del soggetto. Vedi anche <a :href="`${base}/tabelle/parametri-fiscali`">Parametri fiscali</a>.</p>

        <div v-if="dialogOpen" class="dialog-wrap" @click.self="close">
            <form class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="acc-title" @submit.prevent="save" @keydown.esc="close">
                <h2 id="acc-title">{{ isNew ? 'Nuovo conto' : `Conto ${editing!.code}` }}</h2>
                <div class="acc-grid">
                    <div class="field"><label for="acc-code">Codice</label><input id="acc-code" v-model="form.code" class="input tabular" :aria-invalid="e('code') ? true : undefined"><p v-if="e('code')" class="error">{{ e('code') }}</p></div>
                    <div class="field span2"><label for="acc-name">Descrizione</label><input id="acc-name" v-model="form.name" class="input" :aria-invalid="e('name') ? true : undefined"><p v-if="e('name')" class="error">{{ e('name') }}</p></div>
                    <div class="field"><label for="acc-tipo">Tipo</label><select id="acc-tipo" v-model="form.type" class="input" :disabled="!isNew && editing!.used"><option value="costo">Costo</option><option value="ricavo">Ricavo</option><option value="patrimoniale">Stato patrimoniale</option></select><p v-if="e('type')" class="error">{{ e('type') }}</p></div>
                    <div class="field span2"><label for="acc-sez">Sezione</label><select id="acc-sez" v-model="form.sezione" class="input" :aria-invalid="e('sezione') ? true : undefined"><option value="">—</option><optgroup v-for="(keys, g) in sezioniGroups" :key="g" :label="String(g)"><option v-for="k in keys" :key="k" :value="k">{{ sezioni[k] }}</option></optgroup></select></div>
                    <div class="field"><label for="acc-cat">Categoria</label><input id="acc-cat" v-model="form.categoria" class="input" placeholder="es. Veicoli"></div>
                    <div class="field span2"><label for="acc-cee">Voce CEE (per la contabilità ordinaria)</label><select id="acc-cee" v-model="form.voce_cee" class="input"><option value="">—</option><option v-for="k in ceeOrder" :key="k" :value="k">{{ k }} · {{ cee[k] }}</option></select></div>
                    <label class="check" style="align-self: end"><input v-model="form.active" type="checkbox">Conto attivo</label>
                </div>
                <p v-if="e('active')" class="error">{{ e('active') }}</p>

                <h3 class="acc-sub">Impostazioni fiscali</h3>
                <div class="acc-grid">
                    <label class="check span3"><input v-model="form.iva_rilevante" type="checkbox">Rilevante ai fini IVA (usabile in registrazione)</label>
                    <div v-if="form.type !== 'ricavo'" class="field"><label for="acc-ivap">% IVA detraibile proposta</label><input id="acc-ivap" v-model="form.iva_detraibile_pct" class="input tabular" inputmode="decimal" placeholder="100" :disabled="!form.iva_rilevante" :aria-invalid="e('iva_detraibile_pct') ? true : undefined"><p v-if="e('iva_detraibile_pct')" class="error">{{ e('iva_detraibile_pct') }}</p><p class="hint">Vuoto = 100%.</p></div>
                    <div class="field" :class="form.type !== 'ricavo' ? 'span2' : 'span3'"><label for="acc-ivan">Indicazione IVA</label><input id="acc-ivan" v-model="form.iva_nota" class="input" placeholder="es. Generalmente 40%"></div>
                    <div class="field"><label for="acc-dedp">% deducibilità redditi</label><input id="acc-dedp" v-model="form.deducibilita_pct" class="input tabular" inputmode="decimal" :aria-invalid="e('deducibilita_pct') ? true : undefined"><p v-if="e('deducibilita_pct')" class="error">{{ e('deducibilita_pct') }}</p></div>
                    <div class="field span2"><label for="acc-dedn">Indicazione deducibilità</label><input id="acc-dedn" v-model="form.deducibilita_nota" class="input" placeholder="es. 80%"></div>
                    <div class="field span3"><label for="acc-irap">IRAP</label><input id="acc-irap" v-model="form.irap_nota" class="input" placeholder="es. In genere rilevante"></div>
                    <div class="field span3"><label for="acc-lim">Limite / soglia</label><input id="acc-lim" v-model="form.limite" class="input"></div>
                    <div class="field span3"><label for="acc-norm">Normativa</label><input id="acc-norm" v-model="form.normativa" class="input" placeholder="es. Art. 164 TUIR"></div>
                    <div class="field span3"><label for="acc-note">Note operative</label><textarea id="acc-note" v-model="form.note" class="input" rows="2"></textarea></div>
                </div>
                <p class="hint">La % IVA proposta precompila la registrazione; la % di deducibilità serve per i dati della dichiarazione nei prossimi rilasci.</p>

                <div v-if="confirmDelete" class="notice bad"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Eliminare il conto {{ editing!.code }}?</strong><div class="actions" style="margin-top: 8px"><button class="btn danger" type="button" @click="act(`${base}/tabelle/piano-dei-conti/${editing!.id}`, 'delete'); confirmDelete = false">Elimina</button><button class="btn" type="button" @click="confirmDelete = false">Annulla</button></div></div></div>
                <div class="actions">
                    <button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Salva</button>
                    <button class="btn" type="button" @click="close">Annulla</button>
                    <span style="flex: 1" />
                    <button v-if="editing && !editing.is_default && editing.active && editing.iva_rilevante" class="btn link" type="button" @click="act(`${base}/tabelle/piano-dei-conti/${editing.id}/predefinito`, 'post')">Imposta come predefinito</button>
                    <button v-if="editing && !editing.used && !editing.is_default" class="btn link" type="button" style="color: var(--ct-error)" @click="confirmDelete = true">Elimina</button>
                </div>
            </form>
        </div>

        <div v-if="modelOpen" class="dialog-wrap" @click.self="modelOpen = false">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="mod-title" @submit.prevent="applyModel">
                <h2 id="mod-title">Applicare un modello?</h2>
                <fieldset class="field"><legend>Modello</legend>
                    <label v-for="m in models" :key="m.key" class="check"><input v-model="modelKey" type="radio" :value="m.key" :disabled="!m.available">{{ m.label }} · {{ m.size }} conti<span v-if="!m.available" class="small muted">&nbsp;(salvato: disponibile quando Semplicon gestirà la contabilità ordinaria)</span></label>
                </fieldset>
                <p class="muted">Aggiunge i conti mancanti di “{{ chosenModel?.label }}”, con sezione e impostazioni fiscali. I conti esistenti restano invariati.</p>
                <label class="check"><input v-model="removeOthers" type="checkbox">Elimina i conti che non fanno parte del modello e non sono usati in registrazioni</label>
                <div class="actions"><button class="btn primary" type="submit" :disabled="!chosenModel?.available">Applica modello</button><button class="btn" type="button" @click="modelOpen = false">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>
