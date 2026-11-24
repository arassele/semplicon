<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import AssetMovementForm from '@/Components/AssetMovementForm.vue';
import { date, dateTime, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Scheda cespite: Dati generali, Valori e aliquote, Piano, Movimenti (con anteprima della registrazione), Contabilità, Documenti, Storico modifiche. */
type PlanRow = Record<string, string | number | boolean | null | string[]> & { year: number; fixed: boolean; warnings: string[] };
type Mov = Record<string, unknown> & { id: number; type: string; type_label: string; movement_date: string; status: string; causale: string; amount: string | null; gain_civil: string | null; user: string | null };
const props = defineProps<{ year: number; asset: Record<string, any>; snapshot: Record<string, string>; plan: PlanRow[]; movements: Mov[]; journal: { id: number; number: string; date: string; description: string; status: string; lines: { account: string; dare: string; avere: string }[] }[];
    documents: { id: number; name: string; label: string | null }[]; audit: { at: string; action: string; user: string | null; reason: string | null; data: unknown }[]; types: Record<string, string>;
    categories: { id: number; code: string; name: string; kind: string }[]; accounts: { id: number; code: string; name: string }[]; statuses: Record<string, string>; methods: Record<string, string>; roles: Record<string, string> }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}/cespiti`);
const url = computed(() => `${base.value}/${props.asset.id}`);
const tabs = { dati: 'Dati generali', valori: 'Valori e aliquote', piano: 'Piano', movimenti: 'Movimenti', contabilita: 'Contabilità', documenti: 'Documenti', storico: 'Storico modifiche' } as const;
const tab = ref<keyof typeof tabs>(location.hash === '#movimenti' ? 'movimenti' : 'dati');
const acc = (id: unknown) => { const x = props.accounts.find((a) => a.id === id); return x ? `${x.code} ${x.name}` : null; };
const cat = computed(() => props.asset.category);
const planShown = computed(() => props.plan.filter((r, i) => r.fixed || i < 60));

const rateForm = useForm({ from_year: props.year + 1, civil_rate: String(cat.value?.civil_rate ?? cat.value?.fiscal_rate ?? ''), fiscal_rate: String(cat.value?.fiscal_rate ?? ''), reason: '', manual_civil: '', manual_fiscal: '' });
// Il civilistico segue sempre le regole fiscali (richieste del 24.11.2026)
watch(() => [rateForm.fiscal_rate, rateForm.manual_fiscal], () => { rateForm.civil_rate = rateForm.fiscal_rate; rateForm.manual_civil = rateForm.manual_fiscal; }, { immediate: true });
const excl = useForm({ excluded: !props.asset.excluded, reason: '' });

const reverse = (m: Mov) => { const reason = prompt(`Motivo dello storno di “${m.type_label}” del ${date(m.movement_date)}?`); if (reason) router.post(`${base.value}/movimenti/${m.id}/storno`, { reason }, { preserveScroll: true }); };
const docForm = useForm<{ file: File | null; label: string }>({ file: null, label: '' });
const tone: Record<string, string> = { attivo: 'good', ammortizzato: 'info', sospeso: 'warn' };
</script>

<template>
    <Head :title="`Cespite ${asset.code}`" />
    <AppLayout crumb="Cespiti">
        <div class="page-head">
            <div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>{{ asset.code }} · {{ asset.description }}</h1>
                <p>{{ cat?.name }} · {{ asset.kind }} · <span class="badge" :class="tone[asset.status]">{{ statuses[asset.status] }}</span><template v-if="asset.excluded"> · escluso dal calcolo: {{ asset.excluded_reason }}</template></p></div>
            <div class="actions">
                <button class="btn primary" type="button" title="Vendita, eliminazione, rivalutazione, svalutazione, variazioni…" @click="tab = 'movimenti'"><Icon name="plus" />Nuovo movimento</button>
                <Link class="btn" :href="`${url}/modifica`"><Icon name="doc" />Modifica</Link>
                <Link class="btn" :href="`${url}/duplica`" method="post" as="button"><Icon name="layers" />Duplica</Link>
                <a class="btn" :href="`${base}/stampe/scheda.pdf?asset=${asset.id}`"><Icon name="download" />Scheda PDF</a>
            </div>
        </div>
        <dl class="facts panel panel-body" style="margin-bottom: 16px">
            <div><dt>Costo al 31/12/{{ year }}</dt><dd class="tabular">{{ money(snapshot.cost_end) }}</dd></div>
            <div><dt>Fondo</dt><dd class="tabular">{{ money(snapshot.fund_end) }}</dd></div>
            <div><dt>Netto contabile</dt><dd class="tabular">{{ money(snapshot.net) }}</dd></div>
            <div><dt>Quota {{ year }}</dt><dd class="tabular">{{ money(snapshot.quota_civil) }}</dd></div>
            <div><dt>Quota deducibile {{ year }}</dt><dd class="tabular">{{ money(snapshot.quota_fiscal) }}</dd></div>
            <div><dt>Residuo fiscale</dt><dd class="tabular">{{ money(snapshot.net_fiscal) }}</dd></div>
        </dl>
        <nav class="segmented" aria-label="Sezioni" style="margin-bottom: 16px; flex-wrap: wrap">
            <button v-for="(l, k) in tabs" :key="k" type="button" class="seg-btn" :aria-pressed="tab === k" @click="tab = k">{{ l }}</button>
        </nav>

        <section v-if="tab === 'dati'" class="panel"><dl class="panel-body mi-grid">
            <div><dt class="small muted">Numero</dt><dd>{{ asset.number }}</dd></div><div><dt class="small muted">Sottocategoria</dt><dd>{{ asset.subcategory || '—' }}</dd></div><div><dt class="small muted">Ubicazione / centro di costo</dt><dd>{{ [asset.location, asset.cost_center].filter(Boolean).join(' · ') || '—' }}</dd></div>
            <div><dt class="small muted">Acquisto</dt><dd>{{ date(asset.purchase_date) }}</dd></div><div><dt class="small muted">Entrata in funzione</dt><dd>{{ date(asset.in_service_date) }}</dd></div><div><dt class="small muted">Inizio ammortamento</dt><dd>{{ date(asset.depreciation_start) || asset.start_year }}</dd></div>
            <div><dt class="small muted">Fattura</dt><dd>{{ asset.invoice_number || '—' }} {{ asset.invoice_date ? `del ${date(asset.invoice_date)}` : '' }}<template v-if="asset.invoice"> · <Link :href="`/aziende/${page.props.currentCompany!.id}/fatture/${asset.invoice.id}`">apri in Semplicon</Link></template></dd></div>
            <div><dt class="small muted">Fornitore</dt><dd>{{ asset.supplier_name || '—' }}</dd></div><div><dt class="small muted">Matricola / serie</dt><dd>{{ [asset.matricola, asset.serial_number].filter(Boolean).join(' · ') || '—' }}</dd></div>
            <div class="span3"><dt class="small muted">Descrizione</dt><dd>{{ asset.description_long || asset.description }}</dd></div>
            <div v-if="asset.notes" class="span3"><dt class="small muted">Note</dt><dd>{{ asset.notes }}</dd></div>
        </dl></section>

        <template v-if="tab === 'valori'">
            <section class="panel" style="margin-bottom: 16px"><dl class="panel-body mi-grid">
                <div><dt class="small muted">Valore originario</dt><dd class="tabular">{{ money(asset.original_cost) }}</dd></div><div><dt class="small muted">Costi accessori</dt><dd class="tabular">{{ money(asset.accessory_costs) }}</dd></div><div><dt class="small muted">IVA indetraibile capitalizzata</dt><dd class="tabular">{{ money(asset.nondeductible_vat) }}</dd></div>
                <div><dt class="small muted">Contributi ({{ asset.grant_treatment }})</dt><dd class="tabular">{{ money(asset.grants) }}</dd></div><div><dt class="small muted">Costo civilistico</dt><dd class="tabular">{{ money(asset.cost_civil) }}</dd></div><div><dt class="small muted">Costo fiscale</dt><dd class="tabular">{{ money(asset.cost_fiscal) }}</dd></div>
                <div><dt class="small muted">Valore residuo stimato</dt><dd class="tabular">{{ money(asset.residual_value) }}</dd></div><div><dt class="small muted">Metodo</dt><dd>{{ methods[asset.method] }}<template v-if="asset.reduced_pct"> ({{ String(asset.reduced_pct).replace('.', ',') }}% dell’ordinaria)</template></dd></div>
                <div><dt class="small muted">Aliquote categoria</dt><dd>{{ String(cat?.fiscal_rate).replace('.', ',') }}% (civilistica = fiscale)<template v-if="cat?.rate"> · D.M. {{ String(cat.rate.coefficient).replace('.', ',') }}%</template></dd></div>
                <div v-if="asset.low_value"><dt class="small muted">Bene di modesto valore</dt><dd>deduzione integrale nell’esercizio</dd></div>
                <div v-if="asset.fiscal_cost_cap || asset.fiscal_deductible_pct"><dt class="small muted">Deducibilità limitata</dt><dd>limite {{ money(asset.fiscal_cost_cap) || '—' }} · {{ asset.fiscal_deductible_pct ? String(asset.fiscal_deductible_pct).replace('.', ',') + '%' : '—' }}</dd></div>
                <div v-if="asset.opening"><dt class="small muted">Progressivi al 31/12/{{ asset.opening.last_year }}</dt><dd class="tabular">fondo civ. {{ money(asset.opening.fund_civil) }} · fisc. {{ money(asset.opening.fund_fiscal) }}<template v-if="asset.opening.mode === 'storico'"> (import storico)</template></dd></div>
                <div v-if="asset.extra_deduction_pct"><dt class="small muted">Maggiorazione</dt><dd>{{ String(asset.extra_deduction_pct).replace('.', ',') }}% · {{ asset.extra_deduction_note }}</dd></div>
            </dl>
                <div v-if="asset.rates.length" class="table-wrap"><table class="data"><thead><tr><th>Dal</th><th class="num">Civilistica</th><th class="num">Fiscale</th><th class="num">Quota manuale</th><th>Motivo</th></tr></thead>
                    <tbody><tr v-for="r in asset.rates" :key="r.id"><td class="tabular">{{ r.from_year }}</td><td class="num tabular">{{ String(r.civil_rate).replace('.', ',') }}%</td><td class="num tabular">{{ String(r.fiscal_rate).replace('.', ',') }}%</td><td class="num tabular">{{ r.manual_civil ? money(r.manual_civil) : '' }}</td><td class="small">{{ r.reason }}</td></tr></tbody></table></div>
            </section>
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Modifica dal …</h2><p>Nuove aliquote (o quota manuale) solo per esercizi non ancora calcolati: il passato resta invariato.</p></div></div>
                <form class="panel-body mi-grid" @submit.prevent="rateForm.post(`${url}/aliquote`, { preserveScroll: true })">
                    <div class="field"><label for="r-y">Dall’esercizio</label><input id="r-y" v-model="rateForm.from_year" class="input tabular" inputmode="numeric"><p v-if="rateForm.errors.from_year" class="error">{{ rateForm.errors.from_year }}</p></div>
                    <div class="field"><label for="r-f">Aliquota % (civ. = fisc.)</label><input id="r-f" v-model="rateForm.fiscal_rate" class="input tabular"><p v-if="rateForm.errors.fiscal_rate" class="error">{{ rateForm.errors.fiscal_rate }}</p></div>
                    <template v-if="asset.method === 'manuale'"><div class="field"><label for="r-mf">Quota manuale (civ. = fisc.)</label><input id="r-mf" v-model="rateForm.manual_fiscal" class="input tabular"></div></template>
                    <div class="field span3"><label for="r-r">Motivo</label><input id="r-r" v-model="rateForm.reason" class="input"><p v-if="rateForm.errors.reason" class="error">{{ rateForm.errors.reason }}</p></div>
                    <div class="actions span3"><button class="btn primary" type="submit"><Icon name="check" />Salva</button></div>
                </form></section>
            <section class="panel"><div class="panel-head"><div><h2>{{ asset.excluded ? 'Escluso dal calcolo' : 'Escludi temporaneamente dal calcolo' }}</h2><p>Per beni sospesi o non utilizzati: nessuna quota finché l’esclusione è attiva.</p></div></div>
                <form class="panel-body mi-grid" @submit.prevent="excl.post(`${url}/esclusione`, { preserveScroll: true })">
                    <div v-if="!asset.excluded" class="field span2"><label for="x-r">Motivo</label><input id="x-r" v-model="excl.reason" class="input"><p v-if="(excl.errors as Record<string, string>).excluded_reason" class="error">{{ (excl.errors as Record<string, string>).excluded_reason }}</p></div>
                    <div class="actions"><button class="btn" type="submit">{{ asset.excluded ? 'Riattiva nel calcolo' : 'Escludi' }}</button></div>
                </form></section>
        </template>

        <section v-if="tab === 'piano'" class="panel"><div class="panel-head"><div><h2>Piano pluriennale</h2><p>Esercizi calcolati fissi; i successivi sono previsti e si ricalcolano con aliquote, movimenti e metodo.</p></div></div>
            <div class="table-wrap"><table class="data">
                <thead><tr><th>Esercizio</th><th class="num">Valore iniziale</th><th class="num">Aliquota</th><th class="num">Quota civilistica</th><th class="num">Quota fiscale</th><th class="num">Fondo iniziale</th><th class="num">Fondo finale</th><th class="num">Valore netto</th><th class="num">Residuo fiscale</th><th>Stato</th></tr></thead>
                <tbody><tr v-for="r in planShown" :key="r.year" :style="r.year === year ? 'background: var(--ct-selected)' : ''">
                    <td class="tabular">{{ r.year }}<span v-if="r.days" class="small muted"> ({{ r.days }} gg)</span></td><td class="num tabular">{{ money(String(r.base_civil)) }}</td><td class="num tabular">{{ String(r.civil_rate).replace('.', ',') }}%</td>
                    <td class="num tabular">{{ money(String(r.civil_amount)) }}</td><td class="num tabular">{{ money(String(r.fiscal_amount)) }}<div v-if="Number(r.nondeductible)" class="small muted">non ded. {{ money(String(r.nondeductible)) }}</div></td>
                    <td class="num tabular">{{ money(String(r.fund_civil_start)) }}</td><td class="num tabular">{{ money(String(r.fund_civil_end)) }}</td>
                    <td class="num tabular">{{ money((Number(r.base_civil) - Number(r.fund_civil_end)).toFixed(2)) }}</td><td class="num tabular">{{ money((Number(r.base_fiscal) - Number(r.fund_fiscal_end)).toFixed(2)) }}</td>
                    <td><span class="badge" :class="r.fixed ? 'good' : ''">{{ r.fixed ? (r.method === 'storico' ? 'storico' : 'calcolato') : 'previsto' }}</span><div v-for="(w, k) in r.warnings" :key="k" class="small text-warn">{{ w }}</div></td></tr></tbody>
            </table></div></section>

        <template v-if="tab === 'movimenti'">
            <AssetMovementForm v-if="!['venduto', 'eliminato'].includes(asset.status)" :asset-id="asset.id" :types="types" :categories="categories" />
            <section class="panel"><div class="panel-head"><h2>Storico movimenti</h2></div>
                <div class="table-wrap"><table class="data"><thead><tr><th>Data</th><th>Tipo</th><th class="num">Importo</th><th class="num">Risultato</th><th>Causale</th><th>Utente</th><th>Stato</th><th></th></tr></thead>
                    <tbody><tr v-for="m in movements" :key="m.id" :style="m.status === 'stornato' ? 'color: var(--ct-muted); text-decoration: line-through' : ''">
                        <td class="tabular">{{ date(m.movement_date) }}</td><td>{{ m.type_label }}</td><td class="num tabular">{{ m.amount ? money(m.amount) : '' }}</td><td class="num tabular">{{ m.gain_civil ? money(m.gain_civil) : '' }}</td>
                        <td class="small">{{ m.causale }}</td><td class="small">{{ m.user }}</td><td>{{ m.status }}</td>
                        <td><button v-if="m.status === 'registrato' && !['acquisizione', 'ammortamento'].includes(m.type)" class="btn link" type="button" @click="reverse(m)">Storna</button></td></tr></tbody></table></div></section>
        </template>

        <section v-if="tab === 'contabilita'" class="panel"><div class="panel-head"><div><h2>Conti e scritture</h2><p>Prima nota cespiti collegata a questo bene.</p></div></div>
            <dl class="panel-body mi-grid"><div v-for="(l, r) in roles" :key="r"><dt class="small muted">{{ l }}</dt><dd class="small">{{ acc(asset[`account_${r}_id`] ?? cat?.[`account_${r}_id`]) ?? '— non impostato' }}</dd></div></dl>
            <div v-for="e in journal" :key="e.id" class="panel-body" style="border-top: 1px solid var(--ct-border)">
                <p><strong class="tabular">{{ e.number }}</strong> · {{ date(e.date) }} · {{ e.description }} <span v-if="e.status === 'stornato'" class="badge">stornata</span></p>
                <table class="data"><tbody><tr v-for="(l, k) in e.lines" :key="k"><td>{{ l.account }}</td><td class="num tabular">{{ l.dare !== '0.00' ? money(l.dare) : '' }}</td><td class="num tabular">{{ l.avere !== '0.00' ? money(l.avere) : '' }}</td></tr></tbody></table>
            </div>
            <p v-if="!journal.length" class="panel-body muted small">Nessuna scrittura per questo cespite (le quote annuali si contabilizzano dal Calcolo ammortamenti).</p>
        </section>

        <section v-if="tab === 'documenti'" class="panel"><div class="panel-head"><h2>Documenti</h2></div>
            <ul v-if="documents.length" class="list" style="margin-top: 8px"><li v-for="d in documents" :key="d.id"><a :href="`/aziende/${page.props.currentCompany!.id}/documenti/${d.id}/scarica`">{{ d.name }}</a><span v-if="d.label" class="small muted"> · {{ d.label }}</span></li></ul>
            <form class="panel-body mi-grid" @submit.prevent="docForm.post(`${url}/documenti`, { forceFormData: true, preserveScroll: true, onSuccess: () => docForm.reset() })">
                <div class="field"><label for="d-f">File PDF, XML o immagine</label><input id="d-f" type="file" class="input" accept=".pdf,.xml,.jpg,.jpeg,.png" @change="docForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null"><p v-if="docForm.errors.file" class="error">{{ docForm.errors.file }}</p></div>
                <div class="field"><label for="d-l">Descrizione</label><input id="d-l" v-model="docForm.label" class="input" placeholder="es. fattura, perizia"></div>
                <div class="actions" style="align-self: end"><button class="btn" type="submit" :disabled="!docForm.file"><Icon name="upload" />Allega</button></div>
            </form></section>

        <section v-if="tab === 'storico'" class="panel"><div class="panel-head"><h2>Storico modifiche</h2></div>
            <ul class="list" style="margin-top: 8px"><li v-for="(e, k) in audit" :key="k"><div class="li-row"><span><strong>{{ e.action }}</strong> · {{ e.user }}<template v-if="e.reason"> · {{ e.reason }}</template></span><span class="small muted">{{ dateTime(e.at) }}</span></div>
                <pre v-if="e.data" class="small muted" style="white-space: pre-wrap; margin: 4px 0 0">{{ JSON.stringify(e.data) }}</pre></li></ul></section>
    </AppLayout>
</template>

<style scoped>
.seg-btn { padding: 8px 14px; border-radius: 7px; font-weight: 550; font-size: 14px; border: 0; background: none; cursor: pointer; color: inherit; }
.seg-btn[aria-pressed='true'] { background: var(--ct-selected); color: var(--ct-forest); }
.text-warn { color: #7a4b00; }
</style>
