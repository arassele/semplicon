<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import type { SharedProps } from '@/types';

/* Categorie e aliquote: categorie proprie (con conti), recepimento dal D.M. 31.12.1988 per Gruppo/Specie, variazione dell’aliquota dall’anno. */
type Acc = { id: number; code: string; name: string; type: string };
type Cat = { id: number; code: string; name: string; kind: string; civil_rate: string | null; fiscal_rate: string | null; first_year_half: boolean; active: boolean; depreciation_rate_id: number | null; notes: string | null;
    dm: { gruppo: number; specie: string | null; coefficient: string; description: string } | null; assets: number; changes: { from_year: number; civil_rate: string; fiscal_rate: string; reason: string }[] } & Record<string, unknown>;
const props = defineProps<{ categories: Cat[]; accounts: Acc[]; roles: Record<string, string>; kinds: Record<string, string>; table: { title: string; source: string; version: string; notes: string } | null;
    groups: { gruppo: number; gruppo_desc: string }[]; species: { specie: string | null; specie_desc: string | null }[]; rates: { id: number; description: string; coefficient: string; verify_note: string | null }[]; gruppo: number | null; specie: string | null; year: number }>();
const base = computed(() => `/aziende/${usePage<SharedProps>().props.currentCompany!.id}/cespiti/categorie`);
const blank = () => ({ code: '', name: '', kind: 'materiale', civil_rate: '', fiscal_rate: '', first_year_half: true, active: true, notes: '', depreciation_rate_id: '' as string | number, ...Object.fromEntries(Object.keys(props.roles).map((r) => [`account_${r}_id`, ''])) });
const form = useForm<Record<string, any>>(blank());
const editing = ref<number | null>(null);
const open = ref(false);
const err = (k: string) => (form.errors as Record<string, string>)[k];
const edit = (c: Cat) => { editing.value = c.id; open.value = true; form.clearErrors(); Object.assign(form, { ...blank(), ...Object.fromEntries(Object.entries(c).filter(([k]) => k in blank()).map(([k, v]) => [k, v ?? ''])) }); };
const save = () => (editing.value ? form.put(`${base.value}/${editing.value}`, { preserveScroll: true, onSuccess: () => { open.value = false; } }) : form.post(base.value, { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } }));
const accFor = (r: string) => props.accounts.filter((x) => (['asset', 'fund'].includes(r) ? x.type === 'patrimoniale' : r === 'gain' ? x.type === 'ricavo' : x.type === 'costo'));
const pick = ref<number[]>([]);
const g = ref(props.gruppo ? String(props.gruppo) : '');
const sp = ref(props.specie ?? '');
const browse = () => router.get(base.value, { gruppo: g.value || undefined, specie: sp.value || undefined }, { preserveState: true, preserveScroll: true });
const createFromTable = () => router.post(`${base.value}/da-tabella`, { rates: pick.value }, { preserveScroll: true, onSuccess: () => { pick.value = []; } });
const rateEdit = ref<number | null>(null);
const rf = useForm({ from_year: props.year + 1, civil_rate: '', fiscal_rate: '', reason: '' });
const pct = (v: string | null) => (v === null ? '—' : `${String(v).replace('.', ',')}%`);
</script>

