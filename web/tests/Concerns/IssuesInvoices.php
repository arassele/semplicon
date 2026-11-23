<?php

namespace Tests\Concerns;

use App\Domain\CompanyService;
use App\Domain\Issuing\InvoiceIssuer;
use App\Domain\VatProfileService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Dotazione base dei test: azienda con regime IVA confermato, conto e funzione di supporto per l'emissione. */
trait IssuesInvoices
{
    private User $owner;

    private Company $company;

    private int $account;

    protected function setUpIssuing(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-20 10:00:00');
        $this->owner = $this->customer();
        $this->company = app(CompanyService::class)->create($this->owner, ['name' => 'Alpha S.r.l.', 'tipologia' => 'societa', 'partita_iva' => '01234567897']);
        $this->company->update(['indirizzo' => 'Via Roma', 'numero_civico' => '10', 'cap' => '20121', 'comune' => 'Milano', 'provincia' => 'MI']);
        $svc = app(VatProfileService::class);
        $d = $svc->createDraft($this->company, $this->owner, 2026);
        $svc->updateDraft($this->company, $d, $this->owner, ['regime' => 'semplificata', 'method' => 'cassa', 'periodicity' => 'mensile']);
        $svc->confirm($this->company, $d->fresh(), $this->owner);
        $this->account = (int) $this->company->accounts()->where('type', 'ricavo')->where('iva_rilevante', true)->where('active', true)->orderByDesc('is_default')->value('id');
    }

    protected function invoiceData(array $over = []): array
    {
        $base = [
            'doc_type' => 'TD01', 'doc_date' => '2026-11-18', 'regime' => 'RF01', 'account_id' => $this->account, 'payment_mode' => 'MP05',
            'customer' => ['kind' => 'azienda', 'name' => 'Cliente Uno S.r.l.', 'vat' => '11111111115', 'country' => 'IT', 'address' => 'Via Verdi 1', 'zip' => '40121', 'city' => 'Bologna', 'province' => 'BO', 'sdi_code' => 'ABC1234'],
            'lines' => [['description' => 'Consulenza', 'quantity' => '10', 'unit' => 'ore', 'price' => '100,00', 'rate' => '22', 'natura' => '']],
            'payments' => [['date' => '2026-12-18', 'amount' => '1220,00']],
        ];
        $d = array_replace_recursive($base, $over);
        foreach (['lines', 'payments'] as $k) {
            if (array_key_exists($k, $over)) {
                $d[$k] = $over[$k];
            }
        }

        return $d;
    }

    protected function issueInvoice(array $over = []): Invoice
    {
        return app(InvoiceIssuer::class)->issue($this->company, $this->owner, $this->invoiceData($over));
    }
}
