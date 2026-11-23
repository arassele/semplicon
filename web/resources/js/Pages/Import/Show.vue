<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

type Party = { name: string; vat: string | null; cf: string | null };
type Result = { status: string; reprocessing_id?: number; body?: number; number?: string; date?: string; doc_type?: string; invoice_id?: number; existing_id?: number; message: string; parties?: { cedente: Party; cessionario: Party }; issues?: string[] };
type Item = { id: number; parent_id: number | null; filename: string; status: string; status_label: string; messages: string[]; invoices: number; document_id: number | null; results: Result[] };
const props = defineProps<{
    batch: { id: number; status_label: string; running: boolean; files: number; invoices: number; duplicates: number; to_review: number; errors: number; created_by: string | null; created_at: string };
    items: Item[];
    summary: string;
}>();
/* Ticket 08: esito per corpo fattura. */
const resultLabel: Record<string, string> = { importata: 'Importata', gia_presente: 'Già presente', invariato: 'Documento invariato', modificato: 'Modificato: da confermare', da_abbinare: 'Corrispondenza da verificare', archiviata: 'Archiviata', integrazione_abbinata: 'Abbinata a integrazione', conflitto: 'Possibile conflitto', azienda_non_corrispondente: 'Azienda non corrispondente', non_leggibile: 'File non leggibile' };
const resultTone: Record<string, string> = { importata: 'good', gia_presente: '', invariato: '', modificato: 'warn', da_abbinare: 'warn', archiviata: '', integrazione_abbinata: 'good', conflitto: 'warn', azienda_non_corrispondente: 'bad', non_leggibile: 'bad' };
const partyText = (p: Party) => [p.name, p.vat, p.cf].filter(Boolean).join(' · ');
const isoDate = (d?: string) => (d ? d.split('-').reverse().join('/') : '');

const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const removeFile = (it: { document_id: number | null; filename: string }) => { if (it.document_id && confirm(`Eliminare il file «${it.filename}» e le sue fatture non registrate? Potrai importarlo di nuovo.`)) router.delete(`${base.value}/documenti/${it.document_id}`, { preserveScroll: true }); };
const tone: Record<string, string> = { importato: 'good', da_verificare: 'warn', duplicato: '', errore: 'bad', archivio: 'info', in_attesa: 'warn' };

// Durante l’elaborazione ricarica ogni 2 secondi.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => { if (props.batch.running) timer = setInterval(() => router.reload({ only: ['batch', 'items'] }), 2000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Importazione" />
    <AppLayout crumb="Importazione">
        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="`${base}/importa`">Importa XML</Link></p>
                <h1>Importazione del {{ dateTime(batch.created_at) }}</h1>
                <p>{{ batch.created_by }} · {{ batch.status_label }}</p>
            </div>
            <Link v-if="!batch.running && batch.invoices" :href="`${base}/fatture?stato=${batch.to_review ? 'da_verificare' : ''}`" class="btn primary">Vai alle fatture<Icon name="arrow" /></Link>
        </div>

        <div v-if="batch.running" class="notice info" role="status" style="margin-bottom: 24px">
            <Icon name="clock" style="width: 20px; flex: none" /><span>Elaborazione in corso. La pagina si aggiorna da sola.</span>
        </div>

        <p v-if="summary && !batch.running" class="notice info import-summary" role="status"><Icon name="check" style="width: 20px; flex: none" /><span>{{ summary }}</span></p>
        <section class="panel">
            <dl class="facts panel-body">
                <div><dt>File</dt><dd class="tabular">{{ batch.files }}</dd></div>
                <div><dt>Fatture importate</dt><dd class="tabular">{{ batch.invoices }}</dd></div>
                <div><dt>Già presenti</dt><dd class="tabular">{{ batch.duplicates }}</dd></div>
                <div><dt>Da verificare</dt><dd class="tabular">{{ batch.to_review }}</dd></div>
                <div><dt>Errori</dt><dd class="tabular">{{ batch.errors }}</dd></div>
            </dl>
        </section>

        <section class="panel" aria-labelledby="items-title" style="margin-top: 24px">
            <div class="panel-head"><h2 id="items-title">File</h2></div>
            <ul class="list" style="margin-top: 10px">
                <li v-for="it in items" :key="it.id" :class="{ child: it.parent_id }" style="display: block">
                    <div class="li-row">
                        <p class="title">{{ it.filename }}</p>
                        <div class="actions">
                            <span class="badge" :class="tone[it.status]">{{ it.status_label }}</span>
                            <a v-if="it.document_id" :href="`${base}/documenti/${it.document_id}/scarica`" class="btn link">Originale</a>
                            <button v-if="it.document_id" class="btn link" type="button" title="Elimina il file importato e le sue fatture non registrate: potrai importarlo di nuovo" @click="removeFile(it)">Elimina file</button>
                        </div>
                    </div>
                    <ul v-if="it.results.length" class="doc-results">
                        <li v-for="(r, k) in it.results" :key="k">
                            <div class="li-row">
                                <span><span class="badge" :class="resultTone[r.status]">{{ resultLabel[r.status] }}</span>
                                    <template v-if="r.number"> {{ r.doc_type }} n. <strong>{{ r.number }}</strong> del {{ isoDate(r.date) }}<template v-if="it.results.length > 1"> · documento {{ r.body }} del file</template></template>
                                </span>
                                <span class="actions">
                                    <Link v-if="r.status === 'importata' && r.invoice_id" :href="`${base}/fatture/${r.invoice_id}`" class="btn link">Apri fattura</Link>
                                    <Link v-if="['gia_presente', 'invariato'].includes(r.status) && r.existing_id" :href="`${base}/fatture/${r.existing_id}`" class="btn link">Apri originale</Link>
                                    <Link v-if="['modificato', 'da_abbinare'].includes(r.status) && r.reprocessing_id" :href="`${base}/rielaborazioni/${r.reprocessing_id}`" class="btn link">{{ r.status === 'modificato' ? 'Vedi differenze' : 'Scegli il documento' }}</Link>
                                    <Link v-if="r.status === 'integrazione_abbinata' && r.existing_id" :href="`${base}/fatture/${r.existing_id}`" class="btn link">Apri fattura originale</Link>
                                    <Link v-if="r.status === 'archiviata' && r.existing_id" :href="`${base}/fatture/${r.existing_id}`" class="btn link">Apri e ripristina</Link>
                                    <template v-if="r.status === 'conflitto'">
                                        <Link :href="`${base}/fatture/${r.invoice_id}/confronta/${r.existing_id}`" class="btn link">Confronta documenti</Link>
                                        <Link :href="`${base}/fatture/${r.invoice_id}`" class="btn link">Apri nuova</Link>
                                    </template>
                                </span>
                            </div>
                            <p class="small muted">{{ r.message }}</p>
                            <ul v-if="r.issues?.length" class="messages"><li v-for="(m, i) in r.issues" :key="i">{{ m }}</li></ul>
                            <p v-if="r.parties" class="small">Parti riconosciute nel file: cedente {{ partyText(r.parties.cedente) }} · cessionario {{ partyText(r.parties.cessionario) }}</p>
                        </li>
                    </ul>
                    <ul v-else-if="it.messages.length" class="messages">
                        <li v-for="(m, i) in it.messages" :key="i">{{ m }}</li>
                    </ul>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