<template>
    <Head title="Categorie e aliquote" />
    <AppLayout crumb="Cespiti · Categorie">
        <div class="page-head">
            <div><p class="eyebrow"><Link :href="base.replace('/categorie', '')">Cespiti</Link></p><h1>Categorie e aliquote</h1>
                <p>Le tabelle ministeriali restano immutate; le categorie dell’azienda conservano aliquote e conti. Le modifiche delle aliquote valgono dai futuri esercizi.</p></div>
            <div class="actions"><button class="btn primary" type="button" @click="editing = null; form.defaults(blank()); form.reset(); open = true"><Icon name="plus" />Nuova categoria</button></div>
        </div>
        <section v-if="open" class="panel" style="margin-bottom: 16px"><div class="panel-head"><h2>{{ editing ? 'Modifica categoria' : 'Nuova categoria' }}</h2></div>
            <form class="panel-body mi-grid" novalidate @submit.prevent="save">
                <div class="field"><label for="k-c">Codice</label><input id="k-c" v-model="form.code" class="input tabular" maxlength="20"><p v-if="err('code')" class="error">{{ err('code') }}</p></div>
                <div class="field span2"><label for="k-n">Descrizione</label><input id="k-n" v-model="form.name" class="input"><p v-if="err('name')" class="error">{{ err('name') }}</p></div>
                <div class="field"><label for="k-k">Tipologia</label><select id="k-k" v-model="form.kind" class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="field"><label for="k-cr">Aliquota civilistica %</label><input id="k-cr" v-model="form.civil_rate" class="input tabular" placeholder="come la fiscale"><p v-if="err('civil_rate')" class="error">{{ err('civil_rate') }}</p></div>
                <div class="field"><label for="k-fr">Aliquota fiscale applicata %</label><input id="k-fr" v-model="form.fiscal_rate" class="input tabular"><p v-if="err('fiscal_rate')" class="error">{{ err('fiscal_rate') }}</p></div>
                <label v-if="form.kind === 'materiale'" class="check span3"><input v-model="form.first_year_half" type="checkbox"> Primo esercizio: limite fiscale ridotto alla metà (art. 102 TUIR)</label>
                <p v-if="form.kind === 'immateriale'" class="small muted span3">Immateriali (art. 103 TUIR): nessuna riduzione automatica nel primo esercizio; il D.M. 31/12/1988 non si applica. Indica l’aliquota in base al bene e ai principi contabili.</p>
                <p v-if="err('depreciation_rate_id')" class="error span3">{{ err('depreciation_rate_id') }}</p>
                <div v-for="(l, r) in roles" :key="r" class="field"><label :for="`k-a-${r}`">{{ l }}</label><select :id="`k-a-${r}`" v-model="form[`account_${r}_id`]" class="input"><option value="">—</option><option v-for="x in accFor(r)" :key="x.id" :value="x.id">{{ x.code }} · {{ x.name }}</option></select><p v-if="err(`account_${r}_id`)" class="error">{{ err(`account_${r}_id`) }}</p></div>
                <label class="check"><input v-model="form.active" type="checkbox"> Attiva</label>
                <div class="actions span3"><button class="btn primary" type="submit"><Icon name="check" />Salva</button><button class="btn" type="button" @click="open = false">Annulla</button></div>
            </form></section>

        <section class="panel" style="margin-bottom: 16px">
            <div v-if="categories.length" class="table-wrap"><table class="data">
                <thead><tr><th>Codice</th><th>Descrizione</th><th>Tipologia</th><th class="num">Civilistica</th><th class="num">Fiscale</th><th class="num">D.M.</th><th class="num">Cespiti</th><th></th></tr></thead>
                <tbody><template v-for="c in categories" :key="c.id">
                    <tr><td class="tabular">{{ c.code }}</td><td>{{ c.name }}<div v-for="(x, k) in c.changes" :key="k" class="small muted">dal {{ x.from_year }}: {{ pct(x.civil_rate) }} / {{ pct(x.fiscal_rate) }} · {{ x.reason }}</div></td>
                        <td>{{ kinds[c.kind] }}</td><td class="num tabular">{{ pct(c.civil_rate ?? c.fiscal_rate) }}</td><td class="num tabular" :class="{ 'text-bad': c.dm && Number(c.fiscal_rate) > Number(c.dm.coefficient) }">{{ pct(c.fiscal_rate) }}</td>
                        <td class="num tabular">{{ c.dm ? `${pct(c.dm.coefficient)} · G${c.dm.gruppo}${c.dm.specie ? ' ' + c.dm.specie : ''}` : '—' }}</td><td class="num tabular">{{ c.assets }}</td>
                        <td class="num" style="white-space: nowrap"><button class="btn link" type="button" @click="edit(c)">Modifica</button> <button class="btn link" type="button" @click="rateEdit = rateEdit === c.id ? null : c.id; rf.civil_rate = String(c.civil_rate ?? c.fiscal_rate ?? ''); rf.fiscal_rate = String(c.fiscal_rate ?? '')">Modifica dal …</button></td></tr>
                    <tr v-if="rateEdit === c.id"><td colspan="8"><form class="mi-grid" @submit.prevent="rf.post(`${base}/${c.id}/aliquote`, { preserveScroll: true, onSuccess: () => { rateEdit = null; } })">
                        <div class="field"><label :for="`rf-y-${c.id}`">Dall’esercizio</label><input :id="`rf-y-${c.id}`" v-model="rf.from_year" class="input tabular"><p v-if="rf.errors.from_year" class="error">{{ rf.errors.from_year }}</p></div>
                        <div class="field"><label :for="`rf-c-${c.id}`">Civilistica %</label><input :id="`rf-c-${c.id}`" v-model="rf.civil_rate" class="input tabular"></div>
                        <div class="field"><label :for="`rf-f-${c.id}`">Fiscale %</label><input :id="`rf-f-${c.id}`" v-model="rf.fiscal_rate" class="input tabular"></div>
                        <div class="field span2"><label :for="`rf-r-${c.id}`">Motivo</label><input :id="`rf-r-${c.id}`" v-model="rf.reason" class="input"><p v-if="rf.errors.reason" class="error">{{ rf.errors.reason }}</p></div>
                        <div class="actions" style="align-self: end"><button class="btn primary" type="submit">Salva</button></div></form></td></tr>
                </template></tbody></table></div>
            <p v-else class="panel-body muted">Nessuna categoria: creale dalla tabella ministeriale qui sotto o con “Nuova categoria”.</p>
        </section>

        <section v-if="table" class="panel"><div class="panel-head"><div><h2>Tabella D.M. 31 dicembre 1988</h2><p>{{ table.source }} · {{ table.notes }}</p></div></div>
            <form class="panel-body mi-grid" @submit.prevent="browse">
                <div class="field span2"><label for="t-g">Gruppo (settore dell’azienda)</label><select id="t-g" v-model="g" class="input" @change="sp = ''; browse()"><option value="">Scegli…</option><option v-for="x in groups" :key="x.gruppo" :value="String(x.gruppo)">{{ x.gruppo }} · {{ x.gruppo_desc }}</option></select></div>
                <div v-if="species.length" class="field"><label for="t-s">Specie</label><select id="t-s" v-model="sp" class="input" @change="browse"><option value="">Senza specie</option><option v-for="x in species" :key="x.specie ?? ''" :value="x.specie ?? ''">{{ x.specie }} · {{ (x.specie_desc ?? '').slice(0, 70) }}</option></select></div>
            </form>
            <div v-if="rates.length" class="table-wrap"><table class="data">
                <thead><tr><th style="width: 32px"></th><th>Bene</th><th class="num">Coefficiente</th></tr></thead>
                <tbody><tr v-for="r in rates" :key="r.id"><td><input v-model="pick" type="checkbox" :value="r.id" :aria-label="r.description"></td><td>{{ r.description }}<div v-if="r.verify_note" class="small text-bad">Da verificare: {{ r.verify_note }}</div></td><td class="num tabular">{{ pct(r.coefficient) }}</td></tr></tbody></table>
                <div class="panel-body"><button class="btn primary" type="button" :disabled="!pick.length" @click="createFromTable"><Icon name="plus" />Crea {{ pick.length || '' }} categorie</button> <span class="small muted">Codice G&lt;gruppo&gt;-&lt;specie&gt;-&lt;n&gt;, conti proposti in base alla descrizione.</span></div></div>
        </section>
    </AppLayout>
</template>
