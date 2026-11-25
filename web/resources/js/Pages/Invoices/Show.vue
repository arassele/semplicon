<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Icon.vue';
import { date, dateTime, money, qty } from '@/lib/format';
import type { SharedProps } from '@/types';
import SchedulePanel, { type Schedule } from '@/Components/Scadenziario/SchedulePanel.vue';

type Party = { name: string; vat: string | null; cf: string | null };
type Summary = { rate: string; natura: string | null; natura_label: string | null; taxable: string; tax: string; esigibilita_label: string | null };
type Account = { id: number; code: string; name: string; type: string; categoria: string | null; voce_cee: string | null; iva_detraibile_pct: string | null; iva_nota: string | null; deducibilita_nota: string | null; limite: string | null };
type Panel = {
    blockers: string[]; acknowledge: string[]; direction: string | null; today: string; years: Record<string, string>;
    suggest: { vendita: { year: number; period: number } | null; acquisto: { year: number; period: number } | null; account_acquisto: number | null; account_vendita: number | null };
    accounts: Account[]; is_credit_note: boolean;
    rule_suggestions: Record<string, { type: 'single' | 'split' | 'choice'; account_id?: number; parts?: { account_id: number; taxable: string; tax: string }[]; accounts?: number[]; line?: string; rules: string[] }>;
    rule_party: string | null; group_descriptions: string[][];
};
type Reg = {
    id: number; protocol: number; year: number; status: string; period_label: string; document: { id: number; name: string; kind: string } | null; direction: string; registration_date: string; receipt_date: string | null; sign: number;
    taxable_total: string; tax_total: string; deductible_total: string; notes: string | null; acknowledged: string[]; created_by: string | null; created_at: string;
    period: number;
    replaces: string | null;
    lines: { account_id: number; summary_index: number | null; account: string; rate: string; natura: string | null; taxable: string; tax: string; deductible_pct: string; deductible_tax: string }[];
};
const props = defineProps<{
    credit?: { can: boolean; notes: { id: number; number: string; doc_date: string; total: string }[] } | null;
    invoice: {
        id: number; direction: string | null; direction_label: string; doc_type: string; doc_type_label: string; number: string; doc_date: string;
        currency: string; taxable_total: string; tax_total: string; total_amount: string | null; status: string; status_label: string;
        format: string; body_index: number; supplier: Party; customer: Party; issues: string[]; conflicts_with: number | null;
        document: { id: number; name: string; kind: string } | null; description: string | null; source: string; summaries: Summary[]; archived_at: string | null; receipt_date: string | null;
        lines: { line_no: number; description: string; quantity: string | null; unit: string | null; unit_price: string; total: string; rate: string; natura: string | null }[];
    };
    verify: boolean; returnTo: string | null; viewOnly: boolean; openCount: number; registration: Panel | null; schedule: Schedule; history: Reg[]; discard_reason: string | null;
}>();
const page = usePage<SharedProps>();
const base = computed(() => `/aziende/${page.props.currentCompany!.id}`);
const inv = computed(() => props.invoice);
const back = computed(() => (inv.value.direction === 'acquisto' ? 'acquisti' : inv.value.direction === 'vendita' ? 'vendite' : 'fatture'));
const active = computed(() => props.history.find((h) => h.status === 'registrata') ?? null);
const opts = { preserveScroll: true };

/* ---- Verifica e registra (Briefing 18.11.2026, Ticket 02–07) ---- */
/* Stato dichiarato in anticipo, così che i watcher con immediate possano accedervi. */
const errors = ref<Record<string, string>>({});
const conflict = ref<{ message: string; existing: { id: number; protocol: string; url: string } | null } | null>(null);
const e = (k: string) => errors.value[k];
const isOpen = computed(() => inv.value.status === 'importata' || inv.value.status === 'da_verificare');
const editing = ref(false);
const MONTHS = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
type Part = { account_id: number; taxable: string; tax: string; pctManual: string | null; remember?: boolean; keyword?: string };
type Group = { split: boolean; parts: Part[]; rule?: string[]; choice?: number[] };
type Draft = {
    direction: string; receipt_date: string; registration_date: string; periodManual: string | null; groups: Group[];
    acknowledged: string[]; notes: string; requestId: string | null; sentPayload: string | null; outcome: 'idle' | 'sending' | 'unknown';
};
// Bozze per documento (Ticket 02): passando a un’altra fattura compare il suo stato di lavorazione.
const drafts: Map<number, Draft> = (globalThis as unknown as { __sempliconDrafts?: Map<number, Draft> }).__sempliconDrafts ??= new Map();
const pp = computed(() => props.registration);
const accountById = (id: number) => pp.value?.accounts.find((a) => a.id === id) ?? null;
const accountLabel = (id: number) => { const a = accountById(id); return a ? `${a.code} · ${a.name}` : '—'; };
/* Funzioni di supporto per i centesimi: importi come testo “1.234,56” o “1234,56” ↔ centesimi interi. */
function toCents(v: string | null | undefined): number | null {
    const m = /^(-?)(\d{1,12})(?:[.,](\d{1,2}))?$/.exec(String(v ?? '').trim().replace(/\.(?=\d{3}(\D|$))/g, ''));
    if (!m) return null;
    const c = Number(m[2]) * 100 + Number((m[3] ?? '').padEnd(2, '0'));
    return m[1] ? -c : c;
}
const centsText = (c: number) => { const n = c < 0, a = String(Math.abs(c)).padStart(3, '0'); return `${n ? '-' : ''}${a.slice(0, -2)},${a.slice(-2)}`; };
const decText = (c: number) => centsText(c).replace(',', '.');
const sign = computed(() => (inv.value.doc_type === 'TD04' ? -1 : 1));
/* Importi target per gruppo IVA in centesimi (Nota di credito: valori assoluti, il segno viene impostato una sola volta dal server). */
function target(i: number): { t: number; v: number } {
    const s = inv.value.summaries[i];
    const t = toCents(s.taxable) ?? 0, v = toCents(s.tax) ?? 0;
    return sign.value < 0 ? { t: Math.abs(t), v: Math.abs(v) } : { t, v };
}
function suggestedAccount(d: string): number {
    const p = pp.value;
    return (d === 'acquisto' ? p?.suggest.account_acquisto : p?.suggest.account_vendita) ?? 0;
}
/* Ticket 09: Parola chiave suggerita per una regola = prima parola (≥ 3 lettere) della prima descrizione del gruppo. */
const keywordFor = (i: number) => (pp.value?.group_descriptions[i]?.[0] ?? '').toLowerCase().match(/[\p{L}]{3,}/u)?.[0] ?? '';
function groupFromRules(i: number, d: string): Group {
    const sug = pp.value?.rule_suggestions?.[String(i)];
    const base: Part = { account_id: d ? suggestedAccount(d) : 0, taxable: '', tax: '', pctManual: null, remember: false, keyword: keywordFor(i) };
    if (!sug) return { split: false, parts: [base] };
    if (sug.type === 'single') return { split: false, parts: [{ ...base, account_id: sug.account_id! }], rule: sug.rules };
    if (sug.type === 'split') return { split: true, parts: sug.parts!.map((p) => ({ account_id: p.account_id, taxable: centsText(toCents(p.taxable) ?? 0), tax: centsText(toCents(p.tax) ?? 0), pctManual: null, remember: false, keyword: '' })), rule: sug.rules };
    return { split: false, parts: [{ ...base, account_id: 0 }], rule: sug.rules, choice: sug.accounts };
}
function freshDraft(): Draft {
    const d = pp.value?.direction ?? '';
    return {
        direction: d, receipt_date: inv.value.receipt_date ?? inv.value.doc_date, registration_date: pp.value?.today ?? '', periodManual: null,
        groups: inv.value.summaries.map((_, i) => groupFromRules(i, d)),
        acknowledged: [], notes: '', requestId: null, sentPayload: null, outcome: 'idle',
    };
}
const draft = ref<Draft>(drafts.get(inv.value.id) ?? freshDraft());
drafts.set(inv.value.id, draft.value);
watch(() => props.invoice.id, (id) => {
    editing.value = false;
    draft.value = drafts.get(id) ?? freshDraft();
    drafts.set(id, draft.value);
    errors.value = {};
});
const dir = computed(() => draft.value.direction || null);
const accountsFor = computed(() => (pp.value ? pp.value.accounts.filter((a) => a.type === (dir.value === 'acquisto' ? 'costo' : 'ricavo') || a.type === 'patrimoniale') : []));
const accountGroups = computed(() => {
    const m = new Map<string, Account[]>();
    for (const a of accountsFor.value) { const k = a.categoria ?? 'Altri conti'; m.set(k, [...(m.get(k) ?? []), a]); }
    return [...m.entries()];
});
watch(() => draft.value.direction, (d, old) => {
    if (!d || d === old) return;
    for (const g of draft.value.groups) for (const p of g.parts) if (!accountsFor.value.some((a) => a.id === p.account_id)) p.account_id = suggestedAccount(d);
});

