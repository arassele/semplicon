<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';

const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post('/accedi', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Accedi" />
    <div class="auth">
        <section class="auth-brand" aria-label="Semplicon">
            <span class="wordmark" style="padding: 0">semplicon<i /></span>
            <div>
                <p class="claim">La tua contabilità IVA, <em>semplice.</em></p>
                <p style="margin-top: 16px">Dalle fatture XML ai registri e alla liquidazione, con ogni passaggio verificato e tracciato.</p>
            </div>
            <p class="small">Più ordine. Meno pensieri.</p>
        </section>
        <main class="auth-main" id="contenuto">
            <div class="auth-card">
                <h1>Accedi</h1>
                <p class="muted" style="margin: 8px 0 28px">Non hai un account? <Link href="/registrati">Registrati</Link></p>
                <form class="form" novalidate @submit.prevent="submit">
                    <Field id="email" label="E-mail" :error="form.errors.email" v-slot="{ describedby, invalid }">
                        <input id="email" v-model="form.email" class="input" type="email" autocomplete="username" required autofocus :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <Field id="password" label="Password" :error="form.errors.password" v-slot="{ describedby, invalid }">
                        <input id="password" v-model="form.password" class="input" type="password" autocomplete="current-password" required :aria-describedby="describedby" :aria-invalid="invalid">
                    </Field>
                    <label class="check"><input v-model="form.remember" type="checkbox"> Resta connesso su questo dispositivo</label>
                    <button class="btn primary" type="submit" :disabled="form.processing">Accedi</button>
                </form>
            </div>
        </main>
    </div>
</template>
