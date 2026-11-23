<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

type Rule = { id: number; party: string; party_key: string; scope: string; contains: string; account: string; created_at: string };
defineProps<{ rules: Rule[] }>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const remove = (r: Rule) => router.delete(`${base.value}/tabelle/regole/${r.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Regole di contabilizzazione" />
    <AppLayout crumb="Tabelle · Regole di contabilizzazione">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni · Tabelle</p>
                <h1>Regole di contabilizzazione</h1>
                <p>Regole che hai confermato registrando una fattura: per lo stesso fornitore o cliente, quando la descrizione di una riga contiene il testo indicato, Semplicon propone il conto. La regola salva solo il conto; la % detraibile viene sempre dall’impostazione attuale del conto.</p>
            </div>
        </div>
        <section class="panel">
            <div v-if="rules.length" class="table-wrap">
                <table>
                    <thead><tr><th>Fornitore / cliente</th><th>Registro</th><th>La descrizione contiene</th><th>Conto proposto</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="r in rules" :key="r.id">
                            <td><strong>{{ r.party }}</strong><br><span class="small muted tabular">{{ r.party_key }}</span></td>
                            <td>{{ r.scope === 'acquisti' ? 'Acquisti' : 'Vendite' }}</td>
                            <td>“{{ r.contains }}”</td>
                            <td>{{ r.account }}<br><span class="small muted">dal {{ dateTime(r.created_at) }}</span></td>
                            <td><button class="btn link" type="button" style="color: var(--ct-error)" @click="remove(r)">Elimina</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessuna regola. Registrando una fattura puoi scegliere “Ricorda questa scelta” accanto al conto.</p>
        </section>
    </AppLayout>
</template>
