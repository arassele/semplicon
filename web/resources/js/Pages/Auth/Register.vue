<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
const submit = () => form.post('/registrati', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Registrati" />
    <div class="auth">
        <section class="auth-brand" aria-label="Semplicon">
            <span class="wordmark" style="padding: 0">semplicon<i /></span>
            <div>
                <p class="claim">La tua contabilità IVA, <em>semplice.</em></p>
                <p style="margin-top: 16px">Crea il tuo account, aggiungi la tua azienda e imposta regime, inizio IVA e periodicità.</p>
            </div>
            <p class="small">Più ordine. Meno pensieri.</p>
        </section>
        <main class="auth-main" id="contenuto">
            <div class="auth-card">
                <h1>Crea il tuo account</h1>
                <p class="muted" style="margin: 8px 0 28px">Hai già un account? <Link href="/accedi">Accedi</Link></p>
                <form class="form" novalidate @submit.prevent="submit">
                    <Field id="name" label="Nome e cognome" :error="form.errors.name" v-slot="{ describedby, invalid }">
                        <input id="name" v-model="form.name" class="input" autocomplete="name" required autofocus :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <Field id="email" label="E-mail" :error="form.errors.email" v-slot="{ describedby, invalid }">
                        <input id="email" v-model="form.email" class="input" type="email" autocomplete="username" required :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <Field id="password" label="Password" hint="Almeno 12 caratteri" :error="form.errors.password" v-slot="{ describedby, invalid }">
                        <input id="password" v-model="form.password" class="input" type="password" autocomplete="new-password" required :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <Field id="password_confirmation" label="Ripeti la password" v-slot="{ describedby }">
                        <input id="password_confirmation" v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" required :aria-describedby="describedby">
                    </Field>
                    <button class="btn primary" type="submit" :disabled="form.processing">Crea account</button>
                </form>
            </div>
        </main>
    </div>
</template>
