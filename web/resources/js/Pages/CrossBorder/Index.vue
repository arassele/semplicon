<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, dateTime, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Sezione 7: lista di controllo delle operazioni transfrontaliere. La generazione e il download dell’XML non generano registrazioni. */
type Row = {
    id: number; direction: 'acquisto' | 'vendita'; number: string; doc_date: string; doc_type: string; xml_doc_type: string; partner: string; partner_vat: string | null; country: string;
    taxable: string; currency: string; kind: string | null; relevant: boolean | null; customs: boolean; sdi: boolean; source: string | null;
    integration: { number: string; doc_type: string; tax: string } | null; trigger: string; trigger_label: string; deadline: string; rule: string; overdue: boolean;
    exclusion: string | null; state: string;
    xml: { status: string; version: number; filename: string; generated_at: string; downloaded_at: string | null; downloads: number; id: number } | null;
};
type XmlError = { field: string; label: string; message: string; link: string };
const props = defineProps<{ rows: Row[]; anno: string; years: number[]; kinds: Record<string, string>; xmlErrors: XmlError[] | null; xmlErrorsFor: number | null }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const opts = { preserveScroll: true };
const setYear = (y: string) => router.get(`${base.value}/operazioni-transfrontaliere`, { anno: y }, { preserveState: true });

const STATE: Record<string, [string, string]> = {
    integrata: ['Integrazione registrata', 'good'], integrazione_mancante: ['Integrazione mancante', 'warn'], non_rilevante: ['Non rilevante in Italia', ''],
    escluso: ['Escluso', ''], da_trasmettere: ['Da trasmettere', 'warn'],
};
const purchases = computed(() => props.rows.filter((r) => r.direction === 'acquisto'));
const sales = computed(() => props.rows.filter((r) => r.direction === 'vendita'));
const sections = computed(() => [
    { key: 'acquisti', title: 'Acquisti dall’estero', hint: 'TD17 servizi, TD18 beni UE, TD19 beni già in Italia. Scadenza: 15 del mese successivo alla ricezione.', rows: purchases.value },
    { key: 'vendite', title: 'Vendite a soggetti esteri', hint: 'Fattura con CodiceDestinatario XXXXXXX. Scadenza: termine di emissione della fattura.', rows: sales.value },
]);

const editing = ref<Row | null>(null);
const cls = ref({ kind: '' as string, relevant: 'si' as 'si' | 'no', customs: false });
function startEdit(r: Row) { editing.value = r; cls.value = { kind: r.kind ?? '', relevant: r.relevant === false ? 'no' : 'si', customs: r.customs }; }
const saveEdit = () => router.post(`${base.value}/operazioni-transfrontaliere/${editing.value!.id}/classifica`,
    { kind: cls.value.kind || null, relevant: cls.value.relevant === 'si', customs: cls.value.customs }, { ...opts, onSuccess: () => (editing.value = null) });
const generate = (r: Row) => router.post(`${base.value}/operazioni-transfrontaliere/${r.id}/xml`, {}, opts);
const canGenerate = (r: Row) => !r.exclusion && (r.direction === 'vendita' || r.state === 'integrata' || r.state === 'non_rilevante');
const err = computed(() => (page.props.errors as Record<string, string>).xml);
</script>

