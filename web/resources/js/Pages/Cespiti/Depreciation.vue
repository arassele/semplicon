<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Calcolo ammortamenti: Simulazione → Calcolato (quote fisse) → Contabilizzato (dopo la conferma) → Storno con motivo. */
type Row = { id: number; code: string; description: string; category: string; method: string; fixed: boolean; base: string; rate: string; civil: string; limit: string; fiscal: string; nondeductible: string; fund_end: string; days: number | null; warnings: string[]; kind: string; extra: string; extra_pct: string | null; reduced_pct: string | null };
const props = defineProps<{ year: number; years: number[]; blocking: string[]; closed: boolean; run: { id: number; status: string; total_civil: string; total_fiscal: string; calculated_at: string | null; posted_at: string | null } | null;
    history: { id: number; status: string; total_civil: string; total_fiscal: string; reason: string | null; calculated_at: string | null; posted_at: string | null; reversed_at: string | null }[]; rows: Row[];
    categories: { id: number; code: string; name: string }[]; options: { reduced_pct: string | null; categories: Record<string, string> } }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const tot = computed(() => props.rows.reduce((t, r) => ({ civil: t.civil + Number(r.civil), fiscal: t.fiscal + Number(r.fiscal), nd: t.nd + Number(r.nondeductible), extra: t.extra + Number(r.extra) }), { civil: 0, fiscal: 0, nd: 0, extra: 0 }));
const hasExtra = computed(() => props.rows.some((r) => Number(r.extra) || r.extra_pct));
const confirmPost = ref(false);
/* Quota ridotta (Decisione n. 81): % della quota ordinaria per l’elaborazione, modificabile per categoria; Immateriali mai. */
const pctIn = (v: string | null | undefined) => (v === null || v === undefined || v === '' ? '' : String(Number(v)).replace('.', ','));
const red = ref(pctIn(props.options?.reduced_pct));
const redCat = ref<Record<string, string>>(Object.fromEntries(props.categories.map((c) => [String(c.id), pctIn(props.options?.categories?.[c.id])])));
const editable = computed(() => !props.run || props.run.status === 'calcolato');
const params = () => ({ esercizio: props.year, ridotta: red.value || undefined, ridotta_cat: Object.fromEntries(Object.entries(redCat.value).filter(([, v]) => v !== '')) });
const simulate = () => router.get(`${base.value}/ammortamenti`, params(), { preserveScroll: true, preserveState: true });
const calc = () => router.post(`${base.value}/ammortamenti/calcola`, params(), { preserveScroll: true });
const goYear = (y: string) => router.get(`${base.value}/ammortamenti`, { esercizio: y });
const err = computed(() => usePage<SharedProps>().props.errors as Record<string, string>);
const post = () => router.post(`${base.value}/ammortamenti/${props.run!.id}/contabilizza`, { confirm: true }, { preserveScroll: true, onSuccess: () => { confirmPost.value = false; } });
const reverse = () => { const reason = prompt('Motivo dello storno del calcolo?'); if (reason) router.post(`${base.value}/ammortamenti/${props.run!.id}/storna`, { reason }, { preserveScroll: true }); };
const STATUS: Record<string, [string, string]> = { calcolato: ['Calcolato', 'info'], contabilizzato: ['Contabilizzato', 'good'], stornato: ['Stornato', ''] };
</script>

