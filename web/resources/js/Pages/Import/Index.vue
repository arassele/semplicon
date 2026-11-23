<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import type { SharedProps } from '@/types';

type Batch = { id: number; status: string; status_label: string; running: boolean; files: number; invoices: number; duplicates: number; to_review: number; errors: number; created_by: string | null; created_at: string };
defineProps<{ batches: Batch[]; limits: { files: number; file_mb: number; zip_mb: number } }>();

const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const form = useForm<{ files: File[]; mode: string }>({ files: [], mode: 'salta' });
const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);

function add(list: FileList | null) {
    if (!list) return;
    form.files = [...form.files, ...Array.from(list)];
    form.clearErrors();
}
const remove = (i: number) => form.files.splice(i, 1);
const size = (b: number) => (b > 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(b / 1024))} kB`);
const submit = () => form.post(`${base.value}/importa`, { forceFormData: true, onSuccess: () => form.reset() });
const fileErrors = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('files')).map(([, v]) => v));
</script>

<template>
    <Head title="Importa XML" />
    <AppLayout crumb="Importa XML">
        <div class="page-head">
            <div>
                <p class="eyebrow">Documenti</p>
                <h1>Importa fatture elettroniche</h1>
                <p>File FatturaPA (.xml), firmati (.p7m) o archivi .zip. Ogni file viene conservato invariato, controllato con lo schema ufficiale e confrontato con le fatture già presenti.</p>
            </div>
        </div>

        <div class="split">
            <section class="panel" aria-labelledby="up-title">
                <div class="panel-head"><h2 id="up-title">Carica file</h2></div>
                <form class="panel-body form" @submit.prevent="submit">
                    <div class="dropzone" :class="{ over: dragging }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; add($event.dataTransfer?.files ?? null)">
                        <Icon name="upload" style="width: 28px; height: 28px" />
                        <p><strong>Trascina qui i file</strong> oppure</p>
                        <button class="btn" type="button" @click="input?.click()">Scegli file</button>
                        <input ref="input" class="sr-only" type="file" multiple accept=".xml,.p7m,.zip" aria-label="Scegli file da importare" @change="add(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''">
                        <p class="hint">Fino a {{ limits.files }} file per volta · {{ limits.file_mb }} MB per file XML/P7M · {{ limits.zip_mb }} MB per ZIP</p>
                    </div>
                    <ul v-if="form.files.length" class="list file-list">
                        <li v-for="(f, i) in form.files" :key="i + f.name">
                            <div><p class="title">{{ f.name }}</p><p class="meta">{{ size(f.size) }}</p></div>
                            <button class="btn link" type="button" @click="remove(i)">Rimuovi</button>
                        </li>
                    </ul>
                    <fieldset class="field">
                        <legend>Se il documento è già presente</legend>
                        <label class="check"><input v-model="form.mode" type="radio" name="mode" value="salta"> <span><strong>Salta duplicati</strong> · importa i nuovi documenti e segnala quelli già presenti</span></label>
                        <label class="check"><input v-model="form.mode" type="radio" name="mode" value="rielabora"> <span><strong>Rielabora XML</strong> · rilegge anche i file identici con la logica attuale e mostra le differenze da confermare</span></label>
                        <label class="check"><input v-model="form.mode" type="radio" name="mode" value="sostituisci"> <span><strong>Sostituisci</strong> · elimina la versione già importata (se non registrata) e importa di nuovo il file; i documenti già registrati non vengono sostituiti</span></label>
                    </fieldset>
                    <div v-if="fileErrors.length" class="notice bad" role="alert">
                        <Icon name="alert" style="width: 20px; flex: none" />
                        <ul style="margin: 0; padding-left: 18px"><li v-for="(m, i) in fileErrors" :key="i">{{ m }}</li></ul>
                    </div>
                    <div class="actions">
                        <button class="btn primary" type="submit" :disabled="!form.files.length || form.processing">
                            <Icon name="upload" />{{ form.processing ? 'Caricamento…' : `Importa ${form.files.length || ''} file` }}
                        </button>
                        <progress v-if="form.progress" :value="form.progress.percentage" max="100" style="flex: 1">{{ form.progress.percentage }}%</progress>
                    </div>
                </form>
            </section>

            <section class="panel" aria-labelledby="how-title">
                <div class="panel-head"><h2 id="how-title">Cosa viene controllato</h2></div>
                <ul class="panel-body checklist">
                    <li><Icon name="check" />Schema ufficiale FatturaPA 1.2.3 (FPR12 e FPA12)</li>
                    <li><Icon name="check" />Lettura sicura: niente entità esterne né accessi di rete</li>
                    <li><Icon name="check" />Azienda: acquisto o vendita in base a P. IVA e codice fiscale</li>
                    <li><Icon name="check" />Duplicati: stesso file oppure stessa fattura già presente</li>
                    <li><Icon name="check" />Più fatture in un file e firme .p7m</li>
                    <li><Icon name="check" />Valuta, totali e regime IVA dell’anno da verificare</li>
                </ul>
            </section>
        </div>

        <section class="panel" aria-labelledby="hist-title" style="margin-top: 24px">
            <div class="panel-head"><h2 id="hist-title">Importazioni recenti</h2></div>
            <div v-if="batches.length" class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead><tr><th>Data</th><th>Stato</th><th class="num">File</th><th class="num">Fatture</th><th class="num">Già presenti</th><th class="num">Da verificare</th><th class="num">Errori</th><th><span class="sr-only">Dettaglio</span></th></tr></thead>
                    <tbody>
                        <tr v-for="b in batches" :key="b.id">
                            <td class="nowrap">{{ dateTime(b.created_at) }}<br><span class="small muted">{{ b.created_by }}</span></td>
                            <td><span class="badge" :class="{ warn: b.running, good: !b.running && !b.errors, bad: b.errors }">{{ b.status_label }}</span></td>
                            <td class="num tabular">{{ b.files }}</td>
                            <td class="num tabular">{{ b.invoices }}</td>
                            <td class="num tabular">{{ b.duplicates }}</td>
                            <td class="num tabular">{{ b.to_review }}</td>
                            <td class="num tabular">{{ b.errors }}</td>
                            <td><Link :href="`${base}/importa/${b.id}`" class="btn link">Dettaglio</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="panel-body muted">Ancora nessuna importazione.</p>
        </section>
    </AppLayout>
</template>
