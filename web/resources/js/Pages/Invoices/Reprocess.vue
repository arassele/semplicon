<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date } from '@/lib/format';
import type { SharedProps } from '@/types';

type Change = { change?: string; field?: string; label?: string; index?: number; line_no?: number; old: string | null; new: string | null };
const props = defineProps<{
    r: { id: number; status: string; message: string | null; diff: { fields: Change[]; summaries: Change[]; lines: Change[] } | null; impacts: { type: string; text: string }[];
        candidates: { id: number; number: string; doc_date: string; status: string; counterparty: string; total: string }[]; booking_relevant: boolean; document: string | null; number: string; doc_date: string };
    invoice: { id: number; number: string; status: string; status_label: string; registered: boolean; protocol: string | null } | null;
    locked: string | null;
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const busy = ref(false);
const opts = { onFinish: () => (busy.value = false) };
const apply = (storno = false) => { busy.value = true; router.post(`${base.value}/rielaborazioni/${props.r.id}/conferma`, { storno: storno ? 1 : 0 }, opts); };
const discard = () => { busy.value = true; router.post(`${base.value}/rielaborazioni/${props.r.id}/scarta`, {}, opts); };
const choice = ref<string>('');
const assign = () => { busy.value = true; router.post(`${base.value}/rielaborazioni/${props.r.id}/abbina`, { invoice_id: choice.value }, opts); };
const CH: Record<string, string> = { aggiunta: 'Aggiunta', modificata: 'Modificata', rimossa: 'Rimossa' };
const err = computed(() => page.props.errors.reprocessing || page.props.errors.assign);
</script>

<template>
    <Head title="Nuova elaborazione XML" />
    <AppLayout crumb="Rielabora XML">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link v-if="invoice" :href="`${base}/fatture/${invoice.id}`">Fattura {{ invoice.number }}</Link><template v-else>Importazione</template></p>
                <h1>{{ r.status === 'da_abbinare' ? 'Corrispondenza da verificare' : 'Differenze dalla nuova elaborazione' }}</h1>
                <p>Documento n. {{ r.number }} del {{ date(r.doc_date) }}<template v-if="r.document"> · file {{ r.document }}</template>. I dati della fattura cambiano solo dopo la conferma; la versione precedente resta conservata e la modifica è nel registro attività.</p>
            </div>
        </div>
        <div v-if="err" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ err }}</div></div>
        <div v-if="r.message && r.status !== 'in_attesa'" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ r.message }}</div></div>

        <section v-if="r.status === 'da_abbinare'" class="panel">
            <div class="panel-head"><div><h2>Scegli il documento da aggiornare</h2><p>Più documenti dello stesso emittente, tipo, anno e numero, oppure dati discordanti (data o cessionario). Nessun dato viene sostituito senza la tua scelta.</p></div></div>
            <div class="panel-body">
                <label v-for="c in r.candidates" :key="c.id" class="check"><input v-model="choice" type="radio" name="cand" :value="String(c.id)"> <span>Fattura <Link :href="`${base}/fatture/${c.id}`">{{ c.number }}</Link> del {{ date(c.doc_date) }} · {{ c.counterparty }} · stato {{ c.status }}</span></label>
                <label class="check"><input v-model="choice" type="radio" name="cand" value="nuovo"> <span>Nessuno: importa come nuovo documento</span></label>
                <div class="actions" style="margin-top: 12px"><button class="btn primary" type="button" :disabled="!choice || busy" @click="assign">Conferma la scelta</button><button class="btn" type="button" :disabled="busy" @click="discard">Scarta</button></div>
            </div>
        </section>

        <template v-else-if="r.diff">
            <div v-if="invoice?.registered" class="notice" :class="locked ? 'bad' : 'warn'" role="status">
                <Icon name="alert" style="width: 20px; flex: none" />
                <div><strong>Fattura registrata (prot. {{ invoice.protocol }})</strong>
                    <template v-if="!r.booking_relevant">Le differenze non toccano la registrazione: la fattura si aggiorna direttamente.</template>
                    <template v-else-if="locked">La registrazione è nel periodo {{ locked }}, già liquidato: l’aggiornamento diretto è bloccato. Rettifica: la registrazione viene stornata oggi (periodo corrente) e la fattura aggiornata torna da registrare.</template>
                    <template v-else>Le differenze cambiano la registrazione: confermando, la registrazione viene ricalcolata con gli stessi conti e % detraibile e la liquidazione del periodo va riesaminata.</template></div>
            </div>
            <section v-if="r.impacts.length" class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Effetti</h2></div>
                <ul class="panel-body plain small"><li v-for="(x, i) in r.impacts" :key="i"><strong>{{ x.type }}</strong> · {{ x.text }}</li></ul></section>
            <section v-if="r.diff.fields.length" class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Dati del documento</h2></div>
                <div class="table-wrap"><table><thead><tr><th>Campo</th><th>Attuale</th><th>Nuova elaborazione</th></tr></thead>
                    <tbody><tr v-for="f in r.diff.fields" :key="f.field"><td>{{ f.label }}</td><td class="muted">{{ f.old ?? '—' }}</td><td><strong>{{ f.new ?? '—' }}</strong></td></tr></tbody></table></div></section>
            <section v-if="r.diff.summaries.length" class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Riepilogo IVA</h2></div>
                <div class="table-wrap"><table><thead><tr><th>Gruppo</th><th>Variazione</th><th>Attuale</th><th>Nuova elaborazione</th></tr></thead>
                    <tbody><tr v-for="(x, i) in r.diff.summaries" :key="i"><td>{{ (x.index ?? 0) + 1 }}</td><td>{{ CH[x.change ?? ''] }}</td><td class="muted">{{ x.old ?? '—' }}</td><td><strong>{{ x.new ?? '—' }}</strong></td></tr></tbody></table></div></section>
            <section v-if="r.diff.lines.length" class="panel" style="margin-top: 16px"><div class="panel-head"><h2>Righe del documento</h2></div>
                <div class="table-wrap"><table><thead><tr><th>Riga</th><th>Variazione</th><th>Attuale</th><th>Nuova elaborazione</th></tr></thead>
                    <tbody><tr v-for="(x, i) in r.diff.lines" :key="i"><td class="tabular">{{ x.line_no }}</td><td>{{ CH[x.change ?? ''] }}</td><td class="muted">{{ x.old ?? '—' }}</td><td><strong>{{ x.new ?? '—' }}</strong></td></tr></tbody></table></div></section>
            <div v-if="['in_attesa', 'bloccata'].includes(r.status)" class="actions" style="margin-top: 20px">
                <button v-if="!(invoice?.registered && r.booking_relevant && locked)" class="btn primary" type="button" :disabled="busy" @click="apply(false)"><Icon name="check" />Conferma e aggiorna</button>
                <button v-if="invoice?.registered && r.booking_relevant" class="btn" :class="{ primary: !!locked }" type="button" :disabled="busy" @click="apply(true)">Storna e aggiorna</button>
                <button class="btn" type="button" :disabled="busy" @click="discard">Scarta</button>
            </div>
        </template>
    </AppLayout>
</template>
