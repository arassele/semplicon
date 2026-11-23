<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/lib/format';

defineProps<{
    users: { id: number; name: string; email: string; initials: string; is_admin: boolean; created_at: string; companies: { id: number; name: string }[] }[];
}>();
</script>

<template>
    <Head title="Utenti e aziende" />
    <AppLayout crumb="Amministrazione">
        <div class="page-head">
            <div>
                <p class="eyebrow">Amministrazione</p>
                <h1>Utenti e aziende</h1>
                <p>Tutti gli account registrati e le aziende che gestiscono.</p>
            </div>
        </div>
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
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
