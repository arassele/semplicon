<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Impostazioni cespiti e Esercizi chiusi. */
const props = defineProps<{ settings: Record<string, any>; year: number; closures: { year: number; closed_at: string; reopened_at: string | null; reopen_reason: string | null }[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti`);
const form = useForm({ civil_first_year_half: !!props.settings.civil_first_year_half, sale_year_quota: !!props.settings.sale_year_quota, transit_account: String(props.settings.transit_account ?? ''), reserve_account: String(props.settings.reserve_account ?? ''), low_value_threshold: String(props.settings.low_value_threshold).replace('.', ',') });
const y = ref(props.year);
const close = () => { if (confirm(`Chiudere l’esercizio ${y.value}? Non saranno più possibili movimenti, calcoli o modifiche di aliquote nel ${y.value}.`)) router.post(`${base.value}/esercizi/${y.value}`, {}, { preserveScroll: true }); };
const reopen = (year: number) => { const reason = prompt(`Motivo della riapertura dell’esercizio ${year}?`); if (reason) router.post(`${base.value}/esercizi/${year}`, { reopen: true, reason }, { preserveScroll: true }); };
</script>

<template>
    <Head title="Impostazioni cespiti" />
    <AppLayout crumb="Cespiti · Impostazioni">
        <div class="page-head"><div><p class="eyebrow"><Link :href="base">Cespiti</Link></p><h1>Impostazioni cespiti</h1></div></div>
        <section class="panel" style="margin-bottom: 16px"><div class="panel-head"><h2>Calcolo</h2></div>
            <form class="panel-body mi-grid" @submit.prevent="form.put(`${base}/impostazioni`, { preserveScroll: true })">
                <label class="check span3"><input v-model="form.civil_first_year_half" type="checkbox"> Primo esercizio: quota civilistica a metà come il limite fiscale (metodo ordinario, beni materiali)</label>
                <label class="check span3"><input v-model="form.sale_year_quota" type="checkbox"> Alienazione ed eliminazione: quota dell’esercizio fino alla data, in proporzione ai giorni</label>
                <div class="field"><label for="st-lv">Beni di modesto valore fino a (€)</label><input id="st-lv" v-model="form.low_value_threshold" class="input tabular"><p class="hint">Art. 102 c. 5 TUIR; verifica l’importo vigente.</p><p v-if="form.errors.low_value_threshold" class="error">{{ form.errors.low_value_threshold }}</p></div>
                <div class="field"><label for="st-tr">Conto transitorio cessioni</label><input id="st-tr" v-model="form.transit_account" class="input tabular"></div>
                <div class="field"><label for="st-rs">Conto riserva di rivalutazione</label><input id="st-rs" v-model="form.reserve_account" class="input tabular"></div>
                <div class="actions span3"><button class="btn primary" type="submit"><Icon name="check" />Salva</button></div>
            </form></section>
        <section class="panel"><div class="panel-head"><div><h2>Esercizi chiusi</h2><p>Un esercizio chiuso non accetta movimenti, calcoli, storni o modifiche delle aliquote. La riapertura richiede un motivo e resta nel registro attività.</p></div></div>
            <div class="panel-body actions"><label for="st-y" class="small">Esercizio</label><input id="st-y" v-model.number="y" class="input tabular" style="width: 100px"><button class="btn" type="button" @click="close"><Icon name="lock" />Chiudi esercizio</button></div>
            <ul v-if="closures.length" class="list"><li v-for="c in closures" :key="c.year"><div class="li-row"><span><strong class="tabular">{{ c.year }}</strong> · chiuso il {{ dateTime(c.closed_at) }}<template v-if="c.reopened_at"> · riaperto il {{ dateTime(c.reopened_at) }} ({{ c.reopen_reason }})</template></span>
                <button v-if="!c.reopened_at" class="btn link" type="button" @click="reopen(c.year)">Riapri</button></div></li></ul></section>
    </AppLayout>
</template>
