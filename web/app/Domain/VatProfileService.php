<?php

namespace App\Domain;

use App\Domain\Scadenziario\FiscalService;
use App\Enums\ProfileStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\VatProfile;
use Illuminate\Support\Facades\DB;

/**
 * Regime IVA di un'azienda per anno solare (I01). Regole: docs/FISCAL_RULES.md.
 *
 * Un regime vale sempre dal 1° gennaio al 31 dicembre. Non esiste alcun modo di creare o confermare
 * un anno automaticamente, in particolare non in base ai totali delle fatture.
 */
final class VatProfileService
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /** Ticket 11: trimestrali per opzione o speciali (art. 74 c. 4). */
    public const QUARTERLY_TYPES = ['opzione', 'speciale'];

    private const EDITABLE = ['regime', 'method', 'forfettario_iva', 'periodicity', 'quarterly_type', 'pro_rata', 'notes', 'change_reason'];

    public function createDraft(Company $company, User $actor, int $year): VatProfile
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            RuleViolation::fail(['year' => 'Indica un anno tra '.self::MIN_YEAR.' e '.self::MAX_YEAR.'.']);
        }

        return DB::transaction(function () use ($company, $actor, $year) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            // La nuova creazione di un anno eliminato lo rende di nuovo selezionabile
            if (in_array($year, array_map('intval', (array) $company->fresh()->removed_years), true)) {
                $company->update(['removed_years' => array_values(array_diff(array_map('intval', (array) $company->fresh()->removed_years), [$year]))]);
            }
            if ($company->draftVatProfile($year)) {
                RuleViolation::fail(['year' => "Esiste già una bozza per il {$year}."]);
            }

            // Modello: versione valida dello stesso anno, altrimenti l'ultimo anno precedente confermato.
            $template = $company->activeVatProfile($year)
                ?? $company->vatProfiles()->where('status', ProfileStatus::Confermato)->where('year', '<', $year)
                    ->orderByDesc('year')->orderByDesc('version')->first();

            $draft = VatProfile::create([
                'company_id' => $company->id,
                'year' => $year,
                'version' => (int) $company->vatProfiles()->where('year', $year)->max('version') + 1,
                'status' => ProfileStatus::Bozza,
                'regime' => $template?->regime,
                'method' => $template?->method,
                'forfettario_iva' => $template?->forfettario_iva ?? false,
                'periodicity' => $template?->periodicity,
                'quarterly_type' => $template?->quarterly_type,
                'pro_rata' => $template?->pro_rata ?? '100.00',
                'rule_version' => config('semplicon.rule_version'),
                'created_by' => $actor->id,
            ]);
            Audit::record('vat_profile.draft_created', $actor, $company, $draft, data: ['year' => $year, 'version' => $draft->version]);

            return $draft;
        });
    }

    public function updateDraft(Company $company, VatProfile $profile, User $actor, array $data): VatProfile
    {
        if ($profile->status !== ProfileStatus::Bozza) {
            RuleViolation::fail(['profile' => 'Solo le bozze possono essere modificate.']);
        }
        $profile->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
        // La variante esiste solo nella contabilità semplificata.
        if ($profile->regime && ! $profile->regime->needsMethod()) {
            $profile->method = null;
        }
        // Tipo di trimestrale solo con trimestrale (ticket 11).
        if ($profile->periodicity?->value !== 'trimestrale') {
            $profile->quarterly_type = null;
        } elseif (! $profile->quarterly_type) {
            $profile->quarterly_type = 'opzione'; // precompilazione (caso più frequente), visibile e modificabile nel modulo
        }
        $changes = array_keys($profile->getDirty());
        $profile->save();
        if ($changes) {
            Audit::record('vat_profile.draft_updated', $actor, $company, $profile, data: ['year' => $profile->year, 'version' => $profile->version, 'fields' => $changes]);
        }

        return $profile;
    }

    public function deleteDraft(Company $company, VatProfile $profile, User $actor): void
    {
        if ($profile->status !== ProfileStatus::Bozza) {
            RuleViolation::fail(['profile' => 'Solo le bozze possono essere eliminate.']);
        }
        $profile->delete();
        Audit::record('vat_profile.draft_deleted', $actor, $company, $profile, data: ['year' => $profile->year, 'version' => $profile->version]);
    }

    /** Tutti i motivi per cui la bozza non può essere confermata (vuoto = confermabile). */
    public function blockers(Company $company, VatProfile $profile): array
    {
        if ($profile->status !== ProfileStatus::Bozza) {
            return ['profile' => 'Il regime non è una bozza.'];
        }

        $errors = [];
        if (! $profile->regime) {
            $errors['regime'] = 'Scegli il regime contabile.';
        }
        if ($profile->regime?->needsMethod() && ! $profile->method) {
            $errors['method'] = 'Per la contabilità semplificata scegli tra regime opzionale (per competenza) e regime naturale (per cassa).';
        }
        if (! $profile->periodicity) {
            $errors['periodicity'] = 'Indica la periodicità delle liquidazioni.';
        }
        if ($profile->periodicity?->value === 'trimestrale' && ! in_array($profile->quarterly_type, self::QUARTERLY_TYPES, true)) {
            $errors['quarterly_type'] = 'Indica il tipo di liquidazione trimestrale: per opzione (art. 7 DPR 542/1999, interessi 1%) o speciale (art. 74 c. 4 DPR 633/1972, senza interessi).';
        }
        if (! self::isValidProRata((string) $profile->pro_rata)) {
            $errors['pro_rata'] = 'L’aliquota pro rata deve essere tra 0 e 100%.';
        }
        if ($company->activeVatProfile($profile->year) && blank($profile->change_reason)) {
            $errors['change_reason'] = "Il {$profile->year} è già confermato: indica il motivo della correzione.";
        }

        return $errors;
    }

    /**
     * Pro rata come testo decimale con al massimo due decimali, da 0 a 100.
     * Confronto in centesimi come intero, senza virgola mobile.
     */
    public static function isValidProRata(string $value): bool
    {
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return false;
        }
        $hundredths = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $hundredths <= 10000;
    }

    /** Conferma la bozza in modo atomico; una versione valida dello stesso anno viene sostituita e resta conservata. */
    public function confirm(Company $company, VatProfile $profile, User $actor): VatProfile
    {
        return DB::transaction(function () use ($company, $profile, $actor) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $locked = VatProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if ($errors = $this->blockers($company, $locked)) {
                RuleViolation::fail($errors);
            }

            $active = $company->activeVatProfile($locked->year);
            $active?->update(['status' => ProfileStatus::Sostituito]);

            $locked->update([
                'status' => ProfileStatus::Confermato,
                'rule_version' => config('semplicon.rule_version'),
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);

            app(FiscalService::class)->syncCompany($company); // l'attribuzione fiscale segue il profilo confermato
            Audit::record('vat_profile.confirmed', $actor, $company, $locked, $locked->change_reason, [
                'year' => $locked->year,
                'version' => $locked->version,
                'regime' => $locked->regime->value,
                'method' => $locked->method?->value,
                'forfettario_iva' => $locked->forfettario_iva,
                'periodicity' => $locked->periodicity->value,
                'quarterly_type' => $locked->quarterly_type,
                'pro_rata' => (string) $locked->pro_rata,
                'rule_version' => $locked->rule_version,
                'replaces_version' => $active?->version,
            ]);

            return $locked;
        });
    }
}
