<?php

namespace App\Domain;

use App\Models\Company;
use App\Models\NonVatEntry;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Movimenti fuori campo IVA: costi e ricavi senza IVA (es. bollo, assicurazioni, stipendi, interessi,
 * ricevute senza fattura). Non compaiono nei Registri IVA né nella liquidazione.
 */
final class NonVatEntryService
{
    public function __construct(private ManualInvoiceService $attachments) {}

    /** @param array{kind: string, entry_date: string, account_id: int, description: string, counterparty?: ?string, reference?: ?string, amount: string} $d */
    public function create(Company $company, User $actor, array $d, ?UploadedFile $file = null): NonVatEntry
    {
        $errors = [];
        if ($d['entry_date'] > now()->toDateString()) {
            $errors['entry_date'] = 'La data non può essere nel futuro.';
        }
        $account = $company->accounts()->whereKey((int) $d['account_id'])->first();
        if (! $account || ! $account->active) {
            $errors['account_id'] = 'Scegli un conto attivo del piano dei conti.';
        } elseif ($account->type !== $d['kind']) {
            $errors['account_id'] = $d['kind'] === 'costo' ? 'Per un costo scegli un conto di costo.' : 'Per un ricavo scegli un conto di ricavo.';
        }
        $amount = Decimal::toMinor(str_replace(',', '.', (string) $d['amount']));
        if ($amount <= 0) {
            $errors['amount'] = 'L’importo deve essere maggiore di zero.';
        }
        if ($errors) {
            RuleViolation::fail($errors);
        }

        return DB::transaction(function () use ($company, $actor, $d, $file, $amount, $account) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $year = (int) substr($d['entry_date'], 0, 4);
            $document = $file ? $this->attachments->storeAttachment($company, $actor, $file) : null;
            $entry = NonVatEntry::create([
                'company_id' => $company->id, 'kind' => $d['kind'], 'entry_date' => $d['entry_date'], 'year' => $year,
                'protocol' => $this->nextProtocol($company, $year), 'account_id' => $account->id, 'description' => trim($d['description']),
                'counterparty' => ($d['counterparty'] ?? '') ? trim($d['counterparty']) : null, 'reference' => ($d['reference'] ?? '') ? trim($d['reference']) : null,
                'amount' => Decimal::fromMinor($amount), 'sign' => 1, 'document_id' => $document?->id, 'status' => 'registrato', 'created_by' => $actor->id,
            ]);
            Audit::record('non_vat.created', $actor, $company, $entry, data: ['protocol' => "{$entry->protocol}/{$year}", 'kind' => $entry->kind, 'amount' => $entry->amount, 'account' => $account->code]);

            return $entry;
        });
    }

    /** Storno: registrazione inversa con proprio protocollo nell'anno della data di storno. */
    public function reverse(Company $company, NonVatEntry $entry, User $actor, string $reason, string $date): NonVatEntry
    {
        if (trim($reason) === '') {
            RuleViolation::fail(['reason' => 'Indica il motivo dello storno.']);
        }

        return DB::transaction(function () use ($company, $entry, $actor, $reason, $date) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $original = NonVatEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'registrato') {
                RuleViolation::fail(['reason' => 'Questo movimento è già stornato.']);
            }
            $from = $original->entry_date->toDateString();
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $from || $date > now()->toDateString()) {
                RuleViolation::fail(['date' => 'La data dello storno deve essere tra la data del movimento ('.implode('/', array_reverse(explode('-', $from))).') e oggi.']);
            }
            $year = (int) substr($date, 0, 4);
            $storno = NonVatEntry::create([
                ...collect($original->getAttributes())->only(['company_id', 'kind', 'account_id', 'description', 'counterparty', 'reference', 'amount', 'document_id'])->all(),
                'entry_date' => $date, 'year' => $year, 'protocol' => $this->nextProtocol($company, $year), 'sign' => -1,
                'status' => 'storno', 'reverses_id' => $original->id, 'reason' => trim($reason), 'created_by' => $actor->id,
            ]);
            $original->update(['status' => 'stornato']);
            Audit::record('non_vat.reversed', $actor, $company, $storno, trim($reason), ['protocol' => "{$storno->protocol}/{$year}", 'reverses' => "{$original->protocol}/{$original->year}"]);

            return $storno;
        });
    }

    /**
     * Elenco e totali per conto per un anno (facoltativamente solo costi o ricavi). Importi con segno.
     *
     * @return array{entries: list<array<string, mixed>>, totals: list<array<string, mixed>>, sum: array{costo: string, ricavo: string}}
     */
    public function build(Company $company, int $year, ?string $kind = null): array
    {
        $rows = $company->nonVatEntries()->where('year', $year)->when($kind, fn ($q) => $q->where('kind', $kind))
            ->with(['account', 'document', 'creator'])->orderBy('entry_date')->orderBy('protocol')->get();
        $totals = [];
        $sum = ['costo' => 0, 'ricavo' => 0];
        $entries = $rows->map(function (NonVatEntry $e) use (&$totals, &$sum) {
            $signed = $e->sign * Decimal::toMinor((string) $e->amount);
            $key = $e->account->code;
            $totals[$key] ??= ['code' => $e->account->code, 'name' => $e->account->name, 'kind' => $e->kind, 'amount' => 0];
            $totals[$key]['amount'] += $signed;
            $sum[$e->kind] += $signed;

            return [
                'id' => $e->id, 'protocol_label' => "{$e->protocol}/{$e->year}", 'kind' => $e->kind, 'entry_date' => $e->entry_date->toDateString(),
                'account' => $e->account->code.' · '.$e->account->name, 'description' => $e->description, 'counterparty' => $e->counterparty, 'reference' => $e->reference,
                'amount' => Decimal::fromMinor($signed), 'status' => $e->status, 'reason' => $e->reason,
                'reverses' => $e->reverses_id ? NonVatEntry::whereKey($e->reverses_id)->get(['protocol', 'year'])->map(fn ($o) => "{$o->protocol}/{$o->year}")->first() : null,
                'document' => $e->document ? ['id' => $e->document->id, 'name' => $e->document->original_name] : null, 'created_by' => $e->creator?->name,
            ];
        })->all();
        ksort($totals);

        return [
            'entries' => $entries,
            'totals' => array_values(array_map(fn ($t) => [...$t, 'amount' => Decimal::fromMinor($t['amount'])], $totals)),
            'sum' => ['costo' => Decimal::fromMinor($sum['costo']), 'ricavo' => Decimal::fromMinor($sum['ricavo'])],
        ];
    }

    private function nextProtocol(Company $company, int $year): int
    {
        return (int) $company->nonVatEntries()->where('year', $year)->max('protocol') + 1;
    }
}
