<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { Credit, Movement } from '@/Components/Liquidazioni/types';
import type { SharedProps } from '@/types';

type Claim = { id: number; kind: string; year: number; quarter: number | null; status: string; status_label: string; refund: string; compensation: string; presupposto: string | null; presupposto_label: string | null;
    documentation: string | null; deadline: string | null; filed_at: string | null; protocol: string | null; outcome_at: string | null; outcome_notes: string | null; credit: string | null; guarantee: boolean };
const props = defineProps<{ anno: number | 'tutti'; years: number[]; available: string; credits: Credit[]; claims: Claim[]; annualPresupposti: Record<string, string>; today: string; year: number }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const opts = { preserveScroll: true };

const adding = ref(false);
const nform = useForm({ year: props.year - 1, amount: '', description: '', filed_at: '', visto: false, visto_exempt_limit: '' });
const saveNew = () => nform.post(`${base.value}/crediti-iva`, { ...opts, onSuccess: () => { adding.value = false; nform.reset(); } });

const filing = ref<Credit | null>(null);
const fform = useForm({ filed_at: '', visto: false });
function startFiling(c: Credit) { filing.value = c; fform.filed_at = c.filed_at ?? ''; fform.visto = c.visto; }
const saveFiling = () => fform.post(`${base.value}/crediti-iva/${filing.value!.id}/dichiarazione`, { ...opts, onSuccess: () => (filing.value = null) });

const dest = ref<Credit | null>(null);
const dform = useForm({ rows: [{ credit_id: 0, riporto: '', compensazione: '', rimborso: '' }], target_year: 0, target_period: 0, presupposto: '', documentation: '' });
function startDest(c: Credit) { dest.value = c; dform.clearErrors(); dform.rows = [{ credit_id: c.id, riporto: c.destinations.riporto.ok ? c.residual.replace('.', ',') : '', compensazione: '', rimborso: '' }]; dform.target_year = c.next.year; dform.target_period = c.next.period; }
const saveDest = () => dform.post(`${base.value}/crediti-iva/destinazione`, { ...opts, onSuccess: () => (dest.value = null) });
const de = (k: string) => (dform.errors as Record<string, string>)[k];

const release = ref<Movement | null>(null);
const rform = useForm({ reason: '' });
const saveRelease = () => rform.post(`${base.value}/crediti-iva/movimenti/${release.value!.id}/sblocca`, { ...opts, onSuccess: () => { release.value = null; rform.reset(); } });

const claimDialog = ref<{ c: Claim; action: 'presentata' | 'erogato' | 'respinto' } | null>(null);
const cform = useForm({ date: props.today, protocol: '', notes: '' });
const saveClaim = () => cform.post(`${base.value}/rimborsi/${claimDialog.value!.c.id}/${claimDialog.value!.action}`, { ...opts, onSuccess: () => { claimDialog.value = null; cform.reset(); } });
const mClass = (s: string) => ({ prenotata: 'info', utilizzata: 'good', sbloccata: '' } as Record<string, string>)[s] ?? '';
</script>