/* Ticket 02: periodo come valore comune “anno-periodo”, proposta o selezione manuale. */
const periodOptions = computed(() => Object.entries(pp.value?.years ?? {}).map(([y, per]) => ({
    year: Number(y),
    items: (per === 'trimestrale' ? [1, 2, 3, 4] : MONTHS.map((_, i) => i + 1)).map((n) => ({ key: `${y}-${n}`, label: per === 'trimestrale' ? `${n}° trimestre ${y}` : `${MONTHS[n - 1]} ${y}` })),
})));
const periodLabel = (key: string | null) => periodOptions.value.flatMap((g) => g.items).find((o) => o.key === key)?.label ?? '';
function periodOf(iso: string): string | null {
    const per = pp.value?.years[iso.slice(0, 4)];
    if (!iso || !per) return null;
    const m = Number(iso.slice(5, 7));
    return `${iso.slice(0, 4)}-${per === 'trimestrale' ? Math.floor((m - 1) / 3) + 1 : m}`;
}
const proposedPeriod = computed(() => (!dir.value ? null : dir.value === 'vendita' ? periodOf(inv.value.doc_date) : /^\d{4}-\d{2}-\d{2}$/.test(draft.value.receipt_date) ? periodOf(draft.value.receipt_date) : null));
const periodSel = computed(() => {
    const manual = draft.value.periodManual, proposed = proposedPeriod.value;
    return manual !== null
        ? { value: manual, source: 'manual' as const, differs: proposed !== null && proposed !== manual }
        : { value: proposed, source: proposed === null ? 'empty' as const : 'suggested' as const, differs: false };
});
const periodModel = computed({ get: () => periodSel.value.value ?? '', set: (v: string) => { draft.value.periodManual = v || null; } });

