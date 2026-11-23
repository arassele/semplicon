<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Importazione progressivi: CSV → Anteprima con associazione delle colonne e controlli → Importazione (senza registrazioni). */
type Job = { id: number; filename: string; mode: string; status: string; mapping: Record<string, number>; imported: number; head: string[]; missing: string[]; rows: { line: number; codice: string; descrizione: string; categoria: string; anno?: number; errors: string[]; warnings: string[] }[];
    error_count: number; warning_count: number; assets: number; skipped: string[] };
const props = defineProps<{ fields: string[]; categories: { code: string; name: string }[]; job: Job | null; jobs: { id: number; filename: string; mode: string; status: string; rows: number; imported: number; created_at: string }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti/importazione`);
const up = useForm<{ file: File | null; mode: string }>({ file: null, mode: 'saldi' });
const map = ref<Record<string, number | ''>>(Object.fromEntries(props.fields.map((f) => [f, props.job?.mapping?.[f] ?? ''])));
const onlyValid = ref(false);
const remap = () => router.post(`${base.value}/${props.job!.id}/mappatura`, { mapping: map.value }, { preserveScroll: true });
const run = () => router.post(`${base.value}/${props.job!.id}/importa`, { only_valid: onlyValid.value }, { preserveScroll: true });
const relevant = computed(() => props.fields.filter((f) => (props.job?.mode === 'storico' ? !['fondo_civilistico', 'fondo_fiscale', 'ultimo_esercizio', 'residuo', 'incrementi', 'decrementi', 'rivalutazioni', 'stato'].includes(f) : !['anno', 'quota_civilistica', 'quota_fiscale'].includes(f))));
</script>

<template>
    <Head title="Importazione progressivi" />
    <AppLayout crumb="Cespiti · Importazione">
        <div class="page-head"><div><p class="eyebrow"><Link :href="base.replace('/importazione', '')">Cespiti</Link></p><h1>Importazione cespiti e progressivi</h1>
            <p>Da altri gestionali in CSV (da Excel: Salva con nome › CSV). Le categorie devono esistere (codice). Non vengono create scritture contabili: i saldi sono già nella contabilità di provenienza.</p></div>
            <div class="actions"><a class="btn" :href="`${base}/modello?modo=saldi`"><Icon name="download" />Modello saldi</a><a class="btn" :href="`${base}/modello?modo=storico`"><Icon name="download" />Modello storico</a></div></div>
        <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Carica file</h2><p><strong>Saldi iniziali (A):</strong> una riga per cespite con fondo all’ultimo esercizio ammortizzato; Semplicon calcola dagli esercizi successivi. <strong>Storico annuale (B):</strong> una riga per cespite e anno con le quote; il piano storico viene ricostruito.</p></div></div>
            <form class="panel-body mi-grid" @submit.prevent="up.post(base, { forceFormData: true })">
                <div class="field"><label for="i-m">Modalità</label><select id="i-m" v-model="up.mode" class="input"><option value="saldi">A · Saldi iniziali</option><option value="storico">B · Storico annuale completo</option></select></div>
                <div class="field"><label for="i-f">File CSV</label><input id="i-f" type="file" accept=".csv,.txt" class="input" @change="up.file = ($event.target as HTMLInputElement).files?.[0] ?? null"><p v-if="up.errors.file" class="error">{{ up.errors.file }}</p></div>
                <div class="actions" style="align-self: end"><button class="btn primary" type="submit" :disabled="!up.file || up.processing"><Icon name="upload" />Anteprima</button></div>
            </form></section>

        <template v-if="job">
            <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><div><h2>Anteprima · {{ job.filename }}</h2><p>{{ job.assets }} cespiti · {{ job.rows.length }} righe · <strong :class="{ 'text-bad': job.error_count }">{{ job.error_count }} errori</strong> · {{ job.warning_count }} avvisi · stato {{ job.status }}</p></div></div>
                <div v-if="job.status === 'anteprima'" class="panel-body"><p class="small muted">Mappatura delle colonne del file sui campi di Semplicon.</p>
                    <div class="mi-grid" style="margin-top: 8px"><div v-for="f in relevant" :key="f" class="field"><label :for="`map-${f}`">{{ f.replace(/_/g, ' ') }}<span v-if="job.missing.includes(f)" class="text-bad"> · obbligatorio</span></label>
                        <select :id="`map-${f}`" v-model="map[f]" class="input"><option value="">—</option><option v-for="(h, i) in job.head" :key="i" :value="i">{{ h }}</option></select></div></div>
                    <div class="actions" style="margin-top: 10px"><button class="btn" type="button" @click="remap">Applica mappatura e ricontrolla</button></div></div>
                <div class="table-wrap"><table class="data"><thead><tr><th>Riga</th><th>Codice</th><th v-if="job.mode === 'storico'">Anno</th><th>Descrizione</th><th>Categoria</th><th>Esito</th></tr></thead>
                    <tbody><tr v-for="r in job.rows" :key="r.line"><td class="tabular">{{ r.line }}</td><td class="tabular">{{ r.codice }}</td><td v-if="job.mode === 'storico'" class="tabular">{{ r.anno }}</td><td>{{ r.descrizione }}</td><td>{{ r.categoria }}</td>
                        <td><span v-if="!r.errors.length && !r.warnings.length" class="badge good">OK</span><div v-for="(e, k) in r.errors" :key="'e' + k" class="small text-bad">{{ e }}</div><div v-for="(w, k) in r.warnings" :key="'w' + k" class="small muted">{{ w }}</div></td></tr></tbody></table></div>
                <div v-if="job.status === 'anteprima'" class="panel-body actions">
                    <label v-if="job.error_count" class="check"><input v-model="onlyValid" type="checkbox"> Importa solo i cespiti senza errori</label>
                    <button class="btn primary" type="button" :disabled="job.missing.length > 0 || (job.error_count > 0 && !onlyValid)" @click="run"><Icon name="check" />Importa</button></div>
                <p v-else class="panel-body"><strong>{{ job.imported }} cespiti importati.</strong><template v-if="job.skipped.length"> Esclusi per errori: {{ job.skipped.join(', ') }}.</template> <Link :href="base.replace('/importazione', '')">Vai all’elenco</Link></p>
            </section>
        </template>
        <section v-if="jobs.length" class="panel"><div class="panel-head"><h2>Importazioni recenti</h2></div>
            <ul class="list" style="margin-top: 8px"><li v-for="j in jobs" :key="j.id"><Link :href="`${base}?job=${j.id}`">{{ j.filename }}</Link> · {{ j.mode }} · {{ j.status }} · {{ j.imported }}/{{ j.rows }} · {{ dateTime(j.created_at) }}</li></ul></section>
    </AppLayout>
</template>
