<?php

namespace App\Domain;

use App\Enums\SemplificataMethod;
use App\Enums\VatRegime;
use App\Models\Company;

/**
 * Funzioni e opzioni di un'azienda (indicazione del committente, 21.11.2026): Scadenziario, Emissione fattura e Adempimenti IVA si possono disattivare
 * (poi nascosti, i dati restano); fonte della data di pagamento nel registro IVA; controllo del codice fiscale nell'importazione XML.
 */
final class CompanyOptions
{
    public const MODULES = ['scadenziario' => 'Scadenziario (incassi e pagamenti)', 'emissione' => 'Emissione fattura elettronica', 'adempimenti' => 'Adempimenti IVA (registri, liquidazioni, crediti, scadenze fiscali, archivio, stampa)'];

    public const DEFAULTS = ['modules' => ['scadenziario' => true, 'emissione' => true, 'adempimenti' => true], 'payment_date_source' => 'scadenziario', 'import_check_vat' => true];

    /** @return array{modules: array<string, bool>, payment_date_source: string, import_check_vat: bool} */
    public static function for(Company $company): array
    {
        return self::normalize((array) $company->options);
    }

    /** @param  array<string, mixed>  $in */
    public static function normalize(array $in): array
    {
        $m = (array) ($in['modules'] ?? []);

        return [
            'modules' => array_combine(array_keys(self::MODULES), array_map(fn ($k) => (bool) ($m[$k] ?? true), array_keys(self::MODULES))),
            'payment_date_source' => ($in['payment_date_source'] ?? 'scadenziario') === 'registrazione' ? 'registrazione' : 'scadenziario',
            'import_check_vat' => (bool) ($in['import_check_vat'] ?? true),
        ];
    }

    public static function enabled(Company $company, string $module): bool
    {
        return self::for($company)['modules'][$module] ?? true;
    }

    /**
     * Fonte della data di pagamento nel registro IVA. Semplificata per competenza: sempre la data di registrazione (nessuna annotazione dei pagamenti).
     * Altrimenti secondo l'impostazione: pagamento dallo Scadenziario o data di registrazione. Incide solo sulla rappresentazione fiscale, non sullo Scadenziario.
     *
     * @return 'registrazione'|'scadenziario'
     */
    public static function paymentDateSource(Company $company, ?int $year = null): string
    {
        $p = $company->activeVatProfile($year ?? (int) now()->year);
        if ($p && $p->regime === VatRegime::Semplificata && $p->method === SemplificataMethod::Competenza) {
            return 'registrazione';
        }

        return self::for($company)['payment_date_source'];
    }
}
