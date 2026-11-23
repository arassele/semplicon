<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Item = { type: string; date: string | null; label: string; year: number; period: number | null; amount: string; code: string | null; state: string; review: boolean; detail: string; f24_id: number | null };
type Cal = { date: string; from: string | null; type: string; label: string; code: string | null; note: string | null };
const props = defineProps<{ items: Item[]; today: string; year: number; periodicity: string | null; calendar: Cal[]; types: Record<string, string> }>();
const filter = ref<string>('');
const shown = computed(() => props.calendar.filter((c) => !filter.value || c.type === filter.value));
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const overdue = (i: Item) => i.state === 'aperta' && i.date !== null && i.date < props.today;
</script>

<template>
    <Head title="Scadenze fiscali" />
    <AppLayout crumb="Scadenze fiscali">
        <div class="page-head"><div><p class="eyebrow">Report e scadenze</p><h1>Scadenze fiscali</h1>
            <p>Versamenti IVA delle liquidazioni confermate e termini IVA TR. La sola generazione del PDF non chiude la scadenza: si chiude con la delega presentata.</p></div></div>

        <section class="panel">
            <div v-if="items.length" class="table-wrap">
                <table class="register">
                    <thead><tr><th>Scadenza</th><th>Adempimento</th><th>Codice</th><th class="num">Importo</th><th>Stato</th></tr></thead>
                    <tbody><tr v-for="(i, k) in items" :key="k" :class="{ 'row-stornato': i.state === 'chiusa' }">
                        <td class="tabular">{{ i.date ? date(i.date) : '—' }}</td>
                        <td><Link v-if="i.period" :href="`${base}/liquidazioni/${i.year}/${i.period}`">{{ i.label }}</Link><Link v-else :href="`${base}/crediti-iva`">{{ i.label }}</Link><br><span class="small muted">{{ i.detail }}</span></td>
                        <td>{{ i.code ?? '' }}</td><td class="num tabular">{{ money(i.amount) }}</td>
                        <td><span class="badge" :class="i.state === 'chiusa' ? 'good' : overdue(i) ? 'bad' : 'warn'">{{ i.state === 'chiusa' ? 'Chiusa' : overdue(i) ? 'Scaduta' : 'Aperta' }}</span><template v-if="i.review"> <span class="badge warn">Da riesaminare</span></template></td>
                    </tr></tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Nessuna scadenza: le scadenze nascono dalle liquidazioni confermate.</p>
        </section>

        <section class="panel" aria-labelledby="cal-title" style="margin-top: 24px">
            <div class="panel-head">
                <div><h2 id="cal-title">Calendario fiscale {{ year }}</h2>
                    <p>Termini ordinari dell’anno: versamenti IVA<template v-if="periodicity"> ({{ periodicity === 'mensile' ? 'mensili' : 'trimestrali' }})</template>, dichiarazione IVA, LIPE, bollo sulle fatture elettroniche e invio delle operazioni transfrontaliere. Se cadono in un giorno festivo slittano al primo giorno lavorativo. Verifica sempre eventuali proroghe alla fonte.</p></div>
                <label class="inline-label">Mostra <select v-model="filter" class="input" style="width: auto"><option value="">Tutti</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></label>
            </div>
            <p v-if="!periodicity" class="panel-body small muted">Per vedere i versamenti IVA periodici conferma il regime {{ year }} (periodicità mensile o trimestrale).</p>
            <div class="table-wrap">
                <table class="register">
                    <thead><tr><th>Scadenza</th><th>Adempimento</th><th>Tipo</th><th>Codice</th></tr></thead>
                    <tbody>
                        <tr v-for="(c, k) in shown" :key="k" :class="{ 'row-stornato': c.date < today }">
                            <td class="tabular nowrap">{{ date(c.date) }}<template v-if="c.from"><br><span class="small muted">da {{ date(c.from) }}</span></template></td>
                            <td>{{ c.label }}<template v-if="c.note"><br><span class="small muted">{{ c.note }}</span></template></td>
                            <td class="small">{{ types[c.type] }}</td>
                            <td class="tabular">{{ c.code ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
