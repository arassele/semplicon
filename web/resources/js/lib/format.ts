export function date(iso: string | null | undefined): string {
    if (!iso) return '—';
    const [y, m, d] = iso.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

export function dateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

/** Importo come testo ("1234.5" o "1234.50") → "1.234,50". Senza passare per il float. */
export function money(value: string | null | undefined, decimals = 2): string {
    if (value === null || value === undefined || value === '') return '—';
    const neg = value.startsWith('-');
    const [int, frac = ''] = value.replace('-', '').split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${neg ? '−' : ''}${grouped},${(frac + '0'.repeat(decimals)).slice(0, decimals)}`;
}

/** Quantità o prezzo con fino a 8 decimali, zeri superflui rimossi (minimo 2). */
export function qty(value: string | null | undefined): string {
    if (!value) return '—';
    const [int, frac = ''] = value.split('.');
    const trimmed = frac.replace(/0+$/, '');
    return money(`${int}.${trimmed.padEnd(2, '0')}`, Math.max(2, trimmed.length));
}