<template>
    <Head title="Calcolo ammortamenti" />
    <AppLayout crumb="Cespiti · Calcolo ammortamenti">
        <div class="page-head">
            <div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>Calcolo ammortamenti {{ year }}</h1>
                <p>Simulazione delle quote dell’esercizio (di norma quello scelto in alto; gli esercizi precedenti si recuperano dal selettore). “Calcola” ferma le quote; “Contabilizza” genera la scrittura Dare ammortamento / Avere fondo nella prima nota cespiti, solo dopo la conferma.</p></div>
            <div class="actions">
                <label class="sr-only" for="dy">Esercizio</label><select id="dy" class="input" style="width: auto" :value="year" @change="goYear(($event.target as HTMLSelectElement).value)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <button v-if="!run || run.status === 'calcolato'" class="btn" :class="{ primary: !run }" type="button" :disabled="closed || blocking.length > 0" @click="calc"><Icon name="check" />{{ run ? 'Ricalcola' : 'Calcola' }}</button>
                <button v-if="run?.status === 'calcolato'" class="btn primary" type="button" :disabled="closed" @click="confirmPost = true"><Icon name="check" />Contabilizza</button>
                <button v-if="run" class="btn" type="button" :disabled="closed" @click="reverse">Storna</button>
            </div>
        </div>
        <div v-if="closed" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Esercizio {{ year }} chiuso</strong>Riaprilo in Impostazioni cespiti per ricalcolare.</div></div>
        <div v-for="(b, k) in blocking" :key="k" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ b }} <button v-if="years.length && years[0] < year" class="btn link" type="button" @click="goYear(String(years.find((y) => b.includes(String(y))) ?? years[0]))">Vai all’esercizio</button></div></div>
        <div v-if="run" class="notice" :class="run.status === 'contabilizzato' ? 'good' : 'info'" role="status"><Icon name="info" style="width: 20px; flex: none" />
            <div><strong>{{ STATUS[run.status][0] }}</strong>Quote {{ money(run.total_civil) }} · deducibili {{ money(run.total_fiscal) }}<template v-if="run.calculated_at"> · calcolato il {{ dateTime(run.calculated_at) }}</template><template v-if="run.posted_at"> · contabilizzato il {{ dateTime(run.posted_at) }}</template></div></div>
        <div v-if="confirmPost" class="notice warn" role="alertdialog" style="margin-top: 12px"><Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Confermi la contabilizzazione?</strong>Verrà creata una scrittura per {{ money(run!.total_civil) }} (Dare ammortamenti / Avere fondi, per coppia di conti). Correzioni successive solo con storno.
                <div class="actions" style="margin-top: 8px"><button class="btn primary" type="button" @click="post">Sì, contabilizza</button><button class="btn" type="button" @click="confirmPost = false">Annulla</button></div></div></div>

        <section v-if="editable && rows.some((r) => r.kind === 'materiale' && !r.fixed)" class="panel" style="margin-top: 16px" aria-labelledby="dep-red">
            <div class="panel-head"><div><h2 id="dep-red">Quota ridotta</h2><p>Percentuale della quota ordinaria per questo esercizio (vuoto = ordinaria). Si può cambiare per categoria; non si applica ai beni immateriali né al metodo manuale.</p></div></div>
            <div class="panel-body mi-grid">
                <div class="field"><label for="dep-rp">Tutti i beni materiali (%)</label><input id="dep-rp" v-model="red" class="input tabular" inputmode="decimal" placeholder="es. 50" :aria-invalid="err.reduced_pct ? true : undefined"><p v-if="err.reduced_pct" class="error">{{ err.reduced_pct }}</p></div>
                <div v-for="c in categories" :key="c.id" class="field"><label :for="`dep-rc-${c.id}`">{{ c.code }} · {{ c.name }} (%)</label><input :id="`dep-rc-${c.id}`" v-model="redCat[String(c.id)]" class="input tabular" inputmode="decimal" :placeholder="red ? `come sopra (${red})` : 'ordinaria'"><p v-if="err[`categories.${c.id}`]" class="error">{{ err[`categories.${c.id}`] }}</p></div>
                <div class="actions span3"><button class="btn" type="button" @click="simulate">Aggiorna simulazione</button><span class="small muted">“{{ run ? 'Ricalcola' : 'Calcola' }}” usa queste percentuali e le registra nel calcolo.</span></div>
            </div>
        </section>

        <section class="panel" style="margin-top: 16px">
            <div v-if="rows.length" class="table-wrap"><table class="data">
                <thead><tr><th>Cespite</th><th>Categoria</th><th>Metodo</th><th class="num">Base</th><th class="num">Aliquota</th><th class="num">Quota civilistica</th><th class="num">Limite fiscale</th><th class="num">Deducibile</th><th class="num">Non deducibile</th><th v-if="hasExtra" class="num">Maggiorazione</th><th class="num">Fondo finale</th><th>Stato</th></tr></thead>
                <tbody><tr v-for="r in rows" :key="r.id">
                    <td><Link :href="`${base}/${r.id}`">{{ r.code }}</Link> · {{ r.description }}<span v-if="r.days" class="small muted"> ({{ r.days }} gg)</span><div v-for="(w, k) in r.warnings" :key="k" class="small text-warn">{{ w }}</div></td>
                    <td class="small">{{ r.category }}</td><td class="small">{{ r.method + (r.reduced_pct ? ` ${pctIn(r.reduced_pct)}%` : '') }}</td><td class="num tabular">{{ money(r.base) }}</td><td class="num tabular">{{ r.rate.replace('.', ',') }}%</td><td class="num tabular">{{ money(r.civil) }}</td>
                    <td class="num tabular">{{ money(r.limit) }}</td><td class="num tabular">{{ money(r.fiscal) }}</td><td class="num tabular">{{ Number(r.nondeductible) ? money(r.nondeductible) : '' }}</td><td v-if="hasExtra" class="num tabular">{{ Number(r.extra) ? money(r.extra) : '' }}<div v-if="r.extra_pct" class="small muted">+{{ pctIn(r.extra_pct) }}%</div></td><td class="num tabular">{{ money(r.fund_end) }}</td>
                    <td><span class="badge" :class="r.fixed ? 'good' : ''">{{ r.fixed ? 'fermo' : 'simulato' }}</span></td></tr></tbody>
                <tfoot><tr class="sum-row"><td colspan="5">Totale</td><td class="num tabular">{{ money(tot.civil.toFixed(2)) }}</td><td></td><td class="num tabular">{{ money(tot.fiscal.toFixed(2)) }}</td><td class="num tabular">{{ money(tot.nd.toFixed(2)) }}</td><td v-if="hasExtra" class="num tabular">{{ money(tot.extra.toFixed(2)) }}</td><td colspan="2"></td></tr></tfoot>
            </table></div>
            <p v-else class="panel-body muted">Nessun cespite da ammortizzare nel {{ year }}.</p>
            <p v-if="hasExtra" class="panel-body small muted">Maggiorazione (super/iperammortamento): quota deducibile × percentuale del cespite. È una variazione in diminuzione extracontabile: non entra nella prima nota, compare nel conto economico fiscale e nella stampa K.</p>
        </section>
        <section v-if="history.length > 1" class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Calcoli dell’esercizio</h2></div>
            <ul class="list" style="margin-top: 8px"><li v-for="h in history" :key="h.id"><span class="badge" :class="STATUS[h.status]?.[1]">{{ STATUS[h.status]?.[0] ?? h.status }}</span> {{ money(h.total_civil) }} · {{ dateTime(h.calculated_at) }}<template v-if="h.reason"> · storno: {{ h.reason }}</template></li></ul></section>
    </AppLayout>
</template>
<style scoped>.text-warn { color: #7a4b00; }</style>
