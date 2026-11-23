<?php

namespace App\Domain;

use App\Http\Controllers\InvoiceController;
use App\Http\Middleware\ActiveAccountingYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Eliminare l'anno contabile (indicazione del committente, 20.11.2026). Rimuove definitivamente tutto ciò che appartiene all'anno: fatture dell'anno con righe, rate,
 * file XML e originali, tutte le registrazioni, liquidazioni, F24, crediti, rimborsi, rettifiche, registrazioni fuori campo, pagamenti dell'
 * anno, contatori dei numeri di fattura e il regime. Solo dopo conferma della password; il registro delle attività conserva l'operazione.
 * L'eliminazione avviene con il livello query (i modelli proteggono i record confermati da modifiche).
 */
final class YearService
{
    /** @return array<string, int> */
    public function summary(Company $company, int $year): array
    {
        $ids = $this->invoiceIds($company, $year);

        return [
            'invoices' => count($ids),
            'issued' => DB::table('invoices')->whereIn('id', $ids)->where('source', 'emessa')->count(),
            'registrations' => DB::table('vat_registrations')->where('company_id', $company->id)->where(fn ($q) => $q->where('year', $year)->orWhereIn('invoice_id', $ids))->count(),
            'settlements' => DB::table('vat_settlements')->where('company_id', $company->id)->where('year', $year)->count(),
            'f24' => DB::table('f24_drafts')->where('company_id', $company->id)->whereIn('settlement_id', DB::table('vat_settlements')->where('company_id', $company->id)->where('year', $year)->pluck('id'))->count(),
            'credits' => DB::table('vat_credits')->where('company_id', $company->id)->where('year', $year)->count(),
            'nonvat' => DB::table('non_vat_entries')->where('company_id', $company->id)->where('year', $year)->count(),
            'movements' => DB::table('money_movements')->where('company_id', $company->id)->whereBetween('movement_date', ["{$year}-01-01", "{$year}-12-31"])->count(),
        ];
    }

    /** Errore dovuto a dipendenze in altri anni (null = eliminabile). */
    public function blocker(Company $company, int $year): ?string
    {
        $creditIds = DB::table('vat_credits')->where('company_id', $company->id)->where('year', $year)->pluck('id');
        $yearSettlements = DB::table('vat_settlements')->where('company_id', $company->id)->where('year', $year)->pluck('id');
        $used = DB::table('vat_credit_movements')->where('company_id', $company->id)->whereIn('credit_id', $creditIds)->whereNotNull('settlement_id')->whereNotIn('settlement_id', $yearSettlements)->exists();
        if ($used) {
            return "Il credito IVA del {$year} è già stato usato in un altro anno: elimina o correggi prima le liquidazioni degli anni successivi.";
        }

        return null;
    }

    /** @return list<int> */
    private function invoiceIds(Company $company, int $year): array
    {
        $in = InvoiceController::yearScope(DB::table('invoices')->where('company_id', $company->id), $year)->pluck('id');
        $viaReg = DB::table('vat_registrations')->where('company_id', $company->id)->where('year', $year)->whereNotNull('invoice_id')->pluck('invoice_id');

        return $in->merge($viaReg)->unique()->map(fn ($v) => (int) $v)->values()->all();
    }

    /** @return array<string, int> quantità eliminate */
    public function delete(Company $company, User $actor, int $year, string $password, Request $request): array
    {
        $key = "year-delete:{$actor->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            RuleViolation::fail(['password' => 'Troppi tentativi. Riprova tra qualche minuto.']);
        }
        if (! Hash::check($password, (string) $actor->password)) {
            RateLimiter::hit($key, 300);
            RuleViolation::fail(['password' => 'La password non è corretta.']);
        }
        RateLimiter::clear($key);
        if (! in_array($year, InvoiceController::years($company), true) && ! DB::table('vat_profiles')->where('company_id', $company->id)->where('year', $year)->exists()) {
            RuleViolation::fail(['year' => "L’anno {$year} non esiste per questa azienda."]);
        }
        if ($block = $this->blocker($company, $year)) {
            RuleViolation::fail(['year' => $block]);
        }
        $summary = $this->summary($company, $year);
        $files = [];

