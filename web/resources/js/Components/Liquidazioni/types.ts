export type Dest = { ok: boolean; reasons: string[]; first_date: string | null };
export type Movement = { id: number; destination: string; destination_label: string; amount: string; status: string; status_label: string; target: { year: number; period: number } | null;
    f24: { id: number; status: string; settlement_id: number | null } | null; claim: { id: number; status: string } | null; reason: string | null; date: string };
export type Credit = { id: number; kind: string; kind_label: string; origin: string; description: string; year: number; period: number | null; amount: string; residual: string; status: string;
    filed_at: string | null; visto: boolean; settlement: { year: number; period: number; label: string } | null; next: { year: number; period: number; label: string };
    destinations: Record<'riporto' | 'compensazione' | 'rimborso', Dest>; movements: Movement[] };
