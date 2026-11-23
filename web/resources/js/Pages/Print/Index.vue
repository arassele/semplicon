<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Stampa: Registro IVA, Liquidazione IVA, (Professionisti) Registro incassi e pagamenti – per l’anno contabile scelto a livello centrale. */
const props = defineProps<{ year: number; periodicity: string | null; periods: { value: number; label: string }[]; confirmed: number[]; regime: string | null; cashRegister: boolean }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const reg = ref<'vendite' | 'acquisti'>('vendite');
const regPeriod = ref<number | ''>('');
const liqPeriod = ref<number | null>(props.periods[0]?.value ?? null);
const month = ref<number | ''>('');
const months = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const regQuery = computed(() => `anno=${props.year}${regPeriod.value ? `&periodo=${regPeriod.value}` : ''}`);
</script>

<template>
    <Head title="Stampa" />
    <AppLayout crumb="Stampa">
        <div class="page-head">
            <div>
                <p class="eyebrow">Report e scadenze</p>
                <h1>Stampa · {{ year }}</h1>
                <p>Stampe contabili in PDF per l’anno contabile scelto in alto<template v-if="regime"> ({{ regime }})</template>.</p>
            </div>
        </div>

        <section class="panel" aria-labelledby="p-reg">
            <div class="panel-head"><div><h2 id="p-reg">Registro IVA</h2><p>Registro vendite o acquisti con le registrazioni dell’anno o di un periodo, la data di incasso/pagamento e i totali per aliquota.</p></div></div>
            <div class="panel-body mi-grid">
                <fieldset class="field span3"><legend>Registro</legend>
                    <div class="segmented"><label><input v-model="reg" type="radio" value="vendite">Vendite</label><label><input v-model="reg" type="radio" value="acquisti">Acquisti</label></div></fieldset>
                <div v-if="periods.length" class="field span2"><label for="pr-per">Periodo</label>
                    <select id="pr-per" v-model="regPeriod" class="input"><option value="">Tutto l’anno {{ year }}</option><option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option></select></div>
                <div class="actions span3">
                    <a class="btn primary" :href="`${base}/registri/${reg}/pdf?${regQuery}`"><Icon name="download" />Stampa registro (PDF)</a>
                    <a class="btn" :href="`${base}/registri/${reg}/csv?${regQuery}`"><Icon name="download" />CSV</a>
                </div>
            </div>
        </section>

        <section class="panel" aria-labelledby="p-liq">
            <div class="panel-head"><div><h2 id="p-liq">Liquidazione IVA</h2><p>Prospetto della liquidazione di un periodo (se non è ancora confermata, è una bozza).</p></div></div>
            <div v-if="periods.length" class="panel-body mi-grid">
                <div class="field span2"><label for="pl-per">Periodo</label>
                    <select id="pl-per" v-model="liqPeriod" class="input"><option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}{{ confirmed.includes(p.value) ? ' · confermata' : ' · bozza' }}</option></select></div>
                <div class="actions span3"><a class="btn primary" :href="`${base}/liquidazioni/${year}/${liqPeriod}/pdf`"><Icon name="download" />Stampa liquidazione (PDF)</a></div>
            </div>
            <p v-else class="panel-body muted">Nessun regime IVA confermato per il {{ year }}: confermalo in Regime fiscale e IVA.</p>
        </section>

        <section v-if="cashRegister" class="panel" aria-labelledby="p-cash">
            <div class="panel-head"><div><h2 id="p-cash">Registro incassi e pagamenti</h2><p>Per la contabilità dei professionisti: incassi e pagamenti registrati nello Scadenziario, con i documenti abbinati e i totali.</p></div></div>
            <div class="panel-body mi-grid">
                <div class="field span2"><label for="pc-m">Periodo</label>
                    <select id="pc-m" v-model="month" class="input"><option value="">Tutto l’anno {{ year }}</option><option v-for="(m, i) in months" :key="i" :value="i + 1">{{ m }} {{ year }}</option></select></div>
                <div class="actions span3"><a class="btn primary" :href="`${base}/stampa/incassi-pagamenti?anno=${year}${month ? `&mese=${month}` : ''}`"><Icon name="download" />Stampa registro (PDF)</a></div>
            </div>
        </section>
    </AppLayout>
</template>