        DB::transaction(function () use ($company, $year, &$files) {
            $cid = $company->id;
            $inv = $this->invoiceIds($company, $year);
            $regIds = DB::table('vat_registrations')->where('company_id', $cid)->where(fn ($q) => $q->where('year', $year)->orWhereIn('invoice_id', $inv))->pluck('id')->all();
            $docIds = collect()
                ->merge(DB::table('invoices')->whereIn('id', $inv)->pluck('document_id'))->merge(DB::table('vat_registrations')->whereIn('id', $regIds)->pluck('document_id'))
                ->merge(DB::table('invoice_integrations')->where('company_id', $cid)->where(fn ($q) => $q->where('year', $year)->orWhereIn('invoice_id', $inv))->pluck('document_id'))
                ->merge(DB::table('invoice_versions')->whereIn('invoice_id', $inv)->pluck('document_id'))->merge(DB::table('non_vat_entries')->where('company_id', $cid)->where('year', $year)->pluck('document_id'))
                ->merge(DB::table('vat_claims')->where('company_id', $cid)->where('year', $year)->pluck('document_id'))->filter()->unique()->values()->all();

            DB::table('fiscal_entries')->where('company_id', $cid)->where(fn ($q) => $q->where('year', $year)->orWhereIn('invoice_id', $inv))->delete();
            DB::table('money_movements')->where('company_id', $cid)->whereBetween('movement_date', ["{$year}-01-01", "{$year}-12-31"])->delete();
            DB::table('registration_requests')->where('company_id', $cid)->where(fn ($q) => $q->whereIn('registration_id', $regIds)->orWhereIn('invoice_id', $inv))->delete();
            // Registrazioni: prima le righe di storno (rimandano all'originale), poi le altre
            DB::table('vat_registrations')->whereIn('id', $regIds)->whereNotNull('reverses_id')->delete();
            DB::table('vat_registrations')->whereIn('id', $regIds)->delete();
            DB::table('invoice_integrations')->where('company_id', $cid)->where(fn ($q) => $q->where('year', $year)->orWhereIn('invoice_id', $inv))->delete();
            DB::table('invoices')->whereIn('id', $inv)->delete();

            $yearSettlements = DB::table('vat_settlements')->where('company_id', $cid)->where('year', $year)->pluck('id');
            $yearCredits = DB::table('vat_credits')->where('company_id', $cid)->where('year', $year)->pluck('id');
            DB::table('vat_credit_movements')->where('company_id', $cid)->where(fn ($q) => $q->whereIn('credit_id', $yearCredits)->orWhereIn('settlement_id', $yearSettlements))->delete();
            DB::table('f24_drafts')->where('company_id', $cid)->whereIn('settlement_id', $yearSettlements)->delete();
            DB::table('vat_claims')->where('company_id', $cid)->where('year', $year)->delete();
            DB::table('vat_credits')->where('company_id', $cid)->where('year', $year)->delete();
            DB::table('vat_adjustments')->where('company_id', $cid)->where('year', $year)->delete();
            DB::table('vat_settlements')->where('company_id', $cid)->where('year', $year)->delete();
            DB::table('non_vat_entries')->where('company_id', $cid)->where('year', $year)->whereNotNull('reverses_id')->delete();
            DB::table('non_vat_entries')->where('company_id', $cid)->where('year', $year)->delete();
            DB::table('issuing_counters')->where('company_id', $cid)->where('scope', (string) $year)->delete();
            DB::table('vat_profiles')->where('company_id', $cid)->where('year', $year)->delete();
            // L'anno resta non selezionabile finché non viene ricreato
            $removed = array_values(array_unique([...array_map('intval', (array) $company->fresh()->removed_years), $year]));
            DB::table('companies')->where('id', $cid)->update(['removed_years' => json_encode($removed)]);

            // Rimuovere gli originali non più necessari altrove
            foreach ($docIds as $d) {
                $used = DB::table('invoices')->where('document_id', $d)->exists() || DB::table('vat_registrations')->where('document_id', $d)->exists() || DB::table('invoice_integrations')->where('document_id', $d)->exists()
                    || DB::table('invoice_versions')->where('document_id', $d)->exists() || DB::table('non_vat_entries')->where('document_id', $d)->exists() || DB::table('vat_claims')->where('document_id', $d)->exists();
                if (! $used) {
                    $path = DB::table('documents')->where('id', $d)->where('company_id', $cid)->value('storage_path');
                    DB::table('import_items')->where('document_id', $d)->update(['document_id' => null]);
                    DB::table('documents')->where('id', $d)->where('company_id', $cid)->delete();
                    if ($path) {
                        $files[] = $path;
                    }
                }
            }
        });
        foreach ($files as $f) {
            Storage::disk('local')->delete($f);
        }
        $request->session()->forget(ActiveAccountingYear::key($company));
        // La voce di attività indica l'utente e le quantità eliminate
        Audit::record('year.deleted_summary', $actor, $company, data: ['year' => $year, ...$summary, 'files' => count($files)]);

        return $summary;
    }
}
