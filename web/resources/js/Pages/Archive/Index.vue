<script setup lang="ts">
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Archivio annuale: ZIP con registri, liquidazioni, XML emessi, originali e registro attività, con impronte SHA-256. */
defineProps<{
    years: number[];
    chain: { ok: boolean; checked: number; legacy: number; head: string | null; broken_id: number | null; reason: string | null };
    previous: { at: string | null; year: number | null; sha256: string | null; files: number | null }[];
}>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
</script>

<template>
    <Head title="Archivio annuale" />
    <AppLayout crumb="Archivio annuale">
        <div class="page-head">
            <div>
                <p class="eyebrow">Report e scadenze</p>
                <h1>Archivio annuale</h1>
                <p>Un file ZIP per anno con registri IVA, liquidazioni, fatture emesse in XML, documenti originali e registro attività. Ogni file ha la sua impronta SHA-256 (file SHA256SUMS): per la consegna al commercialista o per la conservazione.</p>
            </div>
        </div>

        <div class="notice" :class="chain.ok ? 'good' : 'bad'" :role="chain.ok ? 'status' : 'alert'" style="margin-bottom: 16px">
            <Icon :name="chain.ok ? 'check' : 'alert'" style="width: 20px; flex: none" />
            <div>
                <strong>{{ chain.ok ? 'Registro attività integro' : 'Registro attività: catena interrotta' }}</strong>
                <template v-if="chain.ok">{{ chain.checked }} eventi verificati con la catena di hash<template v-if="chain.legacy">, {{ chain.legacy }} precedenti alla catena non verificabili</template>.</template>
                <template v-else>Evento {{ chain.broken_id }}: {{ chain.reason }}</template>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head"><h2>Scarica l’archivio</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Anno</th><th><span class="sr-only">Azione</span></th></tr></thead>
                    <tbody>
                        <tr v-for="y in years" :key="y"><td class="tabular">{{ y }}</td><td class="num"><a class="btn" :href="`${base}/archivio/${y}`"><Icon name="download" />Scarica archivio {{ y }}</a></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="previous.length" class="panel">
            <div class="panel-head"><div><h2>Archivi scaricati</h2><p>L’impronta di SHA256SUMS prova che il contenuto non è cambiato. Confrontala con quella nel file.</p></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Quando</th><th>Anno</th><th class="num">File</th><th>Impronta SHA-256 di SHA256SUMS</th></tr></thead>
                    <tbody>
                        <tr v-for="(p, i) in previous" :key="i"><td>{{ dateTime(p.at) }}</td><td class="tabular">{{ p.year }}</td><td class="num tabular">{{ p.files }}</td><td class="tabular small" style="word-break: break-all">{{ p.sha256 }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