<template>
    <Head title="Operazioni transfrontaliere" />
    <AppLayout crumb="Operazioni transfrontaliere">
        <div class="page-head">
            <div>
                <p class="eyebrow">IVA</p>
                <h1>Operazioni transfrontaliere</h1>
                <p>Elenco di controllo delle operazioni con controparti estere: documento, scadenza, motivo di esclusione e stato dell’XML. Generare o scaricare l’XML non crea registrazioni.</p>
            </div>
            <div class="actions">
                <label class="small muted" for="xb-year">Anno</label>
                    <select id="xb-year" class="input" style="max-width: 120px" :value="anno" @change="setYear(($event.target as HTMLSelectElement).value)"><option value="tutti">Tutti</option><option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option></select>
            </div>
        </div>

        <div v-if="err" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ err }}</div></div>
        <div v-if="xmlErrors?.length" class="notice bad" role="alert">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>XML non generato: correggi i dati indicati</strong>
                <ul class="plain small"><li v-for="(e, i) in xmlErrors" :key="i"><strong>{{ e.label }}</strong> · {{ e.message }} <Link :href="e.link">Vai al campo</Link></li></ul></div>
        </div>

        <section v-for="s in sections" :key="s.key" class="panel" style="margin-top: 16px">
            <div class="panel-head"><div><h2>{{ s.title }}</h2><p>{{ s.hint }}</p></div></div>
            <div v-if="!s.rows.length" class="panel-body muted">Nessuna operazione nell’anno selezionato.</div>
            <div v-else class="table-wrap">
                <table class="data">
                    <thead><tr><th>Documento originale</th><th>Controparte</th><th>Tipo XML</th><th class="num">Imponibile</th><th>Scadenza</th><th>Stato</th><th>XML</th></tr></thead>
                    <tbody>
                        <tr v-for="r in s.rows" :key="r.id" :class="{ highlight: xmlErrorsFor === r.id }">
                            <td><Link :href="`${base}/fatture/${r.id}`">{{ r.number }}</Link><div class="small muted">del {{ date(r.doc_date) }}<template v-if="r.currency !== 'EUR'"> · {{ r.currency }}</template></div></td>
                            <td>{{ r.partner }}<div class="small muted">{{ r.country }}<template v-if="r.partner_vat"> · {{ r.partner_vat }}</template></div></td>
                            <td>{{ r.xml_doc_type }}<div v-if="r.integration" class="small muted">integr. {{ r.integration.number }} · IVA {{ money(r.integration.tax) }}</div><div v-else-if="r.relevant === false" class="small muted">natura N2.2</div></td>
                            <td class="num tabular">{{ money(r.taxable) }}</td>
                            <td><template v-if="!r.exclusion"><span :class="{ 'text-bad': r.overdue }">{{ date(r.deadline) }}</span><div class="small muted">{{ r.trigger_label }} {{ date(r.trigger) }}</div></template><span v-else class="muted">—</span></td>
                            <td><span class="badge" :class="STATE[r.state]?.[1]">{{ STATE[r.state]?.[0] ?? r.state }}</span><div v-if="r.exclusion" class="small muted">{{ r.exclusion }}</div></td>
                            <td style="min-width: 180px">
                                <template v-if="r.xml"><span class="badge good">{{ r.xml.status }}</span><div class="small muted">v{{ r.xml.version }} · {{ dateTime(r.xml.generated_at) }}<template v-if="r.xml.downloaded_at"> · scaricato {{ dateTime(r.xml.downloaded_at) }} ({{ r.xml.downloads }}×)</template></div></template>
                                <span v-else class="muted">—</span>
                                <div class="xb-actions">
                                    <Link v-if="r.state === 'integrazione_mancante'" :href="`${base}/fatture/${r.id}/integra-iva`" class="btn link">Integra IVA</Link>
                                    <button v-if="canGenerate(r)" class="btn link" type="button" @click="generate(r)">{{ r.xml ? 'Rigenera XML' : 'Genera XML' }}</button>
                                    <a v-if="r.xml" :href="`${base}/operazioni-transfrontaliere/xml/${r.xml.id}`" class="btn link">Scarica</a>
                                    <button v-if="r.direction === 'acquisto'" class="btn link" type="button" @click="startEdit(r)">Classifica</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div v-if="editing" class="dialog-wrap" @click.self="editing = null">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="cls-title">
                <h2 id="cls-title">Classifica l’acquisto {{ editing.number }}</h2>
                <p class="small muted">La classificazione determina il tipo di XML e le esclusioni; non modifica registrazioni.</p>
                <div class="form-grid">
                    <label>Tipo di operazione<select class="input" v-model="cls.kind"><option value="">—</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></label>
                    <label>Rilevante in Italia<select class="input" v-model="cls.relevant"><option value="si">Sì: IVA italiana con integrazione</option><option value="no">No: fuori campo, XML con natura N2.2</option></select></label>
                    <label class="check"><input v-model="cls.customs" type="checkbox"> <span>Bolletta doganale presente</span></label>
                </div>
                <div class="actions" style="margin-top: 16px"><button class="btn primary" type="button" @click="saveEdit">Salva</button><button class="btn" type="button" @click="editing = null">Annulla</button></div>
            </div>
        </div>
    </AppLayout>
</template>
