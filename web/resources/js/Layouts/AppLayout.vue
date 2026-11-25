<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import QuickSearch from '@/Components/QuickSearch.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import type { SharedProps } from '@/types';

defineProps<{ crumb?: string }>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user!);
const company = computed(() => page.props.currentCompany);
const companies = computed(() => page.props.companies);
const flash = computed(() => page.props.flash.success);
// Ticket 07: la conferma dell’ultima fattura registrata resta visibile nella pagina successiva.
const registered = computed(() => page.props.flash.registered);
const registeredUrl = computed(() => (registered.value ? `${registered.value.url.split('#')[0]}&ritorno=${encodeURIComponent(page.url)}#${registered.value.url.split('#')[1] ?? ''}` : ''));
// P. IVA e Regime non compaiono più nella barra laterale (indicazione del committente, 21.11.2026); sono visibili in Panoramica e Dati azienda.
const open = ref(false);
// Ricerca rapida: Ctrl/Cmd+K apre la palette da qualsiasi pagina di un’azienda
const searchOpen = ref(false);
const searchPages = computed(() => [{ label: 'Panoramica', href: base.value }, ...groups.value.flatMap((g) => flat(g.items).map((i) => ({ label: i.label, href: i.href }))),
    { label: 'Nuovo cespite', href: `${base.value}/cespiti/nuovo` }, { label: 'Importazione progressivi cespiti', href: `${base.value}/cespiti/importazione` }]);
const onGlobalKey = (e: KeyboardEvent) => { if (company.value && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); searchOpen.value = !searchOpen.value; } };
onMounted(() => window.addEventListener('keydown', onGlobalKey));
// Tabelle a schede su telefono (critique del 25.11.2026): ogni cella riceve l’intestazione della sua colonna
const labelTables = () => nextTick(() => document.querySelectorAll('table.cardify').forEach((t) => { const hs = [...t.querySelectorAll('thead th')].map((h) => h.textContent?.trim() ?? ''); t.querySelectorAll('tbody tr').forEach((r) => [...r.children].forEach((td, k) => { if (hs[k] && !(td as HTMLElement).dataset.label) (td as HTMLElement).dataset.label = hs[k]; })); }));
onMounted(labelTables);
watch(() => page.props, labelTables, { deep: false });
onUnmounted(() => window.removeEventListener('keydown', onGlobalKey));
const toast = ref<string | null>(null);

watch(flash, (v) => {
    toast.value = v;
    if (v) setTimeout(() => (toast.value = null), 3500);
}, { immediate: true });

const base = computed(() => (company.value ? `/aziende/${company.value.id}` : ''));
const isCurrent = (path: string) => page.url.split('?')[0] === path || (path !== base.value && page.url.startsWith(path));

function switchYear(e: Event) {
    router.post(`${base.value}/anno`, { year: Number((e.target as HTMLSelectElement).value) }, { preserveScroll: true });
}
function switchCompany(e: Event) {
    const id = (e.target as HTMLSelectElement).value;
    router.visit(id ? `/aziende/${id}` : '/aziende');
}

/* Menu (concetto del committente, 21.11.2026, Decisione n. 82): gruppi chiusi, aperto solo il gruppo della pagina corrente.
   Cespiti è un sottogruppo espandibile in Contabilità (terzo livello). I numeri indicano il lavoro aperto; sui gruppi chiusi come somma.
   I moduli disattivati (Funzioni e opzioni) nascondono il proprio gruppo o le proprie voci. */
