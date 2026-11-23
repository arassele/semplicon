<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Parametri fiscali per anno: righe di riferimento (sola lettura) e righe proprie, che l’azienda adatta per l’anno contabile scelto. */
type P = { id: number; year: number; ambito: string; deducibilita: string | null; iva: string | null; limiti: string | null; riferimento: string | null; fonte_url: string | null; own: boolean };
const props = defineProps<{ year: number; sourceYears: number[]; parameters: P[] }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/tabelle/parametri-fiscali`);
const blank = () => ({ ambito: '', deducibilita: '', iva: '', limiti: '', riferimento: '', fonte_url: '' });
const form = useForm(blank());
const editing = ref<number | null>(null);
const open = ref(false);
const from = ref<number | null>(props.sourceYears[0] ?? null);
const startNew = () => { form.defaults(blank()).reset(); form.clearErrors(); editing.value = null; open.value = true; };
const edit = (p: P) => { editing.value = p.id; open.value = true; form.clearErrors(); Object.assign(form, { ambito: p.ambito, deducibilita: p.deducibilita ?? '', iva: p.iva ?? '', limiti: p.limiti ?? '', riferimento: p.riferimento ?? '', fonte_url: p.fonte_url ?? '' }); };
const done = () => { open.value = false; editing.value = null; };
const save = () => (editing.value ? form.put(`${base.value}/${editing.value}`, { preserveScroll: true, onSuccess: done }) : form.post(`${base.value}`, { preserveScroll: true, onSuccess: done }));
const del = (p: P) => { if (confirm(`Eliminare il parametro «${p.ambito}» del ${p.year}?`)) router.delete(`${base.value}/${p.id}`, { preserveScroll: true }); };
const copy = () => router.post(`${base.value}/copia`, { from: from.value }, { preserveScroll: true });
const hasOwn = computed(() => props.parameters.some((p) => p.own));
</script>

<template>
    <Head title="Parametri fiscali" />
    <AppLayout crumb="Tabelle · Parametri fiscali">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni · Tabelle</p>
                <h1>Parametri fiscali {{ year }}</h1>
                <p>I parametri valgono <strong>per anno</strong>: qui vedi quelli dell’anno contabile scelto in alto. Sono riferimenti indicativi, non formule di calcolo. Per un anno nuovo copia i valori di un altro anno e adattali.</p>
            </div>
            <div class="actions"><button class="btn primary" type="button" @click="startNew"><Icon name="plus" />Aggiungi parametro</button></div>
        </div>

        <div v-if="!parameters.length" class="notice warn" role="status" style="margin-bottom: 16px">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Nessun parametro per il {{ year }}</strong>
                <template v-if="sourceYears.length">Copia quelli di un altro anno: poi li puoi modificare.
                    <div class="actions" style="margin-top: 8px"><label class="inline-label">Copia dal <select v-model.number="from" class="input" style="width: auto"><option v-for="y in sourceYears" :key="y" :value="y">{{ y }}</option></select></label><button class="btn primary" type="button" @click="copy">Copia i parametri</button></div></template>
                <template v-else>Aggiungi i parametri che ti servono.</template></div>
        </div>
        <div v-else-if="sourceYears.length" class="actions" style="margin-bottom: 12px"><label class="inline-label">Aggiungi i parametri mancanti da <select v-model.number="from" class="input" style="width: auto"><option v-for="y in sourceYears" :key="y" :value="y">{{ y }}</option></select></label><button class="btn" type="button" @click="copy">Copia</button></div>

        <section v-if="open" class="panel" aria-labelledby="fp-title">
            <div class="panel-head"><h2 id="fp-title">{{ editing ? 'Modifica parametro' : `Nuovo parametro ${year}` }}</h2></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="save">
                <div class="field span3"><label for="fp-a">Ambito</label><input id="fp-a" v-model="form.ambito" class="input" maxlength="160" :aria-invalid="form.errors.ambito ? true : undefined"><p v-if="form.errors.ambito" class="error">{{ form.errors.ambito }}</p></div>
                <div class="field"><label for="fp-d">Deducibilità redditi</label><input id="fp-d" v-model="form.deducibilita" class="input" maxlength="160"></div>
                <div class="field"><label for="fp-i">IVA indicativa</label><input id="fp-i" v-model="form.iva" class="input" maxlength="160"></div>
                <div class="field"><label for="fp-l">Limiti / condizioni</label><input id="fp-l" v-model="form.limiti" class="input" maxlength="255"></div>
                <div class="field span2"><label for="fp-r">Riferimento</label><input id="fp-r" v-model="form.riferimento" class="input" maxlength="255"></div>
                <div class="field"><label for="fp-u">Fonte (URL)</label><input id="fp-u" v-model="form.fonte_url" class="input" maxlength="500"><p v-if="form.errors.fonte_url" class="error">{{ form.errors.fonte_url }}</p></div>
                <div class="actions span3"><button class="btn primary" type="submit" :disabled="form.processing"><Icon name="check" />Salva</button><button class="btn" type="button" @click="done">Annulla</button></div>
            </form>
        </section>

        <section v-if="parameters.length" class="panel">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Ambito</th><th>Deducibilità redditi</th><th>IVA indicativa</th><th>Limiti / condizioni</th><th>Riferimento</th><th><span class="sr-only">Azioni</span></th></tr></thead>
                    <tbody>
                        <tr v-for="p in parameters" :key="p.id">
                            <td><strong>{{ p.ambito }}</strong><br><span class="small muted">{{ p.year }} · {{ p.own ? 'tuo' : 'riferimento' }}</span></td>
                            <td>{{ p.deducibilita }}</td>
                            <td>{{ p.iva }}</td>
                            <td>{{ p.limiti }}</td>
                            <td>{{ p.riferimento }}<template v-if="p.fonte_url"><br><a :href="p.fonte_url" target="_blank" rel="noopener noreferrer" class="small">Fonte</a></template></td>
                            <td class="num" style="white-space: nowrap"><template v-if="p.own"><button class="btn link" type="button" @click="edit(p)">Modifica</button> <button class="btn link" type="button" @click="del(p)">Elimina</button></template></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!hasOwn" class="panel-body small muted">I valori di riferimento non si modificano. Per adattarli, aggiungi parametri propri o copia da un altro anno.</p>
        </section>
    </AppLayout>
</template>

<style scoped>
.inline-label { display: inline-flex; align-items: center; gap: 8px; font-size: 0.875rem; }
</style>
