<?php

namespace App\Domain;

use App\Enums\InvoiceStatus;
use App\Models\Allocation;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\NonVatEntry;
use App\Models\User;
use App\Models\VatRegistration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Eliminazione di fatture (importate o manuali) comprese le registrazioni. Indicazione del committente, 19.11.2026. */
final class InvoiceService
{
    public function delete(Company $company, Invoice $invoice, User $actor): void
    {
        if ($invoice->source === 'emessa') {
            RuleViolation::fail(['invoice' => 'Una fattura emessa con Semplicon non si elimina: la numerazione deve restare senza buchi. Per annullarla emetti una nota di credito.']);
        }
        if (Allocation::where('status', 'valida')->where(fn ($w) => $w->whereIn('installment_id', $invoice->installments()->pluck('id'))->orWhereIn('credit_installment_id', $invoice->installments()->pluck('id')))
            ->where(fn ($w) => $w->whereNull('movement_id')->orWhereHas('movement', fn ($m) => $m->where('status', 'valida')))->exists()) {
            RuleViolation::fail(['invoice' => 'La fattura ha incassi, pagamenti o compensazioni registrati nello scadenziario: stornali prima di eliminarla.']);
        }
        DB::transaction(function () use ($company, $invoice, $actor) {
            $invoice->load('registrations.lines.account');
            $before = [
                'number' => $invoice->number, 'doc_type' => $invoice->doc_type, 'doc_date' => $invoice->doc_date->toDateString(), 'source' => $invoice->source,
                'direction' => $invoice->direction?->value, 'counterparty' => $invoice->direction ? $invoice->counterpartyName() : $invoice->supplier_name,
                'taxable' => (string) $invoice->taxable_total, 'tax' => (string) $invoice->tax_total,
                'registrations' => $invoice->registrations->map(fn ($r) => RegistrationService::snapshot($r))->all(),
            ];
            // Prima gli storni (chiave esterna reverses_id), poi le altre registrazioni.
            VatRegistration::unlocked(function () use ($invoice) {
                foreach ($invoice->registrations->sortByDesc(fn ($r) => $r->reverses_id ? 1 : 0) as $r) {
                    $r->lines()->delete();
                    $r->delete();
                }
            });
            $documentId = $invoice->document_id;
            $invoice->delete();
            self::cleanupDocument($company, $documentId);
            Audit::record('invoice.deleted', $actor, $company, data: ['before' => $before]);
        });
    }

    /**
     * Eliminare definitivamente una fattura non registrata (file importato o fattura manuale), affinché il file possa essere importato di nuovo.
     * Bloccata non appena esiste una registrazione (prima stornare/eliminare), per fatture emesse e in presenza di pagamenti.
     */
    public function deleteUnregistered(Company $company, Invoice $invoice, User $actor): void
    {
        if ($invoice->company_id !== $company->id) {
            throw new ModelNotFoundException;
        }
        if ($invoice->registrations()->exists()) {
            RuleViolation::fail(['invoice' => "La fattura {$invoice->number} è registrata: elimina o storna prima la registrazione."]);
        }
        $this->delete($company, $invoice, $actor);
    }

    /**
     * Eliminare più fatture; quelle bloccate vengono saltate e segnalate con il motivo.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, blocked: list<string>}
     */
    public function deleteMany(Company $company, array $ids, User $actor): array
    {
        $deleted = 0;
        $blocked = [];
        foreach (Invoice::where('company_id', $company->id)->whereIn('id', $ids)->orderBy('id')->get() as $i) {
            try {
                $this->deleteUnregistered($company, $i, $actor);
                $deleted++;
            } catch (ValidationException $e) {
                $blocked[] = collect($e->errors())->flatten()->first();
            }
        }

        return ['deleted' => $deleted, 'blocked' => $blocked];
    }

    /** Elimina un file importato con tutte le sue fatture (solo se nessuna è registrata) – poi è possibile una nuova importazione. @return int Numero di fatture eliminate */
    public function deleteDocument(Company $company, Document $document, User $actor): int
    {
        if ($document->company_id !== $company->id) {
            throw new ModelNotFoundException;
        }
        $invoices = Invoice::where('company_id', $company->id)->where('document_id', $document->id)->get();
        foreach ($invoices as $i) {
            if ($i->registrations()->exists()) {
                RuleViolation::fail(['document' => "Il file contiene la fattura {$i->number}, già registrata: elimina o storna prima la registrazione."]);
            }
        }
        foreach ($invoices as $i) {
            $this->deleteUnregistered($company, $i, $actor);
        }
        self::cleanupDocument($company, $document->id);
        Audit::record('import.file_deleted', $actor, $company, data: ['file' => $document->original_name, 'sha256' => $document->sha256, 'invoices' => $invoices->count()]);

        return $invoices->count();
    }

    /**
     * Rimuovere dalla lista di lavoro una fattura di acquisto non registrata: archiviare invece di eliminare (incarico di sviluppo 19.11.2026, sezione 1).
     * Originale, allegati, identificativo di importazione e ID restano; si torna indietro solo con restore().
     */
    public function archive(Company $company, Invoice $invoice, User $actor): void
    {
        DB::transaction(function () use ($company, $invoice, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($invoice->direction?->value !== 'acquisto') {
                RuleViolation::fail(['invoice' => 'Si archiviano solo le fatture di acquisto.']);
            }
            if ($invoice->activeRegistration()) {
                RuleViolation::fail(['invoice' => 'La fattura è registrata: per annullarla usa “Storna” nella registrazione.']);
            }
            if ($invoice->status === InvoiceStatus::Archiviata) {
                RuleViolation::fail(['invoice' => 'La fattura è già archiviata.']);
            }
            $invoice->update(['archived_from' => $invoice->status->value, 'status' => InvoiceStatus::Archiviata, 'archived_at' => now(), 'archived_by' => $actor->id]);
            Audit::record('invoice.archived', $actor, $company, $invoice, data: ['number' => $invoice->number, 'from' => $invoice->archived_from]);
        });
    }

    public function restore(Company $company, Invoice $invoice, User $actor): void
    {
        DB::transaction(function () use ($company, $invoice, $actor) {
            $invoice = Invoice::whereKey($invoice->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::Archiviata) {
                RuleViolation::fail(['invoice' => 'La fattura non è archiviata.']);
            }
            $back = InvoiceStatus::tryFrom((string) $invoice->archived_from);
            $back = $back && $back->isOpen() ? $back : (($invoice->issues ?? []) ? InvoiceStatus::DaVerificare : InvoiceStatus::Importata);
            $invoice->update(['status' => $back, 'archived_from' => null, 'archived_at' => null, 'archived_by' => null]);
            Audit::record('invoice.restored_archive', $actor, $company, $invoice, data: ['number' => $invoice->number]);
        });
    }

    /** Rimuove un documento se non è più utilizzato da alcuna fattura, registrazione o movimento (file di nuovo importabile). */
    public static function cleanupDocument(Company $company, ?int $documentId): void
    {
        if (! $documentId) {
            return;
        }
        $used = Invoice::where('document_id', $documentId)->exists() || VatRegistration::where('document_id', $documentId)->exists()
            || NonVatEntry::where('document_id', $documentId)->exists();
        $doc = Document::whereKey($documentId)->where('company_id', $company->id)->first();
        if (! $used && $doc) {
            Storage::disk('local')->delete($doc->storage_path);
            $doc->delete();
        }
    }
}
