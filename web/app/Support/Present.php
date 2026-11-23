<?php

namespace App\Support;

use App\Enums\ProfileStatus;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\VatProfile;

/** Formatta i modelli per l'interfaccia. */
final class Present
{
    public static function options(array $cases): array
    {
        return array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], $cases);
    }

    public static function periodicityLabel(VatProfile $p): ?string
    {
        $l = $p->periodicity?->label();

        return $l && $p->periodicity->value === 'trimestrale' ? $l.match ($p->quarterly_type) {
            'opzione' => ' per opzione', 'speciale' => ' speciale', default => ' (tipo da indicare)'
        } : $l;
    }

    public static function profileSummary(?VatProfile $p): ?array
    {
        return $p ? [
            'year' => $p->year,
            'version' => $p->version,
            'regime_label' => $p->regimeLabel(),
            'forfettario_iva' => $p->forfettario_iva,
            'periodicity_label' => self::periodicityLabel($p),
            'pro_rata' => (string) $p->pro_rata,
        ] : null;
    }

    /**
     * Avviso sul regime per un anno (ticket 01): determinante il profilo confermato, anche se accanto esiste una bozza di correzione;
     * altrimenti bozza o «da impostare».
     *
     * @return array{year: int, state: 'confermato'|'bozza'|'mancante', label: string, forfettario_iva: bool}
     */
    public static function regimeBadge(Company $company, int $year): array
    {
        $active = $company->activeVatProfile($year);
        if ($active) {
            return ['year' => $year, 'state' => 'confermato', 'forfettario_iva' => (bool) $active->forfettario_iva,
                'label' => "{$year} · ".($active->forfettario_iva ? 'Forfettario con IVA' : ($active->regimeLabel() ?: 'Regime IVA confermato'))];
        }
        $draft = $company->vatProfiles()->where('year', $year)->where('status', ProfileStatus::Bozza)->exists();

        return ['year' => $year, 'state' => $draft ? 'bozza' : 'mancante', 'forfettario_iva' => false,
            'label' => $draft ? "{$year} · Regime IVA in bozza" : "{$year} · Regime IVA da impostare"];
    }

    /** Panoramica di tutti gli anni di un'azienda: regime confermato e se è aperta una bozza. */
    public static function years(Company $company): array
    {
        $profiles = $company->vatProfiles()->orderByDesc('year')->orderByDesc('version')->get();

        return $profiles->groupBy('year')->map(function ($list, $year) {
            $active = $list->first(fn ($p) => $p->status === ProfileStatus::Confermato);

            return [
                'year' => (int) $year,
                'active' => self::profileSummary($active),
                'has_draft' => $list->contains(fn ($p) => $p->status === ProfileStatus::Bozza),
            ];
        })->values()->all();
    }

    public static function profile(VatProfile $p): array
    {
        return [
            'id' => $p->id,
            'year' => $p->year,
            'version' => $p->version,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'regime' => $p->regime?->value,
            'regime_label' => $p->regimeLabel(),
            'method' => $p->method?->value,
            'method_label' => $p->method?->label(),
            'forfettario_iva' => $p->forfettario_iva,
            'periodicity' => $p->periodicity?->value,
            'periodicity_label' => self::periodicityLabel($p),
            'quarterly_type' => $p->quarterly_type,
            'pro_rata' => (string) $p->pro_rata,
            'rule_version' => $p->rule_version,
            'notes' => $p->notes,
            'change_reason' => $p->change_reason,
            'confirmed_by' => $p->confirmer?->name,
            'confirmed_at' => $p->confirmed_at?->toIso8601String(),
        ];
    }

    private const ACTIONS = [
        'auth.login' => 'Accesso effettuato',
        'invoice.created_manual' => 'Fattura manuale creata',
        'invoice.updated' => 'Fattura manuale modificata',
        'invoice.deleted' => 'Fattura eliminata',
        'registration.updated' => 'Registrazione modificata',
        'account_rule.created' => 'Regola di contabilizzazione creata',
        'account_rule.deleted' => 'Regola di contabilizzazione eliminata',
        'registration.deleted' => 'Registrazione eliminata',
        'invoice.attachment_added' => 'Documento allegato',
        'movement.incasso' => 'Incasso registrato',
        'movement.pagamento' => 'Pagamento registrato',
        'movement.allocated' => 'Importo abbinato a fatture',
        'movement.reversed' => 'Incasso/pagamento stornato',
        'allocation.compensation' => 'Nota di credito compensata',
        'allocation.reversed' => 'Abbinamento stornato',
        'installments.updated' => 'Scadenze modificate',
        'payment_status.confirmed' => 'Stato pagamento confermato',
        'scadenziario.exported' => 'Scadenziario esportato (CSV)',
        'invoice.issued' => 'Fattura elettronica emessa',
        'invoice.archived' => 'Fattura archiviata (eliminata dalla lista)',
        'invoice.restored_archive' => 'Fattura ripristinata dall’archivio',
        'invoice.reprocessed' => 'Fattura aggiornata da nuova elaborazione XML',
        'integration.created' => 'Integrazione IVA (reverse charge) registrata',
        'xml.generated' => 'XML generato',
        'xml.downloaded' => 'XML scaricato',
        'xml.classified' => 'Operazione transfrontaliera classificata',
        'non_vat.created' => 'Movimento fuori campo IVA registrato',
        'non_vat.reversed' => 'Movimento fuori campo IVA stornato',
        'auth.registered' => 'Account creato',
        'company.created' => 'Azienda creata',
        'company.updated' => 'Dati azienda modificati',
        'import.started' => 'Importazione avviata',
        'import.completed' => 'Importazione completata',
        'document.downloaded' => 'Documento scaricato',
        'registration.created' => 'Fattura registrata',
        'registration.reversed' => 'Registrazione stornata',
        'invoice.discarded' => 'Fattura scartata',
        'invoice.restored' => 'Fattura ripristinata',
        'accounts.updated' => 'Piano dei conti modificato',
        'register.exported' => 'Registro IVA esportato',
        'vat_profile.draft_created' => 'Bozza regime IVA creata',
        'vat_profile.draft_updated' => 'Bozza regime IVA modificata',
        'vat_profile.draft_deleted' => 'Bozza regime IVA eliminata',
        'vat_profile.confirmed' => 'Regime IVA confermato',
    ];

    public static function audit(AuditEvent $e): array
    {
        return [
            'id' => $e->id,
            'action' => $e->action,
            'label' => self::ACTIONS[$e->action] ?? $e->action,
            'user' => $e->user?->name,
            'user_is_admin' => (bool) $e->user?->is_admin,
            'reason' => $e->reason,
            'data' => $e->data,
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }
}
