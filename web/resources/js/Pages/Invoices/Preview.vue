<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Anteprima della fattura ricavata dal file XML (FatturaPA); il PDF è la stessa vista come copia di cortesia. */
const props = defineProps<{ invoice: { id: number; number: string; doc_date: string; source: string; direction: string | null } }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
</script>

<template>
    <Head :title="`Anteprima fattura ${invoice.number}`" />
    <AppLayout crumb="Anteprima fattura">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/fatture/${invoice.id}`">‹ Fattura {{ invoice.number }}</Link></p>
                <h1>Anteprima della fattura</h1>
                <p>Documento n. {{ invoice.number }} del {{ date(invoice.doc_date) }}, ricavato dal file XML. Il PDF è una copia di cortesia: il documento fiscale resta il file FatturaPA.</p>
            </div>
            <div class="actions">
                <a :href="`${base}/fatture/${invoice.id}/pdf`" class="btn primary"><Icon name="download" />Scarica PDF</a>
                <a v-if="invoice.source === 'emessa'" :href="`${base}/fatture/${invoice.id}/xml`" class="btn"><Icon name="download" />Scarica XML</a>
            </div>
        </div>
        <iframe :src="`${base}/fatture/${invoice.id}/anteprima-xml/documento`" title="Anteprima della fattura" class="doc-frame" sandbox="" />
    </AppLayout>
</template>

<style scoped>
.doc-frame { width: 100%; height: min(1100px, 80vh); border: 1px solid var(--ct-border, #dce3dd); border-radius: 12px; background: #fff; }
</style>
