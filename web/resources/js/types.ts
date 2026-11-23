export type Option = { value: string; label: string; hint?: string | null };

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string; initials: string; is_admin: boolean } | null };
    companies: { id: number; name: string }[];
    currentCompany: { id: number; name: string; partita_iva: string; admin_view: boolean; fiscal_year: { active: number; years: number[] }; modules: { scadenziario: boolean; emissione: boolean; adempimenti: boolean }; regime: { year: number; state: 'confermato' | 'bozza' | 'mancante'; label: string; forfettario_iva: boolean } } | null;
    navCounts: Record<string, number> | null;
    flash: { success: string | null; registered: { id: number; invoice: string; register: string; protocol: string; period: string; url: string } | null };
    errors: Record<string, string>;
};

export type Profile = {
    id: number; year: number; version: number; status: string; status_label: string;
    regime: string | null; regime_label: string | null; method: string | null; method_label: string | null; forfettario_iva: boolean;
    periodicity: string | null; periodicity_label: string | null; quarterly_type: string | null;
    pro_rata: string;
    rule_version: string; notes: string | null; change_reason: string | null;
    confirmed_by: string | null; confirmed_at: string | null;
};

export type ProfileSummary = { year: number; version: number; regime_label: string; forfettario_iva: boolean; periodicity_label: string; pro_rata: string };

export type YearItem = { year: number; active: ProfileSummary | null; has_draft: boolean };

export type AuditItem = {
    id: number; action: string; label: string; user: string | null; reason: string | null;
    user_is_admin: boolean; data: Record<string, unknown> | null; created_at: string;
};
