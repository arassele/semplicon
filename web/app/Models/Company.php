<?php

namespace App\Models;

use App\Enums\CompanyType;
use App\Enums\ProfileStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_settings',
        'name', 'partita_iva', 'codice_fiscale', 'tipologia', 'natura_giuridica', 'sigla', 'codice_sdi',
        'titolo', 'cognome', 'nome', 'sesso', 'data_nascita', 'comune_nascita', 'provincia_nascita',
        'indirizzo', 'numero_civico', 'cap', 'comune', 'provincia', 'nazione', 'issuing_settings', 'logo_path', 'options', 'removed_years', 'created_by',
    ];

    protected function casts(): array
    {
        return ['tipologia' => CompanyType::class, 'data_nascita' => 'date:Y-m-d', 'issuing_settings' => 'array', 'options' => 'array', 'removed_years' => 'array', 'asset_settings' => 'array'];
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withTimestamps();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CompanyActivity::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function xmlExports(): HasMany
    {
        return $this->hasMany(XmlExport::class);
    }

    public function invoiceIntegrations(): HasMany
    {
        return $this->hasMany(InvoiceIntegration::class);
    }

    /** L'anno corrente è selezionabile finché non è stato eliminato (dopo l'eliminazione: solo dopo nuova creazione). */
    public function currentYearSelectable(): bool
    {
        return ! in_array((int) now()->year, array_map('intval', (array) $this->removed_years), true);
    }

    public function fiscalParameters(): HasMany
    {
        return $this->hasMany(FiscalParameter::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }

    public function catalogArticles(): HasMany
    {
        return $this->hasMany(CatalogArticle::class);
    }

    public function catalogSnippets(): HasMany
    {
        return $this->hasMany(CatalogSnippet::class);
    }

    public function catalogUnits(): HasMany
    {
        return $this->hasMany(CatalogUnit::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function nonVatEntries(): HasMany
    {
        return $this->hasMany(NonVatEntry::class);
    }

    /** Scadenziario: incassi e pagamenti (parametro di route {movement}). */
    public function movements(): HasMany
    {
        return $this->hasMany(MoneyMovement::class);
    }

    public function invoiceReprocessings(): HasMany
    {
        return $this->hasMany(InvoiceReprocessing::class);
    }

    public function vatAdjustments(): HasMany
    {
        return $this->hasMany(VatAdjustment::class);
    }

    public function f24Drafts(): HasMany
    {
        return $this->hasMany(F24Draft::class);
    }

    public function vatCredits(): HasMany
    {
        return $this->hasMany(VatCredit::class);
    }

    public function vatCreditMovements(): HasMany
    {
        return $this->hasMany(VatCreditMovement::class);
    }

    public function vatClaims(): HasMany
    {
        return $this->hasMany(VatClaim::class);
    }

    /** Scadenziario: attribuzioni (parametro di route {allocation}). */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class)->orderBy('code');
    }

    public function vatRegistrations(): HasMany
    {
        return $this->hasMany(VatRegistration::class);
    }

    public function vatProfiles(): HasMany
    {
        return $this->hasMany(VatProfile::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** Regime confermato di un anno solare (versione valida più recente). */
    public function activeVatProfile(int $year): ?VatProfile
    {
        return $this->vatProfiles()->where('year', $year)->where('status', ProfileStatus::Confermato)->orderByDesc('version')->first();
    }

    public function draftVatProfile(int $year): ?VatProfile
    {
        return $this->vatProfiles()->where('year', $year)->where('status', ProfileStatus::Bozza)->first();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function assetCategories(): HasMany
    {
        return $this->hasMany(AssetCategory::class);
    }

    public function assetMovements(): HasMany
    {
        return $this->hasMany(AssetMovement::class);
    }

    public function assetImportJobs(): HasMany
    {
        return $this->hasMany(AssetImportJob::class);
    }

    public function assetDepreciationRuns(): HasMany
    {
        return $this->hasMany(AssetDepreciationRun::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function fiscalYearClosures(): HasMany
    {
        return $this->hasMany(FiscalYearClosure::class);
    }
}
