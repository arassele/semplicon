<?php

namespace App\Domain\Import;

use App\Domain\Audit;
use App\Enums\ImportItemStatus;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportBatch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class ImportService
{
    /** @param list<UploadedFile> $files */
    public function start(Company $company, User $actor, array $files, string $mode = 'salta'): ImportBatch
    {
        $batch = ImportBatch::create(['company_id' => $company->id, 'created_by' => $actor->id, 'status' => ImportStatus::InCoda, 'files' => count($files), 'mode' => $mode === 'rielabora' ? 'rielabora' : 'salta']);
        foreach ($files as $file) {
            // Solo il nome del file senza percorso; il salvataggio avviene con un nome casuale.
            $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200) ?: 'file';
            $path = $file->storeAs("imports/{$company->id}/{$batch->id}", Str::uuid().'.bin', 'local');
            ImportItem::create([
                'import_batch_id' => $batch->id, 'company_id' => $company->id, 'filename' => $name,
                'staging_path' => $path, 'status' => ImportItemStatus::InAttesa,
            ]);
        }
        Audit::record('import.started', $actor, $company, $batch, data: ['files' => count($files), 'mode' => $mode]);
        ProcessImportBatch::dispatch($batch->id);

        return $batch;
    }
}
