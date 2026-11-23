<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import { money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Nuovo movimento cespite (Anteprima → Conferma): Alienazione totale/parziale, Eliminazione, Incremento/Decremento, Rivalutazione, Svalutazione, Ripristino,
   Rettifica ammortamento, Riclassificazione, Trasferimento. Genutzt in der Scheda des Cespite und unter „Movimenti cespiti“ (mit Auswahl des Cespite). */
const props = defineProps<{ assetId: number | null; types: Record<string, string>; categories: { id: number; code: string; name: string }[]; initialType?: string | null }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const url = computed(() => `${base.value}/${props.assetId}`);
const first = props.initialType && props.types[props.initialType] ? props.initialType : Object.keys(props.types)[0];
const mv = ref<Record<string, any>>({ type: first, movement_date: new Date().toISOString().slice(0, 10), amount: '', fiscal_amount: '', price: '', vat: '', sale_costs: '', share_pct: '', causale: '', asset_category_id: '', cost_center: '', location: '', fiscal_recognized: false, legal_basis: '' });
const preview = ref<{ values: Record<string, string>; warnings: string[]; lines: { account: string; dare: string; avere: string; description: string }[] } | null>(null);
const mvErrors = ref<Record<string, string>>({});
const busy = ref(false);
watch(() => props.assetId, () => { preview.value = null; mvErrors.value = {}; });
watch(() => props.initialType, (t) => { if (t && props.types[t]) { mv.value.type = t; preview.value = null; } });
const csrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const doPreview = async () => {
    if (!props.assetId) { mvErrors.value = { asset: 'Scegli il cespite.' }; return; }
    busy.value = true; mvErrors.value = {}; preview.value = null;
    const r = await fetch(`${url.value}/movimenti/anteprima`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(mv.value), credentials: 'same-origin' });
    busy.value = false;
    const j = await r.json();
    if (r.status === 422) { mvErrors.value = Object.fromEntries(Object.entries(j.errors ?? {}).map(([k, v]) => [k, (v as string[])[0]])); return; }
    preview.value = j;
};
const doRegister = () => router.post(`${url.value}/movimenti`, { ...mv.value, confirm: true }, { preserveScroll: true, onSuccess: () => { preview.value = null; mv.value.causale = ''; }, onError: (e) => { mvErrors.value = e; } });
const needs = (k: string) => ({ amount: ['incremento', 'decremento', 'rivalutazione', 'svalutazione', 'ripristino', 'rettifica_ammortamento'], fiscal_amount: ['incremento', 'decremento', 'rettifica_ammortamento'],
    price: ['alienazione_totale', 'alienazione_parziale'], share_pct: ['alienazione_parziale'], asset_category_id: ['riclassificazione'], cost_center: ['trasferimento'] } as Record<string, string[]>)[k]?.includes(mv.value.type);
const valueLabels: Record<string, string> = { cost_civil: 'Costo', fund_civil: 'Fondo alla data', book_value: 'Valore netto contabile', cost_fiscal: 'Costo fiscale', fund_fiscal: 'Fondo fiscale', fiscal_value: 'Residuo fiscale', quota_civil: 'Quota dell’esercizio fino alla data', gain_civil: 'Risultato civilistico', gain_fiscal: 'Risultato fiscale' };
</script>