type NavItem = { label: string; href: string; icon: string; count?: string; current?: () => boolean; module?: 'emissione' | 'scadenziario' | 'adempimenti'; id?: string; children?: NavItem[] };
type NavGroup = { id: string; label: string; icon: string; items: NavItem[]; module?: 'emissione' | 'scadenziario' | 'adempimenti' };
const navCounts = computed(() => page.props.navCounts ?? {});
const groups = computed<NavGroup[]>(() => {
    const b = base.value;
    const ces = `${b}/cespiti`;
    const all: NavGroup[] = [
        { id: 'fatture', label: 'Fatture', icon: 'plus', module: 'emissione', items: [
            { label: 'Emetti fattura', href: `${b}/fatture/emetti`, icon: 'plus', current: () => page.url.split('?')[0] === `${b}/fatture/emetti` },
            { label: 'Fatture emesse', href: `${b}/fatture-elettroniche`, icon: 'doc', count: 'da_trasmettere' },
            { label: 'Fatture ricorrenti', href: `${b}/fatture/ricorrenti`, icon: 'calendar', count: 'ricorrenti' },
        ] },
        { id: 'incassi', label: 'Incassi e pagamenti', icon: 'cash', module: 'scadenziario', items: [
            { label: 'Da incassare', href: `${b}/scadenziario/da-incassare`, icon: 'download', count: 'da_incassare' },
            { label: 'Da pagare', href: `${b}/scadenziario/da-pagare`, icon: 'out', count: 'da_pagare' },
            { label: 'Movimenti finanziari', href: `${b}/scadenziario/incassi-e-pagamenti`, icon: 'layers' },
        ] },
        { id: 'contabilita', label: 'Contabilità', icon: 'doc', items: [
            { label: 'Importa XML', href: `${b}/importa`, icon: 'upload' },
            { label: 'Da registrare', href: `${b}/fatture?stato=aperte`, icon: 'clock', count: 'da_registrare', current: () => page.url.includes('stato=aperte') || page.url.includes('/verifica') || (/\/fatture\/\d+/.test(page.url) && ['importata', 'da_verificare'].includes(String((page.props as Record<string, any>).invoice?.status ?? ''))) },
            { label: 'Acquisti', href: `${b}/acquisti`, icon: 'download' },
            { label: 'Vendite', href: `${b}/vendite`, icon: 'out' },
            { label: 'Fuori campo IVA', href: `${b}/fuori-campo`, icon: 'percent' },
            { label: 'Conto economico', href: `${b}/conto-economico`, icon: 'layers' },
            { id: 'cespiti', label: 'Cespiti', href: ces, icon: 'building', children: [
                { label: 'Elenco cespiti', href: ces, icon: 'layers', current: () => page.url.split('?')[0] === ces || /\/cespiti\/(\d+|nuovo|importazione)/.test(page.url) },
                { label: 'Movimenti cespiti', href: `${ces}/movimenti`, icon: 'arrow' },
                { label: 'Calcolo ammortamenti', href: `${ces}/ammortamenti`, icon: 'calendar' },
                { label: 'Prima nota cespiti', href: `${ces}/prima-nota`, icon: 'cash' },
                { label: 'Stampe e registri', href: `${ces}/stampe`, icon: 'doc' },
                { label: 'Categorie e aliquote', href: `${ces}/categorie`, icon: 'percent' },
                { label: 'Impostazioni', href: `${ces}/impostazioni`, icon: 'gear' },
            ] },
        ] },
        { id: 'adempimenti', label: 'Adempimenti fiscali', icon: 'percent', module: 'adempimenti', items: [
            { label: 'Registri IVA', href: `${b}/registri/acquisti`, icon: 'layers', current: () => isCurrent(`${b}/registri`) },
            { label: 'Liquidazioni e LIPE', href: `${b}/liquidazioni`, icon: 'percent', count: 'liquidazioni' },
            { label: 'Crediti IVA', href: `${b}/crediti-iva`, icon: 'shield' },
            { label: 'Operazioni transfrontaliere', href: `${b}/operazioni-transfrontaliere`, icon: 'globe', count: 'transfrontaliere' },
        ] },
        { id: 'report', label: 'Report e scadenze', icon: 'calendar', items: [
            { label: 'Riepilogo per cassa', href: `${b}/scadenziario/riepilogo-fiscale`, icon: 'calendar', module: 'scadenziario' },
            { label: 'Stampa', href: `${b}/stampa`, icon: 'doc', module: 'adempimenti' },
            { label: 'Archivio annuale', href: `${b}/archivio`, icon: 'layers', module: 'adempimenti' },
        ] },
        { id: 'tabelle', label: 'Tabelle', icon: 'users', items: [
            { label: 'Clienti', href: `${b}/anagrafiche/clienti`, icon: 'users' },
            { label: 'Fornitori', href: `${b}/anagrafiche/fornitori`, icon: 'building' },
            { label: 'Articoli e testi', href: `${b}/tabelle/catalogo`, icon: 'layers' },
            { label: 'Piano dei conti', href: `${b}/tabelle/piano-dei-conti`, icon: 'layers' },
            { label: 'Regole conti', href: `${b}/tabelle/regole`, icon: 'check' },
            { label: 'Parametri fiscali', href: `${b}/tabelle/parametri-fiscali`, icon: 'percent' },
            { label: 'Scadenze fiscali', href: `${b}/scadenziario/scadenze-fiscali`, icon: 'clock', module: 'adempimenti' },
            { label: 'Codici ATECO', href: `${b}/tabelle/ateco`, icon: 'building' },
        ] },
        { id: 'impostazioni', label: 'Impostazioni', icon: 'gear', items: [
            { label: 'Dati azienda', href: `${b}/dati-azienda`, icon: 'building' },
            { label: 'Regime fiscale e IVA', href: `${b}/profilo-iva`, icon: 'shield' },
            { label: 'Funzioni e opzioni', href: `${b}/impostazioni/funzioni`, icon: 'gear' },
        ] },
    ];
    const m = company.value?.modules;
    const on = (k?: string) => !k || !m || (m as Record<string, boolean>)[k] !== false;
    return all.filter((g) => on(g.module)).map((g) => ({ ...g, items: g.items.filter((it) => on(it.module)) })).filter((g) => g.items.length);
});
const flat = (items: NavItem[]): NavItem[] => items.flatMap((it) => (it.children ? it.children : [it]));
const itemCurrent = (it: NavItem): boolean => (it.children ? it.children.some(itemCurrent) : it.current ? it.current() : isCurrent(it.href.split('?')[0]));
const countOf = (it: NavItem): number => (it.children ? it.children.reduce((n, c) => n + countOf(c), 0) : it.count ? navCounts.value[it.count] ?? 0 : 0);
const groupCount = (g: NavGroup) => g.items.reduce((n, it) => n + countOf(it), 0);
const activeGroup = computed(() => groups.value.find((g) => g.items.some(itemCurrent))?.id ?? null);
const activeSub = computed(() => groups.value.flatMap((g) => g.items).find((it) => it.children && itemCurrent(it))?.id ?? null);
const expanded = ref<Set<string>>(new Set());
// A ogni cambio di pagina: aperto solo il gruppo (e sottogruppo) della pagina corrente.
watch(() => page.url, () => { expanded.value = new Set([activeGroup.value, activeSub.value ? `sub-${activeSub.value}` : null].filter((x): x is string => !!x)); }, { immediate: true });
function toggle(id: string) {
    const s = new Set(expanded.value);
    if (s.has(id)) s.delete(id); else s.add(id);
    expanded.value = s;
}
const footGroup = computed(() => groups.value.find((g) => g.id === 'impostazioni')!);
const mainGroups = computed(() => groups.value.filter((g) => g.id !== 'impostazioni'));
</script>