<template>
    <Head title="Registro crediti IVA" />
    <AppLayout crumb="Crediti IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow">Liquidazioni</p>
                <h1>Registro crediti IVA</h1>
                <p>Per ogni credito: origine, anno, periodo, importo maturato e movimenti. Il residuo si ricostruisce dai movimenti; ogni utilizzo rimanda alla liquidazione, alla delega F24 o alla pratica di rimborso.</p>
            </div>
            <div class="actions">
                <label class="small muted" for="cr-year">Anno di origine</label>
                <select id="cr-year" class="input" style="max-width: 120px" :value="anno" @change="router.get(`${base}/crediti-iva`, { anno: ($event.target as HTMLSelectElement).value })"><option value="tutti">Tutti</option><option v-for="y in [...new Set([...(anno === 'tutti' ? [] : [anno]), ...years])]" :key="y" :value="y">{{ y }}</option></select>
                <button class="btn primary" type="button" @click="adding = !adding"><Icon name="plus" />Credito annuale da dichiarazione</button></div>
        </div>

        <p class="small" style="margin: -8px 0 16px">Credito disponibile{{ anno === 'tutti' ? '' : ` (origine ${anno})` }}: <strong class="tabular">{{ money(available) }}</strong> · ogni credito è contato una volta nel suo anno di origine; i riporti restano attribuiti all’origine.</p>
        <form v-if="adding" class="panel" novalidate @submit.prevent="saveNew">
            <div class="panel-body acc-grid">
                <div class="field"><label for="nc-year">Anno del credito</label><input id="nc-year" v-model.number="nform.year" type="number" class="input"><p v-if="nform.errors.year" class="error">{{ nform.errors.year }}</p></div>
                <div class="field"><label for="nc-amt">Importo</label><input id="nc-amt" v-model="nform.amount" class="input tabular" inputmode="decimal"><p v-if="nform.errors.amount" class="error">{{ nform.errors.amount }}</p></div>
                <div class="field"><label for="nc-filed">Dichiarazione presentata il</label><input id="nc-filed" v-model="nform.filed_at" type="date" class="input"></div>
                <div class="field span2"><label for="nc-desc">Descrizione</label><input id="nc-desc" v-model="nform.description" class="input" :placeholder="`Credito IVA annuale ${nform.year} da dichiarazione`"></div>
                <div class="field"><label for="nc-ex">Esonero visto fino a (facoltativo)</label><input id="nc-ex" v-model="nform.visto_exempt_limit" class="input tabular" inputmode="decimal" placeholder="es. 50.000,00 (ISA)"></div>
                <label class="check"><input v-model="nform.visto" type="checkbox"> Dichiarazione con visto di conformità</label>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="nform.processing">Registra credito</button></div>
            </div>
        </form>

        <section v-for="c in credits" :key="c.id" class="panel" style="margin-bottom: 16px">
            <div class="panel-head">
                <div><h2>{{ c.description }}</h2>
                    <p>{{ c.kind_label }} · anno {{ c.year }}<template v-if="c.settlement"> · <Link :href="`${base}/liquidazioni/${c.settlement.year}/${c.settlement.period}`">liquidazione {{ c.settlement.label }}</Link></template><template v-if="c.kind === 'annuale'"> · {{ c.filed_at ? `dichiarazione presentata il ${date(c.filed_at)}` : 'dichiarazione non ancora presentata' }}{{ c.visto ? ' · con visto' : '' }}</template>
                        <template v-if="c.status === 'sostituito'"> · <span class="badge">Sostituito da nuova versione</span></template></p></div>
                <div style="text-align: right"><span class="small muted">Maturato {{ money(c.amount) }}</span><br><strong class="tabular">Residuo {{ money(c.residual) }}</strong></div>
            </div>
            <div v-if="c.movements.length" class="table-wrap">
                <table><thead><tr><th>Data</th><th>Destinazione</th><th class="num">Importo</th><th>Stato</th><th>Collegamento</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody><tr v-for="m in c.movements" :key="m.id" :class="{ muted: m.status === 'sbloccata' }">
                        <td class="tabular">{{ date(m.date) }}</td><td>{{ m.destination_label }}</td><td class="num tabular">{{ money(m.amount) }}</td>
                        <td><span class="badge" :class="mClass(m.status)">{{ m.status_label }}</span><template v-if="m.reason"><br><span class="small muted">{{ m.reason }}</span></template></td>
                        <td class="small"><Link v-if="m.target" :href="`${base}/liquidazioni/${m.target.year}/${m.target.period}`">liquidazione {{ m.target.period }}/{{ m.target.year }}</Link>
                            <template v-if="m.f24"> delega F24 n. {{ m.f24.id }} ({{ m.f24.status }})</template><template v-if="m.claim"> <a :href="`#pratica-${m.claim.id}`">pratica n. {{ m.claim.id }} ({{ m.claim.status }})</a></template></td>
                        <td><button v-if="m.status === 'prenotata'" class="btn link small" type="button" @click="release = m; rform.clearErrors()">Sblocca</button></td>
                    </tr></tbody></table>
            </div>
            <div class="panel-body actions">
                <button v-if="c.status === 'attivo' && c.residual !== '0.00'" class="btn" type="button" @click="startDest(c)">Destina</button>
                <button v-if="c.kind === 'annuale'" class="btn link" type="button" @click="startFiling(c)">Dati dichiarazione</button>
            </div>
        </section>
        <p v-if="!credits.length" class="panel panel-body muted">Nessun credito. I crediti nascono dalle liquidazioni confermate a credito o si registrano dalla dichiarazione annuale.</p>

        <section class="panel" style="margin-top: 24px">
            <div class="panel-head"><h2>Pratiche di rimborso</h2></div>
            <div v-if="claims.length" class="table-wrap">
                <table><thead><tr><th>Pratica</th><th class="num">Rimborso</th><th class="num">Compensazione</th><th>Presupposto</th><th>Stato</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody><tr v-for="cl in claims" :id="`pratica-${cl.id}`" :key="cl.id">
                        <td><strong>{{ cl.kind === 'tr' ? `IVA TR ${cl.quarter}° trim. ${cl.year}` : `Dichiarazione annuale ${cl.year}` }}</strong> <span class="small muted">n. {{ cl.id }}</span><br><span class="small muted">{{ cl.credit }}<template v-if="cl.deadline"> · entro il {{ date(cl.deadline) }}</template></span></td>
                        <td class="num tabular">{{ money(cl.refund) }}</td><td class="num tabular">{{ money(cl.compensation) }}</td>
                        <td class="small">{{ cl.presupposto_label }}<template v-if="cl.documentation"><br><span class="muted">{{ cl.documentation }}</span></template><template v-if="cl.guarantee"><br><span class="text-warn">Oltre 30.000 €: garanzia oppure visto e dichiarazione sostitutiva.</span></template></td>
                        <td><span class="badge" :class="cl.status === 'erogato' ? 'good' : cl.status === 'respinto' ? 'bad' : cl.status === 'richiesto' ? 'info' : ''">{{ cl.status_label }}</span>
                            <template v-if="cl.filed_at"><br><span class="small muted">presentata {{ date(cl.filed_at) }}{{ cl.protocol ? ` · ${cl.protocol}` : '' }}</span></template><template v-if="cl.outcome_notes"><br><span class="small muted">{{ cl.outcome_notes }}</span></template></td>
                        <td class="row-actions">
                            <button v-if="cl.status === 'bozza'" class="btn link small" type="button" @click="claimDialog = { c: cl, action: 'presentata' }">Richiesto</button>
                            <template v-if="cl.status === 'richiesto'"><button class="btn link small" type="button" @click="claimDialog = { c: cl, action: 'erogato' }">Erogato</button><button class="btn link small" type="button" @click="claimDialog = { c: cl, action: 'respinto' }">Respinto</button></template>
                        </td>
                    </tr></tbody></table>
            </div>
            <p v-else class="panel-body muted">Nessuna pratica.</p>
        </section>

        <div v-if="dest" class="dialog-wrap" @click.self="dest = null">
            <form class="dialog wide" role="dialog" aria-modal="true" aria-labelledby="ds-title" novalidate @submit.prevent="saveDest">
                <h2 id="ds-title">Destina {{ dest.description }} · residuo {{ money(dest.residual) }}</h2>
                <div class="acc-grid">
                    <div v-for="k in (['riporto', 'compensazione', 'rimborso'] as const)" :key="k" class="field"><label :for="`ds-${k}`">{{ k === 'riporto' ? 'Riporto IVA su IVA' : k === 'compensazione' ? 'Compensazione in F24' : 'Rimborso' }}</label>
                        <input :id="`ds-${k}`" v-model="dform.rows[0][k]" class="input tabular" inputmode="decimal" :disabled="!dest.destinations[k].ok">
                        <p v-for="r in dest.destinations[k].reasons" :key="r" class="hint">{{ r }}</p><p v-if="de(`rows.0.${k}`)" class="error">{{ de(`rows.0.${k}`) }}</p></div>
                    <div class="field"><label for="ds-ty">Riporto: anno</label><input id="ds-ty" v-model.number="dform.target_year" type="number" class="input"></div>
                    <div class="field"><label for="ds-tp">Riporto: periodo</label><input id="ds-tp" v-model.number="dform.target_period" type="number" min="1" max="12" class="input"></div>
                    <div class="field"><label for="ds-pres">Presupposto rimborso</label><select id="ds-pres" v-model="dform.presupposto" class="input"><option value="">—</option><option v-for="(l, k) in annualPresupposti" :key="k" :value="k">{{ l }}</option></select></div>
                </div>
                <p v-if="de('rows.0')" class="error">{{ de('rows.0') }}</p>
                <div class="actions"><button class="btn primary" type="submit" :disabled="dform.processing">Conferma</button><button class="btn" type="button" @click="dest = null">Annulla</button></div>
            </form>
        </div>
        <div v-if="filing" class="dialog-wrap" @click.self="filing = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="fl-title" @submit.prevent="saveFiling">
                <h2 id="fl-title">Dichiarazione IVA {{ filing.year }}</h2>
                <div class="field"><label for="fl-date">Presentata il</label><input id="fl-date" v-model="fform.filed_at" type="date" class="input" :max="today"></div>
                <label class="check"><input v-model="fform.visto" type="checkbox"> Con visto di conformità</label>
                <div class="actions"><button class="btn primary" type="submit" :disabled="fform.processing">Salva</button><button class="btn" type="button" @click="filing = null">Annulla</button></div>
            </form>
        </div>
        <div v-if="release" class="dialog-wrap" @click.self="release = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="rl-title" @submit.prevent="saveRelease">
                <h2 id="rl-title">Sbloccare {{ money(release.amount) }} ({{ release.destination_label }})?</h2>
                <div class="field"><label for="rl-reason">Motivo</label><input id="rl-reason" v-model="rform.reason" class="input"><p v-if="rform.errors.reason" class="error">{{ rform.errors.reason }}</p></div>
                <div class="actions"><button class="btn danger" type="submit" :disabled="rform.processing">Sblocca</button><button class="btn" type="button" @click="release = null">Annulla</button></div>
            </form>
        </div>
        <div v-if="claimDialog" class="dialog-wrap" @click.self="claimDialog = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="cl-title" @submit.prevent="saveClaim">
                <h2 id="cl-title">{{ claimDialog.action === 'presentata' ? 'Pratica presentata' : claimDialog.action === 'erogato' ? 'Rimborso erogato' : 'Pratica respinta' }}</h2>
                <div class="field"><label for="cl-date">Data</label><input id="cl-date" v-model="cform.date" type="date" class="input" :max="today"><p v-if="(cform.errors as Record<string, string>).filed_at || (cform.errors as Record<string, string>).date" class="error">{{ (cform.errors as Record<string, string>).filed_at || (cform.errors as Record<string, string>).date }}</p></div>
                <div v-if="claimDialog.action === 'presentata'" class="field"><label for="cl-prot">Protocollo</label><input id="cl-prot" v-model="cform.protocol" class="input"></div>
                <div v-else class="field"><label for="cl-notes">{{ claimDialog.action === 'respinto' ? 'Motivo del rigetto' : 'Note' }}</label><input id="cl-notes" v-model="cform.notes" class="input"><p v-if="cform.errors.notes" class="error">{{ cform.errors.notes }}</p></div>
                <p v-if="(cform.errors as Record<string, string>).status" class="error">{{ (cform.errors as Record<string, string>).status }}</p>
                <div class="actions"><button class="btn primary" type="submit" :disabled="cform.processing">Conferma</button><button class="btn" type="button" @click="claimDialog = null">Annulla</button></div>
            </form>
        </div>
    </AppLayout>
</template>
