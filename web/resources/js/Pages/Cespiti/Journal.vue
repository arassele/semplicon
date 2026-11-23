<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Prima nota cespiti: scritture contabili immodificabili dell’anno; le correzioni come Storno. */
const props = defineProps<{ year: number; entries: { id: number; number: string; date: string; description: string; status: string; source: string; user: string | null; lines: { account: string; dare: string; avere: string; description: string | null }[] }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const sum = (k: 'dare' | 'avere') => props.entries.flatMap((e) => e.lines).reduce((t, l) => t + Number(l[k]), 0).toFixed(2);
</script>

<template>
    <Head title="Prima nota cespiti" />
    <AppLayout crumb="Cespiti · Prima nota">
        <div class="page-head"><div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>Prima nota cespiti · {{ year }}</h1><p>Scritture generate dal modulo (ammortamenti, alienazioni, eliminazioni, rivalutazioni, svalutazioni, rettifiche, riclassificazioni). Non sostituisce la contabilità generale.</p></div></div>
        <section class="panel"><div v-if="entries.length" class="table-wrap"><table class="data">
            <thead><tr><th>N.</th><th>Data</th><th>Conto</th><th>Descrizione</th><th class="num">Dare</th><th class="num">Avere</th></tr></thead>
            <tbody v-for="e in entries" :key="e.id" :style="e.status === 'stornato' ? 'color: var(--ct-muted)' : ''">
                <tr><td class="tabular"><strong>{{ e.number }}</strong></td><td class="tabular">{{ date(e.date) }}</td><td colspan="4"><strong>{{ e.description }}</strong> <span class="small muted">· {{ e.source }} · {{ e.user }}</span> <span v-if="e.status === 'stornato'" class="badge">stornata</span></td></tr>
                <tr v-for="(l, k) in e.lines" :key="k"><td></td><td></td><td>{{ l.account }}</td><td class="small">{{ l.description }}</td><td class="num tabular">{{ l.dare !== '0.00' ? money(l.dare) : '' }}</td><td class="num tabular">{{ l.avere !== '0.00' ? money(l.avere) : '' }}</td></tr>
            </tbody>
            <tfoot><tr class="sum-row"><td colspan="4">Totale</td><td class="num tabular">{{ money(sum('dare')) }}</td><td class="num tabular">{{ money(sum('avere')) }}</td></tr></tfoot>
        </table></div><p v-else class="panel-body muted">Nessuna scrittura nel {{ year }}.</p></section>
    </AppLayout>
</template>