/* Ticket 03: proposta del conto e quota manuale separate. Senza indicazione nel conto vale 100 % (Impostazione fiscale “vuoto = 100 %”). */
const accountPct = (id: number) => { const a = accountById(id); return a ? (a.iva_detraibile_pct == null ? '100' : String(Number(a.iva_detraibile_pct)).replace('.', ',')) : null; };
const effectivePct = (p: Part) => p.pctManual ?? accountPct(p.account_id) ?? '';
const pctValid = (v: string) => { const m = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(v.trim()); return !!m && Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0')) <= 10000; };

/* Ticket 04: suddivisione di un gruppo IVA. */
function startSplit(i: number) {
    const g = draft.value.groups[i], t = target(i), first = g.parts[0];
    g.split = true;
    g.parts = [{ ...first, taxable: centsText(t.t), tax: centsText(t.v) }, { account_id: 0, taxable: '0,00', tax: '0,00', pctManual: null }];
}
function endSplit(i: number) { const g = draft.value.groups[i]; g.split = false; g.parts = [{ ...g.parts[0], taxable: '', tax: '' }]; }
function residual(i: number) {
    const g = draft.value.groups[i], t = target(i);
    if (!g.split) return { t: 0, v: 0, ok: true };
    let st = 0, sv = 0, ok = true;
    for (const p of g.parts) { const a = toCents(p.taxable), b = toCents(p.tax); if (a === null || b === null) ok = false; st += a ?? 0; sv += b ?? 0; }
    return { t: t.t - st, v: t.v - sv, ok };
}
function assignResidual(i: number) {
    const g = draft.value.groups[i], r = residual(i), last = g.parts[g.parts.length - 1];
    last.taxable = centsText((toCents(last.taxable) ?? 0) + r.t);
    last.tax = centsText((toCents(last.tax) ?? 0) + r.v);
}

/* Payload per anteprima e registrazione: gli stessi dati. */
const payload = computed(() => {
    const d = draft.value, sel = periodSel.value.value;
    return {
        direction: d.direction || null, receipt_date: d.direction === 'acquisto' ? d.receipt_date : null, registration_date: d.registration_date,
        year: sel ? Number(sel.split('-')[0]) : 0, period: sel ? Number(sel.split('-')[1]) : 0,
        lines: d.groups.flatMap((g, i) => g.parts.map((p) => ({ summary: i, account_id: p.account_id, deductible_pct: d.direction === 'acquisto' ? effectivePct(p) : null, ...(g.split ? { taxable: decText(toCents(p.taxable) ?? 0), tax: decText(toCents(p.tax) ?? 0) } : {}) }))),
        acknowledged: d.acknowledged, notes: d.notes,
        rules: editing.value ? [] : d.groups.flatMap((g) => g.parts.filter((p) => p.remember && (p.keyword ?? '').trim().length >= 2 && p.account_id).map((p) => ({ contains: p.keyword!.trim(), account_id: p.account_id }))),
    };
});
const ready = computed(() => {
    const p = pp.value, d = draft.value;
    if (!p || p.blockers.length || !dir.value || !periodSel.value.value || d.acknowledged.length !== p.acknowledge.length) return false;
    return d.groups.every((g, i) => { const r = residual(i); return r.ok && r.t === 0 && r.v === 0 && g.parts.every((x) => x.account_id && (dir.value !== 'acquisto' || pctValid(effectivePct(x)))); });
});

/* Richieste JSON con protezione CSRF (cookie XSRF di Laravel). */
function xsrf(): string { const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }
async function postJson(url: string, body: unknown, method = 'POST'): Promise<{ status: number; data: Record<string, unknown> }> {
    const res = await fetch(url, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
    let data: Record<string, unknown> = {};
    try { data = await res.json(); } catch { /* nessuna risposta JSON */ }
    return { status: res.status, data };
}

/* Ticket 05: anteprima dalle righe di registro preparate lato server. */
type PreviewLine = { account: string; rate: string; natura: string | null; taxable: string; tax: string; deductible_pct: string; deductible: string; non_deductible: string };
const preview = ref<{ ok: boolean; lines?: PreviewLine[]; totals?: Record<string, string>; period_label?: string; register?: string; errors?: Record<string, string[]> } | null>(null);
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewSeq = 0;
watch([payload, editing], () => {
    clearTimeout(previewTimer);
    if (!pp.value || (!isOpen.value && !editing.value)) return;
    previewTimer = setTimeout(async () => {
        const seq = ++previewSeq, id = inv.value.id;
        try {
            const r = await postJson(`${base.value}/fatture/${id}/anteprima`, { ...payload.value, editing: editing.value });
            if (seq === previewSeq && id === inv.value.id) preview.value = r.data as unknown as typeof preview.value;
        } catch { if (seq === previewSeq) preview.value = null; }
    }, 300);
}, { deep: true, immediate: true });

/* Ticket 06/07: invio con requestId, pulsante bloccato, “Esito da verificare” in caso di mancata risposta. */
async function send(next: boolean, retry = false) {
    const d = draft.value;
    if (d.outcome === 'sending') return;
    const body = retry && d.sentPayload ? JSON.parse(d.sentPayload) : { ...payload.value, next, request_id: (d.requestId ??= crypto.randomUUID()) };
    if (!retry) d.sentPayload = JSON.stringify(body);
    d.outcome = 'sending';
    errors.value = {}; conflict.value = null;
    const url = editing.value && active.value ? `${base.value}/registrazioni/${active.value.id}` : `${base.value}/fatture/${inv.value.id}/registra`;
    let r;
    try { r = await postJson(url, body, editing.value ? 'PUT' : 'POST'); } catch { d.outcome = 'unknown'; return; }
    if (r.status === 200 && r.data.ok) {
        d.outcome = 'idle'; d.requestId = null; d.sentPayload = null;
        drafts.delete(inv.value.id);
        editing.value = false;
        router.visit(String(r.data.next_url), { preserveScroll: false });
        return;
    }
    d.outcome = 'idle';
    if (r.status === 422) {
        errors.value = Object.fromEntries(Object.entries((r.data.errors ?? {}) as Record<string, string[]>).map(([k, v]) => [k, v[0]]));
        d.requestId = null; d.sentPayload = null; // rifiuto inequivocabile: il prossimo tentativo con un nuovo ID
    } else if (r.status === 409) {
        conflict.value = r.data as unknown as typeof conflict.value; d.requestId = null; d.sentPayload = null;
    } else if (r.status === 419) {
        conflict.value = { message: 'Sessione scaduta: ricarica la pagina.', existing: null };
    } else {
        d.outcome = 'unknown'; // nessuna risposta inequivocabile
    }
}
function startEdit() {
    const a = active.value;
    if (!a || !pp.value) return;
    const groups: Group[] = inv.value.summaries.map(() => ({ split: false, parts: [] }));
    a.lines.forEach((l, k) => {
        const gi = l.summary_index ?? k;
        (groups[gi] ?? groups[0]).parts.push({ account_id: l.account_id, taxable: centsText(Math.abs(toCents(l.taxable) ?? 0)), tax: centsText(Math.abs(toCents(l.tax) ?? 0)), pctManual: null });
    });
    groups.forEach((g, i) => {
        g.split = g.parts.length > 1;
        if (!g.split) g.parts = [{ ...(g.parts[0] ?? { account_id: 0, pctManual: null }), taxable: '', tax: '' } as Part];
        g.parts.forEach((p, j) => { const l = a.lines.filter((x, k) => (x.summary_index ?? k) === i)[j]; if (l && a.direction === 'acquisto') { const v = String(Number(l.deductible_pct)).replace('.', ','); if (v !== accountPct(p.account_id)) p.pctManual = v; } });
    });
    const key = `${a.year}-${a.period}`;
    draft.value = { ...freshDraft(), direction: a.direction, receipt_date: a.receipt_date ?? inv.value.doc_date, registration_date: a.registration_date, groups, acknowledged: [...pp.value.acknowledge], notes: a.notes ?? '' };
    draft.value.periodManual = key;
    if (proposedPeriod.value === key) draft.value.periodManual = null;
    drafts.set(inv.value.id, draft.value);
    editing.value = true;
    nextTick(() => document.getElementById('reg-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
function cancelEdit() { editing.value = false; draft.value = freshDraft(); drafts.set(inv.value.id, draft.value); errors.value = {}; }

/* ---- Scarta, ripristina, storna ---- */
const dialog = ref<'scarta' | 'storna' | null>(null);
// Sezione 3: visualizzazione senza modulo di registrazione (lente), ritorno all’elenco filtrato.
const viewMode = ref(props.viewOnly);
const restoreArchived = () => router.post(`${base.value}/fatture/${props.invoice.id}/ripristina-archivio`, {}, { preserveScroll: true });
const confirmDelete = ref<'registrazione' | 'fattura' | null>(null);
const deleting = ref(false);
function doDelete() {
    deleting.value = true;
    const url = confirmDelete.value === 'registrazione' ? `${base.value}/registrazioni/${active.value!.id}` : `${base.value}/fatture/${inv.value.id}`;
    router.delete(url, { preserveScroll: confirmDelete.value === 'registrazione', onFinish: () => { deleting.value = false; confirmDelete.value = null; } });
}
const kindLabel: Record<string, string> = { xml: 'XML', p7m: 'Firmato digitalmente (.p7m)', pdf: 'PDF', jpg: 'Immagine JPG', png: 'Immagine PNG' };
const attForm = useForm<{ file: File | null }>({ file: null });
const attach = () => attForm.post(`${base.value}/fatture/${inv.value.id}/allegato`, { preserveScroll: true, forceFormData: true, onSuccess: () => attForm.reset() });
const reason = useForm({ reason: '', date: props.registration?.today ?? new Date().toISOString().slice(0, 10), next: false });
function confirmDialog() {
    const url = dialog.value === 'scarta' ? `${base.value}/fatture/${inv.value.id}/scarta` : `${base.value}/registrazioni/${active.value!.id}/storna`;
    reason.next = props.verify;
    reason.post(url, { ...opts, onSuccess: () => { dialog.value = null; reason.reset(); } });
}
const restore = () => router.post(`${base.value}/fatture/${inv.value.id}/ripristina`, {}, opts);
const statusTone: Record<string, string> = { registrata: 'good', da_verificare: 'warn', importata: 'warn', scartata: '' };
</script>

<template>
    <Head :title="`Fattura ${inv.number}`" />
    <AppLayout :crumb="`${inv.doc_type_label} ${inv.number}`">
        <div v-if="verify" class="verify-bar" role="status">
            <Icon name="clock" /><span><strong>Modalità verifica</strong> · {{ openCount }} documenti da registrare</span>
            <Link :href="`${base}/fatture?stato=aperte`" class="btn link">Esci dalla verifica</Link>
        </div>

        <div class="page-head">
            <div>
                <p class="eyebrow"><Link :href="returnTo ?? `${base}/${back}`">{{ returnTo ? '‹ Torna all’elenco' : inv.direction === 'acquisto' ? 'Acquisti' : inv.direction === 'vendita' ? 'Vendite' : 'Fatture' }}</Link></p>
                <h1>{{ inv.doc_type_label }} n. {{ inv.number }}</h1>
                <p class="tabular">del {{ date(inv.doc_date) }} · {{ inv.direction_label }} · {{ inv.source === 'manuale' ? 'Inserita manualmente' : inv.source === 'emessa' ? `Emessa con Semplicon · ${inv.format}` : inv.format }} · <span class="badge" :class="statusTone[inv.status]">{{ inv.status_label }}</span></p>
            </div>
            <div class="actions">
                <a v-if="inv.source === 'emessa'" :href="`${base}/fatture/${inv.id}/xml`" class="btn primary"><Icon name="download" />Scarica XML (FatturaPA)</a>
                <Link v-if="inv.source === 'emessa' || inv.document?.kind === 'xml'" :href="`${base}/fatture/${inv.id}/anteprima-xml`" class="btn">Anteprima</Link>
                <a v-if="inv.source === 'emessa' || inv.document?.kind === 'xml'" :href="`${base}/fatture/${inv.id}/pdf`" class="btn"><Icon name="download" />PDF</a>
                <Link v-if="credit?.can" :href="`${base}/fatture/${inv.id}/nota-di-credito`" class="btn" title="Prepara una nota di credito TD04 con le righe di questa fattura">Emetti nota di credito</Link>
                <a v-else-if="inv.document" :href="`${base}/documenti/${inv.document.id}/scarica`" class="btn"><Icon name="download" />{{ inv.source === 'manuale' ? 'Scarica allegato' : 'Scarica originale' }}</a>
                <Link v-if="inv.source === 'manuale' && !['scartata', 'archiviata'].includes(inv.status)" :href="`${base}/fatture/${inv.id}/modifica`" class="btn">Modifica fattura</Link>
                <!-- Acquisti: non registrata → Elimina (archiviata), registrata → solo Storna nella registrazione -->
                <button v-if="inv.source !== 'emessa' && (inv.direction !== 'acquisto' || (!active && inv.status !== 'archiviata'))" class="btn danger" type="button" @click="confirmDelete = 'fattura'">Elimina</button>
                <button v-if="inv.status === 'archiviata'" class="btn primary" type="button" @click="restoreArchived">Ripristina</button>
                <Link v-if="inv.direction === 'acquisto' && !['scartata', 'archiviata'].includes(inv.status)" :href="`${base}/fatture/${inv.id}/integra-iva`" class="btn">Integra IVA / Reverse charge</Link>
                <button v-if="inv.source !== 'manuale' && inv.source !== 'emessa' && inv.document && inv.status !== 'archiviata'" class="btn" type="button" title="Rilegge l’XML con la logica di importazione attuale e mostra le differenze" @click="router.post(`${base}/fatture/${inv.id}/rielabora`)">Rielabora XML</button>
            </div>
        </div>

        <div v-if="credit?.notes.length" class="notice" role="status" style="margin-bottom: 18px">
            <Icon name="alert" style="width: 20px; flex: none" />
            <div><strong>Note di credito collegate</strong><span v-for="(n, i) in credit.notes" :key="n.id"><template v-if="i">, </template><Link :href="`${base}/fatture/${n.id}`">n. {{ n.number }} del {{ date(n.doc_date) }}</Link> ({{ money(n.total) }} €)</span></div>
        </div>
        <div v-if="viewMode && registration && isOpen" class="notice info" role="status" style="margin-bottom: 18px">
            <Icon name="search" style="width: 20px; flex: none" />
            <div><strong>Anteprima della fattura</strong>La fattura non è ancora registrata. <div class="actions" style="margin-top: 8px"><button class="btn primary" type="button" @click="viewMode = false">Verifica e registra</button><Link v-if="returnTo" :href="returnTo" class="btn">Torna all’elenco</Link></div></div>
        </div>
        <div v-if="registration && (isOpen || editing) && !viewMode" class="reg-wrap">
            <section class="panel reg-panel" aria-labelledby="reg-title">
                <div class="panel-head">
                    <div v-if="editing && active"><h2 id="reg-title">Modifica registrazione {{ active.protocol }}/{{ active.year }}</h2><p>Cambia date, periodo, conti o % detraibile. Il protocollo resta lo stesso se l’anno non cambia; i dati precedenti restano nel registro attività.</p></div>
                    <div v-else><h2 id="reg-title">Verifica e registra</h2><p>Controlla i dati, assegna i conti e conferma. Semplicon propone periodo, conti e percentuali: ciò che scegli tu è indicato come “manuale”.</p></div>
                </div>
                <form class="panel-body form" novalidate @submit.prevent="send(false)">
                    <div v-if="registration.blockers.length" class="notice bad" role="alert">
                        <Icon name="alert" style="width: 20px; flex: none" />
                        <div><strong>Non registrabile</strong><ul><li v-for="(b, i) in registration.blockers" :key="i">{{ b }}</li></ul></div>
                    </div>
                    <p v-if="e('registration')" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><span>{{ e('registration') }}</span></p>
                    <div v-if="conflict" class="notice bad" role="alert"><Icon name="alert" style="width: 20px; flex: none" /><div>{{ conflict.message }}<template v-if="conflict.existing"> <Link :href="conflict.existing.url">Apri la registrazione {{ conflict.existing.protocol }}</Link></template></div></div>

                    <fieldset class="plain-fieldset" :disabled="registration.blockers.length > 0 || draft.outcome !== 'idle'">
                        <div v-if="!registration.direction" class="field">
                            <span class="field-label">Tipo di registrazione</span>
                            <div class="segmented">
                                <label><input v-model="draft.direction" type="radio" name="direction" value="acquisto">Acquisto</label>
                                <label><input v-model="draft.direction" type="radio" name="direction" value="vendita">Vendita</label>
                            </div>
                            <p v-if="e('direction')" class="error">{{ e('direction') }}</p>
                        </div>

                        <div class="form-row three reg-dates">
                            <div v-if="dir === 'acquisto'" class="field">
                                <label for="receipt_date">Data di ricezione</label>
                                <input id="receipt_date" v-model="draft.receipt_date" class="input" type="date" :min="inv.doc_date" :max="registration.today" :aria-invalid="e('receipt_date') ? true : undefined">
                                <p class="hint">Proposta: data della fattura. Correggila se l’hai ricevuta dopo.</p>
                                <p v-if="e('receipt_date')" class="error">{{ e('receipt_date') }}</p>
                            </div>
                            <div class="field">
                                <label for="registration_date">Data di registrazione</label>
                                <input id="registration_date" v-model="draft.registration_date" class="input" type="date" :min="inv.doc_date" :max="registration.today" :aria-invalid="e('registration_date') ? true : undefined">
                                <p v-if="e('registration_date')" class="error">{{ e('registration_date') }}</p>
                            </div>
                            <div class="field">
                                <label for="period">Periodo IVA <span v-if="periodSel.source === 'suggested'" class="badge info">Proposta</span><span v-else-if="periodSel.source === 'manual'" class="badge warn">Scelta manuale</span></label>
                                <select id="period" v-model="periodModel" class="input" :aria-invalid="e('period') ? true : undefined">
                                    <option value="" disabled>Seleziona periodo</option>
                                    <optgroup v-for="g in periodOptions" :key="g.year" :label="String(g.year)"><option v-for="o in g.items" :key="o.key" :value="o.key">{{ o.label }}</option></optgroup>
                                </select>
                                <p v-if="periodSel.source === 'empty'" class="hint">{{ dir === 'acquisto' ? 'Indica la data di ricezione per la proposta, oppure scegli il periodo.' : 'Scegli il periodo.' }}</p>
                                <p v-else-if="periodSel.source === 'suggested'" class="hint">Calcolato dalla data {{ dir === 'acquisto' ? 'di ricezione' : 'del documento' }}.</p>
                                <p v-else-if="periodSel.differs" class="hint">Proposta: {{ periodLabel(proposedPeriod) }} · <button class="btn link" type="button" @click="draft.periodManual = null">Usa periodo proposto</button></p>
                                <p v-else class="hint"><button class="btn link" type="button" @click="draft.periodManual = null">Usa periodo proposto</button></p>
                                <p v-if="e('period')" class="error">{{ e('period') }}</p>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table class="reg-lines">
                                <thead><tr><th>Aliquota / Natura</th><th class="num">Imponibile</th><th class="num">Imposta</th><th>Conto</th><th v-if="dir === 'acquisto'" class="num">% detraibile</th></tr></thead>
                                <tbody v-for="(s, i) in inv.summaries" :key="i" :class="{ 'split-group': draft.groups[i].split }">
                                    <tr v-for="(part, j) in draft.groups[i].parts" :key="j">
                                        <td><template v-if="j === 0">{{ s.natura ? s.natura : `${money(s.rate)}%` }}<span v-if="draft.groups[i].split" class="small muted"><br>Originale XML: {{ money(s.taxable) }} / {{ money(s.tax) }}</span></template></td>
                                        <td class="num tabular">
                                            <template v-if="!draft.groups[i].split">{{ money(s.taxable) }}</template>
                                            <template v-else><input v-model="part.taxable" class="input tabular amt" inputmode="decimal" :aria-label="`Imponibile parte ${j + 1} riga ${i + 1}`"></template>
                                        </td>
                                        <td class="num tabular">
                                            <template v-if="!draft.groups[i].split">{{ money(s.tax) }}</template>
                                            <template v-else><input v-model="part.tax" class="input tabular amt" inputmode="decimal" :aria-label="`Imposta parte ${j + 1} riga ${i + 1}`"></template>
                                        </td>
                                        <td style="min-width: 240px">
                                            <select v-model.number="part.account_id" class="input" :aria-label="`Conto riga ${i + 1}${draft.groups[i].split ? ` parte ${j + 1}` : ''}`">
                                                <option :value="0" disabled>Scegli il conto</option>
                                                <optgroup v-for="[g, list] in accountGroups" :key="g" :label="g"><option v-for="a in list" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></optgroup>
                                            </select>
                                            <p v-if="j === 0 && draft.groups[i].choice?.length && !part.account_id" class="hint rule-hint">Più regole valide per “{{ pp?.group_descriptions[i]?.[0] }}”: scegli
                                                <template v-for="(id, n) in draft.groups[i].choice" :key="id"><button class="btn link" type="button" @click="part.account_id = id">{{ accountLabel(id) }}</button><template v-if="n < draft.groups[i].choice!.length - 1"> oppure </template></template>
                                            </p>
                                            <p v-else-if="j === 0 && draft.groups[i].rule?.length" class="hint rule-hint">Proposta dalla regola {{ draft.groups[i].rule!.join(', ') }}</p>
                                            <label v-if="pp?.rule_party && !editing && part.account_id" class="check rule-remember"><input v-model="part.remember" type="checkbox">
                                                <span>Ricorda questa scelta per {{ pp.rule_party }} quando la descrizione contiene <input v-model="part.keyword" class="input kw" :aria-label="`Testo della regola riga ${i + 1}`" placeholder="testo" @click.stop></span></label>
                                            <p v-if="accountById(part.account_id)?.iva_nota || accountById(part.account_id)?.limite" class="hint fiscal-hint">
                                                <template v-if="accountById(part.account_id)?.iva_nota">IVA: {{ accountById(part.account_id)?.iva_nota }}</template>
                                                <template v-if="accountById(part.account_id)?.limite"><br>{{ accountById(part.account_id)?.limite }}</template>
                                            </p>
                                            <div v-if="j === draft.groups[i].parts.length - 1" class="split-actions">
                                                <button v-if="!draft.groups[i].split" class="btn small-btn" type="button" @click="startSplit(i)"><Icon name="plus" />Ripartisci su più conti</button>
                                                <template v-else>
                                                    <button class="btn link" type="button" @click="draft.groups[i].parts.push({ account_id: 0, taxable: '0,00', tax: '0,00', pctManual: null })">Aggiungi conto</button>
                                                    <button v-if="draft.groups[i].parts.length > 2" class="btn link" type="button" @click="draft.groups[i].parts.pop()">Rimuovi ultima</button>
                                                    <button class="btn link" type="button" @click="endSplit(i)">Annulla ripartizione</button>
                                                </template>
                                            </div>
                                        </td>
                                        <td v-if="dir === 'acquisto'" class="num" style="width: 150px">
                                            <input :value="effectivePct(part)" class="input tabular" inputmode="decimal" :aria-label="`Percentuale detraibile riga ${i + 1}`" style="text-align: right" :aria-invalid="!pctValid(effectivePct(part)) ? true : undefined" @input="part.pctManual = ($event.target as HTMLInputElement).value">
                                            <p class="hint pct-src">
                                                <template v-if="part.pctManual === null">Proposta del conto</template>
                                                <template v-else>Manuale<template v-if="accountPct(part.account_id) !== null && accountPct(part.account_id) !== part.pctManual"> · <button class="btn link" type="button" @click="part.pctManual = null">Usa proposta del conto ({{ accountPct(part.account_id) }} %)</button></template></template>
                                            </p>
                                            <p v-if="!pctValid(effectivePct(part))" class="error">{{ effectivePct(part).trim() === '' ? 'Indica la percentuale (0–100).' : 'Percentuale tra 0 e 100.' }}</p>
                                        </td>
                                    </tr>
                                    <tr v-if="draft.groups[i].split" class="residual-row">
                                        <td colspan="5">
                                            <span :class="residual(i).t === 0 && residual(i).v === 0 && residual(i).ok ? 'good-text' : 'bad-text'">Residuo da attribuire: imponibile {{ centsText(residual(i).t) }} · imposta {{ centsText(residual(i).v) }}</span>
                                            <button v-if="residual(i).t !== 0 || residual(i).v !== 0" class="btn link" type="button" @click="assignResidual(i)">Assegna residuo</button>
                                            <p v-if="e(`groups.${i}`)" class="error">{{ e(`groups.${i}`) }}</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-for="(msg, k) in Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith('lines')))" :key="k" class="error">{{ msg }}</p>
                        <p v-if="dir === 'acquisto'" class="hint">La % detraibile riguarda la singola spesa (es. indetraibilità parziale). L’aliquota pro rata dell’anno si applica in liquidazione.</p>
                        <p v-if="registration.is_credit_note" class="hint">Nota di credito: gli importi riducono il registro.</p>

                        <fieldset v-if="registration.acknowledge.length" class="field ack">
                            <legend>Segnalazioni da verificare</legend>
                            <label v-for="(m, i) in registration.acknowledge" :key="i" class="check"><input v-model="draft.acknowledged" type="checkbox" :value="m">{{ m }}</label>
                            <p v-if="e('acknowledged')" class="error">{{ e('acknowledged') }}</p>
                        </fieldset>

                        <div class="field">
                            <label for="notes">Note (facoltative)</label>
                            <textarea id="notes" v-model="draft.notes" class="input" rows="2" />
                        </div>
                    </fieldset>

                    <!-- Ticket 05: effetto contabile dalle righe preparate lato server -->
                    <section class="booking-preview" aria-labelledby="pv-title">
                        <h3 id="pv-title">Cosa verrà registrato</h3>
                        <dl class="pv-facts">
                            <div v-if="dir === 'acquisto'"><dt>Data di ricezione</dt><dd class="tabular">{{ draft.receipt_date ? date(draft.receipt_date) : '—' }}</dd></div>
                            <div><dt>Data di registrazione</dt><dd class="tabular">{{ draft.registration_date ? date(draft.registration_date) : '—' }}</dd></div>
                            <div><dt>Periodo IVA</dt><dd>{{ periodLabel(periodSel.value) || '—' }} <span v-if="periodSel.source === 'suggested'" class="badge info">Proposta</span><span v-else-if="periodSel.source === 'manual'" class="badge warn">Scelta manuale</span></dd></div>
                            <div v-if="preview?.ok"><dt>Registro</dt><dd>{{ preview.register }}</dd></div>
                        </dl>
                        <div v-if="preview?.ok && preview.lines" class="table-wrap">
                            <table class="pv-table">
                                <thead><tr><th>Conto</th><th class="num">Imponibile</th><th class="num">IVA</th><th v-if="dir === 'acquisto'" class="num">Quota</th><th v-if="dir === 'acquisto'" class="num">Detraibile</th><th v-if="dir === 'acquisto'" class="num">Non detraibile</th></tr></thead>
                                <tbody><tr v-for="(l, k) in preview.lines" :key="k"><td>{{ l.account }}<br><span class="small muted">{{ l.natura ?? `${money(l.rate)}%` }}</span></td><td class="num tabular">{{ money(l.taxable) }}</td><td class="num tabular">{{ money(l.tax) }}</td><td v-if="dir === 'acquisto'" class="num tabular">{{ String(Number(l.deductible_pct)).replace('.', ',') }} %</td><td v-if="dir === 'acquisto'" class="num tabular">{{ money(l.deductible) }}</td><td v-if="dir === 'acquisto'" class="num tabular">{{ money(l.non_deductible) }}</td></tr></tbody>
                                <tfoot><tr class="sum-row"><td>Totale</td><td class="num tabular">{{ money(preview.totals!.taxable) }}</td><td class="num tabular">{{ money(preview.totals!.tax) }}</td><td v-if="dir === 'acquisto'" /><td v-if="dir === 'acquisto'" class="num tabular">{{ money(preview.totals!.deductible) }}</td><td v-if="dir === 'acquisto'" class="num tabular">{{ money(preview.totals!.non_deductible) }}</td></tr></tfoot>
                            </table>
                        </div>
                        <p v-else-if="preview && !preview.ok" class="small muted">Anteprima non ancora disponibile: {{ Object.values(preview.errors ?? {})[0]?.[0] }}</p>
                        <p v-else class="small muted">Calcolo dell’anteprima…</p>
                    </section>

                    <div v-if="draft.outcome === 'unknown'" class="notice warn" role="alert">
                        <Icon name="alert" style="width: 20px; flex: none" />
                        <div><strong>Esito da verificare</strong>La risposta del server non è arrivata: la registrazione potrebbe essere stata salvata. Ripeti lo stesso invio, Semplicon non crea una seconda registrazione.
                            <div class="actions" style="margin-top: 8px"><button class="btn primary" type="button" @click="send(false, true)">Ripeti lo stesso invio</button></div></div>
                    </div>
                    <div v-if="editing" class="actions">
                        <button class="btn primary" type="button" :disabled="!ready || draft.outcome !== 'idle'" @click="send(false)"><Icon name="check" />{{ draft.outcome === 'sending' ? 'Salvataggio…' : 'Salva modifiche' }}</button>
                        <button class="btn" type="button" @click="cancelEdit">Annulla</button>
                    </div>
                    <div v-else class="actions">
                        <button class="btn primary" type="button" :disabled="!ready || draft.outcome !== 'idle'" @click="send(verify)"><Icon name="check" />{{ draft.outcome === 'sending' ? 'Registrazione…' : verify ? 'Registra e passa al successivo' : 'Registra' }}</button>
                        <button v-if="verify" class="btn" type="button" :disabled="!ready || draft.outcome !== 'idle'" @click="send(false)">Registra e resta qui</button>
                        <button class="btn danger" type="button" style="margin-left: auto" @click="dialog = 'scarta'">Scarta fattura</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="split">
            <div class="stack">
                <div v-if="inv.status === 'archiviata'" class="notice warn" role="status">
                    <Icon name="alert" style="width: 20px; flex: none" />
                    <div><strong>Fattura archiviata</strong>Eliminata dalla lista da registrare il {{ dateTime(inv.archived_at) }}. Originale, allegati e numero interno sono conservati; un nuovo import non la riporta in lista.
                        <div class="actions" style="margin-top: 8px"><button class="btn primary" type="button" @click="restoreArchived">Ripristina</button></div></div>
                </div>
                <section v-if="inv.status === 'scartata'" class="panel">
                    <div class="panel-head"><div><h2>Fattura scartata</h2><p>Motivo: {{ discard_reason }}</p></div></div>
                    <div class="panel-body"><button class="btn" type="button" @click="restore">Ripristina</button></div>
                </section>

                <section v-if="active" class="panel" aria-labelledby="done-title">
                    <div class="panel-head">
                        <div><h2 id="done-title">Registrata · protocollo {{ active.protocol }}/{{ active.year }}</h2>
                            <p>{{ active.period_label }} · registrata il {{ date(active.registration_date) }}<template v-if="active.receipt_date"> · ricevuta il {{ date(active.receipt_date) }}</template> · {{ active.created_by }}</p>
                            <p class="attach-line"><Icon name="download" style="width: 16px" /><template v-if="active.document">Allegato: <a :href="`${base}/documenti/${active.document.id}/scarica`">{{ active.document.name }}</a></template><span v-else class="muted">Nessun documento allegato</span></p></div>
                        <span class="badge good">Registrata</span>
                    </div>
                    <dl class="facts panel-body">
                        <div><dt>Imponibile</dt><dd class="tabular">{{ active.sign < 0 ? '−' : '' }}{{ money(active.taxable_total) }}</dd></div>
                        <div><dt>Imposta</dt><dd class="tabular">{{ active.sign < 0 ? '−' : '' }}{{ money(active.tax_total) }}</dd></div>
                        <div v-if="active.direction === 'acquisto'"><dt>IVA detraibile</dt><dd class="tabular">{{ active.sign < 0 ? '−' : '' }}{{ money(active.deductible_total) }}</dd></div>
                    </dl>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Conto</th><th>Aliquota</th><th class="num">Imponibile</th><th class="num">Imposta</th><th v-if="active.direction === 'acquisto'" class="num">Detraibile</th></tr></thead>
                            <tbody>
                                <tr v-for="(l, i) in active.lines" :key="i">
                                    <td>{{ l.account }}</td><td>{{ l.natura ?? `${money(l.rate)}%` }}</td>
                                    <td class="num tabular">{{ money(l.taxable) }}</td><td class="num tabular">{{ money(l.tax) }}</td>
                                    <td v-if="active.direction === 'acquisto'" class="num tabular">{{ money(l.deductible_tax) }} <span class="small muted">({{ money(l.deductible_pct) }}%)</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel-body actions">
                        <button class="btn" type="button" :disabled="editing" @click="startEdit"><Icon name="check" />Modifica registrazione</button>
                        <button class="btn danger" type="button" @click="confirmDelete = 'registrazione'">Elimina registrazione</button>
                        <button v-if="active.direction === 'acquisto'" class="btn link" type="button" @click="dialog = 'storna'">Storna invece di eliminare</button>
                        <Link class="btn" :href="`${base}/registri/${active.direction === 'acquisto' ? 'acquisti' : 'vendite'}?anno=${active.year}&periodo=${active.period}`">Vedi nel registro</Link>
                    </div>
                </section>

                <div v-if="inv.issues.length && !registration" class="notice warn" role="status">
                    <Icon name="alert" style="width: 20px; flex: none" />
                    <div><strong>Segnalazioni dell’importazione</strong><ul><li v-for="(m, i) in inv.issues" :key="i">{{ m }}</li></ul></div>
                </div>
                <p v-if="inv.conflicts_with" class="small"><Link :href="`${base}/fatture/${inv.conflicts_with}`">Apri il documento con lo stesso numero già presente</Link></p>

                <section class="panel">
                    <dl class="facts panel-body">
                        <div><dt>Imponibile</dt><dd class="tabular">{{ money(inv.taxable_total) }}</dd></div>
                        <div><dt>IVA</dt><dd class="tabular">{{ money(inv.tax_total) }}</dd></div>
                        <div><dt>Totale documento</dt><dd class="tabular">{{ inv.total_amount ? money(inv.total_amount) : '—' }}</dd></div>
                    </dl>
                </section>

                <SchedulePanel v-if="inv.status !== 'scartata' && page.props.currentCompany!.modules.scadenziario" :base="base" :invoice-id="inv.id" :s="schedule" />

                <section v-if="inv.description" class="panel">
                    <div class="panel-head"><h2>Descrizione</h2></div>
                    <p class="panel-body" style="white-space: pre-line">{{ inv.description }}</p>
                </section>
                <section v-if="inv.lines.length" class="panel" aria-labelledby="lines-title">
                    <div class="panel-head"><h2 id="lines-title">Righe del documento</h2></div>
                    <div class="table-wrap" style="margin-top: 14px">
                        <table>
                            <thead><tr><th>N.</th><th>Descrizione</th><th class="num">Quantità</th><th class="num">Prezzo</th><th class="num">Totale</th><th class="num">IVA</th></tr></thead>
                            <tbody>
                                <tr v-for="l in inv.lines" :key="l.line_no">
                                    <td class="tabular">{{ l.line_no }}</td><td style="min-width: 220px">{{ l.description }}</td>
                                    <td class="num tabular nowrap">{{ l.quantity ? qty(l.quantity) : '—' }} <span class="small muted">{{ l.unit }}</span></td>
                                    <td class="num tabular nowrap">{{ qty(l.unit_price) }}</td><td class="num tabular nowrap">{{ qty(l.total) }}</td>
                                    <td class="num tabular nowrap">{{ l.natura ?? `${money(l.rate)}%` }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="stack">
                <section class="panel">
                    <div class="panel-head"><h2>Cedente / Prestatore</h2></div>
                    <div class="panel-body party"><strong>{{ inv.supplier.name }}</strong><span v-if="inv.supplier.vat">P. IVA {{ inv.supplier.vat }}</span><span v-if="inv.supplier.cf">C.F. {{ inv.supplier.cf }}</span></div>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Cessionario / Committente</h2></div>
                    <div class="panel-body party"><strong>{{ inv.customer.name }}</strong><span v-if="inv.customer.vat">P. IVA {{ inv.customer.vat }}</span><span v-if="inv.customer.cf">C.F. {{ inv.customer.cf }}</span></div>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Riepilogo IVA</h2></div>
                    <ul class="list" style="margin-top: 8px">
                        <li v-for="(s, i) in inv.summaries" :key="i" style="display: block">
                            <p class="title">{{ s.natura ? `${s.natura} · ${s.natura_label}` : `${money(s.rate)}%` }}</p>
                            <p class="meta tabular">Imponibile {{ money(s.taxable) }} · Imposta {{ money(s.tax) }}<template v-if="s.esigibilita_label"> · {{ s.esigibilita_label }}</template></p>
                        </li>
                    </ul>
                </section>
                <section v-if="history.length" class="panel">
                    <div class="panel-head"><h2>Storico registrazioni</h2></div>
                    <ol class="timeline" style="margin-top: 16px">
                        <li v-for="h in history" :key="h.id">
                            <p class="small"><strong>Prot. {{ h.protocol }}/{{ h.year }} · {{ h.status === 'storno' ? 'Storno' : h.status === 'stornata' ? 'Stornata' : 'Registrata' }}</strong></p>
                            <p class="when">{{ h.period_label }} · {{ h.created_by }} · {{ dateTime(h.created_at) }}</p>
                            <p v-if="h.notes" class="when">{{ h.notes }}</p>
                        </li>
                    </ol>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>{{ inv.source === 'manuale' ? 'Allegato' : 'File originale' }}</h2></div>
                    <div v-if="inv.document" class="panel-body small"><p><a :href="`${base}/documenti/${inv.document.id}/scarica`">{{ inv.document.name }}</a></p><p class="muted">{{ kindLabel[inv.document.kind] ?? inv.document.kind }}</p></div>
                    <form v-else-if="inv.source === 'manuale' && (inv.status === 'importata' || inv.status === 'da_verificare')" class="panel-body attach-form" @submit.prevent="attach">
                        <p class="small muted">Nessun documento allegato. Puoi allegare il PDF, la foto o l’XML della fattura prima di registrarla.</p>
                        <label class="sr-only" for="att-file">Allegato</label>
                        <input id="att-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.xml" class="input" @change="attForm.file = ($event.target as HTMLInputElement).files?.[0] ?? null">
                        <p v-if="attForm.errors.file" class="error">{{ attForm.errors.file }}</p>
                        <button class="btn" type="submit" :disabled="!attForm.file || attForm.processing"><Icon name="upload" />Allega</button>
                    </form>
                    <p v-else class="panel-body small muted">Nessun documento allegato.</p>
                </section>
            </div>
        </div>

        <div v-if="dialog" class="dialog-wrap" @click.self="dialog = null">
            <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-title" @submit.prevent="confirmDialog">
                <h2 id="dlg-title">{{ dialog === 'scarta' ? 'Scartare la fattura?' : 'Stornare la registrazione?' }}</h2>
                <p class="muted">{{ dialog === 'scarta' ? 'La fattura non verrà registrata (es. duplicato o documento errato). Puoi ripristinarla in seguito.' : 'Viene creata una registrazione di storno con nuovo protocollo, nel periodo IVA della data dello storno. La fattura torna da registrare.' }}</p>
                <template v-if="dialog === 'storna'">
                    <label class="field-label" for="dlg-date">Data dello storno</label>
                    <input id="dlg-date" v-model="reason.date" class="input" type="date" :min="active?.registration_date" :max="new Date().toISOString().slice(0, 10)">
                    <p class="hint">Lo storno viene registrato nel periodo IVA di questa data.</p>
                    <p v-if="reason.errors.date" class="error">{{ reason.errors.date }}</p>
                </template>
                <label class="field-label" for="dlg-reason">Motivo</label>
                <textarea id="dlg-reason" v-model="reason.reason" class="input" rows="2" required />
                <p v-if="reason.errors.reason" class="error">{{ reason.errors.reason }}</p>
                <div class="actions">
                    <button class="btn danger" type="submit" :disabled="!reason.reason.trim() || reason.processing">{{ dialog === 'scarta' ? 'Scarta' : 'Storna' }}</button>
                    <button class="btn" type="button" @click="dialog = null">Annulla</button>
                </div>
            </form>
        </div>
        <div v-if="confirmDelete" class="dialog-wrap" @click.self="confirmDelete = null">
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="del-title">
                <h2 id="del-title">{{ confirmDelete === 'registrazione' ? `Eliminare la registrazione ${active?.protocol}/${active?.year}?` : `Eliminare la fattura ${inv.number}?` }}</h2>
                <p v-if="confirmDelete === 'registrazione'" class="muted">La registrazione viene tolta dal registro IVA e la fattura torna da registrare. Il numero di protocollo resta libero (nel registro risulterà un salto di numerazione). I dati restano nel registro attività.</p>
                <p v-else-if="inv.direction === 'acquisto'" class="muted">La fattura esce dalla lista “Da registrare” e viene archiviata con originale, allegati, identificativo di importazione e numero interno. Un nuovo import o una nuova elaborazione dell’XML non la riportano in lista: per farlo usa “Ripristina”.</p>
                <p v-else class="muted">La fattura viene eliminata con tutte le sue registrazioni{{ history.length ? ` (${history.length})` : '' }}<template v-if="inv.source !== 'manuale'">; il file importato, se non contiene altre fatture, si potrà importare di nuovo</template>. I dati restano nel registro attività.</p>
                <div class="actions"><button class="btn danger" type="button" :disabled="deleting" @click="doDelete">Elimina</button><button class="btn" type="button" @click="confirmDelete = null">Annulla</button></div>
            </div>
        </div>
    </AppLayout>
</template>
