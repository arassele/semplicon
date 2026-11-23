<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AssetMovementForm from '@/Components/AssetMovementForm.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Movimenti cespiti: inserimento (scegliere Cespite → Tipo → Anteprima → Conferma) ed elenco dell’anno, filtrabile per Tipo; Storno nella Scheda del Cespite. */
const props = defineProps<{ year: number; type: string | null; types: Record<string, string>; manual_types: Record<string, string>; categories: { id: number; code: string; name: string; kind: string }[];
    assets: { id: number; code: string; description: string; kind: string; asset_category_id: number }[]; selected: number | null; new_type: string | null;
    rows: { id: number; date: string; type: string; type_label: string; asset_id: number; asset: string; amount: string | null; gain: string | null; causale: string; status: string; journal: string | null; user: string | null }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const assetId = ref<number | null>(props.selected ?? null);
const chosenType = ref<string | null>(props.new_type ?? 'alienazione_totale');
const asset = computed(() => props.assets.find((a) => a.id === assetId.value) ?? null);
const cats = computed(() => props.categories.filter((c) => !asset.value || (c.kind === asset.value.kind && c.id !== asset.value.asset_category_id)));
const QUICK: [string, string][] = [['alienazione_totale', 'Vendita'], ['alienazione_parziale', 'Vendita parziale'], ['eliminazione', 'Eliminazione'], ['rivalutazione', 'Rivalutazione'], ['svalutazione', 'Svalutazione'], ['incremento', 'Incremento'], ['decremento', 'Decremento'], ['rettifica_ammortamento', 'Rettifica fondo'], ['riclassificazione', 'Riclassificazione']];
</script>

<template>
    <Head title="Movimenti cespiti" />
    <AppLayout crumb="Cespiti · Movimenti">
        <div class="page-head"><div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>Movimenti cespiti {{ year }}</h1><p>Vendite, eliminazioni, rivalutazioni, svalutazioni, variazioni di valore, rettifiche, riclassificazioni e trasferimenti. Ogni movimento ha anteprima, data, causale, utente e scrittura collegata; correzioni solo con storno.</p></div></div>

        <section class="panel" style="margin-bottom: 16px" aria-labelledby="mv-new"><div class="panel-head"><div><h2 id="mv-new">Registra un movimento</h2><p>Scegli il cespite e il tipo di operazione.</p></div></div>
            <div class="panel-body mi-grid">
                <div class="field span2"><label for="mv-asset">Cespite</label><select id="mv-asset" v-model="assetId" class="input"><option :value="null">Scegli…</option><option v-for="a in assets" :key="a.id" :value="a.id">{{ a.code }} · {{ a.description }}</option></select></div>
                <div class="span3 quick" role="group" aria-label="Tipo di movimento"><button v-for="[k, l] in QUICK" :key="k" type="button" class="btn" :class="{ primary: chosenType === k }" :aria-pressed="chosenType === k" @click="chosenType = k">{{ l }}</button></div>
            </div></section>
        <AssetMovementForm v-if="asset" :key="assetId!" :asset-id="assetId" :types="manual_types" :categories="cats" :initial-type="chosenType" />
        <p v-else-if="!assets.length" class="muted">Nessun cespite attivo.</p>

        <section class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Movimenti del {{ year }}</h2>
            <div class="actions"><label class="sr-only" for="mt">Tipo</label><select id="mt" class="input" :value="type ?? ''" @change="router.get(`${base}/movimenti`, { tipo: ($event.target as HTMLSelectElement).value || undefined })"><option value="">Tutti i tipi</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div></div>
            <div v-if="props.rows.length" class="table-wrap"><table class="data">
            <thead><tr><th>Data</th><th>Tipo</th><th>Cespite</th><th class="num">Importo</th><th class="num">Plus/minus</th><th>Causale</th><th>Scrittura</th><th>Utente</th><th>Stato</th></tr></thead>
            <tbody><tr v-for="r in props.rows" :key="r.id" :style="r.status === 'stornato' ? 'color: var(--ct-muted)' : ''"><td class="tabular">{{ date(r.date) }}</td><td>{{ r.type_label }}</td><td><Link :href="`${base}/${r.asset_id}#movimenti`">{{ r.asset }}</Link></td>
                <td class="num tabular">{{ r.amount ? money(r.amount) : '' }}</td><td class="num tabular">{{ r.gain ? money(r.gain) : '' }}</td><td class="small">{{ r.causale }}</td><td class="tabular small">{{ r.journal ?? '—' }}</td><td class="small">{{ r.user }}</td><td>{{ r.status }}</td></tr></tbody></table></div>
            <p v-else class="panel-body muted">Nessun movimento nel {{ year }}.</p></section>
    </AppLayout>
</template>
<style scoped>.quick { display: flex; flex-wrap: wrap; gap: 8px; }</style>