<template>
<section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Nuovo movimento</h2><p>Prima calcola l’anteprima: valori, risultato e scrittura proposta. La contabilizzazione avviene solo dopo la conferma.</p></div></div>
                <div class="panel-body mi-grid">
                    <div class="field"><label for="m-t">Tipo</label><select id="m-t" v-model="mv.type" class="input" @change="preview = null; mvErrors = {}"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
                    <div class="field"><label for="m-d">Data</label><input id="m-d" v-model="mv.movement_date" type="date" class="input"><p v-if="mvErrors.movement_date" class="error">{{ mvErrors.movement_date }}</p></div>
                    <div v-if="needs('amount')" class="field"><label for="m-a">Importo{{ mv.type === 'rettifica_ammortamento' ? ' (± )' : '' }}</label><input id="m-a" v-model="mv.amount" class="input tabular"><p v-if="mvErrors.amount" class="error">{{ mvErrors.amount }}</p></div>
                    <div v-if="needs('fiscal_amount')" class="field"><label for="m-fa">Importo fiscale (se diverso)</label><input id="m-fa" v-model="mv.fiscal_amount" class="input tabular"></div>
                    <template v-if="mv.type === 'rivalutazione'"><label class="check"><input v-model="mv.fiscal_recognized" type="checkbox"> Riconosciuta fiscalmente</label><div v-if="mv.fiscal_recognized" class="field span2"><label for="m-lb">Norma di riferimento</label><input id="m-lb" v-model="mv.legal_basis" class="input"><p v-if="mvErrors.legal_basis" class="error">{{ mvErrors.legal_basis }}</p></div></template>
                    <div v-if="needs('share_pct')" class="field"><label for="m-sp">Quota alienata %</label><input id="m-sp" v-model="mv.share_pct" class="input tabular"><p v-if="mvErrors.share_pct" class="error">{{ mvErrors.share_pct }}</p></div>
                    <template v-if="needs('price')"><div class="field"><label for="m-p">Prezzo netto</label><input id="m-p" v-model="mv.price" class="input tabular"><p v-if="mvErrors.price" class="error">{{ mvErrors.price }}</p></div>
                        <div class="field"><label for="m-v">IVA</label><input id="m-v" v-model="mv.vat" class="input tabular"></div><div class="field"><label for="m-sc">Costi della cessione</label><input id="m-sc" v-model="mv.sale_costs" class="input tabular"></div></template>
                    <div v-if="needs('asset_category_id')" class="field span2"><label for="m-c">Nuova categoria</label><select id="m-c" v-model="mv.asset_category_id" class="input"><option value="">Scegli…</option><option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.code }} · {{ c.name }}</option></select><p v-if="mvErrors.asset_category_id" class="error">{{ mvErrors.asset_category_id }}</p></div>
                    <template v-if="needs('cost_center')"><div class="field"><label for="m-cc">Nuovo centro di costo</label><input id="m-cc" v-model="mv.cost_center" class="input"><p v-if="mvErrors.cost_center" class="error">{{ mvErrors.cost_center }}</p></div><div class="field"><label for="m-l">Nuova ubicazione</label><input id="m-l" v-model="mv.location" class="input"></div></template>
                    <div class="field span3"><label for="m-ca">Causale</label><input id="m-ca" v-model="mv.causale" class="input"><p v-if="mvErrors.causale" class="error">{{ mvErrors.causale }}</p></div>
                    <p v-for="(e, k) in Object.fromEntries(Object.entries(mvErrors).filter(([k]) => !['movement_date','amount','share_pct','price','asset_category_id','cost_center','causale','legal_basis','asset'].includes(k)))" :key="k" class="error span3">{{ e }}</p>
                    <div class="actions span3"><button class="btn" type="button" :disabled="busy" @click="doPreview"><Icon name="search" />Anteprima</button></div>
                </div>
                <div v-if="preview" class="panel-body" style="border-top: 1px solid var(--ct-border)">
                    <dl v-if="Object.keys(preview.values).length" class="facts" style="margin-bottom: 12px"><div v-for="(v, k) in preview.values" :key="k"><dt>{{ valueLabels[k] ?? k }}</dt><dd class="tabular" :class="{ 'text-bad': String(k).startsWith('gain') && v.startsWith('-') }">{{ money(v) }}</dd></div></dl>
                    <div v-for="(w, k) in preview.warnings" :key="k" class="small muted" style="margin-bottom: 4px">{{ w }}</div>
                    <div v-if="preview.lines.length" class="table-wrap" style="margin-top: 8px"><table class="data"><thead><tr><th>Conto</th><th>Descrizione</th><th class="num">Dare</th><th class="num">Avere</th></tr></thead>
                        <tbody><tr v-for="(l, k) in preview.lines" :key="k"><td>{{ l.account }}</td><td class="small">{{ l.description }}</td><td class="num tabular">{{ l.dare !== '0.00' ? money(l.dare) : '' }}</td><td class="num tabular">{{ l.avere !== '0.00' ? money(l.avere) : '' }}</td></tr></tbody></table></div>
                    <div class="actions" style="margin-top: 12px"><button class="btn primary" type="button" @click="doRegister"><Icon name="check" />{{ preview.lines.length ? 'Conferma e contabilizza' : 'Registra' }}</button></div>
                </div></section>
</template>
