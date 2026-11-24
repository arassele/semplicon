<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date } from '@/lib/format';

/* Amministrazione: utenti e aziende; l’amministratore crea utenti e assegna le aziende (richieste del 24.11.2026). */
type U = { id: number; name: string; email: string; initials: string; is_admin: boolean; created_at: string; companies: { id: number; name: string }[] };
const props = defineProps<{ users: U[]; allCompanies: { id: number; name: string; partita_iva: string }[] }>();
const nu = useForm({ name: '', email: '', password: '', is_admin: false, companies: [] as number[] });
const create = () => nu.post('/amministrazione/utenti', { preserveScroll: true, onSuccess: () => nu.reset() });
const editing = ref<number | null>(null);
const sel = ref<number[]>([]);
const startEdit = (u: U) => { editing.value = editing.value === u.id ? null : u.id; sel.value = u.companies.map((c) => c.id); };
const saveCompanies = (u: U) => router.put(`/amministrazione/utenti/${u.id}/aziende`, { companies: sel.value }, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
</script>

<template>
    <Head title="Utenti e aziende" />
    <AppLayout crumb="Amministrazione">
        <div class="page-head">
            <div>
                <p class="eyebrow">Amministrazione</p>
                <h1>Utenti e aziende</h1>
                <p>Tutti gli account e le aziende che gestiscono. L’amministratore crea nuovi utenti e assegna loro le aziende.</p>
            </div>
        </div>
        <section class="panel" aria-labelledby="nu-title" style="margin-bottom: 20px">
            <div class="panel-head"><div><h2 id="nu-title">Nuovo utente</h2><p>L’utente entra con questa e-mail e password e vede solo le aziende assegnate.</p></div></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="create">
                <div class="field"><label for="nu-name">Nome e cognome</label><input id="nu-name" v-model="nu.name" class="input" autocomplete="off"><p v-if="nu.errors.name" class="error">{{ nu.errors.name }}</p></div>
                <div class="field"><label for="nu-email">E-mail</label><input id="nu-email" v-model="nu.email" class="input" type="email" autocomplete="off"><p v-if="nu.errors.email" class="error">{{ nu.errors.email }}</p></div>
                <div class="field"><label for="nu-pw">Password iniziale (almeno 12 caratteri)</label><input id="nu-pw" v-model="nu.password" class="input" type="password" autocomplete="new-password"><p v-if="nu.errors.password" class="error">{{ nu.errors.password }}</p></div>
                <fieldset class="field span3"><legend>Aziende assegnate</legend>
                    <div class="adm-cos"><label v-for="c in props.allCompanies" :key="c.id" class="check"><input v-model="nu.companies" type="checkbox" :value="c.id">{{ c.name }} <span class="small muted tabular">{{ c.partita_iva }}</span></label></div></fieldset>
                <label class="check span3"><input v-model="nu.is_admin" type="checkbox"> Amministratore (vede tutte le aziende)</label>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="nu.processing"><Icon name="plus" />Crea utente</button></div>
            </form>
        </section>
        <section class="panel">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Utente</th><th>Registrato</th><th>Aziende</th></tr></thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id">
                            <td>
                                <div style="display: flex; gap: 12px; align-items: center">
                                    <span class="avatar">{{ u.initials }}</span>
                                    <div><strong>{{ u.name }}</strong> <span v-if="u.is_admin" class="badge info">Amministratore</span><br><span class="small muted">{{ u.email }}</span></div>
                                </div>
                            </td>
                            <td class="tabular nowrap">{{ date(u.created_at) }}</td>
                            <td>
                                <span v-if="!u.companies.length" class="muted small">Nessuna</span>
                                <template v-for="(c, i) in u.companies" :key="c.id"><template v-if="i">, </template><Link :href="`/aziende/${c.id}`">{{ c.name }}</Link></template>
                                <template v-if="!u.is_admin">
                                    <br><button class="btn link" type="button" :aria-expanded="editing === u.id" @click="startEdit(u)">Modifica aziende</button>
                                    <div v-if="editing === u.id" class="adm-edit">
                                        <div class="adm-cos"><label v-for="c in props.allCompanies" :key="c.id" class="check"><input v-model="sel" type="checkbox" :value="c.id">{{ c.name }}</label></div>
                                        <button class="btn small-btn" type="button" @click="saveCompanies(u)"><Icon name="check" />Salva aziende</button>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.adm-cos { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 2px 16px; }
.adm-edit { margin-top: 8px; display: grid; gap: 8px; justify-items: start; }
</style>
