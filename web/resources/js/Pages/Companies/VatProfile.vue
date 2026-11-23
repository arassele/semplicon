<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Field from '@/Components/Field.vue';
import Icon from '@/Components/Icon.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { dateTime } from '@/lib/format';
import type { AuditItem, Option, Profile, SharedProps, YearItem } from '@/types';

const delOpen = ref(false);
const delForm = useForm({ password: '' });
const delErr = computed(() => (delForm.errors as Record<string, string>).password || (delForm.errors as Record<string, string>).year || '');
const removeYear = () => delForm.post(`${usePage<SharedProps>().props.currentCompany ? `/aziende/${usePage<SharedProps>().props.currentCompany!.id}` : ''}/profilo-iva/anno/${props.year}/elimina`, { preserveScroll: true, onError: () => delForm.reset('password') });
const props = defineProps<{
    yearDelete: { summary: Record<string, number>; blocker: string | null };
    years: YearItem[];
    year: number;
    currentYear: number;
    active: Profile | null;
    draft: Profile | null;
    blockers: Record<string, string>;
    history: Profile[];
    audit: AuditItem[];
    options: { regimes: Option[]; methods: Option[]; periodicity: Option[]; quarterly_types: Option[] };
    supported: string[];
    unsupported: string[];
    ruleVersion: string;
}>();

const page = usePage<SharedProps>();
const company = computed(() => page.props.currentCompany!);
const base = computed(() => `/aziende/${company.value.id}`);
const opts = { preserveScroll: true };

// Barra degli anni: anni noti più l’anno visualizzato.
const yearTabs = computed(() => {
    const list = props.years.map((y) => y.year);
    if (!list.includes(props.year)) list.push(props.year);
    return list.sort((a, b) => b - a);
});
const yearInfo = (y: number) => props.years.find((i) => i.year === y);

const newYear = ref<number | ''>('');
const suggestedYear = computed(() => Math.max(props.currentYear, ...props.years.map((y) => y.year + 1)));
const addYear = () => router.post(`${base.value}/profilo-iva/bozza`, { year: Number(newYear.value || suggestedYear.value) }, { ...opts, onSuccess: () => (newYear.value = '') });

const form = useForm({
    regime: '', method: '', forfettario_iva: false, periodicity: '', quarterly_type: '', pro_rata: '100,00', notes: '', change_reason: '',
});
function load(d: Profile | null) {
    if (!d) return;
    form.defaults({
        regime: d.regime ?? '', method: d.method ?? '', forfettario_iva: d.forfettario_iva, periodicity: d.periodicity ?? '', quarterly_type: d.quarterly_type ?? '',
        pro_rata: d.pro_rata.replace('.', ','), notes: d.notes ?? '', change_reason: d.change_reason ?? '',
    });
    form.reset();
}
watch(() => props.draft, load, { immediate: true });

const confirmable = computed(() => props.draft && Object.keys(props.blockers).length === 0 && !form.isDirty);
const err = (k: string) => form.errors[k as keyof typeof form.errors] || (page.props.errors[k] ?? undefined);

const createDraft = () => router.post(`${base.value}/profilo-iva/bozza`, { year: props.year }, opts);
// La variante esiste solo nella Contabilità semplificata.
watch(() => form.regime, (r) => { if (r !== 'semplificata') form.method = ''; });
const save = () => form.transform((d) => ({ ...d, method: d.method || null, quarterly_type: d.periodicity === 'trimestrale' ? d.quarterly_type || null : null, pro_rata: d.pro_rata.trim().replace(',', '.') })).put(`${base.value}/profilo-iva/${props.draft!.id}`, opts);
const remove = () => confirm('Eliminare la bozza?') && router.delete(`${base.value}/profilo-iva/${props.draft!.id}`, opts);
const confirmDraft = () => {
    if (confirm(`Confermare il regime ${props.year}? Dopo la conferma non è più modificabile: eventuali correzioni richiedono una nuova versione.`)) {
        router.post(`${base.value}/profilo-iva/${props.draft!.id}/conferma`, {}, opts);
    }
};
</script>

