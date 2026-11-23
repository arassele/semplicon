<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Cespiti: Dashboard (indicatori dell’anno) ed Elenco con ricerca, filtri, ordinamento, selezione multipla ed esportazione. */
type Row = { id: number; number: number; code: string; description: string; status: string; kind: string; location: string | null; cost_center: string | null; excluded: boolean; category: string; category_id: number; purchase_date: string; cost: string; fund: string; net: string; rate: string; quota: string };
const props = defineProps<{ year: number; filters: Record<string, string>; rows: Row[]; kpi: Record<string, string | number>; categories: { id: number; code: string; name: string }[]; statuses: Record<string, string> }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const f = ref({ q: '', category: '', status: '', location: '', cost_center: '', purchase_year: '', ...props.filters });
const apply = () => router.get(base.value, Object.fromEntries(Object.entries(f.value).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
const sort = ref<keyof Row>('number');
const dir = ref(1);
const sorted = computed(() => [...props.rows].sort((a, b) => { const x = a[sort.value], y = b[sort.value]; const nx = Number(x), ny = Number(y); return (Number.isFinite(nx) && Number.isFinite(ny) && typeof x !== 'boolean' ? nx - ny : String(x ?? '').localeCompare(String(y ?? ''))) * dir.value; }));
const by = (k: keyof Row) => { if (sort.value === k) dir.value = -dir.value; else { sort.value = k; dir.value = 1; } };
const sel = ref<number[]>([]);
const all = computed({ get: () => sel.value.length > 0 && sel.value.length === props.rows.length, set: (v: boolean) => { sel.value = v ? props.rows.map((r) => r.id) : []; } });
const exportCsv = () => {
    const rows = sorted.value.filter((r) => !sel.value.length || sel.value.includes(r.id));
    const head = ['Codice', 'Descrizione', 'Categoria', 'Acquisto', 'Valore storico', 'Fondo', 'Netto', 'Aliquota', 'Stato'];
    const cell = (v: string) => (/[;"\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
    const csv = '﻿' + [head, ...rows.map((r) => [r.code, r.description, r.category, date(r.purchase_date), r.cost.replace('.', ','), r.fund.replace('.', ','), r.net.replace('.', ','), r.rate.replace('.', ','), props.statuses[r.status] ?? r.status])].map((l) => l.map((v) => cell(String(v))).join(';')).join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = `cespiti-${props.year}.csv`; a.click(); URL.revokeObjectURL(a.href);
};
const tone: Record<string, string> = { attivo: 'good', ammortizzato: 'info', venduto: '', eliminato: '', sospeso: 'warn' };
</script>

<template>
    <Head title="Cespiti" />
    <AppLayout crumb="Cespiti">
        <div class="page-head">
            <div><p class="eyebrow">Cespiti</p><h1>Cespiti · {{ year }}</h1><p>Beni ammortizzabili con piano civilistico e fiscale, movimenti e scritture nella prima nota cespiti. I valori sono al 31/12 dell’anno contabile scelto in alto.</p></div>
            <div class="actions"><Link class="btn primary" :href="`${base}/nuovo`"><Icon name="plus" />Nuovo cespite</Link><Link class="btn" :href="`${base}/importazione`"><Icon name="upload" />Importa progressivi</Link><Link class="btn" :href="`${base}/movimenti`">Movimenti</Link><Link class="btn" :href="`${base}/ammortamenti`">Calcolo ammortamenti</Link></div>
        </div>
        <dl class="facts panel panel-body" style="margin-bottom: 20px">
            <div><dt>Cespiti</dt><dd class="tabular">{{ kpi.count }}</dd></div>
            <div><dt>Costo storico</dt><dd class="tabular">{{ money(String(kpi.cost)) }}</dd></div>
            <div><dt>Fondi</dt><dd class="tabular">{{ money(String(kpi.fund)) }}</dd></div>
            <div><dt>Netto contabile</dt><dd class="tabular">{{ money(String(kpi.net)) }}</dd></div>
            <div><dt>Ammortamenti {{ year }}</dt><dd class="tabular">{{ money(String(kpi.quota)) }}</dd></div>
            <div><dt>Acquisizioni</dt><dd class="tabular">{{ money(String(kpi.acq)) }}</dd></div>
            <div><dt>Alienazioni ed eliminazioni</dt><dd class="tabular">{{ money(String(kpi.alien)) }}</dd></div>
            <div><dt>Plus / minusvalenze</dt><dd class="tabular">{{ money(String(kpi.plus)) }} / {{ money(String(kpi.minus)) }}</dd></div>
        </dl>
        <section class="panel">
            <form class="panel-body mi-grid" style="border-bottom: 1px solid var(--ct-border)" @submit.prevent="apply">
                <div class="field span2"><label for="ce-q">Cerca</label><input id="ce-q" v-model="f.q" class="input" type="search" placeholder="Codice, descrizione, matricola, numero di serie"></div>
                <div class="field"><label for="ce-cat">Categoria</label><select id="ce-cat" v-model="f.category" class="input"><option value="">Tutte</option><option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.code }} · {{ c.name }}</option></select></div>
                <div class="field"><label for="ce-st">Stato</label><select id="ce-st" v-model="f.status" class="input"><option value="">Tutti</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field"><label for="ce-loc">Ubicazione</label><input id="ce-loc" v-model="f.location" class="input"></div>
                <div class="field"><label for="ce-cc">Centro di costo</label><input id="ce-cc" v-model="f.cost_center" class="input"></div>
                <div class="field"><label for="ce-y">Anno di acquisto</label><input id="ce-y" v-model="f.purchase_year" class="input tabular" inputmode="numeric" maxlength="4"></div>
                <div class="actions span2" style="align-self: end"><button class="btn" type="submit"><Icon name="search" />Filtra</button><button class="btn" type="button" @click="exportCsv"><Icon name="download" />{{ sel.length ? `Esporta selezionati (${sel.length})` : 'Esporta CSV' }}</button></div>
            </form>
            <div v-if="rows.length" class="table-wrap">
                <table class="data">
                    <thead><tr>
                        <th style="width: 32px"><input v-model="all" type="checkbox" aria-label="Seleziona tutti"></th>
                        <th><button class="btn link" type="button" @click="by('code')">Codice</button></th><th><button class="btn link" type="button" @click="by('description')">Descrizione</button></th>
                        <th><button class="btn link" type="button" @click="by('category')">Categoria</button></th><th><button class="btn link" type="button" @click="by('purchase_date')">Acquisto</button></th>
                        <th class="num"><button class="btn link" type="button" @click="by('cost')">Valore storico</button></th><th class="num">Fondo</th><th class="num"><button class="btn link" type="button" @click="by('net')">Netto</button></th>
                        <th class="num">Aliquota</th><th>Stato</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="r in sorted" :key="r.id">
                            <td><input v-model="sel" type="checkbox" :value="r.id" :aria-label="`Seleziona ${r.code}`"></td>
                            <td class="tabular"><Link :href="`${base}/${r.id}`">{{ r.code }}</Link></td><td>{{ r.description }}<div v-if="r.location || r.cost_center" class="small muted">{{ [r.location, r.cost_center].filter(Boolean).join(' · ') }}</div></td>
                            <td class="small">{{ r.category }}</td><td class="tabular">{{ date(r.purchase_date) }}</td><td class="num tabular">{{ money(r.cost) }}</td><td class="num tabular">{{ money(r.fund) }}</td>
                            <td class="num tabular">{{ money(r.net) }}</td><td class="num tabular">{{ r.rate.replace('.', ',') }}%</td><td><span class="badge" :class="tone[r.status]">{{ statuses[r.status] ?? r.status }}</span></td>
                            <td class="num" style="white-space: nowrap">
                                <Link class="icon-btn" :href="`${base}/${r.id}`" :aria-label="`Scheda ${r.code}`" title="Scheda"><Icon name="search" /></Link>
                                <Link class="icon-btn" :href="`${base}/${r.id}/modifica`" :aria-label="`Modifica ${r.code}`" title="Modifica"><Icon name="doc" /></Link>
                                <Link class="icon-btn" :href="`${base}/${r.id}/duplica`" method="post" as="button" :aria-label="`Duplica ${r.code}`" title="Duplica"><Icon name="layers" /></Link>
                                <Link class="icon-btn" :href="`${base}/${r.id}#movimenti`" :aria-label="`Movimenta ${r.code}`" title="Movimenta"><Icon name="arrow" /></Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="panel-body"><p class="muted">Nessun cespite. Crea prima le categorie (dalla tabella del D.M. 31/12/1988 o personalizzate), poi inserisci i cespiti o importa i progressivi da un altro gestionale.</p>
                <div class="actions" style="margin-top: 10px"><Link class="btn" :href="`${base}/categorie`">Categorie e aliquote</Link><Link class="btn" :href="`${base}/importazione`">Importazione progressivi</Link></div></div>
        </section>
    </AppLayout>
</template>
