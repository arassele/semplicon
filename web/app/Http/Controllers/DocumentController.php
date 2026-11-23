<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\InvoiceService;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Download dell'originale. Solo tramite l'azienda (scoped binding), altrimenti 404. */
class DocumentController extends Controller
{
    public function download(Request $request, Company $company, Document $document): StreamedResponse
    {
        abort_unless($document->company_id === $company->id && Storage::disk('local')->exists($document->storage_path), 404);
        Audit::record('document.downloaded', $request->user(), $company, $document);

        return Storage::disk('local')->download($document->storage_path, $document->original_name, [
            'Content-Type' => match ($document->kind) {
                'xml' => 'application/xml', 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', default => 'application/pkcs7-mime',
            },
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Eliminare il file importato con le fatture non registrate; poi è possibile una nuova importazione. */
    public function destroy(Request $request, Company $company, Document $document, InvoiceService $service)
    {
        Gate::authorize('manage', $company);
        abort_unless($document->company_id === $company->id, 404);
        $name = $document->original_name;
        $n = $service->deleteDocument($company, $document, $request->user());

        return back()->with('success', "File «{$name}» eliminato ({$n} ".($n === 1 ? 'fattura' : 'fatture').'). Puoi importarlo di nuovo.');
    }
}