<template>
    <Head title="Regime IVA" />
    <AppLayout crumb="Regime IVA">
        <div class="page-head">
            <div>
                <p class="eyebrow">Impostazioni</p>
                <h1>Regime IVA per anno</h1>
                <p>Ogni anno solare ha un solo regime, valido dal 1° gennaio al 31 dicembre. Semplicon non deduce il regime dalle fatture: lo indichi e lo confermi tu.</p>
            </div>
            <span class="badge" title="Versione del registro regole">Regole {{ ruleVersion }}</span>
        </div>

        <p v-if="page.props.errors.profile || page.props.errors.year" class="notice bad" role="alert" style="margin-bottom: 24px">
            <Icon name="alert" style="width: 20px; flex: none" /><span>{{ page.props.errors.profile || page.props.errors.year }}</span>
        </p>

        <nav class="year-bar" aria-label="Anni">
            <Link v-for="y in yearTabs" :key="y" :href="`${base}/profilo-iva?anno=${y}`" class="year-tab" :aria-current="y === year ? 'page' : undefined" preserve-scroll>
                <span class="tabular">{{ y }}</span>
                <small v-if="yearInfo(y)?.active">Confermato</small>
                <small v-else-if="yearInfo(y)?.has_draft">Bozza</small>
                <small v-else>Da impostare</small>
            </Link>
            <form class="year-add" @submit.prevent="addYear">
                <label for="new-year" class="sr-only">Anno da aggiungere</label>
                <input id="new-year" v-model="newYear" class="input tabular" type="number" :min="2000" :max="2100" :placeholder="String(suggestedYear)">
                <button class="btn" type="submit"><Icon name="plus" />Aggiungi anno</button>
            </form>
        </nav>

        <div class="split">
            <div class="stack">
                <section class="panel" aria-labelledby="active-title">
                    <div class="panel-head">
                        <div>
                            <h2 id="active-title">Regime {{ year }}</h2>
                            <p v-if="active">Dal 01/01/{{ year }} al 31/12/{{ year }} · versione {{ active.version }} · confermato da {{ active.confirmed_by }} il {{ dateTime(active.confirmed_at) }}</p>
                            <p v-else>Nessun regime confermato per il {{ year }}.</p>
                        </div>
                        <StatusBadge v-if="active" :status="active.status" :label="active.status_label" />
                    </div>
                    <div class="panel-body">
                        <template v-if="active">
                            <p class="regime-line">{{ active.regime_label }}</p>
                            <p v-if="active.method_label" class="small muted" style="margin: -8px 0 14px">{{ active.method_label }}</p>
                            <dl class="facts">
                                <div><dt>Forfettario con IVA</dt><dd>{{ active.forfettario_iva ? 'Sì' : 'No' }}</dd></div>
                                <div><dt>Periodicità</dt><dd>{{ active.periodicity_label }}</dd></div>
                                <div><dt>Aliquota pro rata</dt><dd class="tabular">{{ active.pro_rata.replace('.', ',') }}%</dd></div>
                            </dl>
                            <p v-if="active.notes" class="small muted" style="margin-top: 14px">{{ active.notes }}</p>
                        </template>
                        <p v-else class="muted">Crea una bozza per il {{ year }}, indica i dati e confermala.</p>
                        <div v-if="!draft" class="actions" style="margin-top: 16px">
                            <button class="btn" :class="{ primary: !active }" type="button" @click="createDraft">
                                <Icon name="plus" />{{ active ? `Correggi il ${year}` : `Crea bozza ${year}` }}
                            </button>
                        </div>
                    </div>
                </section>

                <section v-if="draft" class="panel" aria-labelledby="draft-title">
                    <div class="panel-head">
                        <div>
                            <h2 id="draft-title">Bozza {{ year }} · versione {{ draft.version }}</h2>
                            <p>Salva le modifiche, poi conferma. La conferma registra chi e quando.</p>
                        </div>
                        <StatusBadge status="bozza" label="Bozza" />
                    </div>
                    <form class="panel-body form" novalidate @submit.prevent="save">
                        <fieldset class="field">
                            <legend>Regime contabile</legend>
                            <div class="regime-options">
                                <label v-for="r in options.regimes" :key="r.value" class="regime-option">
                                    <input v-model="form.regime" type="radio" name="regime" :value="r.value">
                                    <span><strong>{{ r.label }}</strong></span>
                                </label>
                            </div>
                            <p v-if="err('regime')" class="error">{{ err('regime') }}</p>
                        </fieldset>

                        <fieldset v-if="form.regime === 'semplificata'" class="field">
                            <legend>Contabilità semplificata: quale regime?</legend>
                            <div class="regime-options one-col">
                                <label v-for="m in options.methods" :key="m.value" class="regime-option">
                                    <input v-model="form.method" type="radio" name="method" :value="m.value">
                                    <span><strong>{{ m.label }}</strong></span>
                                </label>
                            </div>
                            <p v-if="err('method')" class="error">{{ err('method') }}</p>
                        </fieldset>

                        <div class="form-row">
                            <div class="field">
                                <span class="field-label">Soggetto forfettario con IVA</span>
                                <label class="check"><input v-model="form.forfettario_iva" type="checkbox" name="forfettario_iva">Sì, nel {{ year }} il soggetto è forfettario con IVA</label>
                            </div>
                            <fieldset class="field">
                                <legend>Periodicità liquidazioni</legend>
                                <div class="segmented">
                                    <label v-for="o in options.periodicity" :key="o.value"><input v-model="form.periodicity" type="radio" name="periodicity" :value="o.value">{{ o.label }}</label>
                                </div>
                                <p v-if="err('periodicity')" class="error">{{ err('periodicity') }}</p>
                            </fieldset>
                            <fieldset v-if="form.periodicity === 'trimestrale'" class="field">
                                <legend>Tipo di trimestrale</legend>
                                <div class="segmented">
                                    <label v-for="o in options.quarterly_types" :key="o.value"><input v-model="form.quarterly_type" type="radio" name="quarterly_type" :value="o.value">{{ o.label }}</label>
                                </div>
                                <p v-if="err('quarterly_type')" class="error">{{ err('quarterly_type') }}</p>
                            </fieldset>
                        </div>

                        <Field id="pro_rata" :label="`Aliquota pro rata ${year}`" hint="Percentuale dell’IVA sugli acquisti detraibile nell’anno (100% = detrazione piena)" :error="err('pro_rata')" v-slot="{ describedby, invalid }">
                            <div class="input-suffix"><input id="pro_rata" v-model="form.pro_rata" class="input tabular" inputmode="decimal" :aria-describedby="describedby" :aria-invalid="invalid"><span aria-hidden="true">%</span></div>
                        </Field>

                        <Field v-if="active" id="change_reason" :label="`Motivo della correzione del ${year}`" :error="err('change_reason')" v-slot="{ describedby, invalid }">
                            <input id="change_reason" v-model="form.change_reason" class="input" :aria-describedby="describedby" :aria-invalid="invalid">
                        </Field>

                        <Field id="notes" label="Note (facoltative)" v-slot="{ describedby }">
                            <textarea id="notes" v-model="form.notes" class="input" :aria-describedby="describedby" />
                        </Field>

                        <div v-if="Object.keys(blockers).length" class="notice warn" role="status">
                            <Icon name="alert" style="width: 20px; flex: none" />
                            <div><strong>Prima della conferma</strong><ul><li v-for="(m, k) in blockers" :key="k">{{ m }}</li></ul></div>
                        </div>
                        <div v-else-if="!form.isDirty" class="notice good" role="status">
                            <Icon name="check" style="width: 20px; flex: none" /><span>La bozza è completa e può essere confermata.</span>
                        </div>

                        <div class="actions">
                            <button class="btn" :class="{ primary: form.isDirty }" type="submit" :disabled="form.processing || !form.isDirty">Salva bozza</button>
                            <button class="btn" :class="{ primary: confirmable }" type="button" :disabled="!confirmable" :title="form.isDirty ? 'Salva prima le modifiche' : undefined" @click="confirmDraft">
                                <Icon name="check" />Conferma {{ year }}
                            </button>
                            <button class="btn link" type="button" @click="remove">Elimina bozza</button>
                        </div>
                    </form>
                </section>

                <section v-if="history.length" class="panel" aria-labelledby="history-title">
                    <div class="panel-head"><h2 id="history-title">Versioni del {{ year }}</h2></div>
                    <div class="table-wrap" style="margin-top: 16px">
                        <table>
                            <thead><tr><th>Versione</th><th>Regime</th><th>Confermato</th></tr></thead>
                            <tbody>
                                <tr v-for="p in history" :key="p.id">
                                    <td class="nowrap"><strong>v{{ p.version }}</strong> <StatusBadge :status="p.status" :label="p.status_label" /><br><span class="small muted">Regole {{ p.rule_version }}</span><template v-if="p.change_reason"><br><span class="small muted">{{ p.change_reason }}</span></template></td>
                                    <td class="small" style="min-width: 220px">{{ p.regime_label }}<br><span class="muted">{{ p.periodicity_label }}<template v-if="p.forfettario_iva"> · Forfettario con IVA</template></span></td>
                                    <td class="small nowrap">{{ p.confirmed_by }}<br><span class="muted">{{ dateTime(p.confirmed_at) }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="stack">
            <section class="panel" aria-labelledby="info-title">
                <div class="panel-head"><h2 id="info-title">Cosa gestisce Semplicon</h2></div>
                <div class="panel-body info-lists">
                    <div><h3>Sempre supportati</h3><ul><li v-for="s in supported" :key="s"><Icon name="check" />{{ s }}</li></ul></div>
                    <div><h3>Non disponibili</h3><ul class="no"><li v-for="u in unsupported" :key="u">{{ u }}</li></ul></div>
                </div>
            </section>
            <section class="panel" aria-labelledby="audit-title">
                <div class="panel-head">
                    <div><h2 id="audit-title">Attività</h2><p>Registro non modificabile delle operazioni.</p></div>
                </div>
                <ol v-if="audit.length" class="timeline" style="margin-top: 18px">
                    <li v-for="a in audit" :key="a.id">
                        <p class="small"><strong>{{ a.label }}</strong><template v-if="a.data?.year"> · {{ a.data.year }}</template><template v-if="a.data?.version"> v{{ a.data.version }}</template></p>
                        <p class="when">{{ a.user ?? 'Sistema' }}<template v-if="a.user_is_admin"> (amministratore)</template> · {{ dateTime(a.created_at) }}</p>
                        <p v-if="a.reason" class="when">Motivo: {{ a.reason }}</p>
                    </li>
                </ol>
                <p v-else class="panel-body muted small">Nessuna attività.</p>
            </section>
            </div>
        </div>

        <section class="panel danger-zone" aria-labelledby="del-title" style="margin-top: 24px">
            <div class="panel-head"><div><h2 id="del-title">Elimina l’anno {{ year }}</h2><p>Rimuove in modo definitivo tutti i dati dell’anno: fatture con righe, scadenze, file XML e originali, registrazioni, liquidazioni, F24, crediti IVA, movimenti e il regime. Il registro attività conserva l’operazione.</p></div></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Fatture</dt><dd class="tabular">{{ yearDelete.summary.invoices }}<template v-if="yearDelete.summary.issued"> (emesse {{ yearDelete.summary.issued }})</template></dd></div>
                    <div><dt>Registrazioni IVA</dt><dd class="tabular">{{ yearDelete.summary.registrations }}</dd></div>
                    <div><dt>Liquidazioni</dt><dd class="tabular">{{ yearDelete.summary.settlements }}</dd></div>
                    <div><dt>F24 · crediti</dt><dd class="tabular">{{ yearDelete.summary.f24 }} · {{ yearDelete.summary.credits }}</dd></div>
                    <div><dt>Fuori campo · movimenti</dt><dd class="tabular">{{ yearDelete.summary.nonvat }} · {{ yearDelete.summary.movements }}</dd></div>
                </dl>
                <div v-if="yearDelete.blocker" class="notice warn" role="status"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ yearDelete.blocker }}</div></div>
                <template v-else>
                    <p class="small">Prima scarica l’<Link :href="`${base}/archivio`">archivio annuale</Link> se ti serve una copia.</p>
                    <button v-if="!delOpen" class="btn danger" type="button" @click="delOpen = true">Elimina l’anno {{ year }}…</button>
                    <form v-else class="del-form" novalidate @submit.prevent="removeYear">
                        <div class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div><strong>Operazione non reversibile</strong>Con la password confermi di eliminare l’anno {{ year }} con tutti i suoi dati.</div></div>
                        <div class="field" style="max-width: 360px"><label for="del-pw">La tua password</label><input id="del-pw" v-model="delForm.password" type="password" class="input" autocomplete="current-password" :aria-invalid="delErr ? true : undefined"><p v-if="delErr" class="error">{{ delErr }}</p></div>
                        <div class="actions"><button class="btn danger" type="submit" :disabled="delForm.processing || !delForm.password">Elimina definitivamente</button><button class="btn" type="button" @click="delOpen = false; delForm.reset(); delForm.clearErrors()">Annulla</button></div>
                    </form>
                </template>
            </div>
        </section>
    </AppLayout>
</template>
