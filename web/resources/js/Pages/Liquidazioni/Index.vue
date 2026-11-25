<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/lib/format';
import type { SharedProps } from '@/types';

type Row = { period: number; label: string; status: string; version: number | null; result: string | null; due: string | null; credit: string | null; deferred: string | null;
    due_date: string | null; checks: number; empty: boolean; f24: { id: number; status: string; label: string } | null; year: number; pay_state: 'scaduto' | 'da_versare' | 'nessuno' | null; term_passed: boolean };
defineProps<{ year: number | 'tutti'; years: number[]; profile: { label: string; periodicity: string; pro_rata: string } | null; issues: { field: string; message: string }[]; rows: Row[]; lipe: Lipe[]; rules: string | null }>();
type Lipe = { quarter: number; label: string; ok: boolean; reasons: string[]; modules: number; deadline: string; filename: string | null };
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const STATUS: Record<string, [string, string]> = { bozza: ['Bozza', ''], confermata: ['Confermata', 'good'], riesaminare: ['Da riesaminare', 'warn'] };
const RESULT: Record<string, string> = { debito: 'A debito', credito: 'A credito', zero: 'A zero' };
</script>

<template>
    <Head title="Liquidazioni IVA" />
    <AppLayout crumb="Liquidazioni IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow">Liquidazioni</p>
                <h1>Liquidazioni IVA {{ year === 'tutti' ? '· tutti gli anni' : year }}</h1>
                <p>Imposta del periodo dai registri IVA, conferma, delega F24 o destinazione del credito. Il periodo IVA è quello attribuito alla registrazione.</p>
            </div>
            <div class="actions">
                <label class="sr-only" for="lq-year">Anno</label>
                <select id="lq-year" class="input" :value="year" @change="router.get(`${base}/liquidazioni`, { anno: ($event.target as HTMLSelectElement).value })"><option value="tutti">Tutti</option><option v-for="y in [...new Set([...(year === 'tutti' ? [] : [year + 1, year]), ...years])].sort((a, b) => b - a)" :key="y" :value="y">{{ y }}</option></select>
                <Link class="btn" :href="`${base}/crediti-iva`"><Icon name="shield" />Registro crediti</Link>
            </div>
        </div>
        <p v-if="profile" class="small muted" style="margin-top: -8px">{{ profile.label }} · {{ profile.periodicity }} · pro rata {{ profile.pro_rata.replace('.', ',') }}%<template v-if="rules"> · regole {{ rules }}</template></p>
        <div v-for="i in issues" :key="i.field" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Da completare</strong><p>{{ i.message }}</p>
            <Link v-if="['regime', 'periodicity', 'quarterly_type'].includes(i.field)" :href="`${base}/profilo-iva?anno=${year}`">Apri Regime IVA {{ year }}</Link></div></div>
        <section v-if="rows.length" class="panel">
            <div class="table-wrap">
                <table class="register">
                    <thead><tr><th>Periodo</th><th>Stato</th><th>Risultato</th><th class="num">Importo</th><th>Scadenza</th><th>F24</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="`${r.year}-${r.period}`" :class="{ 'row-stornato': r.empty }">
                            <td><Link :href="`${base}/liquidazioni/${r.year}/${r.period}`"><strong>{{ r.label }}</strong></Link></td>
                            <td><span class="badge" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}<template v-if="r.version"> · v{{ r.version }}</template></span>
                                <template v-if="r.checks"> <span class="badge warn">Da verificare ({{ r.checks }})</span></template><template v-if="r.empty"> <span class="small muted">nessun movimento</span></template></td>
                            <td>{{ r.result ? RESULT[r.result] : '—' }}<template v-if="r.deferred && r.deferred !== '0.00'"><br><span class="small muted">rinviato {{ money(r.deferred) }}</span></template></td>
                            <td class="num tabular">{{ r.result === 'credito' ? money(r.credit) : r.due ? money(r.due) : '—' }}</td>
                            <td class="tabular">{{ r.due_date ? date(r.due_date) : '—' }}<template v-if="r.term_passed"><br><span class="badge bad">Termine superato</span></template></td>
                            <td><span v-if="r.f24 && r.pay_state !== 'scaduto'" class="badge" :class="r.f24.status === 'scartato' ? 'bad' : r.f24.status === 'bozza' ? 'info' : 'good'">{{ r.f24.label }}</span>
                                <span v-if="r.pay_state === 'scaduto'" class="badge bad">Da versare · scaduto il {{ r.due_date ? date(r.due_date) : '—' }}</span>
                                <span v-else-if="r.pay_state === 'da_versare' && !r.f24" class="badge warn">Da versare entro il {{ r.due_date ? date(r.due_date) : '—' }}</span>
                                <span v-else-if="r.pay_state === 'nessuno'" class="small muted">nessun versamento</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section v-if="lipe.length" class="panel" aria-labelledby="lipe-title" style="margin-top: 24px">
            <div class="panel-head"><div><h2 id="lipe-title">LIPE · Comunicazione delle liquidazioni periodiche</h2>
                <p>Un file XML per trimestre dalle liquidazioni confermate, da inviare con il tuo canale (Entratel o intermediario): Semplicon non trasmette. La stampa di controllo mostra i righi VP2–VP14.</p></div></div>
            <div class="table-wrap">
                <table class="register">
                    <thead><tr><th>Trimestre</th><th>Stato</th><th>Scadenza invio</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="q in lipe" :key="q.quarter">
                            <td><strong>{{ q.label }}</strong><br><span v-if="q.filename" class="small muted tabular">{{ q.filename }}</span></td>
                            <td><span class="badge" :class="q.ok ? 'good' : 'warn'">{{ q.ok ? `Pronta · ${q.modules} ${q.modules === 1 ? 'modulo' : 'moduli'}` : 'Da completare' }}</span>
                                <ul v-if="!q.ok" class="plain small muted"><li v-for="(r, k) in q.reasons" :key="k">{{ r }}</li></ul></td>
                            <td class="tabular">{{ date(q.deadline) }}</td>
                            <td class="num" style="white-space: nowrap">
                                <a v-if="q.ok" class="btn" :href="`${base}/liquidazioni/lipe/${year}/${q.quarter}/xml`"><Icon name="download" />File XML</a>
                                <a class="btn" :href="`${base}/liquidazioni/lipe/${year}/${q.quarter}/pdf`"><Icon name="download" />Stampa di controllo</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
