<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

/* Fatture ricorrenti: modelli creati dal modulo di emissione; emissione manuale o automatica (ogni mattina). */
type Item = { id: number; name: string; customer: string; frequency: string; next_date: string; end_date: string | null; active: boolean; auto_issue: boolean; due: boolean; ended: boolean; runs: number;
    last_run_at: string | null; last_invoice: { id: number; number: string } | null; last_error: string | null; lines: number };
defineProps<{ today: string; items: Item[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}`);
const freq: Record<string, string> = { mensile: 'Ogni mese', trimestrale: 'Ogni 3 mesi', semestrale: 'Ogni 6 mesi', annuale: 'Ogni anno' };
const del = (i: Item) => { if (confirm(`Eliminare la fattura ricorrente «${i.name}»? Le fatture già emesse restano.`)) router.delete(`${base.value}/fatture/ricorrenti/${i.id}`, { preserveScroll: true }); };
const issue = (i: Item) => { if (confirm(`Emettere ora la fattura «${i.name}» con la data di oggi?`)) router.post(`${base.value}/fatture/ricorrenti/${i.id}/emetti`); };
</script>

<template>
    <Head title="Fatture ricorrenti" />
    <AppLayout crumb="Fatture ricorrenti">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/vendite`">Vendite</Link></p>
                <h1>Fatture ricorrenti</h1>
                <p>Canoni, abbonamenti e consulenze periodiche. Il modello si crea dal modulo «Emetti fattura»; alla scadenza emetti con un clic oppure lasci fare a Semplicon ogni mattina. Nella causale e nelle righe puoi usare <code>{mese}</code>, <code>{anno}</code> e <code>{periodo}</code>.</p>
            </div>
            <div class="actions"><Link :href="`${base}/fatture/emetti`" class="btn primary"><Icon name="plus" />Nuovo modello</Link></div>
        </div>

        <section class="panel">
            <div v-if="items.length" class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Cliente</th><th>Frequenza</th><th>Prossima emissione</th><th>Stato</th><th>Ultima</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="i in items" :key="i.id">
                            <td>{{ i.name }}<br><span class="small muted">{{ i.lines }} {{ i.lines === 1 ? 'riga' : 'righe' }}</span></td>
                            <td>{{ i.customer }}</td>
                            <td>{{ freq[i.frequency] }}<template v-if="i.end_date"><br><span class="small muted">fino al {{ date(i.end_date) }}</span></template></td>
                            <td class="tabular">{{ i.ended ? '—' : date(i.next_date) }}</td>
                            <td><span v-if="i.ended" class="badge">Conclusa</span><span v-else-if="!i.active" class="badge">Sospesa</span><span v-else-if="i.due" class="badge warn">Da emettere</span><span v-else class="badge good">Attiva</span>
                                <span v-if="i.auto_issue && i.active && !i.ended" class="small muted"><br>emissione automatica</span></td>
                            <td class="small"><template v-if="i.last_invoice"><Link :href="`${base}/fatture/${i.last_invoice.id}`">n. {{ i.last_invoice.number }}</Link><br><span class="muted">{{ dateTime(i.last_run_at) }} · {{ i.runs }} emesse</span></template><template v-else><span class="muted">mai emessa</span></template>
                                <p v-if="i.last_error" class="error">{{ i.last_error }}</p></td>
                            <td class="num" style="white-space: nowrap">
                                <button v-if="i.due && !i.ended" class="btn primary" type="button" @click="issue(i)">Emetti ora</button>
                                <button v-if="!i.ended" class="btn link" type="button" @click="router.post(`${base}/fatture/ricorrenti/${i.id}/stato`, {}, { preserveScroll: true })">{{ i.active ? 'Sospendi' : 'Riattiva' }}</button>
                                <button class="btn link" type="button" @click="del(i)">Elimina</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="panel-body"><p class="muted">Nessuna fattura ricorrente. Compila una fattura in «Emetti fattura» e scegli «Salva come fattura ricorrente».</p></div>
        </section>
    </AppLayout>
</template>
