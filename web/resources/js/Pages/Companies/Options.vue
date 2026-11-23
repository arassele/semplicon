<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Funzioni e opzioni: disattivazione dei moduli (poi nascosti), origine della data di pagamento nel registro IVA, verifica del codice fiscale in importazione. */
const props = defineProps<{ options: { modules: Record<string, boolean>; payment_date_source: string; import_check_vat: boolean }; modules: Record<string, string>; year: number; forced: boolean; regime: string | null }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const form = useForm({ modules: { ...props.options.modules }, payment_date_source: props.options.payment_date_source, import_check_vat: props.options.import_check_vat });
const save = () => form.put(`${base.value}/impostazioni/funzioni`, { preserveScroll: true });
</script>

<template>
    <Head title="Funzioni e opzioni" />
    <AppLayout crumb="Funzioni e opzioni">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni</p>
                <h1>Funzioni e opzioni</h1>
                <p>Attiva solo ciò che usi: le funzioni disattivate spariscono dal menu e dalla Panoramica. I dati restano e riappaiono riattivando la funzione.</p>
            </div>
            <button class="btn primary" type="button" :disabled="form.processing || !form.isDirty" @click="save"><Icon name="check" />Salva</button>
        </div>
        <form class="stack" novalidate @submit.prevent="save">
            <section class="panel" aria-labelledby="o-mod">
                <div class="panel-head"><div><h2 id="o-mod">Funzioni</h2></div></div>
                <div class="panel-body">
                    <label v-for="(label, key) in modules" :key="key" class="check"><input v-model="form.modules[key]" type="checkbox">{{ label }}</label>
                </div>
            </section>
            <section class="panel" aria-labelledby="o-pay">
                <div class="panel-head"><div><h2 id="o-pay">Data di incasso e pagamento nel registro IVA</h2><p>Solo per la stampa e la vista fiscale dei registri: non cambia lo Scadenziario. {{ regime ? `Regime ${year}: ${regime}.` : '' }}</p></div></div>
                <div class="panel-body">
                    <div v-if="forced" class="notice info" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div>Con la contabilità semplificata <strong>per competenza</strong> la data di incasso/pagamento coincide sempre con la data di registrazione: la scelta qui sotto vale per gli altri regimi.</div></div>
                    <fieldset class="field">
                        <legend>Per i regimi con incassi e pagamenti (semplificata per cassa, professionisti)</legend>
                        <label class="check"><input v-model="form.payment_date_source" type="radio" value="scadenziario">Prendi la data dallo Scadenziario (data effettiva dell’incasso o del pagamento)</label>
                        <label class="check"><input v-model="form.payment_date_source" type="radio" value="registrazione">Prendi la data dalla registrazione</label>
                    </fieldset>
                </div>
            </section>
            <section class="panel" aria-labelledby="o-imp">
                <div class="panel-head"><div><h2 id="o-imp">Importazione XML</h2></div></div>
                <div class="panel-body">
                    <label class="check"><input v-model="form.import_check_vat" type="checkbox">Controlla la partita IVA / il codice fiscale dell’azienda nei file importati</label>
                    <p class="hint">Se disattivato, i file di un’altra azienda non vengono scartati: verifica tu che appartengano a questa azienda.</p>
                </div>
            </section>
            <div class="actions"><button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Salva</button></div>
        </form>
    </AppLayout>
</template>
