<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { dateTime } from '@/lib/format';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types';

/* Il mio account: backup di archivio delle proprie aziende (tutti gli anni, con file originali e checksum). */
const props = defineProps<{ companies: { id: number; name: string; partita_iva: string }[]; backups: { at: string | null; company_id: number; sha256: string | null; files: number | null; companies: number }[] }>();
const user = computed(() => usePage<SharedProps>().props.auth.user!);
const picked = ref<number[]>(props.companies.map((c) => c.id));
const rest = useForm<{ file: File | null; mode: 'new' | 'replace'; password: string }>({ file: null, mode: 'new', password: '' });
const restoreErr = computed(() => { const e = rest.errors as Record<string, string>; return e.file || e.mode || e.password || ''; });
const restore = () => rest.post('/account/ripristino', { forceFormData: true, preserveScroll: true, onSuccess: () => rest.reset(), onError: () => rest.reset('password') });
const busy = ref(false);
const error = ref('');
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
async function backup() {
    busy.value = true; error.value = '';
    try {
        const res = await fetch('/account/backup', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/zip, application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ companies: picked.value }) });
        if (!res.ok) { error.value = 'Backup non riuscito. Riprova.'; busy.value = false; return; }
        const blob = await res.blob(), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? 'semplicon-backup.zip'; document.body.appendChild(a); a.click(); a.remove();
    } catch { error.value = 'Backup non riuscito. Riprova.'; }
    busy.value = false;
}
</script>

<template>
    <Head title="Il mio account" />
    <AppLayout crumb="Account">
        <div class="page-head">
            <div>
                <p class="eyebrow">Account</p>
                <h1>{{ user.name }}</h1>
                <p>{{ user.email }} · {{ user.is_admin ? 'Amministratore' : 'Cliente' }}</p>
            </div>
        </div>

        <section class="panel" aria-labelledby="bk-title">
            <div class="panel-head"><div><h2 id="bk-title">Backup dell’archivio</h2><p>Un file ZIP con tutti i dati delle aziende scelte, di tutti gli anni: fatture, registrazioni, liquidazioni, anagrafiche, registro attività, documenti originali e logo. Contiene le impronte SHA-256 per verificare che nulla sia cambiato. Non include account né password. Per ripristinarlo usa «Ripristina da backup» qui sotto.</p></div></div>
            <div class="panel-body">
                <fieldset class="field">
                    <legend>Aziende nel backup</legend>
                    <label v-for="c in companies" :key="c.id" class="check"><input v-model="picked" type="checkbox" :value="c.id">{{ c.name }} <span class="muted small tabular">P. IVA {{ c.partita_iva }}</span></label>
                    <p v-if="!companies.length" class="muted">Nessuna azienda.</p>
                </fieldset>
                <p v-if="error" class="error" role="alert">{{ error }}</p>
                <div class="actions"><button class="btn primary" type="button" :disabled="busy || !picked.length" @click="backup"><Icon name="download" />{{ busy ? 'Creo il backup…' : 'Scarica il backup' }}</button></div>
            </div>
        </section>

        <section class="panel" aria-labelledby="rs-title">
            <div class="panel-head"><div><h2 id="rs-title">Ripristina da backup</h2><p>Carica un file ZIP creato con «Scarica il backup». Prima Semplicon verifica le impronte SHA-256: un file alterato viene rifiutato. L’azienda può essere ricreata (se la sua partita IVA non esiste già) oppure i dati dell’azienda esistente con la stessa partita IVA vengono sostituiti.</p></div></div>
            <form class="panel-body stack" novalidate @submit.prevent="restore">
                <div class="field" style="max-width: 460px"><label for="rs-file">File di backup (.zip)</label>
                    <input id="rs-file" class="input" type="file" accept=".zip,application/zip" @change="rest.file = ($event.target as HTMLInputElement).files?.[0] ?? null"></div>
                <fieldset class="field">
                    <legend>Come ripristinare</legend>
                    <label class="check"><input v-model="rest.mode" type="radio" value="new"><span><strong>Nuova azienda</strong> · crea l’azienda dal backup (nome con «(ripristinata)»); non tocca nulla di esistente</span></label>
                    <label class="check"><input v-model="rest.mode" type="radio" value="replace"><span><strong>Sostituisci i dati dell’azienda esistente</strong> · cancella tutti i dati attuali dell’azienda con la stessa partita IVA e li sostituisce con il backup</span></label>
                </fieldset>
                <div v-if="rest.mode === 'replace'" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Operazione non reversibile</strong>I dati attuali dell’azienda vengono eliminati. Scarica prima un backup. Conferma con la tua password.
                    <div class="field" style="max-width: 320px; margin-top: 8px"><label for="rs-pw">La tua password</label><input id="rs-pw" v-model="rest.password" class="input" type="password" autocomplete="current-password"></div></div></div>
                <p v-if="restoreErr" class="error" role="alert">{{ restoreErr }}</p>
                <div class="actions"><button class="btn primary" type="submit" :disabled="rest.processing || !rest.file || (rest.mode === 'replace' && !rest.password)"><Icon name="upload" />{{ rest.processing ? 'Ripristino in corso…' : 'Ripristina' }}</button></div>
            </form>
        </section>

        <section v-if="backups.length" class="panel">
            <div class="panel-head"><div><h2>Ultimi backup</h2><p>Conserva l’impronta: se coincide con SHA256SUMS del file, il backup è integro.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Quando</th><th class="num">Aziende</th><th class="num">File</th><th>Impronta SHA-256 di SHA256SUMS</th></tr></thead>
                <tbody><tr v-for="(b, i) in backups" :key="i"><td>{{ dateTime(b.at) }}</td><td class="num tabular">{{ b.companies }}</td><td class="num tabular">{{ b.files }}</td><td class="tabular small" style="word-break: break-all">{{ b.sha256 }}</td></tr></tbody></table></div>
        </section>
        <p class="small muted"><Link href="/aziende">‹ Le tue aziende</Link></p>
    </AppLayout>
</template>
