<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

type Code = { code: string; title_it: string; active: boolean; source: string };
const props = defineProps<{
    q: string; canEdit: boolean; source: string; total: number;
    codes: { data: Code[]; links: { url: string | null; label: string; active: boolean }[]; total: number; from: number | null; to: number | null };
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const q = ref(props.q);
const search = () => router.get(`${base.value}/tabelle/ateco`, q.value ? { q: q.value } : {}, { preserveState: true });

const editing = ref<string | null>(null);
const edit = useForm({ title_it: '', active: true });
function startEdit(c: Code) { editing.value = c.code; edit.defaults({ title_it: c.title_it, active: c.active }); edit.reset(); }
const saveEdit = () => edit.put(`${base.value}/tabelle/ateco/${editing.value}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });

const showNew = ref(false);
const create = useForm({ code: '', title_it: '' });
const saveNew = () => create.post(`${base.value}/tabelle/ateco`, { preserveScroll: true, onSuccess: () => { create.reset(); showNew.value = false; } });
const label = (l: string) => l.replace('&laquo;', '‹').replace('&raquo;', '›').replace('Previous', 'Precedente').replace('Next', 'Successiva');
</script>

<template>
    <Head title="Codici ATECO" />
    <AppLayout crumb="Tabelle · Codici ATECO">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni · Tabelle</p>
                <h1>Codici ATECO 2025</h1>
                <p>Classificazione delle attività usata in Dati azienda. {{ total }} codici attivi. <template v-if="canEdit">Come amministratore puoi correggere i titoli, disattivare codici o aggiungerne di nuovi: le modifiche valgono per tutte le aziende.</template><template v-else>La tabella è gestita dall’amministratore.</template></p>
            </div>
            <button v-if="canEdit && !showNew" class="btn primary" type="button" @click="showNew = true"><Icon name="plus" />Nuovo codice</button>
        </div>

        <section v-if="showNew" class="panel" style="margin-bottom: 20px">
            <div class="panel-head"><h2>Nuovo codice</h2></div>
            <form class="panel-body form" @submit.prevent="saveNew">
                <div class="form-row two">
                    <div class="field"><label for="n-code">Codice</label><input id="n-code" v-model="create.code" class="input tabular" placeholder="NN.NN.NN"><p v-if="create.errors.code" class="error">{{ create.errors.code }}</p></div>
                    <div class="field"><label for="n-it">Titolo italiano</label><input id="n-it" v-model="create.title_it" class="input"><p v-if="create.errors.title_it" class="error">{{ create.errors.title_it }}</p></div>
                </div>
                <div class="actions"><button class="btn primary" type="submit" :disabled="create.processing">Aggiungi</button><button class="btn" type="button" @click="showNew = false">Annulla</button></div>
            </form>
        </section>

        <section class="panel">
            <form class="filters" @submit.prevent="search">
                <label for="aq" class="sr-only">Cerca</label>
                <input id="aq" v-model="q" class="input" type="search" placeholder="Codice o parola">
                <button class="btn" type="submit">Cerca</button>
            </form>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Codice</th><th>Titolo</th><th v-if="canEdit">Stato</th><th v-if="canEdit"><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="c in codes.data" :key="c.code" :class="{ inactive: !c.active }">
                            <td class="tabular nowrap">{{ c.code }}</td>
                            <td v-if="editing !== c.code">{{ c.title_it }}</td>
                            <td v-else>
                                <div class="form" style="gap: 8px">
                                    <input v-model="edit.title_it" class="input" aria-label="Titolo italiano">
                                    <label class="check"><input v-model="edit.active" type="checkbox">Attivo</label>
                                    <p v-if="edit.errors.title_it" class="error">{{ edit.errors.title_it }}</p>
                                    <div class="actions"><button class="btn primary" type="button" @click="saveEdit">Salva</button><button class="btn" type="button" @click="editing = null">Annulla</button></div>
                                </div>
                            </td>
                            <td v-if="canEdit"><span class="badge" :class="c.active ? 'good' : ''">{{ c.active ? 'Attivo' : 'Disattivato' }}</span><br><span class="small muted">{{ c.source === 'istat' ? 'ISTAT' : 'Manuale' }}</span></td>
                            <td v-if="canEdit"><button v-if="editing !== c.code" class="btn link" type="button" @click="startEdit(c)">Modifica</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!codes.data.length" class="panel-body muted">Nessun codice trovato.</p>
            <nav v-if="codes.links.length > 3" class="pager" aria-label="Pagine">
                <span class="small muted">{{ codes.from }}–{{ codes.to }} di {{ codes.total }}</span>
                <template v-for="(l, k) in codes.links" :key="k"><Link v-if="l.url" :href="l.url" class="btn" :class="{ primary: l.active }" preserve-scroll>{{ label(l.label) }}</Link></template>
            </nav>
            <p class="panel-body hint">{{ source }}</p>
        </section>
    </AppLayout>
</template>
