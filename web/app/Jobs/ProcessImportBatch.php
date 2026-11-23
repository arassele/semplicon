<?php

namespace App\Jobs;

use App\Domain\Audit;
use App\Domain\Import\ImportError;
use App\Domain\Import\InvoiceImporter;
use App\Domain\Import\ZipExpander;
use App\Enums\ImportItemStatus;
use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Elabora un'esecuzione di importazione. Protezione dei mandanti: vengono elaborate solo le voci con la company_id dell'esecuzione,
 * i file intermedi si trovano in imports/{company_id}/{batch_id}/.
 */
class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(InvoiceImporter $importer): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        if ($batch->status === ImportStatus::Completato) {
            return; // nuova consegna: non elaborare nulla due volte
        }
        $batch->update(['status' => ImportStatus::InCorso, 'started_at' => now()]);
        $disk = Storage::disk('local');
        $prefix = "imports/{$batch->company_id}/{$batch->id}/";

        $items = ImportItem::where('import_batch_id', $batch->id)->where('status', ImportItemStatus::InAttesa)->orderBy('id')->get();
        foreach ($items as $item) {
            if ($item->company_id !== $batch->company_id || ! str_starts_with((string) $item->staging_path, $prefix)) {
                $item->update(['status' => ImportItemStatus::Errore, 'messages' => ['Elemento non appartenente a questa importazione.']]);

                continue;
            }
            try {
                $bytes = $disk->get($item->staging_path);
                if ($bytes === null) {
                    throw new ImportError('File temporaneo non trovato.');
                }
                if (str_starts_with($bytes, "PK\x03\x04") || str_ends_with(strtolower($item->filename), '.zip')) {
                    $this->expandZip($batch, $item, $disk->path($item->staging_path), $importer);
                } else {
                    $importer->import($item, $bytes, $batch->created_by);
                }
            } catch (ImportError $e) {
                $item->update(['status' => ImportItemStatus::Errore, 'messages' => [$e->getMessage(), ...$e->details]]);
            } catch (Throwable $e) {
                report($e);
                $item->update(['status' => ImportItemStatus::Errore, 'messages' => ['Errore imprevisto durante l’elaborazione del file.']]);
            } finally {
                $disk->delete($item->staging_path);
                $item->update(['staging_path' => null]);
            }
        }
        $disk->deleteDirectory($prefix);

        $all = ImportItem::where('import_batch_id', $batch->id)->where('status', '!=', ImportItemStatus::Archivio)->get();
        $batch->update([
            'status' => ImportStatus::Completato,
            'finished_at' => now(),
            'files' => $all->count(),
            'invoices' => $all->sum('invoices'),
            'duplicates' => $all->where('status', ImportItemStatus::Duplicato)->count(),
            'to_review' => $all->where('status', ImportItemStatus::DaVerificare)->count(),
            'errors' => $all->where('status', ImportItemStatus::Errore)->count(),
        ]);
        Audit::record('import.completed', User::find($batch->created_by), $batch->company, $batch, data: $batch->only(['files', 'invoices', 'duplicates', 'to_review', 'errors']));
    }

    private function expandZip(ImportBatch $batch, ImportItem $zipItem, string $path, InvoiceImporter $importer): void
    {
        $result = ZipExpander::expand($path);
        $zipItem->update([
            'status' => ImportItemStatus::Archivio,
            'messages' => [count($result['files']).' file trovati nell’archivio.', ...$result['messages']],
        ]);
        foreach ($result['files'] as $file) {
            $child = ImportItem::create([
                'import_batch_id' => $batch->id, 'company_id' => $batch->company_id, 'parent_id' => $zipItem->id,
                'filename' => mb_substr($zipItem->filename.' › '.$file['name'], 0, 255), 'status' => ImportItemStatus::InAttesa,
            ]);
            try {
                $importer->import($child, $file['bytes'], $batch->created_by);
            } catch (ImportError $e) {
                $child->update(['status' => ImportItemStatus::Errore, 'messages' => [$e->getMessage(), ...$e->details]]);
            }
        }
    }
}