<template>
    <a class="skip-link" href="#contenuto">Vai al contenuto</a>
    <QuickSearch v-if="company" :base="base" :pages="searchPages" :open="searchOpen" @close="searchOpen = false" />
    <nav class="sidebar" :class="{ open }" aria-label="Navigazione principale">
        <Link href="/" class="wordmark" aria-label="Semplicon, home">semplicon<i /></Link>

        <div class="company-switch">
            <label for="company-select">Azienda</label>
            <select id="company-select" :value="company?.id ?? ''" @change="switchCompany">
                <option value="">{{ user.is_admin ? 'Tutte le aziende' : 'Le tue aziende' }}</option>
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </div>

        <template v-if="company">
            <button type="button" class="search-btn" aria-haspopup="dialog" @click="searchOpen = true"><Icon name="search" /><span>Cerca</span><kbd>Ctrl K</kbd></button>
            <ul class="nav">
                <li><Link :href="base" :aria-current="isCurrent(base) ? 'page' : undefined"><Icon name="home" />Panoramica</Link></li>
            </ul>
            <ul class="nav nav-groups">
                <li v-for="g in mainGroups" :key="g.id">
                    <button type="button" class="nav-group" :class="{ active: activeGroup === g.id }" :aria-expanded="expanded.has(g.id)" :aria-controls="`nav-${g.id}`" @click="toggle(g.id)">
                        <Icon :name="g.icon" />{{ g.label }}
                        <span v-if="!expanded.has(g.id) && groupCount(g)" class="nav-count" :aria-label="`${groupCount(g)} da fare`">{{ groupCount(g) }}</span>
                        <Icon name="chevron" class="nav-chev" />
                    </button>
                    <ul v-show="expanded.has(g.id)" :id="`nav-${g.id}`" class="nav nav-sub">
                        <li v-for="it in g.items" :key="it.href + (it.id ?? '')">
                            <template v-if="it.children">
                                <button type="button" class="nav-subgroup" :class="{ active: itemCurrent(it) }" :aria-expanded="expanded.has(`sub-${it.id}`)" :aria-controls="`nav-sub-${it.id}`" @click="toggle(`sub-${it.id}`)">
                                    {{ it.label }}<span v-if="!expanded.has(`sub-${it.id}`) && countOf(it)" class="nav-count">{{ countOf(it) }}</span><Icon name="chevron" class="nav-chev" />
                                </button>
                                <ul v-show="expanded.has(`sub-${it.id}`)" :id="`nav-sub-${it.id}`" class="nav nav-sub nav-sub2">
                                    <li v-for="c in it.children" :key="c.href"><Link :href="c.href" :aria-current="itemCurrent(c) ? 'page' : undefined">{{ c.label }}</Link></li>
                                </ul>
                            </template>
                            <Link v-else :href="it.href" :aria-current="itemCurrent(it) ? 'page' : undefined">{{ it.label }}<span v-if="countOf(it)" class="nav-count">{{ countOf(it) }}</span></Link>
                        </li>
                    </ul>
                </li>
            </ul>
            <div class="nav-foot">
                <button type="button" class="nav-group" :class="{ active: activeGroup === 'impostazioni' }" :aria-expanded="expanded.has('impostazioni')" aria-controls="nav-impostazioni" @click="toggle('impostazioni')">
                    <Icon name="gear" />Impostazioni<Icon name="chevron" class="nav-chev" />
                </button>
                <ul v-show="expanded.has('impostazioni')" id="nav-impostazioni" class="nav nav-sub">
                    <li v-for="it in footGroup.items" :key="it.href"><Link :href="it.href" :aria-current="itemCurrent(it) ? 'page' : undefined">{{ it.label }}</Link></li>
                </ul>
            </div>
        </template>
        <ul v-else class="nav">
            <li><Link href="/aziende" :aria-current="page.url.startsWith('/aziende') ? 'page' : undefined"><Icon name="building" />{{ user.is_admin ? 'Tutte le aziende' : 'Le tue aziende' }}</Link></li>
        </ul>
        <template v-if="user.is_admin">
            <p class="nav-label">Amministrazione</p>
            <ul class="nav">
                <li><Link href="/amministrazione" :aria-current="page.url.startsWith('/amministrazione') ? 'page' : undefined"><Icon name="users" />Utenti e aziende</Link></li>
            </ul>
        </template>

        <div class="sidebar-footer">
            <span class="avatar">{{ user.initials }}</span>
            <Link href="/account" class="who who-link" aria-label="Il mio account e backup">
                <strong>{{ user.name }}</strong>
                <span>{{ user.is_admin ? 'Amministratore' : 'Cliente' }} · Account e backup</span>
            </Link>
            <Link href="/esci" method="post" as="button">Esci</Link>
        </div>
    </nav>
    <div v-if="open" class="scrim" @click="open = false" />

    <div class="workspace">
        <header class="topbar">
            <button class="btn menu-toggle" type="button" :aria-expanded="open" aria-label="Apri menu" @click="open = !open"><Icon name="menu" /></button>
            <p class="crumb">{{ company ? company.name : 'Semplicon' }}<template v-if="crumb"> / {{ crumb }}</template></p>
            <NotificationBell v-if="company" :base="base" :counts="navCounts" />
            <label v-if="company?.fiscal_year" class="year-switch"><span>Anno contabile</span>
                <select :value="company.fiscal_year.active" aria-label="Anno contabile" @change="switchYear"><option v-for="y in company.fiscal_year.years" :key="y" :value="y">{{ y }}</option></select>
            </label>
            <span v-if="company?.admin_view" class="badge info">Accesso amministratore</span>
        </header>
        <main id="contenuto" tabindex="-1">
            <div v-if="registered" class="notice good registered-banner" role="status">
                <Icon name="check" style="width: 20px; flex: none" />
                <span>Fattura <strong>{{ registered.invoice }}</strong> registrata · {{ registered.register }} · Protocollo {{ registered.protocol }} · {{ registered.period }}</span>
                <Link :href="registeredUrl" class="btn link">Apri registrazione</Link>
            </div>
            <slot />
        </main>
    </div>
    <div v-if="toast" class="toast" role="status">{{ toast }}</div>
</template>
