export type Payment = { id: number; type: 'pagamento' | 'nota_credito'; date: string; amount: string; movement_id: number | null; movement_amount: string | null; method: string | null;
    bank_ref: string | null; payer_date: string | null; other_invoice_id: number | null; other_number: string | null; status?: string; movement_status?: string | null; reversal_reason?: string | null; seq?: number };
export type Rate = { id: number; seq: number; rates: number; invoice_id: number; number: string; doc_date: string; doc_type: string; credit_note: boolean; sale: boolean; party: string; party_key: string;
    due_date: string | null; payment_mode: string | null; source: string; edited: boolean; amount: string; settled: string; residual: string; paid: string; paid_label: string; due: string; due_label: string;
    amount_check: boolean; payments: Payment[] };

export const paidClass = (p: string) => ({ saldata: 'good', parziale: 'info', aperta: '', da_verificare: 'warn' } as Record<string, string>)[p] ?? '';
export const dueClass = (d: string) => ({ scaduta: 'bad', oggi: 'warn', da_definire: '', a_scadere: 'info' } as Record<string, string>)[d] ?? '';
export const toCents = (v: string) => { const m = String(v ?? '').trim().replace(/\./g, (x, i, s) => (s.indexOf(',') >= 0 ? '' : x)).replace(',', '.').match(/^(\d+)(?:\.(\d{1,2}))?$/); return m ? Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0')) : NaN; };
export const fromCents = (c: number) => `${Math.floor(c / 100)},${String(c % 100).padStart(2, '0')}`;
export const uuid = () => (globalThis.crypto && 'randomUUID' in globalThis.crypto ? globalThis.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);
