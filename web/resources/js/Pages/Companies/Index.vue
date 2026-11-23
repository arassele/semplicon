<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Field from '@/Components/Field.vue';
import Icon from '@/Components/Icon.vue';
import type { ProfileSummary } from '@/types';

type Item = {
    id: number; name: string; partita_iva: string; owners: string;
    profile: ProfileSummary | null;
};
const props = defineProps<{ items: Item[]; isAdmin: boolean }>();

const showForm = ref(props.items.length === 0 && !props.isAdmin);
const form = useForm({ name: '', tipologia: 'ditta_individuale', partita_iva: '', codice_fiscale: '' });
const types = [
    { value: 'professionista', label: 'Professionista' },
    { value: 'ditta_individuale', label: 'Ditta individuale' },
    { value: 'societa', label: 'Società' },
];
const submit = () => form.post('/aziende');
</script>

<template>
    <Head :title="isAdmin ? 'Tutte le aziende' : 'Le tue aziende'" />
    <AppLayout crumb="Aziende">
        <div class="page-head">
            <div>
                <p class="eyebrow">{{ isAdmin ? 'Amministrazione' : 'Il tuo account' }}</p>
                <h1>{{ isAdmin ? 'Tutte le aziende' : 'Le tue aziende' }}</h1>
                <p>{{ isAdmin ? 'Come amministratore vedi e puoi modificare tutte le aziende.' : 'Aggiungi la tua azienda e imposta il regime IVA per anno. Ogni azienda ha dati separati.' }}</p>
            </div>
            <button v-if="!showForm" class="btn primary" type="button" @click="showForm = true"><Icon name="plus" />Aggiungi azienda</button>
        </div>

        <section v-if="showForm" class="panel" aria-labelledby="new-company" style="margin-bottom: 24px">
            <div class="panel-head">
                <div>
                    <h2 id="new-company">Aggiungi azienda</h2>
                    <p>Dopo la creazione completi i dati azienda e imposti il regime IVA per ogni anno.</p>
                </div>
            </div>
            <form class="panel-body form" novalidate @submit.prevent="submit">
                <div class="form-row">
                    <Field id="name" label="Denominazione" :error="form.errors.name" v-slot="{ describedby, invalid }">
                        <input id="name" v-model="form.name" class="input" required :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <Field id="partita_iva" label="Partita IVA" hint="11 cifre" :error="form.errors.partita_iva" v-slot="{ describedby, invalid }">
                        <input id="partita_iva" v-model="form.partita_iva" class="input tabular" inputmode="numeric" maxlength="11" required :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                </div>
                <div class="form-row">
                    <fieldset class="field">
                        <legend>Tipologia</legend>
                        <div class="segmented">
                            <label v-for="t in types" :key="t.value"><input v-model="form.tipologia" type="radio" name="tipologia" :value="t.value">{{ t.label }}</label>
                        </div>
                    </fieldset>
                    <Field id="codice_fiscale" label="Codice fiscale (facoltativo)" :error="form.errors.codice_fiscale" v-slot="{ describedby, invalid }">
                        <input id="codice_fiscale" v-model="form.codice_fiscale" class="input tabular" maxlength="16" :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                </div>
                <div class="actions">
                    <button class="btn primary" type="submit" :disabled="form.processing">Crea azienda</button>
                    <button v-if="items.length" class="btn" type="button" @click="showForm = false">Annulla</button>
                </div>
            </form>
        </section>

        <div v-if="items.length" class="grid-2">
            <Link v-for="c in items" :key="c.id" :href="`/aziende/${c.id}`" class="panel company-card">
                <div>
                    <h2>{{ c.name }}</h2>
                    <p class="muted small tabular">P. IVA {{ c.partita_iva }}<template v-if="isAdmin"> · {{ c.owners }}</template></p>
                </div>
                <template v-if="c.profile">
                    <p class="small"><span class="badge good">Regime {{ c.profile.year }} confermato</span></p>
                    <p class="small">{{ c.profile.regime_label }} · {{ c.profile.periodicity_label }}<template v-if="c.profile.forfettario_iva"> · Forfettario con IVA</template></p>
                </template>
                <p v-else class="small"><span class="badge warn">Regime {{ new Date().getFullYear() }} da confermare</span></p>
            </Link>
        </div>
        <div v-else-if="!showForm" class="panel empty">
            <h2>Nessuna azienda</h2>
            <p>{{ isAdmin ? 'Nessun cliente ha ancora aggiunto un’azienda.' : 'Aggiungi la tua prima azienda per iniziare.' }}</p>
        </div>
    </AppLayout>
</template>
