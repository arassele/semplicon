<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Stampe e registri A–J: anteprima, PDF e CSV; filtri per Elenco e Registro. */
type Report = { key: string; title: string; year: number; columns: string[][]; rows: (string | null)[][]; totals: Record<string, string>; notes: string[] };
const props = defineProps<{ year: number; key: string; reports: Record<string, string>; filters: Record<string, string>; report: Report | null; categories: { id: number; code: string; name: string }[]; statuses: Record<string, string>;
    assets: { id: number; code: string; description: string }[]; accounts: { id: number; code: string; name: string }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti/stampe`);
const f = ref<Record<string, string>>({ category: '', status: '', location: '', cost_center: '', purchase_year: '', account: '', asset: '', ...props.filters });
const q = computed(() => new URLSearchParams(Object.entries(f.value).filter(([, v]) => v) as [string, string][]).toString());
const go = (key: string) => router.get(base.value, { stampa: key, ...Object.fromEntries(Object.entries(f.value).filter(([, v]) => v)) }, { preserveScroll: true });
const isNum = (k: number) => props.report?.columns[k]?.[1] === 'num';
</script>

<template>
    <Head title="Stampe e registri cespiti" />
    <AppLayout crumb="Cespiti · Stampe">
        <div class="page-head"><div><p class="eyebrow"><Link :href="base.replace('/stampe', '')">Cespiti</Link></p><h1>Stampe e registri · {{ year }}</h1><p>Anteprima, PDF e CSV con ragione sociale, Partita IVA, esercizio e data.</p></div></div>
        <section class="panel" style="margin-bottom: 16px"><div class="panel-body mi-grid">
            <div class="field span2"><label for="s-k">Stampa</label><select id="s-k" class="input" :value="key" @change="go(($event.target as HTMLSelectElement).value)"><option v-for="(l, k) in reports" :key="k" :value="k">{{ l }}</option></select></div>
            <div v-if="key === 'scheda'" class="field"><label for="s-a">Cespite</label><select id="s-a" v-model="f.asset" class="input" @change="go(key)"><option value="">Scegli…</option><option v-for="a in assets" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.description }}</option></select></div>
            <template v-else>
                <div class="field"><label for="s-c">Categoria</label><select id="s-c" v-model="f.category" class="input"><option value="">Tutte</option><option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.code }} · {{ c.name }}</option></select></div>
                <div class="field"><label for="s-s">Stato</label><select id="s-s" v-model="f.status" class="input"><option value="">Tutti</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field"><label for="s-acc">Conto</label><select id="s-acc" v-model="f.account" class="input"><option value="">Tutti</option><option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.name }}</option></select></div>
                <div class="field"><label for="s-l">Ubicazione</label><input id="s-l" v-model="f.location" class="input"></div>
                <div class="field"><label for="s-cc">Centro di costo</label><input id="s-cc" v-model="f.cost_center" class="input"></div>
                <div class="field"><label for="s-y">Anno di acquisto</label><input id="s-y" v-model="f.purchase_year" class="input tabular"></div>
                <div class="actions" style="align-self: end"><button class="btn" type="button" @click="go(key)"><Icon name="search" />Aggiorna</button></div>
            </template>
        </div></section>
        <section v-if="report" class="panel"><div class="panel-head"><h2>{{ report.title }}</h2>
            <div class="actions"><a class="btn" :href="`${base}/${key}.pdf?${q}`"><Icon name="download" />PDF</a><a class="btn" :href="`${base}/${key}.csv?${q}`"><Icon name="download" />CSV</a></div></div>
            <div class="table-wrap"><table class="data">
                <thead><tr><th v-for="(c, k) in report.columns" :key="k" :class="{ num: c[1] === 'num' }">{{ c[0] }}</th></tr></thead>
                <tbody><tr v-for="(r, i) in report.rows" :key="i"><td v-for="(v, k) in r" :key="k" :class="{ num: isNum(k), tabular: isNum(k) }">{{ isNum(k) && v !== null && /^-?\d+(\.\d+)?$/.test(String(v)) ? money(String(v)) : v }}</td></tr>
                    <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="muted">Nessun dato.</td></tr></tbody></table></div>
            <dl v-if="Object.keys(report.totals).length" class="facts panel-body"><div v-for="(v, k) in report.totals" :key="k"><dt>{{ k }}</dt><dd class="tabular">{{ money(v) }}</dd></div></dl>
            <p v-for="(n, k) in report.notes" :key="k" class="panel-body small muted" style="padding-top: 0">{{ n }}</p>
        </section>
        <p v-else class="muted">Scegli il cespite per la scheda.</p>
    </AppLayout>
</template>
